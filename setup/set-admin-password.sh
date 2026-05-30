#!/bin/bash
#
# set-admin-password.sh
#
# Configure HTTP Basic authentication for the VPN Client Gateway management
# page. Writes /etc/vpngw/auth.conf with a bcrypt-hashed password (the plaintext
# is never stored). Run as root.
#
#   sudo ./set-admin-password.sh [username]      # prompts for the password
#   VPNGW_ADMIN_PASSWORD=secret sudo -E ./set-admin-password.sh admin
#
# To switch to SSO/reverse-proxy auth instead, set "mode=proxy" in auth.conf
# (see documentation/authentication.md). To disable auth, set "mode=off".
#
set -euo pipefail

AUTH_CONF="/etc/vpngw/auth.conf"
USERNAME="${1:-admin}"

[ "$(id -u)" -eq 0 ] || { echo "Please run as root (sudo $0)." >&2; exit 1; }
command -v php >/dev/null 2>&1 || { echo "php is required to hash the password." >&2; exit 1; }

PW="${VPNGW_ADMIN_PASSWORD:-}"
if [ -z "$PW" ]; then
	read -rsp "Password for '$USERNAME': " PW; echo
	read -rsp "Confirm password: " PW2; echo
	[ "$PW" = "$PW2" ] || { echo "Passwords do not match." >&2; exit 1; }
fi
[ -n "$PW" ] || { echo "Empty password; aborting." >&2; exit 1; }

# Hash with PHP's password_hash() so it matches password_verify() in auth.php.
HASH="$(VPNGW_PW="$PW" php -r 'echo password_hash(getenv("VPNGW_PW"), PASSWORD_DEFAULT);')"
[ -n "$HASH" ] || { echo "Failed to hash password." >&2; exit 1; }

install -d -m 755 "$(dirname "$AUTH_CONF")"
umask 077
cat > "$AUTH_CONF" <<EOF
# VPN Client Gateway management-page authentication.
#
# mode: off | basic | proxy
#   off    - no authentication (default if this file is absent)
#   basic  - HTTP Basic auth using the username + bcrypt password_hash below
#   proxy  - trust an authenticated-user header from an upstream SSO proxy
#            (set user_header and, optionally, trusted_proxies)
mode=basic
username=$USERNAME
password_hash=$HASH
realm=VPN Client Gateway

# --- proxy/SSO mode example (ignored unless mode=proxy) ---
#user_header=Remote-User
#trusted_proxies=127.0.0.1,::1
EOF
# Holds a bcrypt hash (not the plaintext), but restrict it to root + the web
# server's group so other local users can't read it for offline cracking.
WEB_GROUP=""
for g in www-data apache nginx http; do
	if getent group "$g" >/dev/null 2>&1; then WEB_GROUP="$g"; break; fi
done
if [ -n "$WEB_GROUP" ]; then
	chown "root:$WEB_GROUP" "$AUTH_CONF" && chmod 640 "$AUTH_CONF"
	echo "Permissions: 0640 root:$WEB_GROUP"
else
	chmod 644 "$AUTH_CONF"
	echo "Could not detect the web group; left $AUTH_CONF as 0644." >&2
	echo "Tighten it: chown root:<web-group> $AUTH_CONF && chmod 640 $AUTH_CONF" >&2
fi

echo "Wrote $AUTH_CONF (mode=basic, username=$USERNAME)."
echo "Reload the page; the browser should now prompt for credentials."

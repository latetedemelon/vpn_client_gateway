#!/bin/bash
#
# nordvpn-wireguard-setup.sh
#
# One-shot setup of the VPN Client Gateway for NordVPN over WireGuard
# (NordLynx). It:
#
#   1. installs wireguard-tools, curl and jq
#   2. obtains your NordLynx private key (from a NordVPN access token, or a key
#      you paste in)
#   3. picks a recommended WireGuard server and writes /etc/wireguard/wg0.conf
#   4. selects the WireGuard backend (/etc/vpngw/backend)
#   5. generates the server list (vpnservers.xml) for the web UI
#   6. enables the tunnel at boot
#
# It does NOT install the firewall - run fw/fw-config afterwards to enable
# forwarding and the kill switch.
#
# Run as root:  sudo ./nordvpn-wireguard-setup.sh
#
# Non-interactive use (env vars):
#   NORDVPN_TOKEN     NordVPN access token (from the Nord dashboard)
#   NORD_PRIVATE_KEY  a NordLynx private key (alternative to the token)
#   VPNMGMT_DIR       path to the web vpnmgmt directory (auto-detected if unset)
#
set -euo pipefail

WG_CONF="/etc/wireguard/wg0.conf"
WG_IFACE="wg0"
WG_PORT="51820"
BACKEND_FILE="/etc/vpngw/backend"
NORDLYNX_ADDRESS="10.5.0.2/32"
NORDLYNX_DNS="103.86.96.100, 103.86.99.100"
CRED_API="https://api.nordvpn.com/v1/users/services/credentials"
REC_API="https://api.nordvpn.com/v1/servers/recommendations?filters[servers_technologies][identifier]=wireguard_udp&limit=1"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
UPDATER="$SCRIPT_DIR/../www/vpnmgmt/vpn_providers/nordvpn/vpn_update.sh"

say()  { printf '\n\033[1;36m::\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33m!!\033[0m %s\n' "$*" >&2; }
die()  { printf '\033[1;31m!!\033[0m %s\n' "$*" >&2; exit 1; }
need() { command -v "$1" >/dev/null 2>&1; }

[ "$(id -u)" -eq 0 ] || die "Please run as root (sudo $0)."

# --- 1. dependencies --------------------------------------------------------
say "Installing dependencies (wireguard-tools, curl, jq) ..."
if need apt-get; then
	apt-get update -qq
	apt-get install -y wireguard wireguard-tools curl jq
	# openresolv provides the resolvconf used by wg-quick's "DNS =" line.
	apt-get install -y openresolv || warn "openresolv not installed; see DNS notes in the docs."
elif need apk; then
	apk add --no-cache wireguard-tools curl jq
	apk add --no-cache openresolv || warn "openresolv not installed; see DNS notes in the docs."
elif need dnf; then
	dnf install -y wireguard-tools curl jq
	dnf install -y openresolv || warn "openresolv not installed; see DNS notes in the docs."
elif need pacman; then
	pacman -Sy --noconfirm wireguard-tools curl jq
	pacman -Sy --noconfirm openresolv || warn "openresolv not installed; see DNS notes in the docs."
else
	warn "Unknown package manager - make sure wireguard-tools, curl, jq and a"
	warn "resolvconf provider (e.g. openresolv) are installed."
fi
need wg       || die "wg not found after install."
need wg-quick || die "wg-quick not found after install."
need curl     || die "curl not found after install."
need jq       || die "jq not found after install."

# --- 2. NordLynx private key ------------------------------------------------
PRIVATE_KEY="${NORD_PRIVATE_KEY:-}"

if [ -z "$PRIVATE_KEY" ]; then
	TOKEN="${NORDVPN_TOKEN:-}"
	if [ -z "$TOKEN" ] && [ -t 0 ]; then
		cat <<'EOF'

To connect with WireGuard you need your NordLynx private key. The easiest way
is to create an *access token* in your NordVPN account:

  Nord dashboard -> "Set up NordVPN manually" / API access -> Generate new token

Paste the access token below (leave blank to paste a private key instead).
EOF
		read -rsp "NordVPN access token: " TOKEN; echo
	fi

	if [ -n "$TOKEN" ]; then
		say "Requesting your NordLynx key from the NordVPN API ..."
		# The token is used as the HTTP basic password (username is "token").
		CRED_JSON="$(curl -fsS -g -u "token:$TOKEN" "$CRED_API" || true)"
		PRIVATE_KEY="$(printf '%s' "$CRED_JSON" | jq -r '.nordlynx_private_key // empty' 2>/dev/null || true)"
		if [ -z "$PRIVATE_KEY" ]; then
			warn "Could not retrieve the key from the API (token rejected or API changed)."
		fi
	fi
fi

if [ -z "$PRIVATE_KEY" ] && [ -t 0 ]; then
	cat <<'EOF'

Enter a NordLynx private key directly. You can obtain one on a machine where
the NordVPN app is installed and connected with:

  nordvpn set technology nordlynx && nordvpn connect
  sudo wg show nordlynx private-key

EOF
	read -rsp "NordLynx private key: " PRIVATE_KEY; echo
fi

[ -n "$PRIVATE_KEY" ] || die "No private key provided - cannot continue."
# A WireGuard key is 44 base64 chars ending in '='.
if ! printf '%s' "$PRIVATE_KEY" | grep -Eq '^[0-9A-Za-z+/]{42,43}=$'; then
	warn "The private key does not look like a standard WireGuard key; continuing anyway."
fi

# --- 3. pick an initial server and write wg0.conf ---------------------------
say "Selecting a recommended WireGuard server ..."
REC_JSON="$(curl -fsS -g --max-time 30 "$REC_API")"
SRV_HOST="$(printf '%s' "$REC_JSON" | jq -r '.[0].hostname')"
SRV_IP="$(printf '%s' "$REC_JSON"   | jq -r '.[0].station')"
SRV_PUB="$(printf '%s' "$REC_JSON"  | jq -r '[.[0].technologies[]|select(.identifier=="wireguard_udp")|.metadata[]|select(.name=="public_key")|.value]|.[0]')"
[ -n "$SRV_HOST" ] && [ -n "$SRV_IP" ] && [ -n "$SRV_PUB" ] || die "Could not parse a recommended server from the API."
say "Initial server: $SRV_HOST ($SRV_IP)"

say "Writing $WG_CONF ..."
install -d -m 700 /etc/wireguard
umask 077
cat > "$WG_CONF" <<EOF
[Interface]
PrivateKey = $PRIVATE_KEY
Address = $NORDLYNX_ADDRESS
DNS = $NORDLYNX_DNS
# Server = $SRV_HOST

[Peer]
PublicKey = $SRV_PUB
AllowedIPs = 0.0.0.0/0, ::/0
Endpoint = $SRV_IP:$WG_PORT
PersistentKeepalive = 25
EOF
chmod 600 "$WG_CONF"

# --- 4. select the WireGuard backend ----------------------------------------
say "Selecting the WireGuard backend ..."
install -d -m 755 "$(dirname "$BACKEND_FILE")"
echo "wireguard" > "$BACKEND_FILE"

# --- 5. generate the server list for the web UI -----------------------------
# Auto-detect the live web vpnmgmt directory if not provided.
if [ -z "${VPNMGMT_DIR:-}" ]; then
	for d in /var/www/vpnmgmt /var/www/html/vpnmgmt /var/www/localhost/htdocs/vpnmgmt; do
		[ -d "$d" ] && { VPNMGMT_DIR="$d"; break; }
	done
fi
if [ -n "${VPNMGMT_DIR:-}" ]; then
	say "Generating server list and deploying to $VPNMGMT_DIR ..."
	VPNMGMT_DIR="$VPNMGMT_DIR" bash "$UPDATER"
else
	warn "Could not auto-detect the web vpnmgmt directory."
	say "Generating server list into the repository copy only ..."
	bash "$UPDATER"
	warn "Copy www/vpnmgmt/vpn_providers/nordvpn/vpnservers.xml to your web vpnmgmt/ dir,"
	warn "or re-run with VPNMGMT_DIR=/path/to/vpnmgmt."
fi

# --- 6. enable at boot ------------------------------------------------------
if [ -d /run/systemd/system ]; then
	say "Enabling wg-quick@$WG_IFACE at boot (systemd) ..."
	systemctl enable "wg-quick@$WG_IFACE" >/dev/null 2>&1 || warn "Could not enable wg-quick@$WG_IFACE."
else
	warn "Non-systemd init detected. To start WireGuard at boot, create the"
	warn "OpenRC service link:  ln -s /etc/init.d/wg-quick /etc/init.d/wg-quick.$WG_IFACE && rc-update add wg-quick.$WG_IFACE"
fi

cat <<EOF

$(say "Setup complete.")
Next steps:
  1. Configure the firewall / kill switch:   cd $(cd "$SCRIPT_DIR/../fw" && pwd) && sudo ./fw-config
  2. Bring the tunnel up now:                 sudo wg-quick up $WG_IFACE
  3. Verify it is connected:                  sudo wg show ; curl https://api.nordvpn.com/v1/helpers/ips/insights | jq
  4. Open the web UI and pick a country by clicking its flag.

The web user (e.g. www-data) must be able to run wg-quick/wg/iptables via
sudo without a password - the standard VPN Client Gateway install already
configures this.
EOF

#!/bin/bash
#
# enable-https.sh
#
# Serve the management UI over HTTPS so admin/Basic credentials are not sent in
# cleartext over the LAN (security audit finding H3). Generates a self-signed
# certificate and enables TLS for Apache (Debian/Ubuntu/Raspbian layout, incl.
# the php:apache container).
#
# For a CA-signed/Let's Encrypt setup, or if you already run a TLS-terminating
# reverse proxy, you do not need this -- see documentation/authentication.md.
#
#   sudo ./enable-https.sh [common-name]      # default CN: the hostname
#
# Env overrides:
#   VPNGW_TLS_DIR    cert/key directory (default /etc/vpngw/tls)
#   VPNGW_TLS_DAYS   validity in days   (default 3650)
#
set -euo pipefail

[ "$(id -u)" -eq 0 ] || { echo "Please run as root (sudo $0)." >&2; exit 1; }
command -v openssl >/dev/null 2>&1 || { echo "openssl is required." >&2; exit 1; }

CN="${1:-$(hostname -f 2>/dev/null || hostname)}"
TLS_DIR="${VPNGW_TLS_DIR:-/etc/vpngw/tls}"
DAYS="${VPNGW_TLS_DAYS:-3650}"
CERT="$TLS_DIR/gateway.crt"
KEY="$TLS_DIR/gateway.key"

install -d -m 750 "$TLS_DIR"
if [ -f "$CERT" ] && [ -f "$KEY" ]; then
	echo "Certificate already exists at $CERT (delete it to regenerate)."
else
	echo "Generating a self-signed certificate for CN=$CN ($DAYS days) ..."
	openssl req -x509 -newkey rsa:2048 -nodes \
		-keyout "$KEY" -out "$CERT" -days "$DAYS" \
		-subj "/CN=$CN" \
		-addext "subjectAltName=DNS:$CN" >/dev/null 2>&1
	chmod 640 "$KEY"; chmod 644 "$CERT"
	echo "Wrote $CERT and $KEY."
fi

# Wire it into Apache if present (best-effort; harmless if Apache isn't used).
if command -v a2enmod >/dev/null 2>&1; then
	a2enmod ssl >/dev/null 2>&1 || true
	SITE="/etc/apache2/sites-available/vpngw-ssl.conf"
	cat > "$SITE" <<EOF
<IfModule mod_ssl.c>
<VirtualHost _default_:443>
    DocumentRoot /var/www/html
    SSLEngine on
    SSLCertificateFile    $CERT
    SSLCertificateKeyFile $KEY
    # Modern, conservative TLS.
    SSLProtocol all -SSLv3 -TLSv1 -TLSv1.1
    Header always set Strict-Transport-Security "max-age=31536000"
</VirtualHost>
</IfModule>
EOF
	a2enmod headers >/dev/null 2>&1 || true
	a2ensite vpngw-ssl >/dev/null 2>&1 || true
	echo "Enabled the Apache TLS site (vpngw-ssl)."

	# Optional: redirect plain HTTP to HTTPS.
	if [ "${VPNGW_TLS_REDIRECT:-1}" = "1" ]; then
		REDIR="/etc/apache2/conf-available/vpngw-https-redirect.conf"
		cat > "$REDIR" <<'EOF'
<VirtualHost *:80>
    RewriteEngine On
    RewriteRule ^/?(.*) https://%{HTTP_HOST}/$1 [R=301,L]
</VirtualHost>
EOF
		a2enmod rewrite >/dev/null 2>&1 || true
		a2enconf vpngw-https-redirect >/dev/null 2>&1 || true
		echo "Enabled HTTP->HTTPS redirect."
	fi

	if [ -d /run/systemd/system ]; then
		systemctl reload apache2 2>/dev/null || systemctl restart apache2 2>/dev/null || true
	else
		apachectl -k graceful 2>/dev/null || service apache2 reload 2>/dev/null || true
	fi
	echo "Done. Browse to https://$CN/ (a self-signed cert will warn once; trust it)."
else
	cat <<EOF
openssl cert generated, but Apache (a2enmod) was not found. If you use another
web server, point it at:
  certificate: $CERT
  private key: $KEY
EOF
fi

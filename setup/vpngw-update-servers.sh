#!/bin/bash
#
# vpngw-update-servers.sh
#
# Refresh the VPN server list for the active provider and deploy it to the web
# vpnmgmt directory, so the country/flag list and the WireGuard peer data stay
# current. Run on a schedule by setup/vpngw-install-services.sh.
#
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROVIDER_FILE="${VPNGW_PROVIDER_FILE:-/etc/vpngw/provider}"

PROVIDER="nordvpn"
if [ -r "$PROVIDER_FILE" ]; then
	PROVIDER="$(tr -d '[:space:]' < "$PROVIDER_FILE")"
fi
[ -n "$PROVIDER" ] || PROVIDER="nordvpn"

# Locate the live web vpnmgmt directory (so the updater deploys there).
if [ -z "${VPNMGMT_DIR:-}" ]; then
	for d in /var/www/vpnmgmt /var/www/html/vpnmgmt /var/www/localhost/htdocs/vpnmgmt; do
		[ -d "$d" ] && { VPNMGMT_DIR="$d"; break; }
	done
fi

# Prefer the updater in the repo checkout; fall back to the installed web tree.
UPDATER="$SCRIPT_DIR/../www/vpnmgmt/vpn_providers/$PROVIDER/vpn_update.sh"
if [ ! -f "$UPDATER" ]; then
	UPDATER="${VPNMGMT_DIR:-/var/www/vpnmgmt}/vpn_providers/$PROVIDER/vpn_update.sh"
fi
[ -f "$UPDATER" ] || { echo "No server-list updater for provider '$PROVIDER' ($UPDATER)." >&2; exit 1; }

echo "Refreshing server list for provider: $PROVIDER"
VPNMGMT_DIR="${VPNMGMT_DIR:-}" bash "$UPDATER"

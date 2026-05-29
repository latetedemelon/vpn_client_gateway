#!/bin/bash
#
# Run all repository checks: PHP lint, shell syntax, XML validity, shellcheck
# (if available) and the PHP unit tests. Used locally and by CI.
#
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."

SHELL_SCRIPTS=(
	fw/fw-template
	fw/fw-harden
	fw/fw-config
	fw/fw-allow-all
	www/vpnmgmt/vpn_providers/nordvpn/vpn_update.sh
	setup/nordvpn-wireguard-setup.sh
	tests/run.sh
)

XML_FILES=(
	www/vpnmgmt/vpnservers.xml
	www/vpnmgmt/countryflags.xml
	www/vpnmgmt/vpn_providers/nordvpn/vpnservers.xml
	www/vpnmgmt/vpn_providers/private_internet_access/vpnservers.xml
	www/vpnmgmt/vpn_providers/purevpn/vpnservers.xml
	www/vpnmgmt/vpn_providers/newshosting/vpnservers.xml
)

echo "== PHP lint =="
while IFS= read -r -d '' f; do
	php -l "$f" >/dev/null
done < <(find www tests -name '*.php' -print0)
echo "   ok"

echo "== shell syntax (bash -n) =="
for s in "${SHELL_SCRIPTS[@]}"; do bash -n "$s"; done
echo "   ok"

echo "== XML validity =="
if command -v xmllint >/dev/null 2>&1; then
	for x in "${XML_FILES[@]}"; do [ -f "$x" ] && xmllint --noout "$x"; done
	echo "   ok"
else
	echo "   (xmllint not found, skipping)"
fi

echo "== shellcheck (advisory) =="
if command -v shellcheck >/dev/null 2>&1; then
	shellcheck -S warning "${SHELL_SCRIPTS[@]}" || echo "   (shellcheck reported findings; advisory only)"
else
	echo "   (shellcheck not found, skipping)"
fi

echo "== unit tests =="
php tests/test_vpn_backend.php
php tests/test_netstat.php

echo
echo "All checks complete."

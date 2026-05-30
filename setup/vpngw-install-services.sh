#!/bin/bash
#
# vpngw-install-services.sh
#
# Install the connection watchdog (auto-reconnect) and the server-list refresh
# as systemd timers (preferred) or cron jobs (fallback). Run as root.
#
# Env overrides:
#   VPNGW_WATCHDOG_INTERVAL   systemd OnUnitActiveSec (default 1min)
#   VPNGW_UPDATE_SCHEDULE     systemd OnCalendar      (default daily)
#
set -euo pipefail

[ "$(id -u)" -eq 0 ] || { echo "Please run as root (sudo $0)." >&2; exit 1; }

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
WATCHDOG="$DIR/vpngw-watchdog.sh"
UPDATER="$DIR/vpngw-update-servers.sh"
WD_INTERVAL="${VPNGW_WATCHDOG_INTERVAL:-1min}"
UPD_SCHEDULE="${VPNGW_UPDATE_SCHEDULE:-daily}"

[ -x "$WATCHDOG" ] || chmod +x "$WATCHDOG" 2>/dev/null || true
[ -x "$UPDATER" ]  || chmod +x "$UPDATER"  2>/dev/null || true

install -d -m 755 /etc/vpngw
if [ ! -f /etc/vpngw/watchdog.conf ]; then
	cat > /etc/vpngw/watchdog.conf <<EOF
enabled=true
ping_host=1.1.1.1
handshake_max_age=200
max_failures=2
rotate_after=3
EOF
fi

if [ -d /run/systemd/system ]; then
	cat > /etc/systemd/system/vpngw-watchdog.service <<EOF
[Unit]
Description=VPN Client Gateway watchdog (health check + auto-reconnect)
Wants=network-online.target
After=network-online.target

[Service]
Type=oneshot
ExecStart=$WATCHDOG
EOF

	cat > /etc/systemd/system/vpngw-watchdog.timer <<EOF
[Unit]
Description=Run the VPN Client Gateway watchdog periodically

[Timer]
OnBootSec=2min
OnUnitActiveSec=$WD_INTERVAL
AccuracySec=15s

[Install]
WantedBy=timers.target
EOF

	cat > /etc/systemd/system/vpngw-update-servers.service <<EOF
[Unit]
Description=Refresh the VPN Client Gateway server list
Wants=network-online.target
After=network-online.target

[Service]
Type=oneshot
ExecStart=$UPDATER
EOF

	cat > /etc/systemd/system/vpngw-update-servers.timer <<EOF
[Unit]
Description=Refresh the VPN Client Gateway server list on a schedule

[Timer]
OnCalendar=$UPD_SCHEDULE
Persistent=true

[Install]
WantedBy=timers.target
EOF

	systemctl daemon-reload
	systemctl enable --now vpngw-watchdog.timer vpngw-update-servers.timer
	echo "Installed systemd timers: vpngw-watchdog.timer (every $WD_INTERVAL), vpngw-update-servers.timer ($UPD_SCHEDULE)."
else
	cat > /etc/cron.d/vpngw <<EOF
# VPN Client Gateway: watchdog (auto-reconnect) + server-list refresh
* * * * * root $WATCHDOG >/dev/null 2>&1
17 4 * * * root $UPDATER >/dev/null 2>&1
EOF
	echo "No systemd detected. Installed cron jobs in /etc/cron.d/vpngw."
fi

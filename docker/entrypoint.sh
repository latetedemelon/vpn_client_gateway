#!/bin/bash
#
# Container entrypoint for the VPN Client Gateway.
#
# Enables IP forwarding, brings up the WireGuard tunnel (if a config is mounted),
# applies the firewall + kill switch, then runs the web UI (Apache) in the
# foreground. Designed for a container with NET_ADMIN + /dev/net/tun and host
# (or macvlan) networking. See documentation/lxc-docker.md.
#
set -u

log() { printf '[vpngw] %s\n' "$*"; }

# IP forwarding is namespaced; prefer "--sysctl net.ipv4.ip_forward=1" at run
# time, but try to set it here too.
sysctl -w net.ipv4.ip_forward=1 >/dev/null 2>&1 \
	|| log "could not set net.ipv4.ip_forward (pass --sysctl net.ipv4.ip_forward=1)"

install -d -m 755 /etc/vpngw
if [ -f /etc/wireguard/wg0.conf ] && [ ! -f /etc/vpngw/backend ]; then
	echo wireguard > /etc/vpngw/backend
fi

if [ -f /etc/wireguard/wg0.conf ]; then
	log "bringing up WireGuard (wg0)"
	wg-quick up wg0 \
		|| log "wg-quick up failed (need --cap-add=NET_ADMIN, --device /dev/net/tun, and the wireguard module on the host)"
fi

if [ -x /opt/vpngw/fw/fw-template ]; then
	log "applying firewall + kill switch"
	( cd /opt/vpngw/fw && cp -f fw-template fw-script && chmod +x fw-script && ./fw-script ) \
		|| log "firewall apply failed (need --cap-add=NET_ADMIN)"
fi

shutdown() {
	log "shutting down"
	wg-quick down wg0 2>/dev/null || true
	apache2ctl stop 2>/dev/null || true
	exit 0
}
trap shutdown TERM INT

# shellcheck disable=SC1091
. /etc/apache2/envvars 2>/dev/null || true
log "starting web UI on :80"
apache2ctl -DFOREGROUND &
APACHE_PID=$!
wait "$APACHE_PID"

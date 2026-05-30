#!/bin/bash
#
# Container entrypoint for the VPN Client Gateway.
#
# Enables IP forwarding, brings up the WireGuard tunnel (if a config is mounted),
# optionally applies the firewall + kill switch, then runs the web UI (Apache).
# See documentation/lxc-docker.md.
#
set -u

log() { printf '[vpngw] %s\n' "$*"; }
is_true() { case "$(printf '%s' "${1:-}" | tr 'A-Z' 'a-z')" in 1|true|yes|on) return 0;; *) return 1;; esac; }

# IP forwarding is namespaced; prefer "--sysctl net.ipv4.ip_forward=1" at run
# time, but try here too.
sysctl -w net.ipv4.ip_forward=1 >/dev/null 2>&1 \
	|| log "could not set net.ipv4.ip_forward (pass --sysctl net.ipv4.ip_forward=1)"

install -d -m 755 /etc/vpngw

# Warn loudly if the control UI will be unauthenticated -- dangerous especially
# under --network host, where it is reachable on the host's :80.
if ! grep -qiE '^[[:space:]]*mode[[:space:]]*=[[:space:]]*(basic|proxy)' /etc/vpngw/auth.conf 2>/dev/null; then
	log "WARNING: management UI auth is OFF -- anyone who can reach this container"
	log "         can switch servers, disable the VPN, reboot or shut down the box."
	log "         Configure /etc/vpngw/auth.conf (see documentation/authentication.md)."
fi

if [ -f /etc/wireguard/wg0.conf ] && [ ! -f /etc/vpngw/backend ]; then
	echo wireguard > /etc/vpngw/backend
fi

# Bring up the tunnel if a config is present (non-fatal).
if is_true "${VPNGW_WG_AUTOUP:-true}" && [ -f /etc/wireguard/wg0.conf ]; then
	log "bringing up WireGuard (wg0)"
	wg-quick up wg0 \
		|| log "wg-quick up failed (need --cap-add=NET_ADMIN, --device /dev/net/tun, and the wireguard module on the host)"
fi

# The firewall + kill switch is OPT-IN. It sets fail-closed DROP policies and,
# under --network host, mutates the HOST firewall, so enable it deliberately for
# a real gateway (VPNGW_APPLY_FIREWALL=1). Without it the image still routes/NATs
# if you bring the tunnel up, but there is no kill switch.
if is_true "${VPNGW_APPLY_FIREWALL:-false}"; then
	if [ -x /opt/vpngw/fw/fw-template ]; then
		log "applying firewall + kill switch"
		( cd /opt/vpngw/fw && cp -f fw-template fw-script && chmod +x fw-script && ./fw-script ) \
			|| log "firewall apply failed (need --cap-add=NET_ADMIN)"
	fi
else
	log "firewall/kill switch NOT applied (set VPNGW_APPLY_FIREWALL=1 to enable)"
fi

log "starting web UI on :80"
# Apache as PID 1 (php:apache ships apache2-foreground): it handles signals; the
# tunnel/firewall live in this container's netns and are torn down with it
# (except under --network host).
exec apache2-foreground

#!/bin/bash
#
# vpngw-watchdog.sh
#
# Connection watchdog + auto-reconnect for the VPN Client Gateway. Run on a
# schedule (systemd timer or cron, installed by setup/vpngw-install-services.sh).
#
# It checks that the tunnel is healthy; if not it reconnects, and after repeated
# failures it rotates to a fresh server. The kill switch is never touched, so
# the gateway stays fail-closed throughout (a dead tunnel drops traffic, it does
# not leak to the ISP).
#
# Config: /etc/vpngw/watchdog.conf
#   enabled=true|false        (default true)
#   ping_host=1.1.1.1         canary pinged through the tunnel
#   handshake_max_age=200     (WireGuard) seconds since last handshake => stale
#   max_failures=2            consecutive failures before reconnecting
#   rotate_after=3            consecutive failures before rotating servers
#
set -u

CONF="${VPNGW_WATCHDOG_CONF:-/etc/vpngw/watchdog.conf}"
BACKEND_FILE="${VPNGW_BACKEND_FILE:-/etc/vpngw/backend}"
WG_CONF="${VPNGW_WG_CONF:-/etc/wireguard/wg0.conf}"
WG_IFACE="${VPNGW_WG_IFACE:-wg0}"
STATE="${VPNGW_WATCHDOG_STATE:-/var/lib/vpngw/watchdog.fails}"
REC_API="https://api.nordvpn.com/v1/servers/recommendations?filters[servers_technologies][identifier]=wireguard_udp&limit=1"

log() { logger -t vpngw-watchdog -- "$*" 2>/dev/null || true; printf '[vpngw-watchdog] %s\n' "$*"; }

conf_get() {
	[ -r "$CONF" ] || return 0
	sed -n -E "s/^[[:space:]]*$1[[:space:]]*=[[:space:]]*\"?([^\"#]*)\"?.*/\1/Ip" "$CONF" | head -n1 | sed -E 's/[[:space:]]+$//'
}
is_true() { case "$(printf '%s' "${1:-}" | tr 'A-Z' 'a-z')" in 1|true|yes|on|enabled) return 0;; *) return 1;; esac; }

ENABLED="$(conf_get enabled)";              [ -n "$ENABLED" ] || ENABLED="true"
PING_HOST="$(conf_get ping_host)";          [ -n "$PING_HOST" ] || PING_HOST="1.1.1.1"
HS_MAX_AGE="$(conf_get handshake_max_age)"; [ -n "$HS_MAX_AGE" ] || HS_MAX_AGE="200"
MAX_FAILS="$(conf_get max_failures)";       [ -n "$MAX_FAILS" ] || MAX_FAILS="2"
ROTATE_AFTER="$(conf_get rotate_after)";    [ -n "$ROTATE_AFTER" ] || ROTATE_AFTER="3"

is_true "$ENABLED" || { exit 0; }

backend() {
	if [ -r "$BACKEND_FILE" ]; then tr '[:upper:]' '[:lower:]' < "$BACKEND_FILE" | tr -d '[:space:]'; return; fi
	[ -f /etc/openvpn/server.conf ] && { echo openvpn; return; }
	[ -f "$WG_CONF" ] && { echo wireguard; return; }
	echo openvpn
}

read_fails()  { [ -r "$STATE" ] && cat "$STATE" 2>/dev/null || echo 0; }
write_fails() { install -d -m 755 "$(dirname "$STATE")" 2>/dev/null || true; printf '%s' "$1" > "$STATE" 2>/dev/null || true; }

# --- health checks ---------------------------------------------------------
wg_healthy() {
	command -v wg >/dev/null 2>&1 || return 1
	wg show "$WG_IFACE" >/dev/null 2>&1 || return 1
	local latest now age
	latest="$(wg show "$WG_IFACE" latest-handshakes 2>/dev/null | awk '{print $2}' | sort -nr | head -n1)"
	[ -n "$latest" ] && [ "$latest" -gt 0 ] 2>/dev/null || return 1
	now="$(date +%s)"; age=$(( now - latest ))
	[ "$age" -le "$HS_MAX_AGE" ] || return 1
	ping -c1 -W3 -I "$WG_IFACE" "$PING_HOST" >/dev/null 2>&1
}
ovpn_iface() { ip -o link show 2>/dev/null | awk -F': ' '{print $2}' | sed 's/@.*//' | grep -m1 '^tun'; }
ovpn_healthy() {
	local i; i="$(ovpn_iface)"; [ -n "$i" ] || return 1
	ping -c1 -W3 -I "$i" "$PING_HOST" >/dev/null 2>&1
}

# --- recovery --------------------------------------------------------------
reconnect() {
	case "$(backend)" in
		wireguard) wg-quick down "$WG_IFACE" 2>/dev/null || true; wg-quick up "$WG_IFACE" 2>/dev/null || true;;
		*)         systemctl restart openvpn 2>/dev/null || service openvpn restart 2>/dev/null || true;;
	esac
}

# Rotate to a fresh recommended server (WireGuard/NordVPN). Rewrites the [Peer]
# block in wg0.conf, keeping [Interface], then brings the tunnel back up.
rotate_wireguard() {
	command -v curl >/dev/null 2>&1 && command -v jq >/dev/null 2>&1 || { log "rotate skipped (curl/jq missing)"; return 1; }
	local j host ip pub tmp
	j="$(curl -fsS -g --max-time 20 "$REC_API" 2>/dev/null)" || return 1
	host="$(printf '%s' "$j" | jq -r '.[0].hostname // empty')"
	ip="$(printf '%s' "$j"   | jq -r '.[0].station // empty')"
	pub="$(printf '%s' "$j"  | jq -r '[.[0].technologies[]|select(.identifier=="wireguard_udp")|.metadata[]|select(.name=="public_key")|.value]|.[0] // empty')"
	[ -n "$host" ] && [ -n "$ip" ] && [ -n "$pub" ] || { log "rotate: could not parse a server"; return 1; }
	tmp="$(mktemp)" || return 1
	awk 'BEGIN{p=1} /^\[Peer\]/{p=0} p{print}' "$WG_CONF" > "$tmp"
	sed -i -E "s/^[[:space:]]*#[[:space:]]*Server[[:space:]]*=.*/# Server = $host/I" "$tmp"
	{
		printf '\n[Peer]\n'
		printf 'PublicKey = %s\n' "$pub"
		printf 'AllowedIPs = 0.0.0.0/0, ::/0\n'
		printf 'Endpoint = %s:51820\n' "$ip"
		printf 'PersistentKeepalive = 25\n'
	} >> "$tmp"
	install -m 600 -o root -g root "$tmp" "$WG_CONF" && rm -f "$tmp"
	wg-quick down "$WG_IFACE" 2>/dev/null || true
	wg-quick up "$WG_IFACE" 2>/dev/null || true
	log "rotated to $host ($ip)"
}

# --- main ------------------------------------------------------------------
healthy() { case "$(backend)" in wireguard) wg_healthy;; *) ovpn_healthy;; esac; }

if healthy; then
	[ "$(read_fails)" != "0" ] && log "tunnel healthy again"
	write_fails 0
	exit 0
fi

fails=$(( $(read_fails) + 1 ))
write_fails "$fails"
log "tunnel unhealthy (consecutive failures: $fails)"

if [ "$fails" -ge "$ROTATE_AFTER" ] && [ "$(backend)" = "wireguard" ]; then
	log "rotating server after $fails failures"
	rotate_wireguard && write_fails 0
elif [ "$fails" -ge "$MAX_FAILS" ]; then
	log "reconnecting tunnel"
	reconnect
fi
exit 0

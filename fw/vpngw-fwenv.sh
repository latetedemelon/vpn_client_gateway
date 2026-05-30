#!/bin/bash
#
# vpngw-fwenv.sh  --  shared firewall environment (meant to be *sourced*).
#
# Resolves the LAN/WAN interfaces and leak-protection settings from
# /etc/vpngw/interfaces.conf and /etc/vpngw/leak.conf, and provides helper
# functions to (re)apply the IPv6 leak-block and forced DNS. Source it from a
# firewall script:
#
#     FW_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
#     . "$FW_DIR/vpngw-fwenv.sh"
#
# Exports: LAN_IFACE WAN_IFACE BLOCK_IPV6 FORCE_DNS DNS_SERVER
#
# If the config files are absent it auto-detects (WAN = default-route NIC, LAN =
# the other physical NIC) and finally falls back to eth0, so existing single-NIC
# installs behave exactly as before.

VPNGW_IFCONF="${VPNGW_IFCONF:-/etc/vpngw/interfaces.conf}"
VPNGW_LEAKCONF="${VPNGW_LEAKCONF:-/etc/vpngw/leak.conf}"

# _vpngw_get <file> <key>  -> prints the value (quotes/trailing space stripped)
_vpngw_get() {
	[ -r "$1" ] || return 0
	sed -n -E "s/^[[:space:]]*$2[[:space:]]*=[[:space:]]*\"?([^\"#]*)\"?.*/\1/Ip" "$1" \
		| head -n1 | sed -E 's/[[:space:]]+$//'
}
_vpngw_is_true() {
	case "$(printf '%s' "${1:-}" | tr 'A-Z' 'a-z')" in
		1|true|yes|on|enabled) return 0;;
		*) return 1;;
	esac
}
_vpngw_is_tunnel() { case "$1" in wg*|tun*|tap*) return 0;; *) return 1;; esac; }
_vpngw_is_virtual() {
	case "$1" in
		lo|wg*|tun*|tap*|veth*|docker*|br-*|virbr*|vmnet*|kube*) return 0;;
		*) return 1;;
	esac
}

_vpngw_default_iface() {
	local dev
	for dev in $(ip route show default 2>/dev/null | awk '{for(i=1;i<=NF;i++) if($i=="dev") print $(i+1)}'); do
		_vpngw_is_tunnel "$dev" && continue
		echo "$dev"; return 0
	done
	return 1
}
_vpngw_first_phys() {   # $1 = name to skip (optional)
	local n
	for n in $(ip -o link show 2>/dev/null | awk -F': ' '{print $2}' | sed 's/@.*//'); do
		[ "$n" = "${1:-}" ] && continue
		_vpngw_is_virtual "$n" && continue
		echo "$n"; return 0
	done
	return 1
}

WAN_IFACE="$(_vpngw_get "$VPNGW_IFCONF" wan_iface)"
[ -n "$WAN_IFACE" ] || WAN_IFACE="$(_vpngw_default_iface || true)"
[ -n "$WAN_IFACE" ] || WAN_IFACE="$(_vpngw_first_phys || true)"
[ -n "$WAN_IFACE" ] || WAN_IFACE="eth0"

LAN_IFACE="$(_vpngw_get "$VPNGW_IFCONF" lan_iface)"
[ -n "$LAN_IFACE" ] || LAN_IFACE="$(_vpngw_first_phys "$WAN_IFACE" || true)"
[ -n "$LAN_IFACE" ] || LAN_IFACE="$WAN_IFACE"

BLOCK_IPV6="$(_vpngw_get "$VPNGW_LEAKCONF" block_ipv6)"; [ -n "$BLOCK_IPV6" ] || BLOCK_IPV6="true"
FORCE_DNS="$(_vpngw_get "$VPNGW_LEAKCONF" force_dns)";   [ -n "$FORCE_DNS" ]  || FORCE_DNS="false"
DNS_SERVER="$(_vpngw_get "$VPNGW_LEAKCONF" dns_server)"; [ -n "$DNS_SERVER" ] || DNS_SERVER="103.86.96.100"

export LAN_IFACE WAN_IFACE BLOCK_IPV6 FORCE_DNS DNS_SERVER

# Fail-closed IPv6: drop forward/egress (keep loopback + ICMPv6/NDP) so a
# dual-stack client can't bypass the IPv4 tunnel. Reset to ACCEPT when disabled.
vpngw_apply_ipv6_block() {
	command -v ip6tables >/dev/null 2>&1 || { echo "ip6tables not found; skipping IPv6 leak-block" >&2; return 0; }
	if _vpngw_is_true "$BLOCK_IPV6"; then
		ip6tables -P INPUT DROP
		ip6tables -P FORWARD DROP
		ip6tables -P OUTPUT DROP
		ip6tables -F
		ip6tables -A INPUT  -i lo -j ACCEPT
		ip6tables -A OUTPUT -o lo -j ACCEPT
		ip6tables -A INPUT  -p ipv6-icmp -j ACCEPT
		ip6tables -A OUTPUT -p ipv6-icmp -j ACCEPT
	else
		ip6tables -P INPUT ACCEPT
		ip6tables -P FORWARD ACCEPT
		ip6tables -P OUTPUT ACCEPT
		ip6tables -F
	fi
}

# Force client DNS through the tunnel: DNAT LAN :53 to DNS_SERVER so devices
# cannot use their own resolver. No-op unless force_dns is enabled.
vpngw_apply_force_dns() {
	_vpngw_is_true "$FORCE_DNS" || return 0
	iptables -t nat -A PREROUTING -i "$LAN_IFACE" -p udp --dport 53 -j DNAT --to-destination "$DNS_SERVER:53"
	iptables -t nat -A PREROUTING -i "$LAN_IFACE" -p tcp --dport 53 -j DNAT --to-destination "$DNS_SERVER:53"
}

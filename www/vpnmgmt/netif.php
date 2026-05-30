<?php
// ---------------------------------------------------------------------------
// LAN / WAN interface resolution.
//
// Historically the firewall and NAT code hard-coded "eth0". That breaks on
// multi-NIC boxes, VMs and containers. Interfaces are now resolved here:
//
//   * explicit override in /etc/vpngw/interfaces.conf wins:
//         lan_iface=eth0
//         wan_iface=eth1
//   * otherwise auto-detect:
//         WAN = the main-table default-route interface (the internet uplink),
//               ignoring the VPN tunnel (see netstat.php)
//         LAN = the first other physical NIC; on a single-NIC box LAN == WAN.
//
// Pure parsing/classification lives in config.php + netstat.php (unit-tested);
// this layer only adds the /proc reads and the small policy of which is which.
// ---------------------------------------------------------------------------

require_once(__DIR__ . '/config.php');
require_once(__DIR__ . '/netstat.php');

if (!defined('VPNGW_INTERFACES_CONF')) {
	define('VPNGW_INTERFACES_CONF', '/etc/vpngw/interfaces.conf');
}

// Best-effort name of the active VPN interface without hard-depending on
// vpn_backend.php (avoids an include cycle); resolved lazily at call time.
function vpngw_vpn_iface_name()
{
	return function_exists('vpn_iface') ? vpn_iface() : 'wg0';
}

function vpngw_iface_conf()
{
	return vpngw_load_conf(VPNGW_INTERFACES_CONF);
}

// The outflow / uplink interface used to reach the internet (and the ISP link
// when the VPN is disabled).
function vpngw_wan_iface()
{
	$cfg = vpngw_iface_conf();
	if (!empty($cfg['wan_iface'])) {
		return $cfg['wan_iface'];
	}
	$vpn = vpngw_vpn_iface_name();

	$routes = @file_get_contents('/proc/net/route');
	$w = tp_parse_default_iface($routes === false ? '' : $routes, $vpn);
	if ($w !== '') {
		return $w;
	}
	// No default route found: fall back to the first physical NIC.
	$dev = @file_get_contents('/proc/net/dev');
	foreach (array_keys(tp_parse_proc_net_dev($dev === false ? '' : $dev)) as $n) {
		if (tp_is_physical_iface($n, $vpn)) {
			return $n;
		}
	}
	return 'eth0';
}

// The client-facing LAN interface. On a single-NIC gateway this is the same as
// the WAN interface (the one NIC carries both).
function vpngw_lan_iface()
{
	$cfg = vpngw_iface_conf();
	if (!empty($cfg['lan_iface'])) {
		return $cfg['lan_iface'];
	}
	$vpn = vpngw_vpn_iface_name();
	$wan = vpngw_wan_iface();

	$dev = @file_get_contents('/proc/net/dev');
	foreach (array_keys(tp_parse_proc_net_dev($dev === false ? '' : $dev)) as $n) {
		if ($n === $wan || $n === $vpn || $n === 'lo') {
			continue;
		}
		if (tp_is_physical_iface($n, $vpn)) {
			return $n;
		}
	}
	return $wan;
}
?>

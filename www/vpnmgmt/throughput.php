<?php
// AJAX endpoint for the live throughput meter. Returns the current cumulative
// byte counters (plus a server timestamp) for every relevant interface, each
// tagged with its role:
//   vpn  - the VPN tunnel (wg0 / tun0)
//   wan  - the outflow NIC: the physical uplink to the internet
//   lan  - an inflow NIC: a client-facing physical interface
// The browser samples this every couple of seconds and derives the rate from
// successive samples, so there is no server-side state and no blocking sleep.
//
// Reads /proc/net/dev and /proc/net/route (both world-readable) only, so no
// elevated privileges are required and nothing about the firewall/kill switch
// or services is touched. On a single-NIC box there are no separate LAN rows;
// the one physical NIC is reported as the WAN/uplink.

require_once(__DIR__ . '/vpn_backend.php');
require_once(__DIR__ . '/netstat.php');

header('Content-Type: application/json');
header('Cache-Control: no-store');

$raw    = @file_get_contents('/proc/net/dev');
$ifaces = tp_parse_proc_net_dev($raw === false ? '' : $raw);

$routes   = @file_get_contents('/proc/net/route');
$vpn_iface = vpn_iface();
$wan_hint  = tp_parse_default_iface($routes === false ? '' : $routes, $vpn_iface);
$cls       = tp_classify_ifaces($ifaces, $vpn_iface, $wan_hint);

// Helper to build one row for an interface.
$row = function ($role, $iface) use ($ifaces) {
	$has = isset($ifaces[$iface]);
	return array(
		'role'  => $role,
		'iface' => $iface,
		'up'    => $has,
		'rx'    => $has ? $ifaces[$iface]['rx'] : null,
		'tx'    => $has ? $ifaces[$iface]['tx'] : null,
	);
};

$rows = array();

// VPN tunnel first. "up" also requires the gateway not to be disabled.
$vpn_has   = isset($ifaces[$vpn_iface]);
$rows[]    = array(
	'role'  => 'vpn',
	'iface' => $vpn_iface,
	'up'    => $vpn_has && !vpn_is_disabled(),
	'rx'    => $vpn_has ? $ifaces[$vpn_iface]['rx'] : null,
	'tx'    => $vpn_has ? $ifaces[$vpn_iface]['tx'] : null,
);

// Outflow / WAN NIC.
if ($cls['wan'] !== '') {
	$rows[] = $row('wan', $cls['wan']);
}

// Inflow / LAN NIC(s).
foreach ($cls['lan'] as $lan_iface) {
	$rows[] = $row('lan', $lan_iface);
}

echo json_encode(array(
	't'     => (int) round(microtime(true) * 1000), // ms on the server clock
	'multi' => count($cls['lan']) > 0,               // true when LAN NICs are distinct
	'rows'  => $rows,
));
?>

<?php
// AJAX endpoint for the live throughput meter. Returns the current cumulative
// byte counters (plus a server timestamp) for the VPN interface and the primary
// physical interface ("the whole box"). The browser samples this every couple
// of seconds and derives the rate from successive samples, so there is no
// server-side state and no blocking sleep.
//
// Reads /proc/net/dev (world-readable) only, so no elevated privileges are
// required and nothing about the firewall / kill switch is touched.

require_once(__DIR__ . '/vpn_backend.php');
require_once(__DIR__ . '/netstat.php');

header('Content-Type: application/json');
header('Cache-Control: no-store');

$raw    = @file_get_contents('/proc/net/dev');
$ifaces = tp_parse_proc_net_dev($raw === false ? '' : $raw);

$vpn_iface = vpn_iface();
$box_iface = tp_pick_box_iface($ifaces, $vpn_iface);

$vpn_has = isset($ifaces[$vpn_iface]);
$box_has = isset($ifaces[$box_iface]);

$resp = array(
	't'   => (int) round(microtime(true) * 1000), // ms on the server clock
	'vpn' => array(
		'iface' => $vpn_iface,
		// "up" when the tunnel interface exists and the gateway isn't disabled.
		'up'    => $vpn_has && !vpn_is_disabled(),
		'rx'    => $vpn_has ? $ifaces[$vpn_iface]['rx'] : null,
		'tx'    => $vpn_has ? $ifaces[$vpn_iface]['tx'] : null,
	),
	'box' => array(
		'iface' => $box_iface,
		'up'    => $box_has,
		'rx'    => $box_has ? $ifaces[$box_iface]['rx'] : null,
		'tx'    => $box_has ? $ifaces[$box_iface]['tx'] : null,
	),
);

echo json_encode($resp);
?>

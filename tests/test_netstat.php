<?php
// Unit tests for the throughput helpers (www/vpnmgmt/netstat.php).
//
// Run:  php tests/test_netstat.php
//
// These functions are pure (no I/O, no privileges), so they are exercised
// directly with sample /proc/net/dev and /proc/net/route text and interface
// maps.

error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE & ~E_DEPRECATED);

require __DIR__ . '/../www/vpnmgmt/netstat.php';

$TESTS = 0;
$FAILS = 0;
function check($name, $cond)
{
	global $TESTS, $FAILS;
	$TESTS++;
	if ($cond) {
		echo "  PASS  $name\n";
	} else {
		$FAILS++;
		echo "  FAIL  $name\n";
	}
}

// ---------------------------------------------------------------------------
echo "proc/net/dev parsing\n";

$proc = "Inter-|   Receive                                                |  Transmit\n"
	. " face |bytes    packets errs drop fifo frame compressed multicast|bytes    packets errs drop fifo colls carrier compressed\n"
	. "    lo:    1234      12    0    0    0     0          0         0     1234      12    0    0    0     0       0          0\n"
	. "  eth0: 1000000    5000    0    0    0     0          0         0   200000    4000    0    0    0     0       0          0\n"
	. "   wg0:  500000    2500    0    0    0     0          0         0   100000    2000    0    0    0     0       0          0\n";

$p = tp_parse_proc_net_dev($proc);
check('parses all three interfaces', count($p) === 3);
check('eth0 rx bytes', isset($p['eth0']) && $p['eth0']['rx'] === 1000000);
check('eth0 tx bytes', isset($p['eth0']) && $p['eth0']['tx'] === 200000);
check('wg0 rx bytes', isset($p['wg0']) && $p['wg0']['rx'] === 500000);
check('wg0 tx bytes', isset($p['wg0']) && $p['wg0']['tx'] === 100000);
check('lo present', isset($p['lo']));
check('header lines ignored', !isset($p['Inter-|']) && !isset($p['face']));
check('empty input -> empty array', tp_parse_proc_net_dev('') === array());
check('non-string -> empty array', tp_parse_proc_net_dev(null) === array());

$jammed = "enp0s3:9999999 1 0 0 0 0 0 0 8888888 1 0 0 0 0 0 0\n";
$pj = tp_parse_proc_net_dev($jammed);
check('handles value adjacent to colon',
	isset($pj['enp0s3']) && $pj['enp0s3']['rx'] === 9999999 && $pj['enp0s3']['tx'] === 8888888);

check('skips malformed (too few columns) row', tp_parse_proc_net_dev("  eth0: 12 34\n") === array());

// ---------------------------------------------------------------------------
echo "default-route (outflow NIC) parsing\n";

$route_hdr = "Iface\tDestination\tGateway\tFlags\tRefCnt\tUse\tMetric\tMask\tMTU\tWindow\tIRTT\n";
// eth0 has the (physical) default; wg0 also advertises a default with a lower
// metric. We must report the physical NIC, not the VPN tunnel.
$route1 = $route_hdr
	. "eth0\t00000000\t0102A8C0\t0003\t0\t0\t100\t00000000\t0\t0\t0\n"
	. "eth0\t0002A8C0\t00000000\t0001\t0\t0\t0\t00FFFFFF\t0\t0\t0\n"
	. "wg0\t00000000\t00000000\t0001\t0\t0\t50\t00000000\t0\t0\t0\n";
check('returns the physical default iface, not the VPN', tp_parse_default_iface($route1, 'wg0') === 'eth0');
check('header row is ignored', tp_parse_default_iface($route1, 'wg0') !== 'Iface');

// Two physical defaults: lowest metric wins.
$route2 = $route_hdr
	. "eth1\t00000000\t0102A8C0\t0003\t0\t0\t600\t00000000\t0\t0\t0\n"
	. "eth0\t00000000\t0103A8C0\t0003\t0\t0\t100\t00000000\t0\t0\t0\n";
check('lowest-metric default wins', tp_parse_default_iface($route2, 'wg0') === 'eth0');

// WAN on a second NIC, LAN-only first NIC (no default on eth0).
$route3 = $route_hdr
	. "eth1\t00000000\t0102A8C0\t0003\t0\t0\t100\t00000000\t0\t0\t0\n"
	. "eth0\t0002A8C0\t00000000\t0001\t0\t0\t0\t00FFFFFF\t0\t0\t0\n";
check('identifies WAN on the second NIC', tp_parse_default_iface($route3, 'wg0') === 'eth1');

check('no default route -> empty', tp_parse_default_iface($route_hdr . "eth0\t0002A8C0\t00000000\t0001\t0\t0\t0\t00FFFFFF\t0\t0\t0\n", 'wg0') === '');
check('empty input -> empty', tp_parse_default_iface('', 'wg0') === '');
check('garbage -> empty', tp_parse_default_iface("not a route table\n", 'wg0') === '');

// ---------------------------------------------------------------------------
echo "physical interface detection\n";
check('eth0 is physical', tp_is_physical_iface('eth0', 'wg0'));
check('enp3s0 is physical', tp_is_physical_iface('enp3s0', 'wg0'));
check('wlan0 is physical', tp_is_physical_iface('wlan0', 'wg0'));
check('loopback is not physical', !tp_is_physical_iface('lo', 'wg0'));
check('the vpn iface is not physical', !tp_is_physical_iface('wg0', 'wg0'));
check('docker0 is not physical', !tp_is_physical_iface('docker0', 'wg0'));
check('veth* is not physical', !tp_is_physical_iface('veth1a2b3c', 'wg0'));
check('br- bridge is not physical', !tp_is_physical_iface('br-abc123', 'wg0'));
check('other tun is not physical', !tp_is_physical_iface('tun5', 'wg0'));
check('other wg is not physical', !tp_is_physical_iface('wg1', 'wg0'));

// ---------------------------------------------------------------------------
echo "interface classification (outflow WAN vs inflow LAN)\n";

// Single-NIC box: the one physical NIC is the WAN/uplink, no separate LAN rows.
$c = tp_classify_ifaces(array('lo' => 1, 'wg0' => 1, 'eth0' => 1), 'wg0', 'eth0');
check('single NIC -> wan=eth0', $c['wan'] === 'eth0');
check('single NIC -> no LAN rows', $c['lan'] === array());

// Dual-NIC box with a route hint: eth1 is WAN, eth0 is LAN.
$c = tp_classify_ifaces(array('lo' => 1, 'wg0' => 1, 'eth0' => 1, 'eth1' => 1), 'wg0', 'eth1');
check('dual NIC -> wan=eth1 (from route)', $c['wan'] === 'eth1');
check('dual NIC -> lan=[eth0]', $c['lan'] === array('eth0'));

// No route hint: fall back to the name heuristic (prefers ethX).
$c = tp_classify_ifaces(array('lo' => 1, 'wg0' => 1, 'eth0' => 1, 'eth1' => 1), 'wg0', '');
check('no hint -> wan falls back to eth0', $c['wan'] === 'eth0');
check('no hint -> lan=[eth1]', $c['lan'] === array('eth1'));

// Virtual interfaces are excluded entirely.
$c = tp_classify_ifaces(array('lo' => 1, 'wg0' => 1, 'eth0' => 1, 'docker0' => 1, 'veth9' => 1), 'wg0', 'eth0');
check('virtual ifaces excluded -> wan=eth0', $c['wan'] === 'eth0');
check('virtual ifaces excluded -> no LAN rows', $c['lan'] === array());

// Multiple LAN NICs are returned sorted.
$c = tp_classify_ifaces(array('eth2' => 1, 'eth0' => 1, 'eth1' => 1, 'wg0' => 1, 'lo' => 1), 'wg0', 'eth1');
check('multiple LAN NICs are sorted', $c['lan'] === array('eth0', 'eth2'));

// A stale/absent route hint still yields a sensible WAN.
$c = tp_classify_ifaces(array('lo' => 1, 'tun0' => 1, 'wlan0' => 1), 'tun0', 'ppp0');
check('absent hint -> wan falls back to a present NIC', $c['wan'] === 'wlan0');

// ---------------------------------------------------------------------------
echo "box interface selection (name heuristic / fallback)\n";
check('prefers eth0 over vpn and lo',
	tp_pick_box_iface(array('lo' => 1, 'wg0' => 1, 'eth0' => 1), 'wg0') === 'eth0');
check('never returns the vpn iface',
	tp_pick_box_iface(array('lo' => 1, 'wg0' => 1), 'wg0') !== 'wg0');
check('falls back to eth0 when nothing suitable present',
	tp_pick_box_iface(array('lo' => 1, 'wg0' => 1), 'wg0') === 'eth0');
check('picks enX physical name when no ethX',
	tp_pick_box_iface(array('lo' => 1, 'tun0' => 1, 'enp0s3' => 1), 'tun0') === 'enp0s3');
check('prefers eth over wlan',
	tp_pick_box_iface(array('wlan0' => 1, 'eth0' => 1), 'wg0') === 'eth0');

// ---------------------------------------------------------------------------
echo "\n";
if ($FAILS === 0) {
	echo "ALL $TESTS TESTS PASSED\n";
	exit(0);
}
echo "$FAILS of $TESTS TESTS FAILED\n";
exit(1);
?>

<?php
// Unit tests for the throughput helpers (www/vpnmgmt/netstat.php).
//
// Run:  php tests/test_netstat.php
//
// These functions are pure (no I/O, no privileges), so they are exercised
// directly with sample /proc/net/dev text and interface maps.

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

// Value adjacent to the colon (long iface name / large counter).
$jammed = "enp0s3:9999999 1 0 0 0 0 0 0 8888888 1 0 0 0 0 0 0\n";
$pj = tp_parse_proc_net_dev($jammed);
check('handles value adjacent to colon',
	isset($pj['enp0s3']) && $pj['enp0s3']['rx'] === 9999999 && $pj['enp0s3']['tx'] === 8888888);

// A short/garbled row must be skipped, not fatal.
$short = "  eth0: 12 34\n";
check('skips malformed (too few columns) row', tp_parse_proc_net_dev($short) === array());

// ---------------------------------------------------------------------------
echo "box interface selection\n";
check('prefers eth0 over vpn and lo',
	tp_pick_box_iface(array('lo' => 1, 'wg0' => 1, 'eth0' => 1), 'wg0') === 'eth0');
check('never returns the vpn iface',
	tp_pick_box_iface(array('lo' => 1, 'wg0' => 1), 'wg0') !== 'wg0');
check('falls back to eth0 when nothing suitable present',
	tp_pick_box_iface(array('lo' => 1, 'wg0' => 1), 'wg0') === 'eth0');
check('picks enX physical name when no ethX',
	tp_pick_box_iface(array('lo' => 1, 'tun0' => 1, 'enp0s3' => 1), 'tun0') === 'enp0s3');
check('picks wlan when only wifi present',
	tp_pick_box_iface(array('lo' => 1, 'wg0' => 1, 'wlan0' => 1), 'wg0') === 'wlan0');
check('prefers eth over wlan',
	tp_pick_box_iface(array('wlan0' => 1, 'eth0' => 1), 'wg0') === 'eth0');
check('ignores loopback as a candidate',
	tp_pick_box_iface(array('lo' => 1), 'wg0') === 'eth0');

// ---------------------------------------------------------------------------
echo "\n";
if ($FAILS === 0) {
	echo "ALL $TESTS TESTS PASSED\n";
	exit(0);
}
echo "$FAILS of $TESTS TESTS FAILED\n";
exit(1);
?>

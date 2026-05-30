<?php
// Unit tests for the status / DNS-leak pure helpers (status_lib.php).
//
// Run:  php tests/test_status.php

error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE & ~E_DEPRECATED);

require __DIR__ . '/../www/vpnmgmt/status_lib.php';

$TESTS = 0;
$FAILS = 0;
function check($name, $cond)
{
	global $TESTS, $FAILS;
	$TESTS++;
	echo ($cond ? "  PASS  " : "  FAIL  ") . $name . "\n";
	if (!$cond) {
		$FAILS++;
	}
}

// ---------------------------------------------------------------------------
echo "wg dump handshake parsing\n";
// device line, then two peers; newest handshake should win.
$dump = "PRIVKEY\tPUBKEY\t51820\toff\n"
	. "PEER1\t(none)\t1.2.3.4:51820\t0.0.0.0/0\t1700000000\t100\t200\t25\n"
	. "PEER2\t(none)\t5.6.7.8:51820\t0.0.0.0/0\t1700000500\t300\t400\t25\n";
check('newest handshake across peers', vpn_parse_wg_dump_handshake($dump) === 1700000500);
check('device-only dump -> 0', vpn_parse_wg_dump_handshake("PRIVKEY\tPUBKEY\t51820\toff\n") === 0);
check('never-handshaked peer (0) -> 0',
	vpn_parse_wg_dump_handshake("DEV\tK\t51820\toff\nPEER\t(none)\tx\t0.0.0.0/0\t0\t0\t0\t0\n") === 0);
check('empty -> 0', vpn_parse_wg_dump_handshake('') === 0);
check('non-string -> 0', vpn_parse_wg_dump_handshake(null) === 0);

// ---------------------------------------------------------------------------
echo "age formatting\n";
check('seconds', vpn_format_age(5) === '5 seconds ago');
check('singular second', vpn_format_age(1) === '1 second ago');
check('minutes', vpn_format_age(120) === '2 minutes ago');
check('singular minute', vpn_format_age(60) === '1 minute ago');
check('hours', vpn_format_age(7200) === '2 hours ago');
check('days', vpn_format_age(172800) === '2 days ago');
check('negative -> unknown', vpn_format_age(-1) === 'unknown');

// ---------------------------------------------------------------------------
echo "DNS leak evaluation\n";
$expected = array('103.86.96.100', '103.86.99.100');

$r = vpn_dns_leak_eval(array('103.86.96.100'), $expected);
check('only VPN resolver -> pass', $r['status'] === 'pass' && $r['leaking'] === false);

$r = vpn_dns_leak_eval(array('103.86.96.100', '8.8.8.8'), $expected);
check('an external resolver -> leak', $r['status'] === 'leak' && $r['leaking'] === true);
check('leak lists the offender', $r['unexpected'] === array('8.8.8.8'));

$r = vpn_dns_leak_eval(array('1.1.1.1'), $expected);
check('ISP/other resolver -> leak', $r['status'] === 'leak' && $r['unexpected'] === array('1.1.1.1'));

$r = vpn_dns_leak_eval(array(), $expected);
check('no resolvers detected -> unknown', $r['status'] === 'unknown' && $r['leaking'] === false);

$r = vpn_dns_leak_eval(array('103.86.96.100', '103.86.96.100'), $expected);
check('duplicates collapse, still pass', $r['status'] === 'pass');

$r = vpn_dns_leak_eval(array(' 103.86.99.100 ', ''), $expected);
check('whitespace/empties tolerated', $r['status'] === 'pass');

// ---------------------------------------------------------------------------
echo "\n";
if ($FAILS === 0) {
	echo "ALL $TESTS TESTS PASSED\n";
	exit(0);
}
echo "$FAILS of $TESTS TESTS FAILED\n";
exit(1);
?>

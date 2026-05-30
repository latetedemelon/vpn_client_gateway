<?php
// Unit tests for provider selection (provider.php).
//
// Run:  php tests/test_provider.php

error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE & ~E_DEPRECATED);

define('VPNGW_PROVIDER_FILE', sys_get_temp_dir() . '/vpngw_nonexistent_provider');
require __DIR__ . '/../www/vpnmgmt/provider.php';

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

echo "provider name validation\n";
check('accepts nordvpn', vpngw_valid_provider('nordvpn'));
check('accepts custom', vpngw_valid_provider('custom'));
check('accepts underscores/digits', vpngw_valid_provider('my_vpn2'));
check('rejects path traversal', !vpngw_valid_provider('../etc'));
check('rejects slashes', !vpngw_valid_provider('a/b'));
check('rejects empty', !vpngw_valid_provider(''));
check('rejects uppercase', !vpngw_valid_provider('NordVPN'));

echo "active provider\n";
check('defaults to nordvpn when unset', vpngw_provider() === 'nordvpn');

echo "provider dir resolution\n";
check('dir ends with the provider name', substr(vpngw_provider_dir('nordvpn'), -8) === '/nordvpn');
check('bad name falls back to nordvpn dir', substr(vpngw_provider_dir('../x'), -8) === '/nordvpn');

echo "known providers (scans vpn_providers/)\n";
$known = vpngw_known_providers();
check('nordvpn present', in_array('nordvpn', $known, true));
check('private_internet_access present', in_array('private_internet_access', $known, true));
check('no dot entries', !in_array('.', $known, true) && !in_array('..', $known, true));

echo "\n";
if ($FAILS === 0) {
	echo "ALL $TESTS TESTS PASSED\n";
	exit(0);
}
echo "$FAILS of $TESTS TESTS FAILED\n";
exit(1);
?>

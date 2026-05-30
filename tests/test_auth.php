<?php
// Unit tests for the config reader (config.php) and the auth guard (auth.php).
//
// Run:  php tests/test_auth.php
//
// We define VPNGW_AUTH_ENFORCED before including auth.php so that including it
// does NOT run the on-include enforcement (which would read /etc and possibly
// exit). The decision logic is pure and exercised directly.

error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE & ~E_DEPRECATED);

define('VPNGW_AUTH_ENFORCED', 1);          // suppress auto-enforcement on include
define('VPNGW_AUTH_CONF', sys_get_temp_dir() . '/vpngw_nonexistent_auth.conf');
require __DIR__ . '/../www/vpnmgmt/auth.php';   // also pulls in config.php

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
echo "config parsing (vpngw_parse_kv)\n";
$kv = vpngw_parse_kv("mode = basic\n# comment\n; also comment\n\nUsername=admin\nrealm=\"VPN Gateway\"\n");
check('lower-cases keys, trims spaces', isset($kv['mode']) && $kv['mode'] === 'basic');
check('comments (# and ;) ignored', !isset($kv['# comment']) && count($kv) === 3);
check('keys are case-insensitive', isset($kv['username']) && $kv['username'] === 'admin');
check('surrounding quotes stripped', $kv['realm'] === 'VPN Gateway');
$kv2 = vpngw_parse_kv("password_hash=\$2y\$10\$abc=def=\n");
check('value may contain = signs', $kv2['password_hash'] === '$2y$10$abc=def=');
check('empty input -> empty array', vpngw_parse_kv('') === array());
check('bool: yes/on/1/true', vpngw_conf_bool('yes') && vpngw_conf_bool('on') && vpngw_conf_bool('1') && vpngw_conf_bool('true'));
check('bool: no/off/0/false', !vpngw_conf_bool('no') && !vpngw_conf_bool('off') && !vpngw_conf_bool('0') && !vpngw_conf_bool('false'));
check('bool: default for unknown', vpngw_conf_bool('maybe', true) === true && vpngw_conf_bool(null, false) === false);

// ---------------------------------------------------------------------------
echo "auth decision: off\n";
$d = vpngw_auth_decision(array(), array());
check('no config -> allowed (off)', $d['ok'] === true && $d['mode'] === 'off');
$d = vpngw_auth_decision(array('mode' => 'off'), array());
check('explicit off -> allowed', $d['ok'] === true);

// ---------------------------------------------------------------------------
echo "auth decision: basic\n";
$hash = password_hash('s3cret', PASSWORD_DEFAULT);
$cfg  = array('mode' => 'basic', 'username' => 'admin', 'password_hash' => $hash);

$d = vpngw_auth_decision($cfg, array('PHP_AUTH_USER' => 'admin', 'PHP_AUTH_PW' => 's3cret'));
check('correct credentials -> allowed', $d['ok'] === true && $d['mode'] === 'basic');

$d = vpngw_auth_decision($cfg, array('PHP_AUTH_USER' => 'admin', 'PHP_AUTH_PW' => 'wrong'));
check('wrong password -> 401', $d['ok'] === false && $d['status'] === 401);

$d = vpngw_auth_decision($cfg, array('PHP_AUTH_USER' => 'eve', 'PHP_AUTH_PW' => 's3cret'));
check('wrong username -> 401', $d['ok'] === false && $d['status'] === 401);

$d = vpngw_auth_decision($cfg, array());
check('no credentials -> 401 with WWW-Authenticate', $d['ok'] === false && $d['status'] === 401
	&& isset($d['headers'][0]) && stripos($d['headers'][0], 'WWW-Authenticate: Basic') === 0);

// Credentials carried in a raw Authorization header (CGI/FastCGI).
$authz = 'Basic ' . base64_encode('admin:s3cret');
$d = vpngw_auth_decision($cfg, array('HTTP_AUTHORIZATION' => $authz));
check('reads Authorization header (base64)', $d['ok'] === true);

$d = vpngw_auth_decision(array('mode' => 'basic'), array('PHP_AUTH_USER' => 'admin', 'PHP_AUTH_PW' => 'x'));
check('basic with no configured creds -> denied', $d['ok'] === false);

// ---------------------------------------------------------------------------
echo "auth decision: proxy / SSO header\n";
// proxy mode requires trusted_proxies, else it fails closed.
$d = vpngw_auth_decision(array('mode' => 'proxy'), array('HTTP_REMOTE_USER' => 'eve'));
check('proxy without trusted_proxies -> denied (fail closed)', $d['ok'] === false && $d['status'] === 500);

$cfg = array('mode' => 'proxy', 'trusted_proxies' => '10.0.0.0/8'); // default header Remote-User
$d = vpngw_auth_decision($cfg, array('HTTP_REMOTE_USER' => 'alice', 'REMOTE_ADDR' => '10.0.0.5'));
check('user header from trusted proxy -> allowed', $d['ok'] === true && $d['mode'] === 'proxy');
$d = vpngw_auth_decision($cfg, array('REMOTE_ADDR' => '10.0.0.5'));
check('user header absent -> 403', $d['ok'] === false && $d['status'] === 403);
$d = vpngw_auth_decision($cfg, array('HTTP_REMOTE_USER' => 'eve', 'REMOTE_ADDR' => '8.8.8.8'));
check('header from untrusted address -> 403', $d['ok'] === false && $d['status'] === 403);

$cfg = array('mode' => 'proxy', 'user_header' => 'X-Forwarded-User', 'trusted_proxies' => '127.0.0.1, ::1');
$d = vpngw_auth_decision($cfg, array('HTTP_X_FORWARDED_USER' => 'bob', 'REMOTE_ADDR' => '127.0.0.1'));
check('custom user_header honored (trusted)', $d['ok'] === true);

// ---------------------------------------------------------------------------
echo "auth decision: invalid mode fails closed\n";
$d = vpngw_auth_decision(array('mode' => 'banana'), array());
check('unknown mode -> denied (500)', $d['ok'] === false && $d['status'] === 500);

// ---------------------------------------------------------------------------
echo "ip / CIDR matching\n";
check('exact IPv4 match', vpngw_ip_in_list('192.168.1.5', '192.168.1.5'));
check('IPv4 CIDR match', vpngw_ip_in_list('10.4.5.6', '10.0.0.0/8'));
check('IPv4 outside CIDR', !vpngw_ip_in_list('11.0.0.1', '10.0.0.0/8'));
check('IPv6 exact match', vpngw_ip_in_list('::1', '::1'));
check('IPv6 CIDR match', vpngw_cidr_match('fd00::1234', 'fd00::/8'));
check('mixed list, whitespace tolerant', vpngw_ip_in_list('172.16.0.9', '127.0.0.1, 172.16.0.0/12'));
check('empty ip -> false', !vpngw_ip_in_list('', '0.0.0.0/0'));

// ---------------------------------------------------------------------------
echo "\n";
if ($FAILS === 0) {
	echo "ALL $TESTS TESTS PASSED\n";
	exit(0);
}
echo "$FAILS of $TESTS TESTS FAILED\n";
exit(1);
?>

<?php
// ---------------------------------------------------------------------------
// Status & DNS helpers.
//
// Pure parsing/formatting helpers (unit-tested) plus thin wrappers that read
// live tunnel state. Used by status.php (connection panel) and dnsleak.php
// (DNS leak test). Kept separate from vpn_backend.php so the parsing logic can
// be tested without touching the system.
// ---------------------------------------------------------------------------

require_once(__DIR__ . '/config.php');

// --- pure parsers -----------------------------------------------------------

// Parse `wg show <if> dump` and return the newest last-handshake epoch across
// peers (0 if none). The dump format is tab-separated; for an interface the
// first line is the device, subsequent lines are peers where column 5 (1-based)
// is the latest-handshake unix time.
function vpn_parse_wg_dump_handshake($dump)
{
	if (!is_string($dump) || $dump === '') {
		return 0;
	}
	$best = 0;
	$first = true;
	foreach (preg_split('/\R/u', $dump) as $line) {
		if ($line === '') {
			continue;
		}
		if ($first) {
			$first = false;   // device line (private/public/listen-port/fwmark)
			continue;
		}
		$f = explode("\t", $line);
		// peer: pubkey, psk, endpoint, allowed-ips, latest-handshake, rx, tx, keepalive
		if (count($f) >= 5 && ctype_digit(trim($f[4]))) {
			$hs = (int) trim($f[4]);
			if ($hs > $best) {
				$best = $hs;
			}
		}
	}
	return $best;
}

// Human-readable "x seconds/minutes/hours/days ago" for an age in seconds.
function vpn_format_age($secs)
{
	if (!is_int($secs) && !ctype_digit((string) $secs)) {
		return 'unknown';
	}
	$secs = (int) $secs;
	if ($secs < 0) {
		return 'unknown';
	}
	if ($secs < 60) {
		return $secs . ' second' . ($secs === 1 ? '' : 's') . ' ago';
	}
	$mins = intdiv($secs, 60);
	if ($mins < 60) {
		return $mins . ' minute' . ($mins === 1 ? '' : 's') . ' ago';
	}
	$hrs = intdiv($mins, 60);
	if ($hrs < 24) {
		return $hrs . ' hour' . ($hrs === 1 ? '' : 's') . ' ago';
	}
	$days = intdiv($hrs, 24);
	return $days . ' day' . ($days === 1 ? '' : 's') . ' ago';
}

// Decide DNS-leak pass/fail from the set of resolver IPs that actually answered
// and the set of expected (VPN/tunnel) resolver IPs.
//   - leaking  : any answering resolver is NOT in the expected set
//   - status   : 'pass' | 'leak' | 'unknown' (no resolvers detected)
// Returns ['status'=>..., 'leaking'=>bool, 'unexpected'=>[ips]].
function vpn_dns_leak_eval(array $answering, array $expected)
{
	$answering = array_values(array_unique(array_filter(array_map('trim', $answering), 'strlen')));
	if (empty($answering)) {
		return array('status' => 'unknown', 'leaking' => false, 'unexpected' => array());
	}
	$exp = array();
	foreach ($expected as $e) {
		$e = trim((string) $e);
		if ($e !== '') {
			$exp[$e] = true;
		}
	}
	$unexpected = array();
	foreach ($answering as $ip) {
		if (!isset($exp[$ip])) {
			$unexpected[] = $ip;
		}
	}
	return array(
		'status'     => empty($unexpected) ? 'pass' : 'leak',
		'leaking'    => !empty($unexpected),
		'unexpected' => $unexpected,
	);
}

// The DNS resolver IPs that are considered "inside the tunnel" (not a leak).
// Defaults to the NordLynx DNS; override with dns_server in leak.conf and/or
// expected_dns (space/comma list) for other providers or a Pi-hole.
function vpn_expected_dns_servers()
{
	$cfg = vpngw_load_conf('/etc/vpngw/leak.conf');
	$list = array('103.86.96.100', '103.86.99.100'); // NordLynx defaults
	if (!empty($cfg['dns_server'])) {
		$list[] = trim($cfg['dns_server']);
	}
	if (!empty($cfg['expected_dns'])) {
		foreach (preg_split('/[\s,]+/', $cfg['expected_dns']) as $ip) {
			if (trim($ip) !== '') {
				$list[] = trim($ip);
			}
		}
	}
	return array_values(array_unique($list));
}

// --- thin live wrappers (not unit-tested; exercised on a real gateway) ------

// Seconds since the most recent WireGuard handshake, or null if unavailable /
// not WireGuard.
function vpn_handshake_age()
{
	if (!function_exists('vpn_is_wireguard') || !vpn_is_wireguard()) {
		return null;
	}
	$dump = shell_exec('sudo wg show ' . escapeshellarg(WIREGUARD_IFACE) . ' dump 2>/dev/null');
	$hs = vpn_parse_wg_dump_handshake(is_string($dump) ? $dump : '');
	if ($hs <= 0) {
		return null;
	}
	$age = time() - $hs;
	return $age < 0 ? 0 : $age;
}
?>

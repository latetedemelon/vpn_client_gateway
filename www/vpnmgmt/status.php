<?php
// AJAX/health endpoint: current VPN connection status as JSON.
//
//   enabled        gateway not in the "disabled" state
//   running        tunnel interface is up
//   backend        wireguard | openvpn
//   server         active server hostname (from the config marker)
//   handshake_age  seconds since last WireGuard handshake (null if N/A)
//   exit_ip        public IP as seen through the tunnel (best-effort, cached)
//   exit_country   country for that IP (best-effort)
//   healthy        running AND (for WG) a recent handshake
//
// Reads are cached briefly so the panel can poll without hammering wg / the
// public-IP lookup.

require_once(__DIR__ . '/auth.php');
require_once(__DIR__ . '/vpn_backend.php');
require_once(__DIR__ . '/status_lib.php');

header('Content-Type: application/json');
header('Cache-Control: no-store');

$CACHE = sys_get_temp_dir() . '/vpngw_status_cache.json';
$TTL = 8; // seconds

// Serve a fresh-enough cache to keep polling cheap.
if (is_readable($CACHE) && (time() - @filemtime($CACHE) < $TTL)) {
	$cached = @file_get_contents($CACHE);
	if (is_string($cached) && $cached !== '') {
		echo $cached;
		exit;
	}
}

$enabled = !vpn_is_disabled();
$running = $enabled && vpn_is_running();
$backend = vpn_backend();
$server  = $running ? vpn_current_server() : '';
$age     = $running ? vpn_handshake_age() : null;

// Healthy: running, and for WireGuard a handshake within a sane window.
$healthy = $running && ($backend !== 'wireguard' || ($age !== null && $age <= 200));

// Public exit IP + country, best-effort and only when up. Cached with the rest.
$exit_ip = '';
$exit_country = '';
if ($running) {
	$ipjson = shell_exec('curl -fsS --max-time 5 https://api.nordvpn.com/v1/helpers/ips/insights 2>/dev/null');
	if (is_string($ipjson) && $ipjson !== '') {
		$d = json_decode($ipjson, true);
		if (is_array($d)) {
			$exit_ip = isset($d['ip']) ? (string) $d['ip'] : '';
			$exit_country = isset($d['country']) ? (string) $d['country'] : '';
		}
	}
}

$out = json_encode(array(
	't'             => (int) round(microtime(true) * 1000),
	'enabled'       => $enabled,
	'running'       => $running,
	'healthy'       => (bool) $healthy,
	'backend'       => $backend,
	'server'        => $server,
	'handshake_age' => $age,
	'handshake_str' => ($age === null ? null : vpn_format_age($age)),
	'exit_ip'       => $exit_ip,
	'exit_country'  => $exit_country,
));

@file_put_contents($CACHE, $out);
@chmod($CACHE, 0600);
echo $out;
?>

<?php
// On-demand DNS / IP leak test for the Tools page.
//
// Returns JSON describing:
//   dns.servers   resolver IP(s) that answered for the gateway
//   dns.status    pass | leak | unknown  (leak = a non-VPN resolver answered)
//   dns.expected  the resolver IPs considered "inside the tunnel"
//   ip.exit_ip    public IP seen through the tunnel
//   ip.country    its country + whether it looks like a NordVPN address
//
// "Which resolver answered" is determined with an EDNS/whoami-style query to a
// public service that echoes the resolver IP it saw. This reflects the path the
// gateway (and therefore its clients, when DNS is forced) actually uses.

require_once(__DIR__ . '/auth.php');
require_once(__DIR__ . '/vpn_backend.php');
require_once(__DIR__ . '/status_lib.php');

header('Content-Type: application/json');
header('Cache-Control: no-store');

// --- which resolver(s) answered ---------------------------------------------
// Akamai/Google both run "whoami" style responders. We use a TXT/A lookup whose
// answer is the resolver's egress IP. dig is preferred; fall back to PHP's
// dns_get_record. These run from the gateway, over its configured resolver.
$servers = array();

$dig = shell_exec('command -v dig 2>/dev/null');
if (is_string($dig) && trim($dig) !== '') {
	// resolver1.opendns.com's special name "myip" pattern isn't universal; use
	// the well-known Akamai whoami that returns the resolver's IP as an A record.
	$out = shell_exec('dig +short +time=3 +tries=1 whoami.akamai.net 2>/dev/null');
	if (is_string($out)) {
		foreach (preg_split('/\R/u', trim($out)) as $line) {
			$line = trim($line);
			if (filter_var($line, FILTER_VALIDATE_IP)) {
				$servers[] = $line;
			}
		}
	}
} else {
	$rec = @dns_get_record('whoami.akamai.net', DNS_A);
	if (is_array($rec)) {
		foreach ($rec as $r) {
			if (isset($r['ip']) && filter_var($r['ip'], FILTER_VALIDATE_IP)) {
				$servers[] = $r['ip'];
			}
		}
	}
}

$expected = vpn_expected_dns_servers();
$dneval = vpn_dns_leak_eval($servers, $expected);

// --- public exit IP / country -----------------------------------------------
$exit_ip = '';
$country = '';
$is_nord = null;
$ipjson = shell_exec('curl -fsS --max-time 6 https://api.nordvpn.com/v1/helpers/ips/insights 2>/dev/null');
if (is_string($ipjson) && $ipjson !== '') {
	$d = json_decode($ipjson, true);
	if (is_array($d)) {
		$exit_ip = isset($d['ip']) ? (string) $d['ip'] : '';
		$country = isset($d['country']) ? (string) $d['country'] : '';
		// The insights API includes protection info for Nord addresses.
		if (array_key_exists('protected', $d)) {
			$is_nord = (bool) $d['protected'];
		}
	}
}

echo json_encode(array(
	'dns' => array(
		'servers'  => array_values(array_unique($servers)),
		'expected' => $expected,
		'status'   => $dneval['status'],
		'leaking'  => $dneval['leaking'],
		'unexpected' => $dneval['unexpected'],
	),
	'ip' => array(
		'exit_ip'   => $exit_ip,
		'country'   => $country,
		'is_nordvpn' => $is_nord,
	),
	'vpn' => array(
		'running' => !vpn_is_disabled() && vpn_is_running(),
	),
));
?>

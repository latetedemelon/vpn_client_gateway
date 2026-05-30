<?php
// ---------------------------------------------------------------------------
// Network throughput helpers.
//
// Pure, side-effect-free functions used by throughput.php to report per-NIC
// byte counters. Counters come from /proc/net/dev and the route table from
// /proc/net/route, both world-readable, so the throughput meter needs no
// elevated privileges (no sudo, no firewall or service changes).
//
// Rates are computed on the client from two successive samples; these helpers
// only parse the cumulative counters and classify each interface as the VPN
// tunnel, the outflow/WAN NIC (the internet uplink) or an inflow/LAN NIC
// (client-facing). Keeping the logic here (free of I/O) makes it unit-testable.
// ---------------------------------------------------------------------------

// Parse the contents of /proc/net/dev into:
//   [ 'eth0' => ['rx' => <bytes>, 'tx' => <bytes>], ... ]
// Only the cumulative receive/transmit byte columns are kept. Header lines and
// malformed rows are ignored.
function tp_parse_proc_net_dev($text)
{
	$out = array();
	if (!is_string($text) || $text === '') {
		return $out;
	}
	foreach (preg_split('/\R/u', $text) as $line) {
		// Data lines look like:  "  eth0: 12345 67 0 ... 8910 11 ..."
		// The interface name is everything before the first colon; the header
		// lines ("Inter-|...", " face |...") have no colon in that position.
		$pos = strpos($line, ':');
		if ($pos === false) {
			continue;
		}
		$iface = trim(substr($line, 0, $pos));
		// Real interface names are a restricted character set; this also drops
		// the header rows (which contain '|' or spaces).
		if (!preg_match('/^[A-Za-z0-9._:-]+$/', $iface)) {
			continue;
		}
		$fields = preg_split('/\s+/', trim(substr($line, $pos + 1)));
		// Per the kernel column layout, after the colon:
		//   receive:  bytes packets errs drop fifo frame compressed multicast
		//   transmit: bytes packets errs drop fifo colls carrier compressed
		// so receive bytes = field 0 and transmit bytes = field 8 (0-indexed).
		if (count($fields) < 9 || !is_numeric($fields[0]) || !is_numeric($fields[8])) {
			continue;
		}
		$out[$iface] = array(
			'rx' => (int) $fields[0],
			'tx' => (int) $fields[8],
		);
	}
	return $out;
}

// Parse /proc/net/route and return the interface of the main-table default
// route (destination 0.0.0.0/0) with the lowest metric -- i.e. the physical
// uplink the box uses to reach the internet. The VPN interface and loopback are
// ignored so we report the *physical* outflow NIC even while the tunnel owns
// the effective default route. Returns '' if none is found.
//
// Note: wg-quick (AllowedIPs 0.0.0.0/0, via fwmark) and OpenVPN redirect-gateway
// both leave the main-table default pointing at the physical uplink, so this
// correctly identifies the WAN NIC in the common cases.
function tp_parse_default_iface($text, $vpn_iface)
{
	if (!is_string($text) || $text === '') {
		return '';
	}
	$best = '';
	$best_metric = PHP_INT_MAX;
	foreach (preg_split('/\R/u', $text) as $line) {
		$line = trim($line);
		if ($line === '') {
			continue;
		}
		$f = preg_split('/\s+/', $line);
		if (count($f) < 8) {
			continue;
		}
		// Skip the header row (Destination column is the literal word).
		if (!ctype_xdigit($f[1]) || !ctype_xdigit($f[7])) {
			continue;
		}
		// Default route = destination 0.0.0.0 with mask 0.0.0.0.
		if (strcasecmp($f[1], '00000000') !== 0 || strcasecmp($f[7], '00000000') !== 0) {
			continue;
		}
		$iface = $f[0];
		if ($iface === $vpn_iface || $iface === 'lo') {
			continue;
		}
		$metric = is_numeric($f[6]) ? (int) $f[6] : 0;
		if ($metric < $best_metric) {
			$best_metric = $metric;
			$best = $iface;
		}
	}
	return $best;
}

// Is $name a physical-ish NIC we want to report (not loopback, not the VPN
// tunnel, not a virtual/bridge/container interface)?
function tp_is_physical_iface($name, $vpn_iface)
{
	if ($name === 'lo' || $name === $vpn_iface) {
		return false;
	}
	$virtual_prefixes = array(
		'veth', 'docker', 'br-', 'virbr', 'vmnet',
		'tap', 'tun', 'wg', 'sit', 'ip6tnl', 'gre', 'dummy', 'kube',
	);
	foreach ($virtual_prefixes as $p) {
		if (strncmp($name, $p, strlen($p)) === 0) {
			return false;
		}
	}
	return true;
}

// Choose the interface that best represents the primary physical uplink, by
// name, when the route table doesn't tell us (fallback for tp_classify_ifaces).
// Preference: eth*, en*, wlan*, wl*; never the VPN interface or loopback; then
// any other interface; finally 'eth0'.
function tp_pick_box_iface(array $ifaces, $vpn_iface)
{
	$names = array_keys($ifaces);
	foreach (array('/^eth/', '/^en/', '/^wlan/', '/^wl/') as $pat) {
		foreach ($names as $n) {
			if ($n === $vpn_iface || $n === 'lo') {
				continue;
			}
			if (preg_match($pat, $n)) {
				return $n;
			}
		}
	}
	foreach ($names as $n) {
		if ($n === $vpn_iface || $n === 'lo') {
			continue;
		}
		return $n;
	}
	return 'eth0';
}

// Classify the physical interfaces into the outflow/WAN NIC and the inflow/LAN
// NICs. $wan_hint is the default-route interface from tp_parse_default_iface().
// Returns ['wan' => <name|''>, 'lan' => [<name>, ...]] with LAN sorted.
function tp_classify_ifaces(array $ifaces, $vpn_iface, $wan_hint)
{
	$phys = array();
	foreach (array_keys($ifaces) as $n) {
		if (tp_is_physical_iface($n, $vpn_iface)) {
			$phys[] = $n;
		}
	}
	sort($phys);

	$wan = '';
	if ($wan_hint !== '' && in_array($wan_hint, $phys, true)) {
		$wan = $wan_hint;            // the real default-route NIC
	} elseif (!empty($phys)) {
		$cand = tp_pick_box_iface($ifaces, $vpn_iface);  // name-based fallback
		$wan = in_array($cand, $phys, true) ? $cand : $phys[0];
	}

	$lan = array();
	foreach ($phys as $n) {
		if ($n !== $wan) {
			$lan[] = $n;
		}
	}
	return array('wan' => $wan, 'lan' => $lan);
}
?>

<?php
// ---------------------------------------------------------------------------
// Network throughput helpers.
//
// Pure, side-effect-free functions used by throughput.php to report interface
// byte counters. Counters come from /proc/net/dev, which is world-readable, so
// the throughput meter needs no elevated privileges (no sudo, no firewall or
// service changes).
//
// Rates are computed on the client from two successive samples; these helpers
// only parse the cumulative counters and decide which interface represents
// "the whole box". Keeping the logic here (free of I/O) makes it unit-testable.
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
		// lines ("Inter-|...", " face |...") have their pipe before any colon.
		$pos = strpos($line, ':');
		if ($pos === false) {
			continue;
		}
		$iface = trim(substr($line, 0, $pos));
		if ($iface === '' || strpos($iface, '|') !== false || strpos($iface, ' ') !== false) {
			continue; // not a real interface name
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

// Choose the interface that best represents "the whole box": the primary
// physical uplink. Preference order:
//   1) a present interface named like a physical NIC (eth*, en*, wlan*, wl*),
//   2) any other real interface,
// never the VPN interface or loopback. Falls back to 'eth0' (this project's
// documented default) when nothing suitable is present.
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
?>

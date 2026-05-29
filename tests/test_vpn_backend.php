<?php
// Unit tests for the VPN backend abstraction (vpn_backend.php).
//
// Run:  php tests/test_vpn_backend.php
//
// The file-path constants are redirected into a temporary sandbox *before*
// vpn_backend.php is included, so the privileged/system paths (/etc/...) are
// never touched. Only the side-effect-free logic is exercised here.

error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE & ~E_DEPRECATED);

$sandbox = sys_get_temp_dir() . '/vpngw_test_' . getmypid();
@mkdir($sandbox, 0777, true);

define('VPNGW_BACKEND_FILE', "$sandbox/backend");
define('OPENVPN_CONF',       "$sandbox/openvpn.conf");
define('WIREGUARD_CONF',     "$sandbox/wg0.conf");
define('VPN_DISABLED_MARKER', "$sandbox/vpn.disabled");
define('VPN_SERVERS_XML',     "$sandbox/vpnservers.xml");

require __DIR__ . '/../www/vpnmgmt/vpn_backend.php';

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
echo "hostname validation\n";
check('accepts a normal nordvpn host', vpn_valid_hostname('us11894.nordvpn.com'));
check('accepts hyphenated host', vpn_valid_hostname('se-ch17.nordvpn.com'));
check('rejects empty', !vpn_valid_hostname(''));
check('rejects shell metachars', !vpn_valid_hostname('a.com; rm -rf /'));
check('rejects spaces', !vpn_valid_hostname('a b.com'));
check('rejects xpath-breaking quote', !vpn_valid_hostname('a"]/../*'));

// ---------------------------------------------------------------------------
echo "backend selection\n";
file_put_contents(VPNGW_BACKEND_FILE, "wireguard\n");
check('reads wireguard from backend file', vpn_backend() === 'wireguard' && vpn_is_wireguard());
check('iface is wg0 for wireguard', vpn_iface() === 'wg0' && vpn_iface_match() === 'wg+');
file_put_contents(VPNGW_BACKEND_FILE, "openvpn\n");
check('reads openvpn from backend file', vpn_backend() === 'openvpn' && !vpn_is_wireguard());
check('iface is tun for openvpn', vpn_iface() === 'tun0' && vpn_iface_match() === 'tun+');
file_put_contents(VPNGW_BACKEND_FILE, "garbage\n");
unlink(OPENVPN_CONF . '.nope'); // no-op
check('invalid backend falls back to openvpn (no confs present)', vpn_backend() === 'openvpn');

// ---------------------------------------------------------------------------
echo "openvpn config rewrite\n";
$ovpn = "client\ndev tun\nproto udp\nremote old.example.com 1194\nca ca.crt\ntls-auth ta.key\nverb 3\n";
$out = vpn_render_openvpn_conf($ovpn, 'us-east.privateinternetaccess.com');
check('rewrites remote host, keeps port', strpos($out, "remote us-east.privateinternetaccess.com 1194") !== false);
check('leaves ca alone for non-nordvpn', strpos($out, "\nca ca.crt") !== false);
check('preserves other lines', strpos($out, "client\n") === 0 && strpos($out, "verb 3") !== false);

$out2 = vpn_render_openvpn_conf($ovpn, 'us123.nordvpn.com');
check('nordvpn: per-server ca filename', strpos($out2, 'ca us123_nordvpn_com_ca.crt') !== false);
check('nordvpn: per-server tls-auth filename', strpos($out2, 'tls-auth us123_nordvpn_com_tls.key') !== false);
check('nordvpn: rewrites remote', strpos($out2, 'remote us123.nordvpn.com 1194') !== false);

// ---------------------------------------------------------------------------
echo "wireguard config rewrite\n";
$wg = "[Interface]\nPrivateKey = aaaaBBBBccccDDDDeeeeFFFFggggHHHHiiiiJJJJkk=\nAddress = 10.5.0.2/32\nDNS = 103.86.96.100, 103.86.99.100\n# Server = old123.nordvpn.com\n\n[Peer]\nPublicKey = OLDKEYOLDKEYOLDKEYOLDKEYOLDKEYOLDKEYOLDKEY0=\nAllowedIPs = 0.0.0.0/0, ::/0\nEndpoint = 1.2.3.4:51820\nPersistentKeepalive = 25\n";
$peer = array('pubkey' => 'NEWKEYNEWKEYNEWKEYNEWKEYNEWKEYNEWKEYNEWKEY0=', 'endpoint' => '5.6.7.8:51820');
$nw = vpn_render_wireguard_conf($wg, 'us999.nordvpn.com', $peer);
check('keeps PrivateKey from [Interface]', strpos($nw, 'PrivateKey = aaaaBBBBccccDDDDeeeeFFFFggggHHHHiiiiJJJJkk=') !== false);
check('keeps Address/DNS', strpos($nw, 'Address = 10.5.0.2/32') !== false && strpos($nw, 'DNS = 103.86.96.100') !== false);
check('updates # Server marker', strpos($nw, '# Server = us999.nordvpn.com') !== false && strpos($nw, 'old123') === false);
check('replaces PublicKey', strpos($nw, 'PublicKey = NEWKEYNEWKEYNEWKEYNEWKEYNEWKEYNEWKEYNEWKEY0=') !== false && strpos($nw, 'OLDKEY') === false);
check('replaces Endpoint', strpos($nw, 'Endpoint = 5.6.7.8:51820') !== false && strpos($nw, '1.2.3.4') === false);
check('exactly one [Peer] section', substr_count($nw, '[Peer]') === 1);
check('exactly one [Interface] section', substr_count($nw, '[Interface]') === 1);

// inserting a marker when none exists
$wg_nomarker = "[Interface]\nPrivateKey = aaaaBBBBccccDDDDeeeeFFFFggggHHHHiiiiJJJJkk=\nAddress = 10.5.0.2/32\n\n[Peer]\nPublicKey = X=\nEndpoint = 1.1.1.1:51820\n";
$nw2 = vpn_render_wireguard_conf($wg_nomarker, 'de1.nordvpn.com', $peer);
check('inserts # Server marker when missing', strpos($nw2, '# Server = de1.nordvpn.com') !== false);
check('marker is in interface section (before [Peer])', strpos($nw2, '# Server') < strpos($nw2, '[Peer]'));

// ---------------------------------------------------------------------------
echo "wireguard peer lookup from generated server list\n";
$generated = __DIR__ . '/../www/vpnmgmt/vpn_providers/nordvpn/vpnservers.xml';
if (is_readable($generated)) {
	copy($generated, VPN_SERVERS_XML);
	$xml = simplexml_load_file(VPN_SERVERS_XML);
	$first = $xml->xpath('//vpnserver[pubkey != ""]')[0];
	$host = (string) $first->servername;
	$peerLookup = vpn_lookup_wireguard_peer($host);
	check('looks up a known host', $peerLookup !== null);
	check('returns the embedded pubkey', $peerLookup && $peerLookup['pubkey'] === trim((string) $first->pubkey));
	check('returns the embedded endpoint', $peerLookup && $peerLookup['endpoint'] === trim((string) $first->endpoint));
	check('unknown host returns null', vpn_lookup_wireguard_peer('nope99999.nordvpn.com') === null);

	// end-to-end: lookup + render produces a usable config for a real server
	$rendered = vpn_render_wireguard_conf($wg, $host, $peerLookup);
	check('end-to-end render contains real endpoint', strpos($rendered, 'Endpoint = ' . $peerLookup['endpoint']) !== false);
} else {
	echo "  SKIP  generated vpnservers.xml not present (run vpn_update.sh first)\n";
}

// entry missing pubkey -> null
file_put_contents(VPN_SERVERS_XML, '<?xml version="1.0"?><vpnserverinfo><vpnservers><vpnserver><servername>nopub.nordvpn.com</servername><countryname>X</countryname><regionname/></vpnserver></vpnservers></vpnserverinfo>');
check('entry without pubkey returns null', vpn_lookup_wireguard_peer('nopub.nordvpn.com') === null);

// ---------------------------------------------------------------------------
// cleanup
array_map('unlink', glob("$sandbox/*"));
@rmdir($sandbox);

echo "\n" . ($FAILS === 0 ? "ALL $TESTS TESTS PASSED" : "$FAILS/$TESTS TESTS FAILED") . "\n";
exit($FAILS === 0 ? 0 : 1);

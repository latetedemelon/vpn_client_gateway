<?php
require_once(__DIR__ . '/util.php');

// ---------------------------------------------------------------------------
// VPN backend abstraction layer.
//
// The VPN Client Gateway historically drove OpenVPN directly. This library
// adds a WireGuard backend (used for NordVPN / NordLynx) while keeping the
// OpenVPN backend fully working and backward compatible.
//
// Everything that is backend-specific - which service to control, which
// network interface carries the traffic, how to switch servers - lives here,
// so the rest of the web UI can stay backend-agnostic.
//
// Absolute paths are used for all system files so the functions behave the
// same regardless of PHP's current working directory (the legacy code mixed
// relative and absolute paths, which was fragile).
// ---------------------------------------------------------------------------

define('VPNGW_BACKEND_FILE', '/etc/vpngw/backend');   // contains "openvpn" or "wireguard"
define('OPENVPN_CONF',       '/etc/openvpn/server.conf');
define('WIREGUARD_IFACE',    'wg0');
define('WIREGUARD_CONF',     '/etc/wireguard/wg0.conf');
define('WIREGUARD_PORT',     '51820');
define('VPN_DISABLED_MARKER', __DIR__ . '/vpn.disabled');
define('VPN_SERVERS_XML',     __DIR__ . '/vpnservers.xml');

// NordVPN / NordLynx defaults used when (re)writing the WireGuard interface.
define('NORDLYNX_ADDRESS', '10.5.0.2/32');
define('NORDLYNX_DNS',     '103.86.96.100, 103.86.99.100');

// ---------------------------------------------------------------------------
// Backend selection
// ---------------------------------------------------------------------------

// Returns "wireguard" or "openvpn".
function vpn_backend()
{
	// An explicit choice always wins.
	if (is_readable(VPNGW_BACKEND_FILE)) {
		$b = strtolower(trim(file_get_contents(VPNGW_BACKEND_FILE)));
		if ($b === 'wireguard' || $b === 'openvpn') {
			return $b;
		}
	}
	// Otherwise infer from what is installed, preferring OpenVPN for
	// backward compatibility with existing installations.
	if (file_exists(OPENVPN_CONF)) {
		return 'openvpn';
	}
	if (file_exists(WIREGUARD_CONF)) {
		return 'wireguard';
	}
	return 'openvpn';
}

function vpn_is_wireguard()
{
	return vpn_backend() === 'wireguard';
}

// Human readable name of the active backend (for the UI).
function vpn_backend_label()
{
	return vpn_is_wireguard() ? 'WireGuard' : 'OpenVPN';
}

// Network interface that carries VPN traffic for the active backend.
function vpn_iface()
{
	return vpn_is_wireguard() ? WIREGUARD_IFACE : 'tun0';
}

// iptables interface match pattern (a whole family of interfaces).
function vpn_iface_match()
{
	return vpn_is_wireguard() ? 'wg+' : 'tun+';
}

// ---------------------------------------------------------------------------
// "VPN disabled" marker
// ---------------------------------------------------------------------------

function vpn_is_disabled()
{
	// Honour the canonical marker and the legacy relative path.
	return file_exists(VPN_DISABLED_MARKER) || file_exists('vpnmgmt/vpn.disabled');
}

function vpn_mark_disabled($disabled)
{
	if ($disabled) {
		@touch(VPN_DISABLED_MARKER);
	} else {
		@unlink(VPN_DISABLED_MARKER);
		@unlink('vpnmgmt/vpn.disabled'); // tidy up any legacy marker
	}
}

// ---------------------------------------------------------------------------
// Service control (init-system aware)
// ---------------------------------------------------------------------------

function has_systemd()
{
	return is_dir('/run/systemd/system');
}

// systemd template unit / SysV-or-OpenRC service name for the active backend.
function vpn_service_name()
{
	if (vpn_is_wireguard()) {
		return 'wg-quick@' . WIREGUARD_IFACE;
	}
	return 'openvpn';
}

function vpn_start()
{
	if (vpn_is_wireguard()) {
		// wg-quick is the most portable way to bring the tunnel up; it
		// applies the address, DNS and routes from the config file.
		return shell_exec('sudo wg-quick up ' . escapeshellarg(WIREGUARD_IFACE) . ' 2>&1');
	}
	return start_service('openvpn');
}

function vpn_stop()
{
	if (vpn_is_wireguard()) {
		return shell_exec('sudo wg-quick down ' . escapeshellarg(WIREGUARD_IFACE) . ' 2>&1');
	}
	return stop_service('openvpn');
}

function vpn_is_running()
{
	if (vpn_is_wireguard()) {
		$out = shell_exec('sudo wg show ' . escapeshellarg(WIREGUARD_IFACE) . ' 2>/dev/null');
		return is_string($out) && trim($out) !== '';
	}
	$out = shell_exec('sudo service openvpn status 2>&1');
	return (strpos($out, 'Active: active') !== false)
		|| (strpos($out, 'is running') !== false)
		|| (strpos($out, 'started') !== false);
}

function vpn_enable_boot()
{
	if (vpn_is_wireguard()) {
		if (has_systemd()) {
			return shell_exec('sudo systemctl enable ' . escapeshellarg(vpn_service_name()) . ' 2>&1');
		}
		// OpenRC (Alpine): /etc/init.d/wg-quick.wg0 -> wg-quick
		return enable_service('wg-quick.' . WIREGUARD_IFACE);
	}
	return enable_service('openvpn');
}

function vpn_disable_boot()
{
	if (vpn_is_wireguard()) {
		if (has_systemd()) {
			return shell_exec('sudo systemctl disable ' . escapeshellarg(vpn_service_name()) . ' 2>&1');
		}
		return disable_service('wg-quick.' . WIREGUARD_IFACE);
	}
	return disable_service('openvpn');
}

// ---------------------------------------------------------------------------
// Current server
// ---------------------------------------------------------------------------

function vpn_current_server()
{
	if (vpn_is_wireguard()) {
		if (!is_readable(WIREGUARD_CONF)) {
			return '';
		}
		$conf = file_get_contents(WIREGUARD_CONF);
		// We record the active hostname as a "# Server = ..." comment in the
		// [Interface] section, because the config itself only stores an IP.
		if (preg_match('/^[ \t]*#[ \t]*Server[ \t]*=[ \t]*(\S+)/mi', $conf, $m)) {
			return $m[1];
		}
		if (preg_match('/^[ \t]*Endpoint[ \t]*=[ \t]*(\S+)/mi', $conf, $m)) {
			return $m[1];
		}
		return '';
	}
	if (!is_readable(OPENVPN_CONF)) {
		return '';
	}
	$file = file_get_contents(OPENVPN_CONF);
	if (preg_match('/remote (.*?) \d+/s', $file, $m)) {
		return $m[1];
	}
	return '';
}

// ---------------------------------------------------------------------------
// Input validation
// ---------------------------------------------------------------------------

// A defensive hostname check - the value comes from $_GET and is used in
// xpath queries, file contents and (indirectly) the WireGuard config.
function vpn_valid_hostname($h)
{
	return is_string($h) && preg_match('/^[A-Za-z0-9._-]{1,128}$/', $h) === 1;
}

// ---------------------------------------------------------------------------
// Switching servers
// ---------------------------------------------------------------------------

// Writes the active server into the backend's config file (without
// stopping/starting the tunnel). Returns true on success.
function vpn_write_server_conf($hostname)
{
	if (!vpn_valid_hostname($hostname)) {
		return false;
	}
	if (vpn_is_wireguard()) {
		return vpn_write_wireguard_conf($hostname);
	}
	return vpn_write_openvpn_conf($hostname);
}

// OpenVPN: rewrite the "remote" line (and NordVPN per-server ca/tls-auth file
// names). This faithfully preserves the original manage_openvpn.php behaviour.
function vpn_write_openvpn_conf($vpnserver)
{
	if (!is_readable(OPENVPN_CONF)) {
		return false;
	}
	$serverconf = vpn_render_openvpn_conf(file_get_contents(OPENVPN_CONF), $vpnserver);
	return file_put_contents(OPENVPN_CONF, $serverconf) !== false;
}

// Pure helper: given the current openvpn config text and a target hostname,
// return the rewritten config text. Kept side-effect free so it can be tested.
function vpn_render_openvpn_conf($current, $vpnserver)
{
	$hostparts = explode('.', $vpnserver);
	$subdomain = isset($hostparts[0]) ? $hostparts[0] : '';
	$domain    = isset($hostparts[1]) ? $hostparts[1] : '';
	$tld       = isset($hostparts[2]) ? $hostparts[2] : '';

	$serverconf = '';
	foreach (preg_split('/\R/u', $current, -1) as $i => $line) {
		// Re-add the newline that preg_split stripped (except trailing empty).
		$nl = "\n";
		$tok = preg_split('/[\s]+/', $line);
		if ($tok[0] === 'remote') {
			$port = isset($tok[2]) ? $tok[2] : '1194';
			$serverconf .= 'remote ' . $vpnserver . ' ' . $port . $nl;
		} else if ($tok[0] === 'ca' && $domain === 'nordvpn') {
			$serverconf .= 'ca ' . $subdomain . '_' . $domain . '_' . $tld . '_ca.crt ' . $nl;
		} else if ($tok[0] === 'tls-auth' && $domain === 'nordvpn') {
			$serverconf .= 'tls-auth ' . $subdomain . '_' . $domain . '_' . $tld . '_tls.key ' . $nl;
		} else {
			$serverconf .= $line . $nl;
		}
	}
	return rtrim($serverconf, "\n") . "\n";
}

// WireGuard: keep the [Interface] section as-is and rebuild the single [Peer]
// section to point at the chosen server. The peer's public key and endpoint
// are looked up from vpnservers.xml (populated by the provider updater).
function vpn_write_wireguard_conf($hostname)
{
	$peer = vpn_lookup_wireguard_peer($hostname);
	if ($peer === null) {
		return false; // no key/endpoint available for this server
	}
	if (!is_readable(WIREGUARD_CONF)) {
		return false;
	}
	$new = vpn_render_wireguard_conf(file_get_contents(WIREGUARD_CONF), $hostname, $peer);

	// The config lives in /etc/wireguard (root:root, mode 600). Write to a
	// temp file and install it atomically with the right ownership/perms via
	// sudo, matching the project's existing privileged-helper pattern.
	$tmp = tempnam(sys_get_temp_dir(), 'wg');
	if ($tmp === false) {
		return false;
	}
	file_put_contents($tmp, $new);
	shell_exec('sudo install -m 600 -o root -g root ' . escapeshellarg($tmp) . ' ' . escapeshellarg(WIREGUARD_CONF));
	@unlink($tmp);
	return true;
}

// Pure helper: keep the [Interface] section of an existing WireGuard config and
// rebuild the single [Peer] section for the chosen server. Side-effect free so
// it can be tested. $peer = ['pubkey' => ..., 'endpoint' => 'ip:port'].
function vpn_render_wireguard_conf($conf, $hostname, $peer)
{
	// [Interface] section = everything up to the first [Peer].
	$iface = $conf;
	$pos = stripos($conf, '[Peer]');
	if ($pos !== false) {
		$iface = substr($conf, 0, $pos);
	}

	// Update (or insert) the "# Server = ..." marker inside [Interface].
	if (preg_match('/^[ \t]*#[ \t]*Server[ \t]*=.*$/mi', $iface)) {
		$iface = preg_replace('/^[ \t]*#[ \t]*Server[ \t]*=.*$/mi', '# Server = ' . $hostname, $iface, 1);
	} else {
		$iface = preg_replace('/(\[Interface\][^\n]*\n)/i', "$1# Server = " . $hostname . "\n", $iface, 1);
	}
	$iface = rtrim($iface) . "\n";

	$peerblock = "\n[Peer]\n"
		. 'PublicKey = ' . $peer['pubkey'] . "\n"
		. "AllowedIPs = 0.0.0.0/0, ::/0\n"
		. 'Endpoint = ' . $peer['endpoint'] . "\n"
		. "PersistentKeepalive = 25\n";

	return $iface . $peerblock;
}

// Resolves a WireGuard server's public key + endpoint. Primary source is the
// embedded data in vpnservers.xml (works offline, fast). Returns
// ['pubkey' => ..., 'endpoint' => 'ip:port'] or null.
function vpn_lookup_wireguard_peer($hostname)
{
	if (!is_readable(VPN_SERVERS_XML)) {
		return null;
	}
	$info = @simplexml_load_file(VPN_SERVERS_XML);
	if ($info === false) {
		return null;
	}
	$nodes = $info->xpath('//vpnserver[servername="' . $hostname . '"]');
	if (empty($nodes)) {
		return null;
	}
	$n = $nodes[0];
	$pubkey   = isset($n->pubkey) ? trim((string) $n->pubkey) : '';
	$endpoint = isset($n->endpoint) ? trim((string) $n->endpoint) : '';
	if ($pubkey === '' || $endpoint === '') {
		return null;
	}
	// Basic sanity check on the embedded values.
	if (!preg_match('#^[0-9A-Za-z+/]{42,46}=*$#', $pubkey)) {
		return null;
	}
	if (!preg_match('/^[0-9A-Za-z.:\[\]]+:[0-9]{1,5}$/', $endpoint)) {
		return null;
	}
	return array('pubkey' => $pubkey, 'endpoint' => $endpoint);
}

// ---------------------------------------------------------------------------
// Enable / disable the gateway (firewall + service + boot persistence)
// ---------------------------------------------------------------------------

// Route LAN traffic through the VPN and arm the kill switch. Does NOT start
// the tunnel itself (the caller starts it), preserving the original split
// between enablevpn.php and the server-switch flow.
function vpn_enable()
{
	vpn_mark_disabled(false);
	vpn_enable_boot();

	$iface = escapeshellarg(vpn_iface());        // wg0 or tun0
	$match = escapeshellarg(vpn_iface_match());   // wg+ or tun+

	// NAT: masquerade everything leaving via the VPN interface.
	shell_exec('sudo iptables -t nat -F POSTROUTING');
	shell_exec('sudo iptables -t nat -A POSTROUTING -o ' . $iface . ' -j MASQUERADE');

	// Forwarding: LAN <-> VPN.
	shell_exec('sudo iptables -F FORWARD');
	shell_exec('sudo iptables -A FORWARD -i ' . $match . ' -o eth0 -m state --state RELATED,ESTABLISHED -j ACCEPT');
	shell_exec('sudo iptables -A FORWARD -i eth0 -o ' . $match . ' -m comment --comment "LAN out to VPN" -j ACCEPT');

	// Kill switch: with the chain reset to RETURN, the default OUTPUT policy
	// (DROP, set by the firewall template) blocks any traffic that is not
	// explicitly allowed out the VPN interface.
	shell_exec('sudo iptables -F killswitch');
	shell_exec('sudo iptables -t filter -A killswitch -j RETURN');

	shell_exec("sudo su -c 'iptables-save > /etc/iptables/rules.v4'");

	if (host_os_type() == 'alpine') {
		save_fs_changes();
	}
}

// Stop using the VPN: forward LAN traffic via the normal ISP link and disarm
// the kill switch.
function vpn_disable()
{
	vpn_mark_disabled(true);
	vpn_stop();
	vpn_disable_boot();

	shell_exec('sudo iptables -t nat -F POSTROUTING');
	shell_exec('sudo iptables -t nat -A POSTROUTING -o eth0 -j MASQUERADE');

	shell_exec('sudo iptables -F FORWARD');
	shell_exec('sudo iptables -A FORWARD -i eth0 -o eth0 -m comment --comment "LAN forwarding" -j ACCEPT');

	// Disarm the kill switch (allow outbound traffic over the LAN/ISP link).
	shell_exec('sudo iptables -F killswitch');
	shell_exec('sudo iptables -t filter -A killswitch -o eth0 -j ACCEPT');

	shell_exec("sudo su -c 'iptables-save > /etc/iptables/rules.v4'");

	if (host_os_type() == 'alpine') {
		save_fs_changes();
	}
}
?>

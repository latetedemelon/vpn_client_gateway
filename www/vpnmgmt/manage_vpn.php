<?php
require_once(__DIR__ . '/util.php');
require_once(__DIR__ . '/vpn_backend.php');

// ---------------------------------------------------------------------------
// Backend-agnostic VPN dispatcher.
//
// Handles the "?vpnserver=..." requests issued by the web UI:
//   - "disable" / "none" : turn the VPN off (forward via the ISP link)
//   - "enable"           : turn the VPN on and start the tunnel
//   - <hostname>         : switch to the given server (enabling first if off)
//
// The heavy lifting lives in vpn_backend.php, so the same logic drives both
// the OpenVPN and WireGuard backends.
// ---------------------------------------------------------------------------

$vpnserver = isset($_GET['vpnserver']) ? $_GET['vpnserver'] : null;

if ($vpnserver !== null) {
	if ($vpnserver === 'disable' || $vpnserver === 'none') {
		vpn_disable();
	} else if ($vpnserver === 'enable') {
		vpn_enable();
		vpn_start();
	} else if (vpn_valid_hostname($vpnserver)) {
		// Switching to a specific server implies the VPN should be on.
		if (vpn_is_disabled()) {
			vpn_enable();
		}
		// Stop, rewrite the config for the new server, then start again.
		vpn_stop();
		vpn_write_server_conf($vpnserver);
		vpn_start();
	}

	if (host_os_type() == 'alpine') {
		save_fs_changes();
	}
}
?>

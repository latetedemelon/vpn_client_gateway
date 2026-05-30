<?php
// ---------------------------------------------------------------------------
// VPN provider selection (multi-vendor support).
//
// A "provider" is a directory under vpn_providers/ holding that vendor's data
// (server.conf, vpnservers.xml and, optionally, a vpn_update.sh). The active
// provider is named in /etc/vpngw/provider (default: nordvpn).
//
// Provider names map to directory names, so they are restricted to a safe
// charset (no path traversal). Any standard WireGuard config can be brought in
// as the "custom" provider via setup/import-wireguard-config.sh, so additional
// vendors work without provider-specific code; see documentation/providers-backlog.md.
// ---------------------------------------------------------------------------

require_once(__DIR__ . '/config.php');

if (!defined('VPNGW_PROVIDER_FILE')) {
	define('VPNGW_PROVIDER_FILE', '/etc/vpngw/provider');
}

function vpngw_valid_provider($p)
{
	return is_string($p) && preg_match('/^[a-z0-9_]{1,32}$/', $p) === 1;
}

// Active provider (default nordvpn); falls back safely on a missing/bad value.
function vpngw_provider()
{
	if (is_readable(VPNGW_PROVIDER_FILE)) {
		$p = strtolower(trim((string) @file_get_contents(VPNGW_PROVIDER_FILE)));
		if (vpngw_valid_provider($p)) {
			return $p;
		}
	}
	return 'nordvpn';
}

// Directory holding the given (or active) provider's bundled data.
function vpngw_provider_dir($p = null)
{
	$p = ($p === null) ? vpngw_provider() : $p;
	if (!vpngw_valid_provider($p)) {
		$p = 'nordvpn';
	}
	return __DIR__ . '/vpn_providers/' . $p;
}

// Provider directories actually present in this install.
function vpngw_known_providers()
{
	$base = __DIR__ . '/vpn_providers';
	$out = array();
	if (is_dir($base)) {
		foreach (scandir($base) as $e) {
			if ($e === '.' || $e === '..') {
				continue;
			}
			if (vpngw_valid_provider($e) && is_dir($base . '/' . $e)) {
				$out[] = $e;
			}
		}
	}
	return $out;
}
?>

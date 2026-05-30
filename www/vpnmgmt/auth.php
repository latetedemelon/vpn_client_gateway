<?php
// ---------------------------------------------------------------------------
// Management-page authentication guard.
//
// Standards-based and provider-friendly, with three modes selected in
// /etc/vpngw/auth.conf:
//
//   mode=off     (default)  no authentication (backward compatible)
//   mode=basic              HTTP Basic auth, RFC 7617 (bcrypt-hashed password)
//   mode=proxy              trust an authenticated-user header set by an
//                           upstream SSO/reverse-proxy (Authelia, Authentik,
//                           oauth2-proxy, Cloudflare Access, Tailscale, …)
//
// auth.conf keys:
//   mode             off | basic | proxy
//   username         (basic) the login name
//   password_hash    (basic) a PHP password_hash() bcrypt string ($2y$...)
//   realm            (basic) optional WWW-Authenticate realm
//   user_header      (proxy) header carrying the user (default: Remote-User)
//   trusted_proxies  (proxy) optional CSV of IPs/CIDRs allowed as REMOTE_ADDR
//
// Including this file enforces auth immediately (and exactly once), so every
// web entry point just needs `require_once('.../auth.php');` at the very top.
// The decision logic (vpngw_auth_decision) is pure and unit-tested.
// ---------------------------------------------------------------------------

require_once(__DIR__ . '/config.php');

if (!defined('VPNGW_AUTH_CONF')) {
	define('VPNGW_AUTH_CONF', '/etc/vpngw/auth.conf');
}

// Pure decision. Given the parsed config and a $_SERVER-like array, return:
//   ['ok'=>bool, 'status'=>int, 'headers'=>[string...], 'body'=>string, 'mode'=>string]
function vpngw_auth_decision(array $cfg, array $server)
{
	$mode = isset($cfg['mode']) ? strtolower(trim($cfg['mode'])) : 'off';

	if ($mode === '' || $mode === 'off' || $mode === 'none' || $mode === 'disabled') {
		return array('ok' => true, 'status' => 200, 'headers' => array(), 'body' => '', 'mode' => 'off');
	}

	if ($mode === 'basic') {
		$realm = (isset($cfg['realm']) && $cfg['realm'] !== '') ? $cfg['realm'] : 'VPN Client Gateway';
		$realm = preg_replace('/[\r\n"\\\\]/', '', $realm); // header-safe
		$want_user = isset($cfg['username']) ? (string) $cfg['username'] : '';
		$hash      = isset($cfg['password_hash']) ? (string) $cfg['password_hash'] : '';
		list($user, $pass) = vpngw_basic_credentials($server);

		$ok = ($want_user !== '' && $hash !== ''
			&& hash_equals($want_user, (string) $user)
			&& password_verify((string) $pass, $hash));

		if ($ok) {
			return array('ok' => true, 'status' => 200, 'headers' => array(), 'body' => '', 'mode' => 'basic');
		}
		return array(
			'ok'      => false,
			'status'  => 401,
			'headers' => array('WWW-Authenticate: Basic realm="' . $realm . '", charset="UTF-8"'),
			'body'    => "Authentication required.\n",
			'mode'    => 'basic',
		);
	}

	if ($mode === 'proxy' || $mode === 'header' || $mode === 'forward') {
		$hdr  = (isset($cfg['user_header']) && $cfg['user_header'] !== '') ? $cfg['user_header'] : 'Remote-User';
		$skey = 'HTTP_' . strtoupper(str_replace('-', '_', $hdr));
		$user = isset($server[$skey]) ? trim((string) $server[$skey]) : '';

		// Fail closed when no trusted proxy is configured: otherwise any client
		// could authenticate simply by sending the user header itself.
		$trusted = isset($cfg['trusted_proxies']) ? trim((string) $cfg['trusted_proxies']) : '';
		if ($trusted === '') {
			return array(
				'ok'      => false,
				'status'  => 500,
				'headers' => array(),
				'body'    => "Auth misconfigured: proxy mode requires 'trusted_proxies'.\n",
				'mode'    => 'proxy',
			);
		}
		$remote   = isset($server['REMOTE_ADDR']) ? (string) $server['REMOTE_ADDR'] : '';
		$proxy_ok = vpngw_ip_in_list($remote, $trusted);

		if ($user !== '' && $proxy_ok) {
			return array('ok' => true, 'status' => 200, 'headers' => array(), 'body' => '', 'mode' => 'proxy');
		}
		return array(
			'ok'      => false,
			'status'  => 403,
			'headers' => array(),
			'body'    => "Forbidden: authenticated user header missing or proxy not trusted.\n",
			'mode'    => 'proxy',
		);
	}

	// Unknown mode -> fail closed.
	return array(
		'ok'      => false,
		'status'  => 500,
		'headers' => array(),
		'body'    => "Authentication misconfigured (unknown mode).\n",
		'mode'    => 'invalid',
	);
}

// Extract HTTP Basic credentials, handling both the convenient PHP_AUTH_* vars
// and the raw Authorization header (CGI/FastCGI often doesn't populate
// PHP_AUTH_*). Returns array($user, $pass) with '' when absent.
function vpngw_basic_credentials(array $server)
{
	if (isset($server['PHP_AUTH_USER'])) {
		return array($server['PHP_AUTH_USER'], isset($server['PHP_AUTH_PW']) ? $server['PHP_AUTH_PW'] : '');
	}
	$h = '';
	if (isset($server['HTTP_AUTHORIZATION'])) {
		$h = $server['HTTP_AUTHORIZATION'];
	} elseif (isset($server['REDIRECT_HTTP_AUTHORIZATION'])) {
		$h = $server['REDIRECT_HTTP_AUTHORIZATION'];
	}
	if (is_string($h) && stripos($h, 'Basic ') === 0) {
		$dec = base64_decode(substr($h, 6), true);
		if ($dec !== false && strpos($dec, ':') !== false) {
			return explode(':', $dec, 2);
		}
	}
	return array('', '');
}

// Is $ip within a comma/space separated list of IPs or CIDRs (v4 and v6)?
function vpngw_ip_in_list($ip, $list)
{
	$ip = trim((string) $ip);
	if ($ip === '') {
		return false;
	}
	foreach (preg_split('/[\s,]+/', trim((string) $list)) as $entry) {
		if ($entry === '') {
			continue;
		}
		if (strpos($entry, '/') === false) {
			if ($entry === $ip) {
				return true;
			}
			continue;
		}
		if (vpngw_cidr_match($ip, $entry)) {
			return true;
		}
	}
	return false;
}

function vpngw_cidr_match($ip, $cidr)
{
	$parts = explode('/', $cidr, 2);
	if (count($parts) !== 2) {
		return false;
	}
	$ipb  = @inet_pton($ip);
	$netb = @inet_pton($parts[0]);
	if ($ipb === false || $netb === false || strlen($ipb) !== strlen($netb)) {
		return false;
	}
	$bits  = (int) $parts[1];
	$bytes = intdiv($bits, 8);
	$rem   = $bits % 8;
	if ($bytes > 0 && strncmp($ipb, $netb, $bytes) !== 0) {
		return false;
	}
	if ($rem === 0) {
		return true;
	}
	$mask = chr((0xff << (8 - $rem)) & 0xff);
	return (ord($ipb[$bytes]) & ord($mask)) === (ord($netb[$bytes]) & ord($mask));
}

// Enforce auth for the current request; send a challenge/denial and exit if not
// authorized. No-op when mode=off or the config is absent.
function vpngw_auth_require()
{
	$cfg = vpngw_load_conf(VPNGW_AUTH_CONF);
	$server = isset($_SERVER) && is_array($_SERVER) ? $_SERVER : array();
	$d = vpngw_auth_decision($cfg, $server);
	if ($d['ok']) {
		return;
	}
	if (!headers_sent()) {
		http_response_code($d['status']);
		foreach ($d['headers'] as $h) {
			header($h);
		}
		header('Content-Type: text/plain; charset=utf-8');
	}
	echo $d['body'];
	exit;
}

// Enforce on include, exactly once.
if (!defined('VPNGW_AUTH_ENFORCED')) {
	define('VPNGW_AUTH_ENFORCED', 1);
	vpngw_auth_require();
}
?>

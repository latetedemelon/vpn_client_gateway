<?php
// ---------------------------------------------------------------------------
// Shared configuration reader for /etc/vpngw/*.conf files.
//
// These are tiny "KEY=VALUE" files (shell-sourceable too): '#' or ';' starts a
// comment, blank lines are ignored, surrounding quotes on values are stripped,
// keys are lower-cased. The pure parser (vpngw_parse_kv) is unit-tested;
// vpngw_load_conf just adds the file read.
// ---------------------------------------------------------------------------

function vpngw_parse_kv($text)
{
	$out = array();
	if (!is_string($text) || $text === '') {
		return $out;
	}
	foreach (preg_split('/\R/u', $text) as $line) {
		$line = trim($line);
		if ($line === '' || $line[0] === '#' || $line[0] === ';') {
			continue;
		}
		$eq = strpos($line, '=');
		if ($eq === false) {
			continue;
		}
		$key = strtolower(trim(substr($line, 0, $eq)));
		$val = trim(substr($line, $eq + 1));
		if (strlen($val) >= 2) {
			$f = $val[0];
			$l = $val[strlen($val) - 1];
			if (($f === '"' && $l === '"') || ($f === "'" && $l === "'")) {
				$val = substr($val, 1, -1);
			}
		}
		if ($key !== '') {
			$out[$key] = $val;
		}
	}
	return $out;
}

function vpngw_load_conf($path)
{
	if (!is_readable($path)) {
		return array();
	}
	$t = @file_get_contents($path);
	return vpngw_parse_kv($t === false ? '' : $t);
}

// Interpret a config value as a boolean (1/true/yes/on/enabled vs the inverse).
function vpngw_conf_bool($v, $default = false)
{
	if ($v === null) {
		return $default;
	}
	$v = strtolower(trim((string) $v));
	if (in_array($v, array('1', 'true', 'yes', 'on', 'enabled'), true)) {
		return true;
	}
	if (in_array($v, array('0', 'false', 'no', 'off', 'disabled', ''), true)) {
		return false;
	}
	return $default;
}
?>

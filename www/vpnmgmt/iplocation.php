<?php
require_once(__DIR__ . '/auth.php');

// Look up the gateway's public IP and its geolocation for the Tools page.
//
// Security: both lookups use HTTPS with TLS verification, and EVERY value is
// HTML-escaped before output, so an on-path network attacker cannot inject
// markup/script into the admin page (the gateway sits on the network path by
// design, so this matters). The IP is validated before it is used or displayed.

function ip_http_get($url)
{
	$ch = curl_init();
	curl_setopt($ch, CURLOPT_URL, $url);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
	curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 0);
	curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
	curl_setopt($ch, CURLOPT_TIMEOUT, 10);
	curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 1);
	curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
	curl_setopt($ch, CURLOPT_USERAGENT, 'vpn-client-gateway');
	$out = curl_exec($ch);
	curl_close($ch);
	return is_string($out) ? trim($out) : '';
}

function ip_h($v)
{
	return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

// Public IP (plain text, HTTPS). Validate before trusting/using it.
$ipaddress = ip_http_get('https://api.ipify.org');

if (!filter_var($ipaddress, FILTER_VALIDATE_IP)) {
	echo "<P>Could not determine the public IP address.</P>";
} else {
	// Geolocation over HTTPS; the IP is validated above and URL-encoded.
	$response = ip_http_get('https://www.geoplugin.net/xml.gp?ip=' . urlencode($ipaddress));
	$ipgeoxml = ($response !== '') ? @simplexml_load_string($response) : false;

	echo "<TABLE class=\"ipinfotable\" id=\"IPLocationTable\">";
	if ($ipgeoxml !== false) {
		echo "<TR><TD>Country Name</TD><TD>" . ip_h($ipgeoxml->geoplugin_countryName) . "</TD></TR>";
		echo "<TR><TD>Country Code</TD><TD>" . ip_h($ipgeoxml->geoplugin_countryCode) . "</TD></TR>";
		echo "<TR><TD>Region Name</TD><TD>" . ip_h($ipgeoxml->geoplugin_regionName) . "</TD></TR>";
		echo "<TR><TD>Region Code</TD><TD>" . ip_h($ipgeoxml->geoplugin_regionCode) . "</TD></TR>";
		echo "<TR><TD>City</TD><TD>" . ip_h($ipgeoxml->geoplugin_city) . "</TD></TR>";
	}
	echo "<TR><TD>Public IP</TD><TD>" . ip_h($ipaddress) . "</TD></TR>";
	echo "</TABLE>";
}
?>

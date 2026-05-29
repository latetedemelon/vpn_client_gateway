<?php require_once('vpnmgmt/vpn_backend.php'); ?>
<H2>Current VPN server:
<?php
if (vpn_is_disabled()) {
	echo " none. All traffic originates from your ISP.</H2>";
} else {
	$servername = vpn_current_server();
	echo ($servername !== '' ? htmlspecialchars($servername) : 'unknown');
	echo "</H2>";
}
?>
<div id="CurrentVPNFlag">
<?php
if (vpn_is_disabled()) {
	$servername = 'none';
} else {
	$servername = vpn_current_server();
}
$vpnserverinfo = simplexml_load_file('vpnmgmt/vpnservers.xml');
$countryinfo = simplexml_load_file('vpnmgmt/countryflags.xml');
$xpathquery = '//vpnserver[servername="' . (string) $servername . '"]';
$serverinfo = $vpnserverinfo->xpath($xpathquery);
if (!empty($serverinfo)) {
	$countrynamestr = $serverinfo[0]->countryname;
	$regionstr = $serverinfo[0]->regionname;
	// Prefer a flag file embedded in the server entry (used by the WireGuard
	// provider lists, keyed by ISO country code); otherwise fall back to the
	// name-based lookup in countryflags.xml.
	$flagfilestr = isset($serverinfo[0]->flagfile) ? (string) $serverinfo[0]->flagfile : '';
	if ($flagfilestr === '') {
		$xpathquery = '//country[name="' . $countrynamestr . '"]';
		$country = $countryinfo->xpath($xpathquery);
		if (!empty($country)) {
			$flagfilestr = (string) $country[0]->flagfile;
		}
	}
	echo "<TABLE id=\"CurrentVPNFlagTable\">";
	echo "<TR><TD>";
	if ($flagfilestr !== '') {
		echo "<img id=\"CurrentVPNFlag\" width=40% src=\"images/flags/" . htmlspecialchars($flagfilestr) . "\">";
	}
	$location = $countrynamestr;
	if ($regionstr <> "") {
		$location = $location . " (" . $regionstr . ")";
	}
	echo "<P>" . htmlspecialchars($location) . "</P>";
	echo "</TD><TD></TD><TD></TD></TR></TABLE>";
} else {
	// VPN server not found in the list (e.g. list not yet generated).
}
?>
</div>


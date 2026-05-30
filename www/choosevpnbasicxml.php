<?php
require_once(__DIR__ . '/vpnmgmt/auth.php');
$columns=3;
$counter=1;
echo "<TABLE class=\"ChoicesTable\">\n<TR>\n";
$vpnserverinfo = simplexml_load_file('vpnmgmt/vpnservers.xml');
$countryinfo = simplexml_load_file('vpnmgmt/countryflags.xml');
$basicvpnservers = $vpnserverinfo->xpath('/basicvpnservers/servername');
$maxservers = $vpnserverinfo->basicvpnservers->servername->count();
foreach($vpnserverinfo->basicvpnservers->servername as $servername){
	$servernamestr = (string) $servername;
	$xpathquery = '//vpnserver[servername="' . $servernamestr . '"]';
	$serverinfo = $vpnserverinfo->xpath($xpathquery);
	if (empty($serverinfo)) continue;
	$countrynamestr = $serverinfo[0]->countryname;
	$regionstr = $serverinfo[0]->regionname;
	// Prefer a flag file embedded in the server entry (WireGuard provider
	// lists, keyed by ISO country code); otherwise look it up by name.
	$flagfilestr = isset($serverinfo[0]->flagfile) ? (string) $serverinfo[0]->flagfile : '';
	if ($flagfilestr === ''){
		$xpathquery = '//country[name="' . $countrynamestr . '"]';
		$country = $countryinfo->xpath($xpathquery);
		$flagfilestr = !empty($country) ? (string) $country[0]->flagfile : '';
	}
	echo "<TD>" . "\n";
	echo "<A HREF=\".?vpnserver=" . $servernamestr . "\" onclick=\"show_changing_vpn_message();\"><IMG height=60% SRC=\"images/flags/" . $flagfilestr . "\"/></A>" . "\n";
	echo "<P>" . $countrynamestr;
	if ($regionstr<>"") echo "<br>(" . $regionstr . ")";
	echo "</P>\n";
	echo "</TD>" . "\n";
	if (($counter % $columns == 0) and ($counter < $maxservers)) echo "</TR>\n<TR>\n";
	$counter = $counter + 1;
}
echo "</TR>\n";
echo "</TABLE>\n";
?>

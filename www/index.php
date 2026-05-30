<?php require_once(__DIR__ . '/vpnmgmt/auth.php'); ?>
<!doctype html>
<html lang="en">
<head>
	<meta http-equiv="Content-Type" content="text/html; charset=utf-8">
	<title>VPN Client Gateway Management</title>
	<link rel="stylesheet" type="text/css" href="index.css" />
	<link rel="shortcut icon" href="/images/favicon.ico" type="image/x-icon" />
	<?php require_once 'vpnmgmt/manage_vpn.php'; ?>
	<script type="text/javascript" src="js/app.js"></script>
</head>
<?php
// Server-rendered state, passed to the static JS via data-* attributes instead
// of emitting PHP-in-JavaScript.
$vpn_disabled_attr = vpn_is_disabled() ? '1' : '0';
$vpn_changed_attr  = isset($_GET['vpnserver']) ? '1' : '0';
?>
<body id="body" lang="en-CA" dir="LTR"
      data-vpn-disabled="<?php echo $vpn_disabled_attr; ?>"
      data-vpn-changed="<?php echo $vpn_changed_attr; ?>">

<div id="VPNChangeMessageOverlay" class="screenoverlay">
        <div id="VPNChangeMessage" class="vpnchangemessage">
                <H2>VPN Changed</H2>
                <P>Remember to restart media apps!</P>
        </div>
</div>

<div id="ChangingVPNMessageOverlay" class="screenoverlay">
        <div id="ChangingVPNMessage" class="changingvpnmessage">
                <H2>Changing VPN</H2>
                <P>This may take a few moments...</P>
        </div>
</div>

<div id="IPInfoOverlay" class="screenoverlay">
        <div id="IPInfoBox">
                <div id="IPInfoBoxTitle">
                        <H2>IP Geolocation</H2>
                </div>
                <div id="IPInfoBoxTableContainer">
                </div>
                <div id="ButtonContainer">
                        <button id="IPInfoCloseButton" onclick="hide_iplocationinfo();">Close</button>
                </div>
        </div>
</div>

<div id="TracerouteOverlay" class="screenoverlay">
        <div id="TracerouteInfoBox">
                <div id="TracerouteInfoBoxTitle">
                        <H2>Traceroute</H2>
                </div>
                <div id="TracerouteInfoContainer">
                </div>
		<div class="ButtonSpacer"></div>
                <div id="ButtonContainer">
                        <button id="TracerouteInfoCloseButton" onclick="hide_traceroute();">Close</button>
                </div>
        </div>
</div>

<div id="DNSLeakOverlay" class="screenoverlay">
        <div id="DNSLeakInfoBox">
                <div id="DNSLeakInfoBoxTitle">
                        <H2>DNS / IP leak test</H2>
                </div>
                <div id="DNSLeakInfoContainer">
                </div>
		<div class="ButtonSpacer"></div>
                <div id="ButtonContainer">
                        <button id="DNSLeakRetestButton" onclick="show_dnsleak();">Re-test</button>
                        <button id="DNSLeakCloseButton" onclick="hide_dnsleak();">Close</button>
                </div>
        </div>
</div>

<div id="SyslogOverlay" class="screenoverlay">
        <div id="SyslogInfoBox">
                <div id="SyslogInfoBoxTitle">
                        <H2>syslog</H2>
                </div>
                <div id="SyslogInfoContainer">
                </div>
                <div class="ButtonSpacer"></div>
                <div id="ButtonContainer">
                        <button id="SyslogInfoCloseButton" onclick="hide_syslog();">Close</button>
                </div>
        </div>
</div>

<div id="DisableVPNOverlay" class="screenoverlay">
        <div id="DisableVPNInfoBox">
                <div id="DisableVPNInfoBoxTitle">
                        <H2>Disable VPN</H2>
                </div>
                <div id="DisableVPNInfoContainer">
			<P>Disabling VPN service. Network traffic will be forwarded via your normal ISP internet connection.</P>
                </div>
		<div class="ButtonSpacer"></div>
                <div id="ButtonContainer">
                        <table id="DisableVPNButtonTable">
			<tr>
				<td><button id="DisableVPNCancelButton" onclick="hide_disable_vpn();">Cancel</button></td>
				<td></td>
                        	<td><button id="DisableVPNContinueButton" onclick="disable_vpn();">Continue</button></td>
			</tr>
			</table>
                </div>
        </div>
</div>

<div id="EnableVPNOverlay" class="screenoverlay">
        <div id="EnableVPNInfoBox">
                <div id="EnableVPNInfoBoxTitle">
                        <H2>Enable VPN</H2>
                </div>
                <div id="EnableVPNInfoContainer">
			<P>Enabling VPN service. Network traffic will be forwarded via your VPN connection.</P>
                </div>
		<div class="ButtonSpacer"></div>
                <div id="ButtonContainer">
                        <table id="EnableVPNButtonTable">
			<tr>
				<td><button id="EnablePNCancelButton" onclick="hide_enable_vpn();">Cancel</button></td>
				<td></td>
                        	<td><button id="EnableVPNContinueButton" onclick="enable_vpn();">Continue</button></td>
			</tr>
			</table>
                </div>
        </div>
</div>

<div id="ShutdownOverlay" class="screenoverlay">
        <div id="ShutdownInfoBox">
                <div id="ShutdownInfoBoxTitle">
                        <H2>Shut Down VPN Client Gateway</H2>
                </div>
                <div id="ShutdownInfoContainer">
			<P>Warning: after shutting down the VPN Client Gateway server, it must be powered back on manually.</P>
                </div>
		<div class="ButtonSpacer"></div>
                <div id="ButtonContainer">
                        <table id="ShutdownButtonTable">
			<tr>
				<td><button id="ShutdownCancelButton" onclick="hide_shutdown();">Cancel</button></td>
				<td></td>
                        	<td><button id="ShutdownContinueButton" onclick="shutdown();">Continue</button></td>
			</tr>
			</table>
                </div>
        </div>
</div>

<div id="RebootOverlay" class="screenoverlay">
        <div id="RebootInfoBox">
                <div id="RebootInfoBoxTitle">
                        <H2>Reboot VPN Client Gateway</H2>
                </div>
                <div id="RebootInfoContainer">
                        <P>Rebooting will take approximately 90 seconds. All sessions will be terminated.</P>
                </div>
                <div class="ButtonSpacer"></div>
                <div id="ButtonContainer">
                        <table id="RebootButtonTable">
                        <tr>
                                <td><button id="RebootCancelButton" onclick="hide_reboot();">Cancel</button></td>
                                <td></td>
                                <td><button id="RebootContinueButton" onclick="reboot();">Continue</button></td>
                        </tr>
                        </table>
                </div>
        </div>
</div>

<div class="header">
<H1>VPN Client Gateway Management</H1>
</div>
<div id="MainContainer">
	<div id="NavigationMenu" class="buttonmenu">
		<ul>
		<li><a href="javascript:void(0);" onclick="show_basic();">Basic</a></li>
		<div class="menubuttonspacer"></div>
		<li><a href="javascript:void(0);" onclick="show_advanced();">Advanced</a></li>
		<div class="menubuttonspacer"></div>
		<li><a href="javascript:void(0);" onclick="show_tools();">Tools</a></li>
		<div class="menubuttonspacer"></div>
		<li><a href="javascript:void(0);" onclick="show_admin();">Admin</a></li>
		</ul>
	</div>
	<div id="PageContainer">
		<div id="VPNSection">
		<div id="CurrentVPNSection">
		</div>
		<div id="StatusSection">
			<H2>Connection status <span id="stBadge" class="stbadge st-unknown">checking&hellip;</span></H2>
			<table id="StatusTable">
				<tr><td class="stlabel">Server</td><td id="stServer">&hellip;</td></tr>
				<tr><td class="stlabel">Exit IP</td><td id="stExit">&hellip;</td></tr>
				<tr><td class="stlabel">Last handshake</td><td id="stHandshake">&hellip;</td></tr>
				<tr><td class="stlabel">Backend</td><td id="stBackend">&hellip;</td></tr>
			</table>
		</div>
		<div id="ThroughputSection">
			<H2>Throughput</H2>
			<table id="ThroughputTable">
				<thead>
					<tr>
						<th class="tpwhat">Interface</th>
						<th>&#8595; RX (in)</th>
						<th>&#8593; TX (out)</th>
					</tr>
				</thead>
				<tbody id="tpBody">
					<tr><td class="tplabel">&hellip;</td><td class="tprate">&hellip;</td><td class="tprate">&hellip;</td></tr>
				</tbody>
			</table>
			<p class="tplegend"><strong>WAN</strong> = internet uplink (outflow) &middot; <strong>LAN</strong> = client-facing (inflow) &middot; <strong>VPN</strong> = encrypted tunnel. Per-interface RX (received) / TX (transmitted) by the gateway.</p>
		</div>
		<div id="VPNChooser">
		<H2>Choose new VPN server:</H2>
			<div id="ChooseVPNBasic">
			</div>
			<div id="ChooseVPNAdvanced">
			</div>
		</div>
		</div>
		<div id="Tools">
			<div id="ToolsMenu" class="buttonmenu">
			<ul>
			<li><a href="javascript:void(0);" onclick="showIPGeolocation();">Get IP address geolocation</a></li>
			<div class="menubuttonspacer"></div>
			<li><a href="javascript:void(0);" onclick="show_dnsleak();">Test for DNS / IP leaks</a></li>
			<div class="menubuttonspacer"></div>
			<li><a href="javascript:void(0);" onclick="show_traceroute();">Run traceroute</a></li>
			<div class="menubuttonspacer"></div>
			<li><a href="javascript:void(0);" onclick="show_syslog();">View syslog</a></li>
			</ul>
			</div>
		</div>
		<div id="Admin">
			<div id="AdminMenu" class="buttonmenu">
			<ul>
                        <div id="EnableVPNMenuButton">
                                <li><a href="javascript:void(0);" onclick="show_enable_vpn();">Enable VPN</a></li>
                                <div class="menubuttonspacer"></div>
                        </div>
                        <div id="DisableVPNMenuButton">
                                <li><a href="javascript:void(0);" onclick="show_disable_vpn();">Disable VPN</a></li>
                                <div class="menubuttonspacer"></div>
                        </div>
			<li><a href="javascript:void(0);" onclick="show_reboot();">Reboot</a></li>
			<div class="menubuttonspacer"></div>
			<li><a href="javascript:void(0);" onclick="show_shutdown();">Shut down</a></li>
			</ul>
			</div>
		</div>
	</div>
</div>
<footer>
<?php if (vpn_is_wireguard()): ?>
<span id="BackendLabel" class="backendlabel">Powered by WireGuard&reg;</span>
<?php else: ?>
<img id="OpenVPNLogo" class="openvpnlogo" src="images/openvpn_logo_powered_by.png" alt="OpenVPN logo"/>
<?php endif; ?>
</footer>
</body>
</html>

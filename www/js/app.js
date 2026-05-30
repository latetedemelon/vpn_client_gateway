// VPN Client Gateway -- management UI (vanilla JS, no framework, no build step).
//
// Replaces the previous jQuery-based inline scripts. Server-side state is passed
// in via data-* attributes on <body> rather than PHP-in-JS, so this file is
// static and the page contains no third-party script.
"use strict";

// --- tiny DOM helpers -------------------------------------------------------
function qs(id) { return document.getElementById(id); }
function show(id) { var e = qs(id); if (e) e.style.display = ""; }
function hide(id) { var e = qs(id); if (e) e.style.display = "none"; }
function setHTML(id, html) { var e = qs(id); if (e) e.innerHTML = html; }
function esc(s) {
	return String(s == null ? "" : s).replace(/[&<>"']/g, function (c) {
		return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c];
	});
}
function getText(url, cb) {
	fetch(url, { credentials: "same-origin" })
		.then(function (r) { return r.text(); })
		.then(function (t) { cb(t); })
		.catch(function () { cb(null); });
}
function getJSON(url, cb) {
	fetch(url, { credentials: "same-origin", headers: { "Accept": "application/json" } })
		.then(function (r) { return r.json(); })
		.then(function (j) { cb(j); })
		.catch(function () { cb(null); });
}
function loadFragment(id, url) { getText(url, function (t) { if (t !== null) setHTML(id, t); }); }

// --- main view switching ----------------------------------------------------
function show_advanced() { show("VPNSection"); hide("Admin"); hide("Tools"); hide("ChooseVPNBasic"); show("ChooseVPNAdvanced"); }
function show_basic()    { show("VPNSection"); hide("Admin"); hide("Tools"); show("ChooseVPNBasic"); hide("ChooseVPNAdvanced"); }
function show_tools()    { show("Tools"); hide("VPNSection"); hide("Admin"); }
function show_admin() {
	hide("Tools"); hide("VPNSection"); show("Admin");
	// Toggle Enable/Disable based on server-rendered state (data attribute).
	var disabled = document.body.getAttribute("data-vpn-disabled") === "1";
	if (disabled) { show("EnableVPNMenuButton"); hide("DisableVPNMenuButton"); }
	else { hide("EnableVPNMenuButton"); show("DisableVPNMenuButton"); }
}

// --- Tools: IP geolocation / traceroute / syslog / DNS leak -----------------
function showIPGeolocation() {
	setHTML("IPInfoBoxTableContainer", "");
	show("IPInfoOverlay");
	getText("vpnmgmt/iplocation.php", function (t) {
		setHTML("IPInfoBoxTableContainer", t || "<p>Lookup failed.</p>");
		var c = qs("IPInfoBoxTableContainer"); if (c) c.style.background = "white";
	});
}
function hide_iplocationinfo() { hide("IPInfoOverlay"); var c = qs("IPInfoBoxTableContainer"); if (c) c.style.background = ""; }

function show_traceroute() {
	setHTML("TracerouteInfoContainer", "");
	show("TracerouteOverlay");
	getText("vpnmgmt/traceroute.php", function (t) {
		setHTML("TracerouteInfoContainer", t || "<p>Traceroute failed.</p>");
		var c = qs("TracerouteInfoContainer"); if (c) c.style.background = "white";
	});
}
function hide_traceroute() { hide("TracerouteOverlay"); var c = qs("TracerouteInfoContainer"); if (c) c.style.background = ""; }

function show_syslog() {
	setHTML("SyslogInfoContainer", "");
	show("SyslogOverlay");
	getText("vpnmgmt/syslog.php", function (t) { setHTML("SyslogInfoContainer", t || "<p>Could not read syslog.</p>"); });
}
function hide_syslog() { hide("SyslogOverlay"); }

// DNS / IP leak test.
function show_dnsleak() {
	show("DNSLeakOverlay");
	setHTML("DNSLeakInfoContainer", '<p class="dnsleak-testing">Testing &hellip; resolving through the gateway and checking the exit IP.</p>');
	getJSON("vpnmgmt/dnsleak.php", function (d) {
		if (!d) { setHTML("DNSLeakInfoContainer", "<p>Test failed (could not reach the test endpoint).</p>"); return; }
		setHTML("DNSLeakInfoContainer", renderLeak(d));
	});
}
function hide_dnsleak() { hide("DNSLeakOverlay"); }

function renderLeak(d) {
	var dns = d.dns || {}, ip = d.ip || {}, vpn = d.vpn || {};
	var statusClass, statusText;
	if (!vpn.running) { statusClass = "leak-warn"; statusText = "VPN is OFF — traffic and DNS use your ISP."; }
	else if (dns.status === "pass") { statusClass = "leak-pass"; statusText = "No DNS leak detected"; }
	else if (dns.status === "leak") { statusClass = "leak-fail"; statusText = "DNS LEAK detected"; }
	else { statusClass = "leak-warn"; statusText = "DNS resolver could not be determined"; }

	var h = '<div class="leak-banner ' + statusClass + '">' + esc(statusText) + "</div>";
	h += '<table class="ipinfotable leak-table">';
	h += row("DNS resolver(s) that answered", (dns.servers && dns.servers.length) ? dns.servers.map(esc).join(", ") : "—");
	h += row("Expected (VPN) resolvers", (dns.expected && dns.expected.length) ? dns.expected.map(esc).join(", ") : "—");
	if (dns.leaking && dns.unexpected && dns.unexpected.length) {
		h += row("Leaking to", '<span class="leak-fail-text">' + dns.unexpected.map(esc).join(", ") + "</span>");
	}
	h += row("Exit IP", ip.exit_ip ? esc(ip.exit_ip) : "—");
	h += row("Exit country", ip.country ? esc(ip.country) : "—");
	if (ip.is_nordvpn !== null && ip.is_nordvpn !== undefined) {
		h += row("Recognised VPN address", ip.is_nordvpn ? "yes" : "no");
	}
	h += "</table>";
	h += '<p class="leak-note">The DNS test reflects the resolver the gateway uses. To force all client DNS through the tunnel, enable <code>force_dns</code> in /etc/vpngw/leak.conf.</p>';
	return h;
}
function row(k, v) { return "<tr><td>" + esc(k) + "</td><td>" + v + "</td></tr>"; }

// --- Admin: shutdown / reboot / enable / disable ----------------------------
function show_shutdown() { show("ShutdownOverlay"); }
function hide_shutdown() { hide("ShutdownOverlay"); }
function shutdown() {
	hide("ShutdownButtonTable");
	setHTML("ShutdownInfoContainer", "<P>Shutting down. Unplug after 60 seconds.<P>");
	getText("vpnmgmt/shutdown.php", function () {});
}
function show_reboot() { show("RebootOverlay"); }
function hide_reboot() { hide("RebootOverlay"); }
function reboot() {
	hide("RebootButtonTable");
	getText("vpnmgmt/reboot.php", function () {});
	var counter = 90;
	var id = setInterval(function () {
		counter--;
		if (counter < 0) { clearInterval(id); hide("RebootOverlay"); window.location.reload(); }
		else { setHTML("RebootInfoContainer", "<P>Rebooting. Page will reload in " + counter + " seconds.<P>"); }
	}, 1000);
}
function show_enable_vpn() { show("EnableVPNOverlay"); }
function hide_enable_vpn() { hide("EnableVPNOverlay"); }
function show_disable_vpn() { show("DisableVPNOverlay"); }
function hide_disable_vpn() { hide("DisableVPNOverlay"); }
function show_changing_vpn_message() { show("ChangingVPNMessageOverlay"); }

function enable_vpn() {
	hide("EnableVPNOverlay"); show_changing_vpn_message();
	getText("vpnmgmt/enablevpn.php", function () { window.location.reload(); });
}
function disable_vpn() {
	hide("DisableVPNOverlay"); show_changing_vpn_message();
	getText("vpnmgmt/disablevpn.php", function () { window.location.reload(); });
}

// --- live status panel ------------------------------------------------------
(function () {
	function fmtStatus(s) {
		if (!s.enabled) return { cls: "st-off", txt: "VPN disabled" };
		if (!s.running) return { cls: "st-down", txt: "Tunnel down" };
		if (s.healthy) return { cls: "st-up", txt: "Connected" };
		return { cls: "st-warn", txt: "Connected (no recent handshake)" };
	}
	function val(v) { return (v === null || v === undefined || v === "") ? "—" : esc(v); }
	function tick() {
		if (document.hidden) return;
		getJSON("vpnmgmt/status.php", function (s) {
			if (!s) return;
			var st = fmtStatus(s);
			var badge = qs("stBadge");
			if (badge) { badge.className = "stbadge " + st.cls; badge.textContent = st.txt; }
			setHTML("stServer", val(s.server));
			setHTML("stExit", s.exit_ip ? (val(s.exit_ip) + (s.exit_country ? " (" + esc(s.exit_country) + ")" : "")) : "—");
			setHTML("stHandshake", val(s.handshake_str));
			setHTML("stBackend", val(s.backend));
		});
	}
	window.addEventListener("DOMContentLoaded", function () { tick(); setInterval(tick, 5000); });
})();

// --- live throughput meter (was jQuery) -------------------------------------
(function () {
	var prev = null, POLL_MS = 2000;
	function fmt(bps) {
		if (bps === null || isNaN(bps)) return "—";
		if (bps < 0) bps = 0;
		var u = ["B/s", "KB/s", "MB/s", "GB/s"], i = 0, v = bps;
		while (v >= 1024 && i < u.length - 1) { v = v / 1024; i++; }
		var dp = (i === 0) ? 0 : (v < 10 ? 1 : 0);
		return v.toFixed(dp) + " " + u[i];
	}
	function rate(cur, old, dt) { if (cur === null || old === null) return null; var d = cur - old; return d < 0 ? null : d / dt; }
	function roleMeta(role, multi) {
		if (role === "vpn") return { word: "VPN", desc: "tunnel" };
		if (role === "wan") return multi ? { word: "WAN", desc: "outflow" } : { word: "WAN/LAN", desc: "uplink" };
		return { word: "LAN", desc: "inflow" };
	}
	function cellRate(r, pm, dt, which) {
		if (r.role === "vpn" && !r.up) return (which === "rx") ? "VPN off" : "—";
		if (!r.up || r[which] === null) return "—";
		if (pm && pm[r.iface] && dt > 0) return fmt(rate(r[which], pm[r.iface][which], dt));
		return "…";
	}
	function tick() {
		if (document.hidden) return;
		getJSON("vpnmgmt/throughput.php", function (s) {
			if (!s || !s.rows) return;
			var dt = (prev && s.t > prev.t) ? (s.t - prev.t) / 1000.0 : 0;
			var pm = prev ? prev.m : null, html = "", m = {};
			for (var i = 0; i < s.rows.length; i++) {
				var r = s.rows[i], meta = roleMeta(r.role, s.multi);
				html += '<tr class="tprow tprole-' + esc(r.role) + '">'
					+ '<td class="tplabel"><strong>' + esc(meta.word) + "</strong> "
					+ '<span class="tpiface">' + esc(r.iface) + "</span> "
					+ '<span class="tprole">' + esc(meta.desc) + "</span></td>"
					+ '<td class="tprate">' + cellRate(r, pm, dt, "rx") + "</td>"
					+ '<td class="tprate">' + cellRate(r, pm, dt, "tx") + "</td></tr>";
				m[r.iface] = { rx: r.rx, tx: r.tx };
			}
			setHTML("tpBody", html);
			prev = { t: s.t, m: m };
		});
	}
	window.addEventListener("DOMContentLoaded", function () { tick(); setInterval(tick, POLL_MS); });
})();

// --- page bootstrap ---------------------------------------------------------
window.addEventListener("DOMContentLoaded", function () {
	loadFragment("CurrentVPNSection", "currentvpnsection.php");
	loadFragment("ChooseVPNBasic", "choosevpnbasicxml.php");
	loadFragment("ChooseVPNAdvanced", "choosevpnadvancedxml.php");

	// "VPN changed" modal after a ?vpnserver= action (server set the flag).
	if (document.body.getAttribute("data-vpn-changed") === "1") {
		show("VPNChangeMessageOverlay");
		setTimeout(function () { hide("VPNChangeMessageOverlay"); }, 5000);
		window.history.pushState("", "", "/");
	}
});

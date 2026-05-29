<?php
// Backward-compatibility shim.
//
// VPN management is now backend-agnostic (OpenVPN or WireGuard) and lives in
// manage_vpn.php. This file is kept so that any existing references (e.g. the
// bundled index.php from older releases) keep working.
require_once(__DIR__ . '/manage_vpn.php');
?>

<?php
require_once(__DIR__ . '/util.php');
require_once(__DIR__ . '/vpn_backend.php');
// Route traffic through the VPN and arm the kill switch (works for both the
// OpenVPN and WireGuard backends). The tunnel itself is started by the caller.
vpn_enable();
?>

<?php
require_once(__DIR__ . '/util.php');
require_once(__DIR__ . '/vpn_backend.php');
// Stop the VPN, forward traffic via the normal ISP link and disarm the kill
// switch (works for both the OpenVPN and WireGuard backends).
vpn_disable();
?>

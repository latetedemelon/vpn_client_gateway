<?php
require_once(__DIR__ . '/auth.php');
require_once('util.php');
echo "Rebooting server...";
$result = reboot();
?>

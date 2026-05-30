<?php
require_once(__DIR__ . '/auth.php');
require_once('util.php');
echo "Shutting down server...";
$result = shutdown();
?>

<?php
// THIS FILE SIGNS THE USER OUT BY CLEARING THE SESSION AND RETURNING THEM TO LOGIN.
require_once __DIR__ . '/session_config.php';
session_start();
session_destroy();
header("Location: index.php");
exit();
?>

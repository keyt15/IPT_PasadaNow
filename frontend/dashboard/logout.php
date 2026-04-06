<?php
session_start();
session_unset();
session_destroy();

// This goes up one level from 'dashboard' to 'frontend' to find login.php
header("Location: ../login.php");
exit();
session_destroy();
header('Location: ../login.php');
exit;
?>
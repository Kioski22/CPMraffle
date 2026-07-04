<?php
session_start();

// Preserve the current role long enough to send users back to the matching login portal.
$logoutRole = strtolower(trim((string) ($_SESSION['role'] ?? '')));

// Destroy all session data
session_unset();
session_destroy();

// Redirect to login page
$redirectTo = $logoutRole === 'chapter' ? 'chapter_login.php' : 'login.php';
header("Location: {$redirectTo}");
exit();
?>
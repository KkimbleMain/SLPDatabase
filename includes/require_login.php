<?php
// Ensure session is started and the auth helpers are available
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/auth.php';

// Avoid redirect loop: if request is already for login.php, do not redirect
$script = basename($_SERVER['SCRIPT_NAME'] ?? '');
$onLoginPage = in_array($script, ['login.php'], true);

if (!isLoggedIn() && !$onLoginPage) {
    header('Location: /login.php');
    exit();
}
?>

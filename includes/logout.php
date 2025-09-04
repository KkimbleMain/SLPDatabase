<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';

if (isLoggedIn() && function_exists('logActivity')) {
    logActivity($_SESSION['user_id'], 'logout', 'User logged out');
}

// Perform logout (session destroy and redirect)
logout();
?>

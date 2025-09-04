<?php
/**
 * Check if user is logged in
 */
function isLoggedIn() {
    // Basic session check
    if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) return false;

    // Ensure the user still exists in the data store. If the user was removed from users.json
    // we should clear the session to prevent a deleted user from remaining authenticated.
    $uid = $_SESSION['user_id'];
    $user = findRecord('users', 'id', $uid);
    if (!$user) {
        // clear session to invalidate login
        try { $_SESSION = []; } catch (e) { }
        if (session_status() !== PHP_SESSION_NONE) {
            // remove session cookie when possible
            if (ini_get('session.use_cookies')) {
                $params = session_get_cookie_params();
                setcookie(session_name(), '', time() - 42000,
                    $params['path'] ?? '/', $params['domain'] ?? '',
                    $params['secure'] ?? false, $params['httponly'] ?? false
                );
            }
            @session_destroy();
        }
        return false;
    }

    return true;
}

/**
 * Get current user data
 */
function getCurrentUser() {
    if (!isLoggedIn()) {
        return null;
    }
    
    static $user = null;
    
    if ($user === null) {
    // Use JSON-backed lookup by default
    $user = findRecord('users', 'id', $_SESSION['user_id']);
    }
    
    return $user;
}

/**
 * Check if user has specific role
 */
function hasRole($role) {
    $user = getCurrentUser();
    return $user && $user['role'] === $role;
}

/**
 * Check if user can access student
 */
function canAccessStudent($student_id) {
    if (!isLoggedIn()) {
        return false;
    }
    
    $student = findRecord('students', 'id', $student_id);
    return $student && $student['assigned_therapist'] == $_SESSION['user_id'];
}

/**
 * Logout user
 */
function logout() {
    session_destroy();
    header('Location: /login.php');
    exit();
}

/**
 * Require login
 */
function requireLogin() {
    if (!isLoggedIn()) {
    header('Location: /login.php');
        exit();
    }
}

/**
 * Require specific role
 */
function requireRole($role) {
    requireLogin();
    if (!hasRole($role)) {
    header('Location: /index.php?error=access_denied');
        exit();
    }
}

/**
 * Hash password
 */
function hashPassword($password) {
    return password_hash($password, PASSWORD_DEFAULT);
}

/**
 * Verify password
 */
function verifyPassword($password, $hash) {
    return password_verify($password, $hash);
}
?>

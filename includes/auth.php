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

// Roles removed: all users have same privileges. hasRole always returns false.
function hasRole($role) {
    return false;
}

/**
 * Check if user can access student
 */
function canAccessStudent($student_id) {
    if (!isLoggedIn()) {
        return false;
    }
    // Prefer checking the SQLite DB when available. Use students.user_id when present, else fallback to assigned_therapist.
    try {
        if (file_exists(__DIR__ . '/sqlite.php')) {
            require_once __DIR__ . '/sqlite.php';
            if (function_exists('get_db')) {
                $pdo = get_db();
                $pi = $pdo->prepare("PRAGMA table_info('students')");
                $pi->execute();
                $cols = array_column($pi->fetchAll(PDO::FETCH_ASSOC), 'name');
                if (in_array('user_id', $cols)) {
                    $stmt = $pdo->prepare('SELECT user_id FROM students WHERE id = :id LIMIT 1');
                    $stmt->execute([':id' => $student_id]);
                    $r = $stmt->fetch(PDO::FETCH_ASSOC);
                    if ($r) return isset($r['user_id']) && (int)$r['user_id'] === (int)($_SESSION['user_id'] ?? 0);
                    return false;
                } elseif (in_array('assigned_therapist', $cols)) {
                    $stmt = $pdo->prepare('SELECT assigned_therapist FROM students WHERE id = :id LIMIT 1');
                    $stmt->execute([':id' => $student_id]);
                    $r = $stmt->fetch(PDO::FETCH_ASSOC);
                    if ($r) return isset($r['assigned_therapist']) && (int)$r['assigned_therapist'] === (int)($_SESSION['user_id'] ?? 0);
                    return false;
                }
            }
        }
    } catch (\Throwable $e) {
        // fall through to legacy JSON-backed check
    }

    // Legacy fallback: JSON-backed findRecord may return student arrays with assigned_therapist key
    $student = findRecord('students', 'id', $student_id);
    if ($student && isset($student['user_id'])) {
        return ((string)$student['user_id'] === (string)($_SESSION['user_id'] ?? ''));
    }
    return $student && isset($student['assigned_therapist']) && ((string)$student['assigned_therapist'] === (string)($_SESSION['user_id'] ?? ''));
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
    // Ensure session started so isLoggedIn() can inspect session values
    if (session_status() === PHP_SESSION_NONE) session_start();

    // Avoid redirect loop: if current script is login.php, don't redirect
    $script = basename($_SERVER['SCRIPT_NAME'] ?? '');
    $onLoginPage = in_array($script, ['login.php'], true);

    if (!isLoggedIn() && !$onLoginPage) {
        header('Location: /login.php');
        exit();
    }
}

// Roles removed: no-op guard retained for compatibility
function requireRole($role) {
    requireLogin();
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

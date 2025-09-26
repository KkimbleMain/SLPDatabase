<?php
// Merged login loader + template (single webroot login file)
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';

// If already logged in, redirect to dashboard
if (function_exists('isLoggedIn') && isLoggedIn()) {
    header('Location: /index.php');
    exit();
}

// Check if any users exist, if not redirect to setup
$users = loadJsonData('users');
$noUsers = empty($users); // show login page even when no users exist; provide link to setup

$error = '';
$success = '';

if ($_POST) {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    
    if ($username && $password) {
        // Find user in JSON data
        $users = loadJsonData('users');
        require_once __DIR__ . '/includes/sqlite.php';
        $pdo = get_db();
        $stmt = $pdo->prepare('SELECT * FROM users WHERE username = :u OR email = :u LIMIT 1');
        $stmt->execute([':u' => $username]);
        $u = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($u) {
            $ok = false;
            if (!empty($u['password_hash'])) {
                $ok = password_verify($password, $u['password_hash']);
            } else {
                // fallback to legacy plaintext password field
                $ok = isset($u['password']) && $u['password'] === $password;
            }
            if ($ok) {
                $_SESSION['user_id'] = $u['id'];
                $_SESSION['username'] = $u['username'];
                $_SESSION['role'] = $u['role'];
                $_SESSION['first_name'] = $u['first_name'];
                
                // Add success message for debugging
                $success = 'Login successful! Redirecting...';
                
                // Use JavaScript redirect instead of PHP header to avoid header already sent issues
                echo "<script>setTimeout(function(){ window.location.href = 'index.php'; }, 1000);</script>";
            } else {
                $error = 'Invalid credentials - please check username and password';
            }
        } else {
            $error = 'Invalid credentials - please check username and password';
        }
    } else {
        $error = 'Please fill in all fields';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SLP Database - Login</title>
    <link rel="stylesheet" href="/assets/css/login.css">
    <link rel="stylesheet" href="/assets/css/common.css">
    <meta name="csrf-token" content="<?php echo htmlspecialchars(generateCSRFToken()); ?>">
</head>
<body class="login-page">
    <div class="login-container">
        <div class="login-form">
            <h1>SLP Database Login</h1>
            
            <?php if ($error): ?>
                <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            
            <form method="POST" id="loginForm">
                <div class="form-group">
                    <label for="username">Username:</label>
                    <input type="text" id="username" name="username" required>
                </div>
                
                <div class="form-group">
                    <label for="password">Password:</label>
                    <input type="password" id="password" name="password" required>
                </div>
                
                <button type="submit" class="btn btn-primary">Login</button>
            </form>
            
            <p style="margin-top:0.5rem;"><a href="#" id="forgotPasswordLink">Forgot password?</a></p>

            <?php if ($noUsers): ?>
                <div class="alert alert-info">No users found. <a href="setup.php">Create the first administrator</a>.</div>
            <?php else: ?>
                <p class="register-link">Don't have an account? <a href="#" id="openRegister">Register here</a></p>
            <?php endif; ?>
        </div>
    </div>
    
    <?php include_once __DIR__ . '/templates/modal_templates.php'; ?>

    <script type="module" src="/assets/js/main.js"></script>
    <script>
        document.getElementById('openRegister')?.addEventListener('click', function(e){
            e.preventDefault();
            if (window.showRegisterModal) window.showRegisterModal();
        });
    </script>
</body>
</html>
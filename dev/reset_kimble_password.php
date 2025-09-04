<?php
// dev/reset_kimble_password.php
// Quick script to reset kimble user password to a known value

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "Starting password reset...\n";

// Set the new password (6 letters - using username)
$new_password = 'kimble';

// Load users
$users_file = __DIR__ . '/../database/data/users.json';
echo "Loading users from: $users_file\n";

if (!file_exists($users_file)) {
    die("Users file not found at: $users_file\n");
}

$users_content = file_get_contents($users_file);
$users = json_decode($users_content, true);

if (!$users) {
    die("Failed to parse users.json\n");
}

echo "Found " . count($users) . " users\n";

// Find kimble user
$kimble_found = false;
foreach ($users as $index => &$user) {
    echo "Checking user: " . $user['username'] . "\n";
    if ($user['username'] === 'kimble') {
        echo "Found kimble user at index $index\n";
        // Hash the new password
        $user['password'] = password_hash($new_password, PASSWORD_DEFAULT);
        $kimble_found = true;
        echo "Password hashed: " . $user['password'] . "\n";
        break;
    }
}

if (!$kimble_found) {
    die("Kimble user not found!\n");
}

// Save back to file
$result = file_put_contents($users_file, json_encode($users, JSON_PRETTY_PRINT));
if ($result === false) {
    die("Failed to write users file!\n");
}

echo "Password reset successfully!\n";
echo "Username: kimble\n";
echo "New password: $new_password\n";
echo "\nYou can now login with these credentials.\n";
?>

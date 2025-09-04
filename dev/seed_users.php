<?php
// dev/seed_users.php
// Lightweight dev-only script to insert sample users for local testing.
// Safety: only accessible from localhost (127.0.0.1 or ::1).

// Allow running from the built-in webserver or CLI for convenience in dev.
if (!in_array(php_sapi_name(), ['cli-server', 'cli'])) {
    $remote = $_SERVER['REMOTE_ADDR'] ?? '';
    if (!in_array($remote, ['127.0.0.1', '::1'])) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Forbidden - dev script only available on localhost']);
        exit();
    }
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';

// Ensure session is availablee
if (session_status() === PHP_SESSION_NONE) session_start();

header('Content-Type: application/json');

$seed = [
    [
        'username' => 'admin',
        'password' => 'password123',
        'first_name' => 'Site',
        'last_name' => 'Admin',
        'role' => 'admin'
    ],
    [
        'username' => 'therapist',
        'password' => 'password123',
        'first_name' => 'Sam',
        'last_name' => 'Therapist',
        'role' => 'therapist'
    ]
];

$created = [];
foreach ($seed as $u) {
    // if username already exists, include it in the response with the plain password for testing
    $existing = findRecord('users', 'username', $u['username']);
    if ($existing) {
        $created[] = [
            'id' => $existing['id'] ?? null,
            'username' => $existing['username'] ?? $u['username'],
            'first_name' => $existing['first_name'] ?? '',
            'last_name' => $existing['last_name'] ?? '',
            'role' => $existing['role'] ?? '',
            'created_at' => $existing['created_at'] ?? '',
            'plain_password' => $u['password']
        ];
        continue;
    }

    // keep the plain password for the JSON response only (do NOT store plaintext)
    $plainPassword = $u['password'];

    $user = [
        'username' => sanitizeInput($u['username']),
        'password' => password_hash($plainPassword, PASSWORD_DEFAULT),
        'first_name' => sanitizeInput($u['first_name']),
        'last_name' => sanitizeInput($u['last_name']),
        'role' => $u['role'],
        'created_at' => date('Y-m-d H:i:s')
    ];
    $id = insertRecord('users', $user);
    if ($id) {
        // return the created record but include the plain password for developer convenience
        $userForResponse = $user;
        $userForResponse['id'] = $id;
        $userForResponse['plain_password'] = $plainPassword;
        $created[] = $userForResponse;
    }
}

echo json_encode(['created' => $created], JSON_PRETTY_PRINT);

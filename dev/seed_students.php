<?php
// dev/seed_students.php
// Lightweight dev-only script to insert sample students for local testing.
// Safety: allow running from the built-in webserver or CLI for developer convenience.

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

// Ensure session is available
if (session_status() === PHP_SESSION_NONE) session_start();

header('Content-Type: application/json');

// Who will be assigned as therapist? prefer current user if logged in
$user_id = $_SESSION['user_id'] ?? null;
if (!$user_id) {
    // fallback: pick first user from users.json or default to 1
    $users = loadJsonData('users');
    $user_id = $users[0]['id'] ?? 1;
}

// Sample students to insert
$seed = [
    ['first_name' => 'Ava', 'last_name' => 'Smith', 'grade' => '3', 'age' => 8],
    ['first_name' => 'Liam', 'last_name' => 'Johnson', 'grade' => '5', 'age' => 10],
    ['first_name' => 'Maya', 'last_name' => 'Garcia', 'grade' => '2', 'age' => 7]
];

$created = [];
foreach ($seed as $s) {
    $student_data = [
        'first_name' => sanitizeInput($s['first_name']),
        'last_name' => sanitizeInput($s['last_name']),
        'student_id' => uniqid('S'),
        'grade' => sanitizeInput($s['grade']),
        'age' => isset($s['age']) ? intval($s['age']) : null,
        'assigned_therapist' => $user_id,
        'date_of_birth' => null,
        'gender' => null,
        'primary_language' => 'English',
        'teacher' => null,
        'parent_contact' => null,
        'medical_info' => null,
        'iep_status' => 'none',
        'service_frequency' => null,
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s')
    ];

    // Use JSON storage for dev seeding
    $new_id = insertRecord('students', $student_data);

    if ($new_id) {
        $student_data['id'] = $new_id;
        $created[] = $student_data;
    }
}

echo json_encode(['created' => $created], JSON_PRETTY_PRINT);

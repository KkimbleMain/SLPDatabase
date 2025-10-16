<?php
// API endpoint for user registration (create new user)
// Accepts JSON body: { username, email, password, first_name, last_name, role }
// Responds with JSON { success: true, user: { id, username, email, first_name, last_name, role } }

@ini_set('display_errors', '0');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

require_once __DIR__ . '/../includes/sqlite.php';
require_once __DIR__ . '/../includes/auth.php';

try {
    $pdo = get_db();

    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (!is_array($data)) $data = [];

    $username = trim((string)($data['username'] ?? ''));
    $email    = trim((string)($data['email'] ?? ''));
    $password = (string)($data['password'] ?? '');
    $first    = trim((string)($data['first_name'] ?? ''));
    $last     = trim((string)($data['last_name'] ?? ''));
    $role     = trim((string)($data['role'] ?? 'therapist'));

    if ($username === '' || $email === '' || $password === '') {
        echo json_encode(['success' => false, 'message' => 'Username, email, and password are required']);
        exit;
    }

    // Inspect users table to use available columns (schema-aware)
    $pi = $pdo->prepare("PRAGMA table_info('users')");
    $pi->execute();
    $cols = array_column($pi->fetchAll(PDO::FETCH_ASSOC), 'name');

    // Check for uniqueness on username and email when columns exist
    if (in_array('username', $cols)) {
        $st = $pdo->prepare('SELECT COUNT(*) FROM users WHERE username = :u');
        $st->execute([':u' => $username]);
        if ((int)$st->fetchColumn() > 0) {
            echo json_encode(['success' => false, 'message' => 'Username already exists']);
            exit;
        }
    }
    if (in_array('email', $cols)) {
        $st = $pdo->prepare('SELECT COUNT(*) FROM users WHERE email = :e');
        $st->execute([':e' => $email]);
        if ((int)$st->fetchColumn() > 0) {
            echo json_encode(['success' => false, 'message' => 'Email already exists']);
            exit;
        }
    }

    // Build insert payload
    $now = date('c');
    $payload = [
        'username'   => $username,
        'email'      => $email,
        'first_name' => $first ?: null,
        'last_name'  => $last ?: null,
        'role'       => $role ?: 'therapist',
        'created_at' => $now
    ];

    // Prefer password_hash column; fallback to plaintext password column only if hash not present
    $hash = hashPassword($password);
    if (in_array('password_hash', $cols)) {
        $payload['password_hash'] = $hash;
    } elseif (in_array('password', $cols)) {
        $payload['password'] = $password; // legacy fallback
    }

    // Filter payload to existing columns
    $useCols = array_values(array_intersect(array_keys($payload), $cols));
    if (empty($useCols)) {
        echo json_encode(['success' => false, 'message' => 'Users table has no compatible columns']);
        exit;
    }

    $place = array_map(function($c){ return ':' . $c; }, $useCols);
    $sql = 'INSERT INTO users (' . implode(', ', $useCols) . ') VALUES (' . implode(', ', $place) . ')';
    $stmt = $pdo->prepare($sql);
    $params = [];
    foreach ($useCols as $c) $params[':' . $c] = $payload[$c];
    $stmt->execute($params);
    $id = (int)$pdo->lastInsertId();

    // Return sanitized user payload
    $user = [
        'id' => $id,
        'username' => $username,
        'email' => $email,
        'first_name' => $first ?: null,
        'last_name' => $last ?: null,
        'role' => $role ?: 'therapist'
    ];

    echo json_encode(['success' => true, 'user' => $user]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

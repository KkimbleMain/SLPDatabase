<?php
/**
 * Lightweight SQLite helper for local testing.
 * Provides minimal functions: sqlite_init(), sqlite_insert_student(), sqlite_find_record_by_field(),
 * sqlite_find_records(), sqlite_update_record(), sqlite_delete_record().
 */

function sqlite_get_path() {
    return __DIR__ . '/../database/slp.sqlite';
}

function sqlite_init() {
    $path = sqlite_get_path();
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $dbDidExist = file_exists($path);

    $pdo = new PDO('sqlite:' . $path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // If the DB was just created and a SQL schema file exists, import it.
    $schemaFile = __DIR__ . '/../database/sqllite.sql';
    if (!$dbDidExist && file_exists($schemaFile) && filesize($schemaFile) > 0) {
        $sql = file_get_contents($schemaFile);
        if ($sql !== false) {
            // Execute the SQL file; it may contain multiple statements
            // PDO::exec can execute multiple statements for SQLite.
            $pdo->exec($sql);
            return $pdo;
        }
    }

    // Fallback: create tables programmatically if schema file wasn't used
    $pdo->exec("CREATE TABLE IF NOT EXISTS students (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        first_name TEXT,
        last_name TEXT,
        student_id TEXT UNIQUE,
        age INTEGER,
        grade TEXT,
        assigned_therapist INTEGER,
        date_of_birth TEXT,
        gender TEXT,
        primary_language TEXT,
        teacher TEXT,
        parent_contact TEXT,
        medical_info TEXT,
        iep_status TEXT,
        service_frequency TEXT,
        created_at TEXT,
        updated_at TEXT
    );");

    // Create goals and progress tables minimally (optional)
    $pdo->exec("CREATE TABLE IF NOT EXISTS goals (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        student_id INTEGER,
        therapist_id INTEGER,
        goal_area TEXT,
        goal_text TEXT,
        baseline_score INTEGER,
        target_score INTEGER,
        status TEXT,
        created_at TEXT,
        updated_at TEXT
    );");

    $pdo->exec("CREATE TABLE IF NOT EXISTS progress_updates (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        student_id INTEGER,
        goal_id INTEGER,
        date_recorded TEXT,
        score INTEGER,
        notes TEXT,
        created_at TEXT
    );");

    // Create users table for authentication
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT UNIQUE,
        password TEXT,
        first_name TEXT,
        last_name TEXT,
        role TEXT,
        created_at TEXT
    );");

    return $pdo;
}

function sqlite_get_pdo() {
    static $pdo = null;
    if ($pdo === null) {
        $path = sqlite_get_path();
        $pdo = new PDO('sqlite:' . $path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }
    return $pdo;
}

function sqlite_insert_student($student_data) {
    $pdo = sqlite_get_pdo();
    $stmt = $pdo->prepare("INSERT INTO students (first_name, last_name, student_id, age, grade, assigned_therapist, date_of_birth, gender, primary_language, teacher, parent_contact, medical_info, iep_status, service_frequency, created_at, updated_at) VALUES (:first_name, :last_name, :student_id, :age, :grade, :assigned_therapist, :date_of_birth, :gender, :primary_language, :teacher, :parent_contact, :medical_info, :iep_status, :service_frequency, :created_at, :updated_at)");
    $stmt->execute([
        ':first_name' => $student_data['first_name'] ?? null,
        ':last_name' => $student_data['last_name'] ?? null,
        ':student_id' => $student_data['student_id'] ?? null,
        ':age' => isset($student_data['age']) ? intval($student_data['age']) : null,
        ':grade' => $student_data['grade'] ?? null,
        ':assigned_therapist' => $student_data['assigned_therapist'] ?? null,
        ':date_of_birth' => $student_data['date_of_birth'] ?? null,
        ':gender' => $student_data['gender'] ?? null,
        ':primary_language' => $student_data['primary_language'] ?? null,
        ':teacher' => $student_data['teacher'] ?? null,
        ':parent_contact' => $student_data['parent_contact'] ?? null,
        ':medical_info' => $student_data['medical_info'] ?? null,
        ':iep_status' => $student_data['iep_status'] ?? null,
        ':service_frequency' => $student_data['service_frequency'] ?? null,
        ':created_at' => $student_data['created_at'] ?? date('Y-m-d H:i:s'),
        ':updated_at' => $student_data['updated_at'] ?? date('Y-m-d H:i:s')
    ]);
    return (int)$pdo->lastInsertId();
}

function sqlite_find_record_by_field($table, $field, $value) {
    $pdo = sqlite_get_pdo();
    $stmt = $pdo->prepare("SELECT * FROM " . $table . " WHERE " . $field . " = :val LIMIT 1");
    $stmt->execute([':val' => $value]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function sqlite_find_records($table, $conditions = []) {
    $pdo = sqlite_get_pdo();
    if (empty($conditions)) {
        $stmt = $pdo->query("SELECT * FROM " . $table);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    $where = [];
    $params = [];
    foreach ($conditions as $k => $v) {
        $where[] = "$k = :$k";
        $params[":$k"] = $v;
    }
    $sql = "SELECT * FROM " . $table . " WHERE " . implode(' AND ', $where);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function sqlite_update_record($table, $id, $updates) {
    $pdo = sqlite_get_pdo();
    $sets = [];
    $params = [':id' => $id];
    foreach ($updates as $k => $v) {
        $sets[] = "$k = :$k";
        $params[":$k"] = $v;
    }
    $sql = "UPDATE " . $table . " SET " . implode(', ', $sets) . " WHERE id = :id";
    $stmt = $pdo->prepare($sql);
    return $stmt->execute($params);
}

function sqlite_delete_record($table, $id) {
    $pdo = sqlite_get_pdo();
    $stmt = $pdo->prepare("DELETE FROM " . $table . " WHERE id = :id");
    return $stmt->execute([':id' => $id]);
}

// User helpers
function sqlite_insert_user($user) {
    $pdo = sqlite_get_pdo();
    $stmt = $pdo->prepare("INSERT INTO users (username, password, first_name, last_name, role, created_at) VALUES (:username, :password, :first_name, :last_name, :role, :created_at)");
    $stmt->execute([
        ':username' => $user['username'],
        ':password' => $user['password'],
        ':first_name' => $user['first_name'] ?? null,
        ':last_name' => $user['last_name'] ?? null,
        ':role' => $user['role'] ?? 'therapist',
        ':created_at' => $user['created_at'] ?? date('Y-m-d H:i:s')
    ]);
    return (int)$pdo->lastInsertId();
}

function sqlite_find_user_by_username($username) {
    return sqlite_find_record_by_field('users', 'username', $username);
}


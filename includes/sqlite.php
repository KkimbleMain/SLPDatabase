<?php
/**
 * Lightweight SQLite helper for local testing.
 * Provides minimal functions: sqlite_init(), get_db(), sqlite_get_pdo(), sqlite_get_path().
 */

function sqlite_get_path() {
    // use slp.db as the canonical DB filename
    return __DIR__ . '/../database/slp.db';
}

function sqlite_init() {
    $path = sqlite_get_path();
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $dbDidExist = file_exists($path);

    // ensure PDO and pdo_sqlite are available before attempting to connect
    if (!class_exists('PDO') || !extension_loaded('pdo_sqlite')) {
        throw new Exception("SQLite PDO driver not available. Enable the 'pdo_sqlite' extension in your php.ini and restart your webserver (Windows: uncomment extension=\"pdo_sqlite\" or enable via php.ini).\nIf you're running PHP CLI, ensure the same php.ini is used.");
    }
    try {
        $pdo = new PDO('sqlite:' . $path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        // reduce contention waits: set a reasonable busy timeout (milliseconds)
        try {
            $pdo->exec('PRAGMA busy_timeout = 3000'); // 3 seconds
        } catch (\Throwable $e) {
            // non-fatal if PRAGMA not supported
        }
    } catch (PDOException $e) {
        throw new Exception('Failed to open SQLite database: ' . $e->getMessage());
    }

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
        user_id INTEGER,
        created_at TEXT,
        updated_at TEXT,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
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
        user_id INTEGER,
        created_at TEXT,
        updated_at TEXT,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
    );");

    // Progress tracking tables
    $pdo->exec("CREATE TABLE IF NOT EXISTS progress_skills (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        student_id INTEGER NOT NULL,
        skill_label TEXT NOT NULL,
        category TEXT,
        subcategory TEXT,
        created_by INTEGER,
        user_id INTEGER,
        created_at TEXT,
        updated_at TEXT,
        FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
    );");

    $pdo->exec("CREATE TABLE IF NOT EXISTS progress_updates (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        skill_id INTEGER NOT NULL,
        student_id INTEGER NOT NULL,
        score INTEGER NOT NULL,
        target_score INTEGER,
        notes TEXT,
        recorded_by INTEGER,
        user_id INTEGER,
        date_recorded TEXT,
        created_at TEXT,
        FOREIGN KEY (skill_id) REFERENCES progress_skills(id) ON DELETE CASCADE,
        FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
    );");

    // Create users table for authentication (prefer password_hash + email when possible)
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT UNIQUE,
        password_hash TEXT,
        email TEXT,
        password TEXT,
        first_name TEXT,
        last_name TEXT,
        role TEXT,
        created_at TEXT
    );");

    $pdo->exec("CREATE TABLE IF NOT EXISTS password_resets (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER,
        username TEXT,
        token TEXT,
        expires_at TEXT,
        created_at TEXT
    );");

    // Legacy `documents` table removed from runtime creation. Create a new `other_documents`
    // table to capture any form types that don't map into normalized per-form tables.
    $pdo->exec("CREATE TABLE IF NOT EXISTS other_documents (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        student_id INTEGER NOT NULL,
        title TEXT,
        form_type TEXT,
        therapist_id INTEGER,
        metadata TEXT,
        content TEXT,
        user_id INTEGER,
        created_at TEXT,
        FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
    );");

    // Per-student progress report metadata (single overwriteable report per student)
    $pdo->exec("CREATE TABLE IF NOT EXISTS student_reports (
        student_id INTEGER PRIMARY KEY,
        path TEXT,
        created_at TEXT,
        updated_at TEXT,
        created_by INTEGER,
        status TEXT,
        user_id INTEGER
    );");

    // Create per-form normalized tables
    $pdo->exec("CREATE TABLE IF NOT EXISTS initial_evaluations (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        student_id INTEGER NOT NULL,
        title TEXT,
        therapist_id INTEGER,
        metadata TEXT,
        user_id INTEGER,
        created_at TEXT,
        FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
    );");

    $pdo->exec("CREATE TABLE IF NOT EXISTS session_reports (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        student_id INTEGER NOT NULL,
        session_date TEXT,
        duration_minutes INTEGER,
        session_type TEXT,
        title TEXT,
        therapist_id INTEGER,
        metadata TEXT,
        user_id INTEGER,
        created_at TEXT,
        FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
    );");

    $pdo->exec("CREATE TABLE IF NOT EXISTS discharge_reports (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        student_id INTEGER NOT NULL,
        title TEXT,
        therapist_id INTEGER,
        metadata TEXT,
        user_id INTEGER,
        created_at TEXT,
        FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
    );");

    // Ensure progress_reports exists with an autoincrement id PK for tracking report IDs
    $pdo->exec("CREATE TABLE IF NOT EXISTS progress_reports (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        student_id INTEGER,
        title TEXT,
        user_id INTEGER,
        created_at TEXT,
        FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
    );");

    // activity_log table intentionally not created by default; dashboard uses synthesized activity

    return $pdo;
}

// returns a shared PDO instance for sqlite
function get_db() {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    $dbFile = sqlite_get_path();
    // If DB doesn't exist yet, initialize it (creates file, tables, and sets busy_timeout)
    if (!file_exists($dbFile)) {
        try {
            $pdo = sqlite_init();
        } catch (Throwable $e) {
            throw new Exception("Database initialization failed: " . $e->getMessage());
        }
    } else {
        if (!class_exists('PDO') || !extension_loaded('pdo_sqlite')) {
            throw new Exception("SQLite PDO driver not available. Enable the 'pdo_sqlite' extension in your php.ini and restart your webserver.");
        }
        try {
            $dsn = 'sqlite:' . $dbFile;
            $pdo = new PDO($dsn);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch (PDOException $e) {
            throw new Exception('Failed to open SQLite database: ' . $e->getMessage());
        }
        // Apply a reasonable busy timeout for consistency with sqlite_init
        try { $pdo->exec('PRAGMA busy_timeout = 3000'); } catch (Throwable $_e) {}
    }
    // ensure foreign keys
    try { $pdo->exec('PRAGMA foreign_keys = ON'); } catch (Throwable $_e) {}

    // Best-effort schema migrations for users table to ensure email/password_hash exist
    try {
        $pi = $pdo->prepare("PRAGMA table_info('users')");
        $pi->execute();
        $cols = array_column($pi->fetchAll(PDO::FETCH_ASSOC), 'name');
        if (!in_array('email', $cols)) {
            try { $pdo->exec("ALTER TABLE users ADD COLUMN email TEXT"); } catch (Throwable $_e) { /* ignore */ }
        }
        if (!in_array('password_hash', $cols)) {
            try { $pdo->exec("ALTER TABLE users ADD COLUMN password_hash TEXT"); } catch (Throwable $_e) { /* ignore */ }
        }
    } catch (Throwable $e) { /* ignore */ }

    // Online migrations: ensure user_id column exists across key tables
    $tablesToAddUser = [
        'students','goals','progress_skills','progress_updates','other_documents',
        'student_reports','initial_evaluations','session_reports','discharge_reports','progress_reports'
    ];
    foreach ($tablesToAddUser as $tbl) {
        try {
            $pi = $pdo->prepare("PRAGMA table_info('".$tbl."')");
            $pi->execute();
            $cols = array_column($pi->fetchAll(PDO::FETCH_ASSOC), 'name');
            if (!in_array('user_id', $cols)) {
                try { $pdo->exec("ALTER TABLE " . $tbl . " ADD COLUMN user_id INTEGER"); } catch (Throwable $_e) { /* ignore */ }
            }
        } catch (Throwable $_e) { /* ignore */ }
    }
    return $pdo;
}

// Backwards-compatibility: delegate to get_db() so callers using sqlite_get_pdo still work
function sqlite_get_pdo() {
    return get_db();
}

// Removed unused helper sqlite_find_user_by_username() to reduce dead code.


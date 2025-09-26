<?php
// One-shot migration: import existing JSON + per-student folders into database/slp.db
// Run from project root: php .\dev\migrate_json_to_sql.php

$projectRoot = realpath(__DIR__ . '/..');
$dbFile = $projectRoot . '/database/slp.db';
$schemaFile = $projectRoot . '/database/sqllite.sql';
// optional data dir override: php migrate_json_to_sql.php /path/to/data
$dataDir = $argv[1] ?? ($projectRoot . '/database/data');

function dief($msg) { fwrite(STDERR, $msg . PHP_EOL); exit(1); }

try {
    if (!file_exists($schemaFile)) dief("Schema missing: $schemaFile");

    // create DB and apply schema if not present
    $createDb = false;
    if (!file_exists($dbFile)) $createDb = true;
    $pdo = new PDO('sqlite:' . $dbFile);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    if ($createDb) {
        $sql = file_get_contents($schemaFile);
        if ($sql === false) dief("Unable to read schema");
        $pdo->exec($sql);
        echo "Created DB and applied schema: $dbFile\n";
    } else {
        echo "Using existing DB: $dbFile\n";
    }
    $pdo->exec('PRAGMA foreign_keys = ON');

    // helper to load array json
    $loadJson = function($name) use ($dataDir) {
        $path = rtrim($dataDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $name;
        if (!file_exists($path)) return [];
        $s = file_get_contents($path);
        $arr = json_decode($s, true);
        return is_array($arr) ? $arr : [];
    };

    $usersJson = $loadJson('users.json');
    $studentsJson = $loadJson('students.json');
    $documentsJson = $loadJson('documents.json');
    $goalsJson = $loadJson('goals.json');
    $progressJson = $loadJson('progress_updates.json');
    $reportsJson = $loadJson('reports.json');

    // If students.json empty, scan per-student folders
    if (empty($studentsJson)) {
        $studentsJson = [];
        $studentsFolder = $dataDir . '/students';
        if (is_dir($studentsFolder)) {
            foreach (glob($studentsFolder . '/student_*', GLOB_ONLYDIR) as $dir) {
                // find an initial_profile_*.json
                $files = glob($dir . '/initial_profile_*.json');
                if (empty($files)) continue;
                $content = @file_get_contents($files[0]);
                $obj = @json_decode($content, true);
                if (!is_array($obj)) continue;
                // derive id from folder name if present
                $id = null;
                if (preg_match('/student_(\d+)/', basename($dir), $m)) $id = (int)$m[1];
                $studentsJson[] = array_merge(['id' => $id], $obj);
            }
        }
    }

    $counts = ['users'=>0,'students'=>0,'documents'=>0,'goals'=>0,'progress'=>0,'reports'=>0];

    $pdo->beginTransaction();

    // users
    if ($usersJson) {
        $stmt = $pdo->prepare('INSERT OR REPLACE INTO users (id, username, password_hash, role, first_name, last_name, email, created_at) VALUES (:id, :username, :password_hash, :role, :first_name, :last_name, :email, :created_at)');
        foreach ($usersJson as $u) {
            $stmt->execute([
                ':id' => $u['id'] ?? null,
                ':username' => $u['username'] ?? null,
                ':password_hash' => $u['password_hash'] ?? ($u['password'] ?? null),
                ':role' => $u['role'] ?? 'user',
                ':first_name' => $u['first_name'] ?? null,
                ':last_name' => $u['last_name'] ?? null,
                ':email' => $u['email'] ?? null,
                ':created_at' => $u['created_at'] ?? null,
            ]);
            $counts['users']++;
        }
    }

    // students
    if ($studentsJson) {
        $stmt = $pdo->prepare('INSERT OR REPLACE INTO students (id, student_id, first_name, last_name, grade, date_of_birth, primary_language, service_frequency, assigned_therapist, archived, created_at) VALUES (:id, :student_id, :first_name, :last_name, :grade, :date_of_birth, :primary_language, :service_frequency, :assigned_therapist, :archived, :created_at)');
        foreach ($studentsJson as $s) {
            $stmt->execute([
                ':id' => $s['id'] ?? null,
                ':student_id' => $s['student_id'] ?? null,
                ':first_name' => $s['first_name'] ?? ($s['fname'] ?? null),
                ':last_name' => $s['last_name'] ?? ($s['lname'] ?? null),
                ':grade' => $s['grade'] ?? null,
                ':date_of_birth' => $s['date_of_birth'] ?? null,
                ':primary_language' => $s['primary_language'] ?? null,
                ':service_frequency' => $s['service_frequency'] ?? null,
                ':assigned_therapist' => $s['assigned_therapist'] ?? null,
                ':archived' => (!empty($s['archived']) && $s['archived'] != '0') ? 1 : 0,
                ':created_at' => $s['created_at'] ?? null,
            ]);
            $counts['students']++;
        }
    }

    // documents (documents.json and per-student files)
    if ($documentsJson) {
        $stmt = $pdo->prepare('INSERT OR REPLACE INTO documents (id, student_id, title, filename, metadata, created_at) VALUES (:id, :student_id, :title, :filename, :metadata, :created_at)');
        foreach ($documentsJson as $d) {
            $meta = isset($d['metadata']) ? json_encode($d['metadata']) : null;
            $stmt->execute([
                ':id' => $d['id'] ?? null,
                ':student_id' => $d['student_id'] ?? ($d['sid'] ?? null),
                ':title' => $d['title'] ?? $d['name'] ?? null,
                ':filename' => $d['filename'] ?? $d['file'] ?? null,
                ':metadata' => $meta,
                ':created_at' => $d['created_at'] ?? null,
            ]);
            $counts['documents']++;
        }
    }

    // scan per-student folders for files (store filenames as documents entries)
    $studentsFolder = rtrim($dataDir, DIRECTORY_SEPARATOR) . '/students';
    if (is_dir($studentsFolder)) {
        foreach (glob($studentsFolder . '/student_*', GLOB_ONLYDIR) as $dir) {
            if (!preg_match('/student_(\d+)/', basename($dir), $m)) continue;
            $sid = (int)$m[1];
            foreach (glob($dir . '/*') as $f) {
                $bn = basename($f);
                // skip initial_profile and goal/profile json already imported
                if (preg_match('/initial_profile_/', $bn)) continue;
                if (preg_match('/goals_form_/', $bn) || preg_match('/progress_/', $bn) || preg_match('/report_/', $bn)) {
                    // treat as document entry
                    $stmt = $pdo->prepare('INSERT INTO documents (student_id, title, filename, metadata, created_at) VALUES (:student_id, :title, :filename, :metadata, :created_at)');
                    $relative = str_replace('\\', '/', substr($f, strlen(realpath($projectRoot)) + 1));
                    // attempt to read file to heuristically determine form_type
                    $ft = null; $ther = null;
                    $content = @file_get_contents($f);
                    if ($content) {
                        $j = @json_decode($content, true);
                        if (is_array($j)) {
                            if (isset($j['form_type'])) $ft = $j['form_type'];
                            if (isset($j['therapist_id'])) $ther = $j['therapist_id'];
                        }
                    }
                    $stmt->execute([
                        ':student_id' => $sid,
                        ':title' => $bn,
                        ':filename' => $relative,
                        ':form_type' => $ft,
                        ':therapist_id' => $ther,
                        ':metadata' => null,
                        ':created_at' => date('c', filemtime($f)),
                    ]);
                    $counts['documents']++;
                }
            }
        }
    }

    // goals
    if ($goalsJson) {
        $stmt = $pdo->prepare('INSERT OR REPLACE INTO goals (id, student_id, title, description, status, created_at) VALUES (:id, :student_id, :title, :description, :status, :created_at)');
        foreach ($goalsJson as $g) {
            $stmt->execute([
                ':id' => $g['id'] ?? null,
                ':student_id' => $g['student_id'] ?? ($g['sid'] ?? null),
                ':title' => $g['title'] ?? null,
                ':description' => $g['description'] ?? ($g['desc'] ?? null),
                ':status' => $g['status'] ?? null,
                ':created_at' => $g['created_at'] ?? null,
            ]);
            $counts['goals']++;
        }
    }

    // progress updates
    if ($progressJson) {
        $stmt = $pdo->prepare('INSERT OR REPLACE INTO progress_updates (id, student_id, note, created_at) VALUES (:id, :student_id, :note, :created_at)');
        foreach ($progressJson as $p) {
            $stmt->execute([
                ':id' => $p['id'] ?? null,
                ':student_id' => $p['student_id'] ?? ($p['sid'] ?? null),
                ':note' => $p['note'] ?? $p['text'] ?? null,
                ':created_at' => $p['created_at'] ?? null,
            ]);
            $counts['progress']++;
        }
    }

    // reports
    if ($reportsJson) {
        $stmt = $pdo->prepare('INSERT OR REPLACE INTO reports (id, student_id, title, content, created_at) VALUES (:id, :student_id, :title, :content, :created_at)');
        foreach ($reportsJson as $r) {
            $stmt->execute([
                ':id' => $r['id'] ?? null,
                ':student_id' => $r['student_id'] ?? ($r['sid'] ?? null),
                ':title' => $r['title'] ?? null,
                ':content' => $r['content'] ?? ($r['body'] ?? null),
                ':created_at' => $r['created_at'] ?? null,
            ]);
            $counts['reports']++;
        }
    }

    $pdo->commit();

    echo "Migration complete:\n";
    foreach ($counts as $k => $v) echo "  $k: $v\n";

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    dief("Migration failed: " . $e->getMessage());
}
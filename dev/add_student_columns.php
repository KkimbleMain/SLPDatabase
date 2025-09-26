<?php
// Adds missing student columns to the SQLite DB used by the app. Safe: checks existence before ALTER.
require_once __DIR__ . '/../includes/sqlite.php';
$needed = [
    'age' => 'INTEGER',
    'gender' => "TEXT",
    'teacher' => "TEXT",
    'parent_contact' => "TEXT",
    'medical_info' => "TEXT",
    'iep_status' => "TEXT",
    'updated_at' => "TEXT"
];
try {
    $pdo = sqlite_get_pdo();
    $stmt = $pdo->query("PRAGMA table_info('students')");
    $cols = $stmt->fetchAll(PDO::FETCH_COLUMN, 1); // names
    $added = [];
    foreach ($needed as $col => $type) {
        if (!in_array($col, $cols)) {
            $sql = "ALTER TABLE students ADD COLUMN $col $type";
            $pdo->exec($sql);
            $added[] = "$col $type";
        }
    }
    if (count($added) === 0) {
        echo "No columns needed. DB schema is up to date.\n";
    } else {
        echo "Added columns: " . implode(', ', $added) . "\n";
    }
    // show final schema
    $stmt = $pdo->query("PRAGMA table_info('students')");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        echo $r['cid'] . ' ' . $r['name'] . ' ' . $r['type'] . PHP_EOL;
    }
} catch (Exception $e) {
    echo 'ERR: ' . $e->getMessage() . PHP_EOL;
    exit(1);
}

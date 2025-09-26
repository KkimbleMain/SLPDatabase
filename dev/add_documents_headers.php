<?php
$projectRoot = realpath(__DIR__ . '/..');
$dbFile = $projectRoot . '/database/slp.db';
if (!file_exists($dbFile)) { echo "DB not found: $dbFile\n"; exit(1); }
$pdo = new PDO('sqlite:' . $dbFile);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$desired = [
    'form_type' => "TEXT",
    'therapist_id' => "INTEGER",
];

$existing = [];
$stmt = $pdo->query("PRAGMA table_info('documents')");
$cols = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($cols as $c) $existing[$c['name']] = $c;

$added = [];
foreach ($desired as $col => $type) {
    if (!isset($existing[$col])) {
        echo "Adding column: $col $type\n";
        $pdo->exec("ALTER TABLE documents ADD COLUMN $col $type");
        $added[] = $col;
    } else {
        echo "Column exists: $col\n";
    }
}

if (empty($added)) {
    echo "No changes required.\n";
} else {
    echo "Added columns: " . implode(', ', $added) . "\n";
}

exit(0);

<?php
$path = __DIR__ . '/../database/slp.db';
if (!file_exists($path)) { echo "DB not found: $path\n"; exit(1); }
try {
    $pdo = new PDO('sqlite:' . $path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $stmt = $pdo->query("PRAGMA table_info(documents)");
    $cols = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $names = array_column($cols, 'name');
    if (in_array('content', $names)) {
        echo "Column 'content' already exists on documents table.\n";
        exit(0);
    }

    echo "Adding 'content' column to documents table...\n";
    $pdo->exec("ALTER TABLE documents ADD COLUMN content TEXT;");
    echo "Done.\n";
} catch (Exception $e) {
    echo 'Error: ' . $e->getMessage() . "\n";
    exit(1);
}

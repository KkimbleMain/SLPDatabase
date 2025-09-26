<?php
// Drop legacy 'documents' table from the SQLite DB (no backup, per user request)
require __DIR__ . '/../includes/sqlite.php';
$pdo = sqlite_init();
try {
    $r = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='documents'")->fetchColumn();
    if (!$r) {
        echo "No 'documents' table found. Nothing to do.\n";
        exit(0);
    }
    $pdo->exec('DROP TABLE documents');
    echo "Dropped table 'documents'.\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}

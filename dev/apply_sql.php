<?php
// Simple runner: reads database/sqllite.sql and executes against database/slp.db
$root = __DIR__ . '\\..';
$sqlFile = $root . '\\database\\sqllite.sql';
$dbFile  = $root . '\\database\\slp.db';

if (!file_exists($sqlFile)) {
    echo "SQL file not found: $sqlFile\n";
    exit(1);
}

$sql = file_get_contents($sqlFile);
if ($sql === false) {
    echo "Failed to read SQL file\n";
    exit(1);
}

try {
    $pdo = new PDO('sqlite:' . $dbFile);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA foreign_keys = ON;');
    $pdo->exec($sql);
    echo "SQL applied\n";
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    // optionally print SQLite error info
    if (isset($pdo)) {
        print_r($pdo->errorInfo());
    }
    exit(1);
}
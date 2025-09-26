<?php
$path = __DIR__ . '/../database/slp.db';
if (!file_exists($path)) { echo "DB not found: $path\n"; exit(1); }
try {
    $pdo = new PDO('sqlite:' . $path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $stmt = $pdo->query("PRAGMA table_info(documents)");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        echo $r['cid'] . ' ' . $r['name'] . ' ' . $r['type'] . "\n";
    }
} catch (Exception $e) {
    echo 'Error: ' . $e->getMessage() . "\n";
}

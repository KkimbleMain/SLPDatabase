<?php
require_once __DIR__ . '/../includes/sqlite.php';
try {
    $pdo = sqlite_get_pdo();
    $stmt = $pdo->query("PRAGMA table_info('students')");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        echo $r['cid'] . ' ' . $r['name'] . ' ' . $r['type'] . PHP_EOL;
    }
} catch (Exception $e) {
    echo 'ERR: ' . $e->getMessage() . PHP_EOL;
}

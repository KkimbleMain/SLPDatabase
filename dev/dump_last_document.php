<?php
require __DIR__ . '/../includes/sqlite.php';
$pdo = sqlite_get_pdo();
$r = $pdo->query('SELECT * FROM documents ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
echo json_encode($r, JSON_PRETTY_PRINT) . "\n";

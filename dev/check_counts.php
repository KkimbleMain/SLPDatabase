<?php
$require_path = __DIR__ . '/../includes/sqlite.php';
require $require_path;
$pdo = sqlite_init();
$tables = ['initial_evaluations','session_reports','discharge_reports','documents'];
foreach ($tables as $t) {
    $c = $pdo->query('SELECT COUNT(*) FROM ' . $t)->fetchColumn();
    echo $t . ': ' . $c . "\n";
}

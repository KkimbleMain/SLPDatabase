<?php
ini_set('display_errors', '1');
error_reporting(E_ALL);
echo "CLI counts verbose starting...\n";
try {
    require __DIR__ . '/../includes/sqlite.php';
    $pdo = sqlite_init();
    $tables = ['initial_evaluations','session_reports','discharge_reports','documents'];
    foreach ($tables as $t) {
        try {
            $c = $pdo->query('SELECT COUNT(*) FROM ' . $t)->fetchColumn();
            echo "{$t}: {$c}\n";
        } catch (Exception $e) {
            echo "{$t}: ERROR: " . $e->getMessage() . "\n";
        }
    }
    echo "Done.\n";
} catch (Exception $e) {
    echo "Top-level error: " . $e->getMessage() . "\n";
}

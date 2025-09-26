<?php
require __DIR__ . '/../includes/sqlite.php';
$pdo = sqlite_init();
function counts($pdo) {
    $tables = ['initial_evaluations','session_reports','discharge_reports','documents'];
    $out = [];
    foreach ($tables as $t) {
        try { $c = $pdo->query('SELECT COUNT(*) FROM ' . $t)->fetchColumn(); } catch (Exception $e) { $c = 'ERR'; }
        $out[$t] = $c;
    }
    return $out;
}

echo "Counts BEFORE direct inserts:\n";
print_r(counts($pdo));

// Direct inserts
$now = date('c');
$stmt = $pdo->prepare('INSERT INTO initial_evaluations (student_id,title,therapist_id,metadata,content,created_at) VALUES (?,?,?,?,?,?)');
$stmt->execute([1,'Direct Initial Test',1,json_encode(['form_type'=>'initial_evaluation']), json_encode(['test'=>true]), $now]);

$stmt = $pdo->prepare('INSERT INTO session_reports (student_id, session_date, duration_minutes, session_type, title, therapist_id, metadata, content, created_at) VALUES (?,?,?,?,?,?,?,?,?)');
$stmt->execute([1,'2025-09-06',30,'individual','Direct Session Test',1,json_encode(['form_type'=>'session_report']), json_encode(['test'=>true]), $now]);

$stmt = $pdo->prepare('INSERT INTO discharge_reports (student_id,title,therapist_id,metadata,content,created_at) VALUES (?,?,?,?,?,?)');
$stmt->execute([1,'Direct Discharge Test',1,json_encode(['form_type'=>'discharge_report']), json_encode(['test'=>true]), $now]);

echo "Counts AFTER direct inserts:\n";
print_r(counts($pdo));

echo "\nNow run submit.php via isolated CLI scripts (these will attempt to save via submit.php)\n";

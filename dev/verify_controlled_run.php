<?php
// Controlled verification: init DB, record counts before/after direct inserts, write results to a JSON file
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

$results = [];
$results['before'] = counts($pdo);

// Insert sample rows
$now = date('c');
try {
    $stmt = $pdo->prepare('INSERT INTO initial_evaluations (student_id,title,therapist_id,metadata,content,created_at) VALUES (?,?,?,?,?,?)');
    $stmt->execute([1,'Direct Initial Test',1,json_encode(['form_type'=>'initial_evaluation']), json_encode(['test'=>true]), $now]);
    $results['insert_initial_id'] = $pdo->lastInsertId();
} catch (Exception $e) { $results['initial_error'] = $e->getMessage(); }

try {
    $stmt = $pdo->prepare('INSERT INTO session_reports (student_id, session_date, duration_minutes, session_type, title, therapist_id, metadata, content, created_at) VALUES (?,?,?,?,?,?,?,?,?)');
    $stmt->execute([1,'2025-09-06',30,'individual','Direct Session Test',1,json_encode(['form_type'=>'session_report']), json_encode(['test'=>true]), $now]);
    $results['insert_session_id'] = $pdo->lastInsertId();
} catch (Exception $e) { $results['session_error'] = $e->getMessage(); }

try {
    $stmt = $pdo->prepare('INSERT INTO discharge_reports (student_id,title,therapist_id,metadata,content,created_at) VALUES (?,?,?,?,?,?)');
    $stmt->execute([1,'Direct Discharge Test',1,json_encode(['form_type'=>'discharge_report']), json_encode(['test'=>true]), $now]);
    $results['insert_discharge_id'] = $pdo->lastInsertId();
} catch (Exception $e) { $results['discharge_error'] = $e->getMessage(); }

$results['after'] = counts($pdo);

// fetch last rows for each table as sample
$sample = [];
foreach (['initial_evaluations','session_reports','discharge_reports','documents'] as $t) {
    try {
        $r = $pdo->query('SELECT * FROM ' . $t . ' ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        $sample[$t] = $r ?: null;
    } catch (Exception $e) { $sample[$t] = null; }
}
$results['sample'] = $sample;

file_put_contents(__DIR__ . '/verify_results.json', json_encode($results, JSON_PRETTY_PRINT));
echo "Wrote dev/verify_results.json\n";

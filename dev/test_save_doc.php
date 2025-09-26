<?php
require_once __DIR__ . '/../includes/sqlite.php';
try {
    $pdo = get_db();
    // Create a test student
    $pdo->beginTransaction();
    $stmt = $pdo->prepare('INSERT INTO students (first_name, last_name, created_at) VALUES (:fn, :ln, :c)');
    $stmt->execute([':fn'=>'TS',':ln'=>'TestStudent',':c'=>date('c')]);
    $sid = (int)$pdo->lastInsertId();

    $doc = [
        'id' => time(),
        'student_id' => $sid,
        'form_type' => 'unit_test',
        'form_data' => ['foo'=>'bar'],
        'title' => 'Unit Test Document',
        'therapist_id' => null,
        'created_at' => date('c')
    ];

    $stmt = $pdo->prepare('INSERT INTO documents (student_id, title, filename, form_type, therapist_id, metadata, content, created_at) VALUES (:student_id, :title, :filename, :form_type, :therapist_id, :metadata, :content, :created_at)');
    $stmt->execute([
        ':student_id' => $sid,
        ':title' => $doc['title'],
        ':filename' => null,
        ':form_type' => $doc['form_type'],
        ':therapist_id' => $doc['therapist_id'],
        ':metadata' => json_encode(['form_type'=>$doc['form_type']]),
        ':content' => json_encode($doc),
        ':created_at' => date('c'),
    ]);
    $did = (int)$pdo->lastInsertId();
    $pdo->commit();

    $row = $pdo->query('SELECT * FROM documents WHERE id = ' . $did)->fetch(PDO::FETCH_ASSOC);
    echo "Inserted document id={$did}\n";
    echo json_encode($row, JSON_PRETTY_PRINT) . "\n";
    exit(0);
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}

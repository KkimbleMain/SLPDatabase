<?php
// ensure output buffering before any session_start in included files
ob_start();
// One-off test: POST three different form types to includes/submit.php and then call get_student_forms
$base = __DIR__ . "/..";
$submit = $base . '/includes/submit.php';

function post($data) {
    $url = 'http://localhost/'; // not used
    // we will invoke submit.php directly by setting $_POST and including it
    foreach ($data as $k => $v) { $_POST[$k] = $v; }
    // minimal session user id
    if (!session_id()) session_start();
    $_SESSION['user_id'] = 1;
    // simulate a POST request when including the submit handler
    $_SERVER['REQUEST_METHOD'] = 'POST';
    ob_start();
    require __DIR__ . '/../includes/submit.php';
    $out = ob_get_clean();
    // cleanup
    foreach ($data as $k => $v) { unset($_POST[$k]); }
    return $out;
}

// create a dummy student if needed
$require_path = __DIR__ . '/../includes/sqlite.php';
require $require_path;
$pdo = sqlite_init();
$stmt = $pdo->prepare('SELECT id FROM students LIMIT 1');
$stmt->execute();
$sid = $stmt->fetchColumn();
if (!$sid) {
    // Use helper to insert a student with compatible columns
    $sid = sqlite_insert_student(['first_name'=>'Test','last_name'=>'Student','created_at'=>date('c')]);
}

echo "Using student id={$sid}\n";

// initial evaluation
$data = ['action'=>'save_document','student_id'=>$sid,'form_type'=>'initial_evaluation','form_data'=>json_encode(['initial_info'=>'yes']),'title'=>'Initial Eval Test'];
echo "Submitting initial_evaluation...\n";
echo post($data) . "\n";

// session report
$data = ['action'=>'save_document','student_id'=>$sid,'form_type'=>'session_report','form_data'=>json_encode(['sessionDate'=>'2025-09-06','sessionDuration'=>30,'sessionType'=>'individual']),'title'=>'Session Test'];
echo "Submitting session_report...\n";
echo post($data) . "\n";

// discharge report
$data = ['action'=>'save_document','student_id'=>$sid,'form_type'=>'discharge_report','form_data'=>json_encode(['summary'=>'done']),'title'=>'Discharge Test'];
echo "Submitting discharge_report...\n";
echo post($data) . "\n";

// now get student forms
$_POST['action'] = 'get_student_forms';
$_POST['student_id'] = $sid;
$_SERVER['REQUEST_METHOD'] = 'POST';
ob_start();
require __DIR__ . '/../includes/submit.php';
$out = ob_get_clean();
echo "get_student_forms output:\n" . $out . "\n";

// cleanup POST
unset($_POST['action'], $_POST['student_id']);

// done

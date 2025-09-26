<?php
// Lightweight submit endpoint migrated to SQLite (uses includes/sqlite.php)

if (session_status() === PHP_SESSION_NONE) session_start();
@ini_set('display_errors', '0');
@ini_set('log_errors', '1');

set_error_handler(function($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) return false;
    throw new ErrorException($message, 0, $severity, $file, $line);
});

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/sqlite.php'; // must provide get_db()

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid request', 'message' => 'Invalid request']);
    exit;
}

$action = $_POST['action'] ?? '';

try {
    $pdo = get_db();

    switch ($action) {
        // Add student -> insert into students, create folder + initial_profile file, add documents entry
        case 'add_student': {
            $firstName = trim($_POST['first_name'] ?? '');
            $lastName  = trim($_POST['last_name'] ?? '');
            $firstInitial = $firstName !== '' ? strtoupper(substr($firstName,0,1)) : 'X';
            $lastInitial  = $lastName !== '' ? strtoupper(substr($lastName,0,1)) : 'X';

            // generate unique external student_id (e.g., JD1234)
            do {
                $studentIdCandidate = $firstInitial . $lastInitial . str_pad(mt_rand(0,9999), 4, '0', STR_PAD_LEFT);
                $stmt = $pdo->prepare('SELECT COUNT(*) FROM students WHERE student_id = :sid');
                $stmt->execute([':sid' => $studentIdCandidate]);
                $exists = (int)$stmt->fetchColumn() > 0;
            } while ($exists);

            // Persist additional profile fields that the UI collects
            $stmt = $pdo->prepare('INSERT INTO students (student_id, first_name, last_name, grade, date_of_birth, age, gender, primary_language, service_frequency, assigned_therapist, teacher, parent_contact, medical_info, iep_status, created_at, updated_at) VALUES (:student_id, :first_name, :last_name, :grade, :date_of_birth, :age, :gender, :primary_language, :service_frequency, :assigned_therapist, :teacher, :parent_contact, :medical_info, :iep_status, :created_at, :updated_at)');
            $stmt->execute([
                ':student_id' => $studentIdCandidate,
                ':first_name' => $firstName,
                ':last_name'  => $lastName,
                ':grade'      => $_POST['grade'] ?? '',
                ':date_of_birth' => $_POST['date_of_birth'] ?? '',
                ':age' => $_POST['age'] ?? null,
                ':gender'     => $_POST['gender'] ?? '',
                ':primary_language' => $_POST['primary_language'] ?? '',
                ':service_frequency' => $_POST['service_frequency'] ?? '',
                ':assigned_therapist' => isset($_POST['assigned_therapist']) && $_POST['assigned_therapist'] !== '' ? (int)$_POST['assigned_therapist'] : ($_SESSION['user_id'] ?? null),
                ':teacher' => $_POST['teacher'] ?? '',
                ':parent_contact' => $_POST['parent_contact'] ?? '',
                ':medical_info' => $_POST['medical_info'] ?? '',
                ':iep_status' => $_POST['iep_status'] ?? '',
                ':created_at' => date('c'),
                ':updated_at' => date('c'),
            ]);

            $id = (int)$pdo->lastInsertId();

            // create initial profile file and add documents row
            // Persist the profile document into the documents table in the DB (no filesystem writes)
            $profileData = array(
                        'studentName' => $id,
                        'studentId' => $studentIdCandidate,
                        'fullName' => trim($firstName . ' ' . $lastName),
                        'dateOfBirth' => $_POST['date_of_birth'] ?? '',
                        'grade' => $_POST['grade'] ?? '',
                        'age' => $_POST['age'] ?? '',
                        'gender' => $_POST['gender'] ?? '',
                        'primaryLanguage' => $_POST['primary_language'] ?? '',
                        'serviceFrequency' => $_POST['service_frequency'] ?? '',
                        'teacher' => $_POST['teacher'] ?? '',
                        'parentContact' => $_POST['parent_contact'] ?? '',
                        'medicalInfo' => $_POST['medical_info'] ?? '',
                        'iepStatus' => $_POST['iep_status'] ?? '',
                        'enrollmentDate' => date('Y-m-d'),
                    );

            $timestamp = time();
            $profileDoc = [
                'id' => $timestamp,
                'student_id' => $id,
                'form_type' => 'initial_profile',
                'form_data' => $profileData,
                'title' => 'Initial Profile - ' . $profileData['fullName'],
                'therapist_id' => $_SESSION['user_id'] ?? null,
                'created_at' => date('c')
            ];

            // Store initial profile in normalized initial_evaluations table
            $stmt = $pdo->prepare('INSERT INTO initial_evaluations (student_id, title, therapist_id, metadata, content, created_at) VALUES (:student_id, :title, :therapist_id, :metadata, :content, :created_at)');
            $stmt->execute([
                ':student_id' => $id,
                ':title' => $profileDoc['title'],
                ':therapist_id' => $_SESSION['user_id'] ?? null,
                ':metadata' => json_encode(['form_type' => 'initial_profile']),
                ':content' => json_encode($profileDoc, JSON_PRETTY_PRINT),
                ':created_at' => date('c'),
            ]);

            echo json_encode(['success' => true, 'id' => $id]);
            break;
        }

        // Add goal -> insert into goals
        case 'add_goal': {
            $goal_text = trim($_POST['goal_text'] ?? $_POST['description'] ?? '');
            $stmt = $pdo->prepare('INSERT INTO goals (student_id, title, description, status, created_at) VALUES (:student_id, :title, :description, :status, :created_at)');
            $stmt->execute([
                ':student_id' => (int)($_POST['student_id'] ?? 0),
                ':title' => $_POST['goal_area'] ?? '',
                ':description' => $goal_text,
                ':status' => 'active',
                ':created_at' => date('c'),
            ]);
            $id = (int)$pdo->lastInsertId();
            echo json_encode(['success'=>true,'id'=>$id]);
            break;
        }

        // Add progress update -> insert into progress_updates
        case 'add_progress': {
            $stmt = $pdo->prepare('INSERT INTO progress_updates (student_id, note, created_at) VALUES (:student_id, :note, :created_at)');
            $note = $_POST['notes'] ?? ($_POST['text'] ?? '');
            $stmt->execute([
                ':student_id' => (int)($_POST['student_id'] ?? 0),
                ':note' => $note,
                ':created_at' => date('c'),
            ]);
            $id = (int)$pdo->lastInsertId();
            echo json_encode(['success'=>true,'id'=>$id]);
            break;
        }

        // Delete student -> remove DB row (documents cascade) and keep files (optional)
        case 'delete_student': {
            $idToDelete = isset($_POST['id']) ? (int)$_POST['id'] : 0;
            if (!$idToDelete) { echo json_encode(['success'=>false,'error'=>'Missing student id','message'=>'Missing student id']); break; }
            $stmt = $pdo->prepare('DELETE FROM students WHERE id = :id');
            $stmt->execute([':id'=>$idToDelete]);
            echo json_encode(['success'=>true]);
            break;
        }

        // Archive student -> set archived flag
        case 'archive_student': {
            $idToArchive = isset($_POST['id']) ? (int)$_POST['id'] : 0;
            if (!$idToArchive) { echo json_encode(['success'=>false,'error'=>'Missing student id','message'=>'Missing student id']); break; }
            $stmt = $pdo->prepare('UPDATE students SET archived = 1 WHERE id = :id');
            $stmt->execute([':id'=>$idToArchive]);
            echo json_encode(['success'=>true]);
            break;
        }

        // Save document/form -> write file and insert documents row
        case 'save_document': {
            $formData = $_POST['form_data'] ? json_decode($_POST['form_data'], true) : [];
            $timestamp = time();
            $formType = $_POST['form_type'] ?? ($formData['form_type'] ?? 'unspecified');
            $studentId = null;
            if (isset($formData['studentName']) && is_numeric($formData['studentName'])) {
                $studentId = (int)$formData['studentName'];
            } elseif (isset($_POST['student_id'])) {
                $studentId = (int)$_POST['student_id'];
            }
            if (!$studentId) { echo json_encode(['success'=>false,'error'=>'Student ID is required','message'=>'Student ID is required']); break; }

            // No filesystem folders are created; documents are stored in DB

            $stmt = $pdo->prepare('SELECT first_name, last_name FROM students WHERE id = :id');
            $stmt->execute([':id'=>$studentId]);
            $r = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($r) $studentName = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));

            $doc = [
                'id' => $timestamp,
                'student_id' => $studentId,
                'form_type' => $formType,
                'form_data' => $formData,
                'title' => (isset($_POST['title']) ? $_POST['title'] : (ucwords(str_replace('_',' ',$formType)) . ' - ' . ($studentName ?? ''))),
                'therapist_id' => $_SESSION['user_id'] ?? null,
                'created_at' => date('c')
            ];

            // Persist into the appropriate per-form table when possible
            $userId = $_SESSION['user_id'] ?? null;
            $contentJson = json_encode($doc, JSON_PRETTY_PRINT);
            if (in_array($formType, ['initial_evaluation','initial_profile'])) {
                $stmt = $pdo->prepare('INSERT INTO initial_evaluations (student_id, title, therapist_id, metadata, content, created_at) VALUES (:student_id, :title, :therapist_id, :metadata, :content, :created_at)');
                $stmt->execute([
                    ':student_id' => $studentId,
                    ':title' => $doc['title'],
                    ':therapist_id' => $userId,
                    ':metadata' => json_encode(['form_type'=>$formType]),
                    ':content' => $contentJson,
                    ':created_at' => date('c'),
                ]);
            } elseif (in_array($formType, ['session_report','session_notes','session'])) {
                // attempt to extract session_date/duration/session_type from form data
                $session_date = $formData['sessionDate'] ?? $formData['session_date'] ?? null;
                $duration = isset($formData['sessionDuration']) ? (int)$formData['sessionDuration'] : (isset($formData['duration_minutes']) ? (int)$formData['duration_minutes'] : null);
                $stype = $formData['sessionType'] ?? $formData['session_type'] ?? null;
                $stmt = $pdo->prepare('INSERT INTO session_reports (student_id, session_date, duration_minutes, session_type, title, therapist_id, metadata, content, created_at) VALUES (:student_id, :session_date, :duration_minutes, :session_type, :title, :therapist_id, :metadata, :content, :created_at)');
                $stmt->execute([
                    ':student_id' => $studentId,
                    ':session_date' => $session_date,
                    ':duration_minutes' => $duration,
                    ':session_type' => $stype,
                    ':title' => $doc['title'],
                    ':therapist_id' => $userId,
                    ':metadata' => json_encode(['form_type'=>$formType]),
                    ':content' => $contentJson,
                    ':created_at' => date('c'),
                ]);
            } elseif (in_array($formType, ['discharge_report','discharge'])) {
                $stmt = $pdo->prepare('INSERT INTO discharge_reports (student_id, title, therapist_id, metadata, content, created_at) VALUES (:student_id, :title, :therapist_id, :metadata, :content, :created_at)');
                $stmt->execute([
                    ':student_id' => $studentId,
                    ':title' => $doc['title'],
                    ':therapist_id' => $userId,
                    ':metadata' => json_encode(['form_type'=>$formType]),
                    ':content' => $contentJson,
                    ':created_at' => date('c'),
                ]);
            } else {
                // Unknown form types: store in other_documents
                $stmt = $pdo->prepare('INSERT INTO other_documents (student_id, title, form_type, therapist_id, metadata, content, created_at) VALUES (:student_id, :title, :form_type, :therapist_id, :metadata, :content, :created_at)');
                $stmt->execute([
                    ':student_id' => $studentId,
                    ':title' => $doc['title'],
                    ':form_type' => $formType,
                    ':therapist_id' => $userId,
                    ':metadata' => json_encode(['form_type'=>$formType]),
                    ':content' => $contentJson,
                    ':created_at' => date('c'),
                ]);
            }

            echo json_encode(['success'=>true,'id'=>$timestamp]);
            break;
        }

        // Get student forms -> read documents table and file contents if present
        case 'get_student_forms': {
            $studentId = isset($_POST['student_id']) ? (int)$_POST['student_id'] : 0;
            if (!$studentId) { echo json_encode(['success'=>false,'error'=>'Student ID required','message'=>'Student ID required']); break; }

            // Aggregate forms from per-form tables (preferred) and fallback to documents table for unknowns
            $forms = [];
            // initial evaluations
            $stmt = $pdo->prepare('SELECT id, title, therapist_id, metadata, content, created_at FROM initial_evaluations WHERE student_id = :sid ORDER BY created_at DESC');
            $stmt->execute([':sid'=>$studentId]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                if (!empty($r['content'])) { $json = json_decode($r['content'], true); if ($json) { $forms[] = $json; continue; } }
                $forms[] = ['id'=>$r['id'],'title'=>$r['title'],'created_at'=>$r['created_at'],'form_type'=>'initial_evaluation'];
            }
            // session reports
            $stmt = $pdo->prepare('SELECT id, title, therapist_id, metadata, content, created_at FROM session_reports WHERE student_id = :sid ORDER BY created_at DESC');
            $stmt->execute([':sid'=>$studentId]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                if (!empty($r['content'])) { $json = json_decode($r['content'], true); if ($json) { $forms[] = $json; continue; } }
                $forms[] = ['id'=>$r['id'],'title'=>$r['title'],'created_at'=>$r['created_at'],'form_type'=>'session_report'];
            }
            // discharge reports
            $stmt = $pdo->prepare('SELECT id, title, therapist_id, metadata, content, created_at FROM discharge_reports WHERE student_id = :sid ORDER BY created_at DESC');
            $stmt->execute([':sid'=>$studentId]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                if (!empty($r['content'])) { $json = json_decode($r['content'], true); if ($json) { $forms[] = $json; continue; } }
                $forms[] = ['id'=>$r['id'],'title'=>$r['title'],'created_at'=>$r['created_at'],'form_type'=>'discharge_report'];
            }
            // fallback: other_documents table for any other types
            $stmt = $pdo->prepare('SELECT id, title, form_type, metadata, content, created_at FROM other_documents WHERE student_id = :sid ORDER BY created_at DESC');
            $stmt->execute([':sid'=>$studentId]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                if (!empty($r['content'])) { $json = json_decode($r['content'], true); if ($json) { $forms[] = $json; continue; } }
                $forms[] = ['id'=>$r['id'],'title'=>$r['title'],'created_at'=>$r['created_at'],'form_type'=>$r['form_type'] ?? null];
            }
            echo json_encode(['success'=>true,'forms'=>$forms]);
            break;
        }

        // Get student -> return DB student row
        case 'get_student': {
            $studentId = isset($_POST['id']) ? (int)$_POST['id'] : 0;
            if (!$studentId) { echo json_encode(['success'=>false,'error'=>'Student ID required','message'=>'Student ID required']); break; }
            $stmt = $pdo->prepare('SELECT * FROM students WHERE id = :id');
            $stmt->execute([':id'=>$studentId]);
            $student = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$student) { echo json_encode(['success'=>false,'error'=>'Student not found','message'=>'Student not found']); break; }
            echo json_encode(['success'=>true,'student'=>$student]);
            break;
        }

        // Update student -> update DB
        case 'update_student': {
            $studentId = isset($_POST['student_id']) ? (int)$_POST['student_id'] : 0;
            if (!$studentId) { echo json_encode(['success'=>false,'error'=>'Student ID required','message'=>'Student ID required']); break; }
            $stmt = $pdo->prepare('UPDATE students SET first_name=:first_name, last_name=:last_name, grade=:grade, assigned_therapist=:assigned_therapist, date_of_birth=:dob, age=:age, gender=:gender, primary_language=:pl, service_frequency=:sf, teacher=:teacher, parent_contact=:parent_contact, medical_info=:medical_info, iep_status=:iep_status, updated_at=:updated_at WHERE id=:id');
            $stmt->execute([
                ':first_name'=>trim($_POST['first_name'] ?? ''),
                ':last_name'=>trim($_POST['last_name'] ?? ''),
                ':grade'=>$_POST['grade'] ?? '',
                ':assigned_therapist'=>isset($_POST['assigned_therapist']) && $_POST['assigned_therapist'] !== '' ? (int)$_POST['assigned_therapist'] : ($_SESSION['user_id'] ?? null),
                ':dob'=>$_POST['date_of_birth'] ?? '',
                ':age' => $_POST['age'] ?? null,
                ':gender'=>$_POST['gender'] ?? '',
                ':pl'=>$_POST['primary_language'] ?? '',
                ':sf'=>$_POST['service_frequency'] ?? '',
                ':teacher'=>$_POST['teacher'] ?? '',
                ':parent_contact'=>$_POST['parent_contact'] ?? '',
                ':medical_info'=>$_POST['medical_info'] ?? '',
                ':iep_status'=>$_POST['iep_status'] ?? '',
                ':updated_at'=>date('c'),
                ':id'=>$studentId,
            ]);
            echo json_encode(['success'=>true]);
            break;
        }

        // Export student HTML -> build using DB data and documents files
        case 'export_student_html': {
            $idToExport = isset($_POST['id']) ? (int)$_POST['id'] : 0;
            if (!$idToExport) { echo json_encode(['success'=>false,'error'=>'Missing id','message'=>'Missing id']); break; }

            $stmt = $pdo->prepare('SELECT * FROM students WHERE id = :id');
            $stmt->execute([':id'=>$idToExport]);
            $student = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$student) { echo json_encode(['success'=>false,'error'=>'Student not found','message'=>'Student not found']); break; }

            // aggregate per-form tables and documents fallback
            $student_docs = [];
            $tables = ['initial_evaluations','session_reports','discharge_reports','other_documents'];
            foreach ($tables as $t) {
                $stmt = $pdo->prepare("SELECT * FROM {$t} WHERE student_id = :sid ORDER BY created_at DESC");
                $stmt->execute([':sid'=>$idToExport]);
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $d) {
                    if (!empty($d['content'])) { $formData = json_decode($d['content'], true); if ($formData) { $student_docs[] = $formData; continue; } }
                    $student_docs[] = ['id'=>$d['id'],'title'=>$d['title'] ?? ($d['form_type'] ?? 'Document'),'created_at'=>$d['created_at']];
                }
            }

            // goals and progress for this student
            $stmt = $pdo->prepare('SELECT * FROM goals WHERE student_id = :sid');
            $stmt->execute([':sid'=>$idToExport]);
            $student_goals = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $stmt = $pdo->prepare('SELECT * FROM progress_updates WHERE student_id = :sid ORDER BY created_at DESC');
            $stmt->execute([':sid'=>$idToExport]);
            $student_progress = $stmt->fetchAll(PDO::FETCH_ASSOC);

            ob_start();
            include __DIR__ . '/../templates/export.php';
            $html = ob_get_clean();
            echo json_encode(['success'=>true,'html'=>$html]);
            break;
        }

        // Get archived students
        case 'get_archived_students': {
            $stmt = $pdo->query('SELECT * FROM students WHERE archived = 1 ORDER BY last_name, first_name');
            $archived = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success'=>true,'students'=>$archived]);
            break;
        }

        // Restore student
        case 'restore_student': {
            $studentId = isset($_POST['id']) ? (int)$_POST['id'] : 0;
            if (!$studentId) { echo json_encode(['success'=>false,'error'=>'Student ID required']); break; }
            $stmt = $pdo->prepare('UPDATE students SET archived = 0 WHERE id = :id');
            $stmt->execute([':id'=>$studentId]);
            echo json_encode(['success'=>true]);
            break;
        }

        // Export all students as HTML
        case 'export_all_students': {
            $stmt = $pdo->query('SELECT * FROM students WHERE archived = 0 ORDER BY last_name, first_name');
            $activeStudents = $stmt->fetchAll(PDO::FETCH_ASSOC);
            ob_start();
            ?>
            <!DOCTYPE html>
            <html><head><meta charset="utf-8"><title>All Students Export</title>
            <style>body{font-family:Arial,sans-serif;padding:20px} .student{margin-bottom:30px;padding:15px;border:1px solid #ddd;border-radius:5px} .student h3{margin-top:0;color:#333} .info{margin:5px 0} .label{font-weight:bold}</style>
            </head><body>
            <h1>All Students Export - <?php echo date('Y-m-d H:i:s'); ?></h1>
            <p>Total Students: <?php echo count($activeStudents); ?></p>
            <?php foreach ($activeStudents as $student): ?>
                <div class="student">
                    <h3><?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></h3>
                    <div class="info"><span class="label">Student ID:</span> <?php echo htmlspecialchars($student['student_id'] ?? $student['id']); ?></div>
                    <div class="info"><span class="label">Grade:</span> <?php echo htmlspecialchars($student['grade'] ?? 'N/A'); ?></div>
                    <div class="info"><span class="label">Date of Birth:</span> <?php echo htmlspecialchars($student['date_of_birth'] ?? 'N/A'); ?></div>
                    <div class="info"><span class="label">Gender:</span> <?php echo htmlspecialchars($student['gender'] ?? 'N/A'); ?></div>
                    <div class="info"><span class="label">Primary Language:</span> <?php echo htmlspecialchars($student['primary_language'] ?? 'English'); ?></div>
                    <div class="info"><span class="label">Service Frequency:</span> <?php echo htmlspecialchars($student['service_frequency'] ?? 'N/A'); ?></div>
                    <div class="info"><span class="label">Teacher:</span> <?php echo htmlspecialchars($student['teacher'] ?? 'N/A'); ?></div>
                    <div class="info"><span class="label">Parent Contact:</span> <?php echo htmlspecialchars($student['parent_contact'] ?? 'N/A'); ?></div>
                    <?php if (!empty($student['medical_info'])): ?>
                    <div class="info"><span class="label">Medical Info:</span> <?php echo nl2br(htmlspecialchars($student['medical_info'])); ?></div>
                    <?php endif; ?>
                    <div class="info"><span class="label">Created:</span> <?php echo htmlspecialchars($student['created_at'] ?? 'N/A'); ?></div>
                </div>
            <?php endforeach; ?>
            </body></html>
            <?php
            $exportHtml = ob_get_clean();
            echo json_encode(['success'=>true,'html'=>$exportHtml]);
            break;
        }

        // Create backup -> export DB tables to JSON file
        case 'create_backup': {
            $backup = [];
            $tables = ['students','goals','progress_updates','documents','users','reports'];
            foreach ($tables as $t) {
                $stmt = $pdo->query("SELECT * FROM {$t}");
                $backup[$t] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
            $backup['backup_created'] = date('c');
            // Do not write JSON files to disk. Return the backup payload in the response so callers
            // can download or persist it where appropriate.
            echo json_encode(['success'=>true,'backup'=>$backup]);
            break;
        }

        // Request password reset -> use users table if present, otherwise fallback to JSON resets
        case 'request_password_reset': {
            $identifier = trim($_POST['username'] ?? '');
            if (!$identifier) { echo json_encode(['success'=>false,'error'=>'Username/email required','message'=>'Username/email required']); break; }

            $stmt = $pdo->prepare('SELECT id, username, email FROM users WHERE username = :id OR email = :id LIMIT 1');
            $stmt->execute([':id'=>$identifier]);
            $foundUser = $stmt->fetch(PDO::FETCH_ASSOC);

            // Always respond success to avoid leaking usernames
            if ($foundUser) {
                    $token = bin2hex(random_bytes(16));
                    $expires = time() + 3600;
                    // Store reset token in DB (password_resets table) instead of writing to files
                    try {
                        $stmt = $pdo->prepare('INSERT INTO password_resets (user_id, username, token, expires_at, created_at) VALUES (:user_id, :username, :token, :expires_at, :created_at)');
                        $stmt->execute([
                            ':user_id' => $foundUser['id'] ?? null,
                            ':username' => $foundUser['username'] ?? null,
                            ':token' => $token,
                            ':expires_at' => date('c', $expires),
                            ':created_at' => date('c')
                        ]);
                    } catch (Exception $e) {
                        // If DB insert fails, proceed silently but do not create filesystem paths
                    }
            }
            echo json_encode(['success'=>true]);
            break;
        }

        // Get all (or filtered) students
        case 'get_students': {
            // optional: allow filtering via POST (assigned_therapist, archived)
            $assigned = isset($_POST['assigned_therapist']) && $_POST['assigned_therapist'] !== '' ? (int)$_POST['assigned_therapist'] : null;
            $archived  = isset($_POST['archived']) && $_POST['archived'] !== '' ? (int)$_POST['archived'] : null;

            $sql = 'SELECT * FROM students';
            $conds = [];
            $params = [];

            if ($assigned !== null) {
                $conds[] = 'assigned_therapist = :assigned';
                $params[':assigned'] = $assigned;
            }
            if ($archived !== null) {
                $conds[] = 'archived = :archived';
                $params[':archived'] = $archived;
            }
            if ($conds) $sql .= ' WHERE ' . implode(' AND ', $conds);

            $sql .= ' ORDER BY last_name, first_name';

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'students' => $rows]);
            break;
        }

        // Get goals
        case 'get_goals': {
            $sid = isset($_POST['student_id']) ? (int)$_POST['student_id'] : 0;
            if ($sid) {
                $stmt = $pdo->prepare('SELECT * FROM goals WHERE student_id = :sid ORDER BY created_at DESC');
                $stmt->execute([':sid'=>$sid]);
                echo json_encode(['success'=>true,'goals'=>$stmt->fetchAll(PDO::FETCH_ASSOC)]);
            } else {
                $stmt = $pdo->query('SELECT * FROM goals ORDER BY created_at DESC');
                echo json_encode(['success'=>true,'goals'=>$stmt->fetchAll(PDO::FETCH_ASSOC)]);
            }
            break;
        }

        // Get progress updates
        case 'get_progress': {
            $sid = isset($_POST['student_id']) ? (int)$_POST['student_id'] : 0;
            if ($sid) {
                $stmt = $pdo->prepare('SELECT * FROM progress_updates WHERE student_id = :sid ORDER BY created_at DESC');
                $stmt->execute([':sid'=>$sid]);
                echo json_encode(['success'=>true,'progress'=>$stmt->fetchAll(PDO::FETCH_ASSOC)]);
            } else {
                $stmt = $pdo->query('SELECT * FROM progress_updates ORDER BY created_at DESC');
                echo json_encode(['success'=>true,'progress'=>$stmt->fetchAll(PDO::FETCH_ASSOC)]);
            }
            break;
        }

        default:
            echo json_encode(['success'=>false,'error'=>'Unknown action','message'=>'Unknown action']);
            break;
    }

} catch (\Throwable $e) {
    echo json_encode(['success'=>false,'error'=>$e->getMessage(),'message'=>$e->getMessage()]);
}
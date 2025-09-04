<?php
// Lightweight submit endpoint for add_student / add_goal / add_progress
if (session_status() === PHP_SESSION_NONE) session_start();

// Ensure submit endpoint returns well-formed JSON on error.
// Disable direct display of PHP errors (prevent raw warnings/html breaking JSON)
@ini_set('display_errors', '0');
@ini_set('log_errors', '1');

// Convert warnings/notices into exceptions so the outer try/catch can handle them.
set_error_handler(function($severity, $message, $file, $line) {
    // Respect error_reporting level
    if (!(error_reporting() & $severity)) {
        return false; // let normal handler run for suppressed errors
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid request']);
    exit;
}

$action = $_POST['action'] ?? '';

try {
    switch ($action) {
        case 'add_student':
            $students = loadJsonData('students') ?: [];
            $max = 0; foreach ($students as $s) { $max = max($max, (int)($s['id'] ?? 0)); }
            $id = $max + 1;
            
            // Generate student ID from initials and random numbers
            $firstName = trim($_POST['first_name'] ?? '');
            $lastName = trim($_POST['last_name'] ?? '');
            $firstInitial = !empty($firstName) ? strtoupper(substr($firstName, 0, 1)) : 'X';
            $lastInitial = !empty($lastName) ? strtoupper(substr($lastName, 0, 1)) : 'X';
            
            // Generate unique student ID: initials + 4 random digits
            do {
                $randomNumbers = str_pad(mt_rand(0, 9999), 4, '0', STR_PAD_LEFT);
                $studentId = $firstInitial . $lastInitial . $randomNumbers;
                
                // Check if this student ID already exists
                $idExists = false;
                foreach ($students as $existingStudent) {
                    if (($existingStudent['student_id'] ?? '') === $studentId) {
                        $idExists = true;
                        break;
                    }
                }
            } while ($idExists);
            
            $student = [
                'id' => $id,
                'student_id' => $studentId, // e.g., "JD1234"
                'first_name' => $firstName,
                'last_name' => $lastName,
                'grade' => $_POST['grade'] ?? '',
                'date_of_birth' => $_POST['date_of_birth'] ?? '',
                'gender' => $_POST['gender'] ?? '',
                'primary_language' => $_POST['primary_language'] ?? '',
                'service_frequency' => $_POST['service_frequency'] ?? '',
                'teacher' => $_POST['teacher'] ?? '',
                'assigned_therapist_name' => trim($_POST['assigned_therapist_name'] ?? ''),
                'assigned_therapist' => isset($_POST['assigned_therapist']) && $_POST['assigned_therapist'] !== '' ? (int)$_POST['assigned_therapist'] : ($_SESSION['user_id'] ?? null),
                'parent_contact' => $_POST['parent_contact'] ?? '',
                'medical_info' => $_POST['medical_info'] ?? '',
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ];
            $students[] = $student;
            saveJsonData('students', $students);
            
            // Create initial profile document for the student
            $studentFolder = __DIR__ . '/../database/data/students/student_' . $id;
            if (!is_dir($studentFolder)) {
                mkdir($studentFolder, 0755, true);
            }
            
            $profileData = [
                'studentName' => $id,
                'studentId' => $studentId, // Generated ID like "JD1234"
                'fullName' => trim($student['first_name'] . ' ' . $student['last_name']),
                'dateOfBirth' => $student['date_of_birth'],
                'grade' => $student['grade'],
                'gender' => $student['gender'],
                'primaryLanguage' => $student['primary_language'],
                'serviceFrequency' => $student['service_frequency'],
                'teacher' => $student['teacher'],
                'parentContact' => $student['parent_contact'],
                'medicalInfo' => $student['medical_info'],
                'enrollmentDate' => date('Y-m-d'),
                'therapistNotes' => 'Initial profile created automatically upon student enrollment.'
            ];
            
            $timestamp = time();
            $filename = 'initial_profile_' . $timestamp . '.json';
            $filePath = $studentFolder . '/' . $filename;
            
            $profileDoc = [
                'id' => $timestamp,
                'student_id' => $id,
                'form_type' => 'initial_profile',
                'form_data' => $profileData,
                'title' => 'Initial Profile - ' . $profileData['fullName'],
                'therapist_id' => $_SESSION['user_id'] ?? null,
                'created_at' => date('c'),
                'filename' => $filename
            ];
            
            file_put_contents($filePath, json_encode($profileDoc, JSON_PRETTY_PRINT));
            
            echo json_encode(['success' => true, 'id' => $id]);
            break;

        case 'add_goal':
            $goals = loadJsonData('goals') ?: [];
            $max = 0; foreach ($goals as $g) { $max = max($max, (int)($g['id'] ?? 0)); }
            $id = $max + 1;
            // Accept both legacy 'description' and new 'goal_text'
            $goal_text = trim($_POST['goal_text'] ?? $_POST['description'] ?? '');
            $goal = [
                'id' => $id,
                'student_id' => (int)($_POST['student_id'] ?? 0),
                'therapist_id' => $_SESSION['user_id'] ?? null,
                'goal_area' => trim($_POST['goal_area'] ?? ''),
                // keep both keys for compatibility with templates
                'goal_text' => $goal_text,
                'description' => $goal_text,
                'baseline_score' => is_numeric($_POST['baseline_score'] ?? null) ? (float)$_POST['baseline_score'] : 0,
                'target_score' => is_numeric($_POST['target_score'] ?? null) ? (float)$_POST['target_score'] : 0,
                'target_date' => $_POST['target_date'] ?? '',
                'status' => 'active',
                'created_at' => date('c'),
            ];
            $goals[] = $goal;
            saveJsonData('goals', $goals);
            echo json_encode(['success' => true, 'id' => $id]);
            break;

        case 'add_progress':
            $updates = loadJsonData('progress_updates') ?: [];
            $max = 0; foreach ($updates as $u) { $max = max($max, (int)($u['id'] ?? 0)); }
            $id = $max + 1;
            $update = [
                'id' => $id,
                'student_id' => (int)($_POST['student_id'] ?? 0),
                'goal_id' => isset($_POST['goal_id']) ? (int)$_POST['goal_id'] : null,
                'therapist_id' => $_SESSION['user_id'] ?? null,
                'date_recorded' => $_POST['date_recorded'] ?? date('Y-m-d'),
                'score' => is_numeric($_POST['score'] ?? null) ? (float)$_POST['score'] : null,
                'notes' => $_POST['notes'] ?? '',
                'created_at' => date('c'),
            ];
            $updates[] = $update;
            saveJsonData('progress_updates', $updates);
            echo json_encode(['success' => true, 'id' => $id]);
            break;

        case 'delete_student':
            $idToDelete = isset($_POST['id']) ? (int)$_POST['id'] : 0;
            if (!$idToDelete) {
                echo json_encode(['success' => false, 'error' => 'Missing student id']);
                break;
            }
            $students = loadJsonData('students') ?: [];
            $before = count($students);
            $students = array_values(array_filter($students, function($s) use ($idToDelete) {
                return (int)($s['id'] ?? 0) !== $idToDelete;
            }));
            $after = count($students);
            if ($after === $before) {
                echo json_encode(['success' => false, 'error' => 'Student not found']);
                break;
            }
            saveJsonData('students', $students);
            echo json_encode(['success' => true]);
            break;
        case 'archive_student':
            $idToArchive = isset($_POST['id']) ? (int)$_POST['id'] : 0;
            if (!$idToArchive) {
                echo json_encode(['success' => false, 'error' => 'Missing student id']);
                break;
            }
            $students = loadJsonData('students') ?: [];
            $found = false;
            foreach ($students as &$s) {
                if ((int)($s['id'] ?? 0) === $idToArchive) { $s['archived'] = true; $found = true; break; }
            }
            if (!$found) { echo json_encode(['success' => false, 'error' => 'Student not found']); break; }
            saveJsonData('students', $students);
            echo json_encode(['success' => true]);
            break;

        case 'save_document':
            // Handle new form data structure with individual student folders
            $formData = $_POST['form_data'] ? json_decode($_POST['form_data'], true) : [];
            $studentId = null;
            
            // Extract student ID from form data
            if (isset($formData['studentName']) && is_numeric($formData['studentName'])) {
                $studentId = (int)$formData['studentName'];
            } elseif (isset($_POST['student_id'])) {
                $studentId = (int)$_POST['student_id'];
            }
            
            if (!$studentId) {
                echo json_encode(['success' => false, 'error' => 'Student ID is required']);
                break;
            }
            
            // Get student name for title
            $students = loadJsonData('students') ?: [];
            $studentRecord = null;
            foreach ($students as $s) {
                if ((int)($s['id'] ?? 0) === $studentId) {
                    $studentRecord = $s;
                    break;
                }
            }
            
            if (!$studentRecord) {
                echo json_encode(['success' => false, 'error' => 'Student not found']);
                break;
            }
            
            $studentName = ($studentRecord['first_name'] ?? '') . ' ' . ($studentRecord['last_name'] ?? '');
            $formType = $_POST['form_type'] ?? '';
            $formTypeTitle = str_replace('_', ' ', ucwords($formType));
            
            // Create student folder if it doesn't exist
            $studentFolder = __DIR__ . '/../database/data/students/student_' . $studentId;
            if (!is_dir($studentFolder)) {
                mkdir($studentFolder, 0755, true);
            }
            
            // Generate unique filename
            $timestamp = time();
            $filename = $formType . '_' . $timestamp . '.json';
            $filePath = $studentFolder . '/' . $filename;
            
            $doc = [
                'id' => $timestamp, // Use timestamp as unique ID
                'student_id' => $studentId,
                'form_type' => $formType,
                'form_data' => $formData,
                'title' => $formTypeTitle . ' - ' . $studentName,
                'therapist_id' => $_SESSION['user_id'] ?? null,
                'created_at' => date('c'),
                'filename' => $filename
            ];
            
            // Save individual form file
            $jsonData = json_encode($doc, JSON_PRETTY_PRINT);
            if (file_put_contents($filePath, $jsonData) === false) {
                echo json_encode(['success' => false, 'error' => 'Failed to save document']);
                break;
            }
            
            echo json_encode(['success' => true, 'id' => $timestamp, 'filename' => $filename]);
            break;
            
        case 'get_student_forms':
            $studentId = isset($_POST['student_id']) ? (int)$_POST['student_id'] : 0;
            if (!$studentId) {
                echo json_encode(['success' => false, 'error' => 'Student ID required']);
                break;
            }
            
            $studentFolder = __DIR__ . '/../database/data/students/student_' . $studentId;
            $forms = [];
            
            if (is_dir($studentFolder)) {
                $files = glob($studentFolder . '/*.json');
                foreach ($files as $file) {
                    $content = file_get_contents($file);
                    if ($content !== false) {
                        $formData = json_decode($content, true);
                        if ($formData) {
                            $forms[] = $formData;
                        }
                    }
                }
                
                // Sort by creation date (newest first)
                usort($forms, function($a, $b) {
                    return strtotime($b['created_at']) - strtotime($a['created_at']);
                });
            }
            
            echo json_encode(['success' => true, 'forms' => $forms]);
            break;

        case 'get_student':
            $studentId = isset($_POST['id']) ? (int)$_POST['id'] : 0;
            if (!$studentId) {
                echo json_encode(['success' => false, 'error' => 'Student ID required']);
                break;
            }
            
            $students = loadJsonData('students') ?: [];
            $student = null;
            foreach ($students as $s) {
                if ((int)($s['id'] ?? 0) === $studentId) {
                    $student = $s;
                    break;
                }
            }
            
            if (!$student) {
                echo json_encode(['success' => false, 'error' => 'Student not found']);
                break;
            }

            echo json_encode(['success' => true, 'student' => $student]);
            break;

        case 'update_student':
            $studentId = isset($_POST['student_id']) ? (int)$_POST['student_id'] : 0;
            if (!$studentId) {
                echo json_encode(['success' => false, 'error' => 'Student ID required']);
                break;
            }
            
            $students = loadJsonData('students') ?: [];
            $studentIndex = -1;
            foreach ($students as $index => $s) {
                if ((int)($s['id'] ?? 0) === $studentId) {
                    $studentIndex = $index;
                    break;
                }
            }
            
            if ($studentIndex === -1) {
                echo json_encode(['success' => false, 'error' => 'Student not found']);
                break;
            }
            
            // Update student data
            $students[$studentIndex] = array_merge($students[$studentIndex], [
                'first_name' => trim($_POST['first_name'] ?? ''),
                'last_name' => trim($_POST['last_name'] ?? ''),
                'grade' => $_POST['grade'] ?? '',
                'assigned_therapist_name' => trim($_POST['assigned_therapist_name'] ?? ($students[$studentIndex]['assigned_therapist_name'] ?? '')),
                'assigned_therapist' => isset($_POST['assigned_therapist']) && $_POST['assigned_therapist'] !== '' ? (int)$_POST['assigned_therapist'] : ($students[$studentIndex]['assigned_therapist'] ?? $_SESSION['user_id'] ?? null),
                'date_of_birth' => $_POST['date_of_birth'] ?? '',
                'gender' => $_POST['gender'] ?? '',
                'primary_language' => $_POST['primary_language'] ?? '',
                'service_frequency' => $_POST['service_frequency'] ?? '',
                'teacher' => $_POST['teacher'] ?? '',
                'parent_contact' => $_POST['parent_contact'] ?? '',
                'medical_info' => $_POST['medical_info'] ?? '',
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            
            saveJsonData('students', $students);
            echo json_encode(['success' => true]);
            break;

        case 'export_student_html':
            $idToExport = isset($_POST['id']) ? (int)$_POST['id'] : 0;
            if (!$idToExport) { echo json_encode(['success' => false, 'error' => 'Missing id']); break; }
            $students = loadJsonData('students') ?: [];
            $student = findRecord($students, 'id', $idToExport);
            if (!$student) { echo json_encode(['success' => false, 'error' => 'Student not found']); break; }
            
            // Load forms from student folder
            $studentFolder = __DIR__ . '/../database/data/students/student_' . $idToExport;
            $student_docs = [];
            
            if (is_dir($studentFolder)) {
                $files = glob($studentFolder . '/*.json');
                foreach ($files as $file) {
                    $content = file_get_contents($file);
                    if ($content !== false) {
                        $formData = json_decode($content, true);
                        if ($formData) {
                            $student_docs[] = $formData;
                        }
                    }
                }
                
                // Sort by creation date (newest first for display)
                usort($student_docs, function($a, $b) {
                    return strtotime($b['created_at']) - strtotime($a['created_at']);
                });
            }
            
            // Load goals and progress for this student
            $goals = loadJsonData('goals') ?: [];
            $progress_updates = loadJsonData('progress_updates') ?: [];
            
            $student_goals = array_filter($goals, function($goal) use ($idToExport) {
                return (int)($goal['student_id'] ?? 0) === $idToExport;
            });
            
            $student_progress = array_filter($progress_updates, function($update) use ($idToExport) {
                return (int)($update['student_id'] ?? 0) === $idToExport;
            });
            
            // Start output buffering to capture the template
            ob_start();
            
            // Include the export template
            include __DIR__ . '/../templates/export.php';
            
            // Get the rendered HTML
            $html = ob_get_clean();
            echo json_encode(['success' => true, 'html' => $html]);
            break;

        case 'get_archived_students':
            $students = loadJsonData('students') ?: [];
            $archivedStudents = [];
            
            foreach ($students as $student) {
                if (!empty($student['archived'])) {
                    $archivedStudents[] = $student;
                }
            }
            
            echo json_encode(['success' => true, 'students' => $archivedStudents]);
            break;

        case 'restore_student':
            $studentId = isset($_POST['id']) ? (int)$_POST['id'] : 0;
            if (!$studentId) {
                echo json_encode(['success' => false, 'error' => 'Student ID required']);
                break;
            }
            
            $students = loadJsonData('students') ?: [];
            $found = false;
            
            foreach ($students as &$student) {
                if ((int)($student['id'] ?? 0) === $studentId) {
                    unset($student['archived']); // Remove archived flag
                    $student['updated_at'] = date('Y-m-d H:i:s');
                    $found = true;
                    break;
                }
            }
            
            if (!$found) {
                echo json_encode(['success' => false, 'error' => 'Student not found']);
                break;
            }
            
            saveJsonData('students', $students);
            echo json_encode(['success' => true]);
            break;

        case 'export_all_students':
            $students = loadJsonData('students') ?: [];
            $activeStudents = array_filter($students, function($s) {
                return empty($s['archived']);
            });
            
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
            echo json_encode(['success' => true, 'html' => $exportHtml]);
            break;

        case 'create_backup':
            // Simple backup creation - could be enhanced to create ZIP files
            $backupData = [
                'students' => loadJsonData('students') ?: [],
                'goals' => loadJsonData('goals') ?: [],
                'progress_updates' => loadJsonData('progress_updates') ?: [],
                'users' => loadJsonData('users') ?: [],
                'backup_created' => date('Y-m-d H:i:s'),
                'version' => '1.0'
            ];
            
            $backupDir = __DIR__ . '/../database/backups';
            if (!is_dir($backupDir)) {
                mkdir($backupDir, 0755, true);
            }
            
            $backupFile = $backupDir . '/backup_' . date('Y-m-d_H-i-s') . '.json';
            $result = file_put_contents($backupFile, json_encode($backupData, JSON_PRETTY_PRINT));
            
            if ($result !== false) {
                echo json_encode(['success' => true, 'backup_file' => basename($backupFile)]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Failed to create backup file']);
            }
            break;

        case 'request_password_reset':
            $identifier = trim($_POST['username'] ?? '');
            if (!$identifier) { echo json_encode(['success' => false, 'error' => 'Username/email required']); break; }

            $users = loadJsonData('users') ?: [];
            $foundUser = null;
            foreach ($users as $u) {
                if (isset($u['username']) && $u['username'] === $identifier) { $foundUser = $u; break; }
                // also allow email field if present
                if (isset($u['email']) && $u['email'] === $identifier) { $foundUser = $u; break; }
            }

            // Always respond success to avoid leaking valid usernames
            if ($foundUser) {
                $token = bin2hex(random_bytes(16));
                $expires = time() + (60 * 60); // 1 hour

                $resetDir = __DIR__ . '/../database/data';
                if (!is_dir($resetDir)) mkdir($resetDir, 0755, true);
                $resetsFile = $resetDir . '/password_resets.json';
                $resets = [];
                if (is_file($resetsFile)) {
                    $content = file_get_contents($resetsFile);
                    $resets = $content ? json_decode($content, true) : [];
                    if (!is_array($resets)) $resets = [];
                }

                $resets[] = [
                    'user_id' => $foundUser['id'] ?? null,
                    'username' => $foundUser['username'] ?? null,
                    'token' => $token,
                    'expires_at' => date('c', $expires),
                    'created_at' => date('c')
                ];

                file_put_contents($resetsFile, json_encode($resets, JSON_PRETTY_PRINT));

                // Note: Email sending not implemented. Token stored for manual retrieval or future email integration.
            }

            echo json_encode(['success' => true]);
            break;

        default:
            echo json_encode(['success' => false, 'error' => 'Unknown action']);
            break;
    }
} catch (\Throwable $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
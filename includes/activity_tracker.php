<?php
// includes/activity_tracker.php
// Comprehensive activity tracking system

function getRecentActivity($user_id, $limit = 10) {
    $activities = [];
    
    // Get user's students for filtering
    $user_students = findRecords('students', ['assigned_therapist' => $user_id]);
    if (!is_array($user_students)) $user_students = [];
    $student_ids = array_column($user_students, 'id');
    
    // 1. NEW STUDENTS ADDED
    foreach ($user_students as $student) {
        if (!empty($student['created_at'])) {
            $activities[] = [
                'type' => 'student_added',
                'icon' => '👤',
                'title' => 'New student added',
                'description' => trim(($student['first_name'] ?? '') . ' ' . ($student['last_name'] ?? '')) . ' was added to your caseload',
                'student_name' => trim(($student['first_name'] ?? '') . ' ' . ($student['last_name'] ?? '')),
                'student_id' => $student['id'],
                'date' => $student['created_at'],
                'timestamp' => strtotime($student['created_at'])
            ];
        }
    }
    
    // 2. NEW GOALS CREATED
    $goals = findRecords('goals', ['therapist_id' => $user_id]);
    if (is_array($goals)) {
        foreach ($goals as $goal) {
            if (!empty($goal['created_at'])) {
                $student = findRecord('students', 'id', $goal['student_id']);
                $student_name = $student ? trim(($student['first_name'] ?? '') . ' ' . ($student['last_name'] ?? '')) : 'Unknown Student';
                
                $activities[] = [
                    'type' => 'goal_created',
                    'icon' => '🎯',
                    'title' => 'New goal created',
                    'description' => 'Goal created for ' . $student_name . ': ' . substr(($goal['description'] ?? 'New goal'), 0, 50) . (strlen(($goal['description'] ?? '')) > 50 ? '...' : ''),
                    'student_name' => $student_name,
                    'student_id' => $goal['student_id'],
                    'date' => $goal['created_at'],
                    'timestamp' => strtotime($goal['created_at'])
                ];
            }
        }
    }
    
    // 3. PROGRESS UPDATES
    $progress_updates = loadJsonData('progress_updates');
    if (is_array($progress_updates)) {
        foreach ($progress_updates as $update) {
            if (empty($update['student_id']) || !in_array($update['student_id'], $student_ids)) continue;
            if (empty($update['date_recorded'])) continue;
            
            $student = findRecord('students', 'id', $update['student_id']);
            $student_name = $student ? trim(($student['first_name'] ?? '') . ' ' . ($student['last_name'] ?? '')) : 'Unknown Student';
            
            $score = isset($update['score']) && $update['score'] !== null && $update['score'] !== '' ? $update['score'] : null;
            $goal_area = isset($update['goal_area']) && $update['goal_area'] !== '' ? ucfirst((string)$update['goal_area']) : 'General';
            
            $activities[] = [
                'type' => 'progress_update',
                'icon' => '📊',
                'title' => 'Progress updated',
                'description' => $student_name . ' - ' . $goal_area . ' progress: ' . ($score !== null ? $score . '%' : 'Updated'),
                'student_name' => $student_name,
                'student_id' => $update['student_id'],
                'date' => $update['date_recorded'],
                'timestamp' => strtotime($update['date_recorded'])
            ];
        }
    }
    
    // 4. NEW DOCUMENTATION/FORMS (DB-backed)
    // Query per-form tables and other_documents for recent documents related to this user's students
    if (!empty($student_ids)) {
        // Try to get a PDO instance if sqlite helper is available
        $pdo = null;
        try {
            if (file_exists(__DIR__ . '/sqlite.php')) {
                require_once __DIR__ . '/sqlite.php';
                if (function_exists('get_db')) $pdo = get_db();
                elseif (function_exists('sqlite_get_pdo')) $pdo = sqlite_get_pdo();
            }
        } catch (Throwable $e) {
            $pdo = null;
        }

        $placeholders = implode(',', array_fill(0, count($student_ids), '?'));
        $tables = [
            'initial_evaluations' => 'Initial Evaluation',
            'session_reports' => 'Session Report',
            'discharge_reports' => 'Discharge Report',
            'other_documents' => 'Document'
        ];

        if ($pdo instanceof PDO) {
            foreach ($tables as $table => $defaultName) {
                try {
                    $sql = "SELECT * FROM {$table} WHERE student_id IN ({$placeholders}) ORDER BY created_at DESC";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute($student_ids);
                    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    foreach ($rows as $r) {
                        $sid = $r['student_id'];
                        if (!in_array($sid, $student_ids)) continue;
                        $student = findRecord('students', 'id', $sid);
                        $student_name = $student ? trim(($student['first_name'] ?? '') . ' ' . ($student['last_name'] ?? '')) : 'Unknown Student';

                        // prefer metadata.form_type if present
                        $form_type = null;
                        if (!empty($r['metadata']) && is_string($r['metadata'])) {
                            $meta = json_decode($r['metadata'], true);
                            if (is_array($meta) && !empty($meta['form_type'])) $form_type = $meta['form_type'];
                        }
                        // try content JSON
                        $contentData = null;
                        if (!empty($r['content']) && is_string($r['content'])) {
                            $c = json_decode($r['content'], true);
                            if (is_array($c)) { $contentData = $c; if (empty($form_type) && !empty($c['form_type'])) $form_type = $c['form_type']; }
                        }

                        $form_name = $defaultName;
                        if (!empty($form_type)) {
                            $map = [
                                'initial_evaluation' => 'Initial Evaluation',
                                'initial_profile' => 'Initial Profile',
                                'session_report' => 'Session Report',
                                'discharge_report' => 'Discharge Report'
                            ];
                            $form_name = $map[$form_type] ?? ucfirst(str_replace('_', ' ', $form_type));
                        }

                        $date = $r['created_at'] ?? ($contentData['created_at'] ?? null);
                        if (empty($date)) continue;

                        $activities[] = [
                            'type' => 'document_created',
                            'icon' => '📄',
                            'title' => 'New documentation',
                            'description' => $form_name . ' completed for ' . $student_name,
                            'student_name' => $student_name,
                            'student_id' => $sid,
                            'date' => $date,
                            'timestamp' => strtotime($date)
                        ];

                        // If the content JSON indicates an update timestamp different than created_at, emit an updated activity
                        if (!empty($contentData) && !empty($contentData['updated_at']) && $contentData['updated_at'] !== ($contentData['created_at'] ?? null)) {
                            $ud = $contentData['updated_at'];
                            $activities[] = [
                                'type' => 'document_updated',
                                'icon' => '✏️',
                                'title' => 'Documentation updated',
                                'description' => $form_name . ' updated for ' . $student_name,
                                'student_name' => $student_name,
                                'student_id' => $sid,
                                'date' => $ud,
                                'timestamp' => strtotime($ud)
                            ];
                        }
                    }
                } catch (Throwable $e) {
                    // if table doesn't exist or query fails, ignore and continue
                    continue;
                }
            }
        }
    }

    // STUDENT UPDATES: include updated student records as activity
    foreach ($user_students as $student) {
        if (!empty($student['updated_at']) && $student['updated_at'] !== ($student['created_at'] ?? null)) {
            $activities[] = [
                'type' => 'student_updated',
                'icon' => '📝',
                'title' => 'Student updated',
                'description' => trim(($student['first_name'] ?? '') . ' ' . ($student['last_name'] ?? '')) . ' profile updated',
                'student_name' => trim(($student['first_name'] ?? '') . ' ' . ($student['last_name'] ?? '')),
                'student_id' => $student['id'],
                'date' => $student['updated_at'],
                'timestamp' => strtotime($student['updated_at'])
            ];
        }
    }
    
    // Sort all activities by timestamp (newest first)
    usort($activities, function($a, $b) {
        return $b['timestamp'] - $a['timestamp'];
    });
    
    // Limit results
    return array_slice($activities, 0, $limit);
}

function addActivity($type, $student_id, $description, $additional_data = []) {
    // This function could be used to add custom activities to a log file
    // For now, we rely on timestamps in the data files themselves
    // But this provides a hook for future enhancement
}

/**
 * Record an activity into the DB activity_log table when possible.
 */
function record_activity($type, $student_id = null, $description = '', $meta = []) {
    try {
        if (file_exists(__DIR__ . '/sqlite.php')) {
            require_once __DIR__ . '/sqlite.php';
            if (function_exists('get_db')) $pdo = get_db(); else $pdo = sqlite_get_pdo();
            $stmt = $pdo->prepare('INSERT INTO activity_log (type, student_id, user_id, description, metadata, created_at) VALUES (:type, :student_id, :user_id, :description, :metadata, :created_at)');
            $stmt->execute([
                ':type' => $type,
                ':student_id' => $student_id,
                ':user_id' => $_SESSION['user_id'] ?? null,
                ':description' => $description,
                ':metadata' => is_array($meta) ? json_encode($meta) : $meta,
                ':created_at' => date('c')
            ]);
        }
    } catch (Throwable $e) {
        // swallow errors to avoid breaking UI
    }
}
?>

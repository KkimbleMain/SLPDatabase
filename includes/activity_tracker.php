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
    
    // 4. NEW DOCUMENTATION/FORMS
    // Check each student's folder for new documents
    foreach ($student_ids as $student_id) {
        $student_folder = __DIR__ . '/../database/data/students/student_' . $student_id;
        if (is_dir($student_folder)) {
            $files = glob($student_folder . '/*.json');
            foreach ($files as $file) {
                $content = file_get_contents($file);
                if ($content !== false) {
                    $doc = json_decode($content, true);
                    if ($doc && !empty($doc['created_at'])) {
                        $student = findRecord('students', 'id', $student_id);
                        $student_name = $student ? trim(($student['first_name'] ?? '') . ' ' . ($student['last_name'] ?? '')) : 'Unknown Student';
                        
                        $form_type_names = [
                            'initial_evaluation' => 'Initial Evaluation',
                            'goals_form' => 'Goals Form',
                            'session_report' => 'Session Report',
                            'progress_report' => 'Progress Report',
                            'discharge_report' => 'Discharge Report'
                        ];
                        
                        $form_name = $form_type_names[$doc['form_type']] ?? ucfirst(str_replace('_', ' ', $doc['form_type']));
                        
                        $activities[] = [
                            'type' => 'document_created',
                            'icon' => '📄',
                            'title' => 'New documentation',
                            'description' => $form_name . ' completed for ' . $student_name,
                            'student_name' => $student_name,
                            'student_id' => $student_id,
                            'date' => $doc['created_at'],
                            'timestamp' => strtotime($doc['created_at'])
                        ];
                    }
                }
            }
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
?>

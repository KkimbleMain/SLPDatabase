<?php
// includes/activity_tracker.php
// Comprehensive activity tracking system (progress updates removed)

function getRecentActivity($user_id, $limit = 10) {
    $activities = [];
    // Determine mode from config
    if (!defined('RECENT_ACTIVITY_MODE')) {
        // include config if not already loaded
        $cfg = __DIR__ . '/config.php';
        if (file_exists($cfg)) require_once $cfg;
    }
    $mode = defined('RECENT_ACTIVITY_MODE') ? RECENT_ACTIVITY_MODE : 'synth+log';

    // Get user's students for filtering. Prefer DB-backed lookup when possible and the column exists.
    $user_students = [];
    try {
        if (file_exists(__DIR__ . '/sqlite.php')) {
            require_once __DIR__ . '/sqlite.php';
            $pdo = get_db();
            $pi = $pdo->prepare("PRAGMA table_info('students')");
            $pi->execute();
            $cols = array_column($pi->fetchAll(PDO::FETCH_ASSOC), 'name');
            if (in_array('user_id', $cols)) {
                $stmt = $pdo->prepare('SELECT * FROM students WHERE user_id = :uid AND (archived = 0 OR archived IS NULL)');
                $stmt->execute([':uid' => $user_id]);
                $user_students = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } elseif (in_array('assigned_therapist', $cols)) {
                $stmt = $pdo->prepare('SELECT * FROM students WHERE assigned_therapist = :uid AND (archived = 0 OR archived IS NULL)');
                $stmt->execute([':uid' => $user_id]);
                $user_students = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } else {
                // No ownership column; avoid leaking all students
                $user_students = [];
            }
        }
    } catch (\Throwable $e) {
        // fallback to legacy JSON read
        $user_students = findRecords('students', ['user_id' => $user_id]) ?: findRecords('students', ['assigned_therapist' => $user_id]);
        if (!is_array($user_students)) $user_students = [];
    }
    $student_ids = array_column($user_students, 'id');

    $synthesize = $mode !== 'logOnly';

    // 1. NEW STUDENTS ADDED
    if ($synthesize) {
        foreach ($user_students as $student) {
            if (!empty($student['created_at'])) {
                $student_name = trim(($student['first_name'] ?? '') . ' ' . ($student['last_name'] ?? ''));
                $activities[] = [
                    'type' => 'student_created',
                    'icon' => '👤',
                    'title' => 'Student created',
                    'description' => 'Student created for ' . $student_name,
                    'student_name' => $student_name,
                    'student_id' => $student['id'],
                    'date' => $student['created_at'],
                    'timestamp' => strtotime($student['created_at'])
                ];
            }
        }
    }

    // 2. NEW GOALS CREATED (schema-aware)
    try {
        $pdoGoals = null;
        if (file_exists(__DIR__ . '/sqlite.php')) {
            require_once __DIR__ . '/sqlite.php';
            $pdoGoals = get_db();
        }
    if ($synthesize && $pdoGoals instanceof PDO) {
            $gcols = [];
            try { $pg = $pdoGoals->prepare("PRAGMA table_info('goals')"); $pg->execute(); $gcols = array_column($pg->fetchAll(PDO::FETCH_ASSOC), 'name'); } catch (Throwable $_e) { $gcols = []; }
            if (!empty($gcols)) {
                if (in_array('therapist_id', $gcols)) {
                    $stmt = $pdoGoals->prepare('SELECT * FROM goals WHERE therapist_id = :uid ORDER BY created_at DESC LIMIT 100');
                    $stmt->execute([':uid' => $user_id]);
                } elseif (!empty($student_ids)) {
                    $ph = implode(',', array_fill(0, count($student_ids), '?'));
                    $stmt = $pdoGoals->prepare("SELECT * FROM goals WHERE student_id IN ({$ph}) ORDER BY created_at DESC LIMIT 100");
                    $stmt->execute($student_ids);
                } else {
                    $stmt = null;
                }
                if ($stmt) {
                    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $goal) {
                        if (empty($goal['created_at'])) continue;
                        $student = findRecord('students', 'id', $goal['student_id']);
                        $student_name = $student ? trim(($student['first_name'] ?? '') . ' ' . ($student['last_name'] ?? '')) : 'Unknown Student';
                        $descText = $goal['description'] ?? ($goal['Long_Term_Goals'] ?? 'New goal');
                        $activities[] = [
                            'type' => 'goal_created',
                            'icon' => '🎯',
                            'title' => 'New goal created',
                            'description' => 'Goal created for ' . $student_name . ': ' . substr((string)$descText, 0, 50) . (strlen((string)$descText) > 50 ? '...' : ''),
                            'student_name' => $student_name,
                            'student_id' => $goal['student_id'],
                            'date' => $goal['created_at'],
                            'timestamp' => strtotime($goal['created_at'])
                        ];
                    }
                }
            }
    } else if ($synthesize) {
            // Fallback to JSON helper; only works if therapist_id exists in payloads
            $goals = findRecords('goals', ['therapist_id' => $user_id]);
            if (is_array($goals)) {
                foreach ($goals as $goal) {
                    if (!empty($goal['created_at'])) {
                        $student = findRecord('students', 'id', $goal['student_id']);
                        $student_name = $student ? trim(($student['first_name'] ?? '') . ' ' . ($student['last_name'] ?? '')) : 'Unknown Student';
                        $descText = $goal['description'] ?? ($goal['Long_Term_Goals'] ?? 'New goal');
                        $activities[] = [
                            'type' => 'goal_created',
                            'icon' => '🎯',
                            'title' => 'New goal created',
                            'description' => 'Goal created for ' . $student_name . ': ' . substr((string)$descText, 0, 50) . (strlen((string)$descText) > 50 ? '...' : ''),
                            'student_name' => $student_name,
                            'student_id' => $goal['student_id'],
                            'date' => $goal['created_at'],
                            'timestamp' => strtotime($goal['created_at'])
                        ];
                    }
                }
            }
        }
    } catch (Throwable $e) {
        // ignore goals read errors
    }

    // Note: Progress updates feature removed - do not include progress_update activities

    // 3. NEW DOCUMENTATION/FORMS (DB-backed)
    if ($synthesize && !empty($student_ids)) {
        $pdo = null;
        try {
            if (file_exists(__DIR__ . '/sqlite.php')) {
                require_once __DIR__ . '/sqlite.php';
                $pdo = get_db();
            }
        } catch (Throwable $e) {
            $pdo = null;
        }

        $placeholders = implode(',', array_fill(0, count($student_ids), '?'));
        $tables = [
            'initial_evaluations' => 'Initial Evaluation',
            'session_reports' => 'Session Report',
            'discharge_reports' => 'Discharge Report',
            'other_documents' => 'Document',
            'student_reports' => 'Progress Report'
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

                        // Determine the document title for use in the description (prefer content JSON title, then DB title/file)
                        $doc_title = '';
                        if (!empty($contentData['title'])) $doc_title = $contentData['title'];
                        elseif (!empty($r['title'])) $doc_title = $r['title'];
                        elseif (!empty($r['file_path'])) $doc_title = basename($r['file_path']);
                        if ($doc_title === '') $doc_title = ($form_name ?: 'Document');

                        // Title label should be the document type (lowercase) plus the action verb
                        $titleLabel = strtolower($form_name);
                        // Special-case generic 'Document' coming from other_documents table
                        if ($table === 'other_documents') $titleLabel = 'other document';

                        // Document created activity: title is "<doc type> created", description is "<doc title> created for <student>"
                        $activities[] = [
                            'type' => 'document_created',
                            'icon' => '📄',
                            'title' => $titleLabel . ' created',
                            'description' => $doc_title . ' created for ' . $student_name,
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
                                'title' => $titleLabel . ' updated',
                                'description' => $doc_title . ' updated for ' . $student_name,
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
    if ($synthesize) {
        foreach ($user_students as $student) {
            if (!empty($student['updated_at']) && $student['updated_at'] !== ($student['created_at'] ?? null)) {
                $student_name = trim(($student['first_name'] ?? '') . ' ' . ($student['last_name'] ?? ''));
                $activities[] = [
                    'type' => 'student_updated',
                    'icon' => '👤',
                    'title' => 'Student updated',
                    'description' => 'Student updated for ' . $student_name,
                    'student_name' => $student_name,
                    'student_id' => $student['id'],
                    'date' => $student['updated_at'],
                    'timestamp' => strtotime($student['updated_at'])
                ];
            }
        }
    }

    // Sort all activities by timestamp (newest first)
    // Merge in any persisted activity_log entries (if the table exists)
    try {
        if (file_exists(__DIR__ . '/sqlite.php')) {
            require_once __DIR__ . '/sqlite.php';
            if (function_exists('get_db')) {
                $pdo = get_db();
                $pi = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name='activity_log'"); $pi->execute();
                if ($pi->fetchColumn()) {
                    if ($mode !== 'synthOnly') {
                        $stmt = $pdo->prepare('SELECT * FROM activity_log WHERE user_id = :uid OR user_id IS NULL ORDER BY created_at DESC LIMIT :lim');
                        $stmt->bindValue(':uid', $user_id);
                        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
                        $stmt->execute();
                        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $al) {
                            $meta = null;
                            if (!empty($al['metadata'])) {
                                $dec = json_decode($al['metadata'], true);
                                if (is_array($dec)) $meta = $dec;
                            }
                            $atype = $al['type'] ?? 'activity';
                            $created = $al['created_at'] ?? null;
                            $student_name = $meta['student_name'] ?? null;
                            // If student_name not provided in metadata but student_id is present, try to resolve it
                            if (empty($student_name) && !empty($al['student_id'])) {
                                try {
                                    $srec = findRecord('students', 'id', $al['student_id']);
                                    if ($srec) $student_name = trim(($srec['first_name'] ?? '') . ' ' . ($srec['last_name'] ?? '')) ?: null;
                                } catch (Throwable $e) { /* ignore */ }
                            }

                            // Normalize persisted activity entries to match the same item shapes used above
                            if (in_array($atype, ['document_created','document_uploaded','document_updated','document_created_fallback'])) {
                                // Determine a clean doc title and type
                                $docTitle = null;
                                if (!empty($meta['title'])) $docTitle = $meta['title'];
                                elseif (!empty($al['description'])) $docTitle = preg_replace('/\s+(created|completed|deleted|uploaded).*$/i','', $al['description']);
                                if (empty($docTitle)) $docTitle = 'Document';

                                $docType = null;
                                if (!empty($meta['form_type'])) {
                                    $map = ['session_report'=>'Session Report','initial_evaluation'=>'Initial Evaluation','discharge_report'=>'Discharge Report'];
                                    $docType = $map[$meta['form_type']] ?? ucfirst(str_replace('_',' ', $meta['form_type']));
                                }
                                if (empty($docType)) {
                                    // Try to infer from title string or default to 'other document'
                                    $docType = 'Other Document';
                                }

                                $title = strtolower($docType) . ' created';
                                if (!empty($meta['form_type']) && $meta['form_type'] === 'other_documents') $title = 'other document created';
                                $desc = $docTitle . ' created for ' . ($student_name ?? 'Unknown Student');
                                $activities[] = [
                                    'type' => 'document_created',
                                    'icon' => '📄',
                                    'title' => $title,
                                    'description' => $desc,
                                    'student_name' => $student_name,
                                    'student_id' => $al['student_id'] ?? null,
                                    'date' => $created,
                                    'timestamp' => !empty($created) ? strtotime($created) : time(),
                                    'from_activity_log' => true
                                ];
                            } elseif ($atype === 'document_deleted') {
                                $docTitle = null;
                                if (!empty($meta['title'])) $docTitle = $meta['title'];
                                elseif (!empty($al['description'])) $docTitle = preg_replace('/\s+(created|completed|deleted|uploaded).*$/i','', $al['description']);
                                if (empty($docTitle)) $docTitle = 'Document';

                                $docType = null;
                                if (!empty($meta['form_type'])) {
                                    $map = ['session_report'=>'Session Report','initial_evaluation'=>'Initial Evaluation','discharge_report'=>'Discharge Report'];
                                    $docType = $map[$meta['form_type']] ?? ucfirst(str_replace('_',' ', $meta['form_type']));
                                }
                                if (empty($docType)) $docType = 'Other Document';

                                $title = strtolower($docType) . ' deleted';
                                if (!empty($meta['form_type']) && $meta['form_type'] === 'other_documents') $title = 'other document deleted';
                                $desc = $docTitle . ' deleted for ' . ($student_name ?? 'Unknown Student');
                                $activities[] = [
                                    'type' => 'document_deleted',
                                    'icon' => '📄',
                                    'title' => $title,
                                    'description' => $desc,
                                    'student_name' => $student_name,
                                    'student_id' => $al['student_id'] ?? null,
                                    'date' => $created,
                                    'timestamp' => !empty($created) ? strtotime($created) : time(),
                                    'from_activity_log' => true
                                ];
                            } elseif (in_array($atype, ['student_created','student_deleted','student_updated'])) {
                                $verb = $atype === 'student_deleted' ? 'deleted' : ($atype === 'student_updated' ? 'updated' : 'created');
                                $desc = 'Student ' . $verb . ' for ' . ($student_name ?? 'Unknown Student');
                                $activities[] = [
                                    'type' => 'student_' . ($verb),
                                    'icon' => '👤',
                                    'title' => 'Student ' . ucfirst($verb),
                                    'description' => $desc,
                                    'student_name' => $student_name,
                                    'student_id' => $al['student_id'] ?? null,
                                    'date' => $created,
                                    'timestamp' => !empty($created) ? strtotime($created) : time(),
                                    'from_activity_log' => true
                                ];
                            } elseif (in_array($atype, ['progress_report_created','progress_report_deleted'])) {
                                $rawTitle = $meta['title'] ?? ($al['description'] ?? 'Progress Report');
                                // strip trailing verbs from raw title to avoid double verbs in description
                                $title = preg_replace('/\s+(created|completed|deleted|uploaded).*$/i', '', $rawTitle);
                                if (empty($title)) $title = 'Progress Report';
                                $verb = $atype === 'progress_report_deleted' ? 'deleted' : 'created';
                                $desc = $title . ' ' . $verb . ' for ' . ($student_name ?? 'Unknown Student');
                                $activities[] = [
                                    'type' => 'report',
                                    'icon' => '📈',
                                    'title' => $title,
                                    'description' => $desc,
                                    'student_name' => $student_name,
                                    'student_id' => $al['student_id'] ?? null,
                                    'date' => $created,
                                    'timestamp' => !empty($created) ? strtotime($created) : time(),
                                    'from_activity_log' => true
                                ];
                            } else {
                                $title = $al['description'] ?? ($meta['title'] ?? 'Activity');
                                $desc = $title . ' for ' . ($student_name ?? 'Unknown Student');
                                $activities[] = [
                                    'type' => 'activity',
                                    'icon' => 'ℹ️',
                                    'title' => $title,
                                    'description' => $desc,
                                    'student_name' => $student_name,
                                    'student_id' => $al['student_id'] ?? null,
                                    'date' => $created,
                                    'timestamp' => !empty($created) ? strtotime($created) : time(),
                                    'from_activity_log' => true
                                ];
                            }
                        }
                    }
                }
            }
        }
    } catch (Throwable $e) {
        // ignore activity_log read errors
    }

    // Deduplicate activities: consider same when they share type, student_id and timestamp; prefer activity_log entries
    $map = [];
    foreach ($activities as $act) {
        $type = $act['type'] ?? 'x';
        $sid = isset($act['student_id']) ? (string)$act['student_id'] : '';
        $ts = isset($act['timestamp']) ? (int)$act['timestamp'] : (isset($act['date']) ? (is_numeric($act['date']) ? (int)$act['date'] : strtotime($act['date'])) : 0);
        $key = $type . '|' . $sid . '|' . $ts;
        if (isset($map[$key])) {
            $existing = $map[$key];
            $preferNew = false;
            if (!empty($act['from_activity_log']) && empty($existing['from_activity_log'])) {
                $preferNew = true;
            } elseif (empty($existing['from_activity_log']) && empty($act['from_activity_log'])) {
                if ($ts > (int)($existing['timestamp'] ?? 0)) $preferNew = true;
            }
            if ($preferNew) $map[$key] = $act;
        } else {
            $map[$key] = $act;
        }
    }

    // Rebuild activities array from dedup map and sort (newest first)
    $activities = array_values($map);
    usort($activities, function($a, $b) { return ($b['timestamp'] ?? 0) - ($a['timestamp'] ?? 0); });

    // Limit results
    return array_slice($activities, 0, $limit);
}

// Note: addActivity placeholder removed as unused; record_activity() is the canonical entry point

/**
 * Record an activity into the DB activity_log table when possible.
 */
function record_activity($type, $student_id = null, $description = '', $meta = []) {
    // If configured to synthesize activity only, skip writing to the activity_log table
    try {
        if (!defined('RECENT_ACTIVITY_MODE')) {
            $cfg = __DIR__ . '/config.php';
            if (file_exists($cfg)) require_once $cfg;
        }
        if (defined('RECENT_ACTIVITY_MODE') && RECENT_ACTIVITY_MODE === 'synthOnly') {
            return; // no-op
        }
    } catch (Throwable $_e) { /* ignore and continue best-effort */ }
    try {
        if (file_exists(__DIR__ . '/sqlite.php')) {
            require_once __DIR__ . '/sqlite.php';
            $pdo = get_db();
            $stmt = $pdo->prepare('INSERT INTO activity_log (type, student_id, user_id, description, metadata, created_at) VALUES (:type, :student_id, :user_id, :description, :metadata, :created_at)');
            $stmt->execute([
                ':type' => $type,
                ':student_id' => $student_id,
                ':user_id' => $_SESSION['user_id'] ?? null,
                ':description' => $description,
                ':metadata' => is_array($meta) ? json_encode($meta) : $meta,
                ':created_at' => date('c')
            ]);

            // Retain only the most recent 10 activity rows relevant to this user (and global rows with NULL user_id)
            try {
                $uid = $_SESSION['user_id'] ?? null;
                // Avoid aggressive locking while pruning
                try { $pdo->exec('PRAGMA busy_timeout = 3000'); } catch (Throwable $_e) {}
                $prune = $pdo->prepare(
                    "DELETE FROM activity_log 
                     WHERE (user_id = :uid OR user_id IS NULL)
                       AND id NOT IN (
                            SELECT id FROM activity_log 
                            WHERE (user_id = :uid2 OR user_id IS NULL)
                            ORDER BY created_at DESC
                            LIMIT :lim
                       )"
                );
                $prune->bindValue(':uid', $uid, is_null($uid) ? PDO::PARAM_NULL : PDO::PARAM_INT);
                $prune->bindValue(':uid2', $uid, is_null($uid) ? PDO::PARAM_NULL : PDO::PARAM_INT);
                $prune->bindValue(':lim', 10, PDO::PARAM_INT);
                $prune->execute();
            } catch (Throwable $_e) {
                // Non-fatal; if pruning fails due to lock, we'll try again on next insert
            }
        }
    } catch (Throwable $e) {
        // swallow errors to avoid breaking UI
    }
}
?>

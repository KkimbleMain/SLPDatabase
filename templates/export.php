<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Export - <?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></title>
    <link rel="stylesheet" href="/assets/css/common.css">
    <link rel="stylesheet" href="/assets/css/export.css">
</head>
<body>
    <div class="export-container">
        <div class="export-header">
            <h1>Export: <?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></h1>
            <div class="export-actions">
                <button class="btn btn-primary" onclick="window.print()">🖨️ Print</button>
                <button class="btn btn-secondary" onclick="savePDF()">📄 PDF</button>
                <button class="btn btn-outline" onclick="window.close(); history.back();">Close</button>
            </div>
        </div>

        <div class="student-overview">
            <div class="student-info">
                <h3>Student Information</h3>

                <div class="field">
                    <div class="label">Full Name</div>
                    <div class="value"><?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></div>
                </div>

                <div class="field">
                    <div class="label">Student ID</div>
                    <div class="value"><?php echo htmlspecialchars($student['student_id'] ?? $student['id']); ?></div>
                </div>

                <div class="field">
                    <div class="label">Grade</div>
                    <div class="value"><?php echo htmlspecialchars($student['grade'] ?? 'N/A'); ?></div>
                </div>

                <div class="field">
                    <div class="label">Date of Birth</div>
                    <div class="value"><?php echo htmlspecialchars($student['date_of_birth'] ?? 'N/A'); ?></div>
                </div>

                <div class="field">
                    <div class="label">Primary Language</div>
                    <div class="value"><?php echo htmlspecialchars($student['primary_language'] ?? 'English'); ?></div>
                </div>

                <div class="field">
                    <div class="label">Service Frequency</div>
                    <div class="value"><?php echo htmlspecialchars($student['service_frequency'] ?? 'N/A'); ?></div>
                </div>

                <!-- Teacher removed from export (field was removed from students schema) -->

                <div class="field">
                    <div class="label">Parent Contact</div>
                    <div class="value"><?php echo htmlspecialchars($student['parent_contact'] ?? 'N/A'); ?></div>
                </div>

                <?php if (!empty($student['medical_info'])): ?>
                <div class="field">
                    <div class="label">Medical Info</div>
                    <div class="value"><?php echo nl2br(htmlspecialchars($student['medical_info'])); ?></div>
                </div>
                <?php endif; ?>

            </div>
        </div>

        <!-- Documentation Section -->
        <?php if (!empty($student_docs) || !empty($student_goals)): ?>
        <div class="forms-section">
            <h2 class="section-title">📋 Documentation & Forms</h2>
            <div class="forms-grid">
                <?php
                    // Build a combined forms array that includes per-form documents and goals
                    $forms = [];
                    if (!empty($student_docs) && is_array($student_docs)) {
                        foreach ($student_docs as $d) {
                            // Normalize keys we expect
                            $forms[] = [
                                'title' => $d['title'] ?? ($d['form_type'] ?? 'Document'),
                                'form_type' => $d['form_type'] ?? null,
                                'form_data' => $d['form_data'] ?? null,
                                'created_at' => $d['created_at'] ?? null,
                                'file_path' => $d['file_path'] ?? null
                            ];
                        }
                    }

                    // Include goals as a grouped set of entries so they appear in the canonical order
                    if (!empty($student_goals) && is_array($student_goals)) {
                        foreach ($student_goals as $g) {
                            $gTitle = $g['goal_area'] ?? ($g['title'] ?? ($g['goal_text'] ?? 'Goal'));
                            $forms[] = [
                                'title' => $gTitle,
                                'form_type' => 'goals',
                                'form_data' => $g,
                                'created_at' => $g['created_at'] ?? null
                            ];
                        }
                    }

                    // Ensure consistent ordering using canonical group order: initial, goals, sessions, other, discharge.
                    $order = ['initial_evaluation','goals','session_report','other_documents','discharge_report'];
                    $buckets = array_fill_keys($order, []);
                    foreach ($forms as $f) {
                        $ft = strtolower(trim((string)($f['form_type'] ?? '')));
                        if (in_array($ft, ['initial_evaluation','initial_profile'])) $key = 'initial_evaluation';
                        elseif ($ft === 'goals' || strpos($ft, 'goal') !== false) $key = 'goals';
                        elseif (strpos($ft, 'session') !== false || $ft === 'session_report') $key = 'session_report';
                        elseif (strpos($ft, 'discharge') !== false || $ft === 'discharge_report') $key = 'discharge_report';
                        else $key = 'other_documents';
                        $buckets[$key][] = $f;
                    }
                    $merged = [];
                    foreach ($order as $k) {
                        usort($buckets[$k], function($a, $b) {
                            $ta = isset($a['created_at']) ? strtotime($a['created_at']) : 0;
                            $tb = isset($b['created_at']) ? strtotime($b['created_at']) : 0;
                            return $tb <=> $ta;
                        });
                        $merged = array_merge($merged, $buckets[$k]);
                    }
                    $forms = $merged;
                ?>

                <?php foreach ($forms as $doc): ?>
                <div class="form-item">
                    <div class="form-info">
                        <div class="form-title">
                            <?php echo htmlspecialchars($doc['title'] ?? str_replace('_', ' ', ucwords($doc['form_type'] ?? 'Document'))); ?>
                        </div>
                        <div class="form-meta">
                            Type: <?php echo htmlspecialchars(str_replace('_', ' ', ucwords($doc['form_type'] ?? 'Unknown'))); ?>
                            <?php if (!empty($doc['form_data']) && is_array($doc['form_data'])): ?>
                            • <?php echo count(array_filter($doc['form_data'], function($v) { return !empty($v) && $v !== 'studentName'; })); ?> fields completed
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="form-date">
                        <?php echo date('M j, Y', strtotime($doc['created_at'] ?? 'now')); ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php else: ?>
        <div class="forms-section">
            <h2 class="section-title">📋 Documentation & Forms</h2>
            <div style="text-align: center; padding: 2rem; color: var(--muted); font-style: italic; background: var(--light-bg); border-radius: var(--border-radius); border: 1px solid var(--border-color);">
                No documents found for this student.
            </div>
        </div>
        <?php endif; ?>

        <!-- Goals Section -->
        <?php if (!empty($student_goals)): ?>
        <div class="goals-section">
            <h2 class="section-title">🎯 Goals</h2>
            <?php foreach ($student_goals as $goal): ?>
            <div class="goal-item">
                <h4><?php echo htmlspecialchars($goal['goal_area'] ?? 'General Goal'); ?></h4>
                <p><?php echo htmlspecialchars($goal['goal_text'] ?? $goal['description'] ?? ''); ?></p>
                <small>
                    Target: <?php echo htmlspecialchars($goal['target_score'] ?? 'N/A'); ?>% • 
                    Status: <?php echo htmlspecialchars(ucfirst($goal['status'] ?? 'active')); ?> • 
                    Created: <?php echo date('M j, Y', strtotime($goal['created_at'] ?? 'now')); ?>
                </small>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="goals-section">
            <h2 class="section-title">🎯 Goals</h2>
            <div style="text-align: center; padding: 2rem; color: var(--muted); font-style: italic; background: var(--light-bg); border-radius: var(--border-radius); border: 1px solid var(--border-color);">
                No goals set for this student yet.
            </div>
        </div>
        <?php endif; ?>

        <!-- Progress Reports Section removed (feature deprecated) -->

        <div class="export-footer">
            Generated on <?php echo date('F j, Y \a\t g:i A'); ?> • SLP Database Export
        </div>
    </div>

    <script>
    function savePDF() {
        // Hide export actions for cleaner PDF
        const actions = document.querySelector('.export-actions');
        if (actions) actions.style.display = 'none';
        
        // Trigger print dialog (user can save as PDF)
        window.print();
        
        // Restore actions after print dialog
        setTimeout(() => {
            if (actions) actions.style.display = 'flex';
        }, 1000);
    }
    </script>
</body>
</html>

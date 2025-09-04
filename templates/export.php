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

                <div class="field">
                    <div class="label">Teacher</div>
                    <div class="value"><?php echo htmlspecialchars($student['teacher'] ?? 'N/A'); ?></div>
                </div>

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
        <?php if (!empty($student_docs)): ?>
        <div class="forms-section">
            <h2 class="section-title">📋 Documentation & Forms</h2>
            <div class="forms-grid">
                <?php foreach ($student_docs as $doc): ?>
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

        <!-- Progress Reports Section -->
        <?php if (!empty($student_progress)): ?>
        <div class="progress-section">
            <h2 class="section-title">📈 Progress Reports</h2>
            <?php 
            // Sort progress by date (newest first)
            usort($student_progress, function($a, $b) {
                return strtotime($b['date_recorded'] ?? $b['created_at'] ?? 'now') - strtotime($a['date_recorded'] ?? $a['created_at'] ?? 'now');
            });
            foreach (array_slice($student_progress, 0, 10) as $progress): 
            ?>
            <div class="progress-item">
                <h4>
                    <?php echo date('M j, Y', strtotime($progress['date_recorded'] ?? $progress['created_at'] ?? 'now')); ?>
                    <?php if (!empty($progress['score'])): ?>
                    <span style="float: right; color: var(--info-color); font-weight: bold; font-size: 1rem;"><?php echo $progress['score']; ?>%</span>
                    <?php endif; ?>
                </h4>
                <?php if (!empty($progress['goal_area'])): ?>
                <p style="margin: 0.25rem 0; font-weight: 500; color: var(--accent-color);">
                    Goal Area: <?php echo htmlspecialchars($progress['goal_area']); ?>
                </p>
                <?php endif; ?>
                <?php if (!empty($progress['notes'])): ?>
                <p><?php echo htmlspecialchars($progress['notes']); ?></p>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
            <?php if (count($student_progress) > 10): ?>
            <div style="text-align: center; padding: 1rem; color: var(--muted); font-style: italic; background: var(--light-bg); border-radius: var(--border-radius); margin-top: 1rem;">
                ... and <?php echo count($student_progress) - 10; ?> more progress reports
            </div>
            <?php endif; ?>
        </div>
        <?php else: ?>
        <div class="progress-section">
            <h2 class="section-title">📈 Progress Reports</h2>
            <div style="text-align: center; padding: 2rem; color: var(--muted); font-style: italic; background: var(--light-bg); border-radius: var(--border-radius); border: 1px solid var(--border-color);">
                No progress reports recorded for this student yet.
            </div>
        </div>
        <?php endif; ?>

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

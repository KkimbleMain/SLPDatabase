<?php
// templates/student_profile.php
// Student profile view showing detailed information
?>

<div class="container profile">
    <div class="page-header stack">
        <div class="header-content">
            <button class="btn btn-outline" onclick="history.back()">← Back to Students</button>
            <div class="student-title">
                <div class="avatar-circle large"><?php echo strtoupper(substr($profileStudent['first_name'], 0, 1) . substr($profileStudent['last_name'], 0, 1)); ?></div>
                <div>
                    <h1><?php echo htmlspecialchars($profileStudent['first_name'] . ' ' . $profileStudent['last_name']); ?></h1>
                    <p class="student-subtitle">Grade <?php echo htmlspecialchars($profileStudent['grade'] ?? 'Unknown'); ?> • Student ID: <?php echo htmlspecialchars($profileStudent['student_id'] ?? $profileStudent['id']); ?></p>
                </div>
            </div>
        </div>
    </div>

    <div class="profile-content">
        <div class="profile-grid">
            <!-- Basic Information Section -->
            <div class="profile-section">
                <h2 class="section-title">Basic Information</h2>
                <div class="info-grid">
                    <div class="info-item">
                        <label>Full Name:</label>
                        <span><?php echo htmlspecialchars($profileStudent['first_name'] . ' ' . $profileStudent['last_name']); ?></span>
                    </div>
                    <div class="info-item">
                        <label>Date of Birth:</label>
                        <span><?php echo htmlspecialchars($profileStudent['date_of_birth'] ?? 'Not specified'); ?></span>
                    </div>
                    <div class="info-item">
                        <label>Grade:</label>
                        <span><?php echo htmlspecialchars($profileStudent['grade'] ?? 'Not specified'); ?></span>
                    </div>
                    <div class="info-item">
                        <label>Gender:</label>
                        <span><?php echo htmlspecialchars($profileStudent['gender'] ?? 'Not specified'); ?></span>
                    </div>
                    <div class="info-item">
                        <label>Primary Language:</label>
                        <span><?php echo htmlspecialchars($profileStudent['primary_language'] ?? 'Not specified'); ?></span>
                    </div>
                    <div class="info-item">
                        <label>Service Frequency:</label>
                        <span><?php echo htmlspecialchars($profileStudent['service_frequency'] ?? 'Not specified'); ?></span>
                    </div>
                </div>
            </div>

            <!-- Contact & School Information -->
            <div class="profile-section">
                <h2 class="section-title">Contact & School Information</h2>
                <div class="info-grid">
                    <div class="info-item">
                        <label>Teacher:</label>
                        <span><?php echo htmlspecialchars($profileStudent['teacher'] ?? 'Not specified'); ?></span>
                    </div>
                    <div class="info-item">
                        <label>Parent Contact:</label>
                        <span><?php echo htmlspecialchars($profileStudent['parent_contact'] ?? 'Not specified'); ?></span>
                    </div>
                    <div class="info-item">
                        <label>Assigned Therapist:</label>
                            <?php
                            // Prefer the explicitly stored therapist name on the student record.
                            $assignedName = trim($profileStudent['assigned_therapist_name'] ?? '');

                            // If not present, fall back to resolving by user id (legacy behavior)
                            if (empty($assignedName)) {
                                $assignedTherapistId = $profileStudent['assigned_therapist'] ?? null;
                                if (!empty($assignedTherapistId)) {
                                    require_once __DIR__ . '/../includes/sqlite.php';
                                    try {
                                        $pdo = get_db();
                                        $all_users = $pdo->query('SELECT id, username, first_name, last_name FROM users ORDER BY first_name, last_name')->fetchAll(PDO::FETCH_ASSOC);
                                    } catch (Throwable $e) {
                                        $all_users = [];
                                    }
                                    foreach ($all_users as $u) {
                                        if ((int)($u['id'] ?? 0) === (int)$assignedTherapistId) {
                                            $assignedName = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''));
                                            break;
                                        }
                                    }
                                }
                            }

                            // Last fallback: current logged-in user's name (if any)
                            if (empty($assignedName)) $assignedName = trim(($user_data['first_name'] ?? '') . ' ' . ($user_data['last_name'] ?? ''));
                            ?>
                            <span><?php echo htmlspecialchars($assignedName ?: 'Unassigned'); ?></span>
                    </div>
                </div>
            </div>

            <!-- Medical Information -->
            <?php if (!empty($profileStudent['medical_info'])): ?>
            <div class="profile-section full-width">
                <h2 class="section-title">Medical Information</h2>
                <div class="info-content">
                    <?php echo nl2br(htmlspecialchars($profileStudent['medical_info'])); ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Goals Summary -->
            <div class="profile-section">
                <h2 class="section-title">Goals Summary</h2>
                <?php 
                $student_goals = array_filter($goals, function($goal) use ($profileStudent) {
                    return (int)($goal['student_id'] ?? 0) === (int)($profileStudent['id'] ?? 0) && ($goal['status'] ?? 'active') === 'active';
                });
                ?>
                <div class="goals-summary">
                    <div class="stat-item">
                        <span class="stat-number"><?php echo count($student_goals); ?></span>
                        <span class="stat-label">Active Goals</span>
                    </div>
                </div>
                <?php if (!empty($student_goals)): ?>
                <div class="goals-list">
                    <?php foreach (array_slice($student_goals, 0, 3) as $goal): ?>
                    <div class="goal-item">
                        <strong><?php echo htmlspecialchars($goal['goal_area'] ?? 'General'); ?></strong>
                        <p><?php echo htmlspecialchars(substr($goal['goal_text'] ?? $goal['description'] ?? '', 0, 100) . (strlen($goal['goal_text'] ?? $goal['description'] ?? '') > 100 ? '...' : '')); ?></p>
                    </div>
                    <?php endforeach; ?>
                    <?php if (count($student_goals) > 3): ?>
                    <p class="more-goals">And <?php echo count($student_goals) - 3; ?> more goals...</p>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>

            <!-- Progress Summary -->
            <div class="profile-section">
                <h2 class="section-title">Progress Summary</h2>
                <?php 
                $student_progress = array_filter($progress_updates, function($update) use ($profileStudent) {
                    return (int)($update['student_id'] ?? 0) === (int)($profileStudent['id'] ?? 0);
                });
                ?>
                <div class="progress-summary">
                    <div class="stat-item">
                        <span class="stat-number"><?php echo count($student_progress); ?></span>
                        <span class="stat-label">Progress Reports</span>
                    </div>
                </div>
                <?php if (!empty($student_progress)): ?>
                <?php 
                $recent_progress = array_slice(array_reverse($student_progress), 0, 3);
                ?>
                <div class="progress-list">
                    <?php foreach ($recent_progress as $progress): ?>
                    <div class="progress-item">
                        <div class="progress-date"><?php echo date('M j, Y', strtotime($progress['date_recorded'] ?? $progress['created_at'] ?? 'now')); ?></div>
                        <?php if (!empty($progress['notes'])): ?>
                        <p><?php echo htmlspecialchars(substr($progress['notes'], 0, 100) . (strlen($progress['notes']) > 100 ? '...' : '')); ?></p>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>

            <!-- Documentation -->
            <div class="profile-section full-width">
                <h2 class="section-title">Documentation</h2>
                <?php if (!empty($studentForms)): ?>
                <div class="documentation-list">
                    <?php foreach (array_slice($studentForms, 0, 5) as $form): ?>
                    <div class="doc-item">
                        <div class="doc-header">
                            <strong><?php echo htmlspecialchars($form['title'] ?? str_replace('_', ' ', ucwords($form['form_type'] ?? 'Document'))); ?></strong>
                            <span class="doc-date"><?php echo date('M j, Y', strtotime($form['created_at'] ?? 'now')); ?></span>
                        </div>
                        <?php if (!empty($form['form_data']) && is_array($form['form_data'])): ?>
                        <div class="doc-preview">
                            <?php 
                            $preview = '';
                            foreach ($form['form_data'] as $key => $value) {
                                if ($key !== 'studentName' && !empty($value) && strlen($preview) < 150) {
                                    $preview .= $value . ' ';
                                }
                            }
                            echo htmlspecialchars(substr($preview, 0, 150) . (strlen($preview) > 150 ? '...' : ''));
                            ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                    <?php if (count($studentForms) > 5): ?>
                    <p class="more-docs">And <?php echo count($studentForms) - 5; ?> more documents...</p>
                    <?php endif; ?>
                </div>
                <?php else: ?>
                <p class="no-content">No documentation available for this student yet.</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Action Buttons -->
        <div class="profile-actions">
            <button class="btn btn-outline" onclick="editStudentProfile(<?php echo $profileStudent['id']; ?>)">Edit Profile</button>
        </div>
    </div>
</div>

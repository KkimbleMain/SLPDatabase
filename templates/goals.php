<?php
// templates/goals.php
$student_id = $_GET['student_id'] ?? 0;
$selected_student = null;
if ($student_id) {
    $selected_student = findRecord('students', 'id', $student_id);
}
// ensure $user_students is available (index.php usually provides it)
$user_students = $user_students ?? findRecords('students', ['assigned_therapist' => $_SESSION['user_id'] ?? null]);
if (!is_array($user_students)) $user_students = [];

// Get goals for selected student
$student_goals = findRecords('goals', ['student_id' => $student_id]);
?>

<div class="container">
    <div class="page-header stack">
    <h2>Goals Management</h2>
    <?php if ($selected_student): ?>
        <h3><?php echo htmlspecialchars($selected_student['first_name'] . ' ' . $selected_student['last_name']); ?></h3>
    <?php endif; ?>
    <button class="btn btn-primary primary-action-left" data-open-modal="tmpl-add-goal">Add New Goal</button>
</div>

<?php if (!$selected_student): ?>
    <div class="student-selector">
        <label>Select Student:</label>
        <select onchange="window.location.href='?view=goals&student_id='+this.value">
            <option value="">Choose a student...</option>
            <?php foreach ($user_students as $student): ?>
                <option value="<?php echo $student['id']; ?>"><?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></option>
            <?php endforeach; ?>
        </select>
    </div>
<?php else: ?>
    
    
    <div class="goals-list">
        <?php if (empty($student_goals)): ?>
                <div class="no-goals no-students">
                    <div class="no-students-icon">🎯</div>
                    <h3>No Goals Yet</h3>
                    <p>Create a goal to track student progress and milestones.</p>
                </div>
        <?php else: ?>
            <?php foreach ($student_goals as $goal): ?>
                <div class="goal-card">
                    <div class="goal-header">
                        <h4><?php echo ucfirst($goal['goal_area'] ?? ''); ?></h4>
                        <span class="goal-status status-<?php echo htmlspecialchars($goal['status'] ?? 'unknown'); ?>"><?php echo ucfirst($goal['status'] ?? 'unknown'); ?></span>
                    </div>
                    <p class="goal-text"><?php echo htmlspecialchars($goal['goal_text'] ?? $goal['description'] ?? ''); ?></p>
                    <div class="goal-progress">
                        <div class="progress-bar">
                            <?php $baseline = is_numeric($goal['baseline_score'] ?? null) ? $goal['baseline_score'] : 0; $target = max(1, (is_numeric($goal['target_score'] ?? null) ? $goal['target_score'] : 0)); $progress = ($baseline / $target) * 100; ?>
                            <div class="progress-fill" style="width: <?php echo min(max(0,$progress), 100); ?>%"></div>
                        </div>
                        <span><?php echo htmlspecialchars($baseline); ?>% / <?php echo htmlspecialchars($target); ?>%</span>
                    </div>
                    <div class="goal-actions">
                        <button class="btn btn-outline" data-open-modal="tmpl-add-progress" data-student-id="<?php echo htmlspecialchars($student_id); ?>" data-goal-id="<?php echo htmlspecialchars($goal['id']); ?>">Add Progress</button>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php
// simple listing of goals (minimal) — show only when no specific student is selected
if (!$selected_student) {
    $goals_list = findRecords('goals', ['therapist_id' => $_SESSION['user_id'] ?? null]);
    if (!is_array($goals_list)) $goals_list = [];
    ?>
    <section class="goals-list">
        <?php if (empty($goals_list)): ?>
            <div class="no-goals no-students">
                <div class="no-students-icon">🎯</div>
                <h3>No Goals Yet</h3>
                <p>Create a goal to keep track of progress across your caseload.</p>
            </div>
        <?php else: ?>
            <ul>
                <?php foreach ($goals_list as $g): ?>
                    <li><?php echo htmlspecialchars($g['description'] ?? ''); ?> — target: <?php echo htmlspecialchars($g['target_date'] ?? ''); ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
<?php } ?>

</div>

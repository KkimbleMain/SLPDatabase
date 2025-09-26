<?php
// templates/progress.php
$student_id = $_GET['student_id'] ?? 0;
$selected_student = null;
if ($student_id) {
    $selected_student = findRecord('students', 'id', $student_id);
}

// ensure $user_students is available (index.php usually provides it)
$user_students = $user_students ?? findRecords('students', ['assigned_therapist' => $_SESSION['user_id'] ?? null]);
if (!is_array($user_students)) $user_students = [];

// build list of student ids for filtering (avoid undefined variable)
$student_ids = array_map(function($s){ return (int)($s['id'] ?? 0); }, $user_students);

// Get progress data for selected student
$student_progress = [];
if ($student_id) {
    $student_progress = findRecords('progress_updates', ['student_id' => $student_id]);
} else {
    // optionally show recent for the therapist's students
    $all_progress = findRecords('progress_updates', []);
    $student_progress = array_filter($all_progress, function($p) use ($student_ids) {
        return in_array((int)($p['student_id'] ?? 0), $student_ids);
    });
}

// Sort by a normalized date field (prefer created_at, fallback to date_recorded)
usort($student_progress, function($a, $b) {
    $da = strtotime($a['created_at'] ?? $a['date_recorded'] ?? 0);
    $db = strtotime($b['created_at'] ?? $b['date_recorded'] ?? 0);
    return $db <=> $da;
});
?>

<div class="container">
<div class="page-header stack">
    <h2>Progress Tracker</h2>
    <?php if ($selected_student): ?>
        <h3><?php echo htmlspecialchars($selected_student['first_name'] . ' ' . $selected_student['last_name']); ?></h3>
    <?php endif; ?>
    <button class="btn btn-primary primary-action-left" onclick="showQuickProgressModal(<?php echo $selected_student['id'] ?? 0; ?>)">Add Progress Report</button>
</div>

<?php if (!$selected_student): ?>
    <div class="student-selector">
        <label>Select Student:</label>
        <select onchange="window.location.href='?view=progress&student_id='+this.value">
            <option value="">Choose a student...</option>
            <?php foreach ($user_students as $student): ?>
                <option value="<?php echo $student['id']; ?>"><?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></option>
            <?php endforeach; ?>
        </select>
    </div>
<?php else: ?>
    
    
    <div class="progress-overview">
        <?php if (empty($student_progress)): ?>
            <div class="no-progress">
                <div class="no-progress-icon">📊</div>
                <h3>No Progress Data Yet</h3>
                <p>Start tracking <?php echo htmlspecialchars($selected_student['first_name']); ?>'s therapy progress.</p>
            </div>
        <?php else: ?>
            <div class="progress-stats">
                <div class="stat-card">
                    <h4>Total Sessions</h4>
                    <div class="stat-number"><?php echo count($student_progress); ?></div>
                </div>
                <div class="stat-card">
                    <h4>Average Score</h4>
                    <?php
                    $avg_score = 0;
                    if (count($student_progress) > 0) {
                        $total = array_sum(array_column($student_progress, 'score'));
                        $avg_score = round($total / count($student_progress), 1);
                    }
                    ?>
                    <div class="stat-number"><?php echo $avg_score; ?>%</div>
                </div>
            </div>
            
            <div class="progress-chart-container">
                <canvas id="progressChart" width="400" height="200"></canvas>
            </div>
            
            <div class="progress-history">
                <h4>Recent Sessions</h4>
                <?php foreach ($student_progress as $update): ?>
                    <div class="progress-item">
                        <div class="progress-date"><?php echo date('M j, Y', strtotime($update['date_recorded'])); ?></div>
                        <div class="progress-details">
                            <strong><?php echo ucfirst($update['goal_area'] ?? ''); ?></strong>
                            <span class="progress-score"><?php echo htmlspecialchars($update['score'] ?? '0'); ?>%</span>
                            <?php if (!empty($update['notes'])): ?>
                                <p><?php echo htmlspecialchars($update['notes'] ?? ''); ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php
// minimal listing of recent progress
require_once __DIR__ . '/../includes/sqlite.php';
try {
    $pdo = get_db();
    $progress_list = $pdo->query('SELECT p.*, s.first_name, s.last_name FROM progress_updates p LEFT JOIN students s ON s.id = p.student_id ORDER BY p.created_at DESC')->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $progress_list = [];
}
// Later when filtering recent progress list from DB, reuse $student_ids
// (the code that queries DB at the bottom already assigns $progress_list)
$filtered = array_filter($progress_list, function($p) use ($student_ids) {
    return in_array((int)($p['student_id'] ?? 0), $student_ids);
});
?>
<section class="progress-list">
    <?php if (empty($filtered)): ?>
            <div class="no-progress no-students">
                <div class="no-students-icon">📈</div>
                <h3>No Progress Reports</h3>
                <p>Use the Add Progress button to create the first report.</p>
            </div>
    <?php else: ?>
        <ul>
            <?php foreach ($filtered as $u): ?>
                <li><?php echo htmlspecialchars(($u['date_recorded'] ?? '') . ' — ' . ($u['score'] ?? '')); ?>%</li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>

</div>

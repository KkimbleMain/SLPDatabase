<?php
// templates/progress.php
$student_id = $_GET['student_id'] ?? 0;
$selected_student = null;
if ($student_id) {
    $selected_student = findRecord('students', 'id', $student_id);
}

// ensure $user_students is available (index.php usually provides it)
$user_students = $user_students ?? [];
if (empty($user_students)) {
    try {
        // Try DB-backed lookup first
        if (file_exists(__DIR__ . '/../includes/sqlite.php')) {
            require_once __DIR__ . '/../includes/sqlite.php';
            if (function_exists('get_db')) {
                $pdo = get_db();
                $pi = $pdo->prepare("PRAGMA table_info('students')"); $pi->execute();
                $cols = array_column($pi->fetchAll(PDO::FETCH_ASSOC), 'name');
                $uid = $_SESSION['user_id'] ?? null;
                // try strict ownership first
                if (in_array('user_id', $cols) && $uid) {
                    $stmt = $pdo->prepare('SELECT * FROM students WHERE user_id = :uid AND (archived = 0 OR archived IS NULL) ORDER BY last_name, first_name');
                    $stmt->execute([':uid' => $uid]);
                    $user_students = $stmt->fetchAll(PDO::FETCH_ASSOC);
                } elseif (in_array('assigned_therapist', $cols) && $uid) {
                    $stmt = $pdo->prepare('SELECT * FROM students WHERE assigned_therapist = :uid AND (archived = 0 OR archived IS NULL) ORDER BY last_name, first_name');
                    $stmt->execute([':uid' => $uid]);
                    $user_students = $stmt->fetchAll(PDO::FETCH_ASSOC);
                } else {
                    // fallback: show none to non-admins to avoid leakage; index.php provides $user_students for admins
                    $user_students = [];
                }
            }
        }
    } catch (\Throwable $e) {
        $user_students = findRecords('students', ['assigned_therapist' => $_SESSION['user_id'] ?? null]);
    }
}
if (!is_array($user_students)) $user_students = [];

// build list of student ids for filtering (avoid undefined variable)
$student_ids = array_map(function($s){ return (int)($s['id'] ?? 0); }, $user_students);

// Progress reporting has been removed. No progress data will be displayed here.
$student_progress = [];
?>

<div class="container">
<div class="page-header stack">
    <h2>Progress Tracker</h2>
    <!-- client-managed student name (empty by default). JS will set/clear this to avoid server-side persistence -->
    <div id="progressStudentName" class="progress-student-name" aria-live="polite"></div>
        <?php $preIdAttr = isset($selected_student['id']) ? ' data-student-id="' . intval($selected_student['id']) . '"' : ''; ?>
        <?php $disabledAttr = isset($selected_student['id']) ? '' : 'disabled'; ?>
    <span id="reportIndicator" class="report-indicator" style="margin-left:12px;color:#666;font-size:0.95em"></span>
</div>
    
    <div class="progress-toolbar">
            <div class="toolbar-left toolbar-left-stacked">
                <?php // place primary action inside the toolbar so it remains aligned with toolbar/skills area ?>
                <button id="addProgressBtn" class="btn btn-primary primary-action-left" <?php echo $preIdAttr; ?> <?php echo $disabledAttr; ?>>Add Skill</button>
                <div class="toolbar-select-wrap">
                    <label for="progressStudentSelect">Select Student:</label>
                    <select id="progressStudentSelect">
                        <option value="">-- Select a student --</option>
                    <?php foreach ($user_students as $student): ?>
                        <option value="<?php echo $student['id']; ?>" <?php echo (isset($selected_student['id']) && $selected_student['id']==$student['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']) . ' - ' . htmlspecialchars($student['student_id'] ?? $student['id']); ?></option>
                    <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="toolbar-right">
                <button class="btn btn-primary" id="createReport" <?php echo $disabledAttr; ?>>Create Progress Update Report</button>
                <button class="btn btn-outline btn-danger" id="deleteReport" <?php echo $disabledAttr; ?> style="margin-left:8px;">Delete Report</button>
            </div>
    </div>


<?php if (!$selected_student): ?>
    <div class="no-student-selected">
        <div class="no-student-icon">👤</div>
        <h3>No Student Selected</h3>
        <p>Please select a student to view and manage their progress reports.</p>
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
                        <div class="progress-date"><?php echo date('Y-m-d H:i', strtotime($update['date_recorded'])); ?></div>
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
</div>

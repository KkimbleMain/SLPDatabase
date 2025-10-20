<?php
// templates/students.php
// This template reads students from the canonical datastore (SQLite when available).

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
// use the new sqlite helper instead of JSON
require_once __DIR__ . '/../includes/sqlite.php';

// Determine current user context
$currentUser = getCurrentUser();
$currentUserId = $currentUser['id'] ?? ($_SESSION['user_id'] ?? null);
// roles removed

// Load students from SQLite instead of students.json
try {
    $pdo = get_db();
		// Prefer strict ownership by user_id when available; otherwise return no students to avoid leakage
		$pi2 = $pdo->prepare("PRAGMA table_info('students')");
		$pi2->execute();
		$studentCols = array_column($pi2->fetchAll(PDO::FETCH_ASSOC), 'name');
		if (in_array('user_id', $studentCols)) {
			$stmt = $pdo->prepare('SELECT * FROM students WHERE user_id = :uid AND (archived = 0 OR archived IS NULL) ORDER BY last_name, first_name');
			$stmt->execute([':uid' => $currentUserId]);
			$user_students = $stmt->fetchAll(PDO::FETCH_ASSOC);
		} else if (in_array('assigned_therapist', $studentCols)) {
			// Fallback for very old schemas
			$stmt = $pdo->prepare('SELECT * FROM students WHERE assigned_therapist = :uid AND (archived = 0 OR archived IS NULL) ORDER BY last_name, first_name');
			$stmt->execute([':uid' => $currentUserId]);
			$user_students = $stmt->fetchAll(PDO::FETCH_ASSOC);
		} else {
			$user_students = [];
		}
} catch (Exception $e) {
    // fallback to empty array so template still renders
    $user_students = [];
}

// Check if we're viewing a profile
$action = $_GET['action'] ?? '';
$profileStudentId = isset($_GET['id']) ? (int)$_GET['id'] : null;

if (($action === 'profile' || $action === 'edit') && $profileStudentId) {
	// Fetch the requested student directly from the DB so profiles render regardless of filters
	try {
		$pdo = get_db();
	// Enforce ownership when user_id column exists
		$pi = $pdo->prepare("PRAGMA table_info('students')");
		$pi->execute();
		$cols = array_column($pi->fetchAll(PDO::FETCH_ASSOC), 'name');
		if (in_array('user_id', $cols)) {
			$stmt = $pdo->prepare('SELECT * FROM students WHERE id = :id AND user_id = :uid LIMIT 1');
			$stmt->execute([':id' => $profileStudentId, ':uid' => $currentUserId]);
		} else {
			$stmt = $pdo->prepare('SELECT * FROM students WHERE id = :id LIMIT 1');
			$stmt->execute([':id' => $profileStudentId]);
		}
		$profileStudent = $stmt->fetch(PDO::FETCH_ASSOC);
	} catch (Exception $e) {
		$profileStudent = null;
	}

	if ($profileStudent) {
		// Load student's forms from the documents table (DB-backed)
		$studentForms = [];
		try {
			$stmt = $pdo->prepare('SELECT id, title, filename, metadata, content, created_at FROM documents WHERE student_id = :sid ORDER BY created_at DESC');
			$stmt->execute([':sid' => $profileStudentId]);
			$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
			foreach ($rows as $r) {
				if (!empty($r['content'])) {
					$json = json_decode($r['content'], true);
					if ($json) { $studentForms[] = $json; continue; }
				}
				// fallback: try decode metadata/title
				$studentForms[] = ['id' => $r['id'], 'title' => $r['title'], 'created_at' => $r['created_at']];
			}
		} catch (Exception $e) {
			$studentForms = [];
		}

		// Display profile view. Editing is handled client-side via the edit modal template.
		include 'student_profile.php';
		return;
	}
}

// Group students by grade
$students_by_grade = [];
foreach ($user_students as $student) {
    if (!empty($student['archived'])) continue; // skip archived students
    $grade = $student['grade'] ?? 'Unknown';
    if (!isset($students_by_grade[$grade])) {
        $students_by_grade[$grade] = [];
    }
    $students_by_grade[$grade][] = $student;
}

// Sort grades
ksort($students_by_grade, SORT_NATURAL);
?>
<div class="container">
	<div class="page-header stack">
		<h1>My Students</h1>
		<div class="actions-row" style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center;">
			<button class="btn btn-primary primary-action-left" type="button" onclick="try{ if(typeof showAddStudentModal === 'function'){ showAddStudentModal(); } else { const tpl = document.getElementById('tmpl-add-student'); if(tpl){ const clone = tpl.content.cloneNode(true); const container = document.createElement('div'); container.appendChild(clone); insertModal(container); } } }catch(e){ console.error(e); }">Add New Student</button>

		</div>
	</div>
    
	<div class="students-controls">
		<div class="search-bar">
			<input type="text" placeholder="Search students..." id="studentSearch">
		</div>
		<div class="filter-controls">
			<select id="gradeFilter">
				<option value="">All Grades</option>
				<option value="K">Kindergarten</option>
				<option value="1">Grade 1</option>
				<option value="2">Grade 2</option>
				<option value="3">Grade 3</option>
				<option value="4">Grade 4</option>
				<option value="5">Grade 5</option>
				<option value="6">Grade 6</option>
				<option value="7">Grade 7</option>
				<option value="8">Grade 8</option>
				<option value="9">Grade 9</option>
				<option value="10">Grade 10</option>
				<option value="11">Grade 11</option>
				<option value="12">Grade 12</option>
			</select>
		</div>
	</div>

    
	<div class="students-by-grade">
		<?php if (empty($user_students)): ?>
			<div class="no-students">
				<div class="no-students-icon">👥</div>
				<h3>No Students Yet</h3>
				<p>Start by adding your first student to begin tracking their progress.</p>
			</div>
		<?php else: ?>
			<?php foreach ($students_by_grade as $grade => $grade_students): ?>
				<div class="grade-section" data-grade="<?php echo htmlspecialchars($grade); ?>">
					<h2 class="grade-header">
						<?php 
							if ($grade === 'K') echo 'Kindergarten';
							elseif ($grade === 'Unknown') echo 'Grade Not Specified';
							else echo 'Grade ' . htmlspecialchars($grade);
						?>
						<span class="student-count">(<?php echo count($grade_students); ?> student<?php echo count($grade_students) !== 1 ? 's' : ''; ?>)</span>
					</h2>
					
					<div class="grade-students">
						<?php foreach ($grade_students as $student): ?>
							<?php
								$student_goals = 0;
								$student_reports = 0; // progress reporting removed
								foreach ($goals as $goal) {
									if ($goal['student_id'] == $student['id']) $student_goals++;
								}
							?>
							<div class="student-row" data-student-id="<?php echo $student['id']; ?>">
								<div class="student-main" onclick="toggleStudentDetails(<?php echo $student['id']; ?>)">
									<div class="student-info">
										<div class="avatar-circle"><?php echo strtoupper(substr($student['first_name'], 0, 1) . substr($student['last_name'], 0, 1)); ?></div>
										<div class="student-basic">
											<div class="student-name"><?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></div>
                                            <div class="student-meta">
                                                <span class="student-id">ID: <span class="id-value"><?php echo htmlspecialchars($student['student_id'] ?? $student['id']); ?></span></span>
                                                <span class="meta-sep">•</span>
                                                <?php echo $student_goals; ?> goals • <?php echo $student_reports; ?> reports
                                            </div>
										</div>
									</div>
									<div class="expand-indicator">
										<span class="expand-arrow">▶</span>
									</div>
								</div>
								
								<div class="student-details" id="student-details-<?php echo $student['id']; ?>">
									<div class="student-details-content">
										<div class="student-stats">
											<div class="stat-item">
												<span class="stat-label">Primary Language:</span>
												<span class="stat-value"><?php echo htmlspecialchars($student['primary_language'] ?? 'Not specified'); ?></span>
											</div>
											<div class="stat-item">
												<span class="stat-label">Service Frequency:</span>
												<span class="stat-value"><?php echo htmlspecialchars($student['service_frequency'] ?? 'Not specified'); ?></span>
											</div>
										</div>
										
										<div class="student-actions">
											<a class="btn btn-outline" href="?view=students&action=profile&id=<?php echo $student['id']; ?>">Profile</a>
											<a class="btn btn-secondary" href="?view=documentation&student_id=<?php echo $student['id']; ?>">Documentation</a>
											<button class="btn btn-secondary" data-open-progress="" data-student-id="<?php echo $student['id']; ?>">Progress</button>
											<button class="btn btn-outline" onclick="exportStudent(<?php echo $student['id']; ?>)">Cumulative record</button>
											<button class="btn btn-danger" onclick="archiveStudent(<?php echo $student['id']; ?>)">Archive</button>
										</div>
									</div>
								</div>
							</div>
						<?php endforeach; ?>
					</div>
				</div>
			<?php endforeach; ?>
		<?php endif; ?>
	</div>
</div>

<?php
// templates/settings.php
// Comprehensive settings UI with profile, data management, archived students, and system preferences

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/sqlite.php';

// Load canonical students and compute archived count per current user
try {
	$pdo = get_db();
	$uid = $_SESSION['user_id'] ?? null;
	try { $pi = $pdo->prepare("PRAGMA table_info('students')"); $pi->execute(); $scols = array_column($pi->fetchAll(PDO::FETCH_ASSOC),'name'); } catch (Throwable $_e) { $scols = []; }
	if ($uid && in_array('user_id',$scols)) {
		$st = $pdo->prepare('SELECT id, student_id, first_name, last_name, archived FROM students WHERE user_id = :uid ORDER BY last_name, first_name');
		$st->execute([':uid'=>$uid]);
		$all_students = $st->fetchAll(PDO::FETCH_ASSOC);
	} else if ($uid && in_array('assigned_therapist',$scols)) {
		$st = $pdo->prepare('SELECT id, student_id, first_name, last_name, archived FROM students WHERE assigned_therapist = :uid ORDER BY last_name, first_name');
		$st->execute([':uid'=>$uid]);
		$all_students = $st->fetchAll(PDO::FETCH_ASSOC);
	} else {
		// No ownership column available or no user: return empty to avoid leakage
		$all_students = [];
	}
} catch (Throwable $e) {
    $all_students = [];
}

// Get archived count from DB (fallback to 0 on error)
$archivedCount = 0;
try {
	$pdo = get_db();
	$uid = $_SESSION['user_id'] ?? null;
	try { $pi = $pdo->prepare("PRAGMA table_info('students')"); $pi->execute(); $scols = array_column($pi->fetchAll(PDO::FETCH_ASSOC),'name'); } catch (Throwable $_e) { $scols = []; }
	if ($uid && in_array('user_id',$scols)) {
		$st = $pdo->prepare('SELECT COUNT(*) FROM students WHERE archived = 1 AND user_id = :uid'); $st->execute([':uid'=>$uid]);
		$archivedCount = (int)$st->fetchColumn();
	} else if ($uid && in_array('assigned_therapist',$scols)) {
		$st = $pdo->prepare('SELECT COUNT(*) FROM students WHERE archived = 1 AND assigned_therapist = :uid'); $st->execute([':uid'=>$uid]);
		$archivedCount = (int)$st->fetchColumn();
	} else {
		$archivedCount = 0; // no scope available -> show 0 to avoid cross-user data
	}
} catch (Exception $e) {
    $archivedCount = 0;
}

// Compute total document count from DB, scoped to current user when possible
$totalDocs = 0;
try {
	$pdo = get_db();
	$uid = $_SESSION['user_id'] ?? null;
	$totalDocs = 0;
	if ($uid) {
		// If documents has student_id, join to students for ownership; otherwise try documents.user_id when present
		try {
			$tiD = $pdo->prepare("PRAGMA table_info('documents')"); $tiD->execute(); $dcols = array_column($tiD->fetchAll(PDO::FETCH_ASSOC),'name');
			$tiS = $pdo->prepare("PRAGMA table_info('students')"); $tiS->execute(); $scols2 = array_column($tiS->fetchAll(PDO::FETCH_ASSOC),'name');
			if (in_array('student_id',$dcols) && (in_array('user_id',$scols2) || in_array('assigned_therapist',$scols2))) {
				$where = in_array('user_id',$scols2) ? 's.user_id = :uid' : 's.assigned_therapist = :uid';
				$st = $pdo->prepare('SELECT COUNT(*) FROM documents d JOIN students s ON d.student_id = s.id WHERE ' . $where);
				$st->execute([':uid'=>$uid]); $totalDocs = (int)$st->fetchColumn();
			} else if (in_array('user_id',$dcols)) {
				$st = $pdo->prepare('SELECT COUNT(*) FROM documents WHERE user_id = :uid'); $st->execute([':uid'=>$uid]); $totalDocs = (int)$st->fetchColumn();
			}
		} catch (Throwable $_e) { $totalDocs = 0; }
	}
} catch (Exception $e) {
	$totalDocs = 0;
}
?>
<div class="container">
	<div class="page-header stack">
		<h1>Settings</h1>
		<p class="page-subtitle">Manage your account, data, and application preferences</p>
	</div>

	<div class="settings-grid">
		<!-- Profile Settings -->
		<div class="settings-section">
			<div class="section-header">
				<h3>Profile Settings</h3>
				<p class="muted">Manage your personal information and account settings</p>
			</div>
			<div class="setting-item">
				<div class="setting-info">
					<label>Display Name</label>
					<span id="displayNameValue" class="setting-value"><?php echo htmlspecialchars($user_data['first_name'] . ' ' . $user_data['last_name']); ?></span>
				</div>
				<button class="btn btn-outline" onclick="(window.editProfile ? window.editProfile() : alert('Profile editing not available'))">Edit</button>
			</div>
			<div class="setting-item">
				<div class="setting-info">
					<label>Username</label>
					<span class="setting-value"><?php echo htmlspecialchars($user_data['username'] ?? 'N/A'); ?></span>
				</div>
				<span class="muted">Cannot be changed</span>
			</div>
			<div class="setting-item">
				<div class="setting-info">
					<label>Password</label>
					<span class="setting-value">••••••••</span>
				</div>
				<button class="btn btn-outline" onclick="(window.changePassword ? window.changePassword() : alert('Password change not available'))">Change</button>
			</div>
		</div>

	<!-- Profile edit modal -->
	<div id="profileModal" class="modal" style="display:none;">
		<div class="modal-content">
			<div class="modal-header">
				<h2>Edit Profile</h2>
				<button class="close" type="button" onclick="(window.hideModalById ? window.hideModalById('profileModal') : (function(){ const m=document.getElementById('profileModal'); if(m) m.style.display='none'; })())">&times;</button>
			</div>
			<div class="modal-body">
				<div class="form-group">
					<label for="profileFirstName">First name</label>
					<input id="profileFirstName" name="first_name" type="text" required />
				</div>
				<div class="form-group">
					<label for="profileLastName">Last name</label>
					<input id="profileLastName" name="last_name" type="text" required />
				</div>
			</div>
			<div class="modal-actions">
				<button class="btn btn-outline" type="button" onclick="(window.hideModalById ? window.hideModalById('profileModal') : (function(){ const m=document.getElementById('profileModal'); if(m) m.style.display='none'; })())">Cancel</button>
				<button class="btn btn-primary" type="button" id="saveProfileBtn">Save</button>
			</div>
		</div>
	</div>

	<!-- Change password modal -->
	<div id="changePasswordModal" class="modal" style="display:none;">
		<div class="modal-content">
			<div class="modal-header">
				<h2>Change Password</h2>
				<button class="close" type="button" onclick="(window.hideModalById ? window.hideModalById('changePasswordModal') : (function(){ const m=document.getElementById('changePasswordModal'); if(m) m.style.display='none'; })())">&times;</button>
			</div>
			<div class="modal-body">
				<div class="form-group">
					<label for="currentPassword">Current password</label>
					<input id="currentPassword" name="current_password" type="password" required />
				</div>
				<div class="form-group">
					<label for="newPassword">New password</label>
					<input id="newPassword" name="new_password" type="password" required />
				</div>
				<div class="form-group">
					<label for="confirmPassword">Confirm new password</label>
					<input id="confirmPassword" name="confirm_password" type="password" required />
				</div>
			</div>
			<div class="modal-actions">
				<button class="btn btn-outline" type="button" onclick="(window.hideModalById ? window.hideModalById('changePasswordModal') : (function(){ const m=document.getElementById('changePasswordModal'); if(m) m.style.display='none'; })())">Cancel</button>
				<button class="btn btn-primary" type="button" id="savePasswordBtn">Change Password</button>
			</div>
		</div>
	</div>

		<!-- Data Management -->
		<div class="settings-section">
			<div class="section-header">
				<h3>Data</h3>
				<p class="muted">Quick raw counts from the database (read-only)</p>
			</div>
			<?php
			// Compute counts defensively – some tables may not exist on older installs
			try {
				$pdo = get_db();
				$uid = $_SESSION['user_id'] ?? null;
				try { $pi = $pdo->prepare("PRAGMA table_info('students')"); $pi->execute(); $scols = array_column($pi->fetchAll(PDO::FETCH_ASSOC),'name'); } catch (Throwable $_e) { $scols = []; }
				// Students: always scope to current user
				$total_students = $active_students = $archived_students = 0;
				if ($uid && in_array('user_id',$scols)) {
					$st = $pdo->prepare('SELECT COUNT(*) FROM students WHERE user_id = :uid'); $st->execute([':uid'=>$uid]); $total_students = (int)$st->fetchColumn();
					$st = $pdo->prepare('SELECT COUNT(*) FROM students WHERE (archived = 0 OR archived IS NULL) AND user_id = :uid'); $st->execute([':uid'=>$uid]); $active_students = (int)$st->fetchColumn();
					$st = $pdo->prepare('SELECT COUNT(*) FROM students WHERE archived = 1 AND user_id = :uid'); $st->execute([':uid'=>$uid]); $archived_students = (int)$st->fetchColumn();
				} else if ($uid && in_array('assigned_therapist',$scols)) {
					$st = $pdo->prepare('SELECT COUNT(*) FROM students WHERE assigned_therapist = :uid'); $st->execute([':uid'=>$uid]); $total_students = (int)$st->fetchColumn();
					$st = $pdo->prepare('SELECT COUNT(*) FROM students WHERE (archived = 0 OR archived IS NULL) AND assigned_therapist = :uid'); $st->execute([':uid'=>$uid]); $active_students = (int)$st->fetchColumn();
					$st = $pdo->prepare('SELECT COUNT(*) FROM students WHERE archived = 1 AND assigned_therapist = :uid'); $st->execute([':uid'=>$uid]); $archived_students = (int)$st->fetchColumn();
				}

				// document-type counts (join to students for ownership)
				$cnt_goals = $cnt_initial = $cnt_session = $cnt_discharge = $cnt_other = 0; $cnt_orphan_other = 0;
				$tablesMap = [
					'goals' => &$cnt_goals,
					'initial_evaluations' => &$cnt_initial,
					'session_reports' => &$cnt_session,
					'discharge_reports' => &$cnt_discharge,
					'other_documents' => &$cnt_other,
				];
				foreach ($tablesMap as $tbl => &$var) {
					try {
						$tiS = $pdo->prepare("PRAGMA table_info('students')"); $tiS->execute(); $scols2 = array_column($tiS->fetchAll(PDO::FETCH_ASSOC),'name');
						if (!$uid || empty($scols2)) { $var = 0; continue; }
						$where = in_array('user_id',$scols2) ? 's.user_id = :uid' : (in_array('assigned_therapist',$scols2) ? 's.assigned_therapist = :uid' : '1=0');
						$st = $pdo->prepare("SELECT COUNT(*) FROM {$tbl} t JOIN students s ON t.student_id = s.id WHERE {$where}");
						$st->execute([':uid'=>$uid]); $var = (int)$st->fetchColumn();
					} catch (Throwable $_e) { $var = 0; }
				}

				// Orphan other_documents: only count rows attributable to this user when user_id column exists
				try {
					$tiOD = $pdo->prepare("PRAGMA table_info('other_documents')"); $tiOD->execute(); $odcols = array_column($tiOD->fetchAll(PDO::FETCH_ASSOC),'name');
					$tiS2 = $pdo->prepare("PRAGMA table_info('students')"); $tiS2->execute(); $scols3 = array_column($tiS2->fetchAll(PDO::FETCH_ASSOC),'name');
					$cnt_orphan_other = 0;
					if ($uid && in_array('user_id',$odcols)) {
						// Count user's docs with no student or student that does not belong to the user
						if (in_array('user_id',$scols3)) {
							$st = $pdo->prepare("SELECT COUNT(*) FROM other_documents d WHERE d.user_id = :uid AND (d.student_id IS NULL OR d.student_id NOT IN (SELECT id FROM students WHERE user_id = :uid))");
							$st->execute([':uid'=>$uid]); $cnt_orphan_other = (int)$st->fetchColumn();
						} else if (in_array('assigned_therapist',$scols3)) {
							$st = $pdo->prepare("SELECT COUNT(*) FROM other_documents d WHERE d.user_id = :uid AND (d.student_id IS NULL OR d.student_id NOT IN (SELECT id FROM students WHERE assigned_therapist = :uid))");
							$st->execute([':uid'=>$uid]); $cnt_orphan_other = (int)$st->fetchColumn();
						}
					}
				} catch (Throwable $_e) { $cnt_orphan_other = 0; }

				// progress-related counts (join to students for ownership)
				$cnt_progress_reports = $cnt_progress_skills = $cnt_progress_updates = 0;
				$progTables = [
					'progress_reports' => &$cnt_progress_reports,
					'progress_skills' => &$cnt_progress_skills,
					'progress_updates' => &$cnt_progress_updates,
				];
				foreach ($progTables as $tbl => &$var) {
					try {
						$tiS = $pdo->prepare("PRAGMA table_info('students')"); $tiS->execute(); $scols2 = array_column($tiS->fetchAll(PDO::FETCH_ASSOC),'name');
						if (!$uid || empty($scols2)) { $var = 0; continue; }
						$where = in_array('user_id',$scols2) ? 's.user_id = :uid' : (in_array('assigned_therapist',$scols2) ? 's.assigned_therapist = :uid' : '1=0');
						$st = $pdo->prepare("SELECT COUNT(*) FROM {$tbl} t JOIN students s ON t.student_id = s.id WHERE {$where}");
						$st->execute([':uid'=>$uid]); $var = (int)$st->fetchColumn();
					} catch (Throwable $_e) { $var = 0; }
				}

				// goals table (rows) — same as goals count
				$cnt_goals_rows = $cnt_goals;

				// total documents computed across canonical tables (per-user)
				$total_documents = 0;
				$docTables = ['goals','initial_evaluations','session_reports','other_documents','discharge_reports'];
				foreach ($docTables as $tbl) {
					try {
						$tiS = $pdo->prepare("PRAGMA table_info('students')"); $tiS->execute(); $scols2 = array_column($tiS->fetchAll(PDO::FETCH_ASSOC),'name');
						if (!$uid || empty($scols2)) { continue; }
						$where = in_array('user_id',$scols2) ? 's.user_id = :uid' : (in_array('assigned_therapist',$scols2) ? 's.assigned_therapist = :uid' : '1=0');
						$st = $pdo->prepare("SELECT COUNT(*) FROM {$tbl} t JOIN students s ON t.student_id = s.id WHERE {$where}");
						$st->execute([':uid'=>$uid]);
						$total_documents += (int)$st->fetchColumn();
					} catch (Throwable $e) { }
				}
			} catch (Throwable $e) {
				// If DB access fails, fall back to safe defaults
				$total_students = count($user_students);
				$active_students = max(0, count($user_students) - $archivedCount);
				$archived_students = $archivedCount;
				$cnt_goals = $cnt_initial = $cnt_session = $cnt_discharge = $cnt_other = $cnt_orphan_other = 0;
				$cnt_progress_reports = $cnt_progress_skills = $cnt_progress_updates = 0;
				$total_documents = 0;
			}
			?>

			<div class="setting-item">
				<div class="setting-info">
					<label>Total students</label>
					<span class="setting-value"><?php echo (int)$total_students; ?> total — <?php echo (int)$active_students; ?> active, <?php echo (int)$archived_students; ?> archived</span>
				</div>
			</div>

			<div class="setting-item">
				<div class="setting-info">
					<label>Documents (by type)</label>
					<div class="muted">
						<ul style="list-style:none; padding-left:0; margin:0;">
							<li>Goals: <?php echo (int)$cnt_goals; ?></li>
							<li>Initial evaluations: <?php echo (int)$cnt_initial; ?></li>
							<li>Session reports: <?php echo (int)$cnt_session; ?></li>
							<li>Discharge reports: <?php echo (int)$cnt_discharge; ?></li>
							<li>Other documents: <?php echo (int)$cnt_other; ?></li>
						</ul>
					</div>
				</div>
			</div>

			<div class="setting-item">
				<div class="setting-info">
					<label>Progress reports</label>
					<span class="setting-value"><?php echo (int)$cnt_progress_reports; ?></span>
				</div>
			</div>

			<div class="setting-item">
				<div class="setting-info">
					<label>Progress skills</label>
					<span class="setting-value"><?php echo (int)$cnt_progress_skills; ?></span>
				</div>
			</div>

			<div class="setting-item">
				<div class="setting-info">
					<label>Progress updates (history)</label>
					<span class="setting-value"><?php echo (int)$cnt_progress_updates; ?></span>
				</div>
			</div>
		</div>

		<!-- Archived Students -->
		<div class="settings-section">
			<div class="section-header">
				<h3>Archived Students</h3>
				<p class="muted">Manage students that have been archived</p>
			</div>
			<div class="setting-item">
				<div class="setting-info">
					<label>Archived Count</label>
					<!-- added id so JS can update this value dynamically -->
					<span id="archivedCountValue" class="setting-value"><?php echo $archivedCount; ?> students</span>
				</div>
				<!-- remove inline onclick; JS will wire this -->
				<button id="viewArchivedStudentsBtn" data-action="view-archived-students" class="btn btn-secondary" <?php echo $archivedCount === 0 ? 'disabled' : ''; ?> onclick="viewArchivedStudents()">View Archived</button>
			</div>
		</div>
	</div>
</div>


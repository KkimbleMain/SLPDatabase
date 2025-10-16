<?php
// templates/settings.php
// Comprehensive settings UI with profile, data management, archived students, and system preferences

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/sqlite.php';

// Load canonical students and compute archived count from the JSON store
try {
    $pdo = get_db();
    $all_students = $pdo->query('SELECT id, student_id, first_name, last_name, archived FROM students ORDER BY last_name, first_name')->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $all_students = [];
}

// Get archived count from DB (fallback to 0 on error)
$archivedCount = 0;
try {
    $pdo = get_db();
    $archivedCount = (int)$pdo->query('SELECT COUNT(*) FROM students WHERE archived = 1')->fetchColumn();
} catch (Exception $e) {
    $archivedCount = 0;
}

// Compute total document count from DB
$totalDocs = 0;
try {
	$pdo = get_db();
	$totalDocs = (int)$pdo->query('SELECT COUNT(*) FROM documents')->fetchColumn();
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
				$total_students = (int)$pdo->query('SELECT COUNT(*) FROM students')->fetchColumn();
				$active_students = (int)$pdo->query('SELECT COUNT(*) FROM students WHERE archived = 0 OR archived IS NULL')->fetchColumn();
				$archived_students = (int)$pdo->query('SELECT COUNT(*) FROM students WHERE archived = 1')->fetchColumn();

				// document-type counts
				$cnt_goals = 0; $cnt_initial = 0; $cnt_session = 0; $cnt_discharge = 0; $cnt_other = 0; $cnt_orphan_other = 0;
				try { $cnt_goals = (int)$pdo->query('SELECT COUNT(*) FROM goals')->fetchColumn(); } catch (Throwable $e) { }
				try { $cnt_initial = (int)$pdo->query('SELECT COUNT(*) FROM initial_evaluations')->fetchColumn(); } catch (Throwable $e) { }
				try { $cnt_session = (int)$pdo->query('SELECT COUNT(*) FROM session_reports')->fetchColumn(); } catch (Throwable $e) { }
				try { $cnt_discharge = (int)$pdo->query('SELECT COUNT(*) FROM discharge_reports')->fetchColumn(); } catch (Throwable $e) { }
				try { $cnt_other = (int)$pdo->query('SELECT COUNT(*) FROM other_documents')->fetchColumn(); } catch (Throwable $e) { }
				// other_documents orphan rows (no matching student)
				try { $cnt_orphan_other = (int)$pdo->query("SELECT COUNT(*) FROM other_documents WHERE student_id IS NULL OR student_id NOT IN (SELECT id FROM students)")->fetchColumn(); } catch (Throwable $e) { }

				// progress-related counts
				$cnt_progress_reports = 0; $cnt_progress_skills = 0; $cnt_progress_updates = 0;
				try { $cnt_progress_reports = (int)$pdo->query('SELECT COUNT(*) FROM progress_reports')->fetchColumn(); } catch (Throwable $e) { }
				try { $cnt_progress_skills = (int)$pdo->query('SELECT COUNT(*) FROM progress_skills')->fetchColumn(); } catch (Throwable $e) { }
				try { $cnt_progress_updates = (int)$pdo->query('SELECT COUNT(*) FROM progress_updates')->fetchColumn(); } catch (Throwable $e) { }

				// goals table (rows)
				$cnt_goals_rows = 0; try { $cnt_goals_rows = (int)$pdo->query('SELECT COUNT(*) FROM goals')->fetchColumn(); } catch (Throwable $e) { }

				// total documents computed across canonical tables (matches dashboard logic)
				$total_documents = 0;
				$docTables = ['goals','initial_evaluations','session_reports','other_documents','discharge_reports'];
				foreach ($docTables as $tbl) {
					try { $st = $pdo->prepare("SELECT COUNT(*) FROM {$tbl} t JOIN students s ON t.student_id = s.id WHERE s.archived = 0"); $st->execute(); $total_documents += (int)$st->fetchColumn(); } catch (Throwable $e) { }
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


<?php
// templates/settings.php
// Comprehensive settings UI with profile, data management, archived students, and system preferences

// Load canonical students and compute archived count from the JSON store
$all_students = loadJsonData('students') ?: [];
$archivedCount = 0;
foreach ($all_students as $student) {
	if (!empty($student['archived'])) $archivedCount++;
}

// Compute total document count only for students present in students.json
$totalDocs = 0;
foreach ($all_students as $student) {
	$sid = (int)($student['id'] ?? 0);
	if (!$sid) continue;
	$folder = __DIR__ . '/../database/data/students/student_' . $sid;
	if (is_dir($folder)) {
		$files = glob($folder . '/*.json');
		$totalDocs += count($files);
	}
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
					<span class="setting-value"><?php echo htmlspecialchars($user_data['first_name'] . ' ' . $user_data['last_name']); ?></span>
				</div>
				<button class="btn btn-outline" onclick="editProfile()">Edit</button>
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
				<button class="btn btn-outline" onclick="changePassword()">Change</button>
			</div>
		</div>

		<!-- Data Management -->
		<div class="settings-section">
			<div class="section-header">
				<h3>Data Management</h3>
				<p class="muted">Backup, export, and manage your data</p>
			</div>
			<div class="setting-item">
				<div class="setting-info">
					<label>Total Students</label>
					<span class="setting-value"><?php echo count($user_students) - $archivedCount; ?> active</span>
				</div>
				<button class="btn btn-outline" onclick="exportAllStudents()">Export All</button>
			</div>
			<div class="setting-item">
				<div class="setting-info">
					<label>Total Documents</label>
					<span class="setting-value"><?php echo $totalDocs; ?> forms</span>
				</div>
				<button class="btn btn-outline" onclick="exportAllDocuments()">Export All</button>
			</div>
			<div class="setting-item">
				<div class="setting-info">
					<label>Database Backup</label>
					<span class="setting-value">Create complete backup</span>
				</div>
				<button class="btn btn-primary" onclick="createBackup()">Backup Now</button>
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
					<span class="setting-value"><?php echo $archivedCount; ?> students</span>
				</div>
				<button class="btn btn-secondary" onclick="viewArchivedStudents()" <?php echo $archivedCount === 0 ? 'disabled' : ''; ?>>View Archived</button>
			</div>
			<div class="setting-item">
				<div class="setting-info">
					<label>Recovery Options</label>
					<span class="setting-value">Restore archived students</span>
				</div>
				<button class="btn btn-outline" onclick="showArchiveRecovery()" <?php echo $archivedCount === 0 ? 'disabled' : ''; ?>>Recover Students</button>
			</div>
		</div>

		<!-- System Preferences -->
		<div class="settings-section">
			<div class="section-header">
				<h3>System Preferences</h3>
				<p class="muted">Customize your application experience</p>
			</div>
			<div class="setting-item">
				<div class="setting-info">
					<label>Default Grade Filter</label>
					<select id="defaultGradeFilter" class="setting-control" onchange="savePreference('defaultGrade', this.value)">
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
			<div class="setting-item">
				<div class="setting-info">
					<label>Students Per Page</label>
					<select id="studentsPerPage" class="setting-control" onchange="savePreference('studentsPerPage', this.value)">
						<option value="10">10 students</option>
						<option value="25" selected>25 students</option>
						<option value="50">50 students</option>
						<option value="100">All students</option>
					</select>
				</div>
			</div>
			<div class="setting-item">
				<div class="setting-info">
					<label>Auto-Save Forms</label>
					<span class="setting-value">Automatically save form drafts</span>
				</div>
				<label class="toggle-switch">
					<input type="checkbox" id="autoSaveForms" checked onchange="savePreference('autoSave', this.checked)">
					<span class="toggle-slider"></span>
				</label>
			</div>
			<div class="setting-item">
				<div class="setting-info">
					<label>Show Student IDs</label>
					<span class="setting-value">Display student IDs in lists</span>
				</div>
				<label class="toggle-switch">
					<input type="checkbox" id="showStudentIds" checked onchange="savePreference('showStudentIds', this.checked)">
					<span class="toggle-slider"></span>
				</label>
			</div>
		</div>

		<!-- Application Info -->



	</div>
</div>


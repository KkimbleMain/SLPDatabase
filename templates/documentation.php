<?php
// templates/documentation.php
// Provides blank form templates: initial evaluation, goals, session report, progress report, discharge

// Check if a specific student is requested
$selectedStudentId = isset($_GET['student_id']) ? (int)$_GET['student_id'] : null;
$selectedStudent = null;

if ($selectedStudentId) {
    // Find the selected student
    foreach ($user_students as $student) {
        if ((int)($student['id'] ?? 0) === $selectedStudentId) {
            $selectedStudent = $student;
            break;
        }
    }
}
?>
<div class="container">
	<div class="page-header stack">
		<h1>Documentation<?php if ($selectedStudent): ?> - <?php echo htmlspecialchars($selectedStudent['first_name'] . ' ' . $selectedStudent['last_name']); ?><?php endif; ?></h1>
		<?php if ($selectedStudent): ?>
		<div class="selected-student-info">
			<span class="muted">Viewing forms for: <?php echo htmlspecialchars($selectedStudent['first_name'] . ' ' . $selectedStudent['last_name']); ?> (ID: <?php echo htmlspecialchars($selectedStudent['student_id'] ?? $selectedStudent['id']); ?>)</span>
			<a href="?view=documentation" class="btn btn-outline btn-sm">View All Students</a>
		</div>
		<?php endif; ?>
	</div>

	<!-- Search existing forms section -->
	<div class="documentation-section">
		<h2>Search Existing Forms</h2>
		<div class="search-forms">
			<div class="form-row">
				<div class="form-group">
					<label for="studentSelect">Select Student:</label>
					<select id="studentSelect" onchange="loadStudentForms()" data-selected-student="<?php echo $selectedStudentId ? $selectedStudentId : ''; ?>">
						<option value="">-- Select a student --</option>
						<?php foreach ($user_students as $student): ?>
							<?php if (empty($student['archived'])): ?>
								<option value="<?php echo $student['id']; ?>" <?php echo ($selectedStudentId && (int)($student['id'] ?? 0) === $selectedStudentId) ? 'selected' : ''; ?>>
									<?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?> - <?php echo htmlspecialchars($student['student_id'] ?? $student['id']); ?>
								</option>
							<?php endif; ?>
						<?php endforeach; ?>
					</select>
				</div>
				<div class="form-group">
					<label for="formTypeFilter">Filter by Form Type:</label>
					<select id="formTypeFilter" onchange="filterExistingForms()">
						<option value="">All Forms</option>
						<option value="initial_evaluation">Initial Evaluation</option>
						<option value="goals_form">Goals Form</option>
						<option value="session_report">Session Report</option>
						<option value="progress_report">Progress Report</option>
						<option value="discharge_report">Discharge Report</option>
					</select>
				</div>
			</div>
			<div id="existingFormsContainer">
				<?php if ($selectedStudent): ?>
				<p class="muted">Loading forms for <?php echo htmlspecialchars($selectedStudent['first_name'] . ' ' . $selectedStudent['last_name']); ?>...</p>
				<?php else: ?>
				<p class="muted">Select a student to view their saved forms.</p>
				<?php endif; ?>
			</div>
		</div>
	</div>

	<!-- Blank forms section -->
	<div class="documentation-section">
		<h2>Blank Forms</h2>
		<p>Create new forms or download blank PDF templates for printing.</p>
		<div class="forms-grid">
			<div class="form-card">
				<h3>Initial Evaluation</h3>
				<div class="form-actions">
					<button class="btn btn-primary" onclick="showDocModal('initial_evaluation')">Create Form</button>
					<button class="btn btn-outline" onclick="downloadBlankPDF('initial_evaluation')">Download PDF</button>
				</div>
			</div>
			<div class="form-card">
				<h3>Goals Form</h3>
				<div class="form-actions">
					<button class="btn btn-primary" onclick="showDocModal('goals_form')">Create Form</button>
					<button class="btn btn-outline" onclick="downloadBlankPDF('goals_form')">Download PDF</button>
				</div>
			</div>
			<div class="form-card">
				<h3>Session Report</h3>
				<div class="form-actions">
					<button class="btn btn-primary" onclick="showDocModal('session_report')">Create Form</button>
					<button class="btn btn-outline" onclick="downloadBlankPDF('session_report')">Download PDF</button>
				</div>
			</div>
			<div class="form-card">
				<h3>Progress Report</h3>
				<div class="form-actions">
					<button class="btn btn-primary" onclick="showDocModal('progress_report')">Create Form</button>
					<button class="btn btn-outline" onclick="downloadBlankPDF('progress_report')">Download PDF</button>
				</div>
			</div>
			<div class="form-card">
				<h3>Discharge Report</h3>
				<div class="form-actions">
					<button class="btn btn-primary" onclick="showDocModal('discharge_report')">Create Form</button>
					<button class="btn btn-outline" onclick="downloadBlankPDF('discharge_report')">Download PDF</button>
				</div>
			</div>
		</div>
	</div>

	<!-- Documentation Modal -->
	<div id="documentationModal" class="modal" style="display: none;">
		<div class="modal-content">
			<div class="modal-header">
				<h2 id="docModalTitle">Documentation Form</h2>
				<span class="close" onclick="closeDocModal()">&times;</span>
			</div>
			<div class="modal-form">
				<div id="docModalForm">
					<!-- Form content will be loaded here -->
				</div>
				<div class="modal-actions">
					<button type="button" class="btn btn-outline" onclick="closeDocModal()">Cancel</button>
					<button type="button" class="btn btn-outline" onclick="printDocument()">Print PDF</button>
					<button type="button" class="btn btn-primary" onclick="saveDocument()">Submit</button>
				</div>
			</div>
		</div>
	</div>

</div>

<script src="assets/js/pages/documentation.js"></script>

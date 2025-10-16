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
		<!-- Selected student header removed to avoid redundant banner; page title already shows student name when applicable -->
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
						<!-- Progress Report option removed -->
						<option value="discharge_report">Discharge Report</option>
						<option value="other_documents">Other Documents</option>
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
				</div>
			</div>
			<div class="form-card">
				<h3>Goals Form</h3>
				<div class="form-actions">
					<button class="btn btn-primary" onclick="showDocModal('goals_form')">Create Form</button>
				</div>
			</div>
			<div class="form-card">
				<h3>Session Report</h3>
				<div class="form-actions">
					<button class="btn btn-primary" onclick="showDocModal('session_report')">Create Form</button>
				</div>
			</div>

			<div class="form-card">
				<h3>Discharge Report</h3>
				<div class="form-actions">
					<button class="btn btn-primary" onclick="showDocModal('discharge_report')">Create Form</button>
				</div>
			</div>
		</div>
	</div>

	<!-- Upload other documents section -->
	<div class="documentation-section">
		<h2>Upload Other Documents</h2>
		<p>Teachers can upload scanned documents (PDFs, images). Uploaded files are stored and linked to the selected student.</p>
		<div class="form-row">
			<div class="form-group">
				<button class="btn btn-primary" id="btnOpenUpload">Upload Other Document</button>
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
					<button type="button" class="btn btn-outline" id="docCancelBtn">Cancel</button>
					<button type="button" id="printDocBtn" class="btn btn-outline">Print blank PDF</button>
					<button type="button" id="saveDocBtn" class="btn btn-primary">Submit</button>
				</div>
			</div>
		</div>
	</div>

</div>

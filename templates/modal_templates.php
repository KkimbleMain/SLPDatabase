<?php
require_once __DIR__ . '/../includes/sqlite.php';
try {
    $pdo = get_db();
    $all_students = $pdo->query('SELECT id, student_id, first_name, last_name FROM students ORDER BY last_name, first_name')->fetchAll(PDO::FETCH_ASSOC);
    $all_users = $pdo->query('SELECT id, username, first_name, last_name FROM users ORDER BY first_name, last_name')->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $all_students = [];
    $all_users = [];
}
?>
<template id="tmpl-add-student">
    <div id="studentModal" class="modal" role="dialog" aria-modal="true" aria-labelledby="studentModalTitle">
        <div class="modal-content">
            <div class="modal-header">
                <h2 id="studentModalTitle">Add New Student</h2>
                <button class="close" type="button" aria-label="Close">&times;</button>
            </div>
            <form id="addStudentForm" class="modal-form">
                <div class="form-row">
                    <div class="form-group">
                        <label for="firstName">First Name *</label>
                        <input type="text" id="firstName" name="first_name" required>
                    </div>
                    <div class="form-group">
                        <label for="lastName">Last Name *</label>
                        <input type="text" id="lastName" name="last_name" required>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <p class="muted">Student ID will be generated automatically from the student's initials.</p>
                    </div>
                    <div class="form-group">
                        <label for="grade">Grade *</label>
                        <select id="grade" name="grade" required>
                            <option value="">Select grade...</option>
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
                <div class="form-row">
                    <div class="form-group">
                        <label for="dateOfBirth">Date of Birth *</label>
                        <input type="date" id="dateOfBirth" name="date_of_birth" required>
                    </div>
                    <div class="form-group">
                        <label for="gender">Gender *</label>
                        <select id="gender" name="gender" required>
                            <option value="">Select...</option>
                            <option value="male">Male</option>
                            <option value="female">Female</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="primaryLanguage">Primary Language *</label>
                        <select id="primaryLanguage" name="primary_language" required>
                            <option value="">Select language...</option>
                            <option value="English">English</option>
                            <option value="Spanish">Spanish</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="serviceFrequency">Service Frequency *</label>
                        <input type="text" id="serviceFrequency" name="service_frequency" required placeholder="e.g. 30min weekly">
                    </div>
                </div>
                <div class="form-group">
                    <label for="parentContact">Parent/Guardian Contact *</label>
                    <input type="text" id="parentContact" name="parent_contact" required placeholder="Name and phone/email">
                </div>
                <div class="form-group">
                    <label for="medicalInfo">Medical / Notes *</label>
                    <textarea id="medicalInfo" name="medical_info" rows="3" required placeholder="Allergies, accommodations, medical notes..."></textarea>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-outline">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add Student</button>
                </div>
            </form>
        </div>
    </div>
</template>
<!-- Progress preview removed: reporting flow now generates PDF directly from the progress page -> server snapshot or client print -->

<!-- delete confirmation template for centralized modals -->
<template id="tmpl-confirm-delete-skill">
    <div class="modal" role="dialog" aria-modal="true">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Delete skill?</h2>
                <button class="close" type="button" aria-label="Close">&times;</button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete this skill and all its updates? This cannot be undone.</p>
            </div>
            <div class="modal-actions">
                <button class="btn btn-secondary" type="button">Cancel</button>
                <button class="btn btn-danger" type="button">Delete</button>
            </div>
        </div>
    </div>
</template>

<!-- General confirmation modal template -->
<template id="tmpl-confirm-modal">
    <div class="modal" role="dialog" aria-modal="true">
        <div class="modal-content">
            <div class="modal-header">
                <h2 id="confirmModalTitle">Confirm Action</h2>
                <button class="close" type="button" aria-label="Close">&times;</button>
            </div>
            <div class="modal-body">
                <p id="confirmModalMessage">Are you sure?</p>
            </div>
            <div class="modal-actions">
                <button class="btn btn-secondary" type="button" id="confirmModalCancel">Cancel</button>
                <button class="btn btn-danger" type="button" id="confirmModalConfirm">Confirm</button>
            </div>
        </div>
    </div>
</template>

<!-- Typed confirmation modal: user must enter a specific word to confirm (e.g., DELETE) -->
<template id="tmpl-typed-confirm-modal">
    <div class="modal" role="dialog" aria-modal="true">
        <div class="modal-content">
            <div class="modal-header">
                <h2 id="typedConfirmTitle">Confirm Action</h2>
                <button class="close" type="button" aria-label="Close">&times;</button>
            </div>
            <div class="modal-body">
                <p id="typedConfirmMessage">Please type the confirmation text to continue.</p>
                <div style="margin-top:0.75rem;">
                    <label for="typedConfirmInput" class="muted" style="display:block;margin-bottom:0.25rem;">Type the exact text to confirm:</label>
                    <input id="typedConfirmInput" type="text" placeholder="Enter confirmation text" style="width:100%;padding:0.5rem;border:1px solid var(--border-color);border-radius:4px;" />
                </div>
            </div>
            <div class="modal-actions">
                <button class="btn btn-secondary" type="button" id="typedConfirmCancel">Cancel</button>
                <button class="btn btn-danger" type="button" id="typedConfirmConfirm">Confirm</button>
            </div>
        </div>
    </div>
</template>

<!-- Confirm delete report (separate copy so wording matches report deletion) -->
<template id="tmpl-confirm-delete-report">
    <div class="modal" role="dialog" aria-modal="true">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Delete report?</h2>
                <button class="close" type="button" aria-label="Close">&times;</button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to permanently delete this student's progress report and its associated PDF? This cannot be undone.</p>
            </div>
            <div class="modal-actions">
                <button class="btn btn-secondary" type="button">Cancel</button>
                <button class="btn btn-danger" type="button">Delete</button>
            </div>
        </div>
    </div>
</template>

<!-- Progress: History modal template (professional layout) -->
<template id="tmpl-progress-history">
    <div class="modal modal-large" role="dialog" aria-modal="true">
        <div class="modal-content">
            <div class="modal-header">
                <h2 id="historyTitle">Progress History</h2>
                <button class="close" type="button" aria-label="Close">&times;</button>
            </div>
            <div class="modal-body">
                <div class="history-meta muted" id="historyMeta">Loading...</div>
                <div class="history-list" id="historyList">
                    <!-- items rendered here -->
                </div>
            </div>
            <div class="modal-actions">
                <button class="btn btn-outline" type="button">Close</button>
            </div>
        </div>
    </div>
</template>

<template id="tmpl-edit-student">
    <div id="editStudentModal" class="modal" role="dialog" aria-modal="true" aria-labelledby="editStudentModalTitle">
        <div class="modal-content">
            <div class="modal-header">
                <h2 id="editStudentModalTitle">Edit Student Profile</h2>
                <button class="close" type="button" aria-label="Close">&times;</button>
            </div>
            <form id="editStudentForm" class="modal-form">
                <input type="hidden" id="editStudentId" name="student_id">
                <div class="form-row">
                    <div class="form-group">
                        <label for="editFirstName">First Name *</label>
                        <input type="text" id="editFirstName" name="first_name" required>
                    </div>
                    <div class="form-group">
                        <label for="editLastName">Last Name *</label>
                        <input type="text" id="editLastName" name="last_name" required>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <p class="muted">Student ID: <span id="displayStudentId"></span></p>
                    </div>
                    <div class="form-group">
                        <label for="editGrade">Grade *</label>
                        <select id="editGrade" name="grade" required>
                            <option value="">Select grade...</option>
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
                <div class="form-row">
                    <div class="form-group">
                        <label for="editDateOfBirth">Date of Birth *</label>
                        <input type="date" id="editDateOfBirth" name="date_of_birth" required>
                    </div>
                    <div class="form-group">
                        <label for="editGender">Gender *</label>
                        <select id="editGender" name="gender" required>
                            <option value="">Select...</option>
                            <option value="male">Male</option>
                            <option value="female">Female</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="editPrimaryLanguage">Primary Language *</label>
                        <select id="editPrimaryLanguage" name="primary_language" required>
                            <option value="">Select language...</option>
                            <option value="English">English</option>
                            <option value="Spanish">Spanish</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="editServiceFrequency">Service Frequency *</label>
                        <input type="text" id="editServiceFrequency" name="service_frequency" required placeholder="e.g. 30min weekly">
                    </div>
                </div>
                <!-- Teacher and assigned therapist removed from edit modal (fields migrated out of schema). -->
                <div class="form-group">
                    <label for="editParentContact">Parent/Guardian Contact *</label>
                    <input type="text" id="editParentContact" name="parent_contact" required placeholder="Name and phone/email">
                </div>
                <div class="form-group">
                    <label for="editMedicalInfo">Medical / Notes *</label>
                    <textarea id="editMedicalInfo" name="medical_info" rows="3" required placeholder="Allergies, accommodations, medical notes..."></textarea>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-outline">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</template>

<template id="tmpl-register">
    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="registerModalTitle">
        <div class="modal-content">
            <div class="modal-header">
                <h2 id="registerModalTitle">Register</h2>
                <button class="close" type="button" aria-label="Close">&times;</button>
            </div>
            <form id="registerForm" class="modal-form">
                <div class="form-group">
                    <label for="regFirstName">First Name *</label>
                    <input type="text" id="regFirstName" name="first_name" required>
                </div>
                <div class="form-group">
                    <label for="regLastName">Last Name *</label>
                    <input type="text" id="regLastName" name="last_name" required>
                </div>
                <div class="form-group">
                    <label for="regUsername">Username *</label>
                    <input type="text" id="regUsername" name="username" required>
                </div>
                <div class="form-group">
                    <label for="regEmail">Email *</label>
                    <input type="email" id="regEmail" name="email" required>
                </div>
                <div class="form-group">
                    <label for="regPassword">Password *</label>
                    <input type="password" id="regPassword" name="password" required>
                </div>
                <div class="form-group">
                    <label for="regRole">Role</label>
                    <select id="regRole" name="role">
                        <option value="therapist">Therapist</option>
                        <option value="admin">Admin</option>
                    </select>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-outline">Cancel</button>
                    <button type="submit" class="btn btn-primary">Create Account</button>
                </div>
            </form>
        </div>
    </div>
</template>

<template id="tmpl-upload-document">
    <div id="uploadDocumentModal" class="modal" role="dialog" aria-modal="true" aria-labelledby="uploadDocumentTitle">
        <div class="modal-content">
            <div class="modal-header">
                <h2 id="uploadDocumentTitle">Upload Other Document</h2>
                <button class="close" type="button" aria-label="Close">&times;</button>
            </div>
            <form id="uploadDocumentForm" class="modal-form" enctype="multipart/form-data">
                <input type="hidden" name="action" value="upload_document">
                <div class="form-group">
                    <label for="uploadStudent">Student *</label>
                    <select id="uploadStudent" name="student_id" required>
                        <option value="">Select student...</option>
                        <?php foreach ($all_students as $s): ?>
                            <option value="<?php echo htmlspecialchars($s['id'] ?? ''); ?>"><?php echo htmlspecialchars(trim(($s['first_name'] ?? '') . ' ' . ($s['last_name'] ?? ''))); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="uploadTitle">Title *</label>
                    <input type="text" id="uploadTitle" name="title" required>
                </div>
                <div class="form-group">
                    <label for="uploadFile">File *</label>
                    <input type="file" id="uploadFile" name="file" accept=".pdf,image/*" required>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-outline">Cancel</button>
                    <button type="submit" class="btn btn-primary">Upload</button>
                </div>
            </form>
        </div>
    </div>
</template>

<template id="tmpl-student-profile">
    <div class="modal modal-large" role="dialog" aria-modal="true">
        <div class="modal-content">
            <div class="modal-header">
                <h2 id="studentProfileTitle">Student Profile</h2>
                <button class="close" type="button" aria-label="Close">&times;</button>
            </div>
            <div class="modal-body" id="studentProfileBody">
                <p>Loading...</p>
            </div>
            <div class="modal-actions">
                <button id="studentProfileBackBtn" type="button" class="btn btn-outline">Back to Students</button>
                <button id="studentProfileDocsBtn" type="button" class="btn btn-secondary">Documentation</button>
                <button id="studentProfileEditBtn" type="button" class="btn btn-primary">Edit Profile</button>
                <button type="button" class="btn btn-outline">Close</button>
            </div>
        </div>
    </div>
</template>

<template id="tmpl-forgot-password">
    <div class="modal" role="dialog" aria-modal="true">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Reset Password</h2>
                <button class="close" type="button" aria-label="Close">&times;</button>
            </div>
            <form id="forgotPasswordForm" class="modal-form">
                <div class="form-group">
                    <label for="resetUsername">Enter your username or email</label>
                    <input type="text" id="resetUsername" name="username" required>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-outline">Cancel</button>
                    <button type="submit" class="btn btn-primary">Send Reset Link</button>
                </div>
            </form>
        </div>
    </div>
</template>

<!-- Documentation form templates (moved from documentation.js to server-side templates) -->
<template id="tmpl-doc-initial_evaluation">
    <div class="modal modal-large" role="dialog" aria-modal="true">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Initial Evaluation</h2>
                <button class="close" type="button" aria-label="Close">&times;</button>
            </div>
            <div class="modal-body doc-form" data-form-type="initial_evaluation">
                <div class="form-row">
                    <div class="form-group">
                        <label for="studentName">Student Name:</label>
                        <select id="studentName" name="studentName" required>
                            <option value="">Select Student</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="evaluationDate">Evaluation Date:</label>
                        <input type="date" id="evaluationDate" name="evaluationDate" required>
                    </div>
                </div>
                <div class="form-group">
                    <label for="referralReason">Reason for Referral:</label>
                    <textarea id="referralReason" name="referralReason" rows="3" required></textarea>
                </div>
                <div class="form-group">
                    <label for="backgroundInfo">Background Information:</label>
                    <textarea id="backgroundInfo" name="backgroundInfo" rows="4" required></textarea>
                </div>
                <div class="form-group">
                    <label for="assessmentResults">Assessment Results:</label>
                    <textarea id="assessmentResults" name="assessmentResults" rows="5" required></textarea>
                </div>
                <div class="form-group">
                    <label for="recommendations">Recommendations:</label>
                    <textarea id="recommendations" name="recommendations" rows="4" required></textarea>
                </div>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn btn-outline">Cancel</button>
                <button type="button" id="saveDocBtn" class="btn btn-primary">Submit</button>
                <button type="button" id="printDocBtn" class="btn btn-outline">Print blank PDF</button>
            </div>
        </div>
    </div>
</template>

<template id="tmpl-doc-goals_form">
    <div class="modal modal-large" role="dialog" aria-modal="true">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Goals Form</h2>
                <button class="close" type="button" aria-label="Close">&times;</button>
            </div>
            <div class="modal-body doc-form" data-form-type="goals_form">
                <div class="form-row">
                    <div class="form-group">
                        <label for="studentName">Student Name:</label>
                        <select id="studentName" name="studentName" required>
                            <option value="">Select Student</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="goalDate">Goal Date:</label>
                        <input type="date" id="goalDate" name="goalDate" required>
                    </div>
                </div>
                <div class="form-group">
                    <label for="longTermGoals">Long Term Goals:</label>
                    <textarea id="longTermGoals" name="longTermGoals" rows="4" required></textarea>
                </div>
                <div class="form-group">
                    <label for="shortTermObjectives">Short Term Objectives:</label>
                    <textarea id="shortTermObjectives" name="shortTermObjectives" rows="4" required></textarea>
                </div>
                <div class="form-group">
                    <label for="interventionStrategies">Intervention Strategies:</label>
                    <textarea id="interventionStrategies" name="interventionStrategies" rows="3" required></textarea>
                </div>
                <div class="form-group">
                    <label for="measurementCriteria">Measurement Criteria:</label>
                    <textarea id="measurementCriteria" name="measurementCriteria" rows="3" required></textarea>
                </div>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn btn-outline">Cancel</button>
                <button type="button" id="saveDocBtn" class="btn btn-primary">Submit</button>
                <button type="button" id="printDocBtn" class="btn btn-outline">Print blank PDF</button>
            </div>
        </div>
    </div>
</template>

<template id="tmpl-doc-session_report">
    <div class="modal modal-large" role="dialog" aria-modal="true">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Session Report</h2>
                <button class="close" type="button" aria-label="Close">&times;</button>
            </div>
            <div class="modal-body doc-form" data-form-type="session_report">
                <div class="form-row">
                    <div class="form-group">
                        <label for="studentName">Student Name:</label>
                        <select id="studentName" name="studentName" required>
                            <option value="">Select Student</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="sessionDate">Session Date:</label>
                        <input type="date" id="sessionDate" name="sessionDate" required>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="sessionDuration">Duration (minutes):</label>
                        <input type="number" id="sessionDuration" name="sessionDuration" min="1" max="480" required>
                    </div>
                    <div class="form-group">
                        <label for="sessionType">Session Type:</label>
                        <select id="sessionType" name="sessionType" required>
                            <option value="individual">Individual</option>
                            <option value="group">Group</option>
                            <option value="consultation">Consultation</option>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label for="objectivesTargeted">Objectives Targeted:</label>
                    <textarea id="objectivesTargeted" name="objectivesTargeted" rows="3" required></textarea>
                </div>
                <div class="form-group">
                    <label for="activitiesUsed">Activities/Materials Used:</label>
                    <textarea id="activitiesUsed" name="activitiesUsed" rows="3" required></textarea>
                </div>
                <div class="form-group">
                    <label for="studentResponse">Student Response/Performance:</label>
                    <textarea id="studentResponse" name="studentResponse" rows="4" required></textarea>
                </div>
                <div class="form-group">
                    <label for="nextSessionPlan">Plan for Next Session:</label>
                    <textarea id="nextSessionPlan" name="nextSessionPlan" rows="3" required></textarea>
                </div>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn btn-outline">Cancel</button>
                <button type="button" id="saveDocBtn" class="btn btn-primary">Submit</button>
                <button type="button" id="printDocBtn" class="btn btn-outline">Print blank PDF</button>
            </div>
        </div>
    </div>
</template>
<!-- Progress report template removed: progress reporting deprecated for now -->

<template id="tmpl-doc-discharge_report">
    <div class="modal modal-large" role="dialog" aria-modal="true">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Discharge Report</h2>
                <button class="close" type="button" aria-label="Close">&times;</button>
            </div>
            <div class="modal-body doc-form" data-form-type="discharge_report">
                <div class="form-row">
                    <div class="form-group">
                        <label for="studentName">Student Name:</label>
                        <select id="studentName" name="studentName" required>
                            <option value="">Select Student</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="dischargeDate">Discharge Date:</label>
                        <input type="date" id="dischargeDate" name="dischargeDate" required>
                    </div>
                </div>
                <div class="form-group">
                    <label for="servicesSummary">Summary of Services Provided:</label>
                    <textarea id="servicesSummary" name="servicesSummary" rows="4" required></textarea>
                </div>
                <div class="form-group">
                    <label for="goalsAchieved">Goals Achieved:</label>
                    <textarea id="goalsAchieved" name="goalsAchieved" rows="3" required></textarea>
                </div>
                <div class="form-group">
                    <label for="dischargeReason">Reason for Discharge:</label>
                    <textarea id="dischargeReason" name="dischargeReason" rows="3" required></textarea>
                </div>
                <div class="form-group">
                    <label for="followUpRecommendations">Follow-up Recommendations:</label>
                    <textarea id="followUpRecommendations" name="followUpRecommendations" rows="3" required></textarea>
                </div>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn btn-outline">Cancel</button>
                <button type="button" id="saveDocBtn" class="btn btn-primary">Submit</button>
                <button type="button" id="printDocBtn" class="btn btn-outline">Print blank PDF</button>
            </div>
        </div>
    </div>
</template>

<!-- Progress: Add/Update Score modal template -->
<template id="tmpl-progress-add-update">
    <div class="modal" role="dialog" aria-modal="true">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Select a current score</h2>
                <button class="close" type="button" aria-label="Close">&times;</button>
            </div>
            <div class="modal-form">
                <div class="form-group">
                    <label for="scoreVal">Current score</label>
                    <input id="scoreVal" type="number" min="0" max="100" value="50" />
                    <div class="field-error" id="scoreValError" aria-live="polite"></div>
                </div>
                <div class="form-group">
                    <label for="targetVal">Target score</label>
                    <div style="display:flex;align-items:center;gap:8px;">
                        <input id="targetVal" type="number" min="0" max="100" value="80" />
                        <input id="targetValRange" type="range" min="0" max="100" value="80" />
                        <button id="targetValLock" class="btn icon-btn" type="button" aria-pressed="false" title="Toggle target lock">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M12 17a2 2 0 1 0 0-4 2 2 0 0 0 0 4z" fill="#111827"/><path d="M17 8V7a5 5 0 0 0-10 0v1" stroke="#111827" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round"/><rect x="4" y="8" width="16" height="12" rx="2" stroke="#111827" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        </button>
                        <span id="targetValLockLabel" class="lock-status">Locked</span>
                    </div>
                    <div class="field-error" id="targetValError" aria-live="polite"></div>
                </div>
                <div class="form-group">
                    <label for="notes">Notes (optional)</label>
                    <textarea id="notes" placeholder="Notes (optional)"></textarea>
                </div>
                <div class="modal-actions">
                    <button class="btn btn-secondary" type="button">Cancel</button>
                    <button class="btn btn-primary" type="button">Submit</button>
                </div>
            </div>
        </div>
    </div>
</template>
<!-- Progress: Add Skill modal template -->
<template id="tmpl-progress-add-skill">
    <div class="modal" role="dialog" aria-modal="true">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Add Skill</h2>
                <button class="close" type="button" aria-label="Close">&times;</button>
            </div>
            <div class="modal-form">
                <div class="form-group">
                    <label for="skillCategory">Skill category</label>
                    <select id="skillCategory" name="category">
                        <option value="">Select category...</option>
                        <option value="Articulation">Articulation</option>
                        <option value="Fluency">Fluency</option>
                        <option value="Language">Language</option>
                        <option value="Social Communication">Social communication</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="skillLabel">Skill label</label>
                    <input id="skillLabel" list="skillLabelSuggestions" type="text" placeholder="e.g. B sound - cvc, short phrases, problem solving, WH - questions" />
                </div>
                <div class="form-group">
                    <label for="skillCurrentNum">Current (%)</label>
                    <input id="skillCurrentNum" type="number" min="0" max="100" />
                    <input id="skillCurrentRange" type="range" min="0" max="100" value="50" />
                    <div class="field-error" id="skillCurrentNumError" aria-live="polite"></div>
                </div>
                <div class="form-group">
                    <label for="skillTargetNum">Target (%)</label>
                    <div style="display:flex;align-items:center;gap:8px;">
                        <input id="skillTargetNum" type="number" min="0" max="100" />
                        <input id="skillTargetRange" type="range" min="0" max="100" value="80" />
                        <button id="skillTargetLock" class="btn icon-btn" type="button" aria-pressed="false" title="Toggle target lock">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M12 17a2 2 0 1 0 0-4 2 2 0 0 0 0 4z" fill="#111827"/><path d="M17 8V7a5 5 0 0 0-10 0v1" stroke="#111827" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round"/><rect x="4" y="8" width="16" height="12" rx="2" stroke="#111827" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        </button>
                        <span id="targetValLockLabel" class="lock-status">Locked</span>
                    </div>
                    <div class="field-error" id="skillTargetNumError" aria-live="polite"></div>
                </div>
                <div class="form-group">
                    <label for="skillNotes">Notes (optional)</label>
                    <textarea id="skillNotes" placeholder="Notes (optional)"></textarea>
                </div>
                <div class="modal-actions">
                    <button class="btn btn-secondary" type="button">Cancel</button>
                    <button class="btn btn-primary" type="button">Add Skill</button>
                </div>
            </div>
        </div>
    </div>
</template>

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
                    <label for="teacher">Teacher *</label>
                    <input type="text" id="teacher" name="teacher" required>
                </div>
                <div class="form-group">
                    <label for="assignedTherapistName">Assigned Therapist *</label>
                    <input type="text" id="assignedTherapistName" name="assigned_therapist_name" required placeholder="e.g. Dr. Jane Smith">
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
                <div class="form-group">
                    <label for="editTeacher">Teacher *</label>
                    <input type="text" id="editTeacher" name="teacher" required>
                </div>
                <div class="form-group">
                    <label for="editAssignedTherapistName">Assigned Therapist *</label>
                    <input type="text" id="editAssignedTherapistName" name="assigned_therapist_name" required placeholder="e.g. Dr. Jane Smith">
                </div>
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

<template id="tmpl-add-goal">
    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="addGoalTitle">
        <div class="modal-content">
            <div class="modal-header">
                <h2 id="addGoalTitle">Add New Goal</h2>
                <button class="close" type="button" aria-label="Close">&times;</button>
            </div>
            <form id="addGoalForm" class="modal-form">
                <div class="form-group">
                    <label for="goalStudent">Student *</label>
                    <select id="goalStudent" name="student_id" required>
                        <option value="">Select student...</option>
                        <?php foreach ($all_students as $s): ?>
                            <option value="<?php echo htmlspecialchars($s['id'] ?? ''); ?>"><?php echo htmlspecialchars(trim(($s['first_name'] ?? '') . ' ' . ($s['last_name'] ?? ''))); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="goalDescription">Goal Description *</label>
                    <textarea id="goalDescription" name="description" rows="3" required></textarea>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="goalTargetDate">Target Date</label>
                        <input type="date" id="goalTargetDate" name="target_date">
                    </div>
                    <div class="form-group">
                        <label for="goalNotes">Notes</label>
                        <input type="text" id="goalNotes" name="notes">
                    </div>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-outline">Cancel</button>
                    <button type="submit" class="btn btn-primary">Create Goal</button>
                </div>
            </form>
        </div>
    </div>
</template>

<template id="tmpl-add-progress">
    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="addProgressTitle">
        <div class="modal-content">
            <div class="modal-header">
                <h2 id="addProgressTitle">Add Progress Report</h2>
                <button class="close" type="button" aria-label="Close">&times;</button>
            </div>
            <form id="addProgressForm" class="modal-form">
                <div class="form-group">
                    <label for="progressStudent">Student *</label>
                    <select id="progressStudent" name="student_id" required>
                        <option value="">Select student...</option>
                        <?php foreach ($all_students as $s): ?>
                            <option value="<?php echo htmlspecialchars($s['id'] ?? ''); ?>"><?php echo htmlspecialchars(trim(($s['first_name'] ?? '') . ' ' . ($s['last_name'] ?? ''))); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="progressDate">Date</label>
                        <input type="date" id="progressDate" name="date_recorded" value="<?php echo date('Y-m-d'); ?>">
                    </div>
                    <div class="form-group">
                        <label for="progressScore">Score (%)</label>
                        <input type="number" id="progressScore" name="score" min="0" max="100" step="0.1">
                    </div>
                </div>
                <div class="form-group">
                    <label for="progressNotes">Notes</label>
                    <textarea id="progressNotes" name="notes" rows="3"></textarea>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-outline">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Report</button>
                </div>
            </form>
        </div>
    </div>
</template>

<template id="tmpl-student-profile">
    <div class="modal" role="dialog" aria-modal="true">
        <div class="modal-content">
            <div class="modal-header">
                <h2 id="studentProfileTitle">Student Profile</h2>
                <button class="close" type="button" aria-label="Close">&times;</button>
            </div>
            <div class="modal-body" id="studentProfileBody">
                <p>Loading...</p>
            </div>
            <div class="modal-actions">
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

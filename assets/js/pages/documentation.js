// Documentation page JavaScript
let allStudents = [];
let existingForms = [];

// Load students and setup page
document.addEventListener('DOMContentLoaded', async function() {
    await loadStudents();
    setupDocumentationPage();
    
    // Check if a student is pre-selected via URL parameter or data attribute
    const studentSelect = document.getElementById('studentSelect');
    const preSelectedId = studentSelect.dataset.selectedStudent;
    
    if (preSelectedId && preSelectedId !== '') {
        studentSelect.value = preSelectedId;
        await loadStudentForms();
    }
});

async function loadStudents() {
    try {
    const response = await fetch('/api/students.php');
        allStudents = await response.json();
        populateStudentSelect();
    } catch (error) {
        console.error('Error loading students:', error);
        showNotification('Error loading students', 'error');
    }
}

function populateStudentSelect() {
    const select = document.getElementById('studentSelect');
    const currentValue = select.value; // Preserve current selection
    select.innerHTML = '<option value="">-- Select a student --</option>';
    
    console.log('Populating student select with:', allStudents);
    
    allStudents.forEach(student => {
        if (!student.archived) { // Don't show archived students
            const option = document.createElement('option');
            option.value = student.id;
            option.textContent = `${student.first_name} ${student.last_name} - ${student.student_id || student.id}`;
            select.appendChild(option);
        }
    });
    
    // Restore previous selection if it exists
    if (currentValue) {
        select.value = currentValue;
    }
}

async function loadStudentForms() {
    const studentId = document.getElementById('studentSelect').value;
    const container = document.getElementById('existingFormsContainer');
    
    if (!studentId) {
        container.innerHTML = '<p class="muted">Select a student to view their saved forms.</p>';
        return;
    }
    
    container.innerHTML = '<p class="muted">Loading forms...</p>';
    
    try {
        // Load documents from student folder
        console.log('Loading forms for student ID:', studentId);
        
        // Since we can't list directory contents via HTTP, we'll try a different approach
        // Let's create an API endpoint to get student forms
        const formResponse = await fetch('includes/submit.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `action=get_student_forms&student_id=${studentId}`
        });
        
        const result = await formResponse.json();
        console.log('API response:', result);
        
        if (result.success) {
            existingForms = result.forms || [];
            console.log('Forms loaded:', existingForms);
            displayExistingForms(existingForms);
        } else {
            console.error('API error:', result.error);
            container.innerHTML = '<p class="muted">No saved forms found for this student.</p>';
        }
        
    } catch (error) {
        console.error('Error loading student forms:', error);
        container.innerHTML = '<p class="muted">Error loading forms.</p>';
    }
}

function displayExistingForms(forms) {
    const container = document.getElementById('existingFormsContainer');
    console.log('Displaying forms:', forms);
    
    if (forms.length === 0) {
        container.innerHTML = '<p class="muted">No saved forms found for this student.</p>';
        return;
    }
    
    const formTypeNames = {
        'initial_evaluation': 'Initial Evaluation',
        'goals_form': 'Goals Form',
        'session_report': 'Session Report',
        'progress_report': 'Progress Report',
        'discharge_report': 'Discharge Report'
    };
    
    const html = forms.map(form => {
        const formTypeName = formTypeNames[form.form_type] || form.form_type;
        const createdDate = new Date(form.created_at).toLocaleDateString();
        
        return `
            <div class="existing-form-item">
                <div class="existing-form-info">
                    <div class="existing-form-title">${formTypeName}</div>
                    <div class="existing-form-meta">Created: ${createdDate}</div>
                </div>
                <div class="existing-form-actions">
                    <button class="btn btn-sm btn-outline" onclick="viewExistingForm('${form.id}')">View</button>
                    <button class="btn btn-sm btn-outline" onclick="editExistingForm('${form.id}')">Edit</button>
                    <button class="btn btn-sm btn-outline" onclick="printExistingForm('${form.id}')">Print</button>
                </div>
            </div>
        `;
    }).join('');
    
    container.innerHTML = html;
}

function filterExistingForms() {
    const formType = document.getElementById('formTypeFilter').value;
    
    if (!formType) {
        displayExistingForms(existingForms);
        return;
    }
    
    const filteredForms = existingForms.filter(form => form.form_type === formType);
    displayExistingForms(filteredForms);
}

function setupDocumentationPage() {
    // Any additional setup for the documentation page can go here
    console.log('Documentation page initialized');
}

// Function to show documentation modal (existing functionality)
function showDocModal(formType) {
    const modal = document.getElementById('documentationModal');
    const title = document.getElementById('docModalTitle');
    const form = document.getElementById('docModalForm');
    
    // Set the modal title
    const formTitles = {
        'initial_evaluation': 'Initial Evaluation',
        'goals_form': 'Goals Form',
        'session_report': 'Session Report',
        'progress_report': 'Progress Report',
        'discharge_report': 'Discharge Report'
    };
    
    title.textContent = formTitles[formType] || 'Documentation Form';
    
    // Load the form template
    loadFormTemplate(formType);
    
    modal.style.display = 'flex';
}

function loadFormTemplate(formType) {
    const container = document.getElementById('docModalForm');
    
    // Basic form templates - these can be enhanced based on specific requirements
    const templates = {
        'initial_evaluation': `
            <div class="form-row">
                <div class="form-group">
                    <label for="studentName">Student Name:</label>
                    <select id="studentName" required>
                        <option value="">Select Student</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="evaluationDate">Evaluation Date:</label>
                    <input type="date" id="evaluationDate" required>
                </div>
            </div>
            <div class="form-group">
                <label for="referralReason">Reason for Referral:</label>
                <textarea id="referralReason" rows="3" required></textarea>
            </div>
            <div class="form-group">
                <label for="backgroundInfo">Background Information:</label>
                <textarea id="backgroundInfo" rows="4"></textarea>
            </div>
            <div class="form-group">
                <label for="assessmentResults">Assessment Results:</label>
                <textarea id="assessmentResults" rows="5"></textarea>
            </div>
            <div class="form-group">
                <label for="recommendations">Recommendations:</label>
                <textarea id="recommendations" rows="4"></textarea>
            </div>
        `,
        'goals_form': `
            <div class="form-row">
                <div class="form-group">
                    <label for="studentName">Student Name:</label>
                    <select id="studentName" required>
                        <option value="">Select Student</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="goalDate">Goal Date:</label>
                    <input type="date" id="goalDate" required>
                </div>
            </div>
            <div class="form-group">
                <label for="longTermGoals">Long Term Goals:</label>
                <textarea id="longTermGoals" rows="4" required></textarea>
            </div>
            <div class="form-group">
                <label for="shortTermObjectives">Short Term Objectives:</label>
                <textarea id="shortTermObjectives" rows="4"></textarea>
            </div>
            <div class="form-group">
                <label for="interventionStrategies">Intervention Strategies:</label>
                <textarea id="interventionStrategies" rows="3"></textarea>
            </div>
            <div class="form-group">
                <label for="measurementCriteria">Measurement Criteria:</label>
                <textarea id="measurementCriteria" rows="3"></textarea>
            </div>
        `,
        'session_report': `
            <div class="form-row">
                <div class="form-group">
                    <label for="studentName">Student Name:</label>
                    <select id="studentName" required>
                        <option value="">Select Student</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="sessionDate">Session Date:</label>
                    <input type="date" id="sessionDate" required>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label for="sessionDuration">Duration (minutes):</label>
                    <input type="number" id="sessionDuration" min="15" max="120" required>
                </div>
                <div class="form-group">
                    <label for="sessionType">Session Type:</label>
                    <select id="sessionType">
                        <option value="individual">Individual</option>
                        <option value="group">Group</option>
                        <option value="consultation">Consultation</option>
                    </select>
                </div>
            </div>
            <div class="form-group">
                <label for="objectivesTargeted">Objectives Targeted:</label>
                <textarea id="objectivesTargeted" rows="3"></textarea>
            </div>
            <div class="form-group">
                <label for="activitiesUsed">Activities/Materials Used:</label>
                <textarea id="activitiesUsed" rows="3"></textarea>
            </div>
            <div class="form-group">
                <label for="studentResponse">Student Response/Performance:</label>
                <textarea id="studentResponse" rows="4"></textarea>
            </div>
            <div class="form-group">
                <label for="nextSessionPlan">Plan for Next Session:</label>
                <textarea id="nextSessionPlan" rows="3"></textarea>
            </div>
        `,
        'progress_report': `
            <div class="form-row">
                <div class="form-group">
                    <label for="studentName">Student Name:</label>
                    <select id="studentName" required>
                        <option value="">Select Student</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="reportingPeriod">Reporting Period:</label>
                    <input type="text" id="reportingPeriod" placeholder="e.g., Q1 2024" required>
                </div>
            </div>
            <div class="form-group">
                <label for="progressSummary">Progress Summary:</label>
                <textarea id="progressSummary" rows="4" required></textarea>
            </div>
            <div class="form-group">
                <label for="goalProgress">Goal Progress:</label>
                <textarea id="goalProgress" rows="4"></textarea>
            </div>
            <div class="form-group">
                <label for="challenges">Challenges/Barriers:</label>
                <textarea id="challenges" rows="3"></textarea>
            </div>
            <div class="form-group">
                <label for="recommendations">Recommendations/Next Steps:</label>
                <textarea id="recommendations" rows="3"></textarea>
            </div>
        `,
        'discharge_report': `
            <div class="form-row">
                <div class="form-group">
                    <label for="studentName">Student Name:</label>
                    <select id="studentName" required>
                        <option value="">Select Student</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="dischargeDate">Discharge Date:</label>
                    <input type="date" id="dischargeDate" required>
                </div>
            </div>
            <div class="form-group">
                <label for="servicesSummary">Summary of Services Provided:</label>
                <textarea id="servicesSummary" rows="4" required></textarea>
            </div>
            <div class="form-group">
                <label for="goalsAchieved">Goals Achieved:</label>
                <textarea id="goalsAchieved" rows="3"></textarea>
            </div>
            <div class="form-group">
                <label for="dischargeReason">Reason for Discharge:</label>
                <textarea id="dischargeReason" rows="3" required></textarea>
            </div>
            <div class="form-group">
                <label for="followUpRecommendations">Follow-up Recommendations:</label>
                <textarea id="followUpRecommendations" rows="3"></textarea>
            </div>
        `
    };
    
    container.innerHTML = templates[formType] || '<p>Form template not found.</p>';
    container.setAttribute('data-form-type', formType);
    
    // Populate student dropdown in the form
    populateFormStudentSelect();
}

function populateFormStudentSelect() {
    const select = document.getElementById('studentName');
    if (!select) return;
    
    select.innerHTML = '<option value="">Select Student</option>';
    allStudents.forEach(student => {
        if (!student.archived) {
            const option = document.createElement('option');
            option.value = student.id;
            option.textContent = `${student.first_name} ${student.last_name}`;
            select.appendChild(option);
        }
    });
}

// Save document function
async function saveDocument() {
    const formContainer = document.getElementById('docModalForm');
    const formType = formContainer.getAttribute('data-form-type');
    const formData = new FormData();
    
    // Get all form inputs
    const inputs = formContainer.querySelectorAll('input, select, textarea');
    const docData = {};
    
    inputs.forEach(input => {
        docData[input.id] = input.value;
    });
    
    formData.append('action', 'save_document');
    formData.append('form_type', formType);
    formData.append('form_data', JSON.stringify(docData));
    
    try {
        const response = await fetch('includes/submit.php', {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            showNotification('Document saved successfully', 'success');
            closeDocModal();
            // Refresh the existing forms if we have a student selected
            if (document.getElementById('studentSelect').value) {
                await loadStudentForms();
            }
        } else {
            showNotification('Error saving document: ' + result.message, 'error');
        }
    } catch (error) {
        console.error('Error saving document:', error);
        showNotification('Error saving document', 'error');
    }
}

// Print document function
function printDocument() {
    window.print();
}

// Close modal function
function closeDocModal() {
    document.getElementById('documentationModal').style.display = 'none';
}

// Download blank PDF function
function downloadBlankPDF(formType) {
    const formTitles = {
        'initial_evaluation': 'Initial_Evaluation',
        'goals_form': 'Goals_Form',
        'session_report': 'Session_Report',
        'progress_report': 'Progress_Report',
        'discharge_report': 'Discharge_Report'
    };
    
    // Create a temporary window with the blank form for printing
    const printWindow = window.open('', '_blank');
    const formTitle = formTitles[formType] || 'Form';
    
    // Get the form template HTML
    const tempContainer = document.createElement('div');
    loadFormTemplateForPrint(formType, tempContainer);
    
    setTimeout(() => {
        const printContent = `
            <!DOCTYPE html>
            <html>
            <head>
                <title>${formTitle}</title>
                <style>
                    body { font-family: Arial, sans-serif; margin: 20px; line-height: 1.4; }
                    .form-group { margin-bottom: 20px; }
                    label { display: block; font-weight: bold; margin-bottom: 5px; }
                    input, select, textarea { 
                        width: 100%; 
                        border: none; 
                        border-bottom: 1px solid #ccc; 
                        padding: 8px 0; 
                        font-size: 14px;
                        background: transparent;
                    }
                    .form-row { display: flex; gap: 20px; margin-bottom: 20px; }
                    .form-row .form-group { flex: 1; }
                    h1 { text-align: center; border-bottom: 2px solid #333; padding-bottom: 10px; }
                    @media print { 
                        body { margin: 0; }
                        input, select, textarea { border-bottom: 1px solid #000; }
                    }
                </style>
            </head>
            <body>
                <h1>${formTitle.replace('_', ' ')}</h1>
                ${tempContainer.innerHTML}
            </body>
            </html>
        `;
        
        printWindow.document.write(printContent);
        printWindow.document.close();
        printWindow.print();
    }, 100);
}

function loadFormTemplateForPrint(formType, container) {
    // Similar templates but optimized for printing (no student select dropdown)
    const templates = {
        'initial_evaluation': `
            <div class="form-row">
                <div class="form-group">
                    <label for="studentName">Student Name:</label>
                    <input type="text" id="studentName" placeholder="________________________">
                </div>
                <div class="form-group">
                    <label for="evaluationDate">Evaluation Date:</label>
                    <input type="text" id="evaluationDate" placeholder="________________________">
                </div>
            </div>
            <div class="form-group">
                <label for="referralReason">Reason for Referral:</label>
                <textarea id="referralReason" rows="3" placeholder="____________________________________________&#10;____________________________________________&#10;____________________________________________"></textarea>
            </div>
            <div class="form-group">
                <label for="backgroundInfo">Background Information:</label>
                <textarea id="backgroundInfo" rows="4" placeholder="____________________________________________&#10;____________________________________________&#10;____________________________________________&#10;____________________________________________"></textarea>
            </div>
            <div class="form-group">
                <label for="assessmentResults">Assessment Results:</label>
                <textarea id="assessmentResults" rows="5" placeholder="____________________________________________&#10;____________________________________________&#10;____________________________________________&#10;____________________________________________&#10;____________________________________________"></textarea>
            </div>
            <div class="form-group">
                <label for="recommendations">Recommendations:</label>
                <textarea id="recommendations" rows="4" placeholder="____________________________________________&#10;____________________________________________&#10;____________________________________________&#10;____________________________________________"></textarea>
            </div>
        `,
        'goals_form': `
            <div class="form-row">
                <div class="form-group">
                    <label for="studentName">Student Name:</label>
                    <input type="text" id="studentName" placeholder="________________________">
                </div>
                <div class="form-group">
                    <label for="goalDate">Goal Date:</label>
                    <input type="text" id="goalDate" placeholder="________________________">
                </div>
            </div>
            <div class="form-group">
                <label for="longTermGoals">Long Term Goals:</label>
                <textarea id="longTermGoals" rows="4" placeholder="____________________________________________&#10;____________________________________________&#10;____________________________________________&#10;____________________________________________"></textarea>
            </div>
            <div class="form-group">
                <label for="shortTermObjectives">Short Term Objectives:</label>
                <textarea id="shortTermObjectives" rows="4" placeholder="____________________________________________&#10;____________________________________________&#10;____________________________________________&#10;____________________________________________"></textarea>
            </div>
            <div class="form-group">
                <label for="interventionStrategies">Intervention Strategies:</label>
                <textarea id="interventionStrategies" rows="3" placeholder="____________________________________________&#10;____________________________________________&#10;____________________________________________"></textarea>
            </div>
            <div class="form-group">
                <label for="measurementCriteria">Measurement Criteria:</label>
                <textarea id="measurementCriteria" rows="3" placeholder="____________________________________________&#10;____________________________________________&#10;____________________________________________"></textarea>
            </div>
        `,
        'session_report': `
            <div class="form-row">
                <div class="form-group">
                    <label for="studentName">Student Name:</label>
                    <input type="text" id="studentName" placeholder="________________________">
                </div>
                <div class="form-group">
                    <label for="sessionDate">Session Date:</label>
                    <input type="text" id="sessionDate" placeholder="________________________">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label for="sessionDuration">Duration (minutes):</label>
                    <input type="text" id="sessionDuration" placeholder="________________________">
                </div>
                <div class="form-group">
                    <label for="sessionType">Session Type:</label>
                    <input type="text" id="sessionType" placeholder="________________________">
                </div>
            </div>
            <div class="form-group">
                <label for="objectivesTargeted">Objectives Targeted:</label>
                <textarea id="objectivesTargeted" rows="3" placeholder="____________________________________________&#10;____________________________________________&#10;____________________________________________"></textarea>
            </div>
            <div class="form-group">
                <label for="activitiesUsed">Activities/Materials Used:</label>
                <textarea id="activitiesUsed" rows="3" placeholder="____________________________________________&#10;____________________________________________&#10;____________________________________________"></textarea>
            </div>
            <div class="form-group">
                <label for="studentResponse">Student Response/Performance:</label>
                <textarea id="studentResponse" rows="4" placeholder="____________________________________________&#10;____________________________________________&#10;____________________________________________&#10;____________________________________________"></textarea>
            </div>
            <div class="form-group">
                <label for="nextSessionPlan">Plan for Next Session:</label>
                <textarea id="nextSessionPlan" rows="3" placeholder="____________________________________________&#10;____________________________________________&#10;____________________________________________"></textarea>
            </div>
        `,
        'progress_report': `
            <div class="form-row">
                <div class="form-group">
                    <label for="studentName">Student Name:</label>
                    <input type="text" id="studentName" placeholder="________________________">
                </div>
                <div class="form-group">
                    <label for="reportingPeriod">Reporting Period:</label>
                    <input type="text" id="reportingPeriod" placeholder="________________________">
                </div>
            </div>
            <div class="form-group">
                <label for="progressSummary">Progress Summary:</label>
                <textarea id="progressSummary" rows="4" placeholder="____________________________________________&#10;____________________________________________&#10;____________________________________________&#10;____________________________________________"></textarea>
            </div>
            <div class="form-group">
                <label for="goalProgress">Goal Progress:</label>
                <textarea id="goalProgress" rows="4" placeholder="____________________________________________&#10;____________________________________________&#10;____________________________________________&#10;____________________________________________"></textarea>
            </div>
            <div class="form-group">
                <label for="challenges">Challenges/Barriers:</label>
                <textarea id="challenges" rows="3" placeholder="____________________________________________&#10;____________________________________________&#10;____________________________________________"></textarea>
            </div>
            <div class="form-group">
                <label for="recommendations">Recommendations/Next Steps:</label>
                <textarea id="recommendations" rows="3" placeholder="____________________________________________&#10;____________________________________________&#10;____________________________________________"></textarea>
            </div>
        `,
        'discharge_report': `
            <div class="form-row">
                <div class="form-group">
                    <label for="studentName">Student Name:</label>
                    <input type="text" id="studentName" placeholder="________________________">
                </div>
                <div class="form-group">
                    <label for="dischargeDate">Discharge Date:</label>
                    <input type="text" id="dischargeDate" placeholder="________________________">
                </div>
            </div>
            <div class="form-group">
                <label for="servicesSummary">Summary of Services Provided:</label>
                <textarea id="servicesSummary" rows="4" placeholder="____________________________________________&#10;____________________________________________&#10;____________________________________________&#10;____________________________________________"></textarea>
            </div>
            <div class="form-group">
                <label for="goalsAchieved">Goals Achieved:</label>
                <textarea id="goalsAchieved" rows="3" placeholder="____________________________________________&#10;____________________________________________&#10;____________________________________________"></textarea>
            </div>
            <div class="form-group">
                <label for="dischargeReason">Reason for Discharge:</label>
                <textarea id="dischargeReason" rows="3" placeholder="____________________________________________&#10;____________________________________________&#10;____________________________________________"></textarea>
            </div>
            <div class="form-group">
                <label for="followUpRecommendations">Follow-up Recommendations:</label>
                <textarea id="followUpRecommendations" rows="3" placeholder="____________________________________________&#10;____________________________________________&#10;____________________________________________"></textarea>
            </div>
        `
    };
    
    container.innerHTML = templates[formType] || '<p>Form template not found.</p>';
}

// Functions for existing form actions
function viewExistingForm(formId) {
    // Implementation for viewing existing form
    showNotification('View functionality coming soon', 'info');
}

function editExistingForm(formId) {
    // Implementation for editing existing form
    showNotification('Edit functionality coming soon', 'info');
}

function printExistingForm(formId) {
    // Implementation for printing existing form
    showNotification('Print functionality coming soon', 'info');
}

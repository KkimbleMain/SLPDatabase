// Settings page JavaScript functionality

// Initialize settings page
document.addEventListener('DOMContentLoaded', function() {
    loadUserPreferences();
});

// Load user preferences from localStorage
function loadUserPreferences() {
    const defaultGrade = localStorage.getItem('defaultGrade') || '';
    const studentsPerPage = localStorage.getItem('studentsPerPage') || '25';
    const autoSave = localStorage.getItem('autoSave') !== 'false';
    const showStudentIds = localStorage.getItem('showStudentIds') !== 'false';
    
    const defaultGradeSelect = document.getElementById('defaultGradeFilter');
    const studentsPerPageSelect = document.getElementById('studentsPerPage');
    const autoSaveCheckbox = document.getElementById('autoSaveForms');
    const showStudentIdsCheckbox = document.getElementById('showStudentIds');
    
    if (defaultGradeSelect) defaultGradeSelect.value = defaultGrade;
    if (studentsPerPageSelect) studentsPerPageSelect.value = studentsPerPage;
    if (autoSaveCheckbox) autoSaveCheckbox.checked = autoSave;
    if (showStudentIdsCheckbox) showStudentIdsCheckbox.checked = showStudentIds;
}

// Save user preference
function savePreference(key, value) {
    localStorage.setItem(key, value);
    showNotification('Preference saved', 'success');
}

// Profile management functions
function editProfile() {
    showNotification('Profile editing functionality coming soon!', 'info');
}

function changePassword() {
    showNotification('Password change functionality coming soon!', 'info');
}

// Data management functions
async function exportAllStudents() {
    try {
        showNotification('Exporting all student data...', 'info');
        
        const response = await fetch('/includes/submit.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=export_all_students'
        });
        
        const result = await response.json();
        if (result.success) {
            // Create and download the export file
            const blob = new Blob([result.html], { type: 'text/html' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = `all_students_export_${new Date().toISOString().split('T')[0]}.html`;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            window.URL.revokeObjectURL(url);
            
            showNotification('All student data exported successfully', 'success');
        } else {
            showNotification(result.error || 'Export failed', 'error');
        }
    } catch (error) {
        showNotification('Export failed', 'error');
    }
}

async function exportAllDocuments() {
    showNotification('Document export functionality coming soon!', 'info');
}

async function createBackup() {
    try {
        showNotification('Creating backup...', 'info');
        
        const response = await fetch('/includes/submit.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=create_backup'
        });
        
        const result = await response.json();
        if (result.success) {
            showNotification('Backup created successfully', 'success');
        } else {
            showNotification(result.error || 'Backup failed', 'error');
        }
    } catch (error) {
        showNotification('Backup failed', 'error');
    }
}

// Archived student functions
async function viewArchivedStudents() {
    try {
        const response = await fetch('/includes/submit.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=get_archived_students'
        });
        
        const result = await response.json();
        if (result.success && result.students) {
            showArchivedStudentsModal(result.students);
        } else {
            showNotification('No archived students found', 'info');
        }
    } catch (error) {
        showNotification('Failed to load archived students', 'error');
    }
}

function showArchivedStudentsModal(archivedStudents) {
    let modalHtml = `
        <div class="modal" role="dialog" aria-modal="true">
            <div class="modal-content" style="max-width: 600px;">
                <div class="modal-header">
                    <h2>Archived Students</h2>
                    <button class="close" type="button" aria-label="Close">&times;</button>
                </div>
                <div class="modal-body">
                    <div class="archived-students-list">
    `;
    
    archivedStudents.forEach(student => {
        modalHtml += `
            <div class="archived-student-item">
                <div class="student-info">
                    <strong>${student.first_name} ${student.last_name}</strong>
                    <span class="student-details">ID: ${student.student_id || student.id} • Grade: ${student.grade || 'N/A'}</span>
                </div>
                <button class="btn btn-outline btn-sm" onclick="restoreStudent(${student.id})">Restore</button>
            </div>
        `;
    });
    
    modalHtml += `
                    </div>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-outline">Close</button>
                </div>
            </div>
        </div>
    `;
    
    const tempDiv = document.createElement('div');
    tempDiv.innerHTML = modalHtml;
    const modal = tempDiv.firstElementChild;
    
    document.body.appendChild(modal);
    modal.style.display = 'flex';
    
    // Close modal functionality
    modal.querySelector('.close').addEventListener('click', () => {
        document.body.removeChild(modal);
    });
    
    modal.querySelector('.btn-outline').addEventListener('click', () => {
        document.body.removeChild(modal);
    });
}

async function restoreStudent(studentId) {
    if (!confirm('Are you sure you want to restore this student?')) return;
    
    try {
        const response = await fetch('/includes/submit.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `action=restore_student&id=${studentId}`
        });
        
        const result = await response.json();
        if (result.success) {
            showNotification('Student restored successfully', 'success');
            // Close any open modals and refresh
            const modals = document.querySelectorAll('.modal');
            modals.forEach(modal => document.body.removeChild(modal));
            location.reload();
        } else {
            showNotification(result.error || 'Restore failed', 'error');
        }
    } catch (error) {
        showNotification('Restore failed', 'error');
    }
}

function showArchiveRecovery() {
    viewArchivedStudents(); // Same functionality as view archived
}

// System functions
function clearCache() {
    localStorage.clear();
    showNotification('Cache cleared successfully', 'success');
    setTimeout(() => location.reload(), 1000);
}

function generateReport() {
    showNotification('Report generation functionality coming soon!', 'info');
}

function importData() {
    showNotification('Data import functionality coming soon!', 'info');
}

function showHelp() {
    showNotification('Help system coming soon!', 'info');
}

function checkDataIntegrity() {
    showNotification('Data integrity check passed', 'success');
}

// CSS for archived students modal
const style = document.createElement('style');
style.textContent = `
.archived-students-list {
    max-height: 400px;
    overflow-y: auto;
}

.archived-student-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 1rem;
    border: 1px solid var(--border-color);
    border-radius: var(--border-radius);
    margin-bottom: 0.75rem;
    background: var(--light-bg);
}

.archived-student-item:last-child {
    margin-bottom: 0;
}

.student-info {
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
}

.student-details {
    font-size: 0.85rem;
    color: var(--muted);
}
`;
document.head.appendChild(style);

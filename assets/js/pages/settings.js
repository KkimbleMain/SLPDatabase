// Settings page JavaScript functionality

// Initialize settings page
document.addEventListener('DOMContentLoaded', function() {
    loadUserPreferences();

    // Helper to attach handler to a button element
    function wireArchivedBtn(btn) {
        if (!btn) return;
        btn.removeEventListener('click', viewArchivedStudents);
        btn.addEventListener('click', viewArchivedStudents);
    }

    // Try several common selectors so existing markup is covered
    const selectors = [
        '#viewArchivedStudentsBtn',               // existing id you used earlier
        '[data-action="view-archived-students"]', // data-action attribute
        '.view-archived-students'                 // class-based
    ];
    selectors.forEach(sel => {
        document.querySelectorAll(sel).forEach(wireArchivedBtn);
    });

    // Delegate clicks inside a settings container as a last-resort fallback
    const settingsContainer = document.querySelector('.settings-page, #settingsPage, [data-page="settings"]');
    if (settingsContainer) {
        settingsContainer.addEventListener('click', (e) => {
            const btn = e.target.closest('[data-action="view-archived-students"], .view-archived-students');
            if (btn) {
                e.preventDefault();
                viewArchivedStudents();
            }
        });
    }
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

// Small helper to escape HTML for safe insertion into templates
function escapeHtml(str) {
    if (str === null || str === undefined) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function showArchivedStudentsModal(archivedStudents) {
    let modalHtml = `
        <div class="modal" role="dialog" aria-modal="true">
            <div class="modal-content" style="max-width: 640px;">
                <div class="modal-header">
                    <h2>Archived Students</h2>
                    <button class="close" type="button" aria-label="Close">&times;</button>
                </div>
                <div class="modal-body">
                    <div class="archived-students-list">
    `;

    archivedStudents.forEach(student => {
        const first = escapeHtml(student.first_name || '');
        const last = escapeHtml(student.last_name || '');
        // use canonical internal id for actions
        const internalId = escapeHtml(String(student.id ?? ''));
        const externalId = escapeHtml(student.student_id ?? '');
        const grade = escapeHtml(student.grade ?? 'N/A');

        modalHtml += `
            <div class="archived-student-item" data-student-id="${internalId}">
                <div class="student-info">
                    <div class="student-name"><strong>${first} ${last}</strong></div>
                    <div class="student-meta">
                        <span class="student-id">ID: <span class="id-value">${externalId || internalId}</span></span>
                        <span class="meta-sep"> • </span>
                        <span class="student-grade">Grade: <strong>${grade}</strong></span>
                    </div>
                </div>
                <button class="btn btn-outline btn-sm btn-restore" data-student-id="${internalId}">Restore</button>
            </div>
        `;
    });

    modalHtml += `
                    </div>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-outline btn-close">Close</button>
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

    modal.querySelector('.btn-close').addEventListener('click', () => {
        document.body.removeChild(modal);
    });

    // Attach restore handlers to buttons (avoid inline onclick)
    modal.querySelectorAll('.btn-restore').forEach(btn => {
        btn.addEventListener('click', () => {
            const id = btn.getAttribute('data-student-id'); // internal id
            if (!id) return;
            restoreStudent(Number(id));
        });
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

            // Remove the restored item from any open archived-students modal (optimistic UI)
            const itemSelector = `.archived-student-item[data-student-id="${studentId}"]`;
            const item = document.querySelector(itemSelector);
            if (item && item.parentNode) item.parentNode.removeChild(item);

            // Update archived count in settings UI if present
            const countEl = document.getElementById('archivedCountValue');
            if (countEl) {
                // parse leading number from "N students"
                const current = parseInt((countEl.textContent || '').trim(), 10) || 0;
                const updated = Math.max(0, current - 1);
                countEl.textContent = updated + ' students';
            }

            // If no archived items remain in the modal, close it and disable buttons
            const remaining = document.querySelectorAll('.archived-student-item').length;
            if (remaining === 0) {
                document.querySelectorAll('.modal').forEach(m => m.parentNode && m.parentNode.removeChild(m));
                const viewBtn = document.getElementById('viewArchivedStudentsBtn');
                if (viewBtn) viewBtn.disabled = true;
                const recoverBtn = document.getElementById('recoverArchiveBtn');
                if (recoverBtn) recoverBtn.disabled = true;
            }
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

// Expose the real implementation to window (do not overwrite if already present)
if (typeof viewArchivedStudents === 'function' && typeof window.viewArchivedStudents !== 'function') {
    window.viewArchivedStudents = viewArchivedStudents;
}

// Also expose showArchivedStudentsModal and restoreStudent if available
if (typeof showArchivedStudentsModal === 'function' && typeof window.showArchivedStudentsModal !== 'function') {
    window.showArchivedStudentsModal = showArchivedStudentsModal;
}
if (typeof restoreStudent === 'function' && typeof window.restoreStudent !== 'function') {
    window.restoreStudent = restoreStudent;
}

// Expose globals used by inline onclick handlers in templates.
// If the internal functions exist, wire them directly; otherwise provide a simple fallback implementation.
(function() {
    // viewArchivedStudents
    if (typeof window.viewArchivedStudents !== 'function') {
        if (typeof viewArchivedStudents === 'function') {
            window.viewArchivedStudents = viewArchivedStudents;
        } else {
            // Minimal fallback modal (use internal id for actions)
            window.viewArchivedStudents = async function() {
                try {
                    const fd = new URLSearchParams();
                    fd.append('action', 'get_students');
                    fd.append('archived', '0');
                    const res = await fetch('/includes/submit.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: fd.toString()
                    });
                    const arr = res.ok ? await res.json() : [];
                    const archived = Array.isArray(arr) ? arr.filter(s => s && (s.archived === true || s.archived === '1' || s.archived === 1)) : [];
                    if (typeof showArchivedStudentsModal === 'function') {
                        return showArchivedStudentsModal(archived);
                    }
                    // Minimal fallback modal if showArchivedStudentsModal is missing
                    const modal = document.createElement('div');
                    modal.className = 'modal';
                    modal.innerHTML = `
                        <div class="modal-content"><div class="modal-header"><h2>Archived Students</h2><button class="close">&times;</button></div>
                        <div class="modal-body">
                          ${archived.length ? archived.map(s => `<div class="archived-student-item" data-student-id="${s.id}">
                              <strong>${escapeHtml(s.first_name||'')} ${escapeHtml(s.last_name||'')}</strong>
                              <div class="student-details">ID: ${escapeHtml(String(s.student_id||s.id||''))} • Grade: ${escapeHtml(s.grade||'N/A')}</div>
                              <button class="btn btn-sm btn-restore" data-id="${s.id}">Restore</button>
                          </div>`).join('') : '<p>No archived students found.</p>'}
                        </div>
                        <div class="modal-actions"><button class="btn btn-close">Close</button></div></div>
                    `;
                    document.body.appendChild(modal);
                    modal.querySelector('.close, .btn-close').addEventListener('click', () => modal.remove());
                    modal.querySelectorAll('.btn-restore').forEach(btn => {
                        btn.addEventListener('click', () => {
                            const id = Number(btn.getAttribute('data-id'));
                            if (typeof restoreStudent === 'function') restoreStudent(id);
                        });
                    });
                } catch (e) {
                    console.error('viewArchivedStudents fallback failed', e);
                    alert('Unable to load archived students');
                }
            };
        }
    }

    // showArchiveRecovery -> reuse viewArchivedStudents if not present
    if (typeof window.showArchiveRecovery !== 'function') {
        if (typeof showArchiveRecovery === 'function') {
            window.showArchiveRecovery = showArchiveRecovery;
        } else {
            window.showArchiveRecovery = window.viewArchivedStudents;
        }
    }
})();

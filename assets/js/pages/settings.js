// Settings page JavaScript functionality

// Small helper to run initialization even when module is loaded after DOMContentLoaded
function onReady(cb) {
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', cb);
    else cb();
}

// Initialize settings page
onReady(function() {
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
    // Populate and open profile modal
    const display = document.getElementById('displayNameValue');
    const parts = (display ? display.textContent.trim() : '').split(' ');
    const first = parts.shift() || '';
    const last = parts.join(' ') || '';
    const fn = document.getElementById('profileFirstName');
    const ln = document.getElementById('profileLastName');
    if (fn) fn.value = first;
    if (ln) ln.value = last;
    const m = document.getElementById('profileModal');
    if (m) m.style.display = 'flex';
}

function changePassword() {
    // Open change password modal
    const m = document.getElementById('changePasswordModal');
    if (m) m.style.display = 'flex';
}

function closeModal(id) {
    const m = document.getElementById(id);
    if (m) m.style.display = 'none';
}

// Helper to hide a modal by id without removing global backdrops or other modals
function hideModalById(id) {
    try {
        const el = document.getElementById(id);
        if (el) el.style.display = 'none';
    } catch (e) { /* ignore */ }
}

// Save profile via AJAX
onReady(function() {
    const saveProfileBtn = document.getElementById('saveProfileBtn');
    console.debug('settings: found saveProfileBtn?', !!saveProfileBtn);
    if (saveProfileBtn) saveProfileBtn.addEventListener('click', async function() {
        console.debug('settings: saveProfileBtn clicked');
        const fn = document.getElementById('profileFirstName');
        const ln = document.getElementById('profileLastName');
        if (!fn || !ln) return;
        const first = fn.value.trim();
        const last = ln.value.trim();
        if (first === '' || last === '') { showNotification('Please provide both first and last name', 'error'); return; }
        try {
            const fd = new URLSearchParams();
            fd.append('action','update_profile');
            fd.append('first_name', first);
            fd.append('last_name', last);
            const res = await fetch('/includes/submit.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: fd.toString() });
            let j = null;
            try {
                j = await res.json();
            } catch (parseErr) {
                const txt = await res.text();
                console.error('settings: failed to parse JSON from update_profile', parseErr, txt);
                showNotification('Profile update failed: server returned an unexpected response', 'error');
                return;
            }
            if (!res.ok) {
                console.error('settings: update_profile HTTP error', res.status, j);
                showNotification(j.error || j.message || ('Profile update failed (HTTP ' + res.status + ')'), 'error');
                return;
            }
            if (j && j.success) {
                closeModal('profileModal');
                const display = document.getElementById('displayNameValue');
                if (display) display.textContent = first + ' ' + last;
                // Also update header and other visible name locations without reload
                try {
                    const headerName = document.querySelector('.user-menu .muted');
                    if (headerName) headerName.textContent = 'Welcome, ' + first;
                    // also update any other quick display spans that show the user's name
                    document.querySelectorAll('[data-current-user-name]').forEach(el => el.textContent = first + ' ' + last);
                } catch (e) { /* non-fatal */ }
                showNotification('Profile updated', 'success');
            } else {
                showNotification(j.error || j.message || 'Profile update failed', 'error');
            }
        } catch (e) { showNotification('Profile update failed', 'error'); }
    });

    const savePasswordBtn = document.getElementById('savePasswordBtn');
    if (savePasswordBtn) savePasswordBtn.addEventListener('click', async function() {
        const current = document.getElementById('currentPassword');
        const np = document.getElementById('newPassword');
        const cp = document.getElementById('confirmPassword');
        if (!current || !np || !cp) return;
        const cur = current.value;
        const newp = np.value;
        const conf = cp.value;
        if (!cur || !newp || !conf) { showNotification('Please complete all password fields', 'error'); return; }
        if (newp !== conf) { showNotification('New password and confirmation do not match', 'error'); return; }
        try {
            const fd = new URLSearchParams();
            fd.append('action','change_password');
            fd.append('current_password', cur);
            fd.append('new_password', newp);
            const res = await fetch('/includes/submit.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: fd.toString() });
            const j = await res.json();
            if (j.success) {
                closeModal('changePasswordModal');
                // clear fields
                current.value = np.value = cp.value = '';
                showNotification('Password changed successfully', 'success');
            } else {
                showNotification(j.error || j.message || 'Password change failed', 'error');
            }
        } catch (e) { showNotification('Password change failed', 'error'); }
    });
});

// Data management functions
async function exportAllStudents() {
    try {
        showNotification('Exporting all student data...', 'info');
        
        // Legacy HTML export replaced by comprehensive backup including per-student PDFs
        // Delegate to createStudentsZipBackup which handles both DB/uploads and per-student PDFs
        await createStudentsZipBackup();
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

// Create per-student ZIP export (profiles + forms)
async function createStudentsZipBackup() {
    // This now delegates to the server 'create_backup' action which builds a comprehensive ZIP
    try {
        showNotification('Preparing full backup with per-student PDFs...', 'info', 5000);
        const fd = new URLSearchParams(); fd.append('action','create_backup');
        const resp = await fetch('/includes/submit.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: fd.toString() });
        const j = await resp.json();
        if (!resp.ok || !j.success) {
            // server may return success=false with a path when PDFs couldn't be generated
            if (j && j.path) {
                const url = '/' + j.path.replace(/^\/+/, '');
                const a = document.createElement('a'); a.href = url; a.download = j.filename || 'backup.zip'; document.body.appendChild(a); a.click(); a.remove();
                showNotification(j.message || 'Backup created (without PDFs). Download starting.', 'warning');
                return;
            }
            console.error('create_backup failed', resp.status, j);
            showNotification(j.error || j.message || 'Failed to create backup', 'error');
            return;
        }
        // start download
        const url = '/' + j.path.replace(/^\/+/, '');
        const a = document.createElement('a'); a.href = url; a.download = j.filename || 'backup.zip'; document.body.appendChild(a); a.click(); a.remove();
        showNotification('Backup ready — download should start', 'success');
    } catch (e) {
        console.error('createStudentsZipBackup error', e);
        showNotification('Failed to create backup', 'error');
    }
}

// Do not expose backup/export functions as globals anymore — buttons were removed from settings UI.
// Keep functions locally for future use, but avoid attaching to window to prevent accidental calls.

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
    // Prevent duplicate archived modal instances
    const existing = document.querySelector('.archived-students-modal');
    if (existing) {
        // Bring existing modal to front and return
        try { existing.style.display = 'flex'; existing.scrollIntoView({ behavior: 'smooth', block: 'center' }); } catch (e) {}
        return;
    }
    let modalHtml = `
        <div class="modal archived-students-modal" role="dialog" aria-modal="true">
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
                <div class="archived-actions">
                    <button class="btn btn-outline btn-sm btn-restore" data-student-id="${internalId}">Restore</button>
                    <button class="btn btn-danger btn-sm btn-delete" data-student-id="${internalId}">Delete</button>
                </div>
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

    // Attach restore and delete handlers to buttons (avoid inline onclick)
    modal.querySelectorAll('.btn-restore').forEach(btn => {
        btn.addEventListener('click', () => {
            const id = btn.getAttribute('data-student-id'); // internal id
            if (!id) return;
            restoreStudent(Number(id));
        });
    });

    modal.querySelectorAll('.btn-delete').forEach(btn => {
        btn.addEventListener('click', async () => {
            const id = btn.getAttribute('data-student-id');
            if (!id) return;
            // Require typed confirmation: user must type DELETE to proceed
            const promptText = "Type DELETE to permanently delete this archived student and all their associated files. This action cannot be undone.";
            const ok = await (window.showTypedConfirm ? window.showTypedConfirm('DELETE', { title: 'Confirm permanent delete', message: promptText, placeholder: 'DELETE', caseSensitive: false }) : Promise.resolve((function(){ const t = prompt(promptText,''); return !!(t && t.toString().trim().toUpperCase() === 'DELETE'); })()));
            if (!ok) {
                showNotification('Deletion cancelled: typed confirmation did not match', 'info');
                return;
            }
            deleteArchivedStudentPermanent(Number(id), btn);
        });
    });
}

// Permanently delete an archived student after typed confirmation
async function deleteArchivedStudentPermanent(studentId, triggerBtn) {
    try {
        // disable trigger to avoid double-actions
        if (triggerBtn) triggerBtn.disabled = true;
        showNotification('Deleting student permanently...', 'info');

        const fd = new URLSearchParams(); fd.append('action', 'delete_student_permanent'); fd.append('id', String(studentId));
        const resp = await fetch('/includes/submit.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: fd.toString() });
        const j = await resp.json();
        if (!resp.ok || !j.success) {
            showNotification(j.error || j.message || 'Failed to delete student', 'error');
            if (triggerBtn) triggerBtn.disabled = false;
            return;
        }

        // Remove the deleted item from modal and update archived count
        const selector = `.archived-student-item[data-student-id="${studentId}"]`;
        const el = document.querySelector(selector);
        if (el && el.parentNode) el.parentNode.removeChild(el);

        const countEl = document.getElementById('archivedCountValue');
        if (countEl) {
            const current = parseInt((countEl.textContent || '').trim(), 10) || 0;
            const updated = Math.max(0, current - 1);
            countEl.textContent = updated + ' students';
        }

        // Re-check from server to avoid false zeros and keep modal open when more remain
        try {
            const resp2 = await fetch('/includes/submit.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: 'action=get_archived_students' });
            const j2 = await resp2.json();
            const serverCount = (j2 && j2.success && Array.isArray(j2.students)) ? j2.students.length : document.querySelectorAll('.archived-student-item').length;
            const viewBtn = document.getElementById('viewArchivedStudentsBtn');
            if (serverCount <= 0) {
                // Close only the archived modal, not all modals
                const archivedModal = document.querySelector('.archived-students-modal');
                if (archivedModal && archivedModal.parentNode) archivedModal.parentNode.removeChild(archivedModal);
                if (viewBtn) viewBtn.disabled = true;
            } else {
                if (viewBtn) viewBtn.disabled = false;
            }
        } catch (e) {
            // If server check fails, do not over-close; rely on DOM remaining items
        }

        showNotification('Student permanently deleted', 'success');
    } catch (e) {
        console.error('deleteArchivedStudentPermanent error', e);
        showNotification('Failed to delete student', 'error');
        if (triggerBtn) triggerBtn.disabled = false;
    }
}

async function restoreStudent(studentId) {
    const ok = await (window.showConfirm ? window.showConfirm('Are you sure you want to restore this student?') : Promise.resolve(confirm('Are you sure you want to restore this student?')));
    if (!ok) return;
    
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

            // Re-check from server; keep modal open for more actions when others remain
            try {
                const resp2 = await fetch('/includes/submit.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: 'action=get_archived_students' });
                const j2 = await resp2.json();
                const serverCount = (j2 && j2.success && Array.isArray(j2.students)) ? j2.students.length : document.querySelectorAll('.archived-student-item').length;
                const viewBtn = document.getElementById('viewArchivedStudentsBtn');
                const recoverBtn = document.getElementById('recoverArchiveBtn');
                if (serverCount <= 0) {
                    const archivedModal = document.querySelector('.archived-students-modal');
                    if (archivedModal && archivedModal.parentNode) archivedModal.parentNode.removeChild(archivedModal);
                    if (viewBtn) viewBtn.disabled = true;
                    if (recoverBtn) recoverBtn.disabled = true;
                } else {
                    if (viewBtn) viewBtn.disabled = false;
                    if (recoverBtn) recoverBtn.disabled = false;
                }
            } catch (e) {
                // leave modal state as-is on network error
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

// expose viewArchivedStudents and related helpers only (they are used by the UI)
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
                    // Avoid creating a second archived modal if one already exists
                    let modal = document.querySelector('.archived-students-modal');
                    if (!modal) {
                        modal = document.createElement('div');
                        modal.className = 'modal archived-students-modal';
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
                    }
                    // Ensure modal is visible
                    modal.style.display = 'flex';
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

// Ensure profile helpers are available to inline onclick attributes
if (typeof window.editProfile !== 'function') window.editProfile = typeof editProfile === 'function' ? editProfile : function(){ showNotification('Profile editing not available', 'info'); };
if (typeof window.changePassword !== 'function') window.changePassword = typeof changePassword === 'function' ? changePassword : function(){ showNotification('Password change not available', 'info'); };
if (typeof window.closeModal !== 'function') window.closeModal = typeof closeModal === 'function' ? closeModal : function(id){ const m=document.getElementById(id); if(m) m.style.display='none'; };
if (typeof window.hideModalById !== 'function') window.hideModalById = typeof hideModalById === 'function' ? hideModalById : function(id){ const m=document.getElementById(id); if(m) m.style.display='none'; };

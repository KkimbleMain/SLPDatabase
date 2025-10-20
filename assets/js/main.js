// SLP Database Main JavaScript - Modular
import { apiFetch } from './api.js';
import { showNotification, closeModal, insertModal } from './ui.js';
// The modal modules live at assets/js/*.js (no nested 'modals' folder)
import { showAddStudentModal } from './pages/students.js';
import { showAddGoalModal } from './pages/goals.js';
import progressModule from './pages/progress.js';

// quickProgress helper removed — use showAddProgressModal directly from the progress module

document.addEventListener('DOMContentLoaded', () => {
    // Footer modal wiring (moved from footer.php)
    document.addEventListener('click', function(e){
        try {
            const t = e.target;
            if (t.matches && t.matches('.footer-container a[data-modal]')) {
                e.preventDefault();
                const which = t.getAttribute('data-modal');
                const tpl = document.getElementById('tmpl-footer-' + which);
                if (!tpl) return;
                const frag = tpl.content.cloneNode(true);
                const container = document.createElement('div');
                container.className = 'footer-modal-host';
                container.appendChild(frag);
                document.body.appendChild(container);
                container.querySelectorAll('.close-modal, .modal-overlay').forEach(btn => btn.addEventListener('click', () => { try { container.remove(); } catch (e) { container.style.display = 'none'; } }));
            }
        } catch (err) { console.warn('footer modal error', err); }
    });
    function attachModalHandlers(root = document) {
        // ensure buttons with data-open-modal open the templates and allow optional preselect
        root.querySelectorAll('[data-open-modal]').forEach(btn => {
            btn.addEventListener('click', (e) => {
                const id = btn.getAttribute('data-open-modal');
                const tpl = document.getElementById(id);
                if (!tpl) return;
                const clone = tpl.content.cloneNode(true);
                const container = document.createElement('div');
                container.appendChild(clone);
                // use central insertModal to avoid duplicates and ensure consistent close styling
                insertModal(container);
                // preselect student if provided
                const preId = btn.getAttribute('data-student-id');
                if (preId) {
                    const sel = container.querySelector('select[name="student_id"]');
                    if (sel) sel.value = preId;
                    // also set hidden inputs if present
                    const hid = container.querySelector('input[name="student_id"]');
                    if (hid) hid.value = preId;
                }
                // copy goal id if provided
                const goalId = btn.getAttribute('data-goal-id');
                if (goalId) {
                    const hidGoal = container.querySelector('input[name="goal_id"]');
                    if (hidGoal) hidGoal.value = goalId;
                    // attach as data attribute for modules that read it
                    const modalRoot = container.querySelector('.modal');
                    if (modalRoot) modalRoot.setAttribute('data-goal-id', goalId);
                }
                attachModalHandlers(container);
                // Attach default submit handlers for known template forms so they use AJAX
                // Include addStudentForm and addGoalForm here so templates opened via data-open-modal are wired.
                container.querySelectorAll('form#addGoalForm, form#addStudentForm').forEach(form => {
                    if (form.dataset.slpInitialized) return;
                    form.dataset.slpInitialized = '1';
                    form.addEventListener('submit', async (e) => {
                        e.preventDefault();
                        const fd = new FormData(form);
                        // normalize fields
                        if (form.id === 'addGoalForm') {
                            if (!fd.get('description') && fd.get('goal_text')) fd.set('description', fd.get('goal_text'));
                            fd.append('action', 'add_goal');
                        } else if (form.id === 'addStudentForm') {
                            fd.append('action', 'add_student');
                        }
                        try {
                            showNotification('Saving...', 'info');
                            const res = await apiFetch('/includes/submit.php', { method: 'POST', body: fd });
                            if (res && res.success) {
                                showNotification('Saved successfully', 'success');
                                const modal = form.closest('.modal'); if (modal) modal.remove();
                                setTimeout(() => location.reload(), 700);
                            } else {
                                showNotification((res && res.error) ? res.error : 'Failed to save', 'error');
                                // allow retry
                                delete form.dataset.slpInitialized;
                            }
                        } catch (err) {
                            showNotification('Network error: ' + err.message, 'error');
                            // allow retry
                            delete form.dataset.slpInitialized;
                        }
                    });
                });
            });
        });
    }

    // expose so other helpers can reuse it
    window.attachModalHandlers = attachModalHandlers;

    // open modal on buttons with data-open-modal (existing behavior)
    document.querySelectorAll('[data-open-modal]').forEach(btn => {
        btn.addEventListener('click', (e) => {
            const id = btn.getAttribute('data-open-modal');
            // Prefer page module helper for the add-student modal so it namespaces ids and attaches handlers correctly
            if (id === 'tmpl-add-student' && typeof showAddStudentModal === 'function') {
                const preId = btn.getAttribute('data-student-id');
                try { showAddStudentModal(preId); } catch (err) { console.warn('showAddStudentModal failed, falling back', err); }
                return;
            }
            const tpl = document.getElementById(id);
            if (!tpl) return;
            const clone = tpl.content.cloneNode(true);
            const container = document.createElement('div');
            container.appendChild(clone);
            // ensure use of insertModal (dedupe/backdrop/close handlers)
            insertModal(container);
            // preselect student if provided
            const preId = btn.getAttribute('data-student-id');
            if (preId) {
                const sel = container.querySelector('select[name="student_id"]');
                if (sel) sel.value = preId;
            }
            attachModalHandlers(container);
        });
    });

    // handle progress quick buttons (open progress view or add-skill modal)
    document.querySelectorAll('[data-open-progress]').forEach(btn => {
        btn.addEventListener('click', (e) => {
            const sid = btn.getAttribute('data-student-id');
            if (!sid) return;
            const urlParams = new URLSearchParams(window.location.search);
            const view = urlParams.get('view') || 'dashboard';
            if (view === 'progress' && typeof window.initializeProgress === 'function') {
                // We're already on the progress page for some student — dispatch event to open add-skill modal
                const ev = new CustomEvent('openAddSkill', { detail: { student_id: Number(sid) } });
                document.dispatchEvent(ev);
                return;
            }
            // Navigate to the progress view with the student selected
            window.location.href = '?view=progress&student_id=' + encodeURIComponent(sid);
        });
    });

    // Progress page: select change and initial init (moved from templates/progress.php)
    try {
        const select = document.getElementById('progressStudentSelect');
        const addBtn = document.getElementById('addProgressBtn');
        if (select) {
            // Define handler if not present
            if (typeof window.handleProgressSelectChange !== 'function') {
                window.handleProgressSelectChange = function(val) {
                    try {
                        const sel = document.getElementById('progressStudentSelect');
                        const studentId = (typeof val !== 'undefined' && val !== null) ? String(val) : (sel ? sel.value : '');
                        const newUrl = '?view=progress' + (studentId ? ('&student_id=' + encodeURIComponent(studentId)) : '');
                        try { history.pushState({}, '', newUrl); } catch (e) {}

                        const nameEl = document.getElementById('progressStudentName');
                        if (nameEl) {
                            if (studentId && sel) {
                                const option = sel.options[sel.selectedIndex];
                                const txt = option ? (option.textContent || '') : '';
                                nameEl.textContent = txt ? txt.split(' - ')[0] : '\u00A0';
                            } else {
                                nameEl.textContent = '\u00A0';
                            }
                        }

                        // Ensure overview exists
                        let overview = document.querySelector('.progress-overview');
                        if (!studentId) {
                            if (overview) try { overview.remove(); } catch (e) { overview.style.display = 'none'; }
                            const noSel = document.querySelector('.no-student-selected'); if (noSel) noSel.style.display = 'block';
                            const addBtn = document.getElementById('addProgressBtn'); if (addBtn) { addBtn.disabled = true; addBtn.classList.add('btn-disabled'); addBtn.textContent = 'Create Progress Report'; try { addBtn.removeAttribute('data-student-id'); } catch(e) {} }
                            const createBtn = document.getElementById('createReport'); if (createBtn) { createBtn.disabled = true; createBtn.classList.add('btn-disabled'); }
                            const delBtn = document.getElementById('deleteReport'); if (delBtn) { delBtn.disabled = true; delBtn.classList.add('btn-disabled'); }
                            document.querySelectorAll('.no-progress, .progress-stats, .progress-chart-container, .progress-history, .skills-list').forEach(el => { if (el) el.style.display = 'none'; });
                            return;
                        }

                        const noSel = document.querySelector('.no-student-selected'); if (noSel) noSel.style.display = 'none';
                        if (!overview) {
                            overview = document.createElement('div'); overview.className = 'progress-overview';
                            const toolbar = document.querySelector('.progress-toolbar');
                            if (toolbar && toolbar.parentNode) toolbar.parentNode.insertBefore(overview, toolbar.nextSibling);
                            else document.querySelector('.container')?.appendChild(overview);
                        }
                        if (progressModule && typeof progressModule.initializeProgress === 'function') try { progressModule.initializeProgress(Number(studentId)); } catch (e) { console.error('initializeProgress failed', e); }
                    } catch (err) { console.error('handleProgressSelectChange error', err); }
                };
            }
            // Bind change event to avoid inline onchange in template
            try { select.addEventListener('change', (e) => window.handleProgressSelectChange(e.target.value)); } catch (e) {}

            // Initial auto-init on page load when selected
            const sid = select && select.value ? select.value : (function(){ try { const p = new URLSearchParams(window.location.search); return p.get('student_id') || p.get('id') || ''; } catch(e){ return ''; } })();
            // Set the visible student name in reserved element to avoid layout shift
            try {
                const nameElInit = document.getElementById('progressStudentName');
                if (nameElInit) {
                    if (select && select.value && select.selectedIndex >= 0) {
                        const opt = select.options[select.selectedIndex];
                        const txt = opt ? (opt.textContent || '') : '';
                        nameElInit.textContent = txt ? txt.split(' - ')[0] : '\u00A0';
                    } else {
                        nameElInit.textContent = '\u00A0';
                    }
                }
            } catch (e) { /* ignore */ }
            if (sid && progressModule && typeof progressModule.initializeProgress === 'function') {
                try { progressModule.initializeProgress(Number(sid)); } catch (e) { console.error(e); }
            }
        }

        // Add Progress button: open Add Skill modal for selected student
        if (addBtn) {
            addBtn.addEventListener('click', async function(){
                const getSidFromUrl = () => { try { const p = new URLSearchParams(window.location.search); return p.get('student_id') || p.get('id') || ''; } catch (e) { return ''; } };
                let sid2 = (select && select.value) ? select.value : (this.getAttribute('data-student-id') || '');
                if (!sid2) sid2 = getSidFromUrl() || '';
                if (!sid2) { alert('Select a student first'); return; }
                // Behavior:
                // - If a report exists: open Add Skill modal
                // - If no report: prompt to create (do NOT auto-open add-skill after)
                try {
                    // Ask server for latest report; if present, open Add Skill
                    const fd = new URLSearchParams(); fd.append('action','get_latest_progress_report'); fd.append('student_id', String(sid2));
                    const latest = await apiFetch('/includes/submit.php', { method: 'POST', body: fd.toString(), headers: { 'Content-Type': 'application/x-www-form-urlencoded' } });
                    if (latest && latest.success && latest.report) {
                        const ev = new CustomEvent('openAddSkill', { detail: { student_id: Number(sid2) } });
                        document.dispatchEvent(ev);
                        return;
                    }
                    // Fallback: treat legacy student_reports as an active report (no creation)
                    try {
                        const fd2 = new URLSearchParams(); fd2.append('action','get_student_report'); fd2.append('student_id', String(sid2));
                        const legacy = await apiFetch('/includes/submit.php', { method: 'POST', body: fd2.toString(), headers: { 'Content-Type': 'application/x-www-form-urlencoded' } });
                        if (legacy && legacy.success && (legacy.report || legacy.html)) {
                            const ev = new CustomEvent('openAddSkill', { detail: { student_id: Number(sid2) } });
                            document.dispatchEvent(ev);
                            return;
                        }
                    } catch (e) { /* ignore and proceed to creation flow */ }
                } catch (e) { /* ignore and fall back to create flow */ }
                // No existing report: prompt to create a report (title modal), and stop there
                try {
                    if (window.progressModule && typeof window.progressModule.ensureStudentReportExists === 'function') {
                        const res = await window.progressModule.ensureStudentReportExists(Number(sid2));
                        // If a report was created now, open Add Skill with created flag; if only detected legacy/existing, open normal Add Skill
                        if (res && (res.created || res.report)) {
                            const ev = new CustomEvent('openAddSkill', { detail: { student_id: Number(sid2) } });
                            document.dispatchEvent(ev);
                            return;
                        }
                        // Otherwise, user canceled creation — do nothing further
                        return;
                    }
                } catch (e) { /* ignore */ }
                // Fallback: dispatch the event which will handle prompting as needed
                const ev = new CustomEvent('openAddSkill', { detail: { student_id: Number(sid2) } });
                document.dispatchEvent(ev);
            });
        }
    } catch (e) { /* ignore */ }

    // GLOBAL helper for legacy onclick handlers
    // Delegate to the canonical page module's modal when possible
    window.showAddStudentModal = function(preselectId) {
        try {
            if (typeof showAddStudentModal === 'function') return showAddStudentModal(preselectId);
        } catch (e) { /* fallthrough */ }
        const tpl = document.getElementById('tmpl-add-student');
        if (!tpl) return;
        const clone = tpl.content.cloneNode(true);
        const container = document.createElement('div');
        container.appendChild(clone);
        insertModal(container);
        if (preselectId) {
            const sel = container.querySelector('select[name="student_id"]');
            if (sel) sel.value = preselectId;
        }
        attachModalHandlers(container);
    };

    // existing helper to bind form handlers
    if (window.attachModalHandlers) window.attachModalHandlers(document);

    // Forgot password link handler on login page
    const forgotLink = document.getElementById('forgotPasswordLink');
    if (forgotLink) {
        forgotLink.addEventListener('click', (e) => {
            e.preventDefault();
            const tpl = document.getElementById('tmpl-forgot-password');
            if (!tpl) return;
            const clone = tpl.content.cloneNode(true);
            const container = document.createElement('div');
            container.appendChild(clone);
            insertModal(container);
            attachModalHandlers(container);

            const form = container.querySelector('#forgotPasswordForm');
            if (form) {
                form.addEventListener('submit', async (ev) => {
                    ev.preventDefault();
                    const fd = new FormData(form);
                    fd.append('action', 'request_password_reset');
                    try {
                        showNotification('Sending reset link...', 'info');
                        const res = await apiFetch('/includes/submit.php', { method: 'POST', body: fd });
                        if (res && res.success) {
                            showNotification('If this account exists, a reset token was generated.', 'success');
                            const modal = form.closest('.modal'); if (modal) modal.remove();
                        } else {
                            showNotification(res.error || 'Failed to request reset', 'error');
                        }
                    } catch (err) {
                        showNotification('Network error: ' + err.message, 'error');
                    }
                });
            }
        });
    }
});

function cleanupStaleModals() {
    // Remove any elements with the exact id 'studentModal' that are not inside a <template>
    document.querySelectorAll('#studentModal').forEach(el => {
        // ensure it's not the template content (template's id lives on <template> not the inside nodes)
        if (el.closest('template') === null) {
            el.remove();
        }
    });
}

function initializeView() {
    const urlParams = new URLSearchParams(window.location.search);
    const view = urlParams.get('view') || 'dashboard';
    
    switch(view) {
        case 'dashboard':
            initializeDashboard();
            break;
        case 'students':
            initializeStudentsView();
            break;
        case 'goals':
            initializeGoalsView();
            break;
        case 'progress':
            initializeProgressView();
            break;
    }
}

function initializeEventListeners() {
    // Global form submissions
    const forms = document.querySelectorAll('form');
    forms.forEach(form => {
        if (!form.hasAttribute('data-initialized')) {
            form.setAttribute('data-initialized', 'true');
            form.addEventListener('submit', handleFormSubmit);
        }
    });
}

// Dashboard Functions
function initializeDashboard() {
    console.log('Dashboard initialized');
}

// Student Management Functions
function initializeStudentsView() {
    const searchInput = document.getElementById('studentSearch');
    const gradeFilter = document.getElementById('gradeFilter');
    
    if (searchInput) {
        searchInput.addEventListener('input', filterStudents);
    }
    
    if (gradeFilter) {
        gradeFilter.addEventListener('change', filterStudents);
    }
}

function filterStudents() {
    const searchTerm = document.getElementById('studentSearch')?.value.toLowerCase() || '';
    const gradeFilter = document.getElementById('gradeFilter')?.value || '';
    const studentCards = document.querySelectorAll('.student-card');
    
    studentCards.forEach(card => {
        const name = card.querySelector('.student-name')?.textContent.toLowerCase() || '';
        const grade = card.querySelector('.student-grade')?.textContent.toLowerCase() || '';
        
        const matchesSearch = name.includes(searchTerm);
        const matchesGrade = !gradeFilter || grade.includes(gradeFilter.toLowerCase());
        
        if (matchesSearch && matchesGrade) {
            card.style.display = '';
        } else {
            card.style.display = 'none';
        }
    });
}

function viewProfile(studentId) {
    window.location.href = `?view=students&action=profile&id=${studentId}`;
}

// Goals Management Functions
function initializeGoalsView() {
    const addGoalBtn = document.querySelector('.add-goal-btn');
    if (addGoalBtn) {
        addGoalBtn.addEventListener('click', function() {
            const studentId = new URLSearchParams(window.location.search).get('student_id');
            if (studentId) {
                showAddGoalModal(studentId);
            } else {
                showNotification('Please select a student first', 'error');
            }
        });
    }
}

// Progress Tracking Functions
function initializeProgressView() {
    // Initialize progress view by calling the progress module when a student is selected
    const urlParams = new URLSearchParams(window.location.search);
    const studentId = urlParams.get('student_id');
    if (studentId && progressModule && typeof progressModule.initializeProgress === 'function') {
        try { progressModule.initializeProgress(Number(studentId)); } catch (e) { console.error('progress init failed', e); }
    }
}

// Reports view and legacy API submission removed: reporting is handled within progress/documentation modules

// Utility Functions
function handleFormSubmit(e) {
    // Generic form submission handler
    const form = e.target;
    const submitButton = form.querySelector('button[type="submit"]');
    
    if (submitButton && !form.id.includes('Modal')) {
        const originalText = submitButton.textContent;
        submitButton.disabled = true;
        submitButton.textContent = 'Processing...';
        
        // Re-enable after 5 seconds (fallback)
        setTimeout(() => {
            submitButton.disabled = false;
            submitButton.textContent = originalText;
        }, 5000);
    }
}

function navigateToView(view, params = '') {
    window.location.href = `?view=${view}${params}`;
}

// Use showNotification and closeModal imported from ./ui.js; do not redeclare them here.

// Export functions for global use (modals are implemented in separate modules)
window.SLPDatabase = {
    showAddStudentModal,
    showAddGoalModal,
    viewProfile,
    navigateToView,
    showNotification,
    closeModal,
    addStudentToSelects
};

// Backwards-compatibility: expose common helpers as globals for inline onclick handlers
// (Some pages use onclick="showAddStudentModal()" etc.)
window.showAddStudentModal = showAddStudentModal;
window.showAddGoalModal = showAddGoalModal;
window.viewProfile = viewProfile;
window.navigateToView = navigateToView;
window.showNotification = showNotification;
window.closeModal = closeModal;
// helper for inline onclicks inside cloned templates
window.closeNearestModal = function(btn) {
    // find nearest ancestor with class 'modal'
    let el = btn;
    while (el && !el.classList?.contains('modal')) {
        el = el.parentElement;
    }
    if (el) el.remove();
};

function addStudentToSelects(student) {
    // Add to any select dropdowns that reference students (by name and id)
    const selectors = document.querySelectorAll('select, datalist');
    selectors.forEach(sel => {
        // heuristic: if options contain 'Choose a student' or option values look like student ids, add
        try {
            const opt = document.createElement('option');
            opt.value = student.id;
            opt.textContent = `${student.first_name} ${student.last_name}`;
            // For datalist elements we append option.value differently
            if (sel.tagName.toLowerCase() === 'datalist') {
                const dataOpt = document.createElement('option');
                dataOpt.value = `${student.first_name} ${student.last_name}`;
                sel.appendChild(dataOpt);
            } else {
                // append only if select appears to be for students (heuristic: contains 'student' in id or name)
                const idOrName = (sel.id + ' ' + (sel.name || '')).toLowerCase();
                if (idOrName.includes('student')) {
                    sel.appendChild(opt);
                }
            }
        } catch (e) {
            // ignore failing selectors
        }
    });
}

function toggleStudentDetails(studentId) {
    const panel = document.getElementById('student-details-' + studentId);
    if (!panel) return;

    // Close any other open student detail panels first (accordion behavior)
    document.querySelectorAll('.student-details').forEach(p => {
        if (p !== panel) {
            p.style.display = 'none';
            // Reset arrow for other panels
            const otherRow = p.closest('.student-row');
            if (otherRow) {
                const arrow = otherRow.querySelector('.expand-arrow');
                if (arrow) {
                    arrow.textContent = '▶';
                    arrow.style.transform = 'rotate(0deg)';
                }
            }
        }
    });

    // Toggle the clicked panel
    const isOpen = window.getComputedStyle(panel).display !== 'none';
    panel.style.display = isOpen ? 'none' : 'block';
    
    // Update arrow for current panel
    const currentRow = document.querySelector(`[data-student-id="${studentId}"]`);
    if (currentRow) {
        const arrow = currentRow.querySelector('.expand-arrow');
        if (arrow) {
            if (isOpen) {
                arrow.textContent = '▶';
                arrow.style.transform = 'rotate(0deg)';
            } else {
                arrow.textContent = '▼';
                arrow.style.transform = 'rotate(90deg)';
            }
        }
    }
}

// Expose toggleStudentDetails for inline onclick usage in server-rendered markup
window.toggleStudentDetails = toggleStudentDetails;

// Remove student helper (asks for confirmation, calls API, updates DOM and selects)
async function removeStudent(studentId) {
    const ok = await (window.showConfirm ? window.showConfirm('Are you sure you want to remove this student? This action cannot be undone.') : Promise.resolve(confirm('Are you sure you want to remove this student? This action cannot be undone.')));
    if (!ok) return;
    try {
        showNotification('Removing student...', 'info');
        const fd = new FormData();
        fd.append('action', 'delete_student');
        fd.append('id', studentId);
        const resp = await apiFetch('/includes/submit.php', { method: 'POST', body: fd });

        if (resp && resp.success) {
            showNotification('Student removed', 'success');
            // remove the student button and details from DOM
            const btn = document.querySelector(`[data-student-id="${studentId}"]`);
            if (btn) {
                const wrapper = btn.closest('div');
                if (wrapper) wrapper.remove();
                else btn.remove();
            }
            // remove options from selects/datalists
            removeStudentFromSelects(studentId);
        } else {
            showNotification((resp && resp.error) || 'Failed to remove student', 'error');
        }
    } catch (err) {
        showNotification('Network error: ' + err.message, 'error');
    }
}

function removeStudentFromSelects(studentId) {
    const selectors = document.querySelectorAll('select, datalist');
    selectors.forEach(sel => {
        try {
            // remove options with matching value
            const options = Array.from(sel.querySelectorAll('option'));
            options.forEach(opt => {
                if (opt.value == studentId || opt.textContent.includes(`(${studentId})`)) {
                    opt.remove();
                }
            });
        } catch (e) {}
    });
}

// expose removal function globally for inline onclick handlers
window.removeStudent = removeStudent;

// Edit student profile function
async function editStudentProfile(studentId) {
    try {
        // First, get the student data
        const response = await fetch('/includes/submit.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `action=get_student&id=${studentId}`
        });
        
        const result = await response.json();
        if (!result.success) {
            showNotification('Failed to load student data', 'error');
            return;
        }
        
    const student = result.student;

    // Render the edit modal from template and insert it (use insertModal helper)
    const tpl = document.getElementById('tmpl-edit-student');
    if (!tpl) { showNotification('Edit template missing', 'error'); return; }
    const clone = tpl.content.firstElementChild.cloneNode(true);
    const modalEl = insertModal(clone);
    if (!modalEl) return;

    // Scope queries to the inserted modal to avoid id collisions
    const qs = (sel) => modalEl.querySelector(sel);

    // Populate the form with student data
    if (qs('#editStudentId')) qs('#editStudentId').value = student.id || '';
    if (qs('#editFirstName')) qs('#editFirstName').value = student.first_name || '';
    if (qs('#editLastName')) qs('#editLastName').value = student.last_name || '';
    if (qs('#editGrade')) qs('#editGrade').value = student.grade || '';
    if (qs('#editDateOfBirth')) qs('#editDateOfBirth').value = student.date_of_birth || '';
    if (qs('#editGender')) qs('#editGender').value = student.gender || '';
    if (qs('#editPrimaryLanguage')) qs('#editPrimaryLanguage').value = student.primary_language || '';
    if (qs('#editServiceFrequency')) qs('#editServiceFrequency').value = student.service_frequency || '';
    if (qs('#editParentContact')) qs('#editParentContact').value = student.parent_contact || '';
    if (qs('#editMedicalInfo')) qs('#editMedicalInfo').value = student.medical_info || '';
    if (qs('#displayStudentId')) qs('#displayStudentId').textContent = student.student_id || student.id;

    // Handle form submission
    const form = qs('#editStudentForm');
    if (form) form.addEventListener('submit', async function(e) {
            e.preventDefault();

            const formData = new FormData(form);
            formData.append('action', 'update_student');

            try {
                const updateResponse = await fetch('/includes/submit.php', {
                    method: 'POST',
                    body: formData
                });

                // Read raw text first so we can surface non-JSON errors returned by PHP (500/html pages, warnings, etc.)
                const raw = await updateResponse.text();
                let updateResult = null;
                try {
                    updateResult = raw ? JSON.parse(raw) : null;
                } catch (jsonErr) {
                    console.error('update_student: failed to parse JSON response', jsonErr, raw);
                    showNotification('Server error: ' + (raw || updateResponse.statusText), 'error');
                    return;
                }

                if (!updateResponse.ok) {
                    // HTTP error (500/404). Prefer server-provided error message when available.
                    const message = (updateResult && updateResult.error) ? updateResult.error : (`Server error (${updateResponse.status})`);
                    console.error('update_student HTTP error', updateResponse.status, raw);
                    showNotification(message, 'error');
                    return;
                }

                if (updateResult && updateResult.success) {
                    showNotification('Student profile updated successfully', 'success');
                    // close modal if helper exists, otherwise reload to reflect changes
                    if (typeof hideModal === 'function') hideModal();
                    setTimeout(() => { window.location.reload(); }, 250);
                } else {
                    const errMsg = (updateResult && updateResult.error) ? updateResult.error : 'Failed to update student';
                    console.error('update_student returned error', updateResult);
                    showNotification(errMsg, 'error');
                }
            } catch (error) {
                console.error('Network error while updating student', error);
                showNotification('Network error: ' + (error && error.message ? error.message : String(error)), 'error');
            }
        });
        
    } catch (error) {
        showNotification('Failed to load student data', 'error');
    }
}

// Expose profile editing globally
window.editStudentProfile = editStudentProfile;

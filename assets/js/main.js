// SLP Database Main JavaScript - Modular
import { apiFetch } from './api.js';
import { showNotification, closeModal, insertModal } from './ui.js';
// The modal modules live at assets/js/*.js (no nested 'modals' folder)
import { showAddStudentModal } from './pages/students.js';
import { showAddGoalModal } from './pages/goals.js';
import { showAddProgressModal, showQuickProgressModal as progressQuick } from './pages/progress.js';
// quickProgress helper: prefer the progress module export, fallback to showAddProgressModal
let showQuickProgressModal = progressQuick || showAddProgressModal;

document.addEventListener('DOMContentLoaded', () => {
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
                // NOTE: addStudentForm is intentionally excluded here because the students page
                // module (`assets/js/pages/students.js`) provides its own submit handling.
                container.querySelectorAll('form#addGoalForm, form#addProgressForm').forEach(form => {
                    if (form.dataset.slpInitialized) return;
                    form.dataset.slpInitialized = '1';
                    form.addEventListener('submit', async (e) => {
                        e.preventDefault();
                        const fd = new FormData(form);
                        // normalize fields
                        if (form.id === 'addGoalForm') {
                            if (!fd.get('description') && fd.get('goal_text')) fd.set('description', fd.get('goal_text'));
                            fd.append('action', 'add_goal');
                        } else if (form.id === 'addProgressForm') {
                            fd.append('action', 'add_progress');
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
        case 'reports':
            initializeReportsView();
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
    const addProgressBtn = document.querySelector('.add-progress-btn');
    if (addProgressBtn) {
        addProgressBtn.addEventListener('click', function() {
            const studentId = new URLSearchParams(window.location.search).get('student_id');
            const goalId = this.getAttribute('data-goal-id');
            if (studentId && goalId) {
                showAddProgressModal(studentId, goalId);
            } else {
                showNotification('Please select a student and goal', 'error');
            }
        });
    }
}

// Reports Functions
function initializeReportsView() {
    const form = document.getElementById('createReportForm');
    if (form) {
        form.addEventListener('submit', submitReportForm);
    }
}

async function submitReportForm(e) {
    e.preventDefault();
    
    const formData = new FormData(e.target);
    const reportData = Object.fromEntries(formData.entries());
    
    // Add option to generate PDF
    reportData.generate_pdf = true;
    
    // Validate required fields
    if (!reportData.student_id || !reportData.report_type) {
        showNotification('Please select a student and report type', 'error');
        return;
    }
    
    try {
        showNotification('Generating report...', 'info');

        const response = await apiFetch('api/reports.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify(reportData)
        });
        
        const result = await response.json();
        
        if (response.ok) {
            showNotification('Report generated successfully!', 'success');
            
            // Open the generated report in a new window
            if (result.report_data && result.report_data.pdf_path) {
                setTimeout(() => {
                    window.open(result.report_data.pdf_path, '_blank');
                }, 1000);
            }
            
            // Reset form
            e.target.reset();
        } else {
            showNotification(result.error || 'Failed to generate report', 'error');
        }
    } catch (error) {
        showNotification('Network error: ' + error.message, 'error');
    }
}

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
    showAddProgressModal,
    showQuickProgressModal,
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
window.showAddProgressModal = showAddProgressModal;
window.showQuickProgressModal = showQuickProgressModal;
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
    if (!confirm('Are you sure you want to remove this student? This action cannot be undone.')) return;
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
    if (qs('#editTeacher')) qs('#editTeacher').value = student.teacher || '';
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

// Page module: students
import { apiFetch } from '../api.js';
import { showNotification, closeModal, insertModal, addStudentToSelects } from '../ui.js';
export function showAddStudentModal(preselectId) {
    const tpl = document.getElementById('tmpl-add-student');
    if (!tpl) return;
    const clone = tpl.content.cloneNode(true);

    // ensure unique ids in the cloned template to avoid collisions
    clone.querySelectorAll('[id]').forEach(el => {
        const oldId = el.id;
        const newId = oldId + '-' + Math.floor(Math.random() * 100000);
        el.id = newId;
        // update labels that referenced the old id
        clone.querySelectorAll(`label[for="${oldId}"]`).forEach(lbl => lbl.setAttribute('for', newId));
        // update aria-labelledby that reference the old id
        clone.querySelectorAll(`[aria-labelledby="${oldId}"]`).forEach(a => a.setAttribute('aria-labelledby', newId));
    });

    // Remove any inline onclick handlers in the clone that target static ids and attach scoped listeners instead
    clone.querySelectorAll('[onclick]').forEach(btn => {
        const onclick = btn.getAttribute('onclick') || '';
        if (onclick.includes("document.getElementById('studentModal')") || onclick.includes('studentModal')) {
            btn.removeAttribute('onclick');
        }
    });

    // Insert modal via central helper which attaches close handlers and backdrop
    insertModal(clone);

    // ensure date-of-birth cannot be set to today or a future date
    const modalRoot = document.querySelector('.modal') || clone;
    const dobInput = modalRoot.querySelector('input[name="date_of_birth"]');
    if (dobInput) {
        try { dobInput.type = 'text'; dobInput.readOnly = true; } catch (e) { /* ignore */ }
        const now = new Date();
        const year = now.getFullYear();
        const month = String(now.getMonth() + 1).padStart(2, '0');
        const day = String(now.getDate()).padStart(2, '0');
        const todayLocal = `${year}-${month}-${day}`;
        dobInput.max = todayLocal;
        if (dobInput.value && dobInput.value > dobInput.max) dobInput.value = '';

        // Prefer flatpickr when available
        setTimeout(() => {
            if (window.flatpickr) {
                try {
                    if (dobInput._flatpickrInstance && dobInput._flatpickrInstance.destroy) dobInput._flatpickrInstance.destroy();
                    window.flatpickr(dobInput, { maxDate: todayLocal, dateFormat: 'Y-m-d', allowInput: true, altInput: false, disableMobile: true, clickOpens: true, onReady(selectedDates, dateStr, instance) { instance.set('maxDate', todayLocal); dobInput._flatpickrInstance = instance; } });
                    modalRoot.querySelectorAll('.dob-year-select, .dob-month-select, .dob-day-select').forEach(el => el.remove());
                } catch (err) { console.warn('flatpickr init failed', err); }
            }
        }, 0);
    }

    // preselect student if provided (used when opening modal from a student row)
    if (preselectId) {
        const sel = clone.querySelector('select[name="student_id"]');
        if (sel) sel.value = preselectId;
        const hid = clone.querySelector('input[name="student_id"]');
        if (hid) hid.value = preselectId;
    }

    // Attach submit handler scoped to this clone's form
    const form = clone.querySelector('form');
    if (form) form.addEventListener('submit', submitStudentForm);
}

async function submitStudentForm(e) {
    e.preventDefault();
    const form = e.target;
    // If another handler already started processing this form, skip to avoid duplicate submissions
    if (form.dataset.slpHandled) {
        console.warn('submitStudentForm: form already handled, skipping duplicate submit');
        return;
    }
    // mark as handled immediately to prevent other handlers from also submitting
    form.dataset.slpHandled = '1';
    // Client-side required field validation: ensure all [required] fields have values
    const requiredEls = Array.from(form.querySelectorAll('[required]'));
    for (const el of requiredEls) {
        let val = el.value;
        // for selects, ensure non-empty selection
        if (el.tagName.toLowerCase() === 'select') val = el.value;
        if (!val || String(val).trim() === '') {
            // clear handled flag to allow retry
            try { delete form.dataset.slpHandled; } catch (ignore) {}
            const label = form.querySelector(`label[for="${el.id}"]`);
            const name = label ? label.textContent.replace('*','').trim() : (el.name || 'Required field');
            showNotification(`${name} is required.`, 'error');
            // focus the invalid element when possible
            try { el.focus(); } catch (ignore) {}
            return;
        }
    }
    const formData = new FormData(form);
    const studentData = Object.fromEntries(formData.entries());
    // Client-side validation: prevent future date of birth
    if (studentData.date_of_birth) {
        const dob = studentData.date_of_birth;
        const max = e.target.querySelector('input[name="date_of_birth"]')?.max;
        if (max && dob > max) {
            showNotification('Date of birth cannot be in the future.', 'error');
            return;
        }
    }
    // Show progress but keep modal open until we know the result
    showNotification('Adding student...', 'info');

    try {
        // Build FormData to submit to the server-side submit handler
        const fd = new FormData(e.target);
        fd.append('action', 'add_student');
        // include CSRF token if present on the page
        const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
        if (csrf) fd.append('csrf_token', csrf);

        const result = await apiFetch('/includes/submit.php', {
            method: 'POST',
            body: fd
        });

    if (result && result.success) {
            showNotification('Student added successfully!', 'success');
            // Construct a minimal student object for updating selects on other pages
            const student = Object.fromEntries(new FormData(e.target).entries());
            student.id = result.id;

            // Update any selects/datalists on the current page so other UI can see the student
            try {
                if (window.SLPDatabase && typeof window.SLPDatabase.addStudentToSelects === 'function') {
                    window.SLPDatabase.addStudentToSelects(student);
                } else if (typeof addStudentToSelects === 'function') {
                    addStudentToSelects(student);
                }
            } catch (ignore) {}

            // Redirect to the students view so the new student is visible in the canonical list
            setTimeout(() => {
                window.location.href = '?view=students';
            }, 700);
        } else {
            const errMsg = (result && result.error) ? result.error : 'Failed to add student';
            showNotification(errMsg, 'error');
            console.error('Failed to add student', result);
            // allow retry if server returned an error
            try { delete form.dataset.slpHandled; } catch (ignore) {}
        }
    } catch (err) {
        console.error('Network error when adding student:', err);
        showNotification('Network error: ' + err.message, 'error');
        // allow retry on network error
        try { delete form.dataset.slpHandled; } catch (ignore) {}
    }
}

// Small helper to escape HTML used in building DOM nodes
function escapeHtml(str) {
    if (!str) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function init() {
    // search box filtering
    const search = document.getElementById('studentSearch');
    if (search) {
        search.addEventListener('input', () => {
            const q = search.value.trim().toLowerCase();
            document.querySelectorAll('.students-grid > div').forEach(card => {
                const name = (card.textContent || '').toLowerCase();
                card.style.display = q && !name.includes(q) ? 'none' : '';
            });
        });
    }

    const gradeFilter = document.getElementById('gradeFilter');
    if (gradeFilter) {

// Additional helpers used by templates
window.exportStudent = async function(id) {
    try {
        const fd = new FormData(); fd.append('action','export_student_html'); fd.append('id', String(id));
        const res = await fetch('/includes/submit.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (data && data.success && data.html) {
            const w = window.open();
            w.document.write(data.html);
            w.document.close();
            // allow user to print/save from opened window
        } else {
            alert('Export failed: ' + (data.error || 'unknown'));
        }
    } catch (e) { console.error(e); alert('Export failed'); }
};

window.archiveStudent = async function(id) {
    if (!confirm('Archive this student? They will be hidden from lists but can be restored.')) return;
    try {
        const fd = new FormData(); fd.append('action','archive_student'); fd.append('id', String(id));
        const res = await fetch('/includes/submit.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (data && data.success) { location.reload(); } else { alert('Archive failed: '+(data.error||'')); }
    } catch (e) { console.error(e); alert('Archive failed'); }
};

window.openStudentDocs = function(id) {
    // Open documentation view with student_id query param to pre-open forms for this student
    location.href = '?view=documentation&student_id=' + encodeURIComponent(String(id));
};

window.viewProfile = function(id) {
    try {
        // find student info from DOM (dataset) or fetch clients-side students.json
        (async function(){
            let student = null;
            try {
                const fd = new URLSearchParams();
                fd.append('action', 'get_students');
                fd.append('archived', '0');
                const res = await fetch('/includes/submit.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: fd.toString()
                });
                if (res.ok) {
                    const arr = await res.json();
                    student = arr.find(s => String(s.id) === String(id));
                }
            } catch (e) { console.warn('Could not fetch students.json', e); }

            const tmpl = document.getElementById('tmpl-student-profile');
            if (!tmpl) { alert('Profile template missing'); return; }
            const clone = tmpl.content.firstElementChild.cloneNode(true);
            const body = clone.querySelector('#studentProfileBody');
            if (!student) {
                body.innerHTML = '<p>Student not found.</p>';
            } else {
                // Determine assigned therapist display name: prefer stored name then fallback to id lookup if available
                let assignedName = (student.assigned_therapist_name || '').trim();

                if (!assignedName && student.assigned_therapist) {
                    // try to fetch users list quickly from the same API if available (best-effort)
                    try {
                        const usersRes = await fetch('/api/users.php');
                        if (usersRes.ok) {
                            const users = await usersRes.json();
                            const u = users.find(x => String(x.id) === String(student.assigned_therapist));
                            if (u) assignedName = `${u.first_name || ''} ${u.last_name || ''}`.trim();
                        }
                    } catch (e) { /* ignore */ }
                }

                if (!assignedName) assignedName = 'Unassigned';

                body.innerHTML = `<h3>${escapeHtml(student.first_name || '')} ${escapeHtml(student.last_name || '')}</h3>` +
                    `<p>Grade: ${escapeHtml(student.grade || '')}</p>` +
                    `<p>DOB: ${escapeHtml(student.date_of_birth || '')}</p>` +
                    `<p>Primary language: ${escapeHtml(student.primary_language || '')}</p>` +
                    `<p>Assigned Therapist: ${escapeHtml(assignedName)}</p>`;
            }
            insertModal(clone);
        })();
    } catch (e) { console.error(e); }
};
        gradeFilter.addEventListener('change', () => {
            const g = gradeFilter.value;
            document.querySelectorAll('.students-grid > div').forEach(card => {
                if (!g) return card.style.display = '';
                const text = card.textContent || '';
                card.style.display = text.includes('Grade ' + g) ? '' : 'none';
            });
        });
    }

    // expose some small helpers for inline handlers used in templates
    function _toggleStudentDetailsImpl(studentId) {
        try {
            // Close any other open student details first
            document.querySelectorAll('.student-row.expanded').forEach(row => {
                if (row.getAttribute('data-student-id') !== studentId.toString()) {
                    row.classList.remove('expanded');
                    const details = row.querySelector('.student-details');
                    if (details) {
                        details.classList.remove('show');
                        setTimeout(() => {
                            details.style.display = 'none';
                        }, 300);
                    }
                }
            });
            
            // Toggle the clicked student
            const studentRow = document.querySelector(`[data-student-id="${studentId}"]`);
            const details = document.getElementById('student-details-' + studentId);
            
            if (studentRow && details) {
                const isExpanded = studentRow.classList.contains('expanded');
                
                if (isExpanded) {
                    // Close
                    studentRow.classList.remove('expanded');
                    details.classList.remove('show');
                    setTimeout(() => {
                        details.style.display = 'none';
                    }, 300);
                } else {
                    // Open
                    studentRow.classList.add('expanded');
                    details.style.display = 'block';
                    setTimeout(() => {
                        details.classList.add('show');
                    }, 10);
                }
            }
        } catch (e) { 
            console.error('Error toggling student details:', e); 
        }
    }

    // only attach the global if one doesn't exist yet to avoid duplicates/overwrites
    if (typeof window.toggleStudentDetails !== 'function') {
        window.toggleStudentDetails = _toggleStudentDetailsImpl;
    }

    // removal uses fetch-backed delete if available
    // preserve any existing implementation (avoid arguments.callee which is invalid in ES modules)
    const _existingRemoveStudent = (typeof window.removeStudent === 'function') ? window.removeStudent : null;
    window.removeStudent = function(id) {
        if (_existingRemoveStudent && _existingRemoveStudent !== window.removeStudent) {
            return _existingRemoveStudent(id);
        }
        if (!confirm('Remove student #' + id + '?')) return;
        const el = document.querySelector('[data-student-id="' + id + '"]');
        const row = el ? el.closest('.student-row') : null;
        if (row) {
            row.remove();
        } else if (el && el.parentNode) {
            el.parentNode.remove();
        }
    };

    window.navigateToView = function(view, query='') {
        location.href = '?view=' + view + (query || '');
    };
}

// Apply saved user preferences if present (grade filter, students per page, show IDs)
function applyUserPreferences() {
    try {
        const defaultGrade = localStorage.getItem('defaultGrade') || '';
        const studentsPerPage = parseInt(localStorage.getItem('studentsPerPage') || '25', 10);
        const showStudentIds = localStorage.getItem('showStudentIds') !== 'false';

        // Apply default grade filter
        const gradeFilter = document.getElementById('gradeFilter');
        if (gradeFilter && defaultGrade) {
            gradeFilter.value = defaultGrade;
            gradeFilter.dispatchEvent(new Event('change'));
        }

        // Toggle student ID visibility
        if (!showStudentIds) {
            document.querySelectorAll('.student-id').forEach(el => el.style.display = 'none');
        }

        // Apply simple client-side pagination by hiding extra students in each grade
        if (studentsPerPage && Number.isFinite(studentsPerPage) && studentsPerPage > 0) {
            document.querySelectorAll('.grade-section').forEach(section => {
                const students = section.querySelectorAll('.grade-students > .student-row');
                students.forEach((row, idx) => {
                    row.style.display = (idx < studentsPerPage) ? '' : 'none';
                });
                // If there are more than studentsPerPage, add a small indicator
                const existing = section.querySelector('.more-indicator');
                if (existing) existing.remove();
                if (students.length > studentsPerPage) {
                    const more = document.createElement('div');
                    more.className = 'more-indicator muted';
                    more.textContent = `Showing ${studentsPerPage} of ${students.length} students`;
                    section.querySelector('.grade-header').insertAdjacentElement('afterend', more);
                }
            });
        }
    } catch (e) { console.warn('applyUserPreferences failed', e); }
}

// Run preferences after init to ensure DOM is ready
setTimeout(applyUserPreferences, 250);

init();

export default { init, showAddStudentModal };

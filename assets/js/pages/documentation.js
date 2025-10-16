// Documentation page JavaScript
console.info('documentation.js loaded - per-field validation enabled');
let allStudents = [];
let existingForms = [];

// Ensure a notification helper exists even if `ui.js` hasn't been loaded or hasn't exposed it yet.
if (typeof window.showNotification !== 'function') {
    window.showNotification = function(message, type = 'info') {
        if (type === 'error') console.error('[Notification] ' + message);
        else console.log('[Notification] ' + message);
        try { if (typeof alert === 'function') alert(message); } catch (e) {}
    };
}

// Load students and setup page. Use an init function so dynamic imports work whether DOMContentLoaded has already fired or not.
(async function initDocumentation() {
    if (document.readyState === 'loading') {
        await new Promise(resolve => document.addEventListener('DOMContentLoaded', resolve));
    }
    try {
        await loadStudents();
        setupDocumentationPage();

        // Check if a student is pre-selected via URL parameter or data attribute
        const studentSelect = document.getElementById('studentSelect');
        const preSelectedId = studentSelect ? studentSelect.dataset.selectedStudent : null;
        if (studentSelect && preSelectedId && preSelectedId !== '') {
            studentSelect.value = preSelectedId;
            await loadStudentForms();
        }

    // Expose a small set of functions to window for inline onclicks and dynamically generated HTML
    // These are used by templates and existing-form markup which rely on global functions.
    window.showDocModal = showDocModal;
    window.viewExistingForm = viewExistingForm;
    window.deleteExistingForm = deleteExistingForm;
    window.editExistingForm = editExistingForm;
    window.saveDocument = saveDocument;
    window.printDocument = printDocument;
    // Expose handlers used by select onchange attributes in the page template
    window.loadStudentForms = loadStudentForms;
    window.filterExistingForms = filterExistingForms;
    // Expose modal close helper for inline close button
    window.closeDocModal = closeDocModal;
    } catch (err) {
        console.error('Documentation init error:', err);
    }
})();

async function loadStudents() {
    try {
        const fd = new URLSearchParams();
        fd.append('action', 'get_students');
        // optional: restrict to non-archived only
        fd.append('archived', '0');

        const res = await fetch('/includes/submit.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: fd.toString()
        });

        const result = await res.json();
        if (result && result.success && Array.isArray(result.students)) {
            allStudents = result.students;
        } else {
            allStudents = [];
        }
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
        'discharge_report': 'Discharge Report',
        'other_documents': 'Other Documents',
        'uploaded_file': 'Other Document'
    };
    
    const html = forms.map(form => {
    const ft = form.form_type || (form.formType || '');
    const formTypeName = formTypeNames[ft] || ft || 'Document';
    const createdDate = form.created_at ? new Date(form.created_at).toLocaleDateString() : '';
    // Compose a stable UID including form_type to avoid id collisions across DB tables
    const rawId = form.db_id || form.id;
    const rowUid = (ft ? ft : 'unknown') + '::' + String(rawId);
    const deleteBtn = `<button class="btn btn-sm btn-danger" onclick="deleteExistingForm('${rowUid}')">Delete</button>`;
    const entryTitle = (form.title && String(form.title).trim() !== '') ? form.title : (form.title || formTypeName);
        
        return `
            <div class="existing-form-item">
                <div class="existing-form-info">
                    <div class="existing-form-title">${entryTitle}</div>
                    <div class="existing-form-meta">${formTypeName} — Created: ${createdDate}</div>
                </div>
                    <div class="existing-form-actions">
                    <button class="btn btn-sm btn-outline" onclick="viewExistingForm('${rowUid}')">View</button>
                    ${deleteBtn}
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

    const filteredForms = existingForms.filter(form => {
        // other_documents rows may set form_type to 'uploaded_file' or 'other_documents'
        const ft = form.form_type || '';
        if (formType === 'other_documents') return (ft === 'other_documents' || ft === 'uploaded_file');
        return ft === formType;
    });
    displayExistingForms(filteredForms);
}

function setupDocumentationPage() {
    // Any additional setup for the documentation page can go here
    console.log('Documentation page initialized');
    // Wire upload button
    const btn = document.getElementById('btnOpenUpload');
    if (btn) btn.addEventListener('click', showUploadModal);

    // Wire the documentation modal's action buttons (global modal buttons present in the template)
    const saveBtn = document.getElementById('saveDocBtn');
    if (saveBtn) {
        saveBtn.addEventListener('click', async () => {
            try { await saveDocument(); } catch (e) { console.error(e); }
        });
    }
    const printBtn = document.getElementById('printDocBtn');
    if (printBtn) {
        printBtn.addEventListener('click', () => { try { printDocument(); } catch (e) { console.error(e); } });
    }
    const cancelBtn = document.getElementById('docCancelBtn');
    if (cancelBtn) cancelBtn.addEventListener('click', closeDocModal);
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
        // 'progress_report' removed
        'discharge_report': 'Discharge Report'
    };
    
    title.textContent = formTitles[formType] || 'Documentation Form';
    
    // Load the form template
    loadFormTemplate(formType);
    
    // Ensure modal is reset for create mode: show save button, clear db_id, enable controls
    try {
        // save button visibility is handled by setupDocumentationPage wiring (addEventListener)
        const saveBtn = modal.querySelector('#saveDocBtn');
        if (saveBtn) {
            saveBtn.style.display = '';
        }
        // print button label for create mode
        const printBtn = modal.querySelector('#printDocBtn');
        if (printBtn) printBtn.textContent = 'Print blank PDF';
        const containerEl = document.getElementById('docModalForm');
        if (containerEl) {
            // clear any edit state
            try { delete containerEl.dataset.dbId; } catch (e) { containerEl.removeAttribute('data-db-id'); }
            containerEl.classList.remove('read-only-form');
            // remove any hidden db_id input left from edit
            const hid = containerEl.querySelector('#db_id');
            if (hid) hid.remove();
            // enable inputs/selects/textareas and clear values except studentName which we preselect
            containerEl.querySelectorAll('input,select,textarea').forEach(el => {
                try { el.removeAttribute('disabled'); } catch (e) {}
                try { el.removeAttribute('readonly'); } catch (e) {}
                // don't clear studentName so it can be preselected from page
                if (el.id && el.id !== 'studentName') {
                    if (el.tagName.toLowerCase() === 'input' || el.tagName.toLowerCase() === 'textarea') el.value = '';
                    if (el.tagName.toLowerCase() === 'select') el.selectedIndex = 0;
                }
            });
            // preselect studentName from page selection if available
            const pageSel = document.getElementById('studentSelect');
            const studentSelect = containerEl.querySelector('#studentName');
            if (studentSelect && pageSel && pageSel.value) {
                // ensure option exists
                const opt = studentSelect.querySelector("option[value='" + CSS.escape(String(pageSel.value)) + "']");
                if (opt) studentSelect.value = pageSel.value;
            }
        }
    } catch (err) {
        console.warn('Could not fully reset create modal state', err);
    }

    // prevent the page behind from scrolling and size the modal to avoid window scrollbar
    try {
        document.body.style.overflow = 'hidden';
        const panel = modal.querySelector('.modal-content') || modal.querySelector('.modal-panel');
        if (panel) {
            panel.style.maxHeight = 'calc(100vh - 40px)';
            panel.style.overflow = 'auto';
        }
    } catch (e) {}

    modal.style.display = 'flex';
}

function loadFormTemplate(formType) {

    const container = document.getElementById('docModalForm');
    if (!container) return;
    // clear previous content
    container.innerHTML = '';

    // Template IDs on server are prefixed with tmpl-doc-
    const tmplId = 'tmpl-doc-' + formType;
    const tpl = document.getElementById(tmplId);
    if (!tpl) {
        container.innerHTML = '<p>Form template not found.</p>';
        container.setAttribute('data-form-type', formType);
        return;
    }

    const clone = tpl.content.cloneNode(true);
    // Prefer to append only the inner form portion (avoid nesting a full modal inside the existing documentationModal)
    let inner = clone.querySelector('.doc-form') || clone.querySelector('[data-form-type]');
    if (inner) {
        // append a deep clone of the inner form node to keep fragment intact
        container.appendChild(inner.cloneNode(true));
    } else {
        container.appendChild(clone);
    }
    container.setAttribute('data-form-type', formType);

    // populate student selects within the cloned template
    const selects = container.querySelectorAll('select#studentName, select[id$="Student"]');
    selects.forEach(sel => {
        // preserve existing first option if present
        const current = sel.value;
        sel.innerHTML = '<option value="">Select Student</option>';
        allStudents.forEach(student => {
            if (!student.archived) {
                const opt = document.createElement('option');
                opt.value = student.id;
                opt.textContent = `${student.first_name} ${student.last_name}`;
                sel.appendChild(opt);
            }
        });
        if (current) sel.value = current;
    });

    // Add extra spacing for printable/create form writing space
    try {
        // remove any previous injected style
        const prev = container.querySelector('#docModalFormExtraStyle');
        if (prev) prev.remove();
        const style = document.createElement('style');
        style.id = 'docModalFormExtraStyle';
        // Scoped rules using the container id to avoid global leakage
        style.textContent = `#docModalForm .form-group { margin-bottom: 28px; }
#docModalForm textarea { min-height: 100px; line-height: 1.45; padding: 10px; }
#docModalForm input[type="date"], #docModalForm input[type="text"], #docModalForm input[type="number"] { padding: 8px; }
#docModalForm .form-row { gap: 24px; }
`;
        container.prepend(style);
    } catch (err) {
        console.warn('Could not inject extra spacing styles into doc modal', err);
    }
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
    console.log('saveDocument called for formType=', formType);
    // Prevent double submissions: if a save is already in progress, ignore additional clicks
    if (formContainer.dataset.saving === '1') {
        console.warn('saveDocument: save already in progress');
        return;
    }
    formContainer.dataset.saving = '1';
    const globalSaveBtn = document.getElementById('saveDocBtn');
    if (globalSaveBtn) {
        try {
            globalSaveBtn.disabled = true;
            globalSaveBtn.dataset._orig = globalSaveBtn.textContent;
            globalSaveBtn.textContent = 'Saving...';
        } catch (e) {}
    }
    const formData = new FormData();

    // Validation: ensure required fields are filled and not allow entirely blank submissions
    const inputs = Array.from(formContainer.querySelectorAll('input, select, textarea'));
    let nonEmptyCount = 0;
    let missingRequired = false;
    const radioChecked = new Set();
    inputs.forEach(input => {
        if (input.disabled) return;
        if (!input.name && !input.id) return;
        const tag = input.tagName.toLowerCase();
        if (tag === 'input') {
            const t = (input.type || '').toLowerCase();
            if (t === 'hidden' || t === 'button' || t === 'submit') return;
            if (t === 'file') {
                if (input.files && input.files.length > 0) nonEmptyCount++;
                if (input.required && !(input.files && input.files.length > 0)) missingRequired = true;
                return;
            }
            if (t === 'radio') {
                if (radioChecked.has(input.name)) return;
                const group = formContainer.querySelectorAll('input[name="' + input.name + '"][type="radio"]');
                const anyChecked = Array.from(group).some(g => g.checked);
                if (anyChecked) { nonEmptyCount++; radioChecked.add(input.name); }
                if (input.required && !anyChecked) missingRequired = true;
                return;
            }
            if (t === 'checkbox') {
                if (input.checked) nonEmptyCount++;
                if (input.required && !input.checked) missingRequired = true;
                return;
            }
        }
        const val = (input.value || '').toString().trim();
        if (val !== '') nonEmptyCount++;
        if (input.required && val === '') missingRequired = true;
    });

    if (missingRequired) {
        showNotification('Please complete all required fields before saving.', 'error');
        // cleanup saving lock/UI
        try { delete formContainer.dataset.saving; } catch (e) { formContainer.removeAttribute && formContainer.removeAttribute('data-saving'); }
        if (globalSaveBtn) { try { globalSaveBtn.disabled = false; if (globalSaveBtn.dataset && globalSaveBtn.dataset._orig) globalSaveBtn.textContent = globalSaveBtn.dataset._orig; delete globalSaveBtn.dataset._orig; } catch (e) {} }
        return;
    }
    if (nonEmptyCount === 0) {
        showNotification('Please fill out at least one field before saving.', 'error');
        try { delete formContainer.dataset.saving; } catch (e) { formContainer.removeAttribute && formContainer.removeAttribute('data-saving'); }
        if (globalSaveBtn) { try { globalSaveBtn.disabled = false; if (globalSaveBtn.dataset && globalSaveBtn.dataset._orig) globalSaveBtn.textContent = globalSaveBtn.dataset._orig; delete globalSaveBtn.dataset._orig; } catch (e) {} }
        return;
    }

    // Get all form inputs
    const inputsNodeList = formContainer.querySelectorAll('input, select, textarea');
    const docData = {};
    
    try {
        inputs.forEach(input => {
            // only include elements with an id
            if (!input.id) return;
            docData[input.id] = input.value;
        });
    } catch (err) {
        console.error('Error collecting form inputs in saveDocument:', err);
        showNotification('Error preparing form data', 'error');
        return;
    }
    // Add alternate key names so server accepts snake_case or other variants
    const addAliases = (key, aliases) => {
        if (docData[key] !== undefined) {
            aliases.forEach(a => { if (docData[a] === undefined) docData[a] = docData[key]; });
        }
    };

    // session report aliases
    addAliases('sessionDate', ['session_date']);
    addAliases('sessionDuration', ['duration_minutes','durationMinutes']);
    addAliases('sessionType', ['session_type']);
    addAliases('objectivesTargeted', ['objectives_targeted']);
    addAliases('activitiesUsed', ['activities_used']);
    addAliases('studentResponse', ['student_response']);
    addAliases('nextSessionPlan', ['next_session_plan']);

    // goals aliases
    addAliases('goalDate', ['goal_date']);
    addAliases('longTermGoals', ['long_term_goals','Long_Term_Goals']);
    addAliases('shortTermObjectives', ['short_term_objectives','Short_Term_Goals']);
    addAliases('interventionStrategies', ['intervention_strategies']);
    addAliases('measurementCriteria', ['measurement_criteria']);

    // Per-form validation: require every field present in the template to have a non-empty value.
    // Templates now include `required` attributes; enforce that any input/select/textarea with an id
    // or name is non-empty (allow single-character values).
    const enforceAllFieldsFilled = (type) => {
        const fields = Array.from(formContainer.querySelectorAll('input,select,textarea'));
        for (const f of fields) {
            if (f.disabled) continue;
            // ignore purely structural buttons/hidden
            if (!f.id && !f.name) continue;
            const tag = f.tagName.toLowerCase();
            if (tag === 'input') {
                const t = (f.type || '').toLowerCase();
                if (t === 'hidden' || t === 'button' || t === 'submit') continue;
                if (t === 'file') {
                    if (f.required && !(f.files && f.files.length > 0)) return { ok: false, label: 'File input required' };
                    continue;
                }
            }
            const v = (f.value || '').toString().trim();
            // If this is the student selector, return a special result so we can log debug info and show a specific message
            if ((f.id === 'studentName' || f.name === 'studentName' || f.name === 'student_id')) {
                // defensive: some templates may populate option text but not values yet. Consider select valid if
                // a non-empty option text is chosen even when value is empty.
                if (f.tagName.toLowerCase() === 'select') {
                    const selVal = (f.value || '').toString().trim();
                    const selOpt = f.options && f.options[f.selectedIndex];
                    const selText = selOpt ? (selOpt.text || '').toString().trim() : '';
                    if (selVal === '' && selText === '') {
                        return { ok: false, type: 'student', el: f };
                    }
                    continue; // select is acceptable
                }
                if (v === '') return { ok: false, type: 'student', el: f };
            }
            // If template marks field required, enforce non-empty. Otherwise, still enforce non-empty for documentation forms per user request.
            if (v === '') return { ok: false, label: (f.getAttribute('aria-label') || f.previousElementSibling ? (f.previousElementSibling.textContent || 'Field') : 'Field') };
        }
        return { ok: true };
    };

    const enforceResult = enforceAllFieldsFilled(formType);
    if (!enforceResult.ok) {
        // If this was the student selector being seen as empty, show a specific message and log debug info
        if (enforceResult.type === 'student') {
            // Log select state for debugging
            try {
                const sel = enforceResult.el;
                console.warn('Student select reported empty during validation. selectedIndex=', sel.selectedIndex, 'value=', sel.value, 'options=', Array.from(sel.options).map(o=>({value:o.value,text:o.text})) );
            } catch (e) {
                console.warn('Student select debug log failed', e);
            }
            showNotification('Please select a student before saving the form.', 'error');
        } else {
            showNotification('Please complete all fields before saving the form. Field: ' + (enforceResult.label || ''), 'error');
        }
        try { delete formContainer.dataset.saving; } catch (e) { formContainer.removeAttribute && formContainer.removeAttribute('data-saving'); }
        if (globalSaveBtn) { try { globalSaveBtn.disabled = false; if (globalSaveBtn.dataset && globalSaveBtn.dataset._orig) globalSaveBtn.textContent = globalSaveBtn.dataset._orig; delete globalSaveBtn.dataset._orig; } catch (e) {} }
        return;
    }

    // discharge aliases
    addAliases('servicesSummary', ['summary_of_services','Summary_of_services','Summary_of_Services_Provided','services_summary']);
    addAliases('goalsAchieved', ['Goals_Achieved','Goals_achieved','goals_achieved']);
    addAliases('dischargeReason', ['reason_for_discharge','Reason_for_discharge','reasonForDischarge']);
    addAliases('followUpRecommendations', ['Follow_up_Recommendations','FollowUp_Recommendations','follow_up_recommendations','followUp']);
    addAliases('dischargeDate', ['discharge_date','created_at']);

    // include db_id if present in the form (set by editExistingForm)
    const dbIdEl = formContainer.querySelector('#db_id');
    if (dbIdEl && dbIdEl.value) {
        formData.append('db_id', dbIdEl.value);
    } else if (formContainer.dataset.dbId) {
        formData.append('db_id', formContainer.dataset.dbId);
    }
    
    formData.append('action', 'save_document');
    formData.append('form_type', formType);
    formData.append('form_data', JSON.stringify(docData));
    
    try {
        // Log a small summary of the payload
        console.log('Submitting form_data keys:', Object.keys(docData));
        const response = await fetch('/includes/submit.php', {
            method: 'POST',
            body: formData
        });

        const text = await response.text();
        try {
            const result = JSON.parse(text);
            if (result.success) {
                showNotification('Document saved successfully', 'success');
                closeDocModal();
                // Refresh the existing forms if we have a student selected
                if (document.getElementById('studentSelect').value) {
                    await loadStudentForms();
                }
                // Refresh recent activity so dashboard shows the new document
                try { if (typeof window.refreshRecentActivity === 'function') window.refreshRecentActivity(20); } catch (e) { /* ignore */ }
            } else {
                console.error('Save returned error:', result);
                showNotification('Error saving document: ' + (result.message || result.error || 'Unknown'), 'error');
            }
        } catch (jsonErr) {
            console.error('Failed to parse save response as JSON. Raw response:', text);
            showNotification('Save failed: server returned invalid response', 'error');
        }
    } catch (error) {
        console.error('Error saving document:', error);
        showNotification('Error saving document', 'error');
        } finally {
            // restore UI state
            try {
                delete formContainer.dataset.saving;
            } catch (e) { formContainer.removeAttribute && formContainer.removeAttribute('data-saving'); }
            if (globalSaveBtn) {
                try {
                    globalSaveBtn.disabled = false;
                    if (globalSaveBtn.dataset && globalSaveBtn.dataset._orig) globalSaveBtn.textContent = globalSaveBtn.dataset._orig;
                    delete globalSaveBtn.dataset._orig;
                } catch (e) {}
            }
        }
}

// Print document function
function printDocument() {
    // Print the form: when viewing (read-only) use the printable template so we get a single, clean page;
    // when in create/edit mode fallback to cloning the modal form.
    try {
        const container = document.getElementById('docModalForm');
        if (!container) return window.print();

        const formType = container.getAttribute('data-form-type') || '';
        const isViewMode = container.classList.contains('read-only-form') || !!container.querySelector('#db_id') || !!container.dataset.dbId;

        let printHtmlBody = '';

        if (isViewMode && formType) {
            // Clone the rendered modal form and convert controls to plain text so print matches the view
            const clone = container.cloneNode(true);

            // Remove interactive controls and buttons
            clone.querySelectorAll('button, #saveDocBtn, .close').forEach(n => n.remove());
            // Remove any hidden/db_id inputs
            clone.querySelectorAll('input[type="hidden"], input#db_id').forEach(n => n.remove());

            // Convert selects to text (show selected option)
            clone.querySelectorAll('select').forEach(sel => {
                const selected = sel.options[sel.selectedIndex] ? sel.options[sel.selectedIndex].text : '';
                const span = document.createElement('div');
                span.textContent = selected;
                span.style = 'font-weight:normal;margin-bottom:8px;';
                sel.parentNode.replaceChild(span, sel);
            });

            // Convert inputs to plain text
            clone.querySelectorAll('input').forEach(inp => {
                const val = inp.value || '';
                const span = document.createElement('div');
                span.textContent = val;
                span.style = 'font-weight:normal;margin-bottom:8px;';
                inp.parentNode.replaceChild(span, inp);
            });

            // Convert textareas to divs preserving line breaks
            clone.querySelectorAll('textarea').forEach(ta => {
                const val = ta.value || '';
                const div = document.createElement('div');
                div.style.whiteSpace = 'pre-wrap';
                div.textContent = val;
                ta.parentNode.replaceChild(div, ta);
            });

            // Remove disabled/readonly attributes
            clone.querySelectorAll('[disabled],[readonly]').forEach(n => { n.removeAttribute('disabled'); n.removeAttribute('readonly'); });

            printHtmlBody = clone.innerHTML;
        } else {
            // clone modal content (create/edit mode)
            const clone = container.cloneNode(true);
            const srcInputs = container.querySelectorAll('input,select,textarea');
            srcInputs.forEach(src => {
                if (!src.id) return;
                const dst = clone.querySelector('#' + CSS.escape(src.id));
                if (!dst) return;
                try { dst.value = src.value; } catch (e) {}
            });
            printHtmlBody = clone.innerHTML;
        }

        const printHtml = `<!doctype html><html><head><meta charset="utf-8"><title>Print</title><style>body{font-family:Arial,sans-serif;margin:20px} .form-group{margin-bottom:18px} label{display:block;font-weight:bold;margin-bottom:6px} input,select,textarea{width:100%;border:none;border-bottom:1px solid #000;padding:6px 0;background:transparent} .form-row{display:flex;gap:20px;margin-bottom:18px}.form-row .form-group{flex:1}</style></head><body>` + printHtmlBody + `</body></html>`;

        const ww = Math.min(900, Math.floor(window.screen.availWidth * 0.6));
        const hh = Math.min(1200, Math.floor(window.screen.availHeight * 0.85));
        const left = Math.max(0, Math.floor((window.screen.availWidth - ww) / 2));
        const top = Math.max(0, Math.floor((window.screen.availHeight - hh) / 2));
        const w = window.open('', '_blank', `width=${ww},height=${hh},left=${left},top=${top},resizable=yes,scrollbars=yes`);
        if (!w) return window.print();
        w.document.open();
        w.document.write(printHtml);
        w.document.close();
        setTimeout(() => { try { w.focus(); w.print(); } catch (e) { console.error('Print failed', e); } }, 200);
    } catch (e) {
        console.error('Print error, falling back to window.print()', e);
        window.print();
    }
}

// Close modal function
function closeDocModal() {
    const modal = document.getElementById('documentationModal');
    if (modal) modal.style.display = 'none';
    // restore body scroll
    try { document.body.style.overflow = ''; } catch (e) {}
    // remove injected extra style if present
    try {
        const container = document.getElementById('docModalForm');
        const prev = container ? container.querySelector('#docModalFormExtraStyle') : null;
        if (prev) prev.remove();
        const panel = modal ? modal.querySelector('.modal-content') || modal.querySelector('.modal-panel') : null;
        if (panel) { panel.style.maxHeight = ''; panel.style.overflow = ''; }
    } catch (e) {}
}

// Download blank PDF function
// downloadBlankPDF removed — blank print is handled by the Create Form modal and printDocument()

function loadFormTemplateForPrint(formType, container) {
    if (!container) return;
    container.innerHTML = '';
    const tmplId = 'tmpl-doc-' + formType;
    const tpl = document.getElementById(tmplId);
    if (!tpl) {
        container.innerHTML = '<p>Print template not found.</p>';
        return;
    }
    // For print-friendly version we remove select controls (replace with inputs/placeholders)
    const clone = tpl.content.cloneNode(true);
    let inner = clone.querySelector('.doc-form') || clone.querySelector('[data-form-type]');
    let appendNode = null;
    if (inner) {
        appendNode = inner.cloneNode(true);
    } else {
        appendNode = clone;
    }
    // Replace student select(s) with plain text inputs or placeholders inside the appendNode
    appendNode.querySelectorAll && appendNode.querySelectorAll('select').forEach(sel => {
        const input = document.createElement('input');
        input.type = 'text';
        input.id = sel.id || ('print_' + Math.random().toString(36).slice(2,8));
        input.placeholder = '________________________';
        sel.parentNode.replaceChild(input, sel);
    });
    container.appendChild(appendNode);
}

// Functions for existing form actions
function _resolveFormByUid(uid) {
    // uid format: "form_type::id" where id may be db_id or id
    if (!uid || typeof uid !== 'string') return null;
    const parts = uid.split('::');
    const formType = parts[0] || '';
    const rawId = parts.slice(1).join('::');
    // Try to find a matching form where form_type matches and id/db_id matches
    let found = existingForms.find(f => {
        const ft = f.form_type || f.formType || '';
        const rid = String(f.db_id || f.id || '');
        return String(ft) === String(formType) && rid === String(rawId);
    });
    if (found) return found;
    // fallback: try matching by db_id/id only
    found = existingForms.find(f => String(f.db_id || f.id) === String(rawId));
    return found || null;
}

function viewExistingForm(formUid) {
    // Resolve UID to form object
    const form = _resolveFormByUid(formUid);
    if (!form) {
        showNotification('Form not found', 'error');
        return;
    }

    // If this form is a file upload with a file_path, open file viewer
    if (form.file_path) {
        openFileViewer(form);
        return;
    }

    // If the form object contains full content/form_data, open the documentation modal in read-only mode
    const formData = form.form_data || form.content || null;
    const formType = form.form_type || (formData && formData.form_type) || 'unspecified';

    if (formData) {
        // Show modal and load template
        showDocModal(formType);
        // After a short delay to ensure template is rendered, populate fields in read-only mode
        setTimeout(() => {
            try {
                const modal = document.getElementById('documentationModal');
                if (!modal) return;
                const container = document.getElementById('docModalForm');
                // Populate inputs by matching keys in form_data
                const data = (typeof formData === 'string') ? JSON.parse(formData) : formData;

                // Ensure studentName select is populated with the correct student id
                try {
                    const studentSelect = container.querySelector('#studentName');
                    let studentIdToUse = null;
                    if (data && (data.studentName || data.student_id)) {
                        studentIdToUse = data.studentName || data.student_id;
                    } else if (form.student_id) {
                        studentIdToUse = form.student_id;
                    } else {
                        // fallback to currently selected student in the page
                        const pageSel = document.getElementById('studentSelect');
                        if (pageSel && pageSel.value) studentIdToUse = pageSel.value;
                    }
                    if (studentSelect && studentIdToUse) {
                        // if option exists, select it; otherwise try to add an option using the page students list
                        const opt = studentSelect.querySelector("option[value='" + CSS.escape(String(studentIdToUse)) + "']");
                        if (opt) {
                            studentSelect.value = studentIdToUse;
                        } else {
                            // try to find name from allStudents and append option
                            const s = allStudents.find(s => String(s.id) === String(studentIdToUse));
                            if (s) {
                                const newOpt = document.createElement('option');
                                newOpt.value = s.id;
                                newOpt.textContent = s.first_name + ' ' + s.last_name;
                                studentSelect.appendChild(newOpt);
                                studentSelect.value = s.id;
                            }
                        }
                        // mark as disabled/read-only for view mode
                        try { studentSelect.setAttribute('disabled', 'disabled'); } catch (e) {}
                    }
                } catch (err) {
                    console.warn('Failed to set studentName in view modal', err);
                }

                // Prepare created_at fallback for date inputs
                let createdAt = form.created_at || data.created_at || null;
                let isoDate = null;
                if (createdAt) {
                    try {
                        isoDate = new Date(createdAt).toISOString().split('T')[0];
                    } catch (e) {
                        isoDate = null;
                    }
                }

                // Populate all known fields from data, and for date fields use created_at when missing
                const setField = (key, value) => {
                    if (value === null || value === undefined) return;
                    // try exact key
                    let el = container.querySelector('#' + CSS.escape(key));
                    if (!el) {
                        // try snake_case / camelCase variants
                        const alt = key.replace(/([A-Z])/g, '_$1').toLowerCase();
                        el = container.querySelector('#' + CSS.escape(alt));
                        if (!el) {
                            // try removing underscores and camelCase
                            const alt2 = alt.replace(/_([a-z])/g, (_, c) => c.toUpperCase());
                            el = container.querySelector('#' + CSS.escape(alt2));
                        }
                    }
                    if (el) {
                        el.value = value;
                        try { el.setAttribute('disabled', 'disabled'); } catch (e) {}
                        try { el.setAttribute('readonly', 'readonly'); } catch (e) {}
                    }
                };

                Object.keys(data).forEach(k => {
                    setField(k, data[k]);
                });

                // If date inputs are present but not provided in data, use created_at
                if (isoDate) {
                    const dateIds = ['evaluationDate','goalDate','sessionDate','dischargeDate'];
                    dateIds.forEach(id => {
                        const el = container.querySelector('#' + CSS.escape(id));
                        if (el && (!el.value || String(el.value).trim() === '')) {
                            el.value = isoDate;
                            try { el.setAttribute('disabled', 'disabled'); } catch (e) {}
                            try { el.setAttribute('readonly', 'readonly'); } catch (e) {}
                        }
                    });
                }
                // Hide save button if present
                const saveBtn = modal.querySelector('#saveDocBtn');
                if (saveBtn) saveBtn.style.display = 'none';
                // change print button label for view mode
                const printBtn = modal.querySelector('#printDocBtn');
                if (printBtn) printBtn.textContent = 'Print PDF';
                // Add a class so CSS can style it as read-only if needed
                container.classList.add('read-only-form');
            } catch (err) {
                console.error('Error populating form for view:', err);
            }
        }, 100);
        return;
    }

    showNotification('No preview available for this item', 'info');
}

function editExistingForm(formUid) {
    // Allow editing of stored JSON forms (not uploaded files)
    const form = _resolveFormByUid(formUid);
    if (!form) return showNotification('Form not found', 'error');

    if (form.file_path) return showNotification('Editing uploaded files is not supported. Download and re-upload if needed.', 'info');

    const formData = form.form_data || form.content || null;
    const formType = form.form_type || (formData && formData.form_type) || 'unspecified';
    if (!formData) return showNotification('No editable content for this form', 'info');

    // Open documentation modal with template and populate fields (editable)
    showDocModal(formType);
    setTimeout(() => {
        try {
            const container = document.getElementById('docModalForm');
            const data = (typeof formData === 'string') ? JSON.parse(formData) : formData;
            Object.keys(data).forEach(k => {
                const el = container.querySelector('#' + CSS.escape(k));
                if (el) el.value = data[k];
            });
            // store db_id so the saveDocument function will send it
            if (form.db_id || form.id) {
                const dbid = form.db_id || form.id;
                // set on container dataset
                container.dataset.dbId = dbid;
                // ensure hidden input exists for backward compatibility
                let hid = container.querySelector('#db_id');
                if (!hid) {
                    hid = document.createElement('input');
                    hid.type = 'hidden';
                    hid.id = 'db_id';
                    container.appendChild(hid);
                }
                hid.value = dbid;
            }
            // Ensure save button is visible
            const modal = document.getElementById('documentationModal');
            const saveBtn = modal.querySelector('#saveDocBtn');
            if (saveBtn) saveBtn.style.display = '';
        } catch (err) {
            console.error('Error populating form for edit:', err);
        }
    }, 100);
}

function printExistingForm(formUid) {
    const form = _resolveFormByUid(formUid);
    if (!form) return showNotification('Form not found', 'error');

    if (form.file_path) {
        // Open file in new tab and let user print from browser
        window.open('/includes/download_document.php?id=' + encodeURIComponent(form.id), '_blank');
        return;
    }

    // For JSON forms, render printable view
    const formData = form.form_data || form.content || null;
    const formType = form.form_type || (formData && formData.form_type) || 'Form';
    if (!formData) return showNotification('No printable content for this form', 'info');

    // Render the form template into a temporary container and call print
    const temp = document.createElement('div');
    loadFormTemplateForPrint(formType, temp);
    setTimeout(() => {
        try {
            const data = (typeof formData === 'string') ? JSON.parse(formData) : formData;
            Object.keys(data).forEach(k => {
                const el = temp.querySelector('#' + CSS.escape(k));
                if (el) {
                    try { el.value = data[k]; } catch (e) { el.textContent = data[k]; }
                }
            });
            const w = window.open('', '_blank');
            w.document.write(`<!doctype html><html><head><meta charset="utf-8"><title>${formType}</title></head><body>${temp.innerHTML}</body></html>`);
            w.document.close();
            w.print();
        } catch (err) {
            console.error('Error printing form:', err);
            showNotification('Error printing form', 'error');
        }
    }, 100);
}

// Helper to open a modal viewer for uploaded files (PDF/image)
function openFileViewer(form) {
    // remove existing viewer if present
    const existing = document.getElementById('fileViewerModal');
    if (existing) existing.remove();

    const tpl = document.createElement('div');
            tpl.innerHTML = `
            <div id="fileViewerModal" class="modal-overlay" style="position:fixed;top:0;left:0;width:100%;height:100%;display:flex;align-items:center;justify-content:center;background:rgba(0,0,0,0.5);z-index:10000;">
                <div class="modal-panel" role="dialog" aria-modal="true" style="width:90%;height:90%;background:#fff;padding:12px;box-shadow:0 8px 24px rgba(0,0,0,0.4);position:relative;overflow:auto;border-radius:6px;">
                    <button class="close circle" type="button" aria-label="Close">&times;</button>
            <div id="fileViewerContent" style="width:100%;height:calc(100% - 48px);display:flex;align-items:center;justify-content:center;overflow:auto;padding:6px;box-sizing:border-box;"></div>
            <div style="margin-top:8px;text-align:right;position:relative;z-index:10001;padding-top:6px;">
                <button id="fileViewerPrint" class="btn btn-sm btn-primary">Print</button>
                <button id="fileViewerDelete" class="btn btn-sm btn-danger" style="margin-left:8px;">Delete</button>
            </div>
                </div>
            </div>`;
    document.body.appendChild(tpl);

    const modal = document.getElementById('fileViewerModal');
    const content = document.getElementById('fileViewerContent');
    const deleteBtn = document.getElementById('fileViewerDelete');
    modal.querySelector('.close').addEventListener('click', () => modal.remove());

    const docId = form.db_id || form.id;
    const table = form.table || 'other_documents';
    const fileUrl = form.file_path ? '/' + form.file_path.replace(/^\//, '') : `/includes/download_document.php?id=${encodeURIComponent(docId)}&table=${table}&inline=1`;
    const printBtn = document.getElementById('fileViewerPrint');
    printBtn.addEventListener('click', () => {
        const iframe = content.querySelector('iframe');
        const img = content.querySelector('img');
        if (iframe) {
            try {
                iframe.contentWindow.focus();
                iframe.contentWindow.print();
            } catch (err) {
                window.open(fileUrl, '_blank');
            }
            return;
        }
        if (img) {
            const w = window.open('', '_blank');
            w.document.write('<img src="' + img.src + '" style="max-width:100%">');
            w.document.close();
            w.focus();
            w.print();
            return;
        }
        window.open(fileUrl, '_blank');
    });

    // Delete handler
    deleteBtn.addEventListener('click', async () => {
        const ok = await (window.showConfirm ? window.showConfirm('Are you sure? This will permanently remove the document from the database.') : Promise.resolve(confirm('Are you sure? This will permanently remove the document from the database.')));
        if (!ok) return;
        try {
            const fd = new FormData();
            fd.append('action', 'delete_document');
            // Prefer explicit table + id when possible
            const explicitTable = form.table || (form.form_type ? (function(ft){ const m={'initial_evaluation':'initial_evaluations','initial_profile':'initial_evaluations','goals_form':'goals','goals':'goals','session_report':'session_reports','discharge_report':'discharge_reports','other_documents':'other_documents','uploaded_file':'other_documents'}; return m[ft]||null;})(form.form_type) : null);
            const numericId = (form.id && String(form.id).match(/^\d+$/)) ? String(form.id) : null;
            if (explicitTable && numericId) {
                fd.append('table', explicitTable);
                fd.append('id', numericId);
            } else if (form.form_data && form.form_data.id) {
                fd.append('content_id', String(form.form_data.id));
                fd.append('form_type', form.form_type || '');
            } else {
                fd.append('id', String(form.id));
            }
            // include current page student context where possible to avoid cross-student deletes
            try { const sel = document.getElementById('studentSelect'); if (sel && sel.value) fd.append('student_id', String(sel.value)); } catch(e){}
            const res = await fetch('/includes/submit.php', { method: 'POST', body: fd });
            const result = await res.json();
            if (result && result.success) {
                showNotification('Document deleted', 'success');
                // close modal
                const m = document.getElementById('fileViewerModal');
                if (m) m.remove();
                // refresh list for selected student
                const sel = document.getElementById('studentSelect');
                if (sel && sel.value) await loadStudentForms();
            } else {
                const msg = result && result.message ? result.message : 'Delete failed';
                showNotification('Delete failed: ' + msg, 'error');
                console.error('Delete failed response:', result);
            }
        } catch (err) {
            console.error('Delete error', err);
            showNotification('Delete failed', 'error');
        }
    });

    // Determine preview type by extension
    const fp = (form.file_path || '').toLowerCase();
    if (fp.endsWith('.pdf')) {
        const iframe = document.createElement('iframe');
        iframe.src = fileUrl;
        iframe.style.width = '100%';
        iframe.style.height = '80vh';
        iframe.style.border = 'none';
        content.appendChild(iframe);
    } else if (fp.match(/\.(jpg|jpeg|png|gif)$/)) {
        const img = document.createElement('img');
        img.src = fileUrl;
        img.style.maxWidth = '100%';
        img.style.maxHeight = '80vh';
        content.appendChild(img);
    } else {
        const p = document.createElement('p');
        p.textContent = 'Preview not available. Use Download to retrieve the file.';
        content.appendChild(p);
    }
}

// Upload modal functions
function showUploadModal(e) {
    e && e.preventDefault();
    // Find the template and insert into DOM
    const tpl = document.getElementById('tmpl-upload-document');
    if (!tpl) { showNotification('Upload template not found', 'error'); return; }
    // Remove any existing instance
    let existing = document.getElementById('uploadDocumentModal');
    if (existing) existing.remove();
    const clone = tpl.content.cloneNode(true);
    document.body.appendChild(clone);

    const modal = document.getElementById('uploadDocumentModal');
    if (!modal) return;

    // Cancel/close buttons
    modal.querySelectorAll('.close, .btn.btn-outline').forEach(btn => btn.addEventListener('click', () => modal.remove()));

    const form = document.getElementById('uploadDocumentForm');
    form.addEventListener('submit', submitUploadForm);

    modal.style.display = 'flex';
}

async function submitUploadForm(ev) {
    ev.preventDefault();
    const form = ev.currentTarget;
    const fd = new FormData(form);

    try {
        showNotification('Uploading file...', 'info');
        const res = await fetch('/includes/submit.php', { method: 'POST', body: fd });
        let result;
        try {
            result = await res.json();
        } catch (e) {
            const raw = await res.text();
            showNotification('Upload failed: server did not return JSON', 'error');
            console.error('Raw server response:', raw);
            return;
        }
        if (result && result.success) {
            showNotification('Upload successful', 'success');
            // close modal
            const modal = document.getElementById('uploadDocumentModal');
            if (modal) modal.remove();
            // refresh forms/uploads for selected student if matches
            const sel = document.getElementById('studentSelect');
            if (sel && sel.value) await loadStudentForms();
            // Refresh recent activity to show uploaded document
            try { if (typeof window.refreshRecentActivity === 'function') window.refreshRecentActivity(20); } catch (e) { /* ignore */ }
        } else {
            const msg = result && result.message ? result.message : (result && result.error ? result.error : 'unknown');
            showNotification('Upload failed: ' + msg, 'error');
            console.error('Upload failed response:', result);
        }
    } catch (err) {
        console.error('Upload error', err);
        showNotification('Upload failed', 'error');
    }
}

// Delete a form/document from the list
async function deleteExistingForm(formUid) {
    if (!formUid) return;
    const ok = await (window.showConfirm ? window.showConfirm('Are you sure you want to delete this document? This action cannot be undone.') : Promise.resolve(confirm('Are you sure you want to delete this document? This action cannot be undone.')));
    if (!ok) return;

    // Resolve UID to form and choose an id to send to the server.
    const form = _resolveFormByUid(formUid);
    if (!form) return showNotification('Form not found', 'error');

    // The server supports two deletion keys: numeric DB row id OR the content JSON's "id" value.
    // Prefer sending the DB row id if present; otherwise send the JSON content id.
    let sendId = '';
    if (form.db_id || form.id) sendId = String(form.db_id || form.id);
    else if (form.form_data && form.form_data.id) sendId = String(form.form_data.id);
    else if (form.content) {
        try { const j = typeof form.content === 'string' ? JSON.parse(form.content) : form.content; if (j && j.id) sendId = String(j.id); } catch (e) {}
    }
    if (!sendId) return showNotification('Unable to determine id to delete', 'error');

    try {
        const fd = new FormData();
        fd.append('action', 'delete_document');
        // Prefer to send explicit table + DB id when available to avoid ambiguous numeric-id fallbacks on the server
        const mapFormTypeToTable = (ft) => {
            if (!ft) return null;
            const m = {
                'initial_evaluation': 'initial_evaluations',
                'initial_profile': 'initial_evaluations',
                'goals_form': 'goals',
                'goals': 'goals',
                'session_report': 'session_reports',
                'discharge_report': 'discharge_reports',
                'other_documents': 'other_documents',
                'uploaded_file': 'other_documents'
            };
            return m[ft] || null;
        };

        const explicitTable = form.table || mapFormTypeToTable(form.form_type || (form.formType || ''));
        // If we have a DB row id and a table, send them explicitly
        const numericDbId = (form.db_id || form.id) && String(form.db_id || form.id).match(/^\d+$/) ? String(form.db_id || form.id) : null;
        if (explicitTable && numericDbId) {
            fd.append('table', explicitTable);
            fd.append('id', numericDbId);
        } else {
            // Otherwise, indicate whether we're sending a content-based id so server won't assume it's a DB row id
            if (form.form_data && form.form_data.id) {
                fd.append('content_id', String(form.form_data.id));
                fd.append('form_type', form.form_type || '');
            } else {
                fd.append('id', sendId);
            }
        }
        const res = await fetch('/includes/submit.php', { method: 'POST', body: fd });
        const result = await res.json();
        if (result && result.success) {
            showNotification('Document deleted', 'success');
            // If this was a goals form, decrement the global cached goals count so dashboard updates immediately
            try {
                const ft = form.form_type || (form.form_data && form.form_data.form_type) || '';
                if (ft === 'goals_form' || ft === 'goals') {
                    if (window.SLPCache && typeof window.SLPCache.decGoals === 'function') window.SLPCache.decGoals(1);
                }
            } catch (e) { /* ignore */ }
            // refresh current student list
            const sel = document.getElementById('studentSelect');
            if (sel && sel.value) await loadStudentForms();
            // Refresh recent activity to reflect deletion
            try { if (typeof window.refreshRecentActivity === 'function') window.refreshRecentActivity(20); } catch (e) { /* ignore */ }
        } else {
            const msg = result && result.message ? result.message : 'Delete failed';
            showNotification('Delete failed: ' + msg, 'error');
        }
    } catch (err) {
        console.error('Error deleting document', err);
        showNotification('Error deleting document', 'error');
    }
}

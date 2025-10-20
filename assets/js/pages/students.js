// Page module: students
import { apiFetch } from '../api.js';
import { showNotification, closeModal, insertModal, addStudentToSelects } from '../ui.js';
export function showAddStudentModal(preselectId) {
    const tpl = document.getElementById('tmpl-add-student');
    if (!tpl) return;
    const clone = tpl.content.cloneNode(true);

    // Insert modal via central helper which attaches close handlers and backdrop
    // insertModal returns the inserted modal element (or wrapper)
    const inserted = insertModal(clone);
    const modalRoot = inserted || document.querySelector('.modal') || clone;
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

    // Attach submit handler scoped to this inserted modal's form
    const form = modalRoot.querySelector('form');
    if (form) {
        // Avoid double-binding if the fallback modal-local handler already exists
        if (!form.dataset.slpSubmitBound) {
            form.addEventListener('submit', submitStudentForm);
            form.dataset.slpSubmitBound = '1';
        }
    }
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
            // Refresh recent activity so the dashboard shows the new student immediately
            try { if (typeof window.refreshRecentActivity === 'function') window.refreshRecentActivity(20); } catch (e) { /* ignore */ }
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

// Claim Existing Student feature removed per security/partitioning policy. Intentionally left blank.

function init() {
    // search box filtering
    const search = document.getElementById('studentSearch');
    if (search) {
        // Use a unified filter that takes into account name search and grade filter
        const gradeFilterEl = document.getElementById('gradeFilter');
        function applyFilters() {
            const q = (search.value || '').trim().toLowerCase();
            const grade = gradeFilterEl ? gradeFilterEl.value : '';

            const rows = Array.from(document.querySelectorAll('.student-row'));
            let anyVisible = false;

            rows.forEach(row => {
                const nameEl = row.querySelector('.student-name');
                const name = nameEl ? (nameEl.textContent || '').trim().toLowerCase() : (row.textContent || '').toLowerCase();
                const rowGradeSection = row.closest('.grade-section');
                const rowGrade = rowGradeSection ? (rowGradeSection.getAttribute('data-grade') || '') : '';

                const matchesQuery = !q || name.includes(q);
                const matchesGrade = !grade || String(rowGrade) === String(grade);

                const show = matchesQuery && matchesGrade;
                row.style.display = show ? '' : 'none';
                if (show) anyVisible = true;
            });

            // Hide grade sections that ended up empty
            document.querySelectorAll('.grade-section').forEach(section => {
                const visibleRows = section.querySelectorAll('.grade-students > .student-row:not([style*="display: none"])');
                section.style.display = visibleRows.length ? '' : 'none';
            });

            // Show a no-results message only when filters are applied and nothing matches
            let noResults = document.getElementById('studentsNoResults');
            const container = document.querySelector('.students-by-grade');
            if (!noResults && container) {
                noResults = document.createElement('div');
                noResults.id = 'studentsNoResults';
                noResults.className = 'no-students muted';
                noResults.innerHTML = '<div class="no-students-icon">🔍</div><h3>No matching students</h3><p>No students match your search criteria.</p>';
                noResults.style.display = 'none';
                container.parentNode.insertBefore(noResults, container.nextSibling);
            }
            if (noResults) {
                const filtersActive = (!!q) || (!!grade);
                // Only show the no-results panel if filters are active and nothing is visible
                noResults.style.display = (!filtersActive || anyVisible) ? 'none' : '';
            }
        }

        search.addEventListener('input', applyFilters);
        // apply immediately in case there's a prefilled search
        applyFilters();
    }

    const gradeFilter = document.getElementById('gradeFilter');
    if (gradeFilter) {

        // Ensure grade changes also apply unified filters
        gradeFilter.addEventListener('change', () => {
            const ev = new Event('input');
            const searchEl = document.getElementById('studentSearch');
            // reuse applyFilters if available by triggering input event; otherwise run simple filter
            if (searchEl) searchEl.dispatchEvent(ev);
        });

// Additional helpers used by templates
window.exportStudent = async function(id) {
        try {
        // Always regenerate the student report so template edits are reflected immediately.
        // Prefer the server-side full progress report (generate_student_report). This includes profile, goals, docs, and progress.
        try {
            // Collect any visible skill chart canvases into data URLs and attach as multipart FormData
            const dataURLToBlob = (dataURL) => {
                if (!dataURL) return null;
                const parts = dataURL.split(',');
                const meta = parts[0].match(/:(.*?);/);
                const mime = meta ? meta[1] : 'image/png';
                const bstr = atob(parts[1]);
                let n = bstr.length; const u8 = new Uint8Array(n);
                while (n--) u8[n] = bstr.charCodeAt(n);
                return new Blob([u8], { type: mime });
            };

            // Collect chart canvases into dataURLs. Retry a few times (short delay) so
            // charts that are still rendering (new skills) get picked up.
            const collectCharts = async () => {
                const maxAttempts = 6;
                const delayMs = 150;
                let lastMap = {};
                // Gather the list of skill-card ids to aim for
                const cards = Array.from(document.querySelectorAll('.skill-card')).filter(c => !c.classList.contains('add-skill-card'));
                const targetIds = cards.map(c => c.dataset.skillId).filter(Boolean);

                for (let attempt = 0; attempt < maxAttempts; attempt++) {
                    const chartMap = {};
                    for (const card of cards) {
                        const sid = card.dataset.skillId;
                        if (!sid) continue;
                        const canvas = document.getElementById('skillChart-' + sid) || card.querySelector('canvas');
                        if (canvas && typeof canvas.toDataURL === 'function') {
                            try { chartMap[sid] = canvas.toDataURL('image/png'); } catch (err) { console.warn('toDataURL failed for skill', sid, err); }
                        }
                    }
                    // If we've captured charts for all target ids, return immediately
                    const keys = Object.keys(chartMap);
                    if (targetIds.length === 0 || keys.length === targetIds.length) return chartMap;
                    // If this attempt made progress, remember it and retry for missing ones
                    if (keys.length > Object.keys(lastMap).length) lastMap = chartMap;
                    // wait a short moment before trying again
                    await new Promise(r => setTimeout(r, delayMs));
                }
                // return whatever we captured on the last attempt
                return lastMap;
            };

            let chartMap = await collectCharts();
            // If no charts found, wait a short moment and retry once — helps when Chart.js finishes rendering slightly later
            if (Object.keys(chartMap).length === 0) {
                await new Promise(res => setTimeout(res, 300));
                chartMap = await collectCharts();
            }
            // If some skills were not captured (no canvas present or not yet rendered),
            // attempt to fetch the skill list and render missing charts into temporary
            // canvases using Chart.js (when available). This ensures newly-added skills
            // are included in exports even if their live card isn't present.
            try {
                // fetch canonical skills for this student
                const skillsResp = await fetch('/includes/submit.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams({ action: 'get_student_skills', student_id: String(id) }) });
                const skillsJson = await skillsResp.json();
                if (skillsJson && skillsJson.success && Array.isArray(skillsJson.skills)) {
                    const skillIds = skillsJson.skills.map(s => String(s.id));
                    const missing = skillIds.filter(sid => !Object.prototype.hasOwnProperty.call(chartMap, sid));
                    if (missing.length && window.Chart) {
                        for (const mid of missing) {
                            try {
                                // fetch updates for the missing skill
                                const updResp = await fetch('/includes/submit.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams({ action: 'get_skill_updates', skill_id: String(mid) }) });
                                const updJson = await updResp.json();
                                if (!updJson || !updJson.success || !Array.isArray(updJson.updates) || updJson.updates.length === 0) continue;
                                const updates = updJson.updates;
                                // Use local date formatting and normalize percent values
                                const labels = updates.map(u => {
                                    try { const d = u.date_recorded || u.created_at || ''; return (function(v){ if(!v) return ''; const dd = new Date(v); if (isNaN(dd.getTime())) return ''; const pad=(n)=>String(n).padStart(2,'0'); return `${dd.getFullYear()}-${pad(dd.getMonth()+1)}-${pad(dd.getDate())}`; })(d); } catch (e) { return ''; }
                                });
                                const dataPoints = updates.map(u => { const n = (function(v){ if (v===null||typeof v==='undefined'||v==='') return NaN; const nv = Number(String(v).trim().replace('%','')); return isNaN(nv) ? NaN : ((nv>0&&nv<=1)?(nv*100):nv); })(u.score); return isNaN(n) ? null : n; });
                                try { if (window.SLP_DEBUG_CHARTS) { console.debug('[SLP_DEBUG] missing-skill', mid, 'raw scores', updates.map(u => u.score)); console.debug('[SLP_DEBUG] missing-skill', mid, 'parsed dataPoints', dataPoints); } } catch(e) {}
                                // temporary canvas
                                const tmp = document.createElement('canvas'); tmp.width = 600; tmp.height = 240; tmp.style.position = 'fixed'; tmp.style.left = '-9999px'; tmp.style.top = '-9999px'; document.body.appendChild(tmp);
                                const ctx = tmp.getContext('2d');
                                const chart = new Chart(ctx, {
                                    type: 'line',
                                    data: { labels: labels, datasets: [{ label: 'Score', data: dataPoints, borderColor: '#3b82f6', backgroundColor: 'rgba(59,130,246,0.1)', tension: 0.35, fill: true, pointRadius: 3 }] },
                                    options: { responsive: false, maintainAspectRatio: false, scales: { y: { beginAtZero: true, min: 0, max: 100 } }, plugins: { legend: { display: false } } }
                                });
                                // wait briefly for Chart to render
                                await new Promise(r => setTimeout(r, 120));
                                try { chartMap[mid] = tmp.toDataURL('image/png'); } catch (e) { /* ignore */ }
                                try { chart.destroy(); } catch (e) {}
                                try { tmp.remove(); } catch (e) { try { document.body.removeChild(tmp); } catch(e2){} }
                            } catch (e) { console.warn('Could not render chart for missing skill', mid, e); }
                        }
                    }
                }
            } catch (e) { /* non-fatal: continue with whatever chartMap we have */ }

            // Build FormData: send only JSON data URLs to minimize payload size; server will embed as data URIs
            const fdGen = new FormData();
            fdGen.append('action', 'generate_student_report');
            fdGen.append('student_id', String(id));
            try {
                let chartJson = JSON.stringify(chartMap || {});
                // Safety cap: if chart JSON is too large, skip sending images and rely on server-rendered SVGs
                if (chartJson && chartJson.length > 1500000) { // ~1.5MB
                    console.warn('Chart payload too large; omitting chart_images to avoid request limits');
                    chartJson = '{}';
                }
                fdGen.append('chart_images', chartJson);
            } catch (e) { /* ignore */ }

            const resGen = await fetch('/includes/submit.php', { method: 'POST', body: fdGen, credentials: 'same-origin' });
            const gen = await resGen.json();
            if (gen && gen.success && gen.html) {
                const html = gen.html;
                const title = gen.title || 'Printable Preview';
                const modalFrag = document.createRange().createContextualFragment(`<div class="modal"><div class="modal-content modal-wide"><div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;"><strong>${title.replace(/</g,'&lt;').replace(/>/g,'&gt;')}</strong><div><button class="close">&times;</button></div></div><iframe id="previewFrame" style="width:100%;height:84vh;border:1px solid #ddd;border-radius:6px;background:#fff"></iframe><div style="display:flex;justify-content:flex-end;margin-top:8px;"><button id="previewPrintBtn" class="btn btn-primary">Print</button></div></div></div>`);
                const modalEl = insertModal(modalFrag);
                const frame = modalEl.querySelector('#previewFrame');
                try {
                    // Use srcdoc when possible to avoid external fetches and preserve data URIs
                    frame.srcdoc = html;
                } catch (e) {
                    try {
                        frame.contentWindow.document.open();
                        frame.contentWindow.document.write(html);
                        frame.contentWindow.document.close();
                    } catch (e2) { /* ignore */ }
                }
                const prbtn = modalEl.querySelector('#previewPrintBtn');
                if (prbtn) prbtn.addEventListener('click', async () => {
                    try {
                        const doPrintPreview = () => {
                            if (frame.contentWindow && frame.contentDocument && frame.contentDocument.readyState === 'complete') {
                                frame.contentWindow.focus(); frame.contentWindow.print();
                            } else {
                                frame.addEventListener('load', function onload() { frame.removeEventListener('load', onload); try { frame.contentWindow.focus(); frame.contentWindow.print(); } catch (e) { showNotification('Unable to print preview', 'error'); } });
                            }
                        };

                        // If the preview contains embedded PDFs, they may not print reliably from the iframe
                        let foundPdf = false;
                        try {
                            const doc = frame.contentDocument || (frame.contentWindow && frame.contentWindow.document);
                            if (doc) {
                                const embeds = Array.from(doc.querySelectorAll('embed[type="application/pdf"], object[type="application/pdf"], iframe[src$=".pdf"]'));
                                if (embeds.length) {
                                    foundPdf = true;
                                    const ok = await (window.showConfirm ? window.showConfirm('This report contains embedded PDF(s) which may not print correctly from the preview. Open the PDF(s) in new tab(s) for printing? Click Cancel to print the preview anyway.') : Promise.resolve(confirm('This report contains embedded PDF(s) which may not print correctly from the preview. Open the PDF(s) in new tab(s) for printing? Click Cancel to print the preview anyway.')));
                                    if (ok) {
                                        embeds.forEach(el => {
                                            let src = el.getAttribute('src') || el.getAttribute('data') || el.getAttribute('href');
                                            if (!src && el.querySelector && el.querySelector('embed')) src = el.querySelector('embed').getAttribute('src');
                                            if (!src) return;
                                            try { window.open(src, '_blank'); } catch (e) { /* ignore */ }
                                        });
                                        return; // user opted to open PDFs separately
                                    }
                                }
                            }
                        } catch (e) { console.warn('Could not inspect preview iframe for embedded PDFs', e); }

                        doPrintPreview();
                    } catch (e) { showNotification('Unable to print preview', 'error'); }
                });
                return;
            }

            // Inline path failed; show server error if present

            // Generic fallback: show server error if present
            alert((gen && (gen.error || gen.message)) ? (gen.error || gen.message) : 'Student report not found or export unavailable');
            return;
        } catch (e) {
            console.error('generate_student_report failed', e);
            alert('Export failed: ' + (e && e.message ? e.message : 'Unknown error'));
            return;
        }
    } catch (e) { console.error(e); alert('Export failed'); }
};

window.archiveStudent = async function(id) {
    const ok = await (window.showConfirm ? window.showConfirm('Archive this student? They will be hidden from lists but can be restored.') : Promise.resolve(confirm('Archive this student? They will be hidden from lists but can be restored.')));
    if (!ok) return;
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

    if (typeof window.viewProfile !== 'function') {
    window.viewProfile = function(id) {
        try {
            (async function(){
                // Fetch student record
                let student = null;
                try {
                    const fd = new URLSearchParams(); fd.append('action','get_student'); fd.append('id', String(id));
                    const res = await fetch('/includes/submit.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: fd.toString() });
                    const json = await res.json(); if (json && json.success) student = json.student;
                } catch (e) { console.warn('Could not fetch student', e); }

                // Fetch document/forms to compute counts (best-effort)
                let counts = { session_report:0, initial_evaluation:0, discharge_report:0, other_documents:0, goals:0 };
                try {
                    const fd2 = new URLSearchParams(); fd2.append('action','get_student_forms'); fd2.append('student_id', String(id));
                    const res2 = await fetch('/includes/submit.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: fd2.toString() });
                    const list = await res2.json();
                    if (list && list.success && Array.isArray(list.forms)) {
                        list.forms.forEach(f => {
                            const t = f.form_type || (f.form_type === null && f.title ? f.title.toLowerCase() : 'other');
                            if (t === 'session_report' || t === 'session') counts.session_report++;
                            else if (t === 'initial_evaluation' || t === 'initial_profile') counts.initial_evaluation++;
                            else if (t === 'discharge_report' || t === 'discharge') counts.discharge_report++;
                            else if (t === 'goals_form' || t === 'goals') counts.goals++;
                            else counts.other_documents++;
                        });
                    }
                } catch (e) { console.warn('Could not fetch student forms for counts', e); }

                // Check for active progress report metadata
                let hasReport = false;
                try {
                    const fd3 = new URLSearchParams(); fd3.append('action','get_student_report'); fd3.append('student_id', String(id));
                    const res3 = await fetch('/includes/submit.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: fd3.toString() });
                    const rj = await res3.json(); if (rj && rj.success && rj.report) hasReport = true;
                } catch (e) { console.warn('Could not fetch student report metadata', e); }

                // Also fetch progress skills so the modal shows up-to-date skill list
                let skills = [];
                try {
                    const fdSkills = new URLSearchParams(); fdSkills.append('action', 'get_student_skills'); fdSkills.append('student_id', String(id));
                    const resSkills = await fetch('/includes/submit.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: fdSkills.toString() });
                    const sj = await resSkills.json(); if (sj && sj.success && Array.isArray(sj.skills)) skills = sj.skills;
                } catch (e) { console.warn('Could not fetch student skills', e); }

                const tmpl = document.getElementById('tmpl-student-profile');
                if (!tmpl) { alert('Profile template missing'); return; }
                const clone = tmpl.content.firstElementChild.cloneNode(true);
                const body = clone.querySelector('#studentProfileBody');
                if (!student) {
                    body.innerHTML = '<p>Student not found.</p>';
                } else {
                    // Render simplified profile: basic fields + counts + active report flag
                    const assignedName = (student.assigned_therapist_name || '') || (student.assigned_therapist ? String(student.assigned_therapist) : 'Unassigned');
                    body.innerHTML = `
                        <h3>${escapeHtml(student.first_name || '')} ${escapeHtml(student.last_name || '')}</h3>
                        <p><strong>Student ID:</strong> ${escapeHtml(student.student_id || student.id || '')}</p>
                        <p><strong>Grade:</strong> ${escapeHtml(student.grade || '')}</p>
                        <p><strong>DOB:</strong> ${escapeHtml(student.date_of_birth || '')}</p>
                        <p><strong>Primary language:</strong> ${escapeHtml(student.primary_language || '')}</p>
                        <p><strong>Assigned Therapist:</strong> ${escapeHtml(assignedName)}</p>
                        <hr />
                        <h4>Documents</h4>
                        <ul>
                            <li>Session reports: ${counts.session_report}</li>
                            <li>Initial evaluations/profiles: ${counts.initial_evaluation}</li>
                            <li>Discharge reports: ${counts.discharge_report}</li>
                            <li>Other documents: ${counts.other_documents}</li>
                            <li>Goals forms: ${counts.goals}</li>
                        </ul>
                        
                        
                        <div style="margin-top:8px">
                            <h4>Skills</h4>
                            ${(skills && skills.length) ? (`<ul>${skills.map(sk=>{
                                const label = (sk.skill_label || sk.skillName || sk.title || sk.name || ('Skill ' + (sk.id||''))).replace(/</g,'&lt;').replace(/>/g,'&gt;');
                                const cat = sk.category ? (' - ' + String(sk.category).replace(/</g,'&lt;').replace(/>/g,'&gt;')) : '';
                                return `<li>${label}${cat}</li>`;
                            }).join('')}</ul>`) : '<div class="muted">No skills available.</div>'}
                        </div>
                    `;
                }
                insertModal(clone);
            })();
        } catch (e) { console.error(e); }
    };
}
        // (legacy handler removed in favor of combined filters above)
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
    window.removeStudent = async function(id) {
        if (_existingRemoveStudent && _existingRemoveStudent !== window.removeStudent) {
            return _existingRemoveStudent(id);
        }
    const ok = await (window.showConfirm ? window.showConfirm('Remove student #' + id + '?') : Promise.resolve(confirm('Remove student #' + id + '?')));
    if (!ok) return;
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

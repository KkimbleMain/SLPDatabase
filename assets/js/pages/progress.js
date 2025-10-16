// Progress page module
import { apiFetch } from '../api.js';
import { insertModal, showNotification } from '../ui.js';

// Helper: convert dataURL to Blob for multipart upload
function dataURLToBlob(dataURL) {
    if (!dataURL) return null;
    const parts = dataURL.split(',');
    const meta = parts[0].match(/:(.*?);/);
    const mime = meta ? meta[1] : 'image/png';
    const bstr = atob(parts[1]);
    let n = bstr.length;
    const u8 = new Uint8Array(n);
    while (n--) u8[n] = bstr.charCodeAt(n);
    return new Blob([u8], { type: mime });
}

// Helper: format an ISO date string or Date into local YYYY-MM-DD or YYYY-MM-DD HH:MM
function formatLocalDate(v, includeTime = false) {
    if (!v) return '';
    try {
        const d = (v instanceof Date) ? v : new Date(v);
        if (isNaN(d.getTime())) return '';
        const pad = (n) => String(n).padStart(2, '0');
        const y = d.getFullYear();
        const m = pad(d.getMonth() + 1);
        const day = pad(d.getDate());
        if (!includeTime) return `${y}-${m}-${day}`;
        const hh = pad(d.getHours());
        const mm = pad(d.getMinutes());
        return `${y}-${m}-${day} ${hh}:${mm}`;
    } catch (e) { return '' }
}

// Parse a score/target value that may be stored as number, '50', '50%', or a fraction 0.5
function parsePercent(v) {
    if (v === null || typeof v === 'undefined' || v === '') return NaN;
    if (typeof v === 'number') {
        // treat 0..1 as fraction, >1 as percent
        return (v > 0 && v <= 1) ? (v * 100) : Number(v);
    }
    try {
        let s = String(v).trim();
        if (s === '') return NaN;
        if (s.endsWith('%')) s = s.slice(0, -1).trim();
        const n = Number(s);
        if (isNaN(n)) return NaN;
        return (n > 0 && n <= 1) ? (n * 100) : n;
    } catch (e) { return NaN; }
}

function formatPercentDisplay(v) {
    const n = parsePercent(v);
    if (isNaN(n)) return '-';
    // show integer percent when close to integer, otherwise one decimal
    return (Math.abs(n - Math.round(n)) < 0.01) ? (Math.round(n) + '%') : (n.toFixed(1) + '%');
}

async function fetchSkills(studentId) {
    const fd = new URLSearchParams(); fd.append('action','get_student_skills'); fd.append('student_id', String(studentId));
    return await apiFetch('/includes/submit.php', { method: 'POST', body: fd.toString(), headers: { 'Content-Type': 'application/x-www-form-urlencoded' } });
}

async function fetchStudentReport(studentId) {
    const fd = new URLSearchParams(); fd.append('action','get_student_report'); fd.append('student_id', String(studentId));
    return await apiFetch('/includes/submit.php', { method: 'POST', body: fd.toString(), headers: { 'Content-Type': 'application/x-www-form-urlencoded' } });
}

async function fetchLatestProgressReport(studentId) {
    const fd = new URLSearchParams(); fd.append('action','get_latest_progress_report'); fd.append('student_id', String(studentId));
    return await apiFetch('/includes/submit.php', { method: 'POST', body: fd.toString(), headers: { 'Content-Type': 'application/x-www-form-urlencoded' } });
}

// Re-fetch updates for every visible skill card and update its UI/chart
async function refreshAllSkillData(studentId) {
    const section = document.querySelector('.skills-list');
    if (!section) return;
    const cards = Array.from(section.querySelectorAll('.skill-card')).filter(c => !c.classList.contains('add-skill-card'));
    await Promise.all(cards.map(async (card) => {
        const sid = card.dataset.skillId;
        if (!sid) return;
        try {
            const updates = await fetchUpdates(sid);
            if (!updates || !updates.success) return;
            card._updates = updates.updates;
            const last = updates.updates[updates.updates.length - 1];
                 const labels = updates.updates.map(u => formatLocalDate(u.date_recorded || u.created_at || '', false));
                 const dataPoints = updates.updates.map(u => { const p = parsePercent(u.score); return isNaN(p) ? null : p; });
            const curScoreEl = card.querySelector('.skill-current-score');
            if (curScoreEl) curScoreEl.textContent = formatPercentDisplay(last.score);
            const targetVal = (last && typeof last.target_score !== 'undefined') ? parsePercent(last.target_score) : null;
            const tgtEl = card.querySelector('.skill-target-score'); if (tgtEl) tgtEl.textContent = targetVal !== null && !isNaN(targetVal) ? String(Math.round(targetVal)) : '-';
            if (targetVal === 100) card.classList.add('target-top-space'); else card.classList.remove('target-top-space');

            // update badge/status using normalized percent values
            try { const existingBadge = card.querySelector('.skill-badge'); if (existingBadge) existingBadge.remove(); } catch (e) {}
            const badge = document.createElement('span'); badge.className = 'skill-badge';
            if (targetVal !== null && !isNaN(targetVal)) {
                const lastScoreVal = parsePercent(last.score);
                if (!isNaN(lastScoreVal) && lastScoreVal >= targetVal) { badge.textContent = 'Achieved'; badge.classList.add('badge-achieved'); card.classList.add('achieved'); card.classList.remove('working','needs-attention'); }
                else if (!isNaN(lastScoreVal) && lastScoreVal >= (targetVal - 20)) { badge.textContent = 'Working'; badge.classList.add('badge-working'); card.classList.add('working'); card.classList.remove('achieved','needs-attention'); }
                else { badge.textContent = 'Needs Attention'; badge.classList.add('badge-alert'); card.classList.add('needs-attention'); card.classList.remove('working','achieved'); }
                try { curScoreEl.parentElement.appendChild(badge); } catch(e) {}
            }

            // rebuild chart
            try {
                    const labels = updates.updates.map(u => formatLocalDate(u.date_recorded || u.created_at || '', false));
                const dataPoints = updates.updates.map(u => {
                    const p = parsePercent(u.score);
                    return isNaN(p) ? null : p;
                });
                const ctx = document.getElementById('skillChart-' + sid);
                if (ctx && window.Chart) {
                    if (card._chart && typeof card._chart.destroy === 'function') card._chart.destroy();
                    const recentTarget = (updates.updates[updates.updates.length-1] && typeof updates.updates[updates.updates.length-1].target_score !== 'undefined') ? parsePercent(updates.updates[updates.updates.length-1].target_score) : null;
                    // Debug: show raw and parsed percent values when debugging is enabled
                    try {
                        if (window.SLP_DEBUG_CHARTS) {
                            try { console.debug('[SLP_DEBUG] skill', sid, 'raw scores', updates.updates.map(u => u.score)); } catch(e){}
                            try { console.debug('[SLP_DEBUG] skill', sid, 'parsed dataPoints', dataPoints); } catch(e){}
                            try { console.debug('[SLP_DEBUG] skill', sid, 'recentTarget', recentTarget); } catch(e){}
                        }
                    } catch (e) { /* ignore */ }
                    const targetLinePlugin = {
                        id: 'targetLinePlugin',
                        afterDatasetsDraw: function(chart, args, options) {
                            const target = options.target; if (target === null || typeof target === 'undefined') return;
                            const yScale = chart.scales['y']; if (!yScale) return; const y = yScale.getPixelForValue(target);
                            const ctx2 = chart.ctx; ctx2.save(); ctx2.beginPath(); ctx2.moveTo(chart.chartArea.left, y); ctx2.lineTo(chart.chartArea.right, y);
                            ctx2.lineWidth = 2; ctx2.setLineDash([6,4]); ctx2.strokeStyle = options.color || 'rgba(220,38,38,0.9)'; ctx2.stroke();
                            ctx2.fillStyle = options.color || 'rgba(220,38,38,0.9)'; ctx2.font = '12px Arial'; const label = options.label || 'Target ' + target + '%'; const textWidth = ctx2.measureText(label).width; const px = Math.min(chart.chartArea.right - textWidth - 6, chart.chartArea.right - 6); ctx2.fillText(label, px, y - 6); ctx2.restore();
                        }
                    };

                    const chart = new Chart(ctx.getContext('2d'), {
                        type: 'line',
                        data: { labels: labels, datasets: [{ label: 'Score', data: dataPoints, borderColor: '#3b82f6', backgroundColor: 'rgba(59,130,246,0.1)', tension: 0, fill: true, pointRadius: 3 }] },
                        plugins: [targetLinePlugin],
                        options: { 
                            maintainAspectRatio: false, 
                            layout: { padding: { top: 16 } },
                            scales: { 
                                y: { beginAtZero: true, min: 0, max: 100, ticks: { stepSize: 20, callback: v => (v === 100 ? '100%' : (v + '%')) } },
                                x: { ticks: { maxRotation: 0, autoSkip: true } } 
                            }, 
                            plugins: { legend: { display: false }, targetLinePlugin: { target: recentTarget, color: 'rgba(220,38,38,0.9)', label: recentTarget !== null ? ('Target ' + recentTarget + '%') : '' } } 
                        }
                    });
                    card._chart = chart;
                }
            } catch (err) { console.error('refresh chart error', err); }
        } catch (err) { console.error('refresh skill', sid, err); }
    }));
}

async function fetchUpdates(skillId) {
    const fd = new URLSearchParams(); fd.append('action','get_skill_updates'); fd.append('skill_id', String(skillId));
    return await apiFetch('/includes/submit.php', { method: 'POST', body: fd.toString(), headers: { 'Content-Type': 'application/x-www-form-urlencoded' } });
}

export async function initializeProgress(studentId) {
    if (!studentId) return;
    const container = document.querySelector('.progress-overview') || document.querySelector('.container');
    if (!container) return;
    // load skills and render cards into a detached container to avoid visual flash
    try {
        const r = await fetchSkills(studentId);
        if (!r.success) { showNotification(r.error || 'Failed to load skills','error'); return; }
        // Build into a detached wrapper first
        const tempWrapper = document.createElement('div');
        tempWrapper.className = 'progress-overview temp';
        // renderSkills will create and return the .skills-list section inside the wrapper
        const builtSection = renderSkills(r.skills || [], studentId, tempWrapper);

        // At this point, hydrateSkillCards will perform chart drawing and update per-card content
        // but it requires the canvases to be in the live DOM. So swap the built section into the
        // live container in one atomic operation, then hydrate.
        try {
            // find existing live section to replace
            const liveSection = container.querySelector('.skills-list');
            if (liveSection) {
                // Replace existing section with the new built one
                liveSection.parentNode.replaceChild(builtSection, liveSection);
            } else {
                // Insert at top of container
                container.insertBefore(builtSection, container.firstChild);
            }

            // We intentionally avoid writing a persistent student-name element here
            // to prevent stale names from appearing when no student is selected.

            // Now hydrate charts and per-card details
            try { await hydrateSkillCards(builtSection); } catch (e) { console.warn('hydrateSkillCards failed', e); }
        } catch (e) {
            // If DOM swap fails, fall back to direct render to avoid leaving the page blank
            console.error('DOM swap failed, falling back to render into container', e);
            try { renderSkills(r.skills || [], studentId, container); } catch (er) { console.error('fallback render failed', er); }
        }

        // After rendering, update UI state and wire toolbar actions (generate/close/delete/print)
        try {
            // fetch existing report metadata (if any) and update UI
            let rep = null;
            try {
                // Prefer progress_reports (new table) via get_latest_progress_report
                try {
                    const latest = await fetchLatestProgressReport(studentId);
                    if (latest && latest.success && latest.report) rep = latest.report;
                } catch (e) { /* ignore */ }
                // Fallback to legacy student_reports if no progress_reports row found
                if (!rep) {
                    try { const repResp = await fetchStudentReport(studentId); if (repResp && repResp.success) rep = repResp.report; } catch(e) { /* ignore */ }
                }
            } catch (e) { /* ignore overall */ }
            updateReportUIState(rep, studentId);

            // helper to collect chart dataURLs for all skill charts; retry briefly to allow
            // newly-created skill charts to finish rendering before capture.
            const collectCharts = async () => {
                const maxAttempts = 6;
                const delayMs = 150;
                let lastMap = {};
                const cards = Array.from(document.querySelectorAll('.skill-card')).filter(c => !c.classList.contains('add-skill-card'));
                const targetIds = cards.map(c => c.dataset.skillId).filter(Boolean);

                for (let attempt = 0; attempt < maxAttempts; attempt++) {
                    const chartMap = {};
                    for (const card of cards) {
                        const sid = card.dataset.skillId;
                        if (!sid) continue;
                        const canvas = document.getElementById('skillChart-' + sid) || card.querySelector('canvas') || null;
                        if (canvas && typeof canvas.toDataURL === 'function') {
                            try { chartMap[sid] = canvas.toDataURL('image/png'); } catch (err) { console.warn('toDataURL failed for skill', sid, err); }
                        }
                    }
                    const keys = Object.keys(chartMap);
                    if (targetIds.length === 0 || keys.length === targetIds.length) return chartMap;
                    if (keys.length > Object.keys(lastMap).length) lastMap = chartMap;
                    await new Promise(r => setTimeout(r, delayMs));
                }
                return lastMap;
            };

            // Generate/Overwrite report (Create or Update)
            const createBtn = document.getElementById('createReport');
            if (createBtn) createBtn.addEventListener('click', async () => {
                try {
                    // Build printable HTML locally: only include skills, charts, and history (no profile or documents)
                    showNotification('Preparing printable preview...', 'info');
                    const title = document.getElementById('progressReportTitle') ? document.getElementById('progressReportTitle').textContent : ('Progress Report');
                    let html = `<!doctype html><html><head><meta charset="utf-8"><title>${escapeHtml(title)}</title><style>
                        body{font-family:Arial,Helvetica,sans-serif;margin:20px;color:#111}
                        h1{font-size:20px}
                        h2{font-size:16px}
                        .skill{margin-bottom:20px}
                        .chart{max-width:800px;border:1px solid #eee;padding:6px;background:#fff}
                        table{width:100%;border-collapse:collapse;table-layout:fixed}
                        td,th{padding:6px;border:1px solid #eee}
                        /* Fixed widths for Date/Score/Target so Notes gets remaining space */
                        th:nth-child(1), td:nth-child(1) { white-space: nowrap; width:140px; }
                        th:nth-child(2), td:nth-child(2) { width:80px; }
                        th:nth-child(3), td:nth-child(3) { width:80px; }
                        th:nth-child(4), td:nth-child(4) { word-break: break-word; }
                        </style></head><body>`;
                    html += `<h1>${escapeHtml(title)}</h1><div>Generated: ${new Date().toLocaleString()}</div>`;
                    const cards = Array.from(document.querySelectorAll('.skill-card')).filter(c => !c.classList.contains('add-skill-card'));
                    for (const card of cards) {
                        try {
                            const sid = card.dataset.skillId;
                            const labelEl = card.querySelector('.skill-header strong') || card.querySelector('.skill-label');
                            const label = labelEl ? labelEl.textContent.trim() : ('Skill ' + sid);
                            html += `<div class="skill"><h2>${escapeHtml(label)}</h2>`;
                            const canvas = document.getElementById('skillChart-' + sid) || card.querySelector('canvas');
                            if (canvas && typeof canvas.toDataURL === 'function') {
                                try { html += `<div class="chart"><img src="${canvas.toDataURL('image/png')}" style="width:100%;height:auto;" alt="${escapeHtml(label)}"/></div>`; } catch(e) { /* ignore */ }
                            }
                            // append current score/target
                            const cur = card.querySelector('.skill-current-score');
                            const tgt = card.querySelector('.skill-target-score');
                            if (cur || tgt) html += `<div style="margin-top:8px"><strong>Current:</strong> ${escapeHtml(cur ? cur.textContent : '-')} <strong style="margin-left:12px">Target:</strong> ${escapeHtml(tgt ? tgt.textContent : '-')}</div>`;

                            // fetch updates for this skill
                            let updates = [];
                            try {
                                const updResp = await fetchUpdates(sid);
                                if (updResp && updResp.success && updResp.updates) updates = updResp.updates;
                            } catch (e) { /* ignore */ }
                            if (updates && updates.length) {
                                html += '<table><thead><tr><th>Date</th><th>Score</th><th>Target</th><th>Notes</th></tr></thead><tbody>';
                                updates.forEach(u => { html += `<tr><td>${escapeHtml(formatLocalDate(u.date_recorded||u.created_at||'', true))}</td><td>${escapeHtml(formatPercentDisplay(u.score))}</td><td>${u.target_score ? escapeHtml(formatPercentDisplay(u.target_score)) : '-'}</td><td>${escapeHtml(u.notes||'')}</td></tr>`; });
                                html += '</tbody></table>';
                            } else {
                                html += '<div style="color:#666">No history available</div>';
                            }

                            html += '</div>';
                        } catch (err) { /* ignore per-skill errors */ }
                    }
                    html += '</body></html>';

                    try {
                        // Centered, capped-height preview modal so content is visible but the
                        // Print button remains in the viewport on most screens.
                        const modalFrag = document.createRange().createContextualFragment(`
                            <div class="modal" style="position:fixed;inset:0;display:flex;align-items:center;justify-content:center;z-index:10000;padding:12px;">
                                <div class="modal-overlay" style="position:absolute;inset:0;background:rgba(0,0,0,0.4);"></div>
                                <div class="modal-content" style="position:relative;z-index:10001;max-width:1100px;width:100%;max-height:80vh;height:80vh;display:flex;flex-direction:column;border-radius:6px;overflow:hidden;background:#fff;padding:0;box-shadow:0 8px 30px rgba(0,0,0,0.25);">
                                    <div style="display:flex;justify-content:space-between;align-items:center;padding:10px 14px;border-bottom:1px solid #eee;background:#fff;flex:0 0 auto;"><strong>Printable Preview</strong><div><button class="close">&times;</button></div></div>
                                    <div style="flex:1 1 auto;overflow:auto;padding:8px;">
                                        <iframe id="previewFrame" style="width:100%;height:100%;border:0;border-radius:0;background:#fff;display:block;box-sizing:border-box;"></iframe>
                                    </div>
                                    <div style="display:flex;justify-content:flex-end;padding:8px 12px;border-top:1px solid #eee;background:#fafafa;flex:0 0 auto;"><button id="previewPrintBtn" class="btn btn-primary">Print</button></div>
                                </div>
                            </div>`);
                        const modalEl = insertModal(modalFrag);
                        const frame = modalEl.querySelector('#previewFrame');
                        try { frame.srcdoc = html; } catch (e) { frame.contentWindow.document.open(); frame.contentWindow.document.write(html); frame.contentWindow.document.close(); }
                        const prbtn = modalEl.querySelector('#previewPrintBtn');
                        if (prbtn) prbtn.addEventListener('click', () => { try { frame.contentWindow.focus(); frame.contentWindow.print(); } catch (e) { showNotification('Unable to print preview', 'error'); } });
                    } catch (err) {
                        console.warn('print modal failed', err);
                        const printWin = window.open('', '_blank');
                        printWin.document.open(); printWin.document.write(html); printWin.document.close();
                        setTimeout(() => { try { printWin.focus(); printWin.print(); } catch(e) { console.warn('print failed', e); } }, 600);
                    }
                } catch (err) { console.error('client-side generate preview error', err); showNotification('Failed to open printable preview','error'); }
            });

            // Close-out functionality has been removed; no client-side handler is attached.

            // Delete report
            try {
                const delBtn = document.getElementById('deleteReport');
                if (delBtn) delBtn.addEventListener('click', async () => {
                    const tpl = document.getElementById('tmpl-confirm-delete-report') || document.getElementById('tmpl-confirm-delete-skill');
                    let modal = null;
                    if (tpl) { modal = insertModal(tpl.content.cloneNode(true)); }
                    else { const fallback = document.createElement('div'); fallback.className = 'modal'; fallback.innerHTML = '<div class="modal-content"><h3>Delete report?</h3><p>Are you sure you want to permanently delete this student\'s progress report and its associated PDF? This cannot be undone.</p><div class="modal-actions"><button class="btn btn-secondary">Cancel</button><button class="btn btn-danger">Delete</button></div></div>'; modal = insertModal(fallback); }
                    const cancel = modal.querySelector('.modal-actions .btn.btn-secondary');
                    const confirm = modal.querySelector('.modal-actions .btn.btn-danger');
                    if (cancel) cancel.addEventListener('click', () => { try { window.closeModal(); } catch(e) { modal.remove(); } });
                    if (confirm) confirm.addEventListener('click', async () => {
                        try {
                            // Try to fetch the canonical latest progress_report to get its normalized id
                            let reportId = null;
                            try { const latest = await fetchLatestProgressReport(studentId); if (latest && latest.success && latest.report && latest.report.id) reportId = latest.report.id; } catch (e) { /* ignore */ }

                            if (reportId) {
                                const fd = new URLSearchParams(); fd.append('action','delete_progress_report'); fd.append('report_id', String(reportId));
                                const j = await apiFetch('/includes/submit.php', { method:'POST', body: fd.toString(), headers:{ 'Content-Type':'application/x-www-form-urlencoded'} });
                                if (j && j.success) {
                                    showNotification('Report deleted','success');
                                    try { window.closeModal(); } catch(e) { modal.remove(); }
                                    try { const titleEl = document.getElementById('progressReportTitle'); if (titleEl) titleEl.remove(); } catch(e){}
                                    try {
                                        // remove skills section and any skill-card nodes
                                        const section = document.querySelector('.skills-list');
                                        if (section) section.remove();
                                        document.querySelectorAll('.skill-card').forEach(n => { try { n.remove(); } catch(e) { try { n.style.display = 'none'; } catch(e2) {} } });
                                    } catch(e){}
                                    try { updateReportUIState(null, studentId); } catch(e){}
                                    // Refresh recent activity and dashboard counts (server authoritative)
                                    try { if (typeof window.refreshRecentActivity === 'function') window.refreshRecentActivity(20); } catch (e) {}
                                    try { if (typeof window.dashboardUpdateStats === 'function') { window.dashboardUpdateStats(true); /* clear local cache so next page load fetches fresh counts */ try { localStorage.removeItem('slp_dashboard_counts_v1'); } catch(e) {} } } catch (e) {}
                                } else {
                                    showNotification((j && (j.error || j.message)) ? (j.error || j.message) : 'Failed to delete report','error');
                                }
                            } else {
                                // Fallback to legacy delete_student_report
                                const fd = new URLSearchParams(); fd.append('action','delete_student_report'); fd.append('student_id', String(studentId));
                                const j = await apiFetch('/includes/submit.php', { method:'POST', body: fd.toString(), headers:{ 'Content-Type':'application/x-www-form-urlencoded'} });
                                if (j && j.success) {
                                    showNotification('Report deleted','success');
                                    try { window.closeModal(); } catch(e) { modal.remove(); }
                                    try { const titleEl = document.getElementById('progressReportTitle'); if (titleEl) titleEl.remove(); } catch(e){}
                                    try { const section = document.querySelector('.skills-list'); if (section) section.remove(); } catch(e){}
                                    try { updateReportUIState(null, studentId); } catch(e){}
                                } else {
                                    showNotification((j && (j.error || j.message)) ? (j.error || j.message) : 'Failed to delete report','error');
                                }
                            }
                        } catch (err) { console.error('delete report error', err); showNotification('Failed to delete report','error'); }
                    });
                });
            } catch (err) { console.error('delete button wiring error', err); }

            // Wire Print / Save as PDF button (client-side only)
            try {
                const printBtn = document.getElementById('printReport');
                if (printBtn) printBtn.addEventListener('click', async () => {
                    try {
                        const chartMap = {};
                        const cards = Array.from(document.querySelectorAll('.skill-card')).filter(c => !c.classList.contains('add-skill-card'));
                        for (const card of cards) {
                            const sid = card.dataset.skillId;
                            const canvas = document.getElementById('skillChart-' + sid) || card.querySelector('canvas');
                            if (!canvas) continue;
                            try {
                                const ratio = window.devicePixelRatio || 1;
                                const tmp = document.createElement('canvas');
                                tmp.width = Math.round(canvas.width * (ratio > 1 ? 2 : ratio));
                                tmp.height = Math.round(canvas.height * (ratio > 1 ? 2 : ratio));
                                const ctx = tmp.getContext('2d');
                                ctx.scale(tmp.width / canvas.width, tmp.height / canvas.height);
                                ctx.drawImage(canvas, 0, 0);
                                chartMap[sid] = tmp.toDataURL('image/png');
                            } catch (e) { console.warn('print: toDataURL failed for', sid, e); }
                        }

                        const title = document.getElementById('progressReportTitle') ? document.getElementById('progressReportTitle').textContent : ('Progress Report');
                        let html = `<!doctype html><html><head><meta charset="utf-8"><title>${escapeHtml(title)}</title><style>
                            body{font-family:Arial,Helvetica,sans-serif;margin:20px;color:#111}
                            h1{font-size:20px}
                            h2{font-size:16px}
                            .skill{margin-bottom:20px}
                            .chart{max-width:800px;border:1px solid #eee;padding:6px;background:#fff}
                            table{width:100%;border-collapse:collapse;table-layout:fixed}
                            td,th{padding:6px;border:1px solid #eee}
                            th:nth-child(1), td:nth-child(1) { white-space: nowrap; width:140px; }
                            th:nth-child(2), td:nth-child(2) { width:80px; }
                            th:nth-child(3), td:nth-child(3) { width:80px; }
                            th:nth-child(4), td:nth-child(4) { word-break: break-word; }
                            </style></head><body>`;
                        html += `<h1>${escapeHtml(title)}</h1><div>Generated: ${new Date().toLocaleString()}</div>`;
                        for (const card of cards) {
                            const sid = card.dataset.skillId;
                            const labelEl = card.querySelector('.skill-header strong') || card.querySelector('.skill-label');
                            const label = labelEl ? labelEl.textContent.trim() : ('Skill ' + sid);
                            html += `<div class="skill"><h2>${escapeHtml(label)}</h2>`;
                            if (chartMap[sid]) html += `<div class="chart"><img src="${chartMap[sid]}" style="width:100%;height:auto;" alt="${escapeHtml(label)}"/></div>`;
                            const cur = card.querySelector('.skill-current-score');
                            const tgt = card.querySelector('.skill-target-score');
                            if (cur || tgt) html += `<div style="margin-top:8px"><strong>Current:</strong> ${escapeHtml(cur ? cur.textContent : '-')} <strong style="margin-left:12px">Target:</strong> ${escapeHtml(tgt ? tgt.textContent : '-')}</div>`;
                            html += `</div>`;
                        }
                        html += '</body></html>';

                        try {
                            // Centered, capped-height preview modal for print action
                            const modalFrag = document.createRange().createContextualFragment(`
                                <div class="modal" style="position:fixed;inset:0;display:flex;align-items:center;justify-content:center;z-index:10000;padding:12px;">
                                    <div class="modal-overlay" style="position:absolute;inset:0;background:rgba(0,0,0,0.4);"></div>
                                    <div class="modal-content" style="position:relative;z-index:10001;max-width:1100px;width:100%;max-height:80vh;height:80vh;display:flex;flex-direction:column;border-radius:6px;overflow:hidden;background:#fff;padding:0;box-shadow:0 8px 30px rgba(0,0,0,0.25);">
                                        <div style="display:flex;justify-content:space-between;align-items:center;padding:10px 14px;border-bottom:1px solid #eee;background:#fff;flex:0 0 auto;"><strong>Printable Preview</strong><div><button class="close">&times;</button></div></div>
                                        <div style="flex:1 1 auto;overflow:auto;padding:8px;">
                                            <iframe id="modalPreviewFrame" style="width:100%;height:100%;border:0;border-radius:0;background:#fff;display:block;box-sizing:border-box;"></iframe>
                                        </div>
                                        <div style="display:flex;justify-content:flex-end;padding:8px 12px;border-top:1px solid #eee;background:#fafafa;flex:0 0 auto;"><button id="modalPrintBtn" class="btn btn-primary">Print</button></div>
                                    </div>
                                </div>`);
                            const modalEl = insertModal(modalFrag);
                            const frame = modalEl.querySelector('#modalPreviewFrame');
                            frame.srcdoc = html;
                            setTimeout(() => { try { frame.contentWindow.focus(); } catch(e){} }, 500);
                            const prbtn = modalEl.querySelector('#modalPrintBtn');
                            if (prbtn) prbtn.addEventListener('click', () => { try { frame.contentWindow.focus(); frame.contentWindow.print(); } catch (e) { console.warn('print failed', e); showNotification('Print failed', 'error'); } });
                        } catch (err) {
                            console.warn('print modal failed', err);
                            const printWin = window.open('', '_blank');
                            printWin.document.open(); printWin.document.write(html); printWin.document.close();
                            setTimeout(() => { try { printWin.focus(); printWin.print(); } catch(e) { console.warn('print failed', e); } }, 600);
                        }
                    } catch (err) { console.error('print button error', err); showNotification('Failed to open printable view','error'); }
                });
            } catch (err) { console.warn('print button wiring failed', err); }

        } catch (e) {
            console.error('toolbar wiring error', e);
        }
    } catch (e) {
        console.error('initializeProgress failed', e);
        showNotification('Failed to load progress data','error');
    }
}

// Ensure a student report exists; create one if missing.
// Returns an object: { report: {...} | null, created: true|false }
async function ensureStudentReportExists(studentId) {
    try {
        console.debug('[progress] ensureStudentReportExists: fetching report for student', studentId);
        const rep = await fetchStudentReport(studentId);
        console.debug('[progress] fetchStudentReport response', rep);
        if (rep && rep.success && rep.report) return { report: rep.report, created: false };
        // Prompt the user for a report title and create a new progress_report row
        console.debug('[progress] ensureStudentReportExists: prompting for title and creating progress report for student', studentId);
        // Try to determine the student's name from DOM first for a faster default title
        let studentName = '';
        try {
            const hdr = document.querySelector('.progress-overview') || document.querySelector('.page-header') || document.querySelector('.container');
            if (hdr) {
                const nameEl = hdr.querySelector('h3') || hdr.querySelector('.student-name');
                if (nameEl) studentName = (nameEl.textContent || '').trim();
            }
        } catch (e) { /* ignore DOM read errors */ }
        // If DOM did not have a name, fetch student record as fallback
        if (!studentName) {
            try {
                const fd = new URLSearchParams(); fd.append('action','get_student'); fd.append('id', String(studentId));
                const resp = await apiFetch('/includes/submit.php', { method: 'POST', body: fd.toString(), headers: { 'Content-Type': 'application/x-www-form-urlencoded' } });
                if (resp && resp.success && resp.student) studentName = ((resp.student.first_name || '') + ' ' + (resp.student.last_name || '')).trim();
            } catch (e) { /* ignore fetch error */ }
        }
        const defaultTitle = 'Progress Report' + (studentName ? ' - ' + studentName : '');
        const tmpl = document.createElement('template');
        tmpl.innerHTML = `
            <div class="modal"><div class="modal-content"><h3>Create Progress Report</h3><div style="margin-top:8px;"><label>Report title<br/><input id="_pr_title" type="text" style="width:100%;font-size:1.1rem;padding:10px;" placeholder="e.g. Q1 Progress Review" value="${defaultTitle.replace(/"/g,'&quot;')}"/></label></div><div style="display:flex;justify-content:flex-end;margin-top:12px"><button id="_pr_cancel" class="btn">Cancel</button><button id="_pr_create" class="btn btn-primary">Create</button></div></div></div>`;
        const modal = insertModal(tmpl.content.cloneNode(true));
        return await new Promise((resolve) => {
            const inp = modal.querySelector('#_pr_title'); const cancel = modal.querySelector('#_pr_cancel'); const create = modal.querySelector('#_pr_create');
            const cleanup = (res) => { try { window.closeModal(); } catch(e) { modal.remove(); } resolve(res); };
            if (cancel) cancel.addEventListener('click', () => cleanup({ report: null, created: false }));
            if (create) create.addEventListener('click', async () => {
                try {
                    const title = (inp && inp.value) ? inp.value.trim() : 'Progress Report';
                    if (!title) { showNotification('Please enter a report title', 'warning'); return; }
                    showNotification('Creating progress report...', 'info');
                    const fd = new URLSearchParams(); fd.append('action','create_progress_report'); fd.append('student_id', String(studentId)); fd.append('title', title);
                    const res = await apiFetch('/includes/submit.php', { method: 'POST', body: fd.toString(), headers: { 'Content-Type': 'application/x-www-form-urlencoded' } });
                    if (res && res.success) {
                        showNotification('Progress report created', 'success');
                        // Fetch the canonical latest progress_report row and update the UI immediately
                        try {
                            const latest = await fetchLatestProgressReport(studentId);
                            if (latest && latest.success && latest.report) {
                                try { updateReportUIState(latest.report, studentId); } catch(e) { console.warn('updateReportUIState failed', e); }
                                // Ensure full client UI refresh so toolbar buttons and add-skill cards reflect the new state
                                try { if (typeof initializeProgress === 'function') await initializeProgress(studentId); } catch (e) { console.warn('initializeProgress refresh failed', e); }
                                // DOM fallback: enable/adjust elements in case legacy selectors or missing tables prevented a full refresh
                                try {
                                    console.debug('[progress] applying DOM fallback to enable progress UI');
                                    const addBtn = document.getElementById('addProgressBtn'); if (addBtn) { addBtn.disabled = false; addBtn.classList.remove('btn-disabled'); addBtn.textContent = 'Add Skill'; }
                                    const createBtn = document.getElementById('createReport'); if (createBtn) { createBtn.disabled = false; createBtn.classList.remove('btn-disabled'); createBtn.textContent = 'Generate PDF'; }
                                    const delBtn = document.getElementById('deleteReport'); if (delBtn) { delBtn.disabled = false; delBtn.classList.remove('btn-disabled'); }
                                    // remove server-side 'no progress' placeholders
                                    document.querySelectorAll('.no-progress, .no-report-banner, .no-student-selected').forEach(el => { try { el.style.display = 'none'; } catch(e) {} });
                                    // enable any add-skill-card elements
                                    document.querySelectorAll('.add-skill-card').forEach(c => c.classList.remove('disabled'));
                                    // Progress report title injection disabled: do not create or insert a
                                    // `progressReportTitle` element here. We keep the UI free of server-side
                                    // persisted titles to avoid stale or transient display of names.
                                } catch (e) { console.warn('DOM fallback failed', e); }
                                cleanup({ report: latest.report || res.report || null, created: true });
                                // Refresh recent activity to show new progress report
                                try { if (typeof window.refreshRecentActivity === 'function') window.refreshRecentActivity(20); } catch (e) { /* ignore */ }
                            } else {
                                cleanup({ report: res.report || null, created: true });
                                try { if (typeof window.refreshRecentActivity === 'function') window.refreshRecentActivity(20); } catch (e) { /* ignore */ }
                            }
                        } catch (e) {
                            console.warn('fetchLatestProgressReport failed', e);
                            cleanup({ report: res.report || null, created: true });
                        }
                    } else {
                        showNotification((res && (res.error || res.message)) ? (res.error || res.message) : 'Failed to create report', 'error');
                        cleanup({ report: null, created: false });
                    }
                } catch (err) { console.error('create_progress_report failed', err); showNotification('Failed to create report', 'error'); cleanup({ report: null, created: false }); }
            });
        });
    } catch (err) { console.warn('ensureStudentReportExists failed', err); }
    return { report: null, created: false };
}

// Update UI to reflect report existence/metadata
function updateReportUIState(report, studentId) {
    try {
        const addBtn = document.getElementById('addProgressBtn');
        const container = document.querySelector('.progress-overview') || document.querySelector('.container');
        const section = container ? container.querySelector('.skills-list') : null;
        // ensure banner exists
        let banner = null;
        if (container) {
            banner = container.querySelector('.no-report-banner');
            if (!banner) {
                banner = document.createElement('div');
                banner.className = 'no-report-banner muted';
                banner.style.margin = '10px 0';
                banner.style.padding = '10px';
                banner.style.border = '1px dashed #ccc';
                banner.style.background = '#fafafa';
                container.insertBefore(banner, container.firstChild);
            }
        }
        // Toolbar buttons
    const createBtn = document.getElementById('createReport');
    const delBtn = document.getElementById('deleteReport');

        // If a report exists, treat it as active: enable Add Skill, Generate PDF and Delete.
        // If no report exists, show the 'no report' banner and disable add-skill actions.
        if (report) {
            if (addBtn) { addBtn.disabled = false; addBtn.classList.remove('btn-disabled'); addBtn.textContent = 'Add Skill'; }
            if (section) section.querySelectorAll('.add-skill-card').forEach(c => c.classList.remove('disabled'));
            else document.querySelectorAll('.add-skill-card').forEach(c => c.classList.remove('disabled'));
            if (banner) banner.style.display = 'none';
            if (createBtn) { createBtn.disabled = false; createBtn.classList.remove('btn-disabled'); createBtn.textContent = 'Generate PDF'; }
            if (delBtn) { delBtn.disabled = false; delBtn.classList.remove('btn-disabled'); }
        } else {
            if (addBtn) { addBtn.disabled = false; addBtn.classList.remove('btn-disabled'); addBtn.textContent = 'Create Progress Report'; }
            // disable add-skill-cards so user cannot add skills until a report is initialized
            if (section) section.querySelectorAll('.add-skill-card').forEach(c => c.classList.add('disabled'));
            else document.querySelectorAll('.add-skill-card').forEach(c => c.classList.add('disabled'));
            if (banner) { banner.textContent = 'No progress report exists yet for this student. Create a progress report to enable adding skills.'; banner.style.display = 'block'; }
            if (createBtn) { createBtn.disabled = false; createBtn.classList.remove('btn-disabled'); createBtn.textContent = 'Generate PDF'; }
            if (delBtn) { delBtn.disabled = true; delBtn.classList.add('btn-disabled'); }
        }
        // Manage the progress report title element: show above skills when a report exists,
        // otherwise remove it. Insert it into the .progress-overview so it appears above
        // the skills-list section.
        try {
            const overviewEl = document.querySelector('.progress-overview');
            let titleEl = document.getElementById('progressReportTitle');
            if (report && overviewEl) {
                const titleText = (report.title && String(report.title).trim()) ? String(report.title) : 'Progress Report';
                if (!titleEl) {
                    titleEl = document.createElement('div');
                    titleEl.id = 'progressReportTitle';
                    titleEl.className = 'progress-report-title';
                    // Insert before skills-list if present, otherwise at top of overview
                    const skills = overviewEl.querySelector('.skills-list');
                    if (skills && skills.parentNode) skills.parentNode.insertBefore(titleEl, skills);
                    else overviewEl.insertBefore(titleEl, overviewEl.firstChild);
                }
                titleEl.textContent = titleText;
                titleEl.style.display = '';
            } else {
                if (titleEl) try { titleEl.remove(); } catch (e) { titleEl.style.display = 'none'; }
            }
        } catch (err) { /* non-critical */ }
    } catch (err) { console.error('updateReportUIState error', err); }
}

function renderSkills(skills, studentId, container) {
    // create a skills section inside the provided container and return it.
    // Note: this function builds DOM nodes and wires event handlers that do not
    // require the elements to be attached yet. Heavy async hydration (charts)
    // will be run after the section is inserted into the document by
    // hydrateSkillCards().
    let section = container.querySelector('.skills-list');
    if (!section) {
        section = document.createElement('div');
        section.className = 'skills-list';
        container.insertBefore(section, container.firstChild);
    }
    // build content into the section (for detached containers this is fine)
    section.innerHTML = '';

    // Hide any server-rendered "no progress" or placeholder messages globally so client-rendered skills are visible
    try {
        const noPlaceholders = document.querySelectorAll('.no-progress, .no-students, .no-student-selected');
        noPlaceholders.forEach(el => { el.style.display = 'none'; });
    } catch (err) { /* ignore */ }

    skills.forEach(skill => {
        const card = document.createElement('div');
        card.className = 'skill-card';
        card.dataset.skillId = skill.id;
        card.innerHTML = `
            <div class="skill-header"><strong>${escapeHtml(skill.skill_label)}</strong> <span class="skill-cat">${escapeHtml(skill.category || '')}</span></div>
            <div class="skill-controls">
                <button class="btn btn-outline btn-sm btn-update">Update Skill</button>
                <button class="btn btn-secondary btn-history">History</button>
                <button class="btn btn-danger btn-delete">Delete</button>
            </div>
            <div class="skill-current">Current: <span class="skill-current-score">-</span> <span class="skill-target">Target: <span class="skill-target-score">-</span>%</span></div>
            <div class="skill-chart"><canvas id="skillChart-${skill.id}" width="300" height="120"></canvas></div>
        `;
        section.appendChild(card);

        // wire basic handlers that do not depend on being in-document for charts
        try { card.querySelector('.btn-update').addEventListener('click', () => showAddUpdateModal(skill, studentId, card)); } catch(e) {}
        card.querySelector('.btn-history').addEventListener('click', async () => {
            const u = card._updates ? { success: true, updates: card._updates } : await fetchUpdates(skill.id);
            if (!u.success) { showNotification(u.error || 'Failed to load updates','error'); return; }
            showHistoryModal(skill, u.updates || []);
        });

        // Delete handler with confirmation
        const deleteBtn = card.querySelector('.btn-delete');
        if (deleteBtn) {
            deleteBtn.addEventListener('click', () => {
                const tpl = document.getElementById('tmpl-confirm-delete-skill');
                if (!tpl) return console.error('delete confirm template missing');
                const frag = tpl.content.cloneNode(true);
                const modal = insertModal(frag);
                const cancel = modal.querySelector('.modal-actions .btn.btn-secondary');
                const confirm = modal.querySelector('.modal-actions .btn.btn-danger');
                if (cancel) cancel.addEventListener('click', () => { try { window.closeModal(); } catch(e) { modal.remove(); } });
                if (confirm) confirm.addEventListener('click', async () => {
                    try {
                        const fd = new URLSearchParams(); fd.append('action','delete_progress_skill'); fd.append('skill_id', String(skill.id));
                        const j = await apiFetch('/includes/submit.php', { method:'POST', body: fd.toString(), headers:{ 'Content-Type':'application/x-www-form-urlencoded'} });
                        if (j && j.success) {
                            showNotification('Skill deleted','success');
                            try { window.closeModal(); } catch(e) { modal.remove(); }
                            try { card.remove(); } catch(e) { card.style.display = 'none'; }
                            try { if (typeof window.refreshRecentActivity === 'function') window.refreshRecentActivity(20); } catch (e) { /* ignore */ }
                        } else {
                            showNotification(j && (j.error || j.message) ? (j.error || j.message) : 'Failed to delete skill','error');
                        }
                    } catch (err) { console.error('delete skill error', err); showNotification('Failed to delete skill','error'); }
                });
            });
        }
    });

    // add "Add skill" card
    const addCard = document.createElement('div');
    addCard.className = 'skill-card add-skill-card';
    addCard.innerHTML = `<div class="add-skill">+ Add Skill</div>`;
    addCard.addEventListener('click', async () => {
        const addBtn = document.getElementById('addProgressBtn');
        if ((addBtn && addBtn.disabled) || addCard.classList.contains('disabled')) {
            showNotification('Create a progress report first to add skills','info');
            return;
        }
        try {
            const createdRes = await ensureStudentReportExists(studentId);
            const createdFlag = createdRes && createdRes.created ? true : false;
            showAddSkillModal(studentId, { created: createdFlag });
        } catch (err) {
            showAddSkillModal(studentId);
        }
    });
    section.appendChild(addCard);

    // Return the built section so caller can insert it into the live DOM atomically
    return section;
}

// Hydrate skill cards: fetch updates for each card and render charts. This
// must be run after the section has been inserted into document so
// getElementById and canvas operations work correctly.
async function hydrateSkillCards(section) {
    if (!section) return;
    const cards = Array.from(section.querySelectorAll('.skill-card')).filter(c => !c.classList.contains('add-skill-card'));
    await Promise.all(cards.map(async (card) => {
        const sid = card.dataset.skillId;
        if (!sid) return;
        try {
            const updates = await fetchUpdates(sid);
            if (!updates.success) return;
            card._updates = updates.updates;
            const last = updates.updates[updates.updates.length-1];
            const curScoreEl = card.querySelector('.skill-current-score');
            if (curScoreEl) curScoreEl.textContent = formatPercentDisplay(last.score);
            const targetVal = (last && typeof last.target_score !== 'undefined') ? parsePercent(last.target_score) : null;
            const tgtEl = card.querySelector('.skill-target-score');
            if (tgtEl) tgtEl.textContent = targetVal !== null && !isNaN(targetVal) ? String(Math.round(targetVal)) : '-';
            const badge = document.createElement('span'); badge.className = 'skill-badge';
            if (targetVal !== null && !isNaN(targetVal)) {
                const lastScore = parsePercent(last.score);
                if (!isNaN(lastScore) && lastScore >= targetVal) { badge.textContent = 'Achieved'; badge.classList.add('badge-achieved'); card.classList.add('achieved'); card.classList.remove('working','needs-attention'); }
                else if (!isNaN(lastScore) && lastScore >= (targetVal - 20)) { badge.textContent = 'Working'; badge.classList.add('badge-working'); card.classList.add('working'); card.classList.remove('achieved','needs-attention'); }
                else { badge.textContent = 'Needs Attention'; badge.classList.add('badge-alert'); card.classList.add('needs-attention'); card.classList.remove('working','achieved'); }
                try { curScoreEl.parentElement.appendChild(badge); } catch(e) {}
            }
            try {
                const labels = updates.updates.map(u => formatLocalDate(u.date_recorded || u.created_at || '', false));
                const dataPoints = updates.updates.map(u => { const p = parsePercent(u.score); return isNaN(p) ? null : p; });
                const ctx = document.getElementById('skillChart-' + sid);
                if (ctx && window.Chart) {
                    if (card._chart && typeof card._chart.destroy === 'function') card._chart.destroy();
                    const recentTarget = (updates.updates[updates.updates.length-1] && typeof updates.updates[updates.updates.length-1].target_score !== 'undefined') ? parsePercent(updates.updates[updates.updates.length-1].target_score) : null;
                    const targetLinePlugin = {
                        id: 'targetLinePlugin',
                        afterDatasetsDraw: function(chart, args, options) {
                            const target = options.target; if (target === null || typeof target === 'undefined') return;
                            const yScale = chart.scales['y']; if (!yScale) return; const y = yScale.getPixelForValue(target);
                            const ctx2 = chart.ctx; ctx2.save(); ctx2.beginPath(); ctx2.moveTo(chart.chartArea.left, y); ctx2.lineTo(chart.chartArea.right, y); ctx2.lineWidth = 2; ctx2.setLineDash([6,4]); ctx2.strokeStyle = options.color || 'rgba(220,38,38,0.9)'; ctx2.stroke(); ctx2.fillStyle = options.color || 'rgba(220,38,38,0.9)'; ctx2.font = '12px Arial'; const label = options.label || 'Target ' + target + '%'; const textWidth = ctx2.measureText(label).width; const px = Math.min(chart.chartArea.right - textWidth - 6, chart.chartArea.right - 6); ctx2.fillText(label, px, y - 6); ctx2.restore();
                        }
                    };
                        const chart = new Chart(ctx.getContext('2d'), {
                        type: 'line',
                        data: { labels: labels, datasets: [{ label: 'Score', data: dataPoints, borderColor: '#3b82f6', backgroundColor: 'rgba(59,130,246,0.1)', tension: 0, fill: true, pointRadius: 3 }] },
                        plugins: [targetLinePlugin],
                        options: { maintainAspectRatio: false, layout: { padding: { top: 16 } }, scales: { y: { beginAtZero: true, min: 0, max: 100, ticks: { stepSize: 20, callback: v => (v === 100 ? '100%' : (v + '%')) } }, x: { ticks: { maxRotation: 0, autoSkip: true } } }, plugins: { legend: { display: false }, targetLinePlugin: { target: recentTarget, color: 'rgba(220,38,38,0.9)', label: recentTarget !== null ? ('Target ' + recentTarget + '%') : '' } } }
                    });
                    card._chart = chart;
                }
            } catch (err) { console.error('hydrate chart error', err); }
        } catch (err) { console.error('hydrate skill error', sid, err); }
    }));
}

function showAddSkillModal(studentId, options = {}) {
    // clone template and namespace ids to avoid collisions
    const tmpl = document.getElementById('tmpl-progress-add-skill');
    if (!tmpl) { console.error('progress add-skill template missing'); return; }
    const frag = tmpl.content.cloneNode(true);
    // create unique suffix
    const uid = 'ps-' + Date.now();
    // update ids inside fragment
    frag.querySelectorAll('[id]').forEach(el => { const old = el.id; el.id = `${old}-${uid}`; });
    // update any label 'for' attributes inside the fragment so labels reference the namespaced ids
    frag.querySelectorAll('label[for]').forEach(l => { const f = l.getAttribute('for'); if (f) l.setAttribute('for', f + '-' + uid); });
    // Insert modal
    const modal = insertModal(frag);

    // Helpers to select within modal by original id
    const q = (origId) => modal.querySelector('#' + origId + '-' + uid);

    // If this modal is being shown immediately after creating a new report,
    // adjust the title and show a short explanation so teachers understand
    // they're initializing a student's progress report (not just adding a standalone skill).
    try {
        if (options && options.created) {
            const hdr = modal.querySelector('.modal-header h2') || modal.querySelector('.modal-title');
            if (hdr) hdr.textContent = 'Initialize Progress Report — Add first skill';
            // insert a short explanatory paragraph under the header if not present
            let expl = modal.querySelector('.report-init-explainer');
            if (!expl) {
                expl = document.createElement('div');
                expl.className = 'report-init-explainer muted';
                expl.style.margin = '8px 0 12px';
                expl.textContent = 'You just created a new progress report for this student. Add the first skill and set its current and target percentages to begin tracking progress.';
                const headerEl = modal.querySelector('.modal-header');
                if (headerEl && headerEl.parentNode) headerEl.parentNode.insertBefore(expl, headerEl.nextSibling);
            }
        }
    } catch (err) { /* non-critical */ }

    // wire submit/cancel
    const submitBtn = modal.querySelector('.modal-actions .btn.btn-primary');
    const cancelBtn = modal.querySelector('.modal-actions .btn.btn-secondary');
    if (cancelBtn) cancelBtn.addEventListener('click', () => { try { window.closeModal(); } catch(e) { modal.remove(); } });
    if (submitBtn) submitBtn.addEventListener('click', async () => {
            try {
                console.log('Add Skill submit clicked (handler start)');
                const labelEl = q('skillLabel');
                const label = labelEl && labelEl.value ? labelEl.value.trim() : '';
                const catEl = q('skillCategory');
                const cat = catEl && catEl.value ? catEl.value.trim() : '';
                const curEl = q('skillCurrentNum');
                const cur = curEl && typeof curEl.value !== 'undefined' ? curEl.value : '';
                const tgtEl = q('skillTargetNum');
                const tgt = tgtEl && typeof tgtEl.value !== 'undefined' ? tgtEl.value : '';
                const notesEl = q('skillNotes');
                const notes = notesEl ? (notesEl.value || '').trim() : '';
        // inline validation
        const curErr = modal.querySelector('#skillCurrentNumError-' + uid) || modal.querySelector('#skillCurrentNumError-' + uid) || modal.querySelector('#skillCurrentNumError-' + uid);
        const tgtErr = modal.querySelector('#skillTargetNumError-' + uid) || modal.querySelector('#skillTargetNumError-' + uid) || modal.querySelector('#skillTargetNumError-' + uid);
        // clear previous errors
        if (curErr) curErr.textContent = '';
        if (tgtErr) tgtErr.textContent = '';
    if (!label) { try { console.warn('Add Skill validation failed: missing label'); } catch(e) {} if (tgtErr) tgtErr.textContent = ''; showNotification('Please provide a skill label','error'); return; }
        // validate current if provided
        if (cur !== '') {
            const curN = Number(cur);
            if (isNaN(curN) || curN < 0 || curN > 100) { try { console.warn('Add Skill validation failed: invalid current value', cur); } catch(e) {} if (curErr) curErr.textContent = 'Enter a value 0–100'; return; }
        }
        // target must be provided and in range
        if (tgt === '' || isNaN(Number(tgt))) { try { console.warn('Add Skill validation failed: missing or non-numeric target', tgt); } catch(e) {} if (tgtErr) tgtErr.textContent = 'Target is required'; return; }
        const tgtN = Number(tgt);
        if (tgtN < 0 || tgtN > 100) { try { console.warn('Add Skill validation failed: target out of range', tgtN); } catch(e) {} if (tgtErr) tgtErr.textContent = 'Target must be 0–100'; return; }
        const fd = new URLSearchParams(); fd.append('action','add_progress_skill'); fd.append('student_id', String(studentId)); fd.append('skill_label', label); fd.append('category', cat);
    // Debug: log outgoing payload
    try { console.log('Sending add_progress_skill', fd.toString()); } catch (e) {}
        let j = null;
        try {
            j = await apiFetch('/includes/submit.php', { method:'POST', body: fd.toString(), headers:{ 'Content-Type':'application/x-www-form-urlencoded'} });
        } catch (err) {
            console.error('Network or server error when calling add_progress_skill', err);
            showNotification('Network error: ' + (err && err.message ? err.message : 'Failed to reach server'), 'error');
            return;
        }

    try { console.log('add_progress_skill server response', j); } catch(e) {}
    if (j && j.success) {
            // Verify server-side creation by re-fetching skills before closing modal
            try {
                const skillsResp = await fetchSkills(studentId);
                try { console.debug('fetchSkills after add', skillsResp); } catch (e) {}
                const newId = j.id ? String(j.id) : null;
                let found = false;
                if (skillsResp && skillsResp.success && Array.isArray(skillsResp.skills)) {
                    found = skillsResp.skills.some(s => String(s.id) === String(newId));
                }
                if (!found) {
                    // Server claimed success but the skill list doesn't include it. Log and keep modal open so user can retry or inspect.
                    console.warn('Skill creation reported success but new skill not found in fetchSkills', { reportedId: newId, skillsResp });
                    showNotification('Skill was created but did not appear in the list immediately. Please try refreshing the page or try again.', 'error');
                    // still attempt to refresh UI in case of eventual consistency
                    try { await initializeProgress(studentId); } catch (e) { /* ignore */ }
                    return;
                }

                showNotification('Skill added','success');
                try { window.closeModal(); } catch(e) { modal.remove(); }
            } catch (err) {
                console.error('Error verifying new skill presence', err);
                showNotification('Skill added but verification failed. Reload the page to confirm.', 'info');
                try { window.closeModal(); } catch(e) { modal.remove(); }
            }
            // optionally add initial update if provided
                if (cur !== '' || tgt !== '') {
                const scoreVal = cur !== '' ? Number(cur) : null;
                const targetVal = tgt !== '' ? Number(tgt) : null;
                if (scoreVal !== null && (isNaN(scoreVal) || scoreVal < 0 || scoreVal > 100)) { showNotification('Invalid initial score','error'); initializeProgress(studentId); return; }
                if (targetVal !== null && (isNaN(targetVal) || targetVal < 0 || targetVal > 100)) { showNotification('Invalid target score','error'); initializeProgress(studentId); return; }
                try {
                    const fd2 = new URLSearchParams(); fd2.append('action','add_progress_update'); fd2.append('skill_id', String(j.id)); fd2.append('student_id', String(studentId)); if (scoreVal !== null) fd2.append('score', String(scoreVal)); if (targetVal !== null) fd2.append('target_score', String(targetVal)); if (notes) fd2.append('notes', String(notes));
                    const j2 = await apiFetch('/includes/submit.php', { method:'POST', body: fd2.toString(), headers:{ 'Content-Type':'application/x-www-form-urlencoded'} });
                    if (!j2.success) showNotification(j2.error || 'Failed to add initial score','error');
                } catch (err) { console.error('initial update error', err); }
            }
            initializeProgress(studentId);
        } else {
            // Log server response for debugging
            try { console.warn('add_progress_skill response', j); } catch (e) {}
            const msg = j && (j.error || j.message) ? (j.error || j.message) : 'Failed to add skill';
            showNotification(msg,'error');
        }
            } catch (err) {
                console.error('Add Skill handler error', err);
                showNotification('Unexpected error when adding skill. See console for details.','error');
            }
    });

    // wire range-number synchronization
    const numC = q('skillCurrentNum');
    const rngC = q('skillCurrentRange');
    const numT = q('skillTargetNum');
    const rngT = q('skillTargetRange');
    if (numC && rngC) { numC.addEventListener('input', () => rngC.value = numC.value); rngC.addEventListener('input', () => numC.value = rngC.value); 
        // initialize number from range if empty so validations have a value
        try { if ((numC.value === '' || numC.value === undefined) && rngC && typeof rngC.value !== 'undefined') numC.value = rngC.value; } catch(e) {}
    }
    if (numT && rngT) { numT.addEventListener('input', () => rngT.value = numT.value); rngT.addEventListener('input', () => numT.value = rngT.value); 
        try { if ((numT.value === '' || numT.value === undefined) && rngT && typeof rngT.value !== 'undefined') numT.value = rngT.value; } catch(e) {}
    }
    // target lock/unlock: default locked
    try {
        const lockBtn = q('skillTargetLock');
        const lockLabel = q('targetValLockLabel') || q('targetValLockLabel');
        if (lockBtn) {
            const setLocked = (locked) => {
                try {
                    if (numT) {
                        numT.disabled = !!locked;
                        if (locked) numT.setAttribute('disabled', 'disabled'); else numT.removeAttribute('disabled');
                    }
                    if (rngT) {
                        rngT.disabled = !!locked;
                        if (locked) rngT.setAttribute('disabled', 'disabled'); else rngT.removeAttribute('disabled');
                    }
                    if (locked) lockBtn.classList.remove('unlocked'); else lockBtn.classList.add('unlocked');
                    lockBtn.setAttribute('aria-pressed', locked ? 'false' : 'true');
                    lockBtn.title = locked ? 'Unlock target to edit' : 'Lock target';
                    if (lockLabel) lockLabel.textContent = locked ? 'Locked' : 'Unlocked';
                } catch (e) { /* non-critical */ }
            };
            // initialize unlocked by default so teacher can set the target immediately
            // when adding a new skill.
            const shouldStartUnlocked = true;
            // explicit set for clarity: unlocked -> setLocked(false)
            setLocked(false);
            // wire toggle: read actual disabled state to toggle
            lockBtn.addEventListener('click', () => {
                try {
                    const nowLocked = !!(numT && numT.disabled);
                    setLocked(!nowLocked);
                } catch (e) { console.debug('lock toggle error', e); }
            });
            // debug initial state
            try { console.debug('[progress] add-skill modal lock initial', { shouldStartUnlocked, numTExists: !!numT, rngTExists: !!rngT, numTDisabled: numT ? numT.disabled : null }); } catch(e) {}
        }
    } catch (err) { /* ignore */ }
}

function showAddUpdateModal(skill, studentId, card) {
    const tmpl = document.getElementById('tmpl-progress-add-update');
    let uid = 'pu-' + Date.now();
    let frag = null;
    if (tmpl) {
        frag = tmpl.content.cloneNode(true);
    } else {
        // fallback markup when server-side template is missing
        const fallback = document.createElement('div');
        fallback.className = 'modal';
        fallback.innerHTML = `
            <div class="modal-content">
                <div class="modal-header">
                    <h2>Select a current score</h2>
                    <button class="close" type="button" aria-label="Close">&times;</button>
                </div>
                <div class="modal-form">
                    <div class="form-group">
                        <label for="scoreVal">Current score</label>
                        <input id="scoreVal" type="number" min="0" max="100" value="50" />
                        <div class="field-error" id="scoreValError" aria-live="polite"></div>
                    </div>
                    <div class="form-group">
                        <label for="targetVal">Target score</label>
                        <div style="display:flex;align-items:center;gap:8px;">
                            <input id="targetVal" type="number" min="0" max="100" value="80" />
                            <button id="targetValLock" class="btn icon-btn" type="button" aria-pressed="false" title="Toggle target lock">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M12 17a2 2 0 1 0 0-4 2 2 0 0 0 0 4z" fill="#111827"/><path d="M17 8V7a5 5 0 0 0-10 0v1" stroke="#111827" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round"/><rect x="4" y="8" width="16" height="12" rx="2" stroke="#111827" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            </button>
                            <span id="targetValLockLabel" class="lock-status">Locked</span>
                        </div>
                        <div class="field-error" id="targetValError" aria-live="polite"></div>
                    </div>
                    <div class="form-group">
                        <label for="notes">Notes (optional)</label>
                        <textarea id="notes" placeholder="Notes (optional)"></textarea>
                    </div>
                    <div class="modal-actions">
                        <button class="btn btn-secondary" type="button">Cancel</button>
                        <button class="btn btn-primary" type="button">Submit</button>
                    </div>
                </div>
            </div>
        `;
        frag = fallback;
        // generate a uid matching the fallback ids (they start with the bare ids)
        uid = 'pu-' + Date.now();
    }
    // create unique suffix
    // namespace ids inside fragment if it's a DocumentFragment (template.content)
    try {
        if (frag && frag.querySelectorAll) {
            frag.querySelectorAll('[id]').forEach(el => { const old = el.id; el.id = `${old}-${uid}`; });
            // update label for attributes inside the fragment
            frag.querySelectorAll('label[for]').forEach(l => { const f = l.getAttribute('for'); if (f) l.setAttribute('for', f + '-' + uid); });
        }
    } catch (e) { /* ignore */ }
    const modal = insertModal(frag);
    const q = (origId) => modal.querySelector('#' + origId + '-' + uid);

    // cancel handled by modal close binding; ensure explicit cancel handling
    const submitBtn = modal.querySelector('.modal-actions .btn.btn-primary');
    // inline error elements (namespaced)
    const scoreErr = modal.querySelector('#scoreValError-' + uid);
    const targetErr = modal.querySelector('#targetValError-' + uid);
    const scoreInput = q('scoreVal');
    const targetInput = q('targetVal');

    // Validate inputs and enable/disable submit button accordingly
    const validateInputs = () => {
        let ok = true;
        try {
            // score validation
            const sval = scoreInput && scoreInput.value !== undefined ? scoreInput.value : '';
            if (sval === '' || isNaN(Number(sval))) {
                if (scoreErr) scoreErr.textContent = 'Please provide a numeric score';
                ok = false;
            } else {
                const s = Number(sval);
                if (s < 0 || s > 100) { if (scoreErr) scoreErr.textContent = 'Score must be 0–100'; ok = false; } else if (scoreErr) scoreErr.textContent = '';
            }

            // target validation (required)
            const tval = targetInput && targetInput.value !== undefined ? targetInput.value : '';
            if (tval === '' || isNaN(Number(tval))) {
                if (targetErr) targetErr.textContent = 'Please provide a target score';
                ok = false;
            } else {
                const t = Number(tval);
                if (t < 0 || t > 100) { if (targetErr) targetErr.textContent = 'Target must be 0–100'; ok = false; } else if (targetErr) targetErr.textContent = '';
            }
        } catch (e) { console.debug('validation error', e); ok = false; }
        try { if (submitBtn) submitBtn.disabled = !ok; } catch(e) {}
        return ok;
    };

    // wire realtime validation
    try {
        if (scoreInput) scoreInput.addEventListener('input', validateInputs);
        if (targetInput) targetInput.addEventListener('input', validateInputs);
        const rng = q('targetValRange'); if (rng) rng.addEventListener('input', () => { if (targetInput) targetInput.value = rng.value; validateInputs(); });
    } catch (e) { /* ignore */ }

    // run initial validation to set submit state
    try { validateInputs(); } catch (e) { /* ignore */ }

    if (submitBtn) submitBtn.addEventListener('click', async () => {
        // Ensure inputs are valid at click time
        if (!validateInputs()) { showNotification('Please fix validation errors before submitting', 'error'); return; }
        const score = parseInt(q('scoreVal').value, 10);
        const targetEl = q('targetVal');
        const target = targetEl ? parseInt(targetEl.value, 10) : null;
        const notes = q('notes').value.trim();

        // Snapshot previous state for rollback
        const prevUpdates = card && card._updates ? JSON.parse(JSON.stringify(card._updates)) : null;
        const prevChart = card && card._chart ? card._chart : null;

        // Optimistic UI update
        try {
            if (card) card.querySelector('.skill-current-score').textContent = formatPercentDisplay(score);
                    const nowLabel = formatLocalDate(new Date(), false);
            if (card && card._chart) {
                try { card._chart.data.labels.push(nowLabel); card._chart.data.datasets[0].data.push(parsePercent(score)); card._chart.update(); } catch(e) { console.warn('optimistic chart append failed', e); }
            }
        } catch (e) { console.warn('optimistic UI update failed', e); }

        // Send request
        const fd = new URLSearchParams(); fd.append('action','add_progress_update'); fd.append('skill_id', String(skill.id)); fd.append('student_id', String(studentId)); fd.append('score', String(score)); if (!isNaN(target)) fd.append('target_score', String(target)); fd.append('notes', notes);
        let j = null;
        try { j = await apiFetch('/includes/submit.php', { method:'POST', body: fd.toString(), headers:{ 'Content-Type':'application/x-www-form-urlencoded'} }); }
        catch (err) { j = { success: false, error: err && err.message ? err.message : 'Network error' }; }

        if (j && j.success) {
            showNotification('Progress updated','success');
            try { window.closeModal(); } catch(e) { modal.remove(); }
            try { await refreshAllSkillData(studentId); } catch (e) { console.warn('refresh after update failed', e); }
                    // Refresh recent activity to reflect the update
                    try { if (typeof window.refreshRecentActivity === 'function') window.refreshRecentActivity(20); } catch (e) { /* ignore */ }
        } else {
            showNotification((j && (j.error || j.message)) ? (j.error || j.message) : 'Failed to add update','error');
            try {
                    if (card) {
                    if (prevUpdates) { card._updates = prevUpdates; const last = prevUpdates[prevUpdates.length-1]; card.querySelector('.skill-current-score').textContent = last ? formatPercentDisplay(last.score) : '-'; }
                    if (card._chart && prevChart) {
                        try { card._chart.destroy(); } catch(e) {}
                        if (prevUpdates && prevUpdates.length) {
                            const labels = prevUpdates.map(u => formatLocalDate(u.date_recorded || u.created_at || '', false));
                            const dataPoints = prevUpdates.map(u => { const p = parsePercent(u.score); return isNaN(p) ? null : p; });
                            const ctx = document.getElementById('skillChart-' + skill.id);
                            if (ctx && window.Chart) {
                                const recentTarget = (prevUpdates[prevUpdates.length-1] && typeof prevUpdates[prevUpdates.length-1].target_score !== 'undefined') ? parsePercent(prevUpdates[prevUpdates.length-1].target_score) : null;
                                const chart = new Chart(ctx.getContext('2d'), {
                                    type: 'line',
                                    data: { labels: labels, datasets: [{ label: 'Score', data: dataPoints, borderColor: '#3b82f6', backgroundColor: 'rgba(59,130,246,0.1)', tension: 0, fill: true, pointRadius: 3 }] },
                                    options: { maintainAspectRatio: false, scales: { y: { beginAtZero: true, suggestedMax: 100, ticks: { callback: v => v + '%' } }, x: { ticks: { maxRotation: 0, autoSkip: true } } }, plugins: { legend: { display: false }, targetLinePlugin: { target: recentTarget, color: 'rgba(220,38,38,0.9)', label: recentTarget !== null ? ('Target ' + recentTarget + '%') : '' } } }
                                });
                                card._chart = chart;
                            }
                        }
                    }
                }
            } catch (err) { console.error('rollback failed', err); }
        }
    });

    // lock target to most recent value if present
    try {
        const recent = card && card._updates && card._updates.length ? card._updates[card._updates.length-1] : null;
        const tgtInput = q('targetVal');
        const lockBtn = q('targetValLock');
        const lockLabel = q('targetValLockLabel');
        // Prefill current score input with the last recorded score when opening the modal
        try {
            const lastScore = recent && typeof recent.score !== 'undefined' ? parsePercent(recent.score) : null;
            const curInput = q('scoreVal');
            if (curInput && lastScore !== null && !isNaN(lastScore)) curInput.value = String(Math.round(lastScore));
        } catch (e) { /* ignore */ }
        const setLocked = (locked) => {
            try {
                if (tgtInput) {
                    tgtInput.disabled = !!locked;
                    if (locked) tgtInput.setAttribute('disabled', 'disabled'); else tgtInput.removeAttribute('disabled');
                }
                if (lockBtn) {
                    if (locked) lockBtn.classList.remove('unlocked'); else lockBtn.classList.add('unlocked');
                    lockBtn.setAttribute('aria-pressed', locked ? 'false' : 'true');
                    lockBtn.title = locked ? 'Unlock target to edit' : 'Lock target';
                    if (lockLabel) lockLabel.textContent = locked ? 'Locked' : 'Unlocked';
                }
                // if a range control exists, keep it in sync
                const rng = q('targetValRange');
                if (rng) {
                    rng.disabled = !!locked;
                    if (locked) rng.setAttribute('disabled', 'disabled'); else rng.removeAttribute('disabled');
                }
            } catch (e) { /* ignore */ }
        };
        // initialize locked state (Update modal should start locked)
        const initialLocked = true;
        if (tgtInput && recent && recent.target_score) {
            const val = String(recent.target_score);
            tgtInput.value = val;
            // if a range slider exists in the modal, set it too so the thumb matches the number
            try { const rngInit = q('targetValRange'); if (rngInit) rngInit.value = val; } catch (e) { /* ignore */ }
        }
        setLocked(initialLocked);
        // wire lock toggle
        if (lockBtn && tgtInput) {
            lockBtn.addEventListener('click', () => {
                try {
                    const nowLocked = !!(tgtInput && tgtInput.disabled);
                    setLocked(!nowLocked);
                } catch (e) { console.debug('update modal lock toggle error', e); }
            });
        }
        try { console.debug('[progress] update-modal lock initial', { initialLocked, hasRange: !!q('targetValRange'), tgtDisabled: tgtInput ? tgtInput.disabled : null }); } catch(e) {}
        // wire range/number sync if template provides a range (not in update modal by default)
        try {
            const rng = q('targetValRange');
            if (tgtInput && rng) { tgtInput.addEventListener('input', () => rng.value = tgtInput.value); rng.addEventListener('input', () => tgtInput.value = rng.value); }
            // clear inline errors as user types
            try {
                const scoreErrEl = modal.querySelector('#scoreValError-' + uid);
                const targetErrEl = modal.querySelector('#targetValError-' + uid);
                const curInput = q('scoreVal');
                if (curInput) curInput.addEventListener('input', () => { if (scoreErrEl) scoreErrEl.textContent = ''; });
                if (tgtInput) tgtInput.addEventListener('input', () => { if (targetErrEl) targetErrEl.textContent = ''; });
            } catch (e) { /* ignore */ }
        } catch (err) { /* ignore */ }
    } catch (err) { /* ignore */ }
}

function showHistoryModal(skill, updates) {
    const tmpl = document.getElementById('tmpl-progress-history');
    if (!tmpl) {
        // fallback to simple inline if template missing
        const fallback = document.createElement('div');
    fallback.innerHTML = `<div class="modal" role="dialog"><div class="modal-content"><h3>History: ${escapeHtml(skill.skill_label)}</h3><div class="history-list">${updates.map(u => `<div class="history-item"><div>${escapeHtml(formatLocalDate(u.date_recorded || u.created_at || '', true))}</div><div>Score: ${escapeHtml(String(u.score))}% ${u.target_score ? ' (Target '+escapeHtml(String(u.target_score))+'%)' : ''}</div><div>${escapeHtml(u.notes || '')}</div></div>`).join('')}</div><div style="margin-top:12px"><button class="btn btn-primary" id="closeHist">Close</button></div></div></div>`;
        const modal = insertModal(fallback);
        modal.querySelector('#closeHist')?.addEventListener('click', () => { try { window.closeModal(); } catch(e) { modal.remove(); } });
        return;
    }

    // namespace ids
    const uid = 'ph-' + Date.now();
    const frag = tmpl.content.cloneNode(true);
    // namespace ids and label 'for' attributes
    frag.querySelectorAll('[id]').forEach(el => { const old = el.id; el.id = `${old}-${uid}`; });
    frag.querySelectorAll('label[for]').forEach(l => { const f = l.getAttribute('for'); if (f) l.setAttribute('for', f + '-' + uid); });
    const modal = insertModal(frag);
    const meta = modal.querySelector('#historyMeta-' + uid);
    const list = modal.querySelector('#historyList-' + uid);
    const title = modal.querySelector('#historyTitle-' + uid);
    if (title) title.textContent = `History — ${skill.skill_label}`;
    if (meta) meta.textContent = `Skill: ${skill.skill_label} • Category: ${skill.category || '—'}`;
    if (list) {
        if (!updates || !updates.length) {
            list.innerHTML = `<div class="history-empty muted">No updates recorded yet.</div>`;
        } else {
            list.innerHTML = updates.map(u => {
                const date = escapeHtml(formatLocalDate(u.date_recorded || u.created_at || '', true));
                const score = escapeHtml(String(u.score || '-'));
                const tgt = u.target_score ? (`<span class="muted">Target: ${escapeHtml(String(u.target_score))}%</span>`) : '';
                const notes = u.notes ? `<div class="history-notes">${escapeHtml(u.notes)}</div>` : '';
                return `<div class="history-item"><div class="history-row"><div class="history-date">${date}</div><div class="history-score">Score: <strong>${score}%</strong> ${tgt}</div></div>${notes}</div>`;
            }).join('');
        }
    }
    // Close button already wired via modal close handler created by insertModal (modal-actions Cancel/Close). No additional wiring required.
}

function escapeHtml(str) {
    if (!str) return '';
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}

// Expose init function
if (typeof window.initializeProgress !== 'function') window.initializeProgress = initializeProgress;
export default { initializeProgress };

// Listen for custom event to open add-skill modal
document.addEventListener('openAddSkill', function(e){
    try {
        const sid = e && e.detail && e.detail.student_id ? Number(e.detail.student_id) : null;
        if (!sid) return;
        // ensure a student report exists first (create if missing) so Add Skill is allowed
        (async () => {
            try {
                const createdRes = await ensureStudentReportExists(sid);
                const createdFlag = createdRes && createdRes.created ? true : false;
                showAddSkillModal(sid, { created: createdFlag });
            } catch (err) { try { showAddSkillModal(sid); } catch(e) { /* ignore */ } }
        })();
    } catch (err) { console.error('openAddSkill handler error', err); }
});

// Page module: dashboard
console.log('Page module: dashboard loaded');

function init() {
    // Global in-memory cache for counts so other pages/modules can update dashboard without reload
    window.SLPCache = window.SLPCache || (function() {
        const counts = {
            total_students: null,
            total_goals: null,
            total_documents: null,
            recent_sessions: null,
            progress_reports_count: null
        };
        const readDom = () => {
            const read = (id) => {
                const el = document.getElementById(id);
                if (!el) return null;
                const txt = (el.textContent || '').trim();
                if (txt === '') return null;
                const n = parseInt(txt, 10);
                return Number.isInteger(n) ? n : null;
            };
            counts.total_students = read('stat-total-students');
            // template uses stat-total-documents for the documents count; map both
            counts.total_goals = read('stat-total-goals') || null;
            counts.total_documents = read('stat-total-documents') || counts.total_goals || null;
            counts.recent_sessions = read('stat-recent-reports');
            // If template exposes a DB-authoritative progress_reports_count it will be the same DOM element
            counts.progress_reports_count = read('stat-recent-reports');
        };
        const updateDashboardDisplay = () => {
            if (counts.total_students !== null && document.getElementById('stat-total-students')) document.getElementById('stat-total-students').textContent = counts.total_students;
            // update both the legacy goals element (if present) and the template's documents element
            if (counts.total_goals !== null && document.getElementById('stat-total-goals')) document.getElementById('stat-total-goals').textContent = counts.total_goals;
            if ((counts.total_documents !== null || counts.total_goals !== null) && document.getElementById('stat-total-documents')) document.getElementById('stat-total-documents').textContent = (counts.total_documents !== null ? counts.total_documents : counts.total_goals);
            if (counts.recent_sessions !== null && document.getElementById('stat-recent-reports')) document.getElementById('stat-recent-reports').textContent = counts.recent_sessions;
        };
        readDom();
        return {
            getCounts: () => ({ ...counts }),
            setCounts: (c) => { if (c.total_students !== undefined) counts.total_students = c.total_students; if (c.total_goals !== undefined) counts.total_goals = c.total_goals; if (c.recent_sessions !== undefined) counts.recent_sessions = c.recent_sessions; if (c.progress_reports_count !== undefined) counts.progress_reports_count = c.progress_reports_count; updateDashboardDisplay(); },
            incGoals: (n = 1) => {
                counts.total_goals = (Number.isInteger(counts.total_goals) ? counts.total_goals : 0) + n;
                updateDashboardDisplay();
                // persist immediate change so navigation preserves the increment
                try {
                    localStorage.setItem('slp_dashboard_counts_v1', JSON.stringify({ ts: Date.now(), data: { total_students: counts.total_students, total_goals: counts.total_goals, recent_sessions: counts.recent_sessions, progress_reports_count: counts.progress_reports_count } }));
                } catch (e) { /* ignore */ }
            },
            decGoals: (n = 1) => {
                counts.total_goals = (Number.isInteger(counts.total_goals) ? counts.total_goals : 0) - n;
                if (counts.total_goals < 0) counts.total_goals = 0;
                updateDashboardDisplay();
                try {
                    localStorage.setItem('slp_dashboard_counts_v1', JSON.stringify({ ts: Date.now(), data: { total_students: counts.total_students, total_goals: counts.total_goals, recent_sessions: counts.recent_sessions, progress_reports_count: counts.progress_reports_count } }));
                } catch (e) { /* ignore */ }
            },
            setStudents: (n) => { counts.total_students = n; updateDashboardDisplay(); },
            updateDashboardDisplay
        };
    })();
    // nothing aggressive here; placeholder for custom dashboard interactions
    document.querySelectorAll('.quick-actions a.action-card').forEach(a => {
        a.addEventListener('click', (e) => {
            // allow normal navigation but log
            console.log('quick action', a.getAttribute('href'));
        });
    });

    // Live refresh stats: fetch counts and update DOM
    const updateStats = async () => {
        try {
            const form = new FormData();
            form.append('action', 'get_dashboard_counts');
            const res = await fetch('/includes/submit.php', { method: 'POST', body: form });
            const data = await res.json();
            if (data && data.success) {
                // Prefer DB-authoritative progress_reports_count when provided
                let prCount = null;
                try {
                    if (typeof data.progress_reports_count !== 'undefined' && data.progress_reports_count !== null) {
                        if (Number.isInteger(data.progress_reports_count)) prCount = data.progress_reports_count;
                        else if (!Number.isNaN(parseInt(data.progress_reports_count, 10))) prCount = parseInt(data.progress_reports_count, 10);
                    }
                } catch (e) { prCount = null; }
                // Fallback to recent_sessions when prCount not present
                if (prCount === null) {
                    if (typeof data.recent_sessions !== 'undefined' && data.recent_sessions !== null) {
                        prCount = Number.isInteger(data.recent_sessions) ? data.recent_sessions : (Number.isNaN(parseInt(data.recent_sessions, 10)) ? 0 : parseInt(data.recent_sessions, 10));
                    } else {
                        prCount = 0;
                    }
                }

                // Update DOM element for Active Progress Reports with DB count
                try {
                    const el = document.getElementById('stat-recent-reports');
                    if (el) el.textContent = String(prCount);
                } catch (e) { /* ignore DOM errors */ }

                // Update other stats conservatively
                const setIfNum = (id, val) => {
                    try {
                        const el = document.getElementById(id);
                        if (!el) return;
                        if (Number.isInteger(val)) { el.textContent = String(val); return; }
                        const p = parseInt(val, 10);
                        if (!Number.isNaN(p)) el.textContent = String(p);
                    } catch (e) { /* ignore */ }
                };
                setIfNum('stat-total-students', data.total_students);
                setIfNum('stat-total-goals', data.total_goals);
                if (document.getElementById('stat-total-documents')) setIfNum('stat-total-documents', (typeof data.total_documents !== 'undefined' ? data.total_documents : data.total_goals));

                // Sync in-memory cache
                try { window.SLPCache && window.SLPCache.setCounts && window.SLPCache.setCounts({ recent_sessions: data.recent_sessions, total_students: data.total_students, total_goals: data.total_goals, progress_reports_count: prCount }); } catch (e) {}
            } else {
                console.warn('Failed to fetch dashboard counts', data);
            }
        } catch (err) {
            console.warn('Error updating dashboard stats', err);
        }
    };
    // Expose manual trigger so UI or dev console can refresh when desired
    window.dashboardUpdateStats = updateStats;

    // Debug helper: request the server's deduplicated report items for inspection
    window.dashboardDebugReports = async function() {
        try {
            const f = new FormData(); f.append('action', 'get_dashboard_counts'); f.append('report_debug', '1');
            const res = await fetch('/includes/submit.php', { method: 'POST', body: f });
            const data = await res.json();
            console.log('dashboardDebugReports result', data);
            if (data && data.report_items) {
                console.table(data.report_items);
            }
            return data;
        } catch (e) { console.warn('dashboardDebugReports failed', e); return null; }
    };
                            window.dashboardUpdateStats = (force = false) => updateStats(Boolean(force));
    // Helper to refresh recent activity DOM by calling the server and replacing the container
    // Exposed globally so other modules can call it after actions that create/delete items.
    window.refreshRecentActivity = async function(limit = 20) {
        try {
            const container = document.querySelector('.recent-activity .activity-list');
            // fallback selector if templates vary
            const fallback = document.getElementById('recentActivityList');
            const target = container || fallback;
            if (!target) return false;
            const form = new FormData(); form.append('action', 'get_recent_activity'); form.append('limit', String(limit));
            const res = await fetch('/includes/submit.php', { method: 'POST', body: form });
            const data = await res.json();
            if (!data || !data.success || !Array.isArray(data.items)) return false;
            // Build HTML list items conservatively to avoid depending on templating
            const html = data.items.map(it => {
                const icon = it.icon || '';
                const title = it.title || it.type || '';
                const desc = it.description || '';
                const when = it.date ? (new Date(it.date)).toLocaleString() : '';
                return `<div class="activity-item"><div class="activity-icon">${icon}</div><div class="activity-body"><div class="activity-title">${escapeHtml(title)}</div><div class="activity-desc">${escapeHtml(desc)}</div><div class="activity-date muted">${escapeHtml(when)}</div></div></div>`;
            }).join('');
            try { target.innerHTML = html; } catch (e) { console.warn('refreshRecentActivity innerHTML failed', e); }
            return true;
        } catch (e) { console.warn('refreshRecentActivity failed', e); return false; }
    };

    // Optional debug: monitor writes to the documents stat element to detect unexpected overwrites
    try {
        window.SLP_DEBUG_DASHBOARD = window.SLP_DEBUG_DASHBOARD || false;
        if (window.SLP_DEBUG_DASHBOARD) {
            const watchEl = document.getElementById('stat-total-documents');
            if (watchEl) {
                let last = (watchEl.textContent || '').trim();
                const mo = new MutationObserver((mutations) => {
                    mutations.forEach(m => {
                        const now = (watchEl.textContent || '').trim();
                        if (now !== last) {
                            console.group('SLP DEBUG: stat-total-documents changed');
                            console.log('previous:', last, 'current:', now);
                            console.trace();
                            console.groupEnd();
                            last = now;
                        }
                    });
                });
                mo.observe(watchEl, { childList: true, characterData: true, subtree: true });
                // also monkey-patch textContent setter for extra coverage
                try {
                    const proto = Object.getPrototypeOf(watchEl);
                    const desc = Object.getOwnPropertyDescriptor(proto, 'textContent');
                    if (desc && desc.set) {
                        const originalSet = desc.set.bind(watchEl);
                        Object.defineProperty(watchEl, 'textContent', {
                            configurable: true,
                            enumerable: true,
                            get: function() { return desc.get.call(this); },
                            set: function(v) { console.group('SLP DEBUG: stat-total-documents setter'); console.log('setter called with', v); console.trace(); console.groupEnd(); return originalSet(v); }
                        });
                    }
                } catch (e) { /* best-effort only */ }
            }
        }
    } catch (e) { /* ignore debug setup errors */ }

    // Caching: keep last fetched counts in localStorage to avoid unnecessary updates
    const CACHE_KEY = 'slp_dashboard_counts_v1';
    const CACHE_TTL_MS = 1000 * 60 * 2; // 2 minutes

    const readCache = () => {
        try {
            const raw = localStorage.getItem(CACHE_KEY);
            if (!raw) return null;
            const parsed = JSON.parse(raw);
            if (!parsed || !parsed.ts) return null;
            if ((Date.now() - parsed.ts) > CACHE_TTL_MS) return null;
                // Back-compat: ensure progress_reports_count exists
                const d = parsed.data || null;
                if (d && typeof d.progress_reports_count === 'undefined') d.progress_reports_count = (typeof d.recent_sessions !== 'undefined') ? d.recent_sessions : null;
                return d;
        } catch (e) { return null; }
    };

    const writeCache = (data) => {
        try { localStorage.setItem(CACHE_KEY, JSON.stringify({ ts: Date.now(), data: data })); } catch (e) { /* ignore */ }
    };

    // safeUpdate: only update DOM when fetched values differ from current displayed values
    // Accepts optional force flag to allow overwriting server-rendered values when necessary
    const safeUpdate = (data, force = false) => {
        if (!data) return;
        const upd = (id, value) => {
            const el = document.getElementById(id);
            if (!el) return false;
            const raw = (el.textContent || '').trim();
            const existing = (raw === '' || raw === '…') ? null : parseInt(raw, 10);
            const fetched = parseInt(value || 0, 10);
            // If the dashboard already has a server-rendered value for progress reports, preserve it
            if (id === 'stat-recent-reports' && existing !== null) return false;
            if (Number.isInteger(fetched) && existing !== null && fetched === existing) return false; // no change
            // If fetched is zero and existing > 0, avoid overwriting unless cache says value changed
            if (fetched === 0 && Number.isInteger(existing) && existing > 0) return false;
            el.textContent = fetched;
            return true;
        };
        const changed = [];
        if (upd('stat-total-students', data.total_students)) changed.push('students');
        // update documents element from server's total_goals or total_documents
        const docVal = (data.total_documents !== undefined) ? data.total_documents : data.total_goals;
        if (upd('stat-total-documents', docVal)) changed.push('documents');
        if (upd('stat-total-goals', data.total_goals)) changed.push('goals');
        let prCount = null;
        try {
            if (typeof data.progress_reports_count !== 'undefined' && data.progress_reports_count !== null) {
                if (Number.isInteger(data.progress_reports_count)) prCount = data.progress_reports_count;
                else if (!Number.isNaN(parseInt(data.progress_reports_count, 10))) prCount = parseInt(data.progress_reports_count, 10);
            }
        } catch (e) { prCount = null; }
        if (prCount === null) prCount = data.recent_sessions;
        if (upd('stat-recent-reports', prCount)) changed.push('reports');
        return changed.length > 0;
    };

    // Auto-refresh: check cache first; if cached and identical to page values, skip network call
    const autoRefresh = async () => {
        const cached = readCache();
        // get current page values
        // Read current page values; if element is empty or missing, treat as null so we don't assume 0
        const readPageVal = (id) => {
            const el = document.getElementById(id);
            if (!el) return null;
            const txt = (el.textContent || '').trim();
            if (txt === '') return null;
            const n = parseInt(txt, 10);
            return Number.isInteger(n) ? n : null;
        };
        const pageVals = {
            total_students: readPageVal('stat-total-students'),
            total_goals: readPageVal('stat-total-goals'),
            recent_sessions: readPageVal('stat-recent-reports'),
            progress_reports_count: readPageVal('stat-recent-reports')
        };

        if (cached && cached.total_students === pageVals.total_students && cached.total_goals === pageVals.total_goals && cached.recent_sessions === pageVals.recent_sessions) {
            // nothing changed and cache is fresh — skip fetching
            return;
        }

        // otherwise perform the fetch and update if needed; update cache when changed
        try {
            const form = new FormData(); form.append('action', 'get_dashboard_counts');
            const res = await fetch('/includes/submit.php', { method: 'POST', body: form });
            const data = await res.json();
            if (data && data.success) {
                // Rely on server-provided recent_sessions as authoritative; do not override with client-side activity counting.

                const changed = safeUpdate(data);
                // Only write cache when fetched values are meaningful or when something actually changed.
                const anyPositive = [data.total_students, data.total_goals, data.recent_sessions, data.progress_reports_count].some(v => Number.isInteger(v) && v > 0);
                const cachedNow = readCache();
                // If any positive value present, or something changed, or there was no prior cache, persist the authoritative values.
                if (anyPositive || changed || !cachedNow) {
                    writeCache({ total_students: data.total_students, total_goals: data.total_goals, recent_sessions: data.recent_sessions, progress_reports_count: data.progress_reports_count });
                }
                return changed;
            }
        } catch (e) { console.warn('Auto-refresh failed', e); }
    };

    // Automatic autoRefresh has been disabled to avoid visual jumps for goals.
    // Use window.dashboardUpdateStats() or window.SLPCache to update counts manually when needed.

    // Force a fresh fetch on page load to avoid stale localStorage/cached values after restarts.
    try {
        // Remove any existing cache so updateStats fetches authoritative values
        try { localStorage.removeItem(CACHE_KEY); } catch (e) { /* ignore */ }
        // Show a lightweight loading placeholder for recent reports to avoid a visible 0 -> N jump
        try {
            const rr = document.getElementById('stat-recent-reports');
            if (rr && (!rr.textContent || rr.textContent.trim() === '' || rr.textContent.trim() === '0')) rr.textContent = '…';
        } catch (e) {}
        // Trigger an immediate fetch to populate the dashboard with live counts
        updateStats().catch(() => {});
    } catch (e) { /* ignore runtime environments without localStorage */ }
}

init();
export default { init };

<?php
// templates/progress.php
$student_id = $_GET['student_id'] ?? 0;
$selected_student = null;
if ($student_id) {
    $selected_student = findRecord('students', 'id', $student_id);
}

// ensure $user_students is available (index.php usually provides it)
$user_students = $user_students ?? [];
if (empty($user_students)) {
    try {
        // Try DB-backed lookup first
        if (file_exists(__DIR__ . '/../includes/sqlite.php')) {
            require_once __DIR__ . '/../includes/sqlite.php';
            if (function_exists('get_db')) {
                $pdo = get_db();
                $pi = $pdo->prepare("PRAGMA table_info('students')"); $pi->execute();
                $cols = array_column($pi->fetchAll(PDO::FETCH_ASSOC), 'name');
                if (in_array('assigned_therapist', $cols)) {
                    $stmt = $pdo->prepare('SELECT * FROM students WHERE assigned_therapist = :uid');
                    $stmt->execute([':uid' => $_SESSION['user_id'] ?? null]);
                    $user_students = $stmt->fetchAll(PDO::FETCH_ASSOC);
                } else {
                    $stmt = $pdo->query('SELECT * FROM students WHERE archived = 0');
                    $user_students = $stmt->fetchAll(PDO::FETCH_ASSOC);
                }
            }
        }
    } catch (\Throwable $e) {
        $user_students = findRecords('students', ['assigned_therapist' => $_SESSION['user_id'] ?? null]);
    }
}
if (!is_array($user_students)) $user_students = [];

// build list of student ids for filtering (avoid undefined variable)
$student_ids = array_map(function($s){ return (int)($s['id'] ?? 0); }, $user_students);

// Progress reporting has been removed. No progress data will be displayed here.
$student_progress = [];
?>

<div class="container">
<div class="page-header stack">
    <h2>Progress Tracker</h2>
    <!-- client-managed student name (empty by default). JS will set/clear this to avoid server-side persistence -->
    <div id="progressStudentName" class="progress-student-name" aria-live="polite"></div>
        <?php $preIdAttr = isset($selected_student['id']) ? ' data-student-id="' . intval($selected_student['id']) . '"' : ''; ?>
        <?php $disabledAttr = isset($selected_student['id']) ? '' : 'disabled'; ?>
    <span id="reportIndicator" class="report-indicator" style="margin-left:12px;color:#666;font-size:0.95em"></span>
</div>
    
    <div class="progress-toolbar">
            <div class="toolbar-left toolbar-left-stacked">
                <?php // place primary action inside the toolbar so it remains aligned with toolbar/skills area ?>
                <button id="addProgressBtn" class="btn btn-primary primary-action-left" <?php echo $preIdAttr; ?> <?php echo $disabledAttr; ?>>Start new Progress Report</button>
                <div class="toolbar-select-wrap">
                    <label for="progressStudentSelect">Select Student:</label>
                    <select id="progressStudentSelect" onchange="handleProgressSelectChange(this.value);">
                        <option value="">-- Select a student --</option>
                    <?php foreach ($user_students as $student): ?>
                        <option value="<?php echo $student['id']; ?>" <?php echo (isset($selected_student['id']) && $selected_student['id']==$student['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']) . ' - ' . htmlspecialchars($student['student_id'] ?? $student['id']); ?></option>
                    <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="toolbar-right">
                <button class="btn btn-primary" id="createReport" <?php echo $disabledAttr; ?>>Create Progress Update Report</button>
                <button class="btn btn-outline btn-danger" id="deleteReport" <?php echo $disabledAttr; ?> style="margin-left:8px;">Delete Report</button>
            </div>
    </div>
<script>
document.addEventListener('DOMContentLoaded', function(){
    const btn = document.getElementById('addProgressBtn');
    const select = document.getElementById('progressStudentSelect');
    // Leave any server-rendered report title in place; client will update it when needed.
    const getSidFromUrl = () => {
        try { const p = new URLSearchParams(window.location.search); return p.get('student_id') || p.get('id') || ''; } catch (e) { return ''; }
    };

    // Determine selected student id from select dropdown first, then button data attribute, then URL param
    let sid = '';
    if (select && select.value) sid = select.value || '';
    if ((!sid || sid === '') && btn) sid = btn.getAttribute('data-student-id') || '';
    if ((!sid || sid === '') ) sid = getSidFromUrl() || '';

    if (sid && typeof window.initializeProgress === 'function') {
        try { window.initializeProgress(Number(sid)); } catch(e) { console.error(e); }
    }

    // Set the client-visible student name into the reserved element if a
    // selection already exists. The CSS reserves the vertical space so this
    // will not cause layout shifts when the name appears.
    try {
        const nameElInit = document.getElementById('progressStudentName');
        const selInit = document.getElementById('progressStudentSelect');
        if (nameElInit) {
            if (selInit && selInit.value) {
                const opt = selInit.options[selInit.selectedIndex];
                const txt = opt ? (opt.textContent || '') : '';
                nameElInit.textContent = txt ? txt.split(' - ')[0] : '\u00A0';
            } else {
                // keep a non-breaking space so the reserved element remains present
                nameElInit.textContent = '\u00A0';
            }
        }
    } catch (e) { /* ignore */ }

    if (btn) {
            btn.addEventListener('click', function(){
            // Prefer current dropdown value first when opening Add Skill modal
            let sid2 = (select && select.value) ? select.value : (this.getAttribute('data-student-id') || '');
            if ((!sid2 || sid2 === '') ) sid2 = getSidFromUrl() || '';
            if (!sid2) { alert('Select a student first'); return; }
            const ev = new CustomEvent('openAddSkill', { detail: { student_id: Number(sid2) } });
            document.dispatchEvent(ev);
        });
    }

        // Prevent full-page navigations triggered by the select. This handler
        // will update the URL and call initializeProgress() so there is no server
        // default page flash while switching students.
        window.handleProgressSelectChange = function(val) {
            try {
                const sel = document.getElementById('progressStudentSelect');
                const studentId = (typeof val !== 'undefined' && val !== null) ? String(val) : (sel ? sel.value : '');
                // If empty selection, clear the progress area and update URL
                const newUrl = '?view=progress' + (studentId ? ('&student_id=' + encodeURIComponent(studentId)) : '');
                try { history.pushState({}, '', newUrl); } catch (e) { /* ignore */ }

                if (!studentId) {
                    // Show server placeholder and clear any lingering report title
                    const overview = document.querySelector('.progress-overview');
                    if (overview) try { overview.remove(); } catch (e) { overview.style.display = 'none'; }
                    // reserved student-name element intentionally left empty to avoid layout shifts
                    try { const nameEl = document.getElementById('progressStudentName'); if (nameEl) nameEl.textContent = '\u00A0'; } catch (e) {}
                    // Remove progress report title element if present
                    try { const titleEl = document.getElementById('progressReportTitle'); if (titleEl) titleEl.remove(); } catch (e) {}
                    // Hide any progress-related sections and show the no-student placeholder
                    try { document.querySelectorAll('.no-progress, .progress-stats, .progress-chart-container, .progress-history, .skills-list').forEach(el => { if (el) el.style.display = 'none'; }); } catch(e) {}
                    // Reset toolbar button states/text so UI reflects no student selected
                    try {
                        const addBtn = document.getElementById('addProgressBtn');
                        if (addBtn) {
                            addBtn.disabled = true;
                            addBtn.classList.add('btn-disabled');
                            addBtn.textContent = 'Create Progress Report';
                            try { addBtn.removeAttribute('data-student-id'); } catch(e) {}
                        }
                        const createBtn = document.getElementById('createReport'); if (createBtn) { createBtn.disabled = true; createBtn.classList.add('btn-disabled'); }
                        const delBtn = document.getElementById('deleteReport'); if (delBtn) { delBtn.disabled = true; delBtn.classList.add('btn-disabled'); }
                    } catch (e) { /* ignore toolbar reset errors */ }
                    const noSel = document.querySelector('.no-student-selected'); if (noSel) try { noSel.style.display = 'block'; } catch(e){}
                    return;
                }

                // Hide server placeholder if present
                const noSel = document.querySelector('.no-student-selected'); if (noSel) try { noSel.style.display = 'none'; } catch(e){}

                // When a studentId is selected, write the student's name into the
                // reserved element. CSS prevents this from causing layout shifts.
                try {
                    const nameEl = document.getElementById('progressStudentName');
                    const selEl = document.getElementById('progressStudentSelect');
                    if (nameEl) {
                        if (studentId && selEl) {
                            const option = selEl.options[selEl.selectedIndex];
                            const txt = option ? (option.textContent || '') : '';
                            nameEl.textContent = txt ? txt.split(' - ')[0] : '\u00A0';
                        } else {
                            // set a non-breaking space instead of empty string
                            nameEl.textContent = '\u00A0';
                        }
                    }
                } catch (e) { /* ignore */ }

                // Ensure progress-overview exists so initializeProgress can render into it.
                // Insert it after the progress toolbar so the toolbar/search controls remain above the skills.
                let overview = document.querySelector('.progress-overview');
                if (!overview) {
                    overview = document.createElement('div'); overview.className = 'progress-overview';
                    const toolbar = document.querySelector('.progress-toolbar');
                    if (toolbar && toolbar.parentNode) toolbar.parentNode.insertBefore(overview, toolbar.nextSibling);
                    else {
                        // fallback: after page header, then container append
                        const header = document.querySelector('.page-header');
                        if (header && header.parentNode) header.parentNode.insertBefore(overview, header.nextSibling);
                        else document.querySelector('.container')?.appendChild(overview);
                    }
                }

                // Call existing initialization to fetch and render skills in-place
                if (typeof window.initializeProgress === 'function') {
                    try { window.initializeProgress(Number(studentId)); } catch (e) { console.error('initializeProgress failed', e); }
                }
            } catch (err) { console.error('handleProgressSelectChange error', err); }
        };
});
</script>

<?php if (!$selected_student): ?>
    <div class="no-student-selected">
        <div class="no-student-icon">👤</div>
        <h3>No Student Selected</h3>
        <p>Please select a student to view and manage their progress reports.</p>
    </div>
<?php else: ?>
    
    
    <div class="progress-overview">
        <?php if (empty($student_progress)): ?>
            <div class="no-progress">
                <div class="no-progress-icon">📊</div>
                <h3>No Progress Data Yet</h3>
                <p>Start tracking <?php echo htmlspecialchars($selected_student['first_name']); ?>'s therapy progress.</p>
            </div>
        <?php else: ?>
            <div class="progress-stats">
                <div class="stat-card">
                    <h4>Total Sessions</h4>
                    <div class="stat-number"><?php echo count($student_progress); ?></div>
                </div>
                <div class="stat-card">
                    <h4>Average Score</h4>
                    <?php
                    $avg_score = 0;
                    if (count($student_progress) > 0) {
                        $total = array_sum(array_column($student_progress, 'score'));
                        $avg_score = round($total / count($student_progress), 1);
                    }
                    ?>
                    <div class="stat-number"><?php echo $avg_score; ?>%</div>
                </div>
            </div>
            
            <div class="progress-chart-container">
                <canvas id="progressChart" width="400" height="200"></canvas>
            </div>
            
            <div class="progress-history">
                <h4>Recent Sessions</h4>
                <?php foreach ($student_progress as $update): ?>
                    <div class="progress-item">
                        <div class="progress-date"><?php echo date('Y-m-d H:i', strtotime($update['date_recorded'])); ?></div>
                        <div class="progress-details">
                            <strong><?php echo ucfirst($update['goal_area'] ?? ''); ?></strong>
                            <span class="progress-score"><?php echo htmlspecialchars($update['score'] ?? '0'); ?>%</span>
                            <?php if (!empty($update['notes'])): ?>
                                <p><?php echo htmlspecialchars($update['notes'] ?? ''); ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>
</div>

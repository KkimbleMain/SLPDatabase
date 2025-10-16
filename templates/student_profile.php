<?php
// templates/student_profile.php
// Student profile view showing detailed information

// Ensure supporting data (skills, studentForms, student_reports) is available for this template.
$studentId = isset($profileStudent['id']) ? (int)$profileStudent['id'] : (isset($profileStudent['student_id']) ? (int)$profileStudent['student_id'] : 0);
// defensive: attempt to load DB helper if $pdo isn't in scope
// For the full-page profile view always (re)load forms, skills and report metadata
if ($studentId && (!isset($studentForms) || !isset($student_report_meta))) {
    try {
        if (!isset($pdo)) {
            require_once __DIR__ . '/../includes/sqlite.php';
            $pdo = get_db();
        }

        // fetch progress skills (if table exists)
        $skills = [];
        try {
            $pi = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name='progress_skills'"); $pi->execute();
            if ($pi->fetchColumn()) {
                $s = $pdo->prepare('SELECT * FROM progress_skills WHERE student_id = :sid ORDER BY id');
                $s->execute([':sid' => $studentId]);
                $skills = $s->fetchAll(PDO::FETCH_ASSOC);
            }
        } catch (Throwable $e) { $skills = []; }

        // fetch a simple list of documents/titles from other_documents/session_reports/initial_evaluations/discharge_reports
        if (!isset($studentForms) || empty($studentForms)) {
            $studentForms = [];
            $tables = ['initial_evaluations','session_reports','discharge_reports','other_documents'];
            foreach ($tables as $t) {
                try {
                    $pi = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name=:t"); $pi->execute([':t' => $t]);
                    if (!$pi->fetchColumn()) continue;
                    $st = $pdo->prepare("SELECT id, title, form_type, created_at, file_path, content FROM {$t} WHERE student_id = :sid ORDER BY created_at DESC");
                    $st->execute([':sid' => $studentId]);
                    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
                        $studentForms[] = $row;
                    }
                } catch (Throwable $e) { continue; }
            }
        }

        // Determine whether the student has an active progress report.
        // Prefer the modern progress_reports table; fall back to legacy student_reports (status='active' or path non-empty).
        $student_report_meta = null;
        $has_active_report = false;
        try {
            $pi = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name='progress_reports'"); $pi->execute();
            if ($pi->fetchColumn()) {
                $st = $pdo->prepare('SELECT COALESCE(id, rowid) AS id, title, created_at FROM progress_reports WHERE student_id = :sid ORDER BY created_at DESC LIMIT 1');
                $st->execute([':sid' => $studentId]);
                $student_report_meta = $st->fetch(PDO::FETCH_ASSOC) ?: null;
                if (!empty($student_report_meta)) $has_active_report = true;
            }
        } catch (Throwable $e) { /* ignore */ }

        if (!$has_active_report) {
            try {
                $pi2 = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name='student_reports'"); $pi2->execute();
                if ($pi2->fetchColumn()) {
                    $qr = $pdo->prepare('SELECT * FROM student_reports WHERE student_id = :sid LIMIT 1'); $qr->execute([':sid' => $studentId]);
                    $sr = $qr->fetch(PDO::FETCH_ASSOC) ?: null;
                    $student_report_meta = $student_report_meta ?: $sr;
                    if ($sr && (!empty($sr['status']) && strtolower($sr['status']) === 'active' || !empty($sr['path']))) {
                        $has_active_report = true;
                    }
                }
            } catch (Throwable $e) { /* ignore */ }
        }
    } catch (Throwable $e) {
        // ignore DB load failures; template will gracefully show missing sections
    }
}
?>

<div class="container profile">
    <div class="page-header stack">
        <div class="header-content">
            <button class="btn btn-outline" onclick="history.back()">← Back to Students</button>
            <div class="student-title">
                <div class="avatar-circle large"><?php echo strtoupper(substr($profileStudent['first_name'], 0, 1) . substr($profileStudent['last_name'], 0, 1)); ?></div>
                <div>
                    <h1><?php echo htmlspecialchars($profileStudent['first_name'] . ' ' . $profileStudent['last_name']); ?></h1>
                    <p class="student-subtitle">Grade <?php echo htmlspecialchars($profileStudent['grade'] ?? 'Unknown'); ?> • Student ID: <?php echo htmlspecialchars($profileStudent['student_id'] ?? $profileStudent['id']); ?></p>
                </div>
            </div>
                <script>
                // Fallback edit modal handler for the full-page student_profile view
                (function(){
                    if (typeof window.editStudentProfile === 'function') return;
                    window.editStudentProfile = function(studentId){
                        try {
                            var params = new URLSearchParams(); params.append('action','get_student'); params.append('id', String(studentId));
                            fetch('/includes/submit.php', { method: 'POST', headers: {'Content-Type':'application/x-www-form-urlencoded'}, body: params.toString() })
                            .then(function(resp){ return resp.json(); })
                            .then(function(data){
                                if (!data || !data.success) { alert('Failed to load student'); return; }
                                var student = data.student || {};
                                var tpl = document.getElementById('tmpl-edit-student');
                                if (!tpl) { alert('Edit template missing'); return; }
                                var clone = tpl.content.firstElementChild.cloneNode(true);
                                var modalEl = insertModal(clone);
                                if (!modalEl) return;
                                var qs = function(s){ return modalEl.querySelector(s); };
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
                                if (qs('#displayStudentId')) qs('#displayStudentId').textContent = student.student_id || student.id || '';
                                var form = qs('#editStudentForm');
                                if (form) {
                                    form.addEventListener('submit', function(e){
                                        e.preventDefault();
                                        var fd = new FormData(form); fd.append('action','update_student');
                                        fetch('/includes/submit.php', { method: 'POST', body: fd })
                                        .then(function(r){ return r.text(); })
                                        .then(function(txt){
                                            var out = null; try { out = txt ? JSON.parse(txt) : null; } catch(e){ console.error('parse', e, txt); alert('Server error'); return; }
                                            if (out && out.success) {
                                                if (typeof closeModal === 'function') closeModal();
                                                setTimeout(function(){ location.reload(); }, 200);
                                            } else {
                                                alert((out && out.error) ? out.error : 'Failed to update');
                                            }
                                        }).catch(function(err){ console.error(err); alert('Network error'); });
                                    }, { once: true });
                                }
                            }).catch(function(err){ console.error(err); alert('Failed to load student'); });
                        } catch (e) { console.error(e); }
                    };
                })();
                </script>
        </div>
    </div>

    <div class="profile-content">
        <div class="profile-grid">
            <!-- Basic Information Section -->
            <div class="profile-section">
                <h2 class="section-title">Basic Information</h2>
                <div class="info-grid">
                    <div class="info-item">
                        <label>Full Name:</label>
                        <span><?php echo htmlspecialchars($profileStudent['first_name'] . ' ' . $profileStudent['last_name']); ?></span>
                    </div>
                    <div class="info-item">
                        <label>Date of Birth:</label>
                        <span><?php echo htmlspecialchars($profileStudent['date_of_birth'] ?? 'Not specified'); ?></span>
                    </div>
                    <div class="info-item">
                        <label>Grade:</label>
                        <span><?php echo htmlspecialchars($profileStudent['grade'] ?? 'Not specified'); ?></span>
                    </div>
                    <div class="info-item">
                        <label>Gender:</label>
                        <span><?php echo htmlspecialchars($profileStudent['gender'] ?? 'Not specified'); ?></span>
                    </div>
                    <div class="info-item">
                        <label>Primary Language:</label>
                        <span><?php echo htmlspecialchars($profileStudent['primary_language'] ?? 'Not specified'); ?></span>
                    </div>
                    <div class="info-item">
                        <label>Service Frequency:</label>
                        <span><?php echo htmlspecialchars($profileStudent['service_frequency'] ?? 'Not specified'); ?></span>
                    </div>
                </div>
            </div>

            <!-- Contact & School Information -->
            <div class="profile-section">
                <h2 class="section-title">Contact & School Information</h2>
                <div class="info-grid">
                    <div class="info-item">
                        <label>Parent Contact:</label>
                        <span><?php echo htmlspecialchars($profileStudent['parent_contact'] ?? 'Not specified'); ?></span>
                    </div>
                    <!-- Teacher and Assigned Therapist fields were removed from the schema; omitted from profile. -->
                </div>
            </div>

            <!-- Medical Information -->
            <?php if (!empty($profileStudent['medical_info'])): ?>
            <div class="profile-section full-width">
                <h2 class="section-title">Medical Information</h2>
                <div class="info-content">
                    <?php echo nl2br(htmlspecialchars($profileStudent['medical_info'])); ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Goals Summary -->
            <div class="profile-section">
                <h2 class="section-title">Goals Summary</h2>
                <?php 
                $student_goals = array_filter($goals, function($goal) use ($profileStudent) {
                    return (int)($goal['student_id'] ?? 0) === (int)($profileStudent['id'] ?? 0) && ($goal['status'] ?? 'active') === 'active';
                });
                ?>
                <div class="goals-summary">
                    <div class="stat-item">
                        <span class="stat-number"><?php echo count($student_goals); ?></span>
                        <span class="stat-label">Active Goals</span>
                    </div>
                </div>
                <?php if (!empty($student_goals)): ?>
                <div class="goals-list-cards">
                    <?php foreach ($student_goals as $goal): ?>
                    <div class="goal-card">
                        <div class="goal-card-header">
                            <strong><?php echo htmlspecialchars($goal['goal_area'] ?? ($goal['title'] ?? 'General')); ?></strong>
                            <span class="goal-date"><?php echo !empty($goal['created_at']) ? date('M j, Y', strtotime($goal['created_at'])) : ''; ?></span>
                        </div>
                        <?php
                            // Attempt to extract structured fields for display: long term, short term, intervention, measurement
                            $descSource = '';
                            foreach (['Long_Term_Goals','Long_Term_Goals','long_term_goals','longTermGoals','goal_text','description'] as $k) {
                                if (!empty($goal[$k])) { $descSource = $goal[$k]; break; }
                            }

                            // helper to pull known direct columns if present
                            $long = $goal['Long_Term_Goals'] ?? $goal['long_term_goals'] ?? $goal['longTermGoals'] ?? $goal['longTermGoals'] ?? null;
                            $short = $goal['Short_Term_Goals'] ?? $goal['short_term_goals'] ?? $goal['Short_Term_Goals'] ?? null;
                            $intervention = $goal['Intervention_Strategies'] ?? $goal['intervention_strategies'] ?? null;
                            $measurement = $goal['Measurement_Criteria'] ?? $goal['measurement_criteria'] ?? null;

                            // If direct columns are empty, attempt to parse from combined description text
                            if (empty($long) && !empty($descSource)) {
                                if (preg_match('/Long Term Goals:\s*(.*?)($|Short Term Objectives:|Intervention Strategies:|Measurement Criteria:)/si', $descSource, $m)) {
                                    $long = trim($m[1]);
                                }
                            }
                            if (empty($short) && !empty($descSource)) {
                                if (preg_match('/Short Term Objectives:\s*(.*?)($|Intervention Strategies:|Measurement Criteria:)/si', $descSource, $m)) {
                                    $short = trim($m[1]);
                                }
                            }
                            if (empty($intervention) && !empty($descSource)) {
                                if (preg_match('/Intervention Strategies:\s*(.*?)($|Measurement Criteria:)/si', $descSource, $m)) {
                                    $intervention = trim($m[1]);
                                }
                            }
                            if (empty($measurement) && !empty($descSource)) {
                                if (preg_match('/Measurement Criteria:\s*(.*)$/si', $descSource, $m)) {
                                    $measurement = trim($m[1]);
                                }
                            }

                            // If nothing parsed and we still have a short description, show it as a fallback line
                            $fallback = '';
                            if (empty($long) && empty($short) && empty($intervention) && empty($measurement) && !empty($descSource)) {
                                $fallback = trim($descSource);
                            }
                        ?>
                        <div class="goal-body">
                            <?php if (!empty($long)): ?>
                                <div class="goal-field"><strong>Long Term Goals:</strong> <?php echo nl2br(htmlspecialchars($long)); ?></div>
                            <?php endif; ?>
                            <?php if (!empty($short)): ?>
                                <div class="goal-field"><strong>Short Term Objectives:</strong> <?php echo nl2br(htmlspecialchars($short)); ?></div>
                            <?php endif; ?>
                            <?php if (!empty($intervention)): ?>
                                <div class="goal-field"><strong>Intervention Strategies:</strong> <?php echo nl2br(htmlspecialchars($intervention)); ?></div>
                            <?php endif; ?>
                            <?php if (!empty($measurement)): ?>
                                <div class="goal-field"><strong>Measurement Criteria:</strong> <?php echo nl2br(htmlspecialchars($measurement)); ?></div>
                            <?php endif; ?>
                            <?php if (!empty($fallback)): ?>
                                <div class="goal-field"><strong>Notes:</strong> <?php echo nl2br(htmlspecialchars(substr($fallback,0,800) . (strlen($fallback) > 800 ? '...' : ''))); ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>

            <!-- Progress Summary -->
            <div class="profile-section">
                <h2 class="section-title">Progress Summary</h2>
                <div class="progress-summary-compact">
                    <label style="display:flex;align-items:center;gap:10px;">
                        <input type="checkbox" disabled <?php echo (!empty($has_active_report) ? 'checked' : ''); ?> />
                        <span>Active Progress Report</span>
                    </label>
                    <?php if (!empty($skills)): ?>
                                <div class="skill-titles">
                                    <h4>Skills</h4>
                                    <ul>
                                        <?php foreach ($skills as $sk): ?>
                                            <?php
                                                // prefer skill_label column, fall back to name/title; include category for clarity
                                                $label = $sk['skill_label'] ?? $sk['skillName'] ?? $sk['title'] ?? $sk['name'] ?? ('Skill ' . ($sk['id'] ?? ''));
                                                $cat = !empty($sk['category']) ? trim($sk['category']) : '';
                                                $display = $label . ($cat ? ' - ' . $cat : '');
                                            ?>
                                            <li><?php echo htmlspecialchars($display); ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                    <?php else: ?>
                    <div class="skill-titles muted">No skills available.</div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Documentation -->
            <div class="profile-section full-width">
                <h2 class="section-title">Documentation</h2>
                <?php
                // Comprehensive scan: check all user tables for any column that references this student id.
                if (empty($studentForms) && isset($pdo) && $studentId) {
                    try {
                        $studentForms = [];
                        // Only scan canonical document tables per request
                        $tables = ['discharge_reports','goals','initial_evaluations','other_documents','session_reports'];
                        foreach ($tables as $t) {
                            try {
                                $pi = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name=:t"); $pi->execute([':t' => $t]);
                                if (!$pi->fetchColumn()) continue;
                                $piCols = $pdo->prepare("PRAGMA table_info('" . $t . "')"); $piCols->execute();
                                $colsInfo = $piCols->fetchAll(PDO::FETCH_ASSOC);
                                if (empty($colsInfo)) continue;
                                $cols = array_column($colsInfo, 'name');

                                // If the table has an explicit student_id column, use exact numeric match
                                $hasStudentIdCol = false;
                                foreach ($cols as $c) { if (in_array(strtolower($c), ['student_id','studentid'])) { $hasStudentIdCol = true; break; } }

                                $rows = [];
                                if ($hasStudentIdCol) {
                                    try {
                                        $sql = 'SELECT * FROM "' . $t . '" WHERE "student_id" = :sid ORDER BY created_at DESC';
                                        $stmt = $pdo->prepare($sql);
                                        $stmt->execute([':sid' => $studentId]);
                                        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                                    } catch (Throwable $e3) { $rows = []; }
                                } else {
                                    // Only check title and file_path for external identifier matches (avoid broad LIKE on all columns)
                                    $ext = isset($profileStudent['student_id']) ? trim($profileStudent['student_id']) : '';
                                    if ($ext === '') { $rows = []; }
                                    else {
                                        $candidates = [];
                                        if (in_array('title', $cols)) $candidates[] = 'title';
                                        if (in_array('file_path', $cols)) $candidates[] = 'file_path';
                                        // fetch by title/file_path LIKE
                                        if (!empty($candidates)) {
                                            $conds = array_map(function($c){ return '"' . $c . '" LIKE :ext'; }, $candidates);
                                            $sql = 'SELECT * FROM "' . $t . '" WHERE ' . implode(' OR ', $conds) . ' ORDER BY created_at DESC';
                                            try {
                                                $stmt = $pdo->prepare($sql);
                                                $stmt->execute([':ext' => '%' . $ext . '%']);
                                                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                                            } catch (Throwable $e4) { $rows = []; }
                                        }
                                        // For rows with a content column, attempt to parse JSON and include only if content.student_id or content.studentName matches
                                        if (in_array('content', $cols)) {
                                            try {
                                                $stmtC = $pdo->prepare('SELECT * FROM "' . $t . '" WHERE content IS NOT NULL AND content != ""');
                                                $stmtC->execute();
                                                foreach ($stmtC->fetchAll(PDO::FETCH_ASSOC) as $rC) {
                                                    $included = false;
                                                    $cjson = json_decode($rC['content'], true);
                                                    if (is_array($cjson)) {
                                                        if ((isset($cjson['student_id']) && (string)$cjson['student_id'] === (string)$studentId) || (isset($cjson['studentName']) && (string)$cjson['studentName'] === (string)$studentId) || (isset($cjson['student_id']) && (string)$cjson['student_id'] === (string)$ext) || (isset($cjson['studentName']) && (string)$cjson['studentName'] === (string)$ext)) {
                                                            $rows[] = $rC;
                                                        }
                                                    }
                                                }
                                            } catch (Throwable $e5) { /* ignore */ }
                                        }
                                    }
                                }

                                foreach ($rows as $r) {
                                    $doc = [
                                        'table' => $t,
                                        'id' => $r['id'] ?? null,
                                        'title' => $r['title'] ?? $r['name'] ?? $r['form_type'] ?? ('Record from ' . $t),
                                        'created_at' => $r['created_at'] ?? null,
                                        'file_path' => $r['file_path'] ?? null,
                                        'raw' => $r,
                                    ];
                                    $studentForms[] = $doc;
                                }
                            } catch (Throwable $e2) { continue; }
                        }
                    } catch (Throwable $e) { /* ignore scan failures */ }
                }

                    if (!empty($studentForms)):
                    // Ensure consistent ordering using canonical group order: initial, goals, sessions, other, discharge.
                    $order = ['initial_evaluation','goals_form','session_report','other_documents','discharge_report'];
                    $buckets = array_fill_keys($order, []);
                    foreach ($studentForms as $f) {
                        $ft = strtolower(trim((string)($f['form_type'] ?? ($f['table'] ?? ''))));
                        if (in_array($ft, ['initial_evaluation','initial_profile'])) {
                            $key = 'initial_evaluation';
                        } elseif (strpos($ft, 'goal') !== false || (($f['table'] ?? '') === 'goals')) {
                            $key = 'goals_form';
                        } elseif (strpos($ft, 'session') !== false) {
                            $key = 'session_report';
                        } elseif (strpos($ft, 'discharge') !== false) {
                            $key = 'discharge_report';
                        } else {
                            $key = 'other_documents';
                        }
                        $buckets[$key][] = $f;
                    }
                    $merged = [];
                    foreach ($order as $k) {
                        usort($buckets[$k], function($a, $b) {
                            $ta = isset($a['created_at']) ? strtotime($a['created_at']) : 0;
                            $tb = isset($b['created_at']) ? strtotime($b['created_at']) : 0;
                            return $tb <=> $ta;
                        });
                        $merged = array_merge($merged, $buckets[$k]);
                    }
                    $studentForms = $merged;
                ?>
                <div class="documentation-list-compact">
                    <ul>
                    <?php foreach ($studentForms as $form): ?>
                        <li class="doc-row">
                            <?php $title = $form['title'] ?? (!empty($form['form_type']) ? str_replace('_',' ',ucwords($form['form_type'])) : 'Document'); ?>
                            <span class="doc-title"><?php echo htmlspecialchars($title); ?></span>
                            <?php if (!empty($form['file_path'])): ?>
                                <a class="doc-download" href="/<?php echo ltrim($form['file_path'], '/'); ?>" target="_blank">Download</a>
                            <?php endif; ?>
                            <span class="doc-date muted"><?php echo !empty($form['created_at']) ? date('M j, Y', strtotime($form['created_at'])) : ''; ?></span>
                        </li>
                    <?php endforeach; ?>
                    </ul>
                </div>
                <?php else: ?>
                <p class="no-content">No documentation available for this student yet.</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Action Buttons -->
        <div class="profile-actions">
            <a class="btn btn-outline" href="?view=students" onclick="if(typeof editStudentProfile === 'function'){ event.preventDefault(); editStudentProfile(<?php echo $profileStudent['id']; ?>); } else { /* fallback: go back to students list */ }">Edit Profile</a>
        </div>
    </div>
</div>

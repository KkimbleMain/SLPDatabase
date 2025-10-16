<?php
// templates/student_report.php
// Expects variables in scope: $student (array), $skills (array), $student_goals (array), $chart_images (assoc array skill_id => data URI)
if (!isset($student)) $student = [];
if (!isset($skills)) $skills = [];
if (!isset($student_goals)) $student_goals = [];
if (!isset($chart_images)) $chart_images = [];
// If chart images weren't provided by the exporter, try to discover any saved chart image files
// under dev/exports/report_images/ that include the skill id in the filename and use them as fallbacks.
if (empty($chart_images)) {
    $chart_images = [];
    $imagesDirRel = 'dev/exports/report_images';
    $imagesDir = __DIR__ . '/../' . $imagesDirRel;
    if (is_dir($imagesDir)) {
        $files = scandir($imagesDir);
        if ($files !== false) {
                foreach ($files as $f) {
                if ($f === '.' || $f === '..') continue;
                $full = $imagesDir . DIRECTORY_SEPARATOR . $f;
                if (!is_file($full)) continue;
                // store by filename and also parse any numeric ids appearing in the filename
                // Prefer absolute path from web root so iframe/src and wkhtmltopdf resolve correctly
                $chart_images[$f] = '/' . trim($imagesDirRel, '/\\') . '/' . $f;
            }
        }
    }
}
if (!isset($report_title)) $report_title = null;

// Chart score mode for SVG rendering:
// - 'raw_numeric_then_parse' (default): use raw numeric values as-is; if non-numeric, fall back to parsing.
// - 'raw_only': only plot when score/target are numeric; skip otherwise (no parsing).
$chart_score_mode = $chart_score_mode ?? 'raw_numeric_then_parse';

// Defensive: if the controller didn't provide per-form arrays, attempt to load them here so the template
// can produce a comprehensive bundled report. This keeps the template usable both from generate_student_report
// and save_student_report_snapshot handlers which may or may not populate every variable.
$initial_evals = $initial_evals ?? null;
$session_reports = $session_reports ?? null;
$discharge_reports = $discharge_reports ?? null;
$other_documents = $other_documents ?? null;
if (($initial_evals === null || $session_reports === null || $discharge_reports === null || $other_documents === null) && file_exists(__DIR__ . '/../includes/sqlite.php')) {
    try {
        require_once __DIR__ . '/../includes/sqlite.php';
        $pdo = get_db();
    if ($initial_evals === null) {
            $initial_evals = [];
            try {
        // table name is 'initial_evaluations' in DB; fallback if schema varies
        $st = $pdo->prepare('SELECT * FROM initial_evaluations WHERE student_id = :sid ORDER BY created_at DESC');
                $st->execute([':sid' => $student['id'] ?? 0]);
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    if (!empty($r['content']) && ($json = json_decode($r['content'], true))) { $initial_evals[] = $json; continue; }
                    $initial_evals[] = ['db_id'=>$r['id'],'created_at'=>$r['created_at'] ?? null,'form_data'=>[
                        'evaluationDate'=>$r['Evaluation_Date'] ?? ($r['evaluation_date'] ?? null),
                        'referralReason'=>$r['Reason_Referral'] ?? ($r['reason_referral'] ?? null),
                        'backgroundInfo'=>$r['Background_Info'] ?? ($r['background_info'] ?? null),
                        'assessmentResults'=>$r['Assessment_Results'] ?? ($r['assessment_results'] ?? null),
                        'recommendations'=>$r['Recommendations'] ?? ($r['recommendations'] ?? null)
                    ]];
                }
            } catch (Throwable $e) { $initial_evals = $initial_evals ?? []; }
        }
        if ($session_reports === null) {
            $session_reports = [];
            try {
                $st = $pdo->prepare('SELECT * FROM session_reports WHERE student_id = :sid ORDER BY created_at DESC');
                $st->execute([':sid' => $student['id'] ?? 0]);
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    if (!empty($r['content']) && ($json = json_decode($r['content'], true))) { $session_reports[] = $json; continue; }
                    $session_reports[] = ['db_id'=>$r['id'],'created_at'=>$r['created_at'] ?? null,'form_data'=>[
                        'sessionDate'=>$r['session_date'] ?? ($r['sessionDate'] ?? null),
                        'sessionDuration'=>$r['duration_minutes'] ?? ($r['durationMinutes'] ?? null),
                        'sessionType'=>$r['session_type'] ?? ($r['sessionType'] ?? null),
                        'objectivesTargeted'=>$r['objectives_targeted'] ?? ($r['objectivesTargeted'] ?? null),
                        'activitiesUsed'=>$r['activities_used'] ?? ($r['activitiesUsed'] ?? null),
                        'studentResponse'=>$r['student_response'] ?? ($r['studentResponse'] ?? null),
                        'nextSessionPlan'=>$r['next_session_plan'] ?? ($r['nextSessionPlan'] ?? null)
                    ]];
                }
            } catch (Throwable $e) { $session_reports = $session_reports ?? []; }
        }
    if ($discharge_reports === null) {
            $discharge_reports = [];
            try {
        // table name is typically 'discharge_reports'
        $st = $pdo->prepare('SELECT * FROM discharge_reports WHERE student_id = :sid ORDER BY created_at DESC');
                $st->execute([':sid' => $student['id'] ?? 0]);
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    if (!empty($r['content']) && ($json = json_decode($r['content'], true))) { $discharge_reports[] = $json; continue; }
                    $discharge_reports[] = ['db_id'=>$r['id'],'created_at'=>$r['created_at'] ?? null,'form_data'=>[
                        'summaryOfServices'=>$r['Summary_of_Services_Provided'] ?? ($r['Summary_of_services'] ?? ($r['summary_of_services'] ?? null)),
                        'goalsAchieved'=>$r['Goals_Achieved'] ?? ($r['Goals_achieved'] ?? ($r['goals_achieved'] ?? null)),
                        'reasonForDischarge'=>$r['Reason_for_Discharge'] ?? ($r['Reason_for_discharge'] ?? ($r['reason_for_discharge'] ?? null)),
                        'followUpRecommendations'=>$r['Follow_up_Recommendations'] ?? ($r['FollowUp_Recommendations'] ?? ($r['follow_up_recommendations'] ?? null))
                    ]];
                }
            } catch (Throwable $e) { $discharge_reports = $discharge_reports ?? []; }
        }
        if ($other_documents === null) {
            $other_documents = [];
            try {
                $st = $pdo->prepare('SELECT * FROM other_documents WHERE student_id = :sid ORDER BY created_at DESC');
                $st->execute([':sid' => $student['id'] ?? 0]);
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    if (!empty($r['content']) && ($json = json_decode($r['content'], true))) { $other_documents[] = $json; continue; }
                    $other_documents[] = ['db_id'=>$r['id'],'created_at'=>$r['created_at'] ?? null,'title'=>$r['title'] ?? null,'file_path'=>$r['file_path'] ?? null,'form_type'=>$r['form_type'] ?? null];
                }
            } catch (Throwable $e) { $other_documents = $other_documents ?? []; }
        }
    } catch (Throwable $ee) {
        // ignore DB failures; templates will render what is available
    }
}

// Helper to format date-only outputs (returns empty string if input missing)
function _fmt_date($v) {
    if (empty($v)) return '';
    $t = strtotime($v);
    if ($t === false) return htmlspecialchars($v);
    return date('Y-m-d', $t);
}

// Format a datetime string into local timezone 'Y-m-d H:i' for readable history entries
function _fmt_datetime_local($v) {
    if (empty($v)) return '';
    $t = strtotime($v);
    if ($t === false) return htmlspecialchars($v);
    // Use date() which formats in the server's timezone. If stored timestamps are UTC,
    // this will produce a local-looking string; that's acceptable for exported reports.
    return date('Y-m-d H:i', $t);
}

// Normalize percent-like values into a canonical display string like "50%" or "-"
function _fmt_percent($v) {
    if ($v === null || $v === '') return '-';
    // If numeric and between 0 and 1, treat as fraction and convert to percent
    if (is_numeric($v)) {
        $n = (float)$v;
        if ($n > 0 && $n <= 1) {
            $n = $n * 100.0;
        }
        return rtrim(rtrim(number_format($n, ($n == floor($n) ? 0 : 1)), '0'), '.') . '%';
    }
    // Strings like "50%" or "0.5"
    $s = trim((string)$v);
    if ($s === '') return '-';
    if (substr($s, -1) === '%') {
        $s = trim(substr($s, 0, -1));
        if (is_numeric($s)) {
            $n = (float)$s;
            return rtrim(rtrim(number_format($n, ($n == floor($n) ? 0 : 1)), '0'), '.') . '%';
        }
    }
    // fallback: try parse as float and assume 0..1 or 0..100
    if (is_numeric($s)) {
        $n = (float)$s;
        if ($n > 0 && $n <= 1) $n = $n * 100.0;
        return rtrim(rtrim(number_format($n, ($n == floor($n) ? 0 : 1)), '0'), '.') . '%';
    }
    // unknown format
    return htmlspecialchars($v);
}

// Parse a percent-like value into a float 0..100 or null if not parseable
function _parse_percent_value($v) {
    if ($v === null || $v === '') return null;
    if (is_numeric($v)) {
        $n = (float)$v;
        if ($n > 0 && $n <= 1) $n *= 100.0; // treat 0..1 as fraction
        // clamp to a sane range
        if ($n < 0) $n = 0; if ($n > 100) $n = 100;
        return $n;
    }
    $s = trim((string)$v);
    if ($s === '') return null;
    if (substr($s, -1) === '%') $s = substr($s, 0, -1);
    if (!is_numeric($s)) return null;
    $n = (float)$s;
    if ($n > 0 && $n <= 1) $n *= 100.0;
    if ($n < 0) $n = 0; if ($n > 100) $n = 100;
    return $n;
}

// Display a value exactly as stored (for history table), with safe HTML escaping and a friendly dash for empty
function _display_raw($v) {
    if ($v === null) return '-';
    $s = (string)$v;
    if (trim($s) === '') return '-';
    return htmlspecialchars($s, ENT_QUOTES);
}

// Render a simple inline SVG line chart for a skill's updates (scores over time) with a dashed target line.
// Returns SVG markup (string) or empty string if no plottable points.
function _render_skill_svg_chart(array $updates) {
    // Build arrays of (x label, score 0..100) using created_at/date_recorded ordering from caller
    $labels = [];
    $values = [];
    global $chart_score_mode;
    foreach ($updates as $u) {
        $ts = $u['date_recorded'] ?? ($u['created_at'] ?? null);
        $labels[] = _fmt_date($ts);
        $raw = $u['score'] ?? null;
        if ($chart_score_mode === 'raw_only') {
            $v = is_numeric($raw) ? (float)$raw : null;
        } else {
            $v = is_numeric($raw) ? (float)$raw : _parse_percent_value($raw);
        }
        $values[] = $v;
    }
    // filter out null values but preserve indices for label alignment
    $hasPoint = false;
    foreach ($values as $v) { if ($v !== null) { $hasPoint = true; break; } }
    if (!$hasPoint) return '';

    // Chart geometry
    $W = 600; $H = 240; // total SVG size
    $padL = 40; $padR = 12; $padT = 12; $padB = 28; // padding for axes/labels
    $chartW = $W - $padL - $padR; $chartH = $H - $padT - $padB;

    // Build X positions evenly spaced (including single-point case)
    $count = max(1, count($values));
    $xPositions = [];
    if ($count === 1) {
        $xPositions[] = $padL + $chartW; // single point at the right edge (most recent)
    } else {
        for ($i = 0; $i < $count; $i++) {
            $xPositions[] = $padL + ($chartW * $i / ($count - 1));
        }
    }

    // Y scaling: 0..100 => bottom..top
    $yFor = function($pct) use ($padT, $chartH) {
        $p = max(0, min(100, (float)$pct));
        // invert: 0 -> bottom, 100 -> top
        return $padT + ($chartH * (1.0 - ($p / 100.0)));
    };

    // Build polyline points and area polygon points
    $poly = [];
    $area = [];
    for ($i = 0; $i < $count; $i++) {
        $v = $values[$i];
        if ($v === null) continue; // skip missing points
        $x = $xPositions[$i];
        $y = $yFor($v);
        $poly[] = $x . ',' . $y;
        $area[] = $x . ',' . $y;
    }
    if (empty($poly)) return '';
    // close area to bottom
    $firstX = explode(',', $poly[0])[0];
    $lastX  = explode(',', $poly[count($poly)-1])[0];
    $areaPoly = $firstX . ',' . ($padT + $chartH) . ' ' . implode(' ', $area) . ' ' . $lastX . ',' . ($padT + $chartH);

    // Target line: use the most recent non-null target_score
    $target = null;
    for ($i = count($updates)-1; $i >= 0; $i--) {
        $rawT = $updates[$i]['target_score'] ?? null;
        if ($chart_score_mode === 'raw_only') {
            $tv = is_numeric($rawT) ? (float)$rawT : null;
        } else {
            $tv = is_numeric($rawT) ? (float)$rawT : _parse_percent_value($rawT);
        }
        if ($tv !== null) { $target = $tv; break; }
    }

    // Gridlines (y every 20%)
    $grid = '';
    for ($g = 0; $g <= 100; $g += 20) {
        $yg = $yFor($g);
        $grid .= '<line x1="' . $padL . '" y1="' . $yg . '" x2="' . ($padL + $chartW) . '" y2="' . $yg . '" stroke="#eee" stroke-width="1" />';
        $grid .= '<text x="' . ($padL - 6) . '" y="' . ($yg + 4) . '" text-anchor="end" font-size="10" fill="#666">' . $g . '%</text>';
    }

    // X-axis labels: first and last date only to avoid clutter
    $xlab = '';
    if (!empty($labels)) {
        $firstLab = htmlspecialchars($labels[0] ?? '', ENT_QUOTES);
        $lastLab  = htmlspecialchars($labels[count($labels)-1] ?? '', ENT_QUOTES);
        $xlab .= '<text x="' . $padL . '" y="' . ($padT + $chartH + 18) . '" text-anchor="start" font-size="10" fill="#666">' . $firstLab . '</text>';
        $xlab .= '<text x="' . ($padL + $chartW) . '" y="' . ($padT + $chartH + 18) . '" text-anchor="end" font-size="10" fill="#666">' . $lastLab . '</text>';
    }

    // Build SVG
    $svg  = '<svg xmlns="http://www.w3.org/2000/svg" width="' . $W . '" height="' . $H . '" role="img" aria-label="Skill score over time">';
    $svg .= '<rect x="0" y="0" width="' . $W . '" height="' . $H . '" fill="#fff" />';
    $svg .= $grid;
    // target line
    if ($target !== null) {
        $yt = $yFor($target);
        $svg .= '<line x1="' . $padL . '" y1="' . $yt . '" x2="' . ($padL + $chartW) . '" y2="' . $yt . '" stroke="rgba(220,38,38,0.9)" stroke-width="2" stroke-dasharray="6 4" />';
        $svg .= '<text x="' . ($padL + $chartW - 6) . '" y="' . ($yt - 6) . '" text-anchor="end" font-size="11" fill="rgba(220,38,38,0.9)">Target ' . (int)round($target) . '%</text>';
    }
    // area under line
    $svg .= '<polygon points="' . $areaPoly . '" fill="rgba(59,130,246,0.10)" />';
    // line
    $svg .= '<polyline points="' . implode(' ', $poly) . '" fill="none" stroke="#3b82f6" stroke-width="2" />';
    // points
    foreach ($poly as $pt) {
        list($px, $py) = array_map('floatval', explode(',', $pt));
        $svg .= '<circle cx="' . $px . '" cy="' . $py . '" r="3" fill="#3b82f6" />';
    }
    // axes (left/bottom)
    $svg .= '<line x1="' . $padL . '" y1="' . $padT . '" x2="' . $padL . '" y2="' . ($padT + $chartH) . '" stroke="#ccc" stroke-width="1" />';
    $svg .= '<line x1="' . $padL . '" y1="' . ($padT + $chartH) . '" x2="' . ($padL + $chartW) . '" y2="' . ($padT + $chartH) . '" stroke="#ccc" stroke-width="1" />';
    $svg .= $xlab;
    $svg .= '</svg>';
    return $svg;
}
?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Full Student Report Summary - <?php echo htmlspecialchars(($student['first_name'] ?? '') . ' ' . ($student['last_name'] ?? '')); ?></title>
    <style>
        body { font-family: Arial, Helvetica, sans-serif; color: #111; margin: 24px; }
        h1,h2,h3 { margin: 0 0 8px 0; }
        .header { display:flex; justify-content:space-between; align-items:center; margin-bottom:12px; }
        .student-info { border: 1px solid #ddd; padding:12px; border-radius:6px; background:#fafafa; }
        .section { margin-top:18px; }
        .goals-list, .skills-list { display:block; }
        .goal-item, .skill-item { border-bottom:1px solid #eee; padding:8px 0; }
        .chart-img { max-width:100%; height:auto; border:1px solid #eee; background:#fff; padding:6px; border-radius:4px; }
        .meta { color:#666; font-size:0.9em; }
        .page-break { page-break-after: always; }
    </style>
</head>
<body>
    <div class="header">
        <div>
            <h1>Full Student Report Summary</h1>
            <div class="meta">Generated: <?php echo date('F j, Y'); ?></div>
        </div>
        <div>
            <strong><?php echo htmlspecialchars(($student['first_name'] ?? '') . ' ' . ($student['last_name'] ?? '')); ?></strong>
            <div class="meta">ID: <?php echo htmlspecialchars($student['student_id'] ?? $student['id'] ?? ''); ?></div>
            <div class="meta">Grade: <?php echo htmlspecialchars($student['grade'] ?? 'N/A'); ?></div>
        </div>
    </div>

    <div class="section student-info">
        <h2>Student Information</h2>
        <div><?php echo htmlspecialchars($student['first_name'] ?? '') . ' ' . htmlspecialchars($student['last_name'] ?? ''); ?></div>
        <div class="meta">DOB: <?php echo htmlspecialchars($student['date_of_birth'] ?? ''); ?></div>
        <div class="meta">Primary language: <?php echo htmlspecialchars($student['primary_language'] ?? ''); ?></div>
    </div>

    <div class="section">
        <h2>Goals</h2>
        <div class="goals-list">
            <?php if (!empty($student_goals)): foreach ($student_goals as $g): ?>
                <div class="goal-item">
                    <strong><?php echo htmlspecialchars($g['title'] ?? 'Goal'); ?></strong>
                    <?php
                        // Goals may come from different schemas: older versions used a 'description' column,
                        // newer schemas split fields into Long_Term_Goals, Short_Term_Goals, Intervention_Strategies, Measurement_Criteria,
                        // and exporter snapshots may include a 'form_data' array. Build a friendly display from whatever is available.
                        $goalParts = [];
                        // Prefer structured form_data if provided
                        $fd = [];
                        if (isset($g['form_data']) && is_array($g['form_data'])) {
                            $fd = $g['form_data'];
                        }

                        // Helper to push labeled part if present
                        $pushIf = function($label, $val) use (&$goalParts) {
                            if ($val === null || $val === '') return;
                            $goalParts[] = '<div><strong>' . htmlspecialchars($label) . ':</strong> ' . nl2br(htmlspecialchars($val)) . '</div>';
                        };

                        // If a legacy description exists, use it first
                        if (!empty($g['description'])) {
                            $goalParts[] = '<div>' . nl2br(htmlspecialchars($g['description'])) . '</div>';
                        } else {
                            // form_data keys (from the save flow)
                            $pushIf('Long Term Goals', $fd['long_term'] ?? $fd['Long_Term_Goals'] ?? $fd['longTerm'] ?? $fd['longTermGoals'] ?? null);
                            $pushIf('Short Term Objectives', $fd['short_terms'] ?? $fd['Short_Term_Goals'] ?? $fd['shortTerms'] ?? null);
                            $pushIf('Intervention Strategies', $fd['intervention'] ?? $fd['Intervention_Strategies'] ?? null);
                            $pushIf('Measurement Criteria', $fd['measurement'] ?? $fd['Measurement_Criteria'] ?? null);

                            // direct DB columns (if not wrapped in form_data)
                            $pushIf('Long Term Goals', $g['Long_Term_Goals'] ?? $g['long_term_goals'] ?? $g['long_term'] ?? null);
                            $pushIf('Short Term Objectives', $g['Short_Term_Goals'] ?? $g['short_term_goals'] ?? $g['short_terms'] ?? null);
                            $pushIf('Intervention Strategies', $g['Intervention_Strategies'] ?? $g['intervention'] ?? null);
                            $pushIf('Measurement Criteria', $g['Measurement_Criteria'] ?? $g['measurement'] ?? null);
                        }

                        if (!empty($goalParts)) {
                            echo implode("\n", $goalParts);
                        } else {
                            echo '<div class="meta">(No goal details available)</div>';
                        }
                    ?>
                    <div class="meta">Created: <?php echo htmlspecialchars(_fmt_datetime_local($g['created_at'] ?? '')); ?><?php if (!empty($g['status'])): ?> • Status: <?php echo htmlspecialchars($g['status']); ?><?php endif; ?></div>
                </div>
            <?php endforeach; else: ?>
                <div class="goal-item">No goals recorded.</div>
            <?php endif; ?>
        </div>
    </div>

    <div class="section">
        <h2>Initial Evaluation</h2>
        <?php if (!empty($initial_evals)): ?>
            <?php foreach ($initial_evals as $ie): $fd = $ie['form_data'] ?? ($ie['form_data'] ?? $ie); ?>
                <div class="goal-item">
                    <strong>Initial Evaluation</strong>
                    <div><strong>Evaluation Date:</strong> <?php echo htmlspecialchars($fd['evaluationDate'] ?? _fmt_datetime_local($ie['created_at'] ?? '')); ?></div>
                    <div><strong>Reason for Referral:</strong> <?php echo nl2br(htmlspecialchars($fd['referralReason'] ?? '')); ?></div>
                    <div><strong>Background Information:</strong> <?php echo nl2br(htmlspecialchars($fd['backgroundInfo'] ?? '')); ?></div>
                    <div><strong>Assessment Results:</strong> <?php echo nl2br(htmlspecialchars($fd['assessmentResults'] ?? '')); ?></div>
                    <div><strong>Recommendations:</strong> <?php echo nl2br(htmlspecialchars($fd['recommendations'] ?? '')); ?></div>
                    <div class="meta">Created: <?php echo htmlspecialchars(_fmt_datetime_local($ie['created_at'] ?? '')); ?></div>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="meta">No initial evaluations found.</div>
        <?php endif; ?>
    </div>

    <div class="section">
        <h2>Session Reports</h2>
        <?php if (!empty($session_reports)): ?>
            <?php foreach ($session_reports as $sr): $fd = $sr['form_data'] ?? $sr; ?>
                <div class="goal-item">
                    <strong><?php echo htmlspecialchars($sr['title'] ?? 'Session Report'); ?></strong>
                    <div><strong>Date:</strong> <?php echo htmlspecialchars($fd['sessionDate'] ?? _fmt_datetime_local($sr['created_at'] ?? '')); ?></div>
                    <div><strong>Duration (minutes):</strong> <?php echo htmlspecialchars($fd['sessionDuration'] ?? ''); ?></div>
                    <div><strong>Session Type:</strong> <?php echo htmlspecialchars($fd['sessionType'] ?? ''); ?></div>
                    <div><strong>Objectives Targeted:</strong> <?php echo nl2br(htmlspecialchars($fd['objectivesTargeted'] ?? '')); ?></div>
                    <div><strong>Activities Used:</strong> <?php echo nl2br(htmlspecialchars($fd['activitiesUsed'] ?? '')); ?></div>
                    <div><strong>Student Response:</strong> <?php echo nl2br(htmlspecialchars($fd['studentResponse'] ?? '')); ?></div>
                    <div><strong>Plan for Next Session:</strong> <?php echo nl2br(htmlspecialchars($fd['nextSessionPlan'] ?? '')); ?></div>
                    <div class="meta">Created: <?php echo htmlspecialchars(_fmt_datetime_local($sr['created_at'] ?? '')); ?></div>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="meta">No session reports found.</div>
        <?php endif; ?>
    </div>

    <div class="section">
        <h2>Skills & Progress</h2>
        <div class="skills-list">
            <?php if (!empty($skills)): foreach ($skills as $s): ?>
                    <div class="skill-item">
                        <h3><?php echo htmlspecialchars($s['skill_label'] ?? 'Skill'); ?> <span class="meta"><?php echo htmlspecialchars($s['category'] ?? ''); ?></span></h3>
                        <?php
                            $cid = $s['id'] ?? null;
                            // Prefer server-rendered SVG built directly from $skill_updates so the graph matches the history exactly
                            $svg_html = '';
                            if ($cid && isset($skill_updates) && isset($skill_updates[$cid]) && is_array($skill_updates[$cid]) && !empty($skill_updates[$cid])) {
                                $svg_html = _render_skill_svg_chart($skill_updates[$cid]);
                            }
                            // Fallback to provided chart image (from client capture) when no updates or SVG couldn't render
                            $chart_src = null;
                            if ($svg_html === '' && $cid && is_array($chart_images) && !empty($chart_images)) {
                                if (array_key_exists($cid, $chart_images) && $chart_images[$cid]) {
                                    $chart_src = $chart_images[$cid];
                                } elseif (array_key_exists('' . $cid, $chart_images) && $chart_images['' . $cid]) {
                                    $chart_src = $chart_images['' . $cid];
                                } elseif (array_key_exists('skill_' . $cid, $chart_images) && $chart_images['skill_' . $cid]) {
                                    $chart_src = $chart_images['skill_' . $cid];
                                } else {
                                    foreach ($chart_images as $k => $v) { if ($v && (string)$k === (string)$cid) { $chart_src = $v; break; } }
                                }
                            }
                        ?>
                        <?php if (!empty($svg_html)): ?>
                            <div>
                                <?php echo $svg_html; ?>
                                <div class="meta" style="margin-top:6px;">Y-axis scale: 0% — 100% (increments: 20%)</div>
                            </div>
                        <?php elseif ($cid && !empty($chart_src)): ?>
                            <?php if (is_string($chart_src) && stripos($chart_src, 'data:') !== 0) { if (strpos($chart_src, '/') !== 0) { $chart_src = '/' . ltrim($chart_src, '/\\'); } } ?>
                            <div>
                                <img class="chart-img" src="<?php echo htmlspecialchars($chart_src); ?>" alt="Chart for <?php echo htmlspecialchars($s['skill_label'] ?? ''); ?>" />
                                <div class="meta" style="margin-top:6px;">Y-axis scale: 0% — 100% (increments: 20%)</div>
                            </div>
                        <?php else: ?>
                            <div class="meta">No chart available for this skill.</div>
                        <?php endif; ?>

                        <?php // render history table for this skill if available
                            $updates = [];
                            if (isset($skill_updates) && $cid && isset($skill_updates[$cid])) $updates = $skill_updates[$cid];
                        ?>
                        <div class="skill-history">
                            <h4>History</h4>
                            <?php if (!empty($updates)): ?>
                                <table class="history-table" cellpadding="4" cellspacing="0" style="width:100%; border-collapse:collapse;">
                                    <thead>
                                        <tr style="background:#f5f5f5;">
                                            <th style="text-align:left; width:18%;">Date</th>
                                            <th style="text-align:left; width:16%;">Score</th>
                                            <th style="text-align:left; width:16%;">Target</th>
                                            <th style="text-align:left;">Notes</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($updates as $u): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars(_fmt_datetime_local($u['date_recorded'] ?? $u['created_at'] ?? '')); ?></td>
                                                <td><?php echo _display_raw($u['score'] ?? null); ?></td>
                                                <td><?php echo _display_raw($u['target_score'] ?? null); ?></td>
                                                <td><?php echo nl2br(htmlspecialchars($u['notes'] ?? '')); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php else: ?>
                                <div class="meta">No history entries for this skill.</div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div style="margin-top:12px; margin-bottom:12px; border-top:1px solid #eee;"></div>
                <?php endforeach; else: ?>
                    <div class="skill-item">No skills found.</div>
                <?php endif; ?>
        </div>
    </div>

    <div class="section">
        <h2>Discharge Report</h2>
        <?php if (!empty($discharge_reports)): ?>
            <?php foreach ($discharge_reports as $dr): $fd = $dr['form_data'] ?? $dr; ?>
                <div class="goal-item">
                    <strong><?php echo htmlspecialchars($dr['title'] ?? 'Discharge Report'); ?></strong>
                    <div><strong>Summary of Services Provided:</strong> <?php echo nl2br(htmlspecialchars($fd['summaryOfServices'] ?? '')); ?></div>
                    <div><strong>Goals Achieved:</strong> <?php echo nl2br(htmlspecialchars($fd['goalsAchieved'] ?? '')); ?></div>
                    <div><strong>Reason for Discharge:</strong> <?php echo nl2br(htmlspecialchars($fd['reasonForDischarge'] ?? '')); ?></div>
                    <div><strong>Follow-up Recommendations:</strong> <?php echo nl2br(htmlspecialchars($fd['followUpRecommendations'] ?? '')); ?></div>
                    <div class="meta">Created: <?php echo htmlspecialchars(_fmt_datetime_local($dr['created_at'] ?? '')); ?></div>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="meta">No discharge reports found.</div>
        <?php endif; ?>
    </div>

    <div class="section">
        <h2>Other Documents</h2>
        <?php if (!empty($other_documents)): ?>
            <ul>
                <?php foreach ($other_documents as $od): ?>
                    <li>
                        <?php if (!empty($od['file_path'])): ?>
                            <?php $fp = '/' . ltrim($od['file_path'], '/'); $escfp = htmlspecialchars($fp); $title = htmlspecialchars($od['title'] ?? ($od['form_type'] ?? 'Document')); ?>
                            <a href="<?php echo $escfp; ?>" target="_blank"><?php echo $title; ?></a>
                        <?php else: ?>
                            <?php echo htmlspecialchars($od['title'] ?? ($od['form_type'] ?? 'Document')); ?>
                        <?php endif; ?>
                        <span class="meta"> — <?php echo htmlspecialchars(_fmt_datetime_local($od['created_at'] ?? '')); ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php else: ?>
            <div class="meta">No additional documents found.</div>
        <?php endif; ?>
    </div>

    <div class="meta">End of report</div>
</body>
</html>

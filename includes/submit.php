<?php
// Lightweight submit endpoint migrated to SQLite (uses includes/sqlite.php)

if (session_status() === PHP_SESSION_NONE) session_start();
@ini_set('display_errors', '0');
@ini_set('log_errors', '1');

set_error_handler(function($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) return false;
    throw new ErrorException($message, 0, $severity, $file, $line);
});

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/sqlite.php'; // must provide get_db()
// Activity tracker helper
require_once __DIR__ . '/activity_tracker.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid request', 'message' => 'Invalid request']);
    exit;
}

$action = $_POST['action'] ?? '';

try {
    $pdo = get_db();

    // helper: return array of column names for a table (empty array on error)
    $get_table_columns = function($table) use ($pdo) {
        try {
            $pi = $pdo->prepare("PRAGMA table_info('" . $table . "')");
            $pi->execute();
            return array_column($pi->fetchAll(PDO::FETCH_ASSOC), 'name');
        } catch (Throwable $e) { return []; }
    };

    // helper: insert into $table using only columns that exist there
    $safe_insert = function($table, array $data) use ($pdo, $get_table_columns) {
        $cols = $get_table_columns($table);
        if (empty($cols)) throw new Exception('Table not found: ' . $table);
        $use = array_values(array_intersect(array_keys($data), $cols));
        if (empty($use)) throw new Exception('No compatible columns to insert for ' . $table);
        $place = array_map(function($c){ return ':' . $c; }, $use);
        $sql = 'INSERT INTO ' . $table . ' (' . implode(', ', $use) . ') VALUES (' . implode(', ', $place) . ')';
        $stmt = $pdo->prepare($sql);
        $params = [];
        foreach ($use as $c) $params[':' . $c] = $data[$c];
        $stmt->execute($params);
        return (int)$pdo->lastInsertId();
    };

    // helper: update $table id using only columns that exist there
    $safe_update = function($table, $id, array $data) use ($pdo, $get_table_columns) {
        $cols = $get_table_columns($table);
        if (empty($cols)) throw new Exception('Table not found: ' . $table);
        $use = array_values(array_intersect(array_keys($data), $cols));
        if (empty($use)) throw new Exception('No compatible columns to update for ' . $table);
        $set = array_map(function($c){ return $c . ' = :' . $c; }, $use);
        $sql = 'UPDATE ' . $table . ' SET ' . implode(', ', $set) . ' WHERE id = :__id';
        $stmt = $pdo->prepare($sql);
        $params = [];
        foreach ($use as $c) $params[':' . $c] = $data[$c];
        $params[':__id'] = $id;
        $stmt->execute($params);
        return true;
    };

    switch ($action) {
        // Add student -> insert into students, create folder + initial_profile file, add documents entry
        case 'add_student': {
            $firstName = trim($_POST['first_name'] ?? '');
            $lastName  = trim($_POST['last_name'] ?? '');
            $firstInitial = $firstName !== '' ? strtoupper(substr($firstName,0,1)) : 'X';
            $lastInitial  = $lastName !== '' ? strtoupper(substr($lastName,0,1)) : 'X';

            // generate unique external student_id (e.g., JD1234)
            do {
                $studentIdCandidate = $firstInitial . $lastInitial . str_pad(mt_rand(0,9999), 4, '0', STR_PAD_LEFT);
                $stmt = $pdo->prepare('SELECT COUNT(*) FROM students WHERE student_id = :sid');
                $stmt->execute([':sid' => $studentIdCandidate]);
                $exists = (int)$stmt->fetchColumn() > 0;
            } while ($exists);

            // Persist additional profile fields that the UI collects
            $stmt = $pdo->prepare('INSERT INTO students (student_id, first_name, last_name, grade, date_of_birth, gender, primary_language, service_frequency, parent_contact, medical_info, created_at, updated_at) VALUES (:student_id, :first_name, :last_name, :grade, :date_of_birth, :gender, :primary_language, :service_frequency, :parent_contact, :medical_info, :created_at, :updated_at)');
            $stmt->execute([
                ':student_id' => $studentIdCandidate,
                ':first_name' => $firstName,
                ':last_name'  => $lastName,
                ':grade'      => $_POST['grade'] ?? '',
                ':date_of_birth' => $_POST['date_of_birth'] ?? '',
                // ':age' intentionally omitted (use date_of_birth and grade instead)
                ':gender'     => $_POST['gender'] ?? '',
                ':primary_language' => $_POST['primary_language'] ?? '',
                ':service_frequency' => $_POST['service_frequency'] ?? '',
                ':parent_contact' => $_POST['parent_contact'] ?? '',
                ':medical_info' => $_POST['medical_info'] ?? '',
                ':created_at' => date('c'),
                ':updated_at' => date('c'),
            ]);

            $id = (int)$pdo->lastInsertId();

            // create initial profile file and add documents row
            // Persist the profile document into the documents table in the DB (no filesystem writes)
            // No automatic initial profile document is created here. The app will not auto-generate
            // an initial_profile form when a student is added to avoid cluttering the documentation
            // with placeholder forms. If needed, users can create an initial profile manually.

            try { record_activity('student_created', $id, 'Student created: ' . trim($firstName . ' ' . $lastName), ['student_id' => $id, 'student_name' => trim($firstName . ' ' . $lastName)]); } catch (Throwable $e) {}
            echo json_encode(['success' => true, 'id' => $id]);
            break;
        }

        // Add goal -> insert into goals
        case 'add_goal': {
            $goal_text = trim($_POST['goal_text'] ?? $_POST['description'] ?? '');
            $goal_student = isset($_POST['student_id']) ? (int)$_POST['student_id'] : 0;
            // Server-side validation: require a student and non-empty goal text
            if (!$goal_student || $goal_text === '') {
                echo json_encode(['success'=>false,'error'=>'Student and goal text are required','message'=>'Student and goal text are required']);
                break;
            }
            // Attach the current user as therapist when available so dashboard can attribute goals
            $userId = $_SESSION['user_id'] ?? null;
            $stmt = $pdo->prepare('INSERT INTO goals (student_id, title, description, status, therapist_id, created_at) VALUES (:student_id, :title, :description, :status, :therapist_id, :created_at)');
            $stmt->execute([
                ':student_id' => $goal_student,
                ':title' => $_POST['goal_area'] ?? '',
                ':status' => 'active',
                ':therapist_id' => $userId,
                ':created_at' => date('c'),
            ]);
            $id = (int)$pdo->lastInsertId();
            // Record activity for dashboard
            try { record_activity('goal_created', (int)($_POST['student_id'] ?? 0), 'Goal created', ['goal_id' => $id]); } catch (Throwable $e) {}
            echo json_encode(['success'=>true,'id'=>$id]);
            break;
        }

        // Add progress update -> insert into progress_updates
        case 'add_progress': {
            $note = trim($_POST['notes'] ?? ($_POST['text'] ?? ''));
            $progress_student = isset($_POST['student_id']) ? (int)$_POST['student_id'] : 0;
            // Server-side validation: require student and non-empty note
            if (!$progress_student || $note === '') {
                echo json_encode(['success'=>false,'error'=>'Student and progress note are required','message'=>'Student and progress note are required']);
                break;
            }
            $stmt = $pdo->prepare('INSERT INTO progress_updates (student_id, note, created_at) VALUES (:student_id, :note, :created_at)');
            $stmt->execute([
                ':student_id' => $progress_student,
                ':note' => $note,
                ':created_at' => date('c'),
            ]);
            $id = (int)$pdo->lastInsertId();
            try { record_activity('progress_update', (int)($_POST['student_id'] ?? 0), 'Progress update added', ['progress_id' => $id]); } catch (Throwable $e) {}
            echo json_encode(['success'=>true,'id'=>$id]);
            break;
        }

        // Generate a full student progress report (PDF if wkhtmltopdf available, else HTML snapshot)
        case 'generate_student_report': {
            $studentId = isset($_POST['student_id']) ? (int)$_POST['student_id'] : 0;
            if (!$studentId) { echo json_encode(['success'=>false,'error'=>'Student ID required','message'=>'Student ID required']); break; }
            try {
                $stmt = $pdo->prepare('SELECT * FROM students WHERE id = :id LIMIT 1'); $stmt->execute([':id'=>$studentId]); $student = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$student) { echo json_encode(['success'=>false,'error'=>'Student not found']); break; }

                $skills = [];
                try { $ps = $pdo->prepare('SELECT * FROM progress_skills WHERE student_id = :sid ORDER BY id'); $ps->execute([':sid'=>$studentId]); $skills = $ps->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) {}
                $student_goals = [];
                try { $gq = $pdo->prepare('SELECT * FROM goals WHERE student_id = :sid ORDER BY created_at DESC'); $gq->execute([':sid'=>$studentId]); $student_goals = $gq->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) {}
                $skill_updates = [];
                try {
                    // By default include all progress_updates (so the generated report reflects the
                    // latest DB state visible on the progress page). Only apply a cutoff from
                    // progress_reports when the client explicitly requests a snapshot using
                    // either `report_id` (specific report) or `use_report_cutoff` (boolean-like).
                    $report_cutoff = null;
                    $applyCutoff = false;
                    // If client asked for a specific report id, use that report's created_at
                    if (!empty($_POST['report_id'])) {
                        $applyCutoff = true;
                        try {
                            $rid = $_POST['report_id'];
                            $pi = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name='progress_reports'"); $pi->execute();
                            if ($pi->fetchColumn()) {
                                $prt = $pdo->prepare('SELECT created_at FROM progress_reports WHERE id = :id OR rowid = :id LIMIT 1');
                                $prt->execute([':id' => $rid]);
                                $prtRow = $prt->fetch(PDO::FETCH_ASSOC);
                                if ($prtRow && !empty($prtRow['created_at'])) $report_cutoff = $prtRow['created_at'];
                            }
                        } catch (Throwable $_e) { /* ignore */ }
                    } elseif (!empty($_POST['use_report_cutoff'])) {
                        // use latest progress_reports row as cutoff when explicitly requested
                        $applyCutoff = true;
                        try {
                            $pi = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name='progress_reports'"); $pi->execute();
                            if ($pi->fetchColumn()) {
                                $prt = $pdo->prepare('SELECT created_at FROM progress_reports WHERE student_id = :sid ORDER BY created_at DESC LIMIT 1');
                                $prt->execute([':sid' => $studentId]);
                                $prtRow = $prt->fetch(PDO::FETCH_ASSOC);
                                if ($prtRow && !empty($prtRow['created_at'])) $report_cutoff = $prtRow['created_at'];
                            }
                        } catch (Throwable $_e) { /* ignore */ }
                    }

                    if (!empty($skills)) {
                        foreach (array_column($skills,'id') as $sid) {
                            if ($applyCutoff && $report_cutoff) {
                                $pu = $pdo->prepare('SELECT * FROM progress_updates WHERE skill_id = :sid AND (created_at IS NULL OR created_at <= :cutoff) ORDER BY created_at');
                                $pu->execute([':sid' => $sid, ':cutoff' => $report_cutoff]);
                            } else {
                                $pu = $pdo->prepare('SELECT * FROM progress_updates WHERE skill_id = :sid ORDER BY created_at');
                                $pu->execute([':sid' => $sid]);
                            }
                            $skill_updates[$sid] = $pu->fetchAll(PDO::FETCH_ASSOC);
                        }
                    }
                } catch (Throwable $e) {}

                // Prepare export directory
                $outDir = __DIR__ . '/../dev/exports'; if (!is_dir($outDir)) @mkdir($outDir,0755,true);
                $imagesDir = $outDir . '/report_images'; if (!is_dir($imagesDir)) @mkdir($imagesDir,0755,true);

                // Collect chart images from POST JSON 'chart_images' (data URLs) and uploaded files.
                $chart_images = [];
                if (!empty($_POST['chart_images'])) {
                    $decoded = json_decode($_POST['chart_images'], true);
                    if (is_array($decoded)) {
                        foreach ($decoded as $cid => $dataUrl) {
                            if (!is_string($dataUrl) || stripos($dataUrl, 'data:') !== 0) continue;
                            try {
                                // Persist a copy on disk for auditing/exports but ALSO keep the original data URI
                                $ts = time(); $fn = 'chart_' . preg_replace('/[^A-Za-z0-9_\-]/','_', (string)$cid) . '_' . $ts . '.png';
                                $outPath = $imagesDir . DIRECTORY_SEPARATOR . $fn;
                                if (preg_match('/^data:(image\/[^;]+);base64,(.+)$/', $dataUrl, $m)) {
                                    $bin = base64_decode($m[2]);
                                    if ($bin !== false) file_put_contents($outPath, $bin);
                                }
                                // Keep the original data URI so the template embeds the image inline (works with wkhtmltopdf)
                                $chart_images[$cid] = $dataUrl;
                            } catch (Throwable $e) { }
                        }
                    }
                }
                if (!empty($_FILES) && is_array($_FILES)) {
                    foreach ($_FILES as $k => $f) {
                        if (!is_array($f) || empty($f['tmp_name'])) continue;
                        if (strpos($k, 'chart_image_') !== 0) continue;
                        $cid = substr($k, strlen('chart_image_'));
                        try {
                            $orig = $f['tmp_name'];
                            $ext = pathinfo($f['name'] ?? 'img.png', PATHINFO_EXTENSION) ?: 'png';
                            $fn = 'chart_' . preg_replace('/[^A-Za-z0-9_\-]/','_', (string)$cid) . '_' . time() . '.' . $ext;
                            $outPath = $imagesDir . DIRECTORY_SEPARATOR . $fn;
                            if (is_uploaded_file($orig)) move_uploaded_file($orig, $outPath);
                            else @copy($orig, $outPath);
                            // Read the stored file and create a data URI so the template embeds inline images
                            $mime = 'image/png';
                            if (function_exists('finfo_open')) {
                                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                                $det = finfo_file($finfo, $outPath);
                                if ($det) $mime = $det;
                                finfo_close($finfo);
                            } elseif (function_exists('mime_content_type')) {
                                $det = mime_content_type($outPath);
                                if ($det) $mime = $det;
                            }
                            $bin = @file_get_contents($outPath);
                            if ($bin !== false) {
                                $b64 = base64_encode($bin);
                                $chart_images[$cid] = 'data:' . $mime . ';base64,' . $b64;
                            } else {
                                // fallback to file path (relative) if file couldn't be read
                                $chart_images[$cid] = 'dev/exports/report_images/' . $fn;
                            }
                        } catch (Throwable $e) { }
                    }
                }

                // Render HTML snapshot (template will use $chart_images variable if present)
                $timestamp = time(); $safeName = preg_replace('/[^A-Za-z0-9_\-]/','_',($student['student_id'] ?? 'student_'.$studentId));
                // attempt to load a report title from progress_reports (preferred) or student_reports
                $report_title = null;
                try {
                    $pi = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name='progress_reports'"); $pi->execute();
                    if ($pi->fetchColumn()) {
                        $pt = $pdo->prepare('SELECT title FROM progress_reports WHERE student_id = :sid ORDER BY created_at DESC LIMIT 1'); $pt->execute([':sid'=>$studentId]); $ptRow = $pt->fetch(PDO::FETCH_ASSOC); if ($ptRow && !empty($ptRow['title'])) $report_title = $ptRow['title'];
                    } else {
                        $pi2 = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name='student_reports'"); $pi2->execute(); if ($pi2->fetchColumn()) { $srq = $pdo->prepare('SELECT status FROM student_reports WHERE student_id = :sid LIMIT 1'); $srq->execute([':sid'=>$studentId]); $srw = $srq->fetch(PDO::FETCH_ASSOC); if ($srw && !empty($srw['status'])) $report_title = ucfirst($srw['status']) . ' Report'; }
                    }
                } catch (Throwable $e) { /* ignore */ }

                ob_start(); include __DIR__ . '/../templates/student_report.php'; $html = ob_get_clean();
                $htmlFile = $outDir . DIRECTORY_SEPARATOR . $safeName . '_report_' . $timestamp . '.html'; file_put_contents($htmlFile, $html);

                // Detect PDF engine and attempt PDF generation; fall back to snapshot
                $pdfFile = $outDir . DIRECTORY_SEPARATOR . $safeName . '_progress.pdf';

                // Build candidate paths/commands. Allow overriding via WKHTMLTOPDF_PATH in includes/config.php
                $wkCandidates = [];
                if (defined('WKHTMLTOPDF_PATH') && WKHTMLTOPDF_PATH) $wkCandidates[] = WKHTMLTOPDF_PATH;
                // prefer PATH lookup name
                $wkCandidates[] = 'wkhtmltopdf';
                // common platform-specific install locations
                if (DIRECTORY_SEPARATOR === '\\') {
                    $wkCandidates[] = 'C:\\Program Files\\wkhtmltopdf\\bin\\wkhtmltopdf.exe';
                    $wkCandidates[] = 'C:\\Program Files (x86)\\wkhtmltopdf\\bin\\wkhtmltopdf.exe';
                } else {
                    $wkCandidates[] = '/usr/local/bin/wkhtmltopdf';
                    $wkCandidates[] = '/usr/bin/wkhtmltopdf';
                }

                $wkBin = null;
                foreach ($wkCandidates as $cand) {
                    $cand = trim((string)$cand);
                    if ($cand === '') continue;
                    // If a full path exists and is executable, prefer it
                    if (file_exists($cand) && is_executable($cand)) { $wkBin = $cand; break; }
                    // Otherwise try running --version to verify availability (works for PATH names and some paths)
                    try { exec(escapeshellcmd($cand) . ' --version 2>&1', $o, $rc); if ($rc === 0 && !empty($o)) { $wkBin = $cand; break; } } catch (Throwable $e) {}
                }

                $pdfEngine = $wkBin ? 'wkhtmltopdf' : null;

                if ($pdfEngine === null) {
                    $rel = 'dev/exports/' . basename($htmlFile);
                    $pdo->exec("CREATE TABLE IF NOT EXISTS student_reports (student_id INTEGER PRIMARY KEY, path TEXT, created_at TEXT, updated_at TEXT, created_by INTEGER, status TEXT)");
                    $nowc = date('c'); $newStatus = 'snapshot';
                    $stmtUp = $pdo->prepare('INSERT INTO student_reports (student_id, path, created_at, updated_at, created_by, status) VALUES (:student_id, :path, :created_at, :updated_at, :created_by, :status) ON CONFLICT(student_id) DO UPDATE SET path = :path_up, updated_at = :updated_at_up, created_by = :created_by_up, status = :status_up');
                    $stmtUp->execute([':student_id'=>$studentId,':path'=>$rel,':created_at'=>$nowc,':updated_at'=>$nowc,':created_by'=>$_SESSION['user_id'] ?? null,':status'=>$newStatus,':path_up'=>$rel,':updated_at_up'=>$nowc,':created_by_up'=>$_SESSION['user_id'] ?? null,':status_up'=>$newStatus]);
                    echo json_encode(['success'=>true,'path'=>$rel,'filename'=>basename($htmlFile),'report'=>['student_id'=>$studentId,'path'=>$rel,'status'=>$newStatus]]);
                    break;
                }

                // Attempt to create PDF using detected wkhtmltopdf binary
                $bin = $wkBin ?: 'wkhtmltopdf';
                $cmd = escapeshellcmd($bin) . ' ' . escapeshellarg($htmlFile) . ' ' . escapeshellarg($pdfFile) . ' 2>&1'; exec($cmd,$out,$rc);
                if ($rc !== 0 || !file_exists($pdfFile)) { echo json_encode(['success'=>false,'error'=>'PDF generation failed']); break; }

                // Attempt to append any student PDFs (from other_documents.file_path) to the generated PDF
                try {
                    $appendPdfs = [];
                    $st = $pdo->prepare('SELECT file_path FROM other_documents WHERE student_id = :sid AND file_path IS NOT NULL');
                    $st->execute([':sid' => $studentId]);
                    $imageExtensions = ['png','jpg','jpeg','gif','webp','tiff','bmp'];
                    $imageToPdfCandidates = [];
                    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                        if (empty($r['file_path'])) continue;
                        $candidate = __DIR__ . '/../' . ltrim($r['file_path'], '/\\');
                        if (!file_exists($candidate)) continue;
                        $ext = strtolower(pathinfo($candidate, PATHINFO_EXTENSION));
                        if ($ext === 'pdf') {
                            $appendPdfs[] = $candidate;
                        } elseif (in_array($ext, $imageExtensions)) {
                            $imageToPdfCandidates[] = $candidate;
                        }
                    }

                    // If there are image attachments, try to convert them to PDFs for merging
                    if (!empty($imageToPdfCandidates)) {
                        // discover ImageMagick binary (magick preferred, then convert)
                        $imCandidates = [];
                        $imCandidates[] = 'magick';
                        $imCandidates[] = 'convert';
                        if (DIRECTORY_SEPARATOR === '\\') {
                            $imCandidates[] = 'C:\\Program Files\\ImageMagick-7.0.10-Q16\\magick.exe';
                            $imCandidates[] = 'C:\\Program Files\\ImageMagick-7.0.10-Q16\\convert.exe';
                        } else {
                            $imCandidates[] = '/usr/bin/magick';
                            $imCandidates[] = '/usr/bin/convert';
                            $imCandidates[] = '/usr/local/bin/magick';
                            $imCandidates[] = '/usr/local/bin/convert';
                        }
                        $imBin = null;
                        foreach ($imCandidates as $cand) {
                            $cand = trim((string)$cand);
                            if ($cand === '') continue;
                            if (file_exists($cand) && is_executable($cand)) { $imBin = $cand; break; }
                            try { exec(escapeshellcmd($cand) . ' -version 2>&1', $io, $irc); if ($irc === 0 && !empty($io)) { $imBin = $cand; break; } } catch (Throwable $e) {}
                        }

                        if ($imBin) {
                            foreach ($imageToPdfCandidates as $img) {
                                try {
                                    $outPdf = $outDir . DIRECTORY_SEPARATOR . $safeName . '_img_' . md5($img . time()) . '.pdf';
                                    // Use ImageMagick to convert image -> PDF
                                    $cmd = escapeshellcmd($imBin) . ' ' . escapeshellarg($img) . ' ' . escapeshellarg($outPdf) . ' 2>&1';
                                    exec($cmd, $imOut, $imRc);
                                    if ($imRc === 0 && file_exists($outPdf)) {
                                        $appendPdfs[] = $outPdf;
                                    } else {
                                        try { @file_put_contents(__DIR__ . '/../dev/logs/pdf_merge_errors.log', date('c') . " - ImageMagick conversion failed for {$img}: " . implode("\n", (array)$imOut) . "\n", FILE_APPEND | LOCK_EX); } catch (Throwable $_e) {}
                                    }
                                } catch (Throwable $e) {
                                    try { @file_put_contents(__DIR__ . '/../dev/logs/pdf_merge_errors.log', date('c') . " - image->pdf exception for {$img}: " . $e->getMessage() . "\n", FILE_APPEND | LOCK_EX); } catch (Throwable $_e) {}
                                }
                            }
                        } else {
                            try { @file_put_contents(__DIR__ . '/../dev/logs/pdf_merge_errors.log', date('c') . " - ImageMagick not found; skipping image->PDF conversion\n", FILE_APPEND | LOCK_EX); } catch (Throwable $_e) {}
                        }
                    }

                    if (!empty($appendPdfs)) {
                        // Discover Ghostscript binary
                        $gsCandidates = [];
                        if (defined('GS_BIN') && GS_BIN) $gsCandidates[] = GS_BIN;
                        $gsCandidates[] = 'gs';
                        $gsCandidates[] = 'gswin64c';
                        $gsCandidates[] = 'gswin32c';
                        if (DIRECTORY_SEPARATOR === '\\') {
                            $gsCandidates[] = 'C:\\Program Files\\gs\\bin\\gswin64c.exe';
                            $gsCandidates[] = 'C:\\Program Files (x86)\\gs\\bin\\gswin32c.exe';
                        } else {
                            $gsCandidates[] = '/usr/local/bin/gs';
                            $gsCandidates[] = '/usr/bin/gs';
                        }

                        $gsBin = null;
                        foreach ($gsCandidates as $cand) {
                            $cand = trim((string)$cand);
                            if ($cand === '') continue;
                            if (file_exists($cand) && is_executable($cand)) { $gsBin = $cand; break; }
                            try { exec(escapeshellcmd($cand) . ' --version 2>&1', $o, $gRc); if ($gRc === 0 && !empty($o)) { $gsBin = $cand; break; } } catch (Throwable $e) {}
                        }

                        if ($gsBin) {
                            $sources = array_merge([$pdfFile], $appendPdfs);
                            $mergedFile = $outDir . DIRECTORY_SEPARATOR . $safeName . '_progress_merged_' . time() . '.pdf';
                            $gsCmd = escapeshellcmd($gsBin) . ' -dBATCH -dNOPAUSE -q -sDEVICE=pdfwrite -sOutputFile=' . escapeshellarg($mergedFile) . ' ' . implode(' ', array_map('escapeshellarg', $sources)) . ' 2>&1';
                            exec($gsCmd, $gout, $grc);
                            if ($grc === 0 && file_exists($mergedFile)) {
                                @unlink($pdfFile);
                                $pdfFile = $mergedFile;
                            } else {
                                try { @file_put_contents(__DIR__ . '/../dev/logs/pdf_merge_errors.log', date('c') . " - gs merge failed: " . implode("\n", (array)$gout) . "\n", FILE_APPEND | LOCK_EX); } catch (Throwable $e) {}
                            }
                        } else {
                            // Ghostscript not available — skipping merge
                        }
                    }
                } catch (Throwable $e) {
                    try { @file_put_contents(__DIR__ . '/../dev/logs/pdf_merge_errors.log', date('c') . " - merge exception: " . $e->getMessage() . "\n", FILE_APPEND | LOCK_EX); } catch (Throwable $_e) {}
                }

                $rel = 'dev/exports/' . basename($pdfFile);
                $pdo->exec("CREATE TABLE IF NOT EXISTS student_reports (student_id INTEGER PRIMARY KEY, path TEXT, created_at TEXT, updated_at TEXT, created_by INTEGER, status TEXT)");
                $nowc = date('c'); $newStatus = 'active';
                $stmtUp = $pdo->prepare('INSERT INTO student_reports (student_id, path, created_at, updated_at, created_by, status) VALUES (:student_id, :path, :created_at, :updated_at, :created_by, :status) ON CONFLICT(student_id) DO UPDATE SET path = :path_up, updated_at = :updated_at_up, created_by = :created_by_up, status = :status_up');
                $stmtUp->execute([':student_id'=>$studentId,':path'=>$rel,':created_at'=>$nowc,':updated_at'=>$nowc,':created_by'=>$_SESSION['user_id'] ?? null,':status'=>$newStatus,':path_up'=>$rel,':updated_at_up'=>$nowc,':created_by_up'=>$_SESSION['user_id'] ?? null,':status_up'=>$newStatus]);
                echo json_encode(['success'=>true,'path'=>$rel,'filename'=>basename($pdfFile),'report'=>['student_id'=>$studentId,'path'=>$rel,'status'=>$newStatus]]);
            } catch (Throwable $e) { http_response_code(500); echo json_encode(['success'=>false,'error'=>'Failed to generate report','message'=>$e->getMessage()]); }
            break;
        }

        case 'get_student_report': {
            $studentId = isset($_POST['student_id']) ? (int)$_POST['student_id'] : 0; if (!$studentId) { echo json_encode(['success'=>false,'error'=>'Missing student_id']); break; }
            try { $pi = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name='student_reports'"); $pi->execute(); if (!$pi->fetchColumn()) { echo json_encode(['success'=>true,'report'=>null]); break; } $stmt = $pdo->prepare('SELECT * FROM student_reports WHERE student_id = :sid LIMIT 1'); $stmt->execute([':sid'=>$studentId]); $row = $stmt->fetch(PDO::FETCH_ASSOC); echo json_encode(['success'=>true,'report'=>$row]); } catch (Throwable $e) { echo json_encode(['success'=>false,'error'=>$e->getMessage()]); }
            break;
        }

        case 'create_student_report': {
            if (session_status() === PHP_SESSION_NONE) session_start(); if (empty($_SESSION['user_id'])) { echo json_encode(['success'=>false,'error'=>'Unauthorized']); break; }
            $studentId = isset($_POST['student_id']) ? (int)$_POST['student_id'] : 0; if (!$studentId) { echo json_encode(['success'=>false,'error'=>'Missing student_id']); break; }
            try { $pdo->exec("CREATE TABLE IF NOT EXISTS student_reports (student_id INTEGER PRIMARY KEY, path TEXT, created_at TEXT, updated_at TEXT, created_by INTEGER, status TEXT)"); $now = date('c'); $stmt = $pdo->prepare('INSERT INTO student_reports (student_id, path, created_at, updated_at, created_by, status) VALUES (:student_id, :path, :created_at, :updated_at, :created_by, :status) ON CONFLICT(student_id) DO UPDATE SET updated_at = :updated_at_up, created_by = :created_by_up, status = :status_up'); $stmt->execute([':student_id'=>$studentId,':path'=>null,':created_at'=>$now,':updated_at'=>$now,':created_by'=>$_SESSION['user_id'],':status'=>'active',':updated_at_up'=>$now,':created_by_up'=>$_SESSION['user_id'],':status_up'=>'active']); $q = $pdo->prepare('SELECT * FROM student_reports WHERE student_id = :sid LIMIT 1'); $q->execute([':sid'=>$studentId]); $rrow = $q->fetch(PDO::FETCH_ASSOC); try { record_activity('progress_report_created', $studentId, 'Progress report created'); } catch (Throwable $e) {} echo json_encode(['success'=>true,'report'=>$rrow]); } catch (Throwable $e) { echo json_encode(['success'=>false,'error'=>$e->getMessage()]); }
            break;
        }

        case 'create_progress_report': {
            if (session_status() === PHP_SESSION_NONE) session_start(); if (empty($_SESSION['user_id'])) { echo json_encode(['success'=>false,'error'=>'Unauthorized']); break; }
            $studentId = isset($_POST['student_id']) ? (int)$_POST['student_id'] : 0; if (!$studentId) { echo json_encode(['success'=>false,'error'=>'Missing student_id']); break; }
            $title = trim($_POST['title'] ?? 'Progress Report');
            try {
                // Ensure table exists (new installs)
                $pdo->exec("CREATE TABLE IF NOT EXISTS progress_reports (id INTEGER PRIMARY KEY AUTOINCREMENT, student_id INTEGER, title TEXT, created_at TEXT)");

                // If table already existed but lacks an id column, attempt an inline migration safely
                try {
                    $ti = $pdo->prepare("PRAGMA table_info('progress_reports')");
                    $ti->execute();
                    $cols = array_column($ti->fetchAll(PDO::FETCH_ASSOC), 'name');
                    if (!in_array('id', $cols)) {
                        // Best-effort online migration; if it fails due to locks, we'll continue using rowid fallback
                        try {
                            $pdo->exec('PRAGMA busy_timeout = 5000');
                        } catch (Throwable $_e) {}
                        try {
                            $pdo->exec('BEGIN IMMEDIATE');
                            $pdo->exec("CREATE TABLE IF NOT EXISTS progress_reports_new (id INTEGER PRIMARY KEY AUTOINCREMENT, student_id INTEGER, title TEXT, created_at TEXT)");
                            $pdo->exec("INSERT INTO progress_reports_new (student_id, title, created_at) SELECT student_id, title, created_at FROM progress_reports ORDER BY rowid ASC");
                            $pdo->exec("DROP TABLE progress_reports");
                            $pdo->exec("ALTER TABLE progress_reports_new RENAME TO progress_reports");
                            $pdo->commit();
                        } catch (Throwable $_m) {
                            try { $pdo->rollBack(); } catch (Throwable $__e) {}
                            // Log and continue; rowid fallback will still provide identifiers
                            try {
                                $logDir = __DIR__ . '/../dev/logs'; if (!is_dir($logDir)) @mkdir($logDir,0755,true);
                                @file_put_contents($logDir . '/migration_progress_reports.log', date('c') . ' - inline migration failed: ' . $_m->getMessage() . "\n", FILE_APPEND | LOCK_EX);
                            } catch (Throwable $__e) {}
                        }
                    }
                } catch (Throwable $_e) { /* schema check failed; continue */ }
                $now = date('c');
                $ins = $pdo->prepare('INSERT INTO progress_reports (student_id, title, created_at) VALUES (:sid, :title, :created_at)');
                $ins->execute([':sid'=>$studentId, ':title'=>$title, ':created_at'=>$now]);
                $id = (int)$pdo->lastInsertId();
                // fetch using rowid or id column (some DBs used "ID" or omitted id column)
                try {
                    $q = $pdo->prepare('SELECT COALESCE(id, rowid) AS id, rowid AS rowid, student_id, title, created_at FROM progress_reports WHERE rowid = :rid LIMIT 1');
                    $q->execute([':rid' => $id]);
                    $row = $q->fetch(PDO::FETCH_ASSOC);
                } catch (Throwable $e) {
                    // fallback to generic select
                    $q = $pdo->prepare('SELECT * FROM progress_reports WHERE id = :id LIMIT 1'); $q->execute([':id'=>$id]); $row = $q->fetch(PDO::FETCH_ASSOC);
                    if ($row) {
                        if (!isset($row['id']) && isset($row['ID'])) $row['id'] = $row['ID'];
                        if (!isset($row['id']) && isset($row['rowid'])) $row['id'] = $row['rowid'];
                    }
                }
                try { record_activity('progress_report_created', $studentId, 'Progress report created: ' . $title); } catch (Throwable $e) {}
                // Also create/update a legacy student_reports row so UI code that relies on it
                try {
                    $pdo->exec("CREATE TABLE IF NOT EXISTS student_reports (student_id INTEGER PRIMARY KEY, path TEXT, created_at TEXT, updated_at TEXT, created_by INTEGER, status TEXT)");
                    $nowc = date('c');
                    $stmtUp = $pdo->prepare('INSERT INTO student_reports (student_id, path, created_at, updated_at, created_by, status) VALUES (:student_id, :path, :created_at, :updated_at, :created_by, :status) ON CONFLICT(student_id) DO UPDATE SET path = :path_up, updated_at = :updated_at_up, created_by = :created_by_up, status = :status_up');
                    $stmtUp->execute([':student_id'=>$studentId,':path'=>null,':created_at'=>$nowc,':updated_at'=>$nowc,':created_by'=>$_SESSION['user_id'] ?? null,':status'=>'active',':path_up'=>null,':updated_at_up'=>$nowc,':created_by_up'=>$_SESSION['user_id'] ?? null,':status_up'=>'active']);
                } catch (Throwable $e) { /* ignore legacy table issues */ }

                echo json_encode(['success'=>true,'report'=>$row]);
            } catch (Throwable $e) { echo json_encode(['success'=>false,'error'=>$e->getMessage()]); }
            break;
        }

        case 'get_latest_progress_report': {
            $studentId = isset($_POST['student_id']) ? (int)$_POST['student_id'] : 0; if (!$studentId) { echo json_encode(['success'=>false,'error'=>'Missing student_id']); break; }
            try {
                $pi = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name='progress_reports'"); $pi->execute();
                if (!$pi->fetchColumn()) { echo json_encode(['success'=>true,'report'=>null]); break; }
                // select a normalized id (prefer id column, fall back to rowid)
                $st = $pdo->prepare('SELECT COALESCE(id, rowid) AS id, rowid AS rowid, student_id, title, created_at FROM progress_reports WHERE student_id = :sid ORDER BY created_at DESC LIMIT 1');
                $st->execute([':sid'=>$studentId]); $row = $st->fetch(PDO::FETCH_ASSOC);
                echo json_encode(['success'=>true,'report'=>$row]);
            } catch (Throwable $e) { echo json_encode(['success'=>false,'error'=>$e->getMessage()]); }
            break;
        }

        case 'delete_student_report': {
            if (session_status() === PHP_SESSION_NONE) session_start(); if (empty($_SESSION['user_id'])) { echo json_encode(['success'=>false,'error'=>'Unauthorized','message'=>'Unauthorized']); break; }
            $studentId = isset($_POST['student_id']) ? (int)$_POST['student_id'] : 0; if (!$studentId) { echo json_encode(['success'=>false,'error'=>'Missing student_id','message'=>'Missing student_id']); break; }
            try { $pi = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name='student_reports'"); $pi->execute(); if (!(bool)$pi->fetchColumn()) { echo json_encode(['success'=>false,'error'=>'No report found']); break; } $stmt = $pdo->prepare('SELECT * FROM student_reports WHERE student_id = :sid LIMIT 1'); $stmt->execute([':sid'=>$studentId]); $row = $stmt->fetch(PDO::FETCH_ASSOC); if ($row && !empty($row['path'])) { $filePath = __DIR__ . '/../' . ltrim($row['path'], '/\\'); if (file_exists($filePath)) @unlink($filePath); } $d = $pdo->prepare('DELETE FROM student_reports WHERE student_id = :sid'); $d->execute([':sid'=>$studentId]); echo json_encode(['success'=>true]); } catch (Throwable $e) { echo json_encode(['success'=>false,'error'=>$e->getMessage()]); }
            break;
        }

        case 'delete_progress_report': {
            if (session_status() === PHP_SESSION_NONE) session_start(); if (empty($_SESSION['user_id'])) { echo json_encode(['success'=>false,'error'=>'Unauthorized','message'=>'Unauthorized']); break; }
            $reportId = isset($_POST['report_id']) ? (int)$_POST['report_id'] : null;
            $studentId = isset($_POST['student_id']) ? (int)$_POST['student_id'] : null;
            try {
                $pi = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name='progress_reports'"); $pi->execute(); if (!(bool)$pi->fetchColumn()) { echo json_encode(['success'=>false,'error'=>'No progress reports table']); break; }
                if ($reportId) {
                    // Fetch the student_id referenced by this report before deleting the row.
                    // Match explicitly by id OR rowid to avoid COALESCE ambiguity when id exists but the client sent a rowid.
                    $sid = null;
                    try {
                        $s = $pdo->prepare('SELECT student_id FROM progress_reports WHERE id = :id OR rowid = :id LIMIT 1');
                        $s->execute([':id' => $reportId]);
                        $sr = $s->fetch(PDO::FETCH_ASSOC);
                        $sid = isset($sr['student_id']) && $sr['student_id'] !== null ? (int)$sr['student_id'] : null;
                    } catch (Throwable $e) {
                        // ignore and proceed; sid may remain null
                    }

                    // Delete the progress_report row using the same id OR rowid match
                    $d = $pdo->prepare('DELETE FROM progress_reports WHERE id = :id OR rowid = :id'); $d->execute([':id'=>$reportId]);

                    // If we discovered a student id, remove associated skills/updates and any legacy student_reports row
                    if ($sid) {
                        try { $pdo->prepare('DELETE FROM progress_updates WHERE student_id = :sid')->execute([':sid' => $sid]); } catch (Throwable $e) { /* non-fatal */ }
                        try { $pdo->prepare('DELETE FROM progress_skills WHERE student_id = :sid')->execute([':sid' => $sid]); } catch (Throwable $e) { /* non-fatal */ }
                        try { $pdo->prepare('DELETE FROM student_reports WHERE student_id = :sid')->execute([':sid' => $sid]); } catch (Throwable $e) { /* non-fatal */ }
                    } else {
                        // If we couldn't discover student_id, attempt a best-effort cleanup:
                        // - If progress_skills or progress_updates include references to the report id in metadata/content, remove them
                        // - Otherwise, leave them alone (non-destructive). This branch is intentionally conservative.
                        try {
                            // Attempt to find any progress_skills where the student_id can be inferred by joining to progress_reports via student_id
                            $ps = $pdo->prepare('SELECT DISTINCT student_id FROM progress_skills WHERE student_id IS NOT NULL');
                            $ps->execute();
                            $potentialSids = array_filter(array_map(function($r){ return isset($r['student_id']) ? (int)$r['student_id'] : null; }, $ps->fetchAll(PDO::FETCH_ASSOC)));
                            if (!empty($potentialSids)) {
                                foreach ($potentialSids as $psid) {
                                    // If this student's progress_reports no longer exist, it's safe to remove orphaned skills/updates
                                    $chk = $pdo->prepare('SELECT COUNT(*) FROM progress_reports WHERE student_id = :sid'); $chk->execute([':sid' => $psid]);
                                    $cnt = (int)$chk->fetchColumn();
                                    if ($cnt === 0) {
                                        try { $pdo->prepare('DELETE FROM progress_updates WHERE student_id = :sid')->execute([':sid'=>$psid]); } catch (Throwable $_e) {}
                                        try { $pdo->prepare('DELETE FROM progress_skills WHERE student_id = :sid')->execute([':sid'=>$psid]); } catch (Throwable $_e) {}
                                        try { $pdo->prepare('DELETE FROM student_reports WHERE student_id = :sid')->execute([':sid'=>$psid]); } catch (Throwable $_e) {}
                                    }
                                }
                            }
                        } catch (Throwable $_e) { /* non-fatal */ }
                    }
                } elseif ($studentId) {
                    // delete progress report(s) for this student
                    $d = $pdo->prepare('DELETE FROM progress_reports WHERE student_id = :sid'); $d->execute([':sid'=>$studentId]);
                    // delete any skills and updates for this student
                    try { $pdo->prepare('DELETE FROM progress_updates WHERE student_id = :sid')->execute([':sid' => $studentId]); } catch (Throwable $e) { /* non-fatal */ }
                    try { $pdo->prepare('DELETE FROM progress_skills WHERE student_id = :sid')->execute([':sid' => $studentId]); } catch (Throwable $e) { /* non-fatal */ }
                    try { $pdo->prepare('DELETE FROM student_reports WHERE student_id = :sid')->execute([':sid' => $studentId]); } catch (Throwable $e) { /* non-fatal */ }
                } else {
                    echo json_encode(['success'=>false,'error'=>'Missing report_id or student_id']); break;
                }
                // Provide the affected student id when known so the client can refresh UI precisely
                $affectedStudentId = isset($sid) && $sid ? $sid : ($studentId ?: null);
                try { record_activity('progress_report_deleted', $affectedStudentId ?? ($reportId ?: null), 'Progress report deleted'); } catch (Throwable $e) {}
                echo json_encode(['success'=>true, 'student_id' => $affectedStudentId]);
            } catch (Throwable $e) { echo json_encode(['success'=>false,'error'=>$e->getMessage()]); }
            break;
        }

        case 'get_student_skills': {
            $studentId = isset($_POST['student_id']) ? (int)$_POST['student_id'] : 0; if (!$studentId) { echo json_encode(['success'=>false,'error'=>'Missing student_id']); break; }
            try { $pi = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name='progress_skills'"); $pi->execute(); if (!$pi->fetchColumn()) { echo json_encode(['success'=>true,'skills'=>[]]); break; } $st = $pdo->prepare('SELECT * FROM progress_skills WHERE student_id = :sid ORDER BY id'); $st->execute([':sid'=>$studentId]); $rows = $st->fetchAll(PDO::FETCH_ASSOC); echo json_encode(['success'=>true,'skills'=>$rows]); } catch (Throwable $e) { echo json_encode(['success'=>false,'error'=>$e->getMessage()]); }
            break;
        }

        case 'get_skill_updates': {
            $skillId = isset($_POST['skill_id']) ? (int)$_POST['skill_id'] : 0; if (!$skillId) { echo json_encode(['success'=>false,'error'=>'Missing skill_id']); break; }
            try { $pi = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name='progress_updates'"); $pi->execute(); if (!$pi->fetchColumn()) { echo json_encode(['success'=>true,'updates'=>[]]); break; } $st = $pdo->prepare('SELECT * FROM progress_updates WHERE skill_id = :sid ORDER BY created_at'); $st->execute([':sid'=>$skillId]); $rows = $st->fetchAll(PDO::FETCH_ASSOC); echo json_encode(['success'=>true,'updates'=>$rows]); } catch (Throwable $e) { echo json_encode(['success'=>false,'error'=>$e->getMessage()]); }
            break;
        }

        case 'delete_progress_skill': {
            $skillId = isset($_POST['skill_id']) ? (int)$_POST['skill_id'] : 0; if (!$skillId) { echo json_encode(['success'=>false,'error'=>'Missing skill_id']); break; }
            try {
                try { $pi = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name='progress_updates'"); $pi->execute(); if ($pi->fetchColumn()) { $d = $pdo->prepare('DELETE FROM progress_updates WHERE skill_id = :sid'); $d->execute([':sid'=>$skillId]); } } catch (Throwable $e) {}
                $pi2 = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name='progress_skills'"); $pi2->execute(); if ($pi2->fetchColumn()) { $d2 = $pdo->prepare('DELETE FROM progress_skills WHERE id = :id'); $d2->execute([':id'=>$skillId]); }
                echo json_encode(['success'=>true]);
            } catch (Throwable $e) { echo json_encode(['success'=>false,'error'=>$e->getMessage()]); }
            break;
        }

        case 'add_progress_skill': {
            $studentId = isset($_POST['student_id']) ? (int)$_POST['student_id'] : 0;
            $label = isset($_POST['skill_label']) ? trim($_POST['skill_label']) : '';
            $category = isset($_POST['category']) ? trim($_POST['category']) : null;
            if (!$studentId || $label === '') { echo json_encode(['success'=>false,'error'=>'Missing params']); break; }
            try {
                $pdo->exec("CREATE TABLE IF NOT EXISTS progress_skills (id INTEGER PRIMARY KEY AUTOINCREMENT, student_id INTEGER, skill_label TEXT, category TEXT, created_at TEXT)");
                $now = date('c');
                $ins = $pdo->prepare('INSERT INTO progress_skills (student_id, skill_label, category, created_at) VALUES (:sid, :label, :cat, :created_at)');
                $ins->execute([':sid'=>$studentId,':label'=>$label,':cat'=>$category,':created_at'=>$now]);
                $id = (int)$pdo->lastInsertId();
                $stmt = $pdo->prepare('SELECT * FROM progress_skills WHERE id = :id LIMIT 1'); $stmt->execute([':id'=>$id]); $row = $stmt->fetch(PDO::FETCH_ASSOC);
                echo json_encode(['success'=>true,'id'=>$id,'skill'=>$row]);
            } catch (Throwable $e) { echo json_encode(['success'=>false,'error'=>$e->getMessage()]); }
            break;
        }

        case 'add_progress_update': {
            $skillId = isset($_POST['skill_id']) ? (int)$_POST['skill_id'] : 0;
            $studentId = isset($_POST['student_id']) ? (int)$_POST['student_id'] : 0;
            $score = isset($_POST['score']) ? $_POST['score'] : null;
            $target = isset($_POST['target_score']) ? $_POST['target_score'] : null;
            $notes = isset($_POST['notes']) ? trim($_POST['notes']) : null;
            if (!$skillId || !$studentId || $score === null) { echo json_encode(['success'=>false,'error'=>'Missing params']); break; }
            try {
                $pdo->exec("CREATE TABLE IF NOT EXISTS progress_updates (id INTEGER PRIMARY KEY AUTOINCREMENT, skill_id INTEGER, student_id INTEGER, score INTEGER, target_score INTEGER, notes TEXT, created_at TEXT)");
                $now = date('c');
                $ins = $pdo->prepare('INSERT INTO progress_updates (skill_id, student_id, score, target_score, notes, created_at) VALUES (:skill_id, :student_id, :score, :target_score, :notes, :created_at)');
                $ins->execute([':skill_id'=>$skillId,':student_id'=>$studentId,':score'=>$score,':target_score'=>$target,':notes'=>$notes,':created_at'=>$now]);
                $id = (int)$pdo->lastInsertId();
                $stmt = $pdo->prepare('SELECT * FROM progress_updates WHERE id = :id LIMIT 1'); $stmt->execute([':id'=>$id]); $row = $stmt->fetch(PDO::FETCH_ASSOC);
                echo json_encode(['success'=>true,'id'=>$id,'update'=>$row]);
            } catch (Throwable $e) { echo json_encode(['success'=>false,'error'=>$e->getMessage()]); }
            break;
        }

        case 'save_student_report_snapshot': {
            $studentId = isset($_POST['student_id']) ? (int)$_POST['student_id'] : 0;
            if (!$studentId) { header('Content-Type: text/plain; charset=utf-8'); echo 'Student ID required'; break; }
            try {
                $stmt = $pdo->prepare('SELECT * FROM students WHERE id = :id LIMIT 1'); $stmt->execute([':id'=>$studentId]); $student = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$student) { header('Content-Type: text/plain; charset=utf-8'); echo 'Student not found'; break; }

                $skills = [];
                try { $ps = $pdo->prepare('SELECT * FROM progress_skills WHERE student_id = :sid ORDER BY id'); $ps->execute([':sid'=>$studentId]); $skills = $ps->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) {}
                $student_goals = [];
                try { $gq = $pdo->prepare('SELECT * FROM goals WHERE student_id = :sid ORDER BY created_at DESC'); $gq->execute([':sid'=>$studentId]); $student_goals = $gq->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) {}
                $skill_updates = [];
                try { if (!empty($skills)) { foreach (array_column($skills,'id') as $sid) { $pu = $pdo->prepare('SELECT * FROM progress_updates WHERE skill_id = :sid ORDER BY created_at'); $pu->execute([':sid'=>$sid]); $skill_updates[$sid] = $pu->fetchAll(PDO::FETCH_ASSOC); } } } catch (Throwable $e) {}

                header('Content-Type: text/html; charset=utf-8');
                ob_start();
                $chart_images = [];
                include __DIR__ . '/../templates/student_report.php';
                $html = ob_get_clean();
                echo $html;
            } catch (Throwable $e) { header('Content-Type: text/plain; charset=utf-8'); echo 'Failed to render snapshot'; }
            break;
        }

        // Delete student -> remove DB row (documents cascade) and keep files (optional)
        case 'delete_student': {
            $idToDelete = isset($_POST['id']) ? (int)$_POST['id'] : 0;
            if (!$idToDelete) { echo json_encode(['success'=>false,'error'=>'Missing student id','message'=>'Missing student id']); break; }
            // capture student name for activity logging before deletion
            $studentName = null;
            try {
                $s = $pdo->prepare('SELECT first_name, last_name FROM students WHERE id = :id LIMIT 1');
                $s->execute([':id' => $idToDelete]);
                $sr = $s->fetch(PDO::FETCH_ASSOC);
                if ($sr) $studentName = trim(($sr['first_name'] ?? '') . ' ' . ($sr['last_name'] ?? '')) ?: null;
            } catch (Throwable $e) { /* ignore */ }

            $stmt = $pdo->prepare('DELETE FROM students WHERE id = :id');
            $stmt->execute([':id'=>$idToDelete]);
            try { record_activity('student_deleted', $idToDelete, 'Student deleted', ['student_id' => $idToDelete, 'student_name' => $studentName]); } catch (Throwable $e) {}
            echo json_encode(['success'=>true]);
            break;
        }

        // Permanently delete an archived student (typed confirmation from UI)
        case 'delete_student_permanent': {
            $idToDelete = isset($_POST['id']) ? (int)$_POST['id'] : 0;
            if (!$idToDelete) { echo json_encode(['success'=>false,'error'=>'Missing student id','message'=>'Missing student id']); break; }
            // capture student name for activity logging before deletion
            $studentName = null;
            try {
                $s = $pdo->prepare('SELECT first_name, last_name FROM students WHERE id = :id LIMIT 1');
                $s->execute([':id' => $idToDelete]);
                $sr = $s->fetch(PDO::FETCH_ASSOC);
                if ($sr) $studentName = trim(($sr['first_name'] ?? '') . ' ' . ($sr['last_name'] ?? '')) ?: null;
            } catch (Throwable $e) { /* ignore */ }

            try {
                // Attempt to remove uploaded files referenced by document tables (best-effort, safe within uploads/)
                $uploadsDirReal = realpath(__DIR__ . '/../uploads') ?: (__DIR__ . '/../uploads');
                $uploadsPrefix = str_replace(['\\','/'], DIRECTORY_SEPARATOR, rtrim($uploadsDirReal, '/\\'));

                $docTables = ['other_documents','session_reports','initial_evaluations','discharge_reports'];
                foreach ($docTables as $t) {
                    try {
                        $st = $pdo->prepare("SELECT file_path FROM {$t} WHERE student_id = :id AND file_path IS NOT NULL");
                        $st->execute([':id'=>$idToDelete]);
                        while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
                            if (empty($r['file_path'])) continue;
                            $candidate = __DIR__ . '/../' . ltrim($r['file_path'], '/\\');
                            $candidateNorm = str_replace(['\\','/'], DIRECTORY_SEPARATOR, $candidate);
                            // Only unlink files that live under the uploads directory to avoid accidental deletions
                            if (strpos($candidateNorm, $uploadsPrefix) === 0 && is_file($candidateNorm)) {
                                @unlink($candidateNorm);
                            }
                        }
                    } catch (Throwable $e) {
                        // ignore file-collection errors
                    }
                    // then delete document rows from that table
                    try { $pdo->prepare("DELETE FROM {$t} WHERE student_id = :id")->execute([':id'=>$idToDelete]); } catch (Throwable $e) {}
                }

                // Also remove any progress records (skills/updates)
                try { $pdo->prepare('DELETE FROM progress_updates WHERE student_id = :id')->execute([':id'=>$idToDelete]); } catch (Throwable $e) {}
                try { $pdo->prepare('DELETE FROM progress_skills WHERE student_id = :id')->execute([':id'=>$idToDelete]); } catch (Throwable $e) {}

                // Finally delete the student row
                $stmt = $pdo->prepare('DELETE FROM students WHERE id = :id');
                $stmt->execute([':id'=>$idToDelete]);
                try { record_activity('student_deleted_permanent', $idToDelete, 'Student file permanently deleted', ['student_id' => $idToDelete, 'student_name' => $studentName]); } catch (Throwable $e) {}
                echo json_encode(['success'=>true]);
            } catch (Throwable $e) {
                echo json_encode(['success'=>false,'error'=>$e->getMessage(),'message'=>$e->getMessage()]);
            }
            break;
        }

        // Archive student -> set archived flag
        case 'archive_student': {
            $idToArchive = isset($_POST['id']) ? (int)$_POST['id'] : 0;
            if (!$idToArchive) { echo json_encode(['success'=>false,'error'=>'Missing student id','message'=>'Missing student id']); break; }
            $stmt = $pdo->prepare('UPDATE students SET archived = 1 WHERE id = :id');
            $stmt->execute([':id'=>$idToArchive]);
            echo json_encode(['success'=>true]);
            break;
        }

        // Save document/form -> write file and insert documents row
        case 'save_document': {
            $formData = $_POST['form_data'] ? json_decode($_POST['form_data'], true) : [];
            $timestamp = time();
            $formType = $_POST['form_type'] ?? ($formData['form_type'] ?? 'unspecified');
            $studentId = null;
            if (isset($formData['studentName']) && is_numeric($formData['studentName'])) {
                $studentId = (int)$formData['studentName'];
            } elseif (isset($_POST['student_id'])) {
                $studentId = (int)$_POST['student_id'];
            }
            if (!$studentId) { echo json_encode(['success'=>false,'error'=>'Student ID is required','message'=>'Student ID is required']); break; }

            // Server-side validation: ensure form_data contains at least one non-empty value
            $hasNonEmpty = false;
            if (is_array($formData)) {
                foreach ($formData as $k => $v) {
                    if ($k === 'db_id') continue; // ignore db id
                    if (is_array($v)) {
                        if (!empty($v)) { $hasNonEmpty = true; break; }
                    } else {
                        if (trim((string)$v) !== '') { $hasNonEmpty = true; break; }
                    }
                }
            }
            if (!$hasNonEmpty) { echo json_encode(['success'=>false,'error'=>'Form data is empty','message'=>'Please fill out at least one field before saving.']); break; }

            // Transient debug: log discharge_report saves to help diagnose client/server mismatch
            if (in_array($formType, ['discharge_report','discharge'])) {
                try {
                    $logDir = __DIR__ . '/../dev/logs';
                    if (!is_dir($logDir)) @mkdir($logDir, 0755, true);
                    $dbg = [
                        'time' => date('c'),
                        'user_id' => $_SESSION['user_id'] ?? null,
                        'student_id' => $studentId,
                        'raw_post' => $_POST,
                        'form_data_parsed' => $formData
                    ];
                    @file_put_contents($logDir . '/discharge_save_debug.log', json_encode($dbg, JSON_PRETTY_PRINT) . "\n", FILE_APPEND | LOCK_EX);
                } catch (\Throwable $e) {
                    // ignore logging failures
                }
            }

            // No filesystem folders are created; documents are stored in DB

            $stmt = $pdo->prepare('SELECT first_name, last_name FROM students WHERE id = :id');
            $stmt->execute([':id'=>$studentId]);
            $r = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($r) $studentName = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));

            $doc = [
                'id' => $timestamp,
                'student_id' => $studentId,
                'form_type' => $formType,
                'form_data' => $formData,
                'title' => (isset($_POST['title']) ? $_POST['title'] : (ucwords(str_replace('_',' ',$formType)) . ' - ' . ($studentName ?? ''))),
                'therapist_id' => $_SESSION['user_id'] ?? null,
                'created_at' => date('c')
            ];

            // Persist into the appropriate per-form table when possible
            $userId = $_SESSION['user_id'] ?? null;
            $contentJson = json_encode($doc, JSON_PRETTY_PRINT);
            if (in_array($formType, ['initial_evaluation','initial_profile'])) {
                // attempt to extract structured fields from the form_data to populate dedicated columns
                $fd = is_array($formData) ? $formData : [];
                // helper: return the first present key from an array of possible keys
                $pick = function(array $keys) use ($fd) {
                    foreach ($keys as $k) {
                        if (isset($fd[$k]) && $fd[$k] !== null) return $fd[$k];
                    }
                    return null;
                };

                $eval_date = $pick(['Evaluation_Date','evaluation_date','initial_date','EvaluationDate','evaluationDate','evaluationDate']);
                $reason_referral = $pick(['Reason_Referral','reason_referral','reason','referralReason','referral_reason']);
                $background_info = $pick(['Background_Info','background_info','background','backgroundInfo','background_information']);
                $assessment_results = $pick(['Assessment_Results','assessment_results','assessment','assessmentResults','assessment_result']);
                $recommendations = $pick(['Recommendations','recommendations','recommendation']);

                // normalize to empty string if null to avoid NULLs where blank text is expected
                $eval_date = $eval_date !== null ? $eval_date : '';
                $reason_referral = $reason_referral !== null ? $reason_referral : '';
                $background_info = $background_info !== null ? $background_info : '';
                $assessment_results = $assessment_results !== null ? $assessment_results : '';
                $recommendations = $recommendations !== null ? $recommendations : '';

                // If client supplied a db_id (editing existing row), perform UPDATE instead of INSERT
                $dbId = isset($_POST['db_id']) && ctype_digit((string)$_POST['db_id']) ? (int)$_POST['db_id'] : (isset($formData['db_id']) && ctype_digit((string)$formData['db_id']) ? (int)$formData['db_id'] : null);

                // Build candidate columns/values similar to session_reports approach
                $candidates = [
                    'student_id' => $studentId,
                    'title' => $doc['title'],
                    'therapist_id' => $userId,
                    'metadata' => json_encode(['form_type'=>$formType]),
                    'content' => $contentJson,
                    'Evaluation_Date' => $eval_date,
                    'Reason_Referral' => $reason_referral,
                    'Background_Info' => $background_info,
                    'Assessment_Results' => $assessment_results,
                    'Recommendations' => $recommendations,
                    'created_at' => date('c')
                ];

                // Inspect table schema for initial_evaluations
                $pi = $pdo->prepare("PRAGMA table_info('initial_evaluations')");
                $pi->execute();
                $existingCols = array_column($pi->fetchAll(PDO::FETCH_ASSOC), 'name');

                // Ensure content column exists when possible (best-effort)
                if (!in_array('content', $existingCols)) {
                    try {
                        $pdo->exec("ALTER TABLE initial_evaluations ADD COLUMN content TEXT");
                        $pi = $pdo->prepare("PRAGMA table_info('initial_evaluations')");
                        $pi->execute();
                        $existingCols = array_column($pi->fetchAll(PDO::FETCH_ASSOC), 'name');
                    } catch (\Throwable $e) {
                        // ignore failures to alter
                    }
                }

                // Determine which candidate columns actually exist in this DB schema
                $cols = array_values(array_intersect(array_keys($candidates), $existingCols));

                // If we have columns to work with, do an INSERT/UPDATE using only those columns
                if (!empty($cols)) {
                    if ($dbId) {
                        $sets = array_map(function($c){ return "{$c} = :{$c}"; }, $cols);
                        $sql = 'UPDATE initial_evaluations SET ' . implode(', ', $sets) . ' WHERE id = :id';
                        $stmt = $pdo->prepare($sql);
                        $params = [':id' => $dbId];
                        foreach ($cols as $c) $params[':' . $c] = $candidates[$c];
                        $stmt->execute($params);
                    } else {
                        $placeholders = array_map(function($c){ return ':' . $c; }, $cols);
                        $sql = 'INSERT INTO initial_evaluations (' . implode(', ', $cols) . ') VALUES (' . implode(', ', $placeholders) . ')';
                        $stmt = $pdo->prepare($sql);
                        $params = [];
                        foreach ($cols as $c) $params[':' . $c] = $candidates[$c];
                        $stmt->execute($params);
                        try { record_activity('document_created', $studentId, 'Initial evaluation created', ['form_type' => $formType, 'db_id' => (int)$pdo->lastInsertId()]); } catch (Throwable $e) {}
                    }

                    // If content exists in schema but wasn't part of the inserted columns, ensure it's stored via UPDATE
                    if (in_array('content', $existingCols) && !in_array('content', $cols)) {
                        try {
                            $lastId = (int)$pdo->lastInsertId();
                            if ($lastId) {
                                $u = $pdo->prepare('UPDATE initial_evaluations SET content = :content WHERE id = :id');
                                $u->execute([':content' => $contentJson, ':id' => $lastId]);
                            }
                        } catch (\Throwable $e) {
                            // non-fatal
                        }
                    }
                } else {
                    // Fallback: schema doesn't have compatible columns; store in other_documents
                        $insertData = [
                            'student_id' => $studentId,
                            'title' => $doc['title'],
                            'therapist_id' => $userId,
                            'metadata' => json_encode(['fallback_from' => 'initial_evaluations']),
                            'content' => $contentJson,
                            'created_at' => date('c')
                        ];
                        if (!empty($formType)) $insertData['form_type'] = $formType;
                        try {
                            $lastId = (int)$safe_insert('other_documents', $insertData);
                        } catch (Throwable $e) {
                            // As a last-resort fallback, attempt a direct insert without form_type
                            $stmt = $pdo->prepare('INSERT INTO other_documents (student_id, title, therapist_id, metadata, content, created_at) VALUES (:student_id, :title, :therapist_id, :metadata, :content, :created_at)');
                            $stmt->execute([
                                ':student_id' => $studentId,
                                ':title' => $doc['title'],
                                ':therapist_id' => $userId,
                                ':metadata' => json_encode(['fallback_from' => 'initial_evaluations']),
                                ':content' => $contentJson,
                                ':created_at' => date('c')
                            ]);
                            $lastId = (int)$pdo->lastInsertId();
                        }
                    try { record_activity('document_created', $studentId, 'Document created (initial_evaluations fallback)', ['form_type' => $formType, 'db_id' => $lastId]); } catch (Throwable $e) {}
                }
            }
            // Goals form -> normalize into goals table
            elseif ($formType === 'goals_form' || $formType === 'goals') {
                $fd = is_array($formData) ? $formData : [];
                $pick = function(array $keys) use ($fd) {
                    foreach ($keys as $k) {
                        if (isset($fd[$k]) && $fd[$k] !== null) return $fd[$k];
                    }
                    return null;
                };
                $goal_date = $pick(['goalDate','goal_date','date']);
                $long_term = $pick(['longTermGoals','long_term_goals','longTermGoal','longTermGoals']);
                $short_terms = $pick(['shortTermObjectives','short_term_objectives','shortObjectives']);
                $intervention = $pick(['interventionStrategies','intervention_strategies','interventionStrategies']);
                $measurement = $pick(['measurementCriteria','measurement_criteria','measurementCriteria']);

                // Build title and description fields
                // Use a consistent title pattern instead of using the long-term goal text.
                // Format: "Goals form - {Student Name}" (falls back to empty if student name is unavailable)
                $title = 'Goals form - ' . ($studentName ?? '');
                $parts = [];
                if ($long_term) $parts[] = "Long Term Goals:\n" . trim($long_term);
                if ($short_terms) $parts[] = "Short Term Objectives:\n" . trim($short_terms);
                if ($intervention) $parts[] = "Intervention Strategies:\n" . trim($intervention);
                if ($measurement) $parts[] = "Measurement Criteria:\n" . trim($measurement);
                $description = implode("\n\n", $parts);
                $status = 'active';

                // support update when db_id provided
                $dbId = isset($_POST['db_id']) && ctype_digit((string)$_POST['db_id']) ? (int)$_POST['db_id'] : (isset($formData['db_id']) && ctype_digit((string)$formData['db_id']) ? (int)$formData['db_id'] : null);
                // Inspect goals table to determine which columns exist (schema-aware writes)
                $gcols = [];
                try { $pi = $pdo->prepare("PRAGMA table_info('goals')"); $pi->execute(); $gcols = array_column($pi->fetchAll(PDO::FETCH_ASSOC), 'name'); } catch (Throwable $_e) { $gcols = []; }

                if ($dbId) {
                    // Build dynamic UPDATE setting only existing columns
                    $sets = [
                        'title' => $title,
                        'Long_Term_Goals' => $long_term,
                        'Short_Term_Goals' => $short_terms,
                        'Intervention_Strategies' => $intervention,
                        'Measurement_Criteria' => $measurement,
                        'status' => $status,
                        'updated_at' => date('c')
                    ];
                    // Only include therapist_id if the column exists
                    if (in_array('therapist_id', $gcols)) {
                        $sets['therapist_id'] = $userId;
                    }
                    // Compose SQL
                    $assignments = [];
                    $params = [':id' => $dbId];
                    foreach ($sets as $col => $val) { if (in_array($col, $gcols)) { $assignments[] = "$col = :$col"; $params[":$col"] = $val; } }
                    $sql = 'UPDATE goals SET ' . implode(', ', $assignments) . ' WHERE id = :id';
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute($params);
                    try { record_activity('goal_updated', $studentId, 'Goal updated', ['goal_id' => $dbId]); } catch (Throwable $e) {}
                } else {
                    // Build dynamic INSERT including only existing columns
                    $cols = ['student_id','title','Long_Term_Goals','Short_Term_Goals','Intervention_Strategies','Measurement_Criteria','status','created_at'];
                    if (in_array('therapist_id', $gcols)) $cols[] = 'therapist_id';
                    $use = array_values(array_intersect($cols, $gcols));
                    // values map
                    $valuesMap = [
                        'student_id' => $studentId,
                        'title' => $title,
                        'Long_Term_Goals' => $long_term,
                        'Short_Term_Goals' => $short_terms,
                        'Intervention_Strategies' => $intervention,
                        'Measurement_Criteria' => $measurement,
                        'status' => $status,
                        'created_at' => date('c'),
                        'therapist_id' => $userId,
                    ];
                    $place = array_map(function($c){ return ':' . $c; }, $use);
                    $sql = 'INSERT INTO goals (' . implode(', ', $use) . ') VALUES (' . implode(', ', $place) . ')';
                    $stmt = $pdo->prepare($sql);
                    $params = [];
                    foreach ($use as $c) $params[':' . $c] = $valuesMap[$c];
                    $stmt->execute($params);
                    try { record_activity('goal_created', $studentId, 'Goal created', ['goal_id' => (int)$pdo->lastInsertId()]); } catch (Throwable $e) {}
                }
            }
            elseif (in_array($formType, ['session_report','session_notes','session'])) {
                // attempt to extract session_date/duration/session_type from form data
                $session_date = $formData['sessionDate'] ?? $formData['session_date'] ?? null;
                $duration = isset($formData['sessionDuration']) ? (int)$formData['sessionDuration'] : (isset($formData['duration_minutes']) ? (int)$formData['duration_minutes'] : null);
                $stype = $formData['sessionType'] ?? $formData['session_type'] ?? null;

                // Build a map of candidate columns -> values. We'll insert only columns that exist in the DB schema to avoid missing-column errors.
                $candidates = [
                    'student_id' => $studentId,
                    'session_date' => $session_date,
                    'duration_minutes' => $duration,
                    'session_type' => $stype,
                    'title' => $doc['title'],
                    'therapist_id' => $userId,
                    'metadata' => json_encode(['form_type'=>$formType]),
                    'content' => $contentJson,
                    // potential textarea columns present in some schemas
                    'objectives_targeted' => $formData['objectivesTargeted'] ?? $formData['objectives_targeted'] ?? null,
                    'activities_used' => $formData['activitiesUsed'] ?? $formData['activities_used'] ?? null,
                    'student_response' => $formData['studentResponse'] ?? $formData['student_response'] ?? null,
                    'next_session_plan' => $formData['nextSessionPlan'] ?? $formData['next_session_plan'] ?? null,
                    'created_at' => date('c')
                ];

                // Inspect table schema
                $pi = $pdo->prepare("PRAGMA table_info('session_reports')");
                $pi->execute();
                $existingCols = array_column($pi->fetchAll(PDO::FETCH_ASSOC), 'name');

                // If the schema doesn't have a content column, add it so we can persist the full JSON payload
                if (!in_array('content', $existingCols)) {
                    try {
                        $pdo->exec("ALTER TABLE session_reports ADD COLUMN content TEXT");
                        // refresh schema list
                        $pi = $pdo->prepare("PRAGMA table_info('session_reports')");
                        $pi->execute();
                        $existingCols = array_column($pi->fetchAll(PDO::FETCH_ASSOC), 'name');
                    } catch (\Throwable $e) {
                        // If alter fails (permissions or read-only), continue without content — we'll fallback later
                    }
                }

                // Determine which candidate columns exist
                $cols = array_values(array_intersect(array_keys($candidates), $existingCols));

                if (!empty($cols)) {
                    $placeholders = array_map(function($c){ return ':' . $c; }, $cols);
                    $sql = 'INSERT INTO session_reports (' . implode(', ', $cols) . ') VALUES (' . implode(', ', $placeholders) . ')';
                    $stmt = $pdo->prepare($sql);
                    $params = [];
                    foreach ($cols as $c) {
                        $params[':' . $c] = $candidates[$c];
                    }
                    $stmt->execute($params);
                    try { record_activity('document_created', $studentId, 'Session report created', ['form_type' => $formType, 'db_id' => (int)$pdo->lastInsertId()]); } catch (Throwable $e) {}
                    // Ensure content stored: if the schema supports content but it wasn't part of the INSERT, update it now
                    if (in_array('content', $existingCols) && !in_array('content', $cols)) {
                        try {
                            $lastId = (int)$pdo->lastInsertId();
                            if ($lastId) {
                                $u = $pdo->prepare('UPDATE session_reports SET content = :content WHERE id = :id');
                                $u->execute([':content' => $contentJson, ':id' => $lastId]);
                            }
                        } catch (\Throwable $e) {
                            // non-fatal
                        }
                    }
                    // Also ensure textarea columns are persisted: build an UPDATE setting any of the textarea columns if present in schema
                    try {
                        $lastId = (int)$pdo->lastInsertId();
                        if ($lastId) {
                            $updCols = [];
                            $updParams = [':id' => $lastId];
                            $textCols = ['objectives_targeted','activities_used','student_response','next_session_plan'];
                            foreach ($textCols as $tc) {
                                if (in_array($tc, $existingCols) && isset($candidates[$tc]) && $candidates[$tc] !== null) {
                                    $updCols[] = "{$tc} = :{$tc}";
                                    $updParams[':' . $tc] = $candidates[$tc];
                                }
                            }
                            if (!empty($updCols)) {
                                $sqlu = 'UPDATE session_reports SET ' . implode(', ', $updCols) . ' WHERE id = :id';
                                $pu = $pdo->prepare($sqlu);
                                $pu->execute($updParams);
                            }
                        }
                    } catch (\Throwable $e) {
                        // non-fatal
                    }
                } else {
                    // Fallback: store as other_documents if session_reports has no compatible columns
                    $insertData = [
                        'student_id' => $studentId,
                        'title' => $doc['title'],
                        'therapist_id' => $userId,
                        'metadata' => json_encode(['fallback_from' => 'session_report']),
                        'content' => $contentJson,
                        'created_at' => date('c')
                    ];
                    if (!empty($formType)) $insertData['form_type'] = $formType;
                    try {
                        $lastId = (int)$safe_insert('other_documents', $insertData);
                    } catch (Throwable $e) {
                        $stmt = $pdo->prepare('INSERT INTO other_documents (student_id, title, therapist_id, metadata, content, created_at) VALUES (:student_id, :title, :therapist_id, :metadata, :content, :created_at)');
                        $stmt->execute([
                            ':student_id' => $studentId,
                            ':title' => $doc['title'],
                            ':therapist_id' => $userId,
                            ':metadata' => json_encode(['fallback_from' => 'session_report']),
                            ':content' => $contentJson,
                            ':created_at' => date('c')
                        ]);
                        $lastId = (int)$pdo->lastInsertId();
                    }
                    try { record_activity('document_created', $studentId, 'Document created (session_report fallback)', ['form_type' => $formType, 'db_id' => $lastId]); } catch (Throwable $e) {}
                }
            } elseif (in_array($formType, ['discharge_report','discharge'])) {
                // Use the same defensible pattern as session_reports: canonicalize inputs, inspect schema,
                // INSERT only existing columns, then run targeted UPDATEs for large text fields and content.
                $fd = is_array($formData) ? $formData : [];
                $pick = function(array $keys) use ($fd) {
                    foreach ($keys as $k) {
                        if (isset($fd[$k]) && $fd[$k] !== null) return $fd[$k];
                    }
                    return null;
                };

                // canonicalize values
                $summary = $pick(['servicesSummary','summaryOfServices','summary_of_services','Summary_of_Services_Provided','Summary_of_services','summaryServices']);
                $goals = $pick(['goalsAchieved','Goals_Achieved','Goals_achieved','goals_achieved','goalsAchievedText']);
                $reason = $pick(['dischargeReason','reasonForDischarge','Reason_for_discharge','reason_for_discharge','reason']);
                $followUp = $pick(['followUpRecommendations','Follow_up_Recommendations','FollowUp_Recommendations','follow_up_recommendations','followUp']);
                $ddate = $pick(['dischargeDate','discharge_date','created_at']);

                // Build candidates including many DB column name variants so we write to whichever the schema uses
                $base = [
                    'student_id' => $studentId,
                    'title' => $doc['title'],
                    'therapist_id' => $userId,
                    'metadata' => json_encode(['form_type'=>$formType]),
                    'content' => $contentJson,
                    'created_at' => date('c')
                ];

                $variantMap = [
                    // summary variants
                    'Summary_of_Services_Provided' => ['servicesSummary','summaryOfServices','Summary_of_Services_Provided','Summary_of_services','summary_of_services','summaryServices','services_summary'],
                    'Summary_of_services' => ['servicesSummary','summaryOfServices','Summary_of_services','summary_of_services','summary_services','summaryServices','services_summary'],
                    'summary_of_services' => ['servicesSummary','summaryOfServices','summary_of_services','summary_services','summaryServices','services_summary'],
                    // goals variants
                    'Goals_Achieved' => ['goalsAchieved','Goals_Achieved','Goals_achieved','goals_achieved','goalsAchievedText'],
                    'Goals_achieved' => ['goalsAchieved','Goals_achieved','Goals_achieved','goals_achieved','goalsAchievedText'],
                    'goals_achieved' => ['goalsAchieved','goals_achieved','goalsAchievedText'],
                    // reason variants
                    'Reason_for_Discharge' => ['dischargeReason','reasonForDischarge','Reason_for_discharge','reason_for_discharge','reason'],
                    'Reason_for_discharge' => ['dischargeReason','reasonForDischarge','Reason_for_discharge','reason_for_discharge','reason'],
                    'reason_for_discharge' => ['dischargeReason','reasonForDischarge','reason_for_discharge','reason'],
                    // follow-up variants
                    'Follow_up_Recommendations' => ['followUpRecommendations','Follow_up_Recommendations','FollowUp_Recommendations','follow_up_recommendations','followUp'],
                    'FollowUp_Recommendations' => ['followUpRecommendations','FollowUp_Recommendations','follow_up_recommendations','followUp'],
                    'follow_up_recommendations' => ['followUpRecommendations','follow_up_recommendations','Follow_up_Recommendations','followUp'],
                    // accommodate a legacy/misspelled column seen in some DBs
                    'FollowUp_Recommentaions' => ['followUpRecommendations','Follow_up_recommendations','FollowUp_Recommendations','followUp'],
                    // date variants
                    'discharge_date' => ['dischargeDate','discharge_date','created_at']
                ];

                $candidates = $base;
                foreach ($variantMap as $dbCol => $keys) {
                    $val = $pick($keys);
                    // only set the key if value is present (null is allowed but we prefer explicit values)
                    if ($val !== null) $candidates[$dbCol] = $val;
                }

                // Inspect schema
                $pi = $pdo->prepare("PRAGMA table_info('discharge_reports')");
                $pi->execute();
                $existingCols = array_column($pi->fetchAll(PDO::FETCH_ASSOC), 'name');

                // Best-effort: add content column if missing (non-fatal)
                if (!in_array('content', $existingCols)) {
                    try {
                        $pdo->exec("ALTER TABLE discharge_reports ADD COLUMN content TEXT");
                        $pi = $pdo->prepare("PRAGMA table_info('discharge_reports')");
                        $pi->execute();
                        $existingCols = array_column($pi->fetchAll(PDO::FETCH_ASSOC), 'name');
                    } catch (\Throwable $e) {
                        // ignore failures to alter
                    }
                }

                $cols = array_values(array_intersect(array_keys($candidates), $existingCols));

                // support update when db_id provided
                $dbId = isset($_POST['db_id']) && ctype_digit((string)$_POST['db_id']) ? (int)$_POST['db_id'] : (isset($formData['db_id']) && ctype_digit((string)$formData['db_id']) ? (int)$formData['db_id'] : null);

                if ($dbId) {
                    if (!empty($cols)) {
                        $sets = array_map(function($c){ return "{$c} = :{$c}"; }, $cols);
                        $sql = 'UPDATE discharge_reports SET ' . implode(', ', $sets) . ' WHERE id = :id';
                        $stmt = $pdo->prepare($sql);
                        $params = [':id' => $dbId];
                        foreach ($cols as $c) $params[':' . $c] = $candidates[$c];
                        $stmt->execute($params);
                            try { record_activity('document_created', $studentId, 'Discharge report created', ['form_type' => $formType, 'db_id' => (int)$pdo->lastInsertId()]); } catch (Throwable $e) {}
                    } else {
                        // fallback: update content only
                        $stmt = $pdo->prepare('UPDATE discharge_reports SET content = :content WHERE id = :id');
                        $stmt->execute([':content' => $contentJson, ':id' => $dbId]);
                    }
                } else {
                    if (!empty($cols)) {
                        $placeholders = array_map(function($c){ return ':' . $c; }, $cols);
                        $sql = 'INSERT INTO discharge_reports (' . implode(', ', $cols) . ') VALUES (' . implode(', ', $placeholders) . ')';
                        $stmt = $pdo->prepare($sql);
                        $params = [];
                        foreach ($cols as $c) $params[':' . $c] = $candidates[$c];
                        $stmt->execute($params);

                        // Ensure content stored if schema supports it but content wasn't included in INSERT
                        if (in_array('content', $existingCols) && !in_array('content', $cols)) {
                            try {
                                $lastId = (int)$pdo->lastInsertId();
                                if ($lastId) {
                                    $u = $pdo->prepare('UPDATE discharge_reports SET content = :content WHERE id = :id');
                                    $u->execute([':content' => $contentJson, ':id' => $lastId]);
                                }
                            } catch (\Throwable $e) {
                                // non-fatal
                            }
                        }

                        // Targeted UPDATE for large text columns (if present)
                        try {
                            $lastId = (int)$pdo->lastInsertId();
                            if ($lastId) {
                                $updCols = [];
                                $updParams = [':id' => $lastId];
                                $textCols = ['Summary_of_Services_Provided','Goals_Achieved','Reason_for_Discharge','Follow_up_Recommendations'];
                                foreach ($textCols as $tc) {
                                    if (in_array($tc, $existingCols) && isset($candidates[$tc]) && $candidates[$tc] !== null) {
                                        $updCols[] = "{$tc} = :{$tc}";
                                        $updParams[':' . $tc] = $candidates[$tc];
                                    }
                                }
                                if (!empty($updCols)) {
                                    $sqlu = 'UPDATE discharge_reports SET ' . implode(', ', $updCols) . ' WHERE id = :id';
                                    $pu = $pdo->prepare($sqlu);
                                    $pu->execute($updParams);
                                }
                            }
                        } catch (\Throwable $e) {
                            // non-fatal
                        }
                    } else {
                        // fallback: insert into other_documents if no matching columns
                        $insertData = [
                            'student_id' => $studentId,
                            'title' => $doc['title'],
                            'therapist_id' => $userId,
                            'metadata' => json_encode(['fallback_from' => 'discharge_report']),
                            'content' => $contentJson,
                            'created_at' => date('c')
                        ];
                        if (!empty($formType)) $insertData['form_type'] = $formType;
                        try {
                            $lastId = (int)$safe_insert('other_documents', $insertData);
                        } catch (Throwable $e) {
                            $stmt = $pdo->prepare('INSERT INTO other_documents (student_id, title, therapist_id, metadata, content, created_at) VALUES (:student_id, :title, :therapist_id, :metadata, :content, :created_at)');
                            $stmt->execute([
                                ':student_id' => $studentId,
                                ':title' => $doc['title'],
                                ':therapist_id' => $userId,
                                ':metadata' => json_encode(['fallback_from' => 'discharge_report']),
                                ':content' => $contentJson,
                                ':created_at' => date('c')
                            ]);
                            $lastId = (int)$pdo->lastInsertId();
                        }
                        try { record_activity('document_created', $studentId, 'Document created (discharge_report fallback)', ['form_type' => $formType, 'db_id' => $lastId]); } catch (Throwable $e) {}
                    }
                }
            } else {
                // Unknown form types: store in other_documents (be schema-aware)
                $insertData = [
                    'student_id' => $studentId,
                    'title' => $doc['title'],
                    'therapist_id' => $userId,
                    'metadata' => json_encode(['form_type'=>$formType]),
                    'content' => $contentJson,
                    'created_at' => date('c'),
                ];
                if (!empty($formType)) $insertData['form_type'] = $formType;
                try {
                    $lastId = (int)$safe_insert('other_documents', $insertData);
                } catch (Throwable $e) {
                    $stmt = $pdo->prepare('INSERT INTO other_documents (student_id, title, therapist_id, metadata, content, created_at) VALUES (:student_id, :title, :therapist_id, :metadata, :content, :created_at)');
                    $stmt->execute([
                        ':student_id' => $studentId,
                        ':title' => $doc['title'],
                        ':therapist_id' => $userId,
                        ':metadata' => json_encode(['form_type'=>$formType]),
                        ':content' => $contentJson,
                        ':created_at' => date('c'),
                    ]);
                    $lastId = (int)$pdo->lastInsertId();
                }
                try { record_activity('document_created', $studentId, 'Document created', ['form_type' => $formType, 'db_id' => $lastId]); } catch (Throwable $e) {}
            }

            echo json_encode(['success'=>true,'id'=>$timestamp]);
            break;
        }

        // Get student forms -> read documents table and file contents if present
        case 'get_student_forms': {
            $studentId = isset($_POST['student_id']) ? (int)$_POST['student_id'] : 0;
            if (!$studentId) { echo json_encode(['success'=>false,'error'=>'Student ID required','message'=>'Student ID required']); break; }

            // Aggregate forms from per-form tables (preferred) and fallback to documents table for unknowns
            $forms = [];
            // initial evaluations - select only columns that exist to avoid "no such column" errors on older schemas
            // include the known per-form columns so we can build form_data when content JSON is not present
            $cols = ['id','student_id','title','therapist_id','metadata','content','created_at','Evaluation_Date','Reason_Referral','Background_Info','Assessment_Results','Recommendations'];
            $pi = $pdo->prepare("PRAGMA table_info('initial_evaluations')");
            $pi->execute();
            $existing = array_column($pi->fetchAll(PDO::FETCH_ASSOC),'name');
            $want = array_values(array_intersect($cols,$existing));
            if (empty($want)) $want = ['*'];
            $sql = 'SELECT ' . implode(',', $want) . ' FROM initial_evaluations WHERE student_id = :sid ORDER BY created_at DESC';
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':sid'=>$studentId]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                // prefer stored JSON content when available
                if (isset($r['content']) && !empty($r['content'])) {
                    $json = json_decode($r['content'], true);
                    if ($json) { $json['db_id'] = $r['id']; $forms[] = $json; continue; }
                }

                // Build a form_data payload from explicit columns when content JSON isn't present
                $form_data = [];
                // student id
                if (isset($r['student_id'])) $form_data['studentName'] = $r['student_id'];
                // evaluation date
                if (isset($r['Evaluation_Date'])) $form_data['evaluationDate'] = $r['Evaluation_Date'];
                elseif (isset($r['evaluation_date'])) $form_data['evaluationDate'] = $r['evaluation_date'] ?? null;
                // referral reason
                if (isset($r['Reason_Referral'])) $form_data['referralReason'] = $r['Reason_Referral'];
                elseif (isset($r['reason_referral'])) $form_data['referralReason'] = $r['reason_referral'] ?? null;
                // background info
                if (isset($r['Background_Info'])) $form_data['backgroundInfo'] = $r['Background_Info'];
                elseif (isset($r['background_info'])) $form_data['backgroundInfo'] = $r['background_info'] ?? null;
                // assessment results
                if (isset($r['Assessment_Results'])) $form_data['assessmentResults'] = $r['Assessment_Results'];
                elseif (isset($r['assessment_results'])) $form_data['assessmentResults'] = $r['assessment_results'] ?? null;
                // recommendations
                if (isset($r['Recommendations'])) $form_data['recommendations'] = $r['Recommendations'];
                elseif (isset($r['recommendations'])) $form_data['recommendations'] = $r['recommendations'] ?? null;

                // created_at fallback
                $form_data['created_at'] = $r['created_at'] ?? null;

                $forms[] = ['db_id'=>$r['id'],'id'=>$r['id'],'title'=>$r['title'] ?? ('Initial Evaluation'), 'created_at'=>$r['created_at'] ?? null,'form_type'=>'initial_evaluation','form_data'=>$form_data];
            }
            // goals (normalize into forms list so client can view/edit)
            $cols = ['id','student_id','title','description','status','created_at','Long_Term_Goals','Short_Term_Goals','Intervention_Strategies','Measurement_Criteria'];
            $pi = $pdo->prepare("PRAGMA table_info('goals')");
            $pi->execute();
            $existing = array_column($pi->fetchAll(PDO::FETCH_ASSOC),'name');
            $want = array_values(array_intersect($cols,$existing));
            if (empty($want)) $want = ['*'];
            $sql = 'SELECT ' . implode(',', $want) . ' FROM goals WHERE student_id = :sid ORDER BY created_at DESC';
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':sid'=>$studentId]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $form_data = [];
                // Prefer explicit columns if they exist
                if (isset($r['Long_Term_Goals']) || isset($r['Short_Term_Goals']) || isset($r['Intervention_Strategies']) || isset($r['Measurement_Criteria'])) {
                    $form_data['longTermGoals'] = $r['Long_Term_Goals'] ?? '';
                    $form_data['shortTermObjectives'] = $r['Short_Term_Goals'] ?? '';
                    $form_data['interventionStrategies'] = $r['Intervention_Strategies'] ?? '';
                    $form_data['measurementCriteria'] = $r['Measurement_Criteria'] ?? '';
                } else {
                    // fall back to parsing description
                    if (!empty($r['description'])) {
                        $parts = preg_split('/\r?\n\r?\n/', $r['description']);
                        foreach ($parts as $p) {
                            $p = trim($p);
                            if (stripos($p, 'Long Term Goals:') === 0) {
                                $form_data['longTermGoals'] = trim(substr($p, strlen('Long Term Goals:')));
                            } elseif (stripos($p, 'Short Term Objectives:') === 0) {
                                $form_data['shortTermObjectives'] = trim(substr($p, strlen('Short Term Objectives:')));
                            } elseif (stripos($p, 'Intervention Strategies:') === 0) {
                                $form_data['interventionStrategies'] = trim(substr($p, strlen('Intervention Strategies:')));
                            } elseif (stripos($p, 'Measurement Criteria:') === 0) {
                                $form_data['measurementCriteria'] = trim(substr($p, strlen('Measurement Criteria:')));
                            } else {
                                $form_data['notes'] = (isset($form_data['notes']) ? $form_data['notes'] . "\n\n" : '') . $p;
                            }
                        }
                    }
                }
                if (empty($form_data['goalDate'])) $form_data['goalDate'] = $r['created_at'];
                // Provide student_id at top-level and also mirror into form_data.studentName so client view/edit code
                $form_data['studentName'] = $r['student_id'];
                // Also include created_at inside form_data similar to content JSON returned for other forms
                $form_data['created_at'] = $r['created_at'];
                $forms[] = ['db_id'=>$r['id'],'id'=>$r['id'],'student_id'=>$r['student_id'],'title'=>$r['title'],'created_at'=>$r['created_at'],'form_type'=>'goals_form','form_data'=>$form_data,'status'=>$r['status'] ?? 'active'];
            }
            // session reports
            $cols = ['id','title','therapist_id','metadata','content','created_at'];
            $pi = $pdo->prepare("PRAGMA table_info('session_reports')");
            $pi->execute();
            $existing = array_column($pi->fetchAll(PDO::FETCH_ASSOC),'name');
            $want = array_values(array_intersect($cols,$existing));
            if (empty($want)) $want = ['*'];
            $sql = 'SELECT ' . implode(',', $want) . ' FROM session_reports WHERE student_id = :sid ORDER BY created_at DESC';
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':sid'=>$studentId]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                if (!empty($r['content'])) {
                    $json = json_decode($r['content'], true);
                    if ($json) { $json['db_id'] = $r['id']; $forms[] = $json; continue; }
                }

                // Build a fallback form_data from explicit columns when content JSON isn't present
                $form_data = [];
                if (isset($r['student_id'])) $form_data['studentName'] = $r['student_id'];
                // session date variations
                if (isset($r['session_date'])) $form_data['sessionDate'] = $r['session_date'];
                elseif (isset($r['sessionDate'])) $form_data['sessionDate'] = $r['sessionDate'];
                // duration
                if (isset($r['duration_minutes'])) $form_data['sessionDuration'] = $r['duration_minutes'];
                elseif (isset($r['durationMinutes'])) $form_data['sessionDuration'] = $r['durationMinutes'] ?? null;
                // session type
                if (isset($r['session_type'])) $form_data['sessionType'] = $r['session_type'];
                elseif (isset($r['sessionType'])) $form_data['sessionType'] = $r['sessionType'];
                // other possible columns mapped to textarea fields
                if (isset($r['objectives_targeted'])) $form_data['objectivesTargeted'] = $r['objectives_targeted'];
                if (isset($r['objectivesTargeted'])) $form_data['objectivesTargeted'] = $r['objectivesTargeted'];
                if (isset($r['activities_used'])) $form_data['activitiesUsed'] = $r['activities_used'];
                if (isset($r['activitiesUsed'])) $form_data['activitiesUsed'] = $r['activitiesUsed'];
                if (isset($r['student_response'])) $form_data['studentResponse'] = $r['student_response'];
                if (isset($r['studentResponse'])) $form_data['studentResponse'] = $r['studentResponse'];
                if (isset($r['next_session_plan'])) $form_data['nextSessionPlan'] = $r['next_session_plan'];
                if (isset($r['nextSessionPlan'])) $form_data['nextSessionPlan'] = $r['nextSessionPlan'];

                // created_at fallback
                $form_data['created_at'] = $r['created_at'] ?? null;

                $forms[] = ['db_id'=>$r['id'],'id'=>$r['id'],'title'=>$r['title'] ?? 'Session Report', 'created_at'=>$r['created_at'] ?? null,'form_type'=>'session_report','form_data'=>$form_data];
            }
            // discharge reports
            $cols = ['id','title','therapist_id','metadata','content','created_at'];
            $pi = $pdo->prepare("PRAGMA table_info('discharge_reports')");
            $pi->execute();
            $existing = array_column($pi->fetchAll(PDO::FETCH_ASSOC),'name');
            $want = array_values(array_intersect($cols,$existing));
            if (empty($want)) $want = ['*'];
            $sql = 'SELECT ' . implode(',', $want) . ' FROM discharge_reports WHERE student_id = :sid ORDER BY created_at DESC';
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':sid'=>$studentId]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                if (!empty($r['content'])) {
                    $json = json_decode($r['content'], true);
                    if ($json) { $json['db_id'] = $r['id']; $forms[] = $json; continue; }
                }

                // Build form_data from explicit discharge_report columns when content is not present
                $form_data = [];
                if (isset($r['student_id'])) $form_data['studentName'] = $r['student_id'];
                // Summary of services - several possible column names
                if (isset($r['Summary_of_Services_Provided'])) $form_data['summaryOfServices'] = $r['Summary_of_Services_Provided'];
                elseif (isset($r['Summary_of_services'])) $form_data['summaryOfServices'] = $r['Summary_of_services'];
                elseif (isset($r['summary_of_services'])) $form_data['summaryOfServices'] = $r['summary_of_services'] ?? null;

                // Goals achieved
                if (isset($r['Goals_Achieved'])) $form_data['goalsAchieved'] = $r['Goals_Achieved'];
                elseif (isset($r['Goals_achieved'])) $form_data['goalsAchieved'] = $r['Goals_achieved'];
                elseif (isset($r['goals_achieved'])) $form_data['goalsAchieved'] = $r['goals_achieved'] ?? null;

                // Reason for discharge
                if (isset($r['Reason_for_Discharge'])) $form_data['reasonForDischarge'] = $r['Reason_for_Discharge'];
                elseif (isset($r['Reason_for_discharge'])) $form_data['reasonForDischarge'] = $r['Reason_for_discharge'];
                elseif (isset($r['reason_for_discharge'])) $form_data['reasonForDischarge'] = $r['reason_for_discharge'] ?? null;

                // Follow-up recommendations
                if (isset($r['Follow_up_Recommendations'])) $form_data['followUpRecommendations'] = $r['Follow_up_Recommendations'];
                elseif (isset($r['FollowUp_Recommendations'])) $form_data['followUpRecommendations'] = $r['FollowUp_Recommendations'];
                elseif (isset($r['follow_up_recommendations'])) $form_data['followUpRecommendations'] = $r['follow_up_recommendations'] ?? null;

                $form_data['created_at'] = $r['created_at'] ?? null;

                $forms[] = ['db_id'=>$r['id'],'id'=>$r['id'],'title'=>$r['title'] ?? 'Discharge Report','created_at'=>$r['created_at'] ?? null,'form_type'=>'discharge_report','form_data'=>$form_data];
            }
            // fallback: other_documents table for any other types
            // include file_path so clients can show download links
            $cols = ['id','title','form_type','metadata','content','file_path','created_at','therapist_id'];
            $pi = $pdo->prepare("PRAGMA table_info('other_documents')");
            $pi->execute();
            $existing = array_column($pi->fetchAll(PDO::FETCH_ASSOC),'name');
            $want = array_values(array_intersect($cols,$existing));
            if (empty($want)) $want = ['*'];
            $sql = 'SELECT ' . implode(',', $want) . ' FROM other_documents WHERE student_id = :sid ORDER BY created_at DESC';
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':sid'=>$studentId]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                if (!empty($r['content'])) {
                    $json = json_decode($r['content'], true);
                    if ($json) { $json['db_id'] = $r['id']; $forms[] = $json; continue; }
                }
                $forms[] = ['id'=>$r['id'],'title'=>$r['title'],'created_at'=>$r['created_at'],'form_type'=>$r['form_type'] ?? null, 'file_path' => $r['file_path'] ?? null];
            }
            // Normalize into buckets by form type and order groups as: initial, goals, sessions, other, discharge.
            $order = ['initial_evaluation','goals_form','session_report','other_documents','discharge_report'];
            $buckets = array_fill_keys($order, []);
            foreach ($forms as $f) {
                $ft = strtolower(trim((string)($f['form_type'] ?? '')));
                if (in_array($ft, ['initial_evaluation','initial_profile'])) $key = 'initial_evaluation';
                elseif (strpos($ft, 'goal') !== false) $key = 'goals_form';
                elseif (strpos($ft, 'session') !== false) $key = 'session_report';
                elseif (strpos($ft, 'discharge') !== false) $key = 'discharge_report';
                else $key = 'other_documents';
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
            $forms = $merged;
            echo json_encode(['success'=>true,'forms'=>$forms]);
            break;
        }

        // Delete a document/form (uploaded file or per-form JSON)
        case 'delete_document': {
            // expects POST: id (numeric) and optional form_type
            header('Content-Type: application/json; charset=utf-8');
            try {
                if (session_status() === PHP_SESSION_NONE) session_start();
                if (empty($_SESSION['user_id'])) throw new Exception('Unauthorized');

                $provided = isset($_POST['id']) ? $_POST['id'] : '';
                if ($provided === '') throw new Exception('Missing id');

                // Try to locate the row in per-form tables first.
                // Note: some forms were returned to the client as decoded JSON (content) and include an 'id' timestamp
                // which is NOT the DB row id. To support deleting by that JSON id, attempt both matches:
                // 1) DB row id == provided (numeric)
                // 2) json_decode(content)['id'] == provided
                // include 'goals' so per-form goals rows can be found/deleted
                $tables = ['initial_evaluations','goals','session_reports','discharge_reports','other_documents'];
                $found = false;
                $foundTable = '';
                $foundRow = null;
                $foundDbRowId = null;

                // If the client provided an explicit table parameter, prefer that (safer)
                $explicitTable = isset($_POST['table']) ? trim($_POST['table']) : null;
                $explicitId = isset($_POST['id']) ? trim($_POST['id']) : null;
                $contentId = isset($_POST['content_id']) ? trim($_POST['content_id']) : null;

                // If explicit table + numeric id provided, try that first
                if ($explicitTable && $explicitId && ctype_digit((string)$explicitId)) {
                    if (in_array($explicitTable, $tables)) {
                        $stmt = $pdo->prepare("SELECT * FROM {$explicitTable} WHERE id = :id LIMIT 1");
                        $stmt->execute([':id' => (int)$explicitId]);
                        $r = $stmt->fetch(PDO::FETCH_ASSOC);
                        if ($r) { $found = true; $foundTable = $explicitTable; $foundRow = $r; $foundDbRowId = (int)$explicitId; }
                    }
                }

                // If not found yet, and the client sent a content_id (JSON id inside content), search content fields
                if (!$found && $contentId) {
                    foreach ($tables as $t) {
                        try {
                            $pi = $pdo->prepare("PRAGMA table_info('{$t}')"); $pi->execute();
                            $existing = array_column($pi->fetchAll(PDO::FETCH_ASSOC),'name');
                            if (!in_array('content',$existing)) continue;
                            $sql = 'SELECT id, content, file_path, therapist_id, student_id FROM ' . $t . ' WHERE content IS NOT NULL AND content != ""';
                            $stmt = $pdo->prepare($sql);
                            $stmt->execute();
                            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                                $json = json_decode($r['content'], true);
                                if ($json && isset($json['id']) && (string)$json['id'] === (string)$contentId) {
                                    $found = true; $foundTable = $t; $foundRow = $r; $foundDbRowId = isset($r['id']) ? (int)$r['id'] : null; break 2;
                                }
                            }
                        } catch (Throwable $e) { continue; }
                    }
                }

                // If still not found, fall back to matching by DB id (numeric provided) as legacy behavior
                if (!$found && ctype_digit((string)$provided)) {
                    $numericId = (int)$provided;
                    foreach ($tables as $t) {
                        $stmt = $pdo->prepare("SELECT * FROM {$t} WHERE id = :id LIMIT 1");
                        $stmt->execute([':id' => $numericId]);
                        $r = $stmt->fetch(PDO::FETCH_ASSOC);
                        if ($r) { $found = true; $foundTable = $t; $foundRow = $r; $foundDbRowId = $numericId; break; }
                    }
                }

                // If not found by DB id, search content JSON 'id' fields
                if (!$found) {
                    foreach ($tables as $t) {
                        // Determine columns present for this table
                        $pi = $pdo->prepare("PRAGMA table_info('{$t}')");
                        $pi->execute();
                        $existing = array_column($pi->fetchAll(PDO::FETCH_ASSOC),'name');

                        // Build a safe select list: always include id; include content/file_path if present
                        $selectCols = ['id'];
                        if (in_array('content',$existing)) $selectCols[] = 'content';
                        if (in_array('file_path',$existing)) $selectCols[] = 'file_path';
                        if (in_array('therapist_id',$existing)) $selectCols[] = 'therapist_id';

                        $sql = 'SELECT ' . implode(',', $selectCols) . ' FROM ' . $t;
                        if (in_array('content',$existing)) {
                            $sql .= " WHERE content IS NOT NULL AND content != ''";
                        }
                        $stmt = $pdo->prepare($sql);
                        $stmt->execute();
                        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                            if (!isset($r['content']) || empty($r['content'])) continue;
                            $json = json_decode($r['content'], true);
                            if ($json && isset($json['id']) && (string)$json['id'] === (string)$provided) {
                                $found = true;
                                $foundTable = $t;
                                $foundRow = $r;
                                $foundDbRowId = isset($r['id']) ? (int)$r['id'] : null;
                                break 2;
                            }
                        }
                    }
                }

                if (!$found) throw new Exception('Document not found');

                // Permission: allow if current user is the therapist_id (uploader), the student's assigned_therapist, or session has is_admin
                $currentUser = $_SESSION['user_id'] ?? null;
                $ownerId = isset($foundRow['therapist_id']) ? (int)$foundRow['therapist_id'] : null;
                $allowed = false;
                if ($currentUser && $ownerId && $ownerId === (int)$currentUser) $allowed = true;

                // If still not allowed, check the student assigned_therapist
                if (!$allowed) {
                    $studentIdForRow = null;
                    // try to get student_id from the foundRow directly
                    if (isset($foundRow['student_id'])) {
                        $studentIdForRow = (int)$foundRow['student_id'];
                    } else {
                        // attempt to parse content JSON for student_id
                        if (!empty($foundRow['content'])) {
                            $tmp = json_decode($foundRow['content'], true);
                            if ($tmp && isset($tmp['student_id'])) $studentIdForRow = (int)$tmp['student_id'];
                        }
                    }
                    if ($studentIdForRow) {
                        // Only query students.assigned_therapist if that column exists in this DB schema
                        try {
                            $pi3 = $pdo->prepare("PRAGMA table_info('students')");
                            $pi3->execute();
                            $studentCols = array_column($pi3->fetchAll(PDO::FETCH_ASSOC), 'name');
                        } catch (Throwable $e) {
                            $studentCols = [];
                        }
                        if (in_array('assigned_therapist', $studentCols)) {
                            $stmt = $pdo->prepare('SELECT assigned_therapist FROM students WHERE id = :id LIMIT 1');
                            $stmt->execute([':id' => $studentIdForRow]);
                            $stu = $stmt->fetch(PDO::FETCH_ASSOC);
                            if ($stu && !empty($stu['assigned_therapist']) && $currentUser && (int)$stu['assigned_therapist'] === (int)$currentUser) {
                                $allowed = true;
                            }
                        }
                    }
                }

                if (!$allowed) {
                    // Build debug info to help admins diagnose permission failures
                    $debug = [
                        'currentUser' => $currentUser,
                        'ownerId' => $ownerId,
                        'foundTable' => $foundTable,
                        'foundDbRowId' => $foundDbRowId,
                        'studentIdForRow' => $studentIdForRow ?? null
                    ];
                    // attempt to include assigned_therapist value if present
                    try {
                        $pi_debug = $pdo->prepare("PRAGMA table_info('students')");
                        $pi_debug->execute();
                        $studentColsDbg = array_column($pi_debug->fetchAll(PDO::FETCH_ASSOC),'name');
                    } catch (Throwable $e) { $studentColsDbg = []; }
                    if (in_array('assigned_therapist', $studentColsDbg) && !empty($studentIdForRow)) {
                        try {
                            $sstmt = $pdo->prepare('SELECT assigned_therapist FROM students WHERE id = :id LIMIT 1');
                            $sstmt->execute([':id' => $studentIdForRow]);
                            $srow = $sstmt->fetch(PDO::FETCH_ASSOC);
                            $debug['student_assigned_therapist'] = $srow['assigned_therapist'] ?? null;
                        } catch (Throwable $e) { /* ignore */ }
                    }
                    // detect if current user is admin so we can return debug to them
                    $isAdmin = false;
                    try {
                        $ust = $pdo->prepare('SELECT role FROM users WHERE id = :id LIMIT 1');
                        $ust->execute([':id' => $currentUser]);
                        $ur = $ust->fetch(PDO::FETCH_ASSOC);
                        if ($ur && ($ur['role'] ?? '') === 'admin') $isAdmin = true;
                    } catch (Throwable $e) { $isAdmin = false; }

                    if ($isAdmin) {
                        // Admin override: allow deletion but record debug info so audits show the reason
                        $allowed = true;
                        try {
                            $logDir = __DIR__ . '/../dev/logs';
                            if (!is_dir($logDir)) @mkdir($logDir, 0755, true);
                            $entry = [
                                'time' => date('c'),
                                'action' => 'delete_document',
                                'provided' => $provided,
                                'found_table' => $foundTable,
                                'found_db_id' => $foundDbRowId,
                                'user_id' => $currentUser,
                                'result' => 'admin_override',
                                'debug' => $debug
                            ];
                            @file_put_contents($logDir . '/delete_attempts.log', json_encode($entry) . "\n", FILE_APPEND | LOCK_EX);
                        } catch (Throwable $e) { /* ignore logging failures */ }
                    } else {
                        throw new Exception('Permission denied');
                    }
                }

                // Safety: verify the found row's student_id matches the student's selected id if client provided a student context
                $clientStudent = isset($_POST['student_id']) ? (int)$_POST['student_id'] : null;
                if ($clientStudent && isset($foundRow['student_id']) && (int)$foundRow['student_id'] !== $clientStudent) {
                    // Mismatch: refuse to delete and return descriptive error
                    throw new Exception('Student context mismatch for delete operation');
                }

                // Delete DB row (use the DB row id we discovered)
                if (empty($foundDbRowId)) throw new Exception('Unable to determine DB row id for deletion');
                $del = $pdo->prepare("DELETE FROM {$foundTable} WHERE id = :id");
                $del->execute([':id' => $foundDbRowId]);

                // Log successful deletion for audit/debug
                try {
                    $logDir = __DIR__ . '/../dev/logs';
                    if (!is_dir($logDir)) @mkdir($logDir, 0755, true);
                    $entry = [
                        'time' => date('c'),
                        'action' => 'delete_document',
                        'provided' => $provided,
                        'found_table' => $foundTable,
                        'found_db_id' => $foundDbRowId,
                        'user_id' => $currentUser,
                        'result' => 'deleted'
                    ];
                    @file_put_contents($logDir . '/delete_attempts.log', json_encode($entry) . "\n", FILE_APPEND | LOCK_EX);
                } catch (Throwable $e) { /* ignore logging failures */ }

                // If this was an uploaded file in other_documents, attempt to remove the file
                if ($foundTable === 'other_documents' && !empty($foundRow['file_path'])) {
                    $file = __DIR__ . '/../' . ltrim($foundRow['file_path'], '/\\');
                    if (file_exists($file)) {
                        @unlink($file);
                    }
                }

                try { record_activity('document_deleted', $foundRow['student_id'] ?? null, 'Document deleted from ' . $foundTable, ['table' => $foundTable, 'db_id' => $foundDbRowId]); } catch (Throwable $e) {}
                echo json_encode(['success' => true]);
            } catch (Exception $e) {
                // Log failure context for investigation
                try {
                    $logDir = __DIR__ . '/../dev/logs';
                    if (!is_dir($logDir)) @mkdir($logDir, 0755, true);
                    $entry = [
                        'time' => date('c'),
                        'action' => 'delete_document',
                        'provided' => $provided,
                        'found_table' => $foundTable ?? null,
                        'found_db_id' => $foundDbRowId ?? null,
                        'user_id' => $_SESSION['user_id'] ?? null,
                        'error' => $e->getMessage()
                    ];
                    @file_put_contents($logDir . '/delete_attempts.log', json_encode($entry) . "\n", FILE_APPEND | LOCK_EX);
                } catch (Throwable $le) { /* ignore logging failures */ }

                http_response_code(400);
                $msg = $e->getMessage();
                $maybe = json_decode($msg, true);
                if (is_array($maybe) && isset($maybe['message'])) {
                    // Return structured JSON (admin debug) preserving success=false
                    $out = ['success' => false];
                    $out = array_merge($out, $maybe);
                    echo json_encode($out);
                } else {
                    echo json_encode(['success' => false, 'message' => $msg]);
                }
            }
            break;
        }

        // Get student -> return DB student row
        case 'get_student': {
            $studentId = isset($_POST['id']) ? (int)$_POST['id'] : 0;
            if (!$studentId) { echo json_encode(['success'=>false,'error'=>'Student ID required','message'=>'Student ID required']); break; }
            $stmt = $pdo->prepare('SELECT * FROM students WHERE id = :id');
            $stmt->execute([':id'=>$studentId]);
            $student = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$student) { echo json_encode(['success'=>false,'error'=>'Student not found','message'=>'Student not found']); break; }
            echo json_encode(['success'=>true,'student'=>$student]);
            break;
        }

        // (Old naive update_student block removed -- replaced by a schema-aware update_student case later)

        // Export student HTML -> build using DB data and documents files
        case 'export_student_html': {
            $idToExport = isset($_POST['id']) ? (int)$_POST['id'] : 0;
            if (!$idToExport) { echo json_encode(['success'=>false,'error'=>'Missing id','message'=>'Missing id']); break; }

            $stmt = $pdo->prepare('SELECT * FROM students WHERE id = :id');
            $stmt->execute([':id'=>$idToExport]);
            $student = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$student) { echo json_encode(['success'=>false,'error'=>'Student not found','message'=>'Student not found']); break; }

            // aggregate per-form tables and documents fallback
            $student_docs = [];
            $tables = ['initial_evaluations','session_reports','discharge_reports','other_documents'];
            foreach ($tables as $t) {
                $stmt = $pdo->prepare("SELECT * FROM {$t} WHERE student_id = :sid ORDER BY created_at DESC");
                $stmt->execute([':sid'=>$idToExport]);
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $d) {
                    if (!empty($d['content'])) { $formData = json_decode($d['content'], true); if ($formData) { $student_docs[] = $formData; continue; } }
                    $student_docs[] = ['id'=>$d['id'],'title'=>$d['title'] ?? ($d['form_type'] ?? 'Document'),'created_at'=>$d['created_at']];
                }
            }

            // goals and progress for this student
            $stmt = $pdo->prepare('SELECT * FROM goals WHERE student_id = :sid');
            $stmt->execute([':sid'=>$idToExport]);
            $student_goals = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $stmt = $pdo->prepare('SELECT * FROM progress_updates WHERE student_id = :sid ORDER BY created_at DESC');
            $stmt->execute([':sid'=>$idToExport]);
            $student_progress = $stmt->fetchAll(PDO::FETCH_ASSOC);

            ob_start();
            include __DIR__ . '/../templates/export.php';
            $html = ob_get_clean();
            echo json_encode(['success'=>true,'html'=>$html]);
            break;
        }

        // Get archived students
        case 'get_archived_students': {
            $stmt = $pdo->query('SELECT * FROM students WHERE archived = 1 ORDER BY last_name, first_name');
            $archived = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success'=>true,'students'=>$archived]);
            break;
        }

        // Restore student
        case 'restore_student': {
            $studentId = isset($_POST['id']) ? (int)$_POST['id'] : 0;
            if (!$studentId) { echo json_encode(['success'=>false,'error'=>'Student ID required']); break; }
            $stmt = $pdo->prepare('UPDATE students SET archived = 0 WHERE id = :id');
            $stmt->execute([':id'=>$studentId]);
            echo json_encode(['success'=>true]);
            break;
        }

        // Export all students as HTML
        case 'export_all_students': {
            $stmt = $pdo->query('SELECT * FROM students WHERE archived = 0 ORDER BY last_name, first_name');
            $activeStudents = $stmt->fetchAll(PDO::FETCH_ASSOC);
            ob_start();
            ?>
            <!DOCTYPE html>
            <html><head><meta charset="utf-8"><title>All Students Export</title>
            <style>body{font-family:Arial,sans-serif;padding:20px} .student{margin-bottom:30px;padding:15px;border:1px solid #ddd;border-radius:5px} .student h3{margin-top:0;color:#333} .info{margin:5px 0} .label{font-weight:bold}</style>
            </head><body>
            <h1>All Students Export - <?php echo date('Y-m-d H:i:s'); ?></h1>
            <p>Total Students: <?php echo count($activeStudents); ?></p>
            <?php foreach ($activeStudents as $student): ?>
                <div class="student">
                    <h3><?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></h3>
                    <div class="info"><span class="label">Student ID:</span> <?php echo htmlspecialchars($student['student_id'] ?? $student['id']); ?></div>
                    <div class="info"><span class="label">Grade:</span> <?php echo htmlspecialchars($student['grade'] ?? 'N/A'); ?></div>
                    <div class="info"><span class="label">Date of Birth:</span> <?php echo htmlspecialchars($student['date_of_birth'] ?? 'N/A'); ?></div>
                    <div class="info"><span class="label">Gender:</span> <?php echo htmlspecialchars($student['gender'] ?? 'N/A'); ?></div>
                    <div class="info"><span class="label">Primary Language:</span> <?php echo htmlspecialchars($student['primary_language'] ?? 'English'); ?></div>
                    <div class="info"><span class="label">Service Frequency:</span> <?php echo htmlspecialchars($student['service_frequency'] ?? 'N/A'); ?></div>
                    <div class="info"><span class="label">Teacher:</span> <?php echo htmlspecialchars($student['teacher'] ?? 'N/A'); ?></div>
                    <div class="info"><span class="label">Parent Contact:</span> <?php echo htmlspecialchars($student['parent_contact'] ?? 'N/A'); ?></div>
                    <?php if (!empty($student['medical_info'])): ?>
                    <div class="info"><span class="label">Medical Info:</span> <?php echo nl2br(htmlspecialchars($student['medical_info'])); ?></div>
                    <?php endif; ?>
                    <div class="info"><span class="label">Created:</span> <?php echo htmlspecialchars($student['created_at'] ?? 'N/A'); ?></div>
                </div>
            <?php endforeach; ?>
            </body></html>
            <?php
            $exportHtml = ob_get_clean();
            echo json_encode(['success'=>true,'html'=>$exportHtml]);
            break;
        }

        // Create backup -> export DB tables to JSON file
        case 'create_backup': {
            $backup = [];
            $tables = ['students','goals','progress_updates','documents','users','reports'];
            foreach ($tables as $t) {
                $stmt = $pdo->query("SELECT * FROM {$t}");
                $backup[$t] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
            $backup['backup_created'] = date('c');
            // Do not write JSON files to disk. Return the backup payload in the response so callers
            // can download or persist it where appropriate.
            echo json_encode(['success'=>true,'backup'=>$backup]);
            break;
        }

        // Request password reset -> use users table if present, otherwise fallback to JSON resets
        case 'request_password_reset': {
            $identifier = trim($_POST['username'] ?? '');
            if (!$identifier) { echo json_encode(['success'=>false,'error'=>'Username/email required','message'=>'Username/email required']); break; }

            // Be schema-aware: only reference email in SQL if the column exists
            $userCols = [];
            try { $pi = $pdo->prepare("PRAGMA table_info('users')"); $pi->execute(); $userCols = array_column($pi->fetchAll(PDO::FETCH_ASSOC), 'name'); } catch (Throwable $e) { $userCols = []; }
            if (in_array('email', $userCols)) {
                $stmt = $pdo->prepare('SELECT id, username, email FROM users WHERE username = :id OR email = :id LIMIT 1');
                $stmt->execute([':id'=>$identifier]);
            } else {
                $stmt = $pdo->prepare('SELECT id, username, NULL AS email FROM users WHERE username = :id LIMIT 1');
                $stmt->execute([':id'=>$identifier]);
            }
            $foundUser = $stmt->fetch(PDO::FETCH_ASSOC);

            // Always respond success to avoid leaking usernames
            if ($foundUser) {
                    $token = bin2hex(random_bytes(16));
                    $expires = time() + 3600;
                    // Store reset token in DB (password_resets table) instead of writing to files
                    try {
                        $stmt = $pdo->prepare('INSERT INTO password_resets (user_id, username, token, expires_at, created_at) VALUES (:user_id, :username, :token, :expires_at, :created_at)');
                        $stmt->execute([
                            ':user_id' => $foundUser['id'] ?? null,
                            ':username' => $foundUser['username'] ?? null,
                            ':token' => $token,
                            ':expires_at' => date('c', $expires),
                            ':created_at' => date('c')
                        ]);
                    } catch (Exception $e) {
                        // If DB insert fails, proceed silently but do not create filesystem paths
                    }
            }
            echo json_encode(['success'=>true]);
            break;
        }

        // Get all (or filtered) students
        case 'get_students': {
            // optional: allow filtering via POST (assigned_therapist, archived)
            $assigned = isset($_POST['assigned_therapist']) && $_POST['assigned_therapist'] !== '' ? (int)$_POST['assigned_therapist'] : null;
            $archived  = isset($_POST['archived']) && $_POST['archived'] !== '' ? (int)$_POST['archived'] : null;

            // Inspect students schema so we only reference columns that exist
            try {
                $pi = $pdo->prepare("PRAGMA table_info('students')");
                $pi->execute();
                $studentCols = array_column($pi->fetchAll(PDO::FETCH_ASSOC), 'name');
            } catch (Throwable $e) {
                $studentCols = [];
            }

            // If assigned filter was requested but column doesn't exist, ignore the filter
            if ($assigned !== null && !in_array('assigned_therapist', $studentCols)) {
                $assigned = null;
            }

            $sql = 'SELECT * FROM students';
            $conds = [];
            $params = [];

            if ($assigned !== null) {
                $conds[] = 'assigned_therapist = :assigned';
                $params[':assigned'] = $assigned;
            }
            if ($archived !== null && in_array('archived', $studentCols)) {
                $conds[] = 'archived = :archived';
                $params[':archived'] = $archived;
            }
            if ($conds) $sql .= ' WHERE ' . implode(' AND ', $conds);

            $sql .= ' ORDER BY last_name, first_name';

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'students' => $rows]);
            break;
        }

        // Get goals
        case 'get_goals': {
            $sid = isset($_POST['student_id']) ? (int)$_POST['student_id'] : 0;
            if ($sid) {
                $stmt = $pdo->prepare('SELECT * FROM goals WHERE student_id = :sid ORDER BY created_at DESC');
                $stmt->execute([':sid'=>$sid]);
                echo json_encode(['success'=>true,'goals'=>$stmt->fetchAll(PDO::FETCH_ASSOC)]);
            } else {
                $stmt = $pdo->query('SELECT * FROM goals ORDER BY created_at DESC');
                echo json_encode(['success'=>true,'goals'=>$stmt->fetchAll(PDO::FETCH_ASSOC)]);
            }
            break;
        }

        // Get progress updates
        case 'get_progress': {
            $sid = isset($_POST['student_id']) ? (int)$_POST['student_id'] : 0;
            if ($sid) {
                $stmt = $pdo->prepare('SELECT * FROM progress_updates WHERE student_id = :sid ORDER BY created_at DESC');
                $stmt->execute([':sid'=>$sid]);
                echo json_encode(['success'=>true,'progress'=>$stmt->fetchAll(PDO::FETCH_ASSOC)]);
            } else {
                $stmt = $pdo->query('SELECT * FROM progress_updates ORDER BY created_at DESC');
                echo json_encode(['success'=>true,'progress'=>$stmt->fetchAll(PDO::FETCH_ASSOC)]);
            }
            break;
        }

        // Get recent activity (AJAX-friendly) - include progress_reports when available
        case 'get_recent_activity': {
            $limit = isset($_POST['limit']) ? (int)$_POST['limit'] : 20;
            if ($limit <= 0 || $limit > 200) $limit = 20;

            $items = [];
            // new students
            try {
                $stmt = $pdo->prepare("SELECT id, first_name, last_name, COALESCE(created_at, updated_at) AS ts FROM students WHERE archived = 0 ORDER BY ts DESC LIMIT :lim");
                $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
                $stmt->execute();
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $items[] = ['type'=>'student','student_id'=>$r['id'],'student_name'=>trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')),'title'=>'New student','created_at'=>$r['ts'] ?? null];
                }
            } catch (Throwable $e) {}

            // canonical document tables
            $docTables = ['goals','initial_evaluations','session_reports','other_documents','discharge_reports'];
            foreach ($docTables as $tbl) {
                try {
                    $pi = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name=:tbl"); $pi->execute([':tbl'=>$tbl]); if (!$pi->fetchColumn()) continue;
                    $sql = "SELECT id, student_id, COALESCE(title, '') AS title, COALESCE(created_at, updated_at) AS ts FROM {$tbl} WHERE student_id IS NOT NULL ORDER BY ts DESC LIMIT :lim";
                    $st = $pdo->prepare($sql);
                    $st->bindValue(':lim', $limit, PDO::PARAM_INT);
                    $st->execute();
                    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                        $items[] = ['type'=>'document','doc_table'=>$tbl,'doc_id'=>$r['id'],'student_id'=>$r['student_id'],'student_name'=>null,'title'=>$r['title'] ?? '','created_at'=>$r['ts'] ?? null];
                    }
                } catch (Throwable $e) { continue; }
            }

            // progress_reports (preferred new table)
            try {
                $pi = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name='progress_reports'"); $pi->execute();
                if ($pi->fetchColumn()) {
                    $pr = $pdo->prepare("SELECT COALESCE(id, rowid) AS id, student_id, title, created_at AS ts FROM progress_reports ORDER BY created_at DESC LIMIT :lim");
                    $pr->bindValue(':lim', $limit, PDO::PARAM_INT); $pr->execute();
                    foreach ($pr->fetchAll(PDO::FETCH_ASSOC) as $r) {
                        $items[] = ['type'=>'report','report_id'=>$r['id'],'student_id'=>$r['student_id'],'student_name'=>null,'title'=>$r['title'] ?? 'Progress Report','created_at'=>$r['ts'] ?? null];
                    }
                }
            } catch (Throwable $e) {}

            // student_reports (legacy)
            try {
                $pi = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name='student_reports'"); $pi->execute();
                if ($pi->fetchColumn()) {
                    $sr = $pdo->prepare("SELECT id, student_id, path, COALESCE(updated_at, created_at) AS ts FROM student_reports ORDER BY ts DESC LIMIT :lim");
                    $sr->bindValue(':lim', $limit, PDO::PARAM_INT); $sr->execute();
                    foreach ($sr->fetchAll(PDO::FETCH_ASSOC) as $r) {
                        $items[] = ['type'=>'report','report_id'=>$r['id'],'student_id'=>$r['student_id'],'student_name'=>null,'title'=>basename($r['path'] ?? 'Progress Report'),'created_at'=>$r['ts'] ?? null];
                    }
                }
            } catch (Throwable $e) {}

            // resolve student names
            $studentIds = array_values(array_unique(array_filter(array_map(function($it){ return $it['student_id'] ?? null; }, $items))));
            $studentMap = [];
            if (!empty($studentIds)) {
                $placeholders = implode(',', array_fill(0, count($studentIds), '?'));
                $q = $pdo->prepare("SELECT id, first_name, last_name FROM students WHERE id IN ($placeholders)");
                $q->execute($studentIds);
                foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $s) $studentMap[$s['id']] = trim(($s['first_name'] ?? '') . ' ' . ($s['last_name'] ?? ''));
            }
            foreach ($items as &$it) { if (empty($it['student_name']) && !empty($it['student_id']) && isset($studentMap[$it['student_id']])) $it['student_name'] = $studentMap[$it['student_id']]; }
            unset($it);

            // Merge persisted activity_log entries so actions recorded via record_activity(...) are visible
            try {
                $pi = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name='activity_log'"); $pi->execute();
                if ($pi->fetchColumn()) {
                    $al = $pdo->prepare('SELECT * FROM activity_log WHERE user_id = :uid OR user_id IS NULL ORDER BY created_at DESC LIMIT :lim');
                    $al->bindValue(':uid', $_SESSION['user_id'] ?? null);
                    $al->bindValue(':lim', $limit, PDO::PARAM_INT);
                    $al->execute();
                    foreach ($al->fetchAll(PDO::FETCH_ASSOC) as $alog) {
                        $met = null;
                        if (!empty($alog['metadata']) && is_string($alog['metadata'])) {
                            $decoded = json_decode($alog['metadata'], true);
                            if (is_array($decoded)) $met = $decoded;
                        }
                        $atype = $alog['type'] ?? 'activity';
                        $created = $alog['created_at'] ?? null;
                        // Map common activity types into the same item shapes used above
                        // Normalize activity descriptions and icons per spec
                        $student_name = $met['student_name'] ?? null;
                        // resolve student_name from id if missing
                        if (empty($student_name) && !empty($alog['student_id'])) {
                            try { $q = $pdo->prepare('SELECT first_name,last_name FROM students WHERE id = :id LIMIT 1'); $q->execute([':id'=>$alog['student_id']]); $sr = $q->fetch(PDO::FETCH_ASSOC); if ($sr) $student_name = trim(($sr['first_name'] ?? '') . ' ' . ($sr['last_name'] ?? '')) ?: null; } catch (Throwable $e) {}
                        }

                        // helper to derive a clean title from metadata or description
                        $derive_title = function($meta, $descFallback) {
                            $t = null;
                            if (!empty($meta['title'])) $t = $meta['title'];
                            elseif (!empty($meta['form_type'])) {
                                $map = ['session_report'=>'Session Report','initial_evaluation'=>'Initial Evaluation','discharge_report'=>'Discharge Report','progress_report'=>'Progress Report'];
                                $t = $map[$meta['form_type']] ?? ucfirst(str_replace('_',' ', $meta['form_type']));
                            } elseif (!empty($descFallback)) {
                                $t = preg_replace('/\s+(created|completed|deleted|uploaded).*$/i','', $descFallback);
                            }
                            return $t ?: 'Document';
                        };

                        if (in_array($atype, ['document_created','document_uploaded','document_updated','document_created_fallback'])) {
                            $title = $derive_title($met ?? [], $alog['description'] ?? null);
                            $desc = $title . ' created for ' . ($student_name ?? 'Unknown Student');
                            $items[] = [
                                'type' => 'document',
                                'doc_table' => 'activity_log',
                                'doc_id' => $alog['id'],
                                'student_id' => $alog['student_id'] ?? null,
                                'student_name' => $student_name,
                                'title' => $title,
                                'created_at' => $created,
                                'description' => $desc,
                                'icon' => '📄',
                                'from_activity_log' => true
                            ];
                        } elseif ($atype === 'document_deleted') {
                            $title = $derive_title($met ?? [], $alog['description'] ?? null);
                            $desc = $title . ' deleted for ' . ($student_name ?? 'Unknown Student');
                            $items[] = [
                                'type' => 'document',
                                'doc_table' => 'activity_log',
                                'doc_id' => $alog['id'],
                                'student_id' => $alog['student_id'] ?? null,
                                'student_name' => $student_name,
                                'title' => $title,
                                'created_at' => $created,
                                'description' => $desc,
                                'icon' => '📄',
                                'from_activity_log' => true
                            ];
                        } elseif (in_array($atype, ['student_created','student_deleted','student_updated'])) {
                            $verb = $atype === 'student_deleted' ? 'deleted' : ($atype === 'student_updated' ? 'updated' : 'created');
                            $desc = 'Student ' . $verb . ' for ' . ($student_name ?? 'Unknown Student');
                            $items[] = [
                                'type' => 'student',
                                'student_id' => $alog['student_id'] ?? null,
                                'student_name' => $student_name,
                                'title' => 'Student',
                                'created_at' => $created,
                                'description' => $desc,
                                'icon' => '👤',
                                'from_activity_log' => true
                            ];
                        } elseif (in_array($atype, ['progress_report_created','progress_report_deleted'])) {
                            $raw = $met['title'] ?? ($alog['description'] ?? 'Progress Report');
                            $title = preg_replace('/\s+(created|completed|deleted|uploaded).*$/i','', $raw) ?: 'Progress Report';
                            $verb = $atype === 'progress_report_deleted' ? 'deleted' : 'created';
                            $desc = $title . ' ' . $verb . ' for ' . ($student_name ?? 'Unknown Student');
                            $items[] = [
                                'type' => 'report',
                                'report_id' => $met['db_id'] ?? $alog['id'],
                                'student_id' => $alog['student_id'] ?? null,
                                'student_name' => $student_name,
                                'title' => $title,
                                'created_at' => $created,
                                'description' => $desc,
                                'icon' => '📈',
                                'from_activity_log' => true
                            ];
                        } else {
                            // generic fallback
                            $title = $alog['description'] ?? ($met['title'] ?? 'Activity');
                            $desc = $title . ' for ' . ($student_name ?? 'Unknown Student');
                            $items[] = [
                                'type' => 'activity',
                                'activity_id' => $alog['id'],
                                'student_id' => $alog['student_id'] ?? null,
                                'student_name' => $student_name,
                                'title' => $title,
                                'created_at' => $created,
                                'description' => $desc,
                                'icon' => 'ℹ️',
                                'from_activity_log' => true
                            ];
                        }
                    }
                }
            } catch (Throwable $e) {
                // ignore activity_log read issues
            }

            // Deduplicate items: consider items the same when they share type, student_id and timestamp.
            // Prefer items that originate from the persisted activity_log (from_activity_log = true).
            $map = [];
            foreach ($items as $it) {
                $type = $it['type'] ?? 'x';
                $sid = isset($it['student_id']) ? (string)$it['student_id'] : '';
                // normalize created_at to integer timestamp for stable comparison
                $ts = 0;
                if (!empty($it['created_at'])) {
                    $ts = is_numeric($it['created_at']) ? (int)$it['created_at'] : strtotime($it['created_at']);
                    if ($ts === false) $ts = 0;
                }
                $key = $type . '|' . $sid . '|' . $ts;

                if (isset($map[$key])) {
                    $existing = $map[$key];
                    $preferNew = false;
                    // If one of the items came from activity_log prefer that one
                    if (!empty($it['from_activity_log']) && empty($existing['from_activity_log'])) {
                        $preferNew = true;
                    } elseif (empty($existing['from_activity_log']) && empty($it['from_activity_log'])) {
                        // Neither came from activity_log: keep the one with the later created_at (if available)
                        $exTs = !empty($existing['created_at']) ? (is_numeric($existing['created_at']) ? (int)$existing['created_at'] : strtotime($existing['created_at'])) : 0;
                        if ($exTs === false) $exTs = 0;
                        if ($ts > $exTs) $preferNew = true;
                    }
                    if ($preferNew) $map[$key] = $it;
                } else {
                    $map[$key] = $it;
                }
            }
            $items = array_values($map);
            usort($items, function($a,$b){ $ta = isset($a['created_at']) ? strtotime($a['created_at']) : 0; $tb = isset($b['created_at']) ? strtotime($b['created_at']) : 0; return $tb <=> $ta; });
            $items = array_values(array_slice($items,0,$limit));
            echo json_encode(['success'=>true,'items'=>$items], JSON_UNESCAPED_SLASHES);
            break;
        }

        // Get dashboard counts (AJAX-friendly)
        case 'get_dashboard_counts': {
            try {
                // total active students
                $total_students = 0;
                $total_goals = 0;
                $recent_sessions = 0;
                $recent_updates = 0;
                // protect against missing tables by checking existence via PRAGMA
                try {
                    $tbls = $pdo->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
                } catch (Throwable $e) {
                    $tbls = [];
                }
                if (in_array('students', $tbls)) {
                    try {
                        $total_students = (int)$pdo->query('SELECT COUNT(*) FROM students WHERE archived = 0')->fetchColumn();
                    } catch (Throwable $e) { $total_students = 0; }
                }
                if (in_array('goals', $tbls) && in_array('students', $tbls)) {
                    try {
                        $total_goals = (int)$pdo->query('SELECT COUNT(*) FROM goals g JOIN students s ON g.student_id = s.id WHERE s.archived = 0')->fetchColumn();
                    } catch (Throwable $e) { $total_goals = 0; }
                }
                // Compute total_documents across canonical document tables (match templates/dashboard.php)
                $total_documents = 0;
                try {
                    $docTables = ['goals','initial_evaluations','session_reports','other_documents','discharge_reports'];
                    foreach ($docTables as $tbl) {
                        if (in_array($tbl, $tbls)) {
                            try {
                                $st = $pdo->prepare("SELECT COUNT(*) FROM {$tbl} t JOIN students s ON t.student_id = s.id WHERE s.archived = 0");
                                $st->execute();
                                $total_documents += (int)$st->fetchColumn();
                            } catch (Throwable $e) { /* ignore per-table errors */ }
                        }
                    }
                } catch (Throwable $e) { $total_documents = $total_documents ?? 0; }

                // Prefer a dedicated `progress_reports` table; otherwise fallback to student_reports or legacy reports
                if (in_array('progress_reports', $tbls) && in_array('students', $tbls)) {
                    try {
                        $recent_sessions = (int)$pdo->query('SELECT COUNT(*) FROM progress_reports pr JOIN students s ON pr.student_id = s.id WHERE s.archived = 0')->fetchColumn();
                    } catch (Throwable $e) { $recent_sessions = 0; }
                } elseif (in_array('student_reports', $tbls) && in_array('students', $tbls)) {
                    try {
                        $recent_sessions = (int)$pdo->query('SELECT COUNT(*) FROM student_reports sr JOIN students s ON sr.student_id = s.id WHERE s.archived = 0')->fetchColumn();
                    } catch (Throwable $e) { $recent_sessions = 0; }
                } elseif (in_array('reports', $tbls) && in_array('students', $tbls)) {
                    try {
                        $recent_sessions = (int)$pdo->query('SELECT COUNT(*) FROM reports r JOIN students s ON r.student_id = s.id WHERE s.archived = 0')->fetchColumn();
                    } catch (Throwable $e) { $recent_sessions = 0; }
                }
                if (in_array('progress_updates', $tbls) && in_array('students', $tbls)) {
                    try {
                        $recent_updates = (int)$pdo->query('SELECT COUNT(*) FROM progress_updates p JOIN students s ON p.student_id = s.id WHERE s.archived = 0 AND p.created_at >= datetime("now","-30 days")')->fetchColumn();
                    } catch (Throwable $e) { $recent_updates = 0; }
                }

                echo json_encode(['success' => true, 'total_students' => $total_students, 'total_goals' => $total_goals, 'total_documents' => $total_documents, 'recent_sessions' => $recent_sessions, 'recent_updates' => $recent_updates]);
            } catch (Throwable $e) {
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            }
            break;
        }

        case 'update_student': {
            $studentId = isset($_POST['student_id']) ? (int)$_POST['student_id'] : 0;
            if (!$studentId) { echo json_encode(['success'=>false,'error'=>'Student ID required','message'=>'Student ID required']); break; }

            // Build candidate updates and only include columns that exist in the students table
            $candidates = [
                'first_name' => trim($_POST['first_name'] ?? ''),
                'last_name' => trim($_POST['last_name'] ?? ''),
                'grade' => $_POST['grade'] ?? '',
                'date_of_birth' => $_POST['date_of_birth'] ?? '',
                'age' => $_POST['age'] ?? null,
                'gender' => $_POST['gender'] ?? '',
                'primary_language' => $_POST['primary_language'] ?? '',
                'service_frequency' => $_POST['service_frequency'] ?? '',
                'teacher' => $_POST['teacher'] ?? '',
                'parent_contact' => $_POST['parent_contact'] ?? '',
                'medical_info' => $_POST['medical_info'] ?? '',
                'iep_status' => $_POST['iep_status'] ?? '',
                'updated_at' => date('c')
            ];

            try {
                $pi = $pdo->prepare("PRAGMA table_info('students')");
                $pi->execute();
                $studentCols = array_column($pi->fetchAll(PDO::FETCH_ASSOC), 'name');
            } catch (Throwable $e) {
                $studentCols = [];
            }

            $toUpdate = array_intersect_key($candidates, array_flip($studentCols));
            if (empty($toUpdate)) {
                echo json_encode(['success' => false, 'message' => 'No updatable columns available on students table']);
                break;
            }

            $sets = [];
            $params = [];
            foreach ($toUpdate as $col => $val) {
                $sets[] = "$col = :$col";
                $params[":$col"] = $val;
            }
            $params[':id'] = $studentId;

            $sql = 'UPDATE students SET ' . implode(', ', $sets) . ' WHERE id = :id';
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            echo json_encode(['success'=>true]);
            break;
        }

        // Upload a file and create an other_documents row
        case 'upload_document': {
            try {
                if (empty($_FILES['file'])) throw new Exception('No file uploaded');
                $student_id = isset($_POST['student_id']) ? (int)$_POST['student_id'] : 0;
                if (!$student_id) throw new Exception('Student ID required');
                $title = trim($_POST['title'] ?? 'Uploaded Document');

                // Determine mime type: prefer finfo, then mime_content_type, then client-provided type
                if (function_exists('finfo_open')) {
                    $f = finfo_open(FILEINFO_MIME_TYPE);
                    $mime = finfo_file($f, $_FILES['file']['tmp_name']);
                    finfo_close($f);
                } elseif (function_exists('mime_content_type')) {
                    $mime = mime_content_type($_FILES['file']['tmp_name']);
                } else {
                    $mime = $_FILES['file']['type'] ?? 'application/octet-stream';
                }

                $allowed = ['application/pdf','image/png','image/jpeg'];
                if (!in_array($mime, $allowed)) throw new Exception('File type not allowed: ' . $mime);

                $uploadsDir = __DIR__ . '/../uploads';
                if (!is_dir($uploadsDir)) mkdir($uploadsDir, 0755, true);

                $orig = pathinfo($_FILES['file']['name'], PATHINFO_BASENAME);
                $safeBase = preg_replace('/[^A-Za-z0-9_\-\.]/', '_', $orig);
                $filename = time() . '_' . $safeBase;
                $dest = $uploadsDir . '/' . $filename;
                if (!move_uploaded_file($_FILES['file']['tmp_name'], $dest)) throw new Exception('Failed to move uploaded file');

                // insert metadata into other_documents (schema-aware)
                $insertData = [
                    'student_id' => $student_id,
                    'title' => $title,
                    'metadata' => json_encode(['orig_name'=>$orig,'mime'=>$mime,'size'=>$_FILES['file']['size']]),
                    'file_path' => 'uploads/' . $filename,
                    'created_at' => date('c')
                ];
                // prefer to set form_type when the schema supports it
                $insertData['form_type'] = 'uploaded_file';
                try {
                    $lastId = (int)$safe_insert('other_documents', $insertData);
                } catch (Throwable $e) {
                    // fallback: attempt insert without form_type
                    $stmt = $pdo->prepare('INSERT INTO other_documents (student_id, title, metadata, file_path, created_at) VALUES (:student_id, :title, :metadata, :file_path, :created_at)');
                    $stmt->execute([
                        ':student_id' => $student_id,
                        ':title' => $title,
                        ':metadata' => json_encode(['orig_name'=>$orig,'mime'=>$mime,'size'=>$_FILES['file']['size']]),
                        ':file_path' => 'uploads/' . $filename,
                        ':created_at' => date('c')
                    ]);
                    $lastId = (int)$pdo->lastInsertId();
                }
                try { record_activity('document_uploaded', $student_id, 'Document uploaded: ' . $title, ['document_id' => $lastId, 'file_path' => 'uploads/'.$filename]); } catch (Throwable $e) {}
                echo json_encode(['success'=>true,'id'=>$lastId,'file'=>'uploads/'.$filename]);
            } catch (Exception $e) {
                http_response_code(400);
                echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
            }
            break;
        }

        default:
            echo json_encode(['success'=>false,'error'=>'Unknown action','message'=>'Unknown action']);
            break;
    }

} catch (\Throwable $e) {
    echo json_encode(['success'=>false,'error'=>$e->getMessage(),'message'=>$e->getMessage()]);
}
<?php
// Migrate existing per-student JSON form files into the documents.content column
// and move the original files to an archive folder. Idempotent: skips files already migrated.

$root = __DIR__ . '/../database';
$dbPath = $root . '/slp.db';
// Allow optional source directory as first CLI arg: php migrate_files_to_db_and_archive.php /path/to/old/data
$cliPath = $argv[1] ?? null;
if ($cliPath) {
    $dataDir = rtrim($cliPath, "\/\\");
} else {
    $dataDir = $root . '/data/students';
}
$archiveRoot = $root . '/data/archived_student_files';

if (!file_exists($dbPath)) {
    echo "Database not found at: $dbPath\n";
    exit(1);
}
if (!is_dir($dataDir)) {
    echo "No student data directory found at: $dataDir\n";
    echo "Provide a path to legacy data as the first argument to migrate files, e.g. php migrate_files_to_db_and_archive.php C:/backups/SLPDatabase/data/students\n";
    exit(0);
}

$pdo = new PDO('sqlite:' . $dbPath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dataDir));
$jsonFiles = [];
foreach ($files as $f) {
    if ($f->isFile() && strtolower($f->getExtension()) === 'json') {
        $jsonFiles[] = $f->getPathname();
    }
}

echo "Found " . count($jsonFiles) . " JSON files to inspect...\n";

$inserted = 0; $skipped = 0; $errors = 0;

foreach ($jsonFiles as $filePath) {
    $rel = str_replace(realpath(__DIR__ . '/../') . DIRECTORY_SEPARATOR, '', realpath($filePath));
    // compute relative path from project root for filename column
    $relativeFilename = str_replace('\\', '/', substr($filePath, strlen(realpath(__DIR__ . '/../')) + 1));

    // Skip files already in archiveRoot
    if (strpos($filePath, $archiveRoot) !== false) {
        $skipped++; continue;
    }

    try {
        $content = @file_get_contents($filePath);
        if ($content === false) { echo "Failed to read $filePath\n"; $errors++; continue; }
        $json = json_decode($content, true);
        if ($json === null && json_last_error() !== JSON_ERROR_NONE) {
            echo "Skipping invalid JSON: $filePath\n"; $skipped++; continue;
        }

        // Determine student id heuristic
        $studentId = null;
        if (isset($json['student_id'])) $studentId = (int)$json['student_id'];
        elseif (isset($json['studentId'])) $studentId = (int)$json['studentId'];
        elseif (isset($json['studentName']) && is_numeric($json['studentName'])) $studentId = (int)$json['studentName'];
        else {
            // try from folder name like .../students/student_1/...
            if (preg_match('#students[\\/]+student_(\d+)[\\/]+#', $filePath, $m)) {
                $studentId = (int)$m[1];
            }
        }

        // If still no student id, set null (or skip?) -> we'll set to NULL so it remains discoverable

        // Check if this file has already been migrated: either filename match or identical content
        $check = $pdo->prepare('SELECT id FROM documents WHERE filename = :fn OR content = :content LIMIT 1');
        $check->execute([':fn' => $relativeFilename, ':content' => $content]);
        $exists = $check->fetch(PDO::FETCH_ASSOC);
        if ($exists) { $skipped++; continue; }

        // Prepare title and metadata
        $title = $json['title'] ?? ($json['form_type'] ? (ucwords(str_replace('_',' ',$json['form_type'])) . ' - migrated') : 'Migrated Document');
        $metadata = [];
        if (isset($json['form_type'])) $metadata['form_type'] = $json['form_type'];
        if (isset($json['filename'])) $metadata['source_filename'] = $json['filename'];

        $stmt = $pdo->prepare('INSERT INTO documents (student_id, title, filename, form_type, therapist_id, metadata, content, created_at) VALUES (:student_id, :title, :filename, :form_type, :therapist_id, :metadata, :content, :created_at)');
        $stmt->execute([
            ':student_id' => $studentId,
            ':title' => $title,
            ':filename' => $relativeFilename,
            ':form_type' => $metadata['form_type'] ?? null,
            ':therapist_id' => $metadata['therapist_id'] ?? null,
            ':metadata' => json_encode($metadata),
            ':content' => $content,
            ':created_at' => date('c', filemtime($filePath) ?: time()),
        ]);

        $inserted++;

        // Move the file to archive folder, preserving relative student subpath
        $target = $archiveRoot . '/' . preg_replace('#^' . preg_quote($dataDir, '#') . '#', '', dirname($filePath));
        // normalize target path
        $target = str_replace('\\', '/', $target);
        if (!is_dir($target)) mkdir($target, 0755, true);
        $basename = basename($filePath);
        $archivedName = $basename . '.archived.' . time();
        $dest = $target . '/' . $archivedName;
        if (!rename($filePath, $dest)) {
            echo "Warning: failed to move $filePath to $dest\n"; $errors++; continue;
        }

    } catch (Exception $e) {
        echo "Error processing $filePath: " . $e->getMessage() . "\n";
        $errors++;
    }
}

echo "Migration complete. Inserted: $inserted, Skipped: $skipped, Errors: $errors\n";


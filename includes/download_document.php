<?php
// includes/download_document.php
// Download or view a document from the database

session_start();
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/sqlite.php';

// Check authentication
if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    die('Not authorized');
}

$pdo = get_db();
$userId = $_SESSION['user_id'];

// Get parameters
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$table = isset($_GET['table']) ? trim($_GET['table']) : '';
$inline = isset($_GET['inline']) ? (int)$_GET['inline'] : 0;

if (!$id) {
    http_response_code(400);
    die('Missing document ID');
}

// Valid tables that can contain documents
$validTables = ['initial_evaluations', 'session_reports', 'discharge_reports', 'other_documents', 'goals'];

// If no table specified, try to infer from typical form types or find the document in all tables
if (empty($table)) {
    foreach ($validTables as $t) {
        try {
            $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name=:t");
            $stmt->execute([':t' => $t]);
            if (!$stmt->fetchColumn()) continue;
            
            $stmt = $pdo->prepare("SELECT * FROM {$t} WHERE id = :id LIMIT 1");
            $stmt->execute([':id' => $id]);
            $doc = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($doc) {
                $table = $t;
                break;
            }
        } catch (Exception $e) {
            continue;
        }
    }
}

if (empty($table) || !in_array($table, $validTables)) {
    http_response_code(400);
    die('Invalid or missing table');
}

// Fetch the document
try {
    $stmt = $pdo->prepare("SELECT * FROM {$table} WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $id]);
    $doc = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$doc) {
        http_response_code(404);
        die('Document not found in table: ' . htmlspecialchars($table) . ' with id: ' . $id);
    }
    
    // DEBUG: Log what we're retrieving
    error_log("download_document.php - Table: {$table}, ID: {$id}, Title: " . ($doc['title'] ?? 'N/A') . ", File Path: " . ($doc['file_path'] ?? 'N/A'));
    
    // Verify user owns this student (prefer students.user_id when present; fall back to assigned_therapist)
    if (isset($doc['student_id']) && $doc['student_id']) {
        try {
            $ti = $pdo->prepare("PRAGMA table_info('students')"); $ti->execute(); $cols = array_column($ti->fetchAll(PDO::FETCH_ASSOC),'name');
        } catch (Throwable $e) { $cols = []; }
        if (in_array('user_id', $cols)) {
            $studentStmt = $pdo->prepare("SELECT id FROM students WHERE id = :sid AND user_id = :uid");
            $studentStmt->execute([':sid' => $doc['student_id'], ':uid' => $userId]);
        } elseif (in_array('assigned_therapist', $cols)) {
            $studentStmt = $pdo->prepare(
                "SELECT s.id FROM students s WHERE s.id = :sid AND s.assigned_therapist = :uid"
            );
            $studentStmt->execute([':sid' => $doc['student_id'], ':uid' => $userId]);
        } else {
            // No ownership columns; deny to avoid leakage
            http_response_code(403);
            die('Not authorized to access this document');
        }
        if (!$studentStmt->fetch()) {
            http_response_code(403);
            die('Not authorized to access this document');
        }
    }
    
    // Check if this is an uploaded file with a file_path
    if (!empty($doc['file_path'])) {
        $filePath = __DIR__ . '/../' . ltrim($doc['file_path'], '/');
        
        if (!file_exists($filePath)) {
            http_response_code(404);
            die('File not found on disk: ' . htmlspecialchars($doc['file_path']));
        }
        
        // Determine content type
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $filePath);
        finfo_close($finfo);
        
        // Set headers
        header('Content-Type: ' . $mimeType);
        if ($inline) {
            header('Content-Disposition: inline; filename="' . basename($filePath) . '"');
        } else {
            header('Content-Disposition: attachment; filename="' . basename($filePath) . '"');
        }
        header('Content-Length: ' . filesize($filePath));
        
        // Output file
        readfile($filePath);
        exit;
    }
    
    // If no file_path, check for legacy content or form_data (JSON forms) – legacy only; new inserts don't use content
    $content = null;
    if (array_key_exists('content', $doc) && !empty($doc['content'])) {
        $content = $doc['content'];
    } elseif (!empty($doc['form_data'])) {
        $content = $doc['form_data'];
    }
    
    if ($content) {
        // Try to parse as JSON
        $jsonData = json_decode($content, true);
        
        if ($jsonData) {
            // Generate HTML view of the form data
            $title = $doc['title'] ?? ($doc['form_type'] ?? 'Document');
            $createdAt = $doc['created_at'] ?? date('Y-m-d H:i:s');
            
            header('Content-Type: text/html; charset=utf-8');
            
            echo '<!DOCTYPE html>';
            echo '<html><head>';
            echo '<meta charset="utf-8">';
            echo '<title>' . htmlspecialchars($title) . '</title>';
            echo '<style>';
            echo 'body { font-family: Arial, sans-serif; max-width: 800px; margin: 40px auto; padding: 20px; }';
            echo 'h1 { color: #333; border-bottom: 2px solid #007bff; padding-bottom: 10px; }';
            echo '.meta { color: #666; font-size: 14px; margin-bottom: 20px; }';
            echo 'table { width: 100%; border-collapse: collapse; margin-top: 20px; }';
            echo 'th, td { padding: 12px; text-align: left; border-bottom: 1px solid #ddd; }';
            echo 'th { background-color: #f8f9fa; font-weight: bold; }';
            echo 'tr:hover { background-color: #f8f9fa; }';
            echo '.btn { padding: 10px 20px; margin: 20px 10px 0 0; background: #007bff; color: white; border: none; cursor: pointer; }';
            echo '.btn:hover { background: #0056b3; }';
            echo '@media print { .no-print { display: none; } }';
            echo '</style>';
            echo '</head><body>';
            
            echo '<h1>' . htmlspecialchars($title) . '</h1>';
            echo '<div class="meta">Created: ' . htmlspecialchars(date('F j, Y g:i A', strtotime($createdAt))) . '</div>';
            
            echo '<table>';
            foreach ($jsonData as $key => $value) {
                if ($key === 'id' || $key === 'form_type') continue;
                
                // Format the key to be more readable
                $label = ucwords(str_replace('_', ' ', $key));
                
                // Handle arrays and objects
                if (is_array($value) || is_object($value)) {
                    $value = json_encode($value, JSON_PRETTY_PRINT);
                }
                
                echo '<tr>';
                echo '<th>' . htmlspecialchars($label) . '</th>';
                echo '<td>' . nl2br(htmlspecialchars($value)) . '</td>';
                echo '</tr>';
            }
            echo '</table>';
            
            echo '<div class="no-print">';
            echo '<button class="btn" onclick="window.print()">Print</button>';
            echo '<button class="btn" onclick="window.close()">Close</button>';
            echo '</div>';
            
            echo '</body></html>';
            exit;
        } else {
            // Not JSON, output as plain text
            header('Content-Type: text/plain; charset=utf-8');
            echo $content;
            exit;
        }
    }
    
    // No content available
    http_response_code(404);
    die('No viewable content in this document');
    
} catch (Exception $e) {
    http_response_code(500);
    die('Error: ' . htmlspecialchars($e->getMessage()));
}

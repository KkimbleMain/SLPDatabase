<?php
// Minimal configuration & data helpers for the SLP Database app
// Defines helpers for JSON-backed storage used by the templates and controllers.

if (session_status() === PHP_SESSION_NONE) session_start();

define('DATA_DIR', __DIR__ . '/../database/data');

function loadJsonData(string $name): array {
    // Try SQLite first if available and DB file exists
    $tableMap = [
        'users' => 'users',
        'students' => 'students',
        'documents' => 'documents',
        'goals' => 'goals',
        'progress_updates' => 'progress_updates',
        'reports' => 'reports'
    ];

    // If sqlite helper exists, and the DB file is present, try to read from DB
    try {
        if (file_exists(__DIR__ . '/sqlite.php')) {
            require_once __DIR__ . '/sqlite.php';
            if (function_exists('get_db')) {
                $pdo = get_db();
                if ($pdo instanceof PDO) {
                    if (isset($tableMap[$name])) {
                        $table = $tableMap[$name];
                        // ensure table exists
                        $row = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name=" . $pdo->quote($table))->fetchColumn();
                        if ($row) {
                            $stmt = $pdo->query("SELECT * FROM " . $table . " ORDER BY id ASC");
                            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                            // decode metadata JSON for documents if present
                            if ($name === 'documents') {
                                foreach ($rows as &$r) {
                                    if (!empty($r['metadata']) && is_string($r['metadata'])) {
                                        $meta = json_decode($r['metadata'], true);
                                        $r['metadata'] = $meta === null ? $r['metadata'] : $meta;
                                    }
                                }
                                unset($r);
                            }
                            return is_array($rows) ? $rows : [];
                        }
                    }
                }
            }
        }
    } catch (\Throwable $e) {
        // On any DB error, fall back to JSON file below
    }

    // Legacy on-disk JSON files are no longer used for runtime reads. Return empty array
    // if DB lookup did not yield results. This preserves API while preventing filesystem access.
    return [];
}

/**
 * Save array data to JSON file
 */
function saveJsonData(string $name, array $data): bool {
    // Deprecated: runtime JSON file writes are disabled. The application uses SQLite for all writes.
    // Dev migration scripts may still read or write JSON files directly.
    return false;
}

/**
 * Insert a record into a JSON collection and return the new numeric id.
 */
function insertRecord(string $name, array $record): int {
    // InsertRecord is preserved for compatibility but will not write to disk. Use DB for persistence.
    // If callers expect a numeric id, return a negative sentinel to indicate unsupported at runtime.
    return -1;
}

/**
 * Basic input sanitizer used by dev scripts.
 */
function sanitizeInput($val) {
	if (is_string($val)) return trim(strip_tags($val));
	return $val;
}

/**
 * Find a single record by field in either an array or named JSON collection
 */
function findRecord($collection, string $field, $value) {
	$rows = is_array($collection) ? $collection : loadJsonData((string)$collection);
	foreach ($rows as $r) {
		if (!is_array($r)) continue;
		if (isset($r[$field]) && ((string)$r[$field] === (string)$value)) return $r;
	}
	return null;
}

/**
 * Find many records by matching criteria array
 */
function findRecords($collection, array $criteria = []): array {
	$rows = is_array($collection) ? $collection : loadJsonData((string)$collection);
	if (empty($criteria)) return is_array($rows) ? $rows : [];
	$out = [];
	foreach ($rows as $r) {
		if (!is_array($r)) continue;
		$ok = true;
		foreach ($criteria as $k => $v) {
			if (!array_key_exists($k, $r)) { $ok = false; break; }
			if ((string)$r[$k] !== (string)$v) { $ok = false; break; }
		}
		if ($ok) $out[] = $r;
	}
	return $out;
}

/**
 * Simple CSRF token generator and validator
 */
function generateCSRFToken(): string {
	if (empty($_SESSION['csrf_token'])) {
		$_SESSION['csrf_token'] = bin2hex(random_bytes(16));
	}
	return $_SESSION['csrf_token'];
}

function validateCSRFToken($token): bool {
	return !empty($token) && isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], (string)$token);
}

// Provide a noop placeholder for any missing SQL toggle
if (!defined('USE_SQL')) define('USE_SQL', false);

?>

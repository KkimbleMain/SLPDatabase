<?php
// Minimal configuration & data helpers for the SLP Database app
// Defines helpers for JSON-backed storage used by the templates and controllers.

if (session_status() === PHP_SESSION_NONE) session_start();

define('DATA_DIR', __DIR__ . '/../database/data');

if (!is_dir(DATA_DIR)) {
	// attempt to create data directory if missing
	@mkdir(DATA_DIR, 0755, true);
}

// No local SQL override loaded here; project uses JSON storage by default.

/**
 * Load JSON data file by short name (e.g. 'students', 'users')
 * Returns array on success or empty array on failure.
 */
function loadJsonData(string $name): array {
	$path = DATA_DIR . '/' . basename($name) . '.json';
	if (!file_exists($path)) return [];
	$raw = @file_get_contents($path);
	if ($raw === false) return [];
	$data = json_decode($raw, true);
	return is_array($data) ? $data : [];
}

/**
 * Save array data to JSON file
 */
function saveJsonData(string $name, array $data): bool {
	$path = DATA_DIR . '/' . basename($name) . '.json';
	$json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
	if ($json === false) return false;
	// write atomically
	$tmp = $path . '.tmp';
	$ok = @file_put_contents($tmp, $json);
	if ($ok === false) return false;
	return rename($tmp, $path);
}

/**
 * Insert a record into a JSON collection and return the new numeric id.
 */
function insertRecord(string $name, array $record): int {
	$rows = loadJsonData($name);
	$max = 0;
	foreach ($rows as $r) { $max = max($max, (int)($r['id'] ?? 0)); }
	$id = $max + 1;
	$record['id'] = $id;
	$rows[] = $record;
	saveJsonData($name, $rows);
	return $id;
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

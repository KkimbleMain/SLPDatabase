<?php
// API endpoint that returns the canonical students list with no-cache headers
require_once __DIR__ . '/../includes/config.php';
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
$students = loadJsonData('students') ?: [];
echo json_encode($students);

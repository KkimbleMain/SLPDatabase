<?php
require_once __DIR__ . '/../includes/config.php';
header('Content-Type: application/json');
$out = [];
$out['DATA_DIR'] = defined('DATA_DIR') ? DATA_DIR : null;
$path = (defined('DATA_DIR') ? DATA_DIR . '/students.json' : null);
$out['PATH'] = $path;
$out['EXISTS'] = $path && is_file($path) ? true : false;
$out['RAW'] = $out['EXISTS'] ? @file_get_contents($path) : null;
$out['PARSED'] = $out['EXISTS'] ? json_decode($out['RAW'], true) : null;
echo json_encode($out, JSON_PRETTY_PRINT);

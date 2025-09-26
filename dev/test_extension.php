<?php
header('Content-Type: text/plain; charset=utf-8');
echo "php_ini_loaded_file(): " . (php_ini_loaded_file() ?: '(none)') . "\n\n";
echo "extension_loaded('pdo_sqlite'): " . (extension_loaded('pdo_sqlite') ? 'true' : 'false') . "\n";
echo "extension_loaded('sqlite3'): " . (extension_loaded('sqlite3') ? 'true' : 'false') . "\n";
echo "\nPHP_SAPI: " . PHP_SAPI . "\n";
echo "PHP Version: " . PHP_VERSION . "\n";
echo "extension_dir: " . ini_get('extension_dir') . "\n";
echo "\nPHP modules (short):\n";
$mods = get_loaded_extensions();
sort($mods);
echo implode(', ', $mods);
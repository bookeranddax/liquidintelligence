<?php
// Show errors on this page only (safe to leave while debugging)
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

echo "<pre>Bootstrapping…\n";
$here = __DIR__;
echo "This file: $here/ping.php\n";

require_once __DIR__ . '/_bootstrap.php';
echo "_bootstrap loaded\n";

$cfgPath = realpath(__DIR__ . '/../lib/db.php');
echo "db.php at: $cfgPath\n";

$pdo = get_pdo();
echo "DB OK\n";

// Try a trivial query
$stmt = $pdo->query('SELECT 1 AS ok');
var_dump($stmt->fetch());
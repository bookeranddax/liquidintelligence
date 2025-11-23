<?php
// /home/cocktail_user/liquidintelligence.cookingissues.com/dbcheck.php
ini_set('display_errors', 1);
error_reporting(E_ALL);

$cfg = require __DIR__ . '/config.php';

header('Content-Type: text/plain');

echo "PHP: " . PHP_VERSION . "\n";

if (!extension_loaded('pdo_mysql')) {
  echo "ERROR: pdo_mysql extension not loaded\n";
  exit(1);
}

try {
  $dsn = "mysql:host={$cfg['db']['host']};dbname={$cfg['db']['name']};charset={$cfg['db']['charset']}";
  $pdo = new PDO($dsn, $cfg['db']['user'], $cfg['db']['pass'], [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
  ]);

  $row = $pdo->query('SELECT NOW() AS mysql_time')->fetch();
  echo "DB OK. MySQL time: {$row['mysql_time']}\n";
} catch (Throwable $e) {
  echo "DB ERROR: " . $e->getMessage() . "\n";
  // Helpful extra info for DNS/host issues
  if (strpos($e->getMessage(), 'getaddrinfo') !== false) {
    echo "Hint: check the MySQL *hostname* in the DreamHost panel.\n";
  }
  if (strpos($e->getMessage(), 'Access denied') !== false) {
    echo "Hint: check the MySQL user/password and that the user is granted on this DB.\n";
  }
}

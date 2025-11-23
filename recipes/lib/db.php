<?php
// /recipes/lib/db.php
declare(strict_types=1);

function get_pdo(): PDO {
  static $pdo = null;
  if ($pdo instanceof PDO) return $pdo;

  // Load the root config and CAPTURE the returned array
  $cfgPath = __DIR__ . '/../../config.php';
  if (!file_exists($cfgPath)) {
    throw new RuntimeException('Missing config.php at ' . $cfgPath);
  }
  $RET = require $cfgPath;
  if (!is_array($RET) || !isset($RET['db']) || !is_array($RET['db'])) {
    throw new RuntimeException('Root config.php must return an array with a "db" key.');
  }

  $db = $RET['db'];
  $host = (string)($db['host'] ?? '');
  $name = (string)($db['name'] ?? '');
  $user = (string)($db['user'] ?? '');
  $pass = (string)($db['pass'] ?? '');
  $charset = (string)($db['charset'] ?? 'utf8mb4');

  if ($host === '' || $name === '' || $user === '') {
    throw new RuntimeException('Missing DB config values: host/name/user');
  }

  // DreamHost tip: host must be the DH MySQL hostname (NOT "localhost")
  $dsn = 'mysql:host=' . $host . ';dbname=' . $name . ';charset=' . $charset;
  $opts = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
  ];
  $pdo = new PDO($dsn, $user, $pass, $opts);
  return $pdo;
}

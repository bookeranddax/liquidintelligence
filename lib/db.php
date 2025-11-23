<?php
declare(strict_types=1);

// lib/db.php
$config = require __DIR__ . '/../config.php';

function db(): PDO {
  static $pdo = null;
  global $config;
  if ($pdo instanceof PDO) return $pdo;

  $dsn = sprintf(
    'mysql:host=%s;dbname=%s;charset=%s',
    $config['db']['host'],
    $config['db']['name'],
    $config['db']['charset'] ?? 'utf8mb4'
  );
  $pdo = new PDO($dsn, $config['db']['user'], $config['db']['pass'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ]);
  return $pdo;
}

function dbcfg(): array {
  global $config;
  return $config['db'];
}

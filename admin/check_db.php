<?php
require_once __DIR__ . '/../lib/db.php';
try {
  $pdo = db();
  $r = $pdo->query('SELECT COUNT(*) AS c FROM mix_props')->fetch();
  echo "Connected to DB. Rows in mix_props: " . (int)$r['c'];
} catch (Throwable $e) {
  http_response_code(500);
  echo "DB ERROR: " . $e->getMessage();
}

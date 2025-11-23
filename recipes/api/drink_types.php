<?php
// /recipes/api/drink_types.php
declare(strict_types=1);

// Load bootstrap FIRST (handlers + buffer are ready before anything else)
require_once __DIR__ . '/_bootstrap.php';

try {
  require_method('GET');
  $pdo = get_pdo();

  // Table: drink_type (id, drink_type)
  $sql = "SELECT id, drink_type, drink_type AS type_name
          FROM drink_type
          ORDER BY drink_type";
  $st = $pdo->query($sql);
  $rows = $st ? $st->fetchAll(PDO::FETCH_ASSOC) : [];

  json_out(['items' => $rows], 200);

} catch (Throwable $e) {
  json_out(['error' => 'Server error', 'detail' => $e->getMessage()], 500);
}

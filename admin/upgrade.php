<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/db.php';
$pdo = pdo();

$pdo->exec("ALTER TABLE mix_data 
  ADD UNIQUE KEY uq_tc_abm_sbm (T_C, ABM, SBM),
  ADD KEY idx_tc_abv (T_C, ABV),
  ADD KEY idx_tc_sugar (T_C, Sugar_WV)
");

echo "OK: indexes ensured.";

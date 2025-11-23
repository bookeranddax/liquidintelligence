<?php
// /admin/migrate.php
declare(strict_types=1);
require_once __DIR__ . '/../includes/db.php';

$pdo = pdo();

$sql = <<<SQL
CREATE TABLE IF NOT EXISTS mix_data (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  T_C        DECIMAL(4,1)   NOT NULL,
  ABM        DECIMAL(6,3)   NOT NULL,
  SBM        DECIMAL(6,3)   NOT NULL,
  ABV        DECIMAL(6,3)   NOT NULL,
  Sugar_WV   DECIMAL(8,3)   NOT NULL,
  nD         DECIMAL(8,6)   NOT NULL,
  Density    DECIMAL(8,6)   NOT NULL,
  BrixATC    DECIMAL(6,3)   NOT NULL,
  KEY idx_tc_density (T_C, Density),
  KEY idx_tc_brix    (T_C, BrixATC),
  KEY idx_abm_sbm    (ABM, SBM),
  KEY idx_abv_tc     (ABV, T_C)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL;

$pdo->exec($sql);
echo "OK: table mix_data is ready.";

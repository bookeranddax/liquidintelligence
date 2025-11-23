CREATE TABLE IF NOT EXISTS mix_properties (
  ABM       FLOAT NOT NULL,
  SBM       FLOAT NOT NULL,
  T_C       FLOAT NOT NULL,
  ABV       FLOAT NULL,
  Sugar_WV  FLOAT NULL,
  nD        FLOAT NULL,
  Density   FLOAT NULL,
  BrixATC   FLOAT NULL,
  PRIMARY KEY (ABM, SBM, T_C),
  INDEX idx_t (T_C),
  INDEX idx_abm_sbm (ABM, SBM)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

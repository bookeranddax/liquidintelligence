Liquid Intelligence PHP Starter
================================

Quick start
-----------
1) Upload the contents of this folder to your subdomain's web root, e.g.
   /home/<user>/liquidintelligence.cookingissues.com/

2) Edit config.php with your DreamHost MySQL credentials (DB_HOST/DB_NAME/DB_USER/DB_PASS).

3) Create the database table:
   https://liquidintelligence.cookingissues.com/admin/migrate.php

4) Upload your CSV (e.g., ALC_SUGForCalc.csv) into /data/ via SFTP, then import it:
   https://liquidintelligence.cookingissues.com/admin/import_csv.php?file=/data/ALC_SUGForCalc.csv

   Notes:
   - BrixATC and nD values of 9999 are treated as NULL automatically.
   - You can re-run the import to add rows (no dedupe). If you need to start over: TRUNCATE mix_props.

5) Check DB connectivity and row count:
   https://liquidintelligence.cookingissues.com/admin/check_db.php

6) Try the web form:
   https://liquidintelligence.cookingissues.com/public/index.php

API contract
------------
POST /api/solve.php
{
  "mode": "brix_density",                       // or abv_brix, abv_density, abv_sugarwv, abm_sbm
  "input": { "brix": 12.3, "t_brix": 23.5, "density": 0.9921, "t_density": 22.0 },
  "out_temp": 20.0                               // optional for abm_sbm; required for others if you want outputs
}

Response fields
---------------
- ok: true/false — whether your two inputs are mutually consistent within tolerances.
- composition: { abm, sbm } — solved alcohol-by-mass & sugar-by-mass (temperature independent).
- residuals: differences between your inputs and the nearest point implied by the table.
- props: all other properties interpolated at out_temp (ABV, Sugar_WV, nD, Density, BrixATC).
- diagnostics (when ok=false): approximate valid ranges for one property given the other.

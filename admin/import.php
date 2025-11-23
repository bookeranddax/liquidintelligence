<?php // admin/import.php ?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <title>CSV Import</title>
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <style>
    body{font:16px/1.4 system-ui,Segoe UI,Roboto,Helvetica,Arial,sans-serif;margin:2rem;max-width:880px}
    fieldset{border:1px solid #ddd;border-radius:8px;padding:1rem 1.25rem;margin:1rem 0}
    legend{padding:0 .5rem;font-weight:600}
    label{display:block;margin:.5rem 0}
    input[type=file]{margin:.25rem 0 .75rem}
    .small{color:#666;font-size:.9em}
    button{padding:.6rem 1rem;border-radius:8px;border:1px solid #ccc;background:#f7f7f7;cursor:pointer}
    button:hover{background:#eee}
    code{background:#f5f5f5;padding:.1rem .3rem;border-radius:4px}
  </style>
</head>
<body>
  <h1>Import mixture CSV</h1>

  <p class="small">
    Max upload: <code><?=ini_get('upload_max_filesize')?></code> &middot;
    Max POST: <code><?=ini_get('post_max_size')?></code> &middot;
    Memory limit: <code><?=ini_get('memory_limit')?></code>
  </p>

  <form action="import_csv.php" method="post" enctype="multipart/form-data">
    <fieldset>
      <legend>CSV file</legend>
      <label>
        Choose file:
        <input type="file" name="csv" accept=".csv,text/csv" required>
      </label>
      <label>
        <input type="checkbox" name="dry_run" value="1" checked>
        Dry run (don’t write to DB)
      </label>
    </fieldset>

    <fieldset>
      <legend>Notes</legend>
      <ul class="small">
        <li>Header row required. Columns (any order): <code>T_C, ABM, SBM, ABV, Sugar_WV, nD, Density, BrixATC</code>.
            An extra leading <code>ID</code> column is OK; it will be ignored.</li>
        <li>Sentinel <code>9999</code> in <code>nD</code> or <code>BrixATC</code> is stored as <code>NULL</code>.</li>
        <li>Upsert key: <code>(T_C, ABM, SBM)</code>. Existing rows will be updated.</li>
      </ul>
    </fieldset>

    <button type="submit">Upload &amp; Import</button>
  </form>
</body>
</html>

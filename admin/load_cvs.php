<?php
require_once __DIR__ . '/../lib/db.php';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
  header('Content-Type: text/html; charset=utf-8');
  echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Load CSV</title></head><body>';
  echo '<h2>Upload ALC_SUGForCalc.csv</h2>';
  echo '<form method="POST" enctype="multipart/form-data">';
  echo '<input type="file" name="csv" accept=".csv,text/csv" required> ';
  echo '<button>Import</button>';
  echo '</form>';
  echo '<p>Expected headers: <code>T_C,ABM,SBM,ABV,Sugar_WV,nD,Density,BrixATC</code></p>';
  echo '</body></html>';
  exit;
}

if (!isset($_FILES['csv']) || $_FILES['csv']['error'] !== UPLOAD_ERR_OK) {
  http_response_code(400);
  echo "Upload failed."; exit;
}

$tmp = $_FILES['csv']['tmp_name'];
$fh = fopen($tmp, 'r');
if (!$fh) { http_response_code(400); echo "Cannot open upload."; exit; }

$pdo = db();
$pdo->exec(file_get_contents(__DIR__ . '/../schema.sql'));
$pdo->beginTransaction();

$header = fgetcsv($fh);
if (!$header) { http_response_code(400); echo "Missing header."; exit; }
$norm = [];
foreach ($header as $h) { $norm[] = strtolower(trim($h)); }
$idx = array_flip($norm);

$needed = ['t_c','abm','sbm','abv','sugar_wv','nd','density','brixatc'];
foreach ($needed as $n) if (!isset($idx[$n])) { http_response_code(400); echo "Missing column: $n"; exit; }

$ins = $pdo->prepare("REPLACE INTO mix_properties (ABM,SBM,T_C,ABV,Sugar_WV,nD,Density,BrixATC) VALUES (?,?,?,?,?,?,?,?)");
$rows = 0;
while (($row = fgetcsv($fh)) !== false) {
  if (count($row) < count($header)) continue;
  $T  = (float)$row[$idx['t_c']];
  $ABM= (float)$row[$idx['abm']];
  $SBM= (float)$row[$idx['sbm']];
  $ABV= trim($row[$idx['abv']]) === '' ? null : (float)$row[$idx['abv']];
  $Sugar = trim($row[$idx['sugar_wv']]) === '' ? null : (float)$row[$idx['sugar_wv']];
  $nD   = trim($row[$idx['nd']]) === '' ? null : (float)$row[$idx['nd']];
  $Density = trim($row[$idx['density']]) === '' ? null : (float)$row[$idx['density']];
  $Brix = trim($row[$idx['brixatc']]) === '' ? null : (float)$row[$idx['brixatc']];
  // Convert sentinel 9999 in Brix or nD to NULL
  if ($Brix !== null && abs($Brix - 9999) < 1e-6) $Brix = null;
  if ($nD !== null && abs($nD - 9999) < 1e-6) $nD = null;
  $ins->execute([$ABM,$SBM,$T,$ABV,$Sugar,$nD,$Density,$Brix]);
  $rows++;
}
$pdo->commit();
fclose($fh);

header('Content-Type: text/plain; charset=utf-8');
echo "Imported $rows rows.\n";

<?php
// admin/import_csv.php

declare(strict_types=1);

// Load DB config array
$config = require __DIR__ . '/../config.php';
$db = $config['db'] ?? [];
$dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', $db['host'], $db['name'], $db['charset']);
$pdo = new PDO($dsn, $db['user'], $db['pass'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

// --- helpers ---
function normNum(?string $v): ?string {
    if ($v === null) return null;
    $v = trim($v);
    if ($v === '' || strcasecmp($v, 'NULL') === 0) return null;
    // Accept comma decimals
    $v = str_replace(',', '.', $v);
    // Keep as string; PDO will cast appropriately with PARAM_NULL/PARAM_STR
    return is_numeric($v) ? $v : null;
}
function sentinelToNull(?string $v): ?string {
    $v = normNum($v);
    if ($v === null) return null;
    // Treat 9999 (e.g., "9999", "9999.0") as NULL
    return (float)$v >= 9999 ? null : $v;
}
function requiredColsPresent(array $have, array $need): array {
    $missing = [];
    foreach ($need as $c) if (!in_array($c, $have, true)) $missing[] = $c;
    return $missing;
}

// --- input ---
if (!isset($_FILES['csv']) || $_FILES['csv']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo "No CSV uploaded or upload error.";
    exit;
}
$dryRun = isset($_POST['dry_run']) && $_POST['dry_run'] === '1';

// Open CSV
$path = $_FILES['csv']['tmp_name'];
$fh = fopen($path, 'r');
if (!$fh) {
    http_response_code(500);
    echo "Failed to open uploaded CSV.";
    exit;
}

// Read header
$header = fgetcsv($fh);
if ($header === false) {
    echo "Empty CSV.";
    exit;
}
$header = array_map('trim', $header);

// Normalize header keys => lower for lookup, but keep originals to map precisely
$index = [];
foreach ($header as $i => $name) {
    $index[strtolower($name)] = $i;
}

// Required columns by name (case-insensitive)
$required = ['t_c','abm','sbm','abv','sugar_wv','nd','density','brixatc'];
$missing = requiredColsPresent(array_map('strtolower', $header), $required);
if (!empty($missing)) {
    echo "Missing required columns: " . implode(', ', $missing);
    exit;
}

// Prepared upsert
$sql = "
INSERT INTO mix_data
  (T_C, ABM, SBM, ABV, Sugar_WV, nD, Density, BrixATC)
VALUES
  (:t, :abm, :sbm, :abv, :sug, :nd, :dens, :brix)
ON DUPLICATE KEY UPDATE
  ABV = VALUES(ABV),
  Sugar_WV = VALUES(Sugar_WV),
  nD = VALUES(nD),
  Density = VALUES(Density),
  BrixATC = VALUES(BrixATC)
";
$stmt = $pdo->prepare($sql);

// Counters
$inserted = 0; $updated = 0; $skipped = 0; $errors = 0;
$lineNo = 1; // header = line 1
$errSamples = [];

if (!$dryRun) $pdo->beginTransaction();

while (($row = fgetcsv($fh)) !== false) {
    $lineNo++;
    // Some CSVs may have blank trailing lines
    if (count(array_filter($row, fn($v) => trim((string)$v) !== '')) === 0) {
        $skipped++;
        continue;
    }

    // Map by name (ignore unknown columns, including an optional leading ID)
    $val = function(string $col) use ($index, $row): ?string {
        $k = strtolower($col);
        if (!array_key_exists($k, $index)) return null;
        $i = $index[$k];
        return $row[$i] ?? null;
    };

    // Required base keys (numeric)
    $T_C  = normNum($val('T_C'));
    $ABM  = normNum($val('ABM'));
    $SBM  = normNum($val('SBM'));

    // If any of the key trio is missing, skip
    if ($T_C === null || $ABM === null || $SBM === null) {
        $skipped++;
        continue;
    }

    // Other values
    $ABV       = normNum($val('ABV'));
    $Sugar_WV  = normNum($val('Sugar_WV'));
    $nD        = sentinelToNull($val('nD'));         // map 9999 -> NULL
    $Density   = normNum($val('Density'));
    $BrixATC   = sentinelToNull($val('BrixATC'));    // map 9999 -> NULL

    try {
        if ($dryRun) {
            // Probe if row would be insert or update
            $probe = $pdo->prepare("SELECT id FROM mix_data WHERE T_C = ? AND ABM = ? AND SBM = ?");
            $probe->execute([$T_C, $ABM, $SBM]);
            $exists = $probe->fetchColumn() !== false;
            if ($exists) $updated++; else $inserted++;
        } else {
            $stmt->execute([
                ':t'    => $T_C,
                ':abm'  => $ABM,
                ':sbm'  => $SBM,
                ':abv'  => $ABV,
                ':sug'  => $Sugar_WV,
                ':nd'   => $nD,
                ':dens' => $Density,
                ':brix' => $BrixATC,
            ]);
            // rowCount() returns 1 for insert, 2 for update in MySQL with ON DUP KEY
            $rc = $stmt->rowCount();
            if ($rc === 1) $inserted++;
            elseif ($rc === 2) $updated++;
            else $inserted++; // conservative
        }
    } catch (Throwable $e) {
        $errors++;
        if (count($errSamples) < 10) {
            $errSamples[] = "Line $lineNo error: " . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }
    }
}

fclose($fh);
if (!$dryRun) $pdo->commit();

// Report
echo $dryRun ? "Dry-run complete. " : "Import complete. ";
echo "Inserted $inserted, updated $updated, skipped $skipped, errors $errors.";
if ($errors && $errSamples) {
    echo "<br>Sample errors:<br><ul>";
    foreach ($errSamples as $m) echo "<li>$m</li>";
    echo "</ul>";
}

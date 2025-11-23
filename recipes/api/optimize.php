<?php
// /recipes/api/optimize.php
declare(strict_types=1);
ini_set('display_errors','1'); ini_set('display_startup_errors','1'); error_reporting(E_ALL);

require_once __DIR__ . '/_bootstrap.php';

// ---- Fatal catcher + strict error handler (put near top) ----
set_error_handler(function($severity, $message, $file, $line){
  // Convert warnings/notices to exceptions so they hit our try/catch
  if (!(error_reporting() & $severity)) return false;
  throw new ErrorException($message, 0, $severity, $file, $line);
});
register_shutdown_function(function(){
  $e = error_get_last();
  if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
      'error'  => 'Fatal',
      'detail' => $e['message'].' @ '.$e['file'].':'.$e['line']
    ], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  }
});

// Fallback INF for hosts that don’t define it
if (!defined('INF')) { define('INF', 1e308); }

$pdo = get_pdo();
$uid = current_user_id();

function out($data, int $code=200){
  http_response_code($code);
  header('Content-Type: application/json');
  echo json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  exit;
}

// Visibility clause with unique placeholders
function vis_clause(string $alias, ?int $uid, string $ph): string {
  if ($uid) return "($alias.is_public = 1 OR $alias.owner_id = :$ph)";
  return "($alias.is_public = 1)";
}

// Safe number
function f($x, $def=0.0){ return isset($x) && $x!=='' ? (float)$x : (float)$def; }

// ---------- SPOKEN QUANTIZATION HELPERS ----------
function q_round($x, $step){ return $step > 0 ? round($x / $step) * $step : $x; }
function q_clamp($x, $lo, $hi){
  if ($lo !== null && $x < $lo) $x = $lo;
  if ($hi !== null && $x > $hi) $x = $hi;
  return $x;
}
function is_finite_num($v){ return $v !== INF && $v !== -INF && is_numeric($v); }

// Convert ml to the nearest "spoken" amount (in ml)
function spoken_quantize_ml(float $ml): float {
  if ($ml <= 0) return 0.0;

  $ML_PER_OZ   = 30.0;
  $ML_PER_BSP  = 3.75;
  $ML_PER_DASH = 0.82;
  $ML_PER_DROP = 0.05;

  // A) Below one dash => drops
  if ($ml < $ML_PER_DASH) {
    $drops = max(1, (int)round($ml / $ML_PER_DROP));
    return $drops * $ML_PER_DROP;
  }
  // B) 0.82 – 3.5 mL => half-dashes
  if ($ml < 3.5) {
    $halfDashes = max(1, (int)round(($ml / $ML_PER_DASH) * 2.0));
    return ($halfDashes / 2.0) * $ML_PER_DASH;
  }
  // C) 3.5 – 31.3 mL => 24ths (1.25 mL steps), clamped to your table range
  if ($ml < 31.3) {
    $n24 = max(3, min(25, (int)round($ml / 1.25)));
    return $n24 * 1.25;
  }
  // D) ≥ 31.3 mL => 12ths (2.5 mL steps)
  $int12 = max(0, (int)round($ml / 2.5));
  return $int12 * 2.5;
}
function quantize_vector_spoken(array $ml, array $lo, array $hi): array {
  $out = $ml; $n = count($ml);
  for ($k=0; $k<$n; $k++){
    $q = spoken_quantize_ml(max(0.0, $ml[$k]));
    $loK = is_finite_num($lo[$k]) ? $lo[$k] : null;
    $hiK = is_finite_num($hi[$k]) ? $hi[$k] : null;
    $out[$k] = q_clamp($q, $loK, $hiK);
  }
  return $out;
}

try{
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') out(['error'=>'method not allowed'], 405);
  if (!$uid) out(['error'=>'Login required'], 401);

  $drink_id = (int)($_POST['drink_id'] ?? 0);
  if ($drink_id<=0) out(['error'=>'drink_id required'], 400);

  // New flags/fields (with defaults)
  $basis           = ($_POST['basis'] ?? 'undiluted') === 'diluted' ? 'diluted' : 'undiluted';
  $granularity_ml  = f($_POST['granularity_ml'] ?? 0.0, 0.0); // 0=exact
  $quant_spoken    = ($_POST['quantize_spoken'] ?? '0') === '1';
  $lock_volume     = ($_POST['lock_volume'] ?? '0') === '1';

  $dilution_mode   = $_POST['dilution_mode'] ?? 'shake'; // shake|stir|custom
  $custom_pct      = f($_POST['custom_pct'] ?? 0.0, 0.0); // percent value (e.g., 11.0)

  // 1) Load current rows (ingredients + current ml preferring mlTest) respecting visibility
  $whereR = vis_clause('r', $uid, 'uidR');
  $whereI = vis_clause('i', $uid, 'uidI');

  $sql = "SELECT
            r.id, r.drink_id, r.ingredient_id,
            r.mlParts, r.mlTest, r.hold, r.testLow, r.testHigh,
            i.ingredient, i.ethanol, i.sweetness, i.titratable_acid
          FROM recipes r
          JOIN ingredients i ON i.id = r.ingredient_id
          WHERE r.drink_id = :drink_id AND $whereR AND $whereI
          ORDER BY COALESCE(r.position, 9999), i.ingredient";

  $st = $pdo->prepare($sql);
  $params = [':drink_id'=>$drink_id];
  if ($uid){ $params[':uidR'] = $uid; $params[':uidI'] = $uid; }
  $st->execute($params);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);

  if (!$rows) out(['error'=>'No rows to optimize for this drink. Add ingredients first.'], 400);

  // 2) Current vector from rows
  $n = count($rows);
  $ml = [];    // working ml (start from mlTest or mlParts)
  $sens = [];  // per-ingredient sensitivities: [V, Alc, Sugar, Acid]
  $hold = [];
  $lo   = [];
  $hi   = [];

  for ($k=0; $k<$n; $k++){
    $r   = $rows[$k];
    $v   = isset($r['mlTest']) && $r['mlTest'] !== null ? (float)$r['mlTest'] : (float)($r['mlParts'] ?? 0.0);
    $eth = (float)($r['ethanol'] ?? 0.0);             // (mL EtOH / mL ing)
    $sug = (float)($r['sweetness'] ?? 0.0);           // (g sugar / mL ing)
    $acd = (float)($r['titratable_acid'] ?? 0.0);     // (g acid  / mL ing)

    $ml[$k]   = max(0.0, $v);
    $sens[$k] = [1.0, $eth, $sug, $acd];

    $hold[$k] = (int)($r['hold'] ?? 0) ? true : false;
    $lo[$k]   = isset($r['testLow'])  && $r['testLow']  !== '' ? (float)$r['testLow']  : -INF;
    $hi[$k]   = isset($r['testHigh']) && $r['testHigh'] !== '' ? (float)$r['testHigh'] : +INF;
  }

  // 3) Current UNDILUTED totals (volume mL, alcohol mL, sugar g, acid g)
  $cur = [0.0, 0.0, 0.0, 0.0];
  for ($k=0; $k<$n; $k++){
    $cur[0] += $ml[$k];
    $cur[1] += $ml[$k]*$sens[$k][1];
    $cur[2] += $ml[$k]*$sens[$k][2];
    $cur[3] += $ml[$k]*$sens[$k][3];
  }

  // 4) Load targets; if blank, fall back to current (so you can optimize only some fields)
  $st = $pdo->prepare("SELECT * FROM targets
                       WHERE drink_id=? AND (owner_id=? OR owner_id IS NULL)
                       ORDER BY owner_id DESC LIMIT 1");
  $st->execute([$drink_id, $uid]);
  $t = $st->fetch(PDO::FETCH_ASSOC) ?: [];

  $t_vol_ml    = isset($t['target_vol_ml'])     && $t['target_vol_ml']     !== '' ? (float)$t['target_vol_ml']     : $cur[0];
  $t_abv_pct   = isset($t['target_abv_pct'])    && $t['target_abv_pct']    !== '' ? (float)$t['target_abv_pct']    : ($cur[0] ? ($cur[1]/$cur[0])*100.0 : 0.0);
  $t_sugar_pct = isset($t['target_sugar_pct'])  && $t['target_sugar_pct']  !== '' ? (float)$t['target_sugar_pct']  : ($cur[0] ? ($cur[2]/$cur[0])*100.0 : 0.0);
  $t_acid_pct  = isset($t['target_acid_pct'])   && $t['target_acid_pct']   !== '' ? (float)$t['target_acid_pct']   : ($cur[0] ? ($cur[3]/$cur[0])*100.0 : 0.0);

  // Convert percent targets to absolute amounts at chosen basis (undiluted)
  $t_abs_mix = [
    max(0.0, $t_vol_ml),
    max(0.0, $t_vol_ml * ($t_abv_pct/100.0)),
    max(0.0, $t_vol_ml * ($t_sugar_pct/100.0)),
    max(0.0, $t_vol_ml * ($t_acid_pct/100.0)),
  ];

  // If basis is diluted, back out dilution to equivalent undiluted target
  if ($basis === 'diluted') {
    $abvFrac = $cur[0] > 0 ? ($cur[1] / $cur[0]) : 0.0;
    $poly = (-1.5366*$abvFrac*$abvFrac) + (1.7082*$abvFrac) + 0.1991;
    if     ($dilution_mode === 'shake') $d = max(0.0, $poly * 1.02);
    elseif ($dilution_mode === 'stir')  $d = max(0.0, $poly * 0.73);
    else                                $d = max(0.0, $custom_pct/100.0);

    if ($d > 0) {
      $t_abs_mix[0] /= (1.0 + $d);
      $t_abs_mix[1] /= (1.0 + $d);
      $t_abs_mix[2] /= (1.0 + $d);
      $t_abs_mix[3] /= (1.0 + $d);
    }
  }

  // ---------- ITERATED SOLVER (least-squares + clamp + quantize) ----------
  $max_iter = 5;     // small, fast loop
  $lambda   = 1e-6;  // ridge
  for ($iter=0; $iter<$max_iter; $iter++) {

    // Recompute current totals (undiluted) from ml[]
    $cur = [0.0, 0.0, 0.0, 0.0];
    for ($k=0; $k<$n; $k++){
      $cur[0] += $ml[$k];
      $cur[1] += $ml[$k]*$sens[$k][1];
      $cur[2] += $ml[$k]*$sens[$k][2];
      $cur[3] += $ml[$k]*$sens[$k][3];
    }

    $res = [
      $t_abs_mix[0] - $cur[0],
      $t_abs_mix[1] - $cur[1],
      $t_abs_mix[2] - $cur[2],
      $t_abs_mix[3] - $cur[3],
    ];

    // Free variables only
    $freeIdx = [];
    for ($i=0; $i<$n; $i++) if (!$hold[$i]) $freeIdx[] = $i;
    $m = count($freeIdx);
    if ($m === 0) break;

    // Build ATA (m x m) and ATb (m)
    $ATA = array_fill(0, $m, array_fill(0, $m, 0.0));
    $ATb = array_fill(0, $m, 0.0);

    for ($ai=0; $ai<$m; $ai++){
      $i = $freeIdx[$ai];
      $Si = $sens[$i]; // [1,eth,sug,acid]
      for ($aj=0; $aj<$m; $aj++){
        $j  = $freeIdx[$aj];
        $Sj = $sens[$j];
        $sum = 0.0;
        $sum += ($lock_volume ? 1.0 : 0.0) * $Si[0]*$Sj[0]; // volume row
        $sum += 1.0 * $Si[1]*$Sj[1]; // alc
        $sum += 1.0 * $Si[2]*$Sj[2]; // sugar
        $sum += 1.0 * $Si[3]*$Sj[3]; // acid
        $ATA[$ai][$aj] += $sum;
      }
      $rhs = 0.0;
      $rhs += ($lock_volume ? 1.0 : 0.0) * $Si[0]*$res[0];
      $rhs += 1.0 * $Si[1]*$res[1];
      $rhs += 1.0 * $Si[2]*$res[2];
      $rhs += 1.0 * $Si[3]*$res[3];
      $ATb[$ai] += $rhs;
    }

    // Ridge
    for ($i=0; $i<$m; $i++) $ATA[$i][$i] += $lambda;

    // Solve (ATA) d = ATb
    $AUG = [];
    for ($i=0; $i<$m; $i++){ $row = $ATA[$i]; $row[] = $ATb[$i]; $AUG[] = $row; }

    for ($col=0; $col<$m; $col++){
      $pivot = $col; $maxv = abs($AUG[$pivot][$col]);
      for ($r=$col+1; $r<$m; $r++){ $v = abs($AUG[$r][$col]); if ($v > $maxv){ $maxv=$v; $pivot=$r; } }
      if ($maxv < 1e-12) continue;
      if ($pivot !== $col){ $tmp=$AUG[$col]; $AUG[$col]=$AUG[$pivot]; $AUG[$pivot]=$tmp; }
      $diag = $AUG[$col][$col];
      for ($c=$col; $c<=$m; $c++) $AUG[$col][$c] /= $diag;
      for ($r=0; $r<$m; $r++){
        if ($r === $col) continue;
        $f = $AUG[$r][$col]; if ($f == 0.0) continue;
        for ($c=$col; $c<=$m; $c++){ $AUG[$r][$c] -= $f * $AUG[$col][$c]; }
      }
    }

    $d = array_fill(0, $m, 0.0);
    for ($i=0; $i<$m; $i++){ $d[$i] = $AUG[$i][$m] ?? 0.0; }

    // Update ml for free vars
    for ($ai=0; $ai<$m; $ai++){
      $k = $freeIdx[$ai];
      $new = $ml[$k] + $d[$ai];
      if ($new < 0.0) $new = 0.0;
      if ($lo[$k] != -INF) $new = max($new, $lo[$k]);
      if ($hi[$k] != +INF) $new = min($new, $hi[$k]);
      $ml[$k] = $new;
    }

    // Quantize (generic step OR full spoken)
    if ($quant_spoken) {
      $ml = quantize_vector_spoken($ml, $lo, $hi);
    } elseif ($granularity_ml > 0) {
      for ($k=0; $k<$n; $k++){
        if ($hold[$k]) continue;
        $g = $granularity_ml;
        $new = q_round($ml[$k], $g);
        if ($lo[$k] != -INF) $new = max($new, $lo[$k]);
        if ($hi[$k] != +INF) $new = min($new, $hi[$k]);
        $ml[$k] = max(0.0, $new);
      }
    }

    // crude stopping
    $resNorm = abs($res[0]) + abs($res[1]) + abs($res[2]) + abs($res[3]);
    if ($resNorm < 1e-6) break;
  }

  
  // ---------- Post-solve: totals, warnings, match assessment ----------
  // Compute final totals (mix basis absolute amounts)
  $cur = [0.0, 0.0, 0.0, 0.0];
  for ($k=0; $k<$n; $k++){
    $cur[0] += $ml[$k];
    $cur[1] += $ml[$k]*$sens[$k][1];
    $cur[2] += $ml[$k]*$sens[$k][2];
    $cur[3] += $ml[$k]*$sens[$k][3];
  }

  // Warnings container
  $warnings = $warnings ?? [];

  // Warn on volume miss only when lock_volume is false
  if (!$lock_volume && is_finite_num($t_abs_mix[0])){
    $targetV   = $t_abs_mix[0];
    $achievedV = $cur[0];
    $deltaV    = $achievedV - $targetV;
    $absDelta  = abs($deltaV);
    $pct       = ($targetV != 0.0) ? ($absDelta / abs($targetV)) : INF;
    if ($absDelta > 1.0 && $pct > 0.01) { // >1 mL and >1%
      $warnings[] = [
        'type'       => 'volume_not_achieved',
        'detail'     => sprintf('Volume not achieved: got %.2f mL vs target %.2f mL (Δ=%.2f mL).', $achievedV, $targetV, $deltaV),
        'target_ml'  => $targetV,
        'achieved_ml'=> $achievedV,
        'delta_ml'   => $deltaV
      ];
    }
  }

  // Residuals (absolute amounts)
  $residual = [
    'vol_ml'     => $t_abs_mix[0] - $cur[0],
    'ethanol_ml' => $t_abs_mix[1] - $cur[1],
    'sugar_g'    => $t_abs_mix[2] - $cur[2],
    'acid_g'     => $t_abs_mix[3] - $cur[3],
  ];
  $achieved = [
    'vol_ml'     => $cur[0],
    'ethanol_ml' => $cur[1],
    'sugar_g'    => $cur[2],
    'acid_g'     => $cur[3],
  ];

  // Percentages (mix basis) with percentage-point residuals
  $achieved_pct = [
    'abv_pct'   => ($cur[0] > 0 ? ($cur[1]/$cur[0]*100.0) : 0.0),
    'sugar_pct' => ($cur[0] > 0 ? ($cur[2]/$cur[0]*100.0) : 0.0),
    'acid_pct'  => ($cur[0] > 0 ? ($cur[3]/$cur[0]*100.0) : 0.0),
  ];
  $target_pct = [
    'abv_pct'   => ($t_abs_mix[0] > 0 ? ($t_abs_mix[1]/$t_abs_mix[0]*100.0) : 0.0),
    'sugar_pct' => ($t_abs_mix[0] > 0 ? ($t_abs_mix[2]/$t_abs_mix[0]*100.0) : 0.0),
    'acid_pct'  => ($t_abs_mix[0] > 0 ? ($t_abs_mix[3]/$t_abs_mix[0]*100.0) : 0.0),
  ];
  $residual_pct = [
    'abv_pp'   => $target_pct['abv_pct']   - $achieved_pct['abv_pct'],
    'sugar_pp' => $target_pct['sugar_pct'] - $achieved_pct['sugar_pct'],
    'acid_pp'  => $target_pct['acid_pct']  - $achieved_pct['acid_pct'],
  ];

  // Percentage-point tolerances for "matched"
  $mtol_pp = [
    'abv_pp'   => 0.10,  // 0.10 percentage points
    'sugar_pp' => 0.20,  // 0.20 pp
    'acid_pp'  => 0.05,  // 0.05 pp (tighter)
    'vol_ml'   => 0.5    // volume tolerance if locked
  ];
  $matched = (
    abs($residual_pct['abv_pp'])   <= $mtol_pp['abv_pp']   &&
    abs($residual_pct['sugar_pp']) <= $mtol_pp['sugar_pp'] &&
    abs($residual_pct['acid_pp'])  <= $mtol_pp['acid_pp']  &&
    ($lock_volume ? abs($residual['vol_ml']) <= $mtol_pp['vol_ml'] : true)
  );
// Write results to mlTest for THIS USER’S copy. Insert-or-update pattern.
  $pdo->beginTransaction();
  for ($k=0; $k<$n; $k++){
    $rid = (int)$rows[$k]['id'];
    $ing = (int)$rows[$k]['ingredient_id'];

    // ensure a user-owned row exists to store mlTest (if the visible row is public)
    $st = $pdo->prepare('SELECT id FROM recipes WHERE drink_id=? AND ingredient_id=? AND owner_id=? LIMIT 1');
    $st->execute([$drink_id, $ing, $uid]);
    $own = $st->fetchColumn();

    if ($own){
      $u = $pdo->prepare('UPDATE recipes SET mlTest=? WHERE id=?');
      $u->execute([ $ml[$k], (int)$own ]);
    } else {
      // create a private overlay row with mlTest only (leaving mlParts NULL)
      $ins = $pdo->prepare('INSERT INTO recipes (drink_id, ingredient_id, mlParts, position, mlTest, hold, testLow, testHigh, owner_id, is_public)
                            VALUES (?,?,?,?,?,?,?,?,?,0)');
      $pos = null;
      $ins->execute([$drink_id, $ing, null, $pos, $ml[$k], $hold[$k]?1:0,
                     $lo[$k] != -INF ? $lo[$k] : null, $hi[$k] != +INF ? $hi[$k] : null, $uid]);
    }
  }
  $pdo->commit();

  out(['ok'=>true, 'updated'=>$n, 'basis'=>$basis, 'granularity_ml'=>$granularity_ml, 'quant_spoken'=>$quant_spoken, 'lock_volume'=>$lock_volume, 'warnings'=>$warnings, 'achieved'=>$achieved, 'residual'=>$residual, 'matched'=>$matched, 'achieved_pct'=>$achieved_pct, 'residual_pct'=>$residual_pct]);

} catch (Throwable $e){
  error_log("[optimize] ".$e->getMessage()." @ ".$e->getFile().":".$e->getLine());
  out(['error'=>'Server error','detail'=>$e->getMessage()], 500);
}

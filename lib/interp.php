<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/util.php';

/**
 * Load full property grid into memory.
 * Returns: [
 *   'temps' => [10, 11, ..., 30],
 *   'grid' => [
 *      ABM => [
 *        SBM => [
 *           T_C => ['ABV'=>..., 'Sugar_WV'=>..., 'nD'=>..., 'Density'=>..., 'BrixATC'=>...]
 *        ]
 *      ]
 *   ]
 * ]
 */
function load_grid(): array {
  $pdo = db();
  $stmt = $pdo->query("SELECT ABM, SBM, T_C, ABV, Sugar_WV, nD, Density, BrixATC FROM mix_properties ORDER BY ABM, SBM, T_C");
  $grid = [];
  $temps_map = [];
  while ($row = $stmt->fetch()) {
    $abm = (float)$row['ABM'];
    $sbm = (float)$row['SBM'];
    $t   = (float)$row['T_C'];
    $temps_map[$t] = true;
    if (!isset($grid[$abm])) $grid[$abm] = [];
    if (!isset($grid[$abm][$sbm])) $grid[$abm][$sbm] = [];
    $grid[$abm][$sbm][$t] = [
      'ABV' => isset($row['ABV']) ? (float)$row['ABV'] : null,
      'Sugar_WV' => isset($row['Sugar_WV']) ? (float)$row['Sugar_WV'] : null,
      'nD' => isset($row['nD']) ? (float)$row['nD'] : null,
      'Density' => isset($row['Density']) ? (float)$row['Density'] : null,
      'BrixATC' => isset($row['BrixATC']) ? (float)$row['BrixATC'] : null,
    ];
  }
  ksort($temps_map, SORT_NUMERIC);
  $temps = array_map('floatval', array_keys($temps_map));
  return ['temps'=>$temps, 'grid'=>$grid];
}

// Find bracketing temperatures for interpolation
function bracket_temps(array $temps, float $T): array {
  $n = count($temps);
  if ($T <= $temps[0]) return [$temps[0], $temps[min(1, $n-1)]];
  if ($T >= $temps[$n-1]) return [$temps[$n-2], $temps[$n-1]];
  for ($i = 1; $i < $n; $i++) {
    if ($T <= $temps[$i]) return [$temps[$i-1], $temps[$i]];
  }
  return [$temps[$n-2], $temps[$n-1]];
}

// Interpolate one property at temperature T for given ABM/SBM
function interp_prop_at(array $grid, array $temps, float $abm, float $sbm, float $T, string $prop) {
  if (!isset($grid[$abm]) || !isset($grid[$abm][$sbm])) return null;
  $pair = $grid[$abm][$sbm];
  [$t0, $t1] = bracket_temps($temps, $T);
  if (!isset($pair[$t0]) || !isset($pair[$t1])) return null;
  $v0 = $pair[$t0][$prop] ?? null;
  $v1 = $pair[$t1][$prop] ?? null;
  if ($v0 === null || $v1 === null) return null; // respects 9999->NULL
  if ($t0 == $t1) return $v0;
  return lin_interp($T, $t0, $t1, $v0, $v1);
}

// Solve for ABM/SBM by minimizing squared error for two measurements (possibly at different T).
function solve_abm_sbm(array $gridpack, string $m1, float $v1, ?float $T1, string $m2, float $v2, ?float $T2): array {
  $grid = $gridpack['grid']; $temps = $gridpack['temps'];
  $best = ['err'=>INF, 'abm'=>null, 'sbm'=>null];
  foreach ($grid as $abm => $row) {
    foreach ($row as $sbm => $series) {
      $p1 = ($T1 === null) ? null : interp_prop_at($grid, $temps, $abm, $sbm, $T1, $m1);
      $p2 = ($T2 === null) ? null : interp_prop_at($grid, $temps, $abm, $sbm, $T2, $m2);
      // If a measurement is temperature-independent (ABM/SBM), p1 or p2 stays null and we compare directly to abm/sbm.
      $e1 = 0.0; $e2 = 0.0;
      if ($T1 === null) { // implies m1 is 'ABM' or 'SBM'
        $expected = ($m1==='ABM') ? (float)$abm : (float)$sbm;
        $e1 = ($expected - $v1) ** 2;
      } else {
        if ($p1 === null) continue; // cannot use this candidate
        $e1 = ($p1 - $v1) ** 2;
      }
      if ($T2 === null) {
        $expected = ($m2==='ABM') ? (float)$abm : (float)$sbm;
        $e2 = ($expected - $v2) ** 2;
      } else {
        if ($p2 === null) continue;
        $e2 = ($p2 - $v2) ** 2;
      }
      $err = $e1 + $e2;
      if ($err < $best['err']) $best = ['err'=>$err, 'abm'=>$abm, 'sbm'=>$sbm];
    }
  }
  return $best;
}

// Conditional valid ranges: approximate by near-level band on the other measurement
function conditional_range(array $gridpack, string $condProp, float $condVal, float $condT, string $rangeProp, float $rangeT, float $initialBand=0.1, float $expand=2.0, float $maxBand=5.0): ?array {
  $grid = $gridpack['grid']; $temps = $gridpack['temps'];
  $band = $initialBand;
  $vals = [];
  while ($band <= $maxBand && count($vals) < 5) {
    $lo = $condVal - $band; $hi = $condVal + $band;
    $vals = [];
    foreach ($grid as $abm => $row) {
      foreach ($row as $sbm => $series) {
        $c = interp_prop_at($grid, $temps, $abm, $sbm, $condT, $condProp);
        if ($c === null) continue;
        if ($c >= $lo && $c <= $hi) {
          $r = interp_prop_at($grid, $temps, $abm, $sbm, $rangeT, $rangeProp);
          if ($r !== null) $vals[] = $r;
        }
      }
    }
    if (count($vals) >= 5) break;
    $band *= $expand;
  }
  if (!$vals) return null;
  sort($vals, SORT_NUMERIC);
  return ['min'=>$vals[0], 'max'=>$vals[count($vals)-1], 'band_used'=>$band];
}

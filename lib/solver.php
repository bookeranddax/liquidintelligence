<?php
declare(strict_types=1);

final class MixSolver
{
    private ?PDO $pdo;
    /** @var float[] */  private array $axesT = [];
    /** @var float[] */  private array $axesA = [];
    /** @var float[] */  private array $axesS = [];
    /**
     * $grid[col][T][A][S] = float|null (NULL if missing or 9999)
     * @var array<string, array>
     */
    private array $grid = [];

    // columns we interpolate for
    private const COLS = ['ABV','Sugar_WV','nD','Density','BrixATC'];

    // normalization (error weighting) used by coarse/refine
    private const SIGMA = [
        'ABV'      => 0.05,      // %vol effective uncertainty (only if used as input)
        'BrixATC'  => 0.10,      // °Bx effective uncertainty
        'Density'  => 0.00025,   // g/mL effective uncertainty
        'Sugar_WV' => 1.0,       // g/L (only if used as input)
    ];

    // bands for *envelope* calculations (broad, domain-style)
    private const ENVELOPE_BAND = [
        'ABV'      => 0.2,     // %vol
        'BrixATC'  => 0.10,    // °Bx  (kept at 0.10 to match the ranges you’ve been seeing)
        'Density'  => 0.005,   // g/mL (used when Density is the "fixed" property)
        'Sugar_WV' => 5.0,     // g/L
    ];

    // Broader bands for feasibility/range diagnostics so edges (e.g., Sugar_WV=0) are not excluded
    private const RANGE_BAND_FLOOR = [
      'ABV'      => 0.5,     // % vol
      'BrixATC'  => 0.2,     // °Bx
      'Density'  => 0.001,   // g/mL
      'Sugar_WV' => 10.0,    // g/L
    ];

    private function bandFor(string $prop): float {
        $w = self::SIGMA[$prop] ?? 1.0;
        $f = self::RANGE_BAND_FLOOR[$prop] ?? 0.0;
        return max($w, $f);
    }

    // bounds (dataset wide)
    private const ABM_MIN = 0.0;
    private const ABM_MAX = 100.0;  // dataset has broad coverage when SBM=0
    private const SBM_MIN = 0.0;
    private const SBM_MAX = 83.0;   // dataset has coverage up to ~83 g/100g when ABM=0
    private const T_MIN   = 10.0;
    private const T_MAX   = 30.0;

    // -------- Monte-Carlo (uncertainty) helpers ----------
    private const MC_SAMPLES = 200; // speed vs precision

    private static function randn(): float {
        // Box–Muller
        $u = 0.0; $v = 0.0;
        while ($u === 0.0) $u = mt_rand() / mt_getrandmax();
        while ($v === 0.0) $v = mt_rand() / mt_getrandmax();
        return sqrt(-2.0 * log($u)) * cos(2.0 * pi() * $v);
    }

    private static function stddev(array $xs): ?float {
        $n = count($xs);
        if ($n < 2) return null;
        $m = array_sum($xs) / $n;
        $acc = 0.0;
        foreach ($xs as $x) $acc += ($x - $m) * ($x - $m);
        return sqrt($acc / ($n - 1));
    }

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo;
        $this->loadAxesAndGrid();
    }

    /** Main entry */
public function solve(array $in): array
{
    $mode = $in['mode'] ?? null;

    // Should we run uncertainty propagation on this call?
    $doUnc = empty($in['__no_uncertainty']) && (
        (isset($in['sigma'])   && is_array($in['sigma'])   && !empty($in['sigma'])) ||
        (isset($in['sigma_T']) && is_array($in['sigma_T']) && !empty($in['sigma_T']))
    );

    // convenience aliases (normalized by api/solve.php)
    $ABV       = $in['ABV']       ?? null;  $ABV_T       = $in['ABV_T']       ?? null;
    $BrixATC   = $in['BrixATC']   ?? null;  $BrixATC_T   = $in['BrixATC_T']   ?? null;
    $Density   = $in['Density']   ?? null;  $Density_T   = $in['Density_T']   ?? null;
    $Sugar_WV  = $in['Sugar_WV']  ?? null;  $Sugar_WV_T  = $in['Sugar_WV_T']  ?? null;
    $abm_in    = $in['abm']       ?? ($in['ABM'] ?? null);  // accept either key
    $sbm_in    = $in['sbm']       ?? ($in['SBM'] ?? null);
    $report_T  = $in['report_T']  ?? 20.0;

    // ---------- NEW: 1D "edge" solve when ABM=0 or SBM=0 with exactly ONE measured pair ----------
    $alcoholZero = !empty($in['alcohol_zero']) || !empty($in['assume_ABV_zero']);
    $sugarZero   = !empty($in['sugar_zero'])   || !empty($in['assume_Sugar_zero']);

    if ($alcoholZero || $sugarZero) {
        // Count fully-specified measurement pairs (value + its *_T)
        $have = [];
        foreach (['ABV','BrixATC','Density','Sugar_WV'] as $p) {
            if (isset($in[$p]) && isset($in["{$p}_T"])) $have[] = $p;
        }

        if (count($have) === 1) {
            $p = $have[0];

            // Underdetermined combos to reject with a clear message:
            //  - ABM=0 with only ABV@T (ABV is identically 0 on the ABM=0 edge)
            //  - SBM=0 with only Sugar_WV@T (Sugar_WV is identically 0 on the SBM=0 edge)
            if ($alcoholZero && $p === 'ABV') {
                return ['ok'=>false, 'error'=>'ABM=0 with only ABV@T is underdetermined. Add Brix, Density, or Sugar_WV.'];
            }
            if ($sugarZero && $p === 'Sugar_WV') {
                return ['ok'=>false, 'error'=>'SBM=0 with only Sugar_WV@T is underdetermined. Add ABV, Brix, or Density.'];
            }

            // Do the single-input edge inversion (vary SBM if ABM=0; vary ABM if SBM=0)
            return $this->solveEdgeSingle($p, (float)$in[$p], (float)$in["{$p}_T"],
                                          $alcoholZero, (float)$report_T);
        }
        // If there are 0 pairs or >=2 pairs, fall through:
        // - 0 pairs => api layer should have rejected already
        // - >=2 pairs => we’ll solve the normal two-input inversion below
    }
    // ---------- END NEW EDGE HOOK ----------

    // If ABM/SBM are provided directly, just forward-calc the other outputs at report_T.
    if ($mode === 'abm_sbm') {
        $abm = (float)$abm_in; $sbm = (float)$sbm_in;
        $out = $this->predictAllAtT($abm, $sbm, (float)$report_T);

        $resp = [
            'ok'       => true,
            'mode'     => $mode,
            'inputs'   => ['ABM'=>$abm, 'SBM'=>$sbm, 'report_T'=>(float)$report_T],
            'abm'      => round($abm, 4),
            'sbm'      => round($sbm, 4),
            'report_T' => (float)$report_T,
            'outputs'  => $out,
            'diagnostics' => ['note'=>'Direct forward calculation (no inversion).']
        ];

        if ($doUnc) {
            try {
                $resp['uncertainty'] = $this->computeUncertainty($in, (float)$resp['abm'], (float)$resp['sbm'], $resp['outputs'] ?? []);
            } catch (\Throwable $e) {
                $resp['uncertainty_error'] = $e->getMessage();
            }
        }
        return $resp;
    }

    // choose which two measured properties we use to invert for (ABM,SBM)
    $pair = null;
    if     ($mode === 'abv_brix')     $pair = ['ABV','BrixATC',   $ABV, $ABV_T, $BrixATC, $BrixATC_T];
    elseif ($mode === 'abv_density')  $pair = ['ABV','Density',   $ABV, $ABV_T, $Density, $Density_T];
    elseif ($mode === 'brix_density') $pair = ['BrixATC','Density',$BrixATC,$BrixATC_T,$Density,$Density_T];
    elseif ($mode === 'abv_sugarwv')  $pair = ['ABV','Sugar_WV',  $ABV, $ABV_T, $Sugar_WV,$Sugar_WV_T];

    if ($pair === null) {
        return ['ok'=>false, 'error'=>'Missing or invalid mode in solver.'];
    }

    [$p1,$p2,$v1,$t1,$v2,$t2] = $pair;

    // Guard temperature range
    $t1 = $this->clamp((float)$t1, self::T_MIN, self::T_MAX);
    $t2 = $this->clamp((float)$t2, self::T_MIN, self::T_MAX);

// --- Quick envelope-based feasibility (soft block) ---
$diag  = $this->rangeDiagnostics($p1,(float)$v1,$t1,$p2,(float)$v2,$t2);
$warns = [];
$hardBlock = false;

$check = function(string $fixedP, float $fixedV, float $fixedT,
                  string $varP,   float $varV,   float $varT) use (&$diag,&$warns,&$hardBlock) {

    $rng = $diag["range_for_{$varP}_given_{$fixedP}"] ?? null;
    if (!$rng || empty($rng['matched']) || !is_numeric($rng['min']) || !is_numeric($rng['max'])) return;

    $min = (float)$rng['min']; $max = (float)$rng['max'];

    // soft/hard margins: allow small outside due to rounding/interp; hard block only when way out
    $soft = max($this->bandFor($varP),  // e.g., Brix ~0.2, Density ~0.001
                ($varP==='BrixATC' ? 0.15 : ($varP==='ABV' ? 0.15 : ($varP==='Density' ? 0.0015 : 2.5))));
    $hard = 2.0 * $soft;

    if ($varV < $min - $hard || $varV > $max + $hard) {
        $hardBlock = true;
    } elseif ($varV < $min - 1e-6 || $varV > $max + 1e-6) {
        $warns[] = "{$varP}=".$this->fmt($varV,$varP)." @ ".$this->fmtT($varT)
                 ." is just outside feasible range [{$min}, {$max}] for "
                 ."{$fixedP}=".$this->fmt($fixedV,$fixedP)." @ ".$this->fmtT($fixedT).".";
    }
};

$check($p1,(float)$v1,$t1,$p2,(float)$v2,$t2);
$check($p2,(float)$v2,$t2,$p1,(float)$v1,$t1);

if ($hardBlock) {
    // Truly inconsistent: blank outputs, show ranges
    return [
        'ok'         => true,
        'mode'       => $in['mode'],
        'inputs'     => [$p1=>$v1, "{$p1}_T"=>$t1, $p2=>$v2, "{$p2}_T"=>$t2],
        'report_T'   => (float)$report_T,
        'diagnostics'=> $diag + ['warning' => implode(' ', $warns)],
        'outputs'    => null,
    ];
}
// else: proceed to coarse/refine; warnings (if any) will be attached at the end.


    // --- 1) Coarse search on grid to get good starting points ---
    $best = $this->coarseScan($p1, (float)$v1, $t1, $p2, (float)$v2, $t2, 8);

    // Guard: no finite predictions at these temps/inputs
    if (empty($best)) {
        return [
            'ok'       => false,
            'mode'     => $in['mode'],
            'inputs'   => [$p1=>$v1, "{$p1}_T"=>$t1, $p2=>$v2, "{$p2}_T"=>$t2],
            'report_T' => (float)$report_T,
            'outputs'  => null,
            'error'    => 'Model has no coverage for these inputs at the given temperatures.',
            'diagnostics' => $diag, // from earlier rangeDiagnostics()
        ];
    }
    // --- 2) Local refinement (continuous ABM/SBM via tri-linear interpolation) ---
    $refined = $this->refineAround($best[0]['abm'], $best[0]['sbm'], $p1,(float)$v1,$t1,$p2,(float)$v2,$t2);

    $abm_star = $refined['abm'];
    $sbm_star = $refined['sbm'];
    $norm_star = $refined['norm_err'];

    // thresholds: ~5σ warn, ~10σ fail (since norm_err = e1^2 + e2^2 in σ units)
    $WARN_NORM = 25.0;   // ~5σ combined
    $FAIL_NORM = 100.0;  // ~10σ combined

    // --- 3) Report all outputs at requested report_T ---
    $outs = $this->predictAllAtT($abm_star, $sbm_star, (float)$report_T);

    // --- 4) Return with diagnostics (ranges shown use same envelope logic) ---
    $resp = [
        'ok'        => true,
        'mode'      => $in['mode'],
        'inputs'    => [$p1=>$v1, "{$p1}_T"=>$t1, $p2=>$v2, "{$p2}_T"=>$t2],
        'abm'       => round($abm_star, 4),
        'sbm'       => round($sbm_star, 4),
        'report_T'  => (float)$report_T,
        'outputs'   => $outs,
        'diagnostics' => [
            'best_error'      => $refined['error_components'],
            'best_norm_error' => $refined['norm_err'],
            'best_candidates' => $best,
        ] + $diag,
    ];

    // downgrade or fail based on quality
    if (!is_finite($norm_star)) {
      $resp['ok'] = false;
      $resp['error'] = 'Model has no coverage for these inputs at the given temperatures.';
      $resp['outputs'] = null;
      return $resp;
    }
    if ($norm_star >= $FAIL_NORM) {
      $resp['ok'] = false;
      $resp['error'] = 'Inputs cannot be reconciled within model tolerances.';
      $resp['outputs'] = null;
      return $resp;
    }
    if ($norm_star >= $WARN_NORM) {
      $resp['diagnostics']['warning'] = 'Inputs are difficult to reconcile; returning closest-fit solution.';
    }

    // === merge any pre-check "just outside" warnings ===
    if (!empty($warns)) {
        $existing = $resp['diagnostics']['warning'] ?? '';
        $tail = implode(' ', $warns);
        $resp['diagnostics']['warning'] = trim($existing . ' ' . $tail);
    }

    if ($doUnc) {
        try {
            $resp['uncertainty'] = $this->computeUncertainty($in, (float)$resp['abm'], (float)$resp['sbm'], $resp['outputs'] ?? []);
        } catch (\Throwable $e) {
            $resp['uncertainty_error'] = $e->getMessage();
        }
    }

    return $resp;
}


    /* ====================== Grid + interpolation ======================= */

    private function loadAxesAndGrid(): void
    {
        $stmt = $this->pdo->query("SELECT T_C, ABM, SBM, ABV, Sugar_WV, nD, Density, BrixATC FROM mix_data");
        $Tset = []; $Aset = []; $Sset = [];

        $g = [];
        foreach (self::COLS as $c) $g[$c] = [];

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $T = (float)$row['T_C'];
            $A = (float)$row['ABM'];
            $S = (float)$row['SBM'];

            $Tset[$T] = true; $Aset[$A] = true; $Sset[$S] = true;

            foreach (self::COLS as $c) {
                $v = $row[$c];
                $val = null;
                if ($v !== null) {
                    $f = (float)$v;
                    if ($f !== 9999.0) $val = $f; // treat 9999 as missing
                }
                $g[$c][$T][$A][$S] = $val;
            }
        }

        $this->axesT = array_values(array_map('floatval', array_keys($Tset)));
        $this->axesA = array_values(array_map('floatval', array_keys($Aset)));
        $this->axesS = array_values(array_map('floatval', array_keys($Sset)));
        sort($this->axesT); sort($this->axesA); sort($this->axesS);

        $this->grid = $g;
    }

    private function clamp(float $x, float $lo, float $hi): float {
        return max($lo, min($hi, $x));
    }

    private function fmt(float $v, string $p): string {
        if ($p === 'Density' || $p === 'nD') return number_format($v, 5, '.', '');
        if ($p === 'BrixATC') return number_format($v, 2, '.', '');
        if ($p === 'ABV') return number_format($v, 3, '.', '');
        if ($p === 'Sugar_WV') return number_format($v, 1, '.', '');
        return (string)$v;
    }
    private function fmtT(float $t): string {
        return number_format($t, 1, '.', '') . '°C';
    }

    /** Bracket x between axis[k0] and axis[k1] with alpha in [0,1] */
    private function bracket(array $axis, float $x): array
    {
        $n = count($axis);
        if ($n === 0) return [0,0,0.0];
        if ($x <= $axis[0])   return [0, 0, 0.0];           // degenerate (exactly at edge)
        if ($x >= $axis[$n-1])return [$n-1,$n-1,0.0];

        $lo = 0; $hi = $n-1;
        while ($lo+1 < $hi) {
            $mid = intdiv($lo+$hi, 2);
            if ($axis[$mid] <= $x) $lo = $mid; else $hi = $mid;
        }
        $x0 = $axis[$lo]; $x1 = $axis[$hi];
        $alpha = ($x1 == $x0) ? 0.0 : ($x - $x0)/($x1 - $x0);
        return [$lo, $hi, $alpha];
    }

    /** Robust tri-linear interpolation with missing corner handling */
    private function triInterp(string $col, float $T, float $A, float $S): float
    {
        $T = $this->clamp($T, self::T_MIN, self::T_MAX);
        $A = $this->clamp($A, self::ABM_MIN, self::ABM_MAX);
        $S = $this->clamp($S, self::SBM_MIN, self::SBM_MAX);

        [$iT0,$iT1,$aT] = $this->bracket($this->axesT, $T);
        [$iA0,$iA1,$aA] = $this->bracket($this->axesA, $A);
        [$iS0,$iS1,$aS] = $this->bracket($this->axesS, $S);

        $T0 = $this->axesT[$iT0]; $T1 = $this->axesT[$iT1];
        $A0 = $this->axesA[$iA0]; $A1 = $this->axesA[$iA1];
        $S0 = $this->axesS[$iS0]; $S1 = $this->axesS[$iS1];

        $vals = [];
        foreach ([[$T0,1.0-$aT],[$T1,$aT]] as [$t, $wt]) {
            foreach ([[$A0,1.0-$aA],[$A1,$aA]] as [$a, $wa]) {
                foreach ([[$S0,1.0-$aS],[$S1,$aS]] as [$s, $ws]) {
                    $v = $this->grid[$col][$t][$a][$s] ?? null;
                    $w = $wt*$wa*$ws;
                    if ($v !== null && $w>0) $vals[] = [$v, $w];
                }
            }
        }

        if (!$vals) {
            // Nearest-neighbor fallback if all corners missing
            $tN = (abs($T - $T0) <= abs($T - $T1)) ? $T0 : $T1;
            $aN = (abs($A - $A0) <= abs($A - $A1)) ? $A0 : $A1;
            $sN = (abs($S - $S0) <= abs($S - $S1)) ? $S0 : $S1;
            $vN = $this->grid[$col][$tN][$aN][$sN] ?? null;
            if ($vN !== null) return (float)$vN;

            // last resort: scan nearby small cube
            $best = null; $bestD = INF;
            foreach ([$T0,$T1] as $t) foreach ([$A0,$A1] as $a) foreach ([$S0,$S1] as $s) {
                $v = $this->grid[$col][$t][$a][$s] ?? null;
                if ($v !== null) {
                    $d = abs($T-$t)+abs($A-$a)+abs($S-$s);
                    if ($d < $bestD) { $bestD = $d; $best = $v; }
                }
            }
            if ($best !== null) return (float)$best;
            return NAN;
        }

        $num = 0.0; $den = 0.0;
        foreach ($vals as [$v,$w]) { $num += $v*$w; $den += $w; }
        return $den > 0 ? $num/$den : NAN;
    }

    private function predictAllAtT(float $abm, float $sbm, float $T): array
    {
        return [
            'T_C'     => round($T, 2),
            'ABV'     => $this->r($this->triInterp('ABV',     $T, $abm, $sbm),  3),
            'Sugar_WV'=> $this->r($this->triInterp('Sugar_WV',$T, $abm, $sbm),  1),
            'Density' => $this->r($this->triInterp('Density', $T, $abm, $sbm),  5),
            'BrixATC' => $this->r($this->triInterp('BrixATC', $T, $abm, $sbm),  2),
            'nD'      => $this->r($this->triInterp('nD',      $T, $abm, $sbm),  5),
        ];
    }

    private function r(float $x, int $p): float { return (float)round($x, $p); }

    /* ==================== Coarse scan + local refine =================== */

    private function coarseScan(string $p1, float $v1, float $t1,
                                string $p2, float $v2, float $t2,
                                int $keep = 5): array
    {
        $best = [];

        foreach ($this->axesA as $A) {
            foreach ($this->axesS as $S) {
                $pred1 = $this->triInterp($p1, $t1, $A, $S);
                $pred2 = $this->triInterp($p2, $t2, $A, $S);
                if (!is_finite($pred1) || !is_finite($pred2)) continue;

                $e1 = ($pred1 - $v1)/ (self::SIGMA[$p1] ?? 1.0);
                $e2 = ($pred2 - $v2)/ (self::SIGMA[$p2] ?? 1.0);
                $norm = $e1*$e1 + $e2*$e2;

                $best[] = [
                    'abm'=>$A, 'sbm'=>$S,
                    'pred1'=>$pred1, 'pred2'=>$pred2,
                    'err'=>[$p1=>$pred1-$v1, $p2=>$pred2-$v2],
                    'norm_err'=>$norm,
                ];
            }
        }

        usort($best, fn($x,$y)=> $x['norm_err']<=>$y['norm_err']);
        return array_slice($best, 0, max(1,$keep));
    }

    private function refineAround(float $A0, float $S0,
                                  string $p1, float $v1, float $t1,
                                  string $p2, float $v2, float $t2): array
    {
        $A = $A0; $S = $S0;
        $box = 4.0;                    // start with a 4-point box
        $tol = 0.005;                  // ~0.005 in ABM/SBM
        $best = null;

        $eval = function(float $a, float $s) use($p1,$v1,$t1,$p2,$v2,$t2) {
            $a = $this->clamp($a, self::ABM_MIN, self::ABM_MAX);
            $s = $this->clamp($s, self::SBM_MIN, self::SBM_MAX);
            $q1 = $this->triInterp($p1, $t1, $a, $s);
            $q2 = $this->triInterp($p2, $t2, $a, $s);
            if (!is_finite($q1) || !is_finite($q2)) return [INF, $q1, $q2, 0.0, 0.0];
            $e1 = ($q1 - $v1)/ (self::SIGMA[$p1] ?? 1.0);
            $e2 = ($q2 - $v2)/ (self::SIGMA[$p2] ?? 1.0);
            return [$e1*$e1 + $e2*$e2, $q1, $q2, $q1-$v1, $q2-$v2];
        };

        while ($box > $tol) {
            $step = max($box/10.0, 0.02);
            $loA = max(self::ABM_MIN, $A - $box);
            $hiA = min(self::ABM_MAX, $A + $box);
            $loS = max(self::SBM_MIN, $S - $box);
            $hiS = min(self::SBM_MAX, $S + $box);

            $bestHere = [INF,$A,$S,null,null,null,null]; // norm, A, S, pred1, pred2, e1, e2

            for ($a = $loA; $a <= $hiA + 1e-9; $a += $step) {
                for ($s = $loS; $s <= $hiS + 1e-9; $s += $step) {
                    [$n,$p1hat,$p2hat,$e1,$e2] = $eval($a,$s);
                    if ($n < $bestHere[0]) $bestHere = [$n,$a,$s,$p1hat,$p2hat,$e1,$e2];
                }
            }

            $best = $bestHere;
            $A = $best[1]; $S = $best[2];
            $box *= 0.5; // zoom
        }

        return [
            'abm' => $A,
            'sbm' => $S,
            'norm_err' => $best[0],
            'error_components' => [$p1=>$best[5], $p2=>$best[6]],
        ];
    }

    /* ===================== Envelope + diagnostics ====================== */

    private function envelopeFor(string $fixedProp, float $fixedVal, float $fixedTemp,
                                 string $varProp, float $varTemp, float $band): array
    {
        $min = INF; $max = -INF; $count = 0;
        foreach ($this->axesA as $A) foreach ($this->axesS as $S) {
            $pFixed = $this->triInterp($fixedProp, $fixedTemp, $A, $S);
            if (!is_finite($pFixed)) continue;
            if (abs($pFixed - $fixedVal) <= $band) {
                $pVar = $this->triInterp($varProp, $varTemp, $A, $S);
                if (is_finite($pVar)) {
                    if ($pVar < $min) $min = $pVar;
                    if ($pVar > $max) $max = $pVar;
                    $count++;
                }
            }
        }
        $prec = function(string $p): int {
            return ($p==='Density'||$p==='nD') ? 5 : (($p==='BrixATC') ? 3 : (($p==='ABV')?3:1));
        };
        return [
            'matched'   => ($count > 0),
            'band_used' => $band,
            'count'     => $count,
            'min'       => is_finite($min) ? $this->r($min, $prec($varProp)) : null,
            'max'       => is_finite($max) ? $this->r($max, $prec($varProp)) : null,
        ];
    }

private function rangeDiagnostics(string $p1, float $v1, float $t1,
                                  string $p2, float $v2, float $t2): array
{
    // Use widened bands so we don't miss edge cases due to super-tight SIGMA weights
    $bandP2 = $this->bandFor($p2);
    $bandP1 = $this->bandFor($p1);

    $minP1 = INF; $maxP1 = -INF; $c1 = 0;
    foreach ($this->axesA as $A) foreach ($this->axesS as $S) {
        $p2hat = $this->triInterp($p2, $t2, $A, $S);
        if (!is_finite($p2hat)) continue;
        if (abs($p2hat - $v2) <= $bandP2) {
            $p1hat = $this->triInterp($p1, $t1, $A, $S);
            if (is_finite($p1hat)) {
                $minP1 = min($minP1, $p1hat);
                $maxP1 = max($maxP1, $p1hat);
                $c1++;
            }
        }
    }

    $minP2 = INF; $maxP2 = -INF; $c2 = 0;
    foreach ($this->axesA as $A) foreach ($this->axesS as $S) {
        $p1hat = $this->triInterp($p1, $t1, $A, $S);
        if (!is_finite($p1hat)) continue;
        if (abs($p1hat - $v1) <= $bandP1) {
            $p2hat = $this->triInterp($p2, $t2, $A, $S);
            if (is_finite($p2hat)) {
                $minP2 = min($minP2, $p2hat);
                $maxP2 = max($maxP2, $p2hat);
                $c2++;
            }
        }
    }

    // Physical clamping: sugar cannot be negative.
    if ($p1 === 'Sugar_WV' && is_finite($minP1)) $minP1 = max(0.0, $minP1);
    if ($p2 === 'Sugar_WV' && is_finite($minP2)) $minP2 = max(0.0, $minP2);


    $diag = [];
    $diag["range_for_{$p1}_given_{$p2}"] = [
        'matched'   => ($c1>0),
        'min'       => is_finite($minP1)?$this->r($minP1, ($p1==='Density'?5: ($p1==='ABV'?1: ($p1==='Sugar_WV'?1:2)))):null,
        'max'       => is_finite($maxP1)?$this->r($maxP1, ($p1==='Density'?5: ($p1==='ABV'?1: ($p1==='Sugar_WV'?1:2)))):null,
    ];
    $diag["range_for_{$p2}_given_{$p1}"] = [
        'matched'   => ($c2>0),
        'min'       => is_finite($minP2)?$this->r($minP2, ($p2==='Density'?5: ($p2==='ABV'?1: ($p2==='Sugar_WV'?1:2)))):null,
        'max'       => is_finite($maxP2)?$this->r($maxP2, ($p2==='Density'?5: ($p2==='ABV'?1: ($p2==='Sugar_WV'?1:2)))):null,
    ];

    // Only warn if there is literally no overlap either way (keeps sugar=0 allowed)
    if ($c1===0 || $c2===0) {
        $diag['warning'] = 'Inputs appear physically inconsistent for the specified temperatures.';
    }

    return $diag;
}

private function solveEdgeSingle(string $prop, float $val, float $temp,
                                 bool $alcoholZero, float $report_T): array
{
    // ABM=0  => vary SBM;  SBM=0 => vary ABM
    $sigma = self::SIGMA[$prop] ?? 1.0;
    $axis  = $alcoholZero ? $this->axesS : $this->axesA;

    // Coarse scan on the relevant axis to pick a good bracket
    $bestIdx = null; $bestNorm = INF;
    foreach ($axis as $i => $x) {
        $A = $alcoholZero ? 0.0 : $x;
        $S = $alcoholZero ? $x   : 0.0;
        $pred = $this->triInterp($prop, $temp, $A, $S);
        if (!is_finite($pred)) continue;
        $e = ($pred - $val) / $sigma;
        $n = $e*$e;
        if ($n < $bestNorm) { $bestNorm = $n; $bestIdx = $i; }
    }
    if ($bestIdx === null) {
        return ['ok'=>false, 'error'=>'No coverage on edge for this temperature/property.'];
    }

    // Bracket around the best coarse point
    $i0 = max(0, $bestIdx - 1);
    $i1 = min(count($axis)-1, $bestIdx + 1);
    $lo = (float)$axis[$i0];
    $hi = (float)$axis[$i1];

    // 1D ternary search refinement on [lo, hi]
    $f = function(float $x) use ($alcoholZero,$prop,$temp,$val,$sigma){
        $A = $alcoholZero ? 0.0 : $x;
        $S = $alcoholZero ? $x   : 0.0;
        $pred = $this->triInterp($prop, $temp, $A, $S);
        if (!is_finite($pred)) return INF;
        $e = ($pred - $val) / $sigma;
        return $e*$e;
    };
    for ($k = 0; $k < 40; $k++) {
        $m1 = $lo + ($hi - $lo)/3.0;
        $m2 = $hi - ($hi - $lo)/3.0;
        if ($f($m1) > $f($m2)) $lo = $m1; else $hi = $m2;
    }
    $xStar = ($lo + $hi) * 0.5;

    $abm = $alcoholZero ? 0.0 : $xStar;
    $sbm = $alcoholZero ? $xStar : 0.0;

    $outs = $this->predictAllAtT($abm, $sbm, (float)$report_T);

    return [
        'ok'        => true,
        'mode'      => 'edge',
        'inputs'    => [$prop => $val, "{$prop}_T" => $temp],
        'abm'       => round($abm, 4),
        'sbm'       => round($sbm, 4),
        'report_T'  => (float)$report_T,
        'outputs'   => $outs,
        'diagnostics' => [
            'note' => 'Single-input edge solve with ' . ($alcoholZero ? 'ABM=0' : 'SBM=0')
                      . " using {$prop} @ " . $this->fmtT($temp)
        ]
    ];
}



    /* ===================== Monte-Carlo uncertainty ===================== */

    /**
     * Monte-Carlo: jitter inputs using provided sigma/sigma_T, re-solve, estimate stdevs.
     * Accepts canonical keys (ABV, BrixATC, Density, Sugar_WV, *_T) and also abm/sbm for abm_sbm mode.
     */
    private function computeUncertainty(array $req, float $abm, float $sbm, array $baseOut): array
    {
        $sig   = (isset($req['sigma'])   && is_array($req['sigma']))   ? $req['sigma']   : [];
        $sigT  = (isset($req['sigma_T']) && is_array($req['sigma_T'])) ? $req['sigma_T'] : [];
        $samples = isset($req['unc_samples']) ? (int)$req['unc_samples'] : self::MC_SAMPLES;
        $samples = max(10, min(300, $samples)); // clamp 10..300

        $deadlineSec = 2.0; // cap MC time
        $t0 = microtime(true);

        if (!$sig && !$sigT) return [];

        $sABM = []; $sSBM = [];
        $sABV = []; $sSug = []; $sRho = []; $sBx = []; $snd = [];

        $keys  = ['ABV','BrixATC','Density','Sugar_WV'];
        $tkeys = ['ABV_T','BrixATC_T','Density_T','Sugar_WV_T'];

        for ($i = 0; $i < $samples; $i++) {
            if (($i % 10) === 0 && (microtime(true) - $t0) > $deadlineSec) break;
            $r = $req;

            foreach ($keys as $k) {
                if (isset($r[$k]) && isset($sig[$k]) && is_numeric($r[$k]) && $sig[$k] > 0) {
                    $r[$k] = (float)$r[$k] + self::randn() * (float)$sig[$k];
                }
            }
            foreach ($tkeys as $tk) {
                if (isset($r[$tk]) && isset($sigT[$tk]) && is_numeric($r[$tk]) && $sigT[$tk] > 0) {
                    $r[$tk] = (float)$r[$tk] + self::randn() * (float)$sigT[$tk];
                }
            }
            if (isset($r['abm']) && isset($sig['ABM']) && is_numeric($r['abm']) && $sig['ABM'] > 0) {
                $r['abm'] = (float)$r['abm'] + self::randn() * (float)$sig['ABM'];
            }
            if (isset($r['sbm']) && isset($sig['SBM']) && is_numeric($r['sbm']) && $sig['SBM'] > 0) {
                $r['sbm'] = (float)$r['sbm'] + self::randn() * (float)$sig['SBM'];
            }

            // prevent nested MC
            $r['__no_uncertainty'] = true;

            $sol = $this->solve($r);
            if (!is_array($sol) || empty($sol['ok'])) continue;

            if (isset($sol['abm'])) $sABM[] = (float)$sol['abm'];
            if (isset($sol['sbm'])) $sSBM[] = (float)$sol['sbm'];
            if (!empty($sol['outputs']) && is_array($sol['outputs'])) {
                $o = $sol['outputs'];
                if (isset($o['ABV']))      $sABV[] = (float)$o['ABV'];
                if (isset($o['Sugar_WV'])) $sSug[] = (float)$o['Sugar_WV'];
                if (isset($o['Density']))  $sRho[] = (float)$o['Density'];
                if (isset($o['BrixATC']))  $sBx[]  = (float)$o['BrixATC'];
                if (isset($o['nD']))       $snd[]  = (float)$o['nD'];
            }
        }

        return [
            'abm'     => self::stddev($sABM),
            'sbm'     => self::stddev($sSBM),
            'outputs' => [
                'ABV'      => self::stddev($sABV),
                'Sugar_WV' => self::stddev($sSug),
                'Density'  => self::stddev($sRho),
                'BrixATC'  => self::stddev($sBx),
                'nD'       => self::stddev($snd),
            ],
            'samples' => [
                'count'  => count($sABM),
                'total'  => $samples,
                'time_s' => round(microtime(true) - $t0, 3)
            ]
        ];
    }
}


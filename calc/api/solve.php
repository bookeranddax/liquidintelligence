<?php
declare(strict_types=1);

//header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', '0');

function json_out_local($data, int $code=200): void {
   http_response_code($code);
   header('Content-Type: application/json; charset=utf-8');
   echo json_encode(
     $data,
     JSON_UNESCAPED_SLASHES
     | JSON_UNESCAPED_UNICODE
     | JSON_NUMERIC_CHECK
     | JSON_PARTIAL_OUTPUT_ON_ERROR
     | JSON_INVALID_UTF8_SUBSTITUTE
   );
   exit;
 }

// --- Robust root discovery + includes ---
// Prefer DOCUMENT_ROOT, but fall back to going up from /calc/api to the subdomain root.
$root = rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/');
if (!$root || !is_readable($root . '/config.php')) {
    // /calc/api -> dirname(__DIR__, 2) === subdomain root
    $root = dirname(__DIR__, 2);
}

try {
    $cfg = require $root . '/config.php';
} catch (Throwable $e) {
    json_out_local(['ok'=>false,'where'=>'require_config','error'=>$e->getMessage(),'root'=>$root], 500);
}

try {
    require_once $root . '/lib/solver.php';
} catch (Throwable $e) {
    json_out_local(['ok'=>false,'where'=>'require_solver','error'=>$e->getMessage(),'root'=>$root], 500);
}

// --- Helpers ---
function numOrNull(mixed $v): ?float {
    if ($v === null) return null;
    if (is_float($v) || is_int($v)) return (float)$v;
    if (!is_string($v)) return null;
    $s = trim($v);
    if ($s === '') return null;
    $s = str_replace(["\u{00A0}", ' '], '', $s);
    if (preg_match('/^\d{1,3}(\.\d{3})*,\d+$/', $s)) { // 1.234,56 style
        $s = str_replace('.', '', $s);
        $s = str_replace(',', '.', $s);
    } else {
        if (substr_count($s, ',') === 1 && substr_count($s, '.') === 0) $s = str_replace(',', '.', $s);
        if (substr_count($s, ',') > 0 && substr_count($s, '.') >= 1)   $s = str_replace(',', '', $s);
    }
    if (!is_numeric($s)) return null;
    return (float)$s;
}
function pick(array $a, array $keys): mixed { foreach ($keys as $k) if (array_key_exists($k, $a)) return $a[$k]; return null; }
function getJsonInput(): array {
    $raw = file_get_contents('php://input');
    if ($raw !== false && $raw !== '') {
        $js = json_decode($raw, true);
        if (is_array($js)) return $js;
    }
    return $_REQUEST ?? [];
}
function normalizeInput(array $in): array {
    $flat = [];
    foreach ($in as $k => $v) $flat[strtolower(trim((string)$k))] = $v;

    $mode = pick($flat, ['mode']);
    $mode = is_string($mode) ? strtolower(str_replace(['-', ' '], '_', $mode)) : null;

    $abv       = numOrNull(pick($flat, ['abv']));
    $abv_t     = numOrNull(pick($flat, ['abv_t','t_abv','abv_temp']));

    $brix      = numOrNull(pick($flat, ['brixatc','brix_atc','brix']));
    $brix_t    = numOrNull(pick($flat, ['brixatc_t','brix_t','t_brix','brix_temp','brixatc_temp']));

    $density   = numOrNull(pick($flat, ['density','rho']));
    $density_t = numOrNull(pick($flat, ['density_t','t_density','rho_t','density_temp']));

    $sugarwv   = numOrNull(pick($flat, ['sugar_wv','sugarwv','sugar_gpl','sugar']));
    $sugarwv_t = numOrNull(pick($flat, ['sugar_wv_t','sugarwv_t','t_sugar_wv','sugar_temp']));

    $abm       = numOrNull(pick($flat, ['abm','alc_mass','alcohol_by_mass']));
    $sbm       = numOrNull(pick($flat, ['sbm','sugar_mass','sugar_by_mass']));

    $report_t  = numOrNull(pick($flat, ['report_t','report_temp','t_report'])) ?? 20.0;

    // NEW: accept either explicit or legacy names (all lowercased in $flat)
    $alcohol_zero = !empty($flat['alcohol_zero']) || !empty($flat['assume_abv_zero']);
    $sugar_zero   = !empty($flat['sugar_zero'])   || !empty($flat['assume_sugar_zero']);

    // Uncertainty maps (keep original keys/case so solver sees ABV, BrixATC, …)
    $sigma = null;
    if (isset($in['sigma']) && is_array($in['sigma'])) {
        $sigma = $in['sigma'];
    } elseif (isset($flat['sigma']) && is_array($flat['sigma'])) {
        $sigma = $flat['sigma'];
    }

    $sigma_T = null;
    if (isset($in['sigma_T']) && is_array($in['sigma_T'])) {
        $sigma_T = $in['sigma_T'];
    } elseif (isset($in['sigma_t']) && is_array($in['sigma_t'])) {
        $sigma_T = $in['sigma_t'];
    } elseif (isset($flat['sigma_t']) && is_array($flat['sigma_t'])) {
        $sigma_T = $flat['sigma_t'];
    } elseif (isset($flat['sigma_T']) && is_array($flat['sigma_T'])) {
        $sigma_T = $flat['sigma_T'];
    }

    $ret = compact(
        'mode','abv','abv_t','brix','brix_t','density','density_t',
        'sugarwv','sugarwv_t','abm','sbm','report_t'
    );
    $ret['alcohol_zero'] = $alcohol_zero;   // <— NEW
    $ret['sugar_zero']   = $sugar_zero;     // <— NEW
    $ret['sigma']   = $sigma;
    $ret['sigma_T'] = $sigma_T;
    return $ret;
}

/**
 * Map normalized (lowercase) inputs to the canonical names MixSolver expects.
 */
function canonizeForSolver(array $in): array {
    $m    = $in['mode'];
    $out  = ['mode' => $m, 'report_T' => (float)$in['report_t']];

    // helper: only add a property if value AND temp exist
    $addPair = function(string $vKeyLC, string $tKeyLC, string $V, string $T) use (&$out, $in) {
        if ($in[$vKeyLC] !== null && $in[$tKeyLC] !== null) {
            $out[$V] = (float)$in[$vKeyLC];
            $out[$T] = (float)$in[$tKeyLC];
        }
    };

    switch ($m) {
        case 'brix_density':
            $addPair('brix',     'brix_t',     'BrixATC',   'BrixATC_T');
            $addPair('density',  'density_t',  'Density',   'Density_T');
            break;

        case 'abv_brix':
            $addPair('abv',      'abv_t',      'ABV',       'ABV_T');
            $addPair('brix',     'brix_t',     'BrixATC',   'BrixATC_T');
            break;

        case 'abv_density':
            $addPair('abv',      'abv_t',      'ABV',       'ABV_T');
            $addPair('density',  'density_t',  'Density',   'Density_T');
            break;

        case 'abv_sugarwv':
            $addPair('abv',      'abv_t',      'ABV',       'ABV_T');
            $addPair('sugarwv',  'sugarwv_t',  'Sugar_WV',  'Sugar_WV_T');
            break;

        case 'abm_sbm':
            // this mode takes direct ABM/SBM
            $out['abm'] = (float)$in['abm'];
            $out['sbm'] = (float)$in['sbm'];
            break;

        default:
            // leave minimal; upstream validation already guards mode
            break;
    }

    // forward uncertainties if present
    if (!empty($in['sigma'])   && is_array($in['sigma']))   $out['sigma']   = $in['sigma'];
    if (!empty($in['sigma_T']) && is_array($in['sigma_T'])) $out['sigma_T'] = $in['sigma_T'];

    // forward edge flags (both the new and legacy keys the solver checks)
    if (!empty($in['alcohol_zero'])) {
        $out['alcohol_zero']    = true;
        $out['assume_ABV_zero'] = true;
    }
    if (!empty($in['sugar_zero'])) {
        $out['sugar_zero']        = true;
        $out['assume_Sugar_zero'] = true;
    }

    return $out;
}


// --- Read & validate ---
try {
    $raw = getJsonInput();
    $in  = normalizeInput($raw);

    $mode = $in['mode'];

// --- Edge-aware validation (ABM=0 / SBM=0 can use ONE measured pair) ---
if (!in_array($mode, ['abv_brix','abv_density','brix_density','abv_sugarwv','abm_sbm'], true)) {
  json_out_local(['ok'=>false,'where'=>'validate_mode','error'=>'Valid modes: abv_brix, abv_density, brix_density, abv_sugarwv, abm_sbm'], 400);
}

$alcoholZero = !empty($in['alcohol_zero']);
$sugarZero   = !empty($in['sugar_zero']);

if ($alcoholZero && $sugarZero) {
  json_out_local(['ok'=>false,'where'=>'validate_flags','error'=>'Choose only one: ABM=0 or SBM=0'], 400);
}

if ($mode === 'abm_sbm') {
  $missing = [];
  foreach (['abm','sbm'] as $k) if (!array_key_exists($k,$in) || $in[$k] === null) $missing[] = $k;
  if ($missing) {
    json_out_local(['ok'=>false,'where'=>'validate_inputs','error'=>'Missing required inputs','missing'=>$missing], 400);
  }
} else {
  // For the selected mode, define its two possible pairs
  $pairs = match ($mode) {
    'abv_brix'     => [['abv','abv_t'],       ['brix','brix_t']],
    'abv_density'  => [['abv','abv_t'],       ['density','density_t']],
    'brix_density' => [['brix','brix_t'],     ['density','density_t']],
    'abv_sugarwv'  => [['abv','abv_t'],       ['sugarwv','sugarwv_t']],
  };

  $pairHas = fn(array $p) => (array_key_exists($p[0],$in) && $in[$p[0]] !== null) &&
                             (array_key_exists($p[1],$in) && $in[$p[1]] !== null);

  $has1 = $pairHas($pairs[0]);
  $has2 = $pairHas($pairs[1]);

  if (!$alcoholZero && !$sugarZero) {
    // Normal: need BOTH pairs complete
    if (!($has1 && $has2)) {
      // Build a helpful missing list
      $missing = [];
      foreach ($pairs as $p) {
        foreach ($p as $k) if (!array_key_exists($k,$in) || $in[$k] === null) $missing[] = $k;
      }
     json_out_local(['ok'=>false,'where'=>'validate_inputs','error'=>'Need value + temperature for this mode','missing'=>$missing], 400);
    }
  } else {
    // Edge case: allow ONE complete pair (either of the two for this mode)
    if (!($has1 || $has2)) {
      // If user typed a partial pair, point to the missing counterpart(s)
      $missing = [];
      foreach ($pairs as $p) {
        $v = array_key_exists($p[0],$in) && $in[$p[0]] !== null;
        $t = array_key_exists($p[1],$in) && $in[$p[1]] !== null;
        if ($v xor $t) { // partial
          if (!$v) $missing[] = $p[0];
          if (!$t) $missing[] = $p[1];
        }
      }
      if (!$missing) { // truly none present
        $missing = array_merge($pairs[0], $pairs[1]);
      }
    json_out_local([
      'ok'     => false,
      'where'  => 'validate_edge_inputs',
      'error'  => 'On ABM=0 or SBM=0 you must supply at least one complete measurement pair (value + temperature) for this mode.',
      'missing'=> $missing
    ], 400);
    }
    // If BOTH pairs are present while edge flag is set, we still allow it.
    // (Your frontend already prevents this; solver will ignore edge path.)
  }
}


    // --- Connect DB & Solve ---
    try {
        $db  = $cfg['db'];
        $dsn = "mysql:host={$db['host']};dbname={$db['name']};charset={$db['charset']}";
        $pdo = new PDO($dsn, $db['user'], $db['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (Throwable $e) {
        json_out_local(['ok'=>false,'where'=>'pdo_connect','error'=>$e->getMessage()], 500);
        exit;
    }

    try { $solver = new MixSolver($pdo); }
    catch (Throwable $e) { json_out_local(['ok'=>false,'where'=>'solver_construct','error'=>$e->getMessage()], 500); exit; }

    $canon = canonizeForSolver($in);

    try { $res = $solver->solve($canon); }
    catch (Throwable $e) { json_out_local(['ok'=>false,'where'=>'solver_solve','error'=>$e->getMessage(),'sent'=>$canon], 500); exit; }

if (!is_array($res) || !array_key_exists('ok', $res)) {
    $res = ['ok'=>true, 'result'=>$res];
}

json_out_local($res, 200);

} catch (Throwable $e) {
    json_out_local(['ok'=>false,'where'=>'outer','error'=>$e->getMessage()], 500);
}


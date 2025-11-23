<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', '0');
// (Optional) keep noise out of JSON entirely
error_reporting(0);

/* ---------- JSON helpers: sanitize and safe emit ---------- */
function sanitize_floats(mixed $v): mixed {
    if (is_array($v)) { foreach ($v as $k => $x) { $v[$k] = sanitize_floats($x); } return $v; }
    // normalize INF/-INF/NaN to null so json_encode never fails
    if (is_float($v) && (!is_finite($v) || is_nan($v))) return null;
    return $v;
}
function json_flags(int $extra = 0): int {
    return JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | $extra;
}
function jout(mixed $data, int $extra_flags = 0): void {
    // scrub floats, then emit with safe flags
    echo json_encode(sanitize_floats($data), json_flags($extra_flags));
}

/* ---------- Robust root discovery + includes ---------- */
// Prefer DOCUMENT_ROOT, but fall back to going up from /calc/api to the subdomain root.
$root = rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/');
if (!$root || !is_readable($root . '/config.php')) {
    // /calc/api -> dirname(__DIR__, 2) === subdomain root
    $root = dirname(__DIR__, 2);
}

try {
    $cfg = require $root . '/config.php';
} catch (Throwable $e) {
    http_response_code(500);
    jout(['ok'=>false,'where'=>'require_config','error'=>$e->getMessage(), 'root'=>$root]);
    exit;
}

try {
    require_once $root . '/lib/solver.php';
} catch (Throwable $e) {
    http_response_code(500);
    jout(['ok'=>false,'where'=>'require_solver','error'=>$e->getMessage(), 'root'=>$root]);
    exit;
}

/* ---------- Helpers ---------- */
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
    $ret['sigma']   = $sigma;
    $ret['sigma_T'] = $sigma_T;
    return $ret;
}

/**
 * Map normalized (lowercase) inputs to the canonical names MixSolver expects.
 */
function canonizeForSolver(array $in): array {
    $m = $in['mode'];
    switch ($m) {
        case 'brix_density':
            $out = [
                'mode'      => 'brix_density',
                'BrixATC'   => (float)$in['brix'],
                'BrixATC_T' => (float)$in['brix_t'],
                'Density'   => (float)$in['density'],
                'Density_T' => (float)$in['density_t'],
                'report_T'  => (float)$in['report_t'],
            ];
            break;
        case 'abv_brix':
            $out = [
                'mode'      => 'abv_brix',
                'ABV'       => (float)$in['abv'],
                'ABV_T'     => (float)$in['abv_t'],
                'BrixATC'   => (float)$in['brix'],
                'BrixATC_T' => (float)$in['brix_t'],
                'report_T'  => (float)$in['report_t'],
            ];
            break;
        case 'abv_density':
            $out = [
                'mode'      => 'abv_density',
                'ABV'       => (float)$in['abv'],
                'ABV_T'     => (float)$in['abv_t'],
                'Density'   => (float)$in['density'],
                'Density_T' => (float)$in['density_t'],
                'report_T'  => (float)$in['report_t'],
            ];
            break;
        case 'abv_sugarwv':
            $out = [
                'mode'       => 'abv_sugarwv',
                'ABV'        => (float)$in['abv'],
                'ABV_T'      => (float)$in['abv_t'],
                'Sugar_WV'   => (float)$in['sugarwv'],
                'Sugar_WV_T' => (float)$in['sugarwv_t'],
                'report_T'   => (float)$in['report_t'],
            ];
            break;
        case 'abm_sbm':
            $out = [
                'mode'      => 'abm_sbm',
                'abm'       => (float)$in['abm'],
                'sbm'       => (float)$in['sbm'],
                'report_T'  => (float)$in['report_t'],
            ];
            break;
        default:
            $out = ['mode' => $m] + $in;
    }

    // forward uncertainties if present
    if (!empty($in['sigma'])   && is_array($in['sigma']))   $out['sigma']   = $in['sigma'];
    if (!empty($in['sigma_T']) && is_array($in['sigma_T'])) $out['sigma_T'] = $in['sigma_T'];

    return $out;
}

/* ---------- Read & validate ---------- */
try {
    $raw = getJsonInput();
    $in  = normalizeInput($raw);

    $mode = $in['mode'];
    $required = match ($mode) {
        'abv_brix'     => ['abv','abv_t','brix','brix_t'],
        'abv_density'  => ['abv','abv_t','density','density_t'],
        'brix_density' => ['brix','brix_t','density','density_t'],
        'abv_sugarwv'  => ['abv','abv_t','sugarwv','sugarwv_t'],
        'abm_sbm'      => ['abm','sbm'],
        default        => null,
    };

    if ($required === null) {
        http_response_code(400);
        jout(['ok'=>false,'where'=>'validate_mode','error'=>'Invalid or missing mode. Use one of: abv_brix, abv_density, brix_density, abv_sugarwv, abm_sbm']);
        exit;
    }

    $missing = [];
    foreach ($required as $k) if (!array_key_exists($k, $in) || $in[$k] === null) $missing[] = $k;
    if ($missing) {
        http_response_code(400);
        jout(['ok'=>false,'where'=>'validate_inputs','error'=>'Missing required inputs','missing'=>$missing,'received'=>$in], JSON_PRETTY_PRINT);
        exit;
    }

    /* ---------- Connect DB & Solve ---------- */
    try {
        $db  = $cfg['db'];
        $dsn = "mysql:host={$db['host']};dbname={$db['name']};charset={$db['charset']}";
        $pdo = new PDO($dsn, $db['user'], $db['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (Throwable $e) {
        http_response_code(500);
        jout(['ok'=>false,'where'=>'pdo_connect','error'=>$e->getMessage()]);
        exit;
    }

    try { $solver = new MixSolver($pdo); }
    catch (Throwable $e) { http_response_code(500); jout(['ok'=>false,'where'=>'solver_construct','error'=>$e->getMessage()]); exit; }

    $canon = canonizeForSolver($in);

    try { $res = $solver->solve($canon); }
    catch (Throwable $e) { http_response_code(500); jout(['ok'=>false,'where'=>'solver_solve','error'=>$e->getMessage(),'sent'=>$canon]); exit; }

    if (!is_array($res) || !array_key_exists('ok', $res)) $res = ['ok'=>true,'result'=>$res];
    jout($res, JSON_PRETTY_PRINT); // pretty for success

} catch (Throwable $e) {
    http_response_code(500);
    jout(['ok'=>false,'where'=>'outer','error'=>$e->getMessage()]);
}

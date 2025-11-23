<?php
// /recipes/api/_bootstrap.php
declare(strict_types=1);

// Capture stray output early (BOM/notices) so we can always emit clean JSON
if (!headers_sent() && ob_get_level() === 0) { ob_start(); }

ini_set('session.use_only_cookies','1');
ini_set('session.cookie_httponly','1');
ini_set('session.cookie_samesite','Lax');
session_name('liquid_sess');
ini_set('session.use_strict_mode','1');
if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }

if (!isset($_SESSION['uid'])) {
  // prevent “role=admin” from persisting without a user
  unset($_SESSION['role']);
}

ini_set('display_errors','0');
error_reporting(E_ALL);

// Always emit JSON
function json_out($data, int $code=200): void {
  while (ob_get_level() > 0) { @ob_end_clean(); }
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  exit;
}
function out($data, int $code=200): void { json_out($data,$code); }


function can_edit_drink(PDO $pdo, ?int $uid, int $drink_id): bool {
  if (!$uid) return false;
  if (is_admin()) return true;
  $st = $pdo->prepare("SELECT 1 FROM drinks WHERE id=:id AND owner_id=:uid LIMIT 1");
  $st->execute([':id'=>$drink_id, ':uid'=>$uid]);
  return (bool)$st->fetchColumn();
}

function require_can_edit_drink(PDO $pdo, ?int $uid, int $drink_id): void {
  if (!can_edit_drink($pdo, $uid, $drink_id)) json_out(['error'=>'forbidden'], 403);
}

function ingredient_is_accessible(PDO $pdo, ?int $uid, int $ingredient_id): bool {
  if ($uid) {
    $st = $pdo->prepare("SELECT 1 FROM ingredients WHERE id=:id AND (is_public=1 OR owner_id=:uid) LIMIT 1");
    $st->execute([':id'=>$ingredient_id, ':uid'=>$uid]);
  } else {
    $st = $pdo->prepare("SELECT 1 FROM ingredients WHERE id=:id AND is_public=1 LIMIT 1");
    $st->execute([':id'=>$ingredient_id]);
  }
  return (bool)$st->fetchColumn();
}

// Convert PHP notices/warnings to exceptions (so they end up as JSON)
set_error_handler(function($sev,$msg,$file,$line){
  if (!(error_reporting() & $sev)) return false;
  throw new ErrorException($msg, 0, $sev, $file, $line);
});
set_exception_handler(function(Throwable $e){
  json_out(['error'=>'Server error','detail'=>$e->getMessage()],500);
});
register_shutdown_function(function(){
  $e = error_get_last();
  if (!$e) return;
  if (in_array($e['type'], [E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR,E_USER_ERROR], true)) {
    while (ob_get_level() > 0) { @ob_end_clean(); }
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error'=>'Fatal error','detail'=>$e['message'],'file'=>$e['file'],'line'=>$e['line']], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  }
});

// Load your DB file first so we can detect existing get_pdo()
require_once __DIR__ . '/../lib/db.php';

/**
 * Provide a tolerant get_pdo() only if your lib didn’t define one.
 * This avoids the “Cannot redeclare get_pdo()” fatal you’re seeing.
 */
if (!function_exists('get_pdo')) {
  function get_pdo(): PDO {
    static $cached = null;
    if ($cached instanceof PDO) return $cached;

    if (function_exists('pdo'))    { $p = pdo();    if ($p instanceof PDO) return $cached = $p; }
    if (function_exists('db'))     { $p = db();     if ($p instanceof PDO) return $cached = $p; }
    if (function_exists('getPDO')) { $p = getPDO(); if ($p instanceof PDO) return $cached = $p; }
    if (class_exists('DB')) {
      if (method_exists('DB','get')) { $p = \DB::get(); if ($p instanceof PDO) return $cached = $p; }
      if (method_exists('DB','pdo')) { $p = \DB::pdo(); if ($p instanceof PDO) return $cached = $p; }
    }
    if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) return $cached = $GLOBALS['pdo'];

    throw new RuntimeException('No PDO connection available (expecting pdo()/db()/getPDO()/DB::get()/DB::pdo() or $GLOBALS["pdo"]).');
  }
}

// Convenience helpers used across endpoints
function require_method(string $method): void {
  if (($_SERVER['REQUEST_METHOD'] ?? '') !== $method) {
    json_out(['error'=>'Method not allowed'],405);
  }
}
function current_user_id(): ?int { return isset($_SESSION['uid']) ? (int)$_SESSION['uid'] : null; }
function is_admin(): bool {
  return isset($_SESSION['uid'], $_SESSION['role']) && $_SESSION['role'] === 'admin';
}


// 1) If admin, no visibility filter needed.
function where_access_clause(string $alias, ?int $uid): string {
  if (is_admin()) return '1=1';
  return $uid
    ? "($alias.is_public = 1 OR $alias.owner_id = :uid)"
    : "($alias.is_public = 1)";
}

// keep the alias:
function where_visibility(string $alias, ?int $uid): string {
  return where_access_clause($alias, $uid);
}

// 2) If admin, skip the hard access check entirely.
function require_drink_and_ingredient_access(PDO $pdo, ?int $uid, int $drink_id): void {
  if (is_admin()) return; // <-- bypass
  if (!drink_is_accessible($pdo, $uid, $drink_id) ||
      !drink_has_only_accessible_ingredients($pdo, $uid, $drink_id)) {
    json_out(['ok'=>false,'error'=>'Forbidden'], 403);
  }
}

// Drink access helpers
function drink_is_accessible(PDO $pdo, ?int $uid, int $drink_id): bool {
  $sql = $uid
    ? "SELECT 1 FROM drinks d WHERE d.id=:did AND (d.is_public=1 OR d.owner_id=:uid) LIMIT 1"
    : "SELECT 1 FROM drinks d WHERE d.id=:did AND d.is_public=1 LIMIT 1";
  $st = $pdo->prepare($sql);
  $uid ? $st->execute([':did'=>$drink_id,':uid'=>$uid]) : $st->execute([':did'=>$drink_id]);
  return (bool)$st->fetchColumn();
}
function drink_has_only_accessible_ingredients(PDO $pdo, ?int $uid, int $drink_id): bool {
  $sql = $uid
    ? "SELECT 1 FROM recipes r JOIN ingredients i ON i.id=r.ingredient_id
       WHERE r.drink_id=:did AND NOT (i.is_public=1 OR i.owner_id=:uid) LIMIT 1"
    : "SELECT 1 FROM recipes r JOIN ingredients i ON i.id=r.ingredient_id
       WHERE r.drink_id=:did AND i.is_public<>1 LIMIT 1";
  $st = $pdo->prepare($sql);
  $uid ? $st->execute([':did'=>$drink_id,':uid'=>$uid]) : $st->execute([':did'=>$drink_id]);
  return !$st->fetchColumn();
}


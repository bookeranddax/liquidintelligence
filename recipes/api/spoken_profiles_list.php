<?php
declare(strict_types=1);
ini_set('display_errors','1'); ini_set('display_startup_errors','1'); error_reporting(E_ALL);

require_once __DIR__ . '/_bootstrap.php';

function out($data,$code=200){ http_response_code($code); header('Content-Type: application/json'); echo json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); exit; }

// ...top of file remains the same

try {
  $pdo = get_pdo();
  $uid = current_user_id();

  // --- ADD: auto-seed defaults on empty table ---
  $cnt = (int)$pdo->query("SELECT COUNT(*) FROM spoken_profiles")->fetchColumn();
  if ($cnt === 0) {
    $ins = $pdo->prepare("INSERT INTO spoken_profiles
      (name, config_json, owner_user, is_default, is_public, created_at, updated_at)
      VALUES (?,?,?,?,?,NOW(),NOW())");

    // US default (30 mL "ounce", fats/shorts on, 1 pour=30, above=quarters (4), below=eighths (8))
    $cfgUS = json_encode([
      'stPour'=>30, 'nUnit'=>1, 'unitName'=>'ounce',
      'hAccuracy'=>1, 'fatShort'=>1,
      'unitDiv'=>4, 'hAunitDiv'=>8,
      'useBarspoon'=>1, 'barspoon'=>3.75,
      'useDash'=>1, 'dash'=>0.82,
      'useDrop'=>1, 'drop'=>0.05
    ], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);

    // UK/EU default (25 mL "part")
    $cfgEU = json_encode([
      'stPour'=>25, 'nUnit'=>1, 'unitName'=>'part',
      'hAccuracy'=>1, 'fatShort'=>1,
      'unitDiv'=>4, 'hAunitDiv'=>8,
      'useBarspoon'=>1, 'barspoon'=>3.75,
      'useDash'=>1, 'dash'=>0.82,
      'useDrop'=>1, 'drop'=>0.05
    ], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);

    $ins->execute(['US 30 mL ounce (default)', $cfgUS, null, 1, 1]);
    $ins->execute(['EU/UK 25 mL part',          $cfgEU, null, 0, 1]);
  }
  // --- end auto-seed ---

  $activeId = null;

  // active by user mapping or session
  if ($uid) {
    $st = $pdo->prepare("SELECT profile_id FROM user_spoken_profile WHERE user_id=? LIMIT 1");
    $st->execute([$uid]);
    $activeId = $st->fetchColumn();
  } else {
    if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
    $activeId = $_SESSION['spoken_profile_id'] ?? null;
  }

  $p = [];
  $sql = "SELECT id, name, owner_user, is_default, is_public FROM spoken_profiles
          WHERE (is_public=1) OR (owner_user ".($uid?'= ?':'IS NULL').")
          ORDER BY is_default DESC, name";
  if ($uid) $p[] = $uid;
  $st = $pdo->prepare($sql);
  $st->execute($p);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);

  foreach ($rows as &$r) {
    $r['owner_user'] = $r['owner_user'] ? (int)$r['owner_user'] : null;
    $r['is_default'] = (int)$r['is_default'];
    $r['is_public']  = (int)$r['is_public'];
    $r['selected']   = ($activeId && (int)$activeId === (int)$r['id']) ? 1 : 0;
  }

  out(['ok'=>true,'items'=>$rows]);

} catch(Throwable $e){
  out(['ok'=>false,'error'=>'Server error','detail'=>$e->getMessage()],500);
}

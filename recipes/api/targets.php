<?php
// /recipes/api/targets.php
declare(strict_types=1);
ini_set('display_errors','1'); ini_set('display_startup_errors','1'); error_reporting(E_ALL);

require_once __DIR__ . '/_bootstrap.php';

function out($data,$code=200){
  http_response_code($code);
  header('Content-Type: application/json');
  echo json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  exit;
}

try{
  $pdo = get_pdo();
  $uid = current_user_id(); // may be null
  $method = $_SERVER['REQUEST_METHOD'];

  if ($method === 'GET') {
    $drink_id = (int)($_GET['drink_id'] ?? 0);
    if ($drink_id <= 0) out(['ok'=>false,'error'=>'Missing drink_id'], 400);

    // If logged in: prefer my row, then public. If logged out: only public.
    if ($uid) {
      $st = $pdo->prepare("
        SELECT id, drink_id,
               target_vol_ml, target_abv_pct, target_sugar_pct, target_acid_pct,
               granularity_ml,
               owner_id, is_public, created_at, updated_at
        FROM targets
        WHERE drink_id = :drink_id
          AND (owner_id = :uid OR is_public = 1)
        ORDER BY (owner_id = :uid) DESC, is_public DESC, id ASC
        LIMIT 1
      ");
      $st->execute([':drink_id'=>$drink_id, ':uid'=>$uid]);
    } else {
      $st = $pdo->prepare("
        SELECT id, drink_id,
               target_vol_ml, target_abv_pct, target_sugar_pct, target_acid_pct,
               granularity_ml,
               owner_id, is_public, created_at, updated_at
        FROM targets
        WHERE drink_id = :drink_id
          AND is_public = 1
        ORDER BY id ASC
        LIMIT 1
      ");
      $st->execute([':drink_id'=>$drink_id]);
    }

    $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    out(['ok'=>true,'item'=>$row]); // 200 even if null (prevents browser auth prompts)
  }

  if ($method === 'POST') {
    // Save/update targets (requires login)
    if (!$uid) out(['ok'=>false,'error'=>'Login required'], 401);

    $drink_id = (int)($_POST['drink_id'] ?? 0);
    if ($drink_id <= 0) out(['ok'=>false,'error'=>'Missing drink_id'], 400);

    // accept blanks as NULL
    $f = fn($k)=> (isset($_POST[$k]) && $_POST[$k] !== '') ? (float)$_POST[$k] : null;

    $target_vol_ml    = $f('target_vol_ml');
    $target_abv_pct   = $f('target_abv_pct');
    $target_sugar_pct = $f('target_sugar_pct');
    $target_acid_pct  = $f('target_acid_pct');
    $granularity_ml   = $f('granularity_ml');

    // Upsert on (drink_id, owner_id)
    // Ensure you have a UNIQUE index:  ALTER TABLE targets ADD UNIQUE KEY uq_targets_owner (drink_id, owner_id);
    $sel = $pdo->prepare('SELECT id FROM targets WHERE drink_id=? AND owner_id=? LIMIT 1');
    $sel->execute([$drink_id, $uid]);
    $id = $sel->fetchColumn();

    if ($id) {
      $upd = $pdo->prepare('
        UPDATE targets
        SET target_vol_ml=?, target_abv_pct=?, target_sugar_pct=?, target_acid_pct=?, granularity_ml=?, updated_at=NOW()
        WHERE id=? AND owner_id=?
      ');
      $upd->execute([$target_vol_ml, $target_abv_pct, $target_sugar_pct, $target_acid_pct, $granularity_ml, (int)$id, $uid]);
      out(['ok'=>true,'id'=>(int)$id,'updated'=>1]);
    } else {
      $ins = $pdo->prepare('
        INSERT INTO targets (drink_id, owner_id, is_public, target_vol_ml, target_abv_pct, target_sugar_pct, target_acid_pct, granularity_ml, created_at, updated_at)
        VALUES (?,?,?,?,?,?,?,?,NOW(),NOW())
      ');
      $ins->execute([$drink_id, $uid, 0, $target_vol_ml, $target_abv_pct, $target_sugar_pct, $target_acid_pct, $granularity_ml]);
      out(['ok'=>true,'id'=>(int)$pdo->lastInsertId(),'created'=>1]);
    }
  }

  out(['ok'=>false,'error'=>'Method not allowed'], 405);

} catch (Throwable $e) {
  out(['ok'=>false,'error'=>'Server error','detail'=>$e->getMessage()], 500);
}

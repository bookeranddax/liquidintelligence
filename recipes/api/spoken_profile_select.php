<?php
declare(strict_types=1);
ini_set('display_errors','1'); ini_set('display_startup_errors','1'); error_reporting(E_ALL);

require_once __DIR__ . '/_bootstrap.php';

function out($data,$code=200){ http_response_code($code); header('Content-Type: application/json'); echo json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); exit; }

try {
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') out(['error'=>'method not allowed'],405);

  $pdo = get_pdo();
  $uid = current_user_id();

  $pid = (int)($_POST['profile_id'] ?? 0);
  if ($pid <= 0) out(['error'=>'profile_id required'],400);

  // Ensure profile exists and is visible to the user
  $p = [':id'=>$pid];
  $sql = "SELECT id, owner_user, is_public FROM spoken_profiles WHERE id=:id";
  $st = $pdo->prepare($sql); $st->execute($p);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if (!$row) out(['error'=>'not found'],404);
  $owner = $row['owner_user'] ? (int)$row['owner_user'] : null;
  $public= (int)$row['is_public'] === 1;

  if (!$public && (!$uid || $owner !== $uid) && !is_admin()) {
    out(['error'=>'forbidden'],403);
  }

  // Persist selection
  if ($uid) {
    // upsert
    $pdo->prepare("INSERT INTO user_spoken_profile (user_id, profile_id, updated_at)
                   VALUES (?, ?, NOW())
                   ON DUPLICATE KEY UPDATE profile_id=VALUES(profile_id), updated_at=NOW()")
        ->execute([$uid, $pid]);
  }

  if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
  $_SESSION['spoken_profile_id'] = $pid;

  out(['ok'=>true,'id'=>$pid]);

} catch(Throwable $e){
  out(['ok'=>false,'error'=>'Server error','detail'=>$e->getMessage()],500);
}

<?php
// /recipes/api/constraints.php
declare(strict_types=1);
ini_set('display_errors','1'); ini_set('display_startup_errors','1'); error_reporting(E_ALL);
require_once __DIR__.'/_bootstrap.php';
$pdo = get_pdo();
$uid = current_user_id();
function out($d,$c=200){ http_response_code($c); header('Content-Type: application/json'); echo json_encode($d,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); exit; }
if(!$uid) out(['error'=>'Login required'],401);

try{
  $drink_id = (int)($_POST['drink_id'] ?? 0);
  $ingredient_id = (int)($_POST['ingredient_id'] ?? 0);
  if($drink_id<=0 || $ingredient_id<=0) out(['error'=>'drink_id and ingredient_id required'],400);

  $hold = isset($_POST['hold']) ? (int)!!$_POST['hold'] : 0;
  $low  = ($_POST['testLow']  ?? '') !== '' ? (float)$_POST['testLow']  : null;
  $high = ($_POST['testHigh'] ?? '') !== '' ? (float)$_POST['testHigh'] : null;

  // Upsert into user's overlay row (create if needed)
  // Prefer existing user row
  $st = $pdo->prepare("SELECT id FROM recipes WHERE drink_id=? AND ingredient_id=? AND owner_id=? LIMIT 1");
  $st->execute([$drink_id, $ingredient_id, $uid]);
  $id = $st->fetchColumn();

  if ($id) {
    $u = $pdo->prepare("UPDATE recipes SET hold=?, testLow=?, testHigh=? WHERE id=?");
    $u->execute([$hold, $low, $high, (int)$id]);
  } else {
    // seed with null mlParts/mlTest; just constraints
    $i = $pdo->prepare("INSERT INTO recipes (drink_id,ingredient_id,mlParts,position,mlTest,hold,testLow,testHigh,owner_id,is_public)
                        VALUES (?,?,?,?,?,?,?,?,?,0)");
    $i->execute([$drink_id,$ingredient_id,null,null,null,$hold,$low,$high,$uid]);
  }
  out(['ok'=>true]);
} catch(Throwable $e){
  out(['error'=>'Server error','detail'=>$e->getMessage()],500);
}

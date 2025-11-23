<?php
// /recipes/api/recipes_reorder.php
declare(strict_types=1);


require_once __DIR__ . '/_bootstrap.php';



try {
  require_method('POST');

  $pdo = get_pdo();
  $uid = current_user_id();
  if (!$uid) out(['ok'=>false,'error'=>'Login required'], 401);

  $drink_id = (int)($_POST['drink_id'] ?? 0);
  if ($drink_id <= 0) out(['ok'=>false,'error'=>'Missing drink_id'], 400);
  // optional, UI sends it; order is global so we accept but don't use
   $target = $_POST['target'] ?? '';

  // Read drink owner + lock; editing requires owner or admin, and not locked (unless admin)
  $st = $pdo->prepare("SELECT owner_id, drink_locked FROM drinks WHERE id = :did");
  $st->execute([':did'=>$drink_id]);
  $d = $st->fetch(PDO::FETCH_ASSOC);
  if (!$d) out(['ok'=>false,'error'=>'Drink not found'], 404);

  $isOwner = ((int)$d['owner_id'] === (int)$uid);
  if (!$isOwner && !is_admin()) out(['ok'=>false,'error'=>'Forbidden'], 403);
  if (!is_admin() && !empty($d['drink_locked'])) out(['ok'=>false,'error'=>'Drink is locked'], 403);

  // Parse the order payload (array of ids or [{id:...}, ...])
  $rawOrder = $_POST['order'] ?? '[]';
  $submitted = json_decode($rawOrder, true);
  if (!is_array($submitted)) {
    out(['ok'=>false,'error'=>'Bad order payload','detail'=>'order must be JSON array of ids or objects with id'], 400);
  }

  $submittedIds = [];
  foreach ($submitted as $it) {
    if (is_array($it) && isset($it['id'])) {
      $id = (int)$it['id'];
    } else {
      $id = is_numeric($it) ? (int)$it : 0;
    }
    if ($id > 0) $submittedIds[] = $id;
  }
  $submittedIds = array_values(array_unique($submittedIds));

  // Get ALL recipe row ids for this drink (no per-row ownership)
  $st = $pdo->prepare("
    SELECT id
    FROM recipes
    WHERE drink_id = :did
    ORDER BY (position IS NULL) ASC, position ASC, id ASC
  ");
  $st->execute([':did'=>$drink_id]);
  $allIds = $st->fetchAll(PDO::FETCH_COLUMN, 0);

  if (!$allIds) out(['ok'=>true,'updated'=>0,'skipped'=>0]); // nothing to reorder

  // Compose the new order:
  // 1) keep any ids submitted that belong to this drink
  $allowed = array_flip($allIds);
  $front = [];
  foreach ($submittedIds as $id) {
    if (isset($allowed[$id])) $front[] = $id;
  }
  // 2) append remaining ids in their existing order
  $remaining = array_values(array_diff($allIds, $front));
  $newOrder = array_merge($front, $remaining);

  // Write positions in steps of 10
  $pdo->beginTransaction();
  try {
    $pos = 10;
    $upd = $pdo->prepare("UPDATE recipes SET position = :pos WHERE id = :id AND drink_id = :did");
    $count = 0;
    foreach ($newOrder as $id) {
      $upd->execute([':pos'=>$pos, ':id'=>$id, ':did'=>$drink_id]);
      $pos += 10;
      $count += ($upd->rowCount() > 0) ? 1 : 0;
    }
    $pdo->commit();
    // report how many submitted ids were ignored because they weren’t part of this drink
    $skipped = count($submittedIds) - count($front);
    out(['ok'=>true,'updated'=>$count,'skipped'=>max(0,$skipped)]);
  } catch (Throwable $e) {
    $pdo->rollBack();
    out(['ok'=>false,'error'=>'Reorder failed','detail'=>$e->getMessage()], 500);
  }

} catch (Throwable $e) {
  out(['ok'=>false,'error'=>'Server error','detail'=>$e->getMessage()], 500);
}



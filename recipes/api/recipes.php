<?php
// /recipes/api/recipes.php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

$pdo = get_pdo();
$uid = current_user_id();
$AM  = is_admin();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

/**
 * Utility: read DELETE body as array (since PHP doesn't populate $_POST for DELETE)
 */
function read_delete_body(): array {
  $raw = file_get_contents('php://input');
  if (!$raw) return [];
  parse_str($raw, $out);
  return is_array($out) ? $out : [];
}

/**
 * Common: compute numbers for response
 */
function row_select_sql(string $view): string {
  // ml expression depends on view
  $ml = ($view === 'test') ? 'COALESCE(r.mlTest, r.mlParts)' : 'r.mlParts';

  return "
    SELECT
      r.id,
      r.drink_id,
      r.ingredient_id,
      $ml AS ml,
      r.position,
      r.mlParts,
      r.mlTest,
      r.hold,
      r.testLow,
      r.testHigh,
      i.ingredient,
      i.ethanol,
      i.sweetness,
      i.titratable_acid,
      (COALESCE(i.ethanol,0)         * COALESCE($ml,0)) AS alcohol_ml,
      (COALESCE(i.sweetness,0)       * COALESCE($ml,0)) AS sugar_g,
      (COALESCE(i.titratable_acid,0) * COALESCE($ml,0)) AS acid_g
    FROM recipes r
    JOIN ingredients i ON i.id = r.ingredient_id
    WHERE r.drink_id = :drink_id
  ";
}

/**
 * GET: list rows for a drink
 *   /recipes/api/recipes.php?drink_id=...&view=recipe|test
 */
if ($method === 'GET') {
  try {
    $drink_id = (int)($_GET['drink_id'] ?? 0);
    if ($drink_id <= 0) json_out(['error' => 'drink_id required'], 400);

    // Must be able to see the drink & its ingredients (admin bypass happens inside)
    require_drink_and_ingredient_access($pdo, $uid, $drink_id);

    $view = (($_GET['view'] ?? 'recipe') === 'test') ? 'test' : 'recipe';

// Visibility (admin => 1=1). If logged out, don't use :uid placeholders.
if ($AM) {
  $whereR = '1=1';
  $whereI = '1=1';
} else if ($uid) {
  $whereR = '(r.is_public = 1 OR r.owner_id = :uidR OR r.owner_id IS NULL)'; // tolerate null owner rows
  $whereI = '(i.is_public = 1 OR i.owner_id = :uidI)';
} else {
  // Logged out: only public rows (and allow NULL owner on recipe rows)
  $whereR = '(r.is_public = 1 OR r.owner_id IS NULL)';
  $whereI = '(i.is_public = 1)';
}

$sql = row_select_sql($view) . "
  AND $whereR
  AND $whereI
  ORDER BY (r.position IS NULL) ASC, r.position ASC, i.ingredient ASC, r.id ASC
";

$st = $pdo->prepare($sql);
$params = [':drink_id' => $drink_id];
if (!$AM && $uid) {
  $params[':uidR'] = $uid;
  $params[':uidI'] = $uid;
}
$st->execute($params);


    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    json_out(['items' => $rows], 200);

  } catch (Throwable $e) {
    json_out(['error' => 'Server error', 'detail' => $e->getMessage()], 500);
  }
}

/**
 * POST: insert/update a row
 *  Inputs:
 *    drink_id (required)
 *    ingredient_id (required)
 *    target = 'recipe' | 'test' (defaults to recipe)
 *    ml (for recipe) OR mlTest (for test)
 *    position (optional, numeric)
 *    id (optional; if provided, updates that row if it belongs to this drink)
 */
if ($method === 'POST') {
  try {
    require_method('POST');

    $drink_id      = (int)($_POST['drink_id'] ?? 0);
    $ingredient_id = (int)($_POST['ingredient_id'] ?? 0);
    $target        = (($_POST['target'] ?? 'recipe') === 'test') ? 'test' : 'recipe';
    $mlParts       = isset($_POST['ml'])     ? (float)$_POST['ml']     : null;
    $mlTest        = isset($_POST['mlTest']) ? (float)$_POST['mlTest'] : null;
    $position      = ($_POST['position'] ?? '') === '' ? null : (int)$_POST['position'];
    $row_id        = ($_POST['id'] ?? '') === '' ? null : (int)$_POST['id'];

    if ($drink_id <= 0 || $ingredient_id <= 0) {
      json_out(['error' => 'drink_id and ingredient_id required'], 400);
    }

    // Only owner (or admin) may edit a drink
    if (!$AM) require_can_edit_drink($pdo, $uid, $drink_id);

    // Non-admins: ingredient must be accessible
    if (!$AM && !ingredient_is_accessible($pdo, $uid, $ingredient_id)) {
      json_out(['error' => 'ingredient not accessible'], 403);
    }

    // If updating by id, verify the row belongs to this drink
    if ($row_id) {
      $chk = $pdo->prepare('SELECT drink_id FROM recipes WHERE id = ?');
      $chk->execute([$row_id]);
      $ownDrink = (int)$chk->fetchColumn();
      if ($ownDrink !== $drink_id) {
        json_out(['error' => 'row/drink mismatch'], 400);
      }
    }

    // Find existing row for (drink, ingredient)
    $sel = $pdo->prepare('SELECT id FROM recipes WHERE drink_id = ? AND ingredient_id = ? LIMIT 1');
    $sel->execute([$drink_id, $ingredient_id]);
    $existingId = (int)($sel->fetchColumn() ?: 0);

    if ($existingId || $row_id) {
      // Update
      $idToUse = $row_id ?: $existingId;

      if ($target === 'test') {
        $sql  = 'UPDATE recipes SET mlTest = :mlTest';
        $args = [':mlTest' => $mlTest];
      } else {
        $sql  = 'UPDATE recipes SET mlParts = :mlParts, position = :position';
        $args = [':mlParts' => $mlParts, ':position' => $position];
      }
      $sql .= ' WHERE id = :id';
      $args[':id'] = $idToUse;

      $upd = $pdo->prepare($sql);
      $upd->execute($args);

      json_out(['ok' => true, 'id' => $idToUse], 200);

    } else {
      // Insert (owner = drink owner; recipe rows are governed by drink ownership)
      // Get drink owner to mirror relationships
      $own = $pdo->prepare('SELECT owner_id FROM drinks WHERE id = ?');
      $own->execute([$drink_id]);
      $drink_owner = (int)$own->fetchColumn();
      if (!$drink_owner && !$AM) {
        json_out(['error'=>'drink not found'], 404);
      }

      $ins = $pdo->prepare('INSERT INTO recipes
        (drink_id, ingredient_id, mlParts, position, mlTest, hold, testLow, testHigh, owner_id, is_public)
        VALUES (:drink_id, :ingredient_id, :mlParts, :position, :mlTest, 0, NULL, NULL, :owner_id, 0)
      ');
      $ins->execute([
        ':drink_id'      => $drink_id,
        ':ingredient_id' => $ingredient_id,
        ':mlParts'       => ($target === 'test') ? null : $mlParts,
        ':position'      => $position,
        ':mlTest'        => ($target === 'test') ? $mlTest : null,
        ':owner_id'      => $drink_owner ?: ($uid ?? null),
      ]);
      $newId = (int)$pdo->lastInsertId();
      json_out(['ok'=>true, 'id'=>$newId], 200);
    }

  } catch (Throwable $e) {
    json_out(['error' => 'Server error', 'detail' => $e->getMessage()], 500);
  }
}

/**
 * DELETE: delete a row by id
 *  id in query (?id=) or in body (for fetch DELETE with form body)
 */
if ($method === 'DELETE') {
  try {
    $body = read_delete_body();
    $id   = (int)(($_GET['id'] ?? $body['id'] ?? 0));
    if ($id <= 0) json_out(['error'=>'id required'], 400);

    // Verify the row’s drink and edit rights
    $st = $pdo->prepare('SELECT drink_id FROM recipes WHERE id = ?');
    $st->execute([$id]);
    $drink_id = (int)$st->fetchColumn();
    if ($drink_id <= 0) json_out(['error'=>'not found'], 404);

    if (!$AM) require_can_edit_drink($pdo, $uid, $drink_id);

    $del = $pdo->prepare('DELETE FROM recipes WHERE id = ?');
    $del->execute([$id]);

    json_out(['ok'=>true], 200);

  } catch (Throwable $e) {
    json_out(['error'=>'Server error', 'detail'=>$e->getMessage()], 500);
  }
}

// Fallback
json_out(['error'=>'Method not allowed'], 405);

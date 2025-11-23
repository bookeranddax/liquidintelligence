<?php
// /recipes/api/drinks.php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

$pdo = get_pdo();
$uid = current_user_id();

try {
  $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

  // ------------------------ GET ------------------------
  if ($method === 'GET') {
    // Version family: ?action=family&id=###
    if (isset($_GET['action']) && $_GET['action'] === 'family') {
      $id = (int)($_GET['id'] ?? 0);
      if ($id <= 0) { json_out(['error'=>'bad id'], 400); }

      // Find the root parent for this drink: parent_drink_id or self
      $st = $pdo->prepare("SELECT COALESCE(parent_drink_id, id) AS root_id FROM drinks WHERE id = ?");
      $st->execute([$id]);
      $root = (int)$st->fetchColumn();
      if (!$root) { json_out(['error'=>'not found'], 404); }

      // Visibility gating for non-admin
      if (is_admin()) {
        $sql = "SELECT id, drink_name, version_tag, is_current
                FROM drinks
                WHERE COALESCE(parent_drink_id, id) = :root
                ORDER BY is_current DESC, id DESC";
        $p = [':root' => $root];
      } else {
        $vis = where_visibility('d', $uid);
        $sql = "SELECT d.id, d.drink_name, d.version_tag, d.is_current
                FROM drinks d
                WHERE COALESCE(d.parent_drink_id, d.id) = :root AND $vis
                ORDER BY d.is_current DESC, d.id DESC";
        $p = [':root' => $root];
        if ($uid) { $p[':uid'] = $uid; }
      }
      $st = $pdo->prepare($sql);
      $st->execute($p);
      $rows = $st->fetchAll(PDO::FETCH_ASSOC);
      json_out(['items'=>$rows]);
    }

    // Single drink by id
    if (isset($_GET['id'])) {
      $id = (int)$_GET['id'];
      if ($id <= 0) { json_out(['error' => 'bad id'], 400); }

      if (is_admin()) {
        $sql = "SELECT d.id, d.drink_name, d.drink_variant, d.drink_type,
                       d.drink_notes, d.drink_source, d.drink_date,
                       d.drink_locked, d.owner_id, d.is_public
                FROM drinks d
                WHERE d.id = :id
                LIMIT 1";
        $st = $pdo->prepare($sql);
        $st->execute([':id' => $id]);
      } else {
        $vis = where_visibility('d', $uid);
        $sql = "SELECT d.id, d.drink_name, d.drink_variant, d.drink_type,
                       d.drink_notes, d.drink_source, d.drink_date,
                       d.drink_locked, d.owner_id, d.is_public
                FROM drinks d
                WHERE d.id = :id AND $vis
                LIMIT 1";
        $p = [':id' => $id];
        if ($uid) { $p[':uid'] = $uid; }
        $st = $pdo->prepare($sql);
        $st->execute($p);
      }

      $row = $st->fetch(PDO::FETCH_ASSOC);
      if (!$row) { json_out(['error' => 'not found'], 404); }
      json_out($row);
    }

    // List (optionally filtered by ?q=)
    $q = trim($_GET['q'] ?? '');
    $params = [];

    if (is_admin()) {
      $sql = "SELECT d.id, d.drink_name, d.drink_variant, d.drink_locked, d.drink_type
              FROM drinks d
              WHERE 1";
    } else {
      $vis = where_visibility('d', $uid);
      $sql = "SELECT d.id, d.drink_name, d.drink_variant, d.drink_locked, d.drink_type
              FROM drinks d
              WHERE $vis";
      if ($uid) { $params[':uid'] = $uid; }
    }

    if ($q !== '') {
      $sql .= " AND (d.drink_name LIKE :q1
                 OR d.drink_variant LIKE :q2
                 OR CONCAT_WS(', ', d.drink_name, d.drink_variant) LIKE :q3)";
      $like = '%' . $q . '%';
      $params[':q1'] = $like;
      $params[':q2'] = $like;
      $params[':q3'] = $like;
    }

    $sql .= " ORDER BY d.drink_name, d.drink_variant";
    $st = $pdo->prepare($sql);
    $st->execute($params);

    json_out(['items' => $st->fetchAll(PDO::FETCH_ASSOC)]);
  }

  // ------------------------ POST ------------------------
  if ($method === 'POST') {
    if (!$uid && !is_admin()) { json_out(['error' => 'login required'], 401); }
    $action = $_POST['action'] ?? '';

    // ------ create ------
    if ($action === 'create') {
      $name    = trim($_POST['drink_name'] ?? '');
      if ($name === '') { json_out(['error' => 'drink_name required'], 400); }

      $variant = trim($_POST['drink_variant'] ?? '');
      $type    = $_POST['drink_type']    ?? null;
      $notes   = $_POST['drink_notes']   ?? null;
      $source  = $_POST['drink_source']  ?? null;
      $date    = $_POST['drink_date']    ?? null;

      // Admin can create on behalf of someone else via owner_id; otherwise current user.
      $owner_id = is_admin() && isset($_POST['owner_id'])
        ? (int)$_POST['owner_id']
        : (int)$uid;

      if ($owner_id <= 0) { json_out(['error' => 'owner_id required'], 400); }

      // (optional) verify owner exists
      $chk = $pdo->prepare('SELECT 1 FROM users WHERE id = ?');
      $chk->execute([$owner_id]);
      if (!$chk->fetchColumn()) { json_out(['error' => 'owner not found'], 400); }

      $st = $pdo->prepare("INSERT INTO drinks
          (drink_name, drink_variant, drink_type, drink_notes, drink_source, drink_date,
           drink_locked, owner_id, is_public)
          VALUES (?,?,?,?,?,?,0,?,0)");
      $st->execute([$name, $variant, $type, $notes, $source, $date, $owner_id]);

      json_out(['ok' => true, 'id' => (int)$pdo->lastInsertId()]);
    }

    // ------ update ------
    if ($action === 'update') {
      $id = (int)($_POST['id'] ?? 0);
      if ($id <= 0) { json_out(['error' => 'id required'], 400); }

      // Get current owner (row must exist)
      $chk = $pdo->prepare("SELECT owner_id FROM drinks WHERE id = ?");
      $chk->execute([$id]);
      $row = $chk->fetch(PDO::FETCH_ASSOC);
      if (!$row) { json_out(['error' => 'not found'], 404); }

      $owner = (int)$row['owner_id'];
      if (!is_admin() && $owner !== (int)$uid) {
        json_out(['error' => 'Forbidden (not owner)'], 403);
      }

      // Accept fields (null means "leave as-is" via COALESCE)
      $type    = $_POST['drink_type']    ?? null;
      $notes   = $_POST['drink_notes']   ?? null;
      $source  = $_POST['drink_source']  ?? null;
      $date    = $_POST['drink_date']    ?? null;
      $locked  = isset($_POST['drink_locked']) ? (int)!!$_POST['drink_locked'] : null;
      $name    = $_POST['drink_name']    ?? null;
      $variant = $_POST['drink_variant'] ?? null;
      //$is_pub  = isset($_POST['is_public']) ? (int)!!$_POST['is_public'] : null; //Next 6 lines were added for versioning
      $is_pub  = null;
      if (isset($_POST['is_public'])) {
        $is_pub = (int)!!$_POST['is_public'];
      } elseif (isset($_POST['public'])) {
        $is_pub = (int)!!$_POST['public']; // legacy alias
      }


      // Optional admin-only ownership transfer
      $new_owner = null;
      if (is_admin() && isset($_POST['owner_id'])) {
        $new_owner = (int)$_POST['owner_id'];
        if ($new_owner <= 0) { json_out(['error' => 'owner_id invalid'], 400); }
        $chk2 = $pdo->prepare('SELECT 1 FROM users WHERE id = ?');
        $chk2->execute([$new_owner]);
        if (!$chk2->fetchColumn()) { json_out(['error' => 'owner not found'], 400); }
      }

      $sql = "UPDATE drinks SET
                drink_type    = ?,
                drink_notes   = ?,
                drink_source  = ?,
                drink_date    = ?,
                drink_locked  = COALESCE(?, drink_locked),
                drink_name    = COALESCE(?, drink_name),
                drink_variant = COALESCE(?, drink_variant)";
      $params = [$type, $notes, $source, $date, $locked, $name, $variant];

      if ($is_pub !== null) { $sql .= ", is_public = ?"; $params[] = $is_pub; }
      if ($new_owner !== null) { $sql .= ", owner_id = ?"; $params[] = $new_owner; }

      $sql .= " WHERE id = ?";
      $params[] = $id;

      $st = $pdo->prepare($sql);
      $st->execute($params);

      json_out(['ok' => true]);
    }

// ------ clone ------
if ($action === 'clone') {
  $from = (int)($_POST['from_id'] ?? 0);
  if ($from <= 0) { json_out(['error' => 'from_id required'], 400); }

  // You must be able to see the source (admin sees all)
  if (is_admin()) {
    $st = $pdo->prepare("SELECT id, drink_name, drink_variant, drink_type, drink_notes, drink_source, drink_date,
                                parent_drink_id
                         FROM drinks WHERE id = ?");
    $st->execute([$from]);
  } else {
    $vis = where_visibility('d', $uid);
    $st = $pdo->prepare("SELECT d.id, d.drink_name, d.drink_variant, d.drink_type, d.drink_notes, d.drink_source, d.drink_date,
                                d.parent_drink_id
                         FROM drinks d WHERE d.id = :id AND $vis");
    $p = [':id' => $from];
    if ($uid) { $p[':uid'] = $uid; }
    $st->execute($p);
  }
  $src = $st->fetch(PDO::FETCH_ASSOC);
  if (!$src) { json_out(['error' => 'source not found'], 404); }

  $newName = trim($_POST['drink_name'] ?? ($src['drink_name'] ?? 'Copy'));
  $newVar  = trim($_POST['drink_variant'] ?? ''); // no longer force "Variant"
  $vtag    = trim($_POST['version_tag'] ?? '');   // optional version label

  // Admin can choose new owner; otherwise current user
  $new_owner = is_admin() && isset($_POST['owner_id'])
    ? (int)$_POST['owner_id']
    : (int)$uid;
  if ($new_owner <= 0) { json_out(['error' => 'owner_id required'], 400); }
  $chk3 = $pdo->prepare('SELECT 1 FROM users WHERE id = ?');
  $chk3->execute([$new_owner]);
  if (!$chk3->fetchColumn()) { json_out(['error' => 'owner not found'], 400); }

  // Version family root = source's parent if present, else the source itself
  $root = (int)($src['parent_drink_id'] ?? 0);
  if ($root <= 0) $root = (int)$src['id'];

  $pdo->beginTransaction();
  try {
    // Mark other versions in this family as not current
    $pdo->prepare("UPDATE drinks SET is_current = 0 WHERE COALESCE(parent_drink_id, id) = ?")
        ->execute([$root]);

    // Create new drink as the current version, private by default
    $ins = $pdo->prepare("INSERT INTO drinks
      (drink_name, drink_variant, drink_type, drink_notes, drink_source, drink_date,
       drink_locked, owner_id, is_public, parent_drink_id, version_tag, is_current)
      VALUES (?,?,?,?,?,?,0,?,0,?,?,1)");
    $ins->execute([
      $newName, $newVar,
      $src['drink_type'], $src['drink_notes'], $src['drink_source'], $src['drink_date'],
      $new_owner,
      $root,
      ($vtag !== '' ? $vtag : null)
    ]);
    $newId = (int)$pdo->lastInsertId();

    // Copy recipe rows
    if (is_admin()) {
      $rs = $pdo->prepare("SELECT ingredient_id, mlParts, position, mlTest, hold, testLow, testHigh
                           FROM recipes WHERE drink_id = ?");
      $rs->execute([$from]);
    } else {
      $visI = where_visibility('i', $uid);
      $sqlr = "SELECT r.ingredient_id, r.mlParts, r.position, r.mlTest, r.hold, r.testLow, r.testHigh
               FROM recipes r
               JOIN ingredients i ON i.id = r.ingredient_id
               WHERE r.drink_id = :from AND $visI";
      $rs = $pdo->prepare($sqlr);
      $rp = [':from' => $from];
      if ($uid) { $rp[':uid'] = $uid; }
      $rs->execute($rp);
    }

    $rows = $rs->fetchAll(PDO::FETCH_ASSOC);
    if ($rows) {
      $ri = $pdo->prepare("INSERT INTO recipes
        (drink_id, ingredient_id, mlParts, position, mlTest, hold, testLow, testHigh, owner_id, is_public)
        VALUES (?,?,?,?,?,?,?,?,?,0)");
      foreach ($rows as $r) {
        $ri->execute([
          $newId, $r['ingredient_id'], $r['mlParts'], $r['position'], $r['mlTest'],
          $r['hold'], $r['testLow'], $r['testHigh'],
          $new_owner
        ]);
      }
    }

    $pdo->commit();
    json_out(['ok' => true, 'id' => $newId]);

  } catch (Throwable $e) {
    $pdo->rollBack();
    json_out(['error' => 'clone failed', 'detail' => $e->getMessage()], 500);
  }
}


    json_out(['error' => 'unknown action'], 400);
  }

  // Fallback if not GET/POST
  json_out(['error' => 'method not allowed'], 405);

} catch (Throwable $e) {
  json_out(['error' => 'Server error', 'detail' => $e->getMessage()], 500);
}

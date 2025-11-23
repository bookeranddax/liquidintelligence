<?php
// /recipes/api/ingredients.php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

try {
  $pdo = get_pdo();
  $uid = current_user_id();
  $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

  if ($method === 'GET') {
    $q = trim($_GET['q'] ?? '');
    $admin = is_admin();
    $vis = $admin ? '1=1' : where_visibility('i', $uid);
    $sql = "SELECT i.id, i.ingredient, i.i_type, i.ethanol, i.brix, i.sweetness,
                   i.titratable_acid, i.density, i.ri, i.vetted, i.flag
            FROM ingredients i
            WHERE $vis";
    $p = [];
    if (!$admin && $uid) $p[':uid'] = $uid;

    if ($q !== '') {
      $sql .= " AND i.ingredient LIKE :q";
      $p[':q'] = '%' . $q . '%';
    }
    $sql .= " ORDER BY i.ingredient";

    $st = $pdo->prepare($sql);
    $st->execute($p);
    json_out(['items' => $st->fetchAll(PDO::FETCH_ASSOC)]);
  }

  if ($method === 'POST') {
    if (!$uid) json_out(['error' => 'login required'], 401);
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
      $name = trim($_POST['ingredient'] ?? '');
      if ($name === '') json_out(['error' => 'ingredient name required'], 400);

      $itype = $_POST['i_type'] ?? null;
      $eth   = ($_POST['ethanol'] ?? '')          !== '' ? (float)$_POST['ethanol']          : null;
      $brix  = ($_POST['brix'] ?? '')             !== '' ? (float)$_POST['brix']             : null;
      $sweet = ($_POST['sweetness'] ?? '')        !== '' ? (float)$_POST['sweetness']        : null;
      $acid  = ($_POST['titratable_acid'] ?? '')  !== '' ? (float)$_POST['titratable_acid']  : null;

      $sql = "INSERT INTO ingredients
                (ingredient, i_type, ethanol, brix, sweetness, titratable_acid, owner_id, is_public)
              VALUES (?,?,?,?,?,?,?,0)";
      $st = $pdo->prepare($sql);
      $st->execute([$name, $itype, $eth, $brix, $sweet, $acid, $uid]);

      json_out(['ok' => true, 'id' => (int)$pdo->lastInsertId()]);
    }

    json_out(['error' => 'unknown action'], 400);
  }

  json_out(['error' => 'method not allowed'], 405);

} catch (Throwable $e) {
  json_out(['error' => 'Server error', 'detail' => $e->getMessage()], 500);
}

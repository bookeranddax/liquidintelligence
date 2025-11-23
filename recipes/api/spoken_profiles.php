<?php
// /recipes/api/spoken_profiles.php
header('Content-Type: application/json; charset=utf-8');
session_start();
require_once __DIR__ . '/db.php'; // whatever you use to get $pdo
// If you don’t have db.php, replace with your PDO bootstrap.

try {
  $uid = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;

  // Current user’s selected profile (if any)
  $currentId = null;
  if ($uid) {
    $stmt = $pdo->prepare("SELECT spoken_profile_id FROM users WHERE id = ?");
    $stmt->execute([$uid]);
    $currentId = $stmt->fetchColumn();
    if ($currentId !== null) $currentId = (int)$currentId;
  } else {
    // Anonymous: prefer global default
    $stmt = $pdo->query("SELECT id FROM spoken_profiles WHERE is_default = 1 AND owner_user IS NULL ORDER BY id DESC LIMIT 1");
    $currentId = (int)$stmt->fetchColumn();
  }

  // List global + user-owned profiles
  if ($uid) {
    $stmt = $pdo->prepare("
      SELECT id, name, is_default, (owner_user IS NULL) AS is_global
      FROM spoken_profiles
      WHERE owner_user IS NULL OR owner_user = ?
      ORDER BY is_default DESC, is_global DESC, name
    ");
    $stmt->execute([$uid]);
  } else {
    $stmt = $pdo->query("
      SELECT id, name, is_default, (owner_user IS NULL) AS is_global
      FROM spoken_profiles
      WHERE owner_user IS NULL
      ORDER BY is_default DESC, name
    ");
  }
  $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

  echo json_encode([
    'ok' => true,
    'current_id' => $currentId,
    'items' => $items
  ]);
} catch (Exception $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'Server error','detail'=>$e->getMessage()]);
}


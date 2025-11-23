<?php
declare(strict_types=1);

// In production, don't echo errors in responses (they break JSON)
if (!defined('DEV_MODE') || !DEV_MODE) {
  ini_set('display_errors','0');
  ini_set('display_startup_errors','0');
} else {
  ini_set('display_errors','1');
  ini_set('display_startup_errors','1');
}
error_reporting(E_ALL);

require_once __DIR__ . '/_bootstrap.php';

try {
  $pdo = get_pdo();
  $uid = current_user_id();

  if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    json_out(['error' => 'method not allowed'], 405);
  }
  if (!$uid) {
    json_out(['error' => 'login required'], 401);
  }

  $action     = $_POST['action'] ?? '';
  $name       = trim($_POST['name'] ?? '');
  $configJson = $_POST['config'] ?? '';

  if ($name === '')        json_out(['error' => 'name required'], 400);
  if ($configJson === '')  json_out(['error' => 'config required'], 400);

  $cfg = json_decode($configJson, true);
  if (!is_array($cfg))     json_out(['error' => 'config must be valid JSON'], 400);

  // Normalize/validate while preserving unknown keys
  $norm = function($k, $def, $min=0, $max=null) use ($cfg){
    $v = $cfg[$k] ?? $def;
    if (!is_numeric($v)) return $def;
    $v = 0 + $v;
    if ($v < $min) $v = $min;
    if ($max !== null && $v > $max) $v = $max;
    return $v;
  };
  $bool = function($k, $def=0) use ($cfg){ return !empty($cfg[$k]) ? 1 : 0; };

  $safe = $cfg;
  $safe['stPour']      = $norm('stPour', 30, 0.0001, 1000);
  $safe['nUnit']       = $bool('nUnit');
  $safe['hAccuracy']   = $bool('hAccuracy');
  $safe['fatShort']    = $bool('fatShort');
  $safe['unitDiv']     = $norm('unitDiv',   !empty($safe['nUnit']) ? 4 : 5, 0.0001, 1000);
  $safe['hAunitDiv']   = $norm('hAunitDiv', 0, 0, 1000);
  $safe['useBarspoon'] = $bool('useBarspoon');
  $safe['useDash']     = $bool('useDash');
  $safe['useDrop']     = $bool('useDrop');
  $safe['barspoon']    = $norm('barspoon', 3.75, 0, 100);
  $safe['dash']        = $norm('dash', 0.82, 0, 10);
  $safe['drop']        = $norm('drop', 0.05, 0, 1);

  // Note: any extra keys (like unitName, dilution, etc.) stay in $safe
  $configJson = json_encode($safe, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);

  if ($action === 'create') {
    $st = $pdo->prepare("
      INSERT INTO spoken_profiles (name, config_json, owner_user, is_default, is_public, created_at, updated_at)
      VALUES (?, ?, ?, 0, 0, NOW(), NOW())
    ");
    $st->execute([$name, $configJson, $uid]);
    json_out(['ok'=>true,'id'=>(int)$pdo->lastInsertId()]);
  }

  if ($action === 'update_or_clone') {
    $find = $pdo->prepare("SELECT id FROM spoken_profiles WHERE owner_user = ? AND name = ? LIMIT 1");
    $find->execute([$uid, $name]);
    $row = $find->fetch(PDO::FETCH_ASSOC);

    if ($row && !empty($row['id'])) {
      $id = (int)$row['id'];
      $up = $pdo->prepare("UPDATE spoken_profiles SET config_json=?, updated_at=NOW() WHERE id=?");
      $up->execute([$configJson, $id]);
      json_out(['ok'=>true,'id'=>$id, 'updated'=>true]);
    } else {
      $st = $pdo->prepare("
        INSERT INTO spoken_profiles (name, config_json, owner_user, is_default, is_public, created_at, updated_at)
        VALUES (?, ?, ?, 0, 0, NOW(), NOW())
      ");
      $st->execute([$name, $configJson, $uid]);
      json_out(['ok'=>true,'id'=>(int)$pdo->lastInsertId(), 'created'=>true]);
    }
  }

  json_out(['error'=>'unknown action'], 400);

} catch(Throwable $e){
  // Avoid leaking HTML; send JSON error
  json_out(['error'=>'Server error','detail'=>$e->getMessage()], 500);
}

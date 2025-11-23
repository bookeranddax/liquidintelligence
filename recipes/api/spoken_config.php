<?php
// /recipes/api/spoken_config.php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

try {
  require_method('GET');
  $pdo = get_pdo();
  $uid = current_user_id();

  // Does the table exist?
  $hasProfiles = false;
  try {
    $pdo->query("SELECT 1 FROM spoken_profiles LIMIT 1");
    $hasProfiles = true;
  } catch (Throwable $e) {
    $hasProfiles = false;
  }

  if ($hasProfiles) {
    // Discover columns so we can work with either owner_id or user_id, etc.
    $cols = [];
    try {
      $cst = $pdo->query("SHOW COLUMNS FROM spoken_profiles");
      while ($r = $cst->fetch(PDO::FETCH_ASSOC)) {
        $cols[] = $r['Field'];
      }
    } catch (Throwable $e) {
      $cols = [];
    }

    $ownerCol  = null; foreach (['owner_id','user_id','uid'] as $c) if (in_array($c, $cols, true)) { $ownerCol = $c; break; }
    $nameCol   = in_array('name', $cols, true) ? 'name' : (in_array('profile_name',$cols,true) ? 'profile_name' : null);
    $cfgCol    = in_array('config_json',$cols,true) ? 'config_json' : (in_array('config',$cols,true) ? 'config' : null);
    $activeCol = null; foreach (['is_active','active','selected'] as $c) if (in_array($c,$cols,true)) { $activeCol = $c; break; }

    // Try to fetch the active profile for this user (if logged in), otherwise the latest.
    if ($cfgCol) {
      $sql = "SELECT id"
           . ($nameCol ? ", $nameCol AS name" : ", '' AS name")
           . ", $cfgCol AS cfg
              FROM spoken_profiles";
      $where = [];
      $params = [];

      if ($uid && $ownerCol) { $where[] = "$ownerCol = :uid"; $params[':uid'] = $uid; }
      if ($activeCol)        { $where[] = "$activeCol = 1"; }

      if ($where) $sql .= " WHERE " . implode(' AND ', $where);
      $sql .= " ORDER BY id DESC LIMIT 1";

      $st = $pdo->prepare($sql);
      $st->execute($params);
      $row = $st->fetch(PDO::FETCH_ASSOC);

      if ($row) {
        $cfg = json_decode($row['cfg'] ?? '{}', true) ?: [];
        $name = $row['name'] ?? 'My Profile';
        json_out([
          'profile_id' => (int)$row['id'],
          'name'       => $name,
          'config'     => $cfg,
        ]);
      }
    }
    // If table exists but we couldn't read a row (e.g., no cfg column), fall through to default.
  }

  // Fallback default config – safe for your formatter
  json_out([
    'name'   => 'Default',
    'config' => [
      'stPour'      => 30,
      'nUnit'       => 1,
      'unitName'    => 'ounce',
      'unitDiv'     => 4,
      'hAccuracy'   => 0,
      'hAunitDiv'   => 0,
      'useBarspoon' => 1,
      'useDash'     => 1,
      'useDrop'     => 1,
      'barspoon'    => 3.75,
      'dash'        => 0.82,
      'drop'        => 0.05,
      'fatShort'    => 1,
    ]
  ], 200);

} catch (Throwable $e) {
  json_out(['error' => 'Fatal error', 'detail' => $e->getMessage()], 500);
}

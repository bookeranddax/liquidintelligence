<?php
// /recipes/api/auth.php
declare(strict_types=1);

require_once __DIR__.'/_bootstrap.php';

$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
  switch ($action) {
    case 'whoami': {
      // No DB needed; just reflect session
      $uid   = isset($_SESSION['uid']) ? (int)$_SESSION['uid'] : null;
      $email = $_SESSION['email'] ?? null;
      $role  = $_SESSION['role']  ?? null;

      json_out([
        'ok'    => true,
        'uid'   => $uid,
        'id'    => $uid,   // alias for your JS
        'email' => $email,
        'role'  => $role,
        'is_admin' => ($role === 'admin'),
        'admin' => ($role === 'admin'),
      ], 200);
      // json_out exits
      break; // optional safeguard; json_out() already exits
    }

    case 'register': {
      require_method('POST');
      $pdo  = get_pdo();
      $email = trim($_POST['email'] ?? '');
      $pass  = $_POST['password'] ?? '';

      if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($pass) < 8) {
        json_out(['error' => 'Invalid email or password too short (min 8)'], 400);
      }

      $hash = password_hash($pass, PASSWORD_DEFAULT);
      $stmt = $pdo->prepare('INSERT INTO users (email, password_hash, role) VALUES (?, ?, "user")');
      try {
        $stmt->execute([$email, $hash]);
      } catch (PDOException $e) {
        // Likely UNIQUE violation
        json_out(['error' => 'Email already exists'], 409);
      }
      json_out(['ok' => true], 200);
    }

    case 'login': {
      require_method('POST');
      $pdo   = get_pdo();
      $email = trim($_POST['email'] ?? '');
      $pass  = $_POST['password'] ?? '';

      $stmt = $pdo->prepare('SELECT id, password_hash, role FROM users WHERE email = ?');
      $stmt->execute([$email]);
      $u = $stmt->fetch(PDO::FETCH_ASSOC);

      if (!$u || !password_verify($pass, $u['password_hash'])) {
        json_out(['error' => 'Invalid credentials'], 401);
      }

      $_SESSION['uid']   = (int)$u['id'];
      $_SESSION['role']  = $u['role'];
      $_SESSION['email'] = $email;

      json_out(['ok' => true, 'uid' => (int)$u['id'], 'role' => $u['role'], 'is_admin' => ($u['role'] === 'admin'), 'admin'    => ($u['role'] === 'admin')]);
    }

    case 'logout': {
      require_method('POST');
      // Clear session safely
      $_SESSION = [];


      if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
          $params['path'], $params['domain'], $params['secure'], $params['httponly']
        );
      }
      
      session_destroy();
      json_out(['ok' => true]);
    }

    case '':
      json_out(['error' => 'missing action'], 400);

    default:
      json_out(['error' => 'unknown action'], 400);
  }

} catch (Throwable $e) {
  json_out(['error' => 'Server error', 'detail' => $e->getMessage()], 500);
}

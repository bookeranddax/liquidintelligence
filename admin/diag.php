<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/db.php';

$pdo = pdo();
$now = $pdo->query('SELECT NOW() AS now')->fetch()['now'] ?? '(unknown)';
echo "PHP: " . PHP_VERSION . "<br>DB OK. MySQL time: $now";
<?php
ini_set('display_errors', '1'); error_reporting(E_ALL);

echo "<pre>Diagnosing config paths...\n";

$base = dirname(__DIR__);          // /recipes
$paths = [
  $base . '/../config.php',
  $base . '/config.php',
  $base . '/../calc/config.php',
  $_SERVER['DOCUMENT_ROOT'] . '/config.php',
];

foreach ($paths as $p) {
  $r = realpath($p);
  echo ($r ? "[FOUND] " : "[MISS]  ") . $p . ($r ? "  -> $r\n" : "\n");
}

echo "\nIncluding the one db.php currently uses...\n";
require_once __DIR__ . '/../lib/db.php'; // this will include your config.php internally

// After db.php ran require_once, dump top-level variable names from the global scope:
$globals = array_keys($GLOBALS);
sort($globals);
echo "\nTop-level variables/consts present after include:\n";
foreach ($globals as $g) {
  if (in_array($g, ['GLOBALS','_SERVER','_GET','_POST','_FILES','_COOKIE','_SESSION','_REQUEST','_ENV'])) continue;
  if (is_array($GLOBALS[$g])) {
    echo "  \${$g} (array)\n";
  } elseif (is_string($GLOBALS[$g])) {
    $val = strlen($GLOBALS[$g]) > 64 ? substr($GLOBALS[$g],0,64) . '…' : $GLOBALS[$g];
    echo "  \${$g} = " . var_export($val,true) . "\n";
  } else {
    echo "  \${$g} (" . gettype($GLOBALS[$g]) . ")\n";
  }
}

// Also show defined constants that look DB-ish:
echo "\nDB-like constants:\n";
foreach (get_defined_constants(true)['user'] ?? [] as $k => $v) {
  if (stripos($k, 'DB') !== false || stripos($k, 'MYSQL') !== false) {
    echo "  $k = " . (is_string($v) ? $v : var_export($v,true)) . "\n";
  }
}

echo "\nDone.\n";

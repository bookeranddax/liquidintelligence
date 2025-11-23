<?php
function json_out($data, int $code=200) {
  http_response_code($code);
  header('Content-Type: application/json');
  //echo json_encode($data, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_NUMERIC_CHECK);
  // replace existing echo json_encode(...) line
  echo json_encode(
    $data,
    JSON_UNESCAPED_SLASHES
    | JSON_UNESCAPED_UNICODE
    | JSON_NUMERIC_CHECK
    | JSON_PARTIAL_OUTPUT_ON_ERROR
    | JSON_INVALID_UTF8_SUBSTITUTE
  );

  exit;
}
function clamp($v, $a, $b) { return max($a, min($b, $v)); }
function lin_interp($x, $x0, $x1, $y0, $y1) {
  if ($x1 == $x0) return $y0;
  $t = ($x - $x0) / ($x1 - $x0);
  return $y0 + $t * ($y1 - $y0);
}

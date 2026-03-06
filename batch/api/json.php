<?php namespace Vanderbilt\CensusExternalModule;

function json_out($data, int $code = 200): void {
  http_response_code($code);
  header('Content-Type: application/json');
  header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
  header('Pragma: no-cache');
  echo json_encode($data);
  exit;
}

// Returns decoded JSON as array, or empty array if invalid/missing
function json_in(): array {
  $raw = file_get_contents('php://input') ?: '';
  $data = json_decode($raw, true);
  return is_array($data) ? $data : [];
}
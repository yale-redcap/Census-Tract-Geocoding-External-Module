<?php

require_once __DIR__ . "/RunState.php";
require_once __DIR__ . "/json.php";

$itemId = $_POST["itemId"] ?? null; // allow form-data override

if ($itemId === null) json_out(["error" => "Missing itemId"], 400);

$runId = $_POST["runId"] ?? null; // allow form-data override for testing via browser

if ($runId === null) json_out(["error" => "Missing runId"], 400);

$runState = new RunState();

$state = $runState->read($runId);

if (($state["status"] ?? "") !== "running") {

  json_out(["error" => "Run is not running", "runStatus" => $state["status"] ?? "unknown"], 409);
}

// --- Idempotency gate (pseudo) ---
// This must check whatever “processed” indicator exists in your existing data model.
// If already processed, return OK without doing external call again.
if (already_processed($itemId)) {

  json_out(["ok" => true, "skipped" => true, "runStatus" => "running"]);
}

try {
  // --- Do the upstream API call and persist ---
  // call_upstream_api_and_persist($itemId);

  $match_result = mark_processed($itemId); // if that's how your system works

  // update run counters best-effort
  $runState->update($runId, function($s) {
    $s["done"] = (int)($s["done"] ?? 0) + 1;
    return $s;
  });

  json_out(["ok" => true, "runStatus" => "running", "matchResult" => $match_result]);
} 
catch (Throwable $e) {

  $runState->update($runId, function($s) {
    $s["failed"] = (int)($s["failed"] ?? 0) + 1;
    return $s;
  });

  json_out(["error" => $e->getMessage(), "runStatus" => "running"], 500);
}

// ----- stubs -----
function already_processed($itemId): bool {
  // TODO: query your DB/model
  return false;
}

function mark_processed($itemId): string {
  // TODO: write processed indicator using existing model

  return (rand(0, 10) < 2) ? "not matched" : "matched";
}


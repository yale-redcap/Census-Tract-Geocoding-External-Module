<?php

require_once __DIR__ . "/RunState.php";
require_once __DIR__ . "/json.php";

$total = (int)($_POST["total"] ?? 0); 

$runState = new RunState();

$runId = $runState->create([
  "operator" => $_SERVER["REMOTE_USER"] ?? null,
  "total" => $total,
  "done" => 0,
  "failed" => 0,
]);

json_out(["runId" => $runId]);
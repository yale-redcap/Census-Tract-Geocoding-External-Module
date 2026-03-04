<?php

require_once __DIR__ . "/RunState.php";
require_once __DIR__ . "/json.php";

$in = json_in();
$runId = $in["runId"] ?? "";

$runState = new RunState();
$state = $runState->update($runId, function($s) {
  $s["status"] = "stopped";
  return $s;
});

json_out(["ok" => true, "status" => $state["status"]]);
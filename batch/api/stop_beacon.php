<?php

// This is a placeholder for the “stop_beacon” API endpoint. 
// eventually we might want to track these “beacon stops” in the run state, 
// but for now this is just a fire-and-forget endpoint to receive the beacon signal from the client when the tab/window is closed.

require_once __DIR__ . "/json.php";

$runId = $_POST["runId"] ?? null;

// do stuff



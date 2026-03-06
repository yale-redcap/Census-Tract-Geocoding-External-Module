<?php namespace Vanderbilt\CensusExternalModule;

use RuntimeException;
use Throwable;

require_once __DIR__ . "/RunState.php";
require_once __DIR__ . "/json.php";

//$project_id = $_POST["project_id"] ?? null;

// establish project context for EM functions
//$_GET['pid'] = $project_id;

//if (!isset($_GET['pid']) || $_GET['pid'] !== $project_id) {
//    json_out(["error" => "Missing the dang project_id: " . $project_id . ", received: " . ($_GET['pid'] ?? 'null')], 400);
//}

//$module = new CensusExternalModule();

$itemId = $_POST["itemId"] ?? null; 

if ($itemId === null) json_out(["error" => "Missing itemId"], 400);

if ($project_id === null) json_out(["error" => "Missing project_id"], 400);

$runId = $_POST["runId"] ?? null; 

if ($runId === null) json_out(["error" => "Missing runId"], 400);

$benchmarkVintage = $_POST["benchmarkVintage"] ?? null;

if ($benchmarkVintage === null) json_out(["error" => "Missing Benchmark-Vintage"], 400);

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

  $api_result = mark_processed($itemId, $benchmarkVintage); // if that's how your system works

  // update run counters best-effort
  $runState->update($runId, function($s) {
    $s["done"] = (int)($s["done"] ?? 0) + 1;
    return $s;
  });

  json_out(["ok" => true, "runStatus" => "running", "api_result" => $api_result]);
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

function mark_processed($itemId, $benchmarkVintage): array {
    global $module;

    $apiRequirements = $module->getApiRequirements( $itemId );

  	$address = urlencode(preg_replace("/[^a-zA-Z0-9 ,]/","",$apiRequirements["address"] ?? ""));

	//$benchmark_vintage = $apiRequirements["benchmark_vintage"] ?? null;

    if ( !$benchmarkVintage || !$address ) {

        return [    
            "error" => "Missing required data for API call",
            "record" => $itemId,
            "address" => $address,
            "benchmarkVintage" => $benchmarkVintage,
            "apiRequirements" => $apiRequirements
        ];
    }

    $url = 'https://geocoding.geo.census.gov/geocoder/geographies/onelineaddress?address='.$address.'&'.$module->getSharedArgsBenchmark($benchmarkVintage);

	$ch = curl_init();
	curl_setopt($ch, CURLOPT_URL, $url);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
	curl_setopt($ch, CURLOPT_VERBOSE, 0);
	curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
	curl_setopt($ch, CURLOPT_AUTOREFERER, true);
	curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
	curl_setopt($ch, CURLOPT_FRESH_CONNECT, 1);
	$output = curl_exec($ch);
	curl_close($ch);

    $data = json_decode($output, true);

    $apiResults = $data['result'] ?? null;

    $apiResults["benchmarkVintage"] = $benchmarkVintage;

    $saveResult = $module->saveApiResults( $apiRequirements, $apiResults );

    //$inputAddress = $data['result']['input']['address']['address'] ?? '';

    //$matchedAddress = $data['result']['addressMatches'][0]['matchedAddress'] ?? '';

    $returnData = [
        "benchmarkVintage" => $benchmarkVintage,
        "matchResult" => $saveResult["matchResult"] ?? "",
        "matchReport" => $saveResult["matchReport"] ?? "",
        "inputAddress" => $saveResult["inputAddress"] ?? "",
        "matchedAddress" => $saveResult["matchedAddress"] ?? "",
        "matchedAddresses" => $saveResult["matchedAddresses"] ?? [],
        "dataVector" => $saveResult["dataVector"] ?? null,
        "geodata" => $saveResult["geodata"] ?? null,
        "data_array" => $saveResult["data_array"] ?? null,
        "saveDataResponse" => $saveResult["saveDataResponse"] ?? null,
        "mappings" => $saveResult["mappings"] ?? null,
        //"apiResults" => $data['result'] ?? null,
        //"apiRequirements" => $apiRequirements,
        //"saveResult" => $saveResult
    ];

    return $returnData;
}


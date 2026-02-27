<?php namespace Vanderbilt\CensusExternalModule;

use DateTimeImmutable;
use Exception;
use ExternalModules\AbstractExternalModule;
use ExternalModules\ExternalModules;

class CensusExternalModule extends AbstractExternalModule
{
	const BENCHMARKS_URL = "https://geocoding.geo.census.gov/geocoder/benchmarks";
	const VINTAGES_URL = "https://geocoding.geo.census.gov/geocoder/vintages?benchmark=";

    const CACHE_TIMEOUT = 604800; // 604800; // 7 days in seconds

    const EMLOG_BV_MESSAGE = "Benchmark and Vintage combinations";

	function redcap_survey_page($project_id, $record, $instrument, $event_id, $group_id, $survey_hash, $response_id) {

        if ( ($confirmationObject = $this->getConfirmationObject($instrument)) === null ) {
            // This survey does not contain any of the fields specified in the EM settings, so do not add the script
            return;
        }

		$this->addScript($project_id, $record, $instrument, $event_id, $group_id, $survey_hash, $response_id, $confirmationObject, true);
	}

	function redcap_data_entry_form($project_id, $record, $instrument, $event_id, $group_id, $survey_hash = null, $response_id = null) {

        if ( ($confirmationObject = $this->getConfirmationObject($instrument)) === null ) {
            // This form does not contain any of the fields specified in the EM settings, so do not add the script
            return;
        }

		$this->addScript($project_id, $record, $instrument, $event_id, $group_id, $survey_hash, $response_id, $confirmationObject, false);
	}

	function redcap_module_configuration_settings($project_id, $settings): array {

        if ( !$project_id ) {
            // We're on the system settings
            return $settings;
        }

		$census_idx = array_search("censuses", array_column($settings, "key")) ?? null;

        if ($census_idx === null) {
            return $settings;
        }

        $combo_choices = null;
        $cacheLoaded = false;
        $apiLoaded = false;
        $bv_cache_record = null;
        $cacheTimeout = false;

        $debug_info = "";

        // fetch the cached combos first, so that later we can set up rules for API vs cache retrieval
        $bv_cache_record = $this->fetchBenchmarkVintageChoicesFromEmLog();

        if ( $bv_cache_record && isset($bv_cache_record["bv"]) && is_array($bv_cache_record["bv"]) && count($bv_cache_record["bv"]) > 0 ) {

            $cache_age_seconds = $bv_cache_record['age_seconds'] ?? null;

            $cache_age_days = ($cache_age_seconds !== null) ? round($cache_age_seconds / 86400, 2) : "unknown";

            $cacheTimeout = ( $cache_age_seconds && $cache_age_seconds > $this::CACHE_TIMEOUT );

            $debug_info .= "Cache age: " . $cache_age_days . " days\n";
            $debug_info .= "Cache timeout: " . ($cacheTimeout ? "yes" : "no") . "\n";

            if ( $cacheTimeout ) {

                $bv_cache_record = null; // cache is too old, so ignore it
            }
            else {

                $cacheLoaded = true;
            }
        }

        $debug_info .= "Cache loaded: " . ($cacheLoaded ? "yes" : "no") . "\n";

        // if no cache record or cache is stale, attempt to load from API
		if (!$cacheLoaded) {

			$combo_choices = $this->generateBenchmarkVintageChoices();

            $apiLoaded = ( is_array($combo_choices) && count($combo_choices) > 0 );
		}

        $debug_info .= "API loaded: " . ($apiLoaded ? "yes" : "no") . "\n";
        
        if ( $cacheLoaded && !$apiLoaded ) {

            $combo_choices = $bv_cache_record["bv"];
            $debug_info .= "Benchmark-vintage combinations loaded from cache with EM log_id: {$bv_cache_record['log_id']}\n";
        }

        // one last check
        if ( !is_array($combo_choices) || count($combo_choices) === 0 ) {

            return $settings;
        }
        
		$bv_idx = array_search("benchmark_vintage", array_column($settings[$census_idx]["sub_settings"], "key"));

		$settings[$census_idx]["sub_settings"][$bv_idx]["choices"] = $combo_choices;

        if ( $apiLoaded ) {
            
            $saveResult = $this->bv_save($combo_choices);

            $log_id = $saveResult["log_id"] ?? null;

            $debug_info .= $saveResult["message"] . "\n";

            $debug_info .= "Latest benchmark-vintage combinations are cached in EM log with log_id: {$log_id}\n";
        }

        $debug_info_idx = array_search("debug-info", array_column($settings, "key"));

        if ( $debug_info_idx !== null ) {

            $settings[$debug_info_idx]["name"] = "<p>Census Benchmark-Vintage retrieval pathway:</p><pre>" . $debug_info . "</pre>";
        }

		return $settings;
	}

	function redcap_every_page_before_render(){
		if(PHP_SAPI === 'cli'){
			return;
		}

		$expectedUrl = APP_URL_EXTMOD . 'manager/ajax/get-settings.php';
		$actualUrl = $_SERVER['REQUEST_SCHEME'] . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['SCRIPT_NAME'];
		if($expectedUrl !== $actualUrl){
			return;
		}

		$pid = $_GET['pid'] ?? null;
		if($pid === null){
			// We're on the system settings
			return;
		}
		else{
			// Make sure old settings get transitioned
			$this->getCensuses();
		}
	}

    function redcap_module_ajax($action, $payload){

        if ( $action === "fetchCensusBenchmarks" ) {

            return $this->fetchBenchmarkVintageChoicesFromEmLog();
        }
    }

	function transitionOldSettings(){
		$keys = $this->getProjectSetting('keys');
		$fields = $this->getProjectSetting('fields');

		if($fields === null){
			/**
			 * Do nothing.  This project has not been configured, and does not need settings to be transitioned.
			 * This was added to fix an odd subsetting display issue caused by setting 'mappings' to '[[]]',
			 * when they would normally be set to '[[null]]' when a single null 'fields' value exists.
			 */
			return;
		}

		$this->setProjectSetting('year', ['2020']);
		$this->setProjectSetting('censuses', ['true']);

		$mappingValues = [];
		foreach($fields as $field){
			$mappingValues[] = "true";
		}

		$this->setProjectSetting('mappings', [$mappingValues]);

		$this->setProjectSetting('keys', [$keys]);
		$this->setProjectSetting('fields', [$fields]);
	}

	function getCensuses(){
		$censuses = $this->getSubSettings('censuses');
		if (!isset($censuses[0]['year']) && !isset($censuses[0]['benchmark_vintage'])) {
			$this->transitionOldSettings();
			$censuses = $this->getSubSettings('censuses');
		}

		return $censuses;
	}

	function getSharedArgsBenchmark($benchmark_vintage) {
		[$benchmark, $vintage] = explode(" - ", $benchmark_vintage);

		return "benchmark={$benchmark}&vintage={$vintage}&format=json";
	}

	function generateBenchmarkVintageChoices(): array {

		$benchmarks_from_api = json_decode(file_get_contents($this::BENCHMARKS_URL), 1)["benchmarks"];

		$combo_choices = [];

        //$even = true;
		foreach ($benchmarks_from_api as $benchmark) {

            //$even = !$even; if ( $even ) { continue; } // just to force a "different" b-v set for testing

            $vintages = [];
			$this_benchmark_name = $benchmark["benchmarkName"];

			// NOTE: this can be quite slow, if this becomes a problem consider caching and setting up a cron to refresh these
			$vintages = json_decode(file_get_contents($this::VINTAGES_URL . $benchmark["id"]), 1)["vintages"];

			foreach($vintages as $vintage) {
				$vintage_choice = [
					"name" => $this_benchmark_name . " - " . $vintage["vintageName"],
					"value" => $this_benchmark_name . " - " . $vintage["vintageName"]
				];

				if ($vintage['isDefault']) {
					// bubble defaults to top
					array_unshift($combo_choices, $vintage_choice);
				} else {
					$combo_choices[] = $vintage_choice;
				}
			}
		}

		return $combo_choices;
	}

    private function bv_save($combos){

        $combos_json = json_encode($combos);
        $combos_hash = hash("sha256", $combos_json);

        // retrieve the most recently saved combos hash from the logs
        $current = $this->fetchBenchmarkVintageChoicesFromEmLog();

        if ( $current && $current["bv_hash"] === $combos_hash ) {
            // the combos have not changed since the last save, so do not log them again
            return [
                "log_id" => $current["log_id"],
                "message" => "Benchmark-vintage combinations have not changed since last save, so cache was not updated."
            ];
        }

        $log_id = $this->log( $this::EMLOG_BV_MESSAGE,
            [
                "bv_json" => $combos_json,
                "bv_hash" => $combos_hash
            ]
        );

        return [
            "log_id" => $log_id,
            "message" => "Benchmark-vintage combinations cached to EM log with log_id: {$log_id}"
        ];
    }

    function fetchBenchmarkVintageChoicesFromEmLog(){

        $pSql = "select timestamp, now() as db_now, log_id, bv_json, bv_hash where project_id = ? and message = ? order by timestamp desc";
        
        $params = [
            $this->getProjectId(),
            $this::EMLOG_BV_MESSAGE
        ];

        $result = $this->queryLogs($pSql, $params);

        if ( !$result || $result->num_rows === 0 ) {
            return false;
        }

        $row = $result->fetch_assoc();
        $bv_json = $row["bv_json"] ?? null;
        $bv_hash = $row["bv_hash"] ?? null;

        if ( !$bv_json || !$bv_hash ) {
            return false;
        }

        $bv = json_decode($bv_json, true);

        if ( !is_array($bv) || count($bv) === 0 ) {
            return false;
        }

        // make sure $bv is an array of arrays with "name" and "value" keys
        foreach ( $bv as $item ) {
            if ( !is_array($item) || !isset($item["name"]) || !isset($item["value"]) ) {
                return false;
            }
        }

        try {
            $db_now = new DateTimeImmutable($row["db_now"]);
            $timestamp = new DateTimeImmutable($row["timestamp"]);
        }
        catch (Exception $e) {
            // if there is an error parsing the dates, return false
            return false;
        }

        $age_seconds = $db_now->getTimestamp() - $timestamp->getTimestamp();

        // thus endeth the gauntlet
        return [
            "bv" => $bv,
            "bv_hash" => $bv_hash,
            "log_id" => $row["log_id"],            
            "age_seconds" => $age_seconds
        ];
    }

	/*
	 * @deprecated 2.0.0 The "year" option is no longer visible in the configuration settings
	 */
	function getSharedArgsYear($censusYear){
		$censusYear = (int)$censusYear;
		// NOTE: The US census is conducted every 10 years on years ending in 0
		$mostRecentCensusYear = (int) (floor($censusYear / 10) * 10);
		// HACK: US Census site eliminated most vintages and benchmarks in August 2024
		if (!in_array($censusYear, [2010, 2020])) { $censusYear = $mostRecentCensusYear; }
		if ($mostRecentCensusYear == $censusYear) {
			// NOTE: vintage Census<mostRecentCensusYear> is chosen for similarity to Census2020_Current scheme, namely the presence of data in "Census Blocks" field of API results
			// see comments on related PR for further details
			// https://github.com/vanderbilt-redcap/Census-Tract-Geocoding-External-Module/pull/4
			// HACK: At the release of ACS 2024, all other ACS benchmarks were eliminated as well as all non ACS2024 vintages
			$ACS_year = 2024;
			$benchmark = "Public_AR_ACS{$ACS_year}";
			$vintage = "Census{$mostRecentCensusYear}_ACS{$ACS_year}";
		} else {
			$benchmark = "Public_AR_Current";
			$vintage = "Census{$censusYear}_Current";
		}
		return "benchmark={$benchmark}&vintage={$vintage}&format=json";
	}

    /**
     * Since this function is called on every data entry and survey page,
     * we want a graceful and informative failure if upstream changes cause throwable errors.
     * 
     * @param string $form_name
     * @return array
     */
    public function getConfirmationObject($form_name) {

        try {

            return $this->getConfirmationObject_exec($form_name);

        } catch (\Throwable $e) {

            error_log("Census Geocoder EM failed: " . $e->getMessage());

            return [
                "execution_failure" => 1,
                "form_confirmed" => 0,
                "message" => $e->getMessage()
            ];
        }
    }

    /**
     * Returns a 'form confirmation object' that indicates whether the geocoder
     * should be loaded on the calling data entry form or survey.
     * 
     * Returns null if no EM fields are found on the form, to indicate that the geocoder should not be loaded.
     * 
     * Otherwise returns details on which EM fields were found and not found on the form, to assist with debugging EM configuration issues.
     * 
     * If the form is confirmed (all EM fields located on it), returns the name of the field that the geocode button should be anchored to. 
     * This will be the last input field (address, latitude, or longitude) found on the form, based on the current configuration. 
     * 
     * @param mixed $form_name 
     * @return mixed
     * @throws Exception 
     */
    private function getConfirmationObject_exec( $form_name ) {

        global $Proj;

        $form_fields = array_keys($Proj->forms[$form_name]['fields']);

        $inputFields = [
			$this->getProjectSetting('address'),
			$this->getProjectSetting('latitude'),
			$this->getProjectSetting('longitude')
        ];

        $outputFields = [
            $this->getProjectSetting('geocode_match_result'),
            $this->getProjectSetting('geocode_report')
        ];

        $fields_on_form = [];
        $fields_not_on_form = [];
        $buttonAnchorField = null;

        $censuses = $this->getCensuses();

        foreach ($censuses as $census) {

            foreach ($census['mappings'] as $mapping) {

                $field_name = $mapping['fields'];

                if ( $field_name && !in_array($field_name, $outputFields) ) { $outputFields[] = $field_name; }
            }
        }

        $form_confirmed = 1;
        $buttonAnchorField = null;

        $message = "";

        // iterate over input and output fields
        foreach ( array_merge($inputFields, $outputFields) as $field ) {

            if ( in_array($field, $form_fields) ) { 
                $fields_on_form[] = $field; 
            }
            else { $fields_not_on_form[] = $field; }
        }

        if ( count($fields_on_form) === 0 ) {

            return null; // no fields found, so return null to indicate that the script should not be added to the page
        }
        else {

            $form_confirmed = ( count($fields_not_on_form) === 0 ) ? 1 : 0;
        }

        if ( $form_confirmed ) {

            $message = "All Census Geocoder EM fields were found on the form {$form_name}. Geocoder will be loaded.";

            // now determine the button anchor field, which will be the last input field (address, latitude, or longitude) found on the form
            $i = count($inputFields);
            foreach ( $form_fields as $form_field ) {

                if ( in_array($form_field, $inputFields) ) {

                    $buttonAnchorField = $form_field;

                    if ( --$i === 0 ) { break; }
                }
            }
        }
        // return a message indicating which fields are missing
        else {

            $message = "The following fields are missing on the form {$form_name}:\n" 
                . implode("\n", $fields_not_on_form)
                . "\n\nPlease reconfigure the Census Geocoder EM settings to ensure that all fields (input and output) are located on the same form."
                . "\n\nGeocoder will not be loaded.";
        }

        return [
            "execution_failure" => 0,
            "form_confirmed" => $form_confirmed,
            "message" => $message,
            "buttonAnchorField" => $buttonAnchorField,
            "fields_on_form" => $fields_on_form,
            "fields_not_on_form" => $fields_not_on_form
        ];
    }

	function addScript($project_id, $record, $instrument, $event_id, $group_id, $survey_hash = null, $response_id = null, $confirmationObject = null, $isSurvey = false) {

		if (!$project_id) { return; }

		$this->initializeJavascriptModuleObject();

		$censuses = $this->getCensuses();

		$this->tt_addToJavascriptModuleObject("censuses", $censuses);

		$fields = [
			"addressField" => $this->getProjectSetting('address'),
			"latitudeField" => $this->getProjectSetting('latitude'),
			"longitudeField" => $this->getProjectSetting('longitude'),
            "geocodeReportField" => $this->getProjectSetting('geocode_report'),
            "geocodeMatchResultField" => $this->getProjectSetting('geocode_match_result'),
            "addGeoCodeButton" => $this->getProjectSetting('add_geocode_button'),
            "addBVDropdown" => $this->getProjectSetting('add_bv_dropdown'),
            "isSurvey" => $isSurvey,
            "buttonAnchorField" => $confirmationObject['buttonAnchorField'] ?? null,
            "confirmationObject" => $confirmationObject
		];
		$this->tt_addToJavascriptModuleObject("fields", $fields);

		$urls = [
			"getAddressUrl" => $this->getUrl("getAddress.php"),
			"getCoordinatesUrl" => $this->getUrl("getCoordinates.php")
		];
		$this->tt_addToJavascriptModuleObject("urls", $urls);

		echo '<script src="https://cdn.jsdelivr.net/npm/gasparesganga-jquery-loading-overlay@2.1.0/dist/loadingoverlay.min.js" integrity="sha384-MySkuCDi7dqpbJ9gSTKmmDIdrzNbnjT6QZ5cAgqdf1PeAYvSUde3uP8MGnBzuhUx"
				crossorigin="anonymous"></script>';

		echo <<<STYLETEXT
        <style>
            .geocode-input-missing {
                border: 2px solid red;
            }
        </style>
        STYLETEXT;

        $censusBenchmarks = [];

        if ( $fields["addBVDropdown"] ) {

            $bvObj = $this->fetchBenchmarkVintageChoicesFromEmLog();

            if ( $bvObj && isset($bvObj["bv"]) && is_array($bvObj["bv"]) && count($bvObj["bv"]) > 0 ) {

                $censusBenchmarks = $bvObj["bv"];
            }
        }

        $this->tt_addToJavascriptModuleObject("censusBenchmarks", $censusBenchmarks);

		echo "<script src='" . $this->getUrl("js/main.js") . "'></script>";
	}
}

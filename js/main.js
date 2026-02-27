$(document).ready(() => {

    console.log('Census Geocoder loaded');

    // apparently this is how one implements an enum in JavaScript
    const geocodeAPI = Object.freeze({
        addressLookup: 1,
        locationLookup: 2
    });

	const module = ExternalModules.Vanderbilt.CensusExternalModule;

	const censuses = module.tt('censuses');

	const fields                    = module.tt('fields');
	const urls                      = module.tt('urls');

    // field names for the address, latitude, and longitude fields specified in the EM config
	const addressField              = fields.addressField;
	const latitudeField             = fields.latitudeField;
	const longitudeField            = fields.longitudeField;

    // field names for the optional geocode match result and geocode report fields specified in the EM config
    const geocodeMatchResultField   = fields.geocodeMatchResultField;
    const geocodeReportField        = fields.geocodeReportField;

    const addGeoCodeButton          = fields.addGeoCodeButton; // option to trigger geocoding via button(s) added to the UI, 
    const addBVDropdown             = fields.addBVDropdown; // option to allow user to select additional benchmark/vintage combinations
    const isSurvey                  = fields.isSurvey; // whether we're on a survey page 

	const getAddressUrl             = urls.getAddressUrl; // the API endpoint for geocoding based on an address
	const getCoordinatesUrl         = urls.getCoordinatesUrl; // the API endpoint for geocoding based on latitude and longitude

    // Build the global geocodeData object, which contains all REDCap field names mapped to census data across the censuses, 
    // along with field values that will be updated as data are extracted from the API responses and processed.
    const geocodeData = newGeocodeDataRecord();

    // Build the global geocodeReport object, which will be populated with details about the geocoding process.
    const geocodeReport = newGeocodeReportObject();

    // A list of all valid benchmark-vintage combinations (for the options BV dropdown).
    const censusBenchmarks = newBenchmarkVintageList();

    // A consolidated mapping of all Tiger Web attribute keys to REDCap field names across the censuses processed.
    const geocodeFieldMappings = newGeocodeFieldMappings();

    console.log('censuses:', censuses);
    console.log('tt_censusBenchmarks', module.tt('censusBenchmarks'));
    console.log('censusBenchmarks:', censusBenchmarks);
    console.log('geocodeFieldMappings:', geocodeFieldMappings);
    //console.log('urls:', urls);
    //console.log('geocodeData:', geocodeData);
    //console.log('geocodeAPI:', geocodeAPI);
    //console.log('isSurvey:', isSurvey);
    //console.log('addGeoCodeButton:', addGeoCodeButton);

    /*
        Add listeners & UI elements as indicated in the EM config.
        Note that the geocode button is not rendered on survey pages.
    */

    if ( addGeoCodeButton && latitudeField && longitudeField && !$('#census-geocode-location-button').length && !isSurvey ) {

        injectGeocodeLocationUIElements(); // a button to trigger geocoding based on the lat/long field values
    }

    if ( addGeoCodeButton && addressField && !$('#census-geocode-address-button').length && !isSurvey ) {

        injectGeocodeAddressUIElements(); // a button to trigger geocoding based on the address field
    }

	if (addressField && !addGeoCodeButton) {

		$(`[name="${addressField}"]`).change(function() {

            processAllCensuses(false, geocodeAPI.addressLookup);
		});
	}

	if (latitudeField && longitudeField && !addGeoCodeButton) {

		$(`[name="${latitudeField}"], [name="${longitudeField}"]`).change(function() {
            
			processAllCensuses(false, geocodeAPI.locationLookup);
		});
	}

    /*
        GEOCODING & DATA PROCESSING FUNCTIONS
    */

    function injectBVDropdown( $parentElement ) {

        if (!addBVDropdown) {
            return;
        }

        const $bvSelect = $('<select>', {
            id: 'census-bv-dropdown',
            class: 'form-control',
            style: 'max-width: 300px; font-size: 0.9em;',
            title: 'Select a benchmark/vintage to filter the geocoding results by census benchmark/vintage. This dropdown is populated based on the benchmark/vintage combinations specified in the EM config for the censuses being processed, with selected options corresponding to the benchmark/vintage combinations of the censuses currently selected for processing. Changing the selection will trigger re-processing of the censuses to update the geocoding results based on the new selection.'
        });

        $bvSelectWrapper = $(`
            <div id="census-bv-dropdown-container" style="width: 100%; display: flex; align-items: center;">
            </div>
        `);

        $bvSelectWrapper.append($bvSelect);
        $parentElement.append($bvSelectWrapper);

        updateBVSelectOptions();

        $bvSelect.on('change', function() {

            const selectedBV = $(this).val();

            // add to the list of selected benchmarks
            for (const bv of censusBenchmarks) {
                if (bv.value === selectedBV) {
                    bv.selected = true;
                    break;
                }
            }

            // add a census object with all configured mappings
            //addCensusItem(selectedBV);

            //updateBVSelectOptions(); // update the dropdown options to reflect the new selection and disable the selected benchmark/vintage
        });

        return $bvSelectWrapper;
    }

    function processUserSelectedBV() {

        const selectedBV = $('#census-bv-dropdown').val() || null;

        if ( !selectedBV ) {
            return;
        }

        // add to the list of selected benchmarks - required for updateBVSelectOptions()
        for (const bv of censusBenchmarks) {
            if (bv.value === selectedBV) {
                bv.selected = true;
                break;
            }
        }

        updateBVSelectOptions(); // update the dropdown options to reflect the new selection and disable the selected benchmark/vintage

        addCensusItem(selectedBV); // add to the list of censuses to process, with all configured mappings
    }

    function updateBVSelectOptions() {

        const $bvSelect = $('#census-bv-dropdown');

        if (!$bvSelect.length) {
            return;
        }
    
        const $defaultOption = $('<option>', {
            value: '',
            text: '-- select benchmark/vintage --'
        });

        const $processedOptgroup = $('<optgroup>', { label: 'Selected Census Benchmark(s)' });
        const $unprocessedOptgroup = $('<optgroup>', { label: 'Additional Census Benchmarks' });

        $processedOptgroup.empty();
        $unprocessedOptgroup.empty();

        for (const bv of censusBenchmarks) {

            const $option = $(`<option value="${bv.value}">${bv.name}</option>`);

            if (bv.selected) {
                $processedOptgroup.append($option).attr('disabled', true);
            }
            else {
                $unprocessedOptgroup.append($option);
            }
        }

        console.log('$processedOptgroup:', $processedOptgroup);
        console.log('$unprocessedOptgroup:', $unprocessedOptgroup);

        $bvSelect.empty().append($defaultOption, $processedOptgroup, $unprocessedOptgroup);
    }

    function getGeocoderContainer($anchorField) {

        if ( $anchorField.closest('td').find('.census-geocoder-container').length ) {
            return;
        }

        const $container = $('<div>', {
            class: 'census-geocoder-container',
            style: 'width: 100%; display: flex; flex-direction: column; align-items: flex-start; row-gap: 3px; padding: 3px; margin-top: 3px; border: 1px solid #ccc; border-radius: 6px;'   
        });

        $anchorField.closest('td').append($container);

        return $container;
    }

    /**
     * Injects a bootstrap-styled Geocode Button below the address input element
     * 
     * @returns 
     */
    function injectGeocodeAddressUIElements() {

        if (!addGeoCodeButton) {
            return;
        }

        //console.log('injectGeocodeAddressUIElements: addressField:', addressField);

        const $buttonAnchorField = $(`[name="${addressField}"]`);

        if ( !$buttonAnchorField.length ) {

            return;
        }

        const $geoCodeButton = $(`
            <div id="census-geocode-button-container" style="width: 100%; display: flex; align-items: center;">
                <button type="button" 
                    id="census-geocode-address-button" 
                    class="btn btn-secondary" 
                    style="margin-left: 0; font-size: 0.9em;"
                    title="Click to geocode the address and populate the corresponding fields with Census data">
                    <i class="fas fa-map-marker-alt"></i> Geocode Address
                </button>
            </div>
        `);

        const $parentElement = getGeocoderContainer($buttonAnchorField);

        if ( addBVDropdown ) {

            injectBVDropdown($parentElement);
        }

        $parentElement.append($geoCodeButton);

        $geoCodeButton.on('click', function() {

            const $addressField = $(`[name="${addressField}"]`);  
            const addressValue = $addressField.val();

            $('.geocode-input-missing').removeClass('geocode-input-missing'); // remove the red border from any previously flagged missing input

            if ( !addressValue || addressValue.trim().length === 0 ) {

                $addressField.addClass('geocode-input-missing');

                //alert('Please enter an address before clicking the geocode button.');
                return;
            }

            processAllCensuses(true, geocodeAPI.addressLookup);
        });
    }

    function injectGeocodeLocationUIElements() {

        if (!addGeoCodeButton) {
            return;
        }

        const $latField = $(`[name="${latitudeField}"]`);
        const $longField = $(`[name="${longitudeField}"]`);

        if ( !$latField.length || !$longField.length ) {

            return;
        }

        const $geoCodeButton = $(`
            <div id="census-geocode-button-container" style="width: 100%; display: flex; align-items: center;">
                <button type="button" 
                    id="census-geocode-location-button" 
                    class="btn btn-secondary" 
                    style="margin-left: 0; font-size: 0.9em;"
                    title="Click to geocode the location and populate the corresponding fields with Census data">
                    <i class="fas fa-map-marker-alt"></i> Geocode Location
                </button>
            </div>
        `);

        // the location geocode button is determined by the DOM order of the lat and long fields
        const $buttonAnchorField = compareDomOrder($latField[0], $longField[0]) < 0 ? $longField : $latField; 

        const $parentElement = getGeocoderContainer($buttonAnchorField);

        $parentElement.append($geoCodeButton);

        $geoCodeButton.on('click', function() {

            const latValue = $latField.val();
            const longValue = $longField.val();
            let hasMissingInput = false;
            
            $('.geocode-input-missing').removeClass('geocode-input-missing'); // remove the red border from any previously flagged missing input

            if ( !latValue || latValue.trim().length === 0 ) {

                $latField.addClass('geocode-input-missing');
                hasMissingInput = true;
            }

            if ( !longValue || longValue.trim().length === 0 ) {

                $longField.addClass('geocode-input-missing');
                hasMissingInput = true;
            }

            if ( hasMissingInput ) {

                //alert('Please enter both latitude and longitude before clicking the geocode button.');
                return;
            }

            processAllCensuses(true, geocodeAPI.locationLookup);
        });
    }

    /**
     * compares the DOM order of two nodes a and b, returning:
     *  -1 if a comes before b
     *  1 if a comes after b
     *  0 if they are the same node or in different trees (i.e. disconnected)
     * 
     * @param {Node} a 
     * @param {Node} b 
     * @returns {number}
     */
    function compareDomOrder(a, b) {

        const pos = a.compareDocumentPosition(b);

        if (pos & Node.DOCUMENT_POSITION_FOLLOWING) return -1;
        if (pos & Node.DOCUMENT_POSITION_PRECEDING) return 1;
        return 0; // same node or disconnected
    }

    /*
        COMPREHENSIVE BENCHMARK-VINTAGE LIST - all valid benchmark-vintage combinations,
        with each item in the list containing the benchmark-vintage,
        and whether it's in the list of censuses to be processed (i.e. whether it's selected or not, which could be used to filter the dropdown if the addBVDropdown option is enabled in the EM config).
    */

    function newBenchmarkVintageList() {

        if (!addBVDropdown || !module.tt('censusBenchmarks') || module.tt('censusBenchmarks').length === 0) {
            return [];
        }

        const bvCount = module.tt('censusBenchmarks').length;
        const bvList = [];

        for (let i=0; i < bvCount; i++) {

            const bv = module.tt('censusBenchmarks')[i];

            // Whether bv is attached to any census config for the EM.
            // User-selected b-v combos will have selected=true, configured=false.
            const bvConfigured = censuses.some(c => c.benchmark_vintage === bv.value);

            bvList.push({
                name: bv.name,
                value: bv.value,
                configured: bvConfigured,
                selected: bvConfigured
            });
        }

        return bvList;
    }
    
    /*
        GEOCODE DATA OBJECT - this is the object that will hold all geocoded data extracted from the API responses, 
        with keys corresponding to the REDCap field names mapped to census data across the censuses processed, 
        and values that are updated as data are extracted and processed. 
        This object is initialized with empty string values for all mapped fields, 
        and then updated with retrieved data during processing, 
        and finally used to update the UI after all processing is complete.
    */

    /**
     * Returns an array with keys corresponding to all unique REDCap field names mapped to census data across the censuses processed.
     * Called once to initialize the global geocodeData object.
     * 
     * @returns {Object} Object with keys as unique field names and values as empty strings
     */
    function newGeocodeDataRecord() {

        // store the names in a set to ensure uniqueness, since the same field might be mapped to data from multiple censuses
        const geocodeFields = new Set();

        // optional fields for reporting the geocoding result and match result summary
        if ( geocodeReportField ) { geocodeFields.add(geocodeReportField); }
        if ( geocodeMatchResultField ) { geocodeFields.add(geocodeMatchResultField); }

        // collect the field names mapped to census data across the censuses processed
        for (const census of censuses) {
            for (const mapping of census.mappings) {
                geocodeFields.add(mapping.fields);
            }
        }

        // build an object out of the set of mapped fields, with each field name as a key and an empty string as the initial value
        const geocodeDataObject = Object.fromEntries([...geocodeFields].map(field => [field, '']));

        //console.log('Mapped REDCap fields across censuses:', geocodeDataObject);

        return geocodeDataObject;
    }

    /**
     * 
     * A consolidated mapping of all census field keys to REDCap field names across the censuses processed.
     * Currently used only for user-added census benchmarks (i.e. when the addBVDropdown option is enabled in the EM config).
     * 
     * @returns {Array} Array of objects mapping census field keys to REDCap field names
     * 
     */
    function newGeocodeFieldMappings() {

        const geocodeFieldMappings = [];

        // collect the field names mapped to census data across the censuses processed
        for (const census of censuses) {
            for (const mapping of census.mappings) {
                const geocodeFieldMapping = {
                        tigerWebAttribKey: mapping.keys,
                        redcapFieldName: mapping.fields
                };

                // add if not already in the consolidated mapping
                if ( !geocodeFieldMappings.some(m => m.tigerWebAttribKey === geocodeFieldMapping.tigerWebAttribKey 
                    && m.redcapFieldName === geocodeFieldMapping.redcapFieldName) ) {
                    geocodeFieldMappings.push(geocodeFieldMapping);
                }
            }
        }
        return geocodeFieldMappings;
    }
    
    /**
     * Clears the global geocodeData object by setting all values to empty strings.
     */
    function clearGeocodeDataRecord() {

        for (const fieldName of Object.keys(geocodeData)) {
            geocodeData[fieldName] = '';
        }
    }

    // updates the REDCap UI by setting the values of the mapped fields, as recorded in the global geocodeData object, to empty strings
    function setMappedREDCapFieldsToEmpty() {

        for (const fieldName of Object.keys(geocodeData)) {
            const $field = $(`[name="${fieldName}"]`);
            if ($field.length) {
                $field.val('');
                $field.change();
            }
        }
    }

    /*
       GEOCODE REPORT OBJECT - this object is built to capture details about the geocoding process and result, 
       including the match result summary, details for each census processed, and the list of all matched addresses across the censuses processed.
       Always output to the console after all censuses are processed, 
       and optionally output to the UI if a geocodeReportField is specified in the EM config.
    */

    // returns a blank geocode report object, called once to initialize the global geocodeReport object
    function newGeocodeReportObject() {

        return {
            matchResult: '', // summary of the geocoding result - either 'not matched', 'matched', or 'multiple matches'
            censuses: [], // list of census objects (with benchmark/vintage, matchedAddress, count of geographies) that were processed
            allMatchedAddresses: new Set(), // all addresses matched by TigerWeb
            matchResultCode: '', // code indicating the match result for the geocoded address, e.g. 'Exact Match', 'No Match', etc. - this is determined based on the match result code(s) returned from TigerWeb for the geocoded address(es) across the censuses processed
            reportText: '' // full text of the geocode report to be injected into the UI or reported to the console
        };
    }

    // clears the global geocode report object
    function clearGeocodeReportObject() {

        geocodeReport.matchResult = ''; // either 'not matched', 'matched', or 'multiple matches' - this is determined based on whether a geocoded address was returned and whether multiple matched addresses were found across the censuses processed
        geocodeReport.censusSummaries = []; // for each census processed, we will store the benchmark/vintage, matched address, and count of geographies updated in the UI
        geocodeReport.allMatchedAddresses.clear(); // we will accumulate all matched addresses across all censuses processed, since only the first matched address in each census is used for geocoding
        geocodeReport.reportText = ''; // this will be the full text of the geocode report, which includes the match result summary, details for each census processed, and the list of all matched addresses - this is what gets injected into the UI or reported to the console
        geocodeReport.matchResultCode = ''; // reset the match result code as well
    }

    // called after the API response is received 
    // and before processing the census data, 
    // to update the set of all matched addresses 
    // with the matched address collection from TigerWeb
    function setGeocodeReportAllMatchedAddresses(addressMatches=[]) {
        for (const match of addressMatches) {
            geocodeReport.allMatchedAddresses.add(match.matchedAddress);
        }
    }

    // called for each census, before the UI is updated
    function updateGeocodeReportObject(census, geocodeUpdates = [], api = geocodeAPI.addressLookup) {

        geocodeReport.censusSummaries.push({
            benchmarkVintage: census.benchmark_vintage,
            geocodedAddress: census.geocodedAddress,
            geocodedLocation: census.geocodedLocation,
            geocodesUpdated: geocodeUpdates.length
        });


        let reportLines = [];
        let timestamp = new Date().toLocaleString();
        let anyGeocodesUpdated = geocodeReport.censusSummaries.some(c => c.geocodesUpdated > 0);

        reportLines.push(`Geocode Report on ${timestamp}`);
        
        if (api === geocodeAPI.addressLookup) {

            // indicates whether multiple matches were found within or across the censuses processed
            geocodeReport.matchResult = geocodeReport.allMatchedAddresses && geocodeReport.allMatchedAddresses.size > 0 ? geocodeReport.allMatchedAddresses.size > 1 ? 'multiple matches' : 'matched' : 'not matched';
            reportLines.push(`Geocoding Method: Single Address Lookup API`);
            reportLines.push(`Address Match Result: ${geocodeReport.matchResult}`);
        }
        else if (api === geocodeAPI.locationLookup) {

            geocodeReport.matchResult = anyGeocodesUpdated ? 'matched' : 'not matched';
            reportLines.push(`Geocoding Method: Single Location Lookup API`);
            reportLines.push(`Location Match Result: ${geocodeReport.matchResult}`);
        }

        if (geocodeReport.censusSummaries.length === 0) {

            reportLines.push('\nNo geocodes were updated.');
        }
        else {

            reportLines.push(`\nResults for ${geocodeReport.censusSummaries.length} Census specification(s):`);

            // iterate through the censuses and include benchmark/vintage, geocoded address, and geocodes updated for each census processed
            for (const census of geocodeReport.censusSummaries) {
                reportLines.push(`\nBenchmark-Vintage: ${census.benchmarkVintage}`);
                if (api===geocodeAPI.addressLookup) {
                    reportLines.push(`Geocoded Address: ${census.geocodedAddress}`);
                }
                else if (api===geocodeAPI.locationLookup) {
                    //console.log('updateGeocodeReportObject: census:', census);
                    //console.log('updateGeocodeReportObject: location:', census.geocodedLocation);
                    reportLines.push(`Geocoded Location: latitude ${census.geocodedLocation['latitude']}, longitude ${census.geocodedLocation['longitude']}`);
                }
                reportLines.push(`Geocodes Updated: ${census.geocodesUpdated}`);
                for (const update of geocodeUpdates) {
                    reportLines.push(`  - ${update.redcapFieldName} => ${update.value} from TigerWeb attribute ${update.tigerWebAttribKey}`);
                }
            }
        }

        if ( geocodeReport.allMatchedAddresses && geocodeReport.allMatchedAddresses.size > 0 ) {
            
            // all addresses matched by TigerWeb across all censuses processed
            reportLines.push(`\nAll Matched Addresses:\n${[...geocodeReport.allMatchedAddresses].join('\n')}`);
        }

        geocodeReport.reportText = reportLines.join('\n');

        // stash it in the geocodeData object so it's available for transfer to the UI when transferGeocodeDataToREDCapForm is called at the end of processing all censuses
        geocodeData[geocodeReportField] = geocodeReport.reportText;

        // match result
        geocodeData[geocodeMatchResultField] = geocodeReport.matchResult;
    }

    function addCensusItem(benchmark_vintage) {
        
        const censusItem = {
            benchmark_vintage: benchmark_vintage,
            mappings: [],   // this will be populated with the consolidated field mappings across all configured censuses
        }

        // add the consolidated field mappings for all configured censuses
        for (const mapping of geocodeFieldMappings) {
            censusItem.mappings.push({
                keys: mapping.tigerWebAttribKey,
                fields: mapping.redcapFieldName
            });
        }

        censuses.push(censusItem);
    }

    /**
     * Process all censuses sequentially.
     * Optionally displays a spinner overlay while processing.
     * 
     * @param {boolean} overlay    indicates whether to show a spinner overlay while processing - defaults to false
     * @param {boolean} fromClick  indicates whether this function was triggered by a click on the geocode button
     * @param {number} api         indicates which API to use for geocoding - defaults to 1 (the address lookup API), but can be set to 2 to use the lat/long lookup API instead
     * 
     * @returns {Promise<void>}  Resolves when all censuses have been processed and the UI has been updated with the retrieved data and geocode report.
     */
    async function processAllCensuses(fromClick = false, api = geocodeAPI.addressLookup) {

        const $geoButton = api === geocodeAPI.addressLookup ? $('#census-geocode-address-button') : $('#census-geocode-location-button');
        
        if ( !$geoButton.length ) { fromClick = false; }

        // status icons to indicate the state and result of the geocoding process after clicking a geocode button
        const $spinner = $(`<i class="fas fa-spinner fa-spin geo-button-status" id="census-geocode-spinner" style="margin-left: 10px; font-size: 1.5em;" title="Please wait, processing..."></i>`);
        const $successIcon = $(`<i class="fas fa-check geo-button-status" style="color: green; margin-left: 10px; font-size: 1.5em;" title="Success: data extracted and processed."></i>`);
        const $warningIcon = $(`<i class="fas fa-exclamation-triangle geo-button-status" style="color: orange; margin-left: 10px; font-size: 1.5em;" title="Warning: no data were retrieved."></i>`);
        //const $errorIcon = $(`<i class="fas fa-exclamation-triangle geo-button-status" style="color: red; margin-left: 10px; font-size: 1.5em;" title="Error: see console."></i>`);
        const $errorIcon = $(`<i class="fas fa-skull-crossbones geo-button-status" style="color: red; margin-left: 10px; font-size: 1.5em;" title="API error! see console."></i>`);

        if (fromClick) {

            $('.geo-button-status').remove(); // remove any existing status icons

            // disable the button and add a spinner while processing
            $geoButton
                .prop('disabled', true)
                .after($spinner)
            ;

            processUserSelectedBV(); // if the user has selected a benchmark/vintage from the dropdown, add it to the list of censuses to process
        }

        // clear objects that will be populated with new data during this process
        clearGeocodeReportObject(); // clear the report object values before processing
        clearGeocodeDataRecord();  // reset the geocodeData object values to empty strings

        let dataRetrieved = false; // true if any geographies are retrieved from any of the censuses processed
        let resolved = false; // true if promise resolves successfully
        let apiError = false; // true if any census download results in an API error

        if (!fromClick) {
            $.LoadingOverlay('show');
        }

        try {
            for (const census of censuses) {

                const gotData = await downloadCensusData(census, api);
                
                resolved = true;
                
                if  ( gotData ) {

                    dataRetrieved = true;
                    // a stopping rule might go here eventually
                }
                else {
                    console.warn(`No data were retrieved for census with benchmark/vintage ${census.benchmark_vintage}`);
                }
            }
        }
        catch (e) {

            console.error('Error processing censuses:', e);
            apiError = true;
        } 
        finally {

            if (!fromClick) {
                $.LoadingOverlay('hide');
            }
            else {

                $spinner.remove(); // remove the spinner that was added when the button was clicked

                // display the appropriate status icon: success, warning (no data retrieved), or error (API error)
                if (apiError) { $errorIcon.insertAfter($geoButton); }
                else if (dataRetrieved) { $successIcon.insertAfter($geoButton); }
                else { $warningIcon.insertAfter($geoButton); }

                $geoButton.prop('disabled', false); // re-enable the button after processing
            }

            if ( resolved ) {

                transferGeocodeDataToREDCapForm();

                // log the geocode report object to the console for debugging
                //console.log('Geocoder: Final report:', geocodeReport);
                //console.log('Geocoder: Data updates:', geocodeData);
            }
        }
    }

    /**
     * Process a single census:
     * (1) retrieves geocode data from the Census API based on the address field value and the census benchmark/vintage, 
     * (2) updates the census object with the geocoded address and lookup table (geographies) returned from the API, and then
     * (3) calls processCensusData to update the UI fields and geocode report object with the retrieved data.
     * 
     * Returns a promise that resolves to true if geographies were retrieved for the census and false otherwise.
     * The promise will reject if there is an error during the API request or if the response cannot be parsed as JSON.
     * 
     * @param {*} census 
     * @param {typeof geocodeAPI[keyof typeof geocodeAPI]} api    indicates which API to use for geocoding - defaults to geocodeAPI.addressLookup, but can be set to geocodeAPI.coordinatesLookup to use the lat/long lookup API instead
     * @returns {Promise<boolean>} Resolves to true if geographies were retrieved, false otherwise.
     */
    function downloadCensusData(census, api = geocodeAPI.addressLookup) {

        //console.log('downloadCensusData called with census, api:', census, api);

        const data = {
            get: 1,
            year: census.year,
            benchmark_vintage: census.benchmark_vintage,
            redcap_csrf_token: redcap_csrf_token
        }

        let url;

        if (api === geocodeAPI.addressLookup) {

            url = getAddressUrl;

            data.address = $(`[name="${addressField}"]`).val().replace(/United States/g, '').trim();
            if (!data.address) return Promise.resolve(false);
        }
        else if (api === geocodeAPI.locationLookup) {

            url = getCoordinatesUrl;

            const latRaw = $(`[name="${latitudeField}"]`).val().trim();
            const longRaw = $(`[name="${longitudeField}"]`).val().trim();

            if (latRaw.length === 0 || longRaw.length === 0) return Promise.resolve(false);

            data.lat = Number(latRaw);
            data.long = Number(longRaw);

            if (isNaN(data.lat) || isNaN(data.long)) return Promise.resolve(false);
        }
        else {

            return Promise.reject(new Error(`Invalid API specified: ${api}`));
        }

        // initialize census properties that might be set by the API response
        census.geocodedAddress = ''; 
        census.lookupTable = null;   

        return new Promise((resolve, reject) => {
            $.post(url, data)
            .done((json) => {

                let data;
                try {
                    data = JSON.parse(json);
                } catch (e) {
                    // Bad JSON response
                    return reject(e);
                }

                if (api === geocodeAPI.addressLookup) {

                    const addressMatches = data?.result?.addressMatches;

                    if (addressMatches && addressMatches.length > 0) {

                        // the first matched address returned from the API is used for geocoding and reporting in the geocode report object
                        census.geocodedAddress = addressMatches?.[0]?.matchedAddress;
                        census.lookupTable = addressMatches?.[0]?.geographies;

                        // all matched addresses are saved to the geocode report object
                        // to aid in resolving ambiguous matches
                        setGeocodeReportAllMatchedAddresses(addressMatches);
                    }
                }
                else if (api === geocodeAPI.locationLookup) {

                    census.lookupTable = data?.result?.geographies;

                    census.geocodedLocation = {
                        latitude: data?.result?.input?.location?.y,
                        longitude: data?.result?.input?.location?.x
                    };

                    //console.log('downloadCensusData: location lookup response data:', data);
                }

                if (census.lookupTable) {

                    // extract data from the API geographies response for this census
                    // extracted data are saved to the geocodeData object, and to the UI if not using the geocode button
                    extractCensusData(census, api);

                    // resolve and indicate that data were retrieved and processed for this census
                    return resolve(true);
                }

                // update the geocode report object to reflect that no data were retrieved for this census - we want to capture this in the report even if it's not treated as an error per se, since it is still important information about the geocoding process and result for this census
                updateGeocodeReportObject(census, [], api); // pass 0 geocodes updated since no data were retrieved

                // resolve and indicate that no data were retrieved for this census
                resolve(false);
            })
            .fail((xhr, status, err) => {

                // reject with the error object if available, otherwise create a new error with the status text
                reject(err || new Error(status));
            });
        });
    }

    /**
     * Transfers the data stored in the geocodeData object to the corresponding fields in the REDCap form.
     * Called after processing all censuses to update the UI with the retrieved data and geocode report.
     */
    function transferGeocodeDataToREDCapForm() {

        setMappedREDCapFieldsToEmpty(); // clear the UI, no stale data allowed

        //console.log('Transferring the following mapped fields to the REDCap form:', geocodeData);

        for (const [fieldName, value] of Object.entries(geocodeData)) {

            const $field = $(`[name="${fieldName}"]`);
            if ($field.length && value) {

                $field.val(value).change();

                if ($field.hasClass('rc-autocomplete')) {
                    const $autocompleteField = $field.closest('td').find('.ui-autocomplete-input')
                    $autocompleteField.val($field.find('option:selected').text()).change();
                }
            }
        }
    }

	/**
	 * Returns keys in ascending order of the specified "order_by" key
	 * Intended to be run on a list of geographies returned from the Census API
	 * @param {Object} arr - input object, expected to be a an object containing multiple elements each of which must contain the "order_by" key
	 * @param {string} order_by - the key used to sort, must contain a numeric value
	 * @returns {array}
	 * */
	function sortKeysByValue(arr, order_by = 'AREALAND') {
		let sorted_keys = Object.keys(arr).sort(function(a, b) {
			return arr[a][0][order_by] - arr[b][0][order_by];
		})

		return sorted_keys;
	}

    /**
     * Extracts geocode data and updates the geocodeData object with data.
     * 
     * Called by downloadCensusData() for each census after retrieving data from the Census API.
     * 
     * Note: this function is a refactoring of processCensusData() 
     * 
     * @param {*} census 
     */
	function extractCensusData(census, api = geocodeAPI.addressLookup) {
		const sortedGeographyLayers = sortKeysByValue(census.lookupTable); // layer names sorted by land area, a proxy for layer hierarchy

        let geocodesUpdated = 0;

        const geocodeUpdates = []; // key-value pairs

        //console.log('extractCensusData: census:', census);
        //console.log('extractCensusData: sortedGeographyLayers:', sortedGeographyLayers);

		for (const mapping of census.mappings) {
			let value = '';

			for (const layer of sortedGeographyLayers) {
				let potential_val = census.lookupTable[layer][0][mapping.keys];
				if (potential_val) {
					value = potential_val;
					break;
				}
			}

            if (value) {

                geocodesUpdated++;

                const fieldName = mapping.fields

                // Save the extracted value to the global geocodeData object for
                //  (1) later reporting, and
                //  (2) for updating the UI after all censuses are processed.
                geocodeData[fieldName] = value; 

                geocodeUpdates.push({ tigerWebAttribKey: mapping.keys, redcapFieldName: fieldName, value: value });
            }
		}

        // update the geocode report object with the benchmark/vintage, matched address, and count of geocodes updated for this census
        updateGeocodeReportObject(census, geocodeUpdates, api);
	}

});



$(document).ready(() => {
	console.log('Census Geocoder loaded');

	const module = ExternalModules.Vanderbilt.CensusExternalModule;

	const censuses = module.tt('censuses');

	const fields         = module.tt('fields');
	const addressField   = fields['addressField'];
	const latitudeField  = fields['latitudeField'];
	const longitudeField = fields['longitudeField'];

    const addGeoCodeButton = fields['addGeoCodeButton'];
    const geocodeReportField = fields['geocodeReportField'];

	const urls              = module.tt('urls');
	const getAddressUrl     = urls['getAddressUrl'];
	const getCoordinatesUrl = urls['getCoordinatesUrl'];

    const mappedFields = getMappedFieldsObject();

    const geocodeReport = getGeocodeReportObject();

    console.log('censuses:', censuses);
    console.log('fields:', fields);
    console.log('urls:', urls);
    console.log('mappedFields:', mappedFields);

    function getGeocodeReportObject() {

        return {
            matchResult: '', // summary of the geocoding result - either 'not matched', 'matched', or 'multiple matches'
            censuses: [], // list of census objects (with benchmark/vintage, matchedAddress, count of geographies) that were processed
            allMatchedAddresses: new Set(), // all addresses matched by TigerWeb
            reportText: '' // full text of the geocode report to be injected into the UI or reported to the console
        };
    }

    function clearGeocodeReportObject() {

        geocodeReport.matchResult = ''; // eithe 'not matched', 'matched', or 'multiple matches' - this is determined based on whether a geocoded address was returned and whether multiple matched addresses were found across the censuses processed
        geocodeReport.censuses = []; // for each census processed, we will store the benchmark/vintage, matched address, and count of geographies updated in the UI
        geocodeReport.allMatchedAddresses.clear(); // we will accumulate all matched addresses across all censuses processed, since only the first matched address in each census is used for geocoding
        geocodeReport.reportText = ''; // this will be the full text of the geocode report, which includes the match result summary, details for each census processed, and the list of all matched addresses - this is what gets injected into the UI or reported to the console
    }

    /**
     * The set of REDCap field names mapped to census data across the censuses processed.
     * 
     * @returns {Set} Set of unique field names mapped to be updated with census data across the censuses processed
     */
    function getMappedFieldsObject() {

        let mappedFields = new Set();

        if ( geocodeReportField ) {
            mappedFields.add(geocodeReportField);
        }

        for (const census of censuses) {
            for (const mapping of census.mappings) {
                mappedFields.add(mapping.fields);
            }
        }

        // build an object out of the set for easier use later
        const mappedFieldsObject = Object.fromEntries([...mappedFields].map(field => [field, '']));

        console.log('Mapped REDCap fields across censuses:', mappedFieldsObject);

        return mappedFieldsObject;
    }
    
    function clearMappedFieldsObject() {

        for (const fieldName of Object.keys(mappedFields)) {
            mappedFields[fieldName] = '';
        }
    }

    /**
     * Injects a bootstrap-styled Geocode Button below the address input element
     * 
     * @returns 
     */
    function injectGeobutton() {

        if (!addGeoCodeButton) {
            return;
        }

        const $addressField = $(`[name="${addressField}"]`);

        if ($addressField.length === 0) {
            return;
        }

        const $geoCodeButton = $(`
            <div id="census-geocode-button-container" style="width: 100%; display: flex; align-items: center;">
                <button type="button" 
                    id="census-geocode-button" 
                    class="btn btn-secondary" 
                    style="margin-left: 0; margin-top: 5px; font-size: 0.9em;"
                    title="Click to geocode the address and populate the corresponding fields with Census data">
                    <i class="fas fa-map-marker-alt"></i> Geocode Address
                </button>
            </div>
        `);

        $addressField.parent().after($geoCodeButton);

        $geoCodeButton.on('click', function() {

            processAllCensuses(true);
        });
    }

    function setMappedREDCapFieldsToEmpty() {

        for (const fieldName of Object.keys(mappedFields)) {
            const $field = $(`[name="${fieldName}"]`);
            if ($field.length) {
                $field.val('');
                $field.change();
            }
        }
    }

    /*
       GEOCODE REPORT OBJECT
    */

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
    function updateGeocodeReportObject(census, geocodesUpdated = 0) {

        geocodeReport.censuses.push({
            benchmarkVintage: census.benchmark_vintage,
            geocodedAddress: census.geocodedAddress,
            geocodesUpdated: geocodesUpdated
        });

        // indicates whether multiple matches were found within or across the censuses processed
        geocodeReport.matchResult = geocodeReport.allMatchedAddresses && geocodeReport.allMatchedAddresses.size > 0 ? geocodeReport.allMatchedAddresses.size > 1 ? 'multiple matches' : 'single match' : 'not matched';

        let reportLines = [];
        let timestamp = new Date().toLocaleString();

        reportLines.push(`Geocode Report on ${timestamp}`);
        
        reportLines.push(`\nAddress Match Result: ${geocodeReport.matchResult}`);

        if (geocodeReport.censuses.length === 0) {

            reportLines.push('\nNo geocodes were updated.');
        }

        else {

            reportLines.push(`\nResults for ${geocodeReport.censuses.length} Census specification(s):`);

            // iterate through the censuses and include benchmark/vintage, geocoded address, and geocodes updated for each census processed
            for (const census of geocodeReport.censuses) {
                //reportLines.push(`\nCensus Benchmark/Vintage:\n${census.benchmarkVintage}`);
                //reportLines.push(`Geocoded Address:\n${census.geocodedAddress}`);
                reportLines.push(`\n${census.benchmarkVintage}`);
                reportLines.push(census.geocodedAddress);
                reportLines.push(`Geocodes Updated: ${census.geocodesUpdated}`);
            }
        }

        if ( geocodeReport.allMatchedAddresses && geocodeReport.allMatchedAddresses.size > 0 ) {
            
            // all addresses matched by TigerWeb across all censuses processed
            reportLines.push(`\nAll Matched Addresses:\n${[...geocodeReport.allMatchedAddresses].join('\n')}`);
        }

        geocodeReport.reportText = reportLines.join('\n');

        // stash it in the mappedFields object so it's available for transfer to the UI when transferMappedDataToREDCapForm is called at the end of processing all censuses
        mappedFields[geocodeReportField] = geocodeReport.reportText;
    }

    // updates the geocode report field in the UI with the current geocode report text - called after processing each census to update the report with the latest cumulative results
    function updateGeocodeReportField() {

        if (!geocodeReportField) {
            return;
        }

        const $geocodeReportField = $(`[name="${geocodeReportField}"]`);

        if ($geocodeReportField.length === 0) {
            return;
        }
        
        $geocodeReportField.val(geocodeReport.reportText).change();
    }

    /**
     * Process all censuses sequentially.
     * Optionally displays a spinner overlay while processing.
     * 
     * @param {boolean} overlay    indicates whether to show a spinner overlay while processing - defaults to false
     * @param {boolean} fromClick  indicates whether this function was triggered by a click on the geocode button
     */
    async function processAllCensuses(fromClick = false) {

        const $geoButton = $('#census-geocode-button');

        // status icons to indicate the state and result of the geocoding process after clicking the geocode button
        const $spinner = $(`<i class="fas fa-spinner fa-spin geo-button-status" id="census-geocode-spinner" style="margin-left: 10px; font-size: 1.5em;" title="Please wait, processing..."></i>`);
        const $greenCheck = $(`<i class="fas fa-check geo-button-status" style="color: green; margin-left: 10px; font-size: 1.5em;" title="Success: data extracted and processed."></i>`);
        const $warningIcon = $(`<i class="fas fa-exclamation-triangle geo-button-status" style="color: orange; margin-left: 10px; font-size: 1.5em;" title="Warning: no data were retrieved."></i>`);
        const $errorIcon = $(`<i class="fas fa-exclamation-triangle geo-button-status" style="color: red; margin-left: 10px; font-size: 1.5em;" title="Error: see console."></i>`);
        
        if ( !$geoButton.length ) { fromClick = false; }

        const overlay = !fromClick; // revert to legacy behavior if no button

        if (fromClick) {

            $('.geo-button-status').remove(); // remove any existing status icons

            // disable the button and show the spinner while processing
            $geoButton
                .prop('disabled', true)
                .after($spinner)
            ;
        }

        // clear objects that will be populated with new data during this process
        clearGeocodeReportObject(); // clear the report object values before processing
        clearMappedFieldsObject();  // reset the mappedFields object values to empty strings

        let dataRetrieved = false; // true if any geographies are retrieved from any of the censuses processed
        let resolved = false; // true if promise resolves successfully
        let apiError = false;      // true if any census download results in an API error

        if (overlay) {
            $.LoadingOverlay('show');
        }

        try {
            for (const census of censuses) {

                const gotData = await downloadCensusData(census);
                
                resolved = true;
                
                if  ( gotData ) {

                    dataRetrieved = true;
                    // a stopping rule might go here eventually
                    // break;
                }
                else {
                    console.warn(`No data was retrieved for census with benchmark/vintage ${census.benchmark_vintage}`);
                }
            }
        }
        catch (e) {

            console.error('Error processing censuses:', e);
            apiError = true;
        } 
        finally {

            if (overlay) {
                $.LoadingOverlay('hide');
            }

            if (fromClick) {

                $spinner.remove();

                // display the appropriate status icon: success, warning (no data retrieved), or error (API error)
                if (apiError) { $errorIcon.insertAfter($geoButton); }
                else if (dataRetrieved) { $greenCheck.insertAfter($geoButton); }
                else { $warningIcon.insertAfter($geoButton); }

                $geoButton.prop('disabled', false);
            }

            if ( resolved ) {

                transferMappedDataToREDCapForm(); // for debugging - logs the mapped fields and their values to the console
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
     * @returns {Promise<boolean>} Resolves to true if geographies were retrieved, false otherwise.
     */
    function downloadCensusData(census) {
        console.log('downloadCensusData called with census:', census);

        const address = $(`[name="${addressField}"]`).val();
        if (!address) return Promise.resolve(false);

        const encodedAddress = address.replace(/United States/g, '');
        console.log(`Looking up ${encodedAddress}`);

        // initialize census properties set by the API response
        census.geocodedAddress = ''; 
        census.lookupTable = null;   

        return new Promise((resolve, reject) => {
            $.post(getAddressUrl, {
                get: 1,
                address: encodedAddress,
                year: census.year,
                benchmark_vintage: census.benchmark_vintage,
                redcap_csrf_token
            })
            .done((json) => {

                let data;
                try {
                    data = JSON.parse(json);
                } catch (e) {
                    // Bad JSON response
                    return reject(e);
                }

                const addressMatches = data?.result?.addressMatches;
                const geocodedAddress = addressMatches?.[0]?.matchedAddress;
                const geographies = addressMatches?.[0]?.geographies;

                if (geographies && geocodedAddress) {

                    // update the census object with the geocoded address and lookup table (geographies) returned from the API
                    census.geocodedAddress = geocodedAddress;
                    census.lookupTable = geographies;

                    // update the set of all matched addresses in the geocode report object with the matched address(es) returned from TigerWeb for this census
                    setGeocodeReportAllMatchedAddresses(addressMatches);

                    // extract data from the API geographies response for this census
                    // extracted data are saved to the mappedFields object, and to the UI if not using the geocode button
                    processCensusData(census);

                    // resolve and indicate that data were retrieved and processed for this census
                    return resolve(true);
                }

                // update the geocode report object to reflect that no data was retrieved for this census - we want to capture this in the report even if it's not treated as an error per se, since it is still important information about the geocoding process and result for this census
                updateGeocodeReportObject(census, 0); // pass 0 geocodes updated since no data was retrieved

                // resolve and indicate that no data were retrieved for this census
                resolve(false);
            })
            .fail((xhr, status, err) => {

                // reject with the error object if available, otherwise create a new error with the status text
                reject(err || new Error(status));
            });
        });
    }

    function transferMappedDataToREDCapForm() {

        setMappedREDCapFieldsToEmpty(); // clear the UI, no stale data allowed

        console.log('Transferring the following mapped fields to the REDCap form:', mappedFields);

        for (const [fieldName, value] of Object.entries(mappedFields)) {

            const $field = $(`[name="${fieldName}"]`);
            if ($field.length && value) {
                //console.log(`Setting ${fieldName} to ${value}`);
                $field.val(value).change();
                if ($field.hasClass('rc-autocomplete')) {
                    const $autocompleteField = $field.closest('td').find('.ui-autocomplete-input')
                    $autocompleteField.val($field.find('option:selected').text()).change();
                }
            }
        }
    }

    function processAllCensuses_deprecated() {

        for(const census of censuses) { downloadCensusData(census); }
    }

	function downloadCensusData_deprecated(census) {

        console.log('downloadCensusData called with census:', census);

		// part out fields from census
		const address = $(`[name="${addressField}"]`).val();

		if (!address) { return; }

		let encodedAddress = address.replace(/United States/g, '');
		console.log(`Looking up ${encodedAddress}`);
		$.LoadingOverlay('show');
		$.post(
			getAddressUrl,
			{
				'get': 1,
				'address': encodedAddress,
				'year': census.year,
				'benchmark_vintage': census.benchmark_vintage,
				'redcap_csrf_token': redcap_csrf_token
			},
			function(json) {
				$.LoadingOverlay('hide');

				console.log('Got data from TigerWeb');

				let data = JSON.parse(json);
				console.log(data);

                /*
				if (data && data['result'] && data['result']['addressMatches'] && data['result']['addressMatches'][0] && data['result']['addressMatches'][0]['geographies'] && data['result']['addressMatches'][0]['geographies']) {
					console.log('TigerWeb lookup data present');

					census['lookupTable'] = data['result']['addressMatches'][0]['geographies'];

					processCensusData(census);
				}
                */

                const addressMatches = data?.result?.addressMatches;
                const geocodedAddress = addressMatches?.[0]?.matchedAddress;
                const geographies = addressMatches?.[0]?.geographies;

                if (geographies && geocodedAddress) {
        
                    console.log('TigerWeb lookup data present');

                    census.geocodedAddress = geocodedAddress;
                    census.lookupTable = geographies;

                    // update the set of all matched addresses with the current matched address
                    setGeocodeReportAllMatchedAddresses(addressMatches);

                    console.log('Calling processCensusData with census:', census);

                    // update the UI with the geocodes and other census data
                    processCensusData(census);
                }
			});
	}

	function downloadCensusDataFromLatLong(census) {
		console.log('downloadCensusDataFromLatLong()')

		const latitude  = $(`[name="${latitudeField}"]`).val();
		const longitude = $(`[name="${longitudeField}"]`).val();

		if (!latitude || !longitude) { return; }

		console.log(`Looking up ${latitude}/${longitude}`);
		$.LoadingOverlay('show');
		$.ajax(
			{
				url: getCoordinatesUrl,
				data: {
					get: 1,
					lat: latitude,
					long: longitude,
					year: census.year,
					benchmark_vintage: census.benchmark_vintage,
                    redcap_csrf_token: redcap_csrf_token
				},
				type: 'POST'
			}).done(function(json) {
				$.LoadingOverlay('hide')
				console.log('Got coordinate data');
				console.log(json);
				let data = JSON.parse(json);
				if (data && data['result'] && data['result']['geographies'] && data['result']['geographies']) {
					census['lookupTable'] = data['result']['geographies'];
					processCensusData(census);
				}
			});
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
     * Processes the census data and updates the mappedFields object with data
     * 
     * Called for each census after retrieving data from the Census API.
     * 
     * @param {*} census 
     */
	function processCensusData(census) {
		let lookupTable       = census.lookupTable;
		let mappings          = census.mappings;
		var sortedGeographies = sortKeysByValue(lookupTable);
        let geocodesUpdated = 0;

		for (const mapping of mappings) {
			let value = '';

			for (const geography of sortedGeographies) {
				let potential_val = lookupTable[geography][0][mapping.keys];
				if (potential_val) {
					console.log(`found ${mapping.keys} in ${geography}`);
					value = potential_val;
					break;
				}
			}

            if (value) {

                geocodesUpdated++;

                const fieldName = mapping.fields

                mappedFields[fieldName] = value; // update the mappedFields object with the new value for this field - this is used to build the geocode report

                // if there is no geocode button, update the UI immediately as the data is processed for each census
                if ( !addGeoCodeButton ) {

                    console.log(`Setting ${fieldName} to ${value}`);
                    var field = $(`[name="${fieldName}"]`);
                    field.val(value);
                    field.change();

                    if (field.hasClass('rc-autocomplete')) {
                        var autocompleteField = field.closest('td').find('.ui-autocomplete-input')
                        autocompleteField.val(field.find('option:selected').text())
                        autocompleteField.change()
                    }
                }
            }
		}

        // update the geocode report object with the benchmark/vintage, matched address, and count of geocodes updated for this census
        updateGeocodeReportObject(census, geocodesUpdated);

        if ( !addGeoCodeButton ) {

            // if there is no geocode button, update the geocode report field in the UI immediately after processing each census
            updateGeocodeReportField();
        }

        //console.log('geocodeReport after processing census data:', geocodeReport);
        console.log('processCensusData:', census);
	}

	// The following used to occur on the 'blur' event, but we switched it to 'change' since some
	// modules update the field AFTER it has lost focus (like Address Autocompletion).
	if (addressField) {

		$(`[name="${addressField}"]`).change(function() {
			console.log('Looking up Census data');
			//for(const census of censuses) { downloadCensusData(census); }
            processAllCensuses(false);
		});

        injectGeobutton();
	}

	if (latitudeField && longitudeField) {
		$(`[name="${latitudeField}"]`).change(function() {
			for (const census of censuses) { downloadCensusDataFromLatLong(census); }
		});

		$(`[name="${longitudeField}"]`).change(function() {
			for(const census of censuses) { downloadCensusDataFromLatLong(census); }
		});
	}
});

<?php namespace Vanderbilt\CensusExternalModule;

/**/
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);
/**/

$module = new CensusExternalModule();

$censuses = $module->getCensuses();

$project_id = $module->getProjectId();

$batchApiRequirements  = $module->getApiRequirements("BWH-00133");

$module->initializeJavascriptModuleObject();

//$redcap_csrf_token = $module->getCSRFToken();

use REDCap;
use HtmlPage;

$HtmlPage = new HtmlPage();
$HtmlPage->ProjectHeader();
/*
echo "<pre>";
echo print_r($batchApiRequirements, true);
echo "</pre>";
exit();
*/

?>

<script>

    const module = ExternalModules.Vanderbilt.CensusExternalModule;

    const startUrl = <?php echo json_encode($module->getUrl('batch/api/start.php')); ?>;
    const stopUrl = <?php echo json_encode($module->getUrl('batch/api/stop.php')); ?>;
    const processUrl = <?php echo json_encode($module->getUrl('batch/api/process.php')); ?>;
    const stopBeaconUrl = <?php echo json_encode($module->getUrl('batch/api/stop_beacon.php')); ?>;
    const project_id = <?php echo json_encode($project_id); ?>;

    console.log("Batch module JS initialized. API endpoints:", { startUrl, stopUrl, processUrl, stopBeaconUrl });

    const record_list = [];

    $(document).ready(function() {
        // do something on load
    });
</script>

<style>

    #status {
        height: 30px;
        line-height: 30px;
    }

    #geocoder-wrapper table, 
        #geocoder-wrapper th, 
        #geocoder-wrapper td,
        #geocoder-wrapper select,
        #geocoder-wrapper button,
        #geocoder-wrapper input,
        #geocoder-wrapper div {
        font-size: 13px !important;
    }

    .yes3-scrolling-container {

        scrollbar-color: grey;
        scrollbar-width: 10px;
        overflow-y: auto;
        border: 1px solid #ccc;
        padding: 5px;
        background-color: #f9f9f9;
        /*
        font-family: "Open Sans", -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Helvetica, Arial, Arial, sans-serif, "Apple Color Emoji", "Segoe UI Emoji", "Segoe UI Symbol";
        */
    }

    .yes3-scrolling-container::-webkit-scrollbar-track
    {
        /*box-shadow: inset 0 0 5px rgba(0,0,0,0.3);*/
        /*border-radius: 2px;*/
        background-color: transparent;
    }

    .yes3-scrolling-container::-webkit-scrollbar
    {
        width: 10px;
        background-color: transparent;
    }

    .yes3-scrolling-container::-webkit-scrollbar-thumb
    {
        /*border-radius: 2px;*/
        /*box-shadow: inset 0 0 5px rgba(0,0,0,.3);*/
        background-color: gray;
    }

</style>

<div id="geocoder-wrapper" class="container">

    <h5>Census Geocoder Batch Processing</h5>

    <p>Abandon all hope, ye who enter here.</p>

    <div class="row">
        <div class="col-sm-6">

            <h6>Configuration</h6>

            <table class="table table-bordered">
                <tr>
                    <th>Field Name</th>
                    <th>Value</th>
                </tr>
                <tr>
                    <td>Address field</td>
                    <td><?php echo $module->getProjectSetting('address'); ?></td>
                </tr>
                <tr>
                    <td>Match result field</td>
                    <td><?php echo $module->getProjectSetting('geocode_match_result'); ?></td>
                </tr>
                <tr>
                    <td>Batch selection field</td>
                    <td><?php echo $module->getProjectSetting('batch_selection_field') ?? 'N/A'; ?></td>
                </tr>
                <tr>
                    <td>Batch size</td>
                    <td>
                        <select id="batchSize">
                            <option value="1">1</option>
                            <option value="5">5</option>
                            <option value="10">10</option>
                            <option value="25">25</option>
                            <option value="50" selected>50</option>
                            <option value="100">100</option>
                            <option value="250">250</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <td>Concurrency (simultaneous requests)</td>
                    <td>
                        <select id="concurrency">
                            <option value="1">1</option>
                            <option value="5" selected>5</option>
                            <option value="10">10</option>
                        </select>
                    </td>
                </tr>

            </table>
        </div>
        <div class="col-sm-6">
            <table class="table table-bordered">

                <h6>Progress Summary</h6>

                <tr>
                    <th>Match Result</th>
                    <th>Count</th>
                    <th>Percentage</th>
                </tr>
                <tr>
                    <td>Exact</td>
                    <td id="countExact">-</td>
                    <td id="pctExact">-</td>
                </tr>
                <tr>
                    <td>Inexact</td>
                    <td id="countInexact">-</td>
                    <td id="pctInexact">-</td>
                </tr>
                <tr>
                    <td>Open/No Match</td>
                    <td id="countNoMatch">-</td>
                    <td id="pctNoMatch">-</td>
                </tr>
                <tr>
                    <td>Closed/No Match</td>
                    <td id="countClosedNoMatch">-</td>
                    <td id="pctNoMatch">-</td>
                </tr>
                <tr>
                    <td>Total</td>
                    <td id="countTotal">-</td>
                    <td id="pctTotal">-</td>
                </tr>
            </table>
        </div>
    </div>

    <button class="btn btn-secondary" id="start">Next Batch</button>
    <button class="btn btn-secondary" id="pause" disabled>Pause</button>
    <button class="btn btn-secondary" id="resume" disabled>Resume</button>
    <button class="btn btn-secondary" id="stop" disabled>Stop</button>

    <div id="status"></div>

    <div id="log" class="yes3-scrolling-container" style="max-height: 300px; height: 300px;"></div>

</div>

<script>

    let timeStarted;
        
    const ui = {
        start: document.getElementById('start'),
        pause: document.getElementById('pause'),
        resume: document.getElementById('resume'),
        stop: document.getElementById('stop'),
        status: document.getElementById('status'),
        log: document.getElementById('log'),
    };

    function logLine(s) {
        ui.log.innerHTML += s + "<br>";
    }

    function setStatus(s) {
        const timeNow = Date.now();
        const elapsed = ((timeNow - timeStarted) / 1000).toFixed(1);
        ui.status.textContent = `${s} (Elapsed: ${elapsed}s)`;
    }

    async function postFormData(url, body, { signal } = {}) {

        const fd = new FormData();

        for (const [key, value] of Object.entries(body)) {
            fd.append(key, value);
        }

        fd.append('redcap_csrf_token', redcap_csrf_token);

        const res = await fetch(url, {
            method: "POST",
            cache: "no-store",
            credentials: "same-origin",
            body: fd,
            signal
        });

        if (!res.ok) {
            const text = await res.text();
            throw new Error(`HTTP ${res.status}: ${text}`);
        }

        const raw = await res.text();

        try {
            return JSON.parse(raw);
        } catch {
            console.error("Response from", url, "is not valid JSON:", raw);
            throw new Error(`Invalid JSON response: ${raw}`);
        }
    }

    function sleep(ms) {

        return new Promise(r => setTimeout(r, ms));
    }

    async function runBatch({ itemIds, concurrency = 6 }) {
        // --- Create a server-side run ---

        const run = await postFormData(startUrl, { total: itemIds.length, project_id: project_id });

        console.log("Run created:", run);

        timeStarted = Date.now();

        const runId = run.runId;

        setStatus(`Run ${runId} started. Total: ${itemIds.length}.`);

        let stopped = false;
        let paused = false;

        const controllers = new Set(); // track inflight requests so Stop can abort them
        const queue = itemIds.slice(); // copy
        let done = 0, failed = 0;

        function updateButtons() {
            ui.start.disabled = true;
            ui.pause.disabled = stopped || paused;
            ui.resume.disabled = stopped || !paused;
            ui.stop.disabled = stopped;
        }

        async function stopNow(reason = "Stopped by user. Click Start to begin a new run.") {
            if (stopped) return;
            stopped = true;
            paused = false;
            updateButtons();
            setStatus(`${reason}. Notifying server...`);

            // Abort inflight
            for (const c of controllers) c.abort();
            controllers.clear();

            // Tell server to stop (best-effort)
            try { await postFormData(stopUrl, { runId: runId }); } catch (e) {}

            setStatus(`${reason}. Completed=${done} Failed=${failed}.`);

            console.log("Batch geocoding run stopped.");
        }

        async function pauseNow(reason = "Paused") {
            if (stopped) return;
            paused = true;
            updateButtons();
            setStatus(`${reason}. Completed=${done} Failed=${failed}, Remaining=${queue.length}.`);
        }

        async function resumeNow() {
            if (stopped) return;
            paused = false;
            updateButtons();
            setStatus(`Resumed. Completed=${done} Failed=${failed}, Remaining=${queue.length}.`);
        }

        // Button handlers
        ui.pause.onclick = () => pauseNow("Paused by user. Click Resume to continue.");
        ui.resume.onclick = () => resumeNow();
        ui.stop.onclick = () => stopNow("Stopped by user. Click Start to begin a new run.");

        // Guard rails: if tab becomes hidden, pause (or stop) to enforce “operator present”.
        document.addEventListener("visibilitychange", () => {

            if (document.hidden && !stopped) {
                pauseNow("Paused because tab was hidden.");
            }
        });

        // On unload, stop run (best-effort). This prevents “walk away with it still running”.
        /*
        window.addEventListener("beforeunload", (e) => {

            if (!stopped) {

                // send the beacon to notify server that the run was stopped due to tab/window close.
                navigator.sendBeacon?.(stopBeaconUrl, JSON.stringify({ runId }));

                // best-effort stop before unload
                stopNow("Stopped by tab/window close");

                e.preventDefault();
                e.returnValue = "";
            }
        });
        */

        updateButtons();
        ui.stop.disabled = false;
        ui.pause.disabled = false;

        // Worker function
        async function worker(workerId) {
            while (!stopped) {
                if (paused) {
                    await sleep(150);
                    continue;
                }

                const itemId = queue.shift();
                if (!itemId) return;

                const controller = new AbortController();
                controllers.add(controller);

                try {

                    // a random delay to spread out requests and reduce chance of hitting rate limits.
                    await sleep(Math.random() * 500);

                    console.log(`Worker ${workerId} processing item ${itemId}...`);

                    const thisProcessUrl = processUrl + `?X=${crypto.randomUUID()}`;

                    // call the API to process the item, save results to server, etc.
                    const r = await postFormData(thisProcessUrl, { runId: runId, itemId: itemId, project_id: project_id }, { signal: controller.signal });

                    console.log(`Worker ${workerId} processed item ${itemId}: r=`, r);

                    done++;

                    // Log progress
                    logLine(`[W${workerId}] OK record=${itemId}, matchResult=${r.api_result['matchResult']} (${done}/${itemIds.length})`);

                    // If server indicates run stopped, honor it
                    if (r.runStatus && r.runStatus !== "running") {

                        await stopNow(`Server status: ${r.runStatus}`);

                        return;
                    }
                } catch (err) {

                    failed++;

                    // log the error message
                    logLine(`[W${workerId}] FAIL record=${itemId} err=${err.message}`);

                    // may want to re-queue the item for retry, but for now we just count it as failed and move on.
                    // queue.push(itemId);
                } finally {

                    controllers.delete(controller);

                    if (!paused && !stopped) {

                        setStatus(`Running. Completed=${done} Failed=${failed} Remaining=${queue.length}`);
                    }
                }
            }
        }

        // Spin up worker pool
        const workers = Array.from({ length: concurrency }, (_, i) => worker(i + 1));

        await Promise.allSettled(workers);

        if (!stopped) {

            // normal completion
            ui.stop.disabled = true;
            ui.pause.disabled = true;
            ui.resume.disabled = true;
            ui.start.disabled = false;

            setStatus(`Completed. Completed=${done} Failed=${failed}`);

            // Notify server of completion
            //try { 
            //
            //    await postFormData("/api/run/finish.php", { runId }); 
            //} catch (e) {}
        }
    }


    /**
     * START BUTTON
     * 
     * Fetch the next bath and get to work!
     */
    ui.start.onclick = async () => {

        ui.log.textContent = "";

        const batchSize = parseInt(document.getElementById('batchSize').value, 10) || 50;
        const concurrency = parseInt(document.getElementById('concurrency').value, 10) || 5;

        module.ajax('fetchNextBatch', {batchSize: batchSize, project_id: project_id}).then(batch => {

            //console.log('Received batch:', batch);

            runBatch({ itemIds: batch, concurrency: concurrency });

        }).catch(err => {

            console.error('Error fetching batch:', err);
        });
        
        // const itemIds = Array.from({ length: 100 }, (_, i) => i + 1); // replace with real IDs
        // const concurrency = 6; // make user-selectable
        // await runBatch({ itemIds, concurrency });
    };

</script>



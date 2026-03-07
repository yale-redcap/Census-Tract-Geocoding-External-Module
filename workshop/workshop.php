<?php namespace Vanderbilt\CensusExternalModule;

/**/
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);
/**/

$module = new CensusExternalModule();

use AddressMatcher;
use AddrSimSScore;

use REDCap;
use HtmlPage;

$HtmlPage = new HtmlPage();
$HtmlPage->ProjectHeader();

//testAddrSimScore();

assignRandomAddresses();

function testAddrSimScore()
{
    $address1 = "22 Glen Keith Road APT 2L, Glen Cove, NY 11552";
    $address2 = "22 GLEN KEITH RD, GLEN COVE, NY, 11542";

    $score = \Vanderbilt\CensusExternalModule\AddrSimScore::address_similarity($address1, $address2);

    echo "<pre>";
    echo print_r($score, true);
    echo "</pre>";
}
/*
function testAddressMatcher()
{
    $address1 = "22 Glen Keith Road Apt L, Glen Cove, NY 11552";
    $address2 = "22 GLEN KEITH RD, GLEN COVE, NY, 11542";

    $result = \Vanderbilt\CensusExternalModule\AddressMatcher::compare($address1, $address2);

    echo "<pre>";
    print_r($result);
    echo "</pre>";
}
*/
function assignRandomAddresses()
{
    global $module;

    $addresses = [
        "2 Juniper Drive, Milford, CT 06461",
        "306 Clark Ave, Branford, CT 06405",
        "70 Lawndale Ave SE, Concord, NC 28025",
        "567 Ball Road, Glastonbury, CT 06033", // Bell Street
        "7 Edgehill Rd, New Haven, CT 06511",
        "22 Glen Keith Road Apt. L, Glen Cove, NY 11542",
        "3222 Ridgeway Ave Apt 1, Madison, WI 53704",
        "37 Brush Hill Rd, Goshen, CT 06756",
        "177 Gorham Ave, Hamden, CT 06514",
        "50 Silo Street, Harwengton, RI 06791",   // 50 Silo Drive, Harwinton, CT 06791
        "56 Concord St, West Hartford, CT 06119",
        "52 Little Brook Rd, New Hartford, CT 06057",
        "P.O. Box 12, Watertown, CT 06795",
        "25 Lafayette St, Milford, NV 66460",
        "70 Squires Rd, Madison, CT 06443",
        "357 S Brooksvale Rd, Cheshire, CT 06411",
        "380 Flagler Ave, Stratford, AZ 06614",
        "304 Towhee Rd, Winter Haven, FL 33881",
        "835 NW Sorrento Lane, Port St Lucie, FL 34986",
        "70 Hillslide Rd Apt 401, Branford, CT 06405",
        "47 Rose St, East Haven, CT 06513",
        "6920 Knoll St, Gilded Valley, MN 55427",
        "933 Mayfair Ave, Madison, WI 53714",
        "W311s9078 Moccasin Trl, Mukwonago, WI 53194", // zip changed from 53149
        "1827 East Washington Ave Apt 577, Madison, WI 53704",
        "10036 Oakhurst Way, Fort Myers, FL 33913"
    ];

    $redcap_data = $module->getDataTable();

    $sql = "SELECT record FROM redcap_record_list WHERE project_id = ?";

    $qResult = $module->query($sql, [$module->getProjectId()]);

    echo "<pre>";

    while ($row = $qResult->fetch_assoc()) {
        $record = $row["record"];
        $address = $addresses[array_rand($addresses)];
        $sqlUpdate = "UPDATE $redcap_data SET value = ? WHERE project_id = ? AND record = ? AND field_name = 'pt_full_address_manual'";

        $params = array(
            'dataFormat' => 'json',
            'type' => 'flat',
            'data' => '[{"partid": "' . $record . '", "pt_full_address_manual": "' . $address . '"}]',
            'dataLogging' => false
        );

        $rc = REDCap::saveData($params);

        echo "REDCap saveData result for {$record}: " . $rc['item_count'] . " items updated.\n";
    }

    echo "</pre>";
}


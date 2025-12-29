<?php
require_once 'ApiClient.php';
$config = parse_ini_file('config.ini', true);
$client = new ApiClient($config);
$res = $client->login();
if ($res['success']) {
    echo 'Login SUCCESS. ID: ' . $res['login_id'] . "\n";
    // Test BARCODE_INFO first
    echo 'Testing BARCODE_INFO...';
    $info = $client->getParkTicketInfo('TEST');
    echo 'Result: ' . json_encode($info) . "\n";
} else {
    echo 'Login FAILED: ' . $res['error'] . "\n";
}
?>
<?php
require_once 'ApiClient.php';
$config = parse_ini_file('config.ini', true);
$client = new ApiClient($config);

echo "1. Logowanie...\n";
$login = $client->login();
if (!$login['success']) {
    die("Błąd logowania: " . $login['error'] . "\n");
}
echo "Zalogowano. ID: " . $client->getLoginId() . "\n\n";

$barcode = 'KRA200'; // Testowy numer
$nowStr = date('Y-m-d H:i:s');
$nowInt = time();

$variants = [
    'A: Standard + Date String + Device/Entity' => [
        'BARCODE' => $barcode,
        'DATE_FROM' => $nowStr,
        'DATE_TO' => $nowStr,
        'DEVICE_ID' => (int)$config['api']['device_id'],
        'ENTITY_ID' => (int)$config['api']['entity_id']
    ],
    'B: Standard + Date String (No Device/Entity)' => [
        'BARCODE' => $barcode,
        'DATE_FROM' => $nowStr,
        'DATE_TO' => $nowStr
    ],
    'C: Standard + Date TIMESTAMP (Int)' => [
        'BARCODE' => $barcode,
        'DATE_FROM' => $nowInt,
        'DATE_TO' => $nowInt,
        'DEVICE_ID' => (int)$config['api']['device_id'],
        'ENTITY_ID' => (int)$config['api']['entity_id']
    ],
    'D: Tylko Date (bez From/To - stary format?)' => [
        'BARCODE' => $barcode,
        'DATE' => $nowStr,
        'DEVICE_ID' => (int)$config['api']['device_id'],
        'ENTITY_ID' => (int)$config['api']['entity_id']
    ],
];

foreach ($variants as $name => $params) {
    echo "Testuje wariant [$name]...\n";
    
    // Hack to access protected sendRequest via reflection or just duplicate logic casually?
    // Let's just use a public method wrapper or reflection. ApiClient doesn't have generic 'send'.
    // I will use reflection to invoke sendRequest.
    
    $request = array_merge([
        'METHOD' => 'PARK_TICKET_GET_INFO',
        'ORDER_ID' => 999, // Random order
        'LOGIN_ID' => $client->getLoginId(),
    ], $params);
    
    // Use reflection to call private sendRequest
    $method = new ReflectionMethod('ApiClient', 'sendRequest');
    $method->setAccessible(true);
    $response = $method->invoke($client, $request);
    
    echo "Status: " . ($response['STATUS'] ?? 'Brak') . "\n";
    if (isset($response['STATUS']) && $response['STATUS'] == 0) {
        echo "SUKCES! Odpowiedź:\n";
        print_r($response);
        break; 
    } else {
        echo "Błąd: " . ($response['STATUS'] ?? '?') . "\n";
    }
    echo "------------------------------------------------\n";
}

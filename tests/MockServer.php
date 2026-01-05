<?php
// tests/MockServer.php

date_default_timezone_set('Europe/Warsaw');

$port = $argv[1] ?? 12345;
$socket = stream_socket_server("tcp://0.0.0.0:$port", $errno, $errstr);
if (!$socket) die("Error: $errstr ($errno)\n");

echo "Mock Server listening on $port...\n";

// Configuration defaults
$HOURLY_RATE = 500; // 5.00 PLN in grosze
$DAILY_RATE = 5000; // 50.00 PLN in grosze (example)
$FREE_MINUTES = 15;

$stateFile = __DIR__ . '/_output/mock_state.json';
if (!file_exists(dirname($stateFile))) mkdir(dirname($stateFile), 0777, true);

// --- HELPER: Scenario Decoder ---
function getScenarioRules($barcode) {
    // Default: Single Day, Hourly, From Entry (0_1_0)
    $rules = ['multi' => 0, 'type' => 1, 'start' => 0]; 
    
    // Look for pattern _MTS (Multi, Type, Start) e.g., _010, _111
    if (preg_match('/_(\d)(\d)(\d)$/', $barcode, $matches)) {
        $rules['multi'] = (int)$matches[1];
        $rules['type']  = (int)$matches[2];
        $rules['start'] = (int)$matches[3];
    } elseif (preg_match('/PAY_(\d)(\d)(\d)/', $barcode, $matches)) {
         // Legacy test support
        $rules['multi'] = (int)$matches[1];
        $rules['type']  = (int)$matches[2];
        $rules['start'] = (int)$matches[3];
    }
    return $rules;
}

// --- HELPER: Fee Calculator ---
function calculateMockFee($startStr, $endStr, $rules, $hourlyRate, $dailyRate) {
    $start = new DateTime($startStr);
    $end = new DateTime($endStr);
    
    // Don't calculate negative time
    if ($end < $start) return 0;

    $interval = $start->diff($end);
    $minutes = ($interval->days * 24 * 60) + ($interval->h * 60) + $interval->i;

    if ($rules['type'] == 1) { 
        // HOURLY
        $hours = ceil($minutes / 60);
        return $hours * $hourlyRate;
    } else { 
        // DAILY
        // Logic check: Single vs Multi
        if ($rules['multi'] == 0) {
            // Single day daily is usually a fixed fee
            return $dailyRate;
        } else {
            // Multi day: Count days started
            $days = $interval->days;
            if ($interval->h > 0 || $interval->i > 0) $days++;
            return max(1, $days) * $dailyRate;
        }
    }
}

while ($conn = stream_socket_accept($socket)) {
    $request = fgets($conn);
    if (!$request) { fclose($conn); continue; }
    
    $data = json_decode($request, true);
    if (!$data) { fclose($conn); continue; }

    $method = $data['METHOD'] ?? '';
    $orderId = $data['ORDER_ID'] ?? 0;
    $response = ['STATUS' => 0, 'ORDER_ID' => $orderId, 'METHOD' => $method];

    // Load State
    $state = file_exists($stateFile) ? json_decode(file_get_contents($stateFile), true) : [];

    switch ($method) {
        case 'LOGIN':
            $response['LOGIN_ID'] = 'MOCK_LOGIN_123';
            $response['USER'] = ['NAME' => 'Mock Tester'];
            break;

        case 'PARK_TICKET_GET_INFO':
            $barcode = $data['BARCODE'] ?? 'UNKNOWN';
            $reqDateFrom = $data['DATE_FROM'] ?? date('Y-m-d H:i:s');
            $reqDateTo   = $data['DATE_TO'] ?? date('Y-m-d H:i:s');

            $rules = getScenarioRules($barcode);
            
            // Calculate Total Fee based on requested time range
            $totalFee = calculateMockFee($reqDateFrom, $reqDateTo, $rules, $HOURLY_RATE, $DAILY_RATE);

            // Check if user has already paid some amount
            $paidAmount = $state[$barcode]['fee_paid'] ?? 0;
            
            $toPay = max(0, $totalFee - $paidAmount);

            $response = array_merge($response, [
                'TICKET_EXIST' => 1,
                'BARCODE' => $barcode,
                'REGISTRATION_NUMBER' => $barcode,
                'VALID_FROM' => $reqDateFrom,
                'VALID_TO' => $reqDateTo, // This confirms the user's selection
                'FEE' => $toPay,          // Remaining fee
                'FEE_PAID' => $paidAmount,
                'FEE_TYPE' => $rules['type'],
                'FEE_MULTI_DAY' => $rules['multi'],
                'FEE_STARTS_TYPE' => $rules['start'],
                // Debug fields
                'DEBUG_CALC' => [
                    'minutes' => (strtotime($reqDateTo) - strtotime($reqDateFrom))/60,
                    'total' => $totalFee
                ]
            ]);
            break;

        case 'PARK_TICKET_PAY':
            $barcode = $data['BARCODE'] ?? '';
            $paidNow = (int)($data['FEE'] ?? 0);
            $reqDateTo = $data['DATE_TO'] ?? date('Y-m-d H:i:s');
            
            // Update State
            $currentPaid = $state[$barcode]['fee_paid'] ?? 0;
            $state[$barcode] = [
                'fee_paid' => $currentPaid + $paidNow,
                'last_payment_date' => date('Y-m-d H:i:s')
            ];
            file_put_contents($stateFile, json_encode($state));

            $response['RECEIPT_NUMBER'] = rand(10000, 99999);
            break;

        case 'RESET_MOCK':
            if (file_exists($stateFile)) unlink($stateFile);
            break;
    }

    fwrite($conn, json_encode($response) . "\n");
    fclose($conn);
}
<?php
date_default_timezone_set('Europe/Warsaw');

$port = $argv[1] ?? 12345;
$socket = stream_socket_server("tcp://0.0.0.0:$port", $errno, $errstr);

if (!$socket) {
  die("Error creating socket: $errstr ($errno)\n");
}

echo "Mock Server listening on port $port...\n";

// State file for payment persistence
$stateFile = __DIR__ . '/_output/mock_state.json';
if (!file_exists(dirname($stateFile)))
  mkdir(dirname($stateFile), 0777, true);

// Reset state on startup
if (file_exists($stateFile))
  unlink($stateFile);

while ($conn = stream_socket_accept($socket)) {
  $request = fgets($conn);
  if ($request === false) {
    fclose($conn);
    continue;
  }

  $data = json_decode($request, true);
  echo "Received request: $request\n";
  if (!$data) {
    fclose($conn);
    continue;
  }

  $method = $data['METHOD'] ?? '';
  $orderId = $data['ORDER_ID'] ?? 0;

  // Load current state
  $state = file_exists($stateFile) ? json_decode(file_get_contents($stateFile), true) : [];

  $response = ['STATUS' => -999, 'METHOD' => 'ERROR'];

  switch ($method) {
    case 'LOGIN':
      $response = ['STATUS' => 0, 'LOGIN_ID' => 'MOCK_LOGIN', 'ORDER_ID' => $orderId, 'METHOD' => 'LOGIN'];
      break;

    case 'PARK_TICKET_GET_INFO':
      $barcode = $data['BARCODE'] ?? '';

      // Check if we have a stored state for this barcode
      if (isset($state[$barcode])) {
        $savedState = $state[$barcode];
        $response = [
          'STATUS' => 0,
          'TICKET_EXIST' => 1,
          'REGISTRATION_NUMBER' => $barcode,
          'VALID_FROM' => $savedState['valid_from'],
          'VALID_TO' => $savedState['valid_to'],
          'FEE' => max(0, $savedState['fee'] - $savedState['fee_paid']),
          'FEE_PAID' => $savedState['fee_paid'],
          'FEE_TYPE' => $savedState['fee_type'] ?? 0,
          'FEE_MULTI_DAY' => $savedState['fee_multi_day'] ?? 0,
          'ORDER_ID' => $orderId,
          'METHOD' => 'PARK_TICKET_GET_INFO'
        ];
      } else {
        // Default "Unpaid" State for tests based on Barcode Scenario
        $validFrom = date('Y-m-d H:i:s', strtotime('-1 hour'));
        $feeType = 1; // Default Hourly
        $feeMulti = 0;

        if (preg_match('/PAY_(\d)(\d)(\d)/', $barcode, $matches)) {
          $feeType = (int) $matches[1];
          $feeMulti = (int) $matches[2];
        }

        $response = [
          'STATUS' => 0,
          'TICKET_EXIST' => 1,
          'REGISTRATION_NUMBER' => $barcode,
          'VALID_FROM' => $validFrom,
          'VALID_TO' => date('Y-m-d H:i:s', strtotime($validFrom . ' + 15 minutes')),
          'FEE' => 500, // 5.00 PLN to pay
          'FEE_PAID' => 0,
          'FEE_TYPE' => $feeType,
          'FEE_MULTI_DAY' => $feeMulti,
          'ORDER_ID' => $orderId,
          'METHOD' => 'PARK_TICKET_GET_INFO'
        ];
      }
      break;

    case 'PARK_TICKET_PAY':
      $barcode = $data['BARCODE'] ?? '';
      $feePaid = $data['FEE'] ?? 0;
      $dateTo = $data['DATE_TO'] ?? date('Y-m-d H:i:s');

      $validFrom = isset($data['DATE_FROM']) ? strtotime($data['DATE_FROM']) : (isset($state[$barcode]) ? strtotime($state[$barcode]['valid_from']) : strtotime('-1 hour'));
      $newValidTo = $dateTo;

      // Detect Scenario from Barcode (e.g., PAY_000)
      if (preg_match('/PAY_(\d)(\d)(\d)/', $barcode, $matches)) {
        $type = $matches[1];   // 0=Daily, 1=Hourly
        $multi = $matches[2];  // 0=Single, 1=Multi
        $starts = $matches[3]; // 0=Entry, 1=Midnight

        if ($type == '0') { // Daily
          if ($starts == '0') {
            // 24h from entry
            $newValidTo = date('Y-m-d H:i:s', $validFrom + 86400);
          } else {
            // End of day
            $newValidTo = date('Y-m-d 23:59:59', $validFrom);
          }
        } else { // Hourly
          // Calculate based on amount paid (assuming 5.00 rate)
          // feePaid is in units (e.g. 500 = 5.00 PLN)
          $minutesAdded = ($feePaid / 500) * 60;
          $newValidTo = date('Y-m-d H:i:s', $validFrom + ($minutesAdded * 60));
        }
      }

      $currentPaid = isset($state[$barcode]) ? $state[$barcode]['fee_paid'] : 0;
      $currentFee = isset($state[$barcode]) ? $state[$barcode]['fee'] : 500;
      $feeType = isset($state[$barcode]) ? $state[$barcode]['fee_type'] : 0;
      $feeMulti = isset($state[$barcode]) ? $state[$barcode]['fee_multi_day'] : 0;

      if (preg_match('/PAY_(\d)(\d)(\d)/', $barcode, $matches)) {
        $feeType = (int) $matches[1];
        $feeMulti = (int) $matches[2];
      }

      $newState = [
        'valid_from' => date('Y-m-d H:i:s', $validFrom),
        'valid_to' => $newValidTo,
        'fee' => $currentFee,
        'fee_paid' => $currentPaid + $feePaid,
        'fee_type' => $feeType,
        'fee_multi_day' => $feeMulti
      ];

      $state[$barcode] = $newState;
      file_put_contents($stateFile, json_encode($state));

      $response = [
        'STATUS' => 0,
        'RECEIPT_NUMBER' => rand(1000, 9999),
        'ORDER_ID' => $orderId,
        'METHOD' => 'PARK_TICKET_PAY'
      ];
      break;

    // Reset command for tests
    case 'RESET_MOCK':
      if (file_exists($stateFile))
        unlink($stateFile);
      $response = ['STATUS' => 0, 'METHOD' => 'RESET_MOCK'];
      break;
  }

  fwrite($conn, json_encode($response) . "\n");
  fclose($conn);
}

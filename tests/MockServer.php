<?php

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
          'FEE' => $savedState['fee'], // Remaining fee
          'FEE_PAID' => $savedState['fee_paid'], // Total paid
          'FEE_TYPE' => (strpos($barcode, 'DAILY') !== false) ? 0 : ($savedState['fee_type'] ?? 0),
          'FEE_MULTI_DAY' => $savedState['fee_multi_day'] ?? 0,
          'ORDER_ID' => $orderId,
          'METHOD' => 'PARK_TICKET_GET_INFO'
        ];
      } else {
        // Default "Unpaid" State for tests
        $response = [
          'STATUS' => 0,
          'TICKET_EXIST' => 1,
          'REGISTRATION_NUMBER' => $barcode,
          'VALID_FROM' => date('Y-m-d H:i:s', strtotime('-1 hour')),
          // Default valid_to is usually entry + grace period or 0
          'VALID_TO' => date('Y-m-d H:i:s', strtotime('-1 hour + 15 minutes')),
          'FEE' => 500, // 5.00 PLN to pay
          'FEE_PAID' => 0,
          'ORDER_ID' => $orderId,
          'METHOD' => 'PARK_TICKET_GET_INFO'
        ];
      }
      break;

    case 'PARK_TICKET_PAY':
      $barcode = $data['BARCODE'] ?? '';
      $feePaid = $data['FEE'] ?? 0;
      $dateTo = $data['DATE_TO'] ?? date('Y-m-d H:i:s');

      // Save state: User just paid 'feePaid'.
      // New State: Fee=Total Fee (preserved), FeePaid += feePaid, ValidTo = dateTo
      $currentPaid = isset($state[$barcode]) ? $state[$barcode]['fee_paid'] : 0;
      $currentFee = isset($state[$barcode]) ? $state[$barcode]['fee'] : 500; // Default assumed fee

      $newState = [
        'valid_from' => date('Y-m-d H:i:s', strtotime('-1 hour')),
        'valid_to' => $dateTo,
        'fee' => $currentFee, // Preserve Total Fee
        'fee_paid' => $currentPaid + $feePaid,
        // These would ideally come from the request or config, simplified here:
        'fee_type' => 0,
        'fee_multi_day' => 0
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

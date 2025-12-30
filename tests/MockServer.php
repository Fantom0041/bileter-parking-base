<?php

$port = $argv[1] ?? 12345;

$socket = stream_socket_server("tcp://0.0.0.0:$port", $errno, $errstr);
if (!$socket) {
  file_put_contents('mock_server_error.log', "Error creating socket: $errstr ($errno)\n", FILE_APPEND);
  die("Error creating socket: $errstr ($errno)\n");
}

file_put_contents('mock_server.log', "Mock Server listening on port $port...\n", FILE_APPEND);
echo "Mock Server listening on port $port...\n";

while ($conn = stream_socket_accept($socket)) {
  file_put_contents('mock_server.log', "Connection accepted\n", FILE_APPEND);
  // Read request
  $request = fgets($conn);
  if ($request === false) {
    fclose($conn);
    continue;
  }

  $data = json_decode($request, true);
  if (!$data) {
    fwrite($conn, json_encode(['STATUS' => -3, 'DESC' => 'Invalid JSON']) . "\n");
    fclose($conn);
    continue;
  }

  $method = $data['METHOD'] ?? '';
  $orderId = $data['ORDER_ID'] ?? 0;

  $response = ['STATUS' => -999]; // Default error

  // Logic based on Method and Content
  switch ($method) {
    case 'LOGIN':
      $response = [
        'METHOD' => 'LOGIN',
        'ORDER_ID' => $orderId,
        'STATUS' => 0,
        'LOGIN_ID' => 'TEST_LOGIN_ID_123'
      ];
      break;

    case 'PARK_TICKET_GET_INFO':
      $barcode = $data['BARCODE'] ?? '';

      if ($barcode === 'TEST_OK' || $barcode === 'TEST_LIVE') {
        $response = [
          'METHOD' => 'PARK_TICKET_GET_INFO',
          'ORDER_ID' => $orderId,
          'STATUS' => 0,
          'TICKET_EXIST' => 1,
          'REGISTRATION_NUMBER' => $barcode,
          'VALID_FROM' => date('Y-m-d H:i:s', strtotime('-1 hour')),
          'VALID_TO' => date('Y-m-d H:i:s', strtotime('+1 hour')),
          'FEE' => 0,
          'FEE_PAID' => 0
        ];
      } elseif ($barcode === 'TEST_NEW') {
        // Not found, but valid query
        $response = [
          'METHOD' => 'PARK_TICKET_GET_INFO',
          'ORDER_ID' => $orderId,
          'STATUS' => 0,
          'TICKET_EXIST' => 0
        ];
      } elseif ($barcode === 'TEST_FEE_500') {
        // For fee calculation
        // Need DATE_FROM / DATE_TO ??
        $response = [
          'METHOD' => 'PARK_TICKET_GET_INFO',
          'ORDER_ID' => $orderId,
          'STATUS' => 0,
          'TICKET_EXIST' => 1,
          'REGISTRATION_NUMBER' => 'TEST_FEE_500',
          'VALID_FROM' => date('Y-m-d H:i:s', strtotime('-2 hours')),
          'VALID_TO' => date('Y-m-d H:i:s', strtotime('+1 hour')),
          'FEE' => 1000, // 10.00 PLN (if 2 hours * 5.00)
          'FEE_PAID' => 0
        ];
      } elseif ($barcode === 'TEST_HOURLY') {
        $response = [
          'METHOD' => 'PARK_TICKET_GET_INFO',
          'ORDER_ID' => $orderId,
          'STATUS' => 0,
          'TICKET_EXIST' => 1,
          'REGISTRATION_NUMBER' => 'TEST_HOURLY',
          'VALID_FROM' => date('Y-m-d H:i:s', strtotime('-1 hour')),
          'VALID_TO' => date('Y-m-d H:i:s', strtotime('+1 hour')),
          'FEE_TYPE' => 1, // Hourly
          'FEE_MULTI_DAY' => 0, // Single day for now
          'FEE' => 0,
          'FEE_PAID' => 0
        ];
      } elseif ($barcode === 'TEST_DAILY') {
        $response = [
          'METHOD' => 'PARK_TICKET_GET_INFO',
          'ORDER_ID' => $orderId,
          'STATUS' => 0,
          'TICKET_EXIST' => 1,
          'REGISTRATION_NUMBER' => 'TEST_DAILY',
          'VALID_FROM' => date('Y-m-d H:i:s', strtotime('-1 hour')),
          'VALID_TO' => date('Y-m-d H:i:s', strtotime('+1 hour')),
          'FEE_TYPE' => 0, // Daily
          'FEE_MULTI_DAY' => 0,
          'FEE' => 0,
          'FEE_PAID' => 0
        ];
      } else {
        // Simulated "Not Found" error from some systems or just empty
        $response = [
          'METHOD' => 'PARK_TICKET_GET_INFO',
          'ORDER_ID' => $orderId,
          'STATUS' => 0,
          'TICKET_EXIST' => 0
        ];
      }
      break;

    case 'PARK_TICKET_SET_PLATE':
      // Return Success
      $response = [
        'METHOD' => 'PARK_TICKET_SET_PLATE',
        'ORDER_ID' => $orderId,
        'STATUS' => 0
      ];
      break;

    default:
      $response = [
        'METHOD' => 'ERROR',
        'ORDER_ID' => $orderId,
        'STATUS' => -5,
        'DESC' => 'Method not supported'
      ];
      break;
  }

  // Simulate slight network delay if needed
  // usleep(10000); 

  fwrite($conn, json_encode($response) . "\n");
  fclose($conn);
}

fclose($socket);

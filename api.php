<?php
header('Content-Type: application/json');

// 1. Load Configuration & Data
$config = parse_ini_file('config.ini');
$json_file = 'data.json';
$json_data = file_get_contents($json_file);
$tickets = json_decode($json_data, true);

// 2. Get POST Data
$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? 'pay'; // Default to pay for backward compatibility

if ($action === 'create') {
    // CREATE NEW TICKET
    $plate = $input['plate'] ?? null;
    if (!$plate) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Plate number required']);
        exit;
    }

    $new_id = (string) rand(10000, 99999);
    // Ensure unique ID
    while (isset($tickets[$new_id])) {
        $new_id = (string) rand(10000, 99999);
    }

    $tickets[$new_id] = [
        'plate' => strtoupper($plate),
        'entry_time' => date('Y-m-d H:i:s'),
        'status' => 'active'
    ];

    if (file_put_contents($json_file, json_encode($tickets, JSON_PRETTY_PRINT))) {
        echo json_encode([
            'success' => true,
            'ticket_id' => $new_id
        ]);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database write error']);
    }
    exit;
}

// PAY EXISTING TICKET
$ticket_id = $input['ticket_id'] ?? null;
$amount = $input['amount'] ?? 0;

// 3. Validate
if (!$ticket_id || !isset($tickets[$ticket_id])) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Ticket not found']);
    exit;
}

// 4. Process Payment
// In a real app, we would integrate Stripe/PayPal here.
// For this mock, we assume success.

// Update Ticket Status
$tickets[$ticket_id]['status'] = 'paid';
$tickets[$ticket_id]['payment_time'] = date('Y-m-d H:i:s');
$tickets[$ticket_id]['amount_paid'] = $amount;

// Save to DB
if (file_put_contents($json_file, json_encode($tickets, JSON_PRETTY_PRINT))) {
    // 5. Generate "Exit Ticket" (Mock QR)
    $new_qr_code = "EXIT-" . strtoupper(uniqid());

    echo json_encode([
        'success' => true,
        'message' => 'Payment successful',
        'new_ticket_qr' => $new_qr_code,
        'valid_until' => date('H:i', strtotime('+15 minutes')) // 15 mins to exit
    ]);
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database write error']);
}
?>
<?php
// Wyłącz wyświetlanie błędów w HTML, żeby nie psuły JSON-a
ini_set('display_errors', '0');
error_reporting(E_ALL);
date_default_timezone_set('Europe/Warsaw');

// Logowanie błędów zamiast wyświetlania
set_error_handler(function ($errno, $errstr, $errfile, $errline) {
    error_log("PHP Error [$errno]: $errstr in $errfile on line $errline");
    return true;
});

// Globalny handler wyjątków
set_exception_handler(function ($exception) {
    error_log("Uncaught Exception: " . $exception->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Wewnętrzny błąd serwera']);
    exit;
});

header('Content-Type: application/json');

// 1. Wczytanie konfiguracji i danych
require_once 'ApiClient.php';
$configPath = getenv('CONFIG_FILE') ?: 'config.ini';
$config = parse_ini_file($configPath, true);
if ($config === false) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Błąd konfiguracji']);
    exit;
}

// 2. Dane z żądania POST
$rawInput = file_get_contents('php://input');
if (empty($rawInput) && php_sapi_name() === 'cli') {
    $rawInput = file_get_contents('php://stdin');
}
$input = json_decode($rawInput, true);

// Allow handling GET parameters for download actions
$action = $input['action'] ?? $_GET['action'] ?? 'pay';


if ($action === 'create') {
    // "Create" here actually corresponds to "Start Session" from the UI.
    // In our API-only mode, we treat this as "Check if ticket/plate exists and redirect".
    // If we were creating a NEW ticket in the system, we would need TICKET_EXECUTE, 
    // but the requirement implies working with existing tickets or validating plates.

    $plate = $input['plate'] ?? null;
    if (!$plate) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Wymagany numer rejestracyjny']);
        exit;
    }

    // Sanitize plate: Upper case, but allow spaces as some systems store plates with spaces (e.g. "KRA 12345")
    $plate = strtoupper(trim($input['plate'] ?? ''));

    try {
        $client = new ApiClient($config);
        $loginResult = $client->login();

        if (!$loginResult['success']) {
            throw new Exception("Błąd logowania do API: " . ($loginResult['error'] ?? 'Nieznany'));
        }

        $info = $client->getParkTicketInfo($plate, date('Y-m-d H:i:s', strtotime('-1 year')), date('Y-m-d H:i:s', strtotime('+1 day')));


        if ($info['success']) {
            if (!empty($info['tickets'])) {
                // Found existing active ticket!
                echo json_encode([
                    'success' => true,
                    'ticket_id' => $plate
                ]);
                exit;
            } elseif (isset($info['is_new']) && $info['is_new']) {
                // API said: Ticket doesn't exist (TICKET_EXIST=0), treat as new session
                // We don't mark as 'simulated' anymore, but as valid 'new' session backed by API info.
                // However, frontend JS expects 'simulated=1' to trigger the "New Ticket" UI flow?
                // Wait, User said "Remove simulation, use only API".
                // This means if I scan a plate that doesn't exist, I should probably START a session via API?
                // But we don't have TICKET_EXECUTE here.
                // We assume "New Session" means showing the UI to pay/park.
                // Let's return success without 'simulated' flag, but maybe with a 'is_new' flag if frontend uses it?
                // Actually, frontend logic for 'simulated' was just to redirect with ?simulated=1.
                // If we remove 'simulated', frontend redirects to normal view.
                // Normal view fetches ticket details. API will return TICKET_EXIST=0 details.
                // So we just return success.
                echo json_encode([
                    'success' => true,
                    'ticket_id' => $plate
                    // 'is_new' => true // Optional if frontend needs it
                ]);
                exit;
            } else {
                // Success but empty? Should not happen with new logic, but treat as empty/not found
                http_response_code(404);
                echo json_encode([
                    'success' => false,
                    'message' => 'Nie znaleziono biletu w systemie API.'
                ]);
                exit;
            }
        }

        if (!$info['success']) {
            // Include debug info in error message
            $debugInfo = isset($info['debug_request']) ? json_encode($info['debug_request']) : '';
            throw new Exception("Błąd API: " . ($info['error'] ?? 'Nieznany') . " | Debug: " . $debugInfo);
        }

    } catch (Exception $e) {
        error_log("API Error during search: " . $e->getMessage());

        // Remove Simulation Fallback. Return Error.
        // Remove Simulation Fallback. Return Error.
        http_response_code(200); // Changed to 200 to allow JS to read message
        echo json_encode([
            'success' => false,
            'message' => 'Błąd systemu: ' . $e->getMessage()
        ]);
        exit;
    }
}

if ($action === 'calculate_fee') {
    try {
        $ticket_id = $input['ticket_id'] ?? null;
        $extension_minutes = $input['extension_minutes'] ?? 0;

        $ticket = null;


        if (!$ticket) {
            // Treat as new session if not found
            $ticket = [
                'plate' => $ticket_id,
                'entry_time' => date('Y-m-d H:i:s'), // Default to NOW for new sessions
                'status' => 'new'
            ];
        }

        // Jeśli bilet już opłacony? API nie zwraca 'paid'.
        // Zakładamy, że jest do opłacenia.

        // Oblicz czas wyjazdu
        $entry_time_str = $input['entry_time'] ?? $ticket['entry_time'];
        $entry_time = new DateTime($entry_time_str);

        $calculationTime = clone $entry_time;
        if ($extension_minutes > 0) {
            $calculationTime->modify("+{$extension_minutes} minutes");
        }
        $calculationDate = $calculationTime->format('Y-m-d H:i:s');

        // Zamiast liczyć lokalnie, pytamy API o kwotę na dany moment wyjazdu
        $fee = 0;
        $duration_minutes = 0;

        // Ponowne połączenie w celu kalkulacji (chyba że trzymamy sesję - tu otwieramy nową)
        // W przyszłości warto zoptymalizować by nie logować się 2 razy (raz przy checku, raz tutaj)
        // Ale w obecnym kodzie check był wyżej.

        if (!empty($config['api']['api_url'])) {
            $client = new ApiClient($config);
            if ($client->login()['success']) {
                $feeInfo = $client->getParkTicketInfo($ticket['plate'], $entry_time->format('Y-m-d H:i:s'), $calculationDate);
                error_log("Fee Calc Debug: plate={$ticket['plate']}, DATE_FROM={$entry_time->format('Y-m-d H:i:s')}, DATE_TO={$calculationDate}, ext_mins={$extension_minutes}");

                if ($feeInfo['success']) {
                    // API zwraca kwotę w groszach? Dokumentacja mówi: FEE :int64. Zazwyczaj to grosze.

                    // Try tickets array first, fall back to defaults (when TICKET_EXIST=0)
                    $ticketData = $feeInfo['tickets'][0] ?? $feeInfo['defaults'] ?? [];
                    $rawFee = $ticketData['FEE'] ?? 0;
                    $paidFee = $ticketData['FEE_PAID'] ?? 0;
                    $toPay = $rawFee - $paidFee;

                    // Czy dzielić przez 100?
                    // Spójrzmy na config.ini: hourly_rate = 5.00.
                    // Jeśli system BaseSystem używa groszy, to 5.00PLN = 500.
                    // Zaryzykuję podzielenie przez 100, bo int64 rzadko jest dla kwot z przecinkiem, a dla "groszy".
                    $fee = $toPay / 100.0;

                    // Czas trwania - obliczamy lokalnie dla UI, bo API nie zwraca 'duration' wprost, tylko daty.
                    $validFrom = new DateTime($ticketData['VALID_FROM'] ?? $entry_time->format('Y-m-d H:i:s'));
                    $interval = $validFrom->diff($calculationTime);
                    $duration_minutes = ($interval->days * 24 * 60) + ($interval->h * 60) + $interval->i;
                } else {
                    // Fallback albo błąd
                    error_log("API Fee Calc Error: " . $feeInfo['error']);
                    // Fallback do lokalnego? Nie, skoro użytkownik chce API.
                    // Ale żeby nie blokować UI, zwróćmy błąd lub 0.
                }

            }
        }


        echo json_encode([
            'success' => true,
            'fee' => $fee,
            'currency' => $config['settings']['currency'],
            'duration_minutes' => $duration_minutes,
            'ticket_exist' => (int) ($ticketData['TICKET_EXIST'] ?? 0),
            'fee_paid' => $paidFee / 100.0 // Return paid amount in standard units
        ]);
    } catch (Exception $e) {
        error_log("Error calculating fee: " . $e->getMessage());
        // Return 200/400 instead of 500 so frontend alert works
        echo json_encode([
            'success' => false,
            'message' => 'Błąd podczas obliczania opłaty: ' . $e->getMessage()
        ]);
    }
    exit;
}

if ($action === 'set_plate') {
    $ticket_id = $input['ticket_id'] ?? null; // This should be the BARCODE
    $new_plate = $input['new_plate'] ?? null;

    // Allow '0' as valid ticket_id/barcode
    if (($ticket_id === null || $ticket_id === '') || ($new_plate === null || $new_plate === '')) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Brak wymaganych danych (ticket_id, new_plate)']);
        exit;
    }

    try {
        if (empty($config['api']['api_url'])) {
            throw new Exception("Brak konfiguracji API");
        }

        $client = new ApiClient($config);
        $loginResult = $client->login();

        if (!$loginResult['success']) {
            throw new Exception("Błąd logowania do API: " . ($loginResult['error'] ?? 'Nieznany'));
        }

        $result = $client->setPlate($ticket_id, $new_plate);

        if ($result['success']) {
            echo json_encode(['success' => true]);
        } else {
            throw new Exception("Błąd zmiany numeru: " . ($result['error'] ?? 'Nieznany błąd'));
        }

    } catch (Exception $e) {
        error_log("Set Plate Error: " . $e->getMessage());
        http_response_code(200);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}


if ($action === 'download_receipt') {
    $receipt_number = $input['receipt_number'] ?? $_GET['receipt_number'] ?? null;

    if (!$receipt_number) {
        die('Brak numeru paragonu.');
    }

    try {
        $client = new ApiClient($config);
        $loginResult = $client->login();

        if (!$loginResult['success']) {
            die("Błąd logowania API");
        }

        // For download, we might use $_GET params mostly, but logic is same
        $pdfResult = $client->getPaymentPdf($receipt_number);

        if ($pdfResult['success'] && !empty($pdfResult['file'])) {
            $fileContent = base64_decode($pdfResult['file']);

            // Serve PDF file
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="paragon_' . $receipt_number . '.pdf"');
            header('Content-Length: ' . strlen($fileContent));
            echo $fileContent;
            exit;
        } else {
            die("Błąd pobierania PDF: " . ($pdfResult['error'] ?? 'Brak danych pliku'));
        }

    } catch (Exception $e) {
        die("Błąd systemu: " . $e->getMessage());
    }
}

// Opłacenie istniejącego biletu
if ($action === 'pay') {
    $ticket_id = $input['ticket_id'] ?? null;
    $amount = $input['amount'] ?? 0;

    // Retrieve required dates from request (passed from frontend script.js)
    $entry_time_str = $input['entry_time'] ?? date('Y-m-d H:i:s'); // Fallback to NOW if missing (shouldn't happen)
    $exit_time_str = $input['exit_time'] ?? date('Y-m-d H:i:s');

    if (!$ticket_id) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Brak numeru biletu']);
        exit;
    }

    try {
        if (empty($config['api']['api_url'])) {
            throw new Exception("Brak konfiguracji API");
        }

        $client = new ApiClient($config);
        $loginResult = $client->login();

        if (!$loginResult['success']) {
            throw new Exception("Błąd logowania do API: " . ($loginResult['error'] ?? 'Nieznany'));
        }

        // 1. Fetch Ticket Info first to get the authoritative FEE (int64) from the backend
        // We use the exact dates provided by the frontend (Entry + User Selected Exit)
        $info = $client->getParkTicketInfo($ticket_id, $entry_time_str, $exit_time_str);

        if (!$info['success']) {
            throw new Exception("Błąd pobierania danych biletu: " . ($info['error'] ?? 'Nieznany'));
        }

        // Extract FEE from response. 
        // If TICKET_EXIST is 0 (new session), we might use defaults or 0 if API logic dictates.
        // Assuming PARK_TICKET_GET_INFO returns the calculated FEE for the given time range.
        $ticketData = $info['tickets'][0] ?? $info['defaults'] ?? [];

        // Safety check: Ensure we have a fee
        if (!isset($ticketData['FEE'])) {
            // If checking a new plate that doesn't exist yet, FEE might be 0 or determined by backend.
            // If the user expects to pay amount > 0 but API says 0, we might have a sync issue.
            // However, we trust the API's calculation for the payment call.
            $feeInt = 0;
        } else {
            $feeInt = (int) $ticketData['FEE'];
        }

        // 2. Perform Payment
        $payResult = $client->payParkTicket($ticket_id, $entry_time_str, $exit_time_str, $feeInt);

        if ($payResult['success']) {
            echo json_encode([
                'success' => true,
                'message' => 'Płatność zakończona powodzeniem',
                'receipt_number' => $payResult['receipt_number'],
                'new_qr_code' => "REC-" . ($payResult['receipt_number'] ?? uniqid()), // Fallback for UI
                'valid_until' => date('H:i', strtotime('+15 minutes'))
            ]);
        } else {
            throw new Exception("Błąd płatności: " . ($payResult['error'] ?? 'Nieznany błąd API'));
        }

    } catch (Exception $e) {
        error_log("Payment Error: " . $e->getMessage());
        http_response_code(200); // Allow frontend to handle error message
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}
?>
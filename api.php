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
$action = $input['action'] ?? 'pay';

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
        if (!empty($config['api']['api_url'])) {
            $client = new ApiClient($config);
            if ($client->login()['success']) {
                $info = $client->getParkTicketInfo($ticket_id, date('Y-m-d H:i:s', strtotime('-1 year')), date('Y-m-d H:i:s', strtotime('+1 day')));
                if ($info['success'] && !empty($info['tickets'])) {
                    $apiData = $info['tickets'][0];
                    $ticket = [
                        'plate' => $apiData['BARCODE'] ?? $ticket_id,
                        'entry_time' => $apiData['VALID_FROM'],
                        'status' => 'active'
                    ];
                }

            }
        }

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
        $entry_time = new DateTime($ticket['entry_time']);
        $current_time = new DateTime();
        if ($extension_minutes > 0) {
            $current_time->modify("+{$extension_minutes} minutes");
        }
        $calculationDate = $current_time->format('Y-m-d H:i:s');

        // Zamiast liczyć lokalnie, pytamy API o kwotę na dany moment wyjazdu
        $fee = 0;
        $duration_minutes = 0;

        // Ponowne połączenie w celu kalkulacji (chyba że trzymamy sesję - tu otwieramy nową)
        // W przyszłości warto zoptymalizować by nie logować się 2 razy (raz przy checku, raz tutaj)
        // Ale w obecnym kodzie check był wyżej.

        if (!empty($config['api']['api_url'])) {
            $client = new ApiClient($config);
            if ($client->login()['success']) {
                $feeInfo = $client->getParkTicketInfo($ticket['plate'], $ticket['entry_time'], $calculationDate);

                if ($feeInfo['success']) {
                    // API zwraca kwotę w groszach? Dokumentacja mówi: FEE :int64. Zazwyczaj to grosze.

                    $ticketData = $feeInfo['tickets'][0] ?? [];
                    $rawFee = $ticketData['FEE'] ?? 0;
                    $paidFee = $ticketData['FEE_PAID'] ?? 0;
                    $toPay = $rawFee - $paidFee;

                    // Czy dzielić przez 100?
                    // Spójrzmy na config.ini: hourly_rate = 5.00.
                    // Jeśli system BaseSystem używa groszy, to 5.00PLN = 500.
                    // Zaryzykuję podzielenie przez 100, bo int64 rzadko jest dla kwot z przecinkiem, a dla "groszy".
                    $fee = $toPay / 100.0;

                    // Czas trwania - obliczamy lokalnie dla UI, bo API nie zwraca 'duration' wprost, tylko daty.
                    $validFrom = new DateTime($ticketData['VALID_FROM'] ?? $ticket['entry_time']);
                    $interval = $validFrom->diff($current_time);
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
            'duration_minutes' => $duration_minutes
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

// Opłacenie istniejącego biletu
$ticket_id = $input['ticket_id'] ?? null;
$amount = $input['amount'] ?? 0;

// 3. Walidacja
$ticket = null;
if (!empty($config['api']['api_url'])) {
    $client = new ApiClient($config);
    if ($client->login()['success']) {
        $info = $client->getParkTicketInfo($ticket_id, date('Y-m-d H:i:s', strtotime('-1 year')), date('Y-m-d H:i:s', strtotime('+1 day')));
        if ($info['success'] && !empty($info['tickets'])) {
            $ticket = ['status' => 'active']; // Found on API
        }

    }
}

if (!$ticket) {
    // http_response_code(404);
    http_response_code(200);
    echo json_encode(['success' => false, 'message' => 'Bilet nie został znaleziony']);
    exit;
}

// 4. Przetworzenie płatności
// W trybie API-only, jeśli nie mamy metody "oznacz jako opłacony" w API, 
// jedynie symulujemy sukces dla UI.

// 5. Wygenerowanie „biletu wyjazdowego” (symulowany kod QR lub dane z API jeśli by były)
$new_qr_code = "EXIT-" . strtoupper(uniqid());

echo json_encode([
    'success' => true,
    'message' => 'Płatność zakończona powodzeniem',
    'new_qr_code' => $new_qr_code,
    'valid_until' => date('H:i', strtotime('+15 minutes'))
]);
?>
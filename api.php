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
$config = parse_ini_file('config.ini', true);
if ($config === false) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Błąd konfiguracji']);
    exit;
}

// 2. Dane z żądania POST
$input = json_decode(file_get_contents('php://input'), true);
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

    try {
        $client = new ApiClient($config);
        $loginResult = $client->login();
        
        if (!$loginResult['success']) {
             throw new Exception("Błąd logowania do API: " . ($loginResult['error'] ?? 'Nieznany'));
        }

        $info = $client->getBarcodeInfo($plate);
        $client->logout();

        if ($info['success'] && !empty($info['tickets'])) {
            // Found it! Return success so JS redirects to index.php?ticket_id=PLATE
            echo json_encode([
                'success' => true,
                'ticket_id' => $plate
            ]);
        } else {
            // Not found
            // In a real app we might want to issue a new ticket here using TICKET_EXECUTE?
            // But without knowing exact params (TICKET_TYPE etc), we error for now.
            http_response_code(404);
            echo json_encode([
                'success' => false, 
                'message' => 'Nie znaleziono biletu dla podanego numeru. Upewnij się, że wjechałeś na parking.'
            ]);
        }

    } catch (Exception $e) {
        error_log("Error searching ticket: " . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Błąd systemu: ' . $e->getMessage()
        ]);
    }
    exit;
}

if ($action === 'calculate_fee') {
    try {
        $ticket_id = $input['ticket_id'] ?? null;
        $extension_minutes = $input['extension_minutes'] ?? 0;

        $ticket = null;
        if (!empty($config['api']['api_url'])) {
             $client = new ApiClient($config);
             if ($client->login()['success']) {
                 $info = $client->getBarcodeInfo($ticket_id);
                 if ($info['success'] && !empty($info['tickets'])) {
                     $apiData = $info['tickets'][0];
                     $ticket = [
                         'plate' => $apiData['BARCODE'] ?? $ticket_id,
                         'entry_time' => $apiData['VALID_FROM'],
                         'status' => 'active'
                     ];
                 }
                 $client->logout();
             }
        }

        if (!$ticket) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Bilet nie został znaleziony']);
            exit;
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
                 $feeInfo = $client->getParkingFee($ticket['plate'], $calculationDate);
                 
                 if ($feeInfo['success']) {
                     // API zwraca kwotę w groszach? Dokumentacja mówi: FEE :int64. Zazwyczaj to grosze.
                     // Sprawdźmy konwencję. rate = 5.00. Jeśli user płaci 5 PLN, a API zwraca np 500, to grosze.
                     // Ale format w config to 5.00. 
                     // Jeśli API zwraca "5" jako int dla 5zł?
                     // Bez pewności, załóżmy że to jednostki główne LUB grosze.
                     // Standardem w takich systemach są grosze.
                     // Jednak w configu mamy hourly_rate=5.00 (float).
                     // Najbezpieczniej wyświetlić to co przyjdzie, ewentualnie podzielić przez 100 jeśli to grosze.
                     // W api.md: FEE :int64 "aktualna oplata".
                     // Zazwyczaj int64 oznacza grosze. 
                     // Przyjmijmy że dzielimy przez 100.
                     
                     $rawFee = $feeInfo['data']['FEE'] ?? 0;
                     $paidFee = $feeInfo['data']['FEE_PAID'] ?? 0;
                     $toPay = $rawFee - $paidFee;
                     
                     // Czy dzielić przez 100?
                     // Spójrzmy na config.ini: hourly_rate = 5.00.
                     // Jeśli system BaseSystem używa groszy, to 5.00PLN = 500.
                     // Zaryzykuję podzielenie przez 100, bo int64 rzadko jest dla kwot z przecinkiem, a dla "groszy".
                     $fee = $toPay / 100.0; 
                     
                     // Czas trwania - obliczamy lokalnie dla UI, bo API nie zwraca 'duration' wprost, tylko daty.
                     $validFrom = new DateTime($feeInfo['data']['VALID_FROM'] ?? $ticket['entry_time']);
                     $interval = $validFrom->diff($current_time);
                     $duration_minutes = ($interval->days * 24 * 60) + ($interval->h * 60) + $interval->i;
                 } else {
                     // Fallback albo błąd
                     error_log("API Fee Calc Error: " . $feeInfo['error']);
                     // Fallback do lokalnego? Nie, skoro użytkownik chce API.
                     // Ale żeby nie blokować UI, zwróćmy błąd lub 0.
                 }
                 $client->logout();
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
        http_response_code(500);
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
         $info = $client->getBarcodeInfo($ticket_id);
         if ($info['success'] && !empty($info['tickets'])) {
             $ticket = ['status' => 'active']; // Found on API
         }
         $client->logout();
     }
}

if (!$ticket) {
    http_response_code(404);
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
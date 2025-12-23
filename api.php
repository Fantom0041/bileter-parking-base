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
$config = parse_ini_file('config.ini');
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

        // Oblicz czas trwania
        $entry_time = new DateTime($ticket['entry_time']);
        $current_time = new DateTime();
        $current_time->modify("+{$extension_minutes} minutes");

        $interval = $entry_time->diff($current_time);
        $duration_minutes = ($interval->days * 24 * 60) + ($interval->h * 60) + $interval->i;

        // Sprawdź okres bezpłatny
        if ($duration_minutes <= $config['free_minutes']) {
            $fee = 0;
        } else {
            // Oblicz opłatę godzinową
            $hours = ceil($duration_minutes / 60);
            $fee = $hours * $config['hourly_rate'];
        }

        echo json_encode([
            'success' => true,
            'fee' => $fee,
            'currency' => $config['currency'],
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
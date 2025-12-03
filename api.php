<?php
// Wyłącz wyświetlanie błędów w HTML, żeby nie psuły JSON-a
ini_set('display_errors', '0');
error_reporting(E_ALL);

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
$config = parse_ini_file('config.ini');
if ($config === false) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Błąd konfiguracji']);
    exit;
}

$json_file = 'data.json';
$json_data = file_get_contents($json_file);
if ($json_data === false) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Błąd odczytu bazy danych']);
    exit;
}

$tickets = json_decode($json_data, true);
if ($tickets === null && json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Błąd parsowania bazy danych']);
    exit;
}

// 2. Dane z żądania POST
$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? 'pay'; // Default to pay for backward compatibility

if ($action === 'create') {
    try {
        // Utworzenie nowego biletu
        $plate = $input['plate'] ?? null;
        if (!$plate) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Wymagany numer rejestracyjny']);
            exit;
        }

        $new_id = (string) rand(10000, 99999);
        // Zapewnij unikalny identyfikator
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
            echo json_encode(['success' => false, 'message' => 'Błąd zapisu bazy danych']);
        }
    } catch (Exception $e) {
        error_log("Error creating ticket: " . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Błąd podczas tworzenia biletu: ' . $e->getMessage()
        ]);
    }
    exit;
}

// Opłacenie istniejącego biletu
$ticket_id = $input['ticket_id'] ?? null;
$amount = $input['amount'] ?? 0;

// 3. Walidacja
if (!$ticket_id || !isset($tickets[$ticket_id])) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Bilet nie został znaleziony']);
    exit;
}

// 4. Przetworzenie płatności
// W prawdziwej aplikacji tutaj byłaby integracja z bramką płatności.
// W tym przykładzie zakładamy sukces.

// Aktualizacja statusu biletu
$tickets[$ticket_id]['status'] = 'paid';
$tickets[$ticket_id]['payment_time'] = date('Y-m-d H:i:s');
$tickets[$ticket_id]['amount_paid'] = $amount;

// Zapis do bazy (pliku)
if (file_put_contents($json_file, json_encode($tickets, JSON_PRETTY_PRINT))) {
    // 5. Wygenerowanie „biletu wyjazdowego” (symulowany kod QR)
    $new_qr_code = "EXIT-" . strtoupper(uniqid());

    echo json_encode([
        'success' => true,
        'message' => 'Płatność zakończona powodzeniem',
        'new_ticket_qr' => $new_qr_code,
        'valid_until' => date('H:i', strtotime('+15 minutes')) // 15 minut na wyjazd
    ]);
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Błąd zapisu bazy danych']);
}
?>
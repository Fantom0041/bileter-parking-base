<?php
// Skrypt odbierający potwierdzenie płatności (URLC) z Dotpay / Przelewy24

// 1. Logowanie błędów i konfiguracja
ini_set('display_errors', '0');
error_reporting(E_ALL);
date_default_timezone_set('Europe/Warsaw');

// Logowanie do pliku
function logUrlc($msg) {
    file_put_contents('urlc.log', '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n", FILE_APPEND);
}

logUrlc("Konfiguracja URLC start...");

// 2. Wczytanie konfiguracji
$config = parse_ini_file('config.ini', true);
if ($config === false) {
    logUrlc("FATAL: Nie można wczytać config.ini");
    die("Error configuration");
}

require_once 'ApiClient.php';

// 3. Odbiór danych POST
$postData = $_POST;
if (empty($postData)) {
    logUrlc("Brak danych POST. Koniec.");
    die("No data");
}

logUrlc("Otrzymano dane: " . json_encode($postData));

// 4. Weryfikacja podpisu
$pin = $config['dotpay']['shop_pin'];
$id = $postData['id'] ?? '';
$operation_number = $postData['operation_number'] ?? '';
$operation_type = $postData['operation_type'] ?? '';
$operation_status = $postData['operation_status'] ?? '';
$operation_amount = $postData['operation_amount'] ?? '';
$operation_currency = $postData['operation_currency'] ?? '';
$operation_withdrawal_amount = $postData['operation_withdrawal_amount'] ?? '';
$control = $postData['control'] ?? '';
$description = $postData['description'] ?? '';
$email = $postData['email'] ?? '';
$p_info = $postData['p_info'] ?? '';
$p_email = $postData['p_email'] ?? '';
$channel = $postData['channel'] ?? '';
$channel_country = $postData['channel_country'] ?? '';
$geoip_country = $postData['geoip_country'] ?? '';
$signature = $postData['signature'] ?? '';

// Konstrukcja podpisu do weryfikacji zgodnie z dokumentacją URLC Dotpay
// PIN + id + operation_number + operation_type + operation_status + operation_amount + operation_currency + ...
// UWAGA: Dotpay ma kilka wersji. Standardowa concat:
// PIN + id + operation_number + operation_type + operation_status + operation_amount + operation_currency + operation_withdrawal_amount + control + description + email + p_info + p_email + channel + channel_country + geoip_country

$signString = $pin . 
              $id . 
              $operation_number . 
              $operation_type . 
              $operation_status . 
              $operation_amount . 
              $operation_currency . 
              $operation_withdrawal_amount . 
              $control . 
              $description . 
              $email . 
              $p_info . 
              $p_email . 
              $channel . 
              $channel_country . 
              $geoip_country;

$calcSignature = hash('sha256', $signString);

if ($calcSignature !== $signature) {
    logUrlc("BŁĄD: Podpis się nie zgadza! Otrzymany: $signature, Obliczony: $calcSignature");
    // W środowisku testowym czasem ignorujemy podpis jeśli implementacja hashowania jest inna, ale tutaj to krytyczne.
    // Jeśli nie działa, to znaczy że kolejność pól jest inna.
    // Dajemy die, ale Dotpay ponowi.
    die("Signature mismatch");
}

// 5. Sprawdzenie statusu
// operation_status: completed, rejected
if ($operation_status !== 'completed') {
    logUrlc("Status transakcji: $operation_status. Ignoruję.");
    echo "OK";
    exit;
}

// 6. Rozkodowanie control (TicketID | ExitTime)
$parts = explode('|', $control);
$ticket_id = $parts[0];
$exit_time_ts = isset($parts[1]) ? (int)$parts[1] : time();
$exit_time = date('Y-m-d H:i:s', $exit_time_ts);

logUrlc("Transakcja zaakceptowana dla biletu: $ticket_id, Wyjazd do: $exit_time, Kwota: $operation_amount");

// 7. Wykonanie akcji w systemie parkingowym (ApiClient)
try {
    $client = new ApiClient($config);
    $loginResult = $client->login();
    
    if (!$loginResult['success']) {
        throw new Exception("Błąd logowania do API Parkingu: " . $loginResult['error']);
    }
    
    // Potrzebujemy daty wjazdu. Pobierzmy info o bilecie.
    $info = $client->getParkTicketInfo($ticket_id);
    if (!$info['success'] || empty($info['tickets'])) {
        throw new Exception("Nie znaleziono biletu $ticket_id w systemie.");
    }
    
    $ticketData = $info['tickets'][0];
    $entry_time = $ticketData['VALID_FROM']; // Data wjazdu
    
    // Wykonaj "payParkTicket"
    // Konwersja kwoty: Dotpay amount string "10.00" -> int (grosze) ?
    // ApiClient spodziewa się int64 fee. Jeśli system to grosze, to * 100.
    // Config: hourly_rate = 5.00.
    // Przyjęliśmy wcześniej, że ApiClient fee to grosze (int64).
    $feeInt = (int) (floatval($operation_amount) * 100);
    
    $payResult = $client->payParkTicket($ticket_id, $entry_time, $exit_time, $feeInt);
    
    $client->logout();
    
    if ($payResult['success']) {
        logUrlc("SUKCES: Bilet $ticket_id opłacony w systemie. Receipt: " . ($payResult['receipt_number'] ?? 'brak'));
        echo "OK";
    } else {
        throw new Exception("Błąd metody payParkTicket: " . $payResult['error']);
    }

} catch (Exception $e) {
    logUrlc("WYJĄTEK: " . $e->getMessage());
    // Nie zwracamy OK, żeby Dotpay ponowił próbę (np. gdy API parkingu leży)
    die("Error processing");
}
?>

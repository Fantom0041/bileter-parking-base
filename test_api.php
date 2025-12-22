<?php
/**
 * Test logowania do API
 */

require_once 'ApiClient.php';

// Wczytaj konfigurację
$config = parse_ini_file('config.ini', true);

// Utwórz klienta API
$api = new ApiClient($config);

echo "=== TEST LOGOWANIA DO API ===\n\n";

// Test 1: Logowanie
echo "1. Logowanie do systemu...\n";
$loginResult = $api->login();

if ($loginResult['success']) {
    echo "✓ Logowanie udane!\n";
    echo "  LOGIN_ID: " . $loginResult['login_id'] . "\n";
    
    if (isset($loginResult['user']['LOGIN'])) {
        echo "  Użytkownik: " . $loginResult['user']['LOGIN'] . "\n";
    }
    if (isset($loginResult['user']['NAME'])) {
        echo "  Imię i nazwisko: " . $loginResult['user']['NAME'] . "\n";
    }
    echo "\n";
    
    // Test 2: Heart Beat
    echo "2. Podtrzymanie połączenia (HEART_BEAT)...\n";
    $heartBeatResult = $api->heartBeat();
    
    if ($heartBeatResult['success']) {
        echo "✓ HEART_BEAT udany!\n\n";
    } else {
        echo "✗ HEART_BEAT nieudany: " . $heartBeatResult['error'] . "\n\n";
    }
    
    // Test 3: Pobranie informacji o bilecie (przykład)
    echo "3. Pobieranie informacji o bilecie (przykładowy kod: TEST123)...\n";
    $barcodeResult = $api->getBarcodeInfo('TEST123');
    
    if ($barcodeResult['success']) {
        echo "✓ Pobranie informacji udane!\n";
        echo "  Liczba biletów: " . count($barcodeResult['tickets']) . "\n";
        echo "  Liczba szafek: " . count($barcodeResult['lockers']) . "\n";
        
        if (!empty($barcodeResult['tickets'])) {
            echo "\n  Bilety:\n";
            foreach ($barcodeResult['tickets'] as $ticket) {
                echo "    - " . ($ticket['TICKET_NAME'] ?? 'Brak nazwy') . "\n";
                echo "      Ważny od: " . ($ticket['VALID_FROM'] ?? 'N/A') . "\n";
                echo "      Ważny do: " . ($ticket['VALID_TO'] ?? 'N/A') . "\n";
            }
        }
        echo "\n";
    } else {
        echo "✗ Pobranie informacji nieudane: " . $barcodeResult['error'] . "\n\n";
    }
    
    // Test 4: Wylogowanie
    echo "4. Wylogowanie z systemu...\n";
    $logoutResult = $api->logout();
    
    if ($logoutResult['success']) {
        echo "✓ Wylogowanie udane!\n\n";
    } else {
        echo "✗ Wylogowanie nieudane: " . $logoutResult['error'] . "\n\n";
    }
    
} else {
    echo "✗ Logowanie nieudane!\n";
    echo "  Błąd: " . $loginResult['error'] . "\n";
    if (isset($loginResult['status'])) {
        echo "  Status: " . $loginResult['status'] . "\n";
    }
    echo "\n";
}

echo "=== KONIEC TESTU ===\n";

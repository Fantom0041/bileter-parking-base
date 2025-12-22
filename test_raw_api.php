<?php
/**
 * Test surowego połączenia z API przez TCP socket
 */

$host = '172.16.2.51';
$port = 2332;

echo "=== TEST SUROWEGO POŁĄCZENIA TCP ===\n\n";

// Przygotuj żądanie LOGIN w formacie JSON
$request = [
    'METHOD' => 'LOGIN',
    'ORDER_ID' => 1,
    'LOGIN_ID' => '',
    'LOGIN' => 'kasjer',
    'PIN' => '',
    'PASSWORD' => 'kasjer',
    'DEVICE_ID' => 1,
    'IP' => '192.168.1.100',
    'NOENCODE' => 1,
    'ENTITY_ID' => 1
];

$jsonRequest = json_encode($request);

echo "Łączenie z $host:$port...\n";

// Otwórz socket TCP
$socket = @fsockopen($host, $port, $errno, $errstr, 10);

if (!$socket) {
    echo "✗ Błąd połączenia: $errstr ($errno)\n";
    exit(1);
}

echo "✓ Połączono!\n\n";

echo "Wysyłanie żądania LOGIN:\n";
echo $jsonRequest . "\n\n";

// Wyślij żądanie JSON + nowa linia
fwrite($socket, $jsonRequest . "\n");

echo "Oczekiwanie na odpowiedź...\n";

// Ustaw timeout na odczyt
stream_set_timeout($socket, 5);

// Odczytaj odpowiedź
$response = '';
while (!feof($socket)) {
    $line = fgets($socket, 4096);
    if ($line === false) break;
    $response .= $line;
    
    // Jeśli mamy kompletny JSON, przerwij
    if (json_decode($response) !== null) {
        break;
    }
}

fclose($socket);

echo "\nOdpowiedź:\n";
echo $response . "\n\n";

// Spróbuj zdekodować JSON
$decoded = json_decode($response, true);

if ($decoded !== null) {
    echo "✓ Poprawny JSON!\n";
    echo "STATUS: " . ($decoded['STATUS'] ?? 'brak') . "\n";
    
    if (isset($decoded['LOGIN_ID'])) {
        echo "LOGIN_ID: " . $decoded['LOGIN_ID'] . "\n";
    }
    
    if (isset($decoded['USER'])) {
        echo "USER: " . json_encode($decoded['USER']) . "\n";
    }
} else {
    echo "✗ Niepoprawny JSON lub brak odpowiedzi\n";
}

echo "\n=== KONIEC TESTU ===\n";

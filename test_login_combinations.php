<?php
/**
 * Test różnych kombinacji logowania
 */

$host = '172.16.2.51';
$port = 2332;

echo "=== TEST RÓŻNYCH KOMBINACJI LOGOWANIA ===\n\n";

// Kombinacje do przetestowania
$tests = [
    [
        'name' => 'LOGIN + PASSWORD (bez PIN)',
        'data' => [
            'METHOD' => 'LOGIN',
            'ORDER_ID' => 1,
            'LOGIN_ID' => '',
            'LOGIN' => 'kasjer',
            'PASSWORD' => 'kasjer',
            'DEVICE_ID' => 1,
            'IP' => '192.168.1.100',
            'NOENCODE' => 1,
            'ENTITY_ID' => 1
        ]
    ],
    [
        'name' => 'Tylko PIN (bez LOGIN i PASSWORD)',
        'data' => [
            'METHOD' => 'LOGIN',
            'ORDER_ID' => 2,
            'LOGIN_ID' => '',
            'PIN' => 'kasjer',
            'DEVICE_ID' => 1,
            'IP' => '192.168.1.100',
            'NOENCODE' => 1,
            'ENTITY_ID' => 1
        ]
    ],
    [
        'name' => 'LOGIN + PIN (bez PASSWORD)',
        'data' => [
            'METHOD' => 'LOGIN',
            'ORDER_ID' => 3,
            'LOGIN_ID' => '',
            'LOGIN' => 'kasjer',
            'PIN' => 'kasjer',
            'DEVICE_ID' => 1,
            'IP' => '192.168.1.100',
            'NOENCODE' => 1,
            'ENTITY_ID' => 1
        ]
    ],
];

foreach ($tests as $test) {
    echo "Test: " . $test['name'] . "\n";
    echo str_repeat('-', 50) . "\n";
    
    $jsonRequest = json_encode($test['data']);
    
    // Otwórz socket TCP
    $socket = @fsockopen($host, $port, $errno, $errstr, 5);
    
    if (!$socket) {
        echo "✗ Błąd połączenia: $errstr ($errno)\n\n";
        continue;
    }
    
    // Wyślij żądanie
    fwrite($socket, $jsonRequest . "\n");
    
    // Ustaw timeout
    stream_set_timeout($socket, 5);
    
    // Odczytaj odpowiedź
    $response = '';
    while (!feof($socket)) {
        $line = fgets($socket, 4096);
        if ($line === false) break;
        $response .= $line;
        
        if (json_decode($response) !== null) {
            break;
        }
    }
    
    fclose($socket);
    
    $decoded = json_decode($response, true);
    
    if ($decoded !== null) {
        $status = $decoded['STATUS'] ?? 'brak';
        
        if ($status == 0) {
            echo "✓ SUKCES! Logowanie udane!\n";
            echo "  LOGIN_ID: " . ($decoded['LOGIN_ID'] ?? 'brak') . "\n";
            if (isset($decoded['USER'])) {
                echo "  USER: " . json_encode($decoded['USER']) . "\n";
            }
        } else {
            echo "✗ Błąd: STATUS = $status\n";
            echo "  DESC: " . ($decoded['DESC'] ?? 'brak') . "\n";
        }
    } else {
        echo "✗ Niepoprawna odpowiedź\n";
    }
    
    echo "\n";
}

echo "=== KONIEC TESTÓW ===\n";

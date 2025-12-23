<?php

/**
 * Klasa do komunikacji z zewnętrznym API systemu biletowego
 */
class ApiClient {
    private $apiUrl;
    private $config;
    private $loginId = null;
    private $orderIdCounter = 1;

    public function __construct($config) {
        $this->config = $config;
        $this->apiUrl = $config['api']['api_url'];
    }

    /**
     * Logowanie do systemu API
     * @return array ['success' => bool, 'login_id' => string, 'user' => array, 'error' => string]
     */
    public function login() {
        $login = $this->config['api']['api_login'];
        $pin = $this->config['api']['api_pin'];
        $password = $this->config['api']['api_password'];
        $deviceId = (int)$this->config['api']['device_id'];
        $deviceIp = $this->config['api']['device_ip'];
        $entityId = (int)$this->config['api']['entity_id'];

     
        $request = [
            'METHOD' => 'LOGIN',
            'ORDER_ID' => $this->getNextOrderId(),
            'LOGIN_ID' => '', 
            'LOGIN' => $login,
            'PIN' => $pin,
            'PASSWORD' => !empty($password) ? sha1($password) : '',
            'DEVICE_ID' => $deviceId,
            'IP' => $deviceIp,
            'NOENCODE' => 1,
            'ENTITY_ID' => $entityId
        ];

        // Wyślij żądanie
        $response = $this->sendRequest($request);

        if ($response === false) {
            return [
                'success' => false,
                'error' => 'Błąd połączenia z API'
            ];
        }

        // Sprawdź status odpowiedzi
        if (isset($response['STATUS']) && $response['STATUS'] == 0) {
           
            $this->loginId = $response['LOGIN_ID'];
            
            return [
                'success' => true,
                'login_id' => $response['LOGIN_ID'],
                'user' => $response['USER'] ?? []
            ];
        } else {
            return [
                'success' => false,
                'error' => $this->getErrorMessage($response['STATUS'] ?? -999),
                'status' => $response['STATUS'] ?? -999
            ];
        }
    }

    /**
     * Podtrzymanie połączenia (HEART_BEAT)
     * @return array ['success' => bool, 'error' => string]
     */
    public function heartBeat() {
        if (!$this->loginId) {
            return ['success' => false, 'error' => 'Nie zalogowano'];
        }

        $request = [
            'METHOD' => 'HEART_BEAT',
            'ORDER_ID' => $this->getNextOrderId(),
            'LOGIN_ID' => $this->loginId
        ];

        $response = $this->sendRequest($request);

        if ($response === false) {
            return ['success' => false, 'error' => 'Błąd połączenia z API'];
        }

        if (isset($response['STATUS']) && $response['STATUS'] == 0) {
            return ['success' => true];
        } else {
            return [
                'success' => false,
                'error' => $this->getErrorMessage($response['STATUS'] ?? -999)
            ];
        }
    }

    /**
     * Wylogowanie z systemu
     * @return array ['success' => bool, 'error' => string]
     */
    public function logout() {
        if (!$this->loginId) {
            return ['success' => false, 'error' => 'Nie zalogowano'];
        }

        $request = [
            'METHOD' => 'LOGOUT',
            'ORDER_ID' => $this->getNextOrderId(),
            'LOGIN_ID' => $this->loginId
        ];

        $response = $this->sendRequest($request);

        if ($response === false) {
            return ['success' => false, 'error' => 'Błąd połączenia z API'];
        }

        if (isset($response['STATUS']) && $response['STATUS'] == 0) {
            $this->loginId = null;
            return ['success' => true];
        } else {
            return [
                'success' => false,
                'error' => $this->getErrorMessage($response['STATUS'] ?? -999)
            ];
        }
    }

    /**
     * Pobranie informacji o biletach dla podanego kodu kreskowego
     * @param string $barcode Kod kreskowy/numer karty
     * @return array ['success' => bool, 'tickets' => array, 'lockers' => array, 'error' => string]
     */
    /**
     * Pobranie informacji o opłacie parkingowej
     * @param string $barcode Numer rejestracyjny/kod kreskowy
     * @param string $date Data rozliczenia (Y-m-d H:i:s)
     * @return array
     */
    /**
     * Pobranie informacji o opłacie parkingowej
     * @param string $barcode Numer rejestracyjny/kod kreskowy
     * @param string $date Data rozliczenia (Y-m-d H:i:s)
     * @return array
     */
    public function getParkingFee($barcode, $date) {
        if (!$this->loginId) {
            return ['success' => false, 'error' => 'Nie zalogowano'];
        }

        $request = [
            'METHOD' => 'PARK_TICKET_GET_INFO',
            'ORDER_ID' => $this->getNextOrderId(),
            'LOGIN_ID' => $this->loginId,
            'BARCODE' => $barcode,
            'DATE_FROM' => $date, // For fee calculation, check at specific time
            'DATE_TO' => $date,   // API doc says "from - to". If calculating fee for a moment, likely same or from entry? 
                                  // Doc says: "Date from (load current date identical to date to) - so if no ticket, returns default."
                                  // For fee calculation, usually we provide the "Exit Time".
                                  // Since doc is sparse on "Calculate Fee at X time", and says "FEE: current fee",
                                  // we likely just query for the status "NOW" (at exit time).
                                  // Let's pass $date for both as the point of truth.
            'DEVICE_ID' => (int)$this->config['api']['device_id'],
            'ENTITY_ID' => (int)$this->config['api']['entity_id']
        ];

        $response = $this->sendRequest($request);

        if ($response === false) {
            return ['success' => false, 'error' => 'Błąd połączenia z API'];
        }

        if (isset($response['STATUS']) && $response['STATUS'] == 0) {
            return [
                'success' => true,
                'data' => $response
            ];
        } else {
            return [
                'success' => false,
                'error' => $this->getErrorMessage($response['STATUS'] ?? -999)
            ];
        }
    }

    /**
     * Pobranie informacji o biletach dla podanego kodu kreskowego
     * @param string $barcode Kod kreskowy/numer karty
     * @return array ['success' => bool, 'tickets' => array, 'lockers' => array, 'error' => string]
     */
    /**
     * Pobranie informacji o biletach dla podanego kodu kreskowego
     * @param string $barcode Kod kreskowy/numer karty
     * @return array ['success' => bool, 'tickets' => array, 'lockers' => array, 'error' => string]
     */
    public function getBarcodeInfo($barcode) {
        if (!$this->loginId) {
            return ['success' => false, 'error' => 'Nie zalogowano'];
        }

        // Use PARK_TICKET_GET_INFO as BARCODE_INFO is not in docs
        $request = [
            'METHOD' => 'PARK_TICKET_GET_INFO',
            'ORDER_ID' => $this->getNextOrderId(),
            'LOGIN_ID' => $this->loginId,
            'BARCODE' => $barcode,
            'DATE_FROM' => date('Y-m-d H:i:s'), 
            'DATE_TO' => date('Y-m-d H:i:s')
        ];

        $response = $this->sendRequest($request);

        if ($response === false) {
            return ['success' => false, 'error' => 'Błąd połączenia z API'];
        }

        if (isset($response['STATUS']) && $response['STATUS'] == 0) {
            // Map PARK_TICKET_GET_INFO response to expected 'tickets' structure
            // The response has flat fields: FEE, REGISTRATION_NUMBER, TICKET_ID etc.
            // We wrap it in a single item array to match previous structure
            $ticketData = [
                'BARCODE' => $response['REGISTRATION_NUMBER'] ?? $barcode,
                'VALID_FROM' => $response['VALID_FROM'] ?? null,
                'VALID_TO' => $response['VALID_TO'] ?? null,
                'FEE' => $response['FEE'] ?? 0,
                // Add any other fields needed
            ];
            
            return [
                'success' => true,
                'tickets' => [$ticketData], // Wrap in array as index.php expects tickets[0]
                'lockers' => []
            ];
        } else {
            return [
                'success' => false,
                'error' => $this->getErrorMessage($response['STATUS'] ?? -999),
                'debug_request' => $request
            ];
        }
    }

    /**
     
     * @param array $data Dane żądania
     * @return array|false Odpowiedź z API lub false w przypadku błędu
     */
    private function sendRequest($data) {
        // Parsuj URL aby wyciągnąć host i port
        $urlParts = parse_url($this->apiUrl);
        $host = $urlParts['host'] ?? 'localhost';
        $port = $urlParts['port'] ?? 80;
        
        $jsonRequest = json_encode($data);
        
      
        $socket = @fsockopen($host, $port, $errno, $errstr, 10);
        
        if (!$socket) {
            error_log("API Socket Error: $errstr ($errno)");
            return false;
        }
        
        // Wyślij żądanie JSON + nowa linia
        fwrite($socket, $jsonRequest . "\n");
        
        // Ustaw timeout na odczyt
        stream_set_timeout($socket, 10);
        
        // Odczytaj odpowiedź
        $response = '';
        while (!feof($socket)) {
            $line = fgets($socket, 4096);
            if ($line === false) break;
            $response .= $line;
            
            // Jeśli mamy kompletny JSON, przerwij
            $decoded = json_decode($response, true);
            if ($decoded !== null) {
                break;
            }
        }
        
        fclose($socket);
        
        if (empty($response)) {
            error_log("API Error: Empty response");
            return false;
        }
        
        $decoded = json_decode($response, true);
        
        if ($decoded === null) {
            error_log("API Error: Invalid JSON response: " . $response);
            return false;
        }
        
        return $decoded;
    }

    /**
     * Pobierz następny numer rozkazu
     * @return int
     */
    private function getNextOrderId() {
        return $this->orderIdCounter++;
    }

    /**
     * Pobierz komunikat błędu na podstawie kodu statusu
     * @param int $status Kod statusu
     * @return string Komunikat błędu
     */
    private function getErrorMessage($status) {
        $errors = [
            -1000 => 'Błąd bazy danych',
            -13 => 'Użytkownik nieautoryzowany',
            -12 => 'Szafka zajęta',
            -11 => 'Nieznana szatnia (autonr)',
            -10 => 'Nieznana szafka (numer)',
            -9 => 'Nieznana szafka (autonr)',
            -8 => 'Błąd połączenia',
            -7 => 'Timeout',
            -6 => 'Brak uprawnień',
            -5 => 'Nieobsługiwana operacja',
            -4 => 'Brak numeru karty',
            -3 => 'Nieprawidłowe dane',
            -2 => 'Kolizja biletów',
            -1 => 'Powtórny rozkaz',
            0 => 'OK',
            1 => 'Przyjęte do realizacji',
            2 => 'Wydrukowane',
            3 => 'Wysłane',
            5 => 'Przyjęte przez automat',
            6 => 'Zrealizowane przez automat',
            7 => 'Wybierz towar dodatkowy',
            8 => 'Bilet zablokowany'
        ];

        return $errors[$status] ?? "Nieznany błąd ($status)";
    }

    /**
     * Sprawdź czy zalogowano
     * @return bool
     */
    public function isLoggedIn() {
        return $this->loginId !== null;
    }

    /**
     * Pobierz LOGIN_ID
     * @return string|null
     */
    public function getLoginId() {
        return $this->loginId;
    }
}

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

        // Przygotuj dane rozkazu LOGIN
        $request = [
            'METHOD' => 'LOGIN',
            'ORDER_ID' => $this->getNextOrderId(),
            'LOGIN_ID' => '', // Ignorowane w LOGIN
            'LOGIN' => $login,
            'PIN' => $pin,
            'PASSWORD' => $password,
            'DEVICE_ID' => $deviceId,
            'IP' => $deviceIp,
            'NOENCODE' => 1, // Nie kodujemy SHA1
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
        if (isset($response['STATUS']) && $response['STATUS'] === 0) {
            // StatusOk = 0
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

        if (isset($response['STATUS']) && $response['STATUS'] === 0) {
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

        if (isset($response['STATUS']) && $response['STATUS'] === 0) {
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
    public function getBarcodeInfo($barcode) {
        if (!$this->loginId) {
            return ['success' => false, 'error' => 'Nie zalogowano'];
        }

        $request = [
            'METHOD' => 'BARCODE_INFO',
            'ORDER_ID' => $this->getNextOrderId(),
            'LOGIN_ID' => $this->loginId,
            'BARCODE' => $barcode
        ];

        $response = $this->sendRequest($request);

        if ($response === false) {
            return ['success' => false, 'error' => 'Błąd połączenia z API'];
        }

        if (isset($response['STATUS']) && $response['STATUS'] === 0) {
            return [
                'success' => true,
                'tickets' => $response['TICKETS'] ?? [],
                'lockers' => $response['LOCKERS'] ?? []
            ];
        } else {
            return [
                'success' => false,
                'error' => $this->getErrorMessage($response['STATUS'] ?? -999)
            ];
        }
    }

    /**
     * Wysłanie żądania do API
     * @param array $data Dane żądania
     * @return array|false Odpowiedź z API lub false w przypadku błędu
     */
    private function sendRequest($data) {
        $ch = curl_init($this->apiUrl);
        
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Accept: application/json'
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        
        curl_close($ch);

        if ($response === false || $httpCode !== 200) {
            error_log("API Error: HTTP $httpCode, $error");
            return false;
        }

        $decoded = json_decode($response, true);
        
        if ($decoded === null) {
            error_log("API Error: Invalid JSON response");
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

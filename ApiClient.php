<?php

require_once 'Logger.php';
/**
 * Klasa do komunikacji z zewnętrznym API systemu biletowego
 */
class ApiClient
{
    protected $apiUrl;
    protected $config;
    protected $loginId = null;
    protected $orderIdCounter = 1;
    private $logger;

    public function __construct($config)
    {
        $this->config = $config;
        $this->apiUrl = $config['api']['api_url'];
        $this->logger = new Logger();
    }

    /**
     * Logowanie do systemu API
     * @return array ['success' => bool, 'login_id' => string, 'user' => array, 'error' => string]
     */
    public function login()
    {
        $login = $this->config['api']['api_login'];
        $pin = $this->config['api']['api_pin'];
        $password = $this->config['api']['api_password'];
        $deviceId = (int) $this->config['api']['device_id'];
        $deviceIp = $this->config['api']['device_ip'];
        $entityId = (int) $this->config['api']['entity_id'];


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
    public function heartBeat()
    {
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
    public function logout()
    {
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
     * Pobranie informacji o biletach dla podanego kodu kreskowego
     * @param string $barcode Kod kreskowy/numer karty
     * @param string|null $dateFrom Data od (opcjonalnie)
     * @param string|null $dateTo Data do (opcjonalnie)
     * @return array ['success' => bool, 'tickets' => array, 'lockers' => array, 'error' => string]
     */
    /**
     * Pobranie informacji o biletach dla podanego kodu kreskowego lub numeru rejestracyjnego
     * @param string $barcode Kod kreskowy lub numer rejestracyjny
     * @param string|null $dateFrom Data od (opcjonalnie, format Y-m-d H:i:s)
     * @param string|null $dateTo Data do (opcjonalnie, format Y-m-d H:i:s)
     * @return array ['success' => bool, 'tickets' => array, 'lockers' => array, 'error' => string]
     */
    public function getParkTicketInfo($barcode, $dateFrom = null, $dateTo = null)
    {
        if (!$this->loginId) {
            return ['success' => false, 'error' => 'Nie zalogowano'];
        }

       
        $dateFrom = $dateFrom ?? date('Y-m-d H:i:s');
        $dateTo = $dateTo ?? date('Y-m-d H:i:s');

       
        $barcode = trim($barcode);

        
        
        $request = [
            'METHOD' => 'PARK_TICKET_GET_INFO',
            'ORDER_ID' => $this->getNextOrderId(),
            'LOGIN_ID' => $this->loginId,
            'BARCODE' => $barcode,
            'DATE_FROM' => $dateFrom,
            'DATE_TO' => $dateTo
        ];

        $this->logger->log('getParkTicketInfo request ApiClient.php: ' . json_encode($request));

        $response = $this->sendRequest($request);


        $this->logger->log('getParkTicketInfo response ApiClient.php: ' . json_encode($response));

        if ($response === false) {
            return ['success' => false, 'error' => 'Błąd połączenia z API'];
        }

        if (isset($response['STATUS']) && $response['STATUS'] == 0) {
            // Check TICKET_EXIST flag
            $ticketExist = isset($response['TICKET_EXIST']) && $response['TICKET_EXIST'] == 1;
            

            $ticketData = [
                'BARCODE' => $response['BARCODE'] ?? $barcode, // Ticket ID from API
                'REGISTRATION_NUMBER' => $response['REGISTRATION_NUMBER'] ?? $barcode, // Plate Number
                'TICKET_ID' => $response['TICKET_ID'] ?? null,
                'VALID_FROM' => $response['VALID_FROM'] ?? null,
                'VALID_TO' => $response['VALID_TO'] ?? null,
                'FEE' => $response['FEE'] ?? 0,
                'STATUS' => 'active',
                'FEE_TYPE' => $response['FEE_TYPE'] ?? null,
                'FEE_STARTS_TYPE' => $response['FEE_STARTS_TYPE'] ?? null,
                'FEE_MULTI_DAY' => $response['FEE_MULTI_DAY'] ?? null,
                'FEE_PAID' => $response['FEE_PAID'] ?? 0,
                'TICKET_EXIST' => $response['TICKET_EXIST'] ?? null,
                'DATE' => $response['DATE'] ?? null
            ];
          

            return [
                'success' => true,
                'tickets' => [$ticketData],
                'lockers' => $response['LOCKERS'] ?? [],
                'is_new' => !$ticketExist // Keep flag for consumers who care about TICKET_EXIST specifically
            ];
        } else {
            // Special handling for Error -3 (Invalid Data).
            // In this specific API implementation, querying a non-existent plate via PARK_TICKET_GET_INFO
            // returns Error -3 instead of success with TICKET_EXIST=0.
            // We treat -3 as "Ticket Not Found" -> New Session.
            if (($response['STATUS'] ?? -999) == -3) {
                return [
                    'success' => true,
                    'tickets' => [],
                    'lockers' => [],
                    'is_new' => true,
                    'defaults' => null // No defaults available from error response
                ];
            }

            return [
                'success' => false,
                'error' => $this->getErrorMessage($response['STATUS'] ?? -999),
                'debug_request' => $request
            ];
        }
    }

    /**
     * Rozliczenie biletu parkingowego
     * @param string $barcode Numer rejestracyjny/biletu
     * @param string $dateFrom Data start (Y-m-d H:i:s)
     * @param string $dateTo Data stop (Y-m-d H:i:s)
     * @param int $fee Opłata (int64 - grosze lub jednostki systemowe)
     * @param string|null $taxId NIP (opcjonalnie)
     * @return array ['success' => bool, 'receipt_number' => int|null, 'error' => string]
     */
    public function payParkTicket($barcode, $dateFrom, $dateTo, $fee, $taxId = null)
    {
        if (!$this->loginId) {
            return ['success' => false, 'error' => 'Nie zalogowano'];
        }

        $request = [
            'METHOD' => 'PARK_TICKET_PAY',
            'ORDER_ID' => $this->getNextOrderId(),
            'LOGIN_ID' => $this->loginId,
            'BARCODE' => $barcode,
            'DATE_FROM' => $dateFrom,
            'DATE_TO' => $dateTo,
            'FEE' => (int) $fee
        ];

        if ($taxId) {
            $request['TAX_ID'] = $taxId;
        }

        $this->logger->log('payParkTicket request: ' . json_encode($request));

        $response = $this->sendRequest($request);

        $this->logger->log('payParkTicket response: ' . json_encode($response));

        if ($response === false) {
            return ['success' => false, 'error' => 'Błąd połączenia z API'];
        }

        if (isset($response['STATUS']) && $response['STATUS'] == 0) {
            return [
                'success' => true,
                'receipt_number' => $response['RECEIPT_NUMBER'] ?? null
            ];
        } else {
            return [
                'success' => false,
                'error' => $this->getErrorMessage($response['STATUS'] ?? -999)
            ];
        }
    }

    /**
     * Zmiana numeru rejestracyjnego dla podanego numeru biletu parkingowego
     * @param string $barcode Numer biletu parkingowego (BARCODE > 0)
     * @param string $newPlate Nowy numer rejestracyjny
     * @return array ['success' => bool, 'error' => string]
     */
    public function setPlate($barcode, $newPlate)
    {
        if (!$this->loginId) {
            return ['success' => false, 'error' => 'Nie zalogowano'];
        }

        $request = [
            'METHOD' => 'PARK_TICKET_SET_PLATE',
            'ORDER_ID' => $this->getNextOrderId(),
            'LOGIN_ID' => $this->loginId,
            'BARCODE' => $barcode,
            'REGISTRATION_NUMBER' => $newPlate
        ];

        $this->logger->log('setPlate request: ' . json_encode($request));

        $response = $this->sendRequest($request);

        $this->logger->log('setPlate response: ' . json_encode($response));

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
     * Pobranie PDF z potwierdzeniem płatności
     * @param int|string $receiptNumber Numer paragonu
     * @return array ['success' => bool, 'file' => string (base64), 'error' => string]
     */
    public function getPaymentPdf($receiptNumber)
    {
        if (!$this->loginId) {
            return ['success' => false, 'error' => 'Nie zalogowano'];
        }

        $request = [
            'METHOD' => 'PARK_TICKET_GET_PAYMENT_PDF',
            'ORDER_ID' => $this->getNextOrderId(),
            'LOGIN_ID' => $this->loginId,
            'RECEIPT_NUMBER' => (int) $receiptNumber
        ];

        $this->logger->log('getPaymentPdf request: ' . json_encode($request));

        $response = $this->sendRequest($request);

        // Do not log the full file content as it's a huge base64 string
        $logResponse = $response;
        if (isset($logResponse['FILE'])) {
            $logResponse['FILE'] = '[BASE64 DATA OMITTED]';
        }
        $this->logger->log('getPaymentPdf response: ' . json_encode($logResponse));

        if ($response === false) {
            return ['success' => false, 'error' => 'Błąd połączenia z API'];
        }

        if (isset($response['STATUS']) && $response['STATUS'] == 0) {
            return [
                'success' => true,
                'file' => $response['FILE'] ?? ''
            ];
        } else {
            return [
                'success' => false,
                'error' => $this->getErrorMessage($response['STATUS'] ?? -999)
            ];
        }
    }

    /**
     * Wyślij żądanie do API z automatycznym ponowieniem w przypadku błędu autoryzacji (-13)

     * @param array $data Dane żądania
     * @param bool $retryAllowed Czy dozwolone jest ponowienie (zapobiega pętli)
     * @return array|false Odpowiedź z API lub false w przypadku błędu
     */
    private function sendRequest($data, $retryAllowed = true)
    {
        $response = $this->executeRawRequest($data);

        // Obsługa błędu połączenia
        if ($response === false) {
            return false;
        }

        // Sprawdzenie poprawności METHOD i ORDER_ID
        // Wyjątek: Odpowiedź ERROR ma METHOD: ERROR, ORDER_ID: "1" (string)
        $respMethod = $response['METHOD'] ?? '';
        $respOrderId = $response['ORDER_ID'] ?? null;

        $reqMethod = $data['METHOD'];
        $reqOrderId = $data['ORDER_ID'];

        // 1. Walidacja METHOD
        // 1. Walidacja METHOD
        $validMethods = [$reqMethod, 'ERROR'];
        // Specjalny przypadek: PARK_TICKET_GET_PAYMENT_PDF może zwrócić GET_FILE
        if ($reqMethod === 'PARK_TICKET_GET_PAYMENT_PDF') {
            $validMethods[] = 'GET_FILE';
        }

        if (!in_array($respMethod, $validMethods)) {
            $this->logError("Niezgodność METHOD: oczekiwano " . implode(' lub ', $validMethods) . ", otrzymano $respMethod");
            return ['STATUS' => -999, 'DESC' => 'Błąd protokołu: Błędna metoda odpowiedzi'];
        }

        // 2. Walidacja ORDER_ID (Luźna walidacja, bo API może zwracać string)
        if ((string) $respOrderId !== (string) $reqOrderId) {
            $this->logError("Niezgodność ORDER_ID: oczekiwano $reqOrderId, otrzymano " . ($respOrderId ?? 'NULL'));
            // Kontynuujemy, ale logujemy ostrzeżenie - w niektórych systemach to może być akceptowalne,
            // ale wg specyfikacji powinno być to samo.
        }

        // 3. Obsługa błędu -13 (UserNotAuthorized) z retry
        $status = isset($response['STATUS']) ? (int) $response['STATUS'] : -999;

        if ($status === -13 && $retryAllowed) {
            $this->logError("Wykryto błąd autoryzacji (-13). Próba ponownego logowania...");

            // Próba ponownego logowania
            $loginKey = $this->config['api']['api_login'] ?? '';
            // Reset ID logowania przed próbą
            $this->loginId = null;

            $loginResult = $this->login();
            if ($loginResult['success']) {
                $this->logError("Ponowne logowanie udane. Ponawiam oryginalne żądanie.");
                // Aktualizuj LOGIN_ID w oryginalnym żądaniu
                $data['LOGIN_ID'] = $this->loginId;
                // Generuj nowy ORDER_ID dla ponowienia? Zazwyczaj tak.
                $data['ORDER_ID'] = $this->getNextOrderId();

                return $this->sendRequest($data, false); // false = brak kolejnego retry
            } else {
                $this->logError("Ponowne logowanie nieudane: " . ($loginResult['error'] ?? 'Nieznany błąd'));
                return $response; // Zwróć oryginalny błąd -13
            }
        }

        return $response;
    }

    /**
     * Fizyczne wykonanie żądania TCP
     */
    protected function executeRawRequest($data)
    {
        // Parsuj URL aby wyciągnąć host i port
        $urlParts = parse_url($this->apiUrl);
        $host = $urlParts['host'] ?? 'localhost';
        $port = $urlParts['port'] ?? 80;

        $jsonRequest = json_encode($data);
        file_put_contents('app.log', "[DEBUG] Connecting to: $host:$port\n", FILE_APPEND);
        $socket = @fsockopen($host, $port, $errno, $errstr, 10);

        if (!$socket) {
            $errorMsg = "API Socket Error: $errstr ($errno)";
            error_log($errorMsg);
            $this->logError($errorMsg);
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
            if ($line === false)
                break;
            $response .= $line;

            // Simple JSON detection to break early if complete
            // (Note: this simple check might be fragile for partial reads but works for simple line protocols)
            $decoded = json_decode($response, true);
            if ($decoded !== null) {
                break;
            }
        }

        fclose($socket);

        if (empty($response)) {
            $this->logError("API Error: Empty response");
            return false;
        }

        $decoded = json_decode($response, true);


        if ($decoded === null) {
            $this->logError("API Error: Invalid JSON response: " . $response);
            return false;
        }

        return $decoded;
    }

    protected function logError($msg)
    {
        if ($this->logger) {
            $this->logger->log($msg, 'ERROR');
        } else {
            error_log("[ApiClient] " . $msg);
        }
    }

    /**
     * Pobierz następny numer rozkazu
     * @return int
     */
    private function getNextOrderId()
    {
        return $this->orderIdCounter++;
    }

    /**
     * Pobierz komunikat błędu na podstawie kodu statusu
     * @param int $status Kod statusu
     * @return string Komunikat błędu
     */
    private function getErrorMessage($status)
    {
        $errors = [
            -1000 => 'Błąd bazy danych',
            -13 => 'Użytkownik nieautoryzowany (Wymagane ponowne logowanie)',
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

        $msg = $errors[$status] ?? "Nieznany błąd ($status)";

        // Add "Contact support" message to all negative errors except system ones if needed
        // Requirement: "Do opisow dodajemy w nowej linii Skontaktuj sie z obsługą"
        // Applying this to all errors < 0 except maybe temporary ones? 
        // Spec says: "except -13".
        if ($status < 0 && $status !== -13) {
            $msg .= "\nSkontaktuj się z obsługą.";
        }

        return $msg;
    }

    /**
     * Sprawdź czy zalogowano
     * @return bool
     */
    public function isLoggedIn()
    {
        return $this->loginId !== null;
    }

    /**
     * Pobierz LOGIN_ID
     * @return string|null
     */
    public function getLoginId()
    {
        return $this->loginId;
    }
}

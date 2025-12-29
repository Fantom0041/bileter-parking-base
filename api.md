
# API do programu XSOL
**Data:** Grudzień 2025

## Informacje ogólne
**Format:** JSON

---

### Standardowe pola rozkazu
Każdy rozkaz (żądanie) musi zawierać te pola:

| Pole | Typ | Opis |
| :--- | :--- | :--- |
| `METHOD` | string | Nazwa rozkazu. |
| `ORDER_ID` | int32 | Unikalny numer rozkazu, zawsze dodany, zwracany w odpowiedzi do rozkazu. |
| `LOGIN_ID` | string | Identyfikator logowania, zwrócony przez rozkaz `LOGIN`. W samym rozkazie `LOGIN` jest ignorowany. |

### Standardowe pola odpowiedzi
Każda odpowiedź musi zawierać te pola:

| Pole | Typ | Opis |
| :--- | :--- | :--- |
| `METHOD` | string | Nazwa rozkazu. |
| `ORDER_ID` | int32 | Unikalny numer rozkazu, zawsze dodany, otrzymany w rozkazie. |
| `STATUS` | enum | Status rozkazu (patrz tabela poniżej). |

#### Lista kodów STATUS
| Kod | Wartość | Opis |
| :--- | :--- | :--- |
| `ErrBladBazy` | -1000 | Błąd bazy danych |
| `ErrUserNotAuthorized` | -13 | Użytkownik nieautoryzowany |
| `ErrSzafkaZajeta` | -12 | Szafka zajęta |
| `ErrNieznanaSzatniaAutonr` | -11 | Nieznany numer automatyczny szatni |
| `ErrNieznanaSzafkaNumer` | -10 | Nieznany numer szafki |
| `ErrNieznanaSzafkaAutonr` | -9 | Nieznany numer automatyczny szafki |
| `ErrPolaczenia` | -8 | Błąd połączenia |
| `ErrTimeout` | -7 | Timeout |
| `ErrBrakUprawnien` | -6 | Brak uprawnień |
| `ErrNieobslugiwanaOperacja` | -5 | Nieobsługiwana operacja |
| `ErrBrakNumeruKarty` | -4 | Brak numeru karty |
| `ErrNieprawidloweDane` | -3 | Nieprawidłowe dane |
| `ErrKolizjaBiletow` | -2 | Kolizja biletów |
| `ErrPowtornyRozkaz` | -1 | Powtórny rozkaz |
| **`StatusOk`** | **0** | **OK / Sukces** |
| `StatusPrzyjeteDoRealizacji` | 1 | Przyjęte do realizacji |
| `StatusWydrukowane` | 2 | Wydrukowane |
| `StatusWyslane` | 3 | Wysłane |
| `StatusPrzyjeteAutomat` | 5 | Przyjęte (Automat) |
| `StatusZrealizowaneAutomat` | 6 | Zrealizowane (Automat) |
| `StatusWybierzTowarDodatkowy`| 7 | Wybierz towar dodatkowy |
| `StatusBiletZablokowany` | 8 | Bilet zablokowany |

---

### Definicje typów (Enums)

**[typ oplaty parkingowej]**
* `0` - godzinowa
* `1` - dzienna

**[typ naliczania oplaty parkingowej]**
* `0` - 24h od wjazdu
* `1` - pierwszy dzień od wjazdu, kolejny od 00:00

**[tak/nie]**
* `0` - nie
* `1` - tak

---

## OPIS ROZKAZÓW

### 1. Logowanie do systemu
Zwraca unikalny identyfikator logowania, którego używamy do wykonania kolejnych operacji.

**METHOD:** `LOGIN`

**Parametry żądania:**
| Pole | Typ | Opis |
| :--- | :--- | :--- |
| `LOGIN` | string | Nazwa użytkownika (opcjonalnie - z PASSWORD). |
| `PIN` | string | Pin użytkownika (opcjonalnie - może być sam PIN). |
| `PASSWORD` | string | Hasło użytkownika (opcjonalnie - z LOGIN). |
| `DEVICE_ID` | int32 | Numer stanowiska dla którego logujemy rozkazy. |
| `IP` | string | Adres IP stanowiska dla którego logujemy rozkazy. |
| `NOENCODE` | enum | Czy LOGIN, PIN, PASSWORD są kodowane algorytmem SHA1 (defaultowo: 0).<br>`0` - tak<br>`1` - nie |
| `ENTITY_ID` | int32 | Identyfikator podmiotu dla którego wykonujemy logowanie (defaultowo: 1). |

**Odpowiedź prawidłowa:**
| Pole | Typ | Opis |
| :--- | :--- | :--- |
| `LOGIN_ID` | string | Unikalny identyfikator logowania. |
| `USER` | table | Tablica opisująca zalogowanego użytkownika (zawiera pola poniżej). |
| -> `LOGIN` | string | Nazwa użytkownika. |
| -> `NAME` | string | Imię i nazwisko. |

---

### 2. Podtrzymanie połączenia
Standardowo logowanie jest ważne przez 5 minut od ostatniej aktywności.

**METHOD:** `HEART_BEAT`

**Parametry żądania:**
* Standardowe pola rozkazu.

**Odpowiedź prawidłowa:**
* Standardowe pola odpowiedzi.

---

### 3. Wylogowanie z systemu

**METHOD:** `LOGOUT`

**Parametry żądania:**
* Standardowe pola rozkazu.

**Odpowiedź prawidłowa:**
* Standardowe pola odpowiedzi.

---

### 4. Pobranie informacji o bilecie parkingowym

**METHOD:** `PARK_TICKET_GET_INFO`

**Parametry żądania:**
| Pole | Typ | Opis |
| :--- | :--- | :--- |
| `BARCODE` | string | (wymagany) Numer rejestracyjny/biletu. |
| `DATE_FROM` | timestamp| (wymagany) Data start. |
| `DATE_TO` | timestamp| (wymagany) Data stop. |

**Odpowiedź prawidłowa:**
| Pole | Typ | Opis |
| :--- | :--- | :--- |
| `DATE` | string | Data info. |
| `REGISTRATION_NUMBER`| string | Numer rejestracyjny. |
| `TICKET_ID` | int64 | Identyfikator biletu. |
| `TICKET_NAME` | string | Nazwa biletu. |
| `VALID_FROM` | timestamp| Ważny od. |
| `VALID_TO` | string | Ważny do (w okresie od-do bilet jest darmowy). |
| `FEE` | int64 | Aktualna opłata. |
| `FEE_PAID` | int64 | Opłata już zapłacona. |
| `FEE_TYPE` | enum | [typ oplaty parkingowej] |
| `FEE_STARTS_TYPE` | enum | [typ naliczania oplaty parkingowej] |
| `FEE_MULTI_DAY` | enum | [tak/nie] |
| `OBJECT_LIST` | table | Lista obiektów. |
| -> `OBJECT_NAME` | string | Nazwa obiektu. |
| `TICKET_EXIST` | enum | [tak/nie] |

---

### 5. Rozliczenie biletu parkingowego

**METHOD:** `PARK_TICKET_PAY`

**Parametry żądania:**
| Pole | Typ | Opis |
| :--- | :--- | :--- |
| `BARCODE` | string | (wymagany) Numer rejestracyjny/biletu. |
| `DATE_FROM` | timestamp| (wymagany) Data start. |
| `DATE_TO` | timestamp| (wymagany) Data stop. |
| `FEE` | int64 | (wymagany) Aktualna opłata - zwrócona w poleceniu `PARK_TICKET_GET_INFO`. |
| `TAX_ID` | int64 | NIP - w przypadku kiedy klient będzie chciał fakturę. |

**Odpowiedź prawidłowa:**
| Pole | Typ | Opis |
| :--- | :--- | :--- |
| `RECEIPT_NUMBER` | int64 | Numer paragonu. |

---

### 6. Pobranie PDF z potwierdzeniem płatności

**METHOD:** `PARK_TICKET_GET_PAYMENT_PDF`

**Parametry żądania:**
| Pole | Typ | Opis |
| :--- | :--- | :--- |
| `RECEIPT_NUMBER` | int64 | Numer paragonu. |

**Odpowiedź prawidłowa:**
| Pole | Typ | Opis |
| :--- | :--- | :--- |
| `FILE` | string | Zakodowany plik: base64. |
| `CRC32` | int64 | crc32 pliku. |
| `CRC32ORG` | int64 | crc32 pliku po dekompresji. |
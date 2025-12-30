

**Format:** JSON

***

### Standardowe pole rozkazu
(każdy rozkaz musi zawierać te pola):

```text
METHOD      :string    <nazwa rozkazu>
ORDER_ID    :int32     <unikalny numer rozkazu, zawsze dodani, zwracany w odpowiedzi do rozkazu>
LOGIN_ID    :string    <identyfikator logowania, zwrócony przez rozkaz LOGIN. W samym rozkazie LOGIN jest ignorowany>
```

***

### Standardowe pole odpowiedzi
(każda odpowiedź musi zawierać te pola):

```text
METHOD      :string    <nazwa rozkazu>
ORDER_ID    :int32     <unikalny numer rozkazu, zawsze dodani, otrzymany w rozkazie>
STATUS      :enum      <status rozkazu:>
```

**Kody statusów (STATUS):**

*   `<ErrBladBazy = -1000>`
*   `<ErrUserNotAuthorized = -13>`
*   `<ErrSzafkaZajeta = -12>`
*   `<ErrNieznanaSzatniaAutonr = -11>`
*   `<ErrNieznanaSzafkaNumer = -10>`
*   `<ErrNieznanaSzafkaAutonr = -9>`
*   `<ErrPolaczenia = -8>`
*   `<ErrTimeout = -7>`
*   `<ErrBrakUprawnien = -6>`
*   `<ErrNieobslugiwanaOperacja = -5>`
*   `<ErrBrakNumeruKarty = -4>`
*   `<ErrNieprawidloweDane = -3>`
*   `<ErrKolizjaBiletow = -2>`
*   `<ErrPowtornyRozkaz = -1>`
*   **`<StatusOk = 0>`**
*   `<StatusPrzyjeteDoRealizacji = 1>`
*   `<StatusWydrukowane = 2>`
*   `<StatusWyslane = 3>`
*   `<StatusPrzyjeteAutomat = 5>`
*   `<StatusZrealizowaneAutomat = 6>`
*   `<StatusWybierzTowarDodatkowy = 7>`
*   `<StatusBiletZablokowany = 8>`

***

**Definicje typów wyliczeniowych (enums):**

**[typ oplaty parkingowej]**
*   0 - dzienna
*   1 - godzinowa

**[typ naliczania oplaty parkingowej]**
*   0 - 24h od wjazdu
*   1 - pierwszy dzien od wjazdu, kolejny od 00:00

**[tak/nie]**
*   0 - nie
*   1 - tak

---

# OPIS ROZKAZOW:

## 1. Logowanie do systemu
Zwraca unikalny identyfikator logowania, który używamy do wykonania kolejnych operacji.

**METHOD: LOGIN**
[standardowe pole rozkazu]

```text
LOGIN       :string    <nazwa użytkownika, opcjonalnie - z PASSWORD>
PIN         :string    <pin użytkownika, opcjonalnie - może byc sam PIN>
PASSWORD    :string    <haslo użytkownika, opcjonalnie - z LOGIN>
DEVICE_ID   :int32     <numer stanowiska dla którego logujemy rozkazy>
IP          :string    <adres ip stanwiska dla którego logujemy rozkazy>
NOENCODE    :enum      <czy LOGIN, PIN, PASSWORD sa kodowane algorytmem SHA1 (dafaultowo: 0):>
                       <0 - tak>
                       <1 - nie>
ENTITY_ID   :int32     <identyfikator podmiotu dla którego wykonujemy logowanie - defaultowo: 1>
```

**ODPOWIEDŹ PRAWDIŁOWA:**
[standardowe pole odpowiedzi]

```text
LOGIN_ID    :string    <unikalny identyfikator logowania>
USER        :table     <tablica opisujaca zalogowanego użytkownika>
    LOGIN   :string    <nazwa użytkownika>
    NAME    :string    <imie i nazwisko>
```

## 2. Podtrzymanie połączenia
Standardowo logowanie jest ważne przez 5 minut od ostatniej aktywności.

**METHOD: HEART_BEAT**
[standardowe pole rozkazu]

**ODPOWIEDŹ PRAWDIŁOWA:**
[standardowe pole odpowiedzi]

## 3. Wylogowanie z systemu

**METHOD: LOGOUT**
[standardowe pole rozkazu]

**ODPOWIEDŹ PRAWDIŁOWA:**
[standardowe pole odpowiedzi]

## 4. Pobranie informacji o bilecie parkingowych

**METHOD: PARK_TICKET_GET_INFO**
[standardowe pole rozkazu]

```text
BARCODE     :string    <wymagany> <numer rejestracyjny/biletu>
DATE_FROM   :timestamp <wymagany> <data start>
DATE_TO     :timestamp <wymagany> <data stop>
```

**ODPOWIEDŹ PRAWDIŁOWA:**
[standardowe pole odpowiedzi]

```text
DATE                :string    <data info>
BARCODE             :string    <numer biletu parkingowego>
REGISTRATION_NUMBER :string    <numer rejestracyjny>
TICKET_ID           :int64     <identyfikator biletu>
TICKET_NAME         :string    <nazwa biletu>
VALID_FROM          :timestamp <wazny od>
VALID_TO            :string    <wazny do>
                               <w okresie od-do bilet jest darmowy>
FEE                 :int64     <aktualna oplata>
FEE_PAID            :int64     <oplata juz zaplacona>
FEE_TYPE            :enum      [typ oplaty parkingowej]
FEE_STARTS_TYPE     :enum      [typ naliczania oplaty parkingowej]
FEE_MULTI_DAY       :enum      [tak/nie]
OBJECT_LIST         :table     <lista obiektow>
    OBJECT_NAME     :string    <nazwa obiektu>
TICKET_EXIST        :enum      [tak/nie]
```

## 5. Rozliczenie biletu parkingowego

**METHOD: PARK_TICKET_PAY**
[standardowe pole rozkazu]

```text
BARCODE     :string    <wymagany> <numer rejestracyjny/biletu>
DATE_FROM   :timestamp <wymagany> <data start>
DATE_TO     :timestamp <wymagany> <data stop>
FEE         :int64     <wymagany> <aktualna oplata - zwrocona w poleceniu PARK_TICKET_GET_INFO>
TAX_ID      :int64     <NIP - w przypadku kiedy klient bedzie chcial fakture>
```

**ODPOWIEDŹ PRAWDIŁOWA:**
[standardowe pole odpowiedzi]

```text
RECEIPT_NUMBER :int64  <numer paragonu>
```

## 6. Pobranie PDF z potwierdzeniem płatności

**METHOD: PARK_TICKET_GET_PAYMENT_PDF**
[standardowe pole rozkazu]

```text
RECEIPT_NUMBER :int64  <wymagany> <numer paragonu>
```

**ODPOWIEDŹ PRAWDIŁOWA:**
[standardowe pole odpowiedzi]

```text
FILE        :string    <zakodowany plik: base64>
CRC32       :int64     <crc32 pliku>
CRC32ORG    :int64     <crc32 pliku po dekompresji>
```

## 7. Zmiana numeru rejestracyjnego dla podanebo numeru biletu parkingowego

**METHOD: PARK_TICKET_SET_PLATE**
[standardowe pole rozkazu]

```text
BARCODE             :string <wymagany> <numer biletu parkingowego>
REGISTRATION_NUMBER :string <wymagany> <numer rejestracyjny>
```

**ODPOWIEDŹ PRAWDIŁOWA:**
[standardowe pole odpowiedzi]
Format: JSON
********************************************************************************
Standardowe pole rozkazu (każdy rozkaz musi zawierać te pola):
METHOD
:string
<nazwa rozkazu>
ORDER_ID
:int32
<unikalny numer rozkazu, zawsze dodani,
zwracany w odpowiedzi do rozkazu>
LOGIN_ID
:string
<identyfikator logowania, zwrócony przez
rozkaz LOGIN. W samym rozkazie LOGIN jest
ignorowany>
********************************************************************************
Standardowe pole odpowiedzi (każda odpowiedź musi zawierać te pola):
METHOD
:string
<nazwa rozkazu>
ORDER_ID :
int32
<unikalny numer rozkazu, zawsze dodani,
otrzymany w rozkazie>
STATUS
:enum
<status rozkazu:>
<ErrBladBazy
= -1000>
<ErrUserNotAuthorized
= -13>
<ErrSzafkaZajeta
= -12>
<ErrNieznanaSzatniaAutonr
= -11>
<ErrNieznanaSzafkaNumer
= -10>
<ErrNieznanaSzafkaAutonr
= -9>
<ErrPolaczenia
= -8>
<ErrTimeout
= -7>
<ErrBrakUprawnien
= -6>
<ErrNieobslugiwanaOperacja
= -5>
<ErrBrakNumeruKarty
= -4>
<ErrNieprawidloweDane
= -3>
<ErrKolizjaBiletow
= -2>
<ErrPowtornyRozkaz
= -1>
<StatusOk
= 0>
<StatusPrzyjeteDoRealizacji
= 1>
<StatusWydrukowane
= 2>
<StatusWyslane
= 3>
<StatusPrzyjeteAutomat
= 5>
<StatusZrealizowaneAutomat
= 6>
<StatusWybierzTowarDodatkowy = 7>
<StatusBiletZablokowany
= 8>
********************************************************************************
[typ oplaty parkingowej]
0 - godzinowa
1 - dzienna
[typ naliczania oplaty parkingowej]
0 - 24h od wjazdu
1 - pierwszy dzien od wjazdu, kolejny od 00:00
[tak/nie]
0 - nie
1 - tak
2OPIS ROZKAZOW:
1. Logowanie do systemu – zwraca unikalny identyfikator logowania, który używamy do
wykonania kolejnych operacji
METHOD: LOGIN
[standardowe pole rozkazu]
LOGIN
:string
PIN
:string
PASSWORD
:string
DEVICE_ID
:int32
IP
:string
NOENCODE
:enum
ENTITY_ID :int32
<nazwa użytkownika, opcjonalnie - z PASSWORD>
<pin użytkownika, opcjonalnie - może byc sam PIN>
<haslo użytkownika, opcjonalnie - z LOGIN>
<numer stanowiska dla którego logujemy rozkazy>
<adres ip stanwiska dla którego logujemy rozkazy>
<czy LOGIN, PIN, PASSWORD sa kodowane
algorytmem SHA1 (dafaultowo: 0):>
<0 - tak>
<1 - nie>
<identyfikator podmiotu dla którego wykonujemy
logowanie - defaultowo: 1>
ODPOWIEDŹ PRAWDIŁOWA:
[standardowe pole odpowiedzi]
LOGIN_ID
:string
USER
:table
LOGIN
:string
NAME
:string
<unikalny identyfikator logowania>
<tablica opisujaca zalogowanego użytkownika>
<nazwa użytkownika>
<imie i nazwisko>
2. Podtrzymanie połączenia - standardowo logowanie jest ważne przez 5 minut od ostatniej
aktywności
METHOD: HEART_BEAT
[standardowe pole rozkazu]
ODPOWIEDŹ PRAWDIŁOWA:
[standardowe pole odpowiedzi]
3. Wylogowanie z systemu
METHOD: LOGOUT
[standardowe pole rozkazu]
ODPOWIEDŹ PRAWDIŁOWA:
[standardowe pole odpowiedzi]
4. Pobranie informacji o bilecie parkingowych
METHOD: PARK_TICKET_GET_INFO
[standardowe pole rozkazu]
BARCODE
:string
<wymagany> <numer rejestracyjny/biletu>
DATE_FROM
:timestamp <wymagany> <data start>
DATE_TO
:timestamp <wymagany> <data stop>
3ODPOWIEDŹ PRAWDIŁOWA:
[standardowe pole odpowiedzi]
DATE
REGISTRATION_NUMBER
TICKET_ID
TICKET_NAME
VALID_FROM
VALID_TO
FEE
FEE_PAID
FEE_TYPE
FEE_STARTS_TYPE
FEE_MULTI_DAY
OBJECT_LIST
OBJECT_NAME
TICKET_EXIST
:string
:string
:int64
:string
:timestamp
:string
<data info>
<numer rejestracyjny>
<identyfikator biletu>
<nazwa biletu>
<wazny od>
<wazny do>
<w okresie od-do bilet jest darmowy>
:int64
<aktualna oplata>
:int64
<oplata juz zaplacona>
:enum
[typ oplaty parkingowej]
:enum
[typ naliczania oplaty parkingowej]
:enum
[tak/nie]
:table
<lista obiektow>
:string
<nazwa obiektu>
:enum
[tak/nie]
5. Rozliczenie biletu parkingowego
METHOD: PARK_TICKET_PAY
[standardowe pole rozkazu]
BARCODE
:string
<wymagany> <numer rejestracyjny/biletu>
DATE_FROM
:timestamp <wymagany> <data start>
DATE_TO
:timestamp <wymagany> <data stop>
FEE
:int64
<wymagany> <aktualna oplata - zwrocona w poleceniu
PARK_TICKET_GET_INFO>
TAX_ID
:int64
<NIP - w przypadku kiedy klient bedzie
chcial fakture>
ODPOWIEDŹ PRAWDIŁOWA:
[standardowe pole odpowiedzi]
RECEIPT_NUMBER
:int64
<numer paragonu>
6. Pobranie PDF z potwierdzeniem płatności
METHOD: PARK_TICKET_GET_PAYMENT_PDF
[standardowe pole rozkazu]
RECEIPT_NUMBER
:int64
<numer paragonu>
ODPOWIEDŹ PRAWDIŁOWA:
[standardowe pole odpowiedzi]
FILE
CRC32
CRC32ORG
:string
:int64
:int64
4
<zakodowany plik: base64>
<crc32 pliku>
<crc32 pliku po dekompresji>
Format: JSON
********************************************************************************
Standardowe pole rozkazu (każdy rozkaz musi zawierać te pola):
METHOD :string <nazwa rozkazu>
ORDER_ID :int32 <unikalny numer rozkazu, zawsze dodani,
zwracany w odpowiedzi do rozkazu>
LOGIN_ID :string <identyfikator logowania, zwrócony przez
rozkaz LOGIN. W samym rozkazie LOGIN jest
ignorowany>
********************************************************************************
Standardowe pole odpowiedzi (każda odpowiedź musi zawierać te pola):
METHOD :string <nazwa rozkazu>
ORDER_ID : int32 <unikalny numer rozkazu, zawsze dodani,
otrzymany w rozkazie>
STATUS :enum <status rozkazu:>
<ErrBladBazy = -1000>
<ErrUserNotAuthorized = -13>
<ErrSzafkaZajeta = -12>
<ErrNieznanaSzatniaAutonr = -11>
<ErrNieznanaSzafkaNumer = -10>
<ErrNieznanaSzafkaAutonr = -9>
<ErrPolaczenia = -8>
<ErrTimeout = -7>
<ErrBrakUprawnien = -6>
<ErrNieobslugiwanaOperacja = -5>
<ErrBrakNumeruKarty = -4>
<ErrNieprawidloweDane = -3>
<ErrKolizjaBiletow = -2>
<ErrPowtornyRozkaz = -1>
<StatusOk = 0>
<StatusPrzyjeteDoRealizacji = 1>
<StatusWydrukowane = 2>
<StatusWyslane = 3>
<StatusPrzyjeteAutomat = 5>
<StatusZrealizowaneAutomat = 6>
<StatusWybierzTowarDodatkowy = 7>
<StatusBiletZablokowany = 8>
********************************************************************************
2
OPIS ROZKAZOW:
1. Logowanie do systemu – zwraca unikalny identyfikator logowania, który używamy do
wykonania kolejnych operacji
METHOD: LOGIN
[standardowe pole rozkazu]
LOGIN :string <nazwa użytkownika, opcjonalnie - z PASSWORD>
PIN :string <pin użytkownika, opcjonalnie - może byc sam PIN>
PASSWORD :string <haslo użytkownika, opcjonalnie - z LOGIN>
DEVICE_ID :int32 <numer stanowiska dla którego logujemy rozkazy>
IP :string <adres ip stanwiska dla którego logujemy rozkazy>
NOENCODE :enum <czy LOGIN, PIN, PASSWORD sa kodowane
algorytmem SHA1 (dafaultowo: 0):>
<0 - tak>
<1 - nie>
ENTITY_ID :int32 <identyfikator podmiotu dla którego wykonujemy
logowanie - defaultowo: 1>
 ODPOWIEDŹ PRAWDIŁOWA:
[standardowe pole odpowiedzi]
LOGIN_ID :string <unikalny identyfikator logowania>
USER :table <tablica opisujaca zalogowanego użytkownika>
LOGIN :string <nazwa użytkownika>
NAME :string <imie i nazwisko>
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
4. Wydanie biletu na podany identyfikator
METHOD: TICKET_EXECUTE
[standardowe pole rozkazu]
TICKET_ID :int64 <identyfikator biletu>
3
TICKET_TYPE :int32 <typ biletu>
AMOUNT :int32 <ilość>
BARCODE :string <kod kreskowy/numer karty/numer zegarka>
VALID_FROM :string <ważny od - format: 2000-01-01 00:00:00>
VALID_TO :string <ważny do - format: 2000-12-31 23:59:50>
 ODPOWIEDŹ PRAWDIŁOWA:
[standardowe pole odpowiedzi]
5. Skasowanie biletów z podanego identyfikatora
METHOD: BARCODE_DELETE
[standardowe pole rozkazu]
BARCODE :string <kod kreskowy/numer karty/numer zegarka>
 ODPOWIEDŹ PRAWDIŁOWA:
[standardowe pole odpowiedzi]
6. Pobranie informacji o aktywnych biletach dla podanego identyfikatora
METHOD: BARCODE_INFO
[standardowe pole rozkazu]
BARCODE :string <kod kreskowy/numer karty/numer zegarka>
 ODPOWIEDŹ PRAWDIŁOWA:
[standardowe pole odpowiedzi]
LOCKERS :table <tablica szafek>
LOCEKR_ID :int64 <szafka - identyfikator>
LOCKER_NUMBER :int32 <szafka - numer>
LOCKER_INDEX :int32 <szafka - indeks wystapienia>
LOCKER_INFO :string <szafka - info>
LOCKER_ROOM_ID :int64 <przebieralnia - identyfikator>
LOCKER_ROOM_NAME :string <przebieralnia - nazwa>
BARCODE :string <kod kreskowy/numer karty/numer
zegarka - dla którego szafka jest zajeta w
podanym okresie>
VALID_FROM :string <szafka zajeta od - format: 2000-01-01
00:00:00>
VALID_TO :string <szafka zajeta do - format: 2000-01-01
00:00:00>
TICKETS :table <tablica opisujaca aktywne bilety>
BARCODE :string <kod kreskowy/numer karty/numer
zegarka>
TICKET_ID :int64 <identyfikator biletu>
TICKET_NAME :string <nazwa biletu>
TICKET_TYPE :int32 <typ biletu>
VALID_FROM :string <ważny od - format: 2000-01-01
00:00:00>
VALID_TO :string <ważny do - format: 2000-12-31
23:59:50>
4
POINTS :int32 <opcjonalnie - ilosc punktów - dla
niektórych typów biletów>
POINTS_FOR_ENTRY :int32 <opcjonalnie - ilosc punktów dla
pojedyńczego przejścia - dla niektórych
typów biletów>
ITEM_ID :int64 <identyfikator pozycji w bazie>
VALID_PERIODS :table <tablica z nadrzędnymi okresami
obowiazywania>
VALID_FROM :string <ważny od - format: 2000-01-01
00:00:00>
VALID_TO :string <ważny do - format: 2000-12-31
23:59:50>

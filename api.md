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



METHOD: PARK_TICKET_GET_INFO
	[standardowe pole rozkazu]
	BARCODE					:string		<wymagany>	<kod kreskowy/numer karty/numer zegarka/numer rejestracyjny>
	DATE					:string		<wymagany>	<data rozliczenienia>

----------------------------------------------------------------------------------
  ODPOWIEDZ PRAWDILOWA:
----------------------------------------------------------------------------------
	[standardowe pole odpowiedzi]
	DATE					:string				<data info>
	REGISTRATION_NUMBER		:string				<numer rejestracyjny samochodu>
	TICKET_ID				:int64				<identyfikator biletu parkingowego>
	TICKET_NAME				:string				<nazwa biletu parkingowego>
	VALID_FROM				:string				<wazny od - format: 2000-01-01 00:00:00>
	VALID_TO				:string 			<wazny do - format: 2000-12-31 23:59:50>
													< - w okresie od - do bilet nie nalicza oplaty>
	FEE						:int64				<aktualna oplata - po odjeciu ewentualnych wczesniejszym oplat>
	FEE_PAID				:int64				<oplata juz zaplacona>
    FEE_TYPE				:enum				[typ oplaty parkingowej]
	FEE_STARTS_TYPE			:enum				[typ naliczania oplaty parkingowej]
	FEE_MULTI_DAY			:enum				[tak/nie]
	OBJECT_LIST				:table				<lista obiektow na ktore wpuszcza parking>
		OBJECT_NAME			:string				<nazwa obiektu>


        poprawki 


     [typ oplaty parkingowej]
		0 - godzinowa
		1 - dzienna

[typ naliczania oplaty parkingowej]
		0 - 24h od wjazdu
		1 - pierwszy dzien od wjazdu, kolejny od 00:00

[tak/nie]
		0 - nie
		1 - tak

----------------------------------------------------------------------------------	
  METHOD: PARK_TICKET_GET_INFO				<zwraca info o biletach parkingowych>
----------------------------------------------------------------------------------	
	[standardowe pole rozkazu]
    BARCODE					:string		<wymagany>	<kod kreskowy/numer karty/numer zegarka/numer rejestracyjny>
	DATE_FROM				:timestamp	<wymagany>	<data start>
	DATE_TO					:timestamp	<wymagany>	<data stop>
----------------------------------------------------------------------------------
  ODPOWIEDZ PRAWDILOWA:
----------------------------------------------------------------------------------
	[standardowe pole odpowiedzi]
	DATE					:string				<data info>
	REGISTRATION_NUMBER		:string				<numer rejestracyjny samochodu>
	TICKET_ID				:int64				<identyfikator biletu parkingowego>
	TICKET_NAME				:string				<nazwa biletu parkingowego>
	VALID_FROM				:string				<wazny od - format: 2000-01-01 00:00:00>
	VALID_TO				:string 			<wazny do - format: 2000-12-31 23:59:50>
													< - w okresie od - do bilet nie nalicza oplaty>
	FEE						:int64				<aktualna oplata - po odjeciu ewentualnych wczesniejszym oplat>
	FEE_PAID				:int64				<oplata juz zaplacona>
    FEE_TYPE				:enum				[typ oplaty parkingowej]
	FEE_STARTS_TYPE			:enum				[typ naliczania oplaty parkingowej]
	FEE_MULTI_DAY			:enum				[tak/nie]
	OBJECT_LIST				:table				<lista obiektow na ktore wpuszcza parking>
		OBJECT_NAME			:string				<nazwa obiektu>
	TICKET_EXIST			:enum				[tak/nie]


UWAGI:
1. Musi podawa date "od", czyli przy zaladowaniu date atkualna (identyczna jak data "do") - po to ze jak na numerze rejestracyjnym / numerze biletu nie ma aktywnego bieltu parkignowego, to system zwroci ustawienia dla biletu domyslnego w podanych datach od - do
2. TICKET_EXIST - jesli zwroci "0" tzn ze biletu nie ma i mozna mu ustawic :dowolna" date "od" (czyli date start) - jesli system jest jednodniowy to tylko w obrebie aktualnego dnia (chyba)
3. FEE_PAID - jesli jest > 0 to wysweitlamy, ze taka kwota zostala juz oplacona (wowczas VALID_TO pokazuje date do ktorej bilet kjest
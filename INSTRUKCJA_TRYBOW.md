# Instrukcja konfiguracji trybów parkowania

## Przegląd

System parkowania posiada **3 niezależne tryby**, które można łączyć w różne kombinacje:

1. **Tryb czasowy** - jak liczymy czas (dzienny/godzinowy)
2. **Długość parkowania** - czy edytujemy czas (jednodniowy/wielodniowy)
3. **Liczenie dnia** - od kiedy liczymy dzień (od wjazdu/od 00:00)

---

## 1. TRYB CZASOWY

### **Dzienny**
- Fokus na **datach** (dni)
- Używany gdy parkowanie jest na całe dni

### **Godzinowy**
- Fokus na **godzinach i minutach**
- Używany gdy parkowanie jest krótkoterminowe (minuty/godziny)

---

## 2. DŁUGOŚĆ PARKOWANIA

### **1-dniowy (Jednodniowy)**
- Parkowanie w ramach **jednego dnia**
- Czas końcowy jest **automatycznie obliczany**
- Użytkownik **NIE może** edytować czasu wyjazdu (kółko zablokowane)

### **Wielodniowy**
- Parkowanie może trwać **wiele dni**
- Użytkownik **MOŻE** edytować czas wyjazdu (kółko aktywne)
- Kręcąc kółkiem wybiera datę/czas wyjazdu

---

## 3. LICZENIE DNIA

### **Od wjazdu**
- Dzień liczony jako **24 godziny od momentu wjazdu**
- Przykład: Wjazd 14:30 → Koniec dnia: jutro 14:30

### **Od 00:00**
- Dzień liczony **od północy do północy**
- Przykład: Wjazd 14:30 → Koniec dnia: dzisiaj 23:59

---

## KOMBINACJE TRYBÓW I ICH DZIAŁANIE

### **Kombinacja 1: Dzienny + 1-dniowy**
**Zastosowanie:** Parking całodniowy bez możliwości edycji

**Działanie:**
- ❌ **Kółko ZABLOKOWANE** (szare, nieprzekręcalne)
- ⏰ Czas końcowy **automatycznie obliczany**
- 📅 Wyświetla datę końcową

**Czas końcowy zależy od "Liczenie dnia":**
- **Od wjazdu:** Wjazd + 24h
- **Od 00:00:** Dzisiaj 23:59

**Skok pokrętła:** Brak (zablokowane)

---

### **Kombinacja 2: Dzienny + Wielodniowy**
**Zastosowanie:** Parking na wiele dni, wybór tylko daty

**Działanie:**
- ✅ **Kółko AKTYWNE** (można kręcić)
- 📅 Wybierasz **tylko datę** wyjazdu
- ⏰ Godzina wyjazdu **stała** (według "Liczenie dnia")
- 🔄 **1 pełny obrót = 7 dni**

**Skok pokrętła:**
- **1 pełny obrót (360°) = 7 dni**
- **Krok:** ~1 dzień na ~51° obrotu

**Przykład:**
- Wjazd: 11.12.2025 14:30
- Kręcisz koło o pół obrotu (180°) → +3.5 dni
- Wyjazd: 14.12.2025 14:30 (lub 23:59 jeśli "Od 00:00")

---

### **Kombinacja 3: Godzinowy + 1-dniowy**
**Zastosowanie:** Parking krótkoterminowy (minuty/godziny) w ramach jednego dnia

**Działanie:**
- ✅ **Kółko AKTYWNE** (można kręcić)
- ⏰ Wybierasz **tylko godzinę/minuty** wyjazdu
- 📅 Data wyjazdu **stała** (dzisiaj lub jutro)
- 🔄 **1 pełny obrót = 60 minut (1 godzina)**

**Skok pokrętła:**
- **1 pełny obrót (360°) = 60 minut**
- **Krok:** 1 minuta na 6° obrotu

**Przykład:**
- Wjazd: 14:30
- Kręcisz koło o pół obrotu (180°) → +30 minut
- Wyjazd: 15:00

---

### **Kombinacja 4: Godzinowy + Wielodniowy**
**Zastosowanie:** Pełna kontrola - wybór dni + godzin/minut

**Działanie:**
- ✅ **Kółko AKTYWNE** (można kręcić)
- 🔀 **Dwa tryby edycji** (przełączanie przyciskami):
  
  **A) Tryb "DNI":**
  - 📅 Wybierasz **datę** wyjazdu
  - 🔄 **1 pełny obrót = 7 dni**
  - Krok: ~1 dzień na ~51° obrotu
  
  **B) Tryb "MINUTY":**
  - ⏰ Wybierasz **godzinę/minuty** wyjazdu
  - 🔄 **1 pełny obrót = 60 minut**
  - Krok: 1 minuta na 6° obrotu

**Przełączanie:**
- Kliknij przycisk **"Dni"** → koło zmienia dni
- Kliknij przycisk **"Minuty"** → koło zmienia minuty
- LUB kliknij na pole **"Data"** / **"Godzina"** w sekcji "Planowany wyjazd"

**Przykład:**
1. Wybierz tryb "Dni", kręć koło → ustaw datę na 15.12.2025
2. Przełącz na "Minuty", kręć koło → ustaw godzinę na 16:45
3. Końcowy czas wyjazdu: **15.12.2025 16:45**

---

## TABELA PODSUMOWUJĄCA

| Tryb czasowy | Długość | Kółko | Co edytujesz | Skok pokrętła | Przełączanie |
|--------------|---------|-------|--------------|---------------|--------------|
| **Dzienny** | 1-dniowy | ❌ Zablokowane | - | - | - |
| **Dzienny** | Wielodniowy | ✅ Aktywne | Tylko datę | 360° = 7 dni | - |
| **Godzinowy** | 1-dniowy | ✅ Aktywne | Tylko minuty | 360° = 60 min | - |
| **Godzinowy** | Wielodniowy | ✅ Aktywne | Datę + minuty | 360° = 7 dni LUB 60 min | Przyciski Dni/Minuty |

---

## SZCZEGÓŁY TECHNICZNE

### Skok pokrętła - dokładne wartości:

**Tryb dni (7 dni = 360°):**
- 1° = ~0.0194 dnia = ~28 minut
- 10° = ~0.194 dnia = ~4.67 godziny
- 51.43° = 1 dzień
- 180° = 3.5 dnia
- 360° = 7 dni

**Tryb minut (60 minut = 360°):**
- 1° = ~0.167 minuty = ~10 sekund
- 6° = 1 minuta
- 90° = 15 minut
- 180° = 30 minut
- 360° = 60 minut (1 godzina)

---

## PRZYKŁADOWE SCENARIUSZE UŻYCIA

### Scenariusz 1: Parking całodniowy bez wyboru
**Konfiguracja:** Dzienny + 1-dniowy + Od 00:00
- Wjazd: 11.12.2025 14:30
- Wyjazd: 11.12.2025 23:59 (automatycznie)
- Użytkownik nie może nic zmienić

### Scenariusz 2: Parking na kilka dni
**Konfiguracja:** Dzienny + Wielodniowy + Od wjazdu
- Wjazd: 11.12.2025 14:30
- Użytkownik kręci koło o 2 obroty (720°) = 14 dni
- Wyjazd: 25.12.2025 14:30

### Scenariusz 3: Parking na godziny
**Konfiguracja:** Godzinowy + 1-dniowy + Od wjazdu
- Wjazd: 14:30
- Użytkownik kręci koło o 3 obroty (1080°) = 180 minut
- Wyjazd: 17:30

### Scenariusz 4: Pełna kontrola
**Konfiguracja:** Godzinowy + Wielodniowy + Od wjazdu
- Wjazd: 11.12.2025 14:30
- Użytkownik:
  1. Tryb "Dni": kręci o 1 obrót → +7 dni
  2. Tryb "Minuty": kręci o 2 obroty → +120 minut
- Wyjazd: 18.12.2025 16:30

---

## WSKAZÓWKI

1. **Dla parkingu krótkoterminowego (< 1 dzień):**
   - Użyj: Godzinowy + 1-dniowy

2. **Dla parkingu wielodniowego z dokładną godziną:**
   - Użyj: Godzinowy + Wielodniowy

3. **Dla parkingu całodniowego bez edycji:**
   - Użyj: Dzienny + 1-dniowy

4. **Dla parkingu na pełne dni:**
   - Użyj: Dzienny + Wielodniowy

---

## ZMIANA KONFIGURACJI

Konfigurację można zmienić na dwa sposoby:

### 1. W aplikacji (tymczasowo)
- Użyj przycisków w górnej części ekranu
- Zmiany obowiązują tylko w bieżącej sesji
- Po odświeżeniu wraca do ustawień z pliku

### 2. W pliku config.ini (trwale)
Edytuj plik `/config.ini`:

```ini
[parking_modes]
time_mode = "daily"          ; daily lub hourly
duration_mode = "multi_day"  ; single_day lub multi_day
day_counting = "from_entry"  ; from_entry lub from_midnight
```

---

## UWAGI

- Panel konfiguracji w aplikacji to **element testowy** - można go łatwo usunąć przed produkcją
- Wszystkie tryby działają niezależnie - można je dowolnie łączyć
- Kółko jest zawsze płynne - wartości są zaokrąglane do najbliższej jednostki



1. powered by basesystem , basesystem kolory, 
logo firmy klienta
na gorze logo klienta

strefa start stop zamiast czasu wjazdu 
padding bottom 

tryb dzienny block godziny (nieklikalna disabled\)

liczenie od polnocy godzina wyjazdu godzina ui 23:59 - tylko w trybie dziennym

godzinny 1 dniowy data disabled i nie da sie kliknac tam aktywne tylko czas

kolko nie przewia godziny / dni po przekroczeniu ustawien, czyli rpzewija tylko 1h do prozdu i 7 dni do przeodu

jak mamy jednodniowy to nie da sie zmniejszyc czas mniej niz teraz 

kropka na gorze na kolce

czas wjazdu edtyowany w trybie zakupu parkingu do przodu 


start okno index root : jak przechodzimy z tego okna z podanuiem numeru to aktywujemy tryp pelnej edycji . 

index strona logo klienta i opis footer base system. 



<div class="timer-circle" id="spinnerContainer" style="cursor: grab; user-select: none; opacity: 0.5; pointer-events: none;">
                    <div class="timer-content">
                        <span class="label" id="spinnerLabel">WYJAZD</span>
                        <span class="value" id="spinnerValue"><span style="font-size: 24px;">12.12.2025</span></span>
                    </div>

                    <!-- SVG Spinner -->
                    <svg class="progress-ring" width="240" height="240" viewBox="0 0 240 240">
                        <!-- Track -->
                        <circle class="progress-ring__track" cx="120" cy="120" r="100" fill="none" stroke="#E0E0E0" stroke-width="20"></circle>

                        <!-- Progress Arc -->
                        <circle class="progress-ring__circle" id="progressCircle" cx="120" cy="120" r="100" fill="none" stroke="var(--primary)" stroke-width="20" stroke-linecap="round" stroke-dasharray="628" stroke-dashoffset="628" transform="rotate(-90 120 120)" style="stroke-dasharray: 628.319, 628.319; stroke-dashoffset: 628.319;"></circle>

                        <!-- Handle -->
                        <g id="spinnerHandle" style="cursor: grab;" transform="translate(0, 0)">
                            <circle cx="120" cy="20" r="12" fill="var(--primary)" stroke="white" stroke-width="4"></circle>
                        </g>
                    </svg>
                </div>

                data i czas wjazdu w trybie brak edycji 



AKTYWN
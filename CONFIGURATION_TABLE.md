| FEE_MULTI_DAY | FEE_TYPE | FEE_STARST_TYPE | START DATA | START GODZINA | STOP DATA | STOP GODZINA |
| :---: | :---: | :---: | :--- | :--- | :--- | :--- |
| 0 | 0 | 0 | nie edytujemy | nie edytujemy | nie edytujemy: START DATA + 1 | nie edytujemy: START GODZINA |
| 0 | 0 | 1 | nie edytujemy | nie edytujemy | nie edytujemy: START DATA | nie edytujemy: 23:59:59 |
| 0 | 1 | 0 | nie edytujemy | nie edytujemy | nie edytujemy: START DATA | edytujemy do 23:59:59 – po przekroczeniu: CURRENT GODZINA i brak zmiany STOP DATA |
| 0 | 1 | 1 | nie edytujemy | nie edytujemy | nie edytujemy: START DATA | edytujemy do 23:59:59 – po przekroczeniu: CURRENT GODZINA i brak zmiany STOP DATA |
| 1 | 0 | 0 | nie edytujemy | nie edytujemy | edytujemy: START DATA + 1 | nie edytujemy: START GODZINA |
| 1 | 0 | 1 | nie edytujemy | nie edytujemy | edytujemy: START DATA | nie edytujemy: 23:59:59 |
| 1 | 1 | 0 | nie edytujemy | nie edytujemy | edytujemy: START DATA | edytujemy |
| 1 | 1 | 1 | nie edytujemy | nie edytujemy | edytujemy: START DATA | edytujemy |

### OGOLNIE (General Rules):

1.  **STOP >= START**: zawsze
2.  **STOP >= VALID_TO**: zawsze
3.  „koleczko” z data/godzina musi się blokowac po przekroczeniu wartosci granicznych (punkty 1,2 oraz ustawnia z tabeli powyzej)
4.  „koleczko” ma nie być widoczne, jeśli nie edytujemy daty i godziny – wówczas pole START/STOP ma nie być edytowalne
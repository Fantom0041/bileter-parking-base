/**
 * Spinner Component
 * Wraps RoundSlider and handles time manipulation logic.
 */
import { state } from '../state.js';
import { CONFIG, FEE_CONFIG, API_SETTINGS, IS_PAID, ENTRY_TIME_RAW } from '../config.js';
import { calculateFee } from '../api.js';
import { showToast, formatDateTime } from '../utils.js';
import { getScenarioConfig } from '../logic/scenarios.js';

// DOM Elements managed by this component
const spinnerContainer = document.getElementById('spinnerContainer');
const spinnerValue = document.getElementById('spinnerValue');
const spinnerLabel = document.getElementById('spinnerLabel');
const exitDateValue = document.getElementById('exitDateValue');
const exitTimeValue = document.getElementById('exitTimeValue');
const entryDateValue = document.getElementById('entryDateValue');
const entryTimeValue = document.getElementById('entryTimeValue');
const payButton = document.getElementById('payButton');

// Updates display of Exit Time
function updateExitTimeDisplay(exitTime) {
    if (!exitDateValue || !exitTimeValue) return;

    const day = String(exitTime.getDate()).padStart(2, '0');
    const month = String(exitTime.getMonth() + 1).padStart(2, '0');
    const year = exitTime.getFullYear();
    const hours = String(exitTime.getHours()).padStart(2, '0');
    const mins = String(exitTime.getMinutes()).padStart(2, '0');

    state.currentExitTime = exitTime;

    exitDateValue.textContent = `${day}.${month}.${year}`;
    exitTimeValue.textContent = `${hours}:${mins}`;

    const exitTimeDisplayCollapsed = document.getElementById('exitTimeDisplayCollapsed');
    if (exitTimeDisplayCollapsed) {
        exitTimeDisplayCollapsed.textContent = `${year}-${month}-${day} ${hours}:${mins}`;
    }
}

function updateEntryTimeDisplay(entryTime) {
    if (!entryDateValue || !entryTimeValue) return;

    const day = String(entryTime.getDate()).padStart(2, '0');
    const month = String(entryTime.getMonth() + 1).padStart(2, '0');
    const year = entryTime.getFullYear();
    const hours = String(entryTime.getHours()).padStart(2, '0');
    const mins = String(entryTime.getMinutes()).padStart(2, '0');

    entryDateValue.textContent = `${day}.${month}.${year}`;
    entryTimeValue.textContent = `${hours}:${mins}`;
}

function setLoadingState() {
    if (payButton && payButton.classList.contains('save-mode')) return;
    if (payButton) {
        payButton.innerText = 'Obliczanie opłaty...';
        payButton.disabled = true;
    }
}

// Logic to fetch fee (debounced)
async function triggerFeeCalc(exitTime) {
    try {
        const data = await calculateFee(state.entryTime, exitTime);
        console.log('Fee Calc Result:', data);
        
        if (data.success) {
            state.currentFee = data.fee;
            
            // Dispatch event to update UI (Pay Button, Payment Info)
            const event = new CustomEvent('feeUpdated', { detail: data });
            document.dispatchEvent(event);
        } else {
             const displayMsg = SCENARIO_TEST_MODE 
                ? `Fee Calc Error: ${data.message}` 
                : "Nie udało się przeliczyć opłaty.";
             showToast(displayMsg);
             state.currentFee = CONFIG.initial_fee || 0; // Fallback? config.js defines constants
             // Dispatch error event?
             document.dispatchEvent(new CustomEvent('feeUpdateFailed'));
        }
    } catch (error) {
         console.error(error);
         showToast("Błąd połączenia przy wycenie.");
         document.dispatchEvent(new CustomEvent('feeUpdateFailed'));
    }
}

function handleSliderChange(newValue) {
    if (IS_PAID) return;

    let diff = newValue - state.lastSliderValue;
    let potentialTurns = state.currentTurns;

    // Detect wrap around
    if (diff < -180) potentialTurns++;
    else if (diff > 180) potentialTurns--;

    let potentialTotal = potentialTurns * 360 + newValue;

    // MIN/MAX Logic
    let minLimit = 0;
    const scenario = state.getModeScenario();
    
    // Multi-Day Hourly negative limit
    if ((scenario === 'scenario_1_1_0' || scenario === 'scenario_1_1_1') && state.currentUnit === 'minutes') {
        minLimit = -(state.selectedDays * 24 * 360);
    }

    // Max Limit for Single Day Hourly
    let maxLimit = Infinity;
    if (scenario === 'scenario_1_0_0' || scenario === 'scenario_1_0_1') {
        let baseTime = new Date(state.entryTime);
        if (CONFIG.valid_to) {
            const vt = new Date(CONFIG.valid_to);
            if (vt > baseTime) baseTime = vt;
        }
        const endOfDay = new Date(baseTime);
        endOfDay.setHours(23, 59, 59, 999);
        const msToEnd = endOfDay - baseTime;
        const minutesToEnd = Math.floor(msToEnd / 60000);
        maxLimit = (minutesToEnd / 60) * 360;
    }

    if (potentialTotal < minLimit) {
        state.currentTurns = Math.floor(minLimit / 360);
        state.lastSliderValue = (minLimit % 360 + 360) % 360;
        state.totalDegrees = minLimit;
        
        const slider = $("#slider").data("roundSlider");
        if (slider) slider.setValue(state.lastSliderValue);
        
        updateSpinner(state.totalDegrees, false); // No interaction flag?
        return;
    }

    if (potentialTotal > maxLimit) {
       state.currentTurns = Math.floor(maxLimit / 360);
       state.lastSliderValue = (maxLimit % 360 + 360) % 360; 
       state.totalDegrees = maxLimit;

       const slider = $("#slider").data("roundSlider");
       if (slider) slider.setValue(state.lastSliderValue);
       
       updateSpinner(state.totalDegrees, false);
       return;
    }

    state.lastSliderValue = newValue;
    state.currentTurns = potentialTurns;
    state.totalDegrees = potentialTotal;

    updateSpinner(state.totalDegrees, true);
}

// Main function export
export function updateSpinner(visualDegrees, isFromAuthoredInteraction = false, skipCalculation = false) {
    if (!isFromAuthoredInteraction) {
        const slider = $("#slider").data("roundSlider");
        if (slider) {
            const val = visualDegrees % 360;
            const normalizedVal = (val + 360) % 360;
            const turns = Math.floor(visualDegrees / 360);
            
            state.currentTurns = turns;
            state.lastSliderValue = normalizedVal;
            slider.setValue(normalizedVal);
        }
    }

    let totalMinutes = 0;
    const entryTime = new Date(state.entryTime);

    if (state.editMode === 'entry') {
        const baseTime = state.editingBaseEntryTime || entryTime;
        
        if (state.currentUnit === 'days') {
            const daysFromAngle = Math.floor((visualDegrees / 360) * 7);
            const newEntryDate = new Date(baseTime.getTime() + daysFromAngle * 24 * 60 * 60 * 1000);
            
            const day = String(newEntryDate.getDate()).padStart(2, '0');
            const month = String(newEntryDate.getMonth() + 1).padStart(2, '0');
            const year = newEntryDate.getFullYear();
            
            spinnerValue.innerHTML = `<span style="font-size: 24px;">${day}.${month}.${year}</span>`;
            
            // Updates global State entryTime
            updateEntryTimeDisplay(newEntryDate);
            
            const hours = String(newEntryDate.getHours()).padStart(2, '0');
            const mins = String(newEntryDate.getMinutes()).padStart(2, '0');
            state.entryTime = `${year}-${month}-${day} ${hours}:${mins}:00`;

        } else {
             const minutesFromAngle = Math.round((visualDegrees / 360) * 60);
             const newEntryTime = new Date(baseTime.getTime() + minutesFromAngle * 60000);
             
             const hours = String(newEntryTime.getHours()).padStart(2, '0');
             const mins = String(newEntryTime.getMinutes()).padStart(2, '0');
             
             spinnerValue.innerHTML = `${hours}:${mins}`;
             updateEntryTimeDisplay(newEntryTime);

             const year = newEntryTime.getFullYear();
             const month = String(newEntryTime.getMonth() + 1).padStart(2, '0');
             const day = String(newEntryTime.getDate()).padStart(2, '0');
             state.entryTime = `${year}-${month}-${day} ${hours}:${mins}:00`;
        }
        
        // Recalc fee
        const now = new Date();
        const newEntry = new Date(state.entryTime);
        const diffMs = now - newEntry;
        totalMinutes = Math.max(0, Math.floor(diffMs / 60000));
        state.addedMinutes = totalMinutes;

        if (!skipCalculation) {
            if (state.debounceTimer) clearTimeout(state.debounceTimer);
            setLoadingState();
            state.debounceTimer = setTimeout(() => {
                triggerFeeCalc(now);
            }, 1000);
        }
        return;
    }

    // EXIT MODE
    let exitBaseTime = entryTime;
    if (CONFIG.valid_to) exitBaseTime = new Date(CONFIG.valid_to);

    const scenario = state.getModeScenario();
    
    // Logic Mapping
    if (scenario === 'scenario_0_1_0' || scenario === 'scenario_0_1_1') {
        // Daily Multi
        const daysFromAngle = Math.round((visualDegrees / 360) * 7);
        state.selectedDays = daysFromAngle;
        const exitDate = new Date(exitBaseTime.getTime() + state.selectedDays * 24 * 60 * 60 * 1000);

        if (scenario === 'scenario_0_1_0') {
             const et = new Date(state.entryTime);
             exitDate.setHours(et.getHours(), et.getMinutes(), 0, 0);
        } else {
             exitDate.setHours(23, 59, 59, 999);
        }

        const day = String(exitDate.getDate()).padStart(2, '0');
        const month = String(exitDate.getMonth() + 1).padStart(2, '0');
        const year = exitDate.getFullYear();
        spinnerValue.innerHTML = `<span style="font-size: 24px;">${day}.${month}.${year}</span>`;
        
        updateExitTimeDisplay(exitDate);
        state.addedMinutes = Math.max(0, Math.floor((exitDate - entryTime) / 60000));

    } else if (scenario === 'scenario_1_0_0' || scenario === 'scenario_1_0_1') {
        // Hourly Single
        const minutesFromAngle = Math.round((visualDegrees / 360) * 60);
        state.selectedMinutes = minutesFromAngle;
        const exitTime = new Date(exitBaseTime.getTime() + state.selectedMinutes * 60000);
        
        // Validation Limit (EndOfDay)
        const baseDay = new Date(exitBaseTime);
        const endOfDay = new Date(baseDay);
        endOfDay.setHours(23, 59, 59, 999);
        
        if (exitTime > endOfDay) {
             // Visual Clamp
             const hours = String(endOfDay.getHours()).padStart(2, '0');
             const mins = String(endOfDay.getMinutes()).padStart(2, '0');
             spinnerValue.innerHTML = `${hours}:${mins}`;
             updateExitTimeDisplay(endOfDay);
             
             state.addedMinutes = Math.floor((endOfDay - entryTime) / 60000);
             // Use clamped time for calculation
             if (!skipCalculation) {
                 if (state.lastAddedMinutes !== state.addedMinutes) {
                     setLoadingState();
                     if (state.debounceTimer) clearTimeout(state.debounceTimer);
                     state.debounceTimer = setTimeout(() => {
                         state.lastAddedMinutes = state.addedMinutes;
                         triggerFeeCalc(endOfDay);
                     }, 1000);
                 }
             }
             return;
        }

        const hours = String(exitTime.getHours()).padStart(2, '0');
        const mins = String(exitTime.getMinutes()).padStart(2, '0');
        spinnerValue.innerHTML = `${hours}:${mins}`;
        updateExitTimeDisplay(exitTime);
        state.addedMinutes = Math.max(0, Math.floor((exitTime - entryTime) / 60000));

    } else if (scenario === 'scenario_1_1_0' || scenario === 'scenario_1_1_1') {
        // Hourly Multi
        if (state.currentUnit === 'days') {
             const daysFromAngle = Math.floor((visualDegrees / 360) * 7);
             state.selectedDays = daysFromAngle;
             const exitDate = new Date(exitBaseTime.getTime() + (state.selectedDays * 1440 + state.selectedMinutes) * 60000);
             
             const day = String(exitDate.getDate()).padStart(2, '0');
             const month = String(exitDate.getMonth() + 1).padStart(2, '0');
             const year = exitDate.getFullYear();
             spinnerValue.innerHTML = `<span style="font-size: 24px;">${day}.${month}.${year}</span>`;
             updateExitTimeDisplay(exitDate);

        } else {
             const minutesFromAngle = Math.round((visualDegrees / 360) * 60);
             state.selectedMinutes = minutesFromAngle;
             const exitTime = new Date(exitBaseTime.getTime() + (state.selectedDays * 1440 + state.selectedMinutes) * 60000);
             
             const hours = String(exitTime.getHours()).padStart(2, '0');
             const mins = String(exitTime.getMinutes()).padStart(2, '0');
             spinnerValue.innerHTML = `${hours}:${mins}`;
             updateExitTimeDisplay(exitTime);
        }
        state.addedMinutes = Math.max(0, Math.floor((state.currentExitTime - entryTime) / 60000));
    }

    // Validation against VALID_TO (Revert Logic)
    if (CONFIG.valid_to) {
        const validToDate = new Date(CONFIG.valid_to);
        if (state.currentExitTime < validToDate) {
             spinnerValue.innerHTML = `<span style="color:var(--error);">Limit!</span>`;
             setTimeout(() => {
                  // Restore visuals? Logic complex, fallback to next render
             }, 500);
             updateExitTimeDisplay(validToDate);
             // Skip fee calc if invalid?
             return;
        }
    }

    // Trigger Fee Calculation
    if (!(state.currentTimeMode === 'daily' && state.currentDurationMode === 'single_day')) {
         if (!skipCalculation && (state.lastAddedMinutes === null || state.lastAddedMinutes !== state.addedMinutes)) {
             setLoadingState();
             if (state.debounceTimer) clearTimeout(state.debounceTimer);
             state.debounceTimer = setTimeout(() => {
                 state.lastAddedMinutes = state.addedMinutes;
                 triggerFeeCalc(state.currentExitTime);
             }, 1000);
         }
    }
}

export function initSpinner() {
    $("#slider").roundSlider({
        radius: 100,
        width: 20,
        handleSize: "+8",
        sliderType: "min-range", // or default
        value: 0,
        max: 360,
        startAngle: 90,
        svgMode: true,
        borderWidth: 0,
        pathColor: "#E0E0E0",
        rangeColor: "var(--primary)",
        tooltipColor: "inherit",
        showTooltip: false,

        drag: function(e) { handleSliderChange(e.value); },
        change: function(e) { handleSliderChange(e.value); },
        start: function() { 
            state.isUserInteracted = true;
            if (state.clockInterval) clearInterval(state.clockInterval);
            state.clockInterval = null; 
        },
        stop: function() {
            if (state.currentTimeMode === 'hourly' && state.currentDurationMode === 'multi_day' && state.currentUnit === 'days') {
                if (state.editMode === 'entry') {
                    const btn = document.getElementById('entryTimeBtn');
                    if (btn) btn.click();
                } else {
                    const btn = document.getElementById('exitTimeBtn');
                    if (btn) btn.click();
                }
            }
        }
    });
}

export function setSliderState(enabled) {
    const slider = $("#slider").data("roundSlider");
    if (slider) {
        slider.option("readOnly", !enabled);
        if (spinnerContainer) {
            spinnerContainer.style.opacity = enabled ? '1' : '0.5';
            spinnerContainer.style.cursor = enabled ? 'default' : 'not-allowed';
        }
    }
}

export function updateSpinnerLabel() {
    if (spinnerLabel) {
        spinnerLabel.textContent = (state.editMode === 'entry') ? 'WJAZD' : 'WYJAZD';
    }
}
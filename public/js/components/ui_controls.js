/**
 * UI Controls Component
 * Handles the Matrix Logic Application, Config Buttons, and Entry/Exit Edit Switching.
 */
import { state } from '../state.js';
import { getScenarioConfig } from '../logic/scenarios.js';
import { updateSpinner, setSliderState, updateSpinnerLabel } from './spinner.js';
import { animateSection } from '../utils.js';
import { startRealTimeClock } from './clock.js';
import { API_SETTINGS, CONFIG, IS_EDITABLE_START, FEE_CONFIG } from '../config.js';

// DOM Elements
const entryCollapsed = document.getElementById('entryCollapsed');
const entryExpanded = document.getElementById('entryExpanded');
const exitCollapsed = document.getElementById('exitCollapsed');
const exitExpanded = document.getElementById('exitExpanded');
const editEntryBtn = document.getElementById('editEntryBtn');
const editExitBtnCollapsed = document.getElementById('editExitBtnCollapsed');
const closeEntryExpandedBtn = document.getElementById('closeEntryExpandedBtn');
const entryDateBtn = document.getElementById('entryDateBtn');
const entryTimeBtn = document.getElementById('entryTimeBtn');
const configButtons = document.querySelectorAll('.config-btn');
const exitDateBtn = document.getElementById('exitDateBtn');
const exitTimeBtn = document.getElementById('exitTimeBtn');
const exitTimeDisplayCollapsed = document.getElementById('exitTimeDisplayCollapsed');

// Main function to Apply Scenario Logic to DOM
export function initializeUI() {
    const scenario = state.getModeScenario();
    const config = getScenarioConfig(scenario);

    console.log("Initializing UI for Scenario:", scenario, config);

    // Visibility Overrides (Max Limit Logic)
    let { showSpinner, showExpandedExit, showCollapsedExit, dateEditable, timeEditable } = config;

    // RULE 4: Check "Max Limit" Override
    if (API_SETTINGS.ticket_exist == '1' && state.currentDurationMode === 'single_day' && CONFIG.valid_to) {
         const validTo = new Date(CONFIG.valid_to);
         const entryTime = new Date(state.entryTime);
         const endOfDay = new Date(entryTime);
         endOfDay.setHours(23, 59, 59, 999);
         
         if ((endOfDay - validTo) <= 15 * 60 * 1000) {
             showSpinner = false;
             showExpandedExit = false;
             showCollapsedExit = true;
         }
    }

    // Apply Visibility
    const spinnerContainer = document.getElementById('spinnerContainer');
    if (spinnerContainer) spinnerContainer.style.display = showSpinner ? '' : 'none';
    if (exitExpanded) exitExpanded.style.display = showExpandedExit ? 'block' : 'none';
    if (exitCollapsed) exitCollapsed.style.display = showCollapsedExit ? 'block' : 'none';

    // Apply Editable States to Buttons
    if (exitDateBtn) {
        if (dateEditable) {
            exitDateBtn.style.opacity = '1';
            exitDateBtn.style.pointerEvents = 'auto';
        } else {
            exitDateBtn.style.opacity = '0.5';
            exitDateBtn.style.pointerEvents = 'none';
            exitDateBtn.classList.remove('active');
        }
    }

    if (exitTimeBtn) {
         if (timeEditable) {
            exitTimeBtn.style.opacity = '1';
            exitTimeBtn.style.pointerEvents = 'auto';
        } else {
            exitTimeBtn.style.opacity = '0.5';
            exitTimeBtn.style.pointerEvents = 'none';
            exitTimeBtn.classList.remove('active');
        }
    }

    // Auto-select active button
    if (showExpandedExit) {
        if (dateEditable && !exitDateBtn.classList.contains('active') && !exitTimeBtn.classList.contains('active')) {
            exitDateBtn.classList.add('active');
            state.currentUnit = 'days';
        }
        if (!dateEditable && timeEditable) {
            exitTimeBtn.classList.add('active');
             state.currentUnit = 'minutes';
        }
    }

    // Collapsed View Logic
    if (showCollapsedExit) {
         if (editExitBtnCollapsed) editExitBtnCollapsed.style.display = 'none';
         
         let targetDate = new Date(); // Default to Now (for Hourly calc)
         if (targetDate < new Date(state.entryTime)) targetDate = new Date(state.entryTime);
         if (scenario === 'scenario_0_0_0') {
             targetDate.setDate(targetDate.getDate() + 1);
         } else if (scenario === 'scenario_0_0_1') {
             targetDate.setHours(23, 59, 59, 999);
         } else if (CONFIG.valid_to && API_SETTINGS.ticket_exist == '1') {
             targetDate = new Date(CONFIG.valid_to);
         }

         state.currentExitTime = targetDate;
         
         const year = targetDate.getFullYear();
         const month = String(targetDate.getMonth() + 1).padStart(2, '0');
         const day = String(targetDate.getDate()).padStart(2, '0');
         const hours = String(targetDate.getHours()).padStart(2, '0');
         const mins = String(targetDate.getMinutes()).padStart(2, '0');
         
         if (exitTimeDisplayCollapsed) {
             exitTimeDisplayCollapsed.textContent = `${year}-${month}-${day} ${hours}:${mins}`;
         }
    }

    // Single Day Start Date Restriction
    if (state.currentDurationMode === 'single_day' && state.currentTimeMode === 'hourly') {
         if (entryDateBtn) {
             entryDateBtn.classList.remove('active');
             entryDateBtn.style.opacity = '0.5';
             entryDateBtn.style.pointerEvents = 'none';
         }
         if (entryTimeBtn) entryTimeBtn.classList.add('active');
    }

    // Update State
    state.isEditable = showSpinner;
    setSliderState(state.isEditable);

    if (showSpinner) {
        // Initial call relies on updateSpinner
        // Note: For initial call, caller handles the calculation skip
        // But here we might be re-initializing.
        updateSpinner(state.totalDegrees, false, true); // Skip calculation on UI reset/re-init to avoid spam
        updateSpinnerLabel();
    }
}

export function initUIControls() {
    // Config Buttons
    configButtons.forEach(btn => {
        const configType = btn.dataset.config;
        const value = btn.dataset.value;
        
        // Initial State
        if ((configType === 'time_mode' && value === state.currentTimeMode) ||
            (configType === 'duration_mode' && value === state.currentDurationMode) ||
            (configType === 'day_counting' && value === state.currentDayCounting)) {
            btn.classList.add('active');
        }

        btn.addEventListener('click', () => {
            // Update UI
            document.querySelectorAll(`[data-config="${configType}"]`).forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            
            state.updateConfig(configType, value);
            state.selectedDays = 0;
            state.selectedMinutes = 0;
            state.currentUnit = 'days';

            initializeUI();
            
            state.isUserInteracted = false;
            startRealTimeClock();
        });
    });

    // Edit Entry Logic
    if (IS_EDITABLE_START) {
        if (editEntryBtn) {
             if (API_SETTINGS.ticket_exist == '1') {
                 editEntryBtn.style.display = 'none';
             } else {
                 editEntryBtn.style.display = 'flex';
             }
             
             editEntryBtn.addEventListener('click', (e) => {
                 if (API_SETTINGS.ticket_exist == '1') return;
                 e.stopPropagation();
                 enterEntryEditMode();
             });
        }
        
        if (entryCollapsed) {
            entryCollapsed.addEventListener('click', () => {
                 if (window.getComputedStyle(editEntryBtn).display !== 'none' && API_SETTINGS.ticket_exist != '1') {
                     enterEntryEditMode();
                 }
            });
        }
    }

    // Close/Save Entry Edit
    document.addEventListener('requestSaveEntry', saveEntryChanges);

    if (closeEntryExpandedBtn) {
        closeEntryExpandedBtn.addEventListener('click', saveEntryChanges);
    }

    if (editExitBtnCollapsed) {
        editExitBtnCollapsed.addEventListener('click', saveEntryChanges);
    }
    
    // Collapsed Exit Click
    if (exitCollapsed) {
        exitCollapsed.addEventListener('click', () => {
             if (window.getComputedStyle(editExitBtnCollapsed).display !== 'none') {
                 saveEntryChanges();
             }
        });
    }

    // Entry Date/Time Buttons
    if (entryDateBtn) {
        entryDateBtn.addEventListener('click', () => {
            if (state.editMode === 'entry') {
                state.currentUnit = 'days';
                state.editingBaseEntryTime = new Date(state.entryTime);
                entryDateBtn.classList.add('active');
                entryTimeBtn.classList.remove('active');
                updateSpinnerLabel();
                state.totalDegrees = 0;
                updateSpinner(0, false, true); // No fee calc on switch? Or yes?
                // Probably yes if visualDegrees is 0 and it changes time.
                // But logging 0 means "Same as base".
            }
        });
    }

    if (entryTimeBtn) {
        entryTimeBtn.addEventListener('click', () => {
            if (state.editMode === 'entry') {
                state.currentUnit = 'minutes';
                state.editingBaseEntryTime = new Date(state.entryTime);
                entryTimeBtn.classList.add('active');
                entryDateBtn.classList.remove('active');
                updateSpinnerLabel();
                state.totalDegrees = 0;
                updateSpinner(0, false, true);
            }
        });
    }

    // Exit Date/Time Buttons (Switching)
    if (exitDateBtn) {
        exitDateBtn.addEventListener('click', () => {
            if (state.currentTimeMode === 'hourly') {
                if (state.currentDurationMode === 'single_day') {
                     state.currentDurationMode = 'multi_day';
                     // Update config btn UI
                     const dBtn = document.querySelector(`.config-btn[data-value="multi_day"]`);
                     if (dBtn) {
                         const sBtn = document.querySelector(`.config-btn[data-value="single_day"]`);
                         if(sBtn) sBtn.classList.remove('active');
                         dBtn.classList.add('active');
                     }
                     state.isEditable = true;
                }
                state.currentUnit = 'days';
                exitDateBtn.classList.add('active');
                exitTimeBtn.classList.remove('active');
                updateSpinnerLabel();
                state.totalDegrees = 0;
                updateSpinner(0, false, false); // Calc fee?
            }
        });
    }

    if (exitTimeBtn) {
        exitTimeBtn.addEventListener('click', () => {
            if (state.currentTimeMode === 'hourly' && state.currentDurationMode === 'multi_day') {
                state.currentUnit = 'minutes';
                exitTimeBtn.classList.add('active');
                exitDateBtn.classList.remove('active');
                updateSpinnerLabel();
                state.totalDegrees = 0;
                updateSpinner(0, false, false);
            }
        });
    }
}

function enterEntryEditMode() {
    state.editMode = 'entry';
    state.editingBaseEntryTime = new Date(state.entryTime);

    // UI Switching
    if (entryCollapsed) entryCollapsed.style.display = 'none';
    if (entryExpanded) entryExpanded.style.display = 'block';
    if (exitExpanded) exitExpanded.style.display = 'none';
    if (exitCollapsed) exitCollapsed.style.display = 'block';

    animateSection(entryExpanded);
    animateSection(exitCollapsed);

    state.totalDegrees = 0;
    state.currentTurns = 0;
    state.currentUnit = 'days';
    
    updateSpinner(0, false, true); // Visual reset only
    updateSpinnerLabel();

    if (entryDateBtn) entryDateBtn.classList.add('active');
    if (entryTimeBtn) entryTimeBtn.classList.remove('active');

    state.isEditable = true;
    setSliderState(true);
}

function saveEntryChanges() {
    state.editingBaseEntryTime = null;
    state.editMode = 'exit';

    if (entryCollapsed) entryCollapsed.style.display = 'flex';
    if (entryExpanded) entryExpanded.style.display = 'none';

    animateSection(entryCollapsed);

    // Update Collapsed Display is handled by updateEntryTimeDisplay in spinner.js (which we called during edit)
    // Just need to re-init UI to show correct Exit view
    initializeUI();
    
    // Reset Spinner to 0 (Starting fresh for Exit editing)
    state.totalDegrees = 0;
    state.currentTurns = 0;
    updateSpinner(0, false, false); 
    updateSpinnerLabel();
}
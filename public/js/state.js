/**
 * State Module
 * Manages mutable application state.
 */
import { INITIAL_FEE, INITIAL_ENTRY_TIME, TIME_MODE, DURATION_MODE, DAY_COUNTING, API_SETTINGS } from './config.js';

class AppState {
    constructor() {
        this.currentFee = INITIAL_FEE;
        this.lastReceiptNumber = null;
        this.addedMinutes = 0;
        this.lastAddedMinutes = null;
        
        // Timer references
        this.debounceTimer = null;
        this.clockInterval = null;

        // Editing config
        this.currentUnit = 'days'; // 'days' or 'minutes'
        this.selectedDays = 0;
        this.selectedMinutes = 0;

        // Times
        this.entryTime = INITIAL_ENTRY_TIME; // String YYYY-MM-DD HH:MM:SS
        this.currentExitTime = new Date();
        this.editingBaseEntryTime = null;

        // Modes
        this.editMode = 'exit';
        this.currentTimeMode = TIME_MODE;
        this.currentDurationMode = DURATION_MODE;
        this.currentDayCounting = DAY_COUNTING;
        this.isEditable = false;

        // Slider/Spinner Logic
        this.currentTurns = 0;
        this.lastSliderValue = 0;
        this.totalDegrees = 0;
        this.isUserInteracted = false;
    }

    resetSpinnerState() {
        this.totalDegrees = 0;
        this.currentTurns = 0;
        this.lastSliderValue = 0;
        // Don't reset isUserInteracted automatically unless intentional
    }

    updateConfig(type, value) {
        if (type === 'time_mode') this.currentTimeMode = value;
        if (type === 'duration_mode') this.currentDurationMode = value;
        if (type === 'day_counting') this.currentDayCounting = value;
    }

    getModeScenario() {
        const type = this.currentTimeMode === 'hourly' ? '1' : '0';
        const multi = this.currentDurationMode === 'multi_day' ? '1' : '0';
        const starts = this.currentDayCounting === 'from_midnight' ? '1' : '0';
        return `scenario_${multi}_${type}_${starts}`;
    }
}

export const state = new AppState();
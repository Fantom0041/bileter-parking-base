/**
 * Clock Component
 * Handles the real-time clock and auto-updating spinner.
 */
import { state } from '../state.js';
import { CONFIG } from '../config.js';
import { updateSpinner } from './spinner.js';

export function startRealTimeClock() {
    if (state.clockInterval) clearInterval(state.clockInterval);

    // Update immediately
    updateRealTimeClock();

    state.clockInterval = setInterval(() => {
        if (!state.isUserInteracted) {
            updateRealTimeClock();
        }
    }, 1000);
}

function updateRealTimeClock() {
    if (state.isUserInteracted) return;

    let now = new Date();
    
    if (CONFIG.valid_to) {
         const validTo = new Date(CONFIG.valid_to);
         if (validTo > now) {
             now = validTo;
         }
    }

    let baseTime = new Date(state.entryTime);
    if (CONFIG.valid_to) {
        baseTime = new Date(CONFIG.valid_to);
    }

    const diffMs = now - baseTime;
    const diffMinutes = Math.max(0, Math.floor(diffMs / 60000));

    let newDegrees = 0;

    if (state.currentTimeMode === 'daily') {
        newDegrees = (diffMinutes / 10080) * 360;
    } else if (state.currentTimeMode === 'hourly') {
        if (state.currentDurationMode === 'single_day') {
            newDegrees = (diffMinutes / 60) * 360;
        } else {
            if (state.currentUnit === 'days') {
                newDegrees = (diffMinutes / 10080) * 360;
            } else {
                newDegrees = (diffMinutes / 60) * 360;
            }
        }
    }

    if (Math.abs(newDegrees - state.totalDegrees) < 0.001) {
        return;
    }

    state.totalDegrees = newDegrees;
    
    // IMPORTANT: When auto-updating clock, we usually don't want to re-calculate fee repeatedly 
    // unless necessary, but the logic in spinner.js handles changes in minutes.
    // We pass isFromAuthoredInteraction=false.
    // skipCalculation? If it's just clock ticking, we might want to update fee every 15 mins?
    // In Original script, updateRealTimeClock -> updateSpinner -> potentially fetchCalculatedFee.
    // Yes, we should allow it to calculate fee if time passes and fee changes.
    updateSpinner(state.totalDegrees, false, false); 
}
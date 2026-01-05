/**
 * Config Module
 * Bridges global PHP variables to ES modules.
 */

// Access globals defined in index.php
const globals = window;

export const TICKET_ID = globals.TICKET_ID;
export const INITIAL_FEE = globals.INITIAL_FEE;
export const HOURLY_RATE = globals.HOURLY_RATE;
export const API_SETTINGS = globals.API_SETTINGS;
export const SCENARIO_TEST_MODE = globals.SCENARIO_TEST_MODE || false;
export const CONFIG = globals.CONFIG;
export const IS_PAID = globals.IS_PAID;
export const ENTRY_TIME_RAW = globals.ENTRY_TIME_RAW;
export const IS_PRE_BOOKING = globals.IS_PRE_BOOKING;
export const IS_EDITABLE_START = globals.IS_EDITABLE_START;

// Initial Entry Time (will be managed by state, but useful as config/constant starter)
export const INITIAL_ENTRY_TIME = globals.ENTRY_TIME;

// Constants
export const TIME_MODE = CONFIG.time_mode;
export const DURATION_MODE = CONFIG.duration_mode;
export const DAY_COUNTING = CONFIG.day_counting;

export const FEE_CONFIG = {
    FEE_MULTI_DAY: API_SETTINGS.fee_multi_day_raw !== null ? parseInt(API_SETTINGS.fee_multi_day_raw) : (API_SETTINGS.duration_mode === 'multi_day' ? 1 : 0),
    FEE_TYPE: API_SETTINGS.fee_type_raw !== null && API_SETTINGS.fee_type_raw !== 'null' ? parseInt(API_SETTINGS.fee_type_raw) : (API_SETTINGS.time_mode === 'hourly' ? 1 : 0),
    FEE_STARTS_TYPE: API_SETTINGS.fee_starts_type_raw !== null ? parseInt(API_SETTINGS.fee_starts_type_raw) : (API_SETTINGS.day_counting === 'from_midnight' ? 1 : 0),
    TICKET_EXIST: parseInt(API_SETTINGS.ticket_exist || 0),
    VALID_TO: API_SETTINGS.valid_to ? new Date(API_SETTINGS.valid_to) : null
};
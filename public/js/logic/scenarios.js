/**
 * Scenarios Module
 * Handles UI logic based on scenarios (Matrix).
 */

export function getScenarioConfig(scenarioSignature) {
    // Defaults
    let config = {
        showSpinner: true,
        showExpandedExit: true,
        showCollapsedExit: false,
        dateEditable: false,
        timeEditable: false
    };

    switch (scenarioSignature) {
        case 'scenario_0_0_0': // Daily, Single, From Entry
            // STOP = Start + 1, Start Time.
            config.showSpinner = false;
            config.showExpandedExit = false;
            config.showCollapsedExit = true;
            break;

        case 'scenario_0_0_1': // Daily, Single, From Midnight
            // STOP = Start, 23:59:59.
            config.showSpinner = false;
            config.showExpandedExit = false;
            config.showCollapsedExit = true;
            break;

        case 'scenario_1_0_0': // Daily, Multi, From Entry (ID: 1_0_0)
        case 'scenario_1_0_1': // Daily, Multi, From Midnight (ID: 1_0_1)
            // STOP = Start + Days.
            config.showSpinner = true;
            config.showExpandedExit = true;
            config.showCollapsedExit = false;
            config.dateEditable = true; // Days spinner
            config.timeEditable = false;
            break;

        case 'scenario_0_1_0': // Hourly, Single, From Entry (ID: 0_1_0)
        case 'scenario_0_1_1': // Hourly, Single, From Midnight (ID: 0_1_1)
            // STOP = Start + Minutes.
            config.showSpinner = true;
            config.showExpandedExit = true;
            config.showCollapsedExit = false;
            config.dateEditable = false;
            config.timeEditable = true;
            break;

        case 'scenario_1_1_0': // Hourly, Multi, From Entry
        case 'scenario_1_1_1': // Hourly, Multi, From Midnight
            // Fully Editable
            config.showSpinner = true;
            config.showExpandedExit = true;
            config.showCollapsedExit = false;
            config.dateEditable = true;
            config.timeEditable = true;
            break;

        default:
            console.warn("Unknown Scenario:", scenarioSignature);
            config.showSpinner = true;
            config.showExpandedExit = true;
    }

    return config;
}
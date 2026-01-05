/**
 * API Module
 * Handles all server communication.
 */
import { API_SETTINGS, SCENARIO_TEST_MODE, TICKET_ID } from './config.js';
import { formatDateTime } from './utils.js';

// Helper to get effective ticket ID (Barcode > Plate)
function getEffectiveTicketId() {
    const ticketBarcode = API_SETTINGS.ticket_barcode;
    if (ticketBarcode && !isNaN(ticketBarcode) && Number(ticketBarcode) > 0) {
        return ticketBarcode;
    }
    return TICKET_ID;
}

export async function createTicket(plate) {
    const response = await fetch('api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'create', plate: plate })
    });

    if (!response.ok) {
        throw new Error(`Server error: ${response.status} ${response.statusText}`);
    }

    try {
        return await response.json();
    } catch (parseError) {
        console.error('Failed to parse response:', parseError);
        throw new Error('Server returned invalid response');
    }
}

export async function calculateFee(entryTimeStr, exitTimeDate) {
    const effectiveId = getEffectiveTicketId();
    // Entry Time should already be formatted or string "YYYY-MM-DD HH:MM:SS"
    // Exit Time is Date object
    
    // Safety check for Entry Time format
    const formattedEntry = entryTimeStr.length > 19 ? entryTimeStr.substring(0, 19) : entryTimeStr;

    const response = await fetch('api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            action: 'calculate_fee',
            ticket_id: effectiveId,
            entry_time: formattedEntry, 
            exit_time: formatDateTime(exitTimeDate)
        })
    });

    if (!response.ok) {
        throw new Error(`Server error: ${response.status}`);
    }

    return await response.json();
}

export async function processPayment(ticketId, amount, entryTimeStr, exitTimeDate) {
    const effectiveId = ticketId || getEffectiveTicketId();
    const formattedEntry = entryTimeStr.length > 19 ? entryTimeStr.substring(0, 19) : entryTimeStr;
    
    const response = await fetch('api.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            action: 'pay',
            ticket_id: effectiveId,
            amount: amount,
            entry_time: formattedEntry,
            exit_time: formatDateTime(exitTimeDate)
        })
    });

    if (!response.ok) {
        throw new Error(`Server error: ${response.status} ${response.statusText}`);
    }

    try {
        return await response.json();
    } catch (parseError) {
        console.error('Failed to parse response:', parseError);
        throw new Error('Server returned invalid response');
    }
}

export async function setPlate(ticketBarcode, newPlate) {
    const response = await fetch('api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ 
            action: 'set_plate', 
            ticket_id: ticketBarcode,
            new_plate: newPlate
        })
    });

    return await response.json();
}
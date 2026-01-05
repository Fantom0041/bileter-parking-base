/**
 * Main Entry Point
 */
import { initSpinner } from './components/spinner.js';
import { initPayment, updatePayButton } from './components/payment.js';
import { initModals } from './components/modals.js';
import { initUIControls, initializeUI } from './components/ui_controls.js';
import { createTicket } from './api.js';
import { showToast } from './utils.js';
import { SCENARIO_TEST_MODE, API_SETTINGS, INITIAL_FEE, CONFIG } from './config.js';
import { state } from './state.js';

document.addEventListener('DOMContentLoaded', () => {
    // --- New Ticket Form Handling ---
    const newTicketForm = document.getElementById('newTicketForm');
    if (newTicketForm) {
        newTicketForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const plate = document.getElementById('plateInput').value;
            const btn = newTicketForm.querySelector('button');
            const originalText = btn.innerText;
            btn.innerText = 'Ładowanie...';
            btn.disabled = true;

            try {
                const data = await createTicket(plate);

                if (data.success) {
                     if (data.simulated) {
                        window.location.href = `index.php?ticket_id=${data.ticket_id}&simulated=1`;
                    } else {
                        window.location.href = `index.php?ticket_id=${data.ticket_id}`;
                    }
                } else {
                    console.error('Błąd: ' + data.message);
                    const displayMsg = SCENARIO_TEST_MODE 
                        ? `API Error: ${data.message}` 
                        : "Wystąpił błąd podczas tworzenia biletu.";
                    showToast(displayMsg);
                    
                    btn.innerText = originalText;
                    btn.disabled = false;
                }
            } catch (error) {
                console.error(error);
                const displayMsg = SCENARIO_TEST_MODE 
                    ? `Network/Server Error: ${error.message}` 
                    : "Wystąpił błąd podczas komunikacji z serwerem.";
                showToast(displayMsg);
                
                btn.innerText = originalText;
                btn.disabled = false;
            }
        });
        
        // Return early if we are on the new ticket page (logic from script.js)
        return; 
    }

    // --- Existing Ticket Logic ---
    
    // Initialize Round Slider
    initSpinner();
    
    // Initialize Modals (Plate Edit)
    initModals();
    
    // Initialize UI Controls (Config buttons, Date/Time buttons)
    initUIControls();

    // Initialize Payment Logic
    initPayment();

    // Initial UI Render
    // This sets up the correct scenarios, visibility, and initial spinner values.
    initializeUI();
    
    // Initial Pay Button State
    // We update it manually here to ensure it reflects the PHP-passed INITIAL_FEE
    // without waiting for a fetch.
    updatePayButton();

    // SVG Filter Fix (if needed, though it's in HTML)
});
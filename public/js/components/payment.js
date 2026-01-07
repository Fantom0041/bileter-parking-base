/**
 * Payment Component
 * Handles payment flow and success states.
 */
import { state } from '../state.js';
import { processPayment } from '../api.js';
import { showToast, formatDateTime } from '../utils.js';
import { CONFIG, SCENARIO_TEST_MODE, API_SETTINGS, ENTRY_TIME_RAW } from '../config.js';

const payButton = document.getElementById('payButton');
const paymentSheet = document.getElementById('paymentSheet');
const successOverlay = document.getElementById('successOverlay');
const qrCode = document.getElementById('qrCode');
const successTicketContainer = document.getElementById('successTicketContainer');

export function initPayment() {
    if (!payButton) return;

    payButton.addEventListener('click', async () => {
        // Check if we're in save mode (editing entry time)
        if (payButton.classList.contains('save-mode')) {
             // Dispatch event for UI controls to handle saving
             document.dispatchEvent(new CustomEvent('requestSaveEntry'));
             return;
        }

        state.isUserInteracted = true;
        if (state.clockInterval) clearInterval(state.clockInterval);

        // Time Drift Check
        const serverTimeStub = new Date(); 
        if (state.currentExitTime < serverTimeStub) {
            if (serverTimeStub.getTime() - state.currentExitTime.getTime() > 60000) {
                console.warn('Selected exit time is in the past. Auto-correcting to NOW.');
                showToast('Wybrany czas wyjazdu jest w przeszłości. Auto-korekta do teraz.');
                state.currentExitTime = serverTimeStub;
            }
        }

        const originalText = payButton.innerText;
        payButton.innerText = 'Przetwarzanie...';
        payButton.disabled = true;

        try {
            // Force seconds to 00 for Entry Time
            const entryTimeClean = state.entryTime.substring(0, 16) + ':00';
            
            const data = await processPayment(
                null, // api.js handles getEffectiveTicketId
                state.currentFee,
                entryTimeClean,
                state.currentExitTime
            );

            if (data.success) {
                if (data.receipt_number) {
                    qrCode.innerHTML = `<span style="font-size:18px; font-weight:700">OPŁACONY DO:<br>${data.valid_to}</span>`;
                    // state.lastReceiptNumber = data.receipt_number; // No longer needed for display, but keeping data might be useful if other logic uses it.
                    // Actually user asked to remove "numer paragonu", so I will focus on the display.
                    // The valid_to is available in data.valid_to as seen in line 68.
                } else if (data.new_qr_code) {
                    qrCode.innerText = data.new_qr_code;
                    if(data.new_qr_code.startsWith('REC-')) {
                         state.lastReceiptNumber = data.new_qr_code.split('-')[1];
                    }
                } else {
                    qrCode.innerText = "OPŁACONO";
                }
                
                if (data.valid_to) {
                    const paymentInfoExitValue = document.getElementById('paymentInfoExitValue');
                    if (paymentInfoExitValue) {
                        paymentInfoExitValue.textContent = data.valid_to;
                        paymentInfoExitValue.style.opacity = '1';
                    }
                    CONFIG.valid_to = data.valid_to;
                }
                
                if (data.fee_paid !== undefined) {
                    const feePaidValue = document.getElementById('feePaidValue');
                    if (feePaidValue) {
                        feePaidValue.innerText = parseFloat(data.fee_paid).toFixed(2);
                    }
                    API_SETTINGS.fee_paid = data.fee_paid * 100; // Keep in sync with raw grosze if needed
                }

                state.currentFee = 0;
                updatePayButton();

                successOverlay.classList.add('visible');
                paymentSheet.style.transform = 'translate(-50%, 100%)';
            } else {
                console.error('Płatność nieudana: ' + data.message);
                const displayMsg = SCENARIO_TEST_MODE 
                    ? `Payment Error: ${data.message}` 
                    : "Płatność nieudana. Spróbuj ponownie.";
                showToast(displayMsg);
                
                payButton.innerText = originalText;
                payButton.disabled = false;
            }
        } catch (error) {
            console.error('Error:', error);
            const displayMsg = SCENARIO_TEST_MODE 
                ? `Payment Network Error: ${error.message}` 
                : "Wystąpił błąd podczas płatności.";
            showToast(displayMsg);

            payButton.innerText = originalText;
            payButton.disabled = false;
        }
    });

    // Handle PDF Download
    if (successTicketContainer) {
        successTicketContainer.addEventListener('click', () => {
            if (state.lastReceiptNumber) {
                window.location.href = `api.php?action=download_receipt&receipt_number=${state.lastReceiptNumber}`;
            } else {
                console.warn('Numer paragonu nie jest dostępny.');
            }
        });
    }
}

// Global Event Listener for Fee Updates to update button state
document.addEventListener('feeUpdated', (e) => {
    updatePayButton();
});

document.addEventListener('feeUpdateFailed', () => {
    if (payButton) {
        // Reset to initial?
         // updatePayButton(); 
         // Leave it disabled? 
    }
});

export function updatePayButton() {
    if (!payButton) return;
    if (payButton.classList.contains('save-mode')) return;

    if (state.currentFee <= 0) {
        const feePaid = API_SETTINGS.fee_paid || 0;
        const ticketExist = API_SETTINGS.ticket_exist == 1;

        if (ticketExist && feePaid > 0) {
             payButton.textContent = "Do zapłaty: 0,00 " + CONFIG.currency;
             payButton.classList.add('btn-glass');
             payButton.disabled = true;
        } else {
             payButton.textContent = "Do zapłaty: 0,00 " + CONFIG.currency;
             payButton.classList.add('btn-glass');
             payButton.disabled = true;
        }
    } else {
        payButton.textContent = "Zapłać " + state.currentFee.toFixed(2) + " " + CONFIG.currency;
        payButton.classList.remove('btn-glass');
        payButton.disabled = false;
    }
}
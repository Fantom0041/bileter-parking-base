/**
 * Modals Component
 * Handles Plate Edit, Confirmation modals.
 */
import { setPlate } from '../api.js';
import { showToast } from '../utils.js';
import { API_SETTINGS, SCENARIO_TEST_MODE } from '../config.js';

// DOM Elements
const plateDisplay = document.getElementById('plateDisplay');
const licensePlateContainer = document.getElementById('licensePlateContainer');
const editPlateBtn = document.getElementById('editPlateBtn');

const plateEditModal = document.getElementById('plateEditModal');
const plateSheetInput = document.getElementById('plateSheetInput');
const savePlateBtn = document.getElementById('savePlateBtn');
const cancelPlateEditBtn = document.getElementById('cancelPlateEdit');

const plateConfirmModal = document.getElementById('plateConfirmModal');
const confirmPlateValue = document.getElementById('confirmPlateValue');
const cancelPlateChange = document.getElementById('cancelPlateChange');
const confirmPlateChange = document.getElementById('confirmPlateChange');

let pendingNewPlate = "";

function openPlateModal() {
    if (!plateEditModal) return;
    if (plateDisplay) {
        plateSheetInput.value = plateDisplay.innerText.trim();
    }
    plateEditModal.classList.add('visible');
    setTimeout(() => {
         plateSheetInput.focus();
    }, 100);
}

function closePlateModal() {
    if (!plateEditModal) return;
    plateEditModal.classList.remove('visible');
    plateSheetInput.blur();
}

export function initModals() {
    if (licensePlateContainer) {
        licensePlateContainer.addEventListener('click', () => openPlateModal());
    }

    if (editPlateBtn) {
        editPlateBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            openPlateModal();
        });
    }

    if (cancelPlateEditBtn) cancelPlateEditBtn.addEventListener('click', closePlateModal);
    
    if (plateEditModal) {
        plateEditModal.addEventListener('click', (e) => {
            if (e.target === plateEditModal) closePlateModal();
        });
    }

    if (plateSheetInput) {
        plateSheetInput.addEventListener('input', (e) => {
            e.target.value = e.target.value.toUpperCase();
        });
        plateSheetInput.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') savePlateBtn.click();
        });
    }

    if (savePlateBtn) {
        savePlateBtn.addEventListener('click', () => {
            const val = plateSheetInput.value.trim().toUpperCase();
            if (val.length < 2) {
                console.warn('Numer rejestracyjny jest za krótki.');
                return;
            }
            if (plateDisplay && val === plateDisplay.innerText.trim()) {
                closePlateModal();
                return;
            }

            pendingNewPlate = val;
            closePlateModal();
            if (confirmPlateValue) confirmPlateValue.innerText = pendingNewPlate;
            if (plateConfirmModal) plateConfirmModal.classList.add('visible');
        });
    }

    if (cancelPlateChange) {
        cancelPlateChange.addEventListener('click', () => {
             if (plateConfirmModal) plateConfirmModal.classList.remove('visible');
        });
    }

    if (confirmPlateChange) {
        confirmPlateChange.addEventListener('click', async () => {
             if (plateConfirmModal) plateConfirmModal.classList.remove('visible');
             
             const ticketBarcode = API_SETTINGS.ticket_barcode;
             const isBarcodeValid = ticketBarcode && !isNaN(ticketBarcode) && Number(ticketBarcode) > 0;
             
             if (isBarcodeValid) {
                 const originalText = confirmPlateChange.innerText;
                 confirmPlateChange.innerText = 'Zapisywanie...';
                 confirmPlateChange.disabled = true;

                 try {
                     const data = await setPlate(ticketBarcode, pendingNewPlate);

                     if (data.success) {
                         window.location.reload();
                     } else {
                         const displayMsg = SCENARIO_TEST_MODE 
                            ? `Plate Change Error: ${data.message}` 
                            : "Nie udało się zmienić numeru rejestracyjnego.";
                         showToast(displayMsg);
                     }
                 } catch (error) {
                     const displayMsg = SCENARIO_TEST_MODE 
                        ? `Plate Change Network Error: ${error.message}` 
                        : "Błąd połączenia.";
                     showToast(displayMsg);
                 } finally {
                     confirmPlateChange.innerText = originalText;
                     confirmPlateChange.disabled = false;
                 }
             } else {
                 window.location.href = `index.php?ticket_id=${encodeURIComponent(pendingNewPlate)}`;
             }
        });
    }
}
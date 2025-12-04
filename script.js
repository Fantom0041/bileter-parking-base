document.addEventListener('DOMContentLoaded', () => {
    // --- NOWA SESJA PARKOWANIA ---
    const newTicketForm = document.getElementById('newTicketForm');
    if (newTicketForm) {
        newTicketForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const plate = document.getElementById('plateInput').value;
            const btn = newTicketForm.querySelector('button');
            const originalText = btn.innerText;
            btn.innerText = 'Tworzenie...';
            btn.disabled = true;

            try {
                const response = await fetch('api.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'create', plate: plate })
                });

                // Check if response is OK before parsing JSON
                if (!response.ok) {
                    throw new Error(`Server error: ${response.status} ${response.statusText}`);
                }

                // Try to parse JSON, catch parse errors
                let data;
                try {
                    data = await response.json();
                } catch (parseError) {
                    console.error('Failed to parse response:', parseError);
                    const text = await response.text();
                    console.error('Response text:', text);
                    throw new Error('Server returned invalid response');
                }

                if (data.success) {
                    window.location.href = `index.php?ticket_id=${data.ticket_id}`;
                } else {
                    alert('Błąd: ' + data.message);
                    btn.innerText = originalText;
                    btn.disabled = false;
                }
            } catch (error) {
                console.error(error);
                alert('Błąd sieci: ' + error.message);
                btn.innerText = originalText;
                btn.disabled = false;
            }
        });
        return; // Stop execution if we are on the "New Ticket" page
    }

    // --- ISTNIEJĄCY BILET PARKINGOWY ---
    let currentFee = INITIAL_FEE;
    let addedMinutes = 0;
    let debounceTimer = null;
    let totalRotations = 0; // Liczba pełnych obrotów (360°)

    // Elements
    // const displayPrice = document.getElementById('displayPrice'); // Removed
    const payButton = document.getElementById('payButton');
    const paymentSheet = document.getElementById('paymentSheet');
    const successOverlay = document.getElementById('successOverlay');
    const qrCode = document.getElementById('qrCode');

    // Spinner Elements
    const spinnerContainer = document.getElementById('spinnerContainer');
    const progressCircle = document.getElementById('progressCircle');
    const spinnerHandle = document.getElementById('spinnerHandle');
    const spinnerValue = document.getElementById('spinnerValue');

    // Spinner Config
    const RADIUS = 100;
    const CIRCUMFERENCE = 2 * Math.PI * RADIUS;
    const CENTER = { x: 120, y: 120 };
    let isDragging = false;
    let lastAngle = 0; // Śledzenie poprzedniego kąta dla wykrywania obrotów

    // Initialize Spinner
    if (progressCircle) {
        progressCircle.style.strokeDasharray = `${CIRCUMFERENCE} ${CIRCUMFERENCE}`;
        progressCircle.style.strokeDashoffset = CIRCUMFERENCE; // Start empty

        // Make spinner container draggable with visual feedback
        spinnerContainer.style.cursor = 'grab';
        spinnerContainer.style.userSelect = 'none';

        // Event Listeners for Dragging - consolidated on container
        spinnerContainer.addEventListener('mousedown', startDrag);
        spinnerContainer.addEventListener('touchstart', startDrag, { passive: false });

        document.addEventListener('mousemove', drag);
        document.addEventListener('touchmove', drag, { passive: false });

        document.addEventListener('mouseup', endDrag);
        document.addEventListener('touchend', endDrag);
    }

    function startDrag(e) {
        // Nie pozwalaj na zmianę czasu, jeśli bilet jest już opłacony
        if (typeof IS_PAID !== 'undefined' && IS_PAID) return;

        e.preventDefault();
        e.stopPropagation();

        isDragging = true;
        spinnerContainer.style.cursor = 'grabbing';
        drag(e);
    }

    function drag(e) {
        if (!isDragging) return;

        // Prevent default to avoid scrolling on mobile
        if (e.cancelable) {
            e.preventDefault();
        }

        // Get coordinates from touch or mouse event
        let clientX, clientY;
        if (e.type.startsWith('touch')) {
            if (e.touches && e.touches.length > 0) {
                clientX = e.touches[0].clientX;
                clientY = e.touches[0].clientY;
            } else {
                return; // No touch points available
            }
        } else {
            clientX = e.clientX;
            clientY = e.clientY;
        }

        // Calculate angle relative to center of spinner
        const rect = spinnerContainer.getBoundingClientRect();
        const centerX = rect.left + rect.width / 2;
        const centerY = rect.top + rect.height / 2;

        const dx = clientX - centerX;
        const dy = clientY - centerY;

        // Angle in radians (atan2 returns -PI to PI)
        // We want 0 at top (-90deg), so we rotate coordinates
        let angle = Math.atan2(dy, dx);

        // Convert to degrees and normalize to 0-360 starting from top
        let degrees = angle * (180 / Math.PI) + 90;
        if (degrees < 0) degrees += 360;

        updateSpinner(degrees);
    }

    function endDrag() {
        isDragging = false;
        if (spinnerContainer) {
            spinnerContainer.style.cursor = 'grab';
        }
    }

    function updateSpinner(degrees) {
        // Wykryj przejście przez 0° (pełny obrót)
        if (lastAngle > 270 && degrees < 90) {
            // Obrót do przodu (przejście z 359° do 0°)
            totalRotations++;
        } else if (lastAngle < 90 && degrees > 270) {
            // Obrót do tyłu (przejście z 0° do 359°)
            if (totalRotations > 0) totalRotations--;
        }
        lastAngle = degrees;

        // 1. Update Handle Position
        const radians = (degrees - 90) * (Math.PI / 180);
        const x = CENTER.x + RADIUS * Math.cos(radians);
        const y = CENTER.y + RADIUS * Math.sin(radians);
        spinnerHandle.setAttribute('transform', `translate(${x - 120}, ${y - 20})`);

        // 2. Update Progress Arc
        const offset = CIRCUMFERENCE - (degrees / 360) * CIRCUMFERENCE;
        progressCircle.style.strokeDashoffset = offset;

        // 3. Oblicz całkowity czas: pełne obroty (dni) + aktualny kąt (godziny w bieżącym dniu)
        // 1 pełny obrót (360°) = 24 godziny = 1440 minut
        const minutesFromRotations = totalRotations * 1440;
        const minutesFromCurrentAngle = Math.round((degrees / 360) * 1440);
        const totalMinutes = minutesFromRotations + minutesFromCurrentAngle;

        // Snap to 15 min increments
        const snappedMinutes = Math.ceil(totalMinutes / 15) * 15;
        addedMinutes = snappedMinutes;

        // Oblicz datę i godzinę wyjazdu
        const now = new Date();
        const exitTime = new Date(now.getTime() + snappedMinutes * 60000);
        const exitDay = String(exitTime.getDate()).padStart(2, '0');
        const exitMonth = String(exitTime.getMonth() + 1).padStart(2, '0');
        const exitHours = String(exitTime.getHours()).padStart(2, '0');
        const exitMins = String(exitTime.getMinutes()).padStart(2, '0');

        // Wyświetl datę i godzinę
        spinnerValue.innerHTML = `${exitDay}.${exitMonth}<br><small>${exitHours}:${exitMins}</small>`;

        // Clear existing debounce timer
        if (debounceTimer) {
            clearTimeout(debounceTimer);
        }

        // Set loading state immediately
        setLoadingState();

        // Set new debounce timer (1000ms)
        debounceTimer = setTimeout(() => {
            fetchCalculatedFee(snappedMinutes);
        }, 1000);
    }

    function setLoadingState() {
        payButton.innerText = 'Obliczanie opłaty...';
        payButton.disabled = true;
    }

    async function fetchCalculatedFee(extensionMinutes) {
        try {
            const response = await fetch('api.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'calculate_fee',
                    ticket_id: TICKET_ID,
                    extension_minutes: extensionMinutes
                })
            });

            if (!response.ok) {
                throw new Error(`Server error: ${response.status}`);
            }

            const data = await response.json();

            if (data.success) {
                currentFee = data.fee;
                updatePayButton();
            } else {
                console.error('Fee calculation failed:', data.message);
                // Reset to initial fee on error
                currentFee = INITIAL_FEE;
                updatePayButton();
            }
        } catch (error) {
            console.error('Error fetching fee:', error);
            // Reset to initial fee on error
            currentFee = INITIAL_FEE;
            updatePayButton();
        }
    }

    function updatePayButton() {
        if (currentFee > 0) {
            payButton.innerText = `Zapłać ${currentFee.toFixed(2)} zł`;
            payButton.disabled = false;
        } else {
            payButton.innerText = 'Wyjazd bez opłaty';
            payButton.disabled = false;
        }
    }

    // 3. Obsługa płatności
    payButton.addEventListener('click', async () => {
        const originalText = payButton.innerText;
        payButton.innerText = 'Przetwarzanie...';
        payButton.disabled = true;

        try {
            const response = await fetch('api.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    ticket_id: TICKET_ID,
                    amount: currentFee
                })
            });

            // Check if response is OK before parsing JSON
            if (!response.ok) {
                throw new Error(`Server error: ${response.status} ${response.statusText}`);
            }

            // Try to parse JSON, catch parse errors
            let data;
            try {
                data = await response.json();
            } catch (parseError) {
                console.error('Failed to parse response:', parseError);
                const text = await response.text();
                console.error('Response text:', text);
                throw new Error('Server returned invalid response');
            }

            if (data.success) {
                qrCode.innerText = data.new_ticket_qr;
                successOverlay.classList.add('visible');
                paymentSheet.style.transform = 'translate(-50%, 100%)';
            } else {
                alert('Płatność nieudana: ' + data.message);
                payButton.innerText = originalText;
                payButton.disabled = false;
            }
        } catch (error) {
            console.error('Error:', error);
            alert('Wystąpił błąd: ' + error.message);
            payButton.innerText = originalText;
            payButton.disabled = false;
        }
    });
});

// 4. Edycja numeru rejestracyjnego
const editPlateBtn = document.getElementById('editPlateBtn');
const plateDisplay = document.getElementById('plateDisplay');
const plateInput = document.getElementById('plateEditInput');

if (editPlateBtn && plateDisplay && plateInput) {
    editPlateBtn.addEventListener('click', () => {
        plateDisplay.style.display = 'none';
        plateInput.style.display = 'block';
        plateInput.focus();
        editPlateBtn.style.display = 'none';
    });

    function savePlate() {
        const newPlate = plateInput.value.toUpperCase();
        if (newPlate.trim() !== "") {
            plateDisplay.innerText = newPlate;
        }
        plateDisplay.style.display = 'flex';
        plateInput.style.display = 'none';
        editPlateBtn.style.display = 'flex';
    }

    plateInput.addEventListener('blur', savePlate);
    plateInput.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
            savePlate();
        }
    });
}


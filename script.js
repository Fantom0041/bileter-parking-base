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

    // Parking mode: 'daily' or 'multiday'
    let parkingMode = 'daily';

    // Multi-day mode state: 'days' or 'hours'
    let multidayUnit = 'days';
    let selectedDays = 0;
    let selectedHours = 0;

    // Elements
    // const displayPrice = document.getElementById('displayPrice'); // Removed
    const payButton = document.getElementById('payButton');
    const paymentSheet = document.getElementById('paymentSheet');
    const successOverlay = document.getElementById('successOverlay');
    const qrCode = document.getElementById('qrCode');

    // Mode selector elements
    const modeButtons = document.querySelectorAll('.mode-btn');
    const multidayToggle = document.getElementById('multidayToggle');
    const toggleUnitBtn = document.getElementById('toggleUnitBtn');
    const toggleLabel = document.getElementById('toggleLabel');
    const spinnerLabel = document.getElementById('spinnerLabel');

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

    // Mode switching
    modeButtons.forEach(btn => {
        btn.addEventListener('click', () => {
            // Remove active class from all buttons
            modeButtons.forEach(b => b.classList.remove('active'));
            // Add active class to clicked button
            btn.classList.add('active');

            // Update parking mode
            parkingMode = btn.dataset.mode;

            // Show/hide multiday toggle
            if (parkingMode === 'multiday') {
                multidayToggle.style.display = 'block';
                multidayUnit = 'days';
                selectedDays = 0;
                selectedHours = 0;
                updateToggleButton();
            } else {
                multidayToggle.style.display = 'none';
            }

            // Reset spinner
            totalRotations = 0;
            lastAngle = 0;
            updateSpinner(0);
        });
    });

    // Toggle between days and hours in multiday mode
    if (toggleUnitBtn) {
        toggleUnitBtn.addEventListener('click', () => {
            if (multidayUnit === 'days') {
                multidayUnit = 'hours';
                totalRotations = 0;
                lastAngle = 0;
            } else {
                multidayUnit = 'days';
                totalRotations = 0;
                lastAngle = 0;
            }
            updateToggleButton();
            updateSpinner(0);
        });
    }

    function updateToggleButton() {
        if (multidayUnit === 'days') {
            toggleLabel.textContent = 'Wybierz dni';
            toggleUnitBtn.classList.remove('selecting-hours');
            spinnerLabel.textContent = 'DNI';
        } else {
            toggleLabel.textContent = 'Wybierz godziny';
            toggleUnitBtn.classList.add('selecting-hours');
            spinnerLabel.textContent = 'GODZINY';
        }
    }

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
        // 1. Update Handle Position
        const radians = (degrees - 90) * (Math.PI / 180);
        const x = CENTER.x + RADIUS * Math.cos(radians);
        const y = CENTER.y + RADIUS * Math.sin(radians);
        spinnerHandle.setAttribute('transform', `translate(${x - 120}, ${y - 20})`);

        // 2. Update Progress Arc
        const offset = CIRCUMFERENCE - (degrees / 360) * CIRCUMFERENCE;
        progressCircle.style.strokeDashoffset = offset;

        let totalMinutes = 0;

        if (parkingMode === 'daily') {
            // TRYB DOBOWY: 1 obrót = 24 godziny, max 23:59
            // Oblicz minuty z kąta (0-1439 minut)
            const minutesFromAngle = Math.round((degrees / 360) * 1440);

            // Ogranicz do maksymalnie 23:59 (1439 minut)
            totalMinutes = Math.min(minutesFromAngle, 1439);

            // Przyrost co 1 minutę
            addedMinutes = totalMinutes;

            // Oblicz godzinę wyjazdu
            const hours = Math.floor(addedMinutes / 60);
            const mins = addedMinutes % 60;

            // Wyświetl czas
            spinnerValue.innerHTML = `${String(hours).padStart(2, '0')}:${String(mins).padStart(2, '0')}<small>/h</small>`;
            spinnerLabel.textContent = 'WYJAZD';

        } else if (parkingMode === 'multiday') {
            // TRYB WIELODNIOWY
            if (multidayUnit === 'days') {
                // Wybór dni: pełny obrót = 30 dni
                const daysFromAngle = Math.floor((degrees / 360) * 30);
                selectedDays = daysFromAngle;

                // Wyświetl liczbę dni
                spinnerValue.innerHTML = `${selectedDays}<small>dni</small>`;
                spinnerLabel.textContent = 'DNI';

                // Oblicz minuty (dni * 24h * 60min)
                totalMinutes = selectedDays * 1440 + selectedHours * 60;

            } else {
                // Wybór godzin: pełny obrót = 24 godziny
                const hoursFromAngle = Math.floor((degrees / 360) * 24);
                selectedHours = hoursFromAngle;

                // Wyświetl liczbę godzin
                spinnerValue.innerHTML = `${selectedHours}<small>h</small>`;
                spinnerLabel.textContent = 'GODZINY';

                // Oblicz minuty
                totalMinutes = selectedDays * 1440 + selectedHours * 60;
            }

            addedMinutes = totalMinutes;
        }

        // Clear existing debounce timer
        if (debounceTimer) {
            clearTimeout(debounceTimer);
        }

        // Set loading state immediately
        setLoadingState();

        // Set new debounce timer (1000ms)
        debounceTimer = setTimeout(() => {
            fetchCalculatedFee(addedMinutes);
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


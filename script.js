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

    // Elements
    const displayPrice = document.getElementById('displayPrice');
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
        // Convert degrees back to radians for position calc (subtract 90 to match SVG coordinate system)
        const radians = (degrees - 90) * (Math.PI / 180);
        const x = CENTER.x + RADIUS * Math.cos(radians);
        const y = CENTER.y + RADIUS * Math.sin(radians);

        spinnerHandle.setAttribute('transform', `translate(${x - 120}, ${y - 20})`); // Offset by handle initial pos

        // 2. Update Progress Arc
        const offset = CIRCUMFERENCE - (degrees / 360) * CIRCUMFERENCE;
        progressCircle.style.strokeDashoffset = offset;

        // 3. Wylicz czas i opłatę
        // Załóżmy, że 1 pełne okrążenie = 4 godziny (240 minut)
        const totalMinutes = Math.round((degrees / 360) * 240);

        // Snap to 15 min increments
        const snappedMinutes = Math.ceil(totalMinutes / 15) * 15;

        // Update Text
        const hours = Math.floor(snappedMinutes / 60);
        const mins = snappedMinutes % 60;
        spinnerValue.innerHTML = `${String(hours).padStart(2, '0')}:${String(mins).padStart(2, '0')}<small>/h</small>`;

        // Update Fee
        addedMinutes = snappedMinutes;
        updatePrice();
    }

    function updatePrice() {
        const additionalHours = Math.ceil(addedMinutes / 60);
        const additionalFee = additionalHours * HOURLY_RATE;

        currentFee = INITIAL_FEE + additionalFee;

        displayPrice.innerText = currentFee.toFixed(2);

        if (currentFee > 0) {
            payButton.innerText = `Zapłać ${currentFee.toFixed(2)}`;
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

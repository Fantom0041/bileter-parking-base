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

    // Current editing unit for multi-day modes
    let currentUnit = 'days'; // 'days' or 'minutes'
    let selectedDays = 0;
    let selectedMinutes = 0;

    // Track calculated exit time for validation
    let currentExitTime = new Date();

    // Edit mode: 'exit' or 'entry'
    let editMode = 'exit'; // Default to editing exit time

    // Elements
    const payButton = document.getElementById('payButton');
    const paymentSheet = document.getElementById('paymentSheet');
    const successOverlay = document.getElementById('successOverlay');
    const qrCode = document.getElementById('qrCode');

    // UI elements
    const spinnerLabel = document.getElementById('spinnerLabel');
    const configButtons = document.querySelectorAll('.config-btn');
    const exitDateBtn = document.getElementById('exitDateBtn');
    const exitTimeBtn = document.getElementById('exitTimeBtn');
    const exitDateValue = document.getElementById('exitDateValue');
    const exitTimeValue = document.getElementById('exitTimeValue');

    // Entry time UI elements
    const entryDateBtn = document.getElementById('entryDateBtn');
    const entryTimeBtn = document.getElementById('entryTimeBtn');
    const entryDateValue = document.getElementById('entryDateValue');
    const entryTimeValue = document.getElementById('entryTimeValue');

    // Current mode configuration (can be changed by user)
    let currentTimeMode = TIME_MODE;
    let currentDurationMode = DURATION_MODE;
    let currentDayCounting = DAY_COUNTING;

    // Determine if spinner should be editable
    // Editable for: multi_day (all) OR hourly+single_day
    // NOT editable for: daily+single_day
    let isEditable = currentDurationMode === 'multi_day' || currentTimeMode === 'hourly';

    // Determine if we need unit selector (only for hourly + multi_day) - Logic kept for internal state if needed, but UI removed.
    // let needsUnitSelector = currentTimeMode === 'hourly' && currentDurationMode === 'multi_day';

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
    let totalDegrees = 0;

    // Real-time clock state
    let clockInterval = null;
    let isUserInteracted = false;

    // --- Phase 3: Pre-booking Entry Time Editing ---
    const editEntryBtn = document.getElementById('editEntryBtn');

    // Initialize entry time display
    function updateEntryTimeDisplay(entryTime) {
        if (!entryDateValue || !entryTimeValue) return;

        const day = String(entryTime.getDate()).padStart(2, '0');
        const month = String(entryTime.getMonth() + 1).padStart(2, '0');
        const year = entryTime.getFullYear();
        const hours = String(entryTime.getHours()).padStart(2, '0');
        const mins = String(entryTime.getMinutes()).padStart(2, '0');

        entryDateValue.textContent = `${day}.${month}.${year}`;
        entryTimeValue.textContent = `${hours}:${mins}`;
    }

    // Initialize entry time display on load
    if (typeof ENTRY_TIME !== 'undefined' && ENTRY_TIME) {
        const entryTime = new Date(ENTRY_TIME);
        updateEntryTimeDisplay(entryTime);

        // Also update collapsed display
        const entryTimeDisplay = document.getElementById('entryTimeDisplay');
        if (entryTimeDisplay) {
            const year = entryTime.getFullYear();
            const month = String(entryTime.getMonth() + 1).padStart(2, '0');
            const day = String(entryTime.getDate()).padStart(2, '0');
            const hours = String(entryTime.getHours()).padStart(2, '0');
            const mins = String(entryTime.getMinutes()).padStart(2, '0');
            entryTimeDisplay.textContent = `${year}-${month}-${day} ${hours}:${mins}`;
        }
    }

    if (typeof IS_EDITABLE_START !== 'undefined' && IS_EDITABLE_START) {
        if (editEntryBtn) editEntryBtn.style.display = 'flex';

        if (editEntryBtn) {
            editEntryBtn.addEventListener('click', () => {
                // Switch to entry edit mode
                editMode = 'entry';

                // Hide collapsed entry, show expanded entry
                const entryCollapsed = document.getElementById('entryCollapsed');
                const entryExpanded = document.getElementById('entryExpanded');
                const exitCollapsed = document.getElementById('exitCollapsed');
                const exitExpanded = document.getElementById('exitExpanded');

                if (entryCollapsed) entryCollapsed.style.display = 'none';
                if (entryExpanded) entryExpanded.style.display = 'block';
                if (exitExpanded) exitExpanded.style.display = 'none';
                if (exitCollapsed) exitCollapsed.style.display = 'block';

                // Reset spinner to show current entry time
                totalDegrees = 0;
                currentUnit = 'days'; // Start with days
                updateSpinner(0);
                updateSpinnerLabel();

                // Activate entry date button
                if (entryDateBtn) entryDateBtn.classList.add('active');
                if (entryTimeBtn) entryTimeBtn.classList.remove('active');

                // Enable spinner editing
                isEditable = true;
                spinnerContainer.style.opacity = '1';
                spinnerContainer.style.pointerEvents = 'auto';
                spinnerContainer.style.cursor = 'grab';
            });
        }

        // Save entry button handler
        const saveEntryBtn = document.getElementById('saveEntryBtn');
        if (saveEntryBtn) {
            saveEntryBtn.addEventListener('click', () => {
                // Switch back to exit edit mode
                editMode = 'exit';

                // Show collapsed entry, hide expanded entry
                const entryCollapsed = document.getElementById('entryCollapsed');
                const entryExpanded = document.getElementById('entryExpanded');
                const exitCollapsed = document.getElementById('exitCollapsed');
                const exitExpanded = document.getElementById('exitExpanded');

                if (entryCollapsed) entryCollapsed.style.display = 'block';
                if (entryExpanded) entryExpanded.style.display = 'none';
                if (exitExpanded) exitExpanded.style.display = 'block';
                if (exitCollapsed) exitCollapsed.style.display = 'none';

                // Update collapsed entry display with current ENTRY_TIME
                const entryTimeDisplay = document.getElementById('entryTimeDisplay');
                if (entryTimeDisplay) {
                    const entryTime = new Date(ENTRY_TIME);
                    const year = entryTime.getFullYear();
                    const month = String(entryTime.getMonth() + 1).padStart(2, '0');
                    const day = String(entryTime.getDate()).padStart(2, '0');
                    const hours = String(entryTime.getHours()).padStart(2, '0');
                    const mins = String(entryTime.getMinutes()).padStart(2, '0');
                    entryTimeDisplay.textContent = `${year}-${month}-${day} ${hours}:${mins}`;
                }

                // Reset spinner for exit time
                totalDegrees = 0;
                currentUnit = 'days';
                updateSpinner(0);
                updateSpinnerLabel();

                // Reactivate exit buttons
                if (exitDateBtn) exitDateBtn.classList.add('active');
                if (exitTimeBtn) exitTimeBtn.classList.remove('active');

                // Re-enable spinner for exit editing
                isEditable = currentDurationMode === 'multi_day' || currentTimeMode === 'hourly';
                if (isEditable) {
                    spinnerContainer.style.opacity = '1';
                    spinnerContainer.style.pointerEvents = 'auto';
                    spinnerContainer.style.cursor = 'grab';
                }
            });
        }

        // Entry date/time button handlers
        if (entryDateBtn && entryTimeBtn) {
            entryDateBtn.addEventListener('click', () => {
                if (editMode === 'entry') {
                    currentUnit = 'days';
                    entryDateBtn.classList.add('active');
                    entryTimeBtn.classList.remove('active');
                    updateSpinnerLabel();
                    totalDegrees = 0;
                    updateSpinner(0);
                }
            });

            entryTimeBtn.addEventListener('click', () => {
                if (editMode === 'entry') {
                    currentUnit = 'minutes';
                    entryTimeBtn.classList.add('active');
                    entryDateBtn.classList.remove('active');
                    updateSpinnerLabel();
                    totalDegrees = 0;
                    updateSpinner(0);
                }
            });
        }
    }

    // Initialize configuration buttons based on config
    configButtons.forEach(btn => {
        const configType = btn.dataset.config;
        const value = btn.dataset.value;

        // Set initial active state based on config
        if ((configType === 'time_mode' && value === currentTimeMode) ||
            (configType === 'duration_mode' && value === currentDurationMode) ||
            (configType === 'day_counting' && value === currentDayCounting)) {
            btn.classList.add('active');
        } else {
            btn.classList.remove('active');
        }
    });

    // Configuration buttons handler
    configButtons.forEach(btn => {
        btn.addEventListener('click', () => {
            const configType = btn.dataset.config;
            const value = btn.dataset.value;

            // Update active state for this config group
            const groupButtons = document.querySelectorAll(`[data-config="${configType}"]`);
            groupButtons.forEach(b => b.classList.remove('active'));
            btn.classList.add('active');

            // Update current configuration
            if (configType === 'time_mode') {
                currentTimeMode = value;
            } else if (configType === 'duration_mode') {
                currentDurationMode = value;
            } else if (configType === 'day_counting') {
                currentDayCounting = value;
            }

            // Recalculate UI state
            isEditable = currentDurationMode === 'multi_day' || currentTimeMode === 'hourly';
            // needsUnitSelector = currentTimeMode === 'hourly' && currentDurationMode === 'multi_day';

            // Reset selections
            selectedDays = 0;
            selectedMinutes = 0;
            currentUnit = 'days';

            // Reinitialize UI
            initializeUI();

            // Reset interaction state on mode change?
            // User might want to keep "current time" tracking if just switching views
            // But if they manually set a time, and switch mode, maybe reset is safer?
            // Let's reset to auto-clock for fresh mode experience
            isUserInteracted = false;
            startRealTimeClock();
        });
    });

    function startRealTimeClock() {
        if (clockInterval) clearInterval(clockInterval);

        // Update immediately
        updateRealTimeClock();

        clockInterval = setInterval(() => {
            if (!isUserInteracted) {
                updateRealTimeClock();
            }
        }, 1000);
    }

    function updateRealTimeClock() {
        if (isUserInteracted) return;

        const now = new Date();
        const entry = new Date(ENTRY_TIME);

        // Calculate minutes difference
        const diffMs = now - entry;
        const diffMinutes = Math.max(0, Math.floor(diffMs / 60000));

        // Convert to degrees based on current mode
        let newDegrees = 0;

        if (currentTimeMode === 'daily') {
            // 360 deg = 7 days = 10080 mins
            newDegrees = (diffMinutes / 10080) * 360;
        } else if (currentTimeMode === 'hourly') {
            if (currentDurationMode === 'single_day') {
                // 360 deg = 60 mins
                newDegrees = (diffMinutes / 60) * 360;
            } else {
                // Multi-day hourly
                if (currentUnit === 'days') {
                    // 360 deg = 7 days
                    newDegrees = (diffMinutes / 10080) * 360; // Approximate for days view
                } else {
                    // 360 deg = 60 mins (Minutes view)
                    // Here we only show minutes part of the total duration?
                    // Or total duration mapped modulo?
                    // "Minutes" unit usually adds to the day. 
                    // Valid logic: just show minutes component of current time?
                    // Let's keep consistency: map total minutes to rotation
                    newDegrees = (diffMinutes / 60) * 360;
                }
            }
        }

        totalDegrees = newDegrees;
        updateSpinner(totalDegrees);
    }

    // Initialize UI based on modes
    function initializeUI() {
        // Show/hide unit selector (Removed)
        /*
        if (needsUnitSelector) {
            unitSelector.style.display = 'flex';
        } else {
            unitSelector.style.display = 'none';
        }
        */

        // Daily Mode: Disable Time selection
        if (currentTimeMode === 'daily') {
            // Visual indication that Time is locked/not needed
            if (exitTimeBtn) {
                exitTimeBtn.classList.remove('active');
                exitTimeBtn.style.opacity = '0.5';
                exitTimeBtn.style.pointerEvents = 'none';
            }
            if (exitDateBtn) {
                exitDateBtn.classList.add('active'); // Force Date active
            }
        } else {
            // Reset Time button state
            if (exitTimeBtn) {
                exitTimeBtn.style.opacity = '1';
                exitTimeBtn.style.pointerEvents = 'auto';
            }
        }

        // Hourly + Single Day Mode: Disable Date selection
        if (currentTimeMode === 'hourly' && currentDurationMode === 'single_day') {
            if (exitDateBtn) {
                exitDateBtn.classList.remove('active');
                exitDateBtn.style.opacity = '0.5';
                exitDateBtn.style.pointerEvents = 'none';
            }
            if (exitTimeBtn) {
                exitTimeBtn.classList.add('active'); // Force Time active
            }
        } else if (currentTimeMode === 'hourly' && currentDurationMode === 'multi_day') {
            // Ensure Date button is clickable again if we switched back
            if (exitDateBtn) {
                exitDateBtn.style.opacity = '1';
                exitDateBtn.style.pointerEvents = 'auto';
            }
        }


        // Enable/disable spinner
        if (!isEditable) {
            spinnerContainer.style.opacity = '0.5';
            spinnerContainer.style.pointerEvents = 'none';
            spinnerContainer.style.cursor = 'not-allowed';
        } else {
            spinnerContainer.style.opacity = '1';
            spinnerContainer.style.pointerEvents = 'auto';
            spinnerContainer.style.cursor = 'grab';
        }

        // Reset spinner position
        // Reset spinner position
        totalDegrees = 0;
        updateSpinner(0);
        updateSpinnerLabel();

        // Calculate end time for single_day modes
        if (currentDurationMode === 'single_day') {
            // For single day, we might need a distinct calc function or just rely on updateSpinner(0)
            // But updateSpinner(0) sets it to NOW or +0min.
            // For Daily Single Day -> It should default to Today 23:59:59?
            // Let's rely on updateSpinner handling 0 degrees correctly for the mode.
        } else {
            // Initialize exit time display with current time
            const entryTime = new Date(ENTRY_TIME);
            updateExitTimeDisplay(entryTime);
        }

        // Start/Restart Clock if not interacted
        if (!isUserInteracted) {
            startRealTimeClock();
        }
    }

    // Exit time buttons - switch between editing date or time
    if (exitDateBtn && exitTimeBtn) {
        exitDateBtn.addEventListener('click', () => {
            if (currentTimeMode === 'hourly' && currentDurationMode === 'multi_day') {
                currentUnit = 'days';
                exitDateBtn.classList.add('active');
                exitTimeBtn.classList.remove('active');
                updateSpinnerLabel();
                totalDegrees = 0;
                updateSpinner(0);
            }
        });

        exitTimeBtn.addEventListener('click', () => {
            if (currentTimeMode === 'hourly' && currentDurationMode === 'multi_day') {
                currentUnit = 'minutes';
                exitTimeBtn.classList.add('active');
                exitDateBtn.classList.remove('active');
                updateSpinnerLabel();
                totalDegrees = 0;
                updateSpinner(0);
            }
        });
    }

    /* Unit buttons removed
    // Unit buttons (days/minutes) for hourly + multi_day mode
    unitButtons.forEach(btn => { ... });
    */

    function updateSpinnerLabel() {
        // Check if we're editing entry time
        const isEditingEntry = editMode === 'entry';

        if (currentTimeMode === 'daily') {
            if (currentDurationMode === 'single_day') {
                spinnerLabel.textContent = isEditingEntry ? 'WJAZD' : 'WYJAZD';
            } else {
                spinnerLabel.textContent = 'DATA';
            }
        } else if (currentTimeMode === 'hourly') {
            if (currentDurationMode === 'single_day') {
                spinnerLabel.textContent = isEditingEntry ? 'WJAZD' : 'WYJAZD';
            } else {
                if (currentUnit === 'days') {
                    spinnerLabel.textContent = 'DATA';
                } else {
                    spinnerLabel.textContent = 'GODZINA';
                }
            }
        }
    }

    // Initialize Spinner
    if (progressCircle) {
        progressCircle.style.strokeDasharray = `${CIRCUMFERENCE} ${CIRCUMFERENCE}`;
        progressCircle.style.strokeDashoffset = CIRCUMFERENCE; // Start empty

        // Make spinner container draggable with visual feedback (only if editable)
        if (isEditable) {
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

        // Initialize UI
        initializeUI();
    }

    function getAngle(e) {
        let clientX, clientY;
        if (e.type.startsWith('touch')) {
            if (e.touches && e.touches.length > 0) {
                clientX = e.touches[0].clientX;
                clientY = e.touches[0].clientY;
            } else {
                return null;
            }
        } else {
            clientX = e.clientX;
            clientY = e.clientY;
        }

        const rect = spinnerContainer.getBoundingClientRect();
        const centerX = rect.left + rect.width / 2;
        const centerY = rect.top + rect.height / 2;

        const dx = clientX - centerX;
        const dy = clientY - centerY;

        let angle = Math.atan2(dy, dx);
        let degrees = angle * (180 / Math.PI) + 90;
        if (degrees < 0) degrees += 360;

        return degrees;
    }

    function startDrag(e) {
        // Nie pozwalaj na zmianę czasu, jeśli bilet jest już opłacony
        if (typeof IS_PAID !== 'undefined' && IS_PAID) return;

        e.preventDefault();
        e.stopPropagation();

        const degrees = getAngle(e);
        if (degrees === null) return;

        lastAngle = degrees;
        isDragging = true;
        isUserInteracted = true; // Stop auto-clock
        if (clockInterval) clearInterval(clockInterval);

        spinnerContainer.style.cursor = 'grabbing';
        if (spinnerHandle) spinnerHandle.style.transition = 'none'; // Disable transition for direct tracking
    }

    function drag(e) {
        if (!isDragging) return;

        if (e.cancelable) {
            e.preventDefault();
        }

        const currentAngle = getAngle(e);
        if (currentAngle === null) return;

        let delta = currentAngle - lastAngle;

        // Handle Wrap-around
        if (delta > 180) {
            delta -= 360;
        } else if (delta < -180) {
            delta += 360;
        }

        totalDegrees += delta;
        // if (totalDegrees < 0) totalDegrees = 0; // Optional: prevent negative time
        if (totalDegrees < 0) totalDegrees = 0;

        lastAngle = currentAngle;
        updateSpinner(totalDegrees); // Use totalDegrees to keep handle in sync with value
    }

    function endDrag() {
        isDragging = false;
        if (spinnerContainer) {
            spinnerContainer.style.cursor = 'grab';
        }
        if (spinnerHandle) spinnerHandle.style.transition = 'transform 0.1s'; // Re-enable transition

        // Auto-switch Tab UX: Hourly + Multi-day
        // If we were dragging Date (Days unit), switch to Time (Minutes unit) after release
        if (currentTimeMode === 'hourly' && currentDurationMode === 'multi_day' && currentUnit === 'days') {
            // Simulate click on Time button based on edit mode
            if (editMode === 'entry') {
                if (entryTimeBtn) entryTimeBtn.click();
            } else {
                if (exitTimeBtn) exitTimeBtn.click();
            }
        }
    }

    function updateSpinner(visualDegrees) {
        // 1. Update Handle Position
        const radians = (visualDegrees - 90) * (Math.PI / 180);
        const x = CENTER.x + RADIUS * Math.cos(radians);
        const y = CENTER.y + RADIUS * Math.sin(radians);
        spinnerHandle.setAttribute('transform', `translate(${x - 120}, ${y - 20})`);

        // 2. Update Progress Arc
        const offset = CIRCUMFERENCE - (visualDegrees / 360) * CIRCUMFERENCE;
        progressCircle.style.strokeDashoffset = offset;

        let totalMinutes = 0;
        const entryTime = new Date(ENTRY_TIME);

        // 3. Check edit mode - are we editing entry or exit time?
        if (editMode === 'entry') {
            // Editing ENTRY time - go backwards in time
            if (currentUnit === 'days') {
                // Days selection - go back in time
                const daysFromAngle = Math.floor((totalDegrees / 360) * 7);

                // Calculate new entry date (going backwards)
                const newEntryDate = new Date(entryTime.getTime() - daysFromAngle * 24 * 60 * 60 * 1000);
                const day = String(newEntryDate.getDate()).padStart(2, '0');
                const month = String(newEntryDate.getMonth() + 1).padStart(2, '0');
                const year = newEntryDate.getFullYear();

                // Display date in spinner
                spinnerValue.innerHTML = `<span style="font-size: 24px;">${day}.${month}.${year}</span>`;

                // Update entry time display
                updateEntryTimeDisplay(newEntryDate);

                // Update global ENTRY_TIME
                const hours = String(newEntryDate.getHours()).padStart(2, '0');
                const mins = String(newEntryDate.getMinutes()).padStart(2, '0');
                ENTRY_TIME = `${year}-${month}-${day} ${hours}:${mins}:00`;
            } else {
                // Minutes selection - go back in time
                const minutesFromAngle = Math.round((totalDegrees / 360) * 60);

                // Calculate new entry time (going backwards)
                const newEntryTime = new Date(entryTime.getTime() - minutesFromAngle * 60000);
                const hours = String(newEntryTime.getHours()).padStart(2, '0');
                const mins = String(newEntryTime.getMinutes()).padStart(2, '0');

                // Display time in spinner
                spinnerValue.innerHTML = `${hours}:${mins}`;

                // Update entry time display
                updateEntryTimeDisplay(newEntryTime);

                // Update global ENTRY_TIME
                const year = newEntryTime.getFullYear();
                const month = String(newEntryTime.getMonth() + 1).padStart(2, '0');
                const day = String(newEntryTime.getDate()).padStart(2, '0');
                ENTRY_TIME = `${year}-${month}-${day} ${hours}:${mins}:00`;
            }

            // Recalculate fee based on new entry time
            const now = new Date();
            const newEntry = new Date(ENTRY_TIME);
            const diffMs = now - newEntry;
            totalMinutes = Math.max(0, Math.floor(diffMs / 60000));
            addedMinutes = totalMinutes;

            // Debounce fee calculation
            if (debounceTimer) clearTimeout(debounceTimer);
            setLoadingState();
            debounceTimer = setTimeout(() => {
                fetchCalculatedFee(addedMinutes);
            }, 1000);

            return; // Exit early for entry mode
        }

        // 3. Logic based on totalDegrees for EXIT time editing

        // 3.2: daily / multi_day - scanning adds days
        if (currentTimeMode === 'daily') {
            // 360 deg = 7 days (as before) or just 1 day per 51 deg?
            // Let's keep density: 360 deg = 7 days
            const daysFromAngle = Math.floor((totalDegrees / 360) * 7);
            selectedDays = daysFromAngle;

            // Oblicz datę wyjazdu
            const exitDate = new Date(entryTime.getTime() + selectedDays * 24 * 60 * 60 * 1000);

            // Force 23:59:59
            exitDate.setHours(23, 59, 59, 999);

            const day = String(exitDate.getDate()).padStart(2, '0');
            const month = String(exitDate.getMonth() + 1).padStart(2, '0');
            const year = exitDate.getFullYear();

            // Wyświetl pełną datę
            spinnerValue.innerHTML = `<span style="font-size: 24px;">${day}.${month}.${year}</span>`;

            // Update exit time display
            updateExitTimeDisplay(exitDate);

            // Calculate duration from entry to this EOD
            const diffMs = exitDate - entryTime;
            totalMinutes = Math.floor(diffMs / 60000);
            if (totalMinutes < 0) totalMinutes = 0;

            addedMinutes = totalMinutes;
        }
        // 3.3: hourly / single_day
        else if (currentTimeMode === 'hourly' && currentDurationMode === 'single_day') {
            // 1 full rotation = 60 minutes
            const minutesFromAngle = Math.round((totalDegrees / 360) * 60);
            selectedMinutes = minutesFromAngle;

            // Oblicz czas wyjazdu
            const exitTime = new Date(entryTime.getTime() + selectedMinutes * 60000);

            const hours = String(exitTime.getHours()).padStart(2, '0');
            const mins = String(exitTime.getMinutes()).padStart(2, '0');

            // Wyświetl godzinę
            spinnerValue.innerHTML = `${hours}:${mins}`;

            // Update exit time display
            updateExitTimeDisplay(exitTime);

            totalMinutes = selectedMinutes;
            addedMinutes = totalMinutes;
        }
        // 3.4: hourly / multi_day
        else if (currentTimeMode === 'hourly' && currentDurationMode === 'multi_day') {
            if (currentUnit === 'days') {
                // Days selection
                const daysFromAngle = Math.floor((totalDegrees / 360) * 7);
                selectedDays = daysFromAngle;

                // Oblicz datę wyjazdu
                const exitDate = new Date(entryTime.getTime() + (selectedDays * 1440 + selectedMinutes) * 60000);
                const day = String(exitDate.getDate()).padStart(2, '0');
                const month = String(exitDate.getMonth() + 1).padStart(2, '0');
                const year = exitDate.getFullYear();

                // Wyświetl datę
                spinnerValue.innerHTML = `<span style="font-size: 24px;">${day}.${month}.${year}</span>`;

                // Update exit time display
                updateExitTimeDisplay(exitDate);
            } else {
                // Minutes selection (adding to days)
                // 1 rotation = 60 minutes
                const minutesFromAngle = Math.round((totalDegrees / 360) * 60);
                selectedMinutes = minutesFromAngle;

                // Oblicz czas wyjazdu
                const exitTime = new Date(entryTime.getTime() + (selectedDays * 1440 + selectedMinutes) * 60000);
                const hours = String(exitTime.getHours()).padStart(2, '0');
                const mins = String(exitTime.getMinutes()).padStart(2, '0');

                // Wyświetl godzinę
                spinnerValue.innerHTML = `${hours}:${mins}`;

                // Update exit time display
                updateExitTimeDisplay(exitTime);
            }

            totalMinutes = selectedDays * 1440 + selectedMinutes;
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

    // Update exit time display fields
    function updateExitTimeDisplay(exitTime) {
        if (!exitDateValue || !exitTimeValue) return;

        const day = String(exitTime.getDate()).padStart(2, '0');
        const month = String(exitTime.getMonth() + 1).padStart(2, '0');
        const year = exitTime.getFullYear();
        const hours = String(exitTime.getHours()).padStart(2, '0');
        const mins = String(exitTime.getMinutes()).padStart(2, '0');

        // Update global exit time
        currentExitTime = exitTime;

        exitDateValue.textContent = `${day}.${month}.${year}`;
        exitTimeValue.textContent = `${hours}:${mins}`;

        // Also update collapsed display
        const exitTimeDisplayCollapsed = document.getElementById('exitTimeDisplayCollapsed');
        if (exitTimeDisplayCollapsed) {
            exitTimeDisplayCollapsed.textContent = `${year}-${month}-${day} ${hours}:${mins}`;
        }
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
        isUserInteracted = true; // Stop clock updates when paying
        if (clockInterval) clearInterval(clockInterval);

        // Phase 4: Time Drift Check
        const serverTimeStub = new Date(); // In real app, we might need true server time, but client time is OK for drift "Start vs Now" check
        if (currentExitTime < serverTimeStub) {
            // Check if diff is substantial (more than 1 minute?)
            // If Exit Time is in the past, it's invalid for a future parking session (or staying).
            // Actually, if I am leaving "Now", and milliseconds passed, strict check might fail.
            // Let's assume 1 minute grace. or strict.
            // Requirement: "If Selected Exit Time < Current ... Do not process."

            // Allow 60s tolerance?
            if (serverTimeStub.getTime() - currentExitTime.getTime() > 60000) {
                alert('Wybrany czas wyjazdu już minął. Aktualizacja do bieżącego czasu.');

                // Reset spinner to "Now" (or minimum)
                // Just call initializeUI() which resets to 0?
                // Or updateSpinner(0)?
                updateSpinner(0);
                updateSpinnerLabel();

                // Recalculate fee implicitly via updateSpinner calling debounce
                // But pay button click stops here. User must click again after review.
                return;
            }
        }

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


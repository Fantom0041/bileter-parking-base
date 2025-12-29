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
                    if (data.simulated) {
                        window.location.href = `index.php?ticket_id=${data.ticket_id}&simulated=1`;
                    } else {
                        window.location.href = `index.php?ticket_id=${data.ticket_id}`;
                    }
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

    // Spinner Elements
    const spinnerContainer = document.getElementById('spinnerContainer');
    const spinnerValue = document.getElementById('spinnerValue');

    // RoundSlider State
    let currentTurns = 0;
    let lastSliderValue = 0;

    let totalDegrees = 0;

    // Real-time clock state
    let clockInterval = null;
    let isUserInteracted = false;

    // Track edit session base time to avoid feedback loop
    let editingBaseEntryTime = null;

    // --- Phase 3: Pre-booking Entry Time Editing ---
    const editEntryBtn = document.getElementById('editEntryBtn');

    // Helper function to animate section
    function animateSection(element) {
        if (!element) return;

        // Remove class if it exists (to retrigger animation)
        element.classList.remove('animate-in');

        // Force reflow to restart animation
        void element.offsetWidth;

        // Add animation class
        element.classList.add('animate-in');

        // Remove class after animation completes
        setTimeout(() => {
            element.classList.remove('animate-in');
        }, 300);
    }

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

    // Function to save entry changes and return to normal mode
    function saveEntryChanges() {
        // Clear base time
        editingBaseEntryTime = null;

        // Switch back to exit edit mode
        editMode = 'exit';

        // Show collapsed entry, hide expanded entry
        const entryCollapsed = document.getElementById('entryCollapsed');
        const entryExpanded = document.getElementById('entryExpanded');
        const exitCollapsed = document.getElementById('exitCollapsed');
        const exitExpanded = document.getElementById('exitExpanded');

        if (entryCollapsed) entryCollapsed.style.display = 'flex';
        if (entryExpanded) entryExpanded.style.display = 'none';

        // Check if we're in special mode: daily + single_day (FEE_TYPE=0, FEE_MULTI_DAY=0)
        if (currentTimeMode === 'daily' && currentDurationMode === 'single_day') {
            // Show collapsed Stop, hide expanded Stop
            if (exitCollapsed) exitCollapsed.style.display = 'block';
            if (exitExpanded) exitExpanded.style.display = 'none';

            // Hide edit button in collapsed view, add margin to maintain alignment
            const editExitBtnCollapsed = document.getElementById('editExitBtnCollapsed');
            const exitTimeDisplayCollapsed = document.getElementById('exitTimeDisplayCollapsed');
            if (editExitBtnCollapsed) {
                editExitBtnCollapsed.style.display = 'none';
            }
            // Add margin-right to compensate for hidden button (20px icon + 24px padding = 44px)
            if (exitTimeDisplayCollapsed) {
                exitTimeDisplayCollapsed.style.marginRight = '28px';
            }

            // Update collapsed exit time display to show end of day
            const entryTime = new Date(ENTRY_TIME);
            const endOfDay = new Date(entryTime);
            endOfDay.setHours(23, 59, 59, 999);

            const year = endOfDay.getFullYear();
            const month = String(endOfDay.getMonth() + 1).padStart(2, '0');
            const day = String(endOfDay.getDate()).padStart(2, '0');
            const hours = String(endOfDay.getHours()).padStart(2, '0');
            const mins = String(endOfDay.getMinutes()).padStart(2, '0');

            if (exitTimeDisplayCollapsed) {
                exitTimeDisplayCollapsed.textContent = `${year}-${month}-${day} ${hours}:${mins}`;
            }
        } else {
            // Normal mode: show expanded Stop, hide collapsed Stop
            if (exitExpanded) exitExpanded.style.display = 'block';
            if (exitCollapsed) exitCollapsed.style.display = 'none';
        }

        // Animate sections that are being shown
        animateSection(entryCollapsed);
        if (currentTimeMode === 'daily' && currentDurationMode === 'single_day') {
            animateSection(exitCollapsed);
        } else {
            animateSection(exitExpanded);
        }

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
        setSliderState(isEditable);
    }

    if (typeof IS_EDITABLE_START !== 'undefined' && IS_EDITABLE_START) {
        if (editEntryBtn) editEntryBtn.style.display = 'flex';

        if (editEntryBtn) {
            editEntryBtn.addEventListener('click', () => {
                // Switch to entry edit mode
                editMode = 'entry';

                // Set base time for this edit session
                editingBaseEntryTime = new Date(ENTRY_TIME);

                // Hide collapsed entry, show expanded entry
                const entryCollapsed = document.getElementById('entryCollapsed');
                const entryExpanded = document.getElementById('entryExpanded');
                const exitCollapsed = document.getElementById('exitCollapsed');
                const exitExpanded = document.getElementById('exitExpanded');

                if (entryCollapsed) entryCollapsed.style.display = 'none';
                if (entryExpanded) entryExpanded.style.display = 'block';
                if (exitExpanded) exitExpanded.style.display = 'none';
                if (exitCollapsed) exitCollapsed.style.display = 'block';

                // Animate sections that are being shown
                animateSection(entryExpanded);
                animateSection(exitCollapsed);

                // Reset spinner to show current entry time
                totalDegrees = 0;
                currentTurns = 0;
                currentUnit = 'days'; // Start with days
                updateSpinner(0);
                updateSpinnerLabel();

                // Activate entry date button
                if (entryDateBtn) entryDateBtn.classList.add('active');
                if (entryTimeBtn) entryTimeBtn.classList.remove('active');

                // Enable spinner editing
                isEditable = true;
                setSliderState(true);
            });
        }

        // Edit exit button handler (in collapsed Stop section when editing entry)
        const editExitBtnCollapsed = document.getElementById('editExitBtnCollapsed');
        if (editExitBtnCollapsed) {
            editExitBtnCollapsed.addEventListener('click', () => {
                // Save entry changes and return to normal mode
                saveEntryChanges();
            });
        }

        // Close button handler (X button in expanded Start section)
        const closeEntryExpandedBtn = document.getElementById('closeEntryExpandedBtn');
        if (closeEntryExpandedBtn) {
            closeEntryExpandedBtn.addEventListener('click', () => {
                // Save entry changes and return to normal mode
                saveEntryChanges();
            });
        }

        // Entry date/time button handlers
        if (entryDateBtn && entryTimeBtn) {
            entryDateBtn.addEventListener('click', () => {
                if (editMode === 'entry') {
                    currentUnit = 'days';
                    // Reset base time when switching units for clean offset
                    editingBaseEntryTime = new Date(ENTRY_TIME);

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
                    // Reset base time when switching units
                    editingBaseEntryTime = new Date(ENTRY_TIME);

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
                    newDegrees = (diffMinutes / 60) * 360;
                }
            }
        }

        totalDegrees = newDegrees;
        updateSpinner(totalDegrees);
    }

    // Initialize UI based on modes
    function initializeUI() {
        // Get exit sections
        const exitExpanded = document.getElementById('exitExpanded');
        const exitCollapsed = document.getElementById('exitCollapsed');

        // Special case: Daily + Single Day (FEE_TYPE=0, FEE_MULTI_DAY=0) = Hide expanded Stop, show collapsed (non-editable)
        if (currentTimeMode === 'daily' && currentDurationMode === 'single_day') {
            // Hide expanded exit section
            if (exitExpanded) exitExpanded.style.display = 'none';

            // Show collapsed exit section (non-editable)
            if (exitCollapsed) {
                exitCollapsed.style.display = 'block';

                // Hide edit button in collapsed view, add margin to maintain alignment
                const editExitBtnCollapsed = document.getElementById('editExitBtnCollapsed');
                const exitTimeDisplayCollapsed = document.getElementById('exitTimeDisplayCollapsed');
                if (editExitBtnCollapsed) {
                    editExitBtnCollapsed.style.display = 'none';
                }
                // Add margin-right to compensate for hidden button (20px icon + 24px padding = 44px)
                if (exitTimeDisplayCollapsed) {
                    exitTimeDisplayCollapsed.style.marginRight = '28px';
                }

                // Calculate and display end of day time
                const entryTime = new Date(ENTRY_TIME);
                const endOfDay = new Date(entryTime);
                endOfDay.setHours(23, 59, 59, 999);

                const year = endOfDay.getFullYear();
                const month = String(endOfDay.getMonth() + 1).padStart(2, '0');
                const day = String(endOfDay.getDate()).padStart(2, '0');
                const hours = String(endOfDay.getHours()).padStart(2, '0');
                const mins = String(endOfDay.getMinutes()).padStart(2, '0');

                if (exitTimeDisplayCollapsed) {
                    exitTimeDisplayCollapsed.textContent = `${year}-${month}-${day} ${hours}:${mins}`;
                }
            }
        } else {
            // Normal mode: Show expanded exit section, hide collapsed
            if (exitExpanded) exitExpanded.style.display = 'block';
            if (exitCollapsed) exitCollapsed.style.display = 'none';
        }

        // Daily Mode: Disable Time selection
        if (currentTimeMode === 'daily') {
            // Visual indication that Time is locked/not needed
            if (exitTimeBtn) {
                exitTimeBtn.classList.remove('active');
                exitTimeBtn.style.opacity = '0.5';
                exitTimeBtn.style.pointerEvents = 'none';
            }
            // Logic addition: If Daily + Single Day, disable Date too?
            // "jak mamy type fee dzienna i fee multi 0 oznacza ze nie mozemy nic zmieniac"
            if (currentDurationMode === 'single_day') {
                 if (exitDateBtn) {
                    exitDateBtn.classList.remove('active');
                    exitDateBtn.style.opacity = '0.5';
                    exitDateBtn.style.pointerEvents = 'none';
                 }
            } else {
                 if (exitDateBtn) {
                    exitDateBtn.classList.add('active'); // Force Date active for Daily+Multi
                 }
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

        // Fee Multi (Multi Day) Constraint: Disable Date, Allow Time
        // REFACTOR: Removed "fee multi nie moge zmienic daty" restriction to allow Hourly + Multi Day editing.
        // We now rely on 'currentTimeMode === daily' to disable time, and 'duration_mode === single_day' to disable date.
        // If it's Hourly + Multi Day, both should be enabled.



        // Enable/disable spinner
        setSliderState(isEditable);

        // Hide spinner completely in daily + single_day mode (FEE_TYPE=0, FEE_MULTI_DAY=0) - time is fixed
        if (currentTimeMode === 'daily' && currentDurationMode === 'single_day') {
            if (spinnerContainer) {
                spinnerContainer.style.display = 'none';
            }
        } else {
            if (spinnerContainer) {
                spinnerContainer.style.display = ''; // Reset to original CSS value
            }
        }

        // Reset spinner position
        // For daily + multi_day + from_entry, start with 1 day (360/7 degrees)
        if (currentTimeMode === 'daily' && currentDurationMode === 'multi_day' && currentDayCounting === 'from_entry') {
            totalDegrees = 360 / 7; // 1 day
            // Set visual position of spinner
            const slider = $("#slider").data("roundSlider");
            if (slider) {
                slider.setValue(totalDegrees);
            }
        } else {
            totalDegrees = 0;
        }
        updateSpinner(totalDegrees);
        updateSpinnerLabel();

        // Calculate end time for single_day modes
        if (currentDurationMode === 'single_day') {
            // For daily + single_day (any day_counting), calculate fee once - time is fixed
            if (currentTimeMode === 'daily') {
                const entryTime = new Date(ENTRY_TIME);
                const endOfDay = new Date(entryTime);
                endOfDay.setHours(23, 59, 59, 999);

                // Calculate duration from entry to end of day
                const diffMs = endOfDay - entryTime;
                const totalMinutes = Math.max(0, Math.floor(diffMs / 60000));

                // Set loading state and fetch fee after a short delay to ensure UI is ready
                setLoadingState();
                setTimeout(() => {
                    fetchCalculatedFee(totalMinutes);
                }, 100);
            }
        } else if (currentDurationMode === 'multi_day') {
            // For daily + multi_day + from_entry, initialize with +1 day and same hour
            if (currentTimeMode === 'daily' && currentDayCounting === 'from_entry') {
                const entryTime = new Date(ENTRY_TIME);
                const nextDay = new Date(entryTime.getTime() + 24 * 60 * 60 * 1000); // +1 day
                updateExitTimeDisplay(nextDay);
            } else {
                // Initialize exit time display with current time
                const entryTime = new Date(ENTRY_TIME);
                updateExitTimeDisplay(entryTime);
            }
        } else {
            // Initialize exit time display with current time
            const entryTime = new Date(ENTRY_TIME);
            updateExitTimeDisplay(entryTime);
        }

        // Start/Restart Clock if not interacted
        // Don't start clock in daily + from_entry modes (time is fixed)
        const isFixedTimeMode = currentTimeMode === 'daily' && currentDayCounting === 'from_entry';
        if (!isUserInteracted && !isFixedTimeMode) {
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

    function initializeRoundSlider() {
        $("#slider").roundSlider({
            radius: 100,
            width: 20,
            handleSize: "+8",
            sliderType: "min-range",
            value: 0,
            max: 360,
            startAngle: 90,
            svgMode: true,
            borderWidth: 0,
            pathColor: "#E0E0E0",
            rangeColor: "var(--primary)",
            tooltipColor: "inherit",
            showTooltip: false,

            drag: function (e) {
                handleSliderChange(e.value);
            },
            change: function (e) {
                handleSliderChange(e.value);
            },
            start: function () {
                isUserInteracted = true;
                if (clockInterval) clearInterval(clockInterval);
            },
            stop: function () {
                // Auto-switch logic
                if (currentTimeMode === 'hourly' && currentDurationMode === 'multi_day' && currentUnit === 'days') {
                    if (editMode === 'entry') {
                        if (entryTimeBtn) entryTimeBtn.click();
                    } else {
                        if (exitTimeBtn) exitTimeBtn.click();
                    }
                }
            }
        });
    }

    function handleSliderChange(newValue) {
        // Nie pozwalaj na zmianę czasu, jeśli bilet jest już opłacony
        if (typeof IS_PAID !== 'undefined' && IS_PAID) return;

        let diff = newValue - lastSliderValue;
        let potentialTurns = currentTurns;

        // Detect wrap around
        if (diff < -180) {
            potentialTurns++;  // Przejście z 350° do 10° (do przodu przez 0°)
        } else if (diff > 180) {
            potentialTurns--;  // Przejście z 10° do 350° (do tyłu przez 0°)
        }

        // Calculate potential total value
        let potentialTotal = potentialTurns * 360 + newValue;

        // Prevent going below 0 - force slider back to 0 position
        if (potentialTotal < 0) {
            // Reset to 0
            currentTurns = 0;
            totalDegrees = 0;
            lastSliderValue = 0;

            // Force slider widget to position 0
            const slider = $("#slider").data("roundSlider");
            if (slider) {
                slider.setValue(0);
            }

            // Update display to show starting value
            updateSpinner(0, false);
            return;
        }

        // Always update lastSliderValue to prevent accumulation errors
        lastSliderValue = newValue;

        // Update currentTurns and totalDegrees
        currentTurns = potentialTurns;
        totalDegrees = potentialTotal;
        updateSpinner(totalDegrees, true);
    }

    // UI Helpers for RoundSlider
    function setSliderState(enabled) {
        // Fallback check if slider exists before calling methods
        if (!$("#slider").data("roundSlider")) return;

        const slider = $("#slider").data("roundSlider");
        if (slider) {
            slider.option("readOnly", !enabled);
            spinnerContainer.style.opacity = enabled ? '1' : '0.5';
            spinnerContainer.style.cursor = enabled ? 'default' : 'not-allowed'; // Slider handles cursor
        }
    }

    function updateSpinner(visualDegrees, isFromAuthoredInteraction = false) {
        // If programmatic update (e.g. clock), sync the slider widget
        if (!isFromAuthoredInteraction) {
            const slider = $("#slider").data("roundSlider");
            if (slider) {
                // Determine turns and local value
                // integer part of degrees / 360
                // We need to map visualDegrees (which might be huge) back to 0-360 + turns
                const val = visualDegrees % 360;
                const turns = Math.floor(visualDegrees / 360);

                // Avoid firing change/drag events
                currentTurns = turns;
                lastSliderValue = val;

                slider.setValue(val);
            }
        }

        let totalMinutes = 0;
        const entryTime = new Date(ENTRY_TIME);

        // 3. Check edit mode - are we editing entry or exit time?
        if (editMode === 'entry') {
            // Use stable base time if available, or fall back to current ENTRY_TIME but be careful
            // For proper "Start of Session + Offset" logic, we need the base.
            // If editingBaseEntryTime is null (e.g. programmatic update?), use entryTime but this might loop if totalDegrees > 0 and we are dragging?
            // Actually, handleSliderChange calls this with isFromAuthoredInteraction=true.
            // When authored interaction happens, we SHOULD have editingBaseEntryTime set by start/click.

            const baseTime = editingBaseEntryTime || entryTime;

            // Editing ENTRY time - go forward in time
            if (currentUnit === 'days') {
                // Days selection - go forward in time
                const daysFromAngle = Math.floor((totalDegrees / 360) * 7);

                // Calculate new entry date (going forward)
                const newEntryDate = new Date(baseTime.getTime() + daysFromAngle * 24 * 60 * 60 * 1000);
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
                // Minutes selection - go forward in time
                const minutesFromAngle = Math.round((totalDegrees / 360) * 60);

                // Calculate new entry time (going forward)
                const newEntryTime = new Date(baseTime.getTime() + minutesFromAngle * 60000);
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
            const daysFromAngle = Math.round((totalDegrees / 360) * 7);
            selectedDays = daysFromAngle;

            // Oblicz datę wyjazdu
            const exitDate = new Date(entryTime.getTime() + selectedDays * 24 * 60 * 60 * 1000);

            // For multi_day + from_entry: keep the same hour as entry time (24h from entry)
            // For other modes: force 23:59:59
            if (currentDurationMode === 'multi_day' && currentDayCounting === 'from_entry') {
                // Keep the same hour as entry time (already set by adding days * 24h)
                // No need to change hours
            } else {
                // Force 23:59:59 for other modes
                exitDate.setHours(23, 59, 59, 999);
            }

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

        // Set loading state immediately (USING SCRIPT.JS LOGIC WITH SAFEGUARD)
        // Don't set loading state in daily + single_day mode (fee is calculated once in initializeUI)
        if (!(currentTimeMode === 'daily' && currentDurationMode === 'single_day')) {
            setLoadingState();

            // Set new debounce timer (1000ms)
            debounceTimer = setTimeout(() => {
                fetchCalculatedFee(addedMinutes);
            }, 1000);
        }
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
        // Don't update if we're in save mode (editing entry time)
        if (payButton.classList.contains('save-mode')) {
            return;
        }

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
                    extension_minutes: extensionMinutes,
                    entry_time: ENTRY_TIME
                })
            });

            if (!response.ok) {
                throw new Error(`Server error: ${response.status}`);
            }

            const data = await response.json();
            console.log('Fee calculation response:', data);
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
        // Don't update if we're in save mode (editing entry time)
        if (payButton.classList.contains('save-mode')) {
            return;
        }

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
        // Check if we're in save mode (editing entry time)
        if (payButton.classList.contains('save-mode')) {
            saveEntryChanges();
            return;
        }

        isUserInteracted = true; // Stop clock updates when paying
        if (clockInterval) clearInterval(clockInterval);

        // Phase 4: Time Drift Check
        const serverTimeStub = new Date(); // In real app, we might need true server time, but client time is OK for drift "Start vs Now" check
        if (currentExitTime < serverTimeStub) {
            // Check if diff is substantial (more than 1 minute?)
            // Allow 60s tolerance?
            if (serverTimeStub.getTime() - currentExitTime.getTime() > 60000) {
                alert('Wybrany czas wyjazdu już minął. Aktualizacja do bieżącego czasu.');

                // Reset spinner to "Now"
                updateSpinner(0);
                updateSpinnerLabel();

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

    // Initialize round slider (Needs jQuery)
    initializeRoundSlider();
    // Initialize UI
    initializeUI();
});

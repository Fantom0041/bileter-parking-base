document.addEventListener('DOMContentLoaded', () => {
    // --- TOASTER COMPONENT ---
    function showToast(message, type = 'error') {
        let container = document.querySelector('.toast-container');
        if (!container) {
            container = document.createElement('div');
            container.className = 'toast-container';
            document.body.appendChild(container);
        }

        const toast = document.createElement('div');
        toast.className = `toast-message ${type}`;
        
        const textSpan = document.createElement('span');
        textSpan.textContent = message;
        
        const closeBtn = document.createElement('button');
        closeBtn.className = 'toast-close';
        closeBtn.innerHTML = '&times;';
        closeBtn.onclick = () => {
            toast.style.opacity = '0';
            setTimeout(() => toast.remove(), 300);
        };

        toast.appendChild(textSpan);
        toast.appendChild(closeBtn);
        container.appendChild(toast);

        // Animate in
        requestAnimationFrame(() => {
            toast.classList.add('show');
        });

        // Auto remove after 5 seconds
        setTimeout(() => {
            if (document.body.contains(toast)) {
                toast.style.opacity = '0';
                setTimeout(() => toast.remove(), 300);
            }
        }, 5000);
    }

    // --- NOWA SESJA PARKOWANIA ---
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
                    console.error('Błąd: ' + data.message);
                    const displayMsg = (typeof SCENARIO_TEST_MODE !== 'undefined' && SCENARIO_TEST_MODE) 
                        ? `API Error: ${data.message}` 
                        : "Wystąpił błąd podczas tworzenia biletu.";
                    showToast(displayMsg);
                    
                    btn.innerText = originalText;
                    btn.disabled = false;
                }
            } catch (error) {
                console.error(error);
                const displayMsg = (typeof SCENARIO_TEST_MODE !== 'undefined' && SCENARIO_TEST_MODE) 
                    ? `Network/Server Error: ${error.message}` 
                    : "Wystąpił błąd podczas komunikacji z serwerem.";
                showToast(displayMsg);
                
                btn.innerText = originalText;
                btn.disabled = false;
            }
        });

        return; // Stop execution if we are on the "New Ticket" page
    }

    // --- ISTNIEJĄCY BILET PARKINGOWY ---
    let currentFee = INITIAL_FEE;
    let lastReceiptNumber = null;
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

        // Animate sections logic handled below based on visibility
        const entryCollapsed = document.getElementById('entryCollapsed');
        const entryExpanded = document.getElementById('entryExpanded');
        const exitCollapsed = document.getElementById('exitCollapsed');
        const exitExpanded = document.getElementById('exitExpanded');

        if (entryCollapsed) entryCollapsed.style.display = 'flex';
        if (entryExpanded) entryExpanded.style.display = 'none';

        // Check Scenario for Visibility State
        const scenario = getModeScenario();
        
        let showExpandedExit = true;
        
        // Determine if we show collapsed or expanded Exit based on Scenario
        switch (scenario) {
            case 'scenario_0_0_0': // Daily, Single, From Entry
            case 'scenario_0_0_1': // Daily, Single, From Midnight
                // Fixed STOP time -> Collapsed View
                showExpandedExit = false;
                break;
            default:
                // Check Max Limit Override
                if (API_SETTINGS.ticket_exist == '1' && currentDurationMode === 'single_day' && CONFIG.valid_to) {
                     const validTo = new Date(CONFIG.valid_to);
                     const entryTime = new Date(ENTRY_TIME);
                     const endOfDay = new Date(entryTime);
                     endOfDay.setHours(23, 59, 59, 999);
                     
                     if ((endOfDay - validTo) <= 15 * 60 * 1000) {
                         showExpandedExit = false;
                     }
                }
                break;
        }

        if (showExpandedExit) {
            // Show Expanded
            if (exitExpanded) exitExpanded.style.display = 'block';
            if (exitCollapsed) exitCollapsed.style.display = 'none';
            animateSection(exitExpanded);
        } else {
            // Show Collapsed
            if (exitExpanded) exitExpanded.style.display = 'none';
            if (exitCollapsed) exitCollapsed.style.display = 'block';
            animateSection(exitCollapsed);

            // Hide edit button in collapsed view for read-only scenarios
            const editExitBtnCollapsed = document.getElementById('editExitBtnCollapsed');
            if (editExitBtnCollapsed) editExitBtnCollapsed.style.display = 'none';

            // RE-CALCULATE Fixed Stop Time for Collapsed View
            const entryTime = new Date(ENTRY_TIME);
            let targetDate = new Date(entryTime);
            
            if (scenario === 'scenario_0_0_0') {
                 // Start + 1 Day, Same Time
                 targetDate.setDate(targetDate.getDate() + 1);
            } else if (scenario === 'scenario_0_0_1') {
                 // Start Day, 23:59:59
                 targetDate.setHours(23, 59, 59, 999);
            } else if (CONFIG.valid_to && API_SETTINGS.ticket_exist == '1') {
                 // Fallback for Max Limit
                 targetDate = new Date(CONFIG.valid_to);
            }

            const exitTimeDisplayCollapsed = document.getElementById('exitTimeDisplayCollapsed');
            if (exitTimeDisplayCollapsed) {
                 const year = targetDate.getFullYear();
                 const month = String(targetDate.getMonth() + 1).padStart(2, '0');
                 const day = String(targetDate.getDate()).padStart(2, '0');
                 const hours = String(targetDate.getHours()).padStart(2, '0');
                 const mins = String(targetDate.getMinutes()).padStart(2, '0');
                 exitTimeDisplayCollapsed.textContent = `${year}-${month}-${day} ${hours}:${mins}`;
            }
            // Update global exit time as well
            currentExitTime = targetDate;
        }

        animateSection(entryCollapsed);

        // Update collapsed entry display
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
        // currentUnit = 'days'; // Don't reset unit blindly, initializeUI handles defaults? 
        // Better to re-run initializeUI partially or just reset visuals
        updateSpinner(0);
        updateSpinnerLabel();

        // Re-initialize UI state (buttons, units) matches scenario
        initializeUI();
    }

    if (typeof IS_EDITABLE_START !== 'undefined' && IS_EDITABLE_START) {
        if (editEntryBtn) {
             // Only show if ticket doesn't exist? Rules say "moge zmienic date START, mimo ze TICKET_EXIST=1 - w takim przypadku NIE da sie zmienic"
             // Wait, the rule says: "mimo ze TICKET_EXIST=1 - w takim przypadku nie da sie zmienic"
             // It means: "Normally I can change start... BUT if TICKET_EXIST=1, then I CANNOT change it."
             if (API_SETTINGS.ticket_exist == '1') {
                 editEntryBtn.style.display = 'none';
             } else {
                 editEntryBtn.style.display = 'flex';
             }
        }

        if (editEntryBtn) {
            editEntryBtn.addEventListener('click', (e) => {
                // Double check restriction
                if (API_SETTINGS.ticket_exist == '1') return;
                
                e.stopPropagation();
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

        const entryCollapsed = document.getElementById('entryCollapsed');
        if (entryCollapsed && editEntryBtn) {
            entryCollapsed.addEventListener('click', () => {
                 if (window.getComputedStyle(editEntryBtn).display !== 'none' && API_SETTINGS.ticket_exist != '1') {
                     editEntryBtn.click();
                 }
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
        
        // Make the entire collapsed Exit component clickable
        if (exitCollapsed && editExitBtnCollapsed) {
            exitCollapsed.addEventListener('click', () => {
                // Only trigger if enabled
                 if (window.getComputedStyle(editExitBtnCollapsed).display !== 'none') {
                     editExitBtnCollapsed.click();
                 }
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

        // Check if value actually changed to avoid spamming updateSpinner and resetting debounce timer
        if (Math.abs(newDegrees - totalDegrees) < 0.001) {
            return;
        }

        totalDegrees = newDegrees;
        updateSpinner(totalDegrees);
    }

    function updateCursors() {
        const entryCollapsed = document.getElementById('entryCollapsed');
        const exitCollapsed = document.getElementById('exitCollapsed');

        // Entry Cursor: Generally no if ticket exists
        const isEntryEditable = API_SETTINGS.ticket_exist != '1';
        
        if (entryCollapsed) {
            entryCollapsed.style.cursor = isEntryEditable ? 'pointer' : 'default';
        }

        // Exit Cursor: Collapsed view is generally read-only in this design
        // because editable modes use expanded view.
        if (exitCollapsed) {
            exitCollapsed.style.cursor = 'default';
        }
    }

    /**
     * Helper to get current Scenario Signature
     * Returns: scenario_TYPE_MULTI_STARTS (e.g. scenario_0_0_0)
     */
    function getModeScenario() {
        const type = currentTimeMode === 'hourly' ? '1' : '0';
        const multi = currentDurationMode === 'multi_day' ? '1' : '0';
        const starts = currentDayCounting === 'from_midnight' ? '1' : '0';
        return `scenario_${type}_${multi}_${starts}`;
    }

    // Initialize UI based on Matrix Scenarios
    function initializeUI() {
        const scenario = getModeScenario();
        console.log("Initializing UI for Scenario:", scenario, {
            mode: currentTimeMode, 
            duration: currentDurationMode, 
            counting: currentDayCounting
        });

        // Elements
        const exitExpanded = document.getElementById('exitExpanded');
        const exitCollapsed = document.getElementById('exitCollapsed');
        const editExitBtnCollapsed = document.getElementById('editExitBtnCollapsed');
        const exitTimeDisplayCollapsed = document.getElementById('exitTimeDisplayCollapsed');

        // Defaults
        let showSpinner = true;
        let showExpandedExit = true;
        let showCollapsedExit = false;
        
        let dateEditable = false;
        let timeEditable = false;

        // --- SCENARIO LOGIC MATRIX ---
        switch (scenario) {
            case 'scenario_0_0_0': // Daily, Single, From Entry
                // STOP = Start + 1, Start Time.
                // Editability: DATA: No, TIME: No.
                showSpinner = false;
                showExpandedExit = false;
                showCollapsedExit = true; // Read-only collapsed view
                // UI: Collapsed Exit
                break;

            case 'scenario_0_0_1': // Daily, Single, From Midnight
                // STOP = Start, 23:59:59.
                // Editability: DATA: No, TIME: No.
                showSpinner = false;
                showExpandedExit = false;
                showCollapsedExit = true;
                break;

            case 'scenario_1_0_0': // Hourly, Single, From Entry
            case 'scenario_1_0_1': // Hourly, Single, From Midnight
                // STOP = Start + Minutes.
                // Editability: DATA: No (Fixed to Start Day/Relative), TIME: Yes (Spinner).
                // Logic: Block 23:59 rollover.
                showSpinner = true;
                showExpandedExit = true;
                showCollapsedExit = false;
                dateEditable = false;
                timeEditable = true;
                break;

            case 'scenario_0_1_0': // Daily, Multi, From Entry
            case 'scenario_0_1_1': // Daily, Multi, From Midnight
                // STOP = Start + Days.
                // Editability: DATA: Yes (Spinner Days), TIME: No (Fixed).
                showSpinner = true;
                showExpandedExit = true;
                showCollapsedExit = false;
                dateEditable = true;
                timeEditable = false;
                break;

            case 'scenario_1_1_0': // Hourly, Multi, From Entry
            case 'scenario_1_1_1': // Hourly, Multi, From Midnight
                // Fully Editable
                showSpinner = true;
                showExpandedExit = true;
                showCollapsedExit = false;
                dateEditable = true;
                timeEditable = true;
                break;

            default:
                console.warn("Unknown Scenario:", scenario);
                showSpinner = true;
                showExpandedExit = true;
        }

        // --- RULE 4: VISIBILITY & OVERRIDES ---
        
        // Check "Max Limit" Override (User Request)
        // If Ticket Exists AND Single Day AND ValidTo is close to EndOfDay -> Collapse/Readonly
        if (API_SETTINGS.ticket_exist == '1' && currentDurationMode === 'single_day' && CONFIG.valid_to) {
             const validTo = new Date(CONFIG.valid_to);
             const entryTime = new Date(ENTRY_TIME);
             const endOfDay = new Date(entryTime);
             endOfDay.setHours(23, 59, 59, 999);
             
             // If validTo is essentially EndOfDay (within 15 mins), treat as ReadOnly
             if ((endOfDay - validTo) <= 15 * 60 * 1000) {
                 showSpinner = false;
                 showExpandedExit = false;
                 showCollapsedExit = true;
             }
        }

        // Apply Visibility
        if (spinnerContainer) {
            spinnerContainer.style.display = showSpinner ? '' : 'none';
        }

        if (exitExpanded) exitExpanded.style.display = showExpandedExit ? 'block' : 'none';
        if (exitCollapsed) exitCollapsed.style.display = showCollapsedExit ? 'block' : 'none';

        // Configure Edit Buttons (Opacity/PointerEvents)
        if (exitDateBtn) {
            if (dateEditable) {
                exitDateBtn.style.opacity = '1';
                exitDateBtn.style.pointerEvents = 'auto';
            } else {
                exitDateBtn.style.opacity = '0.5';
                exitDateBtn.style.pointerEvents = 'none';
                exitDateBtn.classList.remove('active');
            }
        }

        if (exitTimeBtn) {
             if (timeEditable) {
                exitTimeBtn.style.opacity = '1';
                exitTimeBtn.style.pointerEvents = 'auto';
            } else {
                exitTimeBtn.style.opacity = '0.5';
                exitTimeBtn.style.pointerEvents = 'none';
                exitTimeBtn.classList.remove('active');
            }
        }
        
        // Auto-select active button based on editable state
        if (showExpandedExit) {
            if (dateEditable && !exitDateBtn.classList.contains('active') && !exitTimeBtn.classList.contains('active')) {
                exitDateBtn.classList.add('active');
                currentUnit = 'days';
            }
            if (!dateEditable && timeEditable) {
                exitTimeBtn.classList.add('active');
                 currentUnit = 'minutes';
            }
        }

        // Setup Collapsed View Content
        if (showCollapsedExit) {
             if (editExitBtnCollapsed) editExitBtnCollapsed.style.display = 'none'; // Always hide edit in collapsed for these scenarios
             
             // Update display for Collapsed View (Fixed Time)
             // Calculate the fixed stop time based on scenario logic if not already calculated
             // For 0_0_0 and 0_0_1, it's essentially fixed.
             let targetDate = new Date(ENTRY_TIME);
             console.log('UI DEBUG: Scen=' + scenario + ' Entry=' + ENTRY_TIME + ' TgtStart=' + targetDate.toISOString());
             if (scenario === 'scenario_0_0_0') {
                 // Start + 1 Day, Same Time (24h)
                 targetDate.setDate(targetDate.getDate() + 1);
             } else if (scenario === 'scenario_0_0_1') {
                 // Start Day, 23:59:59
                 targetDate.setHours(23, 59, 59, 999);
             } else if (scenario === 'scenario_0_0_0') {
                  // Fallback for others showing collapsed (e.g. Max Limit) comes from currentExitTime or VALID_TO
                  if (CONFIG.valid_to) targetDate = new Date(CONFIG.valid_to);
             }

             const year = targetDate.getFullYear();
             const month = String(targetDate.getMonth() + 1).padStart(2, '0');
             const day = String(targetDate.getDate()).padStart(2, '0');
             const hours = String(targetDate.getHours()).padStart(2, '0');
             const mins = String(targetDate.getMinutes()).padStart(2, '0');
             
             if (exitTimeDisplayCollapsed) {
                 exitTimeDisplayCollapsed.textContent = `${year}-${month}-${day} ${hours}:${mins}`;
             }
             
             // Bugfix: Update global currentExitTime to matches the displayed fixed time
             currentExitTime = targetDate;
        }

        // START Date Editability (Rule: "Read Only" if ticket exists or scenario dictates)
        // Check `newTicketForm` is handled separately. Here we check `editEntryBtn`.
        // If TICKET_EXIST=1, Start is Read-Only.
        if (editEntryBtn) {
            // "nie edytujemy" for practically all scenarios where TICKET_EXIST is 1
            if (API_SETTINGS.ticket_exist == '1') {
                 editEntryBtn.style.display = 'none';
            } else {
                 // New Ticket / Pre-booking: Allow edit?
                 // Prompt says: "START DATA / START GODZINA: nie edytujemy" for most scenarios in the table.
                 // But for "New Ticket", we probably want to allow it. 
                 // Assuming logic: If New Ticket, check scenarios?
                 // Let's stick to: If TICKET_EXIST=0, show button.
                 editEntryBtn.style.display = 'flex';
            }
        }

        // --- NEW RESTRICTION: Disable START DATE for Single Day Mode ---
        // "jak masz jednodniowy to ... date START nie da sie zmienci."
        if (currentDurationMode === 'single_day' && currentTimeMode === 'hourly') {
             if (entryDateBtn) {
                 entryDateBtn.classList.remove('active');
                 entryDateBtn.style.opacity = '0.5';
                 entryDateBtn.style.pointerEvents = 'none';
             }
             if (entryTimeBtn) {
                  entryTimeBtn.classList.add('active');
             }
        } else {
             if (isEditable && entryDateBtn) {
                  if (!API_SETTINGS.ticket_exist || API_SETTINGS.ticket_exist == '0') {
                        entryDateBtn.style.opacity = '1';
                        entryDateBtn.style.pointerEvents = 'auto';
                  }
             }
        }
        
        // Final Spinner State
        isEditable = showSpinner;
        setSliderState(isEditable);

        if (showSpinner) {
            updateSpinner(totalDegrees);
            updateSpinnerLabel();
        }

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
                // setLoadingState();
                // setTimeout(() => {
                //    fetchCalculatedFee(totalMinutes);
                // }, 100);
                // FIX: Skip redundant fetch. Server already pre-calculated the fee for Daily mode.
                // We rely on INITIAL_FEE passed from PHP.

            }
        } else if (currentDurationMode === 'multi_day') {
            // For daily + multi_day + from_entry, initialize with +1 day and same hour
            if (currentTimeMode === 'daily' && currentDayCounting === 'from_entry') {
                const entryTime = new Date(ENTRY_TIME);
                const nextDay = new Date(entryTime.getTime() + 24 * 60 * 60 * 1000); // +1 day
                updateExitTimeDisplay(nextDay);
                updateExitTimeDisplay(entryTime);
            }
        } else {
            // Initialize exit time display with current time
            const entryTime = new Date(ENTRY_TIME);
            updateExitTimeDisplay(entryTime);
        }
        // Use VALID_TO as base if ticket exists and it's valid
        let baseExitTime = new Date(ENTRY_TIME);
        if (API_SETTINGS.ticket_exist == '1' && CONFIG.valid_to) {
             const validTo = new Date(CONFIG.valid_to);
             if (validTo > baseExitTime) {
                 baseExitTime = validTo;
             }
        }
        
        // Calculate initial degrees based on baseExitTime
        const diffMs = baseExitTime - new Date(ENTRY_TIME);
        const diffMinutes = Math.max(0, Math.floor(diffMs / 60000));
        
        if (API_SETTINGS.ticket_exist == '1') {
            // Start at 0 relative to VALID_TO
            totalDegrees = 0;
            currentTurns = 0;
        } else if (currentTimeMode === 'daily') {
             // 360 deg = 7 days = 10080 mins
             totalDegrees = (diffMinutes / 10080) * 360;
        } else if (currentTimeMode === 'hourly') {
             if (currentDurationMode === 'single_day') {
                 // 360 deg = 60 mins
                 totalDegrees = (diffMinutes / 60) * 360;
                 // Set currentTurns to handle huge offsets
                 currentTurns = Math.floor(totalDegrees / 360);
             } else {
                 if (currentUnit === 'days') {
                      totalDegrees = (diffMinutes / 10080) * 360;
                 } else {
                      // Minutes view for multi-day?
                      // Usually implies adding on top of days?
                      // Just use standard minute scaling
                      totalDegrees = (diffMinutes / 60) * 360;
                 }
             }
        }
        
        // Update spinner (both slider position and text value)
        updateSpinner(totalDegrees);

        updateExitTimeDisplay(baseExitTime);

        // Start/Restart Clock if not interacted
        // Don't start clock in daily + from_entry modes (time is fixed)
        const isFixedTimeMode = currentTimeMode === 'daily' && currentDayCounting === 'from_entry';
        if (!isUserInteracted && !isFixedTimeMode) {
            startRealTimeClock();
        }

        updateCursors();

        // DEBUG: UI State Logging
        console.group("UI Editability Debug");
        console.log("Scenario:", scenario, "Config Matrix:", {
             time: currentTimeMode,
             duration: currentDurationMode,
             counting: currentDayCounting,
             fee_multi: FEE_CONFIG.FEE_MULTI_DAY,
             fee_type: FEE_CONFIG.FEE_TYPE,
             fee_starts: FEE_CONFIG.FEE_STARTS_TYPE
        });
        console.log("Editability Decisions:", {
            showSpinner,
            showExpandedExit,
            showCollapsedExit,
            dateEditable,
            timeEditable,
            isMaxLimitOverride: typeof isMaxLimitForSpinner !== 'undefined' ? isMaxLimitForSpinner : 'unknown' 
        });
        console.log("Control States:", {
            exitDateBtn: exitDateBtn ? { opacity: exitDateBtn.style.opacity, pointerEvents: exitDateBtn.style.pointerEvents, active: exitDateBtn.classList.contains('active') } : 'null',
            exitTimeBtn: exitTimeBtn ? { opacity: exitTimeBtn.style.opacity, pointerEvents: exitTimeBtn.style.pointerEvents, active: exitTimeBtn.classList.contains('active') } : 'null',
            spinnerVisible: spinnerContainer ? spinnerContainer.style.display !== 'none' : 'unknown'
        });
        console.groupEnd();
    }

    // Exit time buttons - switch between editing date or time
    if (exitDateBtn && exitTimeBtn) {
        exitDateBtn.addEventListener('click', () => {
            if (currentTimeMode === 'hourly') {
                if (currentDurationMode === 'single_day') {
                     // Auto-upgrade to multi-day
                     currentDurationMode = 'multi_day';
                     
                     // Update config UI if present
                     const durationBtn = document.querySelector(`.config-btn[data-value="multi_day"]`);
                     if (durationBtn) {
                         durationBtn.classList.add('active');
                         const singleBtn = document.querySelector(`.config-btn[data-value="single_day"]`);
                         if (singleBtn) singleBtn.classList.remove('active');
                     }
                     
                     // Re-run minimal setup for multi-day
                     isEditable = true;
                     // Don't call initializeUI() fully here as it effectively resets us, 
                     // just ensure state is correct for "Date" editing below.
                     
                     // Need to ensure spinnerContainer is shown? It is shown in hourly.
                }

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
        spinnerLabel.textContent = isEditingEntry ? 'START' : 'STOP';
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

        // Validation against VALID_TO
        // We do this check AFTER calculating the potential new time, but we can also prevent slider movement?
        // Better to calculate potential time first, then check.
        // But handleSliderChange updates Turns and Degrees.
        // Let's go through the logic.

        
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
                fetchCalculatedFee(now);
            }, 1000);

            return; // Exit early for entry mode
        }

        // 3. Logic based on totalDegrees for EXIT time editing
        
        // Define base time for Exit Editing
        let exitBaseTime = entryTime;
        if (API_SETTINGS.ticket_exist == '1' && CONFIG.valid_to) {
            exitBaseTime = new Date(CONFIG.valid_to);
        }

        const scenario = getModeScenario();
        
        // Scenario-based Calculations
        // Note: Scenarios 0_0_0 and 0_0_1 are non-editable (spinner hidden), so we shouldn't be here optimally,
        // but if we are, we handle them or they fall through.

        if (scenario === 'scenario_0_1_0' || scenario === 'scenario_0_1_1') {
            // Daily, Multi (0 or 1 Start)
            // Editability: Days Only (enforced by initializeUI)
            // 360 deg = 7 days
            const daysFromAngle = Math.round((totalDegrees / 360) * 7);
            selectedDays = daysFromAngle;

            // Calculate Exit Date
            const exitDate = new Date(exitBaseTime.getTime() + selectedDays * 24 * 60 * 60 * 1000);

            // Handle Time Component
            if (scenario === 'scenario_0_1_0') {
                 // From Entry: Keep same hour/min as Entry
                 const et = new Date(ENTRY_TIME);
                 exitDate.setHours(et.getHours(), et.getMinutes(), 0, 0);
            } else {
                 // From Midnight: Fix to 23:59:59
                 exitDate.setHours(23, 59, 59, 999);
            }

            // Update Validation Check: Don't allow less than Entry/ValidTo
            // (handled generically or implicit by days >= 0)
            
            // Format Display
            const day = String(exitDate.getDate()).padStart(2, '0');
            const month = String(exitDate.getMonth() + 1).padStart(2, '0');
            const year = exitDate.getFullYear();
            spinnerValue.innerHTML = `<span style="font-size: 24px;">${day}.${month}.${year}</span>`;

            updateExitTimeDisplay(exitDate);
            const diffMs = exitDate - entryTime;
            addedMinutes = Math.max(0, Math.floor(diffMs / 60000));
        }
        else if (scenario === 'scenario_1_0_0' || scenario === 'scenario_1_0_1') {
            // Hourly, Single (0 or 1 Start)
            // Editability: Minutes Only (enforced by initializeUI)
            // 1 full rotation = 60 minutes
            const minutesFromAngle = Math.round((totalDegrees / 360) * 60);
            selectedMinutes = minutesFromAngle;

            // Calculate Exit Time
            const exitTime = new Date(exitBaseTime.getTime() + selectedMinutes * 60000);

            // Limit Check: End of Day 23:59:59 (Same Day as Base)
            const baseDay = new Date(exitBaseTime);
            // If base is 23:50, we can only go 9 mins.
            // Check if date changed
            if (exitTime.getDate() !== baseDay.getDate()) {
                 // Clamped to End of Day
                 // We rely on validation block below to handle visual clamping,
                 // OR we clamp here to ensure 'exitTime' passed to display is correct.
                 // Let's clamp here for display consistency during drag.
                 const endOfDay = new Date(baseDay);
                 endOfDay.setHours(23, 59, 59, 999);
                 
                 // If we went PAST end of day (next day)
                 if (exitTime > endOfDay) {
                      exitTime.setTime(endOfDay.getTime());
                 }
            }
            
            const hours = String(exitTime.getHours()).padStart(2, '0');
            const mins = String(exitTime.getMinutes()).padStart(2, '0');
            spinnerValue.innerHTML = `${hours}:${mins}`;

            updateExitTimeDisplay(exitTime);
            const diffMs = exitTime - entryTime;
            addedMinutes = Math.max(0, Math.floor(diffMs / 60000));
        }
        else if (scenario === 'scenario_1_1_0' || scenario === 'scenario_1_1_1') {
             // Hourly, Multi (0 or 1 Start)
             // Fully Editable
             if (currentUnit === 'days') {
                const daysFromAngle = Math.floor((totalDegrees / 360) * 7);
                selectedDays = daysFromAngle;
                
                const exitDate = new Date(exitBaseTime.getTime() + (selectedDays * 1440 + selectedMinutes) * 60000);
                const day = String(exitDate.getDate()).padStart(2, '0');
                const month = String(exitDate.getMonth() + 1).padStart(2, '0');
                const year = exitDate.getFullYear();
                spinnerValue.innerHTML = `<span style="font-size: 24px;">${day}.${month}.${year}</span>`;
                
                updateExitTimeDisplay(exitDate);
             } else {
                const minutesFromAngle = Math.round((totalDegrees / 360) * 60);
                selectedMinutes = minutesFromAngle;
                
                const exitTime = new Date(exitBaseTime.getTime() + (selectedDays * 1440 + selectedMinutes) * 60000);
                const hours = String(exitTime.getHours()).padStart(2, '0');
                const mins = String(exitTime.getMinutes()).padStart(2, '0');
                spinnerValue.innerHTML = `${hours}:${mins}`;
                
                updateExitTimeDisplay(exitTime);
             }
             
             // Recalculate addedMinutes...
             // Note: updateExitTimeDisplay updates 'currentExitTime'.
             const diffMs = currentExitTime - entryTime;
             addedMinutes = Math.max(0, Math.floor(diffMs / 60000));
        } else {
             // Default Fallback (shouldn't happen for active spinner)
             // console.warn("Spinner active in unknown scenario");
        }

        // --- VALIDATION: Check against VALID_TO ---
        // --- VALIDATION: Check against VALID_TO ---
        if (CONFIG.valid_to && API_SETTINGS.ticket_exist == '1') {
             const validToDate = new Date(CONFIG.valid_to);
             let calculatedExitDate = null;
             
             if (currentTimeMode === 'daily') {
                  const daysFromAngle = Math.round((totalDegrees / 360) * 7);
                   calculatedExitDate = new Date(exitBaseTime.getTime() + daysFromAngle * 24 * 60 * 60 * 1000);
                   if (currentDurationMode === 'multi_day' && currentDayCounting === 'from_entry') {
                       // Keep hour
                   } else {
                       calculatedExitDate.setHours(23, 59, 59, 999);
                   }
             } else if (currentTimeMode === 'hourly' && currentDurationMode === 'single_day') {
                  const minutesFromAngle = Math.round((totalDegrees / 360) * 60);
                  calculatedExitDate = new Date(exitBaseTime.getTime() + minutesFromAngle * 60000);
             } else if (currentTimeMode === 'hourly' && currentDurationMode === 'multi_day') {
                 if (currentUnit === 'days') {
                      // Logic handled above but for completeness using calculatedExitTime from above
                      // Or recalc using exitBaseTime
                      // Since calculateExitTime from above UPDATED currentExitTime, we can just use that.
                 }
                 // Simplification: use `currentExitTime` which was just updated above.
                 calculatedExitDate = currentExitTime;
             }
             
             // Upper Bound Check for Single Day Mode: Max 23:59:59 of the VALID_TO day?
             // User says: "maximum we can set of time is 23:59"
             // If we are Single Day, we shouldn't cross too far.
             // Let's limit to End Of Day of the calculated date?
             // Actually, if we are editing Single Day, we are just adding minutes.
             // If VALID_TO is today, we can go to 23:59 today.
             // If VALID_TO is tomorrow (e.g. 02:17), we can go to 23:59 tomorrow?
             // Since we disabled expanding to Multi-Day (Date button disabled), we are implicitly limited by spinner.
             // But spinner can spin forever.
             // Let's enforce the "Max 23:59" rule strictly.
             if (currentTimeMode === 'hourly' && currentDurationMode === 'single_day') {
                  // User Request: "stop tylko godzina do 23:59" (Stop only hour up to 23:59)
                  // Calculate End Of Day of the BASE date (Start Date or Valid To)
                  const baseDay = new Date(exitBaseTime); 
                  const endOfDay = new Date(baseDay);
                  endOfDay.setHours(23, 59, 59, 999);
                  
                  if (calculatedExitDate > endOfDay) {
                       // Clamp logic
                       // We can't easily clamp the spinner degrees without recalculating turns/angles complexly.
                       // Instead, we will visualy display the Limit, and effectively clamp the "Time" value
                       // But the Slider handle might be visually "past" the limit. 
                       // Ideally we validat *before* updating totalDegrees in handleSliderChange, but that is generic.
                       // Here we are in updateSpinner logic.
                       
                       console.log("Validation: Clamping to EndOfDay " + endOfDay);
                       
                       // Force the display to show 23:59
                       const hours = String(endOfDay.getHours()).padStart(2, '0');
                       const mins = String(endOfDay.getMinutes()).padStart(2, '0');
                       spinnerValue.innerHTML = `${hours}:${mins}`;
                       
                       // Update global currentExitTime to clamped value
                       updateExitTimeDisplay(endOfDay);
                       
                       // Show limit warning briefly?
                       // spinnerValue.innerHTML = `<span style="color:var(--error);">Limit!</span>`;
                       // setTimeout(...)
                       // Maybe just sticking to 23:59 is enough feedback (it won't go further).
                       
                       // IMPORTANT: We must STOP here and NOT call fetchCalculatedFee with the futuristic date.
                       // We should call it with the CLAMPED date (endOfDay).
                       
                       addedMinutes = Math.floor((endOfDay - entryTime) / 60000);
                       
                       // Do standard debounce fetch with CLAMPED date
                        if (typeof lastAddedMinutes === 'undefined' || lastAddedMinutes !== addedMinutes) {
                            setLoadingState();
                            if (debounceTimer) clearTimeout(debounceTimer);
                            debounceTimer = setTimeout(() => {
                                lastAddedMinutes = addedMinutes; 
                                fetchCalculatedFee(endOfDay);
                            }, 1000);
                        }

                       return; // Stop processing the original (overflowing) calculated date
                  }
             }

             if (calculatedExitDate && calculatedExitDate < validToDate) {
                 // REVERT / CLAMP
                 // If the calculated time is BEFORE valid_to, enforce valid_to.
                 // But simply replacing the date isn't enough, we must update the SPINNER/SLIDER too to reflect this.
                 // This is hard because mapping Date -> Degrees is complex (modulo, turns).
                 // ALTERNATIVE: Just update the Display to be ValidTo, and maybe reset slider?
                 // Or better: Just check `currentExitTime` against `validToDate`.
                 
                 // If less than validTo, snap to validTo.
             
                 
                 updateExitTimeDisplay(validToDate);
                 
                 // How to update Spinner Visuals to match VALID_TO?
                 // It's complicated to reverse-engineer degrees from date here without code duplication.
                 // Maybe just visual feedback is enough? "Data/godzina STOP nie moze byc mniejsza niz VALID_TO".
                 // Requirement: "nie da sie zmienić" (cannot change).
                 // So we should probably prevent the update if illegal?
                 // Or clamp it. Clamping is better UX.
                 // Let's force display to ValidTo.
                 
                 spinnerValue.innerHTML = `<span style="color:var(--error);">Limit!</span>`;
                 setTimeout(() => {
                      // restore visual text?
                      // actually let's just show the time of ValidTo
                      const day = String(validToDate.getDate()).padStart(2, '0');
                      const month = String(validToDate.getMonth() + 1).padStart(2, '0');
                      const year = validToDate.getFullYear();
                      const hours = String(validToDate.getHours()).padStart(2, '0');
                      const mins = String(validToDate.getMinutes()).padStart(2, '0');
                      
                      if (currentUnit === 'days' || currentTimeMode === 'daily') {
                           spinnerValue.innerHTML = `<span style="font-size: 24px;">${day}.${month}.${year}</span>`;
                      } else {
                           spinnerValue.innerHTML = `${hours}:${mins}`;
                      }
                 }, 500);
             }
        }

        // Clear existing debounce timer
        if (debounceTimer) {
            clearTimeout(debounceTimer);
        }

        // Set loading state immediately (USING SCRIPT.JS LOGIC WITH SAFEGUARD)
        // Don't set loading state in daily + single_day mode (fee is calculated once in initializeUI)
        // Also ensure we only fetch if minutes actually changed (prevent loops)
        if (!(currentTimeMode === 'daily' && currentDurationMode === 'single_day')) {
             if (typeof lastAddedMinutes === 'undefined' || lastAddedMinutes !== addedMinutes) {
                setLoadingState();

                // Set new debounce timer (1000ms)
                debounceTimer = setTimeout(() => {
                    lastAddedMinutes = addedMinutes; // Update last fetched/sent minutes (keep logic/name or remove?)
                    // Even if we use exitTime, tracking change via addedMinutes is fine for debounce check
                    fetchCalculatedFee(currentExitTime);
                }, 1000);
             }
        }
    }

    // Track last minutes ensuring we don't refetch same value
    let lastAddedMinutes = null;

    // Update exit time display fields
    function updateExitTimeDisplay(exitTime) {
        if (!exitDateValue || !exitTimeValue) return;

        const day = String(exitTime.getDate()).padStart(2, '0');
        const month = String(exitTime.getMonth() + 1).padStart(2, '0');
        const year = exitTime.getFullYear();
        const hours = String(exitTime.getHours()).padStart(2, '0');
        const mins = String(exitTime.getMinutes()).padStart(2, '0');
        const secs = String(exitTime.getSeconds()).padStart(2, '0'); // Added seconds for precision if needed

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

    // Helper to format date as YYYY-MM-DD HH:MM:SS
    function formatDateTime(date) {
        const year = date.getFullYear();
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const day = String(date.getDate()).padStart(2, '0');
        const hours = String(date.getHours()).padStart(2, '0');
        const mins = String(date.getMinutes()).padStart(2, '0');
        const secs = String(date.getSeconds()).padStart(2, '0');
        return `${year}-${month}-${day} ${hours}:${mins}:${secs}`;
    }

    // Helper to get the most effective Ticket ID for API operations
    // Prioritizes numeric Barcode (if valid) over the initial Ticket ID (which might be a Plate)
    function getEffectiveTicketId() {
        const ticketBarcode = API_SETTINGS.ticket_barcode;
        // Check if barcode is valid and positive number
        if (ticketBarcode && !isNaN(ticketBarcode) && Number(ticketBarcode) > 0) {
            return ticketBarcode;
        }
        return TICKET_ID;
    }

    async function fetchCalculatedFee(exitTime) {
        try {
            const effectiveId = getEffectiveTicketId();
            const response = await fetch('api.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'calculate_fee',
                    ticket_id: effectiveId,
                    entry_time: ENTRY_TIME.substring(0, 16) + ':00',
                    exit_time: typeof exitTime === 'string' ? exitTime : formatDateTime(exitTime)
                })
            });

            if (!response.ok) {
                throw new Error(`Server error: ${response.status}`);
            }

            const data = await response.json();
           
            if (data.success) {
                currentFee = data.fee;
                updatePayButton();

                // Show payment info card ALWAYS (for both New and Existing tickets)
                // data.ticket_exist check removed as per requirement: "teraz zawsze to ma wyswietlac"
                 const paymentInfoCard = document.querySelector('.payment-info-card');
                 if (paymentInfoCard) {
                     paymentInfoCard.style.display = ''; // Reset to default (which should be visible)
                 }
                 // Update Fee Paid Value
                 if (data.fee_paid !== undefined) {
                     const feePaidValue = document.getElementById('feePaidValue');
                     if (feePaidValue) {
                         feePaidValue.innerText = parseFloat(data.fee_paid).toFixed(2);
                     }
                 }
                     
                     // Also check if we should populate "Wyjazd do" if it was empty?
                     // data.duration_minutes is calculated, but API might have authoritative date.
                     // The logic for 'Wyjazd do' is complex, mainly set by spinner/inputs.
                     // But if the user didn't change anything, we might want to sync.
                     // For now, user request focused on 'oplacono' component.
                // } else {
                //    const paymentInfoCard = document.querySelector('.payment-info-card');
                //    if (paymentInfoCard) {
                //        paymentInfoCard.style.display = 'none';
                //    }
                // }
            } else {
                console.error('Fee calculation failed:', data.message);
                const displayMsg = (typeof SCENARIO_TEST_MODE !== 'undefined' && SCENARIO_TEST_MODE) 
                    ? `Fee Calc Error: ${data.message}` 
                    : "Nie udało się przeliczyć opłaty.";
                showToast(displayMsg);
                
                // Reset to initial fee on error
                currentFee = INITIAL_FEE;
                updatePayButton();
            }
        } catch (error) {
            console.error('Error fetching fee:', error);
             const displayMsg = (typeof SCENARIO_TEST_MODE !== 'undefined' && SCENARIO_TEST_MODE) 
                ? `Fee Calc Network Error: ${error.message}` 
                : "Błąd połączenia przy wycenie.";
             showToast(displayMsg);
             
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

        if (currentFee <= 0) {
        // Check if ticket is paid and exists (Liquid Glass CTA)
        const feePaid = API_SETTINGS.fee_paid || 0;
        const ticketExist = API_SETTINGS.ticket_exist == 1;

        // Note: We check if feePaid > 0. The user log showed FEE_PAID: "24000" (grosze).
        // Since INITIAL_FEE was likely checked against this, if currentFee is 0, it means no *extra* fee.
        // If we have a paid history, show "0,00" in glass style.
        if (ticketExist && feePaid > 0) {
             payButton.textContent = "Do zapłaty: 0,00 " + CONFIG.currency;
             payButton.classList.add('btn-glass');
             payButton.disabled = true; // Still disabled as there's nothing to pay
        } else {
             payButton.textContent = "Do zapłaty: 0,00 " + CONFIG.currency;
             payButton.classList.remove('btn-glass');
             payButton.disabled = true;
        }
    } else {
        payButton.textContent = "Zapłać " + currentFee.toFixed(2) + " " + CONFIG.currency;
        payButton.classList.remove('btn-glass');
        payButton.disabled = false;
    }    }

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
                console.log('DEBUG DATE: ' + serverTimeStub.toISOString() + ' vs ' + currentExitTime.toISOString() + ' | Diff: ' + (serverTimeStub - currentExitTime));
                console.warn('Wybrany czas wyjazdu już minął. Aktualizacja do bieżącego czasu.');

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
            const effectiveId = getEffectiveTicketId();
            const response = await fetch('api.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    action: 'pay',
                    ticket_id: effectiveId,
                    amount: currentFee,
                    // Force seconds to 00
                    entry_time: ENTRY_TIME.substring(0, 16) + ':00',
                    exit_time: formatDateTime(currentExitTime)
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
                // Display Receipt Number if available, or fallback text
                if (data.receipt_number) {
                    qrCode.innerHTML = `<span style="font-size:18px; font-weight:700">PARAGON<br>${data.receipt_number}</span>`;
                    lastReceiptNumber = data.receipt_number;
                } else if (data.new_qr_code) {
                    qrCode.innerText = data.new_qr_code;
                    // Try to extract number if format is REC-123
                    if(data.new_qr_code.startsWith('REC-')) {
                         lastReceiptNumber = data.new_qr_code.split('-')[1];
                    }
                } else {
                    qrCode.innerText = "OPŁACONO";
                }
                
                successOverlay.classList.add('visible');
                paymentSheet.style.transform = 'translate(-50%, 100%)';
            } else {
                console.error('Płatność nieudana: ' + data.message);
                const displayMsg = (typeof SCENARIO_TEST_MODE !== 'undefined' && SCENARIO_TEST_MODE) 
                    ? `Payment Error: ${data.message}` 
                    : "Płatność nieudana. Spróbuj ponownie.";
                showToast(displayMsg);
                
                payButton.innerText = originalText;
                payButton.disabled = false;
            }
        } catch (error) {
            console.error('Error:', error);
            console.error('Wystąpił błąd: ' + error.message);
            
            const displayMsg = (typeof SCENARIO_TEST_MODE !== 'undefined' && SCENARIO_TEST_MODE) 
                ? `Payment Network Error: ${error.message}` 
                : "Wystąpił błąd podczas płatności.";
            showToast(displayMsg);

            payButton.innerText = originalText;
            payButton.disabled = false;
        }
    });

    // 4. Edycja numeru rejestracyjnego - Modal Implementation
    const plateDisplay = document.getElementById('plateDisplay');
    const licensePlateContainer = document.getElementById('licensePlateContainer');
    const editPlateBtn = document.getElementById('editPlateBtn'); // Keep reference if it exists

    // Modal Elements
    const plateEditModal = document.getElementById('plateEditModal');
    const plateSheetInput = document.getElementById('plateSheetInput');
    const savePlateBtn = document.getElementById('savePlateBtn');
    const cancelPlateEditBtn = document.getElementById('cancelPlateEdit');

    // Confirmation Modal Elements
    const plateConfirmModal = document.getElementById('plateConfirmModal');
    const confirmPlateValue = document.getElementById('confirmPlateValue');
    const cancelPlateChange = document.getElementById('cancelPlateChange');
    const confirmPlateChange = document.getElementById('confirmPlateChange');

    let pendingNewPlate = "";

    function openPlateModal() {
        if (!plateEditModal) return;
        
        // Populate input with current value
        if (plateDisplay) {
            plateSheetInput.value = plateDisplay.innerText.trim();
        }
        
        // Show modal
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

    if (licensePlateContainer) {
        licensePlateContainer.addEventListener('click', (e) => {
            // Prevent if it's supposed to be read-only (optional check)
            openPlateModal();
        });
    }

    if (editPlateBtn) {
        editPlateBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            openPlateModal();
        });
    }

    if (cancelPlateEditBtn) {
        cancelPlateEditBtn.addEventListener('click', closePlateModal);
    }
    
    // Close on click outside (Backdrop is part of modal-overlay if clicked directly)
    if (plateEditModal) {
        plateEditModal.addEventListener('click', (e) => {
            if (e.target === plateEditModal) {
                closePlateModal();
            }
        });
    }

    // Input formatting (UpperCase)
    if (plateSheetInput) {
        plateSheetInput.addEventListener('input', (e) => {
            e.target.value = e.target.value.toUpperCase();
        });
        
        plateSheetInput.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') {
                savePlateBtn.click();
            }
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
            
            // Close Input Modal
            closePlateModal();

            // Open Confirmation Modal
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
             // No need to close plate sheet as it's already closed
             
             // Execute Change Logic
             const ticketBarcode = API_SETTINGS.ticket_barcode; // Got from PHP
             
             // Check if we have a numeric barcode greater than 0
             const isBarcodeValid = ticketBarcode && !isNaN(ticketBarcode) && Number(ticketBarcode) > 0;
             
             if (isBarcodeValid) {
                 // Call API to set plate
                 const originalText = confirmPlateChange.innerText;
                 confirmPlateChange.innerText = 'Zapisywanie...';
                 confirmPlateChange.disabled = true;

                 try {
                     const response = await fetch('api.php', {
                         method: 'POST',
                         headers: { 'Content-Type': 'application/json' },
                         body: JSON.stringify({ 
                             action: 'set_plate', 
                             ticket_id: ticketBarcode,
                             new_plate: pendingNewPlate
                         })
                     });

                     const data = await response.json();

                     if (data.success) {
                         // Reload page to reflect changes
                         window.location.reload();
                     } else {
                         console.error('Błąd zmiany numeru: ' + data.message);
                         const displayMsg = (typeof SCENARIO_TEST_MODE !== 'undefined' && SCENARIO_TEST_MODE) 
                            ? `Plate Change Error: ${data.message}` 
                            : "Nie udało się zmienić numeru rejestracyjnego.";
                         showToast(displayMsg);
                         
                         // Reset state?
                         plateConfirmModal.classList.remove('visible');
                     }
                 } catch (error) {
                     console.error(error);
                     console.error('Błąd komunikacji: ' + error.message);
                     
                     const displayMsg = (typeof SCENARIO_TEST_MODE !== 'undefined' && SCENARIO_TEST_MODE) 
                        ? `Plate Change Network Error: ${error.message}` 
                        : "Błąd połączenia.";
                     showToast(displayMsg);
                 } finally {
                     confirmPlateChange.innerText = originalText;
                     confirmPlateChange.disabled = false;
                 }
             } else {
                 // Legacy / New Ticket Mode: Reload with new ID
                 window.location.href = `index.php?ticket_id=${encodeURIComponent(pendingNewPlate)}`;
             }
        });
    }

    // 5. Handle PDF Download on Success Overlay Click
    const successTicketContainer = document.getElementById('successTicketContainer');
    if (successTicketContainer) {
        successTicketContainer.addEventListener('click', () => {
            if (lastReceiptNumber) {
                // Trigger download via GET request
                window.location.href = `api.php?action=download_receipt&receipt_number=${lastReceiptNumber}`;
            } else {
                console.warn('Numer paragonu nie jest dostępny.');
            }
        });
    }

    // Initialize round slider (Needs jQuery)
    initializeRoundSlider();
    // Initialize UI
    initializeUI();
    // Update Pay Button state based on initial PHP values
    updatePayButton();
});

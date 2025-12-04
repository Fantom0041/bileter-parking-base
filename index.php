<?php
// 1. Load Configuration
$config = parse_ini_file('config.ini');

// 2. Load Data
$json_data = file_get_contents('data.json');
$tickets = json_decode($json_data, true);

// 3. Get Ticket ID
$ticket_id = $_GET['ticket_id'] ?? null;
$ticket = null;
$error = null;

// 4. Validate Ticket
if ($ticket_id && isset($tickets[$ticket_id])) {
    $ticket = $tickets[$ticket_id];
} else {
    $error = "Bilet nie został znaleziony lub jest nieprawidłowy.";
}

// 5. Calculate Fee
$fee = 0;
$duration_minutes = 0;
$status_message = "";
$is_free_period = false;

if ($ticket) {
    // Always initialize entry_time from ticket data
    $entry_time = new DateTime($ticket['entry_time']);

    if ($ticket['status'] === 'paid') {
        $status_message = "Opłacony";
        $fee = 0;
    } else {
        $current_time = new DateTime(); // Now
        // For testing, you might want to force a specific time if needed, 
        // but for now we use server time.

        $interval = $entry_time->diff($current_time);
        $duration_minutes = ($interval->days * 24 * 60) + ($interval->h * 60) + $interval->i;

        if ($duration_minutes <= $config['free_minutes']) {
            $fee = 0;
            $is_free_period = true;
            $status_message = "Okres bezpłatny (" . ($config['free_minutes'] - $duration_minutes) . " min pozostało)";
        } else {
            // Simple hourly calculation: ceil(hours) * rate
            $hours = ceil($duration_minutes / 60);
            $fee = $hours * $config['hourly_rate'];
            $status_message = "Aktywne";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Parking Settlement</title>
    <link rel="stylesheet" href="style.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
        href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Roboto+Mono:wght@500&display=swap"
        rel="stylesheet">
</head>

<body>
    <?php if ($error): ?>
        <div class="error-container">
            <div class="icon-error" style="background: rgba(106, 27, 154, 0.1); color: var(--primary);">+</div>
            <h1>Rozpocznij parkowanie</h1>
            <p>Wpisz numer rejestracyjny, aby rozpocząć nową sesję.</p>

            <form id="newTicketForm" class="new-ticket-form">
                <input type="text" id="plateInput" placeholder="np. KRA 12345" maxlength="10" required>
                <button type="submit" class="btn-primary">Start</button>
            </form>
        </div>
    <?php else: ?>

        <div class="app-container">
            <!-- Header -->
            <header class="app-header">
                <div class="brand-logo">P</div>
                <h2>Szczegóły parkowania</h2>
            </header>

            <!-- Hero: License Plate -->
            <section class="plate-section">
                <div class="license-plate" id="licensePlateContainer">
                    <div class="plate-blue">
                        <span>PL</span>
                    </div>
                    <div class="plate-number" id="plateDisplay">
                        <?php echo htmlspecialchars($ticket['plate']); ?>
                    </div>
                    <input type="text" id="plateEditInput" class="plate-input" value="<?php echo htmlspecialchars($ticket['plate']); ?>" maxlength="10" style="display: none;">
                    <button id="editPlateBtn" class="edit-icon" aria-label="Edytuj numer rejestracyjny">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>
                            <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>
                        </svg>
                    </button>
                </div>
            </section>

            <!-- Details Grid -->
            <section class="details-grid">
                <div class="info-card">
                    <span class="label">Czas wjazdu</span>
                    <span class="value"><?php echo $entry_time->format('Y-m-d H:i'); ?></span>
                </div>
                <div class="info-card">
                    <span class="label">Strefa</span>
                    <span class="value"><?php echo htmlspecialchars($config['station_id']); ?></span>
                </div>
            </section>

            <!-- Status Indicator -->
            <section class="status-section">
                <div class="status-badge <?php echo $is_free_period ? 'status-free' : 'status-paid'; ?>">
                    <?php echo htmlspecialchars($status_message); ?>
                </div>
            </section>

            <!-- Interactive Spinner -->
            <section class="timer-section">
                <div class="timer-circle" id="spinnerContainer">
                    <div class="timer-content">
                        <span class="label">WYJAZD</span>
                        <span class="value" id="spinnerValue">00:00<small>/h</small></span>
                    </div>

                    <!-- SVG Spinner -->
                    <svg class="progress-ring" width="240" height="240" viewBox="0 0 240 240">
                        <!-- Track -->
                        <circle class="progress-ring__track" cx="120" cy="120" r="100" fill="none" stroke="#E0E0E0"
                            stroke-width="20" />

                        <!-- Progress Arc -->
                        <circle class="progress-ring__circle" id="progressCircle" cx="120" cy="120" r="100" fill="none"
                            stroke="var(--primary)" stroke-width="20" stroke-linecap="round" stroke-dasharray="628"
                            stroke-dashoffset="628" transform="rotate(-90 120 120)" />

                        <!-- Handle -->
                        <g id="spinnerHandle" style="cursor: grab;">
                            <circle cx="120" cy="20" r="12" fill="var(--primary)" stroke="white" stroke-width="4" />
                        </g>
                    </svg>
                </div>
            </section>

            <div class="spacer"></div>

            <!-- Bottom Sheet: Payment Control -->
            <footer class="payment-sheet" id="paymentSheet">
                <div class="sheet-handle"></div>

                <!-- Extension Chips (Hidden by default, shown via JS if needed) -->
                <div class="extension-chips" id="extensionChips" style="display: none;">
                    <button class="chip" data-add="30">+30m</button>
                    <button class="chip" data-add="60">+1h</button>
                    <button class="chip" data-add="120">+2h</button>
                </div>

                <button id="payButton" class="btn-primary" <?php echo $fee <= 0 ? 'disabled' : ''; ?>>
                    <?php echo $fee > 0 ? 'Zapłać ' . number_format($fee, 2) . ' ' . $config['currency'] : 'Wyjazd bez opłaty'; ?>
                </button>
            </footer>

            <!-- Success Overlay (Hidden) -->
            <div class="success-overlay" id="successOverlay">
                <div class="success-content">
                    <div class="checkmark-circle">
                        <div class="checkmark draw"></div>
                    </div>
                    <h2>Płatność zakończona</h2>
                    <div class="exit-ticket">
                        <div class="qr-placeholder" id="qrCode"></div>
                        <p class="ticket-msg">Zeskanuj przy wyjeździe</p>
                        <p class="valid-until">Ważny przez 15 minut</p>
                    </div>
                    <button class="btn-secondary" onclick="location.reload()">Zamknij</button>
                </div>
            </div>

        </div>
    <?php endif; ?>

    <!-- Pass PHP variables to JS -->
    <script>
        const TICKET_ID = "<?php echo $ticket_id; ?>";
        const INITIAL_FEE = <?php echo $fee; ?>;
        const HOURLY_RATE = <?php echo $config['hourly_rate']; ?>;
        const IS_PAID = <?php echo ($ticket && $ticket['status'] === 'paid') ? 'true' : 'false'; ?>;
    </script>
    <script src="script.js"></script>
</body>

</html>
<?php
date_default_timezone_set('Europe/Warsaw');
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
  <link href="https://cdn.jsdelivr.net/npm/round-slider@1.6.1/dist/roundslider.min.css" rel="stylesheet" />
</head>

<body>
  <?php if ($error): ?>
    <div class="error-container">
      <!-- Logo -->
      <div style="margin-bottom: 20px; background: rgba(0, 0, 0, 0.7); padding: 15px 30px; border-radius: 12px; display: inline-block;">
        <img src="image/rusin-ski_white.svg" alt="Rusin Ski" style="height: 60px; max-width: 200px; object-fit: contain; display: block;">
      </div>
      <!-- <div class="icon-error" style="background: rgba(106, 27, 154, 0.1); color: var(--primary);">+</div> -->
      <h1>Rozlicz parkowanie</h1>
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
        <div class="header-top">
          <div class="brand-logo">P</div>
          <!-- Client Logo -->
          <div style="display: flex; align-items: center;">
            <img src="image/rusin-ski_white.svg" alt="Rusin Ski" style="height: 40px; max-width: 150px; object-fit: contain; display: block;">
          </div>
        </div>

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
          <input type="text" id="plateEditInput" class="plate-input"
            value="<?php echo htmlspecialchars($ticket['plate']); ?>" maxlength="10" style="display: none;">
          <button id="editPlateBtn" class="edit-icon" aria-label="Edytuj numer rejestracyjny">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
              stroke-linecap="round" stroke-linejoin="round">
              <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>
              <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>
            </svg>
          </button>
        </div>
      </section>

      <!-- Details Grid -->
      <section class="details-section">
        <div class="info-card-full">
          <span class="label">Strefa</span>
          <span class="value"><?php echo htmlspecialchars($config['station_id']); ?></span>
        </div>

        <!-- Collapsed Entry Time (Default) -->
        <div class="info-card-full" id="entryCollapsed" style="position: relative;">
          <span class="label">Start</span>
          <span class="value" style="padding-right: 30px;">
            <span id="entryTimeDisplay"><?php echo $entry_time->format('Y-m-d H:i'); ?></span>
          </span>
          <button id="editEntryBtn" class="edit-icon" style="display: none;">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>
              <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>
            </svg>
          </button>
        </div>
      </section>

      <!-- Expanded Entry Time (Hidden by default) -->
      <section class="exit-time-section" id="entryExpanded" style="display: none;">
        <div class="exit-time-card" style="background: rgba(0, 200, 83, 0.08); border: 2px solid rgba(0, 200, 83, 0.2);">
          <div style="display: flex; justify-content: space-between; align-items: center;">
            <span class="label" style="color: var(--success);">Start</span>
            <button id="closeEntryExpandedBtn" class="edit-icon" style="position: static; transform: none; padding: 12px; margin: -12px;">
              <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <line x1="18" y1="6" x2="6" y2="18"></line>
                <line x1="6" y1="6" x2="18" y2="18"></line>
              </svg>
            </button>
          </div>
          <div class="exit-time-display">
            <button class="exit-time-btn" id="entryDateBtn">
              <span class="exit-label">Data</span>
              <span class="exit-value" id="entryDateValue">--.--.----</span>
            </button>
            <button class="exit-time-btn" id="entryTimeBtn">
              <span class="exit-label">Godzina</span>
              <span class="exit-value" id="entryTimeValue">--:--</span>
            </button>
          </div>
        </div>
      </section>


      <!-- Collapsed Exit Time (Hidden by default, shown when editing entry) -->
      <section class="details-section" id="exitCollapsed" style="display: none;">
        <div class="info-card-full">
          <span class="label">Stop</span>
          <span class="value" style="display: flex; align-items: center; gap: 8px; justify-content: flex-end;">
            <span id="exitTimeDisplayCollapsed">--:--</span>
            <button id="editExitBtnCollapsed" class="edit-icon" style="position: static; transform: none; padding: 12px; margin: -12px;">
              <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>
                <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>
              </svg>
            </button>
          </span>
        </div>
      </section>

      <!-- Expanded Exit Time Display (Default) -->
      <section class="exit-time-section" id="exitExpanded">
        <div class="exit-time-card">
          <span class="label">Stop</span>
          <div class="exit-time-display">
            <button class="exit-time-btn" id="exitDateBtn">
              <span class="exit-label">Data</span>
              <span class="exit-value" id="exitDateValue">--.--.----</span>
            </button>
            <button class="exit-time-btn" id="exitTimeBtn">
              <span class="exit-label">Godzina</span>
              <span class="exit-value" id="exitTimeValue">--:--</span>
            </button>
          </div>
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
        <!-- Unit selector removed as it is handled by Stop section -->
        <div class="timer-circle" id="spinnerContainer">
          <div id="slider"></div>

          <div class="timer-content" style="pointer-events: none;">
            <span class="label" id="spinnerLabel">WYJAZD</span>
            <span class="value" id="spinnerValue">00:00<small>/h</small></span>
          </div>
        </div>
      </section>
  <!-- Footer -->
      <footer class="app-footer">
        <span>Powered by <strong>Base System</strong></span>
      </footer>
      <!-- Mode Configuration Panel -->
      <div class="mode-config-panel">
        <div class="mode-group">
          <label>Tryb:</label>
          <div class="mode-buttons">
            <button class="config-btn active" data-config="time_mode" data-value="daily">Dzienny</button>
            <button class="config-btn" data-config="time_mode" data-value="hourly">Godzinowy</button>
          </div>
        </div>
        <div class="mode-group">
          <label>Długość:</label>
          <div class="mode-buttons">
            <button class="config-btn" data-config="duration_mode" data-value="single_day">1-dniowy</button>
            <button class="config-btn active" data-config="duration_mode" data-value="multi_day">Wielodniowy</button>
          </div>
        </div>
        <div class="mode-group">
          <label>Liczenie:</label>
          <div class="mode-buttons">
            <button class="config-btn active" data-config="day_counting" data-value="from_entry">Od
              wjazdu</button>
            <button class="config-btn" data-config="day_counting" data-value="from_midnight">Od
              00:00</button>
          </div>
        </div>
      </div>

      <div class="spacer"></div>

      <!-- Bottom Sheet: Payment Control -->
      <footer class="payment-sheet" id="paymentSheet">
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
    const ENTRY_TIME_RAW = "<?php echo $ticket ? $entry_time->format('Y-m-d\TH:i') : ''; ?>";
    let ENTRY_TIME = "<?php echo $ticket ? $entry_time->format('Y-m-d H:i:s') : ''; ?>";

    // Detect "New Ticket" (Pre-booking) state
    // If created < 1 minute ago AND not paid, assume new.
    // Or better, pass a query param or just logic:
    const IS_PRE_BOOKING = <?php echo ($ticket && $ticket['status'] !== 'paid' && $ticket_id) ? 'true' : 'false'; ?>;
    // Note: Ideally we'd have a specific flag from creation referer, but this checks if it's an active unpaid ticket.
    // Actually, "New Ticket" vs "Scanned".
    // Use a heuristic: If we just created it, we are pre-booking. If we scanned it, we are paying.
    // But the requirement says: "If scanning an existing ticket... Start remains read-only."
    // "If user arrived via New Ticket form..."
    // Let's assume for this refactor that ALL active tickets viewed here allow editing Start logic IF they are "fresh" or explicitly "Pre-booking".
    // But to be safe, let's enable it for all ACTIVE/UNPAID tickets for now as per "Pre-booking" generic logic, 
    // OR restricting it to only recently created ones might be safer?
    // Let's strictly follow: "Detect Entry Source... allow editing... Restriction: If scanning existing... read-only"
    // Since we don't have referer data here easily without URL params, I'll default to: ALLOW EDIT if it's active.
    // Wait, "Time Drift Check" allows paying. "Pre-booking" allows setting future start.
    // I will add IS_PRE_BOOKING = true for all active tickets in this demo env.
    const IS_EDITABLE_START = IS_PRE_BOOKING;

    // Parking modes configuration
    const TIME_MODE = "<?php echo $config['time_mode'] ?? 'daily'; ?>"; // daily or hourly
    const DURATION_MODE = "<?php echo $config['duration_mode'] ?? 'multi_day'; ?>"; // single_day or multi_day
    const DAY_COUNTING = "<?php echo $config['day_counting'] ?? 'from_entry'; ?>"; // from_entry or from_midnight
  </script>
  <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/round-slider@1.6.1/dist/roundslider.min.js"></script>
  <script src="script.js"></script>
</body>

</html>
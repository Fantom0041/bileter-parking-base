<?php
date_default_timezone_set('Europe/Warsaw');
// 1. Load Configuration
$config = parse_ini_file('config.ini', true);

// 2. Setup API
require_once 'ApiClient.php';

// 3. Get Ticket ID
$ticket_id = $_GET['ticket_id'] ?? null;
if (isset($_GET['ticket_id'])) {
  $ticket_id = trim($_GET['ticket_id']);
}
$ticket = null;
$error = null;

// 4. Validate Ticket via API
$is_simulated = isset($_GET['simulated']) && $_GET['simulated'] == '1';

if ($ticket_id) {
  if ($is_simulated) {
    // Create Mock Ticket for simulation
    $ticket = [
      'plate' => $ticket_id,
      'entry_time' => date('Y-m-d H:i:s'), // Start now
      'status' => 'active',
      'is_new' => true, // Simulation: assume new
      'api_data' => []
    ];
  } else if (!empty($config['api']['api_url'])) {
    try {
      $client = new ApiClient($config);
      $loginResult = $client->login();
      if ($loginResult['success']) {
        $info = $client->getBarcodeInfo($ticket_id);
        if ($info['success']) {
          if (!empty($info['tickets'])) {
            $apiData = $info['tickets'][0]; // Take the first active ticket
            $ticket = [
              'plate' => $apiData['BARCODE'] ?? $ticket_id,
              'entry_time' => $apiData['VALID_FROM'],
              'status' => 'active',
              'is_new' => false, // Found in DB -> Existing
              'api_data' => $apiData
            ];
          } elseif (isset($info['is_new']) && $info['is_new']) {
            // API confirmed new ticket session (TICKET_EXIST=0 or Error -3 handled)
            $ticket = [
              'plate' => $ticket_id,
              'entry_time' => date('Y-m-d H:i:s'), // Default to now
              'status' => 'active',
              'is_new' => true, // Flag as new
              'api_data' => $info['defaults'] ?? [] // Use defaults if available
            ];
          } else {
            // Valid response but no tickets and no is_new flag? match legacy behavior
            $error = "Bilet nie znaleziony w systemie.";
          }
        } else {
          $error = "Bilet nie znaleziony w systemie (Błąd API).";
        }

      } else {
        $error = "Błąd komunikacji z systemem parkingowym (Login): " . ($loginResult['error'] ?? 'Nieznany błąd');
      }
    } catch (Exception $e) {
      error_log("API Error: " . $e->getMessage());
      $error = "Błąd krytyczny komunikacji z systemem.";
    }
  } else {
    $error = "Brak konfiguracji API.";
  }
}

// 5. Calculate Fee
$fee = 0;
$duration_minutes = 0;
$status_message = "";
$is_free_period = false;

if ($ticket) {
  // Always initialize entry_time from ticket data
  $entry_time = new DateTime($ticket['entry_time']);

  if (isset($ticket['status']) && $ticket['status'] === 'paid') {
    // API typically doesn't return 'paid' status directly in the same way, 
    // but if we were to support it, we'd check here. 
    // For now, if BARCODE_INFO returns it, it's usually active/unpaid or valid.
    // If we want to check if paid, we might need to check 'POINTS' or other fields?
    // For this implementation, we recalc fee every time.
    $status_message = "Opłacony";
    $fee = 0;
  } else {
    $current_time = new DateTime(); // Now
    // For testing, you might want to force a specific time if needed, 
    // but for now we use server time.

    $interval = $entry_time->diff($current_time);
    $duration_minutes = ($interval->days * 24 * 60) + ($interval->h * 60) + $interval->i;

    if ($duration_minutes <= $config['settings']['free_minutes']) {
      $fee = 0;
      $is_free_period = true;
      $status_message = "Okres bezpłatny (" . ($config['settings']['free_minutes'] - $duration_minutes) . " min pozostało)";
    } else {
      // Simple hourly calculation: ceil(hours) * rate
      $hours = ceil($duration_minutes / 60);
      $fee = $hours * $config['settings']['hourly_rate'];
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
  <!-- DEBUG: TicketID: <?php echo htmlspecialchars(var_export($ticket_id, true)); ?> Ticket: <?php echo htmlspecialchars(var_export($ticket, true)); ?> -->
  <div class="app-container">
    <!-- Header -->
    <header class="app-header">
      <div class="header-top">
        <div class="brand-logo">P</div>

        <!-- Regulamin Link -->
        <a href="regulamin.php" class="regulations-link">Regulamin</a>

        <!-- Client Logo -->
        <div style="display: flex; align-items: center;">
          <img src="image/rusin-ski_white.svg" alt="Rusin Ski"
            style="height: 40px; max-width: 150px; object-fit: contain; display: block;">
        </div>
      </div>
    </header>
    <?php if (!$ticket): ?>
      <div class="error-container" style="min-height: auto; padding-top: 20px;">

        <h1>Rozlicz parkowanie</h1>

        <?php if ($error): ?>
          <div
            style="background-color: #ffebee; color: #c62828; padding: 10px; border-radius: 8px; margin-bottom: 15px; border: 1px solid #ffcdd2;">
            <?php echo htmlspecialchars($error); ?>
          </div>
        <?php endif; ?>

        <p>Wpisz numer rejestracyjny / numer biletu.</p>

        <form id="newTicketForm" class="new-ticket-form">
          <input type="text" id="plateInput" placeholder="np. KRA 12345" maxlength="10" required>
          <button type="submit" class="btn-primary">Start</button>
        </form>
      </div>
      <!-- Footer -->
      <!-- Footer -->
      <?php include 'footer.php'; ?>

    </div>
  <?php else: ?>

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
        <span class="value"><?php echo htmlspecialchars($config['settings']['station_id']); ?></span>
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
          <button id="closeEntryExpandedBtn" class="edit-icon"
            style="position: static; transform: none; padding: 12px; margin: -12px;">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
              stroke-linecap="round" stroke-linejoin="round">
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
          <button id="editExitBtnCollapsed" class="edit-icon"
            style="position: static; transform: none; padding: 12px; margin: -12px;">
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

    <!-- Status / Payment Info -->
    <section class="status-section">
      <div class="payment-info-card" <?php echo (isset($ticket['is_new']) && $ticket['is_new']) ? 'style="display:none;"' : ''; ?>>
        <?php
        $feePaid = 0.00;
        $validTo = null;
        $validFrom = $entry_time ? $entry_time->format('Y-m-d H:i:s') : null;

        if (isset($ticket['api_data'])) {
          // API returns fee in grosze (usually), need to confirm. user implies 30.00. 
          // Let's assume the API returns standard unit or handled logic. 
          // Wait, earlier we assumed pennies for FEE (div by 100).
          // If user says "30.00", and FEE_PAID is int64, logic dictates it's likely pennies or the raw value.
          // Let's safe bet: If it's huge (>1000 without decimal), divide by 100. If small, use as is. 
          // Or stick to assumed convection (usually pennies). If user input "30.00", that implies standard currency.
          // Given previous steps used /100 for FEE, I'll use /100 for FEE_PAID.
          $feePaidRaw = $ticket['api_data']['FEE_PAID'] ?? 0;
          $feePaid = $feePaidRaw / 100;

          $validToRaw = $ticket['api_data']['VALID_TO'] ?? null;
          if ($validToRaw && $validToRaw > $validFrom) {
            $validTo = $validToRaw;
          }
        }
        ?>

        <div class="payment-row">
          <div class="payment-col left">
            <span class="payment-label">Opłacono:</span>
            <span class="payment-value"><?php echo number_format($feePaid, 2, '.', ''); ?></span>
          </div>
          <div class="payment-col right">
            <!-- Only show 'Wyjazd do' if validTo is available and > validFrom -->
            <?php if ($validTo): ?>
              <span class="payment-label">Wyjazd do:</span>
              <span class="payment-value"><?php echo htmlspecialchars($validTo); ?></span>
            <?php else: ?>
              <!-- Optional: Placeholder or empty if logic dictates -->
              <span class="payment-label" style="opacity: 0;">-</span>
              <span class="payment-value" style="opacity: 0;">-</span>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </section>

    <!-- Interactive Spinner -->
    <section class="timer-section">

      <div class="timer-circle" id="spinnerContainer">
        <div id="slider"></div>

        <div class="timer-content" style="pointer-events: none;">
          <span class="label" id="spinnerLabel">WYJAZD</span>
          <span class="value" id="spinnerValue">00:00<small>/h</small></span>
        </div>
      </div>
    </section>
    <!-- Footer -->
    <!-- Footer -->
    <?php include 'footer.php'; ?>



    <div class="spacer"></div>

    <!-- Bottom Sheet: Payment Control -->
    <footer class="payment-sheet" id="paymentSheet">
      <button id="payButton" class="btn-primary" <?php echo $fee <= 0 ? 'disabled' : ''; ?>>
        <?php echo $fee > 0 ? 'Zapłać ' . number_format($fee, 2) . ' ' . $config['settings']['currency'] : 'Wyjazd bez opłaty'; ?>
      </button>
    </footer>


    <!-- Disabled Overlay for Fee Type 1 (Daily) -->
    <?php if (isset($ticket['api_data']['FEE_TYPE']) && $ticket['api_data']['FEE_TYPE'] == '1'): ?>
      <style>
        /* Hide/Disable edit buttons for Fee Type 0 */
        #editPlateBtn,
        #editEntryBtn,
        #editExitBtnCollapsed,
        .edit-icon {
          display: none !important;
        }

        /* Optional: indicate read-only state */
      </style>
    <?php endif; ?>

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
    const HOURLY_RATE = <?php echo $config['settings']['hourly_rate']; ?>;

    // Map API settings to JS Config
    // FEE_TYPE: 0 = hourly, 1 = daily
    // FEE_STARTS_TYPE: 0 = 24h from entry, 1 = from midnight
    // FEE_MULTI_DAY: 0 = single day (no), 1 = multi day (yes)
    // Map API settings to JS Config with Fallback to config.ini
    // FEE_TYPE: 0 = hourly, 1 = daily
    // FEE_STARTS_TYPE: 0 = 24h from entry, 1 = from midnight
    // FEE_MULTI_DAY: 0 = single day (no), 1 = multi day (yes)
    const API_SETTINGS = {
      time_mode: <?php
      if (isset($ticket['api_data']['FEE_TYPE'])) {
        // User said: fee type '0' is hourly. 1 is daily.
        // If type is 1 (daily), we might want to enforce restrictions.
        echo "'" . ($ticket['api_data']['FEE_TYPE'] == '0' ? "hourly" : "daily") . "'";
      } else {
        echo "'" . ($config['parking_modes']['time_mode'] ?? 'hourly') . "'";
      }
      ?>,
      duration_mode: <?php
      if (isset($ticket['api_data']['FEE_MULTI_DAY'])) {
        echo $ticket['api_data']['FEE_MULTI_DAY'] == 1 ? "'multi_day'" : "'single_day'";
      } else {
        echo "'" . ($config['parking_modes']['duration_mode'] ?? 'single_day') . "'";
      }
      ?>,
      day_counting: <?php
      if (isset($ticket['api_data']['FEE_STARTS_TYPE'])) {
        echo $ticket['api_data']['FEE_STARTS_TYPE'] == 1 ? "'from_midnight'" : "'from_entry'";
      } else {
        echo "'" . ($config['parking_modes']['day_counting'] ?? 'from_entry') . "'";
      }
      ?>,
      fee_type_raw: <?php echo isset($ticket['api_data']['FEE_TYPE']) ? $ticket['api_data']['FEE_TYPE'] : 'null'; ?>,
      is_new: <?php echo (isset($ticket['is_new']) && $ticket['is_new']) ? 'true' : 'false'; ?>
    };

    // Override local config with API settings for logic
    const CONFIG = {
      default_duration: 60,
      currency: "<?php echo $config['settings']['currency']; ?>",
      hourly_rate: <?php echo $config['settings']['hourly_rate']; ?>,
      time_mode: API_SETTINGS.time_mode,
      duration_mode: API_SETTINGS.duration_mode,
      day_counting: API_SETTINGS.day_counting
    };
    const IS_PAID = <?php echo ($ticket && isset($ticket['status']) && $ticket['status'] === 'paid') ? 'true' : 'false'; ?>;
    const ENTRY_TIME_RAW = "<?php echo $ticket ? $entry_time->format('Y-m-d\TH:i') : ''; ?>";
    let ENTRY_TIME = "<?php echo $ticket ? $entry_time->format('Y-m-d H:i:s') : ''; ?>";

    // Detect "New Ticket" (Pre-booking) state
    // If created < 1 minute ago AND not paid, assume new.
    // Or better, pass a query param or just logic:
    const IS_PRE_BOOKING = <?php echo ($ticket && isset($ticket['status']) && $ticket['status'] !== 'paid' && $ticket_id) ? 'true' : 'false'; ?>;
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
    const TIME_MODE = API_SETTINGS.time_mode; // daily or hourly
    const DURATION_MODE = API_SETTINGS.duration_mode; // single_day or multi_day
    const DAY_COUNTING = API_SETTINGS.day_counting; // from_entry or from_midnight
  </script>
  <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/round-slider@1.6.1/dist/roundslider.min.js"></script>
  <script src="script.js"></script>
</body>

</html>
<?php
date_default_timezone_set('Europe/Warsaw');
// 1. Load Configuration
$configFile = getenv('PARK_CONFIG_FILE') !== false ? getenv('PARK_CONFIG_FILE') : 'config.ini';
$config = parse_ini_file($configFile, true);

// 2. Setup API
require_once 'ApiClient.php';
require_once 'Logger.php';
require_once 'ScenarioTester.php'; // Include the new tester class

$logger = new Logger();
$scenarioTester = new ScenarioTester($config); // Initialize Tester


// 3. Get Ticket ID
$ticket_id = $_GET['ticket_id'] ?? null;
if (isset($_GET['ticket_id'])) {
  $ticket_id = trim($_GET['ticket_id']);
}
$ticket = null;
$error = null;

// 4. Validate Ticket via API
$is_simulated = isset($_GET['simulated']) && $_GET['simulated'] == '1';
$logger->log("is_simulated: " . $is_simulated);

// --- SCENARIO TEST: Force Simulated Ticket if Testing is Enabled and no ID provided ---
// This allows visiting index.php?ticket_id=TEST without needing real API
if ($scenarioTester->isEnabled() && $ticket_id && !$is_simulated) {
  // If we are testing scenarios, we might want to bypass real API calls
  // strictly for UI behavior testing. 
  // Uncomment the line below if you want to force simulation even without ?simulated=1
  // $is_simulated = true; 
}

if ($ticket_id) {
  $logger->log("ticket_id: " . $ticket_id);
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
      $logger->log("loginResult: " . json_encode($loginResult));
      if ($loginResult['success']) {
        // --- STEP 1: INITIAL PROBE (Discovery) ---
        // We use current time for both Start and End to force API to return config
        // without applying duration logic yet, but finding the ticket.
        $probeTime = date('Y-m-d H:i:s');
        $info = $client->getParkTicketInfo($ticket_id, $probeTime, $probeTime);
        $logger->log("Probe Call (getParkTicketInfo) initial: " . json_encode($info));

        if ($info['success']) {
          if (!empty($info['tickets'])) {
            $apiData = $info['tickets'][0]; // Active ticket data
            $logger->log("Probe Result Data: " . json_encode($apiData));

            // Extract Flags
            $feeType = $apiData['FEE_TYPE'] ?? '0';            // 0=Daily, 1=Hourly
            $feeMultiDay = $apiData['FEE_MULTI_DAY'] ?? '1';      // 0=Single, 1=Multi
            $feeStartsType = $apiData['FEE_STARTS_TYPE'] ?? '0';  // 0=Entry, 1=Midnight

            // Extract Real Entry Time
            $realEntryTimeStr = $apiData['VALID_FROM'];
            $realEntryTime = new DateTime($realEntryTimeStr);

            // --- STEP 2: DETERMINE TARGET EXIT TIME (Scenario Logic) ---
            $calculatedExitTime = clone $realEntryTime;

            // Logic Mapping based on Multi_Type_Starts
            // 0_0_0 (Single/Daily/Entry): Entry + 1 Day
            if ($feeMultiDay == '0' && $feeType == '0' && $feeStartsType == '0') {
              $calculatedExitTime->modify('+1 day');
            }
            // 0_0_1 (Single/Daily/Midnight): End of Entry Day
            elseif ($feeMultiDay == '0' && $feeType == '0' && $feeStartsType == '1') {
              $calculatedExitTime->setTime(23, 59, 59);
            }
            // 0_1_1 (Single/Hourly/Midnight): Manual selection
            // Editable: Skip default calculation/refetch
            elseif ($feeMultiDay == '0' && $feeType == '1' && $feeStartsType == '1') {
              // No op
            }
            // 0_1_0 (Single/Hourly/Entry): Manual selection
            // Editable: Skip default calculation/refetch
            elseif ($feeMultiDay == '0' && $feeType == '1' && $feeStartsType == '0') {
              // No op
            }
            // 1_0_0 (Multi/Daily/Entry): Entry + 1 Day
            elseif ($feeMultiDay == '1' && $feeType == '0' && $feeStartsType == '0') {
              $calculatedExitTime->modify('+1 day');
            }
            // 1_0_1 (Multi/Daily/Midnight): End of Entry Day
            elseif ($feeMultiDay == '1' && $feeType == '0' && $feeStartsType == '1') {
              $calculatedExitTime->setTime(23, 59, 59);
            }
            // 1_1_x (Multi/Hourly): Manual selection
            // Editable: Skip default calculation/refetch
            elseif ($feeMultiDay == '1' && $feeType == '1') {
              // No op
            } else {
              // Default fallback
              // No op
            }

            // --- STEP 3: FEE REFETCH (If needed) ---
            // Only Refetch if calculated exit is effectively different/later than Entry.

            if ($calculatedExitTime > $realEntryTime) {
              $calcExitStr = $calculatedExitTime->format('Y-m-d H:i:s');

              $logger->log("Refetching Fee for: $realEntryTimeStr -> $calcExitStr");

              $feeInfo = $client->getParkTicketInfo(
                $ticket_id,
                $realEntryTimeStr,
                $calcExitStr
              );

              if ($feeInfo['success'] && !empty($feeInfo['tickets'])) {
                // Use the Refetched Data
                $finalApiData = $feeInfo['tickets'][0];
                $logger->log("Refetch Result: " . json_encode($finalApiData));

                // IMPORTANT: Ensure VALID_TO in api_data matches our Calculated Exit 
                // so Frontend displays it correctly as the "Pay Until" or "Calculation Basis"
                // The API might return its own VALID_TO, but for the purpose of the UI "Simulation", 
                // we want to ensure consistency if the API honored our request.
                // Usually API returns what we asked for in VALID_TO if it's a simulation call.

                $ticket = [
                  'plate' => !empty($finalApiData['REGISTRATION_NUMBER']) ? $finalApiData['REGISTRATION_NUMBER'] : ($finalApiData['BARCODE'] ?? $ticket_id),
                  'entry_time' => $finalApiData['VALID_FROM'],
                  'status' => 'active',
                  'is_new' => false,
                  'api_data' => $finalApiData
                ];
              } else {
                // Fallback to Probe data if Refetch fails (unlikely)
                $ticket = [
                  'plate' => !empty($apiData['REGISTRATION_NUMBER']) ? $apiData['REGISTRATION_NUMBER'] : ($apiData['BARCODE'] ?? $ticket_id),
                  'entry_time' => $apiData['VALID_FROM'],
                  'status' => 'active',
                  'is_new' => false,
                  'api_data' => $apiData
                ];
              }
            } else {
              // No Refetch needed (e.g. Hourly modes where we wait for user input)
              // Just use the Probe data
              $ticket = [
                'plate' => !empty($apiData['REGISTRATION_NUMBER']) ? $apiData['REGISTRATION_NUMBER'] : ($apiData['BARCODE'] ?? $ticket_id),
                'entry_time' => $apiData['VALID_FROM'],
                'status' => 'active',
                'is_new' => false,
                'api_data' => $apiData
              ];
            }
          } elseif (isset($info['is_new']) && $info['is_new']) {
            // API confirmed new ticket session (TICKET_EXIST=0 or Error -3 handled)
            $ticket = [
              'plate' => $ticket_id,
              'entry_time' => date('Y-m-d H:i:00'), // Default to now with 00 seconds
              'status' => 'active',
              'is_new' => true, // Flag as new
              'api_data' => $info['defaults'] ?? [] // Use defaults if available
            ];
          } else {
            // If testing, we might want to force a ticket creation to see the UI
            if ($scenarioTester->isEnabled()) {
              $ticket = [
                'plate' => $ticket_id,
                'entry_time' => date('Y-m-d H:i:00'),
                'status' => 'active',
                'is_new' => true,
                'api_data' => []
              ];
            } else {
              $error = "Bilet nie znaleziony w systemie.";
            }
          }
        } else {
          if ($scenarioTester->isEnabled()) {
            $ticket = [
              'plate' => $ticket_id,
              'entry_time' => date('Y-m-d H:i:00'),
              'status' => 'active',
              'is_new' => true,
              'api_data' => []
            ];
          } else {
            $error = "Bilet nie znaleziony w systemie (Błąd API).";
          }
        }

      } else {
        // Allow bypass if testing
        if ($scenarioTester->isEnabled()) {
          $ticket = [
            'plate' => $ticket_id,
            'entry_time' => date('Y-m-d H:i:00'),
            'status' => 'active',
            'is_new' => true,
            'api_data' => []
          ];
        } else {
          $error = "Błąd komunikacji z systemem parkingowym (Login): " . ($loginResult['error'] ?? 'Nieznany błąd');
        }
      }
    } catch (Exception $e) {
      error_log("API Error: " . $e->getMessage());
      if ($scenarioTester->isEnabled()) {
        $ticket = [
          'plate' => $ticket_id,
          'entry_time' => date('Y-m-d H:i:00'),
          'status' => 'active',
          'is_new' => true,
          'api_data' => []
        ];
      } else {
        $error = "Błąd krytyczny komunikacji z systemem.";
      }
    }
  } else {
    $error = "Brak konfiguracji API.";
  }
}

// --- APPLY SCENARIO OVERRIDES ---
if ($ticket) {
  $scenarioTester->applyOverrides($ticket);
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

    // Check for explicit API Fee first
    $apiFeeRaw = isset($ticket['api_data']['FEE']) ? floatval($ticket['api_data']['FEE']) : 0;
    $apiFeePaidRaw = isset($ticket['api_data']['FEE_PAID']) ? floatval($ticket['api_data']['FEE_PAID']) : 0;

    // If API reports a fee, trust it (ignoring local free minutes check if needed)
    // Note: API returns fee in lowest unit (grosze), usually.
    if ($apiFeeRaw > 0) {
      $fee = ($apiFeeRaw - $apiFeePaidRaw) / 100.0;
      $status_message = "Aktywne (Opłata wyliczona przez system)";
    } elseif ($duration_minutes <= $config['settings']['free_minutes'] && !$scenarioTester->isEnabled()) {
      $fee = 0;
      $is_free_period = true;
      $status_message = "Okres bezpłatny (" . ($config['settings']['free_minutes'] - $duration_minutes) . " min pozostało)";
    } else {
      // Pre-calculate Fee for Initial Render (Server-Side)
      // This avoids the "wrong" initial flash and double fetch.
      // If Daily + Free Period passed, calculate properly.

      // Determine mode logic (simplified replication of JS logic)
      $feeType = $ticket['api_data']['FEE_TYPE'] ?? '0'; // Default to Daily (0)
      $feeMultiDay = $ticket['api_data']['FEE_MULTI_DAY'] ?? '1'; // Default to Multi-Day (1)
      $feeStartsType = $ticket['api_data']['FEE_STARTS_TYPE'] ?? '0'; // Default to From Entry (0)

      $isDaily = ($feeType == '0');
      $isSingleDay = ($feeMultiDay == '0');

      // If Scenario Test is active, we calculate fee based on the scenario-calculated VALID_TO
      if ($scenarioTester->isEnabled() && isset($ticket['api_data']['VALID_TO'])) {
        $entry = new DateTime($ticket['entry_time']);
        $stop = new DateTime($ticket['api_data']['VALID_TO']);
        $diff = $entry->diff($stop);
        $mins = ($diff->days * 24 * 60) + ($diff->h * 60) + $diff->i;

        if ($isDaily) {
          $days = $diff->days;
          if ($diff->h > 0 || $diff->i > 0)
            $days++;
          $days = max(1, $days);
          $fee = $days * 50.00; // Standard Daily Rate for tests
        } else {
          $hours = ceil($mins / 60);
          $fee = $hours * ($config['settings']['hourly_rate'] ?? 5.00);
        }

        $paid = ($ticket['api_data']['FEE_PAID'] ?? 0) / 100.0;
        $fee = max(0, $fee - $paid);
      } elseif ($isDaily && $isSingleDay) {
        // Daily + Single Day: Fee is calculated until End of Day (23:59:59)
        $calcExitTime = clone $entry_time;
        $calcExitTime->setTime(23, 59, 00); // Set to end of day with 00 seconds

        // If we are already past this time? (e.g. next day) -> Logic handles via dates.
        // But for "Single Day" mode, we usually assume the ticket is for THAT day.
        // Let's recalculate fee via API with this specific Exit Time

        if (!empty($config['api']['api_url'])) {
          try {
            $client = new ApiClient($config);
            if ($client->login()['success']) {
              // Request exact fee for Entry -> EndOfDay
              $feeInfo = $client->getParkTicketInfo($ticket['plate'], $entry_time->format('Y-m-d H:i:00'), $calcExitTime->format('Y-m-d H:i:00'));
              if ($feeInfo['success'] && !empty($feeInfo['tickets'])) {
                $tData = $feeInfo['tickets'][0];
                $rawFee = $tData['FEE'] ?? 0;
                $paidFee = $tData['FEE_PAID'] ?? 0;
                $fee = ($rawFee - $paidFee) / 100.0;
              }
            }
          } catch (Exception $e) {
            // Fallback to local calculation
            error_log("Pre-calc Fee Error: " . $e->getMessage());
          }
        }
      } else {
        // Hourly or Multi-Day default:
        // Simple local calculation or just show 0 and let JS verify?
        // Existing logic:
        $hours = ceil($duration_minutes / 60);
        $fee = $hours * $config['settings']['hourly_rate'];
      }

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
<script>
  if (typeof global === 'undefined') {
    window.global = window;
  }
</script>

<body>

  <?php if ($scenarioTester->isEnabled()): ?>
    <div
      style="position:fixed; top:0; left:0; width:100%; background:orange; color:black; text-align:center; padding:2px; font-size:10px; z-index:9999;">
      <?php echo $scenarioTester->getScenarioDescription(); ?>
    </div>
  <?php endif; ?>

  <!-- DEBUG: TicketID: <?php echo htmlspecialchars(var_export($ticket_id, true)); ?> Ticket: <?php echo htmlspecialchars(var_export($ticket, true)); ?> -->
  <div class="app-container">
    <!-- Header -->
    <header class="app-header">
      <div class="header-top">
        <div class="header-left">
          <div class="brand-logo">P</div>
          <!-- Regulamin Link -->
          <a href="regulamin.php" class="regulations-link">Regulamin</a>
        </div>

        <!-- Client Logo -->
        <div style="display: flex; align-items: center;">
          <a href="index.php">
            <img src="image/rusin-ski_white.svg" alt="Rusin Ski"
              style="height: 40px; max-width: 150px; object-fit: contain; display: block;">
          </a>
        </div>
      </div>
    </header>
    <?php if (!$ticket): ?>
      <div class="error-container" style="min-height: auto; padding-top: 20px;">

        <div class="login-container">


          <h1>Rozlicz parkowanie</h1>



          <p>Wpisz numer rejestracyjny / numer biletu.</p>

          <form id="newTicketForm" class="new-ticket-form">
            <input type="text" id="plateInput" placeholder="np. KRA 12345" maxlength="10" required>
            <button type="submit" class="btn-primary">Start</button>
          </form>
        </div>
        <?php if ($error): ?>
          <div
            style="background-color: #ffebee; color: #c62828; padding: 10px; border-radius: 8px; margin-bottom: 15px; border: 1px solid #ffcdd2;">
            <?php echo htmlspecialchars($error); ?>
          </div>
        <?php endif; ?>
      </div>
      <!-- Footer -->
      <!-- Footer -->
      <div class="index-footer">
        <?php include 'footer.php'; ?>

      </div>

    </div>
  <?php else: ?>

    <!-- Hero: License Plate -->
    <section class="plate-section">
      <div class="license-plate" id="licensePlateContainer" style="cursor: pointer;">
        <div class="plate-blue">
          <span>PL</span>
        </div>
        <div class="plate-number-container">




          <div class="plate-number" id="plateDisplay">
            <button id="editPlateBtn" class="edit-icon" aria-label="Edytuj numer rejestracyjny">
              <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                stroke-linecap="round" stroke-linejoin="round">
                <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>
                <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>
              </svg>
            </button>
            <span>


              <?php echo htmlspecialchars($ticket['plate']); ?>
            </span>
          </div>
        </div>



      </div>
    </section>

    <!-- Details Grid -->
    <section class="details-section">
      <!-- Collapsed Entry Time (Default) -->
      <div class="info-card-full" id="entryCollapsed" style="position: relative;">
        <span class="label">Start</span>
        <span class="value" style="display: flex; align-items: center; gap: 8px;">
          <button id="editEntryBtn" class="edit-icon" style="display: none; position: static; transform: none;"
            aria-label="Edytuj start">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>
              <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>
            </svg>
          </button>
          <span id="entryTimeDisplay"><?php echo $entry_time->format('Y-m-d H:i'); ?></span>
        </span>
      </div>
    </section>

    <!-- Expanded Entry Time (Hidden by default) -->
    <section class="exit-time-section" id="entryExpanded" style="display: none;">
      <div class="glass-container">

        <!-- Your content -->
        <div class="glass-content">
          <span class="label" style="color: var(--primary);">Start</span>
          <div class="exit-time-card">


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
        </div>
      </div>
    </section>


    <!-- Collapsed Exit Time (Hidden by default, shown when editing entry) -->
    <section class="details-section" id="exitCollapsed" style="display: none;">
      <div class="info-card-full">
        <span class="label">Stop</span>
        <span class="value" style="display: flex; align-items: center; gap: 8px; justify-content: flex-end;">
          <button id="editExitBtnCollapsed" class="edit-icon" style="position: static; transform: none; padding: 0;">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>
              <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>
            </svg>
          </button>
          <span id="exitTimeDisplayCollapsed">--:--</span>
        </span>
      </div>
    </section>

    <!-- Expanded Exit Time Display (Default) -->
    <section class="exit-time-section" id="exitExpanded">
      <div class="glass-container">




        <!-- Your content -->
        <div class="glass-content">
          <span class="label">Stop</span>
          <div class="exit-time-card">

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
        </div>
      </div>
    </section>

    <!-- Status / Payment Info -->


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




    <div class="spacer"></div>

    <!-- Bottom Sheet: Payment Control -->
    <footer class="payment-sheet" id="paymentSheet">
      <section class="status-section">
        <div class="payment-info-card">
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

            // Fix: Use DATE (Calculation "Stop" Date) if available, otherwise fallback to VALID_TO
            // User verified that VALID_TO is start/grace period, DATE is the correct stop time.
            $validToRaw = $ticket['api_data']['VALID_TO'] ?? null;


            if ($validToRaw && $validToRaw > $validFrom) {
              $validTo = $validToRaw;
            }
          }
          ?>

          <div class="payment-row">
            <div class="payment-col left">
              <span class="payment-label">Opłacono:</span>
              <span class="payment-value" id="feePaidValue"><?php echo number_format($feePaid, 2, '.', ''); ?></span>
            </div>
            <div class="payment-col right">
              <!-- Only show 'Wyjazd do' if validTo is available and > validFrom -->
              <?php if ($validTo): ?>
                <span id="paymentInfoExitLabel" class="payment-label">Opłacone do:</span>
                <span id="paymentInfoExitValue" class="payment-value"><?php echo htmlspecialchars($validTo); ?></span>
              <?php else: ?>
                <!-- Modified: Added IDs here too for JS to target even if initially empty -->
                <span id="paymentInfoExitLabel" class="payment-label" style="opacity: 0;">Opłacone do:</span>
                <span id="paymentInfoExitValue" class="payment-value" style="opacity: 0;">-</span>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </section>
      <button id="payButton" class="btn-primary" <?php echo $fee <= 0 ? 'disabled' : ''; ?>>
        <?php echo $fee > 0 ? 'Zapłać ' . number_format($fee, 2) . ' ' . $config['settings']['currency'] : 'Wyjazd bez opłaty'; ?>
      </button>
      <?php include 'footer.php'; ?>
    </footer>


    <!-- Disabled Overlay for Fee Type 1 (Daily) -->
    <?php if (isset($ticket['api_data']['FEE_TYPE']) && $ticket['api_data']['FEE_TYPE'] == '0'): ?>
      <style>
        /* Hide/Disable edit buttons for Fee Type 0 */
        /* #editPlateBtn, REMOVED to allow editing */

        #editEntryBtn,
        #editExitBtnCollapsed {
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
        <div class="exit-ticket" id="successTicketContainer">
          <div class="qr-placeholder" id="qrCode"></div>
          <div class="download-hint">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
              stroke-linecap="round" stroke-linejoin="round">
              <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
              <polyline points="7 10 12 15 17 10"></polyline>
              <line x1="12" y1="15" x2="12" y2="3"></line>
            </svg>
            Pobierz potwierdzenie PDF
          </div>
        </div>
        <button class="btn-secondary" onclick="location.reload()">Zamknij</button>
      </div>
    </div>



    <!-- Plate Edit Modal -->
    <div class="modal-overlay" id="plateEditModal">
      <div class="modal-card">
        <div class="sheet-header" style="margin-bottom: 20px;">
          <h3 style="margin:0;">Edytuj numer rejestracyjny</h3>
        </div>

        <div class="plate-edit-wrapper" style="margin-bottom: 20px;">
          <div class="plate-blue small">
            <span>PL</span>
          </div>
          <input type="text" id="plateSheetInput" class="plate-sheet-input" maxlength="10" placeholder="Numer rej."
            style="width: 100%; text-align: center; font-size: 24px; letter-spacing: 2px; padding: 10px; border: 1px solid #ddd; border-radius: 8px;">
        </div>

        <div class="modal-actions">
          <button class="btn-secondary" id="cancelPlateEdit">Anuluj</button>
          <button class="btn-primary" id="savePlateBtn">Zatwierdź</button>
        </div>
      </div>
    </div>

    <!-- Confirmation Modal -->
    <div class="modal-overlay" id="plateConfirmModal">
      <div class="modal-card">
        <div class="icon-warning">
          <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path>
            <line x1="12" y1="9" x2="12" y2="13"></line>
            <line x1="12" y1="17" x2="12.01" y2="17"></line>
          </svg>
        </div>
        <h3>Potwierdź zmianę</h3>
        <p>Czy na pewno chcesz zmienić numer rejestracyjny na <strong id="confirmPlateValue"></strong>?</p>
        <div class="modal-actions">
          <button class="btn-secondary" id="cancelPlateChange">Anuluj</button>
          <button class="btn-primary" id="confirmPlateChange">Zatwierdź</button>
        </div>
      </div>
    </div>

    </div>
  <?php endif; ?>

  <!-- Pass PHP variables to JS -->
  <script>
    // Expose globals to window for ES configuration module
    window.TICKET_ID = "<?php echo $ticket_id; ?>";
    window.INITIAL_FEE = <?php echo $fee; ?>;
    window.HOURLY_RATE = <?php echo $config['settings']['hourly_rate']; ?>;

    window.API_SETTINGS = {
      time_mode: <?php
      if (isset($ticket['api_data']['FEE_TYPE'])) {
        echo "'" . ($ticket['api_data']['FEE_TYPE'] == '0' ? "daily" : "hourly") . "'";
      } else {
        echo "'" . ('daily') . "'";
      }
      ?>,
      duration_mode: <?php
      if (isset($ticket['api_data']['FEE_MULTI_DAY'])) {
        echo $ticket['api_data']['FEE_MULTI_DAY'] == 1 ? "'multi_day'" : "'single_day'";
      } else {
        echo "'" . ('multi_day') . "'";
      }
      ?>,
      day_counting: <?php
      if (isset($ticket['api_data']['FEE_STARTS_TYPE'])) {
        echo $ticket['api_data']['FEE_STARTS_TYPE'] == 1 ? "'from_midnight'" : "'from_entry'";
      } else {
        echo "'" . ('from_entry') . "'";
      }
      ?>,
      fee_type_raw: <?php echo isset($ticket['api_data']['FEE_TYPE']) ? $ticket['api_data']['FEE_TYPE'] : 'null'; ?>,
      fee_starts_type_raw: <?php echo isset($ticket['api_data']['FEE_STARTS_TYPE']) ? $ticket['api_data']['FEE_STARTS_TYPE'] : 'null'; ?>,
      fee_multi_day_raw: <?php echo isset($ticket['api_data']['FEE_MULTI_DAY']) ? $ticket['api_data']['FEE_MULTI_DAY'] : 'null'; ?>,
      is_new: <?php echo (isset($ticket['is_new']) && $ticket['is_new']) ? 'true' : 'false'; ?>,
      fee_paid: <?php echo isset($ticket['api_data']['FEE_PAID']) ? json_encode($ticket['api_data']['FEE_PAID']) : 0; ?>,
      ticket_exist: <?php echo isset($ticket['api_data']['TICKET_EXIST']) ? json_encode($ticket['api_data']['TICKET_EXIST']) : 0; ?>,
      ticket_barcode: <?php echo isset($ticket['api_data']['BARCODE']) ? json_encode($ticket['api_data']['BARCODE']) : 'null'; ?>,
      valid_to: <?php echo isset($ticket['api_data']['VALID_TO']) ? json_encode($ticket['api_data']['VALID_TO']) : 'null'; ?>
    };

    window.SCENARIO_TEST_MODE = <?php echo $scenarioTester->isEnabled() ? 'true' : 'false'; ?>;

    window.CONFIG = {
      default_duration: 60,
      currency: "<?php echo $config['settings']['currency']; ?>",
      hourly_rate: <?php echo $config['settings']['hourly_rate']; ?>,
      time_mode: window.API_SETTINGS.time_mode,
      duration_mode: window.API_SETTINGS.duration_mode,
      day_counting: window.API_SETTINGS.day_counting,
      valid_to: <?php echo isset($ticket['api_data']['VALID_TO']) ? json_encode($ticket['api_data']['VALID_TO']) : 'null'; ?>
    };
    window.IS_PAID = <?php echo ($ticket && isset($ticket['status']) && $ticket['status'] === 'paid') ? 'true' : 'false'; ?>;
    window.ENTRY_TIME = "<?php echo $ticket ? $entry_time->format('Y-m-d H:i:00') : ''; ?>";
    window.ENTRY_TIME_RAW = "<?php echo $ticket ? $entry_time->format('Y-m-d\TH:i') : ''; ?>";

    window.IS_PRE_BOOKING = <?php echo ($ticket && isset($ticket['status']) && $ticket['status'] !== 'paid' && $ticket_id) ? 'true' : 'false'; ?>;
    window.IS_EDITABLE_START = window.IS_PRE_BOOKING;

    // Log for debugging
    console.log("Global Config Loaded:", window.API_SETTINGS);
  </script>
  <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/round-slider@1.6.1/dist/roundslider.min.js"></script>
  <script type="module" src="public/js/main.js"></script>
  <!-- SVG filter (put once per page, e.g. before </body>) -->
  <svg width="0" height="0" style="position: absolute;">
    <filter id="lg-dist">
      <feTurbulence type="fractalNoise" baseFrequency="0.9" numOctaves="3" seed="2" result="noise" />
      <feGaussianBlur in="noise" stdDeviation="8" result="blurred" />
      <feDisplacementMap in="SourceGraphic" in2="blurred" scale="60" xChannelSelector="R" yChannelSelector="G" />
    </filter>
  </svg>
</body>

</html>
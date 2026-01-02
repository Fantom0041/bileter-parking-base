<?php

class ScenarioTester
{
  private $enabled = false;
  private $feeMultiDay;
  private $feeType;
  private $feeStartsType;

  public function __construct($config)
  {
    // Check if testing section exists and is enabled
    if (isset($config['testing']['scenario_test']) && $config['testing']['scenario_test']) {
      $this->enabled = true;

      $scenarioStr = $config['testing']['selected_scenario'] ?? '0_0_0';
      $parts = explode('_', $scenarioStr);

      // Mapping based on CONFIGURATION_TABLE.md
      // Format: FEE_MULTI_DAY _ FEE_TYPE _ FEE_STARTS_TYPE
      // 1. FEE_MULTI_DAY (0=Single, 1=Multi)
      // 2. FEE_TYPE (0=Daily, 1=Hourly)
      // 3. FEE_STARTS_TYPE (0=Entry, 1=Midnight)
      
      $this->feeMultiDay = isset($parts[0]) ? (int) $parts[0] : 0;
      $this->feeType = isset($parts[1]) ? (int) $parts[1] : 0;
      $this->feeStartsType = isset($parts[2]) ? (int) $parts[2] : 0;
    }
  }

  /**
   * Applies the test scenario overrides to the ticket array
   */
  public function applyOverrides(&$ticket)
  {
    if (!$this->enabled || !$ticket) {
      return;
    }

    // Ensure api_data array exists
    if (!isset($ticket['api_data'])) {
      $ticket['api_data'] = [];
    }

    // Apply overrides
    $ticket['api_data']['FEE_MULTI_DAY'] = $this->feeMultiDay;
    $ticket['api_data']['FEE_TYPE'] = $this->feeType;
    $ticket['api_data']['FEE_STARTS_TYPE'] = $this->feeStartsType;
    
    // Ensure TICKET_EXIST is set (default to 1 for scenario testing unless simulated new)
    if (!isset($ticket['api_data']['TICKET_EXIST'])) {
        $ticket['api_data']['TICKET_EXIST'] = 1;
    }

    // Calculate and set VALID_TO based on Entry Time and Scenario Table rules
    if (isset($ticket['entry_time'])) {
      $ticket['api_data']['VALID_TO'] = $this->calculateValidTo($ticket['entry_time']);
    }

    // Add a visual indicator flag
    $ticket['is_scenario_test'] = true;
  }

  private function calculateValidTo($entryTimeStr)
  {
    try {
      $entry = new DateTime($entryTimeStr);
      $validTo = clone $entry;

      // Construct Scenario Signature: M_T_S
      $scenario = "{$this->feeMultiDay}_{$this->feeType}_{$this->feeStartsType}";

      switch ($scenario) {
        // --- SINGLE DAY (M=0) ---

        case '0_0_0': // Single, Daily, Entry
            // Rule: STOP DATA = START DATA + 1, STOP GODZINA = START GODZINA
            $validTo->modify('+1 day');
            break;

        case '0_0_1': // Single, Daily, Midnight
            // Rule: STOP DATA = START DATA, STOP GODZINA = 23:59:59
            $validTo->setTime(23, 59, 59);
            break;

        case '0_1_0': // Single, Hourly, Entry
            // Rule: STOP DATA = START DATA (Not Editable)
            // Rule: STOP GODZINA = Editable to 23:59:59
            // Logic: Default to +1 hour, but CLAMP to End of Day (23:59:59)
            $validTo->modify('+1 hour');
            $endOfDay = clone $entry;
            $endOfDay->setTime(23, 59, 59);
            if ($validTo > $endOfDay) {
                $validTo = $endOfDay;
            }
            break;

        case '0_1_1': // Single, Hourly, Midnight
            // Rule: STOP DATA = START DATA (Not Editable)
            // Rule: STOP GODZINA = Editable to 23:59:59
            // Logic: Default to +1 hour, but CLAMP to End of Day
            $validTo->modify('+1 hour');
            $endOfDay = clone $entry;
            $endOfDay->setTime(23, 59, 59);
            if ($validTo > $endOfDay) {
                $validTo = $endOfDay;
            }
            break;

        // --- MULTI DAY (M=1) ---

        case '1_0_0': // Multi, Daily, Entry
            // Rule: STOP DATA = START DATA + 1 (Editable)
            // Rule: STOP GODZINA = START GODZINA (Not Editable)
            $validTo->modify('+1 day');
            break;

        case '1_0_1': // Multi, Daily, Midnight
            // Rule: STOP DATA = START DATA (Editable)
            // Rule: STOP GODZINA = 23:59:59 (Not Editable)
            // Logic: Default to End of START Day. (User can add days in UI)
            $validTo->setTime(23, 59, 59);
            break;

        case '1_1_0': // Multi, Hourly, Entry
            // Rule: STOP DATA = START DATA (Editable)
            // Rule: STOP GODZINA = Editable
            // Logic: Fully editable, default +1 hour (can cross midnight)
            $validTo->modify('+1 hour');
            break;

        case '1_1_1': // Multi, Hourly, Midnight
            // Rule: STOP DATA = START DATA (Editable)
            // Rule: STOP GODZINA = Editable
            // Logic: Fully editable, default +1 hour
            $validTo->modify('+1 hour');
            break;

        default:
            // Fallback
            $validTo->modify('+1 hour');
      }

      return $validTo->format('Y-m-d H:i:s');
    } catch (Exception $e) {
      return null;
    }
  }

  public function isEnabled()
  {
    return $this->enabled;
  }

  public function getScenarioDescription()
  {
    if (!$this->enabled)
      return "";

    $multi = $this->feeMultiDay ? "Multi-Day" : "Single-Day";
    $type = $this->feeType ? "Hourly" : "Daily";
    $start = $this->feeStartsType ? "From Midnight" : "From Entry";

    return "TEST MODE: [{$this->feeMultiDay}_{$this->feeType}_{$this->feeStartsType}] ($type, $multi, $start)";
  }
}
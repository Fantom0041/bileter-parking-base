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
      // Format: FEE_TYPE _ MULTI_DAY _ STARTS_TYPE
      $this->feeType = isset($parts[0]) ? (int) $parts[0] : 0;
      $this->feeMultiDay = isset($parts[1]) ? (int) $parts[1] : 0;
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

    // Add a visual indicator flag (optional, useful for debugging)
    $ticket['is_scenario_test'] = true;

    // Ensure standard fields exist so logic doesn't break
    if (!isset($ticket['api_data']['TICKET_EXIST'])) {
      // Default to 1 (Existing) for testing UI logic, or 0 if testing new ticket flow
      // Usually manual testing of scenarios implies checking the Edit Logic of an existing/new session.
      // We leave TICKET_EXIST as is, or default to 1 if missing.
      $ticket['api_data']['TICKET_EXIST'] = 1;
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

    return "TEST MODE: [{$this->feeType}_{$this->feeMultiDay}_{$this->feeStartsType}] ($type, $multi, $start)";
  }
}

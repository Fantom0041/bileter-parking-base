<?php

declare(strict_types=1);

namespace Tests\Acceptance;

use Tests\Support\AcceptanceTester;

class ScenariosCest
{
  private static $mockPid;
  private static $phpServerPid;
  private static $port = 8022; // Port for the PHP Test Server (matches Acceptance.suite.yml)
  private static $mockPort = 8099; // Port for the Mock API Server

  // Distinct config file for tests to avoid touching prod config.ini
  private static $configFile = 'config_e2e_test.ini';

  public function _before(AcceptanceTester $I): void
  {
    // 1. Start Mock Server
    // We run this in background
    $cmdMock = "php tests/MockServer.php " . self::$mockPort . " > tests/_output/mock_output.log 2>&1 & echo $!";
    self::$mockPid = trim(shell_exec($cmdMock));

    // 2. Start PHP Web Server with custom config environment variable
    // This ensures index.php uses our temp config instead of the production config.ini
    $cmdPhp = "PARK_CONFIG_FILE=" . self::$configFile . " php -S 127.0.0.1:" . self::$port . " > tests/_output/php_server.log 2>&1 & echo $!";
    self::$phpServerPid = trim(shell_exec($cmdPhp));

    // 3. Ensure a default test config exists before server accepts requests
    // (Optional, but good practice to prevent initial errors if checks run early)
    $this->applyScenarioConfig('0_0_0');

    // Give servers a moment to boot
    sleep(2);
  }

  public function _after(AcceptanceTester $I): void
  {
    // 1. Kill servers
    if (self::$mockPid)
      shell_exec("kill " . self::$mockPid);
    if (self::$phpServerPid)
      shell_exec("kill " . self::$phpServerPid);

    // 2. Clean up the temporary config file
    if (file_exists(self::$configFile)) {
      unlink(self::$configFile);
    }
  }

  /**
   * Helper to write the specific configuration file for a scenario.
   * This writes to 'config_e2e_test.ini', NOT 'config.ini'.
   */
  private function applyScenarioConfig(string $scenarioString)
  {
    $configContent = '
[settings]
station_id = "TEST_STATION"
currency = "PLN"
hourly_rate = 5.00
free_minutes = 15
PARK_CONFIG_FILE = config_e2e_test.ini

[api]
; Point to the local Mock Server
api_url = "tcp://127.0.0.1:' . self::$mockPort . '"
api_login = "test"
api_password = "pass"
device_id = 1
device_ip = "127.0.0.1"
entity_id = 1

[testing]
scenario_test = true
selected_scenario = "' . $scenarioString . '"
';
    file_put_contents(self::$configFile, $configContent);
  }

  // --- E2E UI SCENARIO TESTS ---

  /**
   * Scenario 0_0_0: Daily, Single Day, From Entry.
   * Logic: Read-only mode.
   * UI: Spinner hidden, Collapsed Exit view visible.
   */
  public function testScenario_0_0_0_UI(AcceptanceTester $I)
  {
    $this->applyScenarioConfig('0_0_0');
    $I->amOnPage('/?ticket_id=UI_TEST&_t=' . time());

    // Verify Test Mode Banner (injected by index.php)
    $I->waitForText('TEST MODE: [0_0_0]', 5);

    // Assert: Spinner should be hidden (display: none)
    // Note: Codeception's seeElement checks presence in DOM, not visibility.
    // We use executeJS to check computed style.
    // $I->seeElement('#spinnerContainer'); // Removed: Element exists but is hidden, seeElement checks presence but we care about visibility logic below
    $display = $I->executeJS("return window.getComputedStyle(document.getElementById('spinnerContainer')).display");
    $I->assertEquals('none', $display, 'Spinner container should be hidden in 0_0_0');

    // Assert: Collapsed Exit view should be visible
    $collapsedDisplay = $I->executeJS("return window.getComputedStyle(document.getElementById('exitCollapsed')).display");
    $I->assertNotEquals('none', $collapsedDisplay, 'Collapsed Exit view should be visible');

    // Assert: Expanded Exit view should be hidden
    $expandedDisplay = $I->executeJS("return window.getComputedStyle(document.getElementById('exitExpanded')).display");
    $I->assertEquals('none', $expandedDisplay, 'Expanded Exit view should be hidden');
  }

  /**
   * Scenario 0_0_1: Daily, Single Day, From Midnight.
   * Logic: Read-only mode (ends at 23:59:59).
   * UI: Spinner hidden, Collapsed Exit view visible.
   */
  public function testScenario_0_0_1_UI(AcceptanceTester $I)
  {
    $this->applyScenarioConfig('0_0_1');
    $I->amOnPage('/?ticket_id=UI_TEST&_t=' . time());
    $I->waitForText('TEST MODE: [0_0_1]', 5);

    // Assert: Spinner hidden
    $display = $I->executeJS("return window.getComputedStyle(document.getElementById('spinnerContainer')).display");
    $I->assertEquals('none', $display);

    // Assert: Collapsed Exit view visible
    $collapsedDisplay = $I->executeJS("return window.getComputedStyle(document.getElementById('exitCollapsed')).display");
    $I->assertNotEquals('none', $collapsedDisplay);
  }

  /**
   * Scenario 1_0_0: Daily, Multi Day, From Entry.
   * Logic: Days editable, Time fixed.
   * UI: Spinner visible, Expanded Exit view visible. Date active, Time disabled.
   */
  public function testScenario_1_0_0_UI(AcceptanceTester $I)
  {
    $this->applyScenarioConfig('1_0_0');
    $I->amOnPage('/?ticket_id=UI_TEST&_t=' . time());
    $I->waitForText('TEST MODE: [1_0_0]', 5);

    // Wait for JavaScript to initialize UI and apply styles
    $I->waitForElement('#exitTimeBtn[style*="opacity"]', 5);

    // Assert: Spinner visible
    $display = $I->executeJS("return window.getComputedStyle(document.getElementById('spinnerContainer')).display");
    $I->assertNotEquals('none', $display, 'Spinner should be visible');

    // Assert: Expanded Exit view visible
    $expandedDisplay = $I->executeJS("return window.getComputedStyle(document.getElementById('exitExpanded')).display");
    $I->assertNotEquals('none', $expandedDisplay, 'Expanded view should be visible');

    // Assert: Buttons State
    // Date Button should be active (class) and fully opaque
    $I->seeElement('#exitDateBtn.active');
    $dateOpacity = $I->executeJS("return window.getComputedStyle(document.getElementById('exitDateBtn')).opacity");
    $I->assertEquals('1', $dateOpacity, 'Date button should be enabled');

    // Time Button should NOT be active and should be faded (disabled)
    $I->dontSeeElement('#exitTimeBtn.active');
    $timeOpacity = $I->executeJS("return document.getElementById('exitTimeBtn').style.opacity");
    $I->assertEquals('0.5', $timeOpacity, 'Time button should be faded/disabled (inline style)');

    // Assert: Pointer events none for time button
    $timePointer = $I->executeJS("return document.getElementById('exitTimeBtn').style.pointerEvents");
    $I->assertEquals('none', $timePointer, 'Time button should not be clickable (inline style)');
  }

  /**
   * Scenario 0_1_0: Hourly, Single Day, From Entry.
   * Logic: Minutes editable, Date fixed (single day).
   * UI: Spinner visible. Time active, Date disabled.
   */
  public function testScenario_0_1_0_UI(AcceptanceTester $I)
  {
    $this->applyScenarioConfig('0_1_0');
    $I->amOnPage('/?ticket_id=UI_TEST&_t=' . time());
    $I->waitForText('TEST MODE: [0_1_0]', 5);

    // Assert: Spinner visible
    $display = $I->executeJS("return window.getComputedStyle(document.getElementById('spinnerContainer')).display");
    $I->assertNotEquals('none', $display);

    // Assert: Buttons State
    // Time Button should be active
    $I->seeElement('#exitTimeBtn.active');

    // Date Button should be disabled (Single Day constraint)
    $I->dontSeeElement('#exitDateBtn.active');
    $dateOpacity = $I->executeJS("return document.getElementById('exitDateBtn').style.opacity");
    $I->assertEquals('0.5', $dateOpacity, 'Date button should be disabled (inline style 0.5) for Single Day Hourly');
  }

  /**
   * Scenario 1_1_0: Hourly, Multi Day, From Entry.
   * Logic: Fully editable.
   * UI: Spinner visible. Both buttons enabled.
   */
  public function testScenario_1_1_0_UI(AcceptanceTester $I)
  {
    $this->applyScenarioConfig('1_1_0');
    $I->amOnPage('/?ticket_id=UI_TEST&_t=' . time());
    $I->waitForText('TEST MODE: [1_1_0]', 5);

    // Assert: Spinner visible
    $display = $I->executeJS("return window.getComputedStyle(document.getElementById('spinnerContainer')).display");
    $I->assertNotEquals('none', $display);

    // Assert: Both buttons enabled (opacity 1)
    $dateOpacity = $I->executeJS("return window.getComputedStyle(document.getElementById('exitDateBtn')).opacity");
    $timeOpacity = $I->executeJS("return window.getComputedStyle(document.getElementById('exitTimeBtn')).opacity");

    $I->assertEquals('1', $dateOpacity, 'Date button should be enabled');
    $I->assertEquals('1', $timeOpacity, 'Time button should be enabled');

    // Assert: Interaction
    // Usually starts with Days (Date) active, or Time depending on logic.
    // Let's ensure we can click both.

    // Click Time
    $I->click('#exitTimeBtn');
    $I->seeElement('#exitTimeBtn.active');
    $I->dontSeeElement('#exitDateBtn.active');

    // Click Date
    $I->click('#exitDateBtn');
    $I->seeElement('#exitDateBtn.active');
    $I->dontSeeElement('#exitTimeBtn.active');
  }
}

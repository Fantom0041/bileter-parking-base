<?php

declare(strict_types=1);

namespace Tests\Acceptance;

use Tests\Support\AcceptanceTester;

class ScenariosCest
{
  // Note: Ensure the ports match your configuration (8022 for PHP server)

  private static $mockPid;
  private static $phpServerPid;
  private static $port = 8022;
  private static $configFile = 'config_test.ini';

  public function _before(AcceptanceTester $I): void
  {
    // Start Mock & PHP Server logic (same as before)
    $cmd = "php tests/MockServer.php 8099 > tests/mock_output.log 2>&1 & echo $!";
    self::$mockPid = trim(shell_exec($cmd));

    // Start PHP Server with custom config env var
    $cmdPhp = "PARK_CONFIG_FILE=" . self::$configFile . " php -S 127.0.0.1:" . self::$port . " > tests/php_server.log 2>&1 & echo $!";
    self::$phpServerPid = trim(shell_exec($cmdPhp));

    sleep(2); // Wait for boot
  }

  public function _after(AcceptanceTester $I): void
  {
    // Clean up test config
    if (file_exists(self::$configFile)) {
      unlink(self::$configFile);
    }

    if (self::$mockPid)
      shell_exec("kill " . self::$mockPid);
    if (self::$phpServerPid)
      shell_exec("kill " . self::$phpServerPid);
  }

  private function applyScenarioConfig(string $scenarioString)
  {
    // (Use the same config writing logic as provided previously)
    $configContent = '
[settings]
station_id = "TEST_STATION"
currency = "PLN"
hourly_rate = 5.00
free_minutes = 15



[api]
api_url = "tcp://127.0.0.1:8099"
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

  // --- TRUE E2E UI TESTS ---

  // 1. Daily, Single, From Entry -> Spinner HIDDEN
  public function testScenario_0_0_0_UI(AcceptanceTester $I)
  {
    $this->applyScenarioConfig('0_0_0');
    $I->amOnPage('/?ticket_id=UI_TEST');

    // Wait for JS to initialize
    $I->waitForText('TEST MODE: [0_0_0]', 5);

    // Assert UI State (handled by script.js)
    $I->dontSeeElement('#spinnerContainer'); // Should be hidden
    $I->seeElement('#exitCollapsed'); // Should show collapsed view
    $I->dontSeeElement('#exitExpanded'); // Should NOT show expanded view
  }

  // 2. Daily, Single, Midnight -> Spinner HIDDEN
  public function testScenario_0_0_1_UI(AcceptanceTester $I)
  {
    $this->applyScenarioConfig('0_0_1');
    $I->amOnPage('/?ticket_id=UI_TEST');
    $I->waitForText('TEST MODE: [0_0_1]', 5);

    $I->dontSeeElement('#spinnerContainer');
    $I->seeElement('#exitCollapsed');
  }

  // 3. Daily, Multi -> Spinner VISIBLE, Days Mode
  public function testScenario_0_1_0_UI(AcceptanceTester $I)
  {
    $this->applyScenarioConfig('0_1_0');
    $I->amOnPage('/?ticket_id=UI_TEST');
    $I->waitForText('TEST MODE: [0_1_0]', 5);

    // Spinner should be visible
    $I->seeElement('#spinnerContainer');

    // Buttons: Date active, Time disabled
    $I->seeElement('#exitDateBtn.active');
    $I->dontSeeElement('#exitTimeBtn.active');

    // Check opacity via JS execution if needed, or rely on class check
    // Wait for opacity transition
    $I->waitForElement('#exitTimeBtn[style*="opacity: 0.5"]', 5);

    $opacity = $I->executeJS("return window.getComputedStyle(document.getElementById('exitTimeBtn')).opacity");
    $I->assertLessThan(1.0, $opacity, 'Time button should be faded out (disabled)');
  }

  // 5. Hourly, Single -> Spinner VISIBLE, Minutes Mode
  public function testScenario_1_0_0_UI(AcceptanceTester $I)
  {
    $this->applyScenarioConfig('1_0_0');
    $I->amOnPage('/?ticket_id=UI_TEST');
    $I->waitForText('TEST MODE: [1_0_0]', 5);

    $I->seeElement('#spinnerContainer');

    // Buttons: Time active, Date disabled
    $I->seeElement('#exitTimeBtn.active');
    $I->dontSeeElement('#exitDateBtn.active');

    // Wait for opacity transition
    $I->waitForElement('#exitDateBtn[style*="opacity: 0.5"]', 5);

    $opacity = $I->executeJS("return window.getComputedStyle(document.getElementById('exitDateBtn')).opacity");
    $I->assertLessThan(1.0, $opacity, 'Date button should be faded out (disabled)');
  }

  // 7. Hourly, Multi -> Fully Editable
  public function testScenario_1_1_0_UI(AcceptanceTester $I)
  {
    $this->applyScenarioConfig('1_1_0');
    $I->amOnPage('/?ticket_id=UI_TEST');
    $I->waitForText('TEST MODE: [1_1_0]', 5);

    $I->seeElement('#spinnerContainer');

    // Both accessible (opacity 1)
    $dateOpacity = $I->executeJS("return window.getComputedStyle(document.getElementById('exitDateBtn')).opacity");
    $timeOpacity = $I->executeJS("return window.getComputedStyle(document.getElementById('exitTimeBtn')).opacity");

    $I->assertEquals('1', $dateOpacity);
    $I->assertEquals('1', $timeOpacity);

    // Default might be days or minutes, usually days for multi-day
    $I->seeElement('#exitDateBtn.active');

    // Interact! Click time button
    $I->click('#exitTimeBtn');
    $I->seeElement('#exitTimeBtn.active');
    $I->dontSeeElement('#exitDateBtn.active');
  }
}

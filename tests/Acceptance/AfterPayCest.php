<?php

declare(strict_types=1);

namespace Tests\Acceptance;

use Tests\Support\AcceptanceTester;

class AfterPayCest
{
  private static $mockPid;
  private static $phpServerPid;
  private static $port = 8022;
  private static $mockPort = 8100;
  private static $configFile = 'config_afterpay_test.ini';
  private static $originalConfigExists = false;

  public function _before(AcceptanceTester $I): void
  {
    // Clean up any stray processes from previous crashes
    shell_exec("pkill -f 'php tests/MockServer.php' || true");
    shell_exec("pkill -f 'php -S 127.0.0.1:" . self::$port . "' || true");
    sleep(1);

    // 1. Start Mock Server
    $cmdMock = "php tests/MockServer.php " . self::$mockPort . " > tests/_output/afterpay_mock.log 2>&1 & echo $!";
    self::$mockPid = trim(shell_exec($cmdMock));

    // 2. Start PHP Web Server
    $cmdPhp = "PARK_CONFIG_FILE=" . self::$configFile . " php -S 127.0.0.1:" . self::$port . " > tests/_output/afterpay_php.log 2>&1 & echo $!";
    self::$phpServerPid = trim(shell_exec($cmdPhp));

    // 3. Reset Mock State if exists
    $stateFile = 'tests/_output/mock_state.json';
    if (file_exists($stateFile)) {
      unlink($stateFile);
    }

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

    // 2. Clean up temporary config file
    if (file_exists(self::$configFile)) {
      unlink(self::$configFile);
    }
  }

  public function _failed(AcceptanceTester $I)
  {
    // Capture logs on failure for debugging
    try {
      $error = $I->executeJS('return window.lastError;');
      file_put_contents('tests/_output/console_fail.log', "JS Error: $error\n");
    } catch (\Exception $e) {
      // Ignore if JS execution fails
    }
    file_put_contents('tests/_output/source_fail.html', $I->grabPageSource());
  }

  private function applyScenario(string $scenario)
  {
    $config = '
[settings]
station_id = "TEST"
currency = "PLN"
hourly_rate = 5.00
free_minutes = 15
PARK_CONFIG_FILE = ' . self::$configFile . '

[api]
api_url = "tcp://127.0.0.1:' . self::$mockPort . '"
api_login = "test"
api_password = "pass"
api_pin = "1234"
device_id = 1
device_ip = "127.0.0.1"
entity_id = 1

[testing]
scenario_test = true
selected_scenario = "' . $scenario . '"
';
    file_put_contents(self::$configFile, $config);
  }

  /**
   * Scenario 0_0_0 (Single, Daily, Entry)
   * Rule: Fixed time (Start + 24h).
   * Fee: Daily Rate (50.00).
   */
  public function testAfterPayScenario_0_0_0(AcceptanceTester $I)
  {
    $this->applyScenario('0_0_0');
    $I->amOnPage('/?ticket_id=PAY_000&_t=' . time());

    // Expect 50.00 PLN for Daily ticket
    $I->waitForText('Zapłać 50.00 PLN', 10, '#payButton');
    $I->click('#payButton');

    $I->waitForElementVisible('#successOverlay', 15);

    // Verify "Opłacone do" is Tomorrow
    $tomorrow = date('Y-m-d', strtotime('+1 day - 1 hour')); // Loose check for date part
    $val = $I->executeJS("return document.getElementById('paymentInfoExitValue') ? document.getElementById('paymentInfoExitValue').textContent : null;");
    $I->assertStringContainsString($tomorrow, $val);

    // Close overlay
    $I->click('#successOverlay button.btn-secondary');

    // Expect Paid state (0.00)
    $I->waitForText('Do zapłaty: 0,00 PLN', 10, '#payButton');
    $I->see($tomorrow, '#paymentInfoExitValue');
  }

  /**
   * Scenario 0_0_1 (Single, Daily, Midnight)
   * Rule: Fixed time (Today 23:59:59).
   * Fee: Daily Rate (50.00).
   */
  public function testAfterPayScenario_0_0_1(AcceptanceTester $I)
  {
    $this->applyScenario('0_0_1');
    $I->amOnPage('/?ticket_id=PAY_001&_t=' . time());

    // Expect 50.00 PLN
    $I->waitForText('Zapłać 50.00 PLN', 10, '#payButton');
    $I->click('#payButton');

    $I->waitForElementVisible('#successOverlay', 15);

    $val = $I->executeJS("return document.getElementById('paymentInfoExitValue') ? document.getElementById('paymentInfoExitValue').textContent : null;");
    $I->assertStringContainsString('23:59', $val);

    $I->click('#successOverlay button.btn-secondary');
    $I->waitForText('Do zapłaty: 0,00 PLN', 10);
  }

  /**
   * Scenario 1_0_0 (Multi, Daily, Entry)
   * Rule: Default +1 Day.
   * Fee: Daily Rate (50.00).
   */
  public function testAfterPayScenario_1_0_0(AcceptanceTester $I)
  {
    $this->applyScenario('1_0_0');
    $I->amOnPage('/?ticket_id=PAY_100&_t=' . time());

    // Expect 50.00 PLN
    $I->waitForText('Zapłać 50.00 PLN', 10, '#payButton');
    $I->click('#payButton');

    $I->waitForElementVisible('#successOverlay', 15);

    // ScenarioTester logic for 1_0_0 is `modify('+1 day')`.
    $tomorrow = date('Y-m-d', strtotime('+1 day'));
    $val = $I->executeJS("return document.getElementById('paymentInfoExitValue') ? document.getElementById('paymentInfoExitValue').textContent : null;");
    $I->assertStringContainsString($tomorrow, $val);

    $I->click('#successOverlay button.btn-secondary');
    $I->waitForText('Do zapłaty: 0,00 PLN', 10);
  }

  /**
   * Scenario 1_0_1 (Multi, Daily, Midnight)
   * Rule: Default End of Day (23:59).
   * Fee: Daily Rate (50.00).
   */
  public function testAfterPayScenario_1_0_1(AcceptanceTester $I)
  {
    $this->applyScenario('1_0_1');
    $I->amOnPage('/?ticket_id=PAY_101&_t=' . time());

    // Expect 50.00 PLN
    $I->waitForText('Zapłać 50.00 PLN', 10, '#payButton');
    $I->click('#payButton');

    $I->waitForElementVisible('#successOverlay', 15);

    $val = $I->executeJS("return document.getElementById('paymentInfoExitValue') ? document.getElementById('paymentInfoExitValue').textContent : null;");
    $I->assertStringContainsString('23:59', $val);

    $I->click('#successOverlay button.btn-secondary');
    $I->waitForText('Do zapłaty: 0,00 PLN', 10);
  }

  /**
   * Scenario 0_1_0 (Single, Hourly, Entry)
   * Rule: Default +1 Hour.
   * Fee: 1 Hour * 5.00 = 5.00 PLN.
   */
  public function testAfterPayScenario_0_1_0(AcceptanceTester $I)
  {
    $this->applyScenario('0_1_0');
    $I->amOnPage('/?ticket_id=PAY_010&_t=' . time());

    // Expect 5.00 PLN
    $I->waitForText('Zapłać 5.00 PLN', 10, '#payButton');
    $I->click('#payButton');

    $I->waitForElementVisible('#successOverlay', 15);

    $I->click('#successOverlay button.btn-secondary');
    $I->waitForText('Do zapłaty: 0,00 PLN', 10);
  }

  /**
   * Scenario 0_1_1 (Single, Hourly, Midnight)
   * Rule: Default +1 Hour (clamped to 23:59).
   * Fee: 1 Hour * 5.00 = 5.00 PLN.
   */
  public function testAfterPayScenario_0_1_1(AcceptanceTester $I)
  {
    $this->applyScenario('0_1_1');
    $I->amOnPage('/?ticket_id=PAY_011&_t=' . time());

    // Expect 5.00 PLN
    $I->waitForText('Zapłać 5.00 PLN', 10, '#payButton');
    $I->click('#payButton');

    $I->waitForElementVisible('#successOverlay', 10);

    $I->click('#successOverlay button.btn-secondary');
    $I->waitForText('Do zapłaty: 0,00 PLN', 10);
  }

  /**
   * Scenario 1_1_0 (Multi, Hourly, Entry)
   * Rule: Default +30 Minutes.
   * Fee: 30 mins -> 1 Hour (Ceil) -> 5.00 PLN.
   */
  public function testAfterPayScenario_1_1_0(AcceptanceTester $I)
  {
    $this->applyScenario('1_1_0');
    $I->amOnPage('/?ticket_id=PAY_110&_t=' . time());

    // Expect 5.00 PLN
    $I->waitForText('Zapłać 5.00 PLN', 10, '#payButton');
    $I->click('#payButton');

    $I->waitForElementVisible('#successOverlay', 10);

    $I->click('#successOverlay button.btn-secondary');
    $I->waitForText('Do zapłaty: 0,00 PLN', 10);
  }

  /**
   * Scenario 1_1_1 (Multi, Hourly, Midnight)
   * Rule: Default +30 Minutes.
   * Fee: 30 mins -> 1 Hour (Ceil) -> 5.00 PLN.
   */
  public function testAfterPayScenario_1_1_1(AcceptanceTester $I)
  {
    $this->applyScenario('1_1_1');
    $I->amOnPage('/?ticket_id=PAY_111&_t=' . time());

    // Expect 5.00 PLN
    $I->waitForText('Zapłać 5.00 PLN', 10, '#payButton');
    $I->click('#payButton');

    $I->waitForElementVisible('#successOverlay', 10);

    $I->click('#successOverlay button.btn-secondary');
    $I->waitForText('Do zapłaty: 0,00 PLN', 10);
  }
}
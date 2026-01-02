<?php

declare(strict_types=1);

namespace Tests\Acceptance;

use Tests\Support\AcceptanceTester;

class AfterPayCest
{
  private static $mockPid;
  private static $phpServerPid;
  private static $port = 8022;
  private static $originalConfig;

  public function _before(AcceptanceTester $I): void
  {
    // 1. Start Servers
    $cmd = "php tests/MockServer.php 8099 > tests/mock_output.log 2>&1 & echo $!";
    self::$mockPid = trim(shell_exec($cmd));

    $cmdPhp = "php -S 127.0.0.1:" . self::$port . " > tests/php_server.log 2>&1 & echo $!";
    self::$phpServerPid = trim(shell_exec($cmdPhp));

    sleep(2);

    if (file_exists('config.ini')) {
      self::$originalConfig = file_get_contents('config.ini');
    }

    // Reset Mock State
    $stateFile = 'tests/_output/mock_state.json';
    if (file_exists($stateFile)) {
      unlink($stateFile);
    }
  }

  public function _after(AcceptanceTester $I): void
  {
    if (self::$originalConfig !== null) {
      file_put_contents('config.ini', self::$originalConfig);
    }
    if (self::$mockPid)
      shell_exec("kill " . self::$mockPid);
    if (self::$phpServerPid)
      shell_exec("kill " . self::$phpServerPid);
  }

  public function _failed(AcceptanceTester $I)
  {
    try {
      $error = $I->executeJS('return window.lastError;');
      $alert = $I->executeJS('return window.lastAlert;');
      file_put_contents('tests/_output/console_fail.log', "JS Error: $error\nAlert: $alert");
    } catch (\Exception $e) {
      file_put_contents('tests/_output/console_fail.log', "Error getting logs: " . $e->getMessage());
    }
    file_put_contents('tests/_output/source_fail.html', $I->grabPageSource());
  }

  private function applyScenario(string $scenario)
  {
    // Parse Scenario ID: type_multiday_starts
    // e.g. 0_0_0
    $parts = explode('_', $scenario);
    $type = $parts[0] ?? '0';
    $multi = $parts[1] ?? '0';
    $starts = $parts[2] ?? '0';

    $timeMode = ($type === '0') ? 'daily' : 'hourly';
    $durationMode = ($multi === '1') ? 'multi_day' : 'single_day';
    $dayCounting = ($starts === '1') ? 'from_midnight' : 'from_entry';

    $config = '
[settings]
station_id = "TEST"
currency = "PLN"
hourly_rate = 5.00
free_minutes = 15
[parking_modes]
time_mode = "' . $timeMode . '"
duration_mode = "' . $durationMode . '"
day_counting = "' . $dayCounting . '"
[api]
api_url = "tcp://127.0.0.1:8099"
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
    file_put_contents('config.ini', $config);
  }

  /**
   * Scenario 0_0_0 (Daily/Single/Entry)
   * Expected:
   * 1. Pay Button active (5.00 PLN)
   * 2. After Pay -> Reload
   * 3. Pay Button Disabled "Do zapłaty: 0.00 PLN"
   * 4. "Opłacone do" = Entry + 24h (Fixed)
   */
  public function testAfterPay_0_0_0(AcceptanceTester $I)
  {
    $this->applyScenario('0_0_0');
    $I->amOnPage('/?ticket_id=PAY_000');

    // Inject Debug Hooks
    $I->executeJS("window.lastError = ''; window.lastAlert = ''; 
      var oldErr = console.error; console.error = function(m) { window.lastError += m + '\\n'; oldErr.apply(console, arguments); };
      window.alert = function(m) { window.lastAlert += m + '\\n'; };
    ");

    $I->waitForText('TEST MODE: [0_0_0]', 5);

    // 1. Initial State
    $I->see('Zapłać 5.00 PLN', '#payButton');

    // 2. Perform Payment
    $I->click('#payButton');

    // 3. Wait for Success Overlay
    $I->waitForElementVisible('#successOverlay', 10);
    $I->see('Płatność zakończona', '#successOverlay h2');

    // 4. Click Close (Triggers Reload)
    $I->click('#successOverlay button.btn-secondary');

    // 5. Wait for Reload and Verify "Paid" State
    $I->waitForText('Do zapłaty: 0,00 PLN', 10, '#payButton');

    // Verify Button is glass style (disabled)
    $class = $I->grabAttributeFrom('#payButton', 'class');
    $I->assertStringContainsString('btn-glass', $class);
    $I->seeElement('#payButton[disabled]');

    // Verify "Opłacone do"
    // For 0_0_0, ValidTo should be Entry + 1 Day.
    // Mock Server defaults entry to "-1 hour". So +1 day = Tomorrow - 1 hour.
    $tomorrow = date('Y-m-d', strtotime('+1 day'));
    $I->see($tomorrow, '#paymentInfoExitValue');
  }

  /**
   * Scenario 1_0_0 (Hourly/Single/Entry)
   * Expected:
   * 1. Spinner Visible. Select time.
   * 2. Pay.
   * 3. Reload -> "Opłacone do" matches what we selected.
   */
  public function testAfterPay_1_0_0(AcceptanceTester $I)
  {
    $this->applyScenario('1_0_0');
    $I->amOnPage('/?ticket_id=PAY_100');
    $I->waitForText('TEST MODE: [1_0_0]', 5);

    // 1. Interact with Spinner (add minutes)
    // Since we can't easily drag via WebDriver, we rely on the default initial fee (1 hour = 5.00)
    // or click the "+" buttons if implemented. 
    // For E2E simplicity, we pay the default suggested amount (5.00 for 1h from entry).

    $I->click('#payButton');
    $I->waitForElementVisible('#successOverlay', 10);
    $I->click('#successOverlay button.btn-secondary');

    // 2. Verify Post-Pay
    $I->waitForText('Do zapłaty: 0,00 PLN', 10);

    // Verify Valid To is roughly Now + remaining of that 1h (since entry was -1h, valid to is ~Now)
    // Wait, MockServer default entry is -1h. 
    // Logic: if duration > free_mins (15), fee is calculated. 
    // 60 min duration. 5.00 PLN.
    // Valid To = Entry + 60m = Now.
    // So UI should show time close to now.
    $nowDate = date('Y-m-d');
    $I->see($nowDate, '#paymentInfoExitValue');
  }

  /**
   * Scenario 0_1_0 (Daily/Multi/Entry)
   * Expected: Valid To increments by full days.
   */
  public function testAfterPay_0_1_0(AcceptanceTester $I)
  {
    $this->applyScenario('0_1_0');
    $I->amOnPage('/?ticket_id=PAY_010');
    $I->waitForText('TEST MODE: [0_1_0]', 5);

    // In this mode, spinner defaults to +0 days (just paying current debt if any).
    // Mock default fee is 5.00. 

    $I->click('#payButton');
    $I->waitForElementVisible('#successOverlay', 10);
    $I->click('#successOverlay button.btn-secondary');

    $I->waitForText('Do zapłaty: 0,00 PLN', 10);

    // Since we didn't add days, we just paid the outstanding fee.
    // Valid To should be Entry + 1 Day (min charge for daily).
    $tomorrow = date('Y-m-d', strtotime('+1 day'));
    $I->see($tomorrow, '#paymentInfoExitValue');
  }
}

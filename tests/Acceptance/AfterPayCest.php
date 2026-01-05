<?php

declare(strict_types=1);

namespace Tests\Acceptance;

use Tests\Support\AcceptanceTester;

class AfterPayCest
{
  private static $originalConfig;

  public function _before(AcceptanceTester $I): void
  {
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
    $parts = explode('_', $scenario);
    $type = $parts[0] ?? '0';
    $multi = $parts[1] ?? '0';
    $starts = $parts[2] ?? '0';

    $config = '
[settings]
station_id = "TEST"
currency = "PLN"
hourly_rate = 5.00
free_minutes = 15

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
   */
  public function testAfterPayScenario_0_0_0(AcceptanceTester $I)
  {
    $this->applyScenario('0_0_0');
    $I->amOnPage('/?ticket_id=PAY_000');
    $I->waitForText('TEST MODE: [0_0_0]', 5);

    $I->see('Zapłać 5.00 PLN', '#payButton');
    $I->click('#payButton');

    $I->waitForElementVisible('#successOverlay', 10);

    $tomorrow = date('Y-m-d', strtotime('+1 day - 1 hour'));
    $val = $I->executeJS("return document.getElementById('paymentInfoExitValue') ? document.getElementById('paymentInfoExitValue').textContent : null;");
    if ($val === null)
      $I->fail("paymentInfoExitValue not found in 0_0_0");

    $I->assertStringContainsString($tomorrow, $val);

    $I->click('#successOverlay button.btn-secondary');
    $I->waitForText('Do zapłaty: 0,00 PLN', 10, '#payButton');
    $I->see($tomorrow, '#paymentInfoExitValue');
  }

  /**
   * Scenario 0_0_1 (Daily/Single/Midnight)
   */
  public function testAfterPayScenario_0_0_1(AcceptanceTester $I)
  {
    $this->applyScenario('0_0_1');
    $I->amOnPage('/?ticket_id=PAY_001');
    $I->waitForText('TEST MODE: [0_0_1]', 5);

    $I->click('#payButton');
    $I->waitForElementVisible('#successOverlay', 10);

    $val = $I->executeJS("return document.getElementById('paymentInfoExitValue') ? document.getElementById('paymentInfoExitValue').textContent : null;");
    if ($val === null)
      $I->fail("paymentInfoExitValue not found in 0_0_1");

    $I->assertStringContainsString('23:59', $val);

    $I->click('#successOverlay button.btn-secondary');
    $I->waitForText('Do zapłaty: 0,00 PLN', 10);
    $I->see('23:59', '#paymentInfoExitValue');
  }

  /**
   * Scenario 1_0_0 (Hourly/Single/Entry)
   */
  public function testAfterPayScenario_1_0_0(AcceptanceTester $I)
  {
    $this->applyScenario('1_0_0');
    $I->amOnPage('/?ticket_id=PAY_100');
    $I->waitForText('TEST MODE: [1_0_0]', 5);

    $I->click('#payButton');
    $I->waitForElementVisible('#successOverlay', 10);

    $nowDate = date('Y-m-d');
    $val = $I->executeJS("return document.getElementById('paymentInfoExitValue') ? document.getElementById('paymentInfoExitValue').textContent : null;");
    if ($val === null)
      $I->fail("paymentInfoExitValue not found in 1_0_0");

    $I->assertStringContainsString($nowDate, $val);

    $I->click('#successOverlay button.btn-secondary');
    $I->waitForText('Do zapłaty: 0,00 PLN', 10);
    $I->see($nowDate, '#paymentInfoExitValue');
  }

  /**
   * Scenario 1_0_1 (Hourly/Single/Midnight)
   */
  public function testAfterPayScenario_1_0_1(AcceptanceTester $I)
  {
    $this->applyScenario('1_0_1');
    $I->amOnPage('/?ticket_id=PAY_101');
    $I->waitForText('TEST MODE: [1_0_1]', 5);

    $I->click('#payButton');
    $I->waitForElementVisible('#successOverlay', 10);

    $today = date('Y-m-d');
    $val = $I->executeJS("return document.getElementById('paymentInfoExitValue') ? document.getElementById('paymentInfoExitValue').textContent : null;");
    if ($val === null)
      $I->fail("paymentInfoExitValue not found in 1_0_1");

    $I->assertStringContainsString($today, $val);

    $I->click('#successOverlay button.btn-secondary');
    $I->waitForText('Do zapłaty: 0,00 PLN', 10);
    $I->see($today, '#paymentInfoExitValue');
  }

  /**
   * Scenario 0_1_0 (Daily/Multi/Entry)
   */
  public function testAfterPayScenario_0_1_0(AcceptanceTester $I)
  {
    $this->applyScenario('0_1_0');
    $I->amOnPage('/?ticket_id=PAY_010');
    $I->waitForText('TEST MODE: [0_1_0]', 5);

    $I->click('#payButton');
    $I->waitForElementVisible('#successOverlay', 10);

    $tomorrow = date('Y-m-d', strtotime('+23 hours'));
    $val = $I->executeJS("return document.getElementById('paymentInfoExitValue') ? document.getElementById('paymentInfoExitValue').textContent : null;");
    if ($val === null)
      $I->fail("paymentInfoExitValue not found in 0_1_0");

    $I->assertStringContainsString($tomorrow, $val);

    $I->click('#successOverlay button.btn-secondary');
    $I->waitForText('Do zapłaty: 0,00 PLN', 10);
    $I->see($tomorrow, '#paymentInfoExitValue');
  }

  /**
   * Scenario 0_1_1 (Daily/Multi/Midnight)
   */
  public function testAfterPayScenario_0_1_1(AcceptanceTester $I)
  {
    $this->applyScenario('0_1_1');
    $I->amOnPage('/?ticket_id=PAY_011');
    $I->waitForText('TEST MODE: [0_1_1]', 5);

    $I->click('#payButton');
    $I->waitForElementVisible('#successOverlay', 10);

    $val = $I->executeJS("return document.getElementById('paymentInfoExitValue') ? document.getElementById('paymentInfoExitValue').textContent : null;");
    if ($val === null)
      $I->fail("paymentInfoExitValue not found in 0_1_1");

    $I->assertStringContainsString('23:59', $val);

    $I->click('#successOverlay button.btn-secondary');
    $I->waitForText('Do zapłaty: 0,00 PLN', 10);
    $I->see('23:59', '#paymentInfoExitValue');
  }

  /**
   * Scenario 1_1_0 (Hourly/Multi/Entry)
   */
  public function testAfterPayScenario_1_1_0(AcceptanceTester $I)
  {
    $this->applyScenario('1_1_0');
    $I->amOnPage('/?ticket_id=PAY_110');
    $I->waitForText('TEST MODE: [1_1_0]', 5);

    $I->click('#payButton');
    $I->waitForElementVisible('#successOverlay', 10);

    $today = date('Y-m-d');
    $val = $I->executeJS("return document.getElementById('paymentInfoExitValue') ? document.getElementById('paymentInfoExitValue').textContent : null;");
    if ($val === null)
      $I->fail("paymentInfoExitValue not found in 1_1_0");

    $I->assertStringContainsString($today, $val);

    $I->click('#successOverlay button.btn-secondary');
    $I->waitForText('Do zapłaty: 0,00 PLN', 10);
    $I->see($today, '#paymentInfoExitValue');
  }

  /**
   * Scenario 1_1_1 (Hourly/Multi/Midnight)
   */
  public function testAfterPayScenario_1_1_1(AcceptanceTester $I)
  {
    $this->applyScenario('1_1_1');
    $I->amOnPage('/?ticket_id=PAY_111');
    $I->waitForText('TEST MODE: [1_1_1]', 5);

    $I->click('#payButton');
    $I->waitForElementVisible('#successOverlay', 10);

    $today = date('Y-m-d');
    $val = $I->executeJS("return document.getElementById('paymentInfoExitValue') ? document.getElementById('paymentInfoExitValue').textContent : null;");
    if ($val === null)
      $I->fail("paymentInfoExitValue not found in 1_1_1");

    $I->assertStringContainsString($today, $val);

    $I->click('#successOverlay button.btn-secondary');
    $I->waitForText('Do zapłaty: 0,00 PLN', 10);
    $I->see($today, '#paymentInfoExitValue');
  }
}

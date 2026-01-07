<?php

namespace Tests\Acceptance;

use Tests\Support\AcceptanceTester;

class SpinnerJumpCest
{
  private static $mockPid;
  private static $phpServerPid;
  private static $port = 8022;
  private static $mockPort = 8099;
  private static $configFile = 'config_e2e_test.ini';

  public function _before(AcceptanceTester $I)
  {
    // 1. Start Mock Server
    $cmdMock = "php tests/MockServer.php " . self::$mockPort . " > tests/_output/mock_output.log 2>&1 & echo $!";
    self::$mockPid = trim(shell_exec($cmdMock));

    // 2. Start PHP Web Server
    $cmdPhp = "PARK_CONFIG_FILE=" . self::$configFile . " php -S 127.0.0.1:" . self::$port . " > tests/_output/php_server.log 2>&1 & echo $!";
    self::$phpServerPid = trim(shell_exec($cmdPhp));

    sleep(2);
  }

  public function _after(AcceptanceTester $I)
  {
    if (self::$mockPid)
      shell_exec("kill " . self::$mockPid);
    if (self::$phpServerPid)
      shell_exec("kill " . self::$phpServerPid);
    if (file_exists(self::$configFile))
      unlink(self::$configFile);
  }

  private function applyScenarioConfig(string $scenarioString)
  {
    $configContent = '
[settings]
station_id = "TEST_STATION"
currency = "PLN"
hourly_rate = 5.00
free_minutes = 15
PARK_CONFIG_FILE = ' . self::$configFile . '

[api]
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

  // tests
  public function testSpinnerNoJumpOnSameDate(AcceptanceTester $I)
  {
    $this->applyScenarioConfig('1_1_0'); // Multi-Day Hourly
    $I->amOnPage('/?ticket_id=UI_TEST&_t=' . time());
    $I->waitForElement('.config-btn', 10);

    // Ensure we are in Exit Date mode
    $I->click('#exitDateBtn');
    $I->waitForElement('#exitDateBtn.active', 5);

    // Execute JS to simulate dragging without changing the value
    // We'll simulate start, drag (small change), and stop
    $I->executeJS("
            const slider = $('#slider').data('roundSlider');
            const startEvent = new Event('start');
            const stopEvent = new Event('stop');
            
            // Initial state capture
            slider.options.start(); 
            
            // Simulate drag but stay on same value (0 days)
            // 360 degrees = 7 days. < 51 degrees is still 0 days.
            slider.setValue(10); 
            slider.options.drag({value: 10});
            
            // Stop interaction
            slider.options.stop();
        ");

    // Assert: Should still be on Date button
    $I->seeElement('#exitDateBtn.active');
    $I->dontSeeElement('#exitTimeBtn.active');

    // Now simulate a change that DOES change the day
    // 1 day ~ 52 degrees. Let's do 60 degrees.
    $I->executeJS("
            const slider = $('#slider').data('roundSlider');
            
            // Initial state capture
            slider.options.start();
            
            // Drag to > 1 day
            slider.setValue(60); 
            slider.options.drag({value: 60});
            
            // Stop interaction
            slider.options.stop();
        ");

    // Assert: Should now auto-switch to Time button
    $I->wait(1); // Small wait for UI transition
    $I->seeElement('#exitTimeBtn.active');
  }
}

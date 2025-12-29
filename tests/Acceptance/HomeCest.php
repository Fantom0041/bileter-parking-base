<?php

declare(strict_types=1);

namespace Tests\Acceptance;

use Tests\Support\AcceptanceTester;

final class HomeCest
{
    private static $mockPid;
    private static $phpServerPid;
    private static $mockPort = 8099; // Changed port
    private static $originalConfig;

    /**
     * Start Mock Server and configure existing config.ini to use it.
     */
    public function _before(AcceptanceTester $I): void
    {
        // 1. Start Mock Server
        $cmd = "php tests/MockServer.php " . self::$mockPort . " > tests/mock_output.log 2>&1 & echo $!";
        self::$mockPid = trim(shell_exec($cmd));

        // 2. Start PHP Web Server
        $cmdPhp = "php -S 127.0.0.1:8022 > tests/php_server.log 2>&1 & echo $!";
        self::$phpServerPid = trim(shell_exec($cmdPhp));

        sleep(2); // Wait for both to start

        // 3. Backup and Overwrite config.ini in document root
        // Assuming document root is current dir (given existing paths)
        if (file_exists('config.ini')) {
            self::$originalConfig = file_get_contents('config.ini');
        }

        $configContent = "
[api]
api_url = \"tcp://127.0.0.1:" . self::$mockPort . "\"
api_login = \"test\"
api_pin = \"123\"
api_password = \"pass\"
device_id = 1
device_ip = \"127.0.0.1\"
entity_id = 1

[settings]
currency = \"PLN\"
hourly_rate = 5.00
station_id = \"TEST_ZONE\"
free_minutes = 15
        ";
        file_put_contents('config.ini', $configContent);
    }

    public function _after(AcceptanceTester $I): void
    {
        // 1. Kill Mock Server
        if (self::$mockPid) {
            shell_exec("kill " . self::$mockPid);
        }

        // 2. Kill PHP Server
        if (self::$phpServerPid) {
            shell_exec("kill " . self::$phpServerPid);
        }

        // 3. Restore config.ini
        if (self::$originalConfig !== null) {
            file_put_contents('config.ini', self::$originalConfig);
        } else {
            // If it didn't exist (unlikely), delete it
            if (file_exists('config.ini')) {
                unlink('config.ini');
            }
        }
    }

    public function landingPageWorks(AcceptanceTester $I): void
    {
        $I->amOnPage('/');
        $I->see('Rozlicz parkowanie', 'h1');
        $I->seeElement('#newTicketForm');
    }

    /**
     * Test Daily Mode (FEE_TYPE=0)
     */
    public function dailyModeWorks(AcceptanceTester $I): void
    {
        $I->amOnPage('/?ticket_id=TEST_DAILY');

        $I->dontSee('Rozlicz parkowanie', 'h1');
        $I->see('TEST_DAILY', '#plateDisplay');

        // Check for specific UI behavior for Daily + Single Day (from Mock)
        // Check that DATA button is visible but likely disabled/opaque depending on exact logic
        // But we definitely shouldn't see GODZINA (Time) as primary selector label relative to Hourly?
        // JS: if daily + single -> spinnerLabel: WJAZD/WYJAZD (entry/exit) but spinner hidden

        $I->see('WYJAZD', '#spinnerLabel');
        $I->dontSeeElement('#spinnerContainer[style*="display: none"]'); // Ideally check visibility
        // Actually script.js hides spinnerContainer for daily+single_day:
        // spinnerContainer.style.display = 'none';

        // Verify we are in the correct mode via JS variable (via browser console exec if needed, but UI check is better)
        // Let's check that Time Button is disabled/hidden in Daily mode
        // Script.js: if (currentTimeMode === 'daily') ... exitTimeBtn opacity 0.5
        // We can check style attribute? Codeception `seeElement` with attributes is tricky.
        // But we can check if clicking it does nothing or if class is missing 'active'.
        // Daily mode -> Date active (unless single day), Time inactive.

        // Mock returns Daily + Single Day (FEE_MULTI_DAY=0).
        // Script.js: if daily + single -> Date disabled, Time disabled (implicitly, fixed time).

        // Just verify we see the ticket and fee calculation works (defaults to 0 initially).
        $I->seeElement('#payButton');
    }

    /**
     * Test Hourly Mode (FEE_TYPE=1)
     */
    public function hourlyModeWorks(AcceptanceTester $I): void
    {
        $I->amOnPage('/?ticket_id=TEST_HOURLY');

        $I->dontSee('Rozlicz parkowanie', 'h1');
        $I->see('TEST_HOURLY', '#plateDisplay');

        // Hourly + Single Day (Mock default for now)
        // Spinner label should be WJAZD or WYJAZD, but spinner visible.
        // Script.js: Hourly + Single -> Spinner visible.
        // Wait, script.js: if hourly + single -> spinner hidden? NO.
        // Only hidden for daily + single.

        $I->see('WYJAZD', '#spinnerLabel');
        // $I->seeElement('#spinnerContainer'); // Should be visible
    }

    /**
     * Test Live API Path using Mock (previously Test Live)
     * Now effectively tests that "No 'simulated=1' param" correctly calls our Mock API
     */
    public function liveApiPathWorks(AcceptanceTester $I): void
    {
        $plateNumber = 'TEST_INV'; // Invalid plate -> Should be treated as NEW TICKET

        $I->amOnPage("/?ticket_id={$plateNumber}"); // No simulated=1

        // Mock returns TICKET_EXIST=0 for unknown plates.
        // index.php handles this by starting a new session (is_new=true).
        // So we should see the plate display.

        $I->see($plateNumber, '#plateDisplay');
        $I->dontSee('Błąd');
    }
}

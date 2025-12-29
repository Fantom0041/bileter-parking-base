<?php

use PHPUnit\Framework\TestCase;

class ApiIntegrationTest extends TestCase
{
  private static $mockPid;
  private static $mockPort;
  private static $configFile;

  public static function setUpBeforeClass(): void
  {
    // 1. Pick a random port
    self::$mockPort = rand(30000, 40000);

    // 2. Start Mock Server in background
    $cmd = "php tests/MockServer.php " . self::$mockPort . " > /dev/null 2>&1 & echo $!";
    self::$mockPid = trim(shell_exec($cmd));

    // Give it a moment to start
    usleep(200000); // 200ms

    // 3. Create Request Config
    self::$configFile = getcwd() . '/tests/config_phpunit.ini';
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
";
    file_put_contents(self::$configFile, $configContent);
  }

  public static function tearDownAfterClass(): void
  {
    if (self::$mockPid) {
      shell_exec("kill " . self::$mockPid);
    }
    if (file_exists(self::$configFile)) {
      unlink(self::$configFile);
    }
  }

  private function makeRequest($action, $data = [])
  {
    $payload = array_merge(['action' => $action], $data);
    $json = json_encode($payload);

    // Escape JSON for single-quoted string in shell
    $escapedJson = str_replace("'", "'\\''", $json);

    $cmd = "export CONFIG_FILE='" . self::$configFile . "'; echo '$escapedJson' | php api.php";
    $output = shell_exec($cmd);

    return json_decode($output, true);
  }

  public function testCreateExistingTicket()
  {
    $res = $this->makeRequest('create', ['plate' => 'TEST_OK']);

    $this->assertIsArray($res, 'Response should be an array');
    $this->assertTrue($res['success'], 'Success should be true');
    $this->assertEquals('TEST_OK', $res['ticket_id'], 'Ticket ID should match');
  }

  public function testCreateNewTicket()
  {
    $res = $this->makeRequest('create', ['plate' => 'TEST_NEW']);

    $this->assertIsArray($res);
    $this->assertTrue($res['success']);
    // According to api.php logic, it returns plate as ticket_id for new/not found tickets too
    $this->assertEquals('TEST_NEW', $res['ticket_id']);
  }

  public function testCalculateFee()
  {
    $res = $this->makeRequest('calculate_fee', ['ticket_id' => 'TEST_FEE_500']);

    $this->assertIsArray($res);
    $this->assertTrue($res['success']);
    $this->assertEquals(10, $res['fee'], 'Fee should be 10.00 (from 1000 gr)');
  }

  public function testPay()
  {
    $res = $this->makeRequest('pay', ['ticket_id' => 'TEST_OK', 'amount' => 10.00]);

    $this->assertIsArray($res);
    $this->assertTrue($res['success']);
    $this->assertNotEmpty($res['new_qr_code'], 'Should return a new QR code');
  }
}

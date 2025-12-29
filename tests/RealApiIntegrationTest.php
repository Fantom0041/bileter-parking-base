<?php

use PHPUnit\Framework\TestCase;

class RealApiIntegrationTest extends TestCase
{
  private static $configFile;

  public static function setUpBeforeClass(): void
  {
    // Use the real config.ini from the root directory
    self::$configFile = getcwd() . '/config.ini';

    if (!file_exists(self::$configFile)) {
      self::markTestSkipped('config.ini not found.');
    }
  }

  private function makeRequest($action, $data = [])
  {
    $payload = array_merge(['action' => $action], $data);
    $json = json_encode($payload);

    // Escape JSON for single-quoted string in shell
    $escapedJson = str_replace("'", "'\\''", $json);

    // Point to the real config file
    $cmd = "export CONFIG_FILE='" . self::$configFile . "'; echo '$escapedJson' | php api.php";
    $output = shell_exec($cmd);

    return json_decode($output, true);
  }

  /**
   * Test simple connection by trying to "create" a session for a dummy plate.
   * Use a plate that is unlikely to exist to be safe, or just check that we don't get a connection error.
   */
  public function testRealConnection()
  {
    // We use a dummy plate. API should return success=true (New Session) OR explicit error,
    // but NOT "Connection Error".
    $res = $this->makeRequest('create', ['plate' => 'PING_TEST']);

    $this->assertIsArray($res, 'API response should be an array');

    // Check for catastrophic failure messages
    if (isset($res['message']) && stripos($res['message'], 'Błąd połączenia') !== false) {
      $this->fail('Connection to Real API failed: ' . $res['message']);
    }

    // Generally expect success (as new session)
    $this->assertTrue($res['success'], 'API Login/Connection failed or API returned error: ' . ($res['message'] ?? ''));
  }

  public function testRealExistingTicket()
  {
    $plate = getenv('REAL_EXISTING_PLATE');
    if (!$plate) {
      $this->markTestSkipped('REAL_EXISTING_PLATE env var not set.');
    }

    $res = $this->makeRequest('create', ['plate' => $plate]);

    $this->assertTrue($res['success']);
    // Verify it found the ticket (ticket_id should match plate or be present)
    $this->assertEquals($plate, $res['ticket_id']);
  }

  public function testRealCalculateFee()
  {
    $ticketId = getenv('REAL_FEE_TICKET');
    if (!$ticketId) {
      $this->markTestSkipped('REAL_FEE_TICKET env var not set.');
    }

    $res = $this->makeRequest('calculate_fee', ['ticket_id' => $ticketId]);

    $this->assertTrue($res['success'], 'Fee calc failed: ' . ($res['message'] ?? ''));
    $this->assertArrayHasKey('fee', $res);
  }

  public function testRealPay()
  {
    $ticketId = getenv('REAL_PAY_TICKET');
    if (!$ticketId) {
      $this->markTestSkipped('REAL_PAY_TICKET env var not set. Skipping payment test to save money/state.');
    }

    // Amount to pay - assuming 0 or low value, or just checking if req works.
    // BE CAREFUL: This might charge money or close ticket.
    $res = $this->makeRequest('pay', ['ticket_id' => $ticketId, 'amount' => 1.00]);

    $this->assertTrue($res['success'], 'Payment failed: ' . ($res['message'] ?? ''));
    $this->assertNotEmpty($res['new_qr_code']);
  }
}

<?php

class Logger
{
  private $logFile;

  public function __construct($logFile = 'app.log')
  {
    $this->logFile = $logFile;
  }

  public function log($message, $level = 'INFO')
  {
    $timestamp = date('Y-m-d H:i:s');

    if (is_array($message) || is_object($message)) {
      $message = json_encode($message, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    $formattedMessage = "[$timestamp] [$level] $message" . PHP_EOL;

    // Write to file
    @file_put_contents($this->logFile, $formattedMessage, FILE_APPEND);

    // Write to console (stderr)
    // Write to console (stderr)
    // Write to console (stderr)
    error_log(trim($formattedMessage));
  }

  public function logApi($direction, $endpoint, $data)
  {
    $this->log("API $direction [$endpoint]: " . json_encode($data, JSON_UNESCAPED_UNICODE));
  }
}

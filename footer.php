<footer class="app-footer">
  <span>Powered by <a href="https://www.basesystem.pl/" target="_blank">Base System</a></span>
  <?php if (isset($config) && !empty($config['api']['api_url'])): ?>
    <div style="font-size: 0.7rem; color: #999; margin-top: 4px; text-align: center;">
      <?php
      $host = parse_url($config['api']['api_url'], PHP_URL_HOST);
      echo "TCP: " . $host;
      ?>
      <?php if (isset($ticket) && $ticket && isset($ticket['api_data'])): ?>
        <br>
        <div style="font-family: monospace; font-size: 0.65rem; color: #777;">
          RAW API DATA:<br>
          FEE_TYPE: <?php var_export($ticket['api_data']['FEE_TYPE'] ?? 'N/A'); ?><br>
          FEE_MULTI: <?php var_export($ticket['api_data']['FEE_MULTI_DAY'] ?? 'N/A'); ?><br>
          FEE_START: <?php var_export($ticket['api_data']['FEE_STARTS_TYPE'] ?? 'N/A'); ?><br>
          EXIST: <?php var_export($ticket['api_data']['TICKET_EXIST'] ?? 'N/A'); ?>
        </div>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</footer>
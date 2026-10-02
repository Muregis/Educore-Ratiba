<?php
declare(strict_types=1);
header('Cache-Control: no-store');
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
$readyUrl = ($https ? 'https' : 'http') . '://' . $host . '/health.php?ready=1';

$payload = null;
$httpCode = 0;
$ch = curl_init($readyUrl);
if ($ch) {
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    $raw = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if (is_string($raw)) {
        $payload = json_decode($raw, true);
    }
}
$ok = is_array($payload) && ($payload['status'] ?? '') === 'healthy' && $httpCode === 200;
$label = $ok ? 'All systems operational' : (($payload['status'] ?? '') === 'degraded' ? 'Degraded performance' : 'Service disruption');
$color = $ok ? '#059669' : (($payload['status'] ?? '') === 'degraded' ? '#d97706' : '#dc2626');
?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>EduCore Ratiba Status</title>
  <style>
    body { font-family: system-ui, sans-serif; max-width: 640px; margin: 40px auto; padding: 0 16px; color: #0f172a; }
    .badge { display: inline-block; padding: 8px 14px; border-radius: 999px; background: <?php echo $color; ?>22; color: <?php echo $color; ?>; font-weight: 700; }
    .card { border: 1px solid #e2e8f0; border-radius: 12px; padding: 16px; margin-top: 20px; }
    code { background: #f1f5f9; padding: 2px 6px; border-radius: 4px; font-size: 0.85rem; }
    .muted { color: #64748b; font-size: 0.9rem; }
  </style>
</head>
<body>
  <h1>EduCore Ratiba</h1>
  <p class="badge"><?php echo htmlspecialchars($label); ?></p>
  <div class="card">
    <p><strong>API / app</strong> — <?php echo $ok ? 'Operational' : 'Check in progress'; ?></p>
    <p class="muted">Checked at <?php echo htmlspecialchars(gmdate('Y-m-d H:i:s')); ?> UTC</p>
    <?php if (is_array($payload)): ?>
      <p class="muted">Health: <code><?php echo htmlspecialchars((string)($payload['status'] ?? '')); ?></code>
      · DB: <code><?php echo !empty($payload['checks']['database']) ? 'up' : 'down'; ?></code></p>
    <?php endif; ?>
  </div>
  <p class="muted" style="margin-top:24px">Machine-readable: <a href="/health.php?ready=1">/health.php?ready=1</a></p>
</body>
</html>

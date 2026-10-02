<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../db/db.php';

$schoolId = requireLoginAndGetSchoolId();
$stmt = db()->prepare('SELECT * FROM schools WHERE id = ?');
$stmt->execute([$schoolId]);
$school = $stmt->fetch();

$configured = (string) (getenv('MPESA_CONSUMER_KEY') ?: '') !== ''
    && (string) (getenv('MPESA_CONSUMER_SECRET') ?: '') !== ''
    && (string) (getenv('MPESA_PASSKEY') ?: '') !== ''
    && (string) (getenv('MPESA_SHORTCODE') ?: '') !== '';

$pageTitle = 'Payments — ' . ($school['name'] ?? 'School');
require __DIR__ . '/_header.php';
?>
<div class="card">
  <h2 style="margin-top:0">M-Pesa Payments</h2>
  <?php if (!$configured): ?>
    <div class="error" style="margin:12px 0;">
      <strong>Not enabled on this deployment.</strong>
      Billing via M-Pesa is optional and needs Safaricom Daraja credentials
      (<code>MPESA_CONSUMER_KEY</code>, <code>MPESA_CONSUMER_SECRET</code>,
      <code>MPESA_PASSKEY</code>, <code>MPESA_SHORTCODE</code>).
    </div>
    <p class="empty">All timetable features work without payments. Ask the platform admin to enable billing when ready.</p>
  <?php else: ?>
    <p class="empty">M-Pesa credentials are present. Full STK UI is available in the commercial package.</p>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/_footer.php'; ?>

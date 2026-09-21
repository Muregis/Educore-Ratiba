<?php
declare(strict_types=1);

/**
 * sso_test.php — generates a test JWT and redirects to sso.php
 *
 * DELETE this file (or restrict access) in production after testing.
 *
 * Usage (browser): /sso_test.php
 * Optional query: ?school_id=demo-001&username=admin&name=Demo+School
 */

require_once __DIR__ . '/config/config.php';

$secret = (string) Config::get('security.sso_secret', '');
if ($secret === '' || $secret === 'REPLACE_ME_BEFORE_DEPLOYING') {
    http_response_code(503);
    echo '<h1>SSO not configured</h1><p>Set environment variable <code>SSO_SHARED_SECRET</code> on Render to a 64-char hex value, then restart.</p>';
    echo '<pre>openssl rand -hex 32</pre>';
    exit;
}

$schoolId   = trim($_GET['school_id'] ?? 'demo-school-001');
$schoolName = trim($_GET['name'] ?? 'Demo School');
$username   = trim($_GET['username'] ?? 'admin');

$payload = [
    'educore_school_id'      => $schoolId,
    'educore_school_name'    => $schoolName,
    'educore_admin_username' => $username,
    'exp'                    => time() + 300,
];

$header = ['alg' => 'HS256', 'typ' => 'JWT'];
$headerB64 = rtrim(strtr(base64_encode(json_encode($header)), '+/', '-_'), '=');
$payloadB64 = rtrim(strtr(base64_encode(json_encode($payload)), '+/', '-_'), '=');
$sig = rtrim(strtr(base64_encode(
    hash_hmac('sha256', $headerB64 . '.' . $payloadB64, $secret, true)
), '+/', '-_'), '=');
$token = $headerB64 . '.' . $payloadB64 . '.' . $sig;
$redirectUrl = 'sso.php?token=' . urlencode($token);

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>SSO Test — EduCore Ratiba</title>
    <style>
        body { font-family: system-ui, sans-serif; max-width: 720px; margin: 40px auto; padding: 0 16px; }
        code, textarea { font-family: ui-monospace, monospace; font-size: 12px; }
        textarea { width: 100%; height: 120px; }
        .ok { color: #047857; }
        a.btn { display: inline-block; margin-top: 16px; padding: 10px 18px; background: #6366f1; color: #fff; text-decoration: none; border-radius: 8px; }
    </style>
</head>
<body>
    <h1>SSO Test (EduCore → Ratiba)</h1>
    <p class="ok">Secret is set (length <?php echo strlen($secret); ?>).</p>
    <p><strong>Payload</strong></p>
    <pre><?php echo htmlspecialchars(json_encode($payload, JSON_PRETTY_PRINT)); ?></pre>
    <p><strong>Token</strong></p>
    <textarea readonly><?php echo htmlspecialchars($token); ?></textarea>
    <p>
        <a class="btn" href="<?php echo htmlspecialchars($redirectUrl); ?>">Test SSO sign-in now</a>
    </p>
    <p>Redirecting in 3 seconds…</p>
    <script>setTimeout(function () { location.href = <?php echo json_encode($redirectUrl); ?>; }, 3000);</script>
    <hr>
    <p>EduCore should redirect users to the same URL pattern with a real JWT signed with the same <code>SSO_SHARED_SECRET</code>.</p>
</body>
</html>

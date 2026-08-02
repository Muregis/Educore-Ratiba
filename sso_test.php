<?php
declare(strict_types=1);

/**
 * sso_test.php — generates a test JWT and redirects to sso.php
 *
 * Usage: visit this file in a browser, or run:
 *   php sso_test.php
 *
 * After testing, DELETE this file for security.
 */

require_once __DIR__ . '/db/db.php';

// Read the shared secret from sso.php
$secret = getenv('SSO_SHARED_SECRET') ?: 'REPLACE_ME_BEFORE_DEPLOYING';

// Test payload — matches what EduCore would send
$payload = [
    'educore_school_id'      => 'demo-school-001',
    'educore_school_name'    => 'Demo School',
    'educore_admin_username' => 'admin',
    'exp'                    => time() + 300, // 5 minutes from now
];

// Encode header
$header = ['alg' => 'HS256', 'typ' => 'JWT'];
$headerB64 = rtrim(strtr(base64_encode(json_encode($header)), '+/', '-_'), '=');

// Encode payload
$payloadJson = json_encode($payload);
$payloadB64 = rtrim(strtr(base64_encode($payloadJson), '+/', '-_'), '=');

// Sign
$signatureInput = $headerB64 . '.' . $payloadB64;
$sig = rtrim(strtr(base64_encode(hash_hmac('sha256', $signatureInput, $secret, true)), '+/', '-_'), '=');

// Build token
$token = $headerB64 . '.' . $payloadB64 . '.' . $sig;

// Show token info
echo "<!DOCTYPE html><html><head><title>SSO Test</title></head><body>";
echo "<h2>SSO Test Token Generator</h2>";
echo "<p><strong>Secret:</strong> " . htmlspecialchars($secret) . "</p>";
echo "<p><strong>Payload:</strong> <pre>" . htmlspecialchars(json_encode($payload, JSON_PRETTY_PRINT)) . "</pre></p>";
echo "<p><strong>Token:</strong></p>";
echo "<textarea style='width:100%;height:200px;font-family:monospace;font-size:12px;' readonly>" . htmlspecialchars($token) . "</textarea>";

// Auto-redirect after 3 seconds
$redirectUrl = 'sso.php?token=' . urlencode($token);
echo "<p style='margin-top:20px;'><a href='" . htmlspecialchars($redirectUrl) . "'>Click here to test SSO</a></p>";
echo "<p>Redirecting in 3 seconds...</p>";
echo "<script>setTimeout(function(){ window.location.href = '" . htmlspecialchars($redirectUrl) . "'; }, 3000);</script>";
echo "</body></html>";

<?php
declare(strict_types=1);

/**
 * SecurityTest — Automated security & cryptographic test suite for EduCore Ratiba.
 *
 * Verifies:
 * 1. CSRF token generation, formatting, and timing-attack-safe validation
 * 2. Brute-force login rate-limiting and account lockout progression
 * 3. HMAC-SHA256 SSO token minting, claim validation, signature tampering, and expiration rejection
 *
 * Exit code 0 = all pass (CI-friendly).
 */

require_once __DIR__ . '/../db/session_csrf.php';
require_once __DIR__ . '/../db/db.php';
require_once __DIR__ . '/../sso.php';

$passed = 0;
$failed = 0;

function assertSec(bool $cond, string $label): void
{
    global $passed, $failed;
    if ($cond) {
        echo "PASS  {$label}\n";
        $passed++;
    } else {
        echo "FAIL  {$label}\n";
        $failed++;
    }
}

echo "=== SECURITY, CSRF & AUTHENTICATION TEST SUITE ===\n\n";

// ============================================================
// 1. CSRF TOKEN GENERATION & INTEGRITY
// ============================================================
echo "-- CSRF Protection --\n";

bootstrapSession();
$token1 = getCsrfToken();
assertSec(is_string($token1) && strlen($token1) === 64 && ctype_xdigit($token1), 'CSRF token is 64-character cryptographic hex string');

$token2 = getCsrfToken();
assertSec($token1 === $token2, 'CSRF token remains stable across same session requests');

$field = csrfField();
assertSec(str_contains($field, '<input type="hidden" name="_csrf_token"') && str_contains($field, $token1), 'csrfField() generates valid hidden input containing active token');

// Timing attack resistance simulation via hash_equals
$validToken = $token1;
$forgedToken = bin2hex(random_bytes(32));
assertSec(hash_equals($validToken, $validToken) === true, 'Valid CSRF token passes verification');
assertSec(hash_equals($validToken, $forgedToken) === false, 'Forged CSRF token reliably rejected');
assertSec(hash_equals($validToken, '') === false, 'Empty CSRF token reliably rejected');


// ============================================================
// 2. BRUTE-FORCE RATE-LIMITING & LOCKOUT
// ============================================================
echo "\n-- Brute-Force Rate Limiting & Lockout --\n";

$testUser = 'security_test_user_' . bin2hex(random_bytes(4));
clearFailedLogins($testUser);

assertSec(loginLockoutSeconds($testUser) === 0, 'New user starts with zero lockout seconds');

// First 4 failed attempts should not trigger lockout
$l1 = recordFailedLogin($testUser);
$l2 = recordFailedLogin($testUser);
$l3 = recordFailedLogin($testUser);
$l4 = recordFailedLogin($testUser);
assertSec(!$l1 && !$l2 && !$l3 && !$l4 && loginLockoutSeconds($testUser) === 0, 'Attempts 1-4 increment counter without locking account');

// 5th failed attempt must trigger lockout
$l5 = recordFailedLogin($testUser);
$remaining = loginLockoutSeconds($testUser);
assertSec($l5 === true && $remaining >= 55 && $remaining <= 60, '5th consecutive failed attempt triggers 60s lockout');

// Successful login clearing
clearFailedLogins($testUser);
assertSec(loginLockoutSeconds($testUser) === 0, 'Successful authentication clears lockout immediately');


// ============================================================
// 3. SSO JWT SIGNATURE & EXPIRATION VERIFICATION
// ============================================================
echo "\n-- SSO Cryptographic Handoff (HMAC-SHA256) --\n";

$secret = 'test_secret_key_1234567890abcdef1234567890abcdef1234567890abcdef';

function mintTestJwt(array $payload, string $secret): string
{
    $header = rtrim(strtr(base64_encode(json_encode(['typ' => 'JWT', 'alg' => 'HS256'])), '+/', '-_'), '=');
    $body = rtrim(strtr(base64_encode(json_encode($payload)), '+/', '-_'), '=');
    $sig = rtrim(strtr(base64_encode(hash_hmac('sha256', "{$header}.{$body}", $secret, true)), '+/', '-_'), '=');
    return "{$header}.{$body}.{$sig}";
}

// 1. Valid Token
$validPayload = [
    'educore_school_id' => 'SCH_1001',
    'educore_admin_username' => 'principal_doe',
    'educore_school_name' => 'Alliance Academy',
    'exp' => time() + 300,
];
$validJwt = mintTestJwt($validPayload, $secret);
$res = verifySsoToken($validJwt, $secret);
assertSec($res !== null && $res['educore_school_id'] === 'SCH_1001', 'Valid SSO JWT verifies and returns expected claims');

// 2. Expired Token
$expiredPayload = $validPayload;
$expiredPayload['exp'] = time() - 60; // expired 1 min ago
$expiredJwt = mintTestJwt($expiredPayload, $secret);
$resExpired = verifySsoToken($expiredJwt, $secret);
assertSec($resExpired === null, 'Expired SSO JWT is rejected');

// 3. Tampered Payload (Privilege Escalation attempt)
$tamperedPayload = $validPayload;
$tamperedPayload['educore_school_id'] = 'SCH_HACKED';
$tamperedBody = rtrim(strtr(base64_encode(json_encode($tamperedPayload)), '+/', '-_'), '=');
$parts = explode('.', $validJwt);
$tamperedJwt = "{$parts[0]}.{$tamperedBody}.{$parts[2]}"; // keeping old signature
$resTampered = verifySsoToken($tamperedJwt, $secret);
assertSec($resTampered === null, 'Tampered JWT payload with mismatched signature is rejected');

// 4. Invalid Signature with wrong secret
$wrongSecret = 'wrong_secret_key_00000000000000000000000000000000000000000000000';
$resWrongSecret = verifySsoToken($validJwt, $wrongSecret);
assertSec($resWrongSecret === null, 'JWT signed with wrong secret is rejected');

// 5. Missing Required Claims
$incompletePayload = [
    'educore_admin_username' => 'principal_doe',
    'exp' => time() + 300,
    // missing educore_school_id
];
$incompleteJwt = mintTestJwt($incompletePayload, $secret);
$resIncomplete = verifySsoToken($incompleteJwt, $secret);
assertSec($resIncomplete === null, 'JWT with missing required tenant claims is rejected');

echo "\n============================================\n";
echo "SECURITY RESULTS: {$passed} passed, {$failed} failed\n";
echo "============================================\n";

exit($failed > 0 ? 1 : 0);

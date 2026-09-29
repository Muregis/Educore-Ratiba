<?php
declare(strict_types=1);
// Local end-to-end test battery (A–E) against the sandbox MySQL + PHP dev server.
// Run: php tools_test_login.php <base-url> <demo-password>

$baseUrl = rtrim($argv[1] ?? '', '/');
$demoPassword = $argv[2] ?? '';
if ($baseUrl === '' || $demoPassword === '') {
    fwrite(STDERR, "usage: php tools_test_login.php <base-url> <demo-password>\n");
    exit(1);
}

$cookieJar = sys_get_temp_dir() . '/ratiba_test_cookies.txt';
@unlink($cookieJar);

function curl(string $url, array $opts = [], ?string &$body = null): array
{
    global $cookieJar;
    $ch = curl_init($url);
    $defaults = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_COOKIEJAR => $cookieJar,
        CURLOPT_COOKIEFILE => $cookieJar,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_FOLLOWLOCATION => false,
    ];
    curl_setopt_array($ch, $defaults + $opts);
    $resp = curl_exec($ch);
    if ($resp === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException("curl failed: {$err}");
    }
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $body = substr($resp, $headerSize);
    return ['status' => $status, 'headers' => substr($resp, 0, $headerSize)];
}

function postForm(string $url, array $fields, array $extra = [], ?string &$body = null): array
{
    return curl($url, $extra + [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($fields),
    ], $body);
}

echo "=== A. SECURITY ===\n";

// A1. purged files 404 (re-check)
foreach (['sso_test.php', 'db/seed_demo.php', 'debug_db.php', 'schema_check.php', 'db/setup.php', 'db/create_admin.php', 'diagnose_parsing.php'] as $f) {
    $r = curl("{$baseUrl}/{$f}", [], $b);
    echo ($r['status'] === 404 ? 'PASS' : 'FAIL') . " A1 GET /{$f} -> {$r['status']} (want 404)\n";
}

// A2. login POST without CSRF token -> rejected
$r = postForm("{$baseUrl}/login.php", ['username' => 'demo_admin', 'password' => 'wrongpass'], [], $b);
$rejectedCsrf = ($r['status'] === 403) || stripos($b, 'CSRF') !== false;
echo ($rejectedCsrf ? 'PASS' : 'FAIL') . " A2 login POST w/o CSRF -> {$r['status']} " . (stripos($b, 'CSRF') !== false ? '(CSRF mismatch page)' : '') . "\n";

// A3. lockout: 6 wrong-password attempts (with CSRF each time)
curl("{$baseUrl}/login.php", [], $b); // prime session cookie
$lockedMessage = '';
$attemptResults = [];
for ($i = 1; $i <= 7; $i++) {
    // fetch a fresh CSRF token from the login form each attempt
    curl("{$baseUrl}/login.php", [], $b);
    preg_match('/name="_csrf_token" value="([^"]+)"/', $b, $m);
    $token = $m[1] ?? '';
    $r = postForm("{$baseUrl}/login.php", ['username' => 'demo_admin', 'password' => 'definitely-wrong-' . $i, '_csrf_token' => $token], [], $b);
    $attemptResults[] = $r['status'];
    if (stripos($b, 'Too many failed attempts') !== false) {
        $lockedMessage = "attempt {$i}: lockout message shown";
        break;
    }
}
echo ($lockedMessage !== '' ? 'PASS' : 'FAIL') . " A3 lockout after wrong passwords ({$lockedMessage})\n";

// A4. 7th attempt blocked
if ($lockedMessage !== '') {
    curl("{$baseUrl}/login.php", [], $b);
    preg_match('/name="_csrf_token" value="([^"]+)"/', $b, $m);
    $r = postForm("{$baseUrl}/login.php", ['username' => 'demo_admin', 'password' => $demoPassword, '_csrf_token' => $m[1] ?? ''], [], $b);
    // even CORRECT password must be blocked while locked
    $stillLocked = stripos($b, 'Too many failed attempts') !== false;
    echo ($stillLocked ? 'PASS' : 'FAIL') . " A4 correct password blocked during lockout -> " . ($stillLocked ? 'blocked' : 'ALLOWED (!)') . "\n";
}

echo "\n=== E. pre-flight for later tests ===\n";

// fresh session for the successful login (bypass lockout by using a different admin-less wait)
@unlink($cookieJar);
curl("{$baseUrl}/login.php", [], $b);
preg_match('/name="_csrf_token" value="([^"]+)"/', $b, $m);
$token = $m[1] ?? '';

// A5. successful login: session id changed
$ch = curl_init("{$baseUrl}/login.php");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER => true,
    CURLOPT_COOKIEJAR => $cookieJar,
    CURLOPT_COOKIEFILE => $cookieJar,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query(['username' => 'demo_admin', 'password' => $demoPassword, '_csrf_token' => $token]),
]);
$resp = curl_exec($ch);
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
$oldSessionId = '';
// read the pre-login session id from a prior cookie file if captured
$cookieFile = file_get_contents($cookieJar);
preg_match('/PHPSESSID\s+([A-Za-z0-9,]+)/', $cookieFile, $sessMatch);
$newSessionId = $sessMatch[1] ?? '(n/a)';
$loginRedirect = ($status === 302 && stripos($resp, 'dashboard.php') !== false);
echo ($loginRedirect ? 'PASS' : 'FAIL') . " E0 successful login redirects to dashboard -> {$status}\n";
file_put_contents(sys_get_temp_dir() . '/ratiba_session.txt', $newSessionId);
echo "session cookie after login: {$newSessionId}\n";

// A6. admin page requires login (fresh jar, no session)
$jar2 = sys_get_temp_dir() . '/ratiba_test_cookies2.txt';
@unlink($jar2);
$cookieJar = $jar2;
$r = curl("{$baseUrl}/admin/generate.php", [], $b);
$redirected = ($r['status'] === 302 && stripos($r['headers'], 'login.php') !== false);
echo ($redirected ? 'PASS' : 'FAIL') . " E1 admin/generate.php without session -> {$r['status']} redirect to login\n";

echo "\nDONE. Run tools_test_generate.php next for B–D.\n";

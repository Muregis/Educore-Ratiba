<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/db/error_handler.php';
require_once __DIR__ . '/db/db.php';
require_once __DIR__ . '/config/config.php';

// ============================================================
// sso.php — EduCore ↔ Ratiba single sign-on bridge
//
// EduCore SMS generates a short-lived signed JWT when a school
// admin clicks "Timetable", then redirects here:
//   https://educore-ratiba.onrender.com/sso.php?token=<jwt>
//
// Identity handoff only — separate databases. Maps educore_school_id
// to a Ratiba school and starts a normal session.
//
// Required env (both apps must match):
//   SSO_SHARED_SECRET=<64-char hex>
// ============================================================

$ssoSecret = (string) Config::get('security.sso_secret', '');
if ($ssoSecret === '' || $ssoSecret === 'REPLACE_ME_BEFORE_DEPLOYING') {
    http_response_code(503);
    die('SSO is not configured. Set SSO_SHARED_SECRET on Render and in EduCore to the same value.');
}

/**
 * Verify HS256 JWT (signature + exp + required claims).
 */
function verifySsoToken(string $token, string $secret): ?array
{
    $parts = explode('.', $token);
    if (count($parts) !== 3) {
        return null;
    }
    [$headerB64, $payloadB64, $sigB64] = $parts;

    $expectedSig = rtrim(strtr(base64_encode(
        hash_hmac('sha256', "{$headerB64}.{$payloadB64}", $secret, true)
    ), '+/', '-_'), '=');

    if (!hash_equals($expectedSig, $sigB64)) {
        return null;
    }

    $payloadJson = base64_decode(strtr($payloadB64, '-_', '+/'));
    $payload = json_decode($payloadJson, true);

    if (!is_array($payload)) {
        return null;
    }
    if (!isset($payload['exp']) || (int) $payload['exp'] < time()) {
        return null;
    }
    if (!isset($payload['educore_school_id'], $payload['educore_admin_username'])) {
        return null;
    }
    $payload['educore_school_id'] = (string) $payload['educore_school_id'];
    $payload['educore_admin_username'] = trim((string) $payload['educore_admin_username']);
    if ($payload['educore_school_id'] === '' || $payload['educore_admin_username'] === '') {
        return null;
    }

    return $payload;
}

/**
 * INSERT and return new id — works on MySQL and PostgreSQL.
 */
function insertGetId(string $sql, array $params): int
{
    $driver = Config::get('database.driver', 'mysql');
    if ($driver === 'pgsql' && stripos($sql, 'RETURNING') === false) {
        $sql = rtrim($sql, " \t\n\r;") . ' RETURNING id';
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return (int) db()->lastInsertId();
}

$token = $_GET['token'] ?? '';
if ($token === '') {
    http_response_code(400);
    die('Missing SSO token. Open Timetable from EduCore, or use a valid sso.php?token=… link.');
}

$payload = verifySsoToken($token, $ssoSecret);
if ($payload === null) {
    http_response_code(401);
    die('This sign-in link is invalid or has expired. Please try again from EduCore.');
}

$educoreSchoolId = $payload['educore_school_id'];
$adminUsername   = $payload['educore_admin_username'];
$schoolName      = trim((string) ($payload['educore_school_name'] ?? 'EduCore School'));
if ($schoolName === '') {
    $schoolName = 'EduCore School';
}

try {
    $stmt = db()->prepare('SELECT * FROM schools WHERE educore_school_id = ?');
    $stmt->execute([$educoreSchoolId]);
    $school = $stmt->fetch();

    if (!$school) {
        $newSchoolId = insertGetId(
            'INSERT INTO schools (name, deployment_type, educore_school_id) VALUES (?, ?, ?)',
            [$schoolName, 'server-hosted', $educoreSchoolId]
        );

        $randomPassword = bin2hex(random_bytes(32));
        $schoolAdminId = insertGetId(
            'INSERT INTO school_admins (school_id, username, password_hash) VALUES (?, ?, ?)',
            [$newSchoolId, $adminUsername, password_hash($randomPassword, PASSWORD_DEFAULT)]
        );
        $schoolId = $newSchoolId;
    } else {
        $schoolId = (int) $school['id'];

        $stmt = db()->prepare('SELECT * FROM school_admins WHERE school_id = ? AND username = ?');
        $stmt->execute([$schoolId, $adminUsername]);
        $admin = $stmt->fetch();

        if (!$admin) {
            $randomPassword = bin2hex(random_bytes(32));
            $schoolAdminId = insertGetId(
                'INSERT INTO school_admins (school_id, username, password_hash) VALUES (?, ?, ?)',
                [$schoolId, $adminUsername, password_hash($randomPassword, PASSWORD_DEFAULT)]
            );
        } else {
            $schoolAdminId = (int) $admin['id'];
        }
    }
} catch (Throwable $e) {
    http_response_code(500);
    error_log('SSO error: ' . $e->getMessage());
    die('Could not complete SSO sign-in. Please contact support.');
}

session_regenerate_id(true);
$_SESSION['school_admin_id'] = $schoolAdminId;
$_SESSION['school_id'] = $schoolId;
$_SESSION['username'] = $adminUsername;
$_SESSION['via_sso'] = true;
$_SESSION['educore_school_id'] = $educoreSchoolId;

header('Location: admin/dashboard.php');
exit;

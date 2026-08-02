<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/db/error_handler.php';
require_once __DIR__ . '/db/db.php';

// ============================================================
// sso.php — EduCore single sign-on bridge (plan section 7a).
//
// EduCore generates a short-lived signed token when a school
// admin clicks "Timetable" from within EduCore, and redirects
// here as: sso.php?token=<jwt>
//
// This does NOT share EduCore's database or teacher/room/class
// records - it is purely an identity handoff ("trust that this
// is admin X from school Y"), matching a school_admins row by
// an external reference, then starting a normal PHP session.
//
// IMPORTANT: SSO_SHARED_SECRET below must be set to the same
// value EduCore's backend uses to sign these tokens. Never
// commit a real secret to source control - set it via an
// environment variable or a gitignored config file in
// production. The placeholder here is NOT safe to deploy as-is.
// ============================================================

define('SSO_SHARED_SECRET', getenv('SSO_SHARED_SECRET') ?: 'REPLACE_ME_BEFORE_DEPLOYING');

/**
 * Minimal JWT verification (HS256) - checks signature and
 * expiry only. Does not require a JWT library dependency for
 * this one endpoint.
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
        return null; // signature mismatch - reject
    }

    $payloadJson = base64_decode(strtr($payloadB64, '-_', '+/'));
    $payload = json_decode($payloadJson, true);

    if (!is_array($payload)) {
        return null;
    }
    if (!isset($payload['exp']) || $payload['exp'] < time()) {
        return null; // expired
    }
    if (!isset($payload['educore_school_id'], $payload['educore_admin_username'])) {
        return null; // missing required claims
    }

    return $payload;
}

$token = $_GET['token'] ?? '';

if ($token === '' || SSO_SHARED_SECRET === 'REPLACE_ME_BEFORE_DEPLOYING') {
    http_response_code(400);
    die('SSO is not configured correctly. Contact support.');
}

$payload = verifySsoToken($token, SSO_SHARED_SECRET);

if ($payload === null) {
    http_response_code(401);
    die('This sign-in link is invalid or has expired. Please try again from EduCore.');
}

// Look up (or create) a matching school + school_admin row for this
// EduCore identity. educore_school_id is EduCore's own school ID,
// stored here as a reference - NOT shared data, just enough to map
// "this EduCore school" to "this timetable-app school" consistently.
$stmt = db()->prepare('SELECT * FROM schools WHERE educore_school_id = ?');
$stmt->execute([$payload['educore_school_id']]);
$school = $stmt->fetch();

if (!$school) {
    // First time this EduCore school has used the timetable tool -
    // create a school + admin row automatically, matching the
    // identity handoff rather than requiring manual super-admin setup.
    $stmt = db()->prepare(
        'INSERT INTO schools (name, deployment_type, educore_school_id) VALUES (?, "server-hosted", ?)'
    );
    $stmt->execute([$payload['educore_school_name'] ?? 'EduCore School', $payload['educore_school_id']]);
    $newSchoolId = (int) db()->lastInsertId();

    // Random, never-shown password - this admin only ever signs in
    // via SSO, but school_admins.password_hash is NOT NULL, so a
    // random unusable value satisfies the schema without creating a
    // real, guessable credential.
    $randomPassword = bin2hex(random_bytes(32));
    $stmt = db()->prepare(
        'INSERT INTO school_admins (school_id, username, password_hash) VALUES (?, ?, ?)'
    );
    $stmt->execute([$newSchoolId, $payload['educore_admin_username'], password_hash($randomPassword, PASSWORD_DEFAULT)]);
    $schoolAdminId = (int) db()->lastInsertId();
    $schoolId = $newSchoolId;
} else {
    $schoolId = (int) $school['id'];
    $stmt = db()->prepare('SELECT * FROM school_admins WHERE school_id = ? AND username = ?');
    $stmt->execute([$schoolId, $payload['educore_admin_username']]);
    $admin = $stmt->fetch();

    if (!$admin) {
        $randomPassword = bin2hex(random_bytes(32));
        $stmt = db()->prepare(
            'INSERT INTO school_admins (school_id, username, password_hash) VALUES (?, ?, ?)'
        );
        $stmt->execute([$schoolId, $payload['educore_admin_username'], password_hash($randomPassword, PASSWORD_DEFAULT)]);
        $schoolAdminId = (int) db()->lastInsertId();
    } else {
        $schoolAdminId = (int) $admin['id'];
    }
}

session_regenerate_id(true);
$_SESSION['school_admin_id'] = $schoolAdminId;
$_SESSION['school_id'] = $schoolId;
$_SESSION['username'] = $payload['educore_admin_username'];
$_SESSION['via_sso'] = true;

header('Location: admin/dashboard.php');
exit;

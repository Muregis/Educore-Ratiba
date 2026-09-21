<?php
declare(strict_types=1);

// Load centralized error handler (must be first)
require_once __DIR__ . '/error_handler.php';
require_once __DIR__ . '/../config/config.php';

// ============================================================
// db/db.php — database connection and helper functions
// ============================================================

/**
 * Returns a PDO instance connected to the database.
 * Supports both MySQL and PostgreSQL (Supabase).
 *
 * IMPORTANT for Render + Supabase:
 * 1. Use the Session Pooler host (IPv4), NOT db.xxx.supabase.co (IPv6-only).
 *    Example: aws-1-eu-west-3.pooler.supabase.com
 * 2. Username MUST be: postgres.YOUR_PROJECT_REF
 *    Example: postgres.wofnvdfnsuevnebpkdsw
 *    (plain "postgres" causes: no tenant identifier provided)
 * 3. Port 5432, sslmode=require
 *
 * Copy the exact connection string from:
 * Supabase → Project Settings → Database → Connect → Session pooler
 */
function db(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $host     = Config::get('database.host', 'localhost');
        $dbname   = Config::get('database.name', 'fet_timetable');
        $username = Config::get('database.user', 'root');
        $password = Config::get('database.pass', '');
        $port     = Config::get('database.port', '');
        $driver   = Config::get('database.driver', 'mysql'); // 'mysql' or 'pgsql'

        try {
            if ($driver === 'pgsql') {
                // Supabase shared pooler requires tenant ID in the username:
                //   postgres.PROJECT_REF
                // If the user only set DB_USER=postgres, try to fix it automatically.
                $username = normalizeSupabasePoolerUser($username, $host);

                $portPart = $port !== '' ? "port={$port};" : 'port=5432;';
                $dsn = "pgsql:host={$host};{$portPart}dbname={$dbname};sslmode=require";

                // EMULATE_PREPARES true: Postgres needs this for LIMIT ? OFFSET ?
                // when values are passed via execute([...]) as PHP ints/strings.
                $pdo = new PDO($dsn, $username, $password, [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => true,
                ]);
            } else {
                // MySQL
                $portPart = $port !== '' ? "port={$port};" : '';
                $dsn = "mysql:host={$host};{$portPart}dbname={$dbname};charset=utf8mb4";

                $pdo = new PDO($dsn, $username, $password, [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]);
            }
        } catch (PDOException $e) {
            $msg = $e->getMessage();

            if (stripos($msg, 'Network is unreachable') !== false
                || stripos($msg, 'could not connect') !== false
                || stripos($msg, 'No route to host') !== false) {
                throw new PDOException(
                    "Database connection failed (network unreachable).\n"
                    . "Use the Supabase Session Pooler host (IPv4), not db.xxxxx.supabase.co.\n"
                    . "Original error: " . $msg,
                    (int) $e->getCode(),
                    $e
                );
            }

            if (stripos($msg, 'tenant identifier') !== false
                || stripos($msg, 'ENOIDENTIFIER') !== false
                || stripos($msg, 'Tenant or user not found') !== false) {
                throw new PDOException(
                    "Supabase pooler rejected the connection: missing tenant identifier.\n"
                    . "Set DB_USER to: postgres.YOUR_PROJECT_REF\n"
                    . "Example: postgres.wofnvdfnsuevnebpkdsw\n"
                    . "Find PROJECT_REF in Supabase → Project Settings → General (Reference ID),\n"
                    . "or copy the full Session pooler connection string from the Connect dialog.\n\n"
                    . "Original error: " . $msg,
                    (int) $e->getCode(),
                    $e
                );
            }

            throw $e;
        }
    }

    return $pdo;
}

/**
 * Supabase shared pooler (Supavisor) requires username format:
 *   postgres.PROJECT_REF
 * Plain "postgres" only works on the direct (IPv6) host.
 */
function normalizeSupabasePoolerUser(string $username, string $host): string
{
    // Already has project ref?
    if (str_contains($username, '.')) {
        return $username;
    }

    // Explicit override via env
    $ref = getenv('DB_PROJECT_REF') ?: Config::get('database.project_ref', '');
    if (is_string($ref) && $ref !== '') {
        return $username . '.' . $ref;
    }

    // Only auto-fix when talking to the shared pooler
    if (!str_contains($host, 'pooler.supabase.com')) {
        return $username;
    }

    // Last resort: cannot invent the project ref — leave as-is and let the
    // clearer error message guide the user.
    return $username;
}

// ============================================================
// CSRF Protection
// ============================================================

function getCsrfToken(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrfField(): string
{
    return '<input type="hidden" name="_csrf_token" value="' . htmlspecialchars(getCsrfToken()) . '">';
}

function verifyCsrf(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $submitted = $_POST['_csrf_token'] ?? '';
    $expected  = $_SESSION['csrf_token'] ?? '';
    if ($expected === '' || !hash_equals($expected, $submitted)) {
        http_response_code(403);
        die('<h1>403 Forbidden</h1><p>CSRF token mismatch. Please go back and try again.</p>');
    }
}

// ============================================================
// Login Rate Limiting
// ============================================================

function recordFailedLogin(string $username): bool
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $key = 'login_attempts_' . md5($username);
    $now = time();

    if (!isset($_SESSION[$key])) {
        $_SESSION[$key] = ['count' => 0, 'first' => $now, 'locked_until' => 0];
    }

    $data = &$_SESSION[$key];

    if ($now - $data['first'] > 600) {
        $data = ['count' => 0, 'first' => $now, 'locked_until' => 0];
    }

    $data['count']++;

    if ($data['count'] >= 5) {
        $data['locked_until'] = $now + 60;
        return true;
    }
    return false;
}

function loginLockoutSeconds(string $username): int
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $key = 'login_attempts_' . md5($username);
    if (!isset($_SESSION[$key])) {
        return 0;
    }
    $remaining = max(0, (int)$_SESSION[$key]['locked_until'] - time());
    if ($remaining === 0 && isset($_SESSION[$key]['locked_until']) && $_SESSION[$key]['locked_until'] > 0) {
        unset($_SESSION[$key]);
    }
    return $remaining;
}

function clearFailedLogins(string $username): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    unset($_SESSION['login_attempts_' . md5($username)]);
}

// ============================================================
// Audit Logging
// ============================================================

function logAudit(
    string $action,
    string $entity,
    ?int   $entityId = null,
    ?array $data     = null
): void {
    try {
        $adminId   = $_SESSION['school_admin_id'] ?? $_SESSION['super_admin_id'] ?? null;
        $adminType = isset($_SESSION['super_admin_id']) ? 'super_admin' : 'school_admin';
        $schoolId  = $_SESSION['school_id'] ?? null;

        $stmt = db()->prepare(
            'INSERT INTO audit_log (school_id, admin_id, admin_type, action, entity, entity_id, details, ip_address, user_agent)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $schoolId,
            $adminId,
            $adminType,
            $action,
            $entity,
            $entityId,
            $data !== null ? json_encode($data) : null,
            $_SERVER['REMOTE_ADDR'] ?? null,
            $_SERVER['HTTP_USER_AGENT'] ?? null,
        ]);
    } catch (Throwable) {
        // Silently ignore
    }
}

// ============================================================
// Auth helpers
// ============================================================

function isSuperAdmin(): bool
{
    return isset($_SESSION['super_admin_id']);
}

function requireLoginAndGetSchoolId(): int
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    if (isset($_SESSION['school_admin_id']) && isset($_SESSION['school_id'])) {
        return (int) $_SESSION['school_id'];
    }

    if (isset($_SESSION['super_admin_id']) && isset($_GET['school_id'])) {
        return (int) $_GET['school_id'];
    }

    header('Location: ../login.php');
    exit;
}

// ============================================================
// Data access helpers
// ============================================================

function getTeachers(int $schoolId): array
{
    $stmt = db()->prepare('SELECT * FROM teachers WHERE school_id = ?');
    $stmt->execute([$schoolId]);
    return $stmt->fetchAll();
}

function getRooms(int $schoolId): array
{
    $stmt = db()->prepare('SELECT * FROM rooms WHERE school_id = ?');
    $stmt->execute([$schoolId]);
    return $stmt->fetchAll();
}

function getBands(int $schoolId): array
{
    $stmt = db()->prepare('SELECT * FROM bands WHERE school_id = ?');
    $stmt->execute([$schoolId]);
    return $stmt->fetchAll();
}

function getClasses(int $schoolId): array
{
    $stmt = db()->prepare('SELECT c.*, b.lessons_per_day, b.lesson_length_minutes
                           FROM classes c
                           JOIN bands b ON c.band_id = b.id
                           WHERE c.school_id = ?');
    $stmt->execute([$schoolId]);
    return $stmt->fetchAll();
}

function getSubjectsForClass(int $schoolId, int $classId): array
{
    $stmt = db()->prepare('SELECT * FROM subjects WHERE school_id = ? AND class_id = ?');
    $stmt->execute([$schoolId, $classId]);
    return $stmt->fetchAll();
}

function getTeacherTotalWeeklyLessons(int $schoolId, int $teacherId): int
{
    $stmt = db()->prepare('SELECT SUM(s.lessons_per_week) as total
                           FROM subjects s
                           WHERE s.school_id = ? AND s.assigned_teacher_id = ?');
    $stmt->execute([$schoolId, $teacherId]);
    $result = $stmt->fetch();
    return (int) ($result['total'] ?? 0);
}

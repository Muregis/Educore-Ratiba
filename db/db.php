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
                || stripos($msg, 'ENOIDENTIFIER') !== false) {
                throw new PDOException(
                    "Database connection failed (no tenant identifier).\n"
                    . "Set DB_USER to postgres.YOUR_PROJECT_REF (from Supabase Connect → Session pooler).\n"
                    . "Original error: " . $msg,
                    (int) $e->getCode(),
                    $e
                );
            }

            if (stripos($msg, 'password authentication failed') !== false) {
                throw new PDOException(
                    "Database connection failed (password authentication).\n"
                    . "Reset the DB password in Supabase and update DB_PASS on Render.\n"
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
 * For Supabase shared pooler (*.pooler.supabase.com), the username must be
 * postgres.PROJECT_REF. If the operator set only "postgres", derive the ref
 * from DB_HOST when possible, or from a companion env DB_PROJECT_REF.
 */
function normalizeSupabasePoolerUser(string $username, string $host): string
{
    if (strpos($host, 'pooler.supabase.com') === false) {
        return $username;
    }

    // Already in correct form
    if (strpos($username, '.') !== false) {
        return $username;
    }

    $ref = getenv('DB_PROJECT_REF') ?: getenv('SUPABASE_PROJECT_REF') ?: '';
    if ($ref === '' && preg_match('/^db\.([a-z0-9]+)\.supabase\.co$/i', $host, $m)) {
        $ref = $m[1];
    }
    // Host is pooler — project ref often lives in other config
    if ($ref === '') {
        $ref = (string) (Config::get('database.project_ref', '') ?: '');
    }

    if ($ref !== '' && ($username === 'postgres' || $username === '')) {
        return 'postgres.' . $ref;
    }

    return $username;
}

/**
 * Require login and return the school_id for the current school admin session.
 */
function requireLoginAndGetSchoolId(): int
{
    if (empty($_SESSION['school_admin_id']) || empty($_SESSION['school_id'])) {
        header('Location: ../login.php');
        exit;
    }
    return (int) $_SESSION['school_id'];
}

/**
 * Require super admin login.
 */
function requireSuperAdmin(): void
{
    if (empty($_SESSION['super_admin_id'])) {
        header('Location: ../login.php');
        exit;
    }
}

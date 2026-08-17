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
 * Uses configuration from config.php for hybrid online/offline support.
 */
function db(): PDO
{
    static $pdo = null;
    
    if ($pdo === null) {
        $host = Config::get('database.host', 'localhost');
        $dbname = Config::get('database.name', 'fet_timetable');
        $username = Config::get('database.user', 'root');
        $password = Config::get('database.pass', '');
        $port = Config::get('database.port', '');
        $driver = Config::get('database.driver', 'mysql'); // 'mysql' or 'pgsql'
        
        if ($driver === 'pgsql') {
            // PostgreSQL/Supabase connection
            $dsn = "pgsql:host={$host};" . ($port ? "port={$port};" : '') . "dbname={$dbname}";
            $pdo = new PDO($dsn, $username, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } else {
            // MySQL connection
            $dsn = "mysql:host={$host};" . ($port ? "port={$port};" : '') . "dbname={$dbname};charset=utf8mb4";
            $pdo = new PDO($dsn, $username, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        }
    }
    
    return $pdo;
}

// ============================================================
// CSRF Protection
// ============================================================

/**
 * Generates (or retrieves) a CSRF token for the current session.
 * Call once per session; the same token is reused.
 */
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

/**
 * Outputs a hidden CSRF input field ready to embed in any form.
 */
function csrfField(): string
{
    return '<input type="hidden" name="_csrf_token" value="' . htmlspecialchars(getCsrfToken()) . '">';
}

/**
 * Verifies the CSRF token in $_POST. Terminates with 403 if invalid.
 * Call at the top of any POST handler.
 */
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

/**
 * Records a failed login attempt. Returns true if the account
 * should be temporarily locked (≥5 failures in 10 minutes).
 */
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

    // Reset window after 10 minutes
    if ($now - $data['first'] > 600) {
        $data = ['count' => 0, 'first' => $now, 'locked_until' => 0];
    }

    $data['count']++;

    if ($data['count'] >= 5) {
        $data['locked_until'] = $now + 60; // lock for 60 seconds
        return true;
    }
    return false;
}

/**
 * Returns seconds remaining on lockout (0 = not locked).
 */
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
        // Lock expired — reset
        unset($_SESSION[$key]);
    }
    return $remaining;
}

/**
 * Clears failed-login counter on a successful login.
 */
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

/**
 * Records an admin action to the audit_log table.
 * Silently fails if the table doesn't exist yet (pre-migration).
 */
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
            'INSERT INTO audit_log (school_id, admin_id, admin_type, action, entity, entity_id, data_json, ip_address)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
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
        ]);
    } catch (Throwable) {
        // Silently ignore — audit log is non-critical
    }
}

// ============================================================
// Auth helpers
// ============================================================

/**
 * Checks if the current session is a super-admin.
 */
function isSuperAdmin(): bool
{
    return isset($_SESSION['super_admin_id']);
}

/**
 * Returns the school_id from the session, or redirects to login if not authenticated.
 */
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
// Data access helpers — used by generate_engine.php and other pages
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

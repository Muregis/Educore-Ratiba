<?php
declare(strict_types=1);

require_once __DIR__ . '/error_handler.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/SchoolConfig.php';

require_once __DIR__ . '/localization.php';
initializeKenyanLocalization();

function db(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $host     = Config::get('database.host', 'localhost');
        $dbname   = Config::get('database.name', 'fet_timetable');
        $username = Config::get('database.user', 'root');
        $password = Config::get('database.pass', '');
        $port     = Config::get('database.port', '');
        $driver   = Config::get('database.driver', 'mysql');

        try {
            if ($driver === 'pgsql') {
                $username = normalizeSupabasePoolerUser($username, $host);

                $portPart = $port !== '' ? "port={$port};" : 'port=5432;';
                $dsn = "pgsql:host={$host};{$portPart}dbname={$dbname};sslmode=require";

                $pdo = new PDO($dsn, $username, $password, [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => true,
                ]);
                $pdo->exec("SET TIME ZONE 'Africa/Nairobi'");
            } else {
                $portPart = $port !== '' ? "port={$port};" : '';
                $resolvedHost = ($port !== '' && $host === 'localhost') ? '127.0.0.1' : $host;
                $dsn = "mysql:host={$resolvedHost};{$portPart}dbname={$dbname};charset=utf8mb4";

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

function normalizeSupabasePoolerUser(string $username, string $host): string
{
    if (str_contains($username, '.')) {
        return $username;
    }

    $ref = getenv('DB_PROJECT_REF') ?: Config::get('database.project_ref', '');
    if (is_string($ref) && $ref !== '') {
        return $username . '.' . $ref;
    }

    if (!str_contains($host, 'pooler.supabase.com')) {
        return $username;
    }

    return $username;
}

require_once __DIR__ . '/session_csrf.php';

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
    }
}

function isSuperAdmin(): bool
{
    return isset($_SESSION['super_admin_id']);
}

function requireLoginAndGetSchoolId(): int
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    // Enforce CSRF on state-changing requests for authenticated admins
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && function_exists('verifyCsrf')) {
        verifyCsrf();
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

/**
 * Calculates a numeric level score for a class to sort by educational progression
 * rather than alphabetical order.
 *
 * Hierarchy:
 * - Playgroup / Daycare / Creche / Baby Class: 0.1
 * - Pre-Primary 1 (PP1 / Nursery / KG1): 0.2
 * - Pre-Primary 2 (PP2 / Kindergarten / KG2): 0.3
 * - Primary (Grade 1 - 6): 1.0 - 6.0
 * - Junior School (Grade 7 - 9 / Form 1 - 3): 7.0 - 9.0
 * - Senior School (Grade 10 - 12 / Form 4): 10.0 - 12.0
 * - British International (Year 1 - 13): 1.0 - 13.0
 * - TVET / Tertiary: 15.0 - 19.0
 * - University (Y1S1, Y1S2, Y2, Y3, Y4): 20.0+
 */
function classLevelScore(string $name, ?string $bandKey = null): float
{
    $n = trim($name);
    $lower = strtolower($n);

    // 1. Pre-Primary keywords
    if (preg_match('/\b(playgroup|daycare|creche|baby)\b/i', $lower)) {
        return 0.1;
    }
    if (preg_match('/\b(pp\s*1|pre[\s\-]?primary\s*1|nursery|kg\s*1)\b/i', $lower)) {
        return 0.2;
    }
    if (preg_match('/\b(pp\s*2|pre[\s\-]?primary\s*2|kindergarten|kg\s*2|reception)\b/i', $lower)) {
        return 0.3;
    }

    // 2. Numeric Grade / Class / Standard / Year / Form
    if (preg_match('/\b(?:grade|class|standard|std|year|yr|form|f)\s*([0-9]{1,2})\b/i', $lower, $m)) {
        $num = (int) $m[1];
        if (preg_match('/\b(?:form|f)\s*([0-9]{1,2})\b/i', $lower)) {
            return 8.0 + $num; // Form 1 -> 9, Form 4 -> 12
        }
        return (float) $num;
    }

    // 3. Standalone G-prefix (e.g. G7, G10)
    if (preg_match('/\bg([0-9]{1,2})\b/i', $lower, $m)) {
        return (float) $m[1];
    }

    // 4. University semester patterns (e.g. Y1S1, Y2S2, Year 1)
    if (preg_match('/\by([1-6])s([1-3])\b/i', $lower, $m)) {
        return 20.0 + (int) $m[1] + ((int) $m[2] * 0.1);
    }
    if (preg_match('/\b(?:year|y)\s*([1-6])\b/i', $lower, $m)) {
        return 20.0 + (int) $m[1];
    }

    // 5. Band-based base score if available
    if ($bandKey !== null && $bandKey !== '') {
        $bandBase = [
            'pp1-pp2' => 0.2,
            'cbc-lower' => 1.0,
            'grade-1-3' => 1.0,
            'cbc-upper' => 4.0,
            'grade-4-6' => 4.0,
            'cbc-jss' => 7.0,
            'grade-7-9' => 7.0,
            'grade-10-12' => 10.0,
            'igcse-gcse' => 9.0,
            'tertiary' => 15.0,
            'university' => 20.0,
        ];
        if (isset($bandBase[$bandKey])) {
            return $bandBase[$bandKey];
        }
    }

    // 6. Standalone digits anywhere in the name
    if (preg_match('/([0-9]{1,2})/', $lower, $m)) {
        return (float) $m[1];
    }

    return 50.0;
}

/**
 * Compares two class records or objects by educational level progression.
 */
function compareClassesByLevel(array $a, array $b): int
{
    $scoreA = classLevelScore((string) ($a['name'] ?? ''), (string) ($a['band_key'] ?? ''));
    $scoreB = classLevelScore((string) ($b['name'] ?? ''), (string) ($b['band_key'] ?? ''));

    if ($scoreA < $scoreB) return -1;
    if ($scoreA > $scoreB) return 1;

    // Equal level (e.g. Grade 7 East vs Grade 7 West) -> Natural alphabetical sort
    return strnatcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
}

/**
 * Sorts an array of class records in-place by educational level progression.
 *
 * @param array<int, array<string, mixed>> $classes
 */
function sortClassesByLevel(array &$classes): void
{
    usort($classes, 'compareClassesByLevel');
}

/**
 * Sorts an array of records having 'class_name' (subjects, activities, remedials)
 * by class level progression, then class name, then item name.
 *
 * @param array<int, array<string, mixed>> $items
 */
function sortItemsByClassLevel(array &$items): void
{
    usort($items, static function(array $a, array $b): int {
        $classNameA = (string) ($a['class_name'] ?? '');
        $classNameB = (string) ($b['class_name'] ?? '');
        $scoreA = classLevelScore($classNameA);
        $scoreB = classLevelScore($classNameB);

        if ($scoreA < $scoreB) return -1;
        if ($scoreA > $scoreB) return 1;

        $classCmp = strnatcasecmp($classNameA, $classNameB);
        if ($classCmp !== 0) return $classCmp;

        return strnatcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
    });
}

function getClasses(int $schoolId): array
{
    $stmt = db()->prepare('SELECT c.*, b.lessons_per_day, b.lesson_length_minutes, b.band_key, b.label AS band_label
                           FROM classes c
                           JOIN bands b ON c.band_id = b.id
                           WHERE c.school_id = ?');
    $stmt->execute([$schoolId]);
    $classes = $stmt->fetchAll();
    sortClassesByLevel($classes);
    return $classes;
}

function getSubjectsForClass(int $schoolId, int $classId): array
{
    $stmt = db()->prepare('SELECT * FROM subjects WHERE school_id = ? AND class_id = ?');
    $stmt->execute([$schoolId, $classId]);
    return $stmt->fetchAll();
}

function getTeacherTotalWeeklyLessons(int $schoolId, int $teacherId): int
{
    try {
        $stmt = db()->prepare(
            'SELECT SUM(s.lessons_per_week * COALESCE(s.duration_slots, 1)) as total
             FROM subjects s
             WHERE s.school_id = ? AND s.assigned_teacher_id = ?'
        );
        $stmt->execute([$schoolId, $teacherId]);
        $result = $stmt->fetch();
        return (int) ($result['total'] ?? 0);
    } catch (Throwable) {
        $stmt = db()->prepare(
            'SELECT SUM(s.lessons_per_week) as total
             FROM subjects s
             WHERE s.school_id = ? AND s.assigned_teacher_id = ?'
        );
        $stmt->execute([$schoolId, $teacherId]);
        $result = $stmt->fetch();
        return (int) ($result['total'] ?? 0);
    }
}

require_once __DIR__ . '/school_flexibility.php';

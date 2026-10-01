<?php
declare(strict_types=1);
/**
 * Engine + DB health for school admins / ops.
 * Shows why Generate fails when data readiness is already 100%.
 */
session_start();
require_once __DIR__ . '/../db/db.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/generate_engine.php';

header('Content-Type: text/plain; charset=utf-8');

try {
    $schoolId = requireLoginAndGetSchoolId();
} catch (Throwable $e) {
    http_response_code(401);
    echo "Auth required\n";
    exit;
}

echo "EduCore Ratiba Diagnostic\n";
echo str_repeat('=', 48) . "\n\n";

$pdo = db();
echo "DB driver: " . $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) . "\n";
echo "PHP: " . PHP_VERSION . " · OS: " . PHP_OS_FAMILY . "\n";
echo "max_execution_time: " . ini_get('max_execution_time') . "\n";
echo "disable_functions: " . (ini_get('disable_functions') ?: '(none)') . "\n";
echo "shell_exec: " . (function_exists('shell_exec') && !in_array('shell_exec', array_map('trim', explode(',', (string) ini_get('disable_functions'))), true) ? 'available' : 'BLOCKED') . "\n";
echo "proc_open: " . (function_exists('proc_open') && !in_array('proc_open', array_map('trim', explode(',', (string) ini_get('disable_functions'))), true) ? 'available' : 'BLOCKED') . "\n\n";

$configured = (string) Config::get('paths.engine');
$resolved = function_exists('resolveFetEnginePath') ? resolveFetEnginePath($configured) : $configured;
echo "Configured engine path: $configured\n";
echo "Resolved engine path:   $resolved\n";
echo "file_exists: " . (file_exists($resolved) ? 'yes' : 'NO') . "\n";
echo "is_executable: " . (is_executable($resolved) ? 'yes' : 'NO') . "\n";
if (is_link($resolved) || is_link($configured)) {
    echo "symlink target: " . (readlink($resolved) ?: readlink($configured) ?: '?') . "\n";
}

$versionOut = '(not run)';
if (file_exists($resolved) && is_executable($resolved) && function_exists('runCommandCapture')) {
    $cap = runCommandCapture($resolved, ['--version']);
    $versionOut = trim($cap['output']) !== '' ? trim($cap['output']) : '(empty)';
    if (mb_strlen($versionOut) > 400) {
        $versionOut = mb_substr($versionOut, 0, 400) . '…';
    }
    echo "fet-cl --version:\n$versionOut\n";
    echo "version runner: {$cap['method']}" . ($cap['exit_code'] !== null ? " exit={$cap['exit_code']}" : '') . "\n";
} else {
    echo "fet-cl --version: SKIPPED (binary missing or not executable)\n";
}

$outputDir = (string) Config::get('paths.output');
echo "\nOutput dir: $outputDir\n";
echo "output writable: " . (is_dir($outputDir) && is_writable($outputDir) ? 'yes' : 'NO') . "\n";

echo "\nSchool data (id=$schoolId)\n";
try {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM teachers WHERE school_id = ?');
    $stmt->execute([$schoolId]);
    echo "Teachers: " . $stmt->fetchColumn() . "\n";

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM rooms WHERE school_id = ?');
    $stmt->execute([$schoolId]);
    echo "Rooms: " . $stmt->fetchColumn() . "\n";

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM bands WHERE school_id = ?');
    $stmt->execute([$schoolId]);
    echo "Bands: " . $stmt->fetchColumn() . "\n";

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM classes WHERE school_id = ? AND active = TRUE');
    $stmt->execute([$schoolId]);
    echo "Active classes: " . $stmt->fetchColumn() . "\n";

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM subjects WHERE school_id = ?');
    $stmt->execute([$schoolId]);
    echo "Subjects: " . $stmt->fetchColumn() . "\n";

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM subjects WHERE school_id = ? AND assigned_teacher_id IS NULL');
    $stmt->execute([$schoolId]);
    echo "Unassigned subjects: " . $stmt->fetchColumn() . "\n";

    echo "\nOK\n";
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}

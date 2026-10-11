<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/../db/db.php';
require_once __DIR__ . '/../db/session_csrf.php';
require_once __DIR__ . '/../db/audit_log.php';
require_once __DIR__ . '/kicd_seeder_service.php';

$schoolId = requireLoginAndGetSchoolId();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: settings.php');
    exit;
}

verifyCsrf();

try {
    $adminId = isset($_SESSION['admin_id']) ? (int) $_SESSION['admin_id'] : null;
    $res = seedKicdDemoData($schoolId, $adminId);

    if ($res['success']) {
        logAudit('seed', 'kicd_demo', $schoolId, [
            'slots_count' => $res['slots_count'],
            'admin_id' => $adminId
        ]);
        $_SESSION['flash_success'] = '✨ ' . $res['message'];
        header('Location: view_timetable.php');
        exit;
    } else {
        $_SESSION['flash_error'] = 'Failed to seed KICD demo data: ' . $res['message'];
        header('Location: settings.php');
        exit;
    }
} catch (Throwable $e) {
    $_SESSION['flash_error'] = 'Exception during KICD seeding: ' . $e->getMessage();
    header('Location: settings.php');
    exit;
}

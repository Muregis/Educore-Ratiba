<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../db/db.php';

$schoolId = requireLoginAndGetSchoolId();
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (function_exists('verifyCsrf')) { verifyCsrf(); }
}

$actions = [];
if (function_exists('rebalanceSchoolForSolvability')) {
    $actions = rebalanceSchoolForSolvability($schoolId);
} else {
    $actions[] = 'Rebalance helper missing.';
}

$pdo = db();
$bands = $pdo->prepare('SELECT id, break_config FROM bands WHERE school_id = ?');
$bands->execute([$schoolId]);
$defaultBreaks = json_encode([
    ['time' => '10:00-10:20', 'label' => 'BREAK'],
    ['time' => '12:40-13:20', 'label' => 'LUNCH'],
]);
$updated = 0;
foreach ($bands->fetchAll() as $b) {
    $bc = $b['break_config'] ?? null;
    if (is_string($bc)) {
        $bc = json_decode($bc, true);
    }
    if (!is_array($bc) || $bc === []) {
        try {
            $pdo->prepare('UPDATE bands SET break_config = ?, day_start_time = COALESCE(day_start_time, ?) WHERE id = ? AND school_id = ?')
                ->execute([$defaultBreaks, '08:00', (int)$b['id'], $schoolId]);
            $updated++;
        } catch (Throwable $e) {
            error_log('band break seed: ' . $e->getMessage());
        }
    }
}
if ($updated > 0) {
    $actions[] = "Set default tea + lunch breaks on {$updated} band(s).";
}

$classes = $pdo->prepare('SELECT id, name FROM classes WHERE school_id = ? AND active = TRUE');
$classes->execute([$schoolId]);
foreach ($classes->fetchAll() as $c) {
    $cnt = $pdo->prepare('SELECT COUNT(*) FROM subjects WHERE school_id = ? AND class_id = ?');
    $cnt->execute([$schoolId, (int)$c['id']]);
    if ((int)$cnt->fetchColumn() === 0) {
        $pdo->prepare('UPDATE classes SET active = FALSE WHERE id = ? AND school_id = ?')
            ->execute([(int)$c['id'], $schoolId]);
        $actions[] = 'Deactivated empty class "' . $c['name'] . '".';
    }
}

header('Content-Type: application/json');
echo json_encode(['ok' => true, 'actions' => $actions], JSON_PRETTY_PRINT);

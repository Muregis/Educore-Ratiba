<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../db/db.php';
require_once __DIR__ . '/generate_engine.php';

header('Content-Type: application/json');
$schoolId = requireLoginAndGetSchoolId();
if (function_exists('verifyCsrf')) { verifyCsrf(); }

$stmt = db()->prepare("SELECT * FROM generated_timetables WHERE school_id = ? AND status = 'success' ORDER BY generated_at DESC LIMIT 1");
$stmt->execute([$schoolId]);
$latest = $stmt->fetch();
if (!$latest) {
    echo json_encode(['ok' => false, 'error' => 'No generated timetable to edit.']);
    exit;
}
$ttId = (int) $latest['id'];
$action = $_POST['action'] ?? '';
$adminId = (int) ($_SESSION['school_admin_id'] ?? $_SESSION['super_admin_id'] ?? 0);

function clashExcluding(int $ttId, array $excludeIds, int $teacherId, ?int $roomId, int $classId, string $day, string $hour): ?string
{
    $excludeIds = array_values(array_unique(array_map('intval', $excludeIds))) ?: [0];
    $ph = implode(',', array_fill(0, count($excludeIds), '?'));
    $params = array_merge($excludeIds, [$ttId, $day, $hour]);
    $stmt = db()->prepare(
        "SELECT ss.*, t.name AS teacher_name, c.name AS class_name, r.name AS room_name
         FROM scheduled_slots ss
         LEFT JOIN teachers t ON ss.teacher_id = t.id
         LEFT JOIN classes c ON ss.class_id = c.id
         LEFT JOIN rooms r ON ss.room_id = r.id
         WHERE ss.id NOT IN ($ph)
           AND ss.generated_timetable_id = ?
           AND ss.day_of_week = ?
           AND ss.hour_slot = ?"
    );
    $stmt->execute($params);
    foreach ($stmt->fetchAll() as $row) {
        if ((int) $row['teacher_id'] === $teacherId) {
            return ($row['teacher_name'] ?? 'Teacher') . ' is already teaching ' . ($row['class_name'] ?? 'a class') . ' at this time.';
        }
        if ($roomId !== null && (int) ($row['room_id'] ?? 0) === $roomId) {
            return ($row['room_name'] ?? 'Room') . ' is already in use.';
        }
        if ((int) $row['class_id'] === $classId) {
            return ($row['class_name'] ?? 'Class') . ' already has a lesson at this time.';
        }
    }
    return null;
}

if ($action === 'move_slot') {
    $slotId = (int) ($_POST['slot_id'] ?? 0);
    $newDay = (string) ($_POST['new_day'] ?? '');
    $newHour = (string) ($_POST['new_hour_slot'] ?? '');
    $stmt = db()->prepare('SELECT * FROM scheduled_slots WHERE id = ? AND generated_timetable_id = ?');
    $stmt->execute([$slotId, $ttId]);
    $slot = $stmt->fetch();
    if (!$slot) {
        echo json_encode(['ok' => false, 'error' => 'Slot not found.']);
        exit;
    }
    $clash = clashExcluding(
        $ttId,
        [$slotId],
        (int) $slot['teacher_id'],
        $slot['room_id'] !== null ? (int) $slot['room_id'] : null,
        (int) $slot['class_id'],
        $newDay,
        $newHour
    );
    if ($clash) {
        echo json_encode(['ok' => false, 'error' => $clash]);
        exit;
    }
    if (function_exists('applySlotMove')) {
        applySlotMove($slotId, $newDay, $newHour, $adminId);
    } else {
        db()->prepare(
            'UPDATE scheduled_slots SET day_of_week=?, hour_slot=?, is_manual_override=TRUE, edited_by_admin_id=? WHERE id=?'
        )->execute([$newDay, $newHour, $adminId, $slotId]);
    }
    echo json_encode(['ok' => true]);
    exit;
}

if ($action === 'swap_slots') {
    $id1 = (int) ($_POST['slot_id_1'] ?? 0);
    $id2 = (int) ($_POST['slot_id_2'] ?? 0);
    $stmt = db()->prepare('SELECT * FROM scheduled_slots WHERE id IN (?,?) AND generated_timetable_id = ?');
    $stmt->execute([$id1, $id2, $ttId]);
    $slots = $stmt->fetchAll();
    if (count($slots) !== 2) {
        echo json_encode(['ok' => false, 'error' => 'Both slots not found.']);
        exit;
    }
    $s1 = $s2 = null;
    foreach ($slots as $s) {
        if ((int) $s['id'] === $id1) {
            $s1 = $s;
        }
        if ((int) $s['id'] === $id2) {
            $s2 = $s;
        }
    }
    if (!$s1 || !$s2) {
        echo json_encode(['ok' => false, 'error' => 'Both slots not found.']);
        exit;
    }
    $c1 = clashExcluding(
        $ttId,
        [$id1, $id2],
        (int) $s1['teacher_id'],
        $s1['room_id'] !== null ? (int) $s1['room_id'] : null,
        (int) $s1['class_id'],
        (string) $s2['day_of_week'],
        (string) $s2['hour_slot']
    );
    if ($c1) {
        echo json_encode(['ok' => false, 'error' => $c1]);
        exit;
    }
    $c2 = clashExcluding(
        $ttId,
        [$id1, $id2],
        (int) $s2['teacher_id'],
        $s2['room_id'] !== null ? (int) $s2['room_id'] : null,
        (int) $s2['class_id'],
        (string) $s1['day_of_week'],
        (string) $s1['hour_slot']
    );
    if ($c2) {
        echo json_encode(['ok' => false, 'error' => $c2]);
        exit;
    }
    // 3-step swap to avoid unique-index collision
    $tmpDay = '__swap_tmp__';
    $tmpHour = '__swap_tmp__';
    db()->prepare('UPDATE scheduled_slots SET day_of_week=?, hour_slot=?, is_manual_override=TRUE, edited_by_admin_id=? WHERE id=?')
        ->execute([$tmpDay, $tmpHour, $adminId, $id1]);
    db()->prepare('UPDATE scheduled_slots SET day_of_week=?, hour_slot=?, is_manual_override=TRUE, edited_by_admin_id=? WHERE id=?')
        ->execute([(string) $s1['day_of_week'], (string) $s1['hour_slot'], $adminId, $id2]);
    db()->prepare('UPDATE scheduled_slots SET day_of_week=?, hour_slot=?, is_manual_override=TRUE, edited_by_admin_id=? WHERE id=?')
        ->execute([(string) $s2['day_of_week'], (string) $s2['hour_slot'], $adminId, $id1]);
    echo json_encode(['ok' => true]);
    exit;
}

echo json_encode(['ok' => false, 'error' => 'Unknown action.']);

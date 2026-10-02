<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../db/db.php';

if (!function_exists('formatHourSlotLabel')) {
    function formatHourSlotLabel(string $hour): string
    {
        $h = preg_replace('/^[^_]*__/', '', $hour) ?? $hour;
        $h = trim((string) $h);
        return $h !== '' ? $h : $hour;
    }
}

$brand_name = 'EduCore Ratiba';

$vendorAutoload = __DIR__ . '/../vendor/autoload.php';
if (!file_exists($vendorAutoload)) {
    http_response_code(500);
    echo "<h3>PDF export isn't set up yet.</h3><p>Run this once in the project folder:</p><pre>composer require dompdf/dompdf</pre>";
    exit;
}
require $vendorAutoload;

use Dompdf\Dompdf;
use Dompdf\Options;

$schoolId = requireLoginAndGetSchoolId();
$stmt = db()->prepare('SELECT * FROM schools WHERE id = ?');
$stmt->execute([$schoolId]);
$school = $stmt->fetch();

$scope = $_GET['scope'] ?? 'whole-school';
$classId = ($_GET['class_id'] ?? '') !== '' ? (int) $_GET['class_id'] : null;
$teacherId = ($_GET['teacher_id'] ?? '') !== '' ? (int) $_GET['teacher_id'] : null;

$stmt = db()->prepare(
    "SELECT * FROM generated_timetables WHERE school_id = ? AND status = 'success' ORDER BY generated_at DESC LIMIT 1"
);
$stmt->execute([$schoolId]);
$latestGeneration = $stmt->fetch();

if (!$latestGeneration) {
    http_response_code(404);
    echo '<h3>No timetable has been generated yet.</h3><p>Go back and generate one first.</p>';
    exit;
}

$params = [$latestGeneration['id']];
$classFilter = '';
$groupBy = 'class_name';
$titleScope = 'Whole-School Timetable';

if ($scope === 'class' && $classId !== null) {
    $classFilter = ' AND scheduled_slots.class_id = ?';
    $params[] = $classId;
    $titleScope = 'Class Timetable';
} elseif ($scope === 'teacher' && $teacherId !== null) {
    $classFilter = ' AND scheduled_slots.teacher_id = ?';
    $params[] = $teacherId;
    $groupBy = 'teacher_name';
    $titleScope = 'Teacher Timetable';
}

$stmt = db()->prepare(
    "SELECT scheduled_slots.*, classes.name AS class_name,
            subjects.name AS subject_name, extra_activities.name AS activity_name,
            teachers.name AS teacher_name, rooms.name AS room_name
     FROM scheduled_slots
     JOIN classes ON scheduled_slots.class_id = classes.id
     LEFT JOIN subjects ON scheduled_slots.subject_id = subjects.id
     LEFT JOIN extra_activities ON scheduled_slots.extra_activity_id = extra_activities.id
     LEFT JOIN teachers ON scheduled_slots.teacher_id = teachers.id
     LEFT JOIN rooms ON scheduled_slots.room_id = rooms.id
     WHERE scheduled_slots.generated_timetable_id = ?{$classFilter}
     ORDER BY classes.name, scheduled_slots.day_of_week, scheduled_slots.hour_slot"
);
$stmt->execute($params);
$rows = $stmt->fetchAll();

if (empty($rows)) {
    http_response_code(404);
    echo '<h3>No scheduled lessons found for this selection.</h3>';
    echo '<p><a href="../admin/generate.php">Try generating again</a>.</p>';
    exit;
}

$byGroup = [];
foreach ($rows as $r) {
    $groupKey = $r[$groupBy] ?? 'Unknown';
    $byGroup[$groupKey][] = $r;
}

$days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
if (function_exists('getSchoolDayNames')) {
    $dn = getSchoolDayNames($schoolId);
    if (count($dn) >= 1) {
        $days = $dn;
    }
}

if ($scope === 'teacher' && $teacherId !== null) {
    $teacherName = array_key_first($byGroup);
    $title = $teacherName . ' — Timetable';
} elseif ($scope === 'class' && count($byGroup) === 1) {
    $title = array_key_first($byGroup) . ' — Timetable';
} else {
    $title = 'Whole-School Timetable';
}

$html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>
    body { font-family: DejaVu Sans, sans-serif; font-size: 8.5px; }
    .pdf-header { text-align: center; margin-bottom: 12px; }
    .pdf-header h1 { font-size: 14px; margin: 0 0 3px; }
    .pdf-header p { font-size: 8px; color: #555; margin: 0; }
    .class-block { page-break-before: always; }
    .class-block:first-of-type { page-break-before: auto; }
    .class-block caption { font-size: 11px; font-weight: bold; margin-bottom: 4px; text-align: left; }
    table { border-collapse: collapse; width: 100%; table-layout: fixed; margin-top: 6px; }
    th, td { border: 1px solid #333; padding: 4px; font-size: 8px; text-align: center; vertical-align: top; word-wrap: break-word; }
    th { background: #e8e8e8; font-weight: bold; }
    th.yAxis { width: 14%; text-align: left; }
    .time-cell { text-align: left; font-size: 7.5px; }
    .subject-cell { text-align: left; }
    .subject-cell .subject-name { font-weight: bold; font-size: 8.5px; }
    .subject-cell .class-name { font-size: 7.5px; color: #555; }
    .subject-cell .room-name { font-size: 7px; color: #555; }
    .empty-cell { background: #fafafa; }
</style></head><body>';

$html .= '<div class="pdf-header"><h1>' . htmlspecialchars($brand_name) . '</h1>';
$html .= '<p>' . htmlspecialchars($school['name']) . ' — ' . htmlspecialchars($title) . ' — generated '
    . htmlspecialchars(date('j M Y', strtotime($latestGeneration['generated_at']))) . '</p></div>';

foreach ($byGroup as $groupName => $groupRows) {
    $anchor = preg_replace('/[^a-z0-9]+/i', '_', $groupName);
    $grid = [];
    $allTimeSlots = [];
    foreach ($groupRows as $r) {
        $day = $r['day_of_week'];
        $slot = $r['hour_slot'];
        if ($day === null || $slot === null || $slot === '') continue;
        $allTimeSlots[(string) $slot] = true;
        $label = $r['subject_name'] ?? $r['activity_name'] ?? null;
        $class = $r['class_name'] ?? null;
        $room = $r['room_name'] ?? null;
        if ($label === null && $class === null && $room === null) continue;
        $cellParts = [];
        if ($label !== null) $cellParts[] = '<div class="subject-name">' . htmlspecialchars($label) . '</div>';
        if ($scope === 'teacher' && $class !== null) $cellParts[] = '<div class="class-name">' . htmlspecialchars($class) . '</div>';
        if ($room !== null) $cellParts[] = '<div class="room-name">' . htmlspecialchars($room) . '</div>';
        $grid[$day][$slot] = implode('', $cellParts);
    }

    uksort($allTimeSlots, static function ($a, $b) {
        $ta = formatHourSlotLabel((string) $a);
        $tb = formatHourSlotLabel((string) $b);
        if (preg_match('/(\d{2}:\d{2})/', $ta, $ma)) $ta = $ma[1];
        if (preg_match('/(\d{2}:\d{2})/', $tb, $mb)) $tb = $mb[1];
        return strcmp($ta, $tb);
    });
    $allTimeSlots = array_keys($allTimeSlots);

    $html .= '<div class="class-block" id="' . $anchor . '">';
    $html .= '<table>';
    $captionLabel = $scope === 'teacher' ? 'Teacher' : 'Class';
    $html .= '<caption><span class="institution">' . htmlspecialchars($captionLabel) . ':</span> ' . htmlspecialchars($groupName) . '</caption>';
    $html .= '<thead><tr><th></th>';
    foreach ($days as $day) {
        $html .= '<th class="xAxis">' . htmlspecialchars($day) . '</th>';
    }
    $html .= '</tr></thead><tbody>';

    foreach ($allTimeSlots as $slot) {
        $html .= '<tr>';
        $html .= '<th class="yAxis time-cell">' . htmlspecialchars(formatHourSlotLabel((string) $slot)) . '</th>';
        foreach ($days as $day) {
            if (isset($grid[$day][$slot])) {
                $html .= '<td class="subject-cell">' . $grid[$day][$slot] . '</td>';
            } else {
                $html .= '<td class="empty-cell">---</td>';
            }
        }
        $html .= '</tr>';
    }
    $html .= '</tbody></table></div>';
}

$html .= '</body></html>';

$options = new Options();
$options->set('isRemoteEnabled', false);
$options->set('defaultFont', 'DejaVu Sans');
$dompdf = new Dompdf($options);
$dompdf->setPaper('A3', 'landscape');
$dompdf->loadHtml($html);
$dompdf->render();
$scopeLabel = $scope === 'teacher' && $teacherId !== null ? 'teacher-' . (int) $teacherId : 'timetable';
$filename = 'timetable_' . $scopeLabel . '_' . date('Ymd_His') . '.pdf';
$dompdf->stream($filename, ['Attachment' => true]);

<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../db/db.php';

// ============================================================
// export/export.php — PDF export matching FET timetable format
// (plan section 3a). Reads from scheduled_slots first; if that
// table is empty, falls back to parsing FET's own HTML timetable
// output so the PDF always works even when solution-XML parsing
// hasn't been wired up yet.
// ============================================================

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

$useFallback = false;
if (empty($rows) && !empty($latestGeneration['html_output_path'])) {
    $useFallback = true;
    $htmlFullPath = 'C:/laragon/www/fet-timetable/' . ltrim($latestGeneration['html_output_path'], '/');

    // The stored path points to _index.html (a TOC), not the actual
    // timetable grid. Follow the "Years / Days Horizontal" link to the
    // real timetable file.
    $timetableHtmlPath = resolveFetchTimetableHtml($htmlFullPath);
    if ($timetableHtmlPath !== null) {
        $rows = parseFetchHtmlTimetable($timetableHtmlPath, $schoolId, $classId);
    }
}

if (empty($rows)) {
    http_response_code(404);
    echo '<h3>No scheduled lessons found for this selection.</h3>';
    echo '<p>The timetable was generated, but we could not extract any scheduled lessons.</p>';
    echo '<p><a href="generate.php">Try generating again</a> or <a href="javascript:history.back()">go back</a>.</p>';
    exit;
}

// Group by class or teacher
$byGroup = [];
foreach ($rows as $r) {
    $groupKey = $r[$groupBy] ?? 'Unknown';
    $byGroup[$groupKey][] = $r;
}

$dayOrder = ['Monday' => 1, 'Tuesday' => 2, 'Wednesday' => 3, 'Thursday' => 4, 'Friday' => 5];
$days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];

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
    .toc { margin-bottom: 16px; }
    .toc h2 { font-size: 11px; margin: 0 0 6px; }
    .toc ul { margin: 0; padding-left: 20px; }
    .toc li { font-size: 9px; margin-bottom: 2px; }
    .class-block { page-break-before: always; }
    .class-block:first-of-type { page-break-before: auto; }
    .class-block caption { font-size: 11px; font-weight: bold; margin-bottom: 4px; text-align: left; }
    .class-block caption .institution { font-weight: bold; }
    table { border-collapse: collapse; width: 100%; table-layout: fixed; margin-top: 6px; }
    th, td { border: 1px solid #333; padding: 4px; font-size: 8px; text-align: center; vertical-align: top; word-wrap: break-word; }
    th { background: #e8e8e8; font-weight: bold; }
    th.yAxis { width: 14%; text-align: left; }
    td.xAxis { background: #f0f0f0; font-weight: bold; }
    .time-cell { text-align: left; font-size: 7.5px; }
    .subject-cell { text-align: left; }
    .subject-cell .subject-name { font-weight: bold; font-size: 8.5px; }
    .subject-cell .teacher-name { font-size: 7.5px; color: #333; }
    .subject-cell .room-name { font-size: 7px; color: #555; }
    .break-cell { background: #f5f5f5; color: #888; }
    .empty-cell { background: #fafafa; }
    .override { background: #fffbe6; }
    .fallback-notice { background: #fff3cd; border: 1px solid #ffc107; padding: 8px; margin-bottom: 12px; font-size: 9px; }
    .class-name { font-size: 7.5px; color: #555; }
</style></head><body>';

if ($useFallback) {
    $html .= '<div class="fallback-notice">⚠️ Showing timetable from FET HTML output (scheduled_slots is empty). Regenerate after fixing solution parsing for editable slots.</div>';
}

// Header
$html .= '<div class="pdf-header"><h1>' . htmlspecialchars($brand_name) . '</h1>';
$html .= '<p>' . htmlspecialchars($school['name']) . ' — ' . htmlspecialchars($title) . ' — generated '
    . htmlspecialchars(date('j M Y', strtotime($latestGeneration['generated_at']))) . '</p></div>';

// Table of contents
if ($scope !== 'teacher') {
    $html .= '<div class="toc"><h2>Table of contents</h2><ul>';
    foreach (array_keys($byGroup) as $groupName) {
        $anchor = preg_replace('/[^a-z0-9]+/i', '_', $groupName);
        $html .= '<li><a href="#' . $anchor . '">Year ' . htmlspecialchars($groupName) . '</a></li>';
    }
    $html .= '</ul></div>';
}

// Render each group (class or teacher) as a grid
foreach ($byGroup as $groupName => $groupRows) {
    $anchor = preg_replace('/[^a-z0-9]+/i', '_', $groupName);

    // Build lookup: day => hour_slot => cell content
    $grid = [];
    $allTimeSlots = [];
    foreach ($groupRows as $r) {
        $day = $r['day_of_week'];
        $slot = $r['hour_slot'];
        if ($day === null || $slot === null || $slot === '' || $slot === true || $slot === false) {
            continue;
        }
        $allTimeSlots[(string) $slot] = true;

        $label = $r['subject_name'] ?? $r['activity_name'] ?? null;
        $class = $r['class_name'] ?? null;
        $room = $r['room_name'] ?? null;

        if ($label === null && $class === null && $room === null) {
            continue;
        }

        $cellParts = [];
        if ($label !== null) $cellParts[] = '<div class="subject-name">' . htmlspecialchars($label) . '</div>';
        if ($scope === 'teacher' && $class !== null) $cellParts[] = '<div class="class-name">' . htmlspecialchars($class) . '</div>';
        if ($room !== null) $cellParts[] = '<div class="room-name">' . htmlspecialchars($room) . '</div>';

        $grid[$day][$slot] = implode('', $cellParts);
    }

    // Sort time slots chronologically by key
    $stringKeys = array_map('strval', array_keys($allTimeSlots));
    uksort($allTimeSlots, static fn($a, $b) => strcmp(strval($a), strval($b)));
    $allTimeSlots = array_keys($allTimeSlots);

    $html .= '<div class="class-block" id="' . $anchor . '">';
    $html .= '<table>';
    $captionLabel = $scope === 'teacher' ? 'Teacher' : 'Institution';
    $html .= '<caption><span class="institution">' . htmlspecialchars($captionLabel) . ':</span> ' . htmlspecialchars($groupName) . '</caption>';
    $html .= '<thead><tr><th></th>';
    foreach ($days as $day) {
        $html .= '<th class="xAxis">' . $day . '</th>';
    }
    $html .= '</tr></thead><tbody>';

    foreach ($allTimeSlots as $slot) {
        $isBreak = stripos($slot, 'BREAK') !== false || stripos($slot, 'LUNCH') !== false;
        $rowClass = $isBreak ? ' break-cell' : '';
        $html .= '<tr>';
        $html .= '<th class="yAxis time-cell">' . htmlspecialchars($slot) . '</th>';
        foreach ($days as $day) {
            if (isset($grid[$day][$slot])) {
                $html .= '<td class="subject-cell' . $rowClass . '">' . $grid[$day][$slot] . '</td>';
            } else {
                $html .= '<td class="empty-cell' . $rowClass . '">---</td>';
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

$scopeLabel = $scope === 'class' && $classId !== null ? preg_replace('/[^a-z0-9]+/i', '-', (string) $classId)
    : (($scope === 'teacher' && $teacherId !== null) ? 'teacher-' . (int) $teacherId : 'whole-school');
$filename = 'timetable_' . $scopeLabel . '_' . date('Ymd_His') . '.pdf';
$dompdf->stream($filename, ['Attachment' => true]);

/**
 * Resolves the actual FET timetable HTML file from the stored index/TOC
 * file. Returns the full path to `_years_days_horizontal.html`, or null
 * if it cannot be found.
 */
function resolveFetchTimetableHtml(string $indexHtmlPath): ?string
{
    if (!file_exists($indexHtmlPath)) {
        return null;
    }

    $dir = dirname($indexHtmlPath);

    // First, try the known filename pattern directly in the same folder.
    $base = basename($indexHtmlPath, '_index.html');
    $direct = $dir . '/' . $base . '_years_days_horizontal.html';
    if (file_exists($direct)) {
        return $direct;
    }

    // Otherwise, parse the index HTML and follow the "Years / Days Horizontal" link.
    $dom = new DOMDocument();
    @$dom->loadHTMLFile($indexHtmlPath);
    $xpath = new DOMXPath($dom);

    // Find the "Years" row, then the "Days Horizontal" link
    $rows = $xpath->query('//table[caption]//tr');
    foreach ($rows as $row) {
        $cells = $xpath->query('.//td', $row);
        $firstText = '';
        if ($cells->length > 0) {
            $firstText = trim($cells->item(0)->textContent);
        }
        if ($firstText === 'Years' && $cells->length >= 2) {
            $link = $xpath->query('.//a', $cells->item(1))->item(0);
            if ($link && $link->hasAttribute('href')) {
                $href = $link->getAttribute('href');
                $resolved = $dir . '/' . $href;
                if (file_exists($resolved)) {
                    return $resolved;
                }
            }
        }
    }

    return null;
}

/**
 * Fallback parser: reads FET's HTML timetable output and extracts
 * the day/slot/subject/teacher grid into the same row structure
 * the PDF renderer expects.
 */
function parseFetchHtmlTimetable(string $htmlPath, int $schoolId, ?int $classId): array
{
    if (!file_exists($htmlPath)) {
        return [];
    }

    $dom = new DOMDocument();
    @$dom->loadHTMLFile($htmlPath);
    $xpath = new DOMXPath($dom);

    // Fetch class/teacher/room lookups
    $pdo = db();
    $stmt = $pdo->prepare('SELECT id, name FROM classes WHERE school_id = ?' . ($classId !== null ? ' AND id = ?' : '') . ' ORDER BY name');
    $stmt->execute($classId !== null ? [$schoolId, $classId] : [$schoolId]);
    $classIdByName = array_column($stmt->fetchAll(), 'id', 'name');

    $stmt = $pdo->prepare('SELECT id, name FROM teachers WHERE school_id = ? ORDER BY name');
    $stmt->execute([$schoolId]);
    $teacherIdByName = array_column($stmt->fetchAll(), 'id', 'name');

    $stmt = $pdo->prepare('SELECT id, name FROM rooms WHERE school_id = ? ORDER BY name');
    $stmt->execute([$schoolId]);
    $roomIdByName = array_column($stmt->fetchAll(), 'id', 'name');

    $stmt = $pdo->prepare('SELECT id, name, class_id FROM subjects WHERE school_id = ? ORDER BY name');
    $stmt->execute([$schoolId]);
    $subjects = $stmt->fetchAll();
    $subjectIdByNameClass = [];
    foreach ($subjects as $s) {
        $subjectIdByNameClass[$s['class_id']][$s['name']] = $s['id'];
    }

    $rows = [];
    $days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];

    // FET HTML uses <caption> for class name and a table with day columns
    $tables = $xpath->query('//table[caption]');
    foreach ($tables as $table) {
        $caption = $xpath->query('.//caption', $table)->item(0);
        if (!$caption) continue;

        // Extract class name from <span class="name"> inside caption,
        // not the whole caption text (which includes the school name).
        $nameSpan = $xpath->query('.//span[@class="name"]', $table)->item(0);
        $className = $nameSpan ? trim($nameSpan->textContent) : trim($caption->textContent);
        if ($className === '') continue;

        // Skip if filtering by class and this isn't it
        if ($classId !== null && ($classIdByName[$className] ?? null) !== $classId) {
            continue;
        }

        // Get header row to find day columns
        $headerRow = $xpath->query('.//thead/tr', $table)->item(0);
        if (!$headerRow) continue;

        $dayColumns = [];
        $thCells = $xpath->query('.//th', $headerRow);
        foreach ($thCells as $th) {
            $dayText = trim($th->textContent);
            if (in_array($dayText, $days, true)) {
                $dayColumns[] = $dayText;
            }
        }

        // Get body rows (time slots)
        $bodyRows = $xpath->query('.//tbody/tr', $table);
        foreach ($bodyRows as $bodyRow) {
            $tdCells = $xpath->query('.//td', $bodyRow);
            if ($tdCells->length === 0) continue;

            // First cell is the time slot label
            $firstCell = $xpath->query('.//th', $bodyRow)->item(0);
            $timeSlot = $firstCell ? trim($firstCell->textContent) : '';

            foreach ($dayColumns as $idx => $day) {
                $cellIndex = $idx + 1;
                if ($cellIndex >= $tdCells->length) continue;

                $cellNode = $tdCells->item($cellIndex);
                $cellText = '';
                foreach ($cellNode->childNodes as $node) {
                    if ($node->nodeName === 'br') {
                        $cellText .= "\n";
                    } elseif ($node->nodeType === XML_TEXT_NODE) {
                        $cellText .= $node->nodeValue;
                    }
                }
                $cellText = trim($cellText);
                if ($cellText === '' || $cellText === '---') continue;

                $lines = array_filter(array_map('trim', explode("\n", $cellText)));
                $lines = array_values($lines);

                $subjectName = $lines[0] ?? null;
                $teacherName = $lines[1] ?? null;
                $roomName = $lines[2] ?? null;

                $classIdFound = $classIdByName[$className] ?? null;
                $teacherId = $teacherName !== null ? ($teacherIdByName[$teacherName] ?? null) : null;
                $roomId = $roomName !== null ? ($roomIdByName[$roomName] ?? null) : null;
                $subjectId = null;
                if ($subjectName !== null && $classIdFound !== null && isset($subjectIdByNameClass[$classIdFound][$subjectName])) {
                    $subjectId = $subjectIdByNameClass[$classIdFound][$subjectName];
                }

                $rows[] = [
                    'class_id' => $classIdFound,
                    'subject_id' => $subjectId,
                    'extra_activity_id' => null,
                    'remedial_session_id' => null,
                    'teacher_id' => $teacherId,
                    'room_id' => $roomId,
                    'day_of_week' => $day,
                    'hour_slot' => $timeSlot,
                    'class_name' => $className,
                    'subject_name' => $subjectName,
                    'activity_name' => null,
                    'teacher_name' => $teacherName,
                    'room_name' => $roomName,
                ];
            }
        }
    }

    return $rows;
}

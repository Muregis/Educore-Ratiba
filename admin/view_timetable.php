<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../db/db.php';

$schoolId = requireLoginAndGetSchoolId();
$stmt = db()->prepare('SELECT * FROM schools WHERE id = ?');
$stmt->execute([$schoolId]);
$school = $stmt->fetch();

$dayNames = getSchoolDayNames($schoolId);

function buildBandPeriodTimeline(array $band): array
{
    $prefix = (string) ($band['band_key'] ?? 'B');
    $lessonsPerDay = (int) ($band['lessons_per_day'] ?? 8);
    $lengthMin = max(15, (int) ($band['lesson_length_minutes'] ?? 40));
    $dayStart = (string) ($band['day_start_time'] ?? '08:00');
    if (!preg_match('/^\d{2}:\d{2}$/', $dayStart)) {
        $dayStart = '08:00';
    }
    $breakConfig = [];
    if (!empty($band['break_config'])) {
        $decoded = is_string($band['break_config'])
            ? json_decode($band['break_config'], true)
            : $band['break_config'];
        if (is_array($decoded)) {
            $breakConfig = $decoded;
        }
    }
    

    $parsedBreaks = [];
    foreach ($breakConfig as $bc) {
        [$bStart, $bEnd] = array_pad(explode('-', (string) ($bc['time'] ?? '')), 2, null);
        if ($bStart === null || $bEnd === null) {
            continue;
        }
        $parsedBreaks[] = [
            'start_ts' => strtotime(trim($bStart)),
            'end_ts' => strtotime(trim($bEnd)),
            'start_label' => trim($bStart),
            'end_label' => trim($bEnd),
            'label' => (string) ($bc['label'] ?? 'BREAK'),
        ];
    }
    usort($parsedBreaks, static fn($a, $b) => $a['start_ts'] <=> $b['start_ts']);

    $periods = [];
    $current = strtotime($dayStart);
    $teachingCount = 0;
    $safety = $lessonsPerDay + count($parsedBreaks) + 6;

    for ($i = 0; $i < $safety; $i++) {
        if ($teachingCount >= $lessonsPerDay) {
            break;
        }
        $matchedBreak = null;
        foreach ($parsedBreaks as $pb) {
            if ($current >= $pb['start_ts'] && $current < $pb['end_ts']) {
                $matchedBreak = $pb;
                break;
            }
        }
        if ($matchedBreak !== null) {
            $key = "{$prefix}__{$matchedBreak['start_label']}-{$matchedBreak['end_label']} {$matchedBreak['label']}";
            $periods[] = [
                'key' => $key,
                'label' => $matchedBreak['start_label'] . ' - ' . $matchedBreak['end_label'],
                'is_break' => true,
                'break_label' => strtoupper($matchedBreak['label']),
            ];
            $current = $matchedBreak['end_ts'];
            continue;
        }
        $slotStart = date('H:i', $current);
        $slotEndTs = $current + ($lengthMin * 60);
        $slotEnd = date('H:i', $slotEndTs);
        $key = "{$prefix}__{$slotStart}-{$slotEnd}";
        $periods[] = [
            'key' => $key,
            'label' => $slotStart . ' - ' . $slotEnd,
            'is_break' => false,
            'break_label' => null,
        ];
        $teachingCount++;
        $current = $slotEndTs;
    }

    return $periods;
}

function hourClockPart(string $hour): string
{
    $h = formatHourSlotLabel($hour);
    return trim($h);
}

$stmt = db()->prepare(
    "SELECT * FROM generated_timetables WHERE school_id = ? AND status = 'success' ORDER BY generated_at DESC LIMIT 1"
);
$stmt->execute([$schoolId]);
$latestSuccess = $stmt->fetch();

$stmt = db()->prepare(
    "SELECT COUNT(*) FROM generated_timetables WHERE school_id = ? AND status = 'success'"
);
$stmt->execute([$schoolId]);
$successCount = (int) $stmt->fetchColumn();

$stmt = db()->prepare('SELECT * FROM generated_timetables WHERE school_id = ? ORDER BY generated_at DESC LIMIT 1');
$stmt->execute([$schoolId]);
$latestAny = $stmt->fetch();

$latestFailedDiagnosis = null;
if ($latestAny && $latestAny['status'] === 'failed' && !empty($latestAny['failure_summary'])) {
    $dec = json_decode((string) $latestAny['failure_summary'], true);
    if (is_array($dec)) {
        $latestFailedDiagnosis = $dec;
    }
}

$stmt = db()->prepare('SELECT * FROM classes WHERE school_id = ? AND active = TRUE ORDER BY name');
$stmt->execute([$schoolId]);
$classes = $stmt->fetchAll();

$selectedClassId = ($_GET['class_id'] ?? '') !== '' ? (int) $_GET['class_id'] : null;

$slots = [];
$periods = [];
$selClass = null;
$selBand = null;

if ($selectedClassId !== null) {
    foreach ($classes as $c) {
        if ((int) $c['id'] === $selectedClassId) {
            $selClass = $c;
            break;
        }
    }
    if ($selClass && !empty($selClass['band_id'])) {
        $bstmt = db()->prepare('SELECT * FROM bands WHERE id = ? AND school_id = ?');
        $bstmt->execute([(int) $selClass['band_id'], $schoolId]);
        $selBand = $bstmt->fetch() ?: null;
    }
    if ($selBand) {
        $periods = buildBandPeriodTimeline($selBand);
    }
}

if ($latestSuccess && $selectedClassId !== null) {
    try {
        $stmt = db()->prepare(
            "SELECT scheduled_slots.*, subjects.name AS subject_name, extra_activities.name AS activity_name,
                    teachers.name AS teacher_name, rooms.name AS room_name, rooms.code AS room_code
             FROM scheduled_slots
             LEFT JOIN subjects ON scheduled_slots.subject_id = subjects.id
             LEFT JOIN extra_activities ON scheduled_slots.extra_activity_id = extra_activities.id
             LEFT JOIN teachers ON scheduled_slots.teacher_id = teachers.id
             LEFT JOIN rooms ON scheduled_slots.room_id = rooms.id
             WHERE scheduled_slots.generated_timetable_id = ? AND scheduled_slots.class_id = ?
             ORDER BY scheduled_slots.hour_slot, scheduled_slots.day_of_week"
        );
        $stmt->execute([$latestSuccess['id'], $selectedClassId]);
        $slots = $stmt->fetchAll();
    } catch (Throwable $e) {
        $stmt = db()->prepare(
            "SELECT scheduled_slots.*, subjects.name AS subject_name, extra_activities.name AS activity_name,
                    teachers.name AS teacher_name, rooms.name AS room_name
             FROM scheduled_slots
             LEFT JOIN subjects ON scheduled_slots.subject_id = subjects.id
             LEFT JOIN extra_activities ON scheduled_slots.extra_activity_id = extra_activities.id
             LEFT JOIN teachers ON scheduled_slots.teacher_id = teachers.id
             LEFT JOIN rooms ON scheduled_slots.room_id = rooms.id
             WHERE scheduled_slots.generated_timetable_id = ? AND scheduled_slots.class_id = ?
             ORDER BY scheduled_slots.hour_slot, scheduled_slots.day_of_week"
        );
        $stmt->execute([$latestSuccess['id'], $selectedClassId]);
        $slots = $stmt->fetchAll();
        foreach ($slots as &$s) {
            $s['room_code'] = null;
        }
        unset($s);
    }
}

$grid = [];
$hourSlotsFromData = [];
foreach ($slots as $s) {
    $h = (string) $s['hour_slot'];
    $d = (string) $s['day_of_week'];
    $grid[$d][$h] = $s;
    $grid[$d]['__clock__' . hourClockPart($h)] = $s;
    $hourSlotsFromData[$h] = true;
}

if (empty($periods) && !empty($hourSlotsFromData)) {
    $keys = array_keys($hourSlotsFromData);
    usort($keys, static function ($a, $b) {
        $timeOf = static function (string $h): string {
            if (preg_match('/(\d{2}:\d{2})\s*-\s*(\d{2}:\d{2})/', $h, $m)) {
                return $m[1];
            }
            return $h;
        };
        return strcmp($timeOf($a), $timeOf($b));
    });
    foreach ($keys as $k) {
        $isBreak = (bool) preg_match('/\b(BREAK|LUNCH|TEA)\b/i', $k);
        $periods[] = [
            'key' => $k,
            'label' => formatHourSlotLabel($k),
            'is_break' => $isBreak,
            'break_label' => $isBreak ? (preg_match('/\b(LUNCH|BREAK|TEA)\b/i', $k, $m) ? strtoupper($m[1]) : 'BREAK') : null,
        ];
    }
}

function findSlotForPeriod(array $grid, string $day, array $period): ?array
{
    $key = $period['key'];
    if (isset($grid[$day][$key])) {
        return $grid[$day][$key];
    }
    $clock = hourClockPart($key);
    if (isset($grid[$day]['__clock__' . $clock])) {
        return $grid[$day]['__clock__' . $clock];
    }
    if (preg_match('/(\d{2}:\d{2})\s*-\s*(\d{2}:\d{2})/', $clock, $m)) {
        $needle = $m[1] . '-' . $m[2];
        foreach ($grid[$day] ?? [] as $hk => $slot) {
            if (str_starts_with((string) $hk, '__clock__')) {
                continue;
            }
            if (str_contains(str_replace(' ', '', hourClockPart((string) $hk)), str_replace(' ', '', $needle))) {
                return $slot;
            }
        }
    }
    return null;
}

$bandLabelById = [];
$stmt = db()->prepare('SELECT id, label FROM bands WHERE school_id = ?');
$stmt->execute([$schoolId]);
foreach ($stmt->fetchAll() as $b) {
    $bandLabelById[(int) $b['id']] = $b['label'];
}

$pageTitle = 'View Timetables — ' . $school['name'];
require __DIR__ . '/_header.php';
?>



<div class="card">
    <h2>Class timetables</h2>

    <?php if ($successCount === 0): ?>
        <?php if ($latestFailedDiagnosis !== null): ?>
            <div class="error">
                <strong>The latest generation failed. Here's why:</strong>
                <ul style="margin: 8px 0 0; padding-left: 20px;">
                    <?php foreach (($latestFailedDiagnosis['summary'] ?? []) as $line): ?>
                        <li><?php echo htmlspecialchars($line); ?></li>
                    <?php endforeach; ?>
                </ul>
                <p class="mt-3"><a href="generate.php">Fix the issues, then generate again</a></p>
            </div>
        <?php else: ?>
            <p class="empty">No successful timetable yet. <a href="generate.php">Generate one</a> first.</p>
        <?php endif; ?>
    <?php else: ?>
        <?php if ($latestAny && $latestAny['status'] === 'failed' && $latestSuccess): ?>
            <div class="error mb-3">
                Latest run failed, but showing the last successful timetable
                (<?php echo htmlspecialchars(date('j M Y, g:i A', strtotime($latestSuccess['generated_at']))); ?>).
            </div>
        <?php endif; ?>

        <p class="tt-sub">
            Timetable generated
            <?php echo htmlspecialchars(date('j M Y, g:i A', strtotime($latestSuccess['generated_at']))); ?>.
            Download timetables for classes or individual teachers.
        </p>
        <p style="margin: 8px 0 16px;">
            <a class="btn btn-secondary" href="../export/export.php?scope=school" target="_blank">Download Whole School PDF</a>
            <a class="btn btn-secondary" href="../export/export_teachers.php" target="_blank">Download All Teacher Timetables</a>
        </p>

        <form method="get" class="flex flex-wrap gap-3 items-end mb-2">
            <div style="min-width:240px;">
                <label for="class_id">Choose a class</label>
                <select id="class_id" name="class_id">
                    <option value="">— All classes (list) —</option>
                    <?php foreach ($classes as $class): ?>
                        <option value="<?php echo (int) $class['id']; ?>" <?php echo $selectedClassId === (int) $class['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($class['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn btn-secondary">Show grid</button>
        </form>

        <?php if ($selectedClassId !== null): ?>
            <?php if (empty($periods) && empty($slots)): ?>
                <div class="card bg-light">
                    <p class="empty text-center">
                        This class has no scheduled slots in the current timetable.
                        <a href="generate.php">Generate</a> or pick another class.
                    </p>
                </div>
            <?php else: ?>
                <div class="tt-title-bar">
                    <h3>
                        <?php echo htmlspecialchars($selClass['name'] ?? 'Class'); ?>
                        <?php if ($selBand): ?>
                            <span class="tt-sub">· <?php echo htmlspecialchars($selBand['label'] ?? ''); ?></span>
                        <?php endif; ?>
                    </h3>
                    <span class="tt-sub">
                        <?php echo count($dayNames); ?>-day week
                        <?php if ($selBand): ?>
                            · <?php echo (int) ($selBand['lessons_per_day'] ?? 0); ?> periods/day
                            · <?php echo (int) ($selBand['lesson_length_minutes'] ?? 0); ?> min
                        <?php endif; ?>
                    </span>
                </div>

                <div class="tt-wrap">
                    <table class="tt-grid">
                        <thead>
                            <tr>
                                <th class="tt-time">Time</th>
                                <?php foreach ($dayNames as $day): ?>
                                    <th><?php echo htmlspecialchars($day); ?></th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($periods as $period): ?>
                                <?php if ($period['is_break']): ?>
                                    <tr class="tt-break-row">
                                        <td class="tt-time"><?php echo htmlspecialchars($period['label']); ?></td>
                                        <td colspan="<?php echo count($dayNames); ?>">
                                            <?php echo htmlspecialchars($period['break_label'] ?? 'BREAK'); ?>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <tr>
                                        <td class="tt-time"><?php echo htmlspecialchars($period['label']); ?></td>
                                        <?php foreach ($dayNames as $day): ?>
                                            <?php $slot = findSlotForPeriod($grid, $day, $period); ?>
                                            <td>
                                                <?php if ($slot): ?>
                                                    <?php
                                                    $title = $slot['subject_name'] ?? $slot['activity_name'] ?? '—';
                                                    $teacher = trim((string) ($slot['teacher_name'] ?? ''));
                                                    $roomCode = trim((string) ($slot['room_code'] ?? ''));
                                                    $roomName = trim((string) ($slot['room_name'] ?? ''));
                                                    $roomDisplay = $roomCode !== '' ? $roomCode : $roomName;
                                                    ?>
                                                    <div class="tt-cell-subject"><?php echo htmlspecialchars($title); ?></div>
                                                    <div class="tt-cell-meta">
                                                        <?php if ($teacher !== ''): ?>
                                                            <?php echo htmlspecialchars($teacher); ?><br>
                                                        <?php endif; ?>
                                                        <?php if ($roomDisplay !== ''): ?>
                                                            <span class="tt-cell-room"><?php echo htmlspecialchars($roomDisplay); ?></span>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php else: ?>
                                                    <div class="tt-cell-empty">—</div>
                                                <?php endif; ?>
                                            </td>
                                        <?php endforeach; ?>
                                    </tr>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="tt-legend">
                    <span><span class="tt-swatch" style="background:var(--warning);"></span> Break / lunch (not taught)</span>
                    <span><span class="tt-swatch" style="background:var(--bg-white); border-color:var(--primary);"></span> Lesson · teacher · room code</span>
                </div>
                <p class="mt-3">
                    <a class="btn btn-secondary" href="../export/export.php?scope=class&class_id=<?php echo (int) $selectedClassId; ?>" target="_blank">Download this class PDF</a>
                    <a class="btn btn-secondary" href="edit-timetable.php?class_id=<?php echo (int) $selectedClassId; ?>">Manual edits</a>
                </p>
            <?php endif; ?>
        <?php else: ?>
            <h3 class="mt-5">All classes</h3>
            <table>
                <thead><tr><th>Class</th><th>Band</th><th>Lessons scheduled</th><th>Actions</th></tr></thead>
                <tbody>
                    <?php foreach ($classes as $class): ?>
                        <?php
                        $stmt = db()->prepare('SELECT COUNT(*) FROM scheduled_slots WHERE generated_timetable_id = ? AND class_id = ?');
                        $stmt->execute([$latestSuccess['id'], $class['id']]);
                        $slotCount = (int) $stmt->fetchColumn();
                        ?>
                        <tr>
                            <td><?php echo htmlspecialchars($class['name']); ?></td>
                            <td><?php echo htmlspecialchars($bandLabelById[(int) $class['band_id']] ?? '—'); ?></td>
                            <td><?php echo $slotCount; ?></td>
                            <td class="row-actions">
                                <a href="?class_id=<?php echo (int) $class['id']; ?>" class="btn btn-secondary">View grid</a>
                                <a href="../export/export.php?scope=class&class_id=<?php echo (int) $class['id']; ?>" target="_blank" class="btn btn-secondary">PDF</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <h3 class="mt-5">Teacher Timetables</h3>
            <table>
                <thead><tr><th>Teacher</th><th>Staff ID</th><th>Actions</th></tr></thead>
                <tbody>
                    <?php
                    $stmt = db()->prepare('SELECT * FROM teachers WHERE school_id = ? ORDER BY name');
                    $stmt->execute([$schoolId]);
                    $teachers = $stmt->fetchAll();
                    foreach ($teachers as $teacher):
                    ?>
                        <tr>
                            <td><?php echo htmlspecialchars($teacher['name']); ?></td>
                            <td><?php echo htmlspecialchars($teacher['staff_id'] ?? '—'); ?></td>
                            <td>
                                <a href="../export/export.php?scope=teacher&teacher_id=<?php echo (int) $teacher['id']; ?>" target="_blank" class="btn btn-secondary">
                                    Download PDF
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/_footer.php'; ?>






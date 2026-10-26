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

function getSchoolTimelinePeriods(int $schoolId, ?int $bandId = null, ?int $timetableId = null): array
{
    if ($bandId !== null && $bandId > 0) {
        $stmt = db()->prepare('SELECT * FROM bands WHERE id = ? AND school_id = ?');
        $stmt->execute([$bandId, $schoolId]);
        $band = $stmt->fetch();
        if ($band) {
            $bandPeriods = buildBandPeriodTimeline($band);
            if (!empty($bandPeriods)) {
                return $bandPeriods;
            }
        }
    }

    $stmt = db()->prepare('SELECT * FROM bands WHERE school_id = ? ORDER BY id');
    $stmt->execute([$schoolId]);
    $allBands = $stmt->fetchAll();

    $periods = [];
    $seenPeriodKeys = [];
    foreach ($allBands as $b) {
        $bPeriods = buildBandPeriodTimeline($b);
        foreach ($bPeriods as $bp) {
            $norm = hourClockPart($bp['label']);
            if (!isset($seenPeriodKeys[$norm])) {
                $seenPeriodKeys[$norm] = true;
                $periods[] = $bp;
            }
        }
    }

    if ($timetableId !== null && $timetableId > 0) {
        try {
            $slotStmt = db()->prepare('SELECT DISTINCT hour_slot FROM scheduled_slots WHERE generated_timetable_id = ?');
            $slotStmt->execute([$timetableId]);
            $slotHours = $slotStmt->fetchAll(PDO::FETCH_COLUMN);
            foreach ($slotHours as $sh) {
                $sh = (string) $sh;
                $norm = hourClockPart(formatHourSlotLabel($sh));
                if (!isset($seenPeriodKeys[$norm])) {
                    $seenPeriodKeys[$norm] = true;
                    $isBreak = (bool) preg_match('/\b(BREAK|LUNCH|TEA)\b/i', $sh);
                    $periods[] = [
                        'key' => $sh,
                        'label' => formatHourSlotLabel($sh),
                        'is_break' => $isBreak,
                        'break_label' => $isBreak ? (preg_match('/\b(LUNCH|BREAK|TEA)\b/i', $sh, $m) ? strtoupper($m[1]) : 'BREAK') : null,
                    ];
                }
            }
        } catch (Throwable) {
        }
    }

    usort($periods, static function ($a, $b) {
        $timeOf = static function (string $h): string {
            if (preg_match('/(\d{2}:\d{2})\s*-\s*(\d{2}:\d{2})/', $h, $m)) {
                return $m[1];
            }
            return $h;
        };
        return strcmp($timeOf((string) ($a['label'] ?? '')), $timeOf((string) ($b['label'] ?? '')));
    });

    return $periods;
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

// Fetch Timetable Generation State
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

// Fetch Entities
$stmt = db()->prepare('SELECT c.*, b.band_key, b.label as band_label FROM classes c LEFT JOIN bands b ON c.band_id = b.id WHERE c.school_id = ? AND c.active = TRUE');
$stmt->execute([$schoolId]);
$classes = $stmt->fetchAll();
sortClassesByLevel($classes);

$stmt = db()->prepare('SELECT * FROM teachers WHERE school_id = ? ORDER BY name');
$stmt->execute([$schoolId]);
$teachers = $stmt->fetchAll();

$stmt = db()->prepare('SELECT * FROM rooms WHERE school_id = ? ORDER BY name');
$stmt->execute([$schoolId]);
$rooms = $stmt->fetchAll();

$stmt = db()->prepare('SELECT id, label FROM bands WHERE school_id = ?');
$stmt->execute([$schoolId]);
$bandLabelById = [];
foreach ($stmt->fetchAll() as $b) {
    $bandLabelById[(int) $b['id']] = $b['label'];
}

// Determine Active View & Selected Entity
$view = $_GET['view'] ?? '';
$selectedClassId = ($_GET['class_id'] ?? '') !== '' ? (int) $_GET['class_id'] : null;
$selectedTeacherId = ($_GET['teacher_id'] ?? '') !== '' ? (int) $_GET['teacher_id'] : null;
$selectedRoomId = ($_GET['room_id'] ?? '') !== '' ? (int) $_GET['room_id'] : null;
$masterDay = $_GET['master_day'] ?? 'all';
$masterMode = $_GET['master_mode'] ?? 'class'; // 'class' or 'teacher'

if ($view === '') {
    if ($selectedTeacherId !== null) {
        $view = 'teacher';
    } elseif ($selectedRoomId !== null) {
        $view = 'room';
    } elseif ($selectedClassId !== null) {
        $view = 'class';
    } else {
        $view = 'class';
    }
}

$slots = [];
$periods = [];
$grid = [];

// ============================================================
// DATA LOADING ACCORDING TO VIEW MODE
// ============================================================

if ($latestSuccess) {
    $ttId = (int) $latestSuccess['id'];

    if ($view === 'class' && $selectedClassId !== null) {
        $selClass = null;
        foreach ($classes as $c) {
            if ((int) $c['id'] === $selectedClassId) {
                $selClass = $c;
                break;
            }
        }
        $bandId = $selClass && !empty($selClass['band_id']) ? (int) $selClass['band_id'] : null;
        $periods = getSchoolTimelinePeriods($schoolId, $bandId, $ttId);

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
            $stmt->execute([$ttId, $selectedClassId]);
            $slots = $stmt->fetchAll();
        } catch (Throwable) {
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
            $stmt->execute([$ttId, $selectedClassId]);
            $slots = $stmt->fetchAll();
            foreach ($slots as &$s) {
                $s['room_code'] = null;
            }
            unset($s);
        }

        foreach ($slots as $s) {
            $h = (string) $s['hour_slot'];
            $d = (string) $s['day_of_week'];
            $grid[$d][$h] = $s;
            $grid[$d]['__clock__' . hourClockPart($h)] = $s;
        }

    } elseif ($view === 'teacher' && $selectedTeacherId !== null) {
        $selTeacher = null;
        foreach ($teachers as $t) {
            if ((int) $t['id'] === $selectedTeacherId) {
                $selTeacher = $t;
                break;
            }
        }
        $periods = getSchoolTimelinePeriods($schoolId, null, $ttId);

        try {
            $stmt = db()->prepare(
                "SELECT scheduled_slots.*, classes.name AS class_name,
                        subjects.name AS subject_name, extra_activities.name AS activity_name,
                        rooms.name AS room_name, rooms.code AS room_code
                 FROM scheduled_slots
                 JOIN classes ON scheduled_slots.class_id = classes.id
                 LEFT JOIN subjects ON scheduled_slots.subject_id = subjects.id
                 LEFT JOIN extra_activities ON scheduled_slots.extra_activity_id = extra_activities.id
                 LEFT JOIN rooms ON scheduled_slots.room_id = rooms.id
                 WHERE scheduled_slots.generated_timetable_id = ? AND scheduled_slots.teacher_id = ?
                 ORDER BY scheduled_slots.hour_slot, scheduled_slots.day_of_week"
            );
            $stmt->execute([$ttId, $selectedTeacherId]);
            $slots = $stmt->fetchAll();
        } catch (Throwable) {
            $stmt = db()->prepare(
                "SELECT scheduled_slots.*, classes.name AS class_name,
                        subjects.name AS subject_name, extra_activities.name AS activity_name,
                        rooms.name AS room_name
                 FROM scheduled_slots
                 JOIN classes ON scheduled_slots.class_id = classes.id
                 LEFT JOIN subjects ON scheduled_slots.subject_id = subjects.id
                 LEFT JOIN extra_activities ON scheduled_slots.extra_activity_id = extra_activities.id
                 LEFT JOIN rooms ON scheduled_slots.room_id = rooms.id
                 WHERE scheduled_slots.generated_timetable_id = ? AND scheduled_slots.teacher_id = ?
                 ORDER BY scheduled_slots.hour_slot, scheduled_slots.day_of_week"
            );
            $stmt->execute([$ttId, $selectedTeacherId]);
            $slots = $stmt->fetchAll();
            foreach ($slots as &$s) {
                $s['room_code'] = null;
            }
            unset($s);
        }

        foreach ($slots as $s) {
            $h = (string) $s['hour_slot'];
            $d = (string) $s['day_of_week'];
            $grid[$d][$h] = $s;
            $grid[$d]['__clock__' . hourClockPart($h)] = $s;
        }

    } elseif ($view === 'room' && $selectedRoomId !== null) {
        $selRoom = null;
        foreach ($rooms as $r) {
            if ((int) $r['id'] === $selectedRoomId) {
                $selRoom = $r;
                break;
            }
        }
        $periods = getSchoolTimelinePeriods($schoolId, null, $ttId);

        $stmt = db()->prepare(
            "SELECT scheduled_slots.*, classes.name AS class_name,
                    subjects.name AS subject_name, extra_activities.name AS activity_name,
                    teachers.name AS teacher_name
             FROM scheduled_slots
             JOIN classes ON scheduled_slots.class_id = classes.id
             LEFT JOIN subjects ON scheduled_slots.subject_id = subjects.id
             LEFT JOIN extra_activities ON scheduled_slots.extra_activity_id = extra_activities.id
             LEFT JOIN teachers ON scheduled_slots.teacher_id = teachers.id
             WHERE scheduled_slots.generated_timetable_id = ? AND scheduled_slots.room_id = ?
             ORDER BY scheduled_slots.hour_slot, scheduled_slots.day_of_week"
        );
        $stmt->execute([$ttId, $selectedRoomId]);
        $slots = $stmt->fetchAll();

        foreach ($slots as $s) {
            $h = (string) $s['hour_slot'];
            $d = (string) $s['day_of_week'];
            $grid[$d][$h] = $s;
            $grid[$d]['__clock__' . hourClockPart($h)] = $s;
        }

    } elseif ($view === 'master') {
        $masterPeriods = getSchoolTimelinePeriods($schoolId, null, $ttId);

        try {
            $stmt = db()->prepare(
                "SELECT scheduled_slots.*, classes.name AS class_name, classes.band_id,
                        subjects.name AS subject_name, extra_activities.name AS activity_name,
                        teachers.name AS teacher_name, teachers.staff_id,
                        rooms.name AS room_name, rooms.code AS room_code
                 FROM scheduled_slots
                 JOIN classes ON scheduled_slots.class_id = classes.id
                 LEFT JOIN subjects ON scheduled_slots.subject_id = subjects.id
                 LEFT JOIN extra_activities ON scheduled_slots.extra_activity_id = extra_activities.id
                 LEFT JOIN teachers ON scheduled_slots.teacher_id = teachers.id
                 LEFT JOIN rooms ON scheduled_slots.room_id = rooms.id
                 WHERE scheduled_slots.generated_timetable_id = ?
                 ORDER BY classes.name, scheduled_slots.day_of_week, scheduled_slots.hour_slot"
            );
            $stmt->execute([$ttId]);
            $allMasterSlots = $stmt->fetchAll();
        } catch (Throwable) {
            $stmt = db()->prepare(
                "SELECT scheduled_slots.*, classes.name AS class_name, classes.band_id,
                        subjects.name AS subject_name, extra_activities.name AS activity_name,
                        teachers.name AS teacher_name, teachers.staff_id,
                        rooms.name AS room_name
                 FROM scheduled_slots
                 JOIN classes ON scheduled_slots.class_id = classes.id
                 LEFT JOIN subjects ON scheduled_slots.subject_id = subjects.id
                 LEFT JOIN extra_activities ON scheduled_slots.extra_activity_id = extra_activities.id
                 LEFT JOIN teachers ON scheduled_slots.teacher_id = teachers.id
                 LEFT JOIN rooms ON scheduled_slots.room_id = rooms.id
                 WHERE scheduled_slots.generated_timetable_id = ?
                 ORDER BY classes.name, scheduled_slots.day_of_week, scheduled_slots.hour_slot"
            );
            $stmt->execute([$ttId]);
            $allMasterSlots = $stmt->fetchAll();
            foreach ($allMasterSlots as &$ms) {
                $ms['room_code'] = null;
            }
            unset($ms);
        }

        $masterGridClass = [];
        $masterGridTeacher = [];
        foreach ($allMasterSlots as $s) {
            $d = (string) $s['day_of_week'];
            $h = (string) $s['hour_slot'];
            $cid = (int) $s['class_id'];
            $tid = (int) ($s['teacher_id'] ?? 0);

            $masterGridClass[$d][$cid][$h] = $s;
            $masterGridClass[$d][$cid]['__clock__' . hourClockPart($h)] = $s;

            if ($tid > 0) {
                $masterGridTeacher[$d][$tid][$h] = $s;
                $masterGridTeacher[$d][$tid]['__clock__' . hourClockPart($h)] = $s;
            }
        }
    }
}

// Aggregate Statistics for Lists
$classSlotCounts = [];
$teacherSlotStats = [];
$roomSlotCounts = [];

if ($latestSuccess) {
    try {
        $cStmt = db()->prepare('SELECT class_id, COUNT(*) as cnt FROM scheduled_slots WHERE generated_timetable_id = ? GROUP BY class_id');
        $cStmt->execute([$latestSuccess['id']]);
        foreach ($cStmt->fetchAll() as $row) {
            $classSlotCounts[(int) $row['class_id']] = (int) $row['cnt'];
        }

        $tStmt = db()->prepare(
            "SELECT scheduled_slots.teacher_id, classes.name as class_name, subjects.name as subject_name, COUNT(*) as cnt
             FROM scheduled_slots
             JOIN classes ON scheduled_slots.class_id = classes.id
             LEFT JOIN subjects ON scheduled_slots.subject_id = subjects.id
             WHERE scheduled_slots.generated_timetable_id = ? AND scheduled_slots.teacher_id IS NOT NULL
             GROUP BY scheduled_slots.teacher_id, classes.name, subjects.name"
        );
        $tStmt->execute([$latestSuccess['id']]);
        foreach ($tStmt->fetchAll() as $row) {
            $tid = (int) $row['teacher_id'];
            if (!isset($teacherSlotStats[$tid])) {
                $teacherSlotStats[$tid] = ['total' => 0, 'classes' => [], 'subjects' => []];
            }
            $teacherSlotStats[$tid]['total'] += (int) $row['cnt'];
            if (!empty($row['class_name'])) {
                $teacherSlotStats[$tid]['classes'][$row['class_name']] = true;
            }
            if (!empty($row['subject_name'])) {
                $teacherSlotStats[$tid]['subjects'][$row['subject_name']] = true;
            }
        }

        $rStmt = db()->prepare('SELECT room_id, COUNT(*) as cnt FROM scheduled_slots WHERE generated_timetable_id = ? AND room_id IS NOT NULL GROUP BY room_id');
        $rStmt->execute([$latestSuccess['id']]);
        foreach ($rStmt->fetchAll() as $row) {
            $roomSlotCounts[(int) $row['room_id']] = (int) $row['cnt'];
        }
    } catch (Throwable) {
    }
}

$pageTitle = 'View Timetables — ' . ($school['name'] ?? 'School');
require __DIR__ . '/_header.php';
?>

<div class="card">
    <div style="display: flex; flex-wrap: wrap; justify-content: space-between; align-items: baseline; gap: 12px; margin-bottom: 8px;">
        <h2>Timetable Viewer</h2>
        <div style="display: flex; gap: 8px; flex-wrap: wrap;">
            <a class="btn btn-secondary" href="../export/export.php?scope=school" target="_blank">📄 Download Whole School PDF</a>
            <a class="btn btn-secondary" href="../export/export_teachers.php" target="_blank">👨‍🏫 Download All Teachers PDF</a>
        </div>
    </div>

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

        <p class="tt-sub mb-3">
            Timetable generated <?php echo htmlspecialchars(date('j M Y, g:i A', strtotime($latestSuccess['generated_at']))); ?>.
            Browse by class, individual teacher, room, or view the complete master whole-school schedule.
        </p>

        <!-- View Switcher Tabs -->
        <nav class="tt-view-nav" aria-label="Timetable Views">
            <a href="?view=class<?php echo $selectedClassId ? '&class_id=' . $selectedClassId : ''; ?>"
               class="tt-view-tab <?php echo $view === 'class' ? 'active' : ''; ?>">
                🎓 Class Timetables
            </a>
            <a href="?view=teacher<?php echo $selectedTeacherId ? '&teacher_id=' . $selectedTeacherId : ''; ?>"
               class="tt-view-tab <?php echo $view === 'teacher' ? 'active' : ''; ?>">
                👨‍🏫 Teacher Timetables
            </a>
            <a href="?view=room<?php echo $selectedRoomId ? '&room_id=' . $selectedRoomId : ''; ?>"
               class="tt-view-tab <?php echo $view === 'room' ? 'active' : ''; ?>">
                🏫 Room Timetables
            </a>
            <a href="?view=master"
               class="tt-view-tab <?php echo $view === 'master' ? 'active' : ''; ?>">
                🌐 Master Whole-School Grid
            </a>
        </nav>

        <!-- ============================================================ -->
        <!-- VIEW 1: CLASS TIMETABLE -->
        <!-- ============================================================ -->
        <?php if ($view === 'class'): ?>
            <form method="get" class="flex flex-wrap gap-3 items-end mb-3">
                <input type="hidden" name="view" value="class">
                <div style="min-width:260px;">
                    <label for="class_id"><strong>Select Class</strong></label>
                    <select id="class_id" name="class_id" onchange="this.form.submit()">
                        <option value="">— All Classes (Overview) —</option>
                        <?php foreach ($classes as $class): ?>
                            <option value="<?php echo (int) $class['id']; ?>" <?php echo $selectedClassId === (int) $class['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($class['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="btn btn-secondary">Show Timetable</button>
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
                        <div>
                            <h3 style="margin: 0;">
                                🎓 <?php echo htmlspecialchars($selClass['name'] ?? 'Class'); ?>
                                <?php if (!empty($selClass['band_label'])): ?>
                                    <span class="tt-sub">· <?php echo htmlspecialchars($selClass['band_label']); ?></span>
                                <?php endif; ?>
                            </h3>
                            <span class="tt-sub">
                                <?php echo count($dayNames); ?>-day week · <?php echo count($slots); ?> lessons scheduled
                            </span>
                        </div>
                        <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                            <a class="btn btn-secondary" href="../export/export.php?scope=class&class_id=<?php echo (int) $selectedClassId; ?>" target="_blank">Download Class PDF</a>
                            <a class="btn btn-secondary" href="edit-timetable.php?class_id=<?php echo (int) $selectedClassId; ?>">Manual edits</a>
                            <a class="btn btn-secondary" href="?view=class">All Classes</a>
                        </div>
                    </div>

                    <div class="mobile-day-tabs" role="tablist" aria-label="Select day">
                        <button type="button" class="day-tab active" data-day="all" onclick="filterTimetableDay(this, 'all')">All Days</button>
                        <?php foreach ($dayNames as $day): ?>
                            <button type="button" class="day-tab" data-day="<?php echo htmlspecialchars($day); ?>" onclick="filterTimetableDay(this, '<?php echo htmlspecialchars($day); ?>')"><?php echo htmlspecialchars($day); ?></button>
                        <?php endforeach; ?>
                    </div>

                    <div class="tt-wrap">
                        <table class="tt-grid">
                            <thead>
                                <tr>
                                    <th class="tt-time">Time</th>
                                    <?php foreach ($dayNames as $day): ?>
                                        <th data-day="<?php echo htmlspecialchars($day); ?>"><?php echo htmlspecialchars($day); ?></th>
                                    <?php endforeach; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($periods as $period): ?>
                                    <?php if ($period['is_break']): ?>
                                        <tr class="tt-break-row">
                                            <td class="tt-time"><?php echo htmlspecialchars($period['label']); ?></td>
                                            <td colspan="<?php echo count($dayNames); ?>" class="tt-break-cell">
                                                <?php echo htmlspecialchars($period['break_label'] ?? 'BREAK'); ?>
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <tr>
                                            <td class="tt-time"><?php echo htmlspecialchars($period['label']); ?></td>
                                            <?php foreach ($dayNames as $day): ?>
                                                <?php $slot = findSlotForPeriod($grid, $day, $period); ?>
                                                <td data-day="<?php echo htmlspecialchars($day); ?>">
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
                        <span><span class="tt-swatch" style="background:var(--warning);"></span> Break / lunch</span>
                        <span><span class="tt-swatch" style="background:var(--bg-white); border-color:var(--primary);"></span> Lesson · Teacher · Room</span>
                    </div>
                <?php endif; ?>

            <?php else: ?>
                <!-- All Classes Table -->
                <h3 class="mt-4">All Classes Overview</h3>
                <p class="tt-sub">Click "View Grid" on any class to view their full weekly schedule on-screen.</p>
                <table>
                    <thead>
                        <tr>
                            <th>Class</th>
                            <th>Band</th>
                            <th>Scheduled Lessons</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($classes as $class): ?>
                            <?php $slotCount = $classSlotCounts[(int) $class['id']] ?? 0; ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($class['name']); ?></strong></td>
                                <td><?php echo htmlspecialchars($bandLabelById[(int) $class['band_id']] ?? '—'); ?></td>
                                <td><span class="badge badge-light"><?php echo $slotCount; ?> lessons</span></td>
                                <td class="row-actions">
                                    <a href="?view=class&class_id=<?php echo (int) $class['id']; ?>" class="btn btn-secondary">View grid</a>
                                    <a href="../export/export.php?scope=class&class_id=<?php echo (int) $class['id']; ?>" target="_blank" class="btn btn-secondary">PDF</a>
                                    <a href="edit-timetable.php?class_id=<?php echo (int) $class['id']; ?>" class="btn btn-secondary">Edit</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

        <!-- ============================================================ -->
        <!-- VIEW 2: TEACHER TIMETABLE -->
        <!-- ============================================================ -->
        <?php elseif ($view === 'teacher'): ?>
            <form method="get" class="flex flex-wrap gap-3 items-end mb-3">
                <input type="hidden" name="view" value="teacher">
                <div style="min-width:260px;">
                    <label for="teacher_id"><strong>Select Teacher</strong></label>
                    <select id="teacher_id" name="teacher_id" onchange="this.form.submit()">
                        <option value="">— All Teachers (Overview) —</option>
                        <?php foreach ($teachers as $t): ?>
                            <option value="<?php echo (int) $t['id']; ?>" <?php echo $selectedTeacherId === (int) $t['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($t['name'] . (!empty($t['staff_id']) ? " ({$t['staff_id']})" : '')); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="btn btn-secondary">Show Timetable</button>
            </form>

            <?php if ($selectedTeacherId !== null && $selTeacher): ?>
                <?php
                $teacherClassesList = array_unique(array_filter(array_column($slots, 'class_name')));
                $teacherSubjectsList = array_unique(array_filter(array_column($slots, 'subject_name')));
                ?>
                <div class="tt-title-bar">
                    <div>
                        <h3 style="margin: 0; display: flex; align-items: center; gap: 8px;">
                            <span>👨‍🏫 <?php echo htmlspecialchars($selTeacher['name']); ?></span>
                            <?php if (!empty($selTeacher['staff_id'])): ?>
                                <span class="badge badge-light" style="font-size: 0.8rem; font-weight: normal;">Staff ID: <?php echo htmlspecialchars($selTeacher['staff_id']); ?></span>
                            <?php endif; ?>
                            <?php if (!empty($selTeacher['tsc_number'])): ?>
                                <span class="badge badge-light" style="font-size: 0.8rem; font-weight: normal;">TSC: <?php echo htmlspecialchars($selTeacher['tsc_number']); ?></span>
                            <?php endif; ?>
                        </h3>
                        <p class="tt-sub" style="margin: 4px 0 0;">
                            <strong><?php echo count($slots); ?></strong> lessons scheduled this week
                            <?php if (!empty($selTeacher['max_lessons_per_week'])): ?>
                                · Target max: <?php echo (int) $selTeacher['max_lessons_per_week']; ?>/wk
                            <?php endif; ?>
                            <?php if (!empty($teacherSubjectsList)): ?>
                                · Subjects: <?php echo htmlspecialchars(implode(', ', $teacherSubjectsList)); ?>
                            <?php endif; ?>
                            <?php if (!empty($teacherClassesList)): ?>
                                · Classes: <?php echo htmlspecialchars(implode(', ', $teacherClassesList)); ?>
                            <?php endif; ?>
                        </p>
                    </div>
                    <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                        <a class="btn btn-secondary" href="../export/export.php?scope=teacher&teacher_id=<?php echo (int) $selectedTeacherId; ?>" target="_blank">Download PDF</a>
                        <a class="btn btn-secondary" href="edit-timetable.php?mode=teacher&selected_id=<?php echo (int) $selectedTeacherId; ?>">Manual edits</a>
                        <a class="btn btn-secondary" href="?view=teacher">All Teachers</a>
                    </div>
                </div>

                <div class="mobile-day-tabs" role="tablist" aria-label="Select day">
                    <button type="button" class="day-tab active" data-day="all" onclick="filterTimetableDay(this, 'all')">All Days</button>
                    <?php foreach ($dayNames as $day): ?>
                        <button type="button" class="day-tab" data-day="<?php echo htmlspecialchars($day); ?>" onclick="filterTimetableDay(this, '<?php echo htmlspecialchars($day); ?>')"><?php echo htmlspecialchars($day); ?></button>
                    <?php endforeach; ?>
                </div>

                <div class="tt-wrap">
                    <table class="tt-grid">
                        <thead>
                            <tr>
                                <th class="tt-time">Time</th>
                                <?php foreach ($dayNames as $day): ?>
                                    <th data-day="<?php echo htmlspecialchars($day); ?>"><?php echo htmlspecialchars($day); ?></th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($periods as $period): ?>
                                <?php if ($period['is_break']): ?>
                                    <tr class="tt-break-row">
                                        <td class="tt-time"><?php echo htmlspecialchars($period['label']); ?></td>
                                        <td colspan="<?php echo count($dayNames); ?>" class="tt-break-cell">
                                            <?php echo htmlspecialchars($period['break_label'] ?? 'BREAK'); ?>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <tr>
                                        <td class="tt-time"><?php echo htmlspecialchars($period['label']); ?></td>
                                        <?php foreach ($dayNames as $day): ?>
                                            <?php $slot = findSlotForPeriod($grid, $day, $period); ?>
                                            <td data-day="<?php echo htmlspecialchars($day); ?>">
                                                <?php if ($slot): ?>
                                                    <?php
                                                    $title = $slot['subject_name'] ?? $slot['activity_name'] ?? '—';
                                                    $className = trim((string) ($slot['class_name'] ?? ''));
                                                    $roomCode = trim((string) ($slot['room_code'] ?? ''));
                                                    $roomName = trim((string) ($slot['room_name'] ?? ''));
                                                    $roomDisplay = $roomCode !== '' ? $roomCode : $roomName;
                                                    ?>
                                                    <span class="tt-badge-class"><?php echo htmlspecialchars($className); ?></span>
                                                    <div class="tt-cell-subject"><?php echo htmlspecialchars($title); ?></div>
                                                    <?php if ($roomDisplay !== ''): ?>
                                                        <div class="tt-cell-meta">
                                                            <span class="tt-cell-room"><?php echo htmlspecialchars($roomDisplay); ?></span>
                                                        </div>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <div class="tt-cell-empty">
                                                        <span class="tt-badge-free">Free</span>
                                                    </div>
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
                    <span><span class="tt-swatch" style="background:var(--warning);"></span> Break / lunch</span>
                    <span><span class="tt-swatch" style="background:var(--bg-white); border-color:var(--primary);"></span> Teaching lesson [Class · Subject · Room]</span>
                    <span><span class="tt-swatch" style="background:var(--bg-light); border-color:var(--border);"></span> Free period</span>
                </div>

            <?php else: ?>
                <!-- All Teachers Table -->
                <h3 class="mt-4">All Teacher Schedules</h3>
                <p class="tt-sub">Click "View Grid" to view any teacher's weekly timetable on-screen, or download individual PDFs.</p>
                <table>
                    <thead>
                        <tr>
                            <th>Teacher</th>
                            <th>Staff ID / TSC</th>
                            <th>Lessons Scheduled</th>
                            <th>Classes Taught</th>
                            <th>Subjects Taught</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($teachers as $teacher): ?>
                            <?php
                            $tid = (int) $teacher['id'];
                            $stat = $teacherSlotStats[$tid] ?? ['total' => 0, 'classes' => [], 'subjects' => []];
                            $classesStr = !empty($stat['classes']) ? implode(', ', array_keys($stat['classes'])) : '—';
                            $subjectsStr = !empty($stat['subjects']) ? implode(', ', array_keys($stat['subjects'])) : '—';
                            ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($teacher['name']); ?></strong></td>
                                <td><?php echo htmlspecialchars($teacher['staff_id'] ?? $teacher['tsc_number'] ?? '—'); ?></td>
                                <td><span class="badge badge-light"><?php echo (int) $stat['total']; ?> lessons</span></td>
                                <td style="font-size:0.82rem; color:var(--text);"><?php echo htmlspecialchars($classesStr); ?></td>
                                <td style="font-size:0.82rem; color:var(--text-muted);"><?php echo htmlspecialchars($subjectsStr); ?></td>
                                <td class="row-actions">
                                    <a href="?view=teacher&teacher_id=<?php echo $tid; ?>" class="btn btn-secondary">View grid</a>
                                    <a href="../export/export.php?scope=teacher&teacher_id=<?php echo $tid; ?>" target="_blank" class="btn btn-secondary">PDF</a>
                                    <a href="edit-timetable.php?mode=teacher&selected_id=<?php echo $tid; ?>" class="btn btn-secondary">Edit</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

        <!-- ============================================================ -->
        <!-- VIEW 3: ROOM TIMETABLE -->
        <!-- ============================================================ -->
        <?php elseif ($view === 'room'): ?>
            <form method="get" class="flex flex-wrap gap-3 items-end mb-3">
                <input type="hidden" name="view" value="room">
                <div style="min-width:260px;">
                    <label for="room_id"><strong>Select Room</strong></label>
                    <select id="room_id" name="room_id" onchange="this.form.submit()">
                        <option value="">— All Rooms (Overview) —</option>
                        <?php foreach ($rooms as $r): ?>
                            <option value="<?php echo (int) $r['id']; ?>" <?php echo $selectedRoomId === (int) $r['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($r['name'] . (!empty($r['code']) ? " ({$r['code']})" : '')); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="btn btn-secondary">Show Timetable</button>
            </form>

            <?php if ($selectedRoomId !== null && $selRoom): ?>
                <div class="tt-title-bar">
                    <div>
                        <h3 style="margin: 0;">
                            🏫 <?php echo htmlspecialchars($selRoom['name']); ?>
                            <?php if (!empty($selRoom['code'])): ?>
                                <span class="badge badge-light"><?php echo htmlspecialchars($selRoom['code']); ?></span>
                            <?php endif; ?>
                        </h3>
                        <p class="tt-sub" style="margin: 4px 0 0;">
                            <?php echo count($slots); ?> periods occupied this week
                            <?php if (!empty($selRoom['capacity'])): ?>
                                · Capacity: <?php echo (int) $selRoom['capacity']; ?> students
                            <?php endif; ?>
                            <?php if (!empty($selRoom['type'])): ?>
                                · Type: <?php echo htmlspecialchars($selRoom['type']); ?>
                            <?php endif; ?>
                        </p>
                    </div>
                    <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                        <a class="btn btn-secondary" href="edit-timetable.php?mode=room&selected_id=<?php echo (int) $selectedRoomId; ?>">Manual edits</a>
                        <a class="btn btn-secondary" href="?view=room">All Rooms</a>
                    </div>
                </div>

                <div class="mobile-day-tabs" role="tablist" aria-label="Select day">
                    <button type="button" class="day-tab active" data-day="all" onclick="filterTimetableDay(this, 'all')">All Days</button>
                    <?php foreach ($dayNames as $day): ?>
                        <button type="button" class="day-tab" data-day="<?php echo htmlspecialchars($day); ?>" onclick="filterTimetableDay(this, '<?php echo htmlspecialchars($day); ?>')"><?php echo htmlspecialchars($day); ?></button>
                    <?php endforeach; ?>
                </div>

                <div class="tt-wrap">
                    <table class="tt-grid">
                        <thead>
                            <tr>
                                <th class="tt-time">Time</th>
                                <?php foreach ($dayNames as $day): ?>
                                    <th data-day="<?php echo htmlspecialchars($day); ?>"><?php echo htmlspecialchars($day); ?></th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($periods as $period): ?>
                                <?php if ($period['is_break']): ?>
                                    <tr class="tt-break-row">
                                        <td class="tt-time"><?php echo htmlspecialchars($period['label']); ?></td>
                                        <td colspan="<?php echo count($dayNames); ?>" class="tt-break-cell">
                                            <?php echo htmlspecialchars($period['break_label'] ?? 'BREAK'); ?>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <tr>
                                        <td class="tt-time"><?php echo htmlspecialchars($period['label']); ?></td>
                                        <?php foreach ($dayNames as $day): ?>
                                            <?php $slot = findSlotForPeriod($grid, $day, $period); ?>
                                            <td data-day="<?php echo htmlspecialchars($day); ?>">
                                                <?php if ($slot): ?>
                                                    <span class="tt-badge-class"><?php echo htmlspecialchars($slot['class_name'] ?? ''); ?></span>
                                                    <div class="tt-cell-subject"><?php echo htmlspecialchars($slot['subject_name'] ?? $slot['activity_name'] ?? '—'); ?></div>
                                                    <div class="tt-cell-meta"><?php echo htmlspecialchars($slot['teacher_name'] ?? '—'); ?></div>
                                                <?php else: ?>
                                                    <div class="tt-cell-empty">
                                                        <span class="tt-badge-free" style="color:var(--secondary); border-color:var(--secondary);">Vacant</span>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                        <?php endforeach; ?>
                                    </tr>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

            <?php else: ?>
                <!-- All Rooms Table -->
                <h3 class="mt-4">All Rooms Overview</h3>
                <p class="tt-sub">View occupancy schedule and utilization across all rooms and laboratories.</p>
                <table>
                    <thead>
                        <tr>
                            <th>Room Name</th>
                            <th>Code</th>
                            <th>Type</th>
                            <th>Capacity</th>
                            <th>Lessons Scheduled</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rooms as $room): ?>
                            <?php $rid = (int) $room['id']; ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($room['name']); ?></strong></td>
                                <td><?php echo htmlspecialchars($room['code'] ?? '—'); ?></td>
                                <td><?php echo htmlspecialchars($room['type'] ?? 'Regular'); ?></td>
                                <td><?php echo isset($room['capacity']) ? (int) $room['capacity'] : '—'; ?></td>
                                <td><span class="badge badge-light"><?php echo $roomSlotCounts[$rid] ?? 0; ?> periods</span></td>
                                <td class="row-actions">
                                    <a href="?view=room&room_id=<?php echo $rid; ?>" class="btn btn-secondary">View grid</a>
                                    <a href="edit-timetable.php?mode=room&selected_id=<?php echo $rid; ?>" class="btn btn-secondary">Edit</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

        <!-- ============================================================ -->
        <!-- VIEW 4: MASTER WHOLE-SCHOOL TIMETABLE -->
        <!-- ============================================================ -->
        <?php elseif ($view === 'master'): ?>
            <?php
            $activeDayList = ($masterDay === 'all') ? $dayNames : [$masterDay];
            $totalScheduledSlots = count($allMasterSlots ?? []);
            $activeClassesCount = count(array_unique(array_column($allMasterSlots ?? [], 'class_id')));
            $activeTeachersCount = count(array_unique(array_filter(array_column($allMasterSlots ?? [], 'teacher_id'))));
            $activeRoomsCount = count(array_unique(array_filter(array_column($allMasterSlots ?? [], 'room_id'))));
            ?>

            <!-- Master Summary Stats Strip -->
            <div class="tt-stat-strip">
                <div class="tt-stat-box">
                    <div class="tt-stat-val"><?php echo $totalScheduledSlots; ?></div>
                    <div class="tt-stat-lbl">Total Scheduled Lessons</div>
                </div>
                <div class="tt-stat-box">
                    <div class="tt-stat-val"><?php echo $activeClassesCount; ?></div>
                    <div class="tt-stat-lbl">Active Classes</div>
                </div>
                <div class="tt-stat-box">
                    <div class="tt-stat-val"><?php echo $activeTeachersCount; ?></div>
                    <div class="tt-stat-lbl">Assigned Teachers</div>
                </div>
                <div class="tt-stat-box">
                    <div class="tt-stat-val"><?php echo $activeRoomsCount; ?></div>
                    <div class="tt-stat-lbl">Rooms Utilized</div>
                </div>
            </div>

            <!-- Master Toolbar -->
            <div class="tt-master-toolbar">
                <div class="tt-master-pills">
                    <span style="font-size:0.8rem; font-weight:700; color:var(--text-muted); margin-right:4px;">DAY:</span>
                    <a href="?view=master&master_mode=<?php echo urlencode($masterMode); ?>&master_day=all"
                       class="tt-master-pill <?php echo $masterDay === 'all' ? 'active' : ''; ?>">All Days</a>
                    <?php foreach ($dayNames as $day): ?>
                        <a href="?view=master&master_mode=<?php echo urlencode($masterMode); ?>&master_day=<?php echo urlencode($day); ?>"
                           class="tt-master-pill <?php echo $masterDay === $day ? 'active' : ''; ?>"><?php echo htmlspecialchars($day); ?></a>
                    <?php endforeach; ?>
                </div>

                <div class="tt-master-pills">
                    <span style="font-size:0.8rem; font-weight:700; color:var(--text-muted); margin-right:4px;">VIEW BY:</span>
                    <a href="?view=master&master_day=<?php echo urlencode($masterDay); ?>&master_mode=class"
                       class="tt-master-pill <?php echo $masterMode === 'class' ? 'active' : ''; ?>">👥 By Class</a>
                    <a href="?view=master&master_day=<?php echo urlencode($masterDay); ?>&master_mode=teacher"
                       class="tt-master-pill <?php echo $masterMode === 'teacher' ? 'active' : ''; ?>">👨‍🏫 By Teacher</a>
                    <button type="button" onclick="window.print()" class="btn btn-secondary" style="padding:6px 12px; font-size:0.8rem;">🖨️ Print Matrix</button>
                </div>
            </div>

            <!-- Master Matrix Render -->
            <?php foreach ($activeDayList as $currentDay): ?>
                <div style="margin-bottom: 30px;">
                    <div style="display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 8px;">
                        <h3 style="margin: 0; color: var(--primary-dark);">
                            📅 <?php echo htmlspecialchars($currentDay); ?> — Master Schedule Matrix
                        </h3>
                        <span class="tt-sub">
                            Perspective: <?php echo $masterMode === 'teacher' ? 'All Teachers' : 'All Classes (Educational Progression)'; ?>
                        </span>
                    </div>

                    <div class="tt-master-wrap">
                        <table class="tt-master-table">
                            <thead>
                                <tr>
                                    <th class="tt-master-row-header">
                                        <?php echo $masterMode === 'teacher' ? 'Teacher' : 'Class'; ?>
                                    </th>
                                    <?php foreach ($masterPeriods as $period): ?>
                                        <th>
                                            <?php echo htmlspecialchars($period['label']); ?>
                                            <?php if ($period['is_break']): ?>
                                                <div style="font-size:0.7rem; font-weight:normal; opacity:0.85;">
                                                    <?php echo htmlspecialchars($period['break_label'] ?? 'BREAK'); ?>
                                                </div>
                                            <?php endif; ?>
                                        </th>
                                    <?php endforeach; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($masterMode === 'teacher'): ?>
                                    <!-- Rows: Teachers -->
                                    <?php foreach ($teachers as $teacher): ?>
                                        <?php
                                        $tid = (int) $teacher['id'];
                                        $tGrid = $masterGridTeacher[$currentDay][$tid] ?? [];
                                        ?>
                                        <tr>
                                            <td class="tt-master-row-header">
                                                <div><?php echo htmlspecialchars($teacher['name']); ?></div>
                                                <?php if (!empty($teacher['staff_id'])): ?>
                                                    <div style="font-size:0.7rem; color:var(--text-muted); font-weight:normal;">
                                                        ID: <?php echo htmlspecialchars($teacher['staff_id']); ?>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                            <?php foreach ($masterPeriods as $period): ?>
                                                <?php if ($period['is_break']): ?>
                                                    <td class="tt-master-break"><?php echo htmlspecialchars($period['break_label'] ?? 'BREAK'); ?></td>
                                                <?php else: ?>
                                                    <?php $slot = findSlotForPeriod([$currentDay => $tGrid], $currentDay, $period); ?>
                                                    <td>
                                                        <?php if ($slot): ?>
                                                            <span class="tt-badge-class"><?php echo htmlspecialchars($slot['class_name'] ?? ''); ?></span>
                                                            <div style="font-weight:700; color:var(--text); line-height:1.2;">
                                                                <?php echo htmlspecialchars($slot['subject_name'] ?? $slot['activity_name'] ?? '—'); ?>
                                                            </div>
                                                            <?php
                                                            $roomCode = trim((string) ($slot['room_code'] ?? ''));
                                                            $roomName = trim((string) ($slot['room_name'] ?? ''));
                                                            $roomDisplay = $roomCode !== '' ? $roomCode : $roomName;
                                                            ?>
                                                            <?php if ($roomDisplay !== ''): ?>
                                                                <div style="font-size:0.7rem; color:var(--primary-dark); font-weight:600;">
                                                                    <?php echo htmlspecialchars($roomDisplay); ?>
                                                                </div>
                                                            <?php endif; ?>
                                                        <?php else: ?>
                                                            <div style="text-align:center;">
                                                                <span class="tt-badge-free">Free</span>
                                                            </div>
                                                        <?php endif; ?>
                                                    </td>
                                                <?php endif; ?>
                                            <?php endforeach; ?>
                                        </tr>
                                    <?php endforeach; ?>

                                <?php else: ?>
                                    <!-- Rows: Classes (sorted PP1 -> Grade 1-12 -> University) -->
                                    <?php foreach ($classes as $class): ?>
                                        <?php
                                        $cid = (int) $class['id'];
                                        $cGrid = $masterGridClass[$currentDay][$cid] ?? [];
                                        ?>
                                        <tr>
                                            <td class="tt-master-row-header">
                                                <div><?php echo htmlspecialchars($class['name']); ?></div>
                                                <?php if (!empty($class['band_label'])): ?>
                                                    <div style="font-size:0.7rem; color:var(--text-muted); font-weight:normal;">
                                                        <?php echo htmlspecialchars($class['band_label']); ?>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                            <?php foreach ($masterPeriods as $period): ?>
                                                <?php if ($period['is_break']): ?>
                                                    <td class="tt-master-break"><?php echo htmlspecialchars($period['break_label'] ?? 'BREAK'); ?></td>
                                                <?php else: ?>
                                                    <?php $slot = findSlotForPeriod([$currentDay => $cGrid], $currentDay, $period); ?>
                                                    <td>
                                                        <?php if ($slot): ?>
                                                            <div style="font-weight:700; color:var(--text); line-height:1.2;">
                                                                <?php echo htmlspecialchars($slot['subject_name'] ?? $slot['activity_name'] ?? '—'); ?>
                                                            </div>
                                                            <?php if (!empty($slot['teacher_name'])): ?>
                                                                <div style="font-size:0.75rem; color:var(--primary-dark); font-weight:600; margin-top:2px;">
                                                                    <?php echo htmlspecialchars($slot['teacher_name']); ?>
                                                                </div>
                                                            <?php endif; ?>
                                                            <?php
                                                            $roomCode = trim((string) ($slot['room_code'] ?? ''));
                                                            $roomName = trim((string) ($slot['room_name'] ?? ''));
                                                            $roomDisplay = $roomCode !== '' ? $roomCode : $roomName;
                                                            ?>
                                                            <?php if ($roomDisplay !== ''): ?>
                                                                <div style="font-size:0.7rem; color:var(--text-muted);">
                                                                    <?php echo htmlspecialchars($roomDisplay); ?>
                                                                </div>
                                                            <?php endif; ?>
                                                        <?php else: ?>
                                                            <div style="text-align:center; color:var(--text-muted); opacity:0.4;">—</div>
                                                        <?php endif; ?>
                                                    </td>
                                                <?php endif; ?>
                                            <?php endforeach; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endforeach; ?>

        <?php endif; ?>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/_footer.php'; ?>

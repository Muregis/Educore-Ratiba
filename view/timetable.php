<?php
declare(strict_types=1);
require_once __DIR__ . '/../db/db.php';

// ============================================================
// view/timetable.php — read-only, no-login view for students,
// teachers, parents, or staff. Scoped by school_id in URL.
// Supports Class view, Teacher view, and Master grid.
// ============================================================

$schoolId = (int) ($_GET['school_id'] ?? 0);
$classId = ($_GET['class_id'] ?? '') !== '' ? (int) $_GET['class_id'] : null;
$teacherId = ($_GET['teacher_id'] ?? '') !== '' ? (int) $_GET['teacher_id'] : null;
$view = $_GET['view'] ?? '';

if ($schoolId === 0) {
    http_response_code(400);
    die('Missing school.');
}

$stmt = db()->prepare('SELECT * FROM schools WHERE id = ?');
$stmt->execute([$schoolId]);
$school = $stmt->fetch();

if (!$school) {
    http_response_code(404);
    die('School not found.');
}

$stmt = db()->prepare(
    "SELECT * FROM generated_timetables WHERE school_id = ? AND status = 'success' ORDER BY generated_at DESC LIMIT 1"
);
$stmt->execute([$schoolId]);
$latestGeneration = $stmt->fetch();

$classes = getClasses($schoolId);
$teachers = getTeachers($schoolId);
$dayNames = getSchoolDayNames($schoolId);

if ($view === '') {
    if ($teacherId !== null) {
        $view = 'teacher';
    } elseif ($classId !== null) {
        $view = 'class';
    } else {
        $view = 'class';
    }
}

$rows = [];
if ($latestGeneration) {
    $params = [$latestGeneration['id']];
    $whereFilter = '';
    if ($view === 'teacher' && $teacherId !== null) {
        $whereFilter = ' AND scheduled_slots.teacher_id = ?';
        $params[] = $teacherId;
    } elseif ($view === 'class' && $classId !== null) {
        $whereFilter = ' AND scheduled_slots.class_id = ?';
        $params[] = $classId;
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
         WHERE scheduled_slots.generated_timetable_id = ?{$whereFilter}
         ORDER BY classes.name, scheduled_slots.day_of_week, scheduled_slots.hour_slot"
    );
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
}

$dayOrder = [];
foreach ($dayNames as $idx => $dName) {
    $dayOrder[$dName] = $idx + 1;
}

$grouped = [];
if ($view === 'teacher') {
    foreach ($rows as $r) {
        $tName = trim((string) ($r['teacher_name'] ?? 'Unassigned Teacher'));
        if ($tName === '') $tName = 'Unassigned Teacher';
        $grouped[$tName][] = $r;
    }
    ksort($grouped);
} else {
    foreach ($rows as $r) {
        $cName = (string) $r['class_name'];
        $grouped[$cName][] = $r;
    }
    uksort($grouped, static fn($a, $b) => compareClassesByLevel(['name' => $a], ['name' => $b]));
}

foreach ($grouped as &$gRows) {
    usort($gRows, static function ($a, $b) use ($dayOrder) {
        $dayCmp = ($dayOrder[$a['day_of_week']] ?? 99) <=> ($dayOrder[$b['day_of_week']] ?? 99);
        return $dayCmp !== 0 ? $dayCmp : strcmp((string) $a['hour_slot'], (string) $b['hour_slot']);
    });
}
unset($gRows);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo htmlspecialchars($school['name']); ?> — Timetable</title>
<style>
    :root { --primary: #0f4c81; --primary-dark: #0a365c; --border: #e1e5ea; --text: #1c2733; --text-muted: #667085; --bg-light: #f4f6f9; }
    * { box-sizing: border-box; }
    body { font-family: 'Segoe UI', Arial, sans-serif; margin: 0; background: var(--bg-light); color: var(--text); }
    header { background: var(--primary); color: white; padding: 18px 24px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; }
    header h1 { font-size: 1.2rem; margin: 0; }
    header p { margin: 2px 0 0; font-size: 0.85rem; opacity: 0.9; }
    main { max-width: 1100px; margin: 24px auto; padding: 0 16px; }
    .nav-tabs { display: flex; gap: 8px; margin-bottom: 18px; border-bottom: 2px solid var(--border); padding-bottom: 12px; }
    .nav-tab { padding: 8px 16px; text-decoration: none; border-radius: 6px; font-weight: 600; font-size: 0.9rem; border: 1px solid var(--border); background: white; color: var(--text); transition: all 0.15s; }
    .nav-tab.active { background: var(--primary); color: white; border-color: var(--primary); }
    .selector { margin-bottom: 18px; display: flex; gap: 12px; align-items: center; flex-wrap: wrap; }
    select { padding: 8px 12px; border: 1px solid var(--border); border-radius: 6px; font-size: 0.9rem; background: white; min-width: 240px; }
    .card { background: white; border: 1px solid var(--border); border-radius: 10px; padding: 20px; margin-bottom: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
    .card h2 { font-size: 1.05rem; margin: 0 0 14px; color: var(--primary-dark); }
    table { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
    th, td { text-align: left; padding: 8px 10px; border-bottom: 1px solid var(--border); }
    th { color: var(--text-muted); font-weight: 600; font-size: 0.75rem; text-transform: uppercase; background: #fafbfc; }
    .empty { color: var(--text-muted); padding: 30px; text-align: center; }
    .badge { display: inline-block; padding: 2px 6px; font-size: 0.75rem; border-radius: 4px; font-weight: 600; background: #e0e7ff; color: #3730a3; }
    .btn-print { padding: 8px 14px; border-radius: 6px; background: white; border: 1px solid var(--border); cursor: pointer; font-size: 0.85rem; font-weight: 600; }
    .btn-print:hover { background: #f0f0f0; }
    @media print {
        header, .nav-tabs, .selector, .btn-print { display: none; }
        body { background: white; }
        .card { box-shadow: none; border: none; padding: 0; margin-bottom: 30px; page-break-after: always; }
    }
</style>
</head>
<body>
<header>
    <div>
        <h1><?php echo htmlspecialchars($school['name']); ?></h1>
        <p><?php echo $latestGeneration ? 'Official Timetable as of ' . htmlspecialchars(date('j M Y', strtotime($latestGeneration['generated_at']))) : 'No timetable available yet'; ?></p>
    </div>
    <button class="btn-print" onclick="window.print()">🖨️ Print Timetable</button>
</header>
<main>
    <!-- View Switcher -->
    <div class="nav-tabs">
        <a href="?school_id=<?php echo $schoolId; ?>&view=class"
           class="nav-tab <?php echo $view === 'class' ? 'active' : ''; ?>">
            🎓 Class Timetables
        </a>
        <a href="?school_id=<?php echo $schoolId; ?>&view=teacher"
           class="nav-tab <?php echo $view === 'teacher' ? 'active' : ''; ?>">
            👨‍🏫 Teacher Timetables
        </a>
    </div>

    <!-- Filter Selector -->
    <div class="selector">
        <?php if ($view === 'teacher'): ?>
            <label for="teacher-select"><strong>Choose Teacher:</strong></label>
            <select id="teacher-select" onchange="window.location.href = '?school_id=<?php echo $schoolId; ?>&view=teacher' + (this.value ? '&teacher_id=' + this.value : '')">
                <option value="">— All Teachers —</option>
                <?php foreach ($teachers as $t): ?>
                    <option value="<?php echo (int) $t['id']; ?>" <?php echo $t['id'] == $teacherId ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($t['name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        <?php else: ?>
            <label for="class-select"><strong>Choose Class:</strong></label>
            <select id="class-select" onchange="window.location.href = '?school_id=<?php echo $schoolId; ?>&view=class' + (this.value ? '&class_id=' + this.value : '')">
                <option value="">— All Classes —</option>
                <?php foreach ($classes as $c): ?>
                    <option value="<?php echo (int) $c['id']; ?>" <?php echo $c['id'] == $classId ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($c['name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        <?php endif; ?>
    </div>

    <?php if (empty($grouped)): ?>
        <div class="card"><p class="empty">No scheduled lessons found for this selection.</p></div>
    <?php else: ?>
        <?php foreach ($grouped as $groupTitle => $groupRows): ?>
            <div class="card">
                <h2><?php echo htmlspecialchars($groupTitle); ?></h2>
                <table>
                    <thead>
                        <tr>
                            <th style="width: 15%;">Day</th>
                            <th style="width: 20%;">Time</th>
                            <th style="width: 30%;">Subject / Activity</th>
                            <?php if ($view === 'teacher'): ?>
                                <th style="width: 20%;">Class</th>
                            <?php else: ?>
                                <th style="width: 20%;">Teacher</th>
                            <?php endif; ?>
                            <th style="width: 15%;">Room</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($groupRows as $r): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($r['day_of_week']); ?></strong></td>
                                <td><?php echo htmlspecialchars(formatHourSlotLabel((string) $r['hour_slot'])); ?></td>
                                <td><strong><?php echo htmlspecialchars($r['subject_name'] ?? $r['activity_name'] ?? '—'); ?></strong></td>
                                <?php if ($view === 'teacher'): ?>
                                    <td><span class="badge"><?php echo htmlspecialchars($r['class_name'] ?? '—'); ?></span></td>
                                <?php else: ?>
                                    <td><?php echo htmlspecialchars($r['teacher_name'] ?? '—'); ?></td>
                                <?php endif; ?>
                                <td><?php echo htmlspecialchars($r['room_name'] ?? '—'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</main>
</body>
</html>

<?php
declare(strict_types=1);
require_once __DIR__ . '/../db/db.php';

// ============================================================
// view/timetable.php — read-only, no-login view for teachers
// or anyone with the link. Scoped by a school_id + optional
// class_id in the URL, NOT by session, since this is meant to
// be shared as a plain link (plan section 3, "anyone with link").
// ============================================================

$schoolId = (int) ($_GET['school_id'] ?? 0);
$classId = ($_GET['class_id'] ?? '') !== '' ? (int) $_GET['class_id'] : null;

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
    'SELECT * FROM generated_timetables WHERE school_id = ? AND status = "success" ORDER BY generated_at DESC LIMIT 1'
);
$stmt->execute([$schoolId]);
$latestGeneration = $stmt->fetch();

$classes = getClasses($schoolId);

$rows = [];
if ($latestGeneration) {
    $params = [$latestGeneration['id']];
    $classFilter = '';
    if ($classId !== null) {
        $classFilter = ' AND scheduled_slots.class_id = ?';
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
         WHERE scheduled_slots.generated_timetable_id = ?{$classFilter}
         ORDER BY classes.name, scheduled_slots.day_of_week, scheduled_slots.hour_slot"
    );
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
}

$dayOrder = ['Monday' => 1, 'Tuesday' => 2, 'Wednesday' => 3, 'Thursday' => 4, 'Friday' => 5];
$byClass = [];
foreach ($rows as $r) {
    $byClass[$r['class_name']][] = $r;
}
foreach ($byClass as &$classRows) {
    usort($classRows, static function ($a, $b) use ($dayOrder) {
        $dayCmp = ($dayOrder[$a['day_of_week']] ?? 9) <=> ($dayOrder[$b['day_of_week']] ?? 9);
        return $dayCmp !== 0 ? $dayCmp : strcmp($a['hour_slot'], $b['hour_slot']);
    });
}
unset($classRows);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo htmlspecialchars($school['name']); ?> — Timetable</title>
<style>
    :root { --primary: #0f4c81; --border: #e1e5ea; --text: #1c2733; --text-muted: #667085; }
    * { box-sizing: border-box; }
    body { font-family: 'Segoe UI', Arial, sans-serif; margin: 0; background: #f4f6f9; color: var(--text); }
    header { background: var(--primary); color: white; padding: 18px 24px; }
    header h1 { font-size: 1.15rem; margin: 0; }
    header p { margin: 2px 0 0; font-size: 0.85rem; opacity: 0.85; }
    main { max-width: 1000px; margin: 24px auto; padding: 0 16px; }
    .selector { margin-bottom: 18px; }
    select { padding: 8px 12px; border: 1px solid var(--border); border-radius: 6px; font-size: 0.9rem; }
    .card { background: white; border: 1px solid var(--border); border-radius: 10px; padding: 20px; margin-bottom: 18px; }
    .card h2 { font-size: 1rem; margin: 0 0 14px; }
    table { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
    th, td { text-align: left; padding: 8px; border-bottom: 1px solid var(--border); }
    th { color: var(--text-muted); font-weight: 600; font-size: 0.75rem; text-transform: uppercase; }
    .empty { color: var(--text-muted); padding: 20px; text-align: center; }
    @media print {
        header, .selector { display: none; }
        body { background: white; }
    }
</style>
</head>
<body>
<header>
    <h1><?php echo htmlspecialchars($school['name']); ?></h1>
    <p><?php echo $latestGeneration ? 'Timetable as of ' . htmlspecialchars(date('j M Y', strtotime($latestGeneration['generated_at']))) : 'No timetable available yet'; ?></p>
</header>
<main>
    <?php if (!empty($classes)): ?>
        <div class="selector">
            <select onchange="window.location.href = '?school_id=<?php echo $schoolId; ?>' + (this.value ? '&class_id=' + this.value : '')">
                <option value="">All classes</option>
                <?php foreach ($classes as $c): ?>
                    <option value="<?php echo (int) $c['id']; ?>" <?php echo $c['id'] == $classId ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($c['name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    <?php endif; ?>

    <?php if (empty($byClass)): ?>
        <div class="card"><p class="empty">No timetable available yet.</p></div>
    <?php else: ?>
        <?php foreach ($byClass as $className => $classRows): ?>
            <div class="card">
                <h2><?php echo htmlspecialchars($className); ?></h2>
                <table>
                    <thead><tr><th>Day</th><th>Time</th><th>Subject</th><th>Teacher</th><th>Room</th></tr></thead>
                    <tbody>
                        <?php foreach ($classRows as $r): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($r['day_of_week']); ?></td>
                                <td><?php echo htmlspecialchars($r['hour_slot']); ?></td>
                                <td><?php echo htmlspecialchars($r['subject_name'] ?? $r['activity_name'] ?? '—'); ?></td>
                                <td><?php echo htmlspecialchars($r['teacher_name'] ?? '—'); ?></td>
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

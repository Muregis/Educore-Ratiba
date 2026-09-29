<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../db/db.php';

$schoolId = requireLoginAndGetSchoolId();
$stmt = db()->prepare('SELECT * FROM schools WHERE id = ?');
$stmt->execute([$schoolId]);
$school = $stmt->fetch();

$dayNames = getSchoolDayNames($schoolId);

// Prefer the latest SUCCESSFUL generation. A newer failed run must not
// hide the last good timetable from the school.
$stmt = db()->prepare(
    "SELECT * FROM generated_timetables WHERE school_id = ? AND status = 'success' ORDER BY generated_at DESC LIMIT 1"
);
$stmt->execute([$schoolId]);
$latestSuccess = $stmt->fetch();

// Has any generation ever succeeded for this school?
$stmt = db()->prepare(
    "SELECT COUNT(*) FROM generated_timetables WHERE school_id = ? AND status = 'success'"
);
$stmt->execute([$schoolId]);
$successCount = (int) $stmt->fetchColumn();

// Latest run of any status — used only for the "last run failed" notice
$stmt = db()->prepare('SELECT * FROM generated_timetables WHERE school_id = ? ORDER BY generated_at DESC LIMIT 1');
$stmt->execute([$schoolId]);
$latestAny = $stmt->fetch();

// Diagnosis of the latest failed run, if it is newer than the last success
$latestFailedDiagnosis = null;
if ($latestAny && $latestAny['status'] === 'failed' && !empty($latestAny['failure_summary'])) {
    $dec = json_decode((string) $latestAny['failure_summary'], true);
    if (is_array($dec)) {
        $latestFailedDiagnosis = $dec;
    }
}

// Get all classes
$stmt = db()->prepare('SELECT * FROM classes WHERE school_id = ? AND active = TRUE ORDER BY name');
$stmt->execute([$schoolId]);
$classes = $stmt->fetchAll();

// Selected class (day×period grid); empty = all classes list view
$selectedClassId = ($_GET['class_id'] ?? '') !== '' ? (int) $_GET['class_id'] : null;

$slots = [];
if ($latestSuccess && $selectedClassId !== null) {
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
}

// Build a day×period grid for the selected class
$grid = [];
$hourSlots = [];
foreach ($slots as $s) {
    $grid[$s['day_of_week']][$s['hour_slot']] = $s;
    $hourSlots[$s['hour_slot']] = true;
}
$hourSlots = array_keys($hourSlots);

// Sort hour slots by their clock start (prefix before the band prefix separator)
usort($hourSlots, static function ($a, $b) {
    $timeOf = static function (string $h): string {
        if (preg_match('/(\d{2}:\d{2})\s*-\s*(\d{2}:\d{2})/', $h, $m)) {
            return $m[1];
        }
        return $h;
    };
    return strcmp($timeOf($a), $timeOf($b));
});

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
                <?php if (!empty($latestFailedDiagnosis['details'])): ?>
                    <details style="margin-top: 8px;">
                        <summary style="cursor:pointer;">Lessons that could not be placed (<?php echo count($latestFailedDiagnosis['details']); ?>)</summary>
                        <ul style="margin: 8px 0 0; padding-left: 20px;">
                            <?php foreach (array_slice($latestFailedDiagnosis['details'], 0, 25) as $d): ?>
                                <li><?php echo htmlspecialchars($d); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </details>
                <?php endif; ?>
                <p style="margin-top: 10px;"><a href="generate.php">Fix the issues, then generate again</a>.</p>
            </div>
        <?php else: ?>
            <div class="error">No timetable has been generated yet. <a href="generate.php">Generate one first</a>.</div>
        <?php endif; ?>
    <?php else: ?>
        <?php if ($latestFailedDiagnosis !== null): ?>
            <div class="error">
                <strong>Heads up:</strong> the most recent generation failed, so this page shows the last
                <em>successful</em> timetable (<?php echo htmlspecialchars(date('j M Y, g:i A', strtotime((string) $latestSuccess['generated_at']))); ?>).
                <details style="margin-top: 6px;">
                    <summary style="cursor:pointer;">Why the latest run failed</summary>
                    <ul style="margin: 8px 0 0; padding-left: 20px;">
                        <?php foreach (($latestFailedDiagnosis['summary'] ?? []) as $line): ?>
                            <li><?php echo htmlspecialchars($line); ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <p style="margin-top: 8px;"><a href="generate.php">Try generating again</a>.</p>
                </details>
            </div>
        <?php endif; ?>

        <p class="empty">Timetable generated <?php echo htmlspecialchars(date('j M Y, g:i A', strtotime((string) $latestSuccess['generated_at']))); ?>. Download timetables for classes or individual teachers.</p>

        <div style="margin-top: 12px; display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 20px;">
            <a href="../export/export.php?scope=whole-school" target="_blank" class="btn">Download Whole School PDF</a>
            <a href="../export/export_teachers.php" target="_blank" class="btn">Download All Teacher Timetables</a>
        </div>

        <form method="get" style="display:flex; gap:8px; align-items:end; flex-wrap:wrap; margin-bottom:16px;">
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
            <?php if (empty($slots)): ?>
                <div class="card" style="background:var(--bg-light);">
                    <p class="empty" style="text-align:center; padding:24px 0;">
                        This class has no scheduled slots in the current timetable.
                        <a href="generate.php">Generate</a> or pick another class.
                    </p>
                </div>
            <?php else: ?>
                <h3 style="margin-bottom:10px;">
                    <?php
                    $selClass = null;
                    foreach ($classes as $c) {
                        if ((int) $c['id'] === $selectedClassId) {
                            $selClass = $c;
                            break;
                        }
                    }
                    echo htmlspecialchars($selClass['name'] ?? 'Class');
                    ?> — weekly grid
                </h3>
                <div style="overflow-x:auto;">
                    <table style="min-width:720px;">
                        <thead>
                            <tr>
                                <th style="min-width:110px;">Time</th>
                                <?php foreach ($dayNames as $day): ?>
                                    <th style="text-align:center;"><?php echo htmlspecialchars($day); ?></th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($hourSlots as $hour): ?>
                                <?php $isBreak = stripos($hour, 'BREAK') !== false || stripos($hour, 'LUNCH') !== false; ?>
                                <tr<?php echo $isBreak ? ' style="background:var(--bg-light);"' : ''; ?>>
                                    <td style="white-space:nowrap; font-size:0.78rem;">
                                        <?php echo htmlspecialchars(preg_replace('/^[^_]*__/', '', $hour)); ?>
                                    </td>
                                    <?php foreach ($dayNames as $day): ?>
                                        <td style="vertical-align:top; min-width:120px;">
                                            <?php if (isset($grid[$day][$hour])): ?>
                                                <?php $slot = $grid[$day][$hour]; ?>
                                                <div style="font-weight:600;"><?php echo htmlspecialchars($slot['subject_name'] ?? $slot['activity_name'] ?? '—'); ?></div>
                                                <div style="font-size:0.78rem; color:var(--text-muted);">
                                                    <?php if ($editShowTeacher = ($slot['teacher_name'] ?? '') !== ''): ?>
                                                        👤 <?php echo htmlspecialchars($slot['teacher_name']); ?><br>
                                                    <?php endif; ?>
                                                    <?php if (($slot['room_name'] ?? '') !== ''): ?>
                                                        🏠 <?php echo htmlspecialchars($slot['room_name']); ?>
                                                    <?php endif; ?>
                                                </div>
                                            <?php else: ?>
                                                <div style="text-align:center; color:var(--text-light);">—</div>
                                            <?php endif; ?>
                                        </td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        <?php else: ?>
            <h3 style="margin-top: 20px; margin-bottom: 10px;">All classes</h3>
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

            <h3 style="margin-top: 30px; margin-bottom: 10px;">Teacher Timetables</h3>
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

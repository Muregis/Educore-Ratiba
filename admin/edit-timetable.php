<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../db/db.php';
require_once __DIR__ . '/generate_engine.php';

$schoolId = requireLoginAndGetSchoolId();
$stmt = db()->prepare('SELECT * FROM schools WHERE id = ?');
$stmt->execute([$schoolId]);
$school = $stmt->fetch();

// Latest generated timetable for this school - manual editing always
// works against the most recent run (plan section 4b).
$stmt = db()->prepare(
    "SELECT * FROM generated_timetables WHERE school_id = ? AND status = 'success' ORDER BY generated_at DESC LIMIT 1"
);
$stmt->execute([$schoolId]);
$latestGeneration = $stmt->fetch();

$classId = (int) ($_GET['class_id'] ?? 0);
$classes = getClasses($schoolId);

$error = null;
$success = null;

// ============================================================
// Handle a move request (AJAX-style POST, returns JSON)
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'move_slot') {
    header('Content-Type: application/json');

    if (!$latestGeneration) {
        echo json_encode(['ok' => false, 'error' => 'No generated timetable to edit.']);
        exit;
    }

    $slotId = (int) ($_POST['slot_id'] ?? 0);
    $newDay = $_POST['new_day'] ?? '';
    $newHourSlot = $_POST['new_hour_slot'] ?? '';

    $stmt = db()->prepare(
        'SELECT scheduled_slots.* FROM scheduled_slots
         WHERE id = ? AND generated_timetable_id = ?'
    );
    $stmt->execute([$slotId, $latestGeneration['id']]);
    $slot = $stmt->fetch();

    if (!$slot) {
        echo json_encode(['ok' => false, 'error' => 'Slot not found.']);
        exit;
    }

    $clashReason = checkSlotMoveForClash(
        (int) $latestGeneration['id'],
        $slotId,
        (int) $slot['teacher_id'],
        $slot['room_id'] !== null ? (int) $slot['room_id'] : null,
        (int) $slot['class_id'],
        $newDay,
        $newHourSlot
    );

    if ($clashReason !== null) {
        echo json_encode(['ok' => false, 'error' => $clashReason]);
        exit;
    }

    $adminId = (int) ($_SESSION['school_admin_id'] ?? $_SESSION['super_admin_id'] ?? 0);
    applySlotMove($slotId, $newDay, $newHourSlot, $adminId);

    echo json_encode(['ok' => true]);
    exit;
}

// ============================================================
// Load the grid for display
// ============================================================
$slots = [];
$hourSlotsInUse = [];
if ($latestGeneration && $classId !== 0) {
    $stmt = db()->prepare(
        'SELECT scheduled_slots.*, subjects.name AS subject_name, extra_activities.name AS activity_name,
                teachers.name AS teacher_name, rooms.name AS room_name
         FROM scheduled_slots
         LEFT JOIN subjects ON scheduled_slots.subject_id = subjects.id
         LEFT JOIN extra_activities ON scheduled_slots.extra_activity_id = extra_activities.id
         LEFT JOIN teachers ON scheduled_slots.teacher_id = teachers.id
         LEFT JOIN rooms ON scheduled_slots.room_id = rooms.id
         WHERE scheduled_slots.generated_timetable_id = ? AND scheduled_slots.class_id = ?
         ORDER BY scheduled_slots.day_of_week, scheduled_slots.hour_slot'
    );
    $stmt->execute([$latestGeneration['id'], $classId]);
    $slots = $stmt->fetchAll();

    foreach ($slots as $s) {
        $hourSlotsInUse[$s['hour_slot']] = true;
    }
}

$days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
$hourSlotsSorted = array_keys($hourSlotsInUse);
sort($hourSlotsSorted);

$pageTitle = 'Edit Timetable — ' . $school['name'];
require __DIR__ . '/_header.php';
?>

<h2 style="margin-top:0;">Manual Timetable Adjustments</h2>
<p class="empty">
    Move a lesson to a different day or time slot. The system checks for teacher, room, and class clashes before applying the change.
</p>

<?php if (!$latestGeneration): ?>
    <div class="error">No successful timetable has been generated yet. <a href="generate.php">Generate one first</a>.</div>
<?php else: ?>
    <div class="card">
        <label for="class_select">Choose a class to edit</label>
        <select id="class_select" onchange="window.location.href = 'edit-timetable.php?class_id=' + this.value;">
            <option value="">— select a class —</option>
            <?php foreach ($classes as $c): ?>
                <option value="<?php echo (int) $c['id']; ?>" <?php echo $c['id'] == $classId ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($c['name']); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <?php if ($classId !== 0): ?>
        <div class="card" style="display:flex; gap:10px; align-items:center;">
            <a class="btn btn-secondary" href="../export/export.php?scope=class&class_id=<?php echo (int) $classId; ?>">⬇ Download PDF (this class)</a>
            <a class="btn btn-secondary" href="../export/export.php?scope=whole-school">⬇ Download PDF (whole school)</a>
        </div>
        <div id="move-feedback"></div>
        <div class="card">
            <?php if (empty($slots)): ?>
                <p class="empty">No scheduled lessons found for this class in the latest generation.</p>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Subject / Activity</th>
                            <th>Teacher</th>
                            <th>Room</th>
                            <th>Day</th>
                            <th>Time</th>
                            <th>Move to</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($slots as $s): ?>
                            <tr id="slot-row-<?php echo (int) $s['id']; ?>">
                                <td>
                                    <?php echo htmlspecialchars($s['subject_name'] ?? $s['activity_name'] ?? '—'); ?>
                                    <?php echo $s['is_manual_override'] ? ' <span style="color:#a5690a;font-size:0.75rem;">(manually moved)</span>' : ''; ?>
                                </td>
                                <td><?php echo htmlspecialchars($s['teacher_name'] ?? '—'); ?></td>
                                <td><?php echo htmlspecialchars($s['room_name'] ?? '—'); ?></td>
                                <td><?php echo htmlspecialchars($s['day_of_week']); ?></td>
                                <td><?php echo htmlspecialchars($s['hour_slot']); ?></td>
                                <td>
                                    <select class="move-day" data-slot-id="<?php echo (int) $s['id']; ?>">
                                        <?php foreach ($days as $d): ?>
                                            <option value="<?php echo htmlspecialchars($d); ?>" <?php echo $d === $s['day_of_week'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($d); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <select class="move-hour" data-slot-id="<?php echo (int) $s['id']; ?>">
                                        <?php foreach ($hourSlotsSorted as $h): ?>
                                            <option value="<?php echo htmlspecialchars($h); ?>" <?php echo $h === $s['hour_slot'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($h); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="button" class="btn-secondary" onclick="moveSlot(<?php echo (int) $s['id']; ?>)">Move</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    <?php endif; ?>
<?php endif; ?>

<script>
async function moveSlot(slotId) {
    const daySelect = document.querySelector(`.move-day[data-slot-id="${slotId}"]`);
    const hourSelect = document.querySelector(`.move-hour[data-slot-id="${slotId}"]`);
    const feedback = document.getElementById('move-feedback');

    const formData = new FormData();
    formData.append('action', 'move_slot');
    formData.append('slot_id', slotId);
    formData.append('new_day', daySelect.value);
    formData.append('new_hour_slot', hourSelect.value);

    try {
        const res = await fetch(window.location.pathname + window.location.search, {
            method: 'POST',
            body: formData,
        });
        const data = await res.json();

        if (data.ok) {
            feedback.innerHTML = '<div class="success">Moved successfully.</div>';
            setTimeout(() => window.location.reload(), 700);
        } else {
            feedback.innerHTML = '<div class="error">' + data.error + '</div>';
        }
    } catch (e) {
        feedback.innerHTML = '<div class="error">Something went wrong. Please try again.</div>';
    }
}
</script>

<?php require __DIR__ . '/_footer.php'; ?>

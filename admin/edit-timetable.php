<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../db/db.php';

$schoolId = requireLoginAndGetSchoolId();
$stmt = db()->prepare('SELECT * FROM schools WHERE id = ?');
$stmt->execute([$schoolId]);
$school = $stmt->fetch();

// Get latest successful generation
$stmt = db()->prepare(
    "SELECT * FROM generated_timetables WHERE school_id = ? AND status = 'success' ORDER BY generated_at DESC LIMIT 1"
);
$stmt->execute([$schoolId]);
$latestGeneration = $stmt->fetch();

$error = null;
$success = null;

// Handle slot move (AJAX or form)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'move_slot') {
    header('Content-Type: application/json');
    $slotId = (int) ($_POST['slot_id'] ?? 0);
    $newDay = trim($_POST['new_day'] ?? '');
    $newPeriod = (int) ($_POST['new_period'] ?? 0);

    if (!$latestGeneration || $slotId === 0 || $newDay === '' || $newPeriod < 1) {
        echo json_encode(['ok' => false, 'error' => 'Invalid request']);
        exit;
    }

    try {
        $stmt = db()->prepare(
            'SELECT scheduled_slots.* FROM scheduled_slots
             WHERE id = ? AND generated_timetable_id = ?'
        );
        $stmt->execute([$slotId, $latestGeneration['id']]);
        $slot = $stmt->fetch();
        if (!$slot) {
            echo json_encode(['ok' => false, 'error' => 'Slot not found']);
            exit;
        }

        $stmt = db()->prepare(
            'UPDATE scheduled_slots SET day_of_week = ?, period_number = ? WHERE id = ?'
        );
        $stmt->execute([$newDay, $newPeriod, $slotId]);
        echo json_encode(['ok' => true]);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// Load classes for selector
$stmt = db()->prepare('SELECT * FROM classes WHERE school_id = ? ORDER BY name');
$stmt->execute([$schoolId]);
$classes = $stmt->fetchAll();

$classId = (int) ($_GET['class_id'] ?? ($classes[0]['id'] ?? 0));
$slots = [];
if ($latestGeneration && $classId > 0) {
    $stmt = db()->prepare(
        'SELECT scheduled_slots.*, subjects.name AS subject_name, extra_activities.name AS activity_name,
                teachers.name AS teacher_name, rooms.name AS room_name
         FROM scheduled_slots
         LEFT JOIN subjects ON scheduled_slots.subject_id = subjects.id
         LEFT JOIN extra_activities ON scheduled_slots.extra_activity_id = extra_activities.id
         LEFT JOIN teachers ON scheduled_slots.teacher_id = teachers.id
         LEFT JOIN rooms ON scheduled_slots.room_id = rooms.id
         WHERE scheduled_slots.generated_timetable_id = ? AND scheduled_slots.class_id = ?
         ORDER BY scheduled_slots.day_of_week, scheduled_slots.period_number'
    );
    $stmt->execute([$latestGeneration['id'], $classId]);
    $slots = $stmt->fetchAll();
}

$days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
$pageTitle = 'Edit Timetable — ' . $school['name'];
require __DIR__ . '/_header.php';
?>

<div class="card">
    <h2>Manual Timetable Edits</h2>
    <?php if (!$latestGeneration): ?>
        <div class="error">No successful timetable generation found. <a href="generate.php">Generate one first</a>.</div>
    <?php elseif (empty($classes)): ?>
        <div class="error">No classes available.</div>
    <?php else: ?>
        <form method="get" style="margin-bottom:16px;">
            <label for="class_id">Class</label>
            <select name="class_id" id="class_id" onchange="this.form.submit()">
                <?php foreach ($classes as $c): ?>
                    <option value="<?php echo (int)$c['id']; ?>" <?php echo $classId == $c['id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($c['name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>

        <div id="feedback"></div>

        <table>
            <thead>
                <tr>
                    <th>Day</th>
                    <th>Period</th>
                    <th>Subject / Activity</th>
                    <th>Teacher</th>
                    <th>Room</th>
                    <th>Move</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($slots)): ?>
                    <tr><td colspan="6" class="empty">No scheduled slots for this class.</td></tr>
                <?php else: ?>
                    <?php foreach ($slots as $slot): ?>
                        <tr data-slot-id="<?php echo (int)$slot['id']; ?>">
                            <td><?php echo htmlspecialchars($slot['day_of_week'] ?? ''); ?></td>
                            <td><?php echo (int)($slot['period_number'] ?? 0); ?></td>
                            <td><?php echo htmlspecialchars($slot['subject_name'] ?? $slot['activity_name'] ?? '—'); ?></td>
                            <td><?php echo htmlspecialchars($slot['teacher_name'] ?? '—'); ?></td>
                            <td><?php echo htmlspecialchars($slot['room_name'] ?? '—'); ?></td>
                            <td>
                                <select class="move-day">
                                    <?php foreach ($days as $d): ?>
                                        <option value="<?php echo $d; ?>" <?php echo ($slot['day_of_week'] ?? '') === $d ? 'selected' : ''; ?>><?php echo $d; ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <input type="number" class="move-period" min="1" max="12" value="<?php echo (int)($slot['period_number'] ?? 1); ?>" style="width:60px">
                                <button type="button" class="btn-secondary btn-move">Move</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <script>
        document.querySelectorAll('.btn-move').forEach(btn => {
            btn.addEventListener('click', async () => {
                const tr = btn.closest('tr');
                const slotId = tr.dataset.slotId;
                const newDay = tr.querySelector('.move-day').value;
                const newPeriod = tr.querySelector('.move-period').value;
                const feedback = document.getElementById('feedback');
                const body = new FormData();
                body.append('action', 'move_slot');
                body.append('slot_id', slotId);
                body.append('new_day', newDay);
                body.append('new_period', newPeriod);
                try {
                    const res = await fetch('edit-timetable.php', { method: 'POST', body });
                    const data = await res.json();
                    if (data.ok) {
                        feedback.innerHTML = '<div class="success">Moved successfully.</div>';
                        setTimeout(() => location.reload(), 600);
                    } else {
                        feedback.innerHTML = '<div class="error">' + (data.error || 'Move failed') + '</div>';
                    }
                } catch (e) {
                    feedback.innerHTML = '<div class="error">Request failed</div>';
                }
            });
        });
        </script>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/_footer.php'; ?>

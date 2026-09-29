<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../db/db.php';
require_once __DIR__ . '/generate_engine.php';

$schoolId = requireLoginAndGetSchoolId();
$stmt = db()->prepare('SELECT * FROM schools WHERE id = ?');
$stmt->execute([$schoolId]);
$school = $stmt->fetch();

// Latest generated timetable for this school
$stmt = db()->prepare(
    "SELECT * FROM generated_timetables WHERE school_id = ? AND status = 'success' ORDER BY generated_at DESC LIMIT 1"
);
$stmt->execute([$schoolId]);
$latestGeneration = $stmt->fetch();

$editMode = $_GET['mode'] ?? 'class'; // class, teacher, room, subject
$selectedId = (int) ($_GET['selected_id'] ?? 0);
$previewMode = $_GET['preview'] ?? false;

// Get data based on edit mode
$editItems = [];
switch ($editMode) {
    case 'class':
        $editItems = getClasses($schoolId);
        break;
    case 'teacher':
        $stmt = db()->prepare('SELECT * FROM teachers WHERE school_id = ? ORDER BY name');
        $stmt->execute([$schoolId]);
        $editItems = $stmt->fetchAll();
        break;
    case 'room':
        $stmt = db()->prepare('SELECT * FROM rooms WHERE school_id = ? ORDER BY name');
        $stmt->execute([$schoolId]);
        $editItems = $stmt->fetchAll();
        break;
    case 'subject':
        $stmt = db()->prepare('SELECT DISTINCT id, name FROM subjects WHERE school_id = ? ORDER BY name');
        $stmt->execute([$schoolId]);
        $editItems = $stmt->fetchAll();
        break;
}

$error = null;
$success = null;

// Handle slot move request
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

// Handle slot swap request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'swap_slots') {
    header('Content-Type: application/json');

    if (!$latestGeneration) {
        echo json_encode(['ok' => false, 'error' => 'No generated timetable to edit.']);
        exit;
    }

    $slotId1 = (int) ($_POST['slot_id_1'] ?? 0);
    $slotId2 = (int) ($_POST['slot_id_2'] ?? 0);

    // Get both slots
    $stmt = db()->prepare(
        'SELECT * FROM scheduled_slots WHERE id IN (?, ?) AND generated_timetable_id = ?'
    );
    $stmt->execute([$slotId1, $slotId2, $latestGeneration['id']]);
    $slots = $stmt->fetchAll();

    if (count($slots) !== 2) {
        echo json_encode(['ok' => false, 'error' => 'Both slots not found.']);
        exit;
    }

    // Check for clashes after swap
    $slot1 = $slots[0];
    $slot2 = $slots[1];

    $clash1 = checkSlotMoveForClash(
        (int) $latestGeneration['id'],
        $slotId1,
        (int) $slot1['teacher_id'],
        $slot1['room_id'] !== null ? (int) $slot1['room_id'] : null,
        (int) $slot1['class_id'],
        $slot2['day_of_week'],
        $slot2['hour_slot']
    );

    $clash2 = checkSlotMoveForClash(
        (int) $latestGeneration['id'],
        $slotId2,
        (int) $slot2['teacher_id'],
        $slot2['room_id'] !== null ? (int) $slot2['room_id'] : null,
        (int) $slot2['class_id'],
        $slot1['day_of_week'],
        $slot1['hour_slot']
    );

    if ($clash1 !== null || $clash2 !== null) {
        echo json_encode(['ok' => false, 'error' => 'Swap would cause: ' . ($clash1 ?: $clash2)]);
        exit;
    }

    // Perform swap
    $adminId = (int) ($_SESSION['school_admin_id'] ?? $_SESSION['super_admin_id'] ?? 0);
    
    db()->beginTransaction();
    try {
        // Update slot 1 to slot 2's position
        $stmt = db()->prepare(
            'UPDATE scheduled_slots SET day_of_week = ?, hour_slot = ?, is_manual_override = TRUE, manual_override_by = ? WHERE id = ?'
        );
        $stmt->execute([$slot2['day_of_week'], $slot2['hour_slot'], $adminId, $slotId1]);

        // Update slot 2 to slot 1's position
        $stmt = db()->prepare(
            'UPDATE scheduled_slots SET day_of_week = ?, hour_slot = ?, is_manual_override = TRUE, manual_override_by = ? WHERE id = ?'
        );
        $stmt->execute([$slot1['day_of_week'], $slot1['hour_slot'], $adminId, $slotId2]);

        db()->commit();
        echo json_encode(['ok' => true]);
    } catch (Throwable $e) {
        db()->rollBack();
        echo json_encode(['ok' => false, 'error' => 'Swap failed: ' . $e->getMessage()]);
    }
    exit;
}

// Load the grid for display
$slots = [];
$hourSlotsInUse = [];
if ($latestGeneration && $selectedId !== 0) {
    $whereClause = '';
    $params = [$latestGeneration['id'], $selectedId];

    switch ($editMode) {
        case 'class':
            $whereClause = 'scheduled_slots.class_id = ?';
            break;
        case 'teacher':
            $whereClause = 'scheduled_slots.teacher_id = ?';
            break;
        case 'room':
            $whereClause = 'scheduled_slots.room_id = ?';
            break;
        case 'subject':
            $whereClause = 'scheduled_slots.subject_id = ?';
            break;
    }

    $stmt = db()->prepare(
        "SELECT scheduled_slots.*, subjects.name AS subject_name, extra_activities.name AS activity_name,
                teachers.name AS teacher_name, rooms.name AS room_name,
                classes.name AS class_name
         FROM scheduled_slots
         LEFT JOIN subjects ON scheduled_slots.subject_id = subjects.id
         LEFT JOIN extra_activities ON scheduled_slots.extra_activity_id = extra_activities.id
         LEFT JOIN teachers ON scheduled_slots.teacher_id = teachers.id
         LEFT JOIN rooms ON scheduled_slots.room_id = rooms.id
         LEFT JOIN classes ON scheduled_slots.class_id = classes.id
         WHERE scheduled_slots.generated_timetable_id = ? AND $whereClause
         ORDER BY scheduled_slots.day_of_week, scheduled_slots.hour_slot"
    );
    $stmt->execute($params);
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

<h2 style="margin-top:0;">Advanced Timetable Editor</h2>
<p class="empty">
    Move, swap, or adjust timetable slots with real-time preview and conflict detection.
</p>

<?php if (!$latestGeneration): ?>
    <div class="error">No successful timetable has been generated yet. <a href="generate.php">Generate one first</a>.</div>
<?php else: ?>
    <div class="card">
        <div class="flex flex-col md:flex-row gap-4 mb-4">
            <div class="flex-1">
                <label for="edit_mode">Edit By</label>
                <select id="edit_mode" onchange="changeEditMode()" class="w-full">
                    <option value="class" <?php echo $editMode === 'class' ? 'selected' : ''; ?>>Class</option>
                    <option value="teacher" <?php echo $editMode === 'teacher' ? 'selected' : ''; ?>>Teacher</option>
                    <option value="room" <?php echo $editMode === 'room' ? 'selected' : ''; ?>>Room</option>
                    <option value="subject" <?php echo $editMode === 'subject' ? 'selected' : ''; ?>>Subject</option>
                </select>
            </div>
            <div class="flex-1">
                <label for="selected_item">Select <?php echo ucfirst($editMode); ?></label>
                <select id="selected_item" onchange="changeSelectedItem()" class="w-full">
                    <option value="">— select <?php echo $editMode; ?> —</option>
                    <?php foreach ($editItems as $item): ?>
                        <option value="<?php echo (int) $item['id']; ?>" <?php echo $item['id'] == $selectedId ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($item['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        
        <div class="flex gap-2 flex-wrap">
            <button type="button" class="btn btn-secondary" onclick="togglePreviewMode()">
                <?php echo $previewMode ? '📋 Edit Mode' : '👁️ Preview Mode'; ?>
            </button>
            <a class="btn btn-secondary" href="../export/export.php?scope=whole-school">⬇ Download PDF (whole school)</a>
        </div>
    </div>

    <?php if ($selectedId !== 0): ?>
        <div id="move-feedback"></div>
        
        <!-- Preview Mode -->
        <?php if ($previewMode): ?>
            <div class="card">
                <h3>👁️ Preview Mode - Read-only View</h3>
                <p class="empty">Switch to Edit Mode to make changes.</p>
                <?php renderTimetableGrid($slots, $days, $hourSlotsSorted, $editMode, true); ?>
            </div>
        <?php else: ?>
            <!-- Edit Mode -->
            <div class="card">
                <h3>✏️ Edit Mode - Make Changes</h3>
                <p class="empty">Use dropdowns to move slots, or select two slots to swap them.</p>
                <div class="mb-4">
                    <button type="button" class="btn btn-secondary" onclick="enableSwapMode()">🔄 Enable Swap Mode</button>
                    <span id="swap-status" class="text-muted text-sm ml-2">Select first slot to swap</span>
                </div>
                <?php renderTimetableGrid($slots, $days, $hourSlotsSorted, $editMode, false); ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
<?php endif; ?>

<?php
function renderTimetableGrid($slots, $days, $hourSlots, $editMode, $isPreview) {
    if (empty($slots)) {
        echo '<p class="empty">No scheduled slots found for this selection.</p>';
        return;
    }
    
    // Organize slots by day and hour
    $grid = [];
    foreach ($slots as $slot) {
        $day = $slot['day_of_week'];
        $hour = $slot['hour_slot'];
        if (!isset($grid[$day])) {
            $grid[$day] = [];
        }
        $grid[$day][$hour] = $slot;
    }
?>
    <div class="overflow-x-auto">
        <table class="min-w-full">
            <thead>
                <tr>
                    <th>Time</th>
                    <?php foreach ($days as $day): ?>
                        <th><?php echo htmlspecialchars($day); ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($hourSlots as $hour): ?>
                    <tr>
                        <td class="font-semibold"><?php echo htmlspecialchars($hour); ?></td>
                        <?php foreach ($days as $day): ?>
                            <td class="border border-gray-200 p-2 min-w-[150px]">
                                <?php if (isset($grid[$day][$hour])): ?>
                                    <?php $slot = $grid[$day][$hour]; ?>
                                    <div class="bg-light p-2 rounded text-sm" 
                                         data-slot-id="<?php echo (int) $slot['id']; ?>"
                                         onclick="<?php echo $isPreview ? '' : 'selectSlotForSwap(' . (int) $slot['id'] . ')'; ?>"
                                         style="cursor: <?php echo $isPreview ? 'default' : 'pointer'; ?>;">
                                        <div class="font-semibold text-primary">
                                            <?php echo htmlspecialchars($slot['subject_name'] ?? $slot['activity_name'] ?? '—'); ?>
                                        </div>
                                        <div class="text-muted text-xs">
                                            <?php if ($editMode !== 'teacher'): ?>
                                                👤 <?php echo htmlspecialchars($slot['teacher_name'] ?? '—'); ?>
                                            <?php endif; ?>
                                            <?php if ($editMode !== 'room'): ?>
                                                🏠 <?php echo htmlspecialchars($slot['room_name'] ?? '—'); ?>
                                            <?php endif; ?>
                                            <?php if ($editMode !== 'class'): ?>
                                                📚 <?php echo htmlspecialchars($slot['class_name'] ?? '—'); ?>
                                            <?php endif; ?>
                                        </div>
                                        <?php if ($slot['is_manual_override']): ?>
                                            <span class="text-warning text-xs">✏️ Manual</span>
                                        <?php endif; ?>
                                    </div>
                                <?php else: ?>
                                    <div class="text-muted text-xs text-center py-2">—</div>
                                <?php endif; ?>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php
}
?>

<script>
let swapMode = false;
let selectedSlotId = null;

function changeEditMode() {
    const mode = document.getElementById('edit_mode').value;
    window.location.href = 'edit-timetable.php?mode=' + mode;
}

function changeSelectedItem() {
    const mode = document.getElementById('edit_mode').value;
    const selectedId = document.getElementById('selected_item').value;
    window.location.href = 'edit-timetable.php?mode=' + mode + '&selected_id=' + selectedId;
}

function togglePreviewMode() {
    const currentPreview = <?php echo $previewMode ? 'true' : 'false'; ?>;
    const newPreview = !currentPreview;
    const mode = document.getElementById('edit_mode').value;
    const selectedId = document.getElementById('selected_item').value;
    window.location.href = 'edit-timetable.php?mode=' + mode + '&selected_id=' + selectedId + '&preview=' + (newPreview ? '1' : '0');
}

function enableSwapMode() {
    swapMode = !swapMode;
    selectedSlotId = null;
    document.getElementById('swap-status').textContent = swapMode ? 'Select first slot to swap' : 'Swap mode disabled';
    
    // Highlight slots that can be swapped
    document.querySelectorAll('[data-slot-id]').forEach(el => {
        el.style.border = swapMode ? '2px solid var(--primary)' : 'none';
    });
}

function selectSlotForSwap(slotId) {
    if (!swapMode) return;
    
    if (selectedSlotId === null) {
        selectedSlotId = slotId;
        document.getElementById('swap-status').textContent = 'Select second slot to swap';
        document.querySelector(`[data-slot-id="${slotId}"]`).style.backgroundColor = 'var(--primary-bg)';
    } else if (selectedSlotId !== slotId) {
        // Perform swap
        swapSlots(selectedSlotId, slotId);
    } else {
        // Deselect
        selectedSlotId = null;
        document.getElementById('swap-status').textContent = 'Select first slot to swap';
        document.querySelector(`[data-slot-id="${slotId}"]`).style.backgroundColor = '';
    }
}

async function swapSlots(slotId1, slotId2) {
    const feedback = document.getElementById('move-feedback');
    
    const formData = new FormData();
    formData.append('action', 'swap_slots');
    formData.append('slot_id_1', slotId1);
    formData.append('slot_id_2', slotId2);

    try {
        const res = await fetch(window.location.pathname + window.location.search, {
            method: 'POST',
            body: formData,
        });
        const data = await res.json();

        if (data.ok) {
            feedback.innerHTML = '<div class="success">Slots swapped successfully.</div>';
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

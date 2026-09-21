<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../db/db.php';
require_once __DIR__ . '/../db/audit_log.php';

$schoolId = requireLoginAndGetSchoolId();
$stmt = db()->prepare('SELECT * FROM schools WHERE id = ?');
$stmt->execute([$schoolId]);
$school = $stmt->fetch();

// Get classes and bands for dropdowns
$stmt = db()->prepare('SELECT c.*, b.label as band_label FROM classes c JOIN bands b ON c.band_id = b.id WHERE c.school_id = ? AND c.active = TRUE ORDER BY c.name');
$stmt->execute([$schoolId]);
$classes = $stmt->fetchAll();

// Get teachers for dropdown
$stmt = db()->prepare('SELECT * FROM teachers WHERE school_id = ? ORDER BY name');
$stmt->execute([$schoolId]);
$teachers = $stmt->fetchAll();

// Get rooms for dropdown
$stmt = db()->prepare('SELECT * FROM rooms WHERE school_id = ? ORDER BY name');
$stmt->execute([$schoolId]);
$rooms = $stmt->fetchAll();

$error = null;
$success = null;
$search = trim($_GET['search'] ?? '');
$classFilter = (int) ($_GET['class_id'] ?? 0);
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

// Handle bulk delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'bulk_delete') {
    $activityIds = $_POST['activity_ids'] ?? [];
    if (!empty($activityIds)) {
        $placeholders = str_repeat('?,', count($activityIds) - 1) . '?';
        try {
            $stmt = db()->prepare("DELETE FROM extra_activities WHERE id IN ($placeholders) AND school_id = ?");
            $params = array_merge($activityIds, [$schoolId]);
            $stmt->execute($params);
            $success = count($activityIds) . ' activit(y/ies) deleted successfully.';
            logAudit('bulk_delete', 'activity', null, ['count' => count($activityIds), 'ids' => $activityIds]);
        } catch (Throwable $e) {
            $error = 'Could not delete activities.';
        }
    }
}

// Handle extra activity creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
    $classId = (int) ($_POST['class_id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $dayOfWeek = $_POST['day_of_week'] ?? '';
    $durationSlots = (int) ($_POST['duration_slots'] ?? 1);
    $frequency = $_POST['frequency'] ?? 'weekly';
    $teacherId = $_POST['teacher_id'] ?? '';
    $roomId = $_POST['room_id'] ?? '';
    
    if ($classId === 0 || $name === '') {
        $error = 'Class and activity name are required.';
    } else {
        try {
            $stmt = db()->prepare('SELECT band_id FROM classes WHERE id = ? AND school_id = ?');
            $stmt->execute([$classId, $schoolId]);
            $class = $stmt->fetch();
            
            if (!$class) {
                $error = 'Invalid class selected.';
            } else {
                $stmt = db()->prepare(
                    'INSERT INTO extra_activities (school_id, band_id, class_id, name, day_of_week, duration_slots, frequency, assigned_teacher_id, room_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $stmt->execute([
                    $schoolId,
                    $class['band_id'],
                    $classId,
                    $name,
                    $dayOfWeek !== '' ? $dayOfWeek : null,
                    $durationSlots,
                    $frequency,
                    $teacherId !== '' ? (int) $teacherId : null,
                    $roomId !== '' ? (int) $roomId : null,
                ]);
                $success = 'Extra activity added successfully.';
                logAudit('create', 'activity', (int) db()->lastInsertId(), ['name' => $name, 'class_id' => $classId]);
            }
        } catch (Throwable $e) {
            $error = 'Could not add extra activity.';
        }
    }
}

// Handle extra activity deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $activityId = (int) ($_POST['activity_id'] ?? 0);
    try {
        $stmt = db()->prepare('DELETE FROM extra_activities WHERE id = ? AND school_id = ?');
        $stmt->execute([$activityId, $schoolId]);
        $success = 'Extra activity deleted successfully.';
        logAudit('delete', 'activity', $activityId);
    } catch (Throwable $e) {
        $error = 'Could not delete extra activity.';
    }
}

// Handle extra activity update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update') {
    $activityId = (int) ($_POST['activity_id'] ?? 0);
    $classId = (int) ($_POST['class_id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $dayOfWeek = $_POST['day_of_week'] ?? '';
    $durationSlots = (int) ($_POST['duration_slots'] ?? 1);
    $frequency = $_POST['frequency'] ?? 'weekly';
    $teacherId = $_POST['teacher_id'] ?? '';
    $roomId = $_POST['room_id'] ?? '';
    
    if ($classId === 0 || $name === '') {
        $error = 'Class and activity name are required.';
    } else {
        try {
            $stmt = db()->prepare('SELECT band_id FROM classes WHERE id = ? AND school_id = ?');
            $stmt->execute([$classId, $schoolId]);
            $class = $stmt->fetch();
            
            if (!$class) {
                $error = 'Invalid class selected.';
            } else {
                $stmt = db()->prepare(
                    'UPDATE extra_activities SET class_id = ?, band_id = ?, name = ?, day_of_week = ?, duration_slots = ?, frequency = ?, assigned_teacher_id = ?, room_id = ? WHERE id = ? AND school_id = ?'
                );
                $stmt->execute([
                    $classId,
                    $class['band_id'],
                    $name,
                    $dayOfWeek !== '' ? $dayOfWeek : null,
                    $durationSlots,
                    $frequency,
                    $teacherId !== '' ? (int) $teacherId : null,
                    $roomId !== '' ? (int) $roomId : null,
                    $activityId,
                    $schoolId,
                ]);
                $success = 'Extra activity updated successfully.';
                logAudit('update', 'activity', $activityId, ['name' => $name, 'class_id' => $classId]);
            }
        } catch (Throwable $e) {
            $error = 'Could not update extra activity.';
        }
    }
}

if ($search !== '' || $classFilter !== 0) {
    $sql = 'SELECT COUNT(*) FROM extra_activities ea WHERE ea.school_id = ?';
    $params = [$schoolId];
    
    if ($search !== '') {
        $sql .= ' AND ea.name LIKE ?';
        $searchParam = '%' . $search . '%';
        $params[] = $searchParam;
    }
    
    if ($classFilter !== 0) {
        $sql .= ' AND ea.class_id = ?';
        $params[] = $classFilter;
    }
    
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $totalCount = $stmt->fetchColumn();
    
    $sql = 'SELECT ea.*, c.name as class_name, b.label as band_label, t.name as teacher_name, r.name as room_name FROM extra_activities ea LEFT JOIN classes c ON ea.class_id = c.id LEFT JOIN bands b ON ea.band_id = b.id LEFT JOIN teachers t ON ea.assigned_teacher_id = t.id LEFT JOIN rooms r ON ea.room_id = r.id WHERE ea.school_id = ?';
    $params = [$schoolId];
    
    if ($search !== '') {
        $sql .= ' AND ea.name LIKE ?';
        $searchParam = '%' . $search . '%';
        $params[] = $searchParam;
    }
    
    if ($classFilter !== 0) {
        $sql .= ' AND ea.class_id = ?';
        $params[] = $classFilter;
    }
    
    $sql .= ' ORDER BY c.name, ea.name LIMIT ? OFFSET ?';
    $params[] = $perPage;
    $params[] = $offset;
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
} else {
    $stmt = db()->prepare('SELECT COUNT(*) FROM extra_activities WHERE school_id = ?');
    $stmt->execute([$schoolId]);
    $totalCount = $stmt->fetchColumn();
    
    $stmt = db()->prepare('SELECT ea.*, c.name as class_name, b.label as band_label, t.name as teacher_name, r.name as room_name FROM extra_activities ea LEFT JOIN classes c ON ea.class_id = c.id LEFT JOIN bands b ON ea.band_id = b.id LEFT JOIN teachers t ON ea.assigned_teacher_id = t.id LEFT JOIN rooms r ON ea.room_id = r.id WHERE ea.school_id = ? ORDER BY c.name, ea.name LIMIT ? OFFSET ?');
    $stmt->execute([$schoolId, $perPage, $offset]);
}
$activities = $stmt->fetchAll();
$totalPages = (int) ceil($totalCount / $perPage);

$pageTitle = 'Extra Activities — ' . $school['name'];
require __DIR__ . '/_header.php';
?>

<div class="card">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 10px;">
        <h2 style="margin: 0;">Extra Activities</h2>
        <form method="get" style="display: flex; gap: 8px; margin: 0; flex-wrap: wrap;">
            <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search activities..." style="width: 200px; margin: 0;">
            <select name="class_id" style="width: 150px; margin: 0;">
                <option value="">All Classes</option>
                <?php foreach ($classes as $class): ?>
                    <option value="<?php echo (int) $class['id']; ?>" <?php echo $classFilter == $class['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($class['name']); ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" style="margin: 0;">Filter</button>
            <?php if ($search !== '' || $classFilter !== 0): ?>
                <a href="activities.php" class="btn btn-secondary" style="padding: 10px 16px;">Clear</a>
            <?php endif; ?>
        </form>
        <div style="display: flex; gap: 8px;">
            <a href="export_csv.php?entity=activities&search=<?php echo htmlspecialchars($search); ?>&class_id=<?php echo $classFilter; ?>&format=csv" class="btn btn-secondary" style="padding: 10px 16px;">Export CSV</a>
            <a href="export_csv.php?entity=activities&search=<?php echo htmlspecialchars($search); ?>&class_id=<?php echo $classFilter; ?>&format=xlsx" class="btn btn-secondary" style="padding: 10px 16px;">Export Excel</a>
        </div>
    </div>
    <p class="empty">Clubs, parade, assembly, and other non-subject activities.</p>
    
    <?php if ($error): ?>
        <div class="error"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
    
    <?php if ($success): ?>
        <div class="success"><?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>
    
    <?php if (empty($classes)): ?>
        <div class="error">You need to create classes first before adding extra activities.</div>
        <a href="classes.php" class="btn">Manage Classes</a>
    <?php else: ?>
        <form method="post">
            <input type="hidden" name="action" value="create">
            <label for="class_id">Class</label>
            <select id="class_id" name="class_id" required>
                <?php foreach ($classes as $class): ?>
                    <option value="<?php echo (int) $class['id']; ?>"><?php echo htmlspecialchars($class['name']); ?> (<?php echo htmlspecialchars($class['band_label']); ?>)</option>
                <?php endforeach; ?>
            </select>
            
            <label for="name">Activity Name</label>
            <input type="text" id="name" name="name" required placeholder="e.g. Parade, Club - Chess">
            
            <label for="day_of_week">Day of Week (optional - leave empty for flexible scheduling)</label>
            <select id="day_of_week" name="day_of_week">
                <option value="">— Flexible —</option>
                <option value="Monday">Monday</option>
                <option value="Tuesday">Tuesday</option>
                <option value="Wednesday">Wednesday</option>
                <option value="Thursday">Thursday</option>
                <option value="Friday">Friday</option>
            </select>
            
            <label for="duration_slots">Duration (slots)</label>
            <input type="number" id="duration_slots" name="duration_slots" value="1" min="1" required>
            
            <label for="frequency">Frequency</label>
            <select id="frequency" name="frequency" required>
                <option value="weekly">Weekly</option>
                <option value="daily">Daily</option>
                <option value="specific-day">Specific Day Only</option>
            </select>
            
            <label for="teacher_id">Assigned Teacher (optional)</label>
            <select id="teacher_id" name="teacher_id">
                <option value="">— Unassigned —</option>
                <?php foreach ($teachers as $teacher): ?>
                    <option value="<?php echo (int) $teacher['id']; ?>"><?php echo htmlspecialchars($teacher['name']); ?></option>
                <?php endforeach; ?>
            </select>
            
            <label for="room_id">Room (optional)</label>
            <select id="room_id" name="room_id">
                <option value="">— No room —</option>
                <?php foreach ($rooms as $room): ?>
                    <option value="<?php echo (int) $room['id']; ?>"><?php echo htmlspecialchars($room['name']); ?></option>
                <?php endforeach; ?>
            </select>
            
            <button type="submit">Add Extra Activity</button>
        </form>
    <?php endif; ?>
</div>

<div class="card">
    <h2>Existing Extra Activities (<?php echo count($activities); ?>)</h2>
    <?php if (empty($activities)): ?>
        <p class="empty">No extra activities added yet.</p>
    <?php else: ?>
        <table>
            <thead><tr><th><input type="checkbox" id="select-all" onchange="toggleAllCheckboxes(this)"></th><th>Class</th><th>Activity</th><th>Day</th><th>Duration</th><th>Frequency</th><th>Teacher</th><th>Room</th><th>Actions</th></tr></thead>
            <tbody>
                <?php foreach ($activities as $activity): ?>
                    <tr>
                        <td><input type="checkbox" name="activity_ids[]" value="<?php echo (int) $activity['id']; ?>" class="row-checkbox"></td>
                        <td><?php echo htmlspecialchars($activity['class_name']); ?></td>
                        <td><?php echo htmlspecialchars($activity['name']); ?></td>
                        <td><?php echo htmlspecialchars($activity['day_of_week'] ?? 'Flexible'); ?></td>
                        <td><?php echo (int) $activity['duration_slots']; ?> slot(s)</td>
                        <td><?php echo htmlspecialchars(ucfirst($activity['frequency'])); ?></td>
                        <td><?php echo htmlspecialchars($activity['teacher_name'] ?? '—'); ?></td>
                        <td><?php echo htmlspecialchars($activity['room_name'] ?? '—'); ?></td>
                        <td class="row-actions">
                            <button type="button" onclick="showEditForm(<?php echo (int) $activity['id']; ?>, <?php echo (int) $activity['class_id']; ?>, '<?php echo htmlspecialchars($activity['name'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($activity['day_of_week'] ?? '', ENT_QUOTES); ?>', <?php echo (int) $activity['duration_slots']; ?>, '<?php echo htmlspecialchars($activity['frequency'], ENT_QUOTES); ?>', <?php echo isset($activity['assigned_teacher_id']) ? (int) $activity['assigned_teacher_id'] : 'null'; ?>, <?php echo isset($activity['room_id']) ? (int) $activity['room_id'] : 'null'; ?>)" class="btn-secondary" style="padding: 8px 12px;">Edit</button>
                            <form method="post" onsubmit="return confirmDelete('Delete this activity?');">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="activity_id" value="<?php echo (int) $activity['id']; ?>">
                                <button type="submit" class="btn-danger">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        
        <?php if (!empty($activities)): ?>
        <div style="margin-top: 16px; display: flex; gap: 8px;">
            <form method="post" onsubmit="return confirmDelete('Delete selected activities?');">
                <input type="hidden" name="action" value="bulk_delete">
                <button type="submit" class="btn-danger">Delete Selected</button>
            </form>
            <span style="color: var(--text-muted); font-size: 0.875rem; padding: 10px 0;">
                <span id="selected-count">0</span> selected
            </span>
        </div>
        <script>
        function toggleAllCheckboxes(source) {
            const checkboxes = document.querySelectorAll('.row-checkbox');
            checkboxes.forEach(cb => cb.checked = source.checked);
            updateSelectedCount();
        }
        
        function updateSelectedCount() {
            const checked = document.querySelectorAll('.row-checkbox:checked');
            document.getElementById('selected-count').textContent = checked.length;
        }
        
        document.querySelectorAll('.row-checkbox').forEach(cb => {
            cb.addEventListener('change', updateSelectedCount);
        });
        </script>
        <?php endif; ?>
        
        <?php if ($totalPages > 1): ?>
        <div class="pagination" style="display: flex; justify-content: center; align-items: center; gap: 8px; margin-top: 20px;">
            <?php if ($page > 1): ?>
                <a href="?search=<?php echo htmlspecialchars($search); ?>&class_id=<?php echo $classFilter; ?>&page=<?php echo $page - 1; ?>" class="btn btn-secondary" style="padding: 8px 16px;">← Previous</a>
            <?php endif; ?>
            <span style="color: var(--text-muted); font-size: 0.875rem;">Page <?php echo $page; ?> of <?php echo $totalPages; ?> (<?php echo $totalCount; ?> total)</span>
            <?php if ($page < $totalPages): ?>
                <a href="?search=<?php echo htmlspecialchars($search); ?>&class_id=<?php echo $classFilter; ?>&page=<?php echo $page + 1; ?>" class="btn btn-secondary" style="padding: 8px 16px;">Next →</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<!-- Edit Activity Modal -->
<div id="edit-modal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center;">
    <div class="card" style="max-width: 500px; width: 90%; max-height: 90vh; overflow-y: auto;">
        <h2>Edit Extra Activity</h2>
        <form method="post">
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="activity_id" id="edit-activity-id">
            
            <label for="edit-class_id">Class</label>
            <select id="edit-class_id" name="class_id" required>
                <?php foreach ($classes as $class): ?>
                    <option value="<?php echo (int) $class['id']; ?>"><?php echo htmlspecialchars($class['name']); ?> (<?php echo htmlspecialchars($class['band_label']); ?>)</option>
                <?php endforeach; ?>
            </select>
            
            <label for="edit-name">Activity Name</label>
            <input type="text" id="edit-name" name="name" required placeholder="e.g. Parade, Club - Chess">
            
            <label for="edit-day_of_week">Day of Week (optional - leave empty for flexible scheduling)</label>
            <select id="edit-day_of_week" name="day_of_week">
                <option value="">— Flexible —</option>
                <option value="Monday">Monday</option>
                <option value="Tuesday">Tuesday</option>
                <option value="Wednesday">Wednesday</option>
                <option value="Thursday">Thursday</option>
                <option value="Friday">Friday</option>
            </select>
            
            <label for="edit-duration_slots">Duration (slots)</label>
            <input type="number" id="edit-duration_slots" name="duration_slots" value="1" min="1" required>
            
            <label for="edit-frequency">Frequency</label>
            <select id="edit-frequency" name="frequency" required>
                <option value="weekly">Weekly</option>
                <option value="daily">Daily</option>
                <option value="specific-day">Specific Day Only</option>
            </select>
            
            <label for="edit-teacher_id">Assigned Teacher (optional)</label>
            <select id="edit-teacher_id" name="teacher_id">
                <option value="">— Unassigned —</option>
                <?php foreach ($teachers as $teacher): ?>
                    <option value="<?php echo (int) $teacher['id']; ?>"><?php echo htmlspecialchars($teacher['name']); ?></option>
                <?php endforeach; ?>
            </select>
            
            <label for="edit-room_id">Room (optional)</label>
            <select id="edit-room_id" name="room_id">
                <option value="">— No room —</option>
                <?php foreach ($rooms as $room): ?>
                    <option value="<?php echo (int) $room['id']; ?>"><?php echo htmlspecialchars($room['name']); ?></option>
                <?php endforeach; ?>
            </select>
            
            <div style="display: flex; gap: 8px; margin-top: 16px;">
                <button type="submit">Update Activity</button>
                <button type="button" onclick="hideEditForm()" class="btn-secondary">Cancel</button>
            </div>
        </form>
    </div>
</div>

<script>
function showEditForm(activityId, classId, name, dayOfWeek, durationSlots, frequency, teacherId, roomId) {
    document.getElementById('edit-activity-id').value = activityId;
    document.getElementById('edit-class_id').value = classId;
    document.getElementById('edit-name').value = name;
    document.getElementById('edit-day_of_week').value = dayOfWeek || '';
    document.getElementById('edit-duration_slots').value = durationSlots;
    document.getElementById('edit-frequency').value = frequency;
    document.getElementById('edit-teacher_id').value = teacherId || '';
    document.getElementById('edit-room_id').value = roomId || '';
    document.getElementById('edit-modal').style.display = 'flex';
}

function hideEditForm() {
    document.getElementById('edit-modal').style.display = 'none';
}

// Close modal when clicking outside
document.getElementById('edit-modal').addEventListener('click', function(e) {
    if (e.target === this) {
        hideEditForm();
    }
});
</script>

<?php require __DIR__ . '/_footer.php'; ?>

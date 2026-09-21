<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../db/db.php';
require_once __DIR__ . '/../db/audit_log.php';

$schoolId = requireLoginAndGetSchoolId();
$stmt = db()->prepare('SELECT * FROM schools WHERE id = ?');
$stmt->execute([$schoolId]);
$school = $stmt->fetch();

$error = null;
$success = null;
$search = trim($_GET['search'] ?? '');
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

// Handle bulk delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'bulk_delete') {
    $teacherIds = $_POST['teacher_ids'] ?? [];
    if (!empty($teacherIds)) {
        $placeholders = str_repeat('?,', count($teacherIds) - 1) . '?';
        try {
            $stmt = db()->prepare("DELETE FROM teachers WHERE id IN ($placeholders) AND school_id = ?");
            $params = array_merge($teacherIds, [$schoolId]);
            $stmt->execute($params);
            $success = count($teacherIds) . ' teacher(s) deleted successfully.';
            logAudit('bulk_delete', 'teacher', null, ['count' => count($teacherIds), 'ids' => $teacherIds]);
        } catch (Throwable $e) {
            $error = 'Could not delete teachers. They may be assigned to subjects.';
        }
    }
}

// Handle teacher creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
    $name = trim($_POST['name'] ?? '');
    $staffId = trim($_POST['staff_id'] ?? '');
    $tscNumber = trim($_POST['tsc_number'] ?? '');
    $maxLessons = $_POST['max_lessons_per_week'] ?? '';

    if ($name === '') {
        $error = 'Teacher name is required.';
    } else {
        try {
            $stmt = db()->prepare(
                'INSERT INTO teachers (school_id, staff_id, tsc_number, name, max_lessons_per_week) VALUES (?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $schoolId,
                $staffId !== '' ? $staffId : null,
                $tscNumber !== '' ? $tscNumber : null,
                $name,
                $maxLessons !== '' ? (int) $maxLessons : null,
            ]);
            $success = 'Teacher added successfully.';
            logAudit('create', 'teacher', (int) db()->lastInsertId(), ['name' => $name, 'staff_id' => $staffId, 'tsc_number' => $tscNumber]);
        } catch (Throwable $e) {
            $error = 'Could not add teacher. TSC number may already be in use.';
        }
    }
}

// Handle teacher deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $teacherId = (int) ($_POST['teacher_id'] ?? 0);
    try {
        $stmt = db()->prepare('DELETE FROM teachers WHERE id = ? AND school_id = ?');
        $stmt->execute([$teacherId, $schoolId]);
        $success = 'Teacher deleted successfully.';
        logAudit('delete', 'teacher', $teacherId);
    } catch (Throwable $e) {
        $error = 'Could not delete teacher. They may be assigned to subjects.';
    }
}

// Handle teacher update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update') {
    $teacherId = (int) ($_POST['teacher_id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $staffId = trim($_POST['staff_id'] ?? '');
    $tscNumber = trim($_POST['tsc_number'] ?? '');
    $maxLessons = $_POST['max_lessons_per_week'] ?? '';

    if ($name === '') {
        $error = 'Teacher name is required.';
    } else {
        try {
            $stmt = db()->prepare(
                'UPDATE teachers SET staff_id = ?, tsc_number = ?, name = ?, max_lessons_per_week = ? WHERE id = ? AND school_id = ?'
            );
            $stmt->execute([
                $staffId !== '' ? $staffId : null,
                $tscNumber !== '' ? $tscNumber : null,
                $name,
                $maxLessons !== '' ? (int) $maxLessons : null,
                $teacherId,
                $schoolId,
            ]);
            $success = 'Teacher updated successfully.';
            logAudit('update', 'teacher', $teacherId, ['name' => $name, 'staff_id' => $staffId, 'tsc_number' => $tscNumber]);
        } catch (Throwable $e) {
            $error = 'Could not update teacher. TSC number may already be in use.';
        }
    }
}

if ($search !== '') {
    $stmt = db()->prepare('SELECT COUNT(*) FROM teachers WHERE school_id = ? AND (name LIKE ? OR staff_id LIKE ? OR tsc_number LIKE ?)');
    $searchParam = '%' . $search . '%';
    $stmt->execute([$schoolId, $searchParam, $searchParam, $searchParam]);
    $totalCount = $stmt->fetchColumn();

    $stmt = db()->prepare('SELECT * FROM teachers WHERE school_id = ? AND (name LIKE ? OR staff_id LIKE ? OR tsc_number LIKE ?) ORDER BY name LIMIT ? OFFSET ?');
    $stmt->execute([$schoolId, $searchParam, $searchParam, $searchParam, $perPage, $offset]);
} else {
    $stmt = db()->prepare('SELECT COUNT(*) FROM teachers WHERE school_id = ?');
    $stmt->execute([$schoolId]);
    $totalCount = $stmt->fetchColumn();

    $stmt = db()->prepare('SELECT * FROM teachers WHERE school_id = ? ORDER BY name LIMIT ? OFFSET ?');
    $stmt->execute([$schoolId, $perPage, $offset]);
}
$teachers = $stmt->fetchAll();
$totalPages = (int) ceil($totalCount / $perPage);

$pageTitle = 'Teachers — ' . $school['name'];
require __DIR__ . '/_header.php';
?>

<div class="card">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 10px;">
        <h2 style="margin: 0;">Teachers</h2>
        <form method="get" style="display: flex; gap: 8px; margin: 0;">
            <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search teachers..." style="width: 250px; margin: 0;">
            <button type="submit" style="margin: 0;">Search</button>
            <?php if ($search !== ''): ?>
                <a href="teachers.php" class="btn btn-secondary" style="padding: 10px 16px;">Clear</a>
            <?php endif; ?>
        </form>
        <div style="display: flex; gap: 8px;">
            <a href="export_csv.php?entity=teachers&search=<?php echo htmlspecialchars($search); ?>&format=csv" class="btn btn-secondary" style="padding: 10px 16px;">Export CSV</a>
            <a href="export_csv.php?entity=teachers&search=<?php echo htmlspecialchars($search); ?>&format=xlsx" class="btn btn-secondary" style="padding: 10px 16px;">Export Excel</a>
        </div>
    </div>
    
    <?php if ($error): ?>
        <div class="error"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
    
    <?php if ($success): ?>
        <div class="success"><?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>
    
    <form method="post">
        <input type="hidden" name="action" value="create">
        <label for="name">Teacher Name</label>
        <input type="text" id="name" name="name" required>

        <label for="staff_id">Staff ID (optional)</label>
        <input type="text" id="staff_id" name="staff_id">

        <label for="tsc_number">TSC Number (optional - for TSC-registered teachers)</label>
        <input type="text" id="tsc_number" name="tsc_number" placeholder="e.g. 123456">

        <label for="max_lessons_per_week">Max Lessons Per Week (optional)</label>
        <input type="number" id="max_lessons_per_week" name="max_lessons_per_week" min="1">

        <button type="submit">Add Teacher</button>
    </form>
</div>

<div class="card">
    <h2>Existing Teachers (<?php echo count($teachers); ?>)</h2>
    <?php if (empty($teachers)): ?>
        <p class="empty">No teachers added yet.</p>
    <?php else: ?>
        <table>
            <thead><tr><th><input type="checkbox" id="select-all" onchange="toggleAllCheckboxes(this)"></th><th>Name</th><th>Staff ID</th><th>TSC Number</th><th>Max Lessons/Week</th><th>Actions</th></tr></thead>
            <tbody>
                <?php foreach ($teachers as $teacher): ?>
                    <tr>
                        <td><input type="checkbox" name="teacher_ids[]" value="<?php echo (int) $teacher['id']; ?>" class="row-checkbox"></td>
                        <td><?php echo htmlspecialchars((string) ($teacher['name'] ?? '')); ?></td>
                        <td><?php echo htmlspecialchars((string) ($teacher['staff_id'] ?? '—')); ?></td>
                        <td><?php echo htmlspecialchars((string) ($teacher['tsc_number'] ?? '—')); ?></td>
                        <td><?php echo isset($teacher['max_lessons_per_week']) ? (int) $teacher['max_lessons_per_week'] : '—'; ?></td>
                        <td class="row-actions">
                            <button type="button" onclick="showEditForm(<?php echo (int) $teacher['id']; ?>, '<?php echo htmlspecialchars($teacher['name'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($teacher['staff_id'] ?? '', ENT_QUOTES); ?>', '<?php echo htmlspecialchars($teacher['tsc_number'] ?? '', ENT_QUOTES); ?>', <?php echo isset($teacher['max_lessons_per_week']) ? (int) $teacher['max_lessons_per_week'] : 'null'; ?>)" class="btn-secondary" style="padding: 8px 12px;">Edit</button>
                            <form method="post" onsubmit="return confirmDelete('Delete this teacher? They may be assigned to subjects.');">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="teacher_id" value="<?php echo (int) $teacher['id']; ?>">
                                <button type="submit" class="btn-danger">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        
        <?php if (!empty($teachers)): ?>
        <div style="margin-top: 16px; display: flex; gap: 8px;">
            <form method="post" onsubmit="return confirmDelete('Delete selected teachers? They may be assigned to subjects.');">
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
                <a href="?search=<?php echo htmlspecialchars($search); ?>&page=<?php echo $page - 1; ?>" class="btn btn-secondary" style="padding: 8px 16px;">← Previous</a>
            <?php endif; ?>
            
            <span style="color: var(--text-muted); font-size: 0.875rem;">
                Page <?php echo $page; ?> of <?php echo $totalPages; ?> (<?php echo $totalCount; ?> total)
            </span>
            
            <?php if ($page < $totalPages): ?>
                <a href="?search=<?php echo htmlspecialchars($search); ?>&page=<?php echo $page + 1; ?>" class="btn btn-secondary" style="padding: 8px 16px;">Next →</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<!-- Edit Teacher Modal -->
<div id="edit-modal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center;">
    <div class="card" style="max-width: 500px; width: 90%; max-height: 90vh; overflow-y: auto;">
        <h2>Edit Teacher</h2>
        <form method="post">
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="teacher_id" id="edit-teacher-id">

            <label for="edit-name">Teacher Name</label>
            <input type="text" id="edit-name" name="name" required>

            <label for="edit-staff_id">Staff ID (optional)</label>
            <input type="text" id="edit-staff_id" name="staff_id">

            <label for="edit-tsc_number">TSC Number (optional - for TSC-registered teachers)</label>
            <input type="text" id="edit-tsc_number" name="tsc_number" placeholder="e.g. 123456">

            <label for="edit-max_lessons_per_week">Max Lessons Per Week (optional)</label>
            <input type="number" id="edit-max_lessons_per_week" name="max_lessons_per_week" min="1">

            <div style="display: flex; gap: 8px; margin-top: 16px;">
                <button type="submit">Update Teacher</button>
                <button type="button" onclick="hideEditForm()" class="btn-secondary">Cancel</button>
            </div>
        </form>
    </div>
</div>

<script>
function showEditForm(teacherId, name, staffId, tscNumber, maxLessons) {
    document.getElementById('edit-teacher-id').value = teacherId;
    document.getElementById('edit-name').value = name;
    document.getElementById('edit-staff_id').value = staffId || '';
    document.getElementById('edit-tsc_number').value = tscNumber || '';
    document.getElementById('edit-max_lessons_per_week').value = maxLessons || '';
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

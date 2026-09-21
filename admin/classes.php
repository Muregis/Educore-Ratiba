<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../db/db.php';
require_once __DIR__ . '/../db/audit_log.php';

$schoolId = requireLoginAndGetSchoolId();
$stmt = db()->prepare('SELECT * FROM schools WHERE id = ?');
$stmt->execute([$schoolId]);
$school = $stmt->fetch();

// Get bands for dropdown
$stmt = db()->prepare('SELECT * FROM bands WHERE school_id = ? AND active = TRUE ORDER BY band_key');
$stmt->execute([$schoolId]);
$bands = $stmt->fetchAll();

// Get rooms for dropdown
$stmt = db()->prepare('SELECT * FROM rooms WHERE school_id = ? ORDER BY name');
$stmt->execute([$schoolId]);
$rooms = $stmt->fetchAll();

$error = null;
$success = null;
$search = trim($_GET['search'] ?? '');
$bandFilter = (int) ($_GET['band_id'] ?? 0);
$statusFilter = $_GET['status'] ?? '';
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

// Handle bulk delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'bulk_delete') {
    $classIds = $_POST['class_ids'] ?? [];
    if (!empty($classIds)) {
        $placeholders = str_repeat('?,', count($classIds) - 1) . '?';
        try {
            $stmt = db()->prepare("DELETE FROM classes WHERE id IN ($placeholders) AND school_id = ?");
            $params = array_merge($classIds, [$schoolId]);
            $stmt->execute($params);
            $success = count($classIds) . ' class(es) deleted successfully.';
            logAudit('bulk_delete', 'class', null, ['count' => count($classIds), 'ids' => $classIds]);
        } catch (Throwable $e) {
            $error = 'Could not delete classes. They may have subjects assigned.';
        }
    }
}

// Handle class creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
    $name = trim($_POST['name'] ?? '');
    $bandId = (int) ($_POST['band_id'] ?? 0);
    $roomId = $_POST['room_id'] ?? '';
    $studentCount = $_POST['student_count'] ?? '';
    
    if ($name === '' || $bandId === 0) {
        $error = 'Class name and band are required.';
    } else {
        try {
            $stmt = db()->prepare(
                'INSERT INTO classes (school_id, band_id, name, room_id, student_count, active) VALUES (?, ?, ?, ?, ?, TRUE)'
            );
            $stmt->execute([
                $schoolId,
                $bandId,
                $name,
                $roomId !== '' ? (int) $roomId : null,
                $studentCount !== '' ? (int) $studentCount : null,
            ]);
            $success = 'Class added successfully.';
            logAudit('create', 'class', (int) db()->lastInsertId(), ['name' => $name, 'band_id' => $bandId]);
        } catch (Throwable $e) {
            $error = 'Could not add class.';
        }
    }
}

// Handle class toggle (active/inactive)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle') {
    $classId = (int) ($_POST['class_id'] ?? 0);
    $active = (int) ($_POST['active'] ?? 0);
    try {
        $stmt = db()->prepare('UPDATE classes SET active = ? WHERE id = ? AND school_id = ?');
        $stmt->execute([(bool)$active, $classId, $schoolId]);
        $success = 'Class updated successfully.';
        logAudit('update', 'class', $classId, ['active' => $active]);
    } catch (Throwable $e) {
        $error = 'Could not update class.';
    }
}

// Handle class deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $classId = (int) ($_POST['class_id'] ?? 0);
    try {
        $stmt = db()->prepare('DELETE FROM classes WHERE id = ? AND school_id = ?');
        $stmt->execute([$classId, $schoolId]);
        $success = 'Class deleted successfully.';
        logAudit('delete', 'class', $classId);
    } catch (Throwable $e) {
        $error = 'Could not delete class. It may have subjects assigned.';
    }
}

if ($search !== '' || $bandFilter !== 0 || $statusFilter !== '') {
    $sql = 'SELECT COUNT(*) FROM classes c WHERE c.school_id = ?';
    $params = [$schoolId];
    
    if ($search !== '') {
        $sql .= ' AND c.name LIKE ?';
        $searchParam = '%' . $search . '%';
        $params[] = $searchParam;
    }
    
    if ($bandFilter !== 0) {
        $sql .= ' AND c.band_id = ?';
        $params[] = $bandFilter;
    }
    
    if ($statusFilter !== '') {
        $sql .= ' AND c.active = (?::boolean)';
        $params[] = ($statusFilter === 'active');
    }
    
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $totalCount = $stmt->fetchColumn();
    
    $sql = 'SELECT c.*, b.label as band_label, r.name as room_name FROM classes c LEFT JOIN bands b ON c.band_id = b.id LEFT JOIN rooms r ON c.room_id = r.id WHERE c.school_id = ?';
    $params = [$schoolId];
    
    if ($search !== '') {
        $sql .= ' AND c.name LIKE ?';
        $searchParam = '%' . $search . '%';
        $params[] = $searchParam;
    }
    
    if ($bandFilter !== 0) {
        $sql .= ' AND c.band_id = ?';
        $params[] = $bandFilter;
    }
    
    if ($statusFilter !== '') {
        $sql .= ' AND c.active = (?::boolean)';
        $params[] = ($statusFilter === 'active');
    }
    
    $sql .= ' ORDER BY c.name LIMIT ? OFFSET ?';
    $params[] = $perPage;
    $params[] = $offset;
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
} else {
    $stmt = db()->prepare('SELECT COUNT(*) FROM classes WHERE school_id = ?');
    $stmt->execute([$schoolId]);
    $totalCount = $stmt->fetchColumn();
    
    $stmt = db()->prepare('SELECT c.*, b.label as band_label, r.name as room_name FROM classes c LEFT JOIN bands b ON c.band_id = b.id LEFT JOIN rooms r ON c.room_id = r.id WHERE c.school_id = ? ORDER BY c.name LIMIT ? OFFSET ?');
    $stmt->execute([$schoolId, $perPage, $offset]);
}
$classes = $stmt->fetchAll();
$totalPages = (int) ceil($totalCount / $perPage);

$pageTitle = 'Classes — ' . $school['name'];
require __DIR__ . '/_header.php';
?>

<div class="card">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 10px;">
        <h2 style="margin: 0;">Classes</h2>
        <form method="get" style="display: flex; gap: 8px; margin: 0; flex-wrap: wrap;">
            <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search classes..." style="width: 200px; margin: 0;">
            <select name="band_id" style="width: 150px; margin: 0;">
                <option value="">All Bands</option>
                <?php foreach ($bands as $band): ?>
                    <option value="<?php echo (int) $band['id']; ?>" <?php echo $bandFilter == $band['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($band['label']); ?></option>
                <?php endforeach; ?>
            </select>
            <select name="status" style="width: 120px; margin: 0;">
                <option value="">All Status</option>
                <option value="active" <?php echo $statusFilter === 'active' ? 'selected' : ''; ?>>Active</option>
                <option value="inactive" <?php echo $statusFilter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
            </select>
            <button type="submit" style="margin: 0;">Filter</button>
            <?php if ($search !== '' || $bandFilter !== 0 || $statusFilter !== ''): ?>
                <a href="classes.php" class="btn btn-secondary" style="padding: 10px 16px;">Clear</a>
            <?php endif; ?>
        </form>
        <div style="display: flex; gap: 8px;">
            <a href="export_csv.php?entity=classes&search=<?php echo htmlspecialchars($search); ?>&band_id=<?php echo $bandFilter; ?>&status=<?php echo htmlspecialchars($statusFilter); ?>&format=csv" class="btn btn-secondary" style="padding: 10px 16px;">Export CSV</a>
            <a href="export_csv.php?entity=classes&search=<?php echo htmlspecialchars($search); ?>&band_id=<?php echo $bandFilter; ?>&status=<?php echo htmlspecialchars($statusFilter); ?>&format=xlsx" class="btn btn-secondary" style="padding: 10px 16px;">Export Excel</a>
        </div>
    </div>
    
    <?php if ($error): ?>
        <div class="error"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
    
    <?php if ($success): ?>
        <div class="success"><?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>
    
    <?php if (empty($bands)): ?>
        <div class="error">You need to create bands first before adding classes.</div>
        <a href="bands.php" class="btn">Manage Bands</a>
    <?php else: ?>
        <form method="post">
            <input type="hidden" name="action" value="create">
            <label for="name">Class Name</label>
            <input type="text" id="name" name="name" required placeholder="e.g. Grade 4 East">
            
            <label for="band_id">Band</label>
            <select id="band_id" name="band_id" required>
                <?php foreach ($bands as $band): ?>
                    <option value="<?php echo (int) $band['id']; ?>"><?php echo htmlspecialchars($band['label']); ?></option>
                <?php endforeach; ?>
            </select>
            
            <label for="room_id">Default Room (optional)</label>
            <select id="room_id" name="room_id">
                <option value="">— No default room —</option>
                <?php foreach ($rooms as $room): ?>
                    <option value="<?php echo (int) $room['id']; ?>"><?php echo htmlspecialchars($room['name']); ?></option>
                <?php endforeach; ?>
            </select>
            
            <label for="student_count">Student Count (optional)</label>
            <input type="number" id="student_count" name="student_count" min="1">
            
            <button type="submit">Add Class</button>
        </form>
    <?php endif; ?>
</div>

<div class="card">
    <h2>Existing Classes (<?php echo count($classes); ?>)</h2>
    <?php if (empty($classes)): ?>
        <p class="empty">No classes added yet.</p>
    <?php else: ?>
        <table>
            <thead><tr><th><input type="checkbox" id="select-all" onchange="toggleAllCheckboxes(this)"></th><th>Name</th><th>Band</th><th>Room</th><th>Students</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody>
                <?php foreach ($classes as $class): ?>
                    <tr>
                        <td><input type="checkbox" name="class_ids[]" value="<?php echo (int) $class['id']; ?>" class="row-checkbox"></td>
                        <td><?php echo htmlspecialchars($class['name']); ?></td>
                        <td><?php echo htmlspecialchars($class['band_label'] ?? '—'); ?></td>
                        <td><?php echo htmlspecialchars($class['room_name'] ?? '—'); ?></td>
                        <td><?php echo (int) $class['student_count']; ?></td>
                        <td><?php echo $class['active'] ? '<span style="color:var(--secondary);font-weight:600;">Active</span>' : '<span style="color:var(--text-muted);">Inactive</span>'; ?></td>
                        <td class="row-actions">
                            <form method="post" style="display:inline;">
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="class_id" value="<?php echo (int) $class['id']; ?>">
                                <input type="hidden" name="active" value="<?php echo $class['active'] ? 0 : 1; ?>">
                                <button type="submit" class="btn-secondary">
                                    <?php echo $class['active'] ? 'Deactivate' : 'Activate'; ?>
                                </button>
                            </form>
                            <form method="post" onsubmit="return confirmDelete('Delete this class? It may have subjects assigned.');">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="class_id" value="<?php echo (int) $class['id']; ?>">
                                <button type="submit" class="btn-danger">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        
        <?php if (!empty($classes)): ?>
        <div style="margin-top: 16px; display: flex; gap: 8px;">
            <form method="post" onsubmit="return confirmDelete('Delete selected classes? They may have subjects assigned.');">
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
                <a href="?search=<?php echo htmlspecialchars($search); ?>&band_id=<?php echo $bandFilter; ?>&status=<?php echo htmlspecialchars($statusFilter); ?>&page=<?php echo $page - 1; ?>" class="btn btn-secondary" style="padding: 8px 16px;">← Previous</a>
            <?php endif; ?>
            <span style="color: var(--text-muted); font-size: 0.875rem;">Page <?php echo $page; ?> of <?php echo $totalPages; ?> (<?php echo $totalCount; ?> total)</span>
            <?php if ($page < $totalPages): ?>
                <a href="?search=<?php echo htmlspecialchars($search); ?>&band_id=<?php echo $bandFilter; ?>&status=<?php echo htmlspecialchars($statusFilter); ?>&page=<?php echo $page + 1; ?>" class="btn btn-secondary" style="padding: 8px 16px;">Next →</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/_footer.php'; ?>

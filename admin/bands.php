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
$statusFilter = $_GET['status'] ?? '';
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

// Handle bulk delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'bulk_delete') {
    $bandIds = $_POST['band_ids'] ?? [];
    if (!empty($bandIds)) {
        $placeholders = str_repeat('?,', count($bandIds) - 1) . '?';
        try {
            $stmt = db()->prepare("DELETE FROM bands WHERE id IN ($placeholders) AND school_id = ?");
            $params = array_merge($bandIds, [$schoolId]);
            $stmt->execute($params);
            $success = count($bandIds) . ' band(s) deleted successfully.';
        } catch (Throwable $e) {
            $error = 'Could not delete bands. They may have classes assigned.';
        }
    }
}

// Handle band creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
    $bandKey = trim($_POST['band_key'] ?? '');
    $label = trim($_POST['label'] ?? '');
    $lessonsPerDay = (int) ($_POST['lessons_per_day'] ?? 8);
    $lessonLength = (int) ($_POST['lesson_length_minutes'] ?? 40);
    
    if ($bandKey === '' || $label === '') {
        $error = 'Band key and label are required.';
    } else {
        try {
            $stmt = db()->prepare(
                'INSERT INTO bands (school_id, band_key, label, lessons_per_day, lesson_length_minutes, active) VALUES (?, ?, ?, ?, ?, 1)'
            );
            $stmt->execute([$schoolId, $bandKey, $label, $lessonsPerDay, $lessonLength]);
            $success = 'Band added successfully.';
            logAudit('create', 'band', (int) db()->lastInsertId(), ['band_key' => $bandKey, 'label' => $label]);
        } catch (Throwable $e) {
            $error = 'Could not add band. Band key may already exist.';
        }
    }
}

// Handle band toggle (active/inactive)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle') {
    $bandId = (int) ($_POST['band_id'] ?? 0);
    $active = (int) ($_POST['active'] ?? 0);
    try {
        $stmt = db()->prepare('UPDATE bands SET active = ? WHERE id = ? AND school_id = ?');
        $stmt->execute([$active, $bandId, $schoolId]);
        $success = 'Band updated successfully.';
        logAudit('update', 'band', $bandId, ['active' => $active]);
    } catch (Throwable $e) {
        $error = 'Could not update band.';
    }
}

// Handle band deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $bandId = (int) ($_POST['band_id'] ?? 0);
    try {
        $stmt = db()->prepare('DELETE FROM bands WHERE id = ? AND school_id = ?');
        $stmt->execute([$bandId, $schoolId]);
        $success = 'Band deleted successfully.';
        logAudit('delete', 'band', $bandId);
    } catch (Throwable $e) {
        $error = 'Could not delete band. It may have classes assigned.';
    }
}

if ($search !== '' || $statusFilter !== '') {
    $sql = 'SELECT COUNT(*) FROM bands WHERE school_id = ?';
    $params = [$schoolId];
    
    if ($search !== '') {
        $sql .= ' AND (band_key LIKE ? OR label LIKE ?)';
        $searchParam = '%' . $search . '%';
        $params[] = $searchParam;
        $params[] = $searchParam;
    }
    
    if ($statusFilter !== '') {
        $sql .= ' AND active = ?';
        $params[] = ($statusFilter === 'active' ? 1 : 0);
    }
    
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $totalCount = $stmt->fetchColumn();
    
    $sql = 'SELECT * FROM bands WHERE school_id = ?';
    $params = [$schoolId];
    
    if ($search !== '') {
        $sql .= ' AND (band_key LIKE ? OR label LIKE ?)';
        $searchParam = '%' . $search . '%';
        $params[] = $searchParam;
        $params[] = $searchParam;
    }
    
    if ($statusFilter !== '') {
        $sql .= ' AND active = ?';
        $params[] = ($statusFilter === 'active' ? 1 : 0);
    }
    
    $sql .= ' ORDER BY band_key LIMIT ? OFFSET ?';
    $params[] = $perPage;
    $params[] = $offset;
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
} else {
    $stmt = db()->prepare('SELECT COUNT(*) FROM bands WHERE school_id = ?');
    $stmt->execute([$schoolId]);
    $totalCount = $stmt->fetchColumn();
    
    $stmt = db()->prepare('SELECT * FROM bands WHERE school_id = ? ORDER BY band_key LIMIT ? OFFSET ?');
    $stmt->execute([$schoolId, $perPage, $offset]);
}
$bands = $stmt->fetchAll();
$totalPages = (int) ceil($totalCount / $perPage);

$pageTitle = 'Bands — ' . $school['name'];
require __DIR__ . '/_header.php';
?>

<div class="card">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 10px;">
        <h2 style="margin: 0;">Bands</h2>
        <form method="get" style="display: flex; gap: 8px; margin: 0; flex-wrap: wrap;">
            <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search bands..." style="width: 250px; margin: 0;">
            <select name="status" style="margin: 0;">
                <option value="">All Status</option>
                <option value="active" <?php echo $statusFilter === 'active' ? 'selected' : ''; ?>>Active</option>
                <option value="inactive" <?php echo $statusFilter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
            </select>
            <button type="submit" style="margin: 0;">Filter</button>
            <?php if ($search !== '' || $statusFilter !== ''): ?>
                <a href="bands.php" class="btn btn-secondary" style="padding: 10px 16px;">Clear</a>
            <?php endif; ?>
        </form>
        <div style="display: flex; gap: 8px;">
            <a href="export_csv.php?entity=bands&search=<?php echo htmlspecialchars($search); ?>&status=<?php echo htmlspecialchars($statusFilter); ?>&format=csv" class="btn btn-secondary" style="padding: 10px 16px;">Export CSV</a>
            <a href="export_csv.php?entity=bands&search=<?php echo htmlspecialchars($search); ?>&status=<?php echo htmlspecialchars($statusFilter); ?>&format=xlsx" class="btn btn-secondary" style="padding: 10px 16px;">Export Excel</a>
        </div>
    </div>
    <p class="empty">Bands represent grade levels (e.g. PP1-PP2, Grade 1-3) with their own lesson schedules.</p>
    
    <?php if ($error): ?>
        <div class="error"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
    
    <?php if ($success): ?>
        <div class="success"><?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>
    
    <form method="post">
        <input type="hidden" name="action" value="create">
        <label for="band_key">Band Key (e.g. pp1-pp2, grade-1-3)</label>
        <input type="text" id="band_key" name="band_key" required placeholder="Use hyphens, no spaces">
        
        <label for="label">Display Label</label>
        <input type="text" id="label" name="label" required placeholder="e.g. PP1 - PP2">
        
        <label for="lessons_per_day">Lessons Per Day</label>
        <input type="number" id="lessons_per_day" name="lessons_per_day" value="8" min="1" required>
        
        <label for="lesson_length_minutes">Lesson Length (minutes)</label>
        <input type="number" id="lesson_length_minutes" name="lesson_length_minutes" value="40" min="1" required>
        
        <button type="submit">Add Band</button>
    </form>
</div>

<div class="card">
    <h2>Existing Bands (<?php echo count($bands); ?>)</h2>
    <?php if (empty($bands)): ?>
        <p class="empty">No bands added yet.</p>
    <?php else: ?>
        <table>
            <thead><tr><th><input type="checkbox" id="select-all" onchange="toggleAllCheckboxes(this)"></th><th>Key</th><th>Label</th><th>Lessons/Day</th><th>Duration</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody>
                <?php foreach ($bands as $band): ?>
                    <tr>
                        <td><input type="checkbox" name="band_ids[]" value="<?php echo (int) $band['id']; ?>" class="row-checkbox"></td>
                        <td><?php echo htmlspecialchars($band['band_key']); ?></td>
                        <td><?php echo htmlspecialchars($band['label']); ?></td>
                        <td><?php echo (int) $band['lessons_per_day']; ?></td>
                        <td><?php echo (int) $band['lesson_length_minutes']; ?> min</td>
                        <td><?php echo $band['active'] ? '<span style="color:var(--secondary);font-weight:600;">Active</span>' : '<span style="color:var(--text-muted);">Inactive</span>'; ?></td>
                        <td class="row-actions">
                            <form method="post" style="display:inline;">
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="band_id" value="<?php echo (int) $band['id']; ?>">
                                <input type="hidden" name="active" value="<?php echo $band['active'] ? 0 : 1; ?>">
                                <button type="submit" class="btn-secondary">
                                    <?php echo $band['active'] ? 'Deactivate' : 'Activate'; ?>
                                </button>
                            </form>
                            <form method="post" onsubmit="return confirmDelete('Delete this band? It may have classes assigned.');">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="band_id" value="<?php echo (int) $band['id']; ?>">
                                <button type="submit" class="btn-danger">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        
        <?php if (!empty($bands)): ?>
        <div style="margin-top: 16px; display: flex; gap: 8px;">
            <form method="post" onsubmit="return confirmDelete('Delete selected bands? They may have classes assigned.');">
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
                <a href="?search=<?php echo htmlspecialchars($search); ?>&status=<?php echo htmlspecialchars($statusFilter); ?>&page=<?php echo $page - 1; ?>" class="btn btn-secondary" style="padding: 8px 16px;">← Previous</a>
            <?php endif; ?>
            <span style="color: var(--text-muted); font-size: 0.875rem;">Page <?php echo $page; ?> of <?php echo $totalPages; ?> (<?php echo $totalCount; ?> total)</span>
            <?php if ($page < $totalPages): ?>
                <a href="?search=<?php echo htmlspecialchars($search); ?>&status=<?php echo htmlspecialchars($statusFilter); ?>&page=<?php echo $page + 1; ?>" class="btn btn-secondary" style="padding: 8px 16px;">Next →</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/_footer.php'; ?>

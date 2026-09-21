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
    $roomIds = $_POST['room_ids'] ?? [];
    if (!empty($roomIds)) {
        $placeholders = str_repeat('?,', count($roomIds) - 1) . '?';
        try {
            $stmt = db()->prepare("DELETE FROM rooms WHERE id IN ($placeholders) AND school_id = ?");
            $params = array_merge($roomIds, [$schoolId]);
            $stmt->execute($params);
            $success = count($roomIds) . ' room(s) deleted successfully.';
            logAudit('bulk_delete', 'room', null, ['count' => count($roomIds), 'ids' => $roomIds]);
        } catch (Throwable $e) {
            $error = 'Could not delete rooms. They may be assigned to classes.';
        }
    }
}

// Handle room creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
    $name = trim($_POST['name'] ?? '');
    $capacity = $_POST['capacity'] ?? '';
    $roomType = trim($_POST['room_type'] ?? '');
    
    if ($name === '') {
        $error = 'Room name is required.';
    } else {
        try {
            $stmt = db()->prepare(
                'INSERT INTO rooms (school_id, name, capacity, room_type) VALUES (?, ?, ?, ?)'
            );
            $stmt->execute([
                $schoolId,
                $name,
                $capacity !== '' ? (int) $capacity : null,
                $roomType !== '' ? $roomType : null,
            ]);
            $success = 'Room added successfully.';
            logAudit('create', 'room', (int) db()->lastInsertId(), ['name' => $name, 'capacity' => $capacity]);
        } catch (Throwable $e) {
            $error = 'Could not add room.';
        }
    }
}

// Handle room deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $roomId = (int) ($_POST['room_id'] ?? 0);
    try {
        $stmt = db()->prepare('DELETE FROM rooms WHERE id = ? AND school_id = ?');
        $stmt->execute([$roomId, $schoolId]);
        $success = 'Room deleted successfully.';
        logAudit('delete', 'room', $roomId);
    } catch (Throwable $e) {
        $error = 'Could not delete room. It may be assigned to classes.';
    }
}

if ($search !== '') {
    $searchParam = '%' . $search . '%';
    
    $stmt = db()->prepare('SELECT COUNT(*) FROM rooms WHERE school_id = ? AND (name LIKE ? OR room_type LIKE ?)');
    $stmt->execute([$schoolId, $searchParam, $searchParam]);
    $totalCount = $stmt->fetchColumn();
    
    $stmt = db()->prepare('SELECT * FROM rooms WHERE school_id = ? AND (name LIKE ? OR room_type LIKE ?) ORDER BY name LIMIT ? OFFSET ?');
    $stmt->execute([$schoolId, $searchParam, $searchParam, $perPage, $offset]);
} else {
    $stmt = db()->prepare('SELECT COUNT(*) FROM rooms WHERE school_id = ?');
    $stmt->execute([$schoolId]);
    $totalCount = $stmt->fetchColumn();
    
    $stmt = db()->prepare('SELECT * FROM rooms WHERE school_id = ? ORDER BY name LIMIT ? OFFSET ?');
    $stmt->execute([$schoolId, $perPage, $offset]);
}
$rooms = $stmt->fetchAll();
$totalPages = (int) ceil($totalCount / $perPage);

$pageTitle = 'Rooms — ' . $school['name'];
require __DIR__ . '/_header.php';
?>

<div class="card">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 10px;">
        <h2 style="margin: 0;">Rooms</h2>
        <form method="get" style="display: flex; gap: 8px; margin: 0; flex-wrap: wrap;">
            <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search rooms..." style="width: 250px; margin: 0;">
            <button type="submit" style="margin: 0;">Search</button>
            <?php if ($search !== ''): ?>
                <a href="rooms.php" class="btn btn-secondary" style="padding: 10px 16px;">Clear</a>
            <?php endif; ?>
        </form>
        <div style="display: flex; gap: 8px;">
            <a href="export_csv.php?entity=rooms&search=<?php echo htmlspecialchars($search); ?>&format=csv" class="btn btn-secondary" style="padding: 10px 16px;">Export CSV</a>
            <a href="export_csv.php?entity=rooms&search=<?php echo htmlspecialchars($search); ?>&format=xlsx" class="btn btn-secondary" style="padding: 10px 16px;">Export Excel</a>
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
        <label for="name">Room Name</label>
        <input type="text" id="name" name="name" required>
        
        <label for="capacity">Capacity (optional)</label>
        <input type="number" id="capacity" name="capacity" min="1">
        
        <label for="room_type">Room Type (optional)</label>
        <input type="text" id="room_type" name="room_type" placeholder="e.g. classroom, lab, hall">
        
        <button type="submit">Add Room</button>
    </form>
</div>

<div class="card">
    <h2>Existing Rooms (<?php echo count($rooms); ?>)</h2>
    <?php if (empty($rooms)): ?>
        <p class="empty">No rooms added yet.</p>
    <?php else: ?>
        <table>
            <thead><tr><th><input type="checkbox" id="select-all" onchange="toggleAllCheckboxes(this)"></th><th>Name</th><th>Capacity</th><th>Type</th><th>Actions</th></tr></thead>
            <tbody>
                <?php foreach ($rooms as $room): ?>
                    <tr>
                        <td><input type="checkbox" name="room_ids[]" value="<?php echo (int) $room['id']; ?>" class="row-checkbox"></td>
                        <td><?php echo htmlspecialchars($room['name']); ?></td>
                        <td><?php echo htmlspecialchars($room['capacity'] ?? '—'); ?></td>
                        <td><?php echo htmlspecialchars($room['room_type'] ?? '—'); ?></td>
                        <td class="row-actions">
                            <form method="post" onsubmit="return confirmDelete('Delete this room? It may be assigned to classes.');">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="room_id" value="<?php echo (int) $room['id']; ?>">
                                <button type="submit" class="btn-danger">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        
        <?php if (!empty($rooms)): ?>
        <div style="margin-top: 16px; display: flex; gap: 8px;">
            <form method="post" onsubmit="return confirmDelete('Delete selected rooms? They may be assigned to classes.');">
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
            <span style="color: var(--text-muted); font-size: 0.875rem;">Page <?php echo $page; ?> of <?php echo $totalPages; ?> (<?php echo $totalCount; ?> total)</span>
            <?php if ($page < $totalPages): ?>
                <a href="?search=<?php echo htmlspecialchars($search); ?>&page=<?php echo $page + 1; ?>" class="btn btn-secondary" style="padding: 8px 16px;">Next →</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/_footer.php'; ?>

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

// Get subjects for dropdown
$stmt = db()->prepare('SELECT s.*, c.name as class_name FROM subjects s JOIN classes c ON s.class_id = c.id WHERE s.school_id = ? ORDER BY c.name, s.name');
$stmt->execute([$schoolId]);
$subjects = $stmt->fetchAll();

$error = null;
$success = null;
$search = trim($_GET['search'] ?? '');
$classFilter = (int) ($_GET['class_id'] ?? 0);
$sessionTypeFilter = $_GET['session_type'] ?? '';
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

// Handle bulk delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'bulk_delete') {
    $sessionIds = $_POST['session_ids'] ?? [];
    if (!empty($sessionIds)) {
        $placeholders = str_repeat('?,', count($sessionIds) - 1) . '?';
        try {
            $stmt = db()->prepare("DELETE FROM remedial_sessions WHERE id IN ($placeholders) AND school_id = ?");
            $params = array_merge($sessionIds, [$schoolId]);
            $stmt->execute($params);
            $success = count($sessionIds) . ' session(s) deleted successfully.';
            logAudit('bulk_delete', 'remedial', null, ['count' => count($sessionIds), 'ids' => $sessionIds]);
        } catch (Throwable $e) {
            $error = 'Could not delete remedial sessions.';
        }
    }
}

// Handle remedial session creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
    $classId = (int) ($_POST['class_id'] ?? 0);
    $subjectId = (int) ($_POST['subject_id'] ?? 0);
    $teacherId = (int) ($_POST['teacher_id'] ?? 0);
    $sessionType = $_POST['session_type'] ?? 'morning';
    $dayOfWeek = $_POST['day_of_week'] ?? '';
    $roomId = $_POST['room_id'] ?? '';
    
    if ($classId === 0 || $subjectId === 0 || $teacherId === 0 || $dayOfWeek === '') {
        $error = 'Class, subject, teacher, and day are required.';
    } else {
        try {
            $stmt = db()->prepare('SELECT band_id FROM classes WHERE id = ? AND school_id = ?');
            $stmt->execute([$classId, $schoolId]);
            $class = $stmt->fetch();
            
            if (!$class) {
                $error = 'Invalid class selected.';
            } else {
                $stmt = db()->prepare(
                    'INSERT INTO remedial_sessions (school_id, band_id, class_id, subject_id, teacher_id, room_id, session_type, day_of_week) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $stmt->execute([
                    $schoolId,
                    $class['band_id'],
                    $classId,
                    $subjectId,
                    $teacherId,
                    $roomId !== '' ? (int) $roomId : null,
                    $sessionType,
                    $dayOfWeek,
                ]);
                $success = 'Remedial session added successfully.';
                logAudit('create', 'remedial', (int) db()->lastInsertId(), ['class_id' => $classId, 'subject_id' => $subjectId]);
            }
        } catch (Throwable $e) {
            $error = 'Could not add remedial session.';
        }
    }
}

// Handle remedial session deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $sessionId = (int) ($_POST['session_id'] ?? 0);
    try {
        $stmt = db()->prepare('DELETE FROM remedial_sessions WHERE id = ? AND school_id = ?');
        $stmt->execute([$sessionId, $schoolId]);
        $success = 'Remedial session deleted successfully.';
        logAudit('delete', 'remedial', $sessionId);
    } catch (Throwable $e) {
        $error = 'Could not delete remedial session.';
    }
}

if ($search !== '' || $classFilter !== 0 || $sessionTypeFilter !== '') {
    $sql = 'SELECT COUNT(*) FROM remedial_sessions rs WHERE rs.school_id = ?';
    $params = [$schoolId];
    
    if ($search !== '') {
        $sql .= ' AND (s.name LIKE ? OR t.name LIKE ?)';
        $searchParam = '%' . $search . '%';
        $params[] = $searchParam;
        $params[] = $searchParam;
    }
    
    if ($classFilter !== 0) {
        $sql .= ' AND rs.class_id = ?';
        $params[] = $classFilter;
    }
    
    if ($sessionTypeFilter !== '') {
        $sql .= ' AND rs.session_type = ?';
        $params[] = $sessionTypeFilter;
    }
    
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $totalCount = $stmt->fetchColumn();
    
    $sql = 'SELECT rs.*, c.name as class_name, b.label as band_label, s.name as subject_name, t.name as teacher_name, r.name as room_name FROM remedial_sessions rs LEFT JOIN classes c ON rs.class_id = c.id LEFT JOIN bands b ON rs.band_id = b.id LEFT JOIN subjects s ON rs.subject_id = s.id LEFT JOIN teachers t ON rs.teacher_id = t.id LEFT JOIN rooms r ON rs.room_id = r.id WHERE rs.school_id = ?';
    $params = [$schoolId];
    
    if ($search !== '') {
        $sql .= ' AND (s.name LIKE ? OR t.name LIKE ?)';
        $searchParam = '%' . $search . '%';
        $params[] = $searchParam;
        $params[] = $searchParam;
    }
    
    if ($classFilter !== 0) {
        $sql .= ' AND rs.class_id = ?';
        $params[] = $classFilter;
    }
    
    if ($sessionTypeFilter !== '') {
        $sql .= ' AND rs.session_type = ?';
        $params[] = $sessionTypeFilter;
    }
    
    $sql .= ' ORDER BY c.name, rs.session_type, rs.day_of_week LIMIT ? OFFSET ?';
    $params[] = $perPage;
    $params[] = $offset;
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
} else {
    $stmt = db()->prepare('SELECT COUNT(*) FROM remedial_sessions WHERE school_id = ?');
    $stmt->execute([$schoolId]);
    $totalCount = $stmt->fetchColumn();
    
    $stmt = db()->prepare('SELECT rs.*, c.name as class_name, b.label as band_label, s.name as subject_name, t.name as teacher_name, r.name as room_name FROM remedial_sessions rs LEFT JOIN classes c ON rs.class_id = c.id LEFT JOIN bands b ON rs.band_id = b.id LEFT JOIN subjects s ON rs.subject_id = s.id LEFT JOIN teachers t ON rs.teacher_id = t.id LEFT JOIN rooms r ON rs.room_id = r.id WHERE rs.school_id = ? ORDER BY c.name, rs.session_type, rs.day_of_week LIMIT ? OFFSET ?');
    $stmt->execute([$schoolId, $perPage, $offset]);
}
$sessions = $stmt->fetchAll();
$totalPages = (int) ceil($totalCount / $perPage);

$pageTitle = 'Remedial Sessions — ' . $school['name'];
require __DIR__ . '/_header.php';
?>

<div class="card">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 10px;">
        <h2 style="margin: 0;">Remedial Sessions</h2>
        <form method="get" style="display: flex; gap: 8px; margin: 0; flex-wrap: wrap;">
            <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search..." style="width: 180px; margin: 0;">
            <select name="class_id" style="width: 150px; margin: 0;">
                <option value="">All Classes</option>
                <?php foreach ($classes as $class): ?>
                    <option value="<?php echo (int) $class['id']; ?>" <?php echo $classFilter == $class['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($class['name']); ?></option>
                <?php endforeach; ?>
            </select>
            <select name="session_type" style="width: 120px; margin: 0;">
                <option value="">All Types</option>
                <option value="morning" <?php echo $sessionTypeFilter === 'morning' ? 'selected' : ''; ?>>Morning</option>
                <option value="evening" <?php echo $sessionTypeFilter === 'evening' ? 'selected' : ''; ?>>Evening</option>
            </select>
            <button type="submit" style="margin: 0;">Filter</button>
            <?php if ($search !== '' || $classFilter !== 0 || $sessionTypeFilter !== ''): ?>
                <a href="remedials.php" class="btn btn-secondary" style="padding: 10px 16px;">Clear</a>
            <?php endif; ?>
        </form>
        <div style="display: flex; gap: 8px;">
            <a href="export_csv.php?entity=remedials&search=<?php echo htmlspecialchars($search); ?>&class_id=<?php echo $classFilter; ?>&session_type=<?php echo htmlspecialchars($sessionTypeFilter); ?>&format=csv" class="btn btn-secondary" style="padding: 10px 16px;">Export CSV</a>
            <a href="export_csv.php?entity=remedials&search=<?php echo htmlspecialchars($search); ?>&class_id=<?php echo $classFilter; ?>&session_type=<?php echo htmlspecialchars($sessionTypeFilter); ?>&format=xlsx" class="btn btn-secondary" style="padding: 10px 16px;">Export Excel</a>
        </div>
    </div>
    <p class="empty">Morning and evening remedial lessons outside normal school hours.</p>
    
    <?php if ($error): ?>
        <div class="error"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
    
    <?php if ($success): ?>
        <div class="success"><?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>
    
    <?php if (empty($classes) || empty($subjects) || empty($teachers)): ?>
        <div class="error">You need to create classes, subjects, and teachers first before adding remedial sessions.</div>
        <a href="classes.php" class="btn">Manage Classes</a>
        <a href="subjects.php" class="btn">Manage Subjects</a>
        <a href="teachers.php" class="btn">Manage Teachers</a>
    <?php else: ?>
        <form method="post">
            <input type="hidden" name="action" value="create">
            <label for="class_id">Class</label>
            <select id="class_id" name="class_id" required onchange="updateSubjects()">
                <?php foreach ($classes as $class): ?>
                    <option value="<?php echo (int) $class['id']; ?>" data-band="<?php echo htmlspecialchars($class['band_label']); ?>"><?php echo htmlspecialchars($class['name']); ?> (<?php echo htmlspecialchars($class['band_label']); ?>)</option>
                <?php endforeach; ?>
            </select>
            
            <label for="subject_id">Subject</label>
            <select id="subject_id" name="subject_id" required>
                <?php foreach ($subjects as $subject): ?>
                    <option value="<?php echo (int) $subject['id']; ?>" data-class="<?php echo (int) $subject['class_id']; ?>"><?php echo htmlspecialchars($subject['subject_name'] ?? $subject['name']); ?> (<?php echo htmlspecialchars($subject['class_name']); ?>)</option>
                <?php endforeach; ?>
            </select>
            
            <label for="teacher_id">Teacher</label>
            <select id="teacher_id" name="teacher_id" required>
                <?php foreach ($teachers as $teacher): ?>
                    <option value="<?php echo (int) $teacher['id']; ?>"><?php echo htmlspecialchars($teacher['name']); ?></option>
                <?php endforeach; ?>
            </select>
            
            <label for="session_type">Session Type</label>
            <select id="session_type" name="session_type" required>
                <option value="morning">Morning</option>
                <option value="evening">Evening</option>
            </select>
            
            <label for="day_of_week">Day of Week</label>
            <select id="day_of_week" name="day_of_week" required>
                <option value="Monday">Monday</option>
                <option value="Tuesday">Tuesday</option>
                <option value="Wednesday">Wednesday</option>
                <option value="Thursday">Thursday</option>
                <option value="Friday">Friday</option>
            </select>
            
            <label for="room_id">Room (optional)</label>
            <select id="room_id" name="room_id">
                <option value="">— No room —</option>
                <?php foreach ($rooms as $room): ?>
                    <option value="<?php echo (int) $room['id']; ?>"><?php echo htmlspecialchars($room['name']); ?></option>
                <?php endforeach; ?>
            </select>
            
            <button type="submit">Add Remedial Session</button>
        </form>
        
        <script>
        function updateSubjects() {
            const classId = document.getElementById('class_id').value;
            const subjectSelect = document.getElementById('subject_id');
            for (let option of subjectSelect.options) {
                if (option.value === '') continue;
                option.style.display = (option.dataset.class == classId) ? '' : 'none';
            }
            for (let option of subjectSelect.options) {
                if (option.value !== '' && option.style.display !== 'none') {
                    subjectSelect.value = option.value;
                    break;
                }
            }
        }
        updateSubjects();
        </script>
    <?php endif; ?>
</div>

<div class="card">
    <h2>Existing Remedial Sessions (<?php echo count($sessions); ?>)</h2>
    <?php if (empty($sessions)): ?>
        <p class="empty">No remedial sessions added yet.</p>
    <?php else: ?>
        <table>
            <thead><tr><th><input type="checkbox" id="select-all" onchange="toggleAllCheckboxes(this)"></th><th>Class</th><th>Subject</th><th>Teacher</th><th>Type</th><th>Day</th><th>Room</th><th>Actions</th></tr></thead>
            <tbody>
                <?php foreach ($sessions as $session): ?>
                    <tr>
                        <td><input type="checkbox" name="session_ids[]" value="<?php echo (int) $session['id']; ?>" class="row-checkbox"></td>
                        <td><?php echo htmlspecialchars($session['class_name']); ?></td>
                        <td><?php echo htmlspecialchars($session['subject_name'] ?? '—'); ?></td>
                        <td><?php echo htmlspecialchars($session['teacher_name']); ?></td>
                        <td><?php echo htmlspecialchars(ucfirst($session['session_type'])); ?></td>
                        <td><?php echo htmlspecialchars($session['day_of_week']); ?></td>
                        <td><?php echo htmlspecialchars($session['room_name'] ?? '—'); ?></td>
                        <td class="row-actions">
                            <form method="post" onsubmit="return confirmDelete('Delete this remedial session?');">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="session_id" value="<?php echo (int) $session['id']; ?>">
                                <button type="submit" class="btn-danger">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        
        <?php if (!empty($sessions)): ?>
        <div style="margin-top: 16px; display: flex; gap: 8px;">
            <form method="post" onsubmit="return confirmDelete('Delete selected remedial sessions?');">
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
                <a href="?search=<?php echo htmlspecialchars($search); ?>&class_id=<?php echo $classFilter; ?>&session_type=<?php echo htmlspecialchars($sessionTypeFilter); ?>&page=<?php echo $page - 1; ?>" class="btn btn-secondary" style="padding: 8px 16px;">← Previous</a>
            <?php endif; ?>
            <span style="color: var(--text-muted); font-size: 0.875rem;">Page <?php echo $page; ?> of <?php echo $totalPages; ?> (<?php echo $totalCount; ?> total)</span>
            <?php if ($page < $totalPages): ?>
                <a href="?search=<?php echo htmlspecialchars($search); ?>&class_id=<?php echo $classFilter; ?>&session_type=<?php echo htmlspecialchars($sessionTypeFilter); ?>&page=<?php echo $page + 1; ?>" class="btn btn-secondary" style="padding: 8px 16px;">Next →</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/_footer.php'; ?>

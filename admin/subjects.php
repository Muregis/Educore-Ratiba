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
$stmt = db()->prepare('SELECT c.*, b.label as band_label FROM classes c JOIN bands b ON c.band_id = b.id WHERE c.school_id = ? AND c.active = 1 ORDER BY c.name');
$stmt->execute([$schoolId]);
$classes = $stmt->fetchAll();

// Get teachers for dropdown
$stmt = db()->prepare('SELECT * FROM teachers WHERE school_id = ? ORDER BY name');
$stmt->execute([$schoolId]);
$teachers = $stmt->fetchAll();

$error = null;
$success = null;
$search = trim($_GET['search'] ?? '');
$classFilter = (int) ($_GET['class_id'] ?? 0);
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

// Handle bulk delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'bulk_delete') {
    $subjectIds = $_POST['subject_ids'] ?? [];
    if (!empty($subjectIds)) {
        $placeholders = str_repeat('?,', count($subjectIds) - 1) . '?';
        try {
            $stmt = db()->prepare("DELETE FROM subjects WHERE id IN ($placeholders) AND school_id = ?");
            $params = array_merge($subjectIds, [$schoolId]);
            $stmt->execute($params);
            $success = count($subjectIds) . ' subject(s) deleted successfully.';
            logAudit('bulk_delete', 'subject', null, ['count' => count($subjectIds), 'ids' => $subjectIds]);
        } catch (Throwable $e) {
            $error = 'Could not delete subjects. This may affect timetable generation.';
        }
    }
}

// Handle subject creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
    $classId = (int) ($_POST['class_id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $lessonsPerWeek = (int) ($_POST['lessons_per_week'] ?? 1);
    $teacherId = $_POST['teacher_id'] ?? '';
    
    if ($classId === 0 || $name === '') {
        $error = 'Class and subject name are required.';
    } else {
        try {
            // Get band_id from class
            $stmt = db()->prepare('SELECT band_id FROM classes WHERE id = ? AND school_id = ?');
            $stmt->execute([$classId, $schoolId]);
            $class = $stmt->fetch();
            
            if (!$class) {
                $error = 'Invalid class selected.';
            } else {
                $stmt = db()->prepare(
                    'INSERT INTO subjects (school_id, band_id, class_id, name, lessons_per_week, assigned_teacher_id) VALUES (?, ?, ?, ?, ?, ?)'
                );
                $stmt->execute([
                    $schoolId,
                    $class['band_id'],
                    $classId,
                    $name,
                    $lessonsPerWeek,
                    $teacherId !== '' ? (int) $teacherId : null,
                ]);
                $success = 'Subject added successfully.';
                logAudit('create', 'subject', (int) db()->lastInsertId(), ['name' => $name, 'class_id' => $classId]);
            }
        } catch (Throwable $e) {
            $error = 'Could not add subject.';
        }
    }
}

// Handle subject deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $subjectId = (int) ($_POST['subject_id'] ?? 0);
    try {
        $stmt = db()->prepare('DELETE FROM subjects WHERE id = ? AND school_id = ?');
        $stmt->execute([$subjectId, $schoolId]);
        $success = 'Subject deleted successfully.';
        logAudit('delete', 'subject', $subjectId);
    } catch (Throwable $e) {
        $error = 'Could not delete subject.';
    }
}

// Handle teacher assignment update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'assign_teacher') {
    $subjectId = (int) ($_POST['subject_id'] ?? 0);
    $teacherId = $_POST['teacher_id'] ?? '';
    try {
        $stmt = db()->prepare('UPDATE subjects SET assigned_teacher_id = ? WHERE id = ? AND school_id = ?');
        $stmt->execute([
            $teacherId !== '' ? (int) $teacherId : null,
            $subjectId,
            $schoolId,
        ]);
        $success = 'Teacher assignment updated successfully.';
    } catch (Throwable $e) {
        $error = 'Could not update teacher assignment.';
    }
}

if ($search !== '' || $classFilter !== 0) {
    $sql = 'SELECT COUNT(*) FROM subjects s WHERE s.school_id = ?';
    $params = [$schoolId];
    
    if ($search !== '') {
        $sql .= ' AND s.name LIKE ?';
        $searchParam = '%' . $search . '%';
        $params[] = $searchParam;
    }
    
    if ($classFilter !== 0) {
        $sql .= ' AND s.class_id = ?';
        $params[] = $classFilter;
    }
    
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $totalCount = $stmt->fetchColumn();
    
    $sql = 'SELECT s.*, c.name as class_name, b.label as band_label, t.name as teacher_name FROM subjects s LEFT JOIN classes c ON s.class_id = c.id LEFT JOIN bands b ON s.band_id = b.id LEFT JOIN teachers t ON s.assigned_teacher_id = t.id WHERE s.school_id = ?';
    $params = [$schoolId];
    
    if ($search !== '') {
        $sql .= ' AND s.name LIKE ?';
        $searchParam = '%' . $search . '%';
        $params[] = $searchParam;
    }
    
    if ($classFilter !== 0) {
        $sql .= ' AND s.class_id = ?';
        $params[] = $classFilter;
    }
    
    $sql .= ' ORDER BY c.name, s.name LIMIT ? OFFSET ?';
    $params[] = $perPage;
    $params[] = $offset;
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
} else {
    $stmt = db()->prepare('SELECT COUNT(*) FROM subjects WHERE school_id = ?');
    $stmt->execute([$schoolId]);
    $totalCount = $stmt->fetchColumn();
    
    $stmt = db()->prepare('SELECT s.*, c.name as class_name, b.label as band_label, t.name as teacher_name FROM subjects s LEFT JOIN classes c ON s.class_id = c.id LEFT JOIN bands b ON s.band_id = b.id LEFT JOIN teachers t ON s.assigned_teacher_id = t.id WHERE s.school_id = ? ORDER BY c.name, s.name LIMIT ? OFFSET ?');
    $stmt->execute([$schoolId, $perPage, $offset]);
}
$subjects = $stmt->fetchAll();
$totalPages = (int) ceil($totalCount / $perPage);

$pageTitle = 'Subjects — ' . $school['name'];
require __DIR__ . '/_header.php';
?>

<div class="card">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 10px;">
        <h2 style="margin: 0;">Subjects</h2>
        <form method="get" style="display: flex; gap: 8px; margin: 0; flex-wrap: wrap;">
            <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search subjects..." style="width: 200px; margin: 0;">
            <select name="class_id" style="width: 150px; margin: 0;">
                <option value="">All Classes</option>
                <?php foreach ($classes as $class): ?>
                    <option value="<?php echo (int) $class['id']; ?>" <?php echo $classFilter == $class['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($class['name']); ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" style="margin: 0;">Filter</button>
            <?php if ($search !== '' || $classFilter !== 0): ?>
                <a href="subjects.php" class="btn btn-secondary" style="padding: 10px 16px;">Clear</a>
            <?php endif; ?>
        </form>
        <div style="display: flex; gap: 8px;">
            <a href="export_csv.php?entity=subjects&search=<?php echo htmlspecialchars($search); ?>&class_id=<?php echo $classFilter; ?>&format=csv" class="btn btn-secondary" style="padding: 10px 16px;">Export CSV</a>
            <a href="export_csv.php?entity=subjects&search=<?php echo htmlspecialchars($search); ?>&class_id=<?php echo $classFilter; ?>&format=xlsx" class="btn btn-secondary" style="padding: 10px 16px;">Export Excel</a>
        </div>
    </div>
    
    <?php if ($error): ?>
        <div class="error"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
    
    <?php if ($success): ?>
        <div class="success"><?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>
    
    <?php if (empty($classes)): ?>
        <div class="error">You need to create classes first before adding subjects.</div>
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
            
            <label for="name">Subject Name</label>
            <input type="text" id="name" name="name" required placeholder="e.g. Mathematics">
            
            <label for="lessons_per_week">Lessons Per Week</label>
            <input type="number" id="lessons_per_week" name="lessons_per_week" value="1" min="1" required>
            
            <label for="teacher_id">Assigned Teacher (optional)</label>
            <select id="teacher_id" name="teacher_id">
                <option value="">— Unassigned —</option>
                <?php foreach ($teachers as $teacher): ?>
                    <option value="<?php echo (int) $teacher['id']; ?>"><?php echo htmlspecialchars($teacher['name']); ?></option>
                <?php endforeach; ?>
            </select>
            
            <button type="submit">Add Subject</button>
        </form>
    <?php endif; ?>
</div>

<div class="card">
    <h2>Existing Subjects (<?php echo count($subjects); ?>)</h2>
    <?php if (empty($subjects)): ?>
        <p class="empty">No subjects added yet.</p>
    <?php else: ?>
        <table>
            <thead><tr><th><input type="checkbox" id="select-all" onchange="toggleAllCheckboxes(this)"></th><th>Class</th><th>Band</th><th>Subject</th><th>Lessons/Week</th><th>Teacher</th><th>Actions</th></tr></thead>
            <tbody>
                <?php foreach ($subjects as $subject): ?>
                    <tr>
                        <td><input type="checkbox" name="subject_ids[]" value="<?php echo (int) $subject['id']; ?>" class="row-checkbox"></td>
                        <td><?php echo htmlspecialchars($subject['class_name'] ?? '—'); ?></td>
                        <td><?php echo htmlspecialchars($subject['band_label'] ?? '—'); ?></td>
                        <td><?php echo htmlspecialchars($subject['name']); ?></td>
                        <td><?php echo (int) $subject['lessons_per_week']; ?></td>
                        <td>
                            <form method="post" style="display:inline;">
                                <input type="hidden" name="action" value="assign_teacher">
                                <input type="hidden" name="subject_id" value="<?php echo (int) $subject['id']; ?>">
                                <select name="teacher_id" style="width:120px; margin:0;">
                                    <option value="">— Unassigned —</option>
                                    <?php foreach ($teachers as $teacher): ?>
                                        <option value="<?php echo (int) $teacher['id']; ?>" <?php echo $subject['assigned_teacher_id'] == $teacher['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($teacher['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="submit" class="btn-secondary" style="padding:8px 12px;">Set</button>
                            </form>
                        </td>
                        <td class="row-actions">
                            <form method="post" onsubmit="return confirmDelete('Delete this subject? This may affect timetable generation.');">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="subject_id" value="<?php echo (int) $subject['id']; ?>">
                                <button type="submit" class="btn-danger">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        
        <?php if (!empty($subjects)): ?>
        <div style="margin-top: 16px; display: flex; gap: 8px;">
            <form method="post" onsubmit="return confirmDelete('Delete selected subjects? This may affect timetable generation.');">
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

<?php require __DIR__ . '/_footer.php'; ?>

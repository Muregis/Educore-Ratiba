<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../db/db.php';
require_once __DIR__ . '/../db/audit_log.php';

$schoolId = requireLoginAndGetSchoolId();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
}
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

function normalizeBreakConfig(?string $jsonInput): string
{
    if ($jsonInput !== null && trim($jsonInput) !== '') {
        $trimmed = trim($jsonInput);
        $decoded = json_decode($trimmed, true);
        if (is_array($decoded)) {
            $cleaned = [];
            foreach ($decoded as $item) {
                $time = trim((string) ($item['time'] ?? ''));
                $label = strtoupper(trim((string) ($item['label'] ?? 'BREAK')));
                if ($time !== '' && preg_match('/^\d{2}:\d{2}\s*-\s*\d{2}:\d{2}$/', $time)) {
                    $cleaned[] = [
                        'time' => preg_replace('/\s+/', '', $time),
                        'label' => $label !== '' ? $label : 'BREAK',
                    ];
                }
            }
            if (!empty($cleaned)) {
                return json_encode($cleaned);
            }
        }
    }
    return '[{"time":"10:00-10:30","label":"TEA BREAK"},{"time":"12:30-13:30","label":"LUNCH BREAK"}]';
}

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
    $dayStartTime = trim($_POST['day_start_time'] ?? '08:00');
    if (!preg_match('/^\d{2}:\d{2}$/', $dayStartTime)) {
        $dayStartTime = '08:00';
    }
    $breakConfig = normalizeBreakConfig($_POST['break_config'] ?? null);

    if ($bandKey === '' || $label === '') {
        $error = 'Band key and label are required.';
    } else {
        try {
            $stmt = db()->prepare(
                'INSERT INTO bands (school_id, band_key, label, lessons_per_day, lesson_length_minutes, day_start_time, break_config, active) VALUES (?, ?, ?, ?, ?, ?, ?, TRUE)'
            );
            $stmt->execute([$schoolId, $bandKey, $label, $lessonsPerDay, $lessonLength, $dayStartTime, $breakConfig]);
            $success = "Band added successfully with lessons starting at {$dayStartTime} sharp.";
            logAudit('create', 'band', (int) db()->lastInsertId(), ['band_key' => $bandKey, 'label' => $label, 'day_start_time' => $dayStartTime]);
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
        $stmt->execute([(bool)$active, $bandId, $schoolId]);
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

// Handle band update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update') {
    $bandId = (int) ($_POST['band_id'] ?? 0);
    $bandKey = trim($_POST['band_key'] ?? '');
    $label = trim($_POST['label'] ?? '');
    $lessonsPerDay = (int) ($_POST['lessons_per_day'] ?? 8);
    $lessonLength = (int) ($_POST['lesson_length_minutes'] ?? 40);
    $dayStartTime = trim($_POST['day_start_time'] ?? '08:00');
    if (!preg_match('/^\d{2}:\d{2}$/', $dayStartTime)) {
        $dayStartTime = '08:00';
    }
    $breakConfig = normalizeBreakConfig($_POST['break_config'] ?? null);

    if ($bandKey === '' || $label === '') {
        $error = 'Band key and label are required.';
    } else {
        try {
            $stmt = db()->prepare(
                'UPDATE bands SET band_key = ?, label = ?, lessons_per_day = ?, lesson_length_minutes = ?, day_start_time = ?, break_config = ? WHERE id = ? AND school_id = ?'
            );
            $stmt->execute([
                $bandKey,
                $label,
                $lessonsPerDay,
                $lessonLength,
                $dayStartTime,
                $breakConfig,
                $bandId,
                $schoolId,
            ]);
            $success = "Band schedule updated successfully (Start: {$dayStartTime}). New timetable generations will lock these exact hours.";
            logAudit('update', 'band', $bandId, ['band_key' => $bandKey, 'label' => $label, 'day_start_time' => $dayStartTime]);
        } catch (Throwable $e) {
            $error = 'Could not update band. Band key may already exist.';
        }
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
        $driver = Config::get('database.driver', 'mysql');
        $sql .= $driver === 'pgsql' ? ' AND active = (?::boolean)' : ' AND active = ?';
        $params[] = ($statusFilter === 'active');
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
        $driver = Config::get('database.driver', 'mysql');
        $sql .= $driver === 'pgsql' ? ' AND active = (?::boolean)' : ' AND active = ?';
        $params[] = ($statusFilter === 'active');
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

$pageTitle = 'Grade Bands & Bell Schedules — ' . ($school['name'] ?? 'School');
require __DIR__ . '/_header.php';
?>

<div class="card">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; flex-wrap: wrap; gap: 10px;">
        <div>
            <h2 style="margin: 0;">Grade Bands & Bell Schedules</h2>
            <p class="tt-sub" style="margin: 4px 0 0;">
                Configure exact lesson start times (e.g. 8:00 AM sharp, 7:45 AM, 8:20 AM) and custom locked breaks (tea breaks, lunch) for each section of your school.
            </p>
        </div>
        <div style="display: flex; gap: 8px;">
            <a href="export_csv.php?entity=bands&search=<?php echo htmlspecialchars($search); ?>&status=<?php echo htmlspecialchars($statusFilter); ?>&format=csv" class="btn btn-secondary" style="padding: 8px 14px;">Export CSV</a>
            <a href="export_csv.php?entity=bands&search=<?php echo htmlspecialchars($search); ?>&status=<?php echo htmlspecialchars($statusFilter); ?>&format=xlsx" class="btn btn-secondary" style="padding: 8px 14px;">Export Excel</a>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="error mb-3"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="success mb-3"><?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>

    <!-- Add New Band Form -->
    <div style="background: var(--bg-light); border: 1px solid var(--border); border-radius: 8px; padding: 18px; margin-bottom: 24px;">
        <h3 style="margin: 0 0 12px; font-size: 1rem; color: var(--primary-dark);">➕ Add New Grade Band & Bell Schedule</h3>
        <form method="post">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="create">

            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px;">
                <div>
                    <label for="band_key"><strong>Band Key Identifier</strong></label>
                    <input type="text" id="band_key" name="band_key" required placeholder="e.g. cbc-jss, grade-7-9, secondary">
                    <span style="font-size:0.75rem; color:var(--text-muted);">Use hyphens, no spaces.</span>
                </div>

                <div>
                    <label for="label"><strong>Display Label</strong></label>
                    <input type="text" id="label" name="label" required placeholder="e.g. Junior Secondary (Grade 7–9)">
                </div>

                <div>
                    <label for="day_start_time"><strong>Lesson Start Time</strong></label>
                    <input type="text" id="day_start_time" name="day_start_time" value="08:00" pattern="^\d{2}:\d{2}$" placeholder="08:00" required>
                    <div style="display: flex; gap: 4px; margin-top: 4px; flex-wrap: wrap;">
                        <button type="button" class="btn btn-secondary btn-sm" style="padding: 2px 6px; font-size: 0.72rem;" onclick="setStartTime('08:00', 'day_start_time')">08:00 Sharp</button>
                        <button type="button" class="btn btn-secondary btn-sm" style="padding: 2px 6px; font-size: 0.72rem;" onclick="setStartTime('07:45', 'day_start_time')">07:45 Early</button>
                        <button type="button" class="btn btn-secondary btn-sm" style="padding: 2px 6px; font-size: 0.72rem;" onclick="setStartTime('08:15', 'day_start_time')">08:15</button>
                        <button type="button" class="btn btn-secondary btn-sm" style="padding: 2px 6px; font-size: 0.72rem;" onclick="setStartTime('08:20', 'day_start_time')">08:20 CBC</button>
                        <button type="button" class="btn btn-secondary btn-sm" style="padding: 2px 6px; font-size: 0.72rem;" onclick="setStartTime('08:30', 'day_start_time')">08:30</button>
                    </div>
                </div>

                <div>
                    <label for="lesson_length_minutes"><strong>Lesson Duration (minutes)</strong></label>
                    <input type="number" id="lesson_length_minutes" name="lesson_length_minutes" value="40" min="15" max="120" required>
                </div>

                <div>
                    <label for="lessons_per_day"><strong>Lessons Per Day</strong></label>
                    <input type="number" id="lessons_per_day" name="lessons_per_day" value="8" min="1" max="15" required>
                </div>
            </div>

            <div style="margin-top: 14px;">
                <label for="break_config"><strong>Locked Breaks Configuration (JSON format)</strong></label>
                <div style="margin-bottom: 6px; display: flex; gap: 6px; flex-wrap: wrap; align-items: center;">
                    <span style="font-size:0.75rem; font-weight:700; color:var(--text-muted);">Quick Presets:</span>
                    <button type="button" class="btn btn-secondary btn-sm" style="padding: 4px 8px; font-size:0.75rem;" onclick="applyBreakPreset('standard_8am', 'break_config', 'day_start_time', 'lesson_length_minutes')">
                        ☕ Standard 8:00 Sharp (Tea: 10:00–10:30, Lunch: 12:30–13:30)
                    </button>
                    <button type="button" class="btn btn-secondary btn-sm" style="padding: 4px 8px; font-size:0.75rem;" onclick="applyBreakPreset('cbc_jss', 'break_config', 'day_start_time', 'lesson_length_minutes')">
                        🔵 CBC JSS 8:20 (Tea: 10:20–10:50, Lunch: 12:50–13:50)
                    </button>
                    <button type="button" class="btn btn-secondary btn-sm" style="padding: 4px 8px; font-size:0.75rem;" onclick="applyBreakPreset('two_tea_breaks', 'break_config', 'day_start_time', 'lesson_length_minutes')">
                        🟠 Two Tea Breaks (Short: 09:20–09:40, Tea: 11:00–11:30, Lunch: 13:30–14:20)
                    </button>
                    <button type="button" class="btn btn-secondary btn-sm" style="padding: 4px 8px; font-size:0.75rem;" onclick="applyBreakPreset('early_boarding', 'break_config', 'day_start_time', 'lesson_length_minutes')">
                        🟣 Early Boarding 07:45 (Tea: 09:45–10:15, Lunch: 12:15–13:15)
                    </button>
                </div>
                <textarea id="break_config" name="break_config" rows="2" style="font-family: monospace; font-size:0.85rem;" required>[{"time":"10:00-10:30","label":"TEA BREAK"},{"time":"12:30-13:30","label":"LUNCH BREAK"}]</textarea>
                <span style="font-size:0.75rem; color:var(--text-muted);">
                    Locked breaks are strictly protected: the scheduler will <strong>never</strong> place any teaching lessons during these times.
                </span>
            </div>

            <div style="margin-top: 14px;">
                <button type="submit">Create Band & Schedule</button>
            </div>
        </form>
    </div>

    <!-- Existing Bands Table -->
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; flex-wrap: wrap; gap: 8px;">
        <h3 style="margin: 0; font-size: 1.1rem;">Existing Grade Bands (<?php echo $totalCount; ?>)</h3>
        <form method="get" style="display: flex; gap: 8px; margin: 0; flex-wrap: wrap;">
            <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search bands..." style="width: 200px; margin: 0; padding: 6px 10px;">
            <select name="status" style="margin: 0; padding: 6px 10px;">
                <option value="">All Status</option>
                <option value="active" <?php echo $statusFilter === 'active' ? 'selected' : ''; ?>>Active</option>
                <option value="inactive" <?php echo $statusFilter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
            </select>
            <button type="submit" style="padding: 6px 12px;">Filter</button>
            <?php if ($search !== '' || $statusFilter !== ''): ?>
                <a href="bands.php" class="btn btn-secondary" style="padding: 6px 12px;">Clear</a>
            <?php endif; ?>
        </form>
    </div>

    <?php if (empty($bands)): ?>
        <p class="empty">No grade bands configured yet. Add one above to define your school's schedule structure.</p>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th style="width: 40px;"><input type="checkbox" id="select-all" onchange="toggleAllCheckboxes(this)"></th>
                    <th>Key</th>
                    <th>Label</th>
                    <th>Day Start</th>
                    <th>Duration</th>
                    <th>Lessons/Day</th>
                    <th>Locked Breaks</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($bands as $band): ?>
                    <?php
                    $startTime = (string) ($band['day_start_time'] ?? '08:00');
                    $breakDecoded = [];
                    if (!empty($band['break_config'])) {
                        $dec = is_string($band['break_config']) ? json_decode($band['break_config'], true) : $band['break_config'];
                        if (is_array($dec)) {
                            $breakDecoded = $dec;
                        }
                    }
                    ?>
                    <tr>
                        <td><input type="checkbox" name="band_ids[]" value="<?php echo (int) $band['id']; ?>" class="row-checkbox"></td>
                        <td><code><?php echo htmlspecialchars($band['band_key']); ?></code></td>
                        <td><strong><?php echo htmlspecialchars($band['label']); ?></strong></td>
                        <td>
                            <span class="badge" style="background:#e0e7ff; color:#3730a3; font-weight:700;">
                                ⏰ <?php echo htmlspecialchars($startTime); ?>
                            </span>
                        </td>
                        <td><?php echo (int) $band['lesson_length_minutes']; ?> min</td>
                        <td><?php echo (int) $band['lessons_per_day']; ?>/day</td>
                        <td>
                            <?php if (empty($breakDecoded)): ?>
                                <span style="color:var(--text-muted); font-size:0.75rem;">None</span>
                            <?php else: ?>
                                <div style="display:flex; flex-direction:column; gap:3px;">
                                    <?php foreach ($breakDecoded as $bc): ?>
                                        <span style="font-size:0.75rem; color:var(--text);">
                                            <strong><?php echo htmlspecialchars((string) ($bc['label'] ?? 'BREAK')); ?>:</strong>
                                            <?php echo htmlspecialchars((string) ($bc['time'] ?? '')); ?>
                                        </span>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php echo $band['active'] ? '<span style="color:var(--secondary);font-weight:600;">Active</span>' : '<span style="color:var(--text-muted);">Inactive</span>'; ?>
                        </td>
                        <td class="row-actions">
                            <button type="button"
                                    onclick="showEditForm(
                                        <?php echo (int) $band['id']; ?>,
                                        '<?php echo htmlspecialchars($band['band_key'], ENT_QUOTES); ?>',
                                        '<?php echo htmlspecialchars($band['label'], ENT_QUOTES); ?>',
                                        <?php echo (int) $band['lessons_per_day']; ?>,
                                        <?php echo (int) $band['lesson_length_minutes']; ?>,
                                        '<?php echo htmlspecialchars($startTime, ENT_QUOTES); ?>',
                                        '<?php echo htmlspecialchars(is_string($band['break_config']) ? $band['break_config'] : json_encode($band['break_config']), ENT_QUOTES); ?>'
                                    )"
                                    class="btn-secondary" style="padding: 6px 10px;">
                                Edit
                            </button>
                            <form method="post" style="display:inline;">
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="band_id" value="<?php echo (int) $band['id']; ?>">
                                <input type="hidden" name="active" value="<?php echo $band['active'] ? 0 : 1; ?>">
                                <button type="submit" class="btn-secondary" style="padding: 6px 10px;">
                                    <?php echo $band['active'] ? 'Deactivate' : 'Activate'; ?>
                                </button>
                            </form>
                            <form method="post" onsubmit="return confirmDelete('Delete this band? It may have classes assigned.');" style="display:inline;">
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="band_id" value="<?php echo (int) $band['id']; ?>">
                                <button type="submit" class="btn-danger" style="padding: 6px 10px;">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?php if (!empty($bands)): ?>
            <div style="margin-top: 16px; display: flex; gap: 8px;">
                <form method="post" onsubmit="return confirmDelete('Delete selected bands? They may have classes assigned.');">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="bulk_delete">
                    <button type="submit" class="btn-danger">Delete Selected</button>
                </form>
                <span style="color: var(--text-muted); font-size: 0.875rem; padding: 8px 0;">
                    <span id="selected-count">0</span> selected
                </span>
            </div>
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

<!-- Edit Band Modal -->
<div id="edit-modal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center;">
    <div class="card" style="max-width: 600px; width: 92%; max-height: 90vh; overflow-y: auto;">
        <h2>Edit Grade Band & Bell Schedule</h2>
        <form method="post">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="band_id" id="edit-band-id">

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px;">
                <div>
                    <label for="edit-band_key">Band Key</label>
                    <input type="text" id="edit-band_key" name="band_key" required>
                </div>
                <div>
                    <label for="edit-label">Display Label</label>
                    <input type="text" id="edit-label" name="label" required>
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 14px; margin-top: 10px;">
                <div>
                    <label for="edit-day_start_time">Day Start Time</label>
                    <input type="text" id="edit-day_start_time" name="day_start_time" required pattern="^\d{2}:\d{2}$" placeholder="08:00">
                    <div style="display: flex; gap: 3px; margin-top: 4px; flex-wrap: wrap;">
                        <button type="button" class="btn btn-secondary btn-sm" style="padding: 1px 4px; font-size: 0.68rem;" onclick="setStartTime('08:00', 'edit-day_start_time')">08:00</button>
                        <button type="button" class="btn btn-secondary btn-sm" style="padding: 1px 4px; font-size: 0.68rem;" onclick="setStartTime('07:45', 'edit-day_start_time')">07:45</button>
                        <button type="button" class="btn btn-secondary btn-sm" style="padding: 1px 4px; font-size: 0.68rem;" onclick="setStartTime('08:20', 'edit-day_start_time')">08:20</button>
                    </div>
                </div>
                <div>
                    <label for="edit-lesson_length_minutes">Lesson Duration (min)</label>
                    <input type="number" id="edit-lesson_length_minutes" name="lesson_length_minutes" min="15" max="120" required>
                </div>
                <div>
                    <label for="edit-lessons_per_day">Lessons Per Day</label>
                    <input type="number" id="edit-lessons_per_day" name="lessons_per_day" min="1" max="15" required>
                </div>
            </div>

            <div style="margin-top: 12px;">
                <label for="edit-break_config">Locked Breaks Configuration (JSON)</label>
                <div style="margin-bottom: 6px; display: flex; gap: 4px; flex-wrap: wrap;">
                    <button type="button" class="btn btn-secondary btn-sm" style="padding: 3px 6px; font-size: 0.72rem;" onclick="applyBreakPreset('standard_8am', 'edit-break_config', 'edit-day_start_time', 'edit-lesson_length_minutes')">
                        Standard 8:00
                    </button>
                    <button type="button" class="btn btn-secondary btn-sm" style="padding: 3px 6px; font-size: 0.72rem;" onclick="applyBreakPreset('cbc_jss', 'edit-break_config', 'edit-day_start_time', 'edit-lesson_length_minutes')">
                        CBC JSS 8:20
                    </button>
                    <button type="button" class="btn btn-secondary btn-sm" style="padding: 3px 6px; font-size: 0.72rem;" onclick="applyBreakPreset('two_tea_breaks', 'edit-break_config', 'edit-day_start_time', 'edit-lesson_length_minutes')">
                        2 Tea Breaks
                    </button>
                    <button type="button" class="btn btn-secondary btn-sm" style="padding: 3px 6px; font-size: 0.72rem;" onclick="applyBreakPreset('early_boarding', 'edit-break_config', 'edit-day_start_time', 'edit-lesson_length_minutes')">
                        Early 07:45
                    </button>
                </div>
                <textarea id="edit-break_config" name="break_config" rows="3" style="font-family: monospace; font-size: 0.85rem;" required></textarea>
            </div>

            <div style="display: flex; gap: 8px; margin-top: 16px;">
                <button type="submit">Update Band</button>
                <button type="button" onclick="hideEditForm()" class="btn btn-secondary">Cancel</button>
            </div>
        </form>
    </div>
</div>

<script>
const PRESETS = {
    standard_8am: {
        startTime: '08:00',
        duration: 40,
        breaks: '[{"time":"10:00-10:30","label":"TEA BREAK"},{"time":"12:30-13:30","label":"LUNCH BREAK"}]'
    },
    cbc_jss: {
        startTime: '08:20',
        duration: 40,
        breaks: '[{"time":"10:20-10:50","label":"TEA BREAK"},{"time":"12:50-13:50","label":"LUNCH BREAK"}]'
    },
    two_tea_breaks: {
        startTime: '08:00',
        duration: 40,
        breaks: '[{"time":"09:20-09:40","label":"SHORT BREAK"},{"time":"11:00-11:30","label":"TEA BREAK"},{"time":"13:30-14:20","label":"LUNCH BREAK"}]'
    },
    early_boarding: {
        startTime: '07:45',
        duration: 40,
        breaks: '[{"time":"09:45-10:15","label":"TEA BREAK"},{"time":"12:15-13:15","label":"LUNCH BREAK"}]'
    }
};

function setStartTime(time, inputId) {
    const el = document.getElementById(inputId);
    if (el) el.value = time;
}

function applyBreakPreset(presetKey, breakInputId, startInputId, durationInputId) {
    const p = PRESETS[presetKey];
    if (!p) return;
    const bEl = document.getElementById(breakInputId);
    const sEl = document.getElementById(startInputId);
    const dEl = document.getElementById(durationInputId);
    if (bEl) bEl.value = p.breaks;
    if (sEl) sEl.value = p.startTime;
    if (dEl && p.duration) dEl.value = p.duration;
}

function showEditForm(bandId, bandKey, label, lessonsPerDay, lessonLength, dayStartTime, breakConfig) {
    document.getElementById('edit-band-id').value = bandId;
    document.getElementById('edit-band_key').value = bandKey;
    document.getElementById('edit-label').value = label;
    document.getElementById('edit-lessons_per_day').value = lessonsPerDay;
    document.getElementById('edit-lesson_length_minutes').value = lessonLength;
    document.getElementById('edit-day_start_time').value = dayStartTime || '08:00';
    document.getElementById('edit-break_config').value = breakConfig || '[{"time":"10:00-10:30","label":"TEA BREAK"},{"time":"12:30-13:30","label":"LUNCH BREAK"}]';
    document.getElementById('edit-modal').style.display = 'flex';
}

function hideEditForm() {
    document.getElementById('edit-modal').style.display = 'none';
}

document.getElementById('edit-modal')?.addEventListener('click', function(e) {
    if (e.target === this) {
        hideEditForm();
    }
});

function toggleAllCheckboxes(source) {
    const checkboxes = document.querySelectorAll('.row-checkbox');
    checkboxes.forEach(cb => cb.checked = source.checked);
    updateSelectedCount();
}

function updateSelectedCount() {
    const checked = document.querySelectorAll('.row-checkbox:checked');
    const el = document.getElementById('selected-count');
    if (el) el.textContent = checked.length;
}

document.querySelectorAll('.row-checkbox').forEach(cb => {
    cb.addEventListener('change', updateSelectedCount);
});
</script>

<?php require __DIR__ . '/_footer.php'; ?>

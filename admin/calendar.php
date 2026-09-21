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
$currentYear = date('Y');
require_once __DIR__ . '/../db/knec_dates.php';

// Handle term creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_term') {
    $year = (int) ($_POST['year'] ?? $currentYear);
    $termNumber = (int) ($_POST['term_number'] ?? 1);
    $termName = trim($_POST['term_name'] ?? '');
    $startDate = $_POST['start_date'] ?? '';
    $endDate = $_POST['end_date'] ?? '';
    $isCurrent = isset($_POST['is_current']);

    if ($termName === '' || $startDate === '' || $endDate === '') {
        $error = 'Term name, start date, and end date are required.';
    } elseif (strtotime($startDate) > strtotime($endDate)) {
        $error = 'Start date must be before end date.';
    } else {
        try {
            db()->beginTransaction();

            // If setting as current, unset previous current term
            if ($isCurrent) {
                $stmt = db()->prepare('UPDATE school_calendar SET is_current = FALSE WHERE school_id = ?');
                $stmt->execute([$schoolId]);
            }

            $stmt = db()->prepare(
                'INSERT INTO school_calendar (school_id, year, term_number, term_name, start_date, end_date, is_current) VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([$schoolId, $year, $termNumber, $termName, $startDate, $endDate, $isCurrent]);

            db()->commit();
            $success = 'Term added successfully.';
            logAudit('create', 'school_calendar', (int) db()->lastInsertId(), ['term_name' => $termName, 'year' => $year]);
        } catch (Throwable $e) {
            db()->rollBack();
            $error = 'Could not add term. Term may already exist for this year.';
        }
    }
}

// Handle holiday creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_holiday') {
    $holidayName = trim($_POST['holiday_name'] ?? '');
    $holidayDate = $_POST['holiday_date'] ?? '';
    $holidayType = $_POST['holiday_type'] ?? 'public';
    $affectsTimetabling = isset($_POST['affects_timetabling']);
    $notes = trim($_POST['notes'] ?? '');

    if ($holidayName === '' || $holidayDate === '') {
        $error = 'Holiday name and date are required.';
    } else {
        try {
            $stmt = db()->prepare(
                'INSERT INTO school_holidays (school_id, holiday_name, holiday_date, holiday_type, affects_timetabling, notes) VALUES (?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([$schoolId, $holidayName, $holidayDate, $holidayType, $affectsTimetabling, $notes !== '' ? $notes : null]);
            $success = 'Holiday added successfully.';
            logAudit('create', 'school_holiday', (int) db()->lastInsertId(), ['holiday_name' => $holidayName, 'holiday_date' => $holidayDate]);
        } catch (Throwable $e) {
            $error = 'Could not add holiday. Date may already exist.';
        }
    }
}

// Handle term deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_term') {
    $termId = (int) ($_POST['term_id'] ?? 0);
    try {
        $stmt = db()->prepare('DELETE FROM school_calendar WHERE id = ? AND school_id = ?');
        $stmt->execute([$termId, $schoolId]);
        $success = 'Term deleted successfully.';
        logAudit('delete', 'school_calendar', $termId);
    } catch (Throwable $e) {
        $error = 'Could not delete term.';
    }
}

// Handle holiday deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_holiday') {
    $holidayId = (int) ($_POST['holiday_id'] ?? 0);
    try {
        $stmt = db()->prepare('DELETE FROM school_holidays WHERE id = ? AND school_id = ?');
        $stmt->execute([$holidayId, $schoolId]);
        $success = 'Holiday deleted successfully.';
        logAudit('delete', 'school_holiday', $holidayId);
    } catch (Throwable $e) {
        $error = 'Could not delete holiday.';
    }
}

// Handle KNEC dates auto-population
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'populate_knec') {
    $year = (int) ($_POST['year'] ?? $currentYear);
    try {
        $addedCount = populateKNECDatesForSchool($schoolId, $year);
        $success = "Added {$addedCount} KNEC exam dates for {$year}.";
        logAudit('create', 'school_holiday', null, ['action' => 'populate_knec', 'year' => $year, 'count' => $addedCount]);
    } catch (Throwable $e) {
        $error = 'Could not populate KNEC dates: ' . $e->getMessage();
    }
}

// Get calendar terms
$stmt = db()->prepare('SELECT * FROM school_calendar WHERE school_id = ? ORDER BY year DESC, term_number ASC');
$stmt->execute([$schoolId]);
$terms = $stmt->fetchAll();

// Get holidays
$stmt = db()->prepare('SELECT * FROM school_holidays WHERE school_id = ? ORDER BY holiday_date ASC');
$stmt->execute([$schoolId]);
$holidays = $stmt->fetchAll();

$pageTitle = 'School Calendar — ' . $school['name'];
require __DIR__ . '/_header.php';
?>

<div class="card">
    <h2>School Calendar</h2>
    <p class="empty">Manage term dates and holidays for <?php echo htmlspecialchars($school['name']); ?></p>

    <?php if ($error): ?>
        <div class="error"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="success"><?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>

    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 24px;">
        <!-- Add Term Form -->
        <div>
            <h3>Add Term</h3>
            <form method="post">
                <input type="hidden" name="action" value="create_term">
                <label for="year">Year</label>
                <input type="number" id="year" name="year" value="<?php echo $currentYear; ?>" min="2020" max="2030" required>

                <label for="term_number">Term Number</label>
                <select id="term_number" name="term_number" required>
                    <option value="1">Term 1</option>
                    <option value="2">Term 2</option>
                    <option value="3">Term 3</option>
                </select>

                <label for="term_name">Term Name</label>
                <input type="text" id="term_name" name="term_name" placeholder="e.g. Term 1 2025" required>

                <label for="start_date">Start Date</label>
                <input type="date" id="start_date" name="start_date" required>

                <label for="end_date">End Date</label>
                <input type="date" id="end_date" name="end_date" required>

                <label style="display: flex; align-items: center; gap: 8px; margin: 16px 0;">
                    <input type="checkbox" id="is_current" name="is_current">
                    <span>Set as current term</span>
                </label>

                <button type="submit">Add Term</button>
            </form>
        </div>

        <!-- Add Holiday Form -->
        <div>
            <h3>Add Holiday</h3>
            <form method="post">
                <input type="hidden" name="action" value="create_holiday">
                <label for="holiday_name">Holiday Name</label>
                <input type="text" id="holiday_name" name="holiday_name" placeholder="e.g. Mashujaa Day" required>

                <label for="holiday_date">Date</label>
                <input type="date" id="holiday_date" name="holiday_date" required>

                <label for="holiday_type">Holiday Type</label>
                <select id="holiday_type" name="holiday_type">
                    <option value="public">Public Holiday</option>
                    <option value="school_break">School Break</option>
                    <option value="exam_period">Exam Period</option>
                    <option value="other">Other</option>
                </select>

                <label style="display: flex; align-items: center; gap: 8px; margin: 16px 0;">
                    <input type="checkbox" id="affects_timetabling" name="affects_timetabling" checked>
                    <span>Block scheduling on this date</span>
                </label>

                <label for="notes">Notes (optional)</label>
                <textarea id="notes" name="notes" rows="2" placeholder="Additional details..."></textarea>

                <button type="submit">Add Holiday</button>
            </form>

            <div style="margin-top: 24px; padding: 16px; background: #f0f9ff; border: 1px solid #bae6fd; border-radius: 8px;">
                <h4 style="margin: 0 0 12px; color: #0369a1;">Quick Add KNEC Exam Dates</h4>
                <p style="margin: 0 0 12px; font-size: 0.875rem; color: #0c4a6e;">Auto-populate KCSE, KCPE, and KCPA exam periods for a given year.</p>
                <form method="post" style="margin: 0;">
                    <input type="hidden" name="action" value="populate_knec">
                    <div style="display: flex; gap: 8px; align-items: center;">
                        <label for="knec_year" style="margin: 0;">Year:</label>
                        <input type="number" id="knec_year" name="year" value="<?php echo $currentYear; ?>" min="2020" max="2030" style="width: 80px; margin: 0;">
                        <button type="submit" class="btn-secondary" style="margin: 0; padding: 8px 16px;">Add KNEC Dates</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <h2>Academic Terms (<?php echo count($terms); ?>)</h2>
    <?php if (empty($terms)): ?>
        <p class="empty">No terms added yet.</p>
    <?php else: ?>
        <table>
            <thead><tr><th>Year</th><th>Term</th><th>Period</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody>
                <?php foreach ($terms as $term): ?>
                    <tr>
                        <td><?php echo (int) $term['year']; ?></td>
                        <td><?php echo htmlspecialchars($term['term_name']); ?></td>
                        <td><?php echo htmlspecialchars(date('j M Y', strtotime($term['start_date']))); ?> - <?php echo htmlspecialchars(date('j M Y', strtotime($term['end_date']))); ?></td>
                        <td><?php echo $term['is_current'] ? '<span style="color:var(--secondary);font-weight:600;">Current</span>' : '<span style="color:var(--text-muted);">Inactive</span>'; ?></td>
                        <td class="row-actions">
                            <form method="post" onsubmit="return confirmDelete('Delete this term?');">
                                <input type="hidden" name="action" value="delete_term">
                                <input type="hidden" name="term_id" value="<?php echo (int) $term['id']; ?>">
                                <button type="submit" class="btn-danger">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<div class="card">
    <h2>Holidays & Important Dates (<?php echo count($holidays); ?>)</h2>
    <?php if (empty($holidays)): ?>
        <p class="empty">No holidays added yet.</p>
    <?php else: ?>
        <table>
            <thead><tr><th>Date</th><th>Name</th><th>Type</th><th>Blocks Scheduling</th><th>Actions</th></tr></thead>
            <tbody>
                <?php foreach ($holidays as $holiday): ?>
                    <tr>
                        <td><?php echo htmlspecialchars(date('j M Y', strtotime($holiday['holiday_date']))); ?></td>
                        <td><?php echo htmlspecialchars($holiday['holiday_name']); ?></td>
                        <td><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $holiday['holiday_type']))); ?></td>
                        <td><?php echo $holiday['affects_timetabling'] ? '✓' : '—'; ?></td>
                        <td class="row-actions">
                            <form method="post" onsubmit="return confirmDelete('Delete this holiday?');">
                                <input type="hidden" name="action" value="delete_holiday">
                                <input type="hidden" name="holiday_id" value="<?php echo (int) $holiday['id']; ?>">
                                <button type="submit" class="btn-danger">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/_footer.php'; ?>

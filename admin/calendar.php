<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../db/db.php';

$schoolId = requireLoginAndGetSchoolId();
$stmt = db()->prepare('SELECT * FROM schools WHERE id = ?');
$stmt->execute([$schoolId]);
$school = $stmt->fetch();

$error = null;
$success = null;
$currentYear = (int) date('Y');


function seedKenyaCalendarDefaults(int $schoolId, int $year): array
{
    $actions = [];
    $terms = [
        [1, "Term 1 {$year}", "{$year}-01-06", "{$year}-04-04", true],
        [2, "Term 2 {$year}", "{$year}-05-05", "{$year}-08-01", false],
        [3, "Term 3 {$year}", "{$year}-09-01", "{$year}-11-28", false],
    ];
    $stmt = db()->prepare(
        'INSERT INTO school_calendar (school_id, year, term_number, term_name, start_date, end_date, is_current) VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    foreach ($terms as [$num, $name, $start, $end, $cur]) {
        try {
            $stmt->execute([$schoolId, $year, $num, $name, $start, $end, $cur]);
            $actions[] = "Added {$name}";
        } catch (Throwable) {}
    }
    $holidays = [
        ["New Year's Day", "{$year}-01-01", 'public'],
        ['Labour Day', "{$year}-05-01", 'public'],
        ['Madaraka Day', "{$year}-06-01", 'public'],
        ['Mashujaa Day', "{$year}-10-20", 'public'],
        ['Jamhuri Day', "{$year}-12-12", 'public'],
        ['Christmas Day', "{$year}-12-25", 'public'],
        ['Boxing Day', "{$year}-12-26", 'public'],
    ];
    $hstmt = db()->prepare(
        'INSERT INTO school_holidays (school_id, holiday_name, holiday_date, holiday_type, affects_timetabling, notes) VALUES (?, ?, ?, ?, ?, ?)'
    );
    foreach ($holidays as [$name, $date, $type]) {
        try {
            $hstmt->execute([$schoolId, $name, $date, $type, true, 'National public holiday']);
            $actions[] = "Added holiday {$name}";
        } catch (Throwable) {}
    }
    return $actions;
}



if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'seed_defaults') {
        try {
            $done = seedKenyaCalendarDefaults($schoolId, $currentYear);
            $success = $done === [] ? 'Defaults already present for this year.' : ('Seeded: ' . implode('; ', array_slice($done, 0, 6)) . (count($done) > 6 ? '…' : ''));
        } catch (Throwable $e) {
            $error = 'Seed failed: ' . $e->getMessage();
        }
    }

    if ($action === 'create_term') {
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
                if ($isCurrent) {
                    db()->prepare('UPDATE school_calendar SET is_current = FALSE WHERE school_id = ?')->execute([$schoolId]);
                }
                db()->prepare(
                    'INSERT INTO school_calendar (school_id, year, term_number, term_name, start_date, end_date, is_current) VALUES (?, ?, ?, ?, ?, ?, ?)'
                )->execute([$schoolId, $year, $termNumber, $termName, $startDate, $endDate, $isCurrent]);
                db()->commit();
                $success = 'Term added successfully.';
            } catch (Throwable $e) {
                db()->rollBack();
                $error = 'Could not add term: ' . $e->getMessage();
            }
        }
    }

    if ($action === 'create_holiday') {
        $holidayName = trim($_POST['holiday_name'] ?? '');
        $holidayDate = $_POST['holiday_date'] ?? '';
        $holidayType = $_POST['holiday_type'] ?? 'public';
        $affects = isset($_POST['affects_timetabling']);
        $notes = trim($_POST['notes'] ?? '');
        if ($holidayName === '' || $holidayDate === '') {
            $error = 'Holiday name and date are required.';
        } else {
            try {
                db()->prepare(
                    'INSERT INTO school_holidays (school_id, holiday_name, holiday_date, holiday_type, affects_timetabling, notes) VALUES (?, ?, ?, ?, ?, ?)'
                )->execute([$schoolId, $holidayName, $holidayDate, $holidayType, $affects, $notes !== '' ? $notes : null]);
                $success = 'Holiday added.';
            } catch (Throwable $e) {
                $error = 'Could not add holiday: ' . $e->getMessage();
            }
        }
    }

    if ($action === 'delete_term') {
        try {
            db()->prepare('DELETE FROM school_calendar WHERE id = ? AND school_id = ?')
                ->execute([(int) ($_POST['term_id'] ?? 0), $schoolId]);
            $success = 'Term deleted.';
        } catch (Throwable) { $error = 'Could not delete term.'; }
    }

    if ($action === 'delete_holiday') {
        try {
            db()->prepare('DELETE FROM school_holidays WHERE id = ? AND school_id = ?')
                ->execute([(int) ($_POST['holiday_id'] ?? 0), $schoolId]);
            $success = 'Holiday deleted.';
        } catch (Throwable) { $error = 'Could not delete holiday.'; }
    }

    if ($action === 'set_current_term') {
        try {
            db()->beginTransaction();
            db()->prepare('UPDATE school_calendar SET is_current = FALSE WHERE school_id = ?')->execute([$schoolId]);
            db()->prepare('UPDATE school_calendar SET is_current = TRUE WHERE id = ? AND school_id = ?')
                ->execute([(int) ($_POST['term_id'] ?? 0), $schoolId]);
            db()->commit();
            $success = 'Current term updated.';
        } catch (Throwable) {
            db()->rollBack();
            $error = 'Could not update current term.';
        }
    }
}

$terms = [];
$holidays = [];
try {
    $stmt = db()->prepare('SELECT * FROM school_calendar WHERE school_id = ? ORDER BY year DESC, term_number ASC');
    $stmt->execute([$schoolId]);
    $terms = $stmt->fetchAll();
    $stmt = db()->prepare('SELECT * FROM school_holidays WHERE school_id = ? ORDER BY holiday_date ASC');
    $stmt->execute([$schoolId]);
    $holidays = $stmt->fetchAll();
} catch (Throwable $e) {
    if ($error === null) $error = $e->getMessage();
}

$pageTitle = 'School Calendar — ' . ($school['name'] ?? 'School');
require __DIR__ . '/_header.php';
$csrf = htmlspecialchars(getCsrfToken());
?>
<div class="card">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
        <div>
            <h2 style="margin:0">School Calendar</h2>
            <p class="empty" style="margin:4px 0 0">Terms and holidays for <?php echo htmlspecialchars($school['name'] ?? ''); ?></p>
        </div>
        <?php if (empty($terms)): ?>
        <form method="post" style="margin:0">
            <input type="hidden" name="action" value="seed_defaults">
            <input type="hidden" name="_csrf_token" value="<?php echo $csrf; ?>">
            <button type="submit" class="btn">Seed Kenya defaults (<?php echo $currentYear; ?>)</button>
        </form>
        <?php endif; ?>
    </div>
    <?php if ($error): ?><div class="error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
    <?php if ($success): ?><div class="success"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:20px;margin-top:16px;">
        <div>
            <h3>Add Term</h3>
            <form method="post">
                <input type="hidden" name="action" value="create_term">
                <input type="hidden" name="_csrf_token" value="<?php echo $csrf; ?>">
                <label for="year">Year</label>
                <input type="number" id="year" name="year" value="<?php echo $currentYear; ?>" min="2020" max="2035" required>
                <label for="term_number">Term Number</label>
                <select id="term_number" name="term_number" required>
                    <option value="1">Term 1</option><option value="2">Term 2</option><option value="3">Term 3</option>
                </select>
                <label for="term_name">Term Name</label>
                <input type="text" id="term_name" name="term_name" placeholder="e.g. Term 1 <?php echo $currentYear; ?>" required>
                <label for="start_date">Start Date</label>
                <input type="date" id="start_date" name="start_date" required>
                <label for="end_date">End Date</label>
                <input type="date" id="end_date" name="end_date" required>
                <label style="display:flex;align-items:center;gap:8px;margin:12px 0;">
                    <input type="checkbox" name="is_current"><span>Set as current term</span>
                </label>
                <button type="submit">Add Term</button>
            </form>
        </div>
        <div>
            <h3>Add Holiday</h3>
            <form method="post">
                <input type="hidden" name="action" value="create_holiday">
                <input type="hidden" name="_csrf_token" value="<?php echo $csrf; ?>">
                <label for="holiday_name">Name</label>
                <input type="text" id="holiday_name" name="holiday_name" required placeholder="e.g. Mashujaa Day">
                <label for="holiday_date">Date</label>
                <input type="date" id="holiday_date" name="holiday_date" required>
                <label for="holiday_type">Type</label>
                <select id="holiday_type" name="holiday_type">
                    <option value="public">Public</option><option value="school">School</option><option value="exam">Exam / Closed</option>
                </select>
                <label style="display:flex;align-items:center;gap:8px;margin:12px 0;">
                    <input type="checkbox" name="affects_timetabling" checked><span>Affects timetabling</span>
                </label>
                <label for="notes">Notes</label>
                <input type="text" id="notes" name="notes" placeholder="Optional">
                <button type="submit">Add Holiday</button>
            </form>
        </div>
    </div>
</div>
<div class="card">
    <h2>Academic Terms (<?php echo count($terms); ?>)</h2>
    <?php if (empty($terms)): ?>
        <p class="empty">No terms yet. Click <strong>Seed Kenya defaults</strong> or add a term above.</p>
    <?php else: ?>
        <div style="overflow-x:auto;"><table>
            <thead><tr><th>Year</th><th>Term</th><th>Dates</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($terms as $term): ?>
                <tr>
                    <td><?php echo (int) $term['year']; ?></td>
                    <td><?php echo htmlspecialchars($term['term_name']); ?></td>
                    <td><?php
                        $sd = function_exists('formatAppDate') ? formatAppDate($term['start_date']) : date('j M Y', strtotime($term['start_date']));
                        $ed = function_exists('formatAppDate') ? formatAppDate($term['end_date']) : date('j M Y', strtotime($term['end_date']));
                        echo htmlspecialchars($sd . ' – ' . $ed);
                    ?></td>
                    <td><?php if (!empty($term['is_current'])): ?>
                        <span class="badge badge-success">Current</span>
                    <?php else: ?>
                        <form method="post" style="display:inline">
                            <input type="hidden" name="action" value="set_current_term">
                            <input type="hidden" name="term_id" value="<?php echo (int) $term['id']; ?>">
                            <input type="hidden" name="_csrf_token" value="<?php echo $csrf; ?>">
                            <button type="submit" class="btn btn-secondary btn-sm">Set current</button>
                        </form>
                    <?php endif; ?></td>
                    <td>
                        <form method="post" onsubmit="return confirm('Delete this term?');" style="display:inline">
                            <input type="hidden" name="action" value="delete_term">
                            <input type="hidden" name="term_id" value="<?php echo (int) $term['id']; ?>">
                            <input type="hidden" name="_csrf_token" value="<?php echo $csrf; ?>">
                            <button type="submit" class="btn btn-secondary btn-sm">Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    <?php endif; ?>
</div>
<div class="card">
    <h2>Holidays (<?php echo count($holidays); ?>)</h2>
    <?php if (empty($holidays)): ?>
        <p class="empty">No holidays yet. Seed defaults or add one above.</p>
    <?php else: ?>
        <div style="overflow-x:auto;"><table>
            <thead><tr><th>Date</th><th>Name</th><th>Type</th><th>Timetable</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($holidays as $h): ?>
                <tr>
                    <td><?php echo htmlspecialchars(function_exists('formatAppDate') ? formatAppDate($h['holiday_date']) : date('j M Y', strtotime($h['holiday_date']))); ?></td>
                    <td><?php echo htmlspecialchars($h['holiday_name']); ?></td>
                    <td><?php echo htmlspecialchars(ucfirst((string) $h['holiday_type'])); ?></td>
                    <td><?php echo !empty($h['affects_timetabling']) ? 'Yes' : 'No'; ?></td>
                    <td>
                        <form method="post" onsubmit="return confirm('Delete this holiday?');" style="display:inline">
                            <input type="hidden" name="action" value="delete_holiday">
                            <input type="hidden" name="holiday_id" value="<?php echo (int) $h['id']; ?>">
                            <input type="hidden" name="_csrf_token" value="<?php echo $csrf; ?>">
                            <button type="submit" class="btn btn-secondary btn-sm">Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/_footer.php'; ?>

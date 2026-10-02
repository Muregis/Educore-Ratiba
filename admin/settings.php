<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../db/db.php';
require_once __DIR__ . '/../db/audit_log.php';

$schoolId = requireLoginAndGetSchoolId();
$stmt = db()->prepare('SELECT * FROM schools WHERE id = ?');
$stmt->execute([$schoolId]);
$school = $stmt->fetch();
if (!$school) {
    header('Location: ../login.php');
    exit;
}

$error = null;
$success = null;

$dayPresets = [
    '5' => ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'],
    '6' => ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'],
    '7' => ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_settings') {
    $schoolType = trim($_POST['school_type'] ?? 'secondary');
    $daysPerWeek = (int) ($_POST['days_per_week'] ?? 5);
    if (!in_array($daysPerWeek, [5, 6, 7], true)) {
        $daysPerWeek = 5;
    }
    $dayNames = $dayPresets[(string) $daysPerWeek];
    $timeLimit = (int) ($_POST['generation_time_limit'] ?? 300);
    $timeLimit = max(60, min(1800, $timeLimit));
    $preferSpread = isset($_POST['prefer_spread']);
    $allowedTypes = ['primary', 'secondary', 'mixed', 'tertiary', 'international', 'other'];
    if (!in_array($schoolType, $allowedTypes, true)) {
        $schoolType = 'secondary';
    }

    try {
        $stmt = db()->prepare(
            'UPDATE schools SET school_type = ?, days_per_week = ?, day_names = ?, generation_time_limit = ?, prefer_spread = ? WHERE id = ?'
        );
        $driver = Config::get('database.driver', 'mysql');
        $dayNamesParam = json_encode($dayNames);
        $spreadParam = $driver === 'pgsql' ? ($preferSpread ? 'true' : 'false') : ($preferSpread ? 1 : 0);
        $stmt->execute([$schoolType, $daysPerWeek, $dayNamesParam, $timeLimit, $spreadParam, $schoolId]);
        $success = 'School scheduling settings saved. New generations will use these options.';
        logAudit('update', 'school_settings', $schoolId, [
            'days_per_week' => $daysPerWeek,
            'time_limit' => $timeLimit,
            'school_type' => $schoolType,
        ]);
        $stmt = db()->prepare('SELECT * FROM schools WHERE id = ?');
        $stmt->execute([$schoolId]);
        $school = $stmt->fetch();
    } catch (Throwable $e) {
        $error = 'Could not save settings. Run db/migrate_flexible_v1.sql on your database first. ('
            . htmlspecialchars($e->getMessage()) . ')';
    }
}

$currentDays = (int) ($school['days_per_week'] ?? 5);
$currentLimit = (int) ($school['generation_time_limit'] ?? 300);
$currentType = (string) ($school['school_type'] ?? 'secondary');
$currentSpread = schoolPrefersSpread($schoolId);
$readiness = getSchoolReadiness($schoolId);

$pageTitle = 'Scheduling Settings — ' . $school['name'];
require __DIR__ . '/_header.php';
?>

<div class="card">
    <h2>Scheduling settings</h2>
    <p class="empty">Tune Ratiba for your school size and calendar. Works for small primary schools through large multi-stream secondary campuses.</p>

    <?php if ($error): ?><div class="error"><?php echo $error; ?></div><?php endif; ?>
    <?php if ($success): ?><div class="success"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>

    <form method="post">
        <input type="hidden" name="action" value="save_settings">

        <label for="school_type">School type</label>
        <select id="school_type" name="school_type">
            <?php foreach (['primary' => 'Primary', 'secondary' => 'Secondary', 'mixed' => 'Mixed (primary + secondary)', 'tertiary' => 'Tertiary / college', 'international' => 'International', 'other' => 'Other'] as $val => $lab): ?>
                <option value="<?php echo $val; ?>" <?php echo $currentType === $val ? 'selected' : ''; ?>><?php echo $lab; ?></option>
            <?php endforeach; ?>
        </select>

        <label for="days_per_week">Teaching days per week</label>
        <select id="days_per_week" name="days_per_week">
            <option value="5" <?php echo $currentDays === 5 ? 'selected' : ''; ?>>5 days (Mon–Fri)</option>
            <option value="6" <?php echo $currentDays === 6 ? 'selected' : ''; ?>>6 days (Mon–Sat)</option>
            <option value="7" <?php echo $currentDays === 7 ? 'selected' : ''; ?>>7 days (full week)</option>
        </select>

        <label for="generation_time_limit">Generator time limit (seconds)</label>
        <input type="number" id="generation_time_limit" name="generation_time_limit" min="60" max="1800" value="<?php echo $currentLimit; ?>">
        <p class="empty" style="margin-top:4px">Small schools: 120–300. Large campuses (40+ classes): 600–1800.</p>

        <label style="display:flex;align-items:center;gap:8px;margin-top:16px">
            <input type="checkbox" name="prefer_spread" value="1" <?php echo $currentSpread ? 'checked' : ''; ?>>
            Prefer spreading subject lessons across different days
        </label>

        <button type="submit" style="margin-top:20px">Save settings</button>
    </form>
</div>

<div class="card">
    <h2>Scale readiness — <?php echo htmlspecialchars($readiness['scale']); ?> school</h2>
    <p class="empty">Score: <strong><?php echo (int) $readiness['score']; ?>%</strong> (<?php echo htmlspecialchars($readiness['level']); ?>)</p>
    <ul style="margin:12px 0;padding-left:20px">
        <?php foreach ($readiness['checks'] as $c): ?>
            <li style="color:<?php echo $c['ok'] ? '#047857' : '#b91c1c'; ?>">
                <?php echo $c['ok'] ? '✓' : '○'; ?>
                <?php echo htmlspecialchars($c['label']); ?>
            </li>
        <?php endforeach; ?>
    </ul>
    <p class="empty">Stats: <?php echo (int) $readiness['stats']['classes']; ?> classes ·
        <?php echo (int) $readiness['stats']['teachers']; ?> teachers ·
        <?php echo (int) $readiness['stats']['rooms']; ?> rooms ·
        <?php echo (int) $readiness['stats']['subjects']; ?> subjects</p>
</div>

<div class="card">
    <h2>Account</h2>
    <p class="empty" style="margin-bottom:12px;">Update the password for your school admin login.</p>
    <a class="btn" href="change_password.php">Change password</a>
</div>

<?php require __DIR__ . '/_footer.php'; ?>

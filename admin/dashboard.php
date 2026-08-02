<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../db/db.php';

$schoolId = requireLoginAndGetSchoolId();
$stmt = db()->prepare('SELECT * FROM schools WHERE id = ?');
$stmt->execute([$schoolId]);
$school = $stmt->fetch();

// Summary stats
$counts = [];
foreach ([
    'teachers'  => 'SELECT COUNT(*) FROM teachers WHERE school_id = ?',
    'rooms'     => 'SELECT COUNT(*) FROM rooms WHERE school_id = ?',
    'bands'     => 'SELECT COUNT(*) FROM bands WHERE school_id = ? AND active = 1',
    'classes'   => 'SELECT COUNT(*) FROM classes WHERE school_id = ? AND active = 1',
    'subjects'  => 'SELECT COUNT(*) FROM subjects WHERE school_id = ?',
] as $key => $sql) {
    $s = db()->prepare($sql);
    $s->execute([$schoolId]);
    $counts[$key] = (int) $s->fetchColumn();
}

// Latest generation
$stmt = db()->prepare('SELECT * FROM generated_timetables WHERE school_id = ? ORDER BY generated_at DESC LIMIT 1');
$stmt->execute([$schoolId]);
$latestGeneration = $stmt->fetch();

// Setup checklist: determine readiness
$steps = [
    ['label' => 'Add Teachers',   'done' => $counts['teachers'] > 0,  'link' => 'teachers.php',  'hint' => 'Add at least one teacher to get started.'],
    ['label' => 'Add Rooms',      'done' => $counts['rooms'] > 0,     'link' => 'rooms.php',     'hint' => 'Add classrooms, labs, and other spaces.'],
    ['label' => 'Configure Bands','done' => $counts['bands'] > 0,     'link' => 'bands.php',     'hint' => 'Set up grade bands with lesson lengths.'],
    ['label' => 'Add Classes',    'done' => $counts['classes'] > 0,   'link' => 'classes.php',   'hint' => 'Create class groups for each band.'],
    ['label' => 'Add Subjects',   'done' => $counts['subjects'] > 0,  'link' => 'subjects.php',  'hint' => 'Assign subjects and teachers to each class.'],
    ['label' => 'Generate Timetable', 'done' => $latestGeneration && $latestGeneration['status'] === 'success', 'link' => 'generate.php', 'hint' => 'Run the FET engine to produce the schedule.'],
];

$totalDone   = count(array_filter($steps, fn($s) => $s['done']));
$totalSteps  = count($steps);
$isSetupDone = $totalDone === $totalSteps;
$setupPct    = (int) round($totalDone / $totalSteps * 100);

$pageTitle = 'Dashboard — ' . $school['name'];
require __DIR__ . '/_header.php';
?>

<?php if (!$isSetupDone): ?>
<div class="card setup-card">
    <div class="setup-header">
        <div>
            <h2 style="margin:0">🚀 Setup Progress</h2>
            <p class="empty" style="margin-top:4px">Complete these steps to generate your first timetable.</p>
        </div>
        <div class="setup-progress-ring">
            <svg width="72" height="72" viewBox="0 0 72 72">
                <circle cx="36" cy="36" r="30" fill="none" stroke="#e5e7eb" stroke-width="6"/>
                <circle cx="36" cy="36" r="30" fill="none" stroke="#6366f1" stroke-width="6"
                    stroke-dasharray="<?php echo round(30 * 2 * M_PI); ?>"
                    stroke-dashoffset="<?php echo round(30 * 2 * M_PI * (1 - $setupPct/100)); ?>"
                    stroke-linecap="round"
                    transform="rotate(-90 36 36)"/>
            </svg>
            <div class="ring-label"><?php echo $setupPct; ?>%</div>
        </div>
    </div>
    <div class="setup-steps">
        <?php
        $nextFound = false;
        foreach ($steps as $i => $step):
            $isDone = $step['done'];
            $isNext = !$isDone && !$nextFound;
            if ($isNext) $nextFound = true;
            $stateClass = $isDone ? 'step-done' : ($isNext ? 'step-next' : 'step-pending');
            $stateIcon  = $isDone ? '✓' : ($isNext ? ($i+1) : ($i+1));
        ?>
        <div class="setup-step">
            <div class="step-number <?php echo $stateClass; ?>"><?php echo $stateIcon; ?></div>
            <div class="step-content">
                <h4>
                    <?php if (!$isDone): ?>
                        <a href="<?php echo htmlspecialchars($step['link']); ?>" style="color:inherit;text-decoration:none">
                            <?php echo htmlspecialchars($step['label']); ?> →
                        </a>
                    <?php else: ?>
                        <?php echo htmlspecialchars($step['label']); ?>
                    <?php endif; ?>
                </h4>
                <p><?php echo $isDone ? 'Done ✓' : htmlspecialchars($step['hint']); ?></p>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<div class="stats-grid">
    <?php
    $statItems = [
        ['icon'=>'👨‍🏫','count'=>$counts['teachers'],'label'=>'Teachers',   'link'=>'teachers.php'],
        ['icon'=>'🏫', 'count'=>$counts['rooms'],    'label'=>'Rooms',      'link'=>'rooms.php'],
        ['icon'=>'📚', 'count'=>$counts['bands'],    'label'=>'Active Bands','link'=>'bands.php'],
        ['icon'=>'🎓', 'count'=>$counts['classes'],  'label'=>'Classes',    'link'=>'classes.php'],
        ['icon'=>'📖', 'count'=>$counts['subjects'], 'label'=>'Subjects',   'link'=>'subjects.php'],
    ];
    foreach ($statItems as $item):
    ?>
    <a href="<?php echo $item['link']; ?>" class="stat-card">
        <div class="stat-icon"><?php echo $item['icon']; ?></div>
        <div class="stat-body">
            <div class="stat-number"><?php echo $item['count']; ?></div>
            <div class="stat-label"><?php echo $item['label']; ?></div>
        </div>
        <div class="stat-arrow">→</div>
    </a>
    <?php endforeach; ?>
</div>

<?php if ($latestGeneration): ?>
<div class="card">
    <div class="card-header">
        <h2>Latest Timetable</h2>
        <a href="generate.php" class="btn btn-secondary btn-sm">Regenerate</a>
    </div>
    <div class="gen-info">
        <div class="gen-row">
            <span class="gen-label">Generated</span>
            <span><?php echo htmlspecialchars(date('j M Y, g:i A', strtotime($latestGeneration['generated_at']))); ?></span>
        </div>
        <div class="gen-row">
            <span class="gen-label">Status</span>
            <?php if ($latestGeneration['status'] === 'success'): ?>
                <span class="badge badge-success">✓ Success</span>
            <?php elseif ($latestGeneration['status'] === 'partial'): ?>
                <span class="badge badge-warning">⚠ Partial</span>
            <?php else: ?>
                <span class="badge badge-danger">✗ Failed</span>
            <?php endif; ?>
        </div>
        <?php if ($latestGeneration['html_output_path']): ?>
        <div class="gen-row">
            <span class="gen-label">View Online</span>
            <a href="../<?php echo htmlspecialchars($latestGeneration['html_output_path']); ?>" target="_blank" class="btn btn-sm">Open Timetable ↗</a>
        </div>
        <?php endif; ?>
    </div>
    <?php if ($latestGeneration['status'] === 'success'): ?>
    <div class="action-row" style="margin-top:16px;display:flex;gap:8px;flex-wrap:wrap;">
        <a href="../export/export.php?scope=whole-school" target="_blank" class="btn">⬇ Download School PDF</a>
        <a href="view_timetable.php" class="btn btn-secondary">📋 View by Class</a>
        <a href="edit-timetable.php" class="btn btn-secondary">✏️ Manual Edits</a>
        <a href="teachers.php" class="btn btn-secondary">👨‍🏫 Teachers</a>
        <a href="rooms.php" class="btn btn-secondary">🏫 Rooms</a>
        <a href="classes.php" class="btn btn-secondary">📚 Classes</a>
        <a href="subjects.php" class="btn btn-secondary">📖 Subjects</a>
    </div>
    <?php endif; ?>
</div>
<?php else: ?>
<div class="card" style="text-align:center;padding:40px 24px;">
    <div style="font-size:3rem;margin-bottom:12px">📅</div>
    <h2 style="margin-bottom:8px">No Timetable Yet</h2>
    <p class="empty" style="margin-bottom:20px">Once you've set up your data, generate your first timetable.</p>
    <?php if ($totalDone >= 5): ?>
        <a href="generate.php" class="btn">⚡ Generate Now</a>
    <?php else: ?>
        <p class="empty">Complete the setup steps above first.</p>
    <?php endif; ?>
</div>
<?php endif; ?>

<style>
/* Stats Grid */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
    gap: 14px;
    margin-bottom: 20px;
}
.stat-card {
    background: var(--bg-white);
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    padding: 18px;
    display: flex;
    align-items: center;
    gap: 14px;
    text-decoration: none;
    color: var(--text);
    transition: var(--transition);
    box-shadow: var(--shadow);
}
.stat-card:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow-md);
    border-color: var(--primary-light);
}
.stat-icon { font-size: 1.8rem; flex-shrink: 0; }
.stat-body { flex: 1; }
.stat-number {
    font-size: 1.8rem;
    font-weight: 800;
    color: var(--primary);
    line-height: 1;
    letter-spacing: -0.03em;
}
.stat-label {
    font-size: 0.78rem;
    color: var(--text-muted);
    font-weight: 500;
    margin-top: 2px;
}
.stat-arrow {
    color: var(--primary);
    font-size: 1.1rem;
    opacity: 0.5;
}
.stat-card:hover .stat-arrow { opacity: 1; }

/* Setup Card */
.setup-card { border-left: 4px solid var(--primary); }
.setup-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 20px;
}
.setup-progress-ring { position: relative; width: 72px; height: 72px; flex-shrink: 0; }
.ring-label {
    position: absolute;
    inset: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.85rem;
    font-weight: 700;
    color: var(--primary);
}

/* Gen Info */
.gen-info { display: flex; flex-direction: column; gap: 10px; }
.gen-row { display: flex; align-items: center; gap: 12px; font-size: 0.875rem; }
.gen-label { color: var(--text-muted); font-weight: 500; width: 110px; flex-shrink: 0; }

@media (max-width: 640px) {
    .stats-grid { grid-template-columns: repeat(2, 1fr); }
    .setup-header { flex-direction: column; gap: 12px; }
}
</style>

<?php require __DIR__ . '/_footer.php'; ?>

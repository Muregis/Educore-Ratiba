<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../db/db.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/generate_engine.php';

$schoolId = requireLoginAndGetSchoolId();
$stmt = db()->prepare('SELECT * FROM schools WHERE id = ?');
$stmt->execute([$schoolId]);
$school = $stmt->fetch();
$readiness = getSchoolReadiness($schoolId);
$dayNames = getSchoolDayNames($schoolId);
$genTimeLimit = getSchoolGenerationTimeLimit($schoolId);

$engine = Config::get('paths.engine');
$projectOutputRoot = Config::get('paths.output') . '/schools/' . $schoolId;

$preflightProblems = [];
$adminMessages = [];
$failureDetails = [];
$rawOutputForAdminView = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'prepare') {
    verifyCsrf();
    try {
        if (function_exists('ensureFlexibleSchema')) {
            ensureFlexibleSchema();
        }
        $actions = prepareSchoolForGeneration($schoolId);
        foreach ($actions as $a) {
            $adminMessages[] = ['type' => 'success', 'text' => $a];
        }
        $adminMessages[] = [
            'type' => 'success',
            'text' => 'Preparation done. Review readiness below, then click Regenerate.',
        ];
    } catch (Throwable $e) {
        $adminMessages[] = ['type' => 'error', 'text' => 'Prepare failed: ' . $e->getMessage()];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'generate') {
    verifyCsrf();

    try {
        $prepActions = prepareSchoolForGeneration($schoolId);
        foreach ($prepActions as $a) {
            if (stripos($a, 'No changes needed') === false) {
                $adminMessages[] = ['type' => 'success', 'text' => $a];
            }
        }
    } catch (Throwable $e) {
        $adminMessages[] = ['type' => 'error', 'text' => 'Auto-prepare failed: ' . $e->getMessage()];
    }

    $preflightProblems = array_merge(
        runPreflightChecks($schoolId),
        runRoomTeacherPreflight($schoolId)
    );

    if (empty($preflightProblems)) {
      try {
        $xmlPath = buildWholeSchoolXml($schoolId, $school['name']);
        $timeLimit = getSchoolGenerationTimeLimit($schoolId);
        $classCount = count(array_filter(getClasses($schoolId), static fn($c) => (bool) $c['active']));
        if ($classCount >= 12 && $timeLimit < 600) {
            $timeLimit = 600;
        }
        $result = runFetEngine($xmlPath, $engine, $projectOutputRoot, $timeLimit);
        $rawOutputForAdminView = $result['raw_output'];

        $status = $result['success'] ? 'success' : 'failed';
        $htmlRelativePath = null;

        if ($result['success'] && $result['html_output_path'] !== null) {
            $basePath = dirname(__DIR__);
            $htmlRelativePath = str_replace($basePath . '/', '', $result['html_output_path']);
            $htmlRelativePath = str_replace('\\', '/', $htmlRelativePath);
        }

        $xmlSnapshot = @file_get_contents($xmlPath) ?: null;
        $metaPath = $xmlPath . '.meta.json';
        $activityMeta = [];
        if (file_exists($metaPath)) {
            $decodedMeta = json_decode((string) file_get_contents($metaPath), true);
            if (is_array($decodedMeta) && $decodedMeta !== []) {
                $activityMeta = array_combine(
                    array_map('intval', array_keys($decodedMeta)),
                    array_values($decodedMeta)
                ) ?: [];
            }
        }

        $diagnosis = null;
        if (!$result['success']) {
            $diagnosis = diagnoseFetFailure($result['raw_output'] ?? '', is_array($activityMeta) ? $activityMeta : []);
            $failureDetails = $diagnosis['details'] ?? [];
            foreach (($diagnosis['summary'] ?? []) as $line) {
                $adminMessages[] = ['type' => 'error', 'text' => $line];
            }
            if (empty($adminMessages)) {
                $adminMessages[] = [
                    'type' => 'error',
                    'text' => 'Timetable generation failed. Check teacher loads, rooms, and subject assignments, then try again.',
                ];
            }
        }

        $failureSummaryJson = $diagnosis !== null ? json_encode($diagnosis) : null;

        $driver = strtolower((string) (db()->getAttribute(PDO::ATTR_DRIVER_NAME) ?: ''));
        $cols = 'school_id, xml_snapshot, html_output_path, status, failure_summary, triggered_by_admin_id, triggered_by_type';
        $vals = '?, ?, ?, ?, ?, ?, ?';
        if ($driver === 'pgsql') {
            $sql = "INSERT INTO generated_timetables ({$cols}) VALUES ({$vals}) RETURNING id";
        } else {
            $sql = "INSERT INTO generated_timetables ({$cols}) VALUES ({$vals})";
        }
        $stmt = db()->prepare($sql);
        $params = [
            $schoolId,
            $xmlSnapshot,
            $htmlRelativePath,
            $status,
            $failureSummaryJson,
            $_SESSION['school_admin_id'] ?? $_SESSION['super_admin_id'] ?? null,
            isSuperAdmin() ? 'super_admin' : 'school_admin',
        ];
        try {
            $stmt->execute($params);
            if ($driver === 'pgsql') {
                $generatedTimetableId = (int) $stmt->fetchColumn();
            } else {
                $generatedTimetableId = (int) (function_exists('insertGetId') ? insertGetId($stmt) : db()->lastInsertId());
            }
        } catch (Throwable $insertEx) {
            if (stripos($insertEx->getMessage(), 'failure_summary') !== false) {
                if ($driver === 'pgsql') {
                    $sql2 = 'INSERT INTO generated_timetables (school_id, xml_snapshot, html_output_path, status, triggered_by_admin_id, triggered_by_type) VALUES (?, ?, ?, ?, ?, ?) RETURNING id';
                } else {
                    $sql2 = 'INSERT INTO generated_timetables (school_id, xml_snapshot, html_output_path, status, triggered_by_admin_id, triggered_by_type) VALUES (?, ?, ?, ?, ?, ?)';
                }
                $stmt2 = db()->prepare($sql2);
                $stmt2->execute([
                    $schoolId,
                    $xmlSnapshot,
                    $htmlRelativePath,
                    $status,
                    $_SESSION['school_admin_id'] ?? $_SESSION['super_admin_id'] ?? null,
                    isSuperAdmin() ? 'super_admin' : 'school_admin',
                ]);
                $generatedTimetableId = $driver === 'pgsql'
                    ? (int) $stmt2->fetchColumn()
                    : (int) db()->lastInsertId();
            } else {
                throw $insertEx;
            }
        }
        if ($generatedTimetableId <= 0) {
            $generatedTimetableId = (int) db()->lastInsertId();
        }

        if ($result['success']) {
            $adminMessages[] = ['type' => 'success', 'text' => 'Timetable generated successfully for the whole school.'];
            if ($result['solution_xml_path'] !== null) {
                $slotRows = parseFetSolutionIntoSlots($result['solution_xml_path'], $activityMeta, $schoolId);
                storeScheduledSlots($generatedTimetableId, $slotRows);
                if (empty($slotRows)) {
                    $adminMessages[] = [
                        'type' => 'error',
                        'text' => 'The timetable generated, but its details could not be loaded for editing. '
                            . 'You can still view/print/export it, but manual adjustments are unavailable for this run.',
                    ];
                } else {
                    $adminMessages[] = ['type' => 'success', 'text' => count($slotRows) . ' lessons scheduled. View or edit them from the View / Edit pages.'];
                }
            } else {
                $adminMessages[] = [
                    'type' => 'error',
                    'text' => 'Timetable generated successfully, but solution XML was not found. PDF export may use HTML fallback.',
                ];
            }
        }
      } catch (Throwable $genEx) {
        error_log('Generate failed: ' . $genEx->getMessage() . ' @ ' . $genEx->getFile() . ':' . $genEx->getLine());
        $adminMessages[] = [
            'type' => 'error',
            'text' => 'Generation error: ' . $genEx->getMessage(),
        ];
      }
    }
}

$stmt = db()->prepare(
    'SELECT * FROM generated_timetables WHERE school_id = ? ORDER BY generated_at DESC LIMIT 20'
);
$stmt->execute([$schoolId]);
$history = $stmt->fetchAll();

$latestFailed = null;
$latestFailedDiagnosis = null;
foreach ($history as $h) {
    if ($h['status'] === 'failed') {
        $latestFailed = $h;
        break;
    }
}
if ($latestFailed && !empty($latestFailed['failure_summary'])) {
    $decoded = json_decode((string) $latestFailed['failure_summary'], true);
    if (is_array($decoded)) {
        $latestFailedDiagnosis = $decoded;
    }
}

$readiness = getSchoolReadiness($schoolId);
$dayNames = getSchoolDayNames($schoolId);
$genTimeLimit = getSchoolGenerationTimeLimit($schoolId);

$pageTitle = 'Generate Timetable — ' . ($school['name'] ?? 'School');
require __DIR__ . '/_header.php';
?>

<div class="card">
    <h2>Generate whole-school timetable</h2>
    <p style="color: var(--text-muted); margin-bottom: 16px;">
        This generates one combined timetable for every active class across every band, so shared
        teachers and rooms are checked for double-booking correctly. Individual class views are
        filtered from this one result.
    </p>

    <div style="background:#f0f9ff;border:1px solid #bae6fd;border-radius:8px;padding:14px 16px;margin-bottom:18px;">
        <strong>Scale readiness:</strong>
        <?php echo (int) $readiness['score']; ?>% (<?php echo htmlspecialchars($readiness['level']); ?>)
        · <?php echo htmlspecialchars($readiness['scale']); ?> school
        · <?php echo count($dayNames); ?>-day week
        · time limit <?php echo (int) $genTimeLimit; ?>s
        <br><span style="font-size:0.9em;color:#0369a1;">
            <?php echo (int) $readiness['stats']['classes']; ?> classes ·
            <?php echo (int) $readiness['stats']['teachers']; ?> teachers ·
            <?php echo (int) $readiness['stats']['rooms']; ?> rooms ·
            <?php echo (int) $readiness['stats']['subjects']; ?> subjects
            · <a href="settings.php">Adjust settings</a>
        </span>
    </div>

    <?php if (!empty($preflightProblems)): ?>
        <div class="error">
            <strong>Cannot generate yet — the following need fixing first:</strong>
            <ul style="margin: 8px 0 0; padding-left: 20px;">
                <?php foreach ($preflightProblems as $p): ?>
                    <li><?php echo nl2br(htmlspecialchars($p)); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php foreach ($adminMessages as $msg): ?>
        <div class="<?php echo $msg['type'] === 'success' ? 'success' : 'error'; ?>">
            <?php echo htmlspecialchars($msg['text']); ?>
        </div>
    <?php endforeach; ?>

    <?php if (!empty($failureDetails)): ?>
        <div class="error" style="margin-top:-6px;">
            <strong>Lessons that could not be placed:</strong>
            <ul style="margin: 8px 0 0; padding-left: 20px;">
                <?php foreach (array_slice($failureDetails, 0, 25) as $d): ?>
                    <li><?php echo htmlspecialchars($d); ?></li>
                <?php endforeach; ?>
                <?php if (count($failureDetails) > 25): ?>
                    <li>…and <?php echo count($failureDetails) - 25; ?> more.</li>
                <?php endif; ?>
            </ul>
        </div>
    <?php endif; ?>

    <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin-top:8px;">
        <form method="post" style="display:inline;">
            <input type="hidden" name="action" value="prepare">
            <input type="hidden" name="_csrf_token" value="<?php echo htmlspecialchars(getCsrfToken()); ?>">
            <button type="submit" class="btn-secondary" onclick="return confirm('Add missing classrooms and raise tight teacher caps so Generate is more likely to succeed?');">
                Prepare school for generation
            </button>
        </form>
        <form method="post" style="display:inline;">
            <input type="hidden" name="action" value="generate">
            <input type="hidden" name="_csrf_token" value="<?php echo htmlspecialchars(getCsrfToken()); ?>">
            <button type="submit" onclick="return confirm('Regenerate the whole-school timetable now? This may take a moment.');">
                Regenerate Whole-School Timetable
            </button>
        </form>
    </div>
    <p class="empty" style="margin-top:10px;font-size:0.85em;">
        Generate now auto-prepares rooms and teacher caps when needed. Use <strong>Prepare</strong> only if you want to review changes first.
    </p>

    <?php if (isSuperAdmin() && $rawOutputForAdminView !== null): ?>
        <details style="margin-top: 20px;">
            <summary style="cursor:pointer; color: var(--text-muted); font-size: 0.85rem;">Raw engine output (super-admin only)</summary>
            <pre style="background:#1e1e1e; color:#d4d4d4; padding:14px; border-radius:6px; overflow-x:auto; font-size: 0.8rem; margin-top: 10px;"><?php echo htmlspecialchars($rawOutputForAdminView); ?></pre>
        </details>
    <?php endif; ?>
</div>

<?php if ($latestFailedDiagnosis !== null && empty($adminMessages) && empty($preflightProblems)): ?>
<div class="card">
    <h2>Last failure — <?php echo htmlspecialchars(date('j M Y, g:i A', strtotime($latestFailed['generated_at']))); ?></h2>
    <?php foreach (($latestFailedDiagnosis['summary'] ?? []) as $line): ?>
        <div class="error"><?php echo htmlspecialchars($line); ?></div>
    <?php endforeach; ?>
    <?php if (!empty($latestFailedDiagnosis['details'])): ?>
        <details>
            <summary style="cursor:pointer; color: var(--text-muted); font-size: 0.85rem;">Lessons that could not be placed (<?php echo count($latestFailedDiagnosis['details']); ?>)</summary>
            <ul style="margin: 8px 0 0; padding-left: 20px;">
                <?php foreach (array_slice($latestFailedDiagnosis['details'], 0, 50) as $d): ?>
                    <li><?php echo htmlspecialchars($d); ?></li>
                <?php endforeach; ?>
            </ul>
        </details>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="card">
    <h2>Generation history</h2>
    <?php if (empty($history)): ?>
        <p class="empty">No timetables generated yet.</p>
    <?php else: ?>
        <table>
            <thead><tr><th>When</th><th>Status</th><th>Why it failed</th><th>Triggered by</th></tr></thead>
            <tbody>
                <?php foreach ($history as $h): ?>
                    <?php
                    $hDiag = null;
                    if (!empty($h['failure_summary'])) {
                        $dec = json_decode((string) $h['failure_summary'], true);
                        if (is_array($dec)) {
                            $hDiag = $dec;
                        }
                    }
                    $whyFailed = $h['status'] === 'success'
                        ? '—'
                        : (($hDiag['summary'][0] ?? null) !== null
                            ? mb_strimwidth((string) $hDiag['summary'][0], 0, 120, '…')
                            : 'Engine could not find a valid schedule — try Generate again for a detailed breakdown.');
                    ?>
                    <tr>
                        <td><?php echo htmlspecialchars(date('j M Y, g:i A', strtotime($h['generated_at']))); ?></td>
                        <td><?php echo htmlspecialchars(ucfirst($h['status'])); ?></td>
                        <td style="max-width:420px;"><?php echo htmlspecialchars($whyFailed); ?></td>
                        <td><?php echo htmlspecialchars($h['triggered_by_type']); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/_footer.php'; ?>

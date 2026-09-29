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

// ---- CONFIG - use hybrid config system for online/offline support ----
$engine = Config::get('paths.engine');
$projectOutputRoot = Config::get('paths.output') . '/schools/' . $schoolId;

$preflightProblems = [];
$adminMessages = [];      // translated, plain-language results for the school-admin
$failureDetails = [];     // per-lesson breakdown of why the engine could not place lessons
$rawOutputForAdminView = null; // only ever shown in a collapsed super-admin block

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'generate') {
    verifyCsrf();
    $preflightProblems = runPreflightChecks($schoolId);

    if (empty($preflightProblems)) {
        $xmlPath = buildWholeSchoolXml($schoolId, $school['name']);
        $timeLimit = getSchoolGenerationTimeLimit($schoolId);
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
        $activityMeta = file_exists($metaPath) ? json_decode((string) file_get_contents($metaPath), true) : [];
        if (!is_array($activityMeta)) {
            $activityMeta = [];
        }
        // json_decode gives string keys - re-key as int to match how
        // parseFetSolutionIntoSlots and buildWholeSchoolXml use them.
        $activityMeta = array_combine(array_map('intval', array_keys($activityMeta)), array_values($activityMeta));

        // Plain-English diagnosis up-front so it can be stored with the run
        // and re-shown later (View page, history) instead of a dead
        // "failed, try again" link.
        $diagnosis = $result['success']
            ? ['summary' => [], 'details' => []]
            : diagnoseFetFailure($rawOutputForAdminView ?? '', $activityMeta);

        $stmt = db()->prepare(
            'INSERT INTO generated_timetables (school_id, xml_snapshot, html_output_path, status, failure_summary, triggered_by_admin_id, triggered_by_type)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $schoolId,
            $xmlSnapshot,
            $htmlRelativePath,
            $status,
            json_encode($diagnosis, JSON_UNESCAPED_UNICODE),
            $_SESSION['school_admin_id'] ?? $_SESSION['super_admin_id'] ?? null,
            isSuperAdmin() ? 'super_admin' : 'school_admin',
        ]);
        $generatedTimetableId = (int) db()->lastInsertId();

        if ($result['success']) {
            $adminMessages[] = ['type' => 'success', 'text' => 'Timetable generated successfully for the whole school.'];

            // Parse FET's solution XML into scheduled_slots rows so the
            // manual editor has structured data to work against.
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
                    'type' => 'warning',
                    'text' => 'Timetable generated successfully, but solution XML was not found. '
                        . 'PDF export will use HTML fallback. This may indicate an issue with the FET engine output.',
                ];
            }
        } else {
            foreach ($diagnosis['summary'] as $line) {
                $adminMessages[] = ['type' => 'error', 'text' => $line];
            }
            $failureDetails = $diagnosis['details'];
        }
    }
}

// Generation history (with diagnosis for failed runs)
$stmt = db()->prepare(
    'SELECT * FROM generated_timetables WHERE school_id = ? ORDER BY generated_at DESC LIMIT 20'
);
$stmt->execute([$schoolId]);
$history = $stmt->fetchAll();

// The most recent failed run, for the "why did it fail" panel that survives
// page reloads (the raw engine output only lives for this request).
$latestFailed = null;
foreach ($history as $h) {
    if ($h['status'] === 'failed') {
        $latestFailed = $h;
        break;
    }
}
$latestFailedDiagnosis = null;
if ($latestFailed && !empty($latestFailed['failure_summary'])) {
    $decoded = json_decode((string) $latestFailed['failure_summary'], true);
    if (is_array($decoded)) {
        $latestFailedDiagnosis = $decoded;
    }
}

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

    <form method="post">
        <input type="hidden" name="action" value="generate">
        <input type="hidden" name="_csrf_token" value="<?php echo htmlspecialchars(getCsrfToken()); ?>">
        <button type="submit" onclick="return confirm('Regenerate the whole-school timetable now? This may take a moment.');">
            Regenerate Whole-School Timetable
        </button>
    </form>

    <?php if (isSuperAdmin() && $rawOutputForAdminView !== null): ?>
        <details style="margin-top: 20px;">
            <summary style="cursor:pointer; color: var(--text-muted); font-size: 0.85rem;">Raw engine output (super-admin only)</summary>
            <pre style="background:#1e1e1e; color:#d4d4d4; padding:14px; border-radius:6px; overflow-x:auto; font-size: 0.8rem; margin-top: 10px;"><?php echo htmlspecialchars($rawOutputForAdminView); ?></pre>
        </details>
    <?php endif; ?>
</div>

<?php if ($latestFailedDiagnosis !== null && empty($adminMessages) && empty($preflightProblems)): ?>
<div class="card">
    <h2>Last failed run — <?php echo htmlspecialchars(date('j M Y, g:i A', strtotime((string) $latestFailed['generated_at']))); ?></h2>
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

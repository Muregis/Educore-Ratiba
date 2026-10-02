<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../db/db.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/generate_engine.php';
require_once __DIR__ . '/fet_runtime.php';

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
        $adminMessages[] = ['type' => 'success', 'text' => 'Preparation done. Review readiness below, then click Regenerate.'];
    } catch (Throwable $e) {
        $adminMessages[] = ['type' => 'error', 'text' => 'Prepare failed: ' . $e->getMessage()];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'rebalance') {
    verifyCsrf();
    try {
        $actions = rebalanceSchoolForSolvability($schoolId);
        foreach ($actions as $a) {
            $adminMessages[] = ['type' => 'success', 'text' => $a];
        }
        $adminMessages[] = ['type' => 'success', 'text' => 'Rebalance complete. Readiness should improve — click Regenerate next.'];
        $stmt = db()->prepare('SELECT * FROM schools WHERE id = ?');
        $stmt->execute([$schoolId]);
        $school = $stmt->fetch();
    } catch (Throwable $e) {
        $adminMessages[] = ['type' => 'error', 'text' => 'Rebalance failed: ' . $e->getMessage()];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'generate') {
    verifyCsrf();
    if (function_exists('set_time_limit')) { @set_time_limit(660); }
    @ini_set('max_execution_time', '660');

    try {
        $classN = count(array_filter(getClasses($schoolId), static fn($c) => (bool) $c['active']));
        $teachN = count(getTeachers($schoolId));
        if ($classN > 0 && $teachN < $classN) {
            $prepActions = rebalanceSchoolForSolvability($schoolId);
            $adminMessages[] = ['type' => 'success', 'text' => 'Auto-rebalanced: teachers were fewer than classes.'];
        } else {
            $prepActions = prepareSchoolForGeneration($schoolId);
        }
        foreach ($prepActions as $a) {
            if (stripos($a, 'No changes needed') === false && stripos($a, 'No light changes') === false) {
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
        require_once __DIR__ . '/apply_band_hour_locks.php';
        applyBandHourLocksToFetXml($xmlPath, $schoolId);
        $timeLimit = getSchoolGenerationTimeLimit($schoolId);
        $classCount = count(array_filter(getClasses($schoolId), static fn($c) => (bool) $c['active']));
        if ($classCount >= 12 && $timeLimit < 600) {
            $timeLimit = 600;
        }
        $result = runFetEngineV2($xmlPath, $engine, $projectOutputRoot, $timeLimit);
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
            $diagnosis = diagnoseFetFailureV2($result['raw_output'] ?? '', is_array($activityMeta) ? $activityMeta : []);
            $failureDetails = $diagnosis['details'] ?? [];
            foreach (($diagnosis['summary'] ?? []) as $line) {
                $adminMessages[] = ['type' => 'error', 'text' => $line];
            }
            if (empty($adminMessages)) {
                $adminMessages[] = ['type' => 'error', 'text' => 'Timetable generation failed. Check teacher loads, rooms, and subject assignments, then try again.'];
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
                require_once __DIR__ . '/purge_cross_band_slots.php';
                $purged = purgeCrossBandSlots($generatedTimetableId, $schoolId);
                if ($purged > 0) {
                    $adminMessages[] = ['type' => 'success', 'text' => "Removed {$purged} cross-band slot(s) that did not match class band hours."];
                }
                if (empty($slotRows)) {
                    $adminMessages[] = ['type' => 'error', 'text' => 'The timetable generated, but its details could not be loaded for editing.'];
                } else {
                    $adminMessages[] = ['type' => 'success', 'text' => count($slotRows) . ' lessons scheduled. View or edit them from the View / Edit pages.'];
                }
            }
        }
      } catch (Throwable $genEx) {
        error_log('Generate failed: ' . $genEx->getMessage());
        $adminMessages[] = ['type' => 'error', 'text' => 'Generation error: ' . $genEx->getMessage()];
      }
    }
}

$stmt = db()->prepare(
    'SELECT * FROM generated_timetables WHERE school_id = ? ORDER BY generated_at DESC LIMIT 20'
);
$stmt->execute([$schoolId]);
$history = $stmt->fetchAll();

$readiness = getSchoolReadiness($schoolId);
$dayNames = getSchoolDayNames($schoolId);
$genTimeLimit = getSchoolGenerationTimeLimit($schoolId);

$pageTitle = 'Generate Timetable — ' . ($school['name'] ?? 'School');
require __DIR__ . '/_header.php';
?>

<div class="card">
    <h2>Generate whole-school timetable</h2>
    <p style="color: var(--text-muted); margin-bottom: 16px;">
        Combined timetable for every active class. Shared teachers and rooms are checked for clashes.
    </p>

    <div style="background:#f0f9ff;border:1px solid #bae6fd;border-radius:8px;padding:14px 16px;margin-bottom:18px;">
        <strong>Scale readiness:</strong>
        <?php echo (int) $readiness['score']; ?>% (<?php echo htmlspecialchars($readiness['level']); ?>)
        · <?php echo htmlspecialchars($readiness['scale']); ?> school
        · <?php echo count($dayNames); ?>-day week
        · time limit <?php echo (int) $genTimeLimit; ?>s
    </div>

    <?php if (!empty($preflightProblems)): ?>
        <div class="error">
            <strong>Cannot generate yet:</strong>
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

    <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin-top:8px;">
        <form method="post" style="display:inline;">
            <input type="hidden" name="action" value="rebalance">
            <input type="hidden" name="_csrf_token" value="<?php echo htmlspecialchars(getCsrfToken()); ?>">
            <button type="submit" class="btn-secondary">Rebalance school for generation</button>
        </form>
        <form method="post" style="display:inline;">
            <input type="hidden" name="action" value="prepare">
            <input type="hidden" name="_csrf_token" value="<?php echo htmlspecialchars(getCsrfToken()); ?>">
            <button type="submit" class="btn-secondary">Prepare (rooms & caps)</button>
        </form>
        <form method="post" style="display:inline;">
            <input type="hidden" name="action" value="generate">
            <input type="hidden" name="_csrf_token" value="<?php echo htmlspecialchars(getCsrfToken()); ?>">
            <button type="submit" onclick="return confirm('Regenerate the whole-school timetable now?');">
                Regenerate Whole-School Timetable
            </button>
        </form>
    </div>

    <?php if ($rawOutputForAdminView !== null): ?>
        <details style="margin-top: 20px;" open>
            <summary style="cursor:pointer;">Raw engine output</summary>
            <pre style="background:#1e1e1e; color:#d4d4d4; padding:14px; border-radius:6px; overflow-x:auto; font-size: 0.8rem; max-height:320px;"><?php echo htmlspecialchars($rawOutputForAdminView); ?></pre>
        </details>
    <?php endif; ?>
</div>

<div class="card">
    <h2>Generation history</h2>
    <?php if (empty($history)): ?>
        <p class="empty">No timetables generated yet.</p>
    <?php else: ?>
        <table>
            <thead><tr><th>When</th><th>Status</th><th>Triggered by</th></tr></thead>
            <tbody>
                <?php foreach ($history as $h): ?>
                    <tr>
                        <td><?php echo htmlspecialchars(function_exists('formatAppDateTime') ? formatAppDateTime($h['generated_at']) : date('j M Y, g:i A', strtotime($h['generated_at']))); ?></td>
                        <td><?php echo htmlspecialchars(ucfirst($h['status'])); ?></td>
                        <td><?php echo htmlspecialchars($h['triggered_by_type']); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/_footer.php'; ?>

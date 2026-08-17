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

// ---- CONFIG - use hybrid config system for online/offline support ----
$engine = Config::get('paths.engine');
$projectOutputRoot = Config::get('paths.output') . '/schools/' . $schoolId;

$preflightProblems = [];
$generationResult = null;
$adminMessages = []; // translated, plain-language results for the school-admin
$rawOutputForAdminView = null; // only ever shown in a collapsed/admin-only block

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'generate') {
    $preflightProblems = runPreflightChecks($schoolId);

    if (empty($preflightProblems)) {
        $xmlPath = buildWholeSchoolXml($schoolId, $school['name']);
        $result = runFetEngine($xmlPath, $engine, $projectOutputRoot);
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
        $activityMeta = file_exists($metaPath) ? json_decode(file_get_contents($metaPath), true) : [];
        // json_decode gives string keys - re-key as int to match how
        // parseFetSolutionIntoSlots and buildWholeSchoolXml use them.
        $activityMeta = array_combine(array_map('intval', array_keys($activityMeta)), array_values($activityMeta));

        $stmt = db()->prepare(
            'INSERT INTO generated_timetables (school_id, xml_snapshot, html_output_path, status, triggered_by_admin_id, triggered_by_type)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $schoolId,
            $xmlSnapshot,
            $htmlRelativePath,
            $status,
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
                }
            }
        } else {
            // Translate the "not scheduled" situation into plain
            // language rather than showing raw engine output (plan
            // section 4d, Layer 2). $activityMeta already loaded above.

            if (stripos($rawOutputForAdminView, 'not_scheduled') !== false || stripos($rawOutputForAdminView, 'could not') !== false) {
                $adminMessages[] = [
                    'type' => 'error',
                    'text' => 'Some lessons could not be scheduled. This usually means a teacher, room, or '
                        . 'time slot is over-committed somewhere in the school. Check the Teachers and '
                        . 'Subjects pages for anything flagged as over capacity.',
                ];
            } else {
                $adminMessages[] = [
                    'type' => 'error',
                    'text' => 'The timetable could not be generated. Please check that every subject has a '
                        . 'teacher assigned, and try again. If this continues, contact support.',
                ];
            }
        }

        @unlink($xmlPath);
        @unlink($metaPath);
    }
}

// Recent generation history
$stmt = db()->prepare(
    'SELECT * FROM generated_timetables WHERE school_id = ? ORDER BY generated_at DESC LIMIT 10'
);
$stmt->execute([$schoolId]);
$history = $stmt->fetchAll();

$pageTitle = 'Generate Timetable — ' . $school['name'];
require __DIR__ . '/_header.php';
?>

<div class="card">
    <h2>Generate whole-school timetable</h2>
    <p class="empty">
        This runs ONE combined timetable for every active class across every band, so shared
        teachers and rooms are checked for double-booking correctly. Individual class views are
        filtered from this one result.
    </p>

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

    <form method="post">
        <input type="hidden" name="action" value="generate">
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
                        <td><?php echo htmlspecialchars(date('j M Y, g:i A', strtotime($h['generated_at']))); ?></td>
                        <td><?php echo htmlspecialchars(ucfirst($h['status'])); ?></td>
                        <td><?php echo htmlspecialchars($h['triggered_by_type']); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/_footer.php'; ?>

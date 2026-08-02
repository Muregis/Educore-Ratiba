<?php
declare(strict_types=1);

// Load centralized error handler
require_once __DIR__ . '/db/error_handler.php';

// ============================================================
// ADMIN AUTH — currently disabled. This page shows raw file
// paths and engine output, so before this is reachable by
// anyone other than you (a client's server, a public URL),
// re-enable some form of access control.
// ============================================================

// ============================================================
// 1. CONFIG - adjust these paths to match your Laragon setup
// ============================================================
$engine    = "C:/laragon/www/fet-timetable/engine/fet-cl.exe";
$inputFile = "C:/laragon/www/fet-timetable/data/bands/pp1-pp2.txt";
$outputDir = "C:/laragon/www/fet-timetable/output";
$timetablesDir = $outputDir . "/timetables";

// ============================================================
// 2. VALIDATE PRE-REQUISITES BEFORE RUNNING THE ENGINE
// ============================================================
$errors = [];

if (!file_exists($engine)) {
    $errors[] = "FET engine not found at: {$engine}";
}
if (!file_exists($inputFile)) {
    $errors[] = "Input file not found at: {$inputFile}";
} else {
    // FET reads by content, not extension, so a .txt file
    // containing XML is fine as long as the content parses.
    libxml_use_internal_errors(true);
    $xml = simplexml_load_file($inputFile);
    if ($xml === false) {
        $errors[] = "Input file is not well-formed XML. Details:";
        foreach (libxml_get_errors() as $e) {
            $errors[] = "  Line {$e->line}: " . trim($e->message);
        }
        libxml_clear_errors();
    } elseif ($xml->getName() !== 'fet') {
        $errors[] = "Input file's root element is '<{$xml->getName()}>', expected '<fet>'.";
    }
}
if (!is_dir($outputDir)) {
    if (!@mkdir($outputDir, 0777, true) && !is_dir($outputDir)) {
        $errors[] = "Could not create output directory: {$outputDir}";
    }
}

// ============================================================
// 3. RUN FET (only if pre-flight checks passed)
// ============================================================
$output = '';
if (empty($errors)) {
    $cmd = '"' . $engine . '" --inputfile="' . $inputFile . '" --outputdir="' . $outputDir . '" 2>&1';
    $output = (string) shell_exec($cmd);
}

// ============================================================
// 4. LOCATE THE GENERATED HTML TIMETABLE
// ============================================================
$webPath       = null;
$latestFolder  = null;
$conflictsHtml = null;
$allIndexFiles = [];

if (empty($errors) && stripos($output, 'Generation successful') !== false) {
    
    // ADD THIS LINE to force PHP to read fresh file timestamps
    clearstatcache(); 

    if (is_dir($timetablesDir)) {
        $folders = @glob($timetablesDir . '/*', GLOB_ONLYDIR) ?: [];

        foreach ($folders as $folderDir) {
            $found = glob($folderDir . '/*_index.html') ?: [];
            foreach ($found as $f) {
                $allIndexFiles[] = $f;
            }
        }

        if (!empty($allIndexFiles)) {
            usort($allIndexFiles, static fn($a, $b) => filemtime($b) - filemtime($a));

            $newestIndexFile = $allIndexFiles[0];
            $latestFolderDir = dirname($newestIndexFile);
            $latestFolder    = basename($latestFolderDir);
            $indexFileName   = basename($newestIndexFile);

            $webPath = "output/timetables/{$latestFolder}/{$indexFileName}";

            $conflictFiles = glob($latestFolderDir . '/*not_scheduled*.html') ?: [];
            if (!empty($conflictFiles)) {
                $conflictsHtml = "output/timetables/{$latestFolder}/" . basename($conflictFiles[0]);
            }
        }
    }
}

$success = empty($errors) && $webPath !== null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>EduCore Ratiba Execution</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 40px; background: #f9f9f9; color: #222; }
        .card { background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); max-width: 900px; }
        .btn { display: inline-block; background: #007bff; color: white; padding: 10px 20px; text-decoration: none;
               border-radius: 5px; font-weight: bold; margin-top: 15px; margin-right: 10px; }
        .btn:hover { background: #0056b3; }
        .btn.warn { background: #b58900; }
        .btn.warn:hover { background: #8a6800; }
        pre { background: #1e1e1e; color: #d4d4d4; padding: 15px; border-radius: 5px; overflow-x: auto; white-space: pre-wrap; }
        ul.errors { color: #b00020; }
        .ok   { color: #1a7d1a; }
        .fail { color: #b00020; }
        .path { font-family: monospace; background: #eee; padding: 2px 6px; border-radius: 3px; }
    </style>
</head>
<body>

<div class="card">
    <h2>EduCore Ratiba Execution Status</h2>

    <?php if (!empty($errors)): ?>
        <h3 class="fail">FAILED: Could not run the FET engine</h3>
        <ul class="errors">
            <?php foreach ($errors as $e): ?>
                <li><?php echo htmlspecialchars($e); ?></li>
            <?php endforeach; ?>
        </ul>
        <p><em>Tip: adjust the paths at the top of <code>index.php</code> if these are wrong on your machine.</em></p>

    <?php elseif ($success): ?>
        <h3 class="ok">SUCCESS: Timetable generated successfully!</h3>
        <p>Found at: <span class="path"><?php echo htmlspecialchars($webPath); ?></span></p>
        <a href="<?php echo htmlspecialchars($webPath); ?>" target="_blank" class="btn">Open Generated HTML Timetable</a>
        <?php if ($conflictsHtml !== null): ?>
            <a href="<?php echo htmlspecialchars($conflictsHtml); ?>" target="_blank" class="btn warn">View Unscheduled Activities</a>
        <?php endif; ?>

    <?php elseif (!empty($output) && stripos($output, 'Generation successful') !== false): ?>
        <h3 class="fail">Partial success: engine ran, but no output HTML was found</h3>
        <p>FET reported success but no <code>*_index.html</code> file was located anywhere under
           <span class="path"><?php echo htmlspecialchars($timetablesDir); ?></span>.</p>
        <p>Folders that exist there right now:</p>
        <pre><?php
            $existing = is_dir($timetablesDir) ? (@glob($timetablesDir . '/*', GLOB_ONLYDIR) ?: []) : [];
            echo $existing ? htmlspecialchars(implode("\n", array_map('basename', $existing))) : "(none found - check that \$outputDir path is correct)";
        ?></pre>
        <p>Check that the engine has write permission to this directory, and that the path above
           matches where Windows Explorer actually shows the folders.</p>

    <?php else: ?>
        <h3 class="fail">FAILED: Generation failed or incomplete</h3>
        <p>Common causes: an activity's teacher/room/subject name in the input file doesn't exactly
           match the corresponding list entry, or the constraints make the timetable infeasible
           (e.g. more weekly lessons requested than available non-break slots).</p>
    <?php endif; ?>

    <h3>Engine Console Log:</h3>
    <pre><?php echo htmlspecialchars($output !== '' ? $output : "No response from FET engine (engine did not run)."); ?></pre>
</div>

</body>
</html>
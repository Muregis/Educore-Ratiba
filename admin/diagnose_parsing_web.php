<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../db/db.php';

$schoolId = requireLoginAndGetSchoolId();

echo "<h2>FET Parsing Diagnostic</h2>";

// Check if we have recent generations
$stmt = db()->prepare('SELECT * FROM generated_timetables WHERE school_id = ? ORDER BY generated_at DESC LIMIT 1');
$stmt->execute([$schoolId]);
$latest = $stmt->fetch();

if (!$latest) {
    echo "<p>No generations found in database.</p>";
    exit;
}

echo "<p><strong>Latest Generation ID:</strong> {$latest['id']}</p>";
echo "<p><strong>Status:</strong> {$latest['status']}</p>";
echo "<p><strong>HTML Path:</strong> " . htmlspecialchars($latest['html_output_path'] ?? 'null') . "</p>";

// Check scheduled slots
$stmt = db()->prepare('SELECT COUNT(*) as cnt FROM scheduled_slots WHERE generated_timetable_id = ?');
$stmt->execute([$latest['id']]);
$slotCount = $stmt->fetchColumn();
echo "<p><strong>Scheduled slots for this generation:</strong> $slotCount</p>";

// Check classes and teachers
$stmt = db()->prepare('SELECT id, name FROM classes WHERE school_id = ?');
$stmt->execute([$schoolId]);
$classes = $stmt->fetchAll();
echo "<h3>Classes in database:</h3>";
echo "<ul>";
foreach ($classes as $c) {
    echo "<li>ID: {$c['id']}, Name: '" . htmlspecialchars($c['name']) . "'</li>";
}
echo "</ul>";

$stmt = db()->prepare('SELECT id, name FROM teachers WHERE school_id = ?');
$stmt->execute([$schoolId]);
$teachers = $stmt->fetchAll();
echo "<h3>Teachers in database:</h3>";
echo "<ul>";
foreach ($teachers as $t) {
    echo "<li>ID: {$t['id']}, Name: '" . htmlspecialchars($t['name']) . "'</li>";
}
echo "</ul>";

// Check the FET output file
if ($latest['html_output_path']) {
    $basePath = dirname(__DIR__);
    $htmlFullPath = $basePath . '/' . ltrim($latest['html_output_path'], '/');
    $dir = dirname($htmlFullPath);
    
    echo "<h3>FET Output Files</h3>";
    echo "<p>Directory: " . htmlspecialchars($dir) . "</p>";
    
    // Look for activities XML
    $xmlFiles = glob($dir . '/*_activities.xml');
    if ($xmlFiles) {
        echo "<p>Activities XML found: " . htmlspecialchars(basename($xmlFiles[0])) . "</p>";
        
        // Parse and show some sample data
        $xml = @simplexml_load_file($xmlFiles[0]);
        if ($xml) {
            echo "<h4>Sample FET activities (first 5):</h4>";
            echo "<ul>";
            $count = 0;
            foreach ($xml->Activity as $act) {
                if ($count++ >= 5) break;
                echo "<li>ID: {$act->Id}, Day: {$act->Day}, Hour: {$act->Hour}</li>";
            }
            echo "</ul>";
        }
    } else {
        echo "<p>No activities XML found</p>";
    }
}

echo "<h3>Analysis</h3>";
if ($slotCount == 0) {
    echo "<p style='color:red'><strong>❌ PROBLEM:</strong> No scheduled slots in database despite successful generation</p>";
    echo "<p>This indicates the solution XML parsing is failing.</p>";
    echo "<p>Most likely causes:</p>";
    echo "<ol>";
    echo "<li>Class names in FET output don't match database class names exactly</li>";
    echo "<li>Teacher names in FET output don't match database teacher names exactly</li>";
    echo "<li>Activity metadata mapping is not working correctly</li>";
    echo "</ol>";
} else {
    echo "<p style='color:green'><strong>✓ Scheduled slots exist</strong> - parsing is working</p>";
}

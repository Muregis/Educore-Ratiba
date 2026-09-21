<?php
// Diagnostic script to check FET parsing issues
require_once 'db/db.php';

echo "=== Database Check ===\n";

// Check if we have recent generations
$stmt = db()->query('SELECT * FROM generated_timetables ORDER BY generated_at DESC LIMIT 1');
$latest = $stmt->fetch();

if (!$latest) {
    echo "No generations found in database.\n";
    exit;
}

echo "Latest Generation ID: {$latest['id']}\n";
echo "Status: {$latest['status']}\n";
echo "HTML Path: {$latest['html_output_path']}\n";

// Check scheduled slots
$stmt = db()->prepare('SELECT COUNT(*) as cnt FROM scheduled_slots WHERE generated_timetable_id = ?');
$stmt->execute([$latest['id']]);
$slotCount = $stmt->fetchColumn();
echo "Scheduled slots for this generation: $slotCount\n";

// Check classes and teachers
$schoolId = $latest['school_id'];
$stmt = db()->prepare('SELECT id, name FROM classes WHERE school_id = ?');
$stmt->execute([$schoolId]);
$classes = $stmt->fetchAll();
echo "\nClasses in database:\n";
foreach ($classes as $c) {
    echo "  ID: {$c['id']}, Name: '{$c['name']}'\n";
}

$stmt = db()->prepare('SELECT id, name FROM teachers WHERE school_id = ?');
$stmt->execute([$schoolId]);
$teachers = $stmt->fetchAll();
echo "\nTeachers in database:\n";
foreach ($teachers as $t) {
    echo "  ID: {$t['id']}, Name: '{$t['name']}'\n";
}

// Check the FET output file
if ($latest['html_output_path']) {
    $basePath = dirname(__DIR__);
    $htmlFullPath = $basePath . '/' . ltrim($latest['html_output_path'], '/');
    $dir = dirname($htmlFullPath);
    
    echo "\n=== FET Output Files ===\n";
    echo "Directory: $dir\n";
    
    // Look for activities XML
    $xmlFiles = glob($dir . '/*_activities.xml');
    if ($xmlFiles) {
        echo "Activities XML found: " . basename($xmlFiles[0]) . "\n";
        
        // Parse and show some sample data
        $xml = simplexml_load_file($xmlFiles[0]);
        if ($xml) {
            echo "\nSample FET activities (first 5):\n";
            $count = 0;
            foreach ($xml->Activity as $act) {
                if ($count++ >= 5) break;
                echo "  ID: {$act->Id}, Day: {$act->Day}, Hour: {$act->Hour}\n";
            }
        }
    } else {
        echo "No activities XML found\n";
    }
}

// Check if there are any recent temp metadata files
$tempDir = sys_get_temp_dir();
echo "\n=== Temp Directory Check ===\n";
echo "Temp dir: $tempDir\n";
$metaFiles = glob($tempDir . '/fet_school_*.meta.json');
if ($metaFiles) {
    echo "Found " . count($metaFiles) . " metadata files\n";
    foreach ($metaFiles as $file) {
        echo "  " . basename($file) . "\n";
    }
} else {
    echo "No metadata files found (they are cleaned up after generation)\n";
}

echo "\n=== Analysis ===\n";
if ($slotCount == 0) {
    echo "❌ PROBLEM: No scheduled slots in database despite successful generation\n";
    echo "This indicates the solution XML parsing is failing.\n";
    echo "Most likely causes:\n";
    echo "1. Class names in FET output don't match database class names exactly\n";
    echo "2. Teacher names in FET output don't match database teacher names exactly\n";
    echo "3. Activity metadata mapping is not working correctly\n";
} else {
    echo "✓ Scheduled slots exist - parsing is working\n";
}

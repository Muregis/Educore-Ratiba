<?php
require_once 'db/db.php';

echo "=== Generated Timetables ===\n";
$stmt = db()->query('SELECT * FROM generated_timetables ORDER BY generated_at DESC LIMIT 5');
$timetables = $stmt->fetchAll();
foreach ($timetables as $t) {
    echo "ID: {$t['id']}, School: {$t['school_id']}, Status: {$t['status']}, Date: {$t['generated_at']}\n";
    echo "HTML Path: {$t['html_output_path']}\n";
    echo "Has XML: " . ($t['xml_snapshot'] ? 'Yes' : 'No') . "\n";
    echo "---\n";
}

echo "\n=== Scheduled Slots Count ===\n";
$stmt = db()->query('SELECT COUNT(*) as cnt FROM scheduled_slots');
$result = $stmt->fetch();
echo "Total slots: {$result['cnt']}\n";

echo "\n=== Scheduled Slots by Generation ===\n";
$stmt = db()->query('SELECT generated_timetable_id, COUNT(*) as cnt FROM scheduled_slots GROUP BY generated_timetable_id');
$results = $stmt->fetchAll();
foreach ($results as $r) {
    echo "Generation ID {$r['generated_timetable_id']}: {$r['cnt']} slots\n";
}

echo "\n=== Recent Error Logs ===\n";
// Check if there are any PHP error logs
$logFiles = glob('C:/laragon/www/fet-timetable/Educore Ratiba/output/schools/*/logs/*.log');
foreach ($logFiles as $logFile) {
    echo "Log file: $logFile\n";
    echo file_get_contents($logFile) . "\n---\n";
}

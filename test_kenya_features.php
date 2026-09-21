<?php
/**
 * Test Script for Kenya Market Features
 * Run this to verify the implementation
 */

echo "Testing Kenya Market Features Implementation\n";
echo "============================================\n\n";

// Test 1: Check if required files exist
echo "1. Checking required files...\n";
$requiredFiles = [
    'admin/calendar.php',
    'db/knec_dates.php',
    'db/school_templates.php',
    'data/bands/tertiary.txt',
    'db/migrations/add_school_classification.sql',
    'db/migrations/add_tsc_number_to_teachers.sql',
    'db/migrations/add_school_calendar_system.sql'
];

$allFilesExist = true;
foreach ($requiredFiles as $file) {
    $exists = file_exists(__DIR__ . '/' . $file);
    echo "   - $file: " . ($exists ? "✓" : "✗") . "\n";
    if (!$exists) $allFilesExist = false;
}
echo $allFilesExist ? "   All files exist ✓\n\n" : "   Some files missing ✗\n\n";

// Test 2: Check school templates class
echo "2. Testing SchoolTemplates class...\n";
if (file_exists(__DIR__ . '/db/school_templates.php')) {
    require_once __DIR__ . '/db/school_templates.php';
    $templates = SchoolTemplates::getAllTemplates();
    echo "   - Templates loaded: " . count($templates) . "\n";
    foreach ($templates as $key => $template) {
        echo "     • $key: {$template['name']}\n";
    }
    echo "   Templates working ✓\n\n";
} else {
    echo "   SchoolTemplates class not found ✗\n\n";
}

// Test 3: Check KNEC dates function
echo "3. Testing KNEC dates function...\n";
if (file_exists(__DIR__ . '/db/knec_dates.php')) {
    require_once __DIR__ . '/db/knec_dates.php';
    $examDates = getKNECExamDates(2025);
    echo "   - Exam dates for 2025: " . count($examDates) . "\n";
    foreach ($examDates as $exam) {
        echo "     • {$exam['name']}: {$exam['date']} ({$exam['duration_days']} days)\n";
    }
    echo "   KNEC dates working ✓\n\n";
} else {
    echo "   KNEC dates file not found ✗\n\n";
}

// Test 4: Check database schema files
echo "4. Checking database schema files...\n";
$schemaFiles = [
    'db/schema.sql',
    'db/schema_postgres.sql'
];

foreach ($schemaFiles as $file) {
    if (file_exists(__DIR__ . '/' . $file)) {
        $content = file_get_contents(__DIR__ . '/' . $file);
        $hasSchoolType = strpos($content, 'school_type') !== false;
        $hasTSC = strpos($content, 'tsc_number') !== false;
        $hasCalendar = strpos($content, 'school_calendar') !== false;

        echo "   - $file:\n";
        echo "     • school_type field: " . ($hasSchoolType ? "✓" : "✗") . "\n";
        echo "     • tsc_number field: " . ($hasTSC ? "✓" : "✗") . "\n";
        echo "     • school_calendar table: " . ($hasCalendar ? "✓" : "✗") . "\n";
    } else {
        echo "   - $file: not found ✗\n";
    }
}
echo "\n";

// Test 5: Check admin files for new fields
echo "5. Checking admin interface files...\n";
$adminFiles = [
    'admin/teachers.php' => 'tsc_number',
    'super/schools.php' => 'school_type'
];

foreach ($adminFiles as $file => $searchTerm) {
    if (file_exists(__DIR__ . '/' . $file)) {
        $content = file_get_contents(__DIR__ . '/' . $file);
        $hasField = strpos($content, $searchTerm) !== false;
        echo "   - $file: " . ($hasField ? "✓" : "✗") . " (contains '$searchTerm')\n";
    } else {
        echo "   - $file: not found ✗\n";
    }
}
echo "\n";

echo "============================================\n";
echo "Basic verification complete.\n";
echo "For full testing, run the database migrations\n";
echo "and test the web interface directly.\n";

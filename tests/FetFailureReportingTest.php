<?php
declare(strict_types=1);

/**
 * FetFailureReportingTest — Automated test suite verifying that FET execution
 * and constraint failure modes are reported accurately and informatively.
 *
 * Tests:
 * 1. Engine missing on host
 * 2. Engine permission / not executable
 * 3. Execution function blocked (proc_open/shell_exec)
 * 4. Invalid or corrupted XML input
 * 5. Constraint failure with unplaced activities and metadata mapping
 * 6. Multi-activity failure pinpointing class, subject, and teacher
 */

require_once __DIR__ . '/../admin/fet_runtime.php';

$passed = 0;
$failed = 0;

function assertCheck(bool $condition, string $testName): void
{
    global $passed, $failed;
    if ($condition) {
        echo "PASS  {$testName}\n";
        $passed++;
    } else {
        echo "FAIL  {$testName}\n";
        $failed++;
    }
}

echo "=== FET FAILURE REPORTING & DIAGNOSTIC TEST SUITE ===\n\n";

// Test 1: Empty output (crashed or killed process)
$diag = diagnoseFetFailureV2('', []);
assertCheck(
    !empty($diag['summary']) && str_contains($diag['summary'][0], 'produced no output'),
    'Crashed/empty engine output triggers actionable crash advisory'
);

// Test 2: Engine binary missing
$diag = diagnoseFetFailureV2('FET engine not found at: /app/engine/fet-cl', []);
assertCheck(
    !empty($diag['summary']) && str_contains($diag['summary'][0], 'FET engine binary was not found'),
    'Missing binary output accurately identifies missing FET executable'
);

// Test 3: Engine binary not executable (permissions)
$diag = diagnoseFetFailureV2('FET engine exists but is not executable: /var/www/html/engine/fet-cl', []);
assertCheck(
    !empty($diag['summary']) && str_contains($diag['summary'][0], 'exists but is not executable'),
    'Permission denied / non-executable binary accurately diagnosed'
);

// Test 4: PHP execution function disabled
$diag = diagnoseFetFailureV2('shell_exec and proc_open are disabled in PHP', []);
assertCheck(
    !empty($diag['summary']) && str_contains($diag['summary'][0], 'PHP cannot run the scheduling engine'),
    'Disabled PHP process functions flagged with hosting advice'
);

// Test 5: XML parse error
$diag = diagnoseFetFailureV2('Cannot open XML file or invalid XML structure', []);
assertCheck(
    !empty($diag['summary']) && str_contains($diag['summary'][0], 'rejected the input file (invalid or unreadable XML)'),
    'Malformed XML input failure accurately diagnosed'
);

// Test 6: Constraint solve failure with unplaced lessons
$rawEngineOutput = "Simulation finished.\nTotal conflicts: 2\nActivity 101 not scheduled\nActivity 102 not scheduled\n";
$meta = [
    101 => [
        'class_name' => 'Grade 7 East',
        'subject_name' => 'Integrated Science',
        'teacher_name' => 'Mr. Kamau',
    ],
    102 => [
        'class_name' => 'Grade 8 West',
        'subject_name' => 'Pre-Technical Studies',
        'teacher_name' => 'Mrs. Ochieng',
    ],
];
$diag = diagnoseFetFailureV2($rawEngineOutput, $meta);
assertCheck(
    count($diag['details']) >= 2,
    'Unplaced activities correctly identified from engine conflict log'
);
assertCheck(
    str_contains($diag['details'][1] ?? '', 'Grade 7 East — Integrated Science (teacher: Mr. Kamau)'),
    'Pinpoints exact Class, Subject, and Teacher for Activity 101'
);
assertCheck(
    str_contains($diag['details'][2] ?? '', 'Grade 8 West — Pre-Technical Studies (teacher: Mrs. Ochieng)'),
    'Pinpoints exact Class, Subject, and Teacher for Activity 102'
);

echo "\n============================================\n";
echo "FET DIAGNOSTIC RESULTS: {$passed} passed, {$failed} failed\n";
echo "============================================\n";

exit($failed > 0 ? 1 : 0);

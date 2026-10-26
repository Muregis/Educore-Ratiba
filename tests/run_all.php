<?php
declare(strict_types=1);

/**
 * EduCore Ratiba — Master Automated Verification & Test Runner
 *
 * Runs all unit, integration, constraint, security, and schema test batteries.
 * Executed via:
 *   composer test
 *   php tests/run_all.php
 */

$startTime = microtime(true);
$phpBin = PHP_BINARY;

echo "====================================================================\n";
echo "           EDUCORE RATIBA — MASTER TEST & VERIFICATION SUITE       \n";
echo "====================================================================\n";
echo "PHP Version: " . PHP_VERSION . " (" . PHP_OS_FAMILY . ")\n";
echo "Timestamp:   " . date('Y-m-d H:i:s T') . "\n";
echo "====================================================================\n\n";

$testSuites = [
    [
        'name' => 'Priority 1: Timetable Constraints & Clash Detection',
        'file' => __DIR__ . '/ClashDetectorTest.php',
    ],
    [
        'name' => 'Priority 2: FET Solver Failure Diagnostics',
        'file' => __DIR__ . '/FetFailureReportingTest.php',
    ],
    [
        'name' => 'Priority 3: Security, CSRF & Authentication',
        'file' => __DIR__ . '/SecurityTest.php',
    ],
    [
        'name' => 'Priority 4: Database Schema & Dialect Parity',
        'file' => __DIR__ . '/DatabaseCompatibilityTest.php',
    ],
    [
        'name' => 'Priority 5: Teacher & Master Timetable Views',
        'file' => __DIR__ . '/TimetableViewTest.php',
    ],
    [
        'name' => 'Priority 6: Real-World Sample Timetable Compliance',
        'file' => __DIR__ . '/SampleTimetableComplianceTest.php',
    ],
];

$suiteResults = [];
$allPassed = true;

foreach ($testSuites as $suite) {
    echo ">> Running " . $suite['name'] . "...\n";
    $cmd = escapeshellarg($phpBin) . ' ' . escapeshellarg($suite['file']);
    $output = [];
    $returnCode = 0;
    exec($cmd, $output, $returnCode);

    $outText = implode("\n", $output);
    $passedCount = 0;
    $failedCount = 0;

    if (preg_match('/(?:RESULTS|TEST RESULTS):\s*(\d+)\s+passed,\s*(\d+)\s+failed/i', $outText, $m)) {
        $passedCount = (int) $m[1];
        $failedCount = (int) $m[2];
    } else {
        $passedCount = substr_count($outText, 'PASS');
        $failedCount = substr_count($outText, 'FAIL');
    }

    $isSuccess = ($returnCode === 0 && $failedCount === 0);
    if (!$isSuccess) {
        $allPassed = false;
    }

    $suiteResults[] = [
        'name' => $suite['name'],
        'passed' => $passedCount,
        'failed' => $failedCount,
        'status' => $isSuccess ? 'PASS' : 'FAIL',
        'output' => $outText,
    ];

    echo ($isSuccess ? "   [OK] " : "   [FAIL] ") . "Passed: {$passedCount}, Failed: {$failedCount}\n\n";
}

$duration = round(microtime(true) - $startTime, 2);
$totalPassed = array_sum(array_column($suiteResults, 'passed'));
$totalFailed = array_sum(array_column($suiteResults, 'failed'));

echo "====================================================================\n";
echo "                      FINAL VERIFICATION SUMMARY                    \n";
echo "====================================================================\n";
printf("%-48s %-10s %-8s\n", "Test Suite", "Status", "Tests");
echo str_repeat("-", 68) . "\n";

foreach ($suiteResults as $r) {
    printf("%-48s [%-4s]     %3d / %d\n", $r['name'], $r['status'], $r['passed'], ($r['passed'] + $r['failed']));
}

echo str_repeat("=", 68) . "\n";
printf("TOTAL TESTS:  %d passed, %d failed\n", $totalPassed, $totalFailed);
printf("TIME ELAPSED: %s seconds\n", $duration);
printf("MEMORY PEAK:  %s MB\n", round(memory_get_peak_usage(true) / 1024 / 1024, 2));
echo "OVERALL VERDICT: " . ($allPassed ? "100% PRODUCTION READY (PASS)" : "VERIFICATION FAILED") . "\n";
echo "====================================================================\n";

exit($allPassed ? 0 : 1);

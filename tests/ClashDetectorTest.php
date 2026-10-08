<?php
/**
 * Run: php tests/ClashDetectorTest.php
 * Exit code 0 = all pass (CI-friendly).
 */
require_once __DIR__ . '/ClashDetector.php';

$passed = 0;
$failed = 0;

function assertTrue(bool $cond, string $label): void
{
    global $passed, $failed;
    if ($cond) {
        echo "PASS  {$label}\n";
        $passed++;
    } else {
        echo "FAIL  {$label}\n";
        $failed++;
    }
}

// --- Teacher double-booking ---
$slots = [
    ['teacher_id' => 'T1', 'room_id' => 'R1', 'class_id' => 'C1', 'day' => 1, 'period' => 2],
    ['teacher_id' => 'T1', 'room_id' => 'R2', 'class_id' => 'C2', 'day' => 1, 'period' => 2],
];
$c = ClashDetector::findConflicts($slots);
assertTrue(count($c) >= 1 && str_contains($c[0], 'Teacher'), 'Teacher double-booking detected');

// --- Room double-booking ---
$slots = [
    ['teacher_id' => 'T1', 'room_id' => 'R1', 'class_id' => 'C1', 'day' => 2, 'period' => 3],
    ['teacher_id' => 'T2', 'room_id' => 'R1', 'class_id' => 'C2', 'day' => 2, 'period' => 3],
];
$c = ClashDetector::findConflicts($slots);
assertTrue(count($c) >= 1 && str_contains(implode(' ', $c), 'Room'), 'Room double-booking detected');

// --- Class double-booking ---
$slots = [
    ['teacher_id' => 'T1', 'room_id' => 'R1', 'class_id' => 'C1', 'day' => 3, 'period' => 1],
    ['teacher_id' => 'T2', 'room_id' => 'R2', 'class_id' => 'C1', 'day' => 3, 'period' => 1],
];
$c = ClashDetector::findConflicts($slots);
assertTrue(count($c) >= 1 && str_contains(implode(' ', $c), 'Class'), 'Class double-booking detected');

// --- Valid schedule (no conflicts) ---
$slots = [
    ['teacher_id' => 'T1', 'room_id' => 'R1', 'class_id' => 'C1', 'day' => 1, 'period' => 1],
    ['teacher_id' => 'T2', 'room_id' => 'R2', 'class_id' => 'C2', 'day' => 1, 'period' => 1],
    ['teacher_id' => 'T1', 'room_id' => 'R1', 'class_id' => 'C1', 'day' => 1, 'period' => 2],
];
$c = ClashDetector::findConflicts($slots);
assertTrue(count($c) === 0, 'Valid schedule remains conflict-free');

// --- Different day/period is fine ---
$slots = [
    ['teacher_id' => 'T1', 'room_id' => 'R1', 'class_id' => 'C1', 'day' => 1, 'period' => 1],
    ['teacher_id' => 'T1', 'room_id' => 'R1', 'class_id' => 'C1', 'day' => 2, 'period' => 1],
];
$c = ClashDetector::findConflicts($slots);
assertTrue(count($c) === 0, 'Same teacher different day is allowed');

// --- Workload pre-checks ---
assertTrue(ClashDetector::teacherLoadValid(20, 25) === true, 'Teacher load within max');
assertTrue(ClashDetector::teacherLoadValid(30, 25) === false, 'Teacher overload rejected');
assertTrue(ClashDetector::teacherLoadValid(-1, 25) === false, 'Invalid negative teacher load');
assertTrue(ClashDetector::classLoadValid(35, 35) === true, 'Class load fits band slots');
assertTrue(ClashDetector::classLoadValid(40, 35) === false, 'Class load exceeds band slots');
assertTrue(ClashDetector::classLoadValid(10, 0) === false, 'Zero band slots invalid');

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);

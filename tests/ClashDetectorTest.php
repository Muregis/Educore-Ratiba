<?php
declare(strict_types=1);

/**
 * ClashDetectorTest — Comprehensive automated test battery for timetable constraints.
 *
 * Covers:
 * 1. Teacher conflicts (double-booking across rooms/classes)
 * 2. Class conflicts (concurrent lessons for same class/stream)
 * 3. Room conflicts (concurrent occupancy of rooms; null/outdoor room safety)
 * 4. Duplicate slots (identical timetable entries)
 * 5. Invalid workloads (overloads, negative loads, zero band slots)
 * 6. Manual adjustments (moving and swapping slots with clash prevention)
 * 7. Generation preflight (detecting invalid configurations before FET runs)
 * 8. Valid schedules (full multi-teacher/class schedule verification)
 *
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

echo "=== PRIORITY 1: CLASH & CONSTRAINT TEST SUITE ===\n\n";

// ============================================================
// 1. TEACHER CONFLICTS
// ============================================================
echo "-- Teacher Conflicts --\n";

// Same teacher in two different classes and rooms at same time
$slots = [
    ['teacher_id' => 'T1', 'room_id' => 'R1', 'class_id' => 'C1', 'day' => 'Monday', 'period' => '08:00-08:40'],
    ['teacher_id' => 'T1', 'room_id' => 'R2', 'class_id' => 'C2', 'day' => 'Monday', 'period' => '08:00-08:40'],
];
$c = ClashDetector::findConflicts($slots);
assertTrue(count($c) >= 1 && str_contains($c[0], 'Teacher'), 'Teacher double-booking across different classes detected');

// Same teacher in two classes, one room null (e.g. playground)
$slots = [
    ['teacher_id' => 'T_PE', 'room_id' => null, 'class_id' => 'C1', 'day' => 'Tuesday', 'period' => '10:00-10:40'],
    ['teacher_id' => 'T_PE', 'room_id' => 'R3',  'class_id' => 'C2', 'day' => 'Tuesday', 'period' => '10:00-10:40'],
];
$c = ClashDetector::findConflicts($slots);
assertTrue(count($c) >= 1 && str_contains($c[0], 'Teacher'), 'Teacher double-booking with unassigned room detected');

// Same teacher at different periods is permitted
$slots = [
    ['teacher_id' => 'T1', 'room_id' => 'R1', 'class_id' => 'C1', 'day' => 'Monday', 'period' => '08:00-08:40'],
    ['teacher_id' => 'T1', 'room_id' => 'R2', 'class_id' => 'C2', 'day' => 'Monday', 'period' => '08:40-09:20'],
];
$c = ClashDetector::findConflicts($slots);
assertTrue(count($c) === 0, 'Same teacher in different consecutive periods is allowed');


// ============================================================
// 2. CLASS CONFLICTS
// ============================================================
echo "\n-- Class Conflicts --\n";

// Same class assigned two different teachers/subjects at same time
$slots = [
    ['teacher_id' => 'T1', 'room_id' => 'R1', 'class_id' => 'C_Grade1', 'day' => 'Wednesday', 'period' => '09:00-09:40'],
    ['teacher_id' => 'T2', 'room_id' => 'R2', 'class_id' => 'C_Grade1', 'day' => 'Wednesday', 'period' => '09:00-09:40'],
];
$c = ClashDetector::findConflicts($slots);
assertTrue(count($c) >= 1 && str_contains($c[0], 'Class'), 'Class double-booking detected');

// Database column name aliases ('day_of_week', 'hour_slot')
$slots = [
    ['teacher_id' => 10, 'room_id' => 1, 'class_id' => 5, 'day_of_week' => 'Thursday', 'hour_slot' => 'B__08:00-08:40'],
    ['teacher_id' => 11, 'room_id' => 2, 'class_id' => 5, 'day_of_week' => 'Thursday', 'hour_slot' => 'B__08:00-08:40'],
];
$c = ClashDetector::findConflicts($slots);
assertTrue(count($c) >= 1 && str_contains($c[0], 'Class'), 'Class double-booking detected using DB column names');


// ============================================================
// 3. ROOM CONFLICTS
// ============================================================
echo "\n-- Room Conflicts --\n";

// Two classes in the same physical room at the same time
$slots = [
    ['teacher_id' => 'T1', 'room_id' => 'Lab1', 'class_id' => 'Form3A', 'day' => 'Friday', 'period' => '11:00-11:40'],
    ['teacher_id' => 'T2', 'room_id' => 'Lab1', 'class_id' => 'Form4B', 'day' => 'Friday', 'period' => '11:00-11:40'],
];
$c = ClashDetector::findConflicts($slots);
assertTrue(count($c) >= 1 && str_contains($c[0], 'Room'), 'Room double-booking detected');

// Null rooms (e.g. PE outside) must NOT clash with each other
$slots = [
    ['teacher_id' => 'T_Coach1', 'room_id' => null, 'class_id' => 'Form1A', 'day' => 'Friday', 'period' => '14:00-14:40'],
    ['teacher_id' => 'T_Coach2', 'room_id' => null, 'class_id' => 'Form2A', 'day' => 'Friday', 'period' => '14:00-14:40'],
];
$c = ClashDetector::findConflicts($slots);
assertTrue(count($c) === 0, 'Multiple classes with unassigned/null rooms do not trigger room conflict');


// ============================================================
// 4. DUPLICATE SLOTS
// ============================================================
echo "\n-- Duplicate Slot Detection --\n";

// Exactly identical lesson slots at the same time
$slots = [
    ['id' => 101, 'teacher_id' => 'T1', 'room_id' => 'R1', 'class_id' => 'C1', 'subject_id' => 'Math', 'day' => 'Monday', 'period' => '08:00-08:40'],
    ['id' => 101, 'teacher_id' => 'T1', 'room_id' => 'R1', 'class_id' => 'C1', 'subject_id' => 'Math', 'day' => 'Monday', 'period' => '08:00-08:40'],
];
$detailed = ClashDetector::findConflictsDetailed($slots);
$hasDup = !empty(array_filter($detailed, fn($x) => $x['type'] === 'duplicate'));
assertTrue($hasDup, 'Duplicate slot with identical ID detected');

// Identical allocation with different IDs (double assignment)
$slots = [
    ['id' => 201, 'teacher_id' => 'T1', 'room_id' => 'R1', 'class_id' => 'C1', 'subject_id' => 'ENG', 'day' => 'Tuesday', 'period' => '10:00-10:40'],
    ['id' => 202, 'teacher_id' => 'T1', 'room_id' => 'R1', 'class_id' => 'C1', 'subject_id' => 'ENG', 'day' => 'Tuesday', 'period' => '10:00-10:40'],
];
$detailed = ClashDetector::findConflictsDetailed($slots);
$hasDup = !empty(array_filter($detailed, fn($x) => $x['type'] === 'duplicate'));
assertTrue($hasDup, 'Duplicate slot with same class, teacher, subject and room detected');


// ============================================================
// 5. INVALID WORKLOADS
// ============================================================
echo "\n-- Invalid Workload Checks --\n";

// Teacher loads
assertTrue(ClashDetector::teacherLoadValid(20, 25) === true, 'Teacher load within maximum accepted');
assertTrue(ClashDetector::teacherLoadValid(25, 25) === true, 'Teacher load exactly at maximum accepted');
assertTrue(ClashDetector::teacherLoadValid(30, 25) === false, 'Teacher overload rejected');
assertTrue(ClashDetector::teacherLoadValid(-1, 25) === false, 'Invalid negative teacher load rejected');
assertTrue(ClashDetector::teacherLoadValid(10, -5) === false, 'Invalid negative teacher capacity rejected');

// Class loads
assertTrue(ClashDetector::classLoadValid(35, 35) === true, 'Class load exactly fitting band slots accepted');
assertTrue(ClashDetector::classLoadValid(20, 35) === true, 'Class load within band slots accepted');
assertTrue(ClashDetector::classLoadValid(40, 35) === false, 'Class load exceeding band slots rejected');
assertTrue(ClashDetector::classLoadValid(10, 0) === false, 'Zero band slots per week rejected');
assertTrue(ClashDetector::classLoadValid(-5, 35) === false, 'Negative class lessons rejected');


// ============================================================
// 6. MANUAL ADJUSTMENTS (MOVES & SWAPS)
// ============================================================
echo "\n-- Manual Adjustments --\n";

$initialSchedule = [
    ['id' => 1, 'teacher_id' => 'T1', 'room_id' => 'R1', 'class_id' => 'C1', 'day' => 'Monday', 'period' => 'P1'],
    ['id' => 2, 'teacher_id' => 'T2', 'room_id' => 'R2', 'class_id' => 'C2', 'day' => 'Monday', 'period' => 'P1'],
    ['id' => 3, 'teacher_id' => 'T1', 'room_id' => 'R1', 'class_id' => 'C1', 'day' => 'Tuesday', 'period' => 'P2'],
    ['id' => 4, 'teacher_id' => 'T3', 'room_id' => 'R3', 'class_id' => 'C3', 'day' => 'Monday', 'period' => 'P2'],
];

// Valid move: Move slot 3 (T1 with C1 on Tuesday P2) to Monday P3 (completely empty)
$moveClashes = ClashDetector::validateManualMove($initialSchedule, 3, 'Monday', 'P3');
assertTrue(empty($moveClashes), 'Manual move to empty slot is conflict-free');

// Invalid move: Move slot 3 to Monday P1 where T1 is ALREADY teaching C1
$moveClashes = ClashDetector::validateManualMove($initialSchedule, 3, 'Monday', 'P1');
assertTrue(!empty($moveClashes), 'Manual move creating teacher clash is rejected');

// Invalid move: Move slot 4 (T3, C3) to Monday P1 into room R1 (where R1 is already used by C1)
$moveClashes = ClashDetector::validateManualMove($initialSchedule, 4, 'Monday', 'P1', 'R1');
assertTrue(!empty($moveClashes), 'Manual move creating room clash is rejected');

// Valid swap: Swap slot 1 (Monday P1) with slot 4 (Monday P2) - T1/C1 and T3/C3
$swapClashes = ClashDetector::validateManualSwap($initialSchedule, 1, 4);
assertTrue(empty($swapClashes), 'Manual swap between compatible slots is conflict-free');

// Invalid swap: Consider scenario where T1 is involved in another lesson on Monday P2
$busySchedule = [
    ['id' => 1, 'teacher_id' => 'T1', 'room_id' => 'R1', 'class_id' => 'C1', 'day' => 'Monday', 'period' => 'P1'],
    ['id' => 2, 'teacher_id' => 'T2', 'room_id' => 'R2', 'class_id' => 'C2', 'day' => 'Monday', 'period' => 'P2'],
    ['id' => 3, 'teacher_id' => 'T1', 'room_id' => 'R3', 'class_id' => 'C3', 'day' => 'Monday', 'period' => 'P2'], // T1 already busy at P2!
];
// Swapping slot 1 (T1 at P1) into P2 would double-book T1 at P2!
$swapClashes = ClashDetector::validateManualSwap($busySchedule, 1, 2);
assertTrue(!empty($swapClashes), 'Manual swap that causes teacher collision is rejected');


// ============================================================
// 7. GENERATION PREFLIGHT
// ============================================================
echo "\n-- Generation Preflight Checks --\n";

// Config with class exceeding band slots
$badClasses = [
    [
        'name' => 'Grade 8',
        'active' => true,
        'lessons_per_day' => 7, // 7 * 5 = 35 max
        'subjects' => [
            ['name' => 'Math', 'lessons_per_week' => 20, 'duration_slots' => 1, 'teacher_id' => 1],
            ['name' => 'English', 'lessons_per_week' => 20, 'duration_slots' => 1, 'teacher_id' => 2], // 40 > 35!
        ]
    ]
];
$teachers = [
    ['name' => 'Mr. Math', 'max_lessons_per_week' => 30, 'assigned_lessons' => 20],
    ['name' => 'Ms. English', 'max_lessons_per_week' => 30, 'assigned_lessons' => 20],
];
$rooms = [['name' => 'Room 1']];
$preflightProblems = ClashDetector::validatePreflightConfig($badClasses, $teachers, $rooms, 5);
assertTrue(count($preflightProblems) >= 1 && str_contains($preflightProblems[0], 'exceeding band capacity'), 'Preflight catches class load exceeding band capacity');

// Config with unassigned teacher on subject
$unassignedClasses = [
    [
        'name' => 'Grade 7',
        'active' => true,
        'lessons_per_day' => 8,
        'subjects' => [
            ['name' => 'Science', 'lessons_per_week' => 5, 'duration_slots' => 1, 'teacher_id' => null],
        ]
    ]
];
$preflightProblems = ClashDetector::validatePreflightConfig($unassignedClasses, $teachers, $rooms, 5);
assertTrue(count($preflightProblems) >= 1 && str_contains($preflightProblems[0], 'has no teacher assigned'), 'Preflight catches subject with unassigned teacher');

// Config with teacher overload
$overloadedTeachers = [
    ['name' => 'Teacher Overworked', 'max_lessons_per_week' => 20, 'assigned_lessons' => 28],
];
$validClasses = [
    [
        'name' => 'Form 1',
        'active' => true,
        'lessons_per_day' => 8,
        'subjects' => [
            ['name' => 'History', 'lessons_per_week' => 5, 'duration_slots' => 1, 'teacher_id' => 1],
        ]
    ]
];
$preflightProblems = ClashDetector::validatePreflightConfig($validClasses, $overloadedTeachers, $rooms, 5);
assertTrue(count($preflightProblems) >= 1 && str_contains($preflightProblems[0], 'exceeding maximum load'), 'Preflight catches teacher assigned beyond maximum load');

// Completely valid config passes preflight
$cleanClasses = [
    [
        'name' => 'Class 1',
        'active' => true,
        'lessons_per_day' => 6,
        'subjects' => [
            ['name' => 'Math', 'lessons_per_week' => 5, 'duration_slots' => 1, 'teacher_id' => 1],
            ['name' => 'Art', 'lessons_per_week' => 5, 'duration_slots' => 1, 'teacher_id' => 2],
        ]
    ]
];
$cleanTeachers = [
    ['name' => 'Teacher A', 'max_lessons_per_week' => 25, 'assigned_lessons' => 5],
    ['name' => 'Teacher B', 'max_lessons_per_week' => 25, 'assigned_lessons' => 5],
];
$cleanRooms = [['name' => 'Room 101']];
$preflightProblems = ClashDetector::validatePreflightConfig($cleanClasses, $cleanTeachers, $cleanRooms, 5);
assertTrue(empty($preflightProblems), 'Valid configuration passes preflight with 0 problems');


// ============================================================
// 8. VALID SCHEDULES
// ============================================================
echo "\n-- Valid Schedule Validation --\n";

// Multi-day, multi-period, multi-teacher schedule
$fullValidSchedule = [
    ['teacher_id' => 'T1', 'room_id' => 'R1', 'class_id' => 'C1', 'day' => 'Monday', 'period' => '08:00-08:40'],
    ['teacher_id' => 'T2', 'room_id' => 'R2', 'class_id' => 'C2', 'day' => 'Monday', 'period' => '08:00-08:40'],
    ['teacher_id' => 'T3', 'room_id' => 'R3', 'class_id' => 'C3', 'day' => 'Monday', 'period' => '08:00-08:40'],
    ['teacher_id' => 'T1', 'room_id' => 'R2', 'class_id' => 'C2', 'day' => 'Monday', 'period' => '08:40-09:20'],
    ['teacher_id' => 'T2', 'room_id' => 'R1', 'class_id' => 'C1', 'day' => 'Monday', 'period' => '08:40-09:20'],
    ['teacher_id' => 'T1', 'room_id' => 'R1', 'class_id' => 'C1', 'day' => 'Tuesday', 'period' => '08:00-08:40'],
    ['teacher_id' => 'T2', 'room_id' => 'R2', 'class_id' => 'C2', 'day' => 'Tuesday', 'period' => '08:00-08:40'],
];
assertTrue(ClashDetector::isScheduleValid($fullValidSchedule), 'Full multi-teacher/class schedule passes conflict validation');


// ============================================================
// SUMMARY
// ============================================================
echo "\n============================================\n";
echo "TEST RESULTS: {$passed} passed, {$failed} failed\n";
echo "============================================\n";

exit($failed > 0 ? 1 : 0);

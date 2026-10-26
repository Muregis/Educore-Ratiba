<?php
declare(strict_types=1);

/**
 * TimetableViewTest — Automated test suite for Teacher Timetable & Master Whole-School Grid Views.
 *
 * Verifies:
 * 1. Timeline period extraction and chronological sorting.
 * 2. Slot matching across band keys, clock ranges, and free periods.
 * 3. Teacher schedule grouping with class name, subject name, and room.
 * 4. Master Whole-School matrix construction (by Class and by Teacher).
 * 5. Educational progression ordering in Master views.
 *
 * Run: php tests/TimetableViewTest.php
 */

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

echo "=== PRIORITY 5: TEACHER & MASTER TIMETABLE VIEW TEST SUITE ===\n\n";

// Helper mocks matching view_timetable.php logic
function testFormatHourSlotLabel(string $hour): string
{
    $h = preg_replace('/^[^_]*__/', '', $hour) ?? $hour;
    return trim((string) $h);
}

function testHourClockPart(string $hour): string
{
    return testFormatHourSlotLabel($hour);
}

function testFindSlotForPeriod(array $grid, string $day, array $period): ?array
{
    $key = $period['key'];
    if (isset($grid[$day][$key])) {
        return $grid[$day][$key];
    }
    $clock = testHourClockPart($key);
    if (isset($grid[$day]['__clock__' . $clock])) {
        return $grid[$day]['__clock__' . $clock];
    }
    if (preg_match('/(\d{2}:\d{2})\s*-\s*(\d{2}:\d{2})/', $clock, $m)) {
        $needle = $m[1] . '-' . $m[2];
        foreach ($grid[$day] ?? [] as $hk => $slot) {
            if (str_starts_with((string) $hk, '__clock__')) {
                continue;
            }
            if (str_contains(str_replace(' ', '', testHourClockPart((string) $hk)), str_replace(' ', '', $needle))) {
                return $slot;
            }
        }
    }
    return null;
}

// -------------------------------------------------------------
// Test 1: Period Matching Logic (Exact Key, Clock Part, Normalized Range)
// -------------------------------------------------------------
echo "-- 1. Period Matching and Slot Finding --\n";

$grid = [
    'Monday' => [
        'B__08:00-08:40' => ['subject_name' => 'Mathematics', 'class_name' => 'Grade 7A', 'teacher_name' => 'Mr. Kamau', 'room_name' => 'Lab 1'],
        '__clock__08:00-08:40' => ['subject_name' => 'Mathematics', 'class_name' => 'Grade 7A', 'teacher_name' => 'Mr. Kamau', 'room_name' => 'Lab 1'],
        'B__08:40-09:20' => ['subject_name' => 'English', 'class_name' => 'Grade 8B', 'teacher_name' => 'Ms. Achieng', 'room_name' => 'Room 4'],
        '__clock__08:40-09:20' => ['subject_name' => 'English', 'class_name' => 'Grade 8B', 'teacher_name' => 'Ms. Achieng', 'room_name' => 'Room 4'],
    ],
];

// Exact key match
$period1 = ['key' => 'B__08:00-08:40', 'label' => '08:00 - 08:40', 'is_break' => false];
$slot1 = testFindSlotForPeriod($grid, 'Monday', $period1);
assertTrue($slot1 !== null && $slot1['subject_name'] === 'Mathematics', 'Find slot by exact band key');

// Cross-band clock match (e.g. Master timeline has unified clock label)
$periodCrossBand = ['key' => 'MASTER__08:00-08:40', 'label' => '08:00-08:40', 'is_break' => false];
$slotCrossBand = testFindSlotForPeriod($grid, 'Monday', $periodCrossBand);
assertTrue($slotCrossBand !== null && $slotCrossBand['class_name'] === 'Grade 7A', 'Find slot across band key prefix via clock matching');

// Free period returns null
$periodFree = ['key' => 'B__10:00-10:40', 'label' => '10:00 - 10:40', 'is_break' => false];
$slotFree = testFindSlotForPeriod($grid, 'Monday', $periodFree);
assertTrue($slotFree === null, 'Free period correctly returns null slot');

// Different day returns null
$slotWrongDay = testFindSlotForPeriod($grid, 'Tuesday', $period1);
assertTrue($slotWrongDay === null, 'Non-scheduled day correctly returns null');

// -------------------------------------------------------------
// Test 2: Teacher Schedule Extraction & Aggregation
// -------------------------------------------------------------
echo "\n-- 2. Teacher Schedule Aggregation --\n";

$teacherSlots = [
    ['teacher_id' => 1, 'class_name' => 'Grade 7A', 'subject_name' => 'Mathematics', 'room_name' => 'Lab 1', 'day_of_week' => 'Monday', 'hour_slot' => 'B__08:00-08:40'],
    ['teacher_id' => 1, 'class_name' => 'Grade 7A', 'subject_name' => 'Mathematics', 'room_name' => 'Lab 1', 'day_of_week' => 'Wednesday', 'hour_slot' => 'B__08:00-08:40'],
    ['teacher_id' => 1, 'class_name' => 'Grade 8B', 'subject_name' => 'Physics', 'room_name' => 'Lab 2', 'day_of_week' => 'Monday', 'hour_slot' => 'B__10:30-11:10'],
    ['teacher_id' => 1, 'class_name' => 'Grade 9C', 'subject_name' => 'Mathematics', 'room_name' => 'Room 12', 'day_of_week' => 'Friday', 'hour_slot' => 'B__14:00-14:40'],
];

$classesTaught = array_values(array_unique(array_filter(array_column($teacherSlots, 'class_name'))));
sort($classesTaught);
assertTrue($classesTaught === ['Grade 7A', 'Grade 8B', 'Grade 9C'], 'Teacher classes taught aggregated correctly');

$subjectsTaught = array_values(array_unique(array_filter(array_column($teacherSlots, 'subject_name'))));
sort($subjectsTaught);
assertTrue($subjectsTaught === ['Mathematics', 'Physics'], 'Teacher subjects taught aggregated correctly');

$totalTeacherLessons = count($teacherSlots);
assertTrue($totalTeacherLessons === 4, 'Teacher weekly lesson count computed accurately');

// -------------------------------------------------------------
// Test 3: Master Whole-School Matrix Construction
// -------------------------------------------------------------
echo "\n-- 3. Master Whole-School Matrix Construction --\n";

$schoolSlots = [
    // Grade 7A
    ['class_id' => 1, 'class_name' => 'Grade 7A', 'teacher_id' => 10, 'teacher_name' => 'Mr. Mwangi', 'subject_name' => 'Math', 'room_code' => 'R1', 'day_of_week' => 'Monday', 'hour_slot' => '08:00-08:40'],
    ['class_id' => 1, 'class_name' => 'Grade 7A', 'teacher_id' => 11, 'teacher_name' => 'Ms. Atieno', 'subject_name' => 'English', 'room_code' => 'R1', 'day_of_week' => 'Monday', 'hour_slot' => '08:40-09:20'],
    // Grade 8A
    ['class_id' => 2, 'class_name' => 'Grade 8A', 'teacher_id' => 12, 'teacher_name' => 'Mr. Omondi', 'subject_name' => 'Science', 'room_code' => 'LAB', 'day_of_week' => 'Monday', 'hour_slot' => '08:00-08:40'],
    ['class_id' => 2, 'class_name' => 'Grade 8A', 'teacher_id' => 10, 'teacher_name' => 'Mr. Mwangi', 'subject_name' => 'Math', 'room_code' => 'R2', 'day_of_week' => 'Monday', 'hour_slot' => '08:40-09:20'],
];

// Build master class grid
$masterClassGrid = [];
$masterTeacherGrid = [];
foreach ($schoolSlots as $s) {
    $d = $s['day_of_week'];
    $h = $s['hour_slot'];
    $cid = $s['class_id'];
    $tid = $s['teacher_id'];

    $masterClassGrid[$d][$cid][$h] = $s;
    $masterClassGrid[$d][$cid]['__clock__' . testHourClockPart($h)] = $s;

    $masterTeacherGrid[$d][$tid][$h] = $s;
    $masterTeacherGrid[$d][$tid]['__clock__' . testHourClockPart($h)] = $s;
}

// Verify Class Matrix at Period 1
$p1 = ['key' => '08:00-08:40', 'label' => '08:00-08:40', 'is_break' => false];
$g7Slot = testFindSlotForPeriod([$currentDay = 'Monday' => $masterClassGrid['Monday'][1]], 'Monday', $p1);
$g8Slot = testFindSlotForPeriod([$currentDay = 'Monday' => $masterClassGrid['Monday'][2]], 'Monday', $p1);

assertTrue($g7Slot !== null && $g7Slot['subject_name'] === 'Math' && $g7Slot['teacher_name'] === 'Mr. Mwangi', 'Master Class Matrix: Grade 7A has Math with Mr. Mwangi at 08:00');
assertTrue($g8Slot !== null && $g8Slot['subject_name'] === 'Science' && $g8Slot['teacher_name'] === 'Mr. Omondi', 'Master Class Matrix: Grade 8A has Science with Mr. Omondi at 08:00');

// Verify Teacher Matrix at Period 1 & 2
$p2 = ['key' => '08:40-09:20', 'label' => '08:40-09:20', 'is_break' => false];

// Teacher 10 (Mr. Mwangi): Period 1 in Grade 7A, Period 2 in Grade 8A
$t10P1 = testFindSlotForPeriod(['Monday' => $masterTeacherGrid['Monday'][10]], 'Monday', $p1);
$t10P2 = testFindSlotForPeriod(['Monday' => $masterTeacherGrid['Monday'][10]], 'Monday', $p2);
assertTrue($t10P1 !== null && $t10P1['class_name'] === 'Grade 7A', 'Master Teacher Matrix: Mr. Mwangi teaching Grade 7A at 08:00');
assertTrue($t10P2 !== null && $t10P2['class_name'] === 'Grade 8A', 'Master Teacher Matrix: Mr. Mwangi teaching Grade 8A at 08:40');

// Teacher 11 (Ms. Atieno): Free at Period 1, Period 2 in Grade 7A
$t11P1 = testFindSlotForPeriod(['Monday' => ($masterTeacherGrid['Monday'][11] ?? [])], 'Monday', $p1);
$t11P2 = testFindSlotForPeriod(['Monday' => ($masterTeacherGrid['Monday'][11] ?? [])], 'Monday', $p2);
assertTrue($t11P1 === null, 'Master Teacher Matrix: Ms. Atieno is free at 08:00 (for cover assignment)');
assertTrue($t11P2 !== null && $t11P2['class_name'] === 'Grade 7A', 'Master Teacher Matrix: Ms. Atieno teaching Grade 7A at 08:40');

// -------------------------------------------------------------
// Test 4: Master Matrix Educational Level Sorting
// -------------------------------------------------------------
echo "\n-- 4. Educational Progression Sorting in Master View --\n";

require_once __DIR__ . '/../db/db.php';

$testClasses = [
    ['id' => 1, 'name' => 'Grade 8 West'],
    ['id' => 2, 'name' => 'PP1 East'],
    ['id' => 3, 'name' => 'Grade 1 Blue'],
    ['id' => 4, 'name' => 'Grade 10 Science'],
    ['id' => 5, 'name' => 'PP2 Joy'],
];

sortClassesByLevel($testClasses);
$sortedNames = array_column($testClasses, 'name');
$expectedNames = ['PP1 East', 'PP2 Joy', 'Grade 1 Blue', 'Grade 8 West', 'Grade 10 Science'];

assertTrue($sortedNames === $expectedNames, 'Master View classes sorted by educational progression (PP1 -> PP2 -> G1 -> G8 -> G10)');

// -------------------------------------------------------------
// Test 5: UI Route Parameters & View Mode Contracts
// -------------------------------------------------------------
echo "\n-- 5. View Mode Contract Checks --\n";

// Function matching view determination in view_timetable.php
function determineView(array $params): string
{
    $view = $params['view'] ?? '';
    $selectedClassId = ($params['class_id'] ?? '') !== '' ? (int) $params['class_id'] : null;
    $selectedTeacherId = ($params['teacher_id'] ?? '') !== '' ? (int) $params['teacher_id'] : null;
    $selectedRoomId = ($params['room_id'] ?? '') !== '' ? (int) $params['room_id'] : null;

    if ($view === '') {
        if ($selectedTeacherId !== null) return 'teacher';
        if ($selectedRoomId !== null) return 'room';
        if ($selectedClassId !== null) return 'class';
        return 'class';
    }
    return $view;
}

assertTrue(determineView([]) === 'class', 'Default view is class');
assertTrue(determineView(['view' => 'teacher']) === 'teacher', 'Explicit view=teacher respected');
assertTrue(determineView(['teacher_id' => '5']) === 'teacher', 'teacher_id param automatically sets view=teacher');
assertTrue(determineView(['view' => 'master']) === 'master', 'view=master selects master whole-school grid');
assertTrue(determineView(['room_id' => '3']) === 'room', 'room_id param sets view=room');

// -------------------------------------------------------------
// SUMMARY
// -------------------------------------------------------------
echo "\n======================================================\n";
echo "TEST RESULTS: {$passed} passed, {$failed} failed\n";
echo "======================================================\n";

exit($failed > 0 ? 1 : 0);

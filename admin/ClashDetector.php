<?php
declare(strict_types=1);

/**
 * ClashDetector — Pure PHP conflict detection and pre-flight validation engine.
 *
 * Unit-testable without database or FET binary dependencies.
 * Authoritative constraint validator for:
 * 1. Post-generation schedule verification (teacher, class, room, duplicate clashes)
 * 2. Manual adjustments (moves and swaps)
 * 3. Pre-flight workload and capacity checks
 */
class ClashDetector
{
    /**
     * Finds all conflicts in a set of schedule slots.
     *
     * Supports both short keys ('day', 'period', 'teacher_id', 'room_id', 'class_id')
     * and DB column names ('day_of_week', 'hour_slot', etc.).
     *
     * @param array<int, array<string, mixed>> $slots
     * @return array<int, string> Human-readable conflict messages (empty = conflict-free)
     */
    public static function findConflicts(array $slots): array
    {
        $detailed = self::findConflictsDetailed($slots);
        return array_values(array_unique(array_column($detailed, 'message')));
    }

    /**
     * Finds structured conflict records including type and slot references.
     *
     * @param array<int, array<string, mixed>> $slots
     * @return array<int, array{type: string, message: string, day: mixed, period: mixed, slot_a: array, slot_b: array}>
     */
    public static function findConflictsDetailed(array $slots): array
    {
        $conflicts = [];
        $n = count($slots);

        for ($i = 0; $i < $n; $i++) {
            $a = $slots[$i];
            $dayA = self::extractDay($a);
            $periodA = self::extractPeriod($a);

            if ($dayA === null || $periodA === null || $dayA === '' || $periodA === '') {
                continue;
            }

            for ($j = $i + 1; $j < $n; $j++) {
                $b = $slots[$j];
                $dayB = self::extractDay($b);
                $periodB = self::extractPeriod($b);

                // Different time -> no simultaneous conflict
                if ($dayA !== $dayB || $periodA !== $periodB) {
                    continue;
                }

                $teacherA = self::extractId($a, 'teacher_id');
                $teacherB = self::extractId($b, 'teacher_id');
                $classA   = self::extractId($a, 'class_id');
                $classB   = self::extractId($b, 'class_id');
                $roomA    = self::extractId($a, 'room_id');
                $roomB    = self::extractId($b, 'room_id');
                $subjectA = self::extractId($a, 'subject_id');
                $subjectB = self::extractId($b, 'subject_id');
                $slotIdA  = $a['id'] ?? null;
                $slotIdB  = $b['id'] ?? null;

                // 1. Duplicate slot detection
                // Exact same slot ID, or identical class, teacher, subject, room at same time
                $isExactDuplicate = ($slotIdA !== null && $slotIdB !== null && $slotIdA === $slotIdB)
                    || ($classA !== null && $classA === $classB && $teacherA !== null && $teacherA === $teacherB
                        && $subjectA !== null && $subjectA === $subjectB && $roomA === $roomB);

                if ($isExactDuplicate) {
                    $conflicts[] = [
                        'type' => 'duplicate',
                        'message' => sprintf(
                            'Duplicate timetable slot detected for Class %s on %s period %s',
                            $classA ?? 'Unknown',
                            $dayA,
                            $periodA
                        ),
                        'day' => $dayA,
                        'period' => $periodA,
                        'slot_a' => $a,
                        'slot_b' => $b,
                    ];
                    continue;
                }

                // 2. Teacher conflict: One teacher cannot occupy two classes/rooms simultaneously
                if ($teacherA !== null && $teacherA === $teacherB) {
                    $teacherName = $a['teacher_name'] ?? $b['teacher_name'] ?? $teacherA;
                    $classDescA = $a['class_name'] ?? $classA ?? 'Class';
                    $classDescB = $b['class_name'] ?? $classB ?? 'Class';
                    $conflicts[] = [
                        'type' => 'teacher',
                        'message' => sprintf(
                            'Teacher %s double-booked on %s period %s (assigned to %s and %s)',
                            $teacherName,
                            $dayA,
                            $periodA,
                            $classDescA,
                            $classDescB
                        ),
                        'day' => $dayA,
                        'period' => $periodA,
                        'slot_a' => $a,
                        'slot_b' => $b,
                    ];
                }

                // 3. Class conflict: One class cannot have two lessons simultaneously
                if ($classA !== null && $classA === $classB) {
                    $className = $a['class_name'] ?? $b['class_name'] ?? $classA;
                    $subjA = $a['subject_name'] ?? $subjectA ?? 'Lesson 1';
                    $subjB = $b['subject_name'] ?? $subjectB ?? 'Lesson 2';
                    $conflicts[] = [
                        'type' => 'class',
                        'message' => sprintf(
                            'Class %s double-booked on %s period %s (concurrent lessons: %s and %s)',
                            $className,
                            $dayA,
                            $periodA,
                            $subjA,
                            $subjB
                        ),
                        'day' => $dayA,
                        'period' => $periodA,
                        'slot_a' => $a,
                        'slot_b' => $b,
                    ];
                }

                // 4. Room conflict: One room cannot host multiple lessons simultaneously
                // Note: null room_id means no specific room assigned (e.g. playground, outdoors)
                if ($roomA !== null && $roomA !== '' && $roomA === $roomB) {
                    $roomName = $a['room_name'] ?? $b['room_name'] ?? $roomA;
                    $conflicts[] = [
                        'type' => 'room',
                        'message' => sprintf(
                            'Room %s double-booked on %s period %s',
                            $roomName,
                            $dayA,
                            $periodA
                        ),
                        'day' => $dayA,
                        'period' => $periodA,
                        'slot_a' => $a,
                        'slot_b' => $b,
                    ];
                }
            }
        }

        return $conflicts;
    }

    /**
     * Checks if a schedule is completely free of clashes.
     */
    public static function isScheduleValid(array $slots): bool
    {
        return empty(self::findConflicts($slots));
    }

    /**
     * Validates a proposed manual slot move without modifying the input array.
     * Returns an array of clash messages; empty array means the move is safe.
     *
     * @param array<int, array<string, mixed>> $currentSlots
     * @param int|string $slotIdentifier Slot ID (or array index) being moved
     * @param string $targetDay
     * @param string $targetPeriod
     * @param int|null $targetRoomId Optional room change during move
     * @return array<int, string>
     */
    public static function validateManualMove(
        array $currentSlots,
        mixed $slotIdentifier,
        string $targetDay,
        string $targetPeriod,
        mixed $targetRoomId = null
    ): array {
        $simulated = [];
        $found = false;

        foreach ($currentSlots as $idx => $slot) {
            $isTarget = false;
            if (isset($slot['id']) && (string) $slot['id'] === (string) $slotIdentifier) {
                $isTarget = true;
            } elseif (!isset($slot['id']) && (string) $idx === (string) $slotIdentifier) {
                $isTarget = true;
            }

            if ($isTarget) {
                $found = true;
                $movedSlot = $slot;
                $movedSlot['day'] = $targetDay;
                $movedSlot['day_of_week'] = $targetDay;
                $movedSlot['period'] = $targetPeriod;
                $movedSlot['hour_slot'] = $targetPeriod;
                if ($targetRoomId !== null) {
                    $movedSlot['room_id'] = $targetRoomId;
                }
                $simulated[] = $movedSlot;
            } else {
                $simulated[] = $slot;
            }
        }

        if (!$found) {
            return ["Slot identifier '{$slotIdentifier}' not found in schedule."];
        }

        return self::findConflicts($simulated);
    }

    /**
     * Validates a proposed manual swap between two slots.
     * Returns an array of clash messages; empty array means the swap is safe.
     *
     * @param array<int, array<string, mixed>> $currentSlots
     * @param int|string $slot1Id
     * @param int|string $slot2Id
     * @return array<int, string>
     */
    public static function validateManualSwap(
        array $currentSlots,
        mixed $slot1Id,
        mixed $slot2Id
    ): array {
        $s1Key = null;
        $s2Key = null;

        foreach ($currentSlots as $idx => $slot) {
            $id = $slot['id'] ?? $idx;
            if ((string) $id === (string) $slot1Id) {
                $s1Key = $idx;
            }
            if ((string) $id === (string) $slot2Id) {
                $s2Key = $idx;
            }
        }

        if ($s1Key === null || $s2Key === null) {
            return ['One or both slots to swap were not found.'];
        }

        $s1 = $currentSlots[$s1Key];
        $s2 = $currentSlots[$s2Key];

        $day1 = self::extractDay($s1);
        $period1 = self::extractPeriod($s1);
        $day2 = self::extractDay($s2);
        $period2 = self::extractPeriod($s2);

        $simulated = $currentSlots;
        $simulated[$s1Key]['day'] = $day2;
        $simulated[$s1Key]['day_of_week'] = $day2;
        $simulated[$s1Key]['period'] = $period2;
        $simulated[$s1Key]['hour_slot'] = $period2;

        $simulated[$s2Key]['day'] = $day1;
        $simulated[$s2Key]['day_of_week'] = $day1;
        $simulated[$s2Key]['period'] = $period1;
        $simulated[$s2Key]['hour_slot'] = $period1;

        return self::findConflicts($simulated);
    }

    /**
     * Pre-check: teacher assigned lessons must not exceed max load.
     */
    public static function teacherLoadValid(int $assignedLessons, int $maxLoad): bool
    {
        if ($maxLoad < 0 || $assignedLessons < 0) {
            return false;
        }
        return $assignedLessons <= $maxLoad;
    }

    /**
     * Pre-check: class weekly lessons must fit in band teaching slots.
     */
    public static function classLoadValid(int $requiredLessons, int $bandSlotsPerWeek): bool
    {
        if ($bandSlotsPerWeek <= 0 || $requiredLessons < 0) {
            return false;
        }
        return $requiredLessons <= $bandSlotsPerWeek;
    }

    /**
     * Pure-PHP preflight validator for school configuration.
     * Checks workload limits, unassigned subjects, teacher capacity deficit, and room starvation.
     *
     * @param array<int, array<string, mixed>> $classes
     * @param array<int, array<string, mixed>> $teachers
     * @param array<int, array<string, mixed>> $rooms
     * @param int $daysPerWeek
     * @return array<int, string> Problem descriptions (empty = ready for generation)
     */
    public static function validatePreflightConfig(
        array $classes,
        array $teachers,
        array $rooms,
        int $daysPerWeek = 5
    ): array {
        $problems = [];
        $activeClasses = array_values(array_filter($classes, static fn($c) => (bool) ($c['active'] ?? true)));
        $classCount = count($activeClasses);
        $roomCount = count($rooms);
        $teacherCount = count($teachers);

        // 1. Room availability
        if ($classCount > 0 && $roomCount === 0) {
            $problems[] = "No rooms are defined. Add at least one room before generation (currently {$classCount} active classes).";
        } elseif ($classCount > $roomCount && $roomCount > 0) {
            $problems[] = "Insufficient rooms: {$classCount} active classes but only {$roomCount} room(s) available.";
        }

        // 2. Teacher headcount
        if ($classCount > 0 && $teacherCount < $classCount) {
            $problems[] = "Teacher shortage: {$classCount} active classes but only {$teacherCount} teacher(s) configured.";
        }

        // 3. Class workload vs band capacity & unassigned teachers
        $totalRequiredLessons = 0;
        foreach ($activeClasses as $class) {
            $className = $class['name'] ?? 'Class';
            $lessonsPerDay = (int) ($class['lessons_per_day'] ?? 8);
            $maxBandSlots = $lessonsPerDay * max(1, $daysPerWeek);
            $subjects = $class['subjects'] ?? [];

            $classTotal = 0;
            foreach ($subjects as $s) {
                $lpw = (int) ($s['lessons_per_week'] ?? 0);
                $dur = max(1, (int) ($s['duration_slots'] ?? 1));
                $slotsNeeded = $lpw * $dur;
                $classTotal += $slotsNeeded;

                if (empty($s['teacher_id']) && empty($s['assigned_teacher_id'])) {
                    $problems[] = "Subject '{$s['name']}' in '{$className}' has no teacher assigned.";
                }
            }

            $totalRequiredLessons += $classTotal;

            if ($classTotal > $maxBandSlots) {
                $problems[] = "Class '{$className}' requires {$classTotal} lessons/week, exceeding band capacity of {$maxBandSlots} ({$lessonsPerDay}/day × {$daysPerWeek} days).";
            }
        }

        // 4. Teacher capacity check
        $totalTeacherCap = 0;
        $hasUncappedTeachers = false;

        foreach ($teachers as $teacher) {
            $tName = $teacher['name'] ?? 'Teacher';
            $maxLoad = $teacher['max_lessons_per_week'] ?? null;
            $assignedLoad = (int) ($teacher['assigned_lessons'] ?? 0);

            if ($maxLoad !== null) {
                $maxLoad = (int) $maxLoad;
                $totalTeacherCap += $maxLoad;
                if ($assignedLoad > $maxLoad) {
                    $over = $assignedLoad - $maxLoad;
                    $problems[] = "Teacher '{$tName}' is assigned {$assignedLoad} lessons/week, exceeding maximum load of {$maxLoad} by {$over}.";
                }
            } else {
                $hasUncappedTeachers = true;
            }
        }

        if (!$hasUncappedTeachers && $totalTeacherCap > 0 && $totalRequiredLessons > $totalTeacherCap) {
            $problems[] = "Total teacher capacity ({$totalTeacherCap} lessons/week) is less than total required school lessons ({$totalRequiredLessons}).";
        }

        return $problems;
    }

    // --- Private Extraction Helpers ---

    private static function extractDay(array $slot): ?string
    {
        $day = $slot['day'] ?? $slot['day_of_week'] ?? null;
        return $day !== null ? (string) $day : null;
    }

    private static function extractPeriod(array $slot): ?string
    {
        $period = $slot['period'] ?? $slot['hour_slot'] ?? $slot['hour'] ?? null;
        return $period !== null ? (string) $period : null;
    }

    private static function extractId(array $slot, string $key): ?string
    {
        if (!isset($slot[$key])) {
            return null;
        }
        $val = $slot[$key];
        if ($val === null || $val === '') {
            return null;
        }
        return (string) $val;
    }
}

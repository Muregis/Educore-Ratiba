<?php
/**
 * Pure PHP clash detection helpers — unit-testable without DB or FET binary.
 * Mirrors the rules Ratiba enforces on manual edits and generation pre-checks.
 */
class ClashDetector
{
    /**
     * @param array $slots list of ['teacher_id','room_id','class_id','day','period']
     * @return array list of human-readable conflict messages
     */
    public static function findConflicts(array $slots): array
    {
        $conflicts = [];
        $n = count($slots);

        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $a = $slots[$i];
                $b = $slots[$j];

                if (($a['day'] ?? null) !== ($b['day'] ?? null)) {
                    continue;
                }
                if (($a['period'] ?? null) !== ($b['period'] ?? null)) {
                    continue;
                }

                if (!empty($a['teacher_id']) && $a['teacher_id'] === ($b['teacher_id'] ?? null)) {
                    $conflicts[] = sprintf(
                        'Teacher %s double-booked on day %s period %s',
                        $a['teacher_id'],
                        $a['day'],
                        $a['period']
                    );
                }
                if (!empty($a['room_id']) && $a['room_id'] === ($b['room_id'] ?? null)) {
                    $conflicts[] = sprintf(
                        'Room %s double-booked on day %s period %s',
                        $a['room_id'],
                        $a['day'],
                        $a['period']
                    );
                }
                if (!empty($a['class_id']) && $a['class_id'] === ($b['class_id'] ?? null)) {
                    $conflicts[] = sprintf(
                        'Class %s double-booked on day %s period %s',
                        $a['class_id'],
                        $a['day'],
                        $a['period']
                    );
                }
            }
        }

        return $conflicts;
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
}

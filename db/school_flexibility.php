<?php
declare(strict_types=1);

function getSchoolRow(int $schoolId): ?array
{
    static $cache = [];
    if (isset($cache[$schoolId])) {
        return $cache[$schoolId];
    }
    $stmt = db()->prepare('SELECT * FROM schools WHERE id = ?');
    $stmt->execute([$schoolId]);
    $row = $stmt->fetch() ?: null;
    $cache[$schoolId] = $row;
    return $row;
}

/** @return list<string> */
function getSchoolDayNames(int $schoolId): array
{
    $defaults = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
    $school = getSchoolRow($schoolId);
    if (!$school) {
        return $defaults;
    }

    $raw = $school['day_names'] ?? null;
    if (is_string($raw) && $raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded) && count($decoded) >= 1) {
            return array_values(array_map('strval', $decoded));
        }
    }
    if (is_array($raw) && count($raw) >= 1) {
        return array_values(array_map('strval', $raw));
    }

    $n = (int) ($school['days_per_week'] ?? 5);
    if ($n >= 6) {
        return ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
    }
    return $defaults;
}

function schoolPrefersSpread(int $schoolId): bool
{
    $school = getSchoolRow($schoolId);
    if (!$school) {
        return true;
    }
    $v = $school['prefer_spread'] ?? true;
    if (is_bool($v)) {
        return $v;
    }
    return (string) $v === '1' || (string) $v === 't' || (string) $v === 'true';
}

function getSchoolGenerationTimeLimit(int $schoolId): int
{
    $school = getSchoolRow($schoolId);
    $limit = (int) ($school['generation_time_limit'] ?? 300);
    return max(60, min(1800, $limit > 0 ? $limit : 300));
}

/**
 * Readiness score for scale: helps small and large schools see gaps.
 * @return array{score:int,level:string,scale:string,checks:list<array{ok:bool,label:string}>,stats:array}
 */
function getSchoolReadiness(int $schoolId): array
{
    $checks = [];
    $pdo = db();

    $count = static function (string $sql, array $params = []) use ($pdo): int {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    };

    $teachers = $count('SELECT COUNT(*) FROM teachers WHERE school_id = ?', [$schoolId]);
    $rooms = $count('SELECT COUNT(*) FROM rooms WHERE school_id = ?', [$schoolId]);
    $bands = $count('SELECT COUNT(*) FROM bands WHERE school_id = ? AND active = TRUE', [$schoolId]);
    $classes = $count('SELECT COUNT(*) FROM classes WHERE school_id = ? AND active = TRUE', [$schoolId]);
    $subjects = $count('SELECT COUNT(*) FROM subjects WHERE school_id = ?', [$schoolId]);
    $assigned = $count(
        'SELECT COUNT(*) FROM subjects WHERE school_id = ? AND assigned_teacher_id IS NOT NULL',
        [$schoolId]
    );
    $unassigned = $subjects - $assigned;

    $checks[] = ['ok' => $teachers > 0, 'label' => "Teachers registered ($teachers)"];
    $checks[] = ['ok' => $rooms > 0, 'label' => "Rooms available ($rooms)"];
    $checks[] = ['ok' => $bands > 0, 'label' => "Active grade bands ($bands)"];
    $checks[] = ['ok' => $classes > 0, 'label' => "Active classes ($classes)"];
    $checks[] = ['ok' => $subjects > 0, 'label' => "Subjects defined ($subjects)"];
    $checks[] = ['ok' => $unassigned === 0 && $subjects > 0, 'label' => $unassigned === 0
        ? 'All subjects have teachers'
        : "$unassigned subject(s) missing a teacher"];

    $scale = $classes <= 8 ? 'small' : ($classes <= 30 ? 'medium' : 'large');
    if ($scale === 'large') {
        $checks[] = ['ok' => $rooms >= (int) ceil($classes * 0.5), 'label' => 'Room capacity vs class count (large school)'];
        $checks[] = ['ok' => $teachers >= (int) ceil($classes * 1.2), 'label' => 'Teacher headcount vs class count (large school)'];
    }

    $okCount = count(array_filter($checks, static fn($c) => $c['ok']));
    $score = (int) round(100 * $okCount / max(1, count($checks)));
    $level = $score >= 90 ? 'ready' : ($score >= 60 ? 'almost' : 'setup');

    return [
        'score' => $score,
        'level' => $level,
        'scale' => $scale,
        'checks' => $checks,
        'stats' => compact('teachers', 'rooms', 'bands', 'classes', 'subjects', 'unassigned'),
    ];
}

/**
 * Extra preflight: rooms vs classes and aggregate teacher capacity.
 * @return list<string>
 */
function runRoomTeacherPreflight(int $schoolId): array
{
    $problems = [];
    $classes = array_values(array_filter(getClasses($schoolId), static fn($c) => (bool) $c['active']));
    $rooms = getRooms($schoolId);
    $roomCount = count($rooms);
    $classCount = count($classes);

    if ($classCount > 0 && $roomCount === 0) {
        $problems[] = "No rooms are defined. Add at least one room on the Rooms page "
            . "(ideally one room per active class, currently {$classCount}).";
    } elseif ($classCount > $roomCount && $roomCount > 0) {
        $problems[] = "You have {$classCount} active classes but only {$roomCount} room(s). "
            . "At any period, only {$roomCount} classes can have a lesson at the same time. "
            . "Add more rooms (one per class is safest), or deactivate some classes. "
            . "Use Prepare school for generation below to auto-add placeholder classrooms.";
    }

    $teachers = getTeachers($schoolId);
    $totalLoad = 0;
    $totalCap = 0;
    $uncapped = 0;
    foreach ($teachers as $teacher) {
        $load = getTeacherTotalWeeklyLessons($schoolId, (int) $teacher['id']);
        $totalLoad += $load;
        if ($teacher['max_lessons_per_week'] === null) {
            $uncapped++;
        } else {
            $totalCap += (int) $teacher['max_lessons_per_week'];
        }
    }
    if ($uncapped === 0 && $totalCap > 0 && $totalLoad > $totalCap) {
        $problems[] = "Total teacher capacity is {$totalCap} lessons/week but subjects assign {$totalLoad}. "
            . "Raise max lessons on Teachers, add teachers, or reduce subject lessons/week.";
    }

    return $problems;
}

/**
 * Make a tight school more likely to generate: ensure enough rooms and
 * raise teacher max_lessons when load exceeds the stated maximum.
 * @return list<string>
 */
function prepareSchoolForGeneration(int $schoolId): array
{
    $actions = [];
    $pdo = db();

    $classes = array_values(array_filter(getClasses($schoolId), static fn($c) => (bool) $c['active']));
    $rooms = getRooms($schoolId);
    $need = count($classes) - count($rooms);
    if ($need > 0) {
        $existingNames = array_map(static fn($r) => (string) $r['name'], $rooms);
        $created = 0;
        $n = 1;
        while ($created < $need) {
            $name = 'Classroom ' . $n;
            $n++;
            if (in_array($name, $existingNames, true)) {
                continue;
            }
            $stmt = $pdo->prepare(
                'INSERT INTO rooms (school_id, name, capacity, room_type) VALUES (?, ?, ?, ?)'
            );
            $stmt->execute([$schoolId, $name, 40, 'classroom']);
            $existingNames[] = $name;
            $created++;
        }
        $actions[] = "Added {$created} placeholder classroom(s) so rooms ≥ active classes ({$need} were missing).";
    }

    $teachers = getTeachers($schoolId);
    $raised = 0;
    foreach ($teachers as $teacher) {
        $load = getTeacherTotalWeeklyLessons($schoolId, (int) $teacher['id']);
        if ($load <= 0) {
            continue;
        }
        $max = $teacher['max_lessons_per_week'];
        $target = max($load + 5, 40);
        if ($max === null || (int) $max < $load) {
            $stmt = $pdo->prepare(
                'UPDATE teachers SET max_lessons_per_week = ? WHERE id = ? AND school_id = ?'
            );
            $stmt->execute([$target, (int) $teacher['id'], $schoolId]);
            $raised++;
        }
    }
    if ($raised > 0) {
        $actions[] = "Raised max lessons/week for {$raised} teacher(s) so assigned load fits under their cap.";
    }

    if (empty($actions)) {
        $actions[] = 'No changes needed — rooms and teacher caps already look sufficient. If Generate still fails, reduce subject lessons/week or add teachers.';
    }

    return $actions;
}

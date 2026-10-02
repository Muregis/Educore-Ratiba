<?php
declare(strict_types=1);

/**
 * Idempotent schema upgrade for flexible timetable columns.
 * Runs on Prepare/Generate so production does not depend on manual SQL.
 */
function ensureFlexibleSchema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $pdo = db();
    $driver = strtolower((string) ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) ?: ''));

    $pgAlters = [
        "ALTER TABLE schools ADD COLUMN IF NOT EXISTS school_type VARCHAR(40) DEFAULT 'secondary'",
        "ALTER TABLE schools ADD COLUMN IF NOT EXISTS days_per_week INT NOT NULL DEFAULT 5",
        "ALTER TABLE schools ADD COLUMN IF NOT EXISTS day_names JSONB DEFAULT '[\"Monday\",\"Tuesday\",\"Wednesday\",\"Thursday\",\"Friday\"]'::jsonb",
        "ALTER TABLE schools ADD COLUMN IF NOT EXISTS generation_time_limit INT NOT NULL DEFAULT 300",
        "ALTER TABLE schools ADD COLUMN IF NOT EXISTS prefer_spread BOOLEAN NOT NULL DEFAULT TRUE",
        "ALTER TABLE schools ADD COLUMN IF NOT EXISTS settings_json JSONB DEFAULT '{}'::jsonb",
        "ALTER TABLE bands ADD COLUMN IF NOT EXISTS day_start_time VARCHAR(8) DEFAULT '08:00'",
        "ALTER TABLE subjects ADD COLUMN IF NOT EXISTS duration_slots INT NOT NULL DEFAULT 1",
        "ALTER TABLE subjects ADD COLUMN IF NOT EXISTS min_days_between INT NOT NULL DEFAULT 0",
        "ALTER TABLE subjects ADD COLUMN IF NOT EXISTS requires_room_type VARCHAR(50)",
        "ALTER TABLE teachers ADD COLUMN IF NOT EXISTS unavailable_json JSONB DEFAULT '[]'::jsonb",
        "ALTER TABLE teachers ADD COLUMN IF NOT EXISTS preferred_max_daily INT",
        "ALTER TABLE generated_timetables ADD COLUMN IF NOT EXISTS failure_summary JSONB",
        "CREATE INDEX IF NOT EXISTS idx_subjects_school_class ON subjects (school_id, class_id)",
        "CREATE INDEX IF NOT EXISTS idx_classes_school_active ON classes (school_id, active)",
        "CREATE INDEX IF NOT EXISTS idx_teachers_school ON teachers (school_id)",
    ];

    if ($driver === 'pgsql') {
        foreach ($pgAlters as $sql) {
            try {
                $pdo->exec($sql);
            } catch (Throwable $e) {
                error_log('ensureFlexibleSchema: ' . $e->getMessage());
            }
        }
        return;
    }

    $mysqlCols = [
        ['schools', 'school_type', "VARCHAR(40) DEFAULT 'secondary'"],
        ['schools', 'days_per_week', 'INT NOT NULL DEFAULT 5'],
        ['schools', 'day_names', 'JSON NULL'],
        ['schools', 'generation_time_limit', 'INT NOT NULL DEFAULT 300'],
        ['schools', 'prefer_spread', 'TINYINT(1) NOT NULL DEFAULT 1'],
        ['schools', 'settings_json', 'JSON NULL'],
        ['bands', 'day_start_time', "VARCHAR(8) DEFAULT '08:00'"],
        ['subjects', 'duration_slots', 'INT NOT NULL DEFAULT 1'],
        ['subjects', 'min_days_between', 'INT NOT NULL DEFAULT 0'],
        ['subjects', 'requires_room_type', 'VARCHAR(50) NULL'],
        ['teachers', 'unavailable_json', 'JSON NULL'],
        ['teachers', 'preferred_max_daily', 'INT NULL'],
        ['generated_timetables', 'failure_summary', 'JSON NULL'],
    ];
    foreach ($mysqlCols as [$table, $col, $type]) {
        try {
            $check = $pdo->prepare(
                'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
            );
            $check->execute([$table, $col]);
            if (!(int) $check->fetchColumn()) {
                $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$col}` {$type}");
            }
        } catch (Throwable $e) {
            error_log("ensureFlexibleSchema {$table}.{$col}: " . $e->getMessage());
        }
    }
}

function getSchoolRow(int $schoolId): ?array
{
    $stmt = db()->prepare('SELECT * FROM schools WHERE id = ?');
    $stmt->execute([$schoolId]);
    return $stmt->fetch() ?: null;
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

function countSlotsForLatestSuccess(int $schoolId): int
{
    $pdo = db();
    $stmt = $pdo->prepare(
        "SELECT gt.id FROM generated_timetables gt
         WHERE gt.school_id = ? AND gt.status = 'success'
         ORDER BY gt.generated_at DESC LIMIT 20"
    );
    $stmt->execute([$schoolId]);
    $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
    foreach ($ids as $id) {
        $c = $pdo->prepare('SELECT COUNT(*) FROM scheduled_slots WHERE generated_timetable_id = ?');
        $c->execute([(int) $id]);
        $n = (int) $c->fetchColumn();
        if ($n > 0) {
            return $n;
        }
    }
    return 0;
}

function getLatestUsableTimetable(int $schoolId): ?array
{
    $pdo = db();
    $stmt = $pdo->prepare(
        "SELECT gt.* FROM generated_timetables gt
         WHERE gt.school_id = ? AND gt.status = 'success'
         ORDER BY gt.generated_at DESC LIMIT 20"
    );
    $stmt->execute([$schoolId]);
    foreach ($stmt->fetchAll() as $row) {
        $c = $pdo->prepare('SELECT COUNT(*) FROM scheduled_slots WHERE generated_timetable_id = ?');
        $c->execute([(int) $row['id']]);
        if ((int) $c->fetchColumn() > 0) {
            return $row;
        }
    }
    return null;
}

function getSchoolReadiness(int $schoolId): array
{
    $checks = [];
    $blockers = [];
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
    $slotCount = countSlotsForLatestSuccess($schoolId);

    $checks[] = ['ok' => $teachers > 0, 'label' => "Teachers registered ($teachers)"];
    $checks[] = ['ok' => $rooms > 0, 'label' => "Rooms available ($rooms)"];
    $checks[] = ['ok' => $bands > 0, 'label' => "Active grade bands ($bands)"];
    $checks[] = ['ok' => $classes > 0, 'label' => "Active classes ($classes)"];
    $checks[] = ['ok' => $subjects > 0, 'label' => "Subjects defined ($subjects)"];
    $checks[] = ['ok' => $unassigned === 0 && $subjects > 0, 'label' => $unassigned === 0
        ? 'All subjects have teachers'
        : "$unassigned subject(s) missing a teacher"];

    if ($classes > 0 && $teachers < $classes) {
        $checks[] = [
            'ok' => false,
            'label' => "Teacher headcount ($teachers) is below active classes ($classes) — same-period clashes likely",
        ];
        $blockers[] = "You have {$classes} classes but only {$teachers} teachers. "
            . "At one period only {$teachers} classes can be taught. Add teachers or use Rebalance.";
    } else {
        $checks[] = [
            'ok' => true,
            'label' => "Teacher headcount vs classes ($teachers teachers / $classes classes)",
        ];
    }

    if ($classes > 0 && $rooms < $classes) {
        $checks[] = [
            'ok' => false,
            'label' => "Rooms ($rooms) below classes ($classes)",
        ];
        $blockers[] = "Need at least {$classes} rooms (one per class is safest). Use Prepare to auto-add classrooms.";
    } else {
        $checks[] = [
            'ok' => $rooms >= $classes || $classes === 0,
            'label' => "Rooms vs classes ($rooms rooms / $classes classes)",
        ];
    }

    $teacherRows = getTeachers($schoolId);
    $idle = 0;
    $atCap = 0;
    foreach ($teacherRows as $t) {
        $load = getTeacherTotalWeeklyLessons($schoolId, (int) $t['id']);
        $max = $t['max_lessons_per_week'];
        if ($load === 0) {
            $idle++;
        }
        if ($max !== null && (int) $max > 0 && $load >= (int) $max) {
            $atCap++;
        }
    }
    if ($idle > 0 && $atCap > 0) {
        $checks[] = [
            'ok' => false,
            'label' => "Load imbalance: {$atCap} teacher(s) at max cap, {$idle} with zero lessons",
        ];
        $blockers[] = "Rebalance subject assignments so idle teachers share the load.";
    } else {
        $checks[] = [
            'ok' => true,
            'label' => $idle > 0
                ? "Teacher load distribution ({$idle} without subjects — optional)"
                : 'Teacher load distribution looks balanced',
        ];
    }

    $scale = $classes <= 8 ? 'small' : ($classes <= 30 ? 'medium' : 'large');
    $okCount = count(array_filter($checks, static fn($c) => $c['ok']));
    $score = (int) round(100 * $okCount / max(1, count($checks)));
    if (!empty($blockers) && $score >= 90) {
        $score = 70;
    }
    $level = $score >= 90 ? 'ready' : ($score >= 60 ? 'almost' : 'setup');

    return [
        'score' => $score,
        'level' => $level,
        'scale' => $scale,
        'checks' => $checks,
        'blockers' => $blockers,
        'stats' => compact('teachers', 'rooms', 'bands', 'classes', 'subjects', 'unassigned', 'slotCount'),
    ];
}

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
            . "Use Prepare / Rebalance to auto-add placeholder classrooms.";
    }

    $teachers = getTeachers($schoolId);
    $teacherCount = count($teachers);
    if ($classCount > 0 && $teacherCount < $classCount) {
        $problems[] = "You have {$classCount} active classes but only {$teacherCount} teacher(s). "
            . "Primary-style schools need roughly one teacher per class (or shared specialists with care). "
            . "Use Rebalance school for generation to add teachers and assign subjects fairly.";
    }

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

function demoTeacherNamePool(): array
{
    return [
        'Alice Wanjiku', 'Brian Otieno', 'Cynthia Achieng', 'David Kamau',
        'Esther Njeri', 'Francis Mwangi', 'Grace Akinyi', 'Henry Omondi',
        'Irene Chebet', 'James Kiprop', 'Karen Wambui', 'Luke Odhiambo',
        'Mary Atieno', 'Nathan Kiptoo', 'Olivia Nyambura', 'Peter Mutua',
        'Queen Auma', 'Robert Cheruiyot', 'Sarah Muthoni', 'Thomas Njoroge',
        'Ursula Jelagat', 'Victor Ochieng', 'Winnie Achieng', 'Xavier Kimani',
    ];
}

function rebalanceSchoolForSolvability(int $schoolId): array
{
    ensureFlexibleSchema();
    $actions = [];
    $pdo = db();

    $classes = array_values(array_filter(getClasses($schoolId), static fn($c) => (bool) $c['active']));
    $classCount = count($classes);
    if ($classCount === 0) {
        return ['No active classes — add classes first.'];
    }

    $rooms = getRooms($schoolId);
    $needRooms = $classCount - count($rooms);
    if ($needRooms > 0) {
        $existingNames = array_map(static fn($r) => (string) $r['name'], $rooms);
        $created = 0;
        $n = 1;
        while ($created < $needRooms) {
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
        $actions[] = "Added {$created} classroom(s) so rooms ≥ classes.";
    }

    $teachers = getTeachers($schoolId);
    $needTeachers = $classCount - count($teachers);
    if ($needTeachers > 0) {
        $pool = demoTeacherNamePool();
        $existingNames = array_map(static fn($t) => (string) $t['name'], $teachers);
        $created = 0;
        $i = 0;
        while ($created < $needTeachers) {
            $name = $pool[$i % count($pool)] ?? ('Teacher ' . ($i + 1));
            if ($i >= count($pool)) {
                $name = 'Teacher ' . ($i + 1);
            }
            $candidate = $name;
            $suffix = 2;
            while (in_array($candidate, $existingNames, true)) {
                $candidate = $name . ' ' . $suffix;
                $suffix++;
            }
            $staffId = 'T-' . str_pad((string) (count($existingNames) + 1), 3, '0', STR_PAD_LEFT);
            $stmt = $pdo->prepare(
                'INSERT INTO teachers (school_id, name, staff_id, max_lessons_per_week) VALUES (?, ?, ?, ?)'
            );
            $stmt->execute([$schoolId, $candidate, $staffId, 35]);
            $existingNames[] = $candidate;
            $created++;
            $i++;
        }
        $actions[] = "Added {$created} teacher(s) so headcount ≥ active classes (class-teacher model).";
        $teachers = getTeachers($schoolId);
    }

    usort($classes, static fn($a, $b) => strcmp((string) $a['name'], (string) $b['name']));
    usort($teachers, static fn($a, $b) => strcmp((string) $a['name'], (string) $b['name']));
    $assignCount = 0;
    foreach ($classes as $idx => $class) {
        $teacher = $teachers[$idx % count($teachers)];
        $tid = (int) $teacher['id'];
        $stmt = $pdo->prepare(
            'UPDATE subjects SET assigned_teacher_id = ? WHERE school_id = ? AND class_id = ?'
        );
        $stmt->execute([$tid, $schoolId, (int) $class['id']]);
        $assignCount += $stmt->rowCount();
    }
    $actions[] = "Assigned subjects with class-teacher model ({$assignCount} subject row(s) updated).";

    // Empty-class fix: clone peer subjects so demo classes like Grade 3 Red are schedulable
    foreach ($classes as $class) {
        $cid = (int) $class['id'];
        $cntStmt = $pdo->prepare('SELECT COUNT(*) FROM subjects WHERE school_id = ? AND class_id = ?');
        $cntStmt->execute([$schoolId, $cid]);
        if ((int) $cntStmt->fetchColumn() > 0) {
            continue;
        }
        $peerId = null;
        foreach ($classes as $other) {
            if ((int) $other['id'] === $cid) {
                continue;
            }
            $cntStmt->execute([$schoolId, (int) $other['id']]);
            if ((int) $cntStmt->fetchColumn() > 0) {
                $peerId = (int) $other['id'];
                break;
            }
        }
        if ($peerId === null) {
            continue;
        }
        $src = $pdo->prepare('SELECT name, lessons_per_week, assigned_teacher_id FROM subjects WHERE school_id = ? AND class_id = ?');
        $src->execute([$schoolId, $peerId]);
        $tid = null;
        foreach ($classes as $i => $c) {
            if ((int) $c['id'] === $cid && !empty($teachers)) {
                $tid = (int) $teachers[$i % count($teachers)]['id'];
                break;
            }
        }
        $ins = $pdo->prepare('INSERT INTO subjects (school_id, class_id, name, lessons_per_week, assigned_teacher_id) VALUES (?, ?, ?, ?, ?)');
        $n = 0;
        foreach ($src->fetchAll() as $row) {
            try {
                $ins->execute([$schoolId, $cid, $row['name'], (int) ($row['lessons_per_week'] ?? 3), $tid ?? $row['assigned_teacher_id']]);
                $n++;
            } catch (Throwable $e) {
                error_log('clone subject: ' . $e->getMessage());
            }
        }
        if ($n > 0) {
            $actions[] = 'Cloned subject set onto class "' . (string) $class['name'] . '" (was empty).';
        }
    }

    try {
        $pdo->prepare(
            'UPDATE subjects SET duration_slots = COALESCE(duration_slots, 1), min_days_between = 0 WHERE school_id = ?'
        )->execute([$schoolId]);
        $actions[] = 'Set lesson duration to 1 slot and min-days-between to 0 for solvability.';
    } catch (Throwable $e) {
        error_log('rebalance soft constraints: ' . $e->getMessage());
    }

    $teachers = getTeachers($schoolId);
    $raised = 0;
    foreach ($teachers as $teacher) {
        $load = getTeacherTotalWeeklyLessons($schoolId, (int) $teacher['id']);
        $target = $load > 0 ? min(40, max($load + 5, 25)) : 30;
        $stmt = $pdo->prepare(
            'UPDATE teachers SET max_lessons_per_week = ? WHERE id = ? AND school_id = ?'
        );
        $stmt->execute([$target, (int) $teacher['id'], $schoolId]);
        $raised++;
    }
    if ($raised > 0) {
        $actions[] = "Set realistic max lessons/week for {$raised} teacher(s) (load + headroom, max 40).";
    }

    try {
        $school = getSchoolRow($schoolId);
        $limit = (int) ($school['generation_time_limit'] ?? 300);
        if ($limit > 600 || $limit < 120) {
            $pdo->prepare('UPDATE schools SET generation_time_limit = ? WHERE id = ?')
                ->execute([300, $schoolId]);
            $actions[] = 'Set generator time limit to 300 seconds (balanced for medium schools).';
        }
        $pdo->prepare('UPDATE schools SET prefer_spread = FALSE WHERE id = ?')->execute([$schoolId]);
    } catch (Throwable $e) {
        error_log('rebalance school settings: ' . $e->getMessage());
    }

    return $actions;
}

function prepareSchoolForGeneration(int $schoolId): array
{
    ensureFlexibleSchema();
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
        $actions[] = "Added {$created} placeholder classroom(s) so rooms ≥ active classes.";
    }

    $teachers = getTeachers($schoolId);
    $raised = 0;
    foreach ($teachers as $teacher) {
        $load = getTeacherTotalWeeklyLessons($schoolId, (int) $teacher['id']);
        $max = $teacher['max_lessons_per_week'];
        if ($max === null || (int) $max < $load + 2) {
            $target = min(40, max($load + 5, 25));
            $pdo->prepare('UPDATE teachers SET max_lessons_per_week = ? WHERE id = ? AND school_id = ?')
                ->execute([$target, (int) $teacher['id'], $schoolId]);
            $raised++;
        }
    }
    if ($raised > 0) {
        $actions[] = "Raised max lessons/week for {$raised} teacher(s).";
    }

    if (empty($actions)) {
        $actions[] = 'No changes needed — rooms and teacher caps already look sufficient.';
    }
    return $actions;
}

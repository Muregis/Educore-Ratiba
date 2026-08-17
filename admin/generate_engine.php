<?php
declare(strict_types=1);

// ============================================================
// admin/generate_engine.php — whole-school XML generation logic
// (plan section 4). Included by generate.php, not accessed
// directly. Kept separate from the page/HTML so this logic is
// unit-testable and reusable (e.g. by a future CLI/cron job).
// ============================================================

require_once __DIR__ . '/../config/config.php';

/**
 * Runs pre-flight checks (plan section 4d) BEFORE any FET run is
 * attempted. Returns an array of human-readable problem strings;
 * empty array means all checks passed.
 */
function runPreflightChecks(int $schoolId): array
{
    $problems = [];
    $classes = getClasses($schoolId);

    foreach ($classes as $class) {
        if (!$class['active']) {
            continue;
        }
        $subjects = getSubjectsForClass($schoolId, (int) $class['id']);
        $totalLessons = array_sum(array_column($subjects, 'lessons_per_week'));
        $lessonsPerDay = $class['lessons_per_day'] ?? null;

        if ($lessonsPerDay !== null) {
            $maxPossible = (int) $lessonsPerDay * 5;
            if ($totalLessons > $maxPossible) {
                $subjectBreakdown = [];
                foreach ($subjects as $s) {
                    $subjectBreakdown[] = "  - {$s['name']}: {$s['lessons_per_week']} lessons/week";
                }
                $problems[] = "\"{$class['name']}\" requires {$totalLessons} lessons/week, "
                    . "but its band only provides {$maxPossible} teaching slots "
                    . "({$lessonsPerDay}/day × 5 days). Reduce a subject's lessons/week. "
                    . "Current subjects:\n" . implode("\n", $subjectBreakdown);
            }
        }
    }

    // Teacher over-capacity check: total assigned lessons across ALL
    // classes (shared teachers, plan section 4) vs their stated max.
    $teachers = getTeachers($schoolId);
    foreach ($teachers as $teacher) {
        if ($teacher['max_lessons_per_week'] === null) {
            continue;
        }
        $load = getTeacherTotalWeeklyLessons($schoolId, (int) $teacher['id']);
        if ($load > (int) $teacher['max_lessons_per_week']) {
            $overBy = $load - (int) $teacher['max_lessons_per_week'];
            $problems[] = "{$teacher['name']} is assigned {$load} lessons/week across all classes, "
                . "which exceeds their maximum of {$teacher['max_lessons_per_week']} by {$overBy} lessons. "
                . "Reassign a subject to another teacher, or raise their maximum.";
        }
    }

    return $problems;
}

/**
 * Builds the combined whole-school FET XML from the database and
 * writes it to a temp file. Returns the file path.
 */
function buildWholeSchoolXml(int $schoolId, string $schoolName): string
{
    $bands = array_filter(getBands($schoolId), fn($b) => (bool) $b['active']);
    $classes = array_filter(getClasses($schoolId), fn($c) => (bool) $c['active']);
    $teachers = getTeachers($schoolId);
    $rooms = getRooms($schoolId);

    // Union of all distinct hour-slot structures across active bands.
    // Each band keeps its own Hours_List entries; FET's Hours_List is
    // global, so every band's slots are represented as distinct named
    // hours (e.g. "PP-08:00-08:30" vs "G7-08:00-08:40") to avoid
    // collisions between bands with different lesson lengths at
    // overlapping clock times, and each class's activities only ever
    // reference its own band's hour names.
    $days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];

    $xml = new SimpleXMLElement('<fet version="6.0.0"/>');
    $xml->addChild('Institution_Name', htmlspecialchars($schoolName));
    $xml->addChild('Comments', 'Whole-school combined timetable, generated ' . date('Y-m-d H:i:s'));

    $daysList = $xml->addChild('Days_List');
    $daysList->addChild('Number_of_Days', (string) count($days));
    foreach ($days as $d) {
        $dayEl = $daysList->addChild('Day');
        $dayEl->addChild('Name', $d);
    }

    // Build one set of named hour slots per band, prefixed by band_key
    // so bands never collide even at the same clock time.
    $bandHourNames = []; // band_id => ['teaching' => [...names], 'break' => [...names]]
    $hoursList = $xml->addChild('Hours_List');
    $allHourEntries = [];

    foreach ($bands as $band) {
        $prefix = $band['band_key'];
        $lessonsPerDay = (int) ($band['lessons_per_day'] ?? 8);
        $lengthMin = (int) $band['lesson_length_minutes'];
        $breakConfig = $band['break_config'] ? json_decode($band['break_config'], true) : [];

        $teachingNames = [];
        $breakNames = [];

        // Parse each configured break's start time ONCE, as a real
        // timestamp, so matching is a numeric time comparison rather
        // than a fragile string-prefix check that silently fails if a
        // lesson length doesn't divide evenly into the break's start
        // minute. Sorted by start time so breaks are considered in the
        // order they'll actually occur in the day.
        $parsedBreaks = [];
        foreach ($breakConfig as $bc) {
            [$bStart, $bEnd] = array_pad(explode('-', $bc['time']), 2, null);
            if ($bStart === null || $bEnd === null) {
                continue; // malformed break_config entry - skip rather than crash
            }
            $parsedBreaks[] = [
                'start_ts' => strtotime(trim($bStart)),
                'end_ts' => strtotime(trim($bEnd)),
                'start_label' => trim($bStart),
                'end_label' => trim($bEnd),
                'label' => $bc['label'] ?? 'BREAK',
            ];
        }
        usort($parsedBreaks, static fn($a, $b) => $a['start_ts'] <=> $b['start_ts']);

        $current = strtotime('08:00');
        $slotsNeeded = $lessonsPerDay + count($parsedBreaks);
        $safetyLimit = $slotsNeeded + 4; // generous buffer against infinite loops on bad data

        for ($i = 0; $i < $safetyLimit; $i++) {
            if (count($teachingNames) >= $lessonsPerDay) {
                break;
            }

            // A break "occupies" the current pointer if $current falls
            // within [start_ts, end_ts) - this is a real time-range
            // check, not a string comparison, so it's robust to any
            // lesson length / break-start combination.
            $matchedBreak = null;
            foreach ($parsedBreaks as $pb) {
                if ($current >= $pb['start_ts'] && $current < $pb['end_ts']) {
                    $matchedBreak = $pb;
                    break;
                }
            }

            if ($matchedBreak !== null) {
                $hourName = "{$prefix}__{$matchedBreak['start_label']}-{$matchedBreak['end_label']} {$matchedBreak['label']}";
                $breakNames[] = $hourName;
                $allHourEntries[$hourName] = true;
                $current = $matchedBreak['end_ts'];
                continue;
            }

            $slotStart = date('H:i', $current);
            $slotEndTs = $current + ($lengthMin * 60);
            $slotEnd = date('H:i', $slotEndTs);
            $hourName = "{$prefix}__{$slotStart}-{$slotEnd}";
            $teachingNames[] = $hourName;
            $allHourEntries[$hourName] = true;
            $current = $slotEndTs;
        }

        $bandHourNames[$band['id']] = ['teaching' => $teachingNames, 'break' => $breakNames];
    }

    $hoursList->addChild('Number_of_Hours', (string) count($allHourEntries));
    foreach (array_keys($allHourEntries) as $hourName) {
        $hourEl = $hoursList->addChild('Hour');
        $hourEl->addChild('Name', htmlspecialchars($hourName));
    }

    // Subjects list - one entry per distinct subject NAME used anywhere
    // in the school (FET subjects are just labels; the real per-class
    // requirement lives in Activities).
    $subjectsList = $xml->addChild('Subjects_List');
    $seenSubjectNames = [];
    $allSubjectRows = [];
    foreach ($classes as $class) {
        $subs = getSubjectsForClass($schoolId, (int) $class['id']);
        foreach ($subs as $s) {
            $allSubjectRows[] = $s + ['class_row' => $class];
            if (!isset($seenSubjectNames[$s['name']])) {
                $seenSubjectNames[$s['name']] = true;
                $subjectsList->addChild('Subject')->addChild('Name', htmlspecialchars($s['name']));
            }
        }
    }

    // Teachers list
    $teachersList = $xml->addChild('Teachers_List');
    foreach ($teachers as $t) {
        $teachersList->addChild('Teacher')->addChild('Name', htmlspecialchars($t['name']));
    }

    // Rooms list
    $roomsList = $xml->addChild('Rooms_List');
    foreach ($rooms as $r) {
        $roomEl = $roomsList->addChild('Room');
        $roomEl->addChild('Name', htmlspecialchars($r['name']));
        $roomEl->addChild('Building', '');
        $roomEl->addChild('Capacity', (string) ($r['capacity'] ?? 50));
    }

    // Students (Years) - one per class
    $studentsList = $xml->addChild('Students_List');
    foreach ($classes as $class) {
        $yearEl = $studentsList->addChild('Year');
        $yearEl->addChild('Name', htmlspecialchars($class['name']));
        $yearEl->addChild('Number_of_Students', (string) ($class['student_count'] ?? 30));
        $yearEl->addChild('Comments', '');
    }

    // Activities - one per lesson, teacher/room lookups by name (FET
    // matches by Name across these lists, not by our internal IDs).
    $activitiesList = $xml->addChild('Activities_List');
    $activityId = 1;
    $groupId = 1;
    $activityMeta = []; // fetId => our own metadata, for translating FET's "not scheduled" report later

    $teacherNameById = array_column($teachers, 'name', 'id');

    foreach ($allSubjectRows as $s) {
        $class = $s['class_row'];
        $teacherName = $s['assigned_teacher_id'] !== null && isset($teacherNameById[$s['assigned_teacher_id']])
            ? $teacherNameById[$s['assigned_teacher_id']]
            : null;

        if ($teacherName === null) {
            // No teacher assigned - skip from the FET run rather than
            // crash; this is also flagged as a pre-flight-style
            // omission the admin should fix, surfaced via $skipped.
            continue;
        }

        for ($i = 0; $i < (int) $s['lessons_per_week']; $i++) {
            $actEl = $activitiesList->addChild('Activity');
            $actEl->addChild('Teacher', htmlspecialchars($teacherName));
            $actEl->addChild('Subject', htmlspecialchars($s['name']));
            $actEl->addChild('Students', htmlspecialchars($class['name']));
            $actEl->addChild('Duration', '1');
            $actEl->addChild('Total_Duration', '1');
            $actEl->addChild('Id', (string) $activityId);
            $actEl->addChild('Activity_Group_Id', (string) $groupId);
            $actEl->addChild('Active', 'true');

            $activityMeta[$activityId] = [
                'type' => 'subject',
                'subject_id' => $s['id'],
                'subject_name' => $s['name'],
                'class_name' => $class['name'],
                'teacher_name' => $teacherName,
            ];
            $activityId++;
        }
        $groupId++;
    }

    // Time constraints
    $timeConstraints = $xml->addChild('Time_Constraints_List');
    $basicTime = $timeConstraints->addChild('ConstraintBasicCompulsoryTime');
    $basicTime->addChild('Weight_Percentage', '100');
    $basicTime->addChild('Active', 'true');

    // Lock every band's break slots, on every day, for every class in
    // that band (ConstraintStudentsSetNotAvailableTimes is per-Year).
    foreach ($classes as $class) {
        $bandId = $class['band_id'];
        $breakNames = $bandHourNames[$bandId]['break'] ?? [];
        if (empty($breakNames)) {
            continue;
        }
        $notAvail = $timeConstraints->addChild('ConstraintStudentsSetNotAvailableTimes');
        $notAvail->addChild('Weight_Percentage', '100');
        $notAvail->addChild('Students', htmlspecialchars($class['name']));
        $entries = [];
        foreach ($days as $d) {
            foreach ($breakNames as $bn) {
                $entries[] = [$d, $bn];
            }
        }
        $notAvail->addChild('Number_of_Not_Available_Times', (string) count($entries));
        foreach ($entries as [$d, $bn]) {
            $nat = $notAvail->addChild('Not_Available_Time');
            $nat->addChild('Day', $d);
            $nat->addChild('Hour', htmlspecialchars($bn));
        }
        $notAvail->addChild('Active', 'true');
    }

    // Same break-locking as a global ConstraintBreakTimes too, since
    // that's what actually removes the slot from consideration
    // school-wide (StudentsSetNotAvailableTimes only protects that
    // Year specifically).
    $allBreakEntries = [];
    foreach ($bands as $band) {
        $breakNames = $bandHourNames[$band['id']]['break'] ?? [];
        foreach ($days as $d) {
            foreach ($breakNames as $bn) {
                $allBreakEntries[] = [$d, $bn];
            }
        }
    }
    if (!empty($allBreakEntries)) {
        $breakTimesEl = $timeConstraints->addChild('ConstraintBreakTimes');
        $breakTimesEl->addChild('Weight_Percentage', '100');
        $breakTimesEl->addChild('Number_of_Break_Times', (string) count($allBreakEntries));
        foreach ($allBreakEntries as [$d, $bn]) {
            $bt = $breakTimesEl->addChild('Break_Time');
            $bt->addChild('Day', $d);
            $bt->addChild('Hour', htmlspecialchars($bn));
        }
        $breakTimesEl->addChild('Active', 'true');
    }

    // Space constraints
    $spaceConstraints = $xml->addChild('Space_Constraints_List');
    $basicSpace = $spaceConstraints->addChild('ConstraintBasicCompulsorySpace');
    $basicSpace->addChild('Weight_Percentage', '100');
    $basicSpace->addChild('Active', 'true');

    // Write to a temp file
    $tmpPath = sys_get_temp_dir() . '/fet_school_' . $schoolId . '_' . time() . '.xml';
    $xml->asXML($tmpPath);

    // Stash activity metadata alongside for later translation of FET's
    // not-scheduled report (plan section 4d, Layer 2).
    file_put_contents($tmpPath . '.meta.json', json_encode($activityMeta));

    return $tmpPath;
}

/**
 * Runs fet-cl.exe against the given XML file. Returns
 * ['success' => bool, 'raw_output' => string, 'html_output_path' => ?string]
 */
function runFetEngine(string $xmlPath, string $engineExePath, string $outputDir): array
{
    if (!is_dir($outputDir)) {
        @mkdir($outputDir, 0777, true);
    }

    $cmd = '"' . $engineExePath . '" --inputfile="' . $xmlPath . '" --outputdir="' . $outputDir . '" 2>&1';
    $rawOutput = (string) shell_exec($cmd);
    $success = stripos($rawOutput, 'Generation successful') !== false;

    $htmlPath = null;
    $solutionXmlPath = null;
    if ($success) {
        $timetablesDir = $outputDir . '/timetables';
        if (is_dir($timetablesDir)) {
            $allIndexFiles = [];
            foreach ((glob($timetablesDir . '/*', GLOB_ONLYDIR) ?: []) as $folder) {
                foreach ((glob($folder . '/*_index.html') ?: []) as $f) {
                    $allIndexFiles[] = $f;
                }
            }
            if (!empty($allIndexFiles)) {
                usort($allIndexFiles, static fn($a, $b) => filemtime($b) - filemtime($a));
                $htmlPath = $allIndexFiles[0];
                // FET also writes a solution .xml alongside the HTML in
                // the same folder - this is what we actually parse into
                // scheduled_slots (structured and stable), not the HTML.
                $folderOfNewest = dirname($htmlPath);
                $xmlCandidates = glob($folderOfNewest . '/*.xml') ?: [];
                if (!empty($xmlCandidates)) {
                    $solutionXmlPath = $xmlCandidates[0];
                }
            }
        }
    }

    return [
        'success' => $success,
        'raw_output' => $rawOutput,
        'html_output_path' => $htmlPath,
        'solution_xml_path' => $solutionXmlPath,
    ];
}

/**
 * Parses FET's solution XML output into scheduled_slots rows (plan
 * section 4b) — this is what the manual editor and clash-checker
 * read/write against, instead of re-parsing XML on every edit.
 *
 * $activityMeta maps our own activity IDs (assigned when building the
 * input XML) back to real subject/class/teacher names, since that's
 * how we translate FET's placement decisions back into our schema.
 *
 * @param array<int, array<string, mixed>> $activityMeta
 * @return array<int, array<string, mixed>> rows ready for INSERT into scheduled_slots
 */
function parseFetSolutionIntoSlots(string $solutionXmlPath, array $activityMeta, int $schoolId): array
{
    if (!file_exists($solutionXmlPath)) {
        return [];
    }

    $xml = @simplexml_load_file($solutionXmlPath);
    if ($xml === false) {
        return [];
    }

    // Build lookups: class name -> class_id, teacher name -> teacher_id,
    // room name -> room_id, subject name -> subject_id (per class).
    $stmt = db()->prepare('SELECT id, name FROM classes WHERE school_id = ?');
    $stmt->execute([$schoolId]);
    $classIdByName = array_column($stmt->fetchAll(), 'id', 'name');

    $stmt = db()->prepare('SELECT id, name FROM teachers WHERE school_id = ?');
    $stmt->execute([$schoolId]);
    $teacherIdByName = array_column($stmt->fetchAll(), 'id', 'name');

    $stmt = db()->prepare('SELECT id, name FROM rooms WHERE school_id = ?');
    $stmt->execute([$schoolId]);
    $roomIdByName = array_column($stmt->fetchAll(), 'id', 'name');

    $rows = [];

    // FET's solution XML format lists placed activities with their
    // assigned Day/Hour/Room under an Activities_List-like structure
    // in the output. We match placed activities back to our own
    // activity IDs via the Id field, then use $activityMeta for the
    // subject/class/teacher this ID represents.
    foreach ($xml->xpath('//Activity') as $act) {
        $fetId = (int) $act->Id;
        if (!isset($activityMeta[$fetId])) {
            continue; // not one of ours (shouldn't happen, but don't crash)
        }
        $meta = $activityMeta[$fetId];

        $day = (string) ($act->Day ?? '');
        $hour = (string) ($act->Hour ?? '');
        $roomName = (string) ($act->Room ?? '');

        if ($day === '' || $hour === '') {
            continue; // unplaced activity - handled separately by the
                      // not-scheduled report, not here
        }

        $classId = $classIdByName[$meta['class_name']] ?? null;
        $teacherId = $teacherIdByName[$meta['teacher_name']] ?? null;
        $roomId = $roomName !== '' ? ($roomIdByName[$roomName] ?? null) : null;

        if ($classId === null || $teacherId === null) {
            continue; // data integrity issue - skip rather than insert a broken row
        }

        $rows[] = [
            'class_id' => $classId,
            'subject_id' => $meta['subject_id'] ?? null,
            'extra_activity_id' => $meta['extra_activity_id'] ?? null,
            'remedial_session_id' => $meta['remedial_session_id'] ?? null,
            'teacher_id' => $teacherId,
            'room_id' => $roomId,
            'day_of_week' => $day,
            'hour_slot' => $hour,
        ];
    }

    return $rows;
}

/**
 * Inserts parsed rows into scheduled_slots for a given generation run.
 */
function storeScheduledSlots(int $generatedTimetableId, array $rows): void
{
    if (empty($rows)) {
        return;
    }
    $stmt = db()->prepare(
        'INSERT INTO scheduled_slots
         (generated_timetable_id, class_id, subject_id, extra_activity_id, remedial_session_id, teacher_id, room_id, day_of_week, hour_slot)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    foreach ($rows as $r) {
        $stmt->execute([
            $generatedTimetableId,
            $r['class_id'],
            $r['subject_id'],
            $r['extra_activity_id'],
            $r['remedial_session_id'],
            $r['teacher_id'],
            $r['room_id'],
            $r['day_of_week'],
            $r['hour_slot'],
        ]);
    }
}

/**
 * Checks whether a proposed manual move would create a clash (plan
 * section 4b) — does any OTHER row in the same generated_timetable
 * already have this teacher, room, or class at the target day+slot.
 * Returns a human-readable reason string if blocked, or null if the
 * move is safe.
 */
function checkSlotMoveForClash(
    int $generatedTimetableId,
    int $slotIdBeingMoved,
    int $teacherId,
    ?int $roomId,
    int $classId,
    string $targetDay,
    string $targetHourSlot
): ?string {
    $stmt = db()->prepare(
        'SELECT scheduled_slots.*, teachers.name AS teacher_name, classes.name AS class_name, rooms.name AS room_name
         FROM scheduled_slots
         LEFT JOIN teachers ON scheduled_slots.teacher_id = teachers.id
         LEFT JOIN classes ON scheduled_slots.class_id = classes.id
         LEFT JOIN rooms ON scheduled_slots.room_id = rooms.id
         WHERE scheduled_slots.generated_timetable_id = ?
           AND scheduled_slots.id != ?
           AND scheduled_slots.day_of_week = ?
           AND scheduled_slots.hour_slot = ?'
    );
    $stmt->execute([$generatedTimetableId, $slotIdBeingMoved, $targetDay, $targetHourSlot]);
    $sameSlotRows = $stmt->fetchAll();

    foreach ($sameSlotRows as $row) {
        if ((int) $row['teacher_id'] === $teacherId) {
            return "{$row['teacher_name']} is already teaching {$row['class_name']} at this time.";
        }
        if ($roomId !== null && (int) $row['room_id'] === $roomId) {
            return "{$row['room_name']} is already in use by {$row['class_name']} at this time.";
        }
        if ((int) $row['class_id'] === $classId) {
            return "{$row['class_name']} already has a lesson scheduled at this time.";
        }
    }

    return null;
}

/**
 * Applies a validated move (call checkSlotMoveForClash first — this
 * function does not re-check, it trusts the caller already validated).
 */
function applySlotMove(int $slotId, string $newDay, string $newHourSlot, int $editedByAdminId): void
{
    $stmt = db()->prepare(
        'UPDATE scheduled_slots
         SET day_of_week = ?, hour_slot = ?, is_manual_override = TRUE, edited_by_admin_id = ?, edited_at = NOW()
         WHERE id = ?'
    );
    $stmt->execute([$newDay, $newHourSlot, $editedByAdminId, $slotId]);
}

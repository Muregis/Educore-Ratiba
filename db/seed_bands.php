<?php
// db/seed_bands.php
require_once __DIR__ . '/db.php';

$bandsDir = __DIR__ . '/../data/bands/';
$schoolId = 1; // Default school ID for your local setup

if (!is_dir($bandsDir)) {
    die("Bands directory not found at: {$bandsDir}");
}

$files = glob($bandsDir . '*.txt');

if (empty($files)) {
    die("No band files found to process.");
}

echo "Starting data import from band files...\n<br>";

    // MOE/KICD-aligned lessons_per_day by band_key
    // These are the NUMBER OF TEACHING LESSONS per day, not total slots.
    // Extra slots in the XML are for breaks/lunch.
    $bandLessonsMap = [
        'pp1-pp2'      => 5,  // 5 lessons/day + 1 break
        'grade-1-3'    => 6,  // 6 lessons/day (KICD Lower Primary)
        'grade-4-6'    => 7,  // 7 lessons/day (KICD Upper Primary)
        'grade-7-9'    => 8,  // 8 lessons/day (KICD Junior Secondary)
        'grade-10-12'  => 8,  // 8 lessons/day (KICD Senior School)
        'form-3-4'     => 8,  // 8 lessons/day (MoE 8-4-4)
    ];

    foreach ($files as $filePath) {
        $filename = basename($filePath);
        $bandKey = pathinfo($filename, PATHINFO_FILENAME);
        $lessonsPerDay = $bandLessonsMap[$bandKey] ?? 8;
        echo "<strong>Processing Band: {$bandKey}</strong> ({$lessonsPerDay} lessons/day)...<br>";

        // Load and parse XML content
        libxml_use_internal_errors(true);
        $xml = simplexml_load_file($filePath);
        if ($xml === false) {
            echo "Error parsing XML in {$filename}<br>";
            continue;
        }

        // Parse lesson length from first non-break hour slot
        $lessonLength = 40; // default
        foreach ($xml->Hours_List->Hour as $hour) {
            $name = (string) $hour->Name;
            if (stripos($name, 'BREAK') === false && stripos($name, 'LUNCH') === false) {
                if (preg_match('/(\d{2}):(\d{2})-(\d{2}):(\d{2})/', $name, $m)) {
                    $start = (int) $m[1] * 60 + (int) $m[2];
                    $end   = (int) $m[3] * 60 + (int) $m[4];
                    $lessonLength = $end - $start;
                    break;
                }
            }
        }

        $pdo = db();
        $pdo->beginTransaction();

        try {
            // 1. Insert or Fetch Band - with MOE-aligned lessons_per_day
            $stmt = $pdo->prepare("INSERT INTO bands (school_id, band_key, label, lessons_per_day, lesson_length_minutes, active) 
                                   VALUES (:sid, :band_key, :label, :lpd, :llm, 1) 
                                   ON DUPLICATE KEY UPDATE lessons_per_day = :lpd, lesson_length_minutes = :llm");
            $stmt->execute([
                ':sid'    => $schoolId,
                ':band_key' => $bandKey,
                ':label'  => ucfirst(str_replace('-', ' ', $bandKey)),
                ':lpd'    => $lessonsPerDay,
                ':llm'    => $lessonLength,
            ]);
            $bandId = $pdo->lastInsertId() ?: $pdo->query("SELECT id FROM bands WHERE school_id = {$schoolId} AND band_key = '{$bandKey}'")->fetchColumn();

        // 2. Import Teachers
        if (isset($xml->Teachers_List->Teacher)) {
            $stmtTeacher = $pdo->prepare("INSERT IGNORE INTO teachers (school_id, name) VALUES (:sid, :name)");
            foreach ($xml->Teachers_List->Teacher as $teacher) {
                $teacherName = trim((string)$teacher->Name);
                if (!empty($teacherName)) {
                    $stmtTeacher->execute([':sid' => $schoolId, ':name' => $teacherName]);
                }
            }
        }

        // 3. Import Rooms
        if (isset($xml->Rooms_List->Room)) {
            $stmtRoom = $pdo->prepare("INSERT IGNORE INTO rooms (school_id, name, capacity) VALUES (:sid, :name, :cap)");
            foreach ($xml->Rooms_List->Room as $room) {
                $roomName = trim((string)$room->Name);
                $capacity = isset($room->Capacity) ? (int)$room->Capacity : 40;
                if (!empty($roomName)) {
                    $stmtRoom->execute([':sid' => $schoolId, ':name' => $roomName, ':cap' => $capacity]);
                }
            }
        }

        // 4. Import Classes (Years in FET)
        if (isset($xml->Students_List->Year)) {
            $stmtClass = $pdo->prepare("INSERT IGNORE INTO classes (school_id, band_id, name) VALUES (:sid, :bid, :name)");
            foreach ($xml->Students_List->Year as $year) {
                $className = trim((string)$year->Name);
                if (!empty($className)) {
                    $stmtClass->execute([':sid' => $schoolId, ':bid' => $bandId, ':name' => $className]);
                }
            }
        }

        // 5. Import Subjects from Activities
        if (isset($xml->Activities_List->Activity)) {
            // Helper lookups
            $getTeacherId = $pdo->prepare("SELECT id FROM teachers WHERE school_id = :sid AND name = :name LIMIT 1");
            $getClassId   = $pdo->prepare("SELECT id FROM classes WHERE school_id = :sid AND name = :name LIMIT 1");

            // Track subject counts per class to calculate lessons_per_week
            $subjectCounts = [];

            foreach ($xml->Activities_List->Activity as $act) {
                $tName = trim((string)$act->Teacher);
                $cName = trim((string)$act->Students);
                $sName = isset($act->Subject) ? trim((string)$act->Subject) : 'General Subject';
                $duration = isset($act->Duration) ? (int)$act->Duration : 1;

                if (empty($tName) || empty($cName)) continue;

                // Get foreign keys
                $getTeacherId->execute([':sid' => $schoolId, ':name' => $tName]);
                $tId = $getTeacherId->fetchColumn();

                $getClassId->execute([':sid' => $schoolId, ':name' => $cName]);
                $cId = $getClassId->fetchColumn();

                if ($tId && $cId) {
                    // Track this subject for this class
                    $key = "{$cId}_{$sName}";
                    if (!isset($subjectCounts[$key])) {
                        $subjectCounts[$key] = [
                            'class_id' => $cId,
                            'band_id' => $bandId,
                            'subject_name' => $sName,
                            'teacher_id' => $tId,
                            'lessons_count' => 0
                        ];
                    }
                    $subjectCounts[$key]['lessons_count'] += $duration;
                }
            }

            // Insert subjects with aggregated lesson counts
            $stmtSub = $pdo->prepare("INSERT IGNORE INTO subjects (school_id, band_id, class_id, name, lessons_per_week, assigned_teacher_id) 
                                      VALUES (:sid, :bid, :cid, :name, :lpw, :tid)");
            foreach ($subjectCounts as $subj) {
                $stmtSub->execute([
                    ':sid' => $schoolId,
                    ':bid' => $subj['band_id'],
                    ':cid' => $subj['class_id'],
                    ':name' => $subj['subject_name'],
                    ':lpw' => $subj['lessons_count'],
                    ':tid' => $subj['teacher_id']
                ]);
            }
        }

        $pdo->commit();
        echo "Successfully imported {$filename}<br>";

    } catch (Exception $e) {
        $pdo->rollBack();
        echo "Failed to import {$filename}: " . $e->getMessage() . "<br>";
    }
}

echo "<br><strong>Database import complete!</strong>";
?>
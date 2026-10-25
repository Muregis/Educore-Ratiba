<?php
declare(strict_types=1);

require_once __DIR__ . '/../db/db.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/generate_engine.php';
require_once __DIR__ . '/fet_runtime.php';
require_once __DIR__ . '/purge_cross_band_slots.php';
require_once __DIR__ . '/apply_band_hour_locks.php';
require_once __DIR__ . '/../tests/ClashDetector.php';

/**
 * Seeds authentic KICD CBC & JSS showcase data into a school and generates
 * a 100% clash-free FET timetable.
 *
 * @param int $schoolId The school to seed (default 1)
 * @param int|null $adminId Optional admin user ID triggering the seed
 * @return array{success: bool, message: string, slots_count: int, conflicts: list<string>}
 */
function seedKicdDemoData(int $schoolId = 1, ?int $adminId = null): array
{
    $pdo = db();

    // 1. Update School Info
    $pdo->prepare("UPDATE schools SET 
        name = 'Kilimani Comprehensive Academy',
        school_type = 'primary',
        school_sub_type = 'county',
        county = 'Nairobi',
        ministry_of_education_code = '1300045231',
        days_per_week = 5,
        day_names = ?,
        generation_time_limit = 300,
        prefer_spread = 1
    WHERE id = ?")->execute([
        json_encode(['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday']),
        $schoolId
    ]);

    // 2. Ensure demo_admin Credentials (DemoAdmin#2026)
    $pwdHash = password_hash('DemoAdmin#2026', PASSWORD_BCRYPT);
    $adminCheck = $pdo->prepare("SELECT id FROM school_admins WHERE username = 'demo_admin'");
    $adminCheck->execute();
    $foundAdminId = $adminCheck->fetchColumn();
    if ($foundAdminId) {
        $pdo->prepare("UPDATE school_admins SET school_id = ?, password_hash = ? WHERE id = ?")->execute([$schoolId, $pwdHash, $foundAdminId]);
        $effectiveAdminId = (int) $foundAdminId;
    } else {
        $pdo->prepare("INSERT INTO school_admins (school_id, username, password_hash) VALUES (?, 'demo_admin', ?)")->execute([$schoolId, $pwdHash]);
        $effectiveAdminId = (int) $pdo->lastInsertId();
    }
    if ($adminId !== null) {
        $effectiveAdminId = $adminId;
    }

    // 3. Clear existing school records cleanly
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0;");
    $pdo->prepare("DELETE FROM scheduled_slots WHERE generated_timetable_id IN (SELECT id FROM generated_timetables WHERE school_id = ?)")->execute([$schoolId]);
    $pdo->prepare("DELETE FROM generated_timetables WHERE school_id = ?")->execute([$schoolId]);
    $pdo->prepare("DELETE FROM remedial_sessions WHERE school_id = ?")->execute([$schoolId]);
    $pdo->prepare("DELETE FROM extra_activities WHERE school_id = ?")->execute([$schoolId]);
    $pdo->prepare("DELETE FROM subjects WHERE school_id = ?")->execute([$schoolId]);
    $pdo->prepare("DELETE FROM classes WHERE school_id = ?")->execute([$schoolId]);
    $pdo->prepare("DELETE FROM bands WHERE school_id = ?")->execute([$schoolId]);
    $pdo->prepare("DELETE FROM teachers WHERE school_id = ?")->execute([$schoolId]);
    $pdo->prepare("DELETE FROM rooms WHERE school_id = ?")->execute([$schoolId]);
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1;");

    // 4. Insert KICD Standard Bands
    $bandDefs = [
        [
            'key' => 'cbc-lower',
            'label' => 'Lower Primary (Grade 1 - 3)',
            'lessons_per_day' => 7,
            'length' => 35,
            'start' => '08:00',
            'breaks' => [
                ['time' => '09:45-10:15', 'label' => 'TEA BREAK'],
                ['time' => '12:00-13:00', 'label' => 'LUNCH BREAK']
            ]
        ],
        [
            'key' => 'cbc-upper',
            'label' => 'Upper Primary (Grade 4 - 6)',
            'lessons_per_day' => 7,
            'length' => 35,
            'start' => '08:00',
            'breaks' => [
                ['time' => '09:45-10:15', 'label' => 'TEA BREAK'],
                ['time' => '12:35-13:30', 'label' => 'LUNCH BREAK']
            ]
        ],
        [
            'key' => 'cbc-jss',
            'label' => 'Junior Secondary School (JSS Grade 7 - 9)',
            'lessons_per_day' => 8,
            'length' => 40,
            'start' => '08:00',
            'breaks' => [
                ['time' => '09:20-09:40', 'label' => 'SHORT BREAK'],
                ['time' => '11:00-11:30', 'label' => 'TEA BREAK'],
                ['time' => '13:30-14:20', 'label' => 'LUNCH BREAK']
            ]
        ]
    ];

    $bandIdByKey = [];
    foreach ($bandDefs as $b) {
        $stmt = $pdo->prepare("INSERT INTO bands (school_id, band_key, label, lessons_per_day, lesson_length_minutes, day_start_time, break_config, active) VALUES (?, ?, ?, ?, ?, ?, ?, 1)");
        $stmt->execute([
            $schoolId,
            $b['key'],
            $b['label'],
            $b['lessons_per_day'],
            $b['length'],
            $b['start'],
            json_encode($b['breaks'])
        ]);
        $bandIdByKey[$b['key']] = (int) $pdo->lastInsertId();
    }

    // 5. Insert Facilities & Rooms
    $roomDefs = [
        ['name' => 'Grade 1 Red Room', 'code' => 'G1R', 'cap' => 40, 'type' => 'classroom'],
        ['name' => 'Grade 1 Blue Room', 'code' => 'G1B', 'cap' => 40, 'type' => 'classroom'],
        ['name' => 'Grade 2 Green Room', 'code' => 'G2G', 'cap' => 40, 'type' => 'classroom'],
        ['name' => 'Grade 3 Amber Room', 'code' => 'G3A', 'cap' => 40, 'type' => 'classroom'],
        ['name' => 'Grade 4 East Room', 'code' => 'G4E', 'cap' => 45, 'type' => 'classroom'],
        ['name' => 'Grade 4 West Room', 'code' => 'G4W', 'cap' => 45, 'type' => 'classroom'],
        ['name' => 'Grade 5 North Room', 'code' => 'G5N', 'cap' => 45, 'type' => 'classroom'],
        ['name' => 'Grade 6 Central Room', 'code' => 'G6C', 'cap' => 45, 'type' => 'classroom'],
        ['name' => 'Grade 7 Mara Room', 'code' => 'G7M', 'cap' => 45, 'type' => 'classroom'],
        ['name' => 'Grade 7 Samburu Room', 'code' => 'G7S', 'cap' => 45, 'type' => 'classroom'],
        ['name' => 'Grade 8 Amboseli Room', 'code' => 'G8A', 'cap' => 45, 'type' => 'classroom'],
        ['name' => 'Grade 9 Turkana Room', 'code' => 'G9T', 'cap' => 45, 'type' => 'classroom'],
        ['name' => 'Integrated Science Lab', 'code' => 'SCI-LAB', 'cap' => 50, 'type' => 'lab'],
        ['name' => 'Pre-Technical / Computer Lab', 'code' => 'ICT-LAB', 'cap' => 50, 'type' => 'lab'],
        ['name' => 'Home Science & Nutrition Lab', 'code' => 'NUT-LAB', 'cap' => 45, 'type' => 'workshop'],
        ['name' => 'School Fields & Sports Pavilion', 'code' => 'FIELD', 'cap' => 120, 'type' => 'outdoors'],
    ];

    $roomIdByName = [];
    foreach ($roomDefs as $r) {
        $stmt = $pdo->prepare("INSERT INTO rooms (school_id, name, capacity, room_type) VALUES (?, ?, ?, ?)");
        $stmt->execute([$schoolId, $r['name'], $r['cap'], $r['type']]);
        $roomIdByName[$r['name']] = (int) $pdo->lastInsertId();
    }

    // 6. Insert Classes
    $classDefs = [
        ['name' => 'Grade 1 Red', 'band' => 'cbc-lower', 'room' => 'Grade 1 Red Room', 'students' => 35],
        ['name' => 'Grade 1 Blue', 'band' => 'cbc-lower', 'room' => 'Grade 1 Blue Room', 'students' => 36],
        ['name' => 'Grade 2 Green', 'band' => 'cbc-lower', 'room' => 'Grade 2 Green Room', 'students' => 38],
        ['name' => 'Grade 3 Amber', 'band' => 'cbc-lower', 'room' => 'Grade 3 Amber Room', 'students' => 37],
        ['name' => 'Grade 4 East', 'band' => 'cbc-upper', 'room' => 'Grade 4 East Room', 'students' => 40],
        ['name' => 'Grade 4 West', 'band' => 'cbc-upper', 'room' => 'Grade 4 West Room', 'students' => 41],
        ['name' => 'Grade 5 North', 'band' => 'cbc-upper', 'room' => 'Grade 5 North Room', 'students' => 42],
        ['name' => 'Grade 6 Central', 'band' => 'cbc-upper', 'room' => 'Grade 6 Central Room', 'students' => 43],
        ['name' => 'Grade 7 Mara', 'band' => 'cbc-jss', 'room' => 'Grade 7 Mara Room', 'students' => 44],
        ['name' => 'Grade 7 Samburu', 'band' => 'cbc-jss', 'room' => 'Grade 7 Samburu Room', 'students' => 42],
        ['name' => 'Grade 8 Amboseli', 'band' => 'cbc-jss', 'room' => 'Grade 8 Amboseli Room', 'students' => 45],
        ['name' => 'Grade 9 Turkana', 'band' => 'cbc-jss', 'room' => 'Grade 9 Turkana Room', 'students' => 40],
    ];

    $classIdByName = [];
    foreach ($classDefs as $c) {
        $bandId = $bandIdByKey[$c['band']];
        $roomId = $roomIdByName[$c['room']] ?? null;
        $stmt = $pdo->prepare("INSERT INTO classes (school_id, band_id, name, student_count, room_id, active) VALUES (?, ?, ?, ?, ?, 1)");
        $stmt->execute([$schoolId, $bandId, $c['name'], $c['students'], $roomId]);
        $classIdByName[$c['name']] = (int) $pdo->lastInsertId();
    }

    // 7. Insert Teachers
    $teacherDefs = [
        ['name' => 'Mr. David Mwangi, OGW', 'staff_id' => 'T-001', 'tsc' => 'TSC/382910', 'max' => 20],
        ['name' => 'Mrs. Mary Wambui',     'staff_id' => 'T-002', 'tsc' => 'TSC/419203', 'max' => 24],
        ['name' => 'Mr. John Otieno',       'staff_id' => 'T-003', 'tsc' => 'TSC/482019', 'max' => 28],
        ['name' => 'Ms. Grace Njeri',       'staff_id' => 'T-004', 'tsc' => 'TSC/510293', 'max' => 28],
        ['name' => 'Mr. Peter Kiprop',      'staff_id' => 'T-005', 'tsc' => 'TSC/463829', 'max' => 28],
        ['name' => 'Mrs. Beatrice Mutua',   'staff_id' => 'T-006', 'tsc' => 'TSC/491820', 'max' => 28],
        ['name' => 'Mr. Emmanuel Ochieng',  'staff_id' => 'T-007', 'tsc' => 'TSC/523910', 'max' => 28],
        ['name' => 'Ms. Faith Chebet',      'staff_id' => 'T-008', 'tsc' => 'TSC/538192', 'max' => 28],
        ['name' => 'Mr. Joseph Karanja',    'staff_id' => 'T-009', 'tsc' => 'TSC/472819', 'max' => 28],
        ['name' => 'Mrs. Lucy Achieng',     'staff_id' => 'T-010', 'tsc' => 'TSC/449182', 'max' => 28],
        ['name' => 'Mr. Hassan Mohammed',   'staff_id' => 'T-011', 'tsc' => 'TSC/502918', 'max' => 28],
        ['name' => 'Ms. Agnes Nduta',       'staff_id' => 'T-012', 'tsc' => 'TSC/518290', 'max' => 32],
        ['name' => 'Mrs. Christine Wanjiku','staff_id' => 'T-013', 'tsc' => 'TSC/529103', 'max' => 32],
        ['name' => 'Mr. Stephen Musyoka',   'staff_id' => 'T-014', 'tsc' => 'TSC/501928', 'max' => 32],
        ['name' => 'Ms. Esther Nyambura',   'staff_id' => 'T-015', 'tsc' => 'TSC/549102', 'max' => 30],
        ['name' => 'Mr. Kevin Omondi',      'staff_id' => 'T-016', 'tsc' => 'TSC/560192', 'max' => 30],
        ['name' => 'Mrs. Janet Wairimu',    'staff_id' => 'T-017', 'tsc' => 'TSC/489201', 'max' => 28],
        ['name' => 'Mr. Brian Barasa',      'staff_id' => 'T-018', 'tsc' => 'TSC/532901', 'max' => 28],
        ['name' => 'Ms. Lilian Chemutai',   'staff_id' => 'T-019', 'tsc' => 'TSC/572819', 'max' => 28],
        ['name' => 'Mr. Dennis Kipchumba',  'staff_id' => 'T-020', 'tsc' => 'TSC/581920', 'max' => 28],
        ['name' => 'Mrs. Florence Wangari', 'staff_id' => 'T-021', 'tsc' => 'TSC/492019', 'max' => 28],
        ['name' => 'Mr. Anthony Maina',     'staff_id' => 'T-022', 'tsc' => 'TSC/541290', 'max' => 28],
    ];

    $teacherIdByName = [];
    foreach ($teacherDefs as $t) {
        $stmt = $pdo->prepare("INSERT INTO teachers (school_id, staff_id, tsc_number, name, max_lessons_per_week) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$schoolId, $t['staff_id'], $t['tsc'], $t['name'], $t['max']]);
        $teacherIdByName[$t['name']] = (int) $pdo->lastInsertId();
    }

    // 8. Insert Subjects per Class
    $stmtSub = $pdo->prepare("INSERT INTO subjects (school_id, band_id, class_id, name, lessons_per_week, duration_slots, min_days_between, assigned_teacher_id) VALUES (?, ?, ?, ?, ?, 1, 1, ?)");

    // Lower Primary (35 lessons/week each)
    $lowerAssignments = [
        'Grade 1 Red' => [
            ['Mathematical Activities', 5, 'Ms. Agnes Nduta'],
            ['English Language Activities', 5, 'Ms. Agnes Nduta'],
            ['Kiswahili Language Activities', 4, 'Ms. Agnes Nduta'],
            ['Environmental Activities', 4, 'Ms. Agnes Nduta'],
            ['Hygiene and Nutrition Activities', 3, 'Ms. Agnes Nduta'],
            ['Religious Education (CRE)', 3, 'Ms. Agnes Nduta'],
            ['Movement Activities', 5, 'Mr. Kevin Omondi'],
            ['Creative Arts & Craft', 5, 'Ms. Esther Nyambura'],
            ['Pastoral Programme of Instruction (PPI)', 1, 'Ms. Agnes Nduta'],
        ],
        'Grade 1 Blue' => [
            ['Mathematical Activities', 5, 'Ms. Esther Nyambura'],
            ['English Language Activities', 5, 'Ms. Esther Nyambura'],
            ['Kiswahili Language Activities', 4, 'Ms. Esther Nyambura'],
            ['Environmental Activities', 4, 'Ms. Esther Nyambura'],
            ['Hygiene and Nutrition Activities', 3, 'Ms. Esther Nyambura'],
            ['Religious Education (CRE)', 3, 'Mr. Dennis Kipchumba'],
            ['Movement Activities', 5, 'Mr. Kevin Omondi'],
            ['Creative Arts & Craft', 5, 'Ms. Faith Chebet'],
            ['Pastoral Programme of Instruction (PPI)', 1, 'Ms. Esther Nyambura'],
        ],
        'Grade 2 Green' => [
            ['Mathematical Activities', 5, 'Mrs. Christine Wanjiku'],
            ['English Language Activities', 5, 'Mrs. Christine Wanjiku'],
            ['Kiswahili Language Activities', 4, 'Mrs. Christine Wanjiku'],
            ['Environmental Activities', 4, 'Mrs. Christine Wanjiku'],
            ['Hygiene and Nutrition Activities', 3, 'Mrs. Christine Wanjiku'],
            ['Religious Education (CRE)', 3, 'Mrs. Christine Wanjiku'],
            ['Movement Activities', 5, 'Mr. Kevin Omondi'],
            ['Creative Arts & Craft', 5, 'Ms. Faith Chebet'],
            ['Pastoral Programme of Instruction (PPI)', 1, 'Mrs. Christine Wanjiku'],
        ],
        'Grade 3 Amber' => [
            ['Mathematical Activities', 5, 'Mr. Stephen Musyoka'],
            ['English Language Activities', 5, 'Mr. Stephen Musyoka'],
            ['Kiswahili Language Activities', 4, 'Mr. Stephen Musyoka'],
            ['Environmental Activities', 4, 'Mr. Stephen Musyoka'],
            ['Hygiene and Nutrition Activities', 3, 'Mr. Stephen Musyoka'],
            ['Religious Education (CRE)', 3, 'Mr. Stephen Musyoka'],
            ['Movement Activities', 5, 'Mr. Kevin Omondi'],
            ['Creative Arts & Craft', 5, 'Ms. Faith Chebet'],
            ['Pastoral Programme of Instruction (PPI)', 1, 'Mr. Stephen Musyoka'],
        ],
    ];
    foreach ($lowerAssignments as $cName => $plan) {
        $cId = $classIdByName[$cName];
        $bId = $bandIdByKey['cbc-lower'];
        foreach ($plan as [$sName, $lw, $tName]) {
            $tId = $teacherIdByName[$tName];
            $stmtSub->execute([$schoolId, $bId, $cId, $sName, $lw, $tId]);
        }
    }

    // Upper Primary (35 lessons/week each)
    $upperAssignments = [
        'Grade 4 East' => [
            ['Mathematics', 5, 'Mr. Brian Barasa'],
            ['English', 5, 'Mrs. Florence Wangari'],
            ['Kiswahili', 4, 'Mr. Anthony Maina'],
            ['Science and Technology', 4, 'Mrs. Janet Wairimu'],
            ['Agriculture and Nutrition', 4, 'Mrs. Beatrice Mutua'],
            ['Social Studies', 4, 'Mr. Dennis Kipchumba'],
            ['Christian Religious Education (CRE)', 3, 'Mr. Joseph Karanja'],
            ['Creative Arts & Music', 3, 'Ms. Faith Chebet'],
            ['Physical Education & Sports', 2, 'Mr. Kevin Omondi'],
            ['Pastoral Programme of Instruction (PPI)', 1, 'Mrs. Florence Wangari'],
        ],
        'Grade 4 West' => [
            ['Mathematics', 5, 'Mr. Brian Barasa'],
            ['English', 5, 'Mrs. Florence Wangari'],
            ['Kiswahili', 4, 'Mr. Anthony Maina'],
            ['Science and Technology', 4, 'Mrs. Janet Wairimu'],
            ['Agriculture and Nutrition', 4, 'Mrs. Beatrice Mutua'],
            ['Social Studies', 4, 'Mr. Dennis Kipchumba'],
            ['Christian Religious Education (CRE)', 3, 'Mr. Joseph Karanja'],
            ['Creative Arts & Music', 3, 'Ms. Faith Chebet'],
            ['Physical Education & Sports', 2, 'Mr. Kevin Omondi'],
            ['Pastoral Programme of Instruction (PPI)', 1, 'Mrs. Florence Wangari'],
        ],
        'Grade 5 North' => [
            ['Mathematics', 5, 'Mr. Brian Barasa'],
            ['English', 5, 'Mrs. Lucy Achieng'],
            ['Kiswahili', 4, 'Mr. Hassan Mohammed'],
            ['Science and Technology', 4, 'Mrs. Janet Wairimu'],
            ['Agriculture and Nutrition', 4, 'Ms. Lilian Chemutai'],
            ['Social Studies', 4, 'Mr. Dennis Kipchumba'],
            ['Christian Religious Education (CRE)', 3, 'Mr. Joseph Karanja'],
            ['Creative Arts & Music', 3, 'Ms. Faith Chebet'],
            ['Physical Education & Sports', 2, 'Mr. Kevin Omondi'],
            ['Pastoral Programme of Instruction (PPI)', 1, 'Mrs. Lucy Achieng'],
        ],
        'Grade 6 Central' => [
            ['Mathematics', 5, 'Mr. Brian Barasa'],
            ['English', 5, 'Mrs. Lucy Achieng'],
            ['Kiswahili', 4, 'Mr. Hassan Mohammed'],
            ['Science and Technology', 4, 'Mrs. Janet Wairimu'],
            ['Agriculture and Nutrition', 4, 'Ms. Lilian Chemutai'],
            ['Social Studies', 4, 'Mr. Joseph Karanja'],
            ['Christian Religious Education (CRE)', 3, 'Mr. Joseph Karanja'],
            ['Creative Arts & Music', 3, 'Ms. Faith Chebet'],
            ['Physical Education & Sports', 2, 'Mr. Kevin Omondi'],
            ['Pastoral Programme of Instruction (PPI)', 1, 'Mrs. Lucy Achieng'],
        ],
    ];
    foreach ($upperAssignments as $cName => $plan) {
        $cId = $classIdByName[$cName];
        $bId = $bandIdByKey['cbc-upper'];
        foreach ($plan as [$sName, $lw, $tName]) {
            $tId = $teacherIdByName[$tName];
            $stmtSub->execute([$schoolId, $bId, $cId, $sName, $lw, $tId]);
        }
    }

    // Junior Secondary School (40 lessons/week each)
    $jssAssignments = [
        'Grade 7 Mara' => [
            ['Mathematics', 5, 'Ms. Grace Njeri'],
            ['English', 5, 'Mrs. Mary Wambui'],
            ['Kiswahili', 4, 'Mr. Peter Kiprop'],
            ['Integrated Science', 5, 'Mr. John Otieno'],
            ['Social Studies', 4, 'Mr. Peter Kiprop'],
            ['Agriculture and Nutrition', 4, 'Mrs. Beatrice Mutua'],
            ['Pre-Technical Studies', 4, 'Mr. Emmanuel Ochieng'],
            ['Christian Religious Education (CRE)', 3, 'Mr. Joseph Karanja'],
            ['Creative Arts & Sports', 5, 'Mr. Anthony Maina'],
            ['Pastoral Programme of Instruction (PPI)', 1, 'Mrs. Mary Wambui'],
        ],
        'Grade 7 Samburu' => [
            ['Mathematics', 5, 'Ms. Grace Njeri'],
            ['English', 5, 'Mrs. Mary Wambui'],
            ['Kiswahili', 4, 'Mr. Peter Kiprop'],
            ['Integrated Science', 5, 'Mr. John Otieno'],
            ['Social Studies', 4, 'Mr. Dennis Kipchumba'],
            ['Agriculture and Nutrition', 4, 'Ms. Lilian Chemutai'],
            ['Pre-Technical Studies', 4, 'Mr. Emmanuel Ochieng'],
            ['Christian Religious Education (CRE)', 3, 'Mr. Joseph Karanja'],
            ['Creative Arts & Sports', 5, 'Mr. Anthony Maina'],
            ['Pastoral Programme of Instruction (PPI)', 1, 'Mrs. Mary Wambui'],
        ],
        'Grade 8 Amboseli' => [
            ['Mathematics', 5, 'Ms. Grace Njeri'],
            ['English', 5, 'Mrs. Florence Wangari'],
            ['Kiswahili', 4, 'Mr. Hassan Mohammed'],
            ['Integrated Science', 5, 'Mr. John Otieno'],
            ['Social Studies', 4, 'Mr. Peter Kiprop'],
            ['Agriculture and Nutrition', 4, 'Mrs. Beatrice Mutua'],
            ['Pre-Technical Studies', 4, 'Mr. Emmanuel Ochieng'],
            ['Christian Religious Education (CRE)', 3, 'Mr. Joseph Karanja'],
            ['Creative Arts & Sports', 5, 'Mrs. Janet Wairimu'],
            ['Pastoral Programme of Instruction (PPI)', 1, 'Mrs. Florence Wangari'],
        ],
        'Grade 9 Turkana' => [
            ['Mathematics', 5, 'Mr. David Mwangi, OGW'],
            ['English', 5, 'Mrs. Mary Wambui'],
            ['Kiswahili', 4, 'Mr. Hassan Mohammed'],
            ['Integrated Science', 5, 'Mr. John Otieno'],
            ['Social Studies', 4, 'Mr. Peter Kiprop'],
            ['Agriculture and Nutrition', 4, 'Ms. Lilian Chemutai'],
            ['Pre-Technical Studies', 4, 'Mr. Emmanuel Ochieng'],
            ['Christian Religious Education (CRE)', 3, 'Mr. Dennis Kipchumba'],
            ['Creative Arts & Sports', 5, 'Mr. Anthony Maina'],
            ['Pastoral Programme of Instruction (PPI)', 1, 'Mrs. Mary Wambui'],
        ],
    ];
    foreach ($jssAssignments as $cName => $plan) {
        $cId = $classIdByName[$cName];
        $bId = $bandIdByKey['cbc-jss'];
        foreach ($plan as [$sName, $lw, $tName]) {
            $tId = $teacherIdByName[$tName];
            $stmtSub->execute([$schoolId, $bId, $cId, $sName, $lw, $tId]);
        }
    }

    // 9. Preflight Verification
    $problems = runPreflightChecks($schoolId);
    if (!empty($problems)) {
        return [
            'success' => false,
            'message' => 'Preflight check failed: ' . implode('; ', $problems),
            'slots_count' => 0,
            'conflicts' => $problems
        ];
    }

    // 10. Generate FET timetable
    $stmtSchool = $pdo->prepare('SELECT * FROM schools WHERE id = ?');
    $stmtSchool->execute([$schoolId]);
    $school = $stmtSchool->fetch();
    $xmlPath = buildWholeSchoolXml($schoolId, $school['name']);
    applyBandHourLocksToFetXml($xmlPath, $schoolId);

    $engine = Config::get('paths.engine');
    $projectOutputRoot = Config::get('paths.output') . '/schools/' . $schoolId;
    $timeLimit = getSchoolGenerationTimeLimit($schoolId);

    $result = runFetEngineV2($xmlPath, $engine, $projectOutputRoot, $timeLimit);
    if (!$result['success']) {
        return [
            'success' => false,
            'message' => 'FET engine failed: ' . ($result['raw_output'] ?? 'Unknown error'),
            'slots_count' => 0,
            'conflicts' => []
        ];
    }

    // 11. Parse generated solution into database
    $metaPath = $xmlPath . '.meta.json';
    $activityMeta = [];
    if (file_exists($metaPath)) {
        $decodedMeta = json_decode((string) file_get_contents($metaPath), true);
        if (is_array($decodedMeta) && $decodedMeta !== []) {
            $activityMeta = array_combine(
                array_map('intval', array_keys($decodedMeta)),
                array_values($decodedMeta)
            ) ?: [];
        }
    }

    $stmt = $pdo->prepare("INSERT INTO generated_timetables (school_id, xml_snapshot, html_output_path, status, triggered_by_admin_id, triggered_by_type) VALUES (?, ?, ?, 'success', ?, 'school_admin')");
    $stmt->execute([
        $schoolId,
        file_get_contents($xmlPath),
        $result['html_output_path'] ?? null,
        $effectiveAdminId
    ]);
    $genId = (int) $pdo->lastInsertId();

    $slotRows = parseFetSolutionIntoSlots($result['solution_xml_path'], $activityMeta, $schoolId);
    storeScheduledSlots($genId, $slotRows);

    purgeCrossBandSlots($genId, $schoolId);

    // 12. Conflict Audit
    $stmt = $pdo->prepare("SELECT * FROM scheduled_slots WHERE generated_timetable_id = ?");
    $stmt->execute([$genId]);
    $finalSlots = $stmt->fetchAll();

    $conflicts = ClashDetector::findConflicts($finalSlots);
    if ($conflicts !== []) {
        return [
            'success' => false,
            'message' => 'Post-generation conflicts detected: ' . count($conflicts) . ' conflicts',
            'slots_count' => count($finalSlots),
            'conflicts' => $conflicts
        ];
    }

    return [
        'success' => true,
        'message' => 'Successfully loaded KICD standard demo data and generated a 100% conflict-free timetable (' . count($finalSlots) . ' lessons).',
        'slots_count' => count($finalSlots),
        'conflicts' => []
    ];
}

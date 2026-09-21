<?php
/**
 * db/seed_demo.php
 *
 * One-shot seed for Supabase / PostgreSQL:
 *  - Super admin for the Super Admin Portal
 *  - One sample school with admin, bands, rooms, teachers, classes, subjects
 *
 * Visit: https://educore-ratiba.onrender.com/db/seed_demo.php
 * Safe to re-run: skips existing super admin / school by name.
 */
declare(strict_types=1);

require_once __DIR__ . '/db.php';

header('Content-Type: text/html; charset=utf-8');

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function tableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare(
        "SELECT 1 FROM information_schema.tables
         WHERE table_schema = 'public' AND table_name = ?"
    );
    $stmt->execute([$table]);
    return (bool) $stmt->fetchColumn();
}

echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Seed Demo — EduCore Ratiba</title>';
echo '<style>body{font-family:system-ui,sans-serif;max-width:720px;margin:40px auto;padding:0 16px;line-height:1.5}
.ok{color:#059669}.err{color:#dc2626}.box{background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:16px;margin:16px 0}
code{background:#f1f5f9;padding:2px 6px;border-radius:4px} table{border-collapse:collapse;width:100%} td,th{border:1px solid #e2e8f0;padding:8px;text-align:left}</style></head><body>';
echo '<h1>EduCore Ratiba — Demo Seed</h1>';

try {
    $pdo = db();
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

    // --- Ensure core tables exist ---
    $required = ['super_admins', 'schools', 'school_admins', 'bands', 'rooms', 'teachers', 'classes', 'subjects'];
    $missing = [];
    foreach ($required as $t) {
        if (!tableExists($pdo, $t)) {
            $missing[] = $t;
        }
    }
    if ($missing) {
        echo '<p class="err">Missing tables: <code>' . h(implode(', ', $missing)) . '</code></p>';
        echo '<p>Run the Postgres schema first in Supabase SQL Editor:</p>';
        echo '<p><code>db/schema_postgres.sql</code></p>';
        echo '</body></html>';
        exit;
    }

    $pdo->beginTransaction();

    // ==========================================================
    // 1. SUPER ADMIN
    // ==========================================================
    $superUser = 'superadmin';
    $superPass = 'SuperAdmin@2026';
    $superHash = password_hash($superPass, PASSWORD_DEFAULT);

    $stmt = $pdo->prepare('SELECT id FROM super_admins WHERE username = ?');
    $stmt->execute([$superUser]);
    $existingSuper = $stmt->fetchColumn();

    if ($existingSuper) {
        // Refresh password so you always know the current one after seed
        $stmt = $pdo->prepare('UPDATE super_admins SET password_hash = ? WHERE id = ?');
        $stmt->execute([$superHash, $existingSuper]);
        echo '<p class="ok">✓ Super admin already existed — password reset.</p>';
    } else {
        $stmt = $pdo->prepare('INSERT INTO super_admins (username, password_hash) VALUES (?, ?)');
        $stmt->execute([$superUser, $superHash]);
        echo '<p class="ok">✓ Super admin created.</p>';
    }

    echo '<div class="box"><h2>Super Admin Portal</h2>';
    echo '<table><tr><th>Username</th><td><code>' . h($superUser) . '</code></td></tr>';
    echo '<tr><th>Password</th><td><code>' . h($superPass) . '</code></td></tr></table>';
    echo '<p>Login at <a href="../login.php">login.php</a> then open Super Admin Portal.</p></div>';

    // ==========================================================
    // 2. SAMPLE SCHOOL
    // ==========================================================
    $schoolName = 'Green Valley Academy';

    $stmt = $pdo->prepare('SELECT id FROM schools WHERE name = ?');
    $stmt->execute([$schoolName]);
    $schoolId = $stmt->fetchColumn();

    if ($schoolId) {
        echo '<p class="ok">✓ School "' . h($schoolName) . '" already exists (id=' . (int)$schoolId . ') — refreshing related data where needed.</p>';
    } else {
        $stmt = $pdo->prepare("INSERT INTO schools (name, deployment_type) VALUES (?, 'server-hosted') RETURNING id");
        $stmt->execute([$schoolName]);
        $schoolId = (int) $stmt->fetchColumn();
        echo '<p class="ok">✓ School created (id=' . $schoolId . ').</p>';
    }

    // School admin
    $schoolAdminUser = 'schooladmin';
    $schoolAdminPass = 'School@2026';
    $schoolAdminHash = password_hash($schoolAdminPass, PASSWORD_DEFAULT);

    $stmt = $pdo->prepare('SELECT id FROM school_admins WHERE school_id = ? AND username = ?');
    $stmt->execute([$schoolId, $schoolAdminUser]);
    $saId = $stmt->fetchColumn();

    if ($saId) {
        $stmt = $pdo->prepare('UPDATE school_admins SET password_hash = ? WHERE id = ?');
        $stmt->execute([$schoolAdminHash, $saId]);
    } else {
        $stmt = $pdo->prepare('INSERT INTO school_admins (school_id, username, password_hash) VALUES (?, ?, ?)');
        $stmt->execute([$schoolId, $schoolAdminUser, $schoolAdminHash]);
    }

    echo '<div class="box"><h2>School Admin</h2>';
    echo '<table><tr><th>School</th><td>' . h($schoolName) . '</td></tr>';
    echo '<tr><th>Username</th><td><code>' . h($schoolAdminUser) . '</code></td></tr>';
    echo '<tr><th>Password</th><td><code>' . h($schoolAdminPass) . '</code></td></tr></table></div>';

    // ==========================================================
    // 3. BANDS (KICD-aligned)
    // ==========================================================
    $bands = [
        ['pp1-pp2',     'Pre-Primary (PP1–PP2)',     5, 30],
        ['grade-1-3',   'Lower Primary (Grade 1–3)', 6, 30],
        ['grade-4-6',   'Upper Primary (Grade 4–6)', 7, 35],
        ['grade-7-9',   'Junior Secondary (G7–9)',   8, 40],
    ];

    $bandIds = [];
    foreach ($bands as [$key, $label, $lpd, $llm]) {
        $stmt = $pdo->prepare('SELECT id FROM bands WHERE school_id = ? AND band_key = ?');
        $stmt->execute([$schoolId, $key]);
        $bid = $stmt->fetchColumn();
        if ($bid) {
            $bandIds[$key] = (int) $bid;
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO bands (school_id, band_key, label, lessons_per_day, lesson_length_minutes, active)
                 VALUES (?, ?, ?, ?, ?, TRUE) RETURNING id'
            );
            $stmt->execute([$schoolId, $key, $label, $lpd, $llm]);
            $bandIds[$key] = (int) $stmt->fetchColumn();
        }
    }
    echo '<p class="ok">✓ Bands: ' . count($bandIds) . '</p>';

    // ==========================================================
    // 4. ROOMS
    // ==========================================================
    $rooms = [
        ['Room A', 40, 'classroom'],
        ['Room B', 40, 'classroom'],
        ['Room C', 35, 'classroom'],
        ['Room D', 35, 'classroom'],
        ['Science Lab', 30, 'lab'],
        ['Computer Lab', 30, 'computer lab'],
        ['Library', 50, 'library'],
        ['Hall', 100, 'hall'],
    ];

    $roomIds = [];
    foreach ($rooms as [$name, $cap, $type]) {
        $stmt = $pdo->prepare('SELECT id FROM rooms WHERE school_id = ? AND name = ?');
        $stmt->execute([$schoolId, $name]);
        $rid = $stmt->fetchColumn();
        if ($rid) {
            $roomIds[] = (int) $rid;
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO rooms (school_id, name, capacity, room_type) VALUES (?, ?, ?, ?) RETURNING id'
            );
            $stmt->execute([$schoolId, $name, $cap, $type]);
            $roomIds[] = (int) $stmt->fetchColumn();
        }
    }
    echo '<p class="ok">✓ Rooms: ' . count($roomIds) . '</p>';

    // ==========================================================
    // 5. TEACHERS
    // ==========================================================
    $teachers = [
        ['T-001', 'John Kamau', 30],
        ['T-002', 'Mary Wanjiku', 28],
        ['T-003', 'Peter Otieno', 32],
        ['T-004', 'Grace Njeri', 30],
        ['T-005', 'James Kipkorir', 28],
        ['T-006', 'Sarah Akinyi', 30],
        ['T-007', 'David Mwangi', 32],
        ['T-008', 'Elizabeth Muthoni', 28],
        ['T-009', 'Michael Njoroge', 30],
        ['T-010', 'Hannah Chepkorir', 28],
        ['T-011', 'Robert Maina', 30],
        ['T-012', 'Rachel Achieng', 28],
    ];

    $teacherIds = [];
    foreach ($teachers as [$staffId, $name, $max]) {
        $stmt = $pdo->prepare('SELECT id FROM teachers WHERE school_id = ? AND staff_id = ?');
        $stmt->execute([$schoolId, $staffId]);
        $tid = $stmt->fetchColumn();
        if ($tid) {
            $teacherIds[] = (int) $tid;
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO teachers (school_id, staff_id, name, max_lessons_per_week)
                 VALUES (?, ?, ?, ?) RETURNING id'
            );
            $stmt->execute([$schoolId, $staffId, $name, $max]);
            $teacherIds[] = (int) $stmt->fetchColumn();
        }
    }
    echo '<p class="ok">✓ Teachers: ' . count($teacherIds) . '</p>';

    // ==========================================================
    // 6. CLASSES
    // ==========================================================
    $classDefs = [
        // [band_key, class_name, student_count]
        ['pp1-pp2',   'PP1 Blue', 28],
        ['pp1-pp2',   'PP2 Green', 30],
        ['grade-1-3', 'Grade 1 East', 35],
        ['grade-1-3', 'Grade 2 West', 34],
        ['grade-1-3', 'Grade 3 North', 36],
        ['grade-4-6', 'Grade 4 East', 38],
        ['grade-4-6', 'Grade 5 West', 37],
        ['grade-4-6', 'Grade 6 South', 39],
        ['grade-7-9', 'Grade 7 Alpha', 40],
        ['grade-7-9', 'Grade 8 Beta', 38],
        ['grade-7-9', 'Grade 9 Gamma', 36],
    ];

    $classRows = []; // [id, band_id]
    $ri = 0;
    foreach ($classDefs as [$bkey, $cname, $scount]) {
        $bandId = $bandIds[$bkey];
        $roomId = $roomIds[$ri % count($roomIds)];
        $ri++;

        $stmt = $pdo->prepare('SELECT id FROM classes WHERE school_id = ? AND name = ?');
        $stmt->execute([$schoolId, $cname]);
        $cid = $stmt->fetchColumn();
        if ($cid) {
            $classRows[] = ['id' => (int)$cid, 'band_id' => $bandId];
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO classes (school_id, band_id, name, student_count, room_id, active)
                 VALUES (?, ?, ?, ?, ?, TRUE) RETURNING id'
            );
            $stmt->execute([$schoolId, $bandId, $cname, $scount, $roomId]);
            $classRows[] = ['id' => (int)$stmt->fetchColumn(), 'band_id' => $bandId];
        }
    }
    echo '<p class="ok">✓ Classes: ' . count($classRows) . '</p>';

    // ==========================================================
    // 7. SUBJECTS (per class)
    // ==========================================================
    $subjectsByBand = [
        'pp1-pp2' => [
            ['Language Activities', 5],
            ['Mathematical Activities', 5],
            ['Environmental Activities', 4],
            ['Creative Activities', 3],
            ['Religious Education', 2],
        ],
        'grade-1-3' => [
            ['English', 5],
            ['Kiswahili', 4],
            ['Mathematics', 5],
            ['Science', 3],
            ['Social Studies', 3],
            ['CRE', 2],
            ['Physical Education', 2],
            ['Art & Craft', 2],
        ],
        'grade-4-6' => [
            ['English', 5],
            ['Kiswahili', 4],
            ['Mathematics', 5],
            ['Science', 4],
            ['Social Studies', 3],
            ['CRE', 2],
            ['Physical Education', 2],
            ['Agriculture', 2],
            ['Computer Studies', 2],
        ],
        'grade-7-9' => [
            ['English', 5],
            ['Kiswahili', 4],
            ['Mathematics', 5],
            ['Integrated Science', 4],
            ['Social Studies', 3],
            ['CRE', 2],
            ['Physical Education', 2],
            ['Agriculture', 2],
            ['Business Studies', 2],
            ['Computer Studies', 2],
        ],
    ];

    // Map class id → band_key
    $bandKeyById = array_flip($bandIds);
    $subjectCount = 0;
    $ti = 0;

    foreach ($classRows as $crow) {
        $cid = $crow['id'];
        $bid = $crow['band_id'];
        $bkey = $bandKeyById[$bid] ?? null;
        if (!$bkey || !isset($subjectsByBand[$bkey])) {
            continue;
        }

        foreach ($subjectsByBand[$bkey] as [$sname, $lpw]) {
            $stmt = $pdo->prepare(
                'SELECT id FROM subjects WHERE school_id = ? AND class_id = ? AND name = ?'
            );
            $stmt->execute([$schoolId, $cid, $sname]);
            if ($stmt->fetchColumn()) {
                continue; // already there
            }

            $tid = $teacherIds[$ti % count($teacherIds)];
            $ti++;

            $stmt = $pdo->prepare(
                'INSERT INTO subjects (school_id, band_id, class_id, name, lessons_per_week, assigned_teacher_id)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([$schoolId, $bid, $cid, $sname, $lpw, $tid]);
            $subjectCount++;
        }
    }
    echo '<p class="ok">✓ Subjects inserted this run: ' . $subjectCount . '</p>';

    $pdo->commit();

    echo '<hr><h2 class="ok">Done</h2>';
    echo '<ul>';
    echo '<li><a href="../login.php">Login page</a></li>';
    echo '<li>Super admin: <code>' . h($superUser) . '</code> / <code>' . h($superPass) . '</code></li>';
    echo '<li>School admin: <code>' . h($schoolAdminUser) . '</code> / <code>' . h($schoolAdminPass) . '</code></li>';
    echo '</ul>';
    echo '<p><strong>Change these passwords after first login.</strong></p>';

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo '<p class="err"><strong>Failed:</strong> ' . h($e->getMessage()) . '</p>';
    echo '<pre style="background:#fef2f2;padding:12px;overflow:auto">' . h($e->getTraceAsString()) . '</pre>';
    echo '<p>If this is a connection error, fix DB_HOST / DB_USER / DB_PASS on Render first.</p>';
}

echo '</body></html>';

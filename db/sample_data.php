<?php
// db/sample_data.php — Generate sample data for small, medium, and large schools
// Run this after setup.php and create_admin.php
// Postgres-compatible (active = TRUE)

require_once __DIR__ . '/db.php';

$pdo = db();

// Sample data configurations
$schoolConfigs = [
    'small' => [
        'name' => 'Small Primary School',
        'bands' => ['pp1-pp2', 'grade-1-3'],
        'teachers_count' => 6,
        'rooms_count' => 6,
        'classes_per_band' => 2,
        'subjects_per_class' => 5,
    ],
    'medium' => [
        'name' => 'Medium Academy',
        'bands' => ['pp1-pp2', 'grade-1-3', 'grade-4-6', 'grade-7-9'],
        'teachers_count' => 20,
        'rooms_count' => 18,
        'classes_per_band' => 3,
        'subjects_per_class' => 8,
    ],
    'large' => [
        'name' => 'Large Comprehensive School',
        'bands' => ['pp1-pp2', 'grade-1-3', 'grade-4-6', 'grade-7-9', 'grade-10-12', 'form-3-4'],
        'teachers_count' => 40,
        'rooms_count' => 35,
        'classes_per_band' => 4,
        'subjects_per_class' => 10,
    ],
];

$subjectNames = [
    'Mathematics', 'English', 'Kiswahili', 'Science', 'Social Studies',
    'CRE', 'IRE', 'HRE', 'Physical Education', 'Art & Craft',
    'Music', 'Computer Studies', 'Business Studies', 'Agriculture', 'Geography',
    'History', 'Physics', 'Chemistry', 'Biology', 'Home Science'
];

$teacherFirstNames = ['John', 'Mary', 'Peter', 'Grace', 'James', 'Sarah', 'David', 'Elizabeth', 'Michael', 'Hannah', 'Robert', 'Rachel', 'William', 'Rebecca', 'Thomas', 'Ruth', 'Daniel', 'Esther', 'Matthew', 'Anna', 'Joseph', 'Martha', 'Samuel', 'Lydia', 'Benjamin', 'Naomi', 'Andrew', 'Deborah', 'Joshua', 'Priscilla'];
$teacherLastNames = ['Kamau', 'Ochieng', 'Mwangi', 'Njoroge', 'Otieno', 'Wanjiku', 'Kipkorir', 'Muthoni', 'Kiplagat', 'Njeri', 'Mutua', 'Akinyi', 'Kimenyi', 'Wairimu', 'Chepkorir', 'Nyambura', 'Maina', 'Achieng', 'Ndirangu', 'Mumbi', 'Koech', 'Atieno', 'Thuo', 'Moraa', 'Njenga', 'Kemunto', 'Githinji', 'Adhiambo', 'Muriithi', 'Wanjiru'];

$roomTypes = ['classroom', 'lab', 'hall', 'library', 'computer lab'];

function generateRandomName($firstNames, $lastNames) {
    return $firstNames[array_rand($firstNames)] . ' ' . $lastNames[array_rand($lastNames)];
}

function generateStaffId() {
    return 'T-' . str_pad((string)rand(1, 999), 3, '0', STR_PAD_LEFT);
}

echo "<h2>Sample Data Generation</h2>";
echo "<hr>";

foreach ($schoolConfigs as $size => $config) {
    echo "<h3>Creating {$config['name']} ({$size})</h3>";
    
    try {
        $pdo->beginTransaction();
        
        // Create school
        $stmt = $pdo->prepare('INSERT INTO schools (name, deployment_type) VALUES (?, ?)');
        $stmt->execute([$config['name'], 'server-hosted']);
        $schoolId = $pdo->lastInsertId();
        
        // Create school admin
        $adminUsername = strtolower(str_replace(' ', '', $config['name'])) . '_admin';
        $adminPassword = $size . '123';
        $adminHash = password_hash($adminPassword, PASSWORD_DEFAULT);
        
        $stmt = $pdo->prepare('INSERT INTO school_admins (school_id, username, password_hash) VALUES (?, ?, ?)');
        $stmt->execute([$schoolId, $adminUsername, $adminHash]);
        
        echo "<p><strong>School Admin:</strong> {$adminUsername} / {$adminPassword}</p>";
        
        // Create bands
        $bandIds = [];
        foreach ($config['bands'] as $bandKey) {
            $stmt = $pdo->prepare('INSERT INTO bands (school_id, band_key, label, lessons_per_day, lesson_length_minutes, active) VALUES (?, ?, ?, ?, ?, TRUE)');
            $label = ucfirst(str_replace('-', ' to ', $bandKey));
            $lessonsPerDay = ($size === 'small') ? 6 : (($size === 'medium') ? 7 : 8);
            $lessonLength = ($size === 'small') ? 40 : 35;
            $stmt->execute([$schoolId, $bandKey, $label, $lessonsPerDay, $lessonLength]);
            $bandIds[$bandKey] = $pdo->lastInsertId();
        }
        
        // Create teachers
        $teacherIds = [];
        for ($i = 0; $i < $config['teachers_count']; $i++) {
            $name = generateRandomName($teacherFirstNames, $teacherLastNames);
            $staffId = generateStaffId();
            $maxLessons = rand(25, 35);
            
            $stmt = $pdo->prepare('INSERT INTO teachers (school_id, staff_id, name, max_lessons_per_week) VALUES (?, ?, ?, ?)');
            $stmt->execute([$schoolId, $staffId, $name, $maxLessons]);
            $teacherIds[] = $pdo->lastInsertId();
        }
        
        // Create rooms
        $roomIds = [];
        for ($i = 0; $i < $config['rooms_count']; $i++) {
            $name = 'Room ' . chr(65 + $i);
            $capacity = rand(30, 50);
            $roomType = $roomTypes[array_rand($roomTypes)];
            
            $stmt = $pdo->prepare('INSERT INTO rooms (school_id, name, capacity, room_type) VALUES (?, ?, ?, ?)');
            $stmt->execute([$schoolId, $name, $capacity, $roomType]);
            $roomIds[] = $pdo->lastInsertId();
        }
        
        // Create classes
        $classIds = [];
        foreach ($config['bands'] as $bandKey) {
            $bandId = $bandIds[$bandKey];
            for ($i = 1; $i <= $config['classes_per_band']; $i++) {
                $className = ucfirst(str_replace('-', ' ', $bandKey)) . ' ' . chr(64 + $i);
                $studentCount = rand(25, 45);
                $roomId = $roomIds[array_rand($roomIds)];
                
                $stmt = $pdo->prepare('INSERT INTO classes (school_id, band_id, name, room_id, student_count, active) VALUES (?, ?, ?, ?, ?, TRUE)');
                $stmt->execute([$schoolId, $bandId, $className, $roomId, $studentCount]);
                $classIds[] = ['id' => $pdo->lastInsertId(), 'band_id' => $bandId, 'name' => $className];
            }
        }
        
        // Create subjects for each class
        foreach ($classIds as $class) {
            $classId = $class['id'];
            $bandId = $class['band_id'];
            
            $classSubjects = array_rand(array_flip($subjectNames), min($config['subjects_per_class'], count($subjectNames)));
            if (!is_array($classSubjects)) {
                $classSubjects = [$classSubjects];
            }
            
            foreach ($classSubjects as $subjectName) {
                $teacherId = $teacherIds[array_rand($teacherIds)];
                $lessonsPerWeek = rand(3, 6);
                
                $stmt = $pdo->prepare('INSERT INTO subjects (school_id, band_id, class_id, name, lessons_per_week, assigned_teacher_id) VALUES (?, ?, ?, ?, ?, ?)');
                $stmt->execute([$schoolId, $bandId, $classId, $subjectName, $lessonsPerWeek, $teacherId]);
            }
        }
        
        $pdo->commit();
        
        echo "<p style='color: green;'>✓ Created successfully!</p>";
        echo "<ul>";
        echo "<li>Bands: " . count($config['bands']) . "</li>";
        echo "<li>Teachers: {$config['teachers_count']}</li>";
        echo "<li>Rooms: {$config['rooms_count']}</li>";
        echo "<li>Classes: " . count($classIds) . "</li>";
        echo "<li>Total Subjects: " . (count($classIds) * $config['subjects_per_class']) . "</li>";
        echo "</ul>";
        
    } catch (Exception $e) {
        $pdo->rollBack();
        echo "<p style='color: red;'>✗ Failed: " . htmlspecialchars($e->getMessage()) . "</p>";
    }
    
    echo "<hr>";
}

echo "<h3>Summary</h3>";
echo "<p>All sample schools have been created with realistic data.</p>";
echo "<p><a href='../login.php'>Go to Login Page</a></p>";
echo "<p>You can now login with any of the school admin credentials above to test the system.</p>";

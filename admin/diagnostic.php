<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../db/db.php';

$schoolId = requireLoginAndGetSchoolId();
$stmt = db()->prepare('SELECT * FROM schools WHERE id = ?');
$stmt->execute([$schoolId]);
$school = $stmt->fetch();

// Check latest generation
$stmt = db()->prepare('SELECT * FROM generated_timetables WHERE school_id = ? ORDER BY generated_at DESC LIMIT 5');
$stmt->execute([$schoolId]);
$generations = $stmt->fetchAll();

// Check scheduled_slots count
$stmt = db()->prepare('SELECT COUNT(*) as cnt FROM scheduled_slots WHERE generated_timetable_id = ?');
$slotCounts = [];
foreach ($generations as $gen) {
    $stmt->execute([$gen['id']]);
    $slotCounts[$gen['id']] = (int) $stmt->fetchColumn();
}

// Check classes
$stmt = db()->prepare('SELECT COUNT(*) as cnt FROM classes WHERE school_id = ? AND active = 1');
$stmt->execute([$schoolId]);
$classCount = (int) $stmt->fetchColumn();

// Check subjects
$stmt = db()->prepare('SELECT COUNT(*) as cnt FROM subjects WHERE school_id = ?');
$stmt->execute([$schoolId]);
$subjectCount = (int) $stmt->fetchColumn();

// Check teachers
$stmt = db()->prepare('SELECT COUNT(*) as cnt FROM teachers WHERE school_id = ?');
$stmt->execute([$schoolId]);
$teacherCount = (int) $stmt->fetchColumn();
?>
<!DOCTYPE html>
<html>
<head>
    <title>Timetable Diagnostic</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 900px; margin: 40px auto; padding: 20px; }
        .card { border: 1px solid #ddd; padding: 20px; margin-bottom: 20px; border-radius: 8px; }
        .ok { color: green; font-weight: bold; }
        .error { color: red; font-weight: bold; }
        .warn { color: orange; font-weight: bold; }
        table { border-collapse: collapse; width: 100%; margin-top: 10px; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background: #f5f5f5; }
    </style>
</head>
<body>
    <h1>Timetable Diagnostic — <?php echo htmlspecialchars($school['name']); ?></h1>
    
    <div class="card">
        <h2>Data Summary</h2>
        <p>Classes: <?php echo $classCount; ?></p>
        <p>Subjects: <?php echo $subjectCount; ?></p>
        <p>Teachers: <?php echo $teacherCount; ?></p>
    </div>
    
    <div class="card">
        <h2>Recent Generations</h2>
        <?php if (empty($generations)): ?>
            <p class="error">No generations found. Generate a timetable first.</p>
        <?php else: ?>
            <table>
                <tr><th>ID</th><th>Status</th><th>Generated At</th><th>Scheduled Slots</th><th>XML Snapshot</th></tr>
                <?php foreach ($generations as $gen): ?>
                    <tr>
                        <td><?php echo (int) $gen['id']; ?></td>
                        <td><?php echo $gen['status'] === 'success' ? '<span class="ok">Success</span>' : '<span class="error">Failed</span>'; ?></td>
                        <td><?php echo htmlspecialchars($gen['generated_at']); ?></td>
                        <td><?php echo $slotCounts[$gen['id']] ?? 0; ?></td>
                        <td><?php echo $gen['xml_snapshot'] ? 'Yes' : 'No'; ?></td>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php endif; ?>
    </div>
    
    <div class="card">
        <h2>Quick Links</h2>
        <p><a href="generate.php">Generate Timetable</a></p>
        <p><a href="view_timetable.php">View Timetables</a></p>
        <p><a href="../export/export.php?scope=whole-school">Download Whole School PDF</a></p>
    </div>
</body>
</html>

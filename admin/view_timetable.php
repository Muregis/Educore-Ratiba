<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../db/db.php';

$schoolId = requireLoginAndGetSchoolId();
$stmt = db()->prepare('SELECT * FROM schools WHERE id = ?');
$stmt->execute([$schoolId]);
$school = $stmt->fetch();

// Get latest generation
$stmt = db()->prepare('SELECT * FROM generated_timetables WHERE school_id = ? ORDER BY generated_at DESC LIMIT 1');
$stmt->execute([$schoolId]);
$latestGeneration = $stmt->fetch();

// Get all classes
$stmt = db()->prepare('SELECT * FROM classes WHERE school_id = ? AND active = TRUE ORDER BY name');
$stmt->execute([$schoolId]);
$classes = $stmt->fetchAll();

$pageTitle = 'View Timetables — ' . $school['name'];
require __DIR__ . '/_header.php';
?>

<div class="card">
    <h2>Download Timetables</h2>
    
    <?php if (!$latestGeneration): ?>
        <div class="error">No timetable has been generated yet. <a href="generate.php">Generate one first</a>.</div>
    <?php elseif ($latestGeneration['status'] !== 'success'): ?>
        <div class="error">The latest generation failed. <a href="generate.php">Try generating again</a>.</div>
    <?php else: ?>
        <p class="empty">Download timetables for classes or individual teachers.</p>
        
        <div style="margin-top: 20px; display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 20px;">
            <a href="../export/export.php?scope=whole-school" target="_blank" class="btn">Download Whole School PDF</a>
            <a href="../export/export_teachers.php" target="_blank" class="btn">Download All Teacher Timetables</a>
        </div>
        
        <h3 style="margin-top: 30px; margin-bottom: 10px;">Class Timetables</h3>
        <table>
            <thead><tr><th>Class</th><th>Band</th><th>Actions</th></tr></thead>
            <tbody>
                <?php foreach ($classes as $class): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($class['name']); ?></td>
                        <td>
                            <?php 
                            $stmt = db()->prepare('SELECT label FROM bands WHERE id = ?');
                            $stmt->execute([$class['band_id']]);
                            $band = $stmt->fetch();
                            echo htmlspecialchars($band['label'] ?? '—');
                            ?>
                        </td>
                        <td>
                            <a href="../export/export.php?scope=class&class_id=<?php echo (int) $class['id']; ?>" target="_blank" class="btn">
                                Download PDF
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        
        <h3 style="margin-top: 30px; margin-bottom: 10px;">Teacher Timetables</h3>
        <p class="empty">Click on a teacher name to download their personal timetable.</p>
        <table>
            <thead><tr><th>Teacher</th><th>Staff ID</th><th>Actions</th></tr></thead>
            <tbody>
                <?php
                $stmt = db()->prepare('SELECT * FROM teachers WHERE school_id = ? ORDER BY name');
                $stmt->execute([$schoolId]);
                $teachers = $stmt->fetchAll();
                foreach ($teachers as $teacher):
                ?>
                    <tr>
                        <td><?php echo htmlspecialchars($teacher['name']); ?></td>
                        <td><?php echo htmlspecialchars($teacher['staff_id'] ?? '—'); ?></td>
                        <td>
                            <a href="../export/export.php?scope=teacher&teacher_id=<?php echo (int) $teacher['id']; ?>" target="_blank" class="btn">
                                Download PDF
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/_footer.php'; ?>

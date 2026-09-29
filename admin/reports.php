<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../db/db.php';
require_once __DIR__ . '/../db/audit_log.php';

$schoolId = requireLoginAndGetSchoolId();
$stmt = db()->prepare('SELECT * FROM schools WHERE id = ?');
$stmt->execute([$schoolId]);
$school = $stmt->fetch();

$reportType = $_GET['report'] ?? 'teacher_workload';
$format = $_GET['format'] ?? 'html';

// Generate report data based on type
function generateTeacherWorkloadReport($schoolId) {
    $stmt = db()->prepare('
        SELECT 
            t.id,
            t.name,
            t.tsc_number,
            t.staff_id,
            t.max_lessons_per_week,
            COUNT(DISTINCT s.id) as assigned_subjects,
            SUM(s.lessons_per_week) as total_assigned_lessons,
            COUNT(DISTINCT c.id) as assigned_classes
        FROM teachers t
        LEFT JOIN subjects s ON t.id = s.assigned_teacher_id AND s.school_id = t.school_id
        LEFT JOIN classes c ON s.class_id = c.id
        WHERE t.school_id = ?
        GROUP BY t.id
        ORDER BY t.name
    ');
    $stmt->execute([$schoolId]);
    return $stmt->fetchAll();
}

function generateRoomUtilizationReport($schoolId) {
    $stmt = db()->prepare('
        SELECT 
            r.id,
            r.name,
            r.capacity,
            r.room_type,
            COUNT(DISTINCT c.id) as assigned_classes,
            COUNT(DISTINCT s.id) as scheduled_subjects
        FROM rooms r
        LEFT JOIN classes c ON r.id = c.room_id AND c.school_id = r.school_id
        LEFT JOIN subjects s ON c.id = s.class_id AND s.school_id = r.school_id
        WHERE r.school_id = ?
        GROUP BY r.id
        ORDER BY r.name
    ');
    $stmt->execute([$schoolId]);
    return $stmt->fetchAll();
}

function generateStaffDeploymentReport($schoolId) {
    $stmt = db()->prepare('
        SELECT 
            t.id,
            t.name,
            t.tsc_number,
            t.staff_id,
            t.subjects_taught,
            COUNT(DISTINCT b.id) as bands_serving,
            GROUP_CONCAT(DISTINCT b.label ORDER BY b.label SEPARATOR ', ') as bands_serving_names
        FROM teachers t
        LEFT JOIN subjects s ON t.id = s.assigned_teacher_id AND s.school_id = t.school_id
        LEFT JOIN bands b ON s.band_id = b.id AND b.school_id = t.school_id
        WHERE t.school_id = ?
        GROUP BY t.id
        ORDER BY t.name
    ');
    $stmt->execute([$schoolId]);
    return $stmt->fetchAll();
}

function generateSchoolStatisticsReport($schoolId) {
    $stats = [];
    
    // Get school info
    $stmt = db()->prepare('SELECT * FROM schools WHERE id = ?');
    $stmt->execute([$schoolId]);
    $stats['school'] = $stmt->fetch();
    
    // Count teachers
    $stmt = db()->prepare('SELECT COUNT(*) FROM teachers WHERE school_id = ?');
    $stmt->execute([$schoolId]);
    $stats['teacher_count'] = $stmt->fetchColumn();
    
    // Count teachers with TSC numbers
    $stmt = db()->prepare('SELECT COUNT(*) FROM teachers WHERE school_id = ? AND tsc_number IS NOT NULL');
    $stmt->execute([$schoolId]);
    $stats['tsc_registered_count'] = $stmt->fetchColumn();
    
    // Count rooms
    $stmt = db()->prepare('SELECT COUNT(*) FROM rooms WHERE school_id = ?');
    $stmt->execute([$schoolId]);
    $stats['room_count'] = $stmt->fetchColumn();
    
    // Count classes
    $stmt = db()->prepare('SELECT COUNT(*) FROM classes WHERE school_id = ? AND active = TRUE');
    $stmt->execute([$schoolId]);
    $stats['active_class_count'] = $stmt->fetchColumn();
    
    // Count subjects
    $stmt = db()->prepare('SELECT COUNT(*) FROM subjects WHERE school_id = ?');
    $stmt->execute([$schoolId]);
    $stats['subject_count'] = $stmt->fetchColumn();
    
    // Count bands
    $stmt = db()->prepare('SELECT COUNT(*) FROM bands WHERE school_id = ? AND active = TRUE');
    $stmt->execute([$schoolId]);
    $stats['active_band_count'] = $stmt->fetchColumn();
    
    return $stats;
}

// Generate the report based on type
$reportData = null;
$reportTitle = '';

switch ($reportType) {
    case 'teacher_workload':
        $reportData = generateTeacherWorkloadReport($schoolId);
        $reportTitle = 'Teacher Workload Analysis';
        break;
    case 'room_utilization':
        $reportData = generateRoomUtilizationReport($schoolId);
        $reportTitle = 'Room Utilization Report';
        break;
    case 'staff_deployment':
        $reportData = generateStaffDeploymentReport($schoolId);
        $reportTitle = 'Staff Deployment Report (TSC Audit)';
        break;
    case 'school_statistics':
        $reportData = generateSchoolStatisticsReport($schoolId);
        $reportTitle = 'School Statistics';
        break;
    default:
        $reportData = generateTeacherWorkloadReport($schoolId);
        $reportTitle = 'Teacher Workload Analysis';
}

// Handle CSV export
if ($format === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $reportTitle . '.csv"');
    
    $output = fopen('php://output', 'w');
    
    if ($reportType === 'teacher_workload') {
        fputcsv($output, ['Teacher Name', 'TSC Number', 'Staff ID', 'Max Lessons/Week', 'Assigned Subjects', 'Total Assigned Lessons', 'Assigned Classes']);
        foreach ($reportData as $row) {
            fputcsv($output, [
                $row['name'],
                $row['tsc_number'] ?? 'N/A',
                $row['staff_id'] ?? 'N/A',
                $row['max_lessons_per_week'] ?? 'N/A',
                $row['assigned_subjects'],
                $row['total_assigned_lessons'] ?? 0,
                $row['assigned_classes']
            ]);
        }
    } elseif ($reportType === 'room_utilization') {
        fputcsv($output, ['Room Name', 'Capacity', 'Room Type', 'Assigned Classes', 'Scheduled Subjects']);
        foreach ($reportData as $row) {
            fputcsv($output, [
                $row['name'],
                $row['capacity'] ?? 'N/A',
                $row['room_type'] ?? 'N/A',
                $row['assigned_classes'],
                $row['scheduled_subjects'] ?? 0
            ]);
        }
    } elseif ($reportType === 'staff_deployment') {
        fputcsv($output, ['Teacher Name', 'TSC Number', 'Staff ID', 'Subjects Taught', 'Bands Serving', 'Band Names']);
        foreach ($reportData as $row) {
            fputcsv($output, [
                $row['name'],
                $row['tsc_number'] ?? 'N/A',
                $row['staff_id'] ?? 'N/A',
                $row['subjects_taught'] ?? 'N/A',
                $row['bands_serving'],
                $row['bands_serving_names'] ?? 'N/A'
            ]);
        }
    }
    
    fclose($output);
    exit;
}

$pageTitle = $reportTitle . ' — ' . $school['name'];
require __DIR__ . '/_header.php';
?>

<div class="card">
    <div class="card-header flex justify-between items-center gap-4 flex-wrap">
        <h2 class="flex-1">Kenyan School Reports</h2>
        <div class="flex gap-2 flex-wrap">
            <select onchange="location.href='reports.php?report=' + this.value" class="w-auto">
                <option value="teacher_workload" <?php echo $reportType === 'teacher_workload' ? 'selected' : ''; ?>>Teacher Workload</option>
                <option value="room_utilization" <?php echo $reportType === 'room_utilization' ? 'selected' : ''; ?>>Room Utilization</option>
                <option value="staff_deployment" <?php echo $reportType === 'staff_deployment' ? 'selected' : ''; ?>>Staff Deployment (TSC)</option>
                <option value="school_statistics" <?php echo $reportType === 'school_statistics' ? 'selected' : ''; ?>>School Statistics</option>
            </select>
            <a href="reports.php?report=<?php echo $reportType; ?>&format=csv" class="btn btn-secondary">Export CSV</a>
        </div>
    </div>
    <p class="empty">Specialized reports for Kenyan schools including TSC compliance, MoE requirements, and operational analysis.</p>
</div>

<div class="card">
    <h2><?php echo htmlspecialchars($reportTitle); ?></h2>
    
    <?php if ($reportType === 'teacher_workload'): ?>
        <table>
            <thead>
                <tr>
                    <th>Teacher Name</th>
                    <th>TSC Number</th>
                    <th>Staff ID</th>
                    <th>Max Lessons/Week</th>
                    <th>Assigned Subjects</th>
                    <th>Total Assigned Lessons</th>
                    <th>Assigned Classes</th>
                    <th>Workload Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($reportData as $teacher): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($teacher['name']); ?></td>
                        <td><?php echo htmlspecialchars($teacher['tsc_number'] ?? '—'); ?></td>
                        <td><?php echo htmlspecialchars($teacher['staff_id'] ?? '—'); ?></td>
                        <td><?php echo $teacher['max_lessons_per_week'] ? (int) $teacher['max_lessons_per_week'] : '—'; ?></td>
                        <td><?php echo (int) $teacher['assigned_subjects']; ?></td>
                        <td><?php echo $teacher['total_assigned_lessons'] ? (int) $teacher['total_assigned_lessons'] : 0; ?></td>
                        <td><?php echo (int) $teacher['assigned_classes']; ?></td>
                        <td>
                            <?php 
                            $workloadStatus = 'Normal';
                            $workloadColor = 'var(--text-muted)';
                            if ($teacher['max_lessons_per_week'] && $teacher['total_assigned_lessons']) {
                                $percentage = ($teacher['total_assigned_lessons'] / $teacher['max_lessons_per_week']) * 100;
                                if ($percentage > 100) {
                                    $workloadStatus = 'Overloaded';
                                    $workloadColor = '#dc2626';
                                } elseif ($percentage < 50) {
                                    $workloadStatus = 'Underutilized';
                                    $workloadColor = '#f59e0b';
                                } else {
                                    $workloadStatus = 'Optimal';
                                    $workloadColor = '#10b981';
                                }
                            }
                            ?>
                            <span style="color: <?php echo $workloadColor; ?>; font-weight: 600;">
                                <?php echo htmlspecialchars($workloadStatus); ?>
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <p style="margin-top: 16px; color: var(--text-muted); font-size: 0.875rem;">
            * Workload status helps identify teachers who may be overburdened or underutilized for TSC compliance.
        </p>

    <?php elseif ($reportType === 'room_utilization'): ?>
        <table>
            <thead>
                <tr>
                    <th>Room Name</th>
                    <th>Capacity</th>
                    <th>Room Type</th>
                    <th>Assigned Classes</th>
                    <th>Scheduled Subjects</th>
                    <th>Utilization Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($reportData as $room): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($room['name']); ?></td>
                        <td><?php echo $room['capacity'] ? (int) $room['capacity'] : '—'; ?></td>
                        <td><?php echo htmlspecialchars($room['room_type'] ?? '—'); ?></td>
                        <td><?php echo (int) $room['assigned_classes']; ?></td>
                        <td><?php echo $room['scheduled_subjects'] ? (int) $room['scheduled_subjects'] : 0; ?></td>
                        <td>
                            <?php 
                            $utilizationStatus = 'Available';
                            $utilizationColor = 'var(--text-muted)';
                            if ($room['assigned_classes'] > 0) {
                                $utilizationStatus = 'In Use';
                                $utilizationColor = '#10b981';
                            }
                            ?>
                            <span style="color: <?php echo $utilizationColor; ?>; font-weight: 600;">
                                <?php echo htmlspecialchars($utilizationStatus); ?>
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

    <?php elseif ($reportType === 'staff_deployment'): ?>
        <table>
            <thead>
                <tr>
                    <th>Teacher Name</th>
                    <th>TSC Number</th>
                    <th>Staff ID</th>
                    <th>Subjects Taught</th>
                    <th>Bands Serving</th>
                    <th>Band Names</th>
                    <th>TSC Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($reportData as $teacher): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($teacher['name']); ?></td>
                        <td><?php echo htmlspecialchars($teacher['tsc_number'] ?? '—'); ?></td>
                        <td><?php echo htmlspecialchars($teacher['staff_id'] ?? '—'); ?></td>
                        <td><?php echo htmlspecialchars($teacher['subjects_taught'] ?? '—'); ?></td>
                        <td><?php echo (int) $teacher['bands_serving']; ?></td>
                        <td><?php echo htmlspecialchars($teacher['bands_serving_names'] ?? '—'); ?></td>
                        <td>
                            <?php 
                            $tscStatus = 'Not Registered';
                            $tscColor = '#f59e0b';
                            if ($teacher['tsc_number']) {
                                $tscStatus = 'TSC Registered';
                                $tscColor = '#10b981';
                            }
                            ?>
                            <span style="color: <?php echo $tscColor; ?>; font-weight: 600;">
                                <?php echo htmlspecialchars($tscStatus); ?>
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <p style="margin-top: 16px; color: var(--text-muted); font-size: 0.875rem;">
            * Staff deployment report useful for TSC audits and Ministry of Education compliance.
        </p>

    <?php elseif ($reportType === 'school_statistics'): ?>
        <div class="grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin: 24px 0;">
            <div style="background: var(--bg-light); padding: 16px; border-radius: 8px; text-align: center;">
                <div style="font-size: 2rem; font-weight: 700; color: var(--primary);"><?php echo (int) $reportData['teacher_count']; ?></div>
                <div style="color: var(--text-muted); font-size: 0.875rem;">Total Teachers</div>
            </div>
            <div style="background: var(--bg-light); padding: 16px; border-radius: 8px; text-align: center;">
                <div style="font-size: 2rem; font-weight: 700; color: #10b981;"><?php echo (int) $reportData['tsc_registered_count']; ?></div>
                <div style="color: var(--text-muted); font-size: 0.875rem;">TSC Registered</div>
            </div>
            <div style="background: var(--bg-light); padding: 16px; border-radius: 8px; text-align: center;">
                <div style="font-size: 2rem; font-weight: 700; color: var(--primary);"><?php echo (int) $reportData['room_count']; ?></div>
                <div style="color: var(--text-muted); font-size: 0.875rem;">Total Rooms</div>
            </div>
            <div style="background: var(--bg-light); padding: 16px; border-radius: 8px; text-align: center;">
                <div style="font-size: 2rem; font-weight: 700; color: var(--primary);"><?php echo (int) $reportData['active_class_count']; ?></div>
                <div style="color: var(--text-muted); font-size: 0.875rem;">Active Classes</div>
            </div>
            <div style="background: var(--bg-light); padding: 16px; border-radius: 8px; text-align: center;">
                <div style="font-size: 2rem; font-weight: 700; color: var(--primary);"><?php echo (int) $reportData['subject_count']; ?></div>
                <div style="color: var(--text-muted); font-size: 0.875rem;">Total Subjects</div>
            </div>
            <div style="background: var(--bg-light); padding: 16px; border-radius: 8px; text-align: center;">
                <div style="font-size: 2rem; font-weight: 700; color: var(--primary);"><?php echo (int) $reportData['active_band_count']; ?></div>
                <div style="color: var(--text-muted); font-size: 0.875rem;">Active Bands</div>
            </div>
        </div>

        <div style="background: var(--bg-light); padding: 20px; border-radius: 8px; margin-top: 24px;">
            <h3 style="margin: 0 0 16px;">School Information</h3>
            <p><strong>School Name:</strong> <?php echo htmlspecialchars($reportData['school']['name']); ?></p>
            <p><strong>School Type:</strong> <?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $reportData['school']['school_type'] ?? ''))); ?></p>
            <p><strong>Sub-type:</strong> <?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $reportData['school']['school_sub_type'] ?? 'N/A'))); ?></p>
            <p><strong>County:</strong> <?php echo htmlspecialchars($reportData['school']['county'] ?? 'N/A'); ?></p>
            <p><strong>MoE Code:</strong> <?php echo htmlspecialchars($reportData['school']['ministry_of_education_code'] ?? 'N/A'); ?></p>
        </div>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/_footer.php'; ?>

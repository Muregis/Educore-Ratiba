<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../db/db.php';

$schoolId = requireLoginAndGetSchoolId();
$school = db()->prepare('SELECT * FROM schools WHERE id = ?');
$school->execute([$schoolId]);
$school = $school->fetch();
$reportType = $_GET['report'] ?? 'teacher_workload';
$format = $_GET['format'] ?? 'html';
$pdo = db();

function latestSuccessId(int $schoolId): ?int {
    $pdo = db();
    $stmt = $pdo->prepare("SELECT id FROM generated_timetables WHERE school_id = ? AND status = 'success' ORDER BY generated_at DESC LIMIT 20");
    $stmt->execute([$schoolId]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $c = $pdo->prepare('SELECT COUNT(*) FROM scheduled_slots WHERE generated_timetable_id = ?');
        $c->execute([(int)$id]);
        if ((int)$c->fetchColumn() > 0) return (int)$id;
    }
    return null;
}

$ttId = latestSuccessId($schoolId);
$rows = [];
$title = 'Teacher Workload';

if ($reportType === 'room_utilization') {
    $title = 'Room Utilization';
    $rooms = $pdo->prepare('SELECT * FROM rooms WHERE school_id = ? ORDER BY name');
    $rooms->execute([$schoolId]);
    foreach ($rooms->fetchAll() as $r) {
        $n = 0; $cls = 0;
        if ($ttId) {
            $s = $pdo->prepare('SELECT COUNT(*) FROM scheduled_slots WHERE generated_timetable_id = ? AND room_id = ?');
            $s->execute([$ttId, (int)$r['id']]); $n = (int)$s->fetchColumn();
            $s = $pdo->prepare('SELECT COUNT(DISTINCT class_id) FROM scheduled_slots WHERE generated_timetable_id = ? AND room_id = ?');
            $s->execute([$ttId, (int)$r['id']]); $cls = (int)$s->fetchColumn();
        }
        $rows[] = ['name'=>$r['name'],'type'=>$r['room_type']??'—','cap'=>$r['capacity']??'—','slots'=>$n,'classes'=>$cls];
    }
} elseif ($reportType === 'staff_deployment') {
    $title = 'Staff Deployment';
    $teachers = $pdo->prepare('SELECT * FROM teachers WHERE school_id = ? ORDER BY name');
    $teachers->execute([$schoolId]);
    foreach ($teachers->fetchAll() as $t) {
        $tid = (int)$t['id'];
        $subs = $pdo->prepare('SELECT DISTINCT name FROM subjects WHERE school_id = ? AND assigned_teacher_id = ? ORDER BY name');
        $subs->execute([$schoolId, $tid]);
        $subList = implode(', ', $subs->fetchAll(PDO::FETCH_COLUMN)) ?: '—';
        $bands = $pdo->prepare('SELECT DISTINCT b.label FROM subjects s JOIN classes c ON c.id = s.class_id JOIN bands b ON b.id = c.band_id WHERE s.school_id = ? AND s.assigned_teacher_id = ? ORDER BY b.label');
        $bands->execute([$schoolId, $tid]);
        $bandList = implode(', ', $bands->fetchAll(PDO::FETCH_COLUMN)) ?: '—';
        $rows[] = ['name'=>$t['name'],'staff'=>$t['staff_id']??'—','subjects'=>$subList,'bands'=>$bandList];
    }
} elseif ($reportType === 'class_coverage') {
    $title = 'Class Coverage';
    $classes = $pdo->prepare('SELECT c.*, b.label AS band_label FROM classes c LEFT JOIN bands b ON b.id = c.band_id WHERE c.school_id = ? AND c.active = TRUE ORDER BY c.name');
    $classes->execute([$schoolId]);
    foreach ($classes->fetchAll() as $c) {
        $cid = (int)$c['id'];
        $sub = $pdo->prepare('SELECT COUNT(*), COALESCE(SUM(lessons_per_week),0) FROM subjects WHERE school_id = ? AND class_id = ?');
        $sub->execute([$schoolId, $cid]);
        [$sn, $lessons] = $sub->fetch(PDO::FETCH_NUM) ?: [0,0];
        $slots = 0;
        if ($ttId) {
            $s = $pdo->prepare('SELECT COUNT(*) FROM scheduled_slots WHERE generated_timetable_id = ? AND class_id = ?');
            $s->execute([$ttId, $cid]); $slots = (int)$s->fetchColumn();
        }
        $cov = ((int)$lessons > 0) ? (int)round(100 * $slots / max(1,(int)$lessons)) : 0;
        $rows[] = ['name'=>$c['name'],'band'=>$c['band_label']??'—','subjects'=>(int)$sn,'lessons'=>(int)$lessons,'slots'=>$slots,'cov'=>$cov];
    }
} elseif ($reportType === 'school_statistics') {
    $title = 'School Statistics';
    $cnt = function($sql,$p=[]) use ($pdo) { $s=$pdo->prepare($sql); $s->execute($p); return (int)$s->fetchColumn(); };
    $rows = [
        'Teachers' => $cnt('SELECT COUNT(*) FROM teachers WHERE school_id = ?', [$schoolId]),
        'Rooms' => $cnt('SELECT COUNT(*) FROM rooms WHERE school_id = ?', [$schoolId]),
        'Active classes' => $cnt('SELECT COUNT(*) FROM classes WHERE school_id = ? AND active = TRUE', [$schoolId]),
        'Subjects' => $cnt('SELECT COUNT(*) FROM subjects WHERE school_id = ?', [$schoolId]),
        'Active bands' => $cnt('SELECT COUNT(*) FROM bands WHERE school_id = ? AND active = TRUE', [$schoolId]),
        'Scheduled slots' => $ttId ? $cnt('SELECT COUNT(*) FROM scheduled_slots WHERE generated_timetable_id = ?', [$ttId]) : 0,
        'Successful generations' => $cnt("SELECT COUNT(*) FROM generated_timetables WHERE school_id = ? AND status = 'success'", [$schoolId]),
    ];
} else {
    $reportType = 'teacher_workload';
    $teachers = $pdo->prepare('SELECT * FROM teachers WHERE school_id = ? ORDER BY name');
    $teachers->execute([$schoolId]);
    foreach ($teachers->fetchAll() as $t) {
        $tid = (int)$t['id'];
        $sub = $pdo->prepare('SELECT COUNT(*), COALESCE(SUM(lessons_per_week),0) FROM subjects WHERE school_id = ? AND assigned_teacher_id = ?');
        $sub->execute([$schoolId, $tid]);
        [$sn, $lessons] = $sub->fetch(PDO::FETCH_NUM) ?: [0,0];
        $cls = $pdo->prepare('SELECT COUNT(DISTINCT class_id) FROM subjects WHERE school_id = ? AND assigned_teacher_id = ?');
        $cls->execute([$schoolId, $tid]);
        $slots = 0;
        if ($ttId) {
            $s = $pdo->prepare('SELECT COUNT(*) FROM scheduled_slots WHERE generated_timetable_id = ? AND teacher_id = ?');
            $s->execute([$ttId, $tid]); $slots = (int)$s->fetchColumn();
        }
        $max = $t['max_lessons_per_week'] !== null ? (int)$t['max_lessons_per_week'] : null;
        $rows[] = ['name'=>$t['name'],'staff'=>$t['staff_id']??'—','max'=>$max,'subjects'=>(int)$sn,'lessons'=>(int)$lessons,'classes'=>(int)$cls->fetchColumn(),'slots'=>$slots];
    }
}

if ($format === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="report.csv"');
    $out = fopen('php://output', 'w');
    if ($reportType === 'school_statistics') {
        fputcsv($out, ['Metric','Value']);
        foreach ($rows as $k=>$v) fputcsv($out, [$k, $v]);
    } else {
        if ($rows) fputcsv($out, array_keys($rows[0]));
        foreach ($rows as $r) fputcsv($out, array_values($r));
    }
    fclose($out); exit;
}

$pageTitle = $title . ' — ' . ($school['name'] ?? 'School');
require __DIR__ . '/_header.php';
$nav = ['teacher_workload'=>'Teacher Workload','room_utilization'=>'Room Utilization','staff_deployment'=>'Staff Deployment','class_coverage'=>'Class Coverage','school_statistics'=>'School Statistics'];
?>
<div class="card">
<h2><?php echo htmlspecialchars($title); ?></h2>
<p style="color:var(--text-muted);margin-bottom:12px;">Live data from school records and the latest successful timetable.</p>
<div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:16px;">
<?php foreach ($nav as $k=>$lab): ?>
<a class="btn <?php echo $reportType===$k?'':'btn-secondary'; ?>" href="?report=<?php echo urlencode($k); ?>"><?php echo htmlspecialchars($lab); ?></a>
<?php endforeach; ?>
<a class="btn btn-secondary" href="?report=<?php echo urlencode($reportType); ?>&format=csv">Export CSV</a>
</div>
<?php if ($reportType === 'school_statistics'): ?>
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px;">
<?php foreach ($rows as $lab=>$val): ?>
<div style="background:var(--bg-white);border:1px solid var(--border);border-radius:8px;padding:16px;text-align:center;">
<div style="font-size:1.75rem;font-weight:700;color:var(--primary-dark);"><?php echo (int)$val; ?></div>
<div style="color:var(--text-muted);font-size:0.85rem;"><?php echo htmlspecialchars($lab); ?></div>
</div>
<?php endforeach; ?>
</div>
<?php else: ?>
<table>
<thead><tr><?php if ($rows): foreach (array_keys($rows[0]) as $h): ?><th><?php echo htmlspecialchars(ucwords(str_replace('_',' ',$h))); ?></th><?php endforeach; endif; ?></tr></thead>
<tbody>
<?php if (!$rows): ?><tr><td class="empty">No data.</td></tr>
<?php else: foreach ($rows as $r): ?><tr><?php foreach ($r as $v): ?><td><?php echo htmlspecialchars((string)$v); ?></td><?php endforeach; ?></tr><?php endforeach; endif; ?>
</tbody>
</table>
<?php endif; ?>
</div>
<?php require __DIR__ . '/_footer.php'; ?>


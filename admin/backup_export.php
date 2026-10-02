<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../db/db.php';

$schoolId = requireLoginAndGetSchoolId();
$stmt = db()->prepare('SELECT * FROM schools WHERE id = ?');
$stmt->execute([$schoolId]);
$school = $stmt->fetch();
if (!$school) {
    http_response_code(404);
    exit('School not found');
}

$tables = [
    'teachers' => 'SELECT * FROM teachers WHERE school_id = ?',
    'rooms' => 'SELECT * FROM rooms WHERE school_id = ?',
    'bands' => 'SELECT * FROM bands WHERE school_id = ?',
    'classes' => 'SELECT * FROM classes WHERE school_id = ?',
    'subjects' => 'SELECT * FROM subjects WHERE school_id = ?',
    'school_calendar' => 'SELECT * FROM school_calendar WHERE school_id = ?',
    'school_holidays' => 'SELECT * FROM school_holidays WHERE school_id = ?',
    'generated_timetables' => 'SELECT id, school_id, status, generated_at, html_output_path, triggered_by_type FROM generated_timetables WHERE school_id = ? ORDER BY generated_at DESC LIMIT 20',
];

$payload = [
    'exported_at' => gmdate('c'),
    'schema_version' => 1,
    'school' => $school,
    'data' => [],
];

foreach ($tables as $name => $sql) {
    try {
        $s = db()->prepare($sql);
        $s->execute([$schoolId]);
        $payload['data'][$name] = $s->fetchAll();
    } catch (Throwable $e) {
        $payload['data'][$name] = ['error' => 'unavailable'];
    }
}

try {
    $s = db()->prepare(
        "SELECT id FROM generated_timetables WHERE school_id = ? AND status = 'success' ORDER BY generated_at DESC LIMIT 1"
    );
    $s->execute([$schoolId]);
    $ttId = (int) ($s->fetchColumn() ?: 0);
    if ($ttId > 0) {
        $s2 = db()->prepare('SELECT * FROM scheduled_slots WHERE generated_timetable_id = ?');
        $s2->execute([$ttId]);
        $payload['data']['scheduled_slots'] = $s2->fetchAll();
        $payload['data']['scheduled_slots_timetable_id'] = $ttId;
    }
} catch (Throwable $e) {
    $payload['data']['scheduled_slots'] = [];
}

$safeName = preg_replace('/[^a-zA-Z0-9_-]+/', '-', (string) ($school['name'] ?? 'school'));
$filename = 'educore-backup-' . $safeName . '-' . gmdate('Ymd\THis') . 'Z.json';

header('Content-Type: application/json; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store');
echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
exit;

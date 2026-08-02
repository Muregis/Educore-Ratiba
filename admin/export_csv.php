<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../db/db.php';

$schoolId = requireLoginAndGetSchoolId();
$stmt = db()->prepare('SELECT * FROM schools WHERE id = ?');
$stmt->execute([$schoolId]);
$school = $stmt->fetch();

$entity = $_GET['entity'] ?? '';
$format = $_GET['format'] ?? 'csv';

// If no entity specified, show a selection page
if ($entity === '') {
    $pageTitle = 'Export Data — ' . $school['name'];
    require __DIR__ . '/_header.php';
    ?>
    <div class="card">
        <h2>Export Data</h2>
        <p class="empty">Select what you want to export:</p>
        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); gap: 12px; margin-top: 20px;">
            <a href="export_csv.php?entity=teachers&format=csv" class="btn" style="text-align: left;">👨‍🏫 Teachers (CSV)</a>
            <a href="export_csv.php?entity=teachers&format=xlsx" class="btn" style="text-align: left;">👨‍🏫 Teachers (Excel)</a>
            <a href="export_csv.php?entity=rooms&format=csv" class="btn" style="text-align: left;">🏫 Rooms (CSV)</a>
            <a href="export_csv.php?entity=rooms&format=xlsx" class="btn" style="text-align: left;">🏫 Rooms (Excel)</a>
            <a href="export_csv.php?entity=bands&format=csv" class="btn" style="text-align: left;">📚 Bands (CSV)</a>
            <a href="export_csv.php?entity=bands&format=xlsx" class="btn" style="text-align: left;">📚 Bands (Excel)</a>
            <a href="export_csv.php?entity=classes&format=csv" class="btn" style="text-align: left;">🎓 Classes (CSV)</a>
            <a href="export_csv.php?entity=classes&format=xlsx" class="btn" style="text-align: left;">🎓 Classes (Excel)</a>
            <a href="export_csv.php?entity=subjects&format=csv" class="btn" style="text-align: left;">📖 Subjects (CSV)</a>
            <a href="export_csv.php?entity=subjects&format=xlsx" class="btn" style="text-align: left;">📖 Subjects (Excel)</a>
            <a href="export_csv.php?entity=activities&format=csv" class="btn" style="text-align: left;">🎯 Activities (CSV)</a>
            <a href="export_csv.php?entity=activities&format=xlsx" class="btn" style="text-align: left;">🎯 Activities (Excel)</a>
            <a href="export_csv.php?entity=remedials&format=csv" class="btn" style="text-align: left;">📝 Remedials (CSV)</a>
            <a href="export_csv.php?entity=remedials&format=xlsx" class="btn" style="text-align: left;">📝 Remedials (Excel)</a>
            <a href="export_csv.php?entity=audit_log&format=csv" class="btn" style="text-align: left;">🔍 Audit Log (CSV)</a>
            <a href="export_csv.php?entity=audit_log&format=xlsx" class="btn" style="text-align: left;">🔍 Audit Log (Excel)</a>
        </div>
    </div>
    <?php
    require __DIR__ . '/_footer.php';
    exit;
}

// ... rest of the export logic continues below ...

$data = [];
$headers = [];
$filename = '';

switch ($entity) {
    case 'teachers':
        $filename = 'teachers_export.csv';
        $headers = ['Name', 'Staff ID', 'Max Lessons/Week', 'Subjects'];
        if ($search !== '') {
            $searchParam = '%' . $search . '%';
            $stmt = db()->prepare('SELECT t.name, t.staff_id, t.max_lessons_per_week, GROUP_CONCAT(DISTINCT s.name SEPARATOR ", ") as subjects FROM teachers t LEFT JOIN subjects s ON t.id = s.teacher_id WHERE t.school_id = ? AND (t.name LIKE ? OR t.staff_id LIKE ?) GROUP BY t.id ORDER BY t.name');
            $stmt->execute([$schoolId, $searchParam, $searchParam]);
        } else {
            $stmt = db()->prepare('SELECT t.name, t.staff_id, t.max_lessons_per_week, GROUP_CONCAT(DISTINCT s.name SEPARATOR ", ") as subjects FROM teachers t LEFT JOIN subjects s ON t.id = s.teacher_id WHERE t.school_id = ? GROUP BY t.id ORDER BY t.name');
            $stmt->execute([$schoolId]);
        }
        $data = $stmt->fetchAll();
        break;
        
    case 'rooms':
        $filename = 'rooms_export.csv';
        $headers = ['Name', 'Capacity', 'Type'];
        if ($search !== '') {
            $searchParam = '%' . $search . '%';
            $stmt = db()->prepare('SELECT name, capacity, room_type FROM rooms WHERE school_id = ? AND (name LIKE ? OR room_type LIKE ?) ORDER BY name');
            $stmt->execute([$schoolId, $searchParam, $searchParam]);
        } else {
            $stmt = db()->prepare('SELECT name, capacity, room_type FROM rooms WHERE school_id = ? ORDER BY name');
            $stmt->execute([$schoolId]);
        }
        $data = $stmt->fetchAll();
        break;
        
    case 'bands':
        $filename = 'bands_export.csv';
        $headers = ['Key', 'Label', 'Lessons/Day', 'Duration (min)', 'Status'];
        if ($search !== '' || $statusFilter !== '') {
            $sql = 'SELECT band_key, label, lessons_per_day, lesson_length_minutes, active FROM bands WHERE school_id = ?';
            $params = [$schoolId];
            if ($search !== '') {
                $sql .= ' AND (band_key LIKE ? OR label LIKE ?)';
                $searchParam = '%' . $search . '%';
                $params[] = $searchParam;
                $params[] = $searchParam;
            }
            if ($statusFilter !== '') {
                $sql .= ' AND active = ?';
                $params[] = ($statusFilter === 'active' ? 1 : 0);
            }
            $sql .= ' ORDER BY band_key';
            $stmt = db()->prepare($sql);
            $stmt->execute($params);
        } else {
            $stmt = db()->prepare('SELECT band_key, label, lessons_per_day, lesson_length_minutes, active FROM bands WHERE school_id = ? ORDER BY band_key');
            $stmt->execute([$schoolId]);
        }
        $data = $stmt->fetchAll();
        break;
        
    case 'classes':
        $filename = 'classes_export.csv';
        $headers = ['Name', 'Band', 'Room', 'Student Count', 'Status'];
        if ($search !== '' || $bandFilter !== 0 || $statusFilter !== '') {
            $sql = 'SELECT c.name, b.label as band_label, r.name as room_name, c.student_count, c.active FROM classes c LEFT JOIN bands b ON c.band_id = b.id LEFT JOIN rooms r ON c.room_id = r.id WHERE c.school_id = ?';
            $params = [$schoolId];
            if ($search !== '') {
                $sql .= ' AND c.name LIKE ?';
                $searchParam = '%' . $search . '%';
                $params[] = $searchParam;
            }
            if ($bandFilter !== 0) {
                $sql .= ' AND c.band_id = ?';
                $params[] = $bandFilter;
            }
            if ($statusFilter !== '') {
                $sql .= ' AND c.active = ?';
                $params[] = ($statusFilter === 'active' ? 1 : 0);
            }
            $sql .= ' ORDER BY c.name';
            $stmt = db()->prepare($sql);
            $stmt->execute($params);
        } else {
            $stmt = db()->prepare('SELECT c.name, b.label as band_label, r.name as room_name, c.student_count, c.active FROM classes c LEFT JOIN bands b ON c.band_id = b.id LEFT JOIN rooms r ON c.room_id = r.id WHERE c.school_id = ? ORDER BY c.name');
            $stmt->execute([$schoolId]);
        }
        $data = $stmt->fetchAll();
        break;
        
    case 'subjects':
        $filename = 'subjects_export.csv';
        $headers = ['Class', 'Band', 'Subject', 'Lessons/Week', 'Teacher'];
        if ($search !== '' || $classFilter !== 0) {
            $sql = 'SELECT c.name as class_name, b.label as band_label, s.name as subject_name, s.lessons_per_week, t.name as teacher_name FROM subjects s LEFT JOIN classes c ON s.class_id = c.id LEFT JOIN bands b ON s.band_id = b.id LEFT JOIN teachers t ON s.assigned_teacher_id = t.id WHERE s.school_id = ?';
            $params = [$schoolId];
            if ($search !== '') {
                $sql .= ' AND s.name LIKE ?';
                $searchParam = '%' . $search . '%';
                $params[] = $searchParam;
            }
            if ($classFilter !== 0) {
                $sql .= ' AND s.class_id = ?';
                $params[] = $classFilter;
            }
            $sql .= ' ORDER BY c.name, s.name';
            $stmt = db()->prepare($sql);
            $stmt->execute($params);
        } else {
            $stmt = db()->prepare('SELECT c.name as class_name, b.label as band_label, s.name as subject_name, s.lessons_per_week, t.name as teacher_name FROM subjects s LEFT JOIN classes c ON s.class_id = c.id LEFT JOIN bands b ON s.band_id = b.id LEFT JOIN teachers t ON s.assigned_teacher_id = t.id WHERE s.school_id = ? ORDER BY c.name, s.name');
            $stmt->execute([$schoolId]);
        }
        $data = $stmt->fetchAll();
        break;
        
    case 'activities':
        $filename = 'activities_export.csv';
        $headers = ['Class', 'Activity', 'Day', 'Duration (slots)', 'Frequency', 'Teacher', 'Room'];
        if ($search !== '' || $classFilter !== 0) {
            $sql = 'SELECT c.name as class_name, ea.name as activity_name, ea.day_of_week, ea.duration_slots, ea.frequency, t.name as teacher_name, r.name as room_name FROM extra_activities ea LEFT JOIN classes c ON ea.class_id = c.id LEFT JOIN teachers t ON ea.assigned_teacher_id = t.id LEFT JOIN rooms r ON ea.room_id = r.id WHERE ea.school_id = ?';
            $params = [$schoolId];
            if ($search !== '') {
                $sql .= ' AND ea.name LIKE ?';
                $searchParam = '%' . $search . '%';
                $params[] = $searchParam;
            }
            if ($classFilter !== 0) {
                $sql .= ' AND ea.class_id = ?';
                $params[] = $classFilter;
            }
            $sql .= ' ORDER BY c.name, ea.name';
            $stmt = db()->prepare($sql);
            $stmt->execute($params);
        } else {
            $stmt = db()->prepare('SELECT c.name as class_name, ea.name as activity_name, ea.day_of_week, ea.duration_slots, ea.frequency, t.name as teacher_name, r.name as room_name FROM extra_activities ea LEFT JOIN classes c ON ea.class_id = c.id LEFT JOIN teachers t ON ea.assigned_teacher_id = t.id LEFT JOIN rooms r ON ea.room_id = r.id WHERE ea.school_id = ? ORDER BY c.name, ea.name');
            $stmt->execute([$schoolId]);
        }
        $data = $stmt->fetchAll();
        break;
        
    case 'remedials':
        $filename = 'remedials_export.csv';
        $headers = ['Class', 'Subject', 'Teacher', 'Type', 'Day', 'Room'];
        if ($search !== '' || $classFilter !== 0 || $sessionTypeFilter !== '') {
            $sql = 'SELECT c.name as class_name, s.name as subject_name, t.name as teacher_name, rs.session_type, rs.day_of_week, r.name as room_name FROM remedial_sessions rs LEFT JOIN classes c ON rs.class_id = c.id LEFT JOIN subjects s ON rs.subject_id = s.id LEFT JOIN teachers t ON rs.teacher_id = t.id LEFT JOIN rooms r ON rs.room_id = r.id WHERE rs.school_id = ?';
            $params = [$schoolId];
            if ($search !== '') {
                $sql .= ' AND (s.name LIKE ? OR t.name LIKE ?)';
                $searchParam = '%' . $search . '%';
                $params[] = $searchParam;
                $params[] = $searchParam;
            }
            if ($classFilter !== 0) {
                $sql .= ' AND rs.class_id = ?';
                $params[] = $classFilter;
            }
            if ($sessionTypeFilter !== '') {
                $sql .= ' AND rs.session_type = ?';
                $params[] = $sessionTypeFilter;
            }
            $sql .= ' ORDER BY c.name, rs.session_type, rs.day_of_week';
            $stmt = db()->prepare($sql);
            $stmt->execute($params);
        } else {
            $stmt = db()->prepare('SELECT c.name as class_name, s.name as subject_name, t.name as teacher_name, rs.session_type, rs.day_of_week, r.name as room_name FROM remedial_sessions rs LEFT JOIN classes c ON rs.class_id = c.id LEFT JOIN subjects s ON rs.subject_id = s.id LEFT JOIN teachers t ON rs.teacher_id = t.id LEFT JOIN rooms r ON rs.room_id = r.id WHERE rs.school_id = ? ORDER BY c.name, rs.session_type, rs.day_of_week');
            $stmt->execute([$schoolId]);
        }
        $data = $stmt->fetchAll();
        break;
        
    case 'audit_log':
        $filename = 'audit_log_export.csv';
        $headers = ['Date/Time', 'Admin', 'Admin Type', 'Action', 'Entity', 'Entity ID', 'Details', 'IP Address'];
        $sql = 'SELECT al.*,
                CASE WHEN al.admin_type = "school_admin" THEN sa.username ELSE su.username END as admin_name
                FROM audit_log al
                LEFT JOIN school_admins sa ON al.admin_type = "school_admin" AND al.admin_id = sa.id
                LEFT JOIN super_admins su ON al.admin_type = "super_admin" AND al.admin_id = su.id
                WHERE al.school_id = ?';
        $params = [$schoolId];
        if ($search !== '') {
            $sql .= ' AND (al.action LIKE ? OR al.entity LIKE ? OR al.details LIKE ?)';
            $searchParam = '%' . $search . '%';
            $params[] = $searchParam;
            $params[] = $searchParam;
            $params[] = $searchParam;
        }
        if ($actionFilter !== '') {
            $sql .= ' AND al.action = ?';
            $params[] = $actionFilter;
        }
        if ($entityFilter !== '') {
            $sql .= ' AND al.entity = ?';
            $params[] = $entityFilter;
        }
        $sql .= ' ORDER BY al.created_at DESC LIMIT 1000';
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        $rawData = $stmt->fetchAll();
        $data = array_map(function($row) {
            return [
                $row['created_at'],
                $row['admin_name'] ?? 'Unknown',
                ucfirst($row['admin_type']),
                ucfirst($row['action']),
                ucfirst($row['entity']),
                $row['entity_id'] ?? '—',
                $row['details'] ?? '—',
                $row['ip_address'] ?? '—'
            ];
        }, $rawData);
        break;

    default:
        http_response_code(400);
        echo '<h3>Invalid Export Entity</h3>';
        echo '<p>Unknown entity type: <code>' . htmlspecialchars($entity) . '</code></p>';
        echo '<p>Supported entities: teachers, rooms, bands, classes, subjects, activities, remedials, audit_log</p>';
        exit;
}

// XLSX Export Handler
if ($format === 'xlsx') {
    exportXlsx($data, $headers, str_replace('.csv', '.xlsx', $filename));
    exit;
}

header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

$output = fopen('php://output', 'w');
fputcsv($output, $headers);

foreach ($data as $row) {
    $exportRow = [];
    foreach ($row as $value) {
        if ($value === null) {
            $exportRow[] = '';
        } elseif (is_bool($value)) {
            $exportRow[] = $value ? 'Yes' : 'No';
        } else {
            $exportRow[] = $value;
        }
    }
    fputcsv($output, $exportRow);
}

fclose($output);
exit;

/**
 * Generates an XLSX file from data using ZipArchive.
 * XLSX is a ZIP archive containing XML files per the OpenXML specification.
 */
function exportXlsx(array $data, array $headers, string $filename): void
{
    if (!class_exists('ZipArchive')) {
        http_response_code(500);
        die('XLSX export requires the ZipArchive PHP extension.');
    }

    $tempDir = sys_get_temp_dir() . '/xlsx_' . uniqid();
    @mkdir($tempDir, 0777, true);

    try {
        // [Content_Types].xml
        $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
    <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
    <Default Extension="xml" ContentType="application/xml"/>
    <Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
    <Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
    <Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>
</Types>';

        // _rels/.rels
        $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
    <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
</Relationships>';

        // xl/_rels/workbook.xml.rels
        $workbookRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
    <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
    <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>
</Relationships>';

        // Build shared strings
        $strings = [];
        $stringIndices = [];
        
        function getStringIndex(string $str, array &$strings, array &$stringIndices): int {
            if (isset($stringIndices[$str])) {
                return $stringIndices[$str];
            }
            $idx = count($strings);
            $strings[] = $str;
            $stringIndices[$str] = $idx;
            return $idx;
        }

        // Collect all strings
        foreach ($headers as $header) {
            getStringIndex((string)$header, $strings, $stringIndices);
        }
        foreach ($data as $row) {
            foreach ($row as $value) {
                if ($value !== null) {
                    getStringIndex((string)$value, $strings, $stringIndices);
                }
            }
        }

        $sharedStringsXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="' . count($strings) . '" uniqueCount="' . count($strings) . '">';
        foreach ($strings as $str) {
            $escaped = htmlspecialchars($str, ENT_XML1 | ENT_QUOTES, 'UTF-8');
            $sharedStringsXml .= "<si><t>{$escaped}</t></si>";
        }
        $sharedStringsXml .= '</sst>';

        // Build worksheet XML
        $worksheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
        $worksheetXml .= '<sheetData>';

        // Header row
        $worksheetXml .= '<row r="1">';
        foreach ($headers as $colIndex => $header) {
            $stringIdx = getStringIndex((string)$header, $strings, $stringIndices);
            $colLetter = chr(65 + $colIndex);
            $worksheetXml .= '<c r="' . $colLetter . '1" t="s"><v>' . $stringIdx . '</v></c>';
        }
        $worksheetXml .= '</row>';

        // Data rows
        foreach ($data as $rowIndex => $row) {
            $rowNum = $rowIndex + 2;
            $worksheetXml .= '<row r="' . $rowNum . '">';
            foreach ($row as $colIndex => $value) {
                $colLetter = chr(65 + $colIndex);
                if ($value === null || $value === '') {
                    $worksheetXml .= '<c r="' . $colLetter . $rowNum . '"><v></v></c>';
                } elseif (is_bool($value)) {
                    $worksheetXml .= '<c r="' . $colLetter . $rowNum . '" t="b"><v>' . ($value ? '1' : '0') . '</v></c>';
                } elseif (is_numeric($value)) {
                    $worksheetXml .= '<c r="' . $colLetter . $rowNum . '" t="n"><v>' . htmlspecialchars((string)$value, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</v></c>';
                } else {
                    $stringIdx = getStringIndex((string)$value, $strings, $stringIndices);
                    $worksheetXml .= '<c r="' . $colLetter . $rowNum . '" t="s"><v>' . $stringIdx . '</v></c>';
                }
            }
            $worksheetXml .= '</row>';
        }
        $worksheetXml .= '</sheetData>';
        $worksheetXml .= '</worksheet>';

        // workbook.xml
        $workbookXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
    <sheets>
        <sheet name="Sheet1" sheetId="1" r:id="rId1"/>
    </sheets>
</workbook>';

        // Create ZIP
        $zip = new ZipArchive();
        $zipFile = $tempDir . '/' . $filename;
        if ($zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not create XLSX file.');
        }

        $zip->addFromString('[Content_Types].xml', $contentTypes);
        $zip->addFromString('_rels/.rels', $rels);
        $zip->addFromString('xl/workbook.xml', $workbookXml);
        $zip->addFromString('xl/_rels/workbook.xml.rels', $workbookRels);
        $zip->addFromString('xl/worksheets/sheet1.xml', $worksheetXml);
        $zip->addFromString('xl/sharedStrings.xml', $sharedStringsXml);
        $zip->close();

        // Output file
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($zipFile));
        header('Pragma: no-cache');
        header('Expires: 0');
        readfile($zipFile);
    } finally {
        @unlink($tempDir . '/[Content_Types].xml');
        @unlink($tempDir . '/_rels/.rels');
        @unlink($tempDir . '/xl/workbook.xml');
        @unlink($tempDir . '/xl/_rels/workbook.xml.rels');
        @unlink($tempDir . '/xl/worksheets/sheet1.xml');
        @unlink($tempDir . '/xl/sharedStrings.xml');
        @rmdir($tempDir . '/xl/worksheets');
        @rmdir($tempDir . '/xl/_rels');
        @rmdir($tempDir . '/xl');
        @rmdir($tempDir . '/_rels');
        @rmdir($tempDir);
    }
}

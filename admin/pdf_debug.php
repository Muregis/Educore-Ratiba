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

echo "<h2>PDF Export Diagnostic</h2>";

if (!$latestGeneration) {
    echo "<p style='color:red'>No generation found</p>";
    exit;
}

echo "<p><strong>Generation ID:</strong> {$latestGeneration['id']}</p>";
echo "<p><strong>Status:</strong> {$latestGeneration['status']}</p>";
echo "<p><strong>HTML path:</strong> " . htmlspecialchars($latestGeneration['html_output_path'] ?? 'null') . "</p>";

$htmlFullPath = 'C:/laragon/www/fet-timetable/' . ltrim($latestGeneration['html_output_path'] ?? '', '/');
echo "<p><strong>Full path:</strong> " . htmlspecialchars($htmlFullPath) . "</p>";
echo "<p><strong>File exists:</strong> " . (file_exists($htmlFullPath) ? 'YES' : 'NO') . "</p>";

// Try to resolve the actual timetable file
$timetablePath = resolveFetchTimetableHtml($htmlFullPath);
echo "<p><strong>Timetable HTML path:</strong> " . htmlspecialchars($timetablePath ?? 'null') . "</p>";
if ($timetablePath) {
    echo "<p><strong>Timetable file exists:</strong> " . (file_exists($timetablePath) ? 'YES' : 'NO') . "</p>";
}

// Check scheduled_slots
$stmt = db()->prepare('SELECT COUNT(*) as cnt FROM scheduled_slots WHERE generated_timetable_id = ?');
$stmt->execute([$latestGeneration['id']]);
$slotCount = (int) $stmt->fetchColumn();
echo "<p><strong>Scheduled slots:</strong> {$slotCount}</p>";

// Check classes
$stmt = db()->prepare('SELECT COUNT(*) as cnt FROM classes WHERE school_id = ? AND active = 1');
$stmt->execute([$schoolId]);
$classCount = (int) $stmt->fetchColumn();
echo "<p><strong>Active classes:</strong> {$classCount}</p>";

if ($slotCount > 0) {
    echo "<p style='color:green'>✓ PDF should work via scheduled_slots</p>";
} elseif ($timetablePath && file_exists($timetablePath)) {
    echo "<p style='color:orange'>⚠ Will use FET HTML fallback</p>";
} else {
    echo "<p style='color:red'>✗ No data source available for PDF</p>";
}

/**
 * Resolves the actual FET timetable HTML file from the stored index/TOC
 * file. Returns the full path to `_years_days_horizontal.html`, or null
 * if it cannot be found.
 */
function resolveFetchTimetableHtml(string $indexHtmlPath): ?string
{
    if (!file_exists($indexHtmlPath)) {
        return null;
    }

    $dir = dirname($indexHtmlPath);
    $base = basename($indexHtmlPath, '_index.html');
    $direct = $dir . '/' . $base . '_years_days_horizontal.html';
    if (file_exists($direct)) {
        return $direct;
    }

    $dom = new DOMDocument();
    @$dom->loadHTMLFile($indexHtmlPath);
    $xpath = new DOMXPath($dom);

    $rows = $xpath->query('//table[caption]//tr');
    foreach ($rows as $row) {
        $cells = $xpath->query('.//td', $row);
        $firstText = '';
        if ($cells->length > 0) {
            $firstText = trim($cells->item(0)->textContent);
        }
        if ($firstText === 'Years' && $cells->length >= 2) {
            $link = $xpath->query('.//a', $cells->item(1))->item(0);
            if ($link && $link->hasAttribute('href')) {
                $href = $link->getAttribute('href');
                $resolved = $dir . '/' . $href;
                if (file_exists($resolved)) {
                    return $resolved;
                }
            }
        }
    }

    return null;
}
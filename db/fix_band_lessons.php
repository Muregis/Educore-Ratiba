<?php
declare(strict_types=1);
/**
 * db/fix_band_lessons.php — one-time migration
 *
 * Updates lessons_per_day to match the actual number of teaching slots
 * in the FET XML templates (not the MOE "lessons per day" comment).
 *
 * XML slot counts:
 * - grade-1-3:   9 total slots - 2 breaks = 7 teaching slots
 * - grade-7-9:  11 total slots - 2 breaks = 9 teaching slots
 * - form-3-4:   11 total slots - 2 breaks = 9 teaching slots
 *
 * Usage: visit this file in a browser or run: php db/fix_band_lessons.php
 * After running, DELETE this file for security.
 */

require_once __DIR__ . '/db.php';

if (php_sapi_name() !== 'cli') {
    echo '<!DOCTYPE html><html><head><title>Fix Band Lessons</title></head><body>';
}

// Actual teaching slot counts from FET XML templates
$bandLessons = [
    'pp1-pp2'      => 5,  // 6 hours - 1 break = 5 teaching slots
    'grade-1-3'    => 7,  // 9 hours - 2 breaks = 7 teaching slots
    'grade-4-6'    => 7,  // 8 hours - 1 break = 7 teaching slots
    'grade-7-9'    => 9,  // 11 hours - 2 breaks = 9 teaching slots
    'grade-10-12'  => 8,  // 10 hours - 2 breaks = 8 teaching slots
    'form-3-4'     => 9,  // 11 hours - 2 breaks = 9 teaching slots
];

echo "<h2>Fixing band lessons_per_day to match XML templates</h2>\n";

foreach ($bandLessons as $bandKey => $lessonsPerDay) {
    $stmt = db()->prepare(
        'UPDATE bands SET lessons_per_day = ? WHERE band_key = ? AND lessons_per_day != ?'
    );
    $stmt->execute([$lessonsPerDay, $bandKey, $lessonsPerDay]);
    $updated = $stmt->rowCount();
    echo "<p>Updated <strong>{$bandKey}</strong> to <strong>{$lessonsPerDay}</strong> lessons/day ({$updated} row(s) changed).</p>\n";
}

echo "<p><strong>Done.</strong></p>\n";
echo "<p><strong>Next step:</strong> Regenerate the timetable from the Generate page.</p>\n";

if (php_sapi_name() !== 'cli') {
    echo '</body></html>';
}

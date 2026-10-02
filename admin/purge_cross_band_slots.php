<?php
declare(strict_types=1);

/**
 * After FET import: drop scheduled_slots whose hour_slot prefix
 * does not match the class's band_key.
 */
function purgeCrossBandSlots(int $generatedTimetableId, int $schoolId): int
{
    $bands = getBands($schoolId);
    $bandKeyById = [];
    foreach ($bands as $b) {
        $bandKeyById[(int) $b['id']] = (string) ($b['band_key'] ?? '');
    }

    $classes = getClasses($schoolId);
    $prefixByClassId = [];
    foreach ($classes as $c) {
        $bid = (int) ($c['band_id'] ?? 0);
        $prefixByClassId[(int) $c['id']] = $bandKeyById[$bid] ?? '';
    }

    $stmt = db()->prepare(
        'SELECT id, class_id, hour_slot FROM scheduled_slots WHERE generated_timetable_id = ?'
    );
    $stmt->execute([$generatedTimetableId]);
    $rows = $stmt->fetchAll();
    if ($rows === []) {
        return 0;
    }

    $badIds = [];
    foreach ($rows as $row) {
        $cid = (int) $row['class_id'];
        $prefix = $prefixByClassId[$cid] ?? '';
        $hour = (string) $row['hour_slot'];
        if ($prefix === '') {
            continue;
        }
        if (strpos($hour, $prefix . '__') !== 0) {
            $badIds[] = (int) $row['id'];
        }
    }

    if ($badIds === []) {
        return 0;
    }

    $removed = 0;
    foreach (array_chunk($badIds, 200) as $chunk) {
        $ph = implode(',', array_fill(0, count($chunk), '?'));
        $del = db()->prepare("DELETE FROM scheduled_slots WHERE id IN ($ph)");
        $del->execute($chunk);
        $removed += $del->rowCount();
    }
    return $removed;
}

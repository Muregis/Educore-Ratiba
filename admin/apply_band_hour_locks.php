<?php
declare(strict_types=1);

/**
 * Post-process FET XML so each class is unavailable outside its own
 * band's teaching hours. Fixes Grade 1 landing in grade-4-6__* slots.
 */
function applyBandHourLocksToFetXml(string $xmlPath, int $schoolId): void
{
    if (!file_exists($xmlPath)) {
        return;
    }

    $bands = array_filter(getBands($schoolId), static fn($b) => (bool) ($b['active'] ?? true));
    $classes = array_filter(getClasses($schoolId), static fn($c) => (bool) ($c['active'] ?? true));
    $days = getSchoolDayNames($schoolId);
    if ($days === []) {
        $days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
    }

    $allHours = [];
    $bandTeaching = [];

    foreach ($bands as $band) {
        $prefix = (string) ($band['band_key'] ?? 'B');
        $lessonsPerDay = (int) ($band['lessons_per_day'] ?? 8);
        $lengthMin = max(15, (int) ($band['lesson_length_minutes'] ?? 40));
        $dayStart = (string) ($band['day_start_time'] ?? '08:00');
        if (!preg_match('/^\d{2}:\d{2}$/', $dayStart)) {
            $dayStart = '08:00';
        }
        $breakConfig = [];
        if (!empty($band['break_config'])) {
            $decoded = is_string($band['break_config']) ? json_decode($band['break_config'], true) : $band['break_config'];
            if (is_array($decoded)) {
                $breakConfig = $decoded;
            }
        }
        if ($breakConfig === []) {
            $breakConfig = [
                ['time' => '10:00-10:20', 'label' => 'BREAK'],
                ['time' => '12:40-13:20', 'label' => 'LUNCH'],
            ];
        }

        $parsedBreaks = [];
        foreach ($breakConfig as $bc) {
            [$bStart, $bEnd] = array_pad(explode('-', (string) ($bc['time'] ?? '')), 2, null);
            if ($bStart === null || $bEnd === null) {
                continue;
            }
            $parsedBreaks[] = [
                'start_ts' => strtotime(trim($bStart)),
                'end_ts' => strtotime(trim($bEnd)),
                'start_label' => trim($bStart),
                'end_label' => trim($bEnd),
                'label' => (string) ($bc['label'] ?? 'BREAK'),
            ];
        }
        usort($parsedBreaks, static fn($a, $b) => $a['start_ts'] <=> $b['start_ts']);

        $teaching = [];
        $current = strtotime($dayStart);
        $count = 0;
        for ($i = 0; $i < $lessonsPerDay + count($parsedBreaks) + 4; $i++) {
            if ($count >= $lessonsPerDay) {
                break;
            }
            $matched = null;
            foreach ($parsedBreaks as $pb) {
                if ($current >= $pb['start_ts'] && $current < $pb['end_ts']) {
                    $matched = $pb;
                    break;
                }
            }
            if ($matched !== null) {
                $hn = "{$prefix}__{$matched['start_label']}-{$matched['end_label']} {$matched['label']}";
                $allHours[$hn] = true;
                $current = $matched['end_ts'];
                continue;
            }
            $slotStart = date('H:i', $current);
            $slotEndTs = $current + ($lengthMin * 60);
            $slotEnd = date('H:i', $slotEndTs);
            $hn = "{$prefix}__{$slotStart}-{$slotEnd}";
            $teaching[] = $hn;
            $allHours[$hn] = true;
            $count++;
            $current = $slotEndTs;
        }
        $bandTeaching[(int) $band['id']] = $teaching;
    }

    $xml = @simplexml_load_file($xmlPath);
    if ($xml === false) {
        return;
    }

    if (!isset($xml->Time_Constraints_List)) {
        $xml->addChild('Time_Constraints_List');
    }
    $tcl = $xml->Time_Constraints_List;

    foreach ($classes as $class) {
        $bandId = (int) ($class['band_id'] ?? 0);
        $allowed = $bandTeaching[$bandId] ?? [];
        if ($allowed === []) {
            continue;
        }
        $allowedSet = array_fill_keys($allowed, true);
        $forbidden = [];
        foreach (array_keys($allHours) as $hn) {
            if (!isset($allowedSet[$hn])) {
                $forbidden[] = $hn;
            }
        }
        if ($forbidden === []) {
            continue;
        }

        $notAvail = $tcl->addChild('ConstraintStudentsSetNotAvailableTimes');
        $notAvail->addChild('Weight_Percentage', '100');
        $notAvail->addChild('Students', (string) $class['name']);
        $entries = [];
        foreach ($days as $d) {
            foreach ($forbidden as $hn) {
                $entries[] = [$d, $hn];
            }
        }
        $notAvail->addChild('Number_of_Not_Available_Times', (string) count($entries));
        foreach ($entries as [$d, $hn]) {
            $nat = $notAvail->addChild('Not_Available_Time');
            $nat->addChild('Day', $d);
            $nat->addChild('Hour', $hn);
        }
        $notAvail->addChild('Active', 'true');
    }

    $xml->asXML($xmlPath);
}

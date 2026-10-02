<?php
declare(strict_types=1);

/**
 * Post-process FET XML so each class is unavailable outside its own
 * band's teaching hours. Reads Hours_List from the XML itself so names
 * always match what buildWholeSchoolXml wrote.
 */
function applyBandHourLocksToFetXml(string $xmlPath, int $schoolId): void
{
    if (!file_exists($xmlPath)) {
        return;
    }

    $xml = @simplexml_load_file($xmlPath);
    if ($xml === false) {
        return;
    }

    // Collect every hour name from the XML Hours_List
    $allHours = [];
    if (isset($xml->Hours_List->Hour)) {
        foreach ($xml->Hours_List->Hour as $hourEl) {
            $name = trim((string) ($hourEl->Name ?? ''));
            if ($name !== '') {
                $allHours[$name] = true;
            }
        }
    }
    if ($allHours === []) {
        return;
    }

    // Group hours by band_key prefix (everything before "__")
    $hoursByPrefix = [];
    foreach (array_keys($allHours) as $hn) {
        $prefix = 'unknown';
        if (strpos($hn, '__') !== false) {
            $prefix = explode('__', $hn, 2)[0];
        }
        $hoursByPrefix[$prefix][] = $hn;
    }

    // Map band_id -> band_key from DB
    $bands = array_filter(getBands($schoolId), static fn($b) => (bool) ($b['active'] ?? true));
    $bandKeyById = [];
    foreach ($bands as $b) {
        $bandKeyById[(int) $b['id']] = (string) ($b['band_key'] ?? '');
    }

    $classes = array_filter(getClasses($schoolId), static fn($c) => (bool) ($c['active'] ?? true));
    $days = getSchoolDayNames($schoolId);
    if ($days === []) {
        $days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
    }

    if (!isset($xml->Time_Constraints_List)) {
        $xml->addChild('Time_Constraints_List');
    }
    $tcl = $xml->Time_Constraints_List;

    foreach ($classes as $class) {
        $bandId = (int) ($class['band_id'] ?? 0);
        $prefix = $bandKeyById[$bandId] ?? '';
        if ($prefix === '') {
            continue;
        }

        // Allowed = teaching hours for this prefix (exclude BREAK/LUNCH labels)
        $allowed = [];
        foreach ($hoursByPrefix[$prefix] ?? [] as $hn) {
            if (stripos($hn, 'BREAK') !== false || stripos($hn, 'LUNCH') !== false) {
                continue;
            }
            $allowed[$hn] = true;
        }
        if ($allowed === []) {
            continue;
        }

        // Forbidden = every other hour in the whole school Hours_List
        $forbidden = [];
        foreach (array_keys($allHours) as $hn) {
            if (!isset($allowed[$hn])) {
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

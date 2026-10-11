<?php
declare(strict_types=1);

/**
 * Post-process FET XML so each class has exactly ONE consolidated
 * ConstraintStudentsSetNotAvailableTimes covering both its band's break
 * slots and all other bands' hours. FET ignores duplicate constraints for
 * the same Students set, so this function deduplicates and ensures strict locks.
 */
function applyBandHourLocksToFetXml(string $xmlPath, int $schoolId): void
{
    if (!file_exists($xmlPath)) {
        return;
    }

    $dom = new DOMDocument('1.0', 'UTF-8');
    $dom->preserveWhiteSpace = false;
    $dom->formatOutput = true;
    if (!@$dom->load($xmlPath)) {
        return;
    }

    // Collect every hour name from Hours_List
    $allHours = [];
    $hourNodes = $dom->getElementsByTagName('Hour');
    foreach ($hourNodes as $hnNode) {
        $nameNodes = $hnNode->getElementsByTagName('Name');
        if ($nameNodes->length > 0) {
            $name = trim($nameNodes->item(0)->textContent);
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

    $tclList = $dom->getElementsByTagName('Time_Constraints_List');
    if ($tclList->length === 0) {
        $tclNode = $dom->createElement('Time_Constraints_List');
        $dom->documentElement->appendChild($tclNode);
    } else {
        $tclNode = $tclList->item(0);
    }

    // Remove any existing ConstraintStudentsSetNotAvailableTimes to prevent duplicates
    $existing = [];
    $cNodes = $tclNode->getElementsByTagName('ConstraintStudentsSetNotAvailableTimes');
    for ($i = 0; $i < $cNodes->length; $i++) {
        $existing[] = $cNodes->item($i);
    }
    foreach ($existing as $exNode) {
        $tclNode->removeChild($exNode);
    }

    // Insert single consolidated constraint per class
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

        $notAvail = $dom->createElement('ConstraintStudentsSetNotAvailableTimes');
        $notAvail->appendChild($dom->createElement('Weight_Percentage', '100'));
        $notAvail->appendChild($dom->createElement('Students', (string) $class['name']));
        $notAvail->appendChild($dom->createElement('Number_of_Not_Available_Times', (string) (count($days) * count($forbidden))));

        foreach ($days as $d) {
            foreach ($forbidden as $hn) {
                $nat = $dom->createElement('Not_Available_Time');
                $nat->appendChild($dom->createElement('Day', $d));
                $nat->appendChild($dom->createElement('Hour', $hn));
                $notAvail->appendChild($nat);
            }
        }
        $notAvail->appendChild($dom->createElement('Active', 'true'));
        $tclNode->appendChild($notAvail);
    }

    $dom->save($xmlPath);
}

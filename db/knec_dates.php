<?php
/**
 * KNEC Exam Period Dates
 * Standard Kenyan national examination periods
 * These are approximate and should be confirmed with KNEC each year
 */

function getKNECExamDates($year = null) {
    if ($year === null) {
        $year = (int) date('Y');
    }

    // These are typical exam periods - adjust based on actual KNEC calendar
    return [
        // KCPE (Kenya Certificate of Primary Education) - typically November
        [
            'name' => 'KCPE Examinations',
            'date' => sprintf('%d-11-01', $year), // Early November
            'type' => 'exam_period',
            'duration_days' => 5 // KCPE typically runs for about a week
        ],
        // KCSE (Kenya Certificate of Secondary Education) - typically October-November
        [
            'name' => 'KCSE Examinations Start',
            'date' => sprintf('%d-10-21', $year), // Late October
            'type' => 'exam_period',
            'duration_days' => 30 // KCSE typically runs for about a month
        ],
        // KCPA (Kenya Certificate of Primary Assessment) - for Grade 6
        [
            'name' => 'KCPA Assessments',
            'date' => sprintf('%d-11-15', $year), // Mid-November
            'type' => 'exam_period',
            'duration_days' => 3
        ]
    ];
}

function populateKNECDatesForSchool($schoolId, $year = null) {
    require_once __DIR__ . '/db.php';

    if ($year === null) {
        $year = (int) date('Y');
    }

    $examDates = getKNECExamDates($year);
    $addedCount = 0;

    foreach ($examDates as $exam) {
        $baseDate = $exam['date'];
        $duration = $exam['duration_days'];

        for ($i = 0; $i < $duration; $i++) {
            $currentDate = date('Y-m-d', strtotime($baseDate . " +$i days"));

            try {
                $stmt = db()->prepare(
                    'INSERT INTO school_holidays (school_id, holiday_name, holiday_date, holiday_type, affects_timetabling, notes) VALUES (?, ?, ?, ?, ?, ?)'
                );
                $stmt->execute([
                    $schoolId,
                    $exam['name'] . ($i > 0 ? " (Day $i+1)" : ''),
                    $currentDate,
                    $exam['type'],
                    true,
                    'KNEC examination period - scheduling blocked'
                ]);
                $addedCount++;
            } catch (Throwable $e) {
                // Date might already exist, skip
                continue;
            }
        }
    }

    return $addedCount;
}

function isDateBlockedForScheduling($schoolId, $date) {
    require_once __DIR__ . '/db.php';

    $stmt = db()->prepare(
        'SELECT COUNT(*) FROM school_holidays WHERE school_id = ? AND holiday_date = ? AND affects_timetabling = TRUE'
    );
    $stmt->execute([$schoolId, $date]);
    return $stmt->fetchColumn() > 0;
}

function getBlockedDateRange($schoolId, $startDate, $endDate) {
    require_once __DIR__ . '/db.php';

    $stmt = db()->prepare(
        'SELECT holiday_date, holiday_name FROM school_holidays WHERE school_id = ? AND holiday_date BETWEEN ? AND ? AND affects_timetabling = TRUE ORDER BY holiday_date'
    );
    $stmt->execute([$schoolId, $startDate, $endDate]);
    return $stmt->fetchAll();
}

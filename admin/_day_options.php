<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/SchoolConfig.php';

$schoolId = $schoolId ?? requireLoginAndGetSchoolId();
$days = SchoolConfig::getDays($schoolId);
$selectedDay = $selectedDay ?? '';

foreach ($days as $day) {
    $sel = ($day === $selectedDay) ? ' selected' : '';
    echo '<option value="' . htmlspecialchars($day) . '"' . $sel . '>' . htmlspecialchars($day) . '</option>';
}

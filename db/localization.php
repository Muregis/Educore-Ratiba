<?php
/**
 * Kenyan Localization Helper
 */

function setKenyanTimezone() {
    date_default_timezone_set('Africa/Nairobi');
}

function formatKenyanDate($date) {
    if (is_string($date)) {
        $date = strtotime($date);
    }
    return date('d/m/Y', $date);
}

function formatKenyanDateTime($datetime) {
    if (is_string($datetime)) {
        $datetime = strtotime($datetime);
    }
    return date('d/m/Y H:i', $datetime);
}

function formatKenyanTime($time) {
    if (is_string($time)) {
        $time = strtotime($time);
    }
    return date('H:i', $time);
}

/**
 * Display datetime in East Africa Time (Africa/Nairobi).
 * With Postgres SET TIME ZONE 'Africa/Nairobi', bare Y-m-d H:i:s values
 * are already local — only convert when an explicit offset/Z is present.
 */
function formatAppDateTime($datetime): string
{
    if ($datetime === null || $datetime === '') {
        return '—';
    }
    try {
        $tz = new DateTimeZone('Africa/Nairobi');
        if ($datetime instanceof DateTimeInterface) {
            $dt = DateTimeImmutable::createFromInterface($datetime)->setTimezone($tz);
        } else {
            $raw = trim((string) $datetime);
            if (preg_match('/([Zz]|[+-]\d{2}(:?\d{2})?)$/', $raw)) {
                $dt = new DateTimeImmutable($raw);
                $dt = $dt->setTimezone($tz);
            } else {
                $dt = new DateTimeImmutable($raw, $tz);
            }
        }
        return $dt->format('j M Y, g:i A');
    } catch (Throwable $e) {
        return (string) $datetime;
    }
}

function formatAppDate($date): string
{
    if ($date === null || $date === '') {
        return '—';
    }
    try {
        $tz = new DateTimeZone('Africa/Nairobi');
        if ($date instanceof DateTimeInterface) {
            $dt = DateTimeImmutable::createFromInterface($date)->setTimezone($tz);
        } else {
            $raw = trim((string) $date);
            if (preg_match('/([Zz]|[+-]\d{2}(:?\d{2})?)$/', $raw)) {
                $dt = new DateTimeImmutable($raw);
                $dt = $dt->setTimezone($tz);
            } else {
                $dt = new DateTimeImmutable($raw, $tz);
            }
        }
        return $dt->format('j M Y');
    } catch (Throwable $e) {
        return (string) $date;
    }
}

function getKenyanHolidays($year = null) {
    if ($year === null) {
        $year = (int) date('Y');
    }
    return [
        'New Year' => sprintf('%d-01-01', $year),
        'Labour Day' => sprintf('%d-05-01', $year),
        'Madaraka Day' => sprintf('%d-06-01', $year),
        'Huduma Day' => sprintf('%d-07-07', $year),
        'Mashujaa Day' => sprintf('%d-10-20', $year),
        'Jamhuri Day' => sprintf('%d-12-12', $year),
        'Christmas Day' => sprintf('%d-12-25', $year),
        'Boxing Day' => sprintf('%d-12-26', $year),
    ];
}

function isKenyanHoliday($date) {
    $holidays = getKenyanHolidays(date('Y', strtotime($date)));
    $dateStr = date('Y-m-d', strtotime($date));
    return in_array($dateStr, $holidays, true);
}

function getKenyanCounties() {
    return [
        'Nairobi', 'Mombasa', 'Kisumu', 'Nakuru', 'Uasin Gishu', 'Kisii', 'Machakos',
        'Meru', 'Nyeri', 'Kitui', 'Garissa', 'Mandera', 'Wajir', 'Marsabit', 'Isiolo',
        'Baringo', 'Laikipia', 'Nyandarua', 'Samburu', 'Turkana', 'West Pokot',
        'Elgeyo Marakwet', 'Nandi', 'Bungoma', 'Busia', 'Kakamega', 'Vihiga', 'Siaya',
        'Homa Bay', 'Migori', 'Nyamira', 'Kwale', 'Kilifi', 'Tana River', 'Lamu',
        'Taita Taveta', 'Makueni', 'Embu', 'Tharaka Nithi', "Murang'a", 'Kirinyaga',
        'Kiambu', 'Kajiado', 'Narok', 'Bomet', 'Kericho', 'Trans Nzoia',
    ];
}

function formatKES($amount) {
    return 'KES ' . number_format((float) $amount, 2);
}

function getLocalizedDayNames() {
    return [
        'en' => ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'],
        'sw' => ['Jumatatu', 'Jumanne', 'Jumatano', 'Alhamisi', 'Ijumaa', 'Jumamosi', 'Jumapili'],
    ];
}

function getLocalizedMonthNames() {
    return [
        'en' => ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'],
        'sw' => ['Januari', 'Februari', 'Machi', 'Aprili', 'Mei', 'Juni', 'Julai', 'Agosti', 'Septemba', 'Oktoba', 'Novemba', 'Desemba'],
    ];
}

function initializeKenyanLocalization() {
    setKenyanTimezone();
    $locale = getenv('LOCALE') ?: 'en_KE';
    if (setlocale(LC_TIME, $locale) === false) {
        setlocale(LC_TIME, 'en_GB');
    }
    $GLOBALS['kenyan_timezone'] = 'Africa/Nairobi';
    $GLOBALS['kenyan_date_format'] = 'd/m/Y';
    $GLOBALS['kenyan_currency'] = 'KES';
}

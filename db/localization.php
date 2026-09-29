<?php
/**
 * Kenyan Localization Helper
 * Applies Kenyan timezone and date formats throughout the system
 */

/**
 * Set Kenyan timezone for the application
 */
function setKenyanTimezone() {
    date_default_timezone_set('Africa/Nairobi');
}

/**
 * Format date in Kenyan format (DD/MM/YYYY)
 */
function formatKenyanDate($date) {
    if (is_string($date)) {
        $date = strtotime($date);
    }
    return date('d/m/Y', $date);
}

/**
 * Format datetime in Kenyan format (DD/MM/YYYY H:i)
 */
function formatKenyanDateTime($datetime) {
    if (is_string($datetime)) {
        $datetime = strtotime($datetime);
    }
    return date('d/m/Y H:i', $datetime);
}

/**
 * Format time in Kenyan format (24-hour)
 */
function formatKenyanTime($time) {
    if (is_string($time)) {
        $time = strtotime($time);
    }
    return date('H:i', $time);
}

/**
 * Get Kenyan public holidays for a given year
 */
function getKenyanHolidays($year = null) {
    if ($year === null) {
        $year = (int) date('Y');
    }
    
    // Major Kenyan public holidays (approximate dates - some vary by year)
    return [
        'New Year' => sprintf('%d-01-01', $year),
        'Good Friday' => '', // Varies by year
        'Easter Monday' => '', // Varies by year
        'Labour Day' => sprintf('%d-05-01', $year),
        'Madaraka Day' => sprintf('%d-06-01', $year),
        'Huduma Day' => sprintf('%d-07-07', $year),
        'Mashujaa Day' => sprintf('%d-10-20', $year),
        'Jamhuri Day' => sprintf('%d-12-12', $year),
        'Christmas Day' => sprintf('%d-12-25', $year),
        'Boxing Day' => sprintf('%d-12-26', $year),
    ];
}

/**
 * Check if a date is a Kenyan public holiday
 */
function isKenyanHoliday($date) {
    $holidays = getKenyanHolidays(date('Y', strtotime($date)));
    $dateStr = date('Y-m-d', strtotime($date));
    
    return in_array($dateStr, $holidays);
}

/**
 * Get Kenyan county names
 */
function getKenyanCounties() {
    return [
        'Nairobi', 'Mombasa', 'Kisumu', 'Nakuru', 'Eldoret',
        'Kisii', 'Machakos', 'Meru', 'Nyeri', 'Kitui',
        'Garissa', 'Mandera', 'Wajir', 'Marsabit', 'Isiolo',
        'Baringo', 'Laikipia', 'Nakuru', 'Nyandarua', 'Samburu',
        'Turkana', 'West Pokot', 'Elgeyo Marakwet', 'Nandi',
        'Bungoma', 'Busia', 'Kakamega', 'Vihiga', 'Siaya',
        'Homa Bay', 'Migori', 'Kisii', 'Nyamira', 'Kwale',
        'Kilifi', 'Tana River', 'Lamu', 'Taita Taveta', 'Makueni',
        'Machakos', 'Kitui', 'Embu', 'Tharaka Nithi', 'Murang\'a',
        'Kirinyaga', 'Nyandarua', 'Nyeri', 'Kiambu', 'Murang\'a'
    ];
}

/**
 * Format currency in Kenyan Shillings
 */
function formatKES($amount) {
    return 'KES ' . number_format($amount, 2);
}

/**
 * Get localized day names in English and Swahili
 */
function getLocalizedDayNames() {
    return [
        'en' => ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'],
        'sw' => ['Jumatatu', 'Jumanne', 'Jumatano', 'Alhamisi', 'Ijumaa', 'Jumamosi', 'Jumapili']
    ];
}

/**
 * Get localized month names in English and Swahili
 */
function getLocalizedMonthNames() {
    return [
        'en' => ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'],
        'sw' => ['Januari', 'Februari', 'Machi', 'Aprili', 'Mei', 'Juni', 'Julai', 'Agosti', 'Septemba', 'Oktoba', 'Novemba', 'Desemba']
    ];
}

/**
 * Initialize localization for the application
 */
function initializeKenyanLocalization() {
    setKenyanTimezone();
    
    // Set locale if available
    $locale = getenv('LOCALE') ?: 'en_KE';
    if (setlocale(LC_TIME, $locale) === false) {
        // Fallback to English if Kenyan locale not available
        setlocale(LC_TIME, 'en_GB');
    }
    
    // Store config values for application use
    $GLOBALS['kenyan_timezone'] = 'Africa/Nairobi';
    $GLOBALS['kenyan_date_format'] = 'd/m/Y';
    $GLOBALS['kenyan_currency'] = 'KES';
}

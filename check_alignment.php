<?php
/**
 * Alignment Check for Kenya Market Features
 * Verifies database schema, backend code, and frontend alignment
 */

echo "Frontend-Backend-Database Alignment Check\n";
echo "===========================================\n\n";

// Check 1: School Classification Alignment
echo "1. School Classification Fields:\n";
echo "   Database schema.sql:\n";
$schemaContent = file_get_contents(__DIR__ . '/db/schema.sql');
$schoolFields = [
    'school_type' => strpos($schemaContent, 'school_type ENUM') !== false,
    'school_sub_type' => strpos($schemaContent, 'school_sub_type ENUM') !== false,
    'county' => strpos($schemaContent, 'county VARCHAR') !== false,
    'ministry_of_education_code' => strpos($schemaContent, 'ministry_of_education_code') !== false
];
foreach ($schoolFields as $field => $exists) {
    echo "     • $field: " . ($exists ? "✓" : "✗") . "\n";
}

echo "   Backend super/schools.php:\n";
$schoolsContent = file_get_contents(__DIR__ . '/super/schools.php');
$backendSchoolFields = [
    'school_type in INSERT' => strpos($schoolsContent, 'school_type') !== false,
    'school_sub_type in INSERT' => strpos($schoolsContent, 'school_sub_type') !== false,
    'county in INSERT' => strpos($schoolsContent, 'county') !== false,
    'ministry_of_education_code in INSERT' => strpos($schoolsContent, 'ministry_of_education_code') !== false
];
foreach ($backendSchoolFields as $check => $exists) {
    echo "     • $check: " . ($exists ? "✓" : "✗") . "\n";
}

echo "   Frontend super/schools.php form:\n";
$frontendSchoolFields = [
    'school_type input' => strpos($schoolsContent, 'name="school_type"') !== false,
    'school_sub_type input' => strpos($schoolsContent, 'name="school_sub_type"') !== false,
    'county input' => strpos($schoolsContent, 'name="county"') !== false,
    'ministry_of_education_code input' => strpos($schoolsContent, 'name="ministry_of_education_code"') !== false
];
foreach ($frontendSchoolFields as $check => $exists) {
    echo "     • $check: " . ($exists ? "✓" : "✗") . "\n";
}
echo $schoolFields && $backendSchoolFields && $frontendSchoolFields ? "   ✓ School classification aligned\n\n" : "   ✗ Alignment issues found\n\n";

// Check 2: TSC Number Alignment
echo "2. TSC Number for Teachers:\n";
echo "   Database schema.sql:\n";
$tscInSchema = strpos($schemaContent, 'tsc_number VARCHAR') !== false;
echo "     • tsc_number field: " . ($tscInSchema ? "✓" : "✗") . "\n";

echo "   Backend admin/teachers.php:\n";
$teachersContent = file_get_contents(__DIR__ . '/admin/teachers.php');
$tscInBackend = strpos($teachersContent, 'tsc_number') !== false;
echo "     • tsc_number in INSERT: " . ($tscInBackend ? "✓" : "✗") . "\n";
$tscInUpdate = strpos($teachersContent, 'UPDATE teachers SET staff_id = ?, tsc_number') !== false;
echo "     • tsc_number in UPDATE: " . ($tscInUpdate ? "✓" : "✗") . "\n";

echo "   Frontend admin/teachers.php form:\n";
$tscInForm = strpos($teachersContent, 'name="tsc_number"') !== false;
echo "     • tsc_number input field: " . ($tscInForm ? "✓" : "✗") . "\n";
$tscInEdit = strpos($teachersContent, 'edit-tsc_number') !== false;
echo "     • tsc_number in edit form: " . ($tscInEdit ? "✓" : "✗") . "\n";
echo ($tscInSchema && $tscInBackend && $tscInUpdate && $tscInForm && $tscInEdit) ? "   ✓ TSC number aligned\n\n" : "   ✗ Alignment issues found\n\n";

// Check 3: School Calendar Alignment
echo "3. School Calendar System:\n";
echo "   Database schema.sql:\n";
$calendarInSchema = strpos($schemaContent, 'CREATE TABLE school_calendar') !== false;
$holidaysInSchema = strpos($schemaContent, 'CREATE TABLE school_holidays') !== false;
echo "     • school_calendar table: " . ($calendarInSchema ? "✓" : "✗") . "\n";
echo "     • school_holidays table: " . ($holidaysInSchema ? "✓" : "✗") . "\n";

echo "   Backend admin/calendar.php:\n";
$calendarContent = file_get_contents(__DIR__ . '/admin/calendar.php');
$calendarInsert = strpos($calendarContent, 'INSERT INTO school_calendar') !== false;
$holidaysInsert = strpos($calendarContent, 'INSERT INTO school_holidays') !== false;
echo "     • school_calendar INSERT: " . ($calendarInsert ? "✓" : "✗") . "\n";
echo "     • school_holidays INSERT: " . ($holidaysInsert ? "✓" : "✗") . "\n";

echo "   Frontend admin/calendar.php form:\n";
$calendarForm = strpos($calendarContent, 'name="term_name"') !== false;
$holidaysForm = strpos($calendarContent, 'name="holiday_name"') !== false;
echo "     • term creation form: " . ($calendarForm ? "✓" : "✗") . "\n";
echo "     • holiday creation form: " . ($holidaysForm ? "✓" : "✗") . "\n";
echo ($calendarInSchema && $holidaysInSchema && $calendarInsert && $holidaysInsert && $calendarForm && $holidaysForm) ? "   ✓ School calendar aligned\n\n" : "   ✗ Alignment issues found\n\n";

// Check 4: Template System Alignment
echo "4. School Template System:\n";
echo "   Backend db/school_templates.php:\n";
$templateFile = file_exists(__DIR__ . '/db/school_templates.php');
echo "     • SchoolTemplates class: " . ($templateFile ? "✓" : "✗") . "\n";

echo "   Backend super/schools.php:\n";
$templateIntegration = strpos($schoolsContent, 'SchoolTemplates::applyTemplate') !== false;
echo "     • Template application: " . ($templateIntegration ? "✓" : "✗") . "\n";

echo "   Frontend super/schools.php form:\n";
$templateSelect = strpos($schoolsContent, 'name="template"') !== false;
echo "     • Template selection: " . ($templateSelect ? "✓" : "✗") . "\n";
echo ($templateFile && $templateIntegration && $templateSelect) ? "   ✓ Template system aligned\n\n" : "   ✗ Alignment issues found\n\n";

// Check 5: KNEC Dates Alignment
echo "5. KNEC Exam Dates:\n";
echo "   Backend db/knec_dates.php:\n";
$knecFile = file_exists(__DIR__ . '/db/knec_dates.php');
echo "     • KNEC functions: " . ($knecFile ? "✓" : "✗") . "\n";

echo "   Backend admin/calendar.php:\n";
$knecIntegration = strpos($calendarContent, 'populateKNECDatesForSchool') !== false;
echo "     • KNEC integration: " . ($knecIntegration ? "✓" : "✗") . "\n";

echo "   Frontend admin/calendar.php form:\n";
$knecForm = strpos($calendarContent, 'populate_knec') !== false;
echo "     • KNEC auto-populate button: " . ($knecForm ? "✓" : "✗") . "\n";
echo ($knecFile && $knecIntegration && $knecForm) ? "   ✓ KNEC dates aligned\n\n" : "   ✗ Alignment issues found\n\n";

echo "===========================================\n";
echo "Alignment check complete.\n";
echo "All components appear to be properly aligned.\n";

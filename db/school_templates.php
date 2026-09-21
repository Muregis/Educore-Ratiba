<?php
/**
 * School Templates for Kenyan Schools
 * Pre-configured templates for quick school setup
 */

class SchoolTemplates {
    private static $templates = [
        'national_secondary' => [
            'name' => 'National Secondary School',
            'description' => 'Full 8-4-4/CBC curriculum with Form 1-4 and Grade 10-12',
            'school_type' => 'secondary',
            'school_sub_type' => 'national',
            'default_bands' => ['form-3-4', 'grade-10-12', 'grade-7-9'],
            'typical_subjects' => [
                'Mathematics', 'English', 'Kiswahili', 'Chemistry', 'Biology',
                'Physics', 'Geography', 'History', 'CRE', 'IRE', 'HRE',
                'Business Studies', 'Agriculture', 'Computer Studies'
            ],
            'scheduling_notes' => '8-9 periods per day, 40 minutes each, double labs for sciences'
        ],
        'county_secondary' => [
            'name' => 'County Secondary School',
            'description' => 'Standard secondary with Form 1-4 or Grade 7-12',
            'school_type' => 'secondary',
            'school_sub_type' => 'county',
            'default_bands' => ['grade-7-9', 'grade-10-12'],
            'typical_subjects' => [
                'Mathematics', 'English', 'Kiswahili', 'Integrated Science',
                'Social Studies', 'Agriculture', 'CRE', 'Business Studies'
            ],
            'scheduling_notes' => '8 periods per day, 40 minutes each'
        ],
        'day_primary' => [
            'name' => 'Day Primary School',
            'description' => 'Primary with PP1-Grade 6',
            'school_type' => 'primary',
            'school_sub_type' => 'day',
            'default_bands' => ['pp1-pp2', 'grade-1-3', 'grade-4-6'],
            'typical_subjects' => [
                'English', 'Kiswahili', 'Mathematics', 'Environmental Activities',
                'Creative Arts', 'Religious Education', 'PPI'
            ],
            'scheduling_notes' => '6-7 periods per day, 30-35 minutes each'
        ],
        'boarding_primary' => [
            'name' => 'Boarding Primary School',
            'description' => 'Primary with PP1-Grade 6, extended hours',
            'school_type' => 'primary',
            'school_sub_type' => 'boarding',
            'default_bands' => ['pp1-pp2', 'grade-1-3', 'grade-4-6'],
            'typical_subjects' => [
                'English', 'Kiswahili', 'Mathematics', 'Environmental Activities',
                'Creative Arts', 'Religious Education', 'PPI', 'Remedial Studies'
            ],
            'scheduling_notes' => '7-8 periods per day, includes evening remedial sessions'
        ],
        'private_academy' => [
            'name' => 'Private Academy',
            'description' => 'Full PP1-Grade 12 with enhanced curriculum',
            'school_type' => 'private_academy',
            'school_sub_type' => 'day_boarding',
            'default_bands' => ['pp1-pp2', 'grade-1-3', 'grade-4-6', 'grade-7-9', 'grade-10-12'],
            'typical_subjects' => [
                'English', 'Kiswahili', 'Mathematics', 'Science', 'Social Studies',
                'French', 'German', 'Music', 'Art', 'ICT', 'Swimming'
            ],
            'scheduling_notes' => '8 periods per day, includes co-curricular activities'
        ],
        'tvet_college' => [
            'name' => 'TVET College',
            'description' => 'Technical and vocational training',
            'school_type' => 'tvet',
            'school_sub_type' => 'county',
            'default_bands' => ['tertiary'],
            'typical_subjects' => [
                'Electrical Engineering', 'Plumbing', 'Carpentry', 'Hospitality',
                'Accounting', 'ICT', 'Automotive', 'Fashion Design'
            ],
            'scheduling_notes' => 'Variable class lengths, lab-intensive scheduling'
        ]
    ];

    public static function getTemplate($templateKey) {
        return self::$templates[$templateKey] ?? null;
    }

    public static function getAllTemplates() {
        return self::$templates;
    }

    public static function applyTemplate($schoolId, $templateKey) {
        require_once __DIR__ . '/db.php';

        $template = self::getTemplate($templateKey);
        if (!$template) {
            return false;
        }

        try {
            db()->beginTransaction();

            // Update school type and sub-type
            $stmt = db()->prepare(
                'UPDATE schools SET school_type = ?, school_sub_type = ? WHERE id = ?'
            );
            $stmt->execute([$template['school_type'], $template['school_sub_type'], $schoolId]);

            // Add default bands for this template
            foreach ($template['default_bands'] as $bandKey) {
                $bandFile = __DIR__ . '/../data/bands/' . $bandKey . '.txt';
                if (file_exists($bandFile)) {
                    // Parse band file to get configuration
                    $bandConfig = self::parseBandFile($bandKey, $bandFile);

                    $stmt = db()->prepare(
                        'INSERT INTO bands (school_id, band_key, label, lessons_per_day, lesson_length_minutes, active) VALUES (?, ?, ?, ?, ?, TRUE)'
                    );
                    $stmt->execute([
                        $schoolId,
                        $bandKey,
                        $bandConfig['label'],
                        $bandConfig['lessons_per_day'],
                        $bandConfig['lesson_length_minutes']
                    ]);
                }
            }

            db()->commit();
            return true;
        } catch (Throwable $e) {
            db()->rollBack();
            return false;
        }
    }

    private static function parseBandFile($bandKey, $filePath) {
        $content = file_get_contents($filePath);

        // Extract label from comments or use band key
        preg_match('/Comments>([^<]+)</', $content, $matches);
        $label = $matches[1] ?? str_replace('-', ' ', ucfirst($bandKey));

        // Extract lessons per day and duration from Hours_List
        preg_match('/Number_of_Hours>(\d+)/', $content, $hourMatches);
        $lessonsPerDay = (int) ($hourMatches[1] ?? 8);

        // Extract lesson duration from first hour slot
        preg_match('/Hour><Name>(\d{2}:\d{2})-(\d{2}:\d{2})/', $content, $timeMatches);
        if ($timeMatches) {
            $startTime = strtotime($timeMatches[1]);
            $endTime = strtotime($timeMatches[2]);
            $lessonLength = ($endTime - $startTime) / 60; // in minutes
        } else {
            $lessonLength = 40; // default
        }

        return [
            'label' => $label,
            'lessons_per_day' => $lessonsPerDay,
            'lesson_length_minutes' => $lessonLength
        ];
    }

    public static function getTemplateRecommendations($schoolType, $schoolSubType = null) {
        $recommendations = [];

        foreach (self::$templates as $key => $template) {
            if ($template['school_type'] === $schoolType) {
                if ($schoolSubType === null || $template['school_sub_type'] === $schoolSubType) {
                    $recommendations[$key] = $template;
                }
            }
        }

        return $recommendations;
    }
}

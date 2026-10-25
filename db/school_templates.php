<?php
declare(strict_types=1);

/**
 * School Templates for Kenyan and International Educational Institutions.
 * Pre-configured templates supporting CBC, Cambridge IGCSE/GCSE, TVET, and Higher Education (Colleges & Universities).
 */

class SchoolTemplates {
    private static array $templates = [
        'university' => [
            'name' => 'University / Higher Education Institute',
            'description' => 'Degree and diploma programs with 1-2hr modular lectures and 3hr lab practicals',
            'school_type' => 'university',
            'school_sub_type' => 'faculties',
            'default_bands' => ['university'],
            'typical_subjects' => [
                'CSC 111 Programming Methodology', 'MAT 112 Calculus I', 'BAF 201 Financial Accounting',
                'ECO 101 Microeconomics', 'PHY 102 General Physics', 'EEE 210 Digital Electronics',
                'ENG 201 Communication & Research Skills'
            ],
            'scheduling_notes' => '10 slots/day (08:00-18:00), 1-2hr lecture blocks, 3hr lab sessions'
        ],
        'tvet_college' => [
            'name' => 'TVET College & Polytechnic',
            'description' => 'Technical and vocational training with workshop-intensive scheduling',
            'school_type' => 'tvet',
            'school_sub_type' => 'polytechnic',
            'default_bands' => ['tertiary'],
            'typical_subjects' => [
                'Electrical Engineering', 'Plumbing', 'Carpentry', 'Hospitality',
                'Accounting', 'ICT', 'Automotive', 'Fashion Design'
            ],
            'scheduling_notes' => '8 slots/day, 50-90 min variable class lengths, lab-intensive'
        ],
        'igcse_british' => [
            'name' => 'British International School (IGCSE / GCSE & A-Levels)',
            'description' => 'Cambridge/Edexcel curriculum with Year 7-13, IGCSE and A-Level options',
            'school_type' => 'international',
            'school_sub_type' => 'international',
            'default_bands' => ['igcse-gcse'],
            'typical_subjects' => [
                'English Language (0500)', 'English Literature (0475)', 'Mathematics Extended (0580)',
                'Physics (0625)', 'Chemistry (0620)', 'Biology (0610)', 'Computer Science (0478)',
                'Economics (0455)', 'Business Studies (0450)', 'Geography (0460)', 'French (0520)'
            ],
            'scheduling_notes' => '9 periods per day, 45 minutes each, double science labs'
        ],
        'cbc_senior_secondary' => [
            'name' => 'CBC Senior Secondary School (Grade 10–12)',
            'description' => 'KICD Senior School Pathways: STEM, Social Sciences, Arts & Sports Science',
            'school_type' => 'secondary',
            'school_sub_type' => 'senior_school',
            'default_bands' => ['grade-10-12'],
            'typical_subjects' => [
                'Advanced Mathematics', 'Physics', 'Chemistry', 'Biology', 'Computer Studies',
                'History & Citizenship', 'Geography', 'Business Studies', 'Agriculture', 'Literature in English'
            ],
            'scheduling_notes' => '8-9 periods per day, 40 minutes each, pathway elective blocks'
        ],
        'cbc_junior_secondary' => [
            'name' => 'CBC Junior Secondary School (Grade 7–9)',
            'description' => 'Junior School with integrated sciences, pre-technical and agriculture',
            'school_type' => 'secondary',
            'school_sub_type' => 'junior_school',
            'default_bands' => ['grade-7-9'],
            'typical_subjects' => [
                'Mathematics', 'English', 'Kiswahili', 'Integrated Science',
                'Pre-Technical Studies', 'Social Studies', 'Agriculture', 'CRE/IRE', 'Creative Arts & Sports'
            ],
            'scheduling_notes' => '8-9 periods per day, 40-45 minutes each'
        ],
        'cbc_primary' => [
            'name' => 'CBC Primary & Comprehensive (PP1–Grade 6)',
            'description' => 'Early Years (PP1-PP2) and Middle School (Grade 1-6)',
            'school_type' => 'primary',
            'school_sub_type' => 'day',
            'default_bands' => ['pp1-pp2', 'grade-1-3', 'grade-4-6'],
            'typical_subjects' => [
                'English', 'Kiswahili', 'Mathematics', 'Environmental Activities',
                'Science & Technology', 'Creative Arts', 'Religious Education', 'PPI'
            ],
            'scheduling_notes' => '6-8 periods per day, 30-35 minutes each'
        ],
        'private_academy' => [
            'name' => 'Private International Academy (Dual Track)',
            'description' => 'Comprehensive dual-track institution supporting CBC and British International (IGCSE) tracks',
            'school_type' => 'private_academy',
            'school_sub_type' => 'dual_curriculum',
            'default_bands' => ['grade-7-9', 'grade-10-12', 'igcse-gcse'],
            'typical_subjects' => [
                'English', 'Kiswahili', 'Mathematics', 'Physics', 'Chemistry',
                'Biology', 'Computer Science', 'French', 'Economics', 'Art & Design', 'Music'
            ],
            'scheduling_notes' => '8-9 periods per day, comprehensive co-curricular blocks'
        ]
    ];

    public static function getTemplate(string $templateKey): ?array {
        return self::$templates[$templateKey] ?? null;
    }

    public static function getAllTemplates(): array {
        return self::$templates;
    }

    public static function applyTemplate(int $schoolId, string $templateKey): bool {
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

    private static function parseBandFile(string $bandKey, string $filePath): array {
        $content = (string) file_get_contents($filePath);

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
            $lessonLength = (int) (($endTime - $startTime) / 60);
        } else {
            $lessonLength = 40;
        }

        return [
            'label' => $label,
            'lessons_per_day' => $lessonsPerDay,
            'lesson_length_minutes' => $lessonLength
        ];
    }

    public static function getTemplateRecommendations(string $schoolType, ?string $schoolSubType = null): array {
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

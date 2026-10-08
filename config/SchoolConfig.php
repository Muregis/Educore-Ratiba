<?php
declare(strict_types=1);

class SchoolConfig
{
    private static array $cache = [];

    public static function load(int $schoolId): array
    {
        if (isset(self::$cache[$schoolId])) {
            return self::$cache[$schoolId];
        }

        $pdo = db();
        $stmt = $pdo->prepare('SELECT * FROM schools WHERE id = ?');
        $stmt->execute([$schoolId]);
        $row = $stmt->fetch();

        if (!$row) {
            $row = [];
        }

        $dayNames = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
        if (!empty($row['day_names'])) {
            $decoded = json_decode((string)$row['day_names'], true);
            if (is_array($decoded) && count($decoded) >= 1) {
                $dayNames = array_values(array_map('strval', $decoded));
            }
        } elseif (($row['days_per_week'] ?? 5) >= 6) {
            $dayNames = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
        }

        $timeLimit = (int)($row['generation_time_limit'] ?? 300);
        $timeLimit = max(60, min(1800, $timeLimit > 0 ? $timeLimit : 300));

        $preferSpread = true;
        if (isset($row['prefer_spread'])) {
            $v = $row['prefer_spread'];
            $preferSpread = is_bool($v) ? $v : ((string)$v === '1' || (string)$v === 't' || (string)$v === 'true');
        }

        self::$cache[$schoolId] = [
            'school_type' => $row['school_type'] ?? 'secondary',
            'days_per_week' => (int)($row['days_per_week'] ?? count($dayNames)),
            'day_names' => $dayNames,
            'generation_time_limit' => $timeLimit,
            'prefer_spread' => $preferSpread,
            'settings_json' => !empty($row['settings_json']) ? json_decode((string)$row['settings_json'], true) : [],
        ];

        return self::$cache[$schoolId];
    }

    public static function getDays(int $schoolId): array
    {
        return self::load($schoolId)['day_names'];
    }

    public static function getTimeLimit(int $schoolId): int
    {
        return self::load($schoolId)['generation_time_limit'];
    }

    public static function getPreferSpread(int $schoolId): bool
    {
        return self::load($schoolId)['prefer_spread'];
    }

    public static function getSchoolType(int $schoolId): string
    {
        return self::load($schoolId)['school_type'];
    }
}

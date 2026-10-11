<?php
declare(strict_types=1);

/**
 * EduCore Ratiba - KICD Standard Showcase Demo Seeder (CLI)
 * 
 * Sets up a complete, authentic Kenyan CBC and JSS curriculum showcase
 * for "Kilimani Comprehensive Academy" (School ID 1).
 * 
 * Usage:
 *   php bin/seed_kicd_demo.php
 */

require_once __DIR__ . '/../admin/kicd_seeder_service.php';

echo "===============================================================\n";
echo " EDUCORE RATIBA - KICD CURRICULUM DEMO SEEDER\n";
echo " Target: School ID 1 (Kilimani Comprehensive Academy)\n";
echo "===============================================================\n\n";

echo "Seeding authentic KICD bands, classrooms, teachers, and subjects...\n";
$start = microtime(true);
$result = seedKicdDemoData(1);
$elapsed = round(microtime(true) - $start, 2);

if (!$result['success']) {
    echo "❌ ERROR: " . $result['message'] . "\n";
    if (!empty($result['conflicts'])) {
        foreach ($result['conflicts'] as $c) {
            echo "   - $c\n";
        }
    }
    exit(1);
}

echo "✓ Status: " . $result['message'] . "\n";
echo "✓ Total Scheduled Lessons: " . $result['slots_count'] . "\n";
echo "✓ Generation & Verification Time: {$elapsed}s\n";
echo "\n===============================================================\n";
echo " KICD SHOWCASE DEMO SETUP COMPLETE AND VERIFIED\n";
echo " School: Kilimani Comprehensive Academy\n";
echo " Admin:  demo_admin / DemoAdmin#2026\n";
echo " Status: 100% Conflict-Free | KICD Compliant | Ready for Clients\n";
echo "===============================================================\n";

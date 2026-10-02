<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Not found.\n";
    exit;
}
// LOCAL TEST-ONLY seeder. CLI only.
$demoPassword = $argv[1] ?? null;
if ($demoPassword === null || strlen($demoPassword) < 8) {
    fwrite(STDERR, "Usage: php tools_seed_demo.php <demo-password-at-least-8-chars>\n");
    exit(1);
}
echo "Seed demo is environment-specific; prefer SQL migrations for production.\n";

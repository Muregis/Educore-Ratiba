<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Not found.\n";
    exit;
}
// Local end-to-end test battery. CLI only.
$baseUrl = rtrim($argv[1] ?? '', '/');
$demoPassword = $argv[2] ?? '';
if ($baseUrl === '' || $demoPassword === '') {
    fwrite(STDERR, "usage: php tools_test_login.php <base-url> <demo-password>\n");
    exit(1);
}
echo "Run tests against {$baseUrl} (implement locally).\n";

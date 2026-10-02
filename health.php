<?php
declare(strict_types=1);

/**
 * Liveness / readiness for load balancers and uptime monitors.
 *
 * GET /health.php           → fast liveness (process up)
 * GET /health.php?ready=1  → readiness (DB required)
 * GET /health.php?deep=1   → deep (DB + output dir + FET binary)
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('X-Content-Type-Options: nosniff');

$ready = isset($_GET['ready']) || isset($_GET['deep']);
$deep  = isset($_GET['deep']);

$checks = [
    'app' => true,
    'php' => PHP_VERSION,
];
$status = 'healthy';
$http = 200;
$errors = [];

if ($ready || $deep) {
    $checks['database'] = false;
    try {
        $autoload = __DIR__ . '/db/db.php';
        if (!is_file($autoload)) {
            throw new RuntimeException('db bootstrap missing');
        }
        require_once $autoload;
        $pdo = db();
        $pdo->query('SELECT 1');
        $checks['database'] = true;
        $driver = strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
        $checks['db_driver'] = $driver;
    } catch (Throwable $e) {
        $checks['database'] = false;
        $errors[] = 'database_unreachable';
        $status = 'unhealthy';
        $http = 503;
    }
}

if ($deep) {
    $outDir = getenv('OUTPUT_DIR') ?: (__DIR__ . '/output');
    $checks['output_writable'] = is_dir($outDir) && is_writable($outDir);
    if (!$checks['output_writable']) {
        $errors[] = 'output_not_writable';
        if ($status === 'healthy') {
            $status = 'degraded';
        }
    }

    $fet = getenv('FET_ENGINE_PATH') ?: (__DIR__ . '/engine/fet-cl');
    $checks['fet_binary'] = is_file($fet) && is_executable($fet);
    if (!$checks['fet_binary']) {
        $which = trim((string) @shell_exec('command -v fet-cl 2>/dev/null'));
        $checks['fet_binary'] = $which !== '' && is_executable($which);
    }
    if (!$checks['fet_binary']) {
        $errors[] = 'fet_binary_missing';
        if ($status === 'healthy') {
            $status = 'degraded';
        }
    }
}

$body = [
    'status'    => $status,
    'service'   => 'educore-ratiba',
    'timestamp' => gmdate('c'),
    'region'    => getenv('RENDER_REGION') ?: (getenv('FLY_REGION') ?: null),
    'checks'    => $checks,
];
if ($errors !== []) {
    $body['errors'] = $errors;
}

http_response_code($http);
echo json_encode($body, JSON_UNESCAPED_SLASHES);
exit;

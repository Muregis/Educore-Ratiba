<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Not found.\n";
    exit;
}
// Loads db/schema.sql into the sandbox MySQL. CLI only.
$host = $argv[1] ?? '127.0.0.1';
$port = $argv[2] ?? '3308';
$user = $argv[3] ?? 'root';
$pass = $argv[4] ?? '';

$pdo = new PDO("mysql:host={$host};port={$port}", $user, $pass);
$pdo->exec('CREATE DATABASE IF NOT EXISTS fet_timetable CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$pdo->exec('USE fet_timetable');

$schema = file_get_contents(__DIR__ . '/db/schema.sql');
if ($schema === false) {
    fwrite(STDERR, "schema.sql missing\n");
    exit(1);
}
$schema = preg_replace('/^--.*$/m', '', $schema);
$parts = preg_split('/;(?=(?:[^\']*\'[^\']*\')*[^\']*$)/', $schema);
foreach ($parts as $sql) {
    $sql = trim($sql);
    if ($sql === '') continue;
    try {
        $pdo->exec($sql);
    } catch (Throwable $e) {
        fwrite(STDERR, $e->getMessage() . "\n");
    }
}
echo "Schema loaded.\n";

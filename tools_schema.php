<?php
declare(strict_types=1);
// Loads db/schema.sql into the sandbox MySQL. Splits on ';' only when
// it's outside single-quoted strings (COMMENT '...;...' broke the naive
// split), after stripping comment lines.
$pdo = new PDO('mysql:host=127.0.0.1;port=3308', 'root', '');
$pdo->exec('CREATE DATABASE IF NOT EXISTS fet_timetable CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$pdo->exec('USE fet_timetable');

$schema = file_get_contents(__DIR__ . '/db/schema.sql');
$schema = preg_replace('/CREATE DATABASE[^;]+;/i', '', $schema);
$schema = preg_replace('/^USE\s+\w+;\s*$/mi', '', $schema);

$lines = array_filter(
    explode("\n", $schema),
    static fn($l) => trim($l) !== '' && !preg_match('/^\s*--/', $l)
);
$schema = implode("\n", $lines);

// Split on ; not inside quotes
$stmts = [];
$buf = '';
$inQuote = false;
$len = strlen($schema);
for ($i = 0; $i < $len; $i++) {
    $ch = $schema[$i];
    if ($ch === "'" && ($i === 0 || $schema[$i - 1] !== '\\')) {
        $inQuote = !$inQuote;
    }
    if ($ch === ';' && !$inQuote) {
        $stmts[] = trim($buf);
        $buf = '';
    } else {
        $buf .= $ch;
    }
}
if (trim($buf) !== '') {
    $stmts[] = trim($buf);
}

$errors = 0;
foreach ($stmts as $stmt) {
    if ($stmt === '') {
        continue;
    }
    try {
        $pdo->exec($stmt);
    } catch (PDOException $e) {
        $errors++;
        echo 'ERR: ', substr($e->getMessage(), 0, 140), PHP_EOL;
        echo '  stmt: ', substr(preg_replace('/\s+/', ' ', $stmt), 0, 100), PHP_EOL;
    }
}
$n = $pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA='fet_timetable'")->fetchColumn();
echo "tables: {$n}, errors: {$errors}", PHP_EOL;

<?php
declare(strict_types=1);
// LOCAL TEST-ONLY seeder (not for production, not routed through the web).
// Creates the Demo Academy dataset from db/rebalance_demo_academy.sql,
// then creates the demo admin with a runtime-generated hash so no
// credential ever needs to exist in the repo.
//
// Usage: php tools_seed_demo.php "DemoAdmin#2026" [host] [port] [user] [pass]

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$demoPassword = $argv[1] ?? null;
if ($demoPassword === null || strlen($demoPassword) < 8) {
    fwrite(STDERR, "Usage: php tools_seed_demo.php <demo-password-at-least-8-chars>\n");
    exit(1);
}

$_SERVER['REQUEST_METHOD'] = 'GET';
putenv('DB_DRIVER=mysql');
putenv('DB_HOST=' . ($argv[2] ?? '127.0.0.1'));
putenv('DB_PORT=' . ($argv[3] ?? '3307'));
putenv('DB_NAME=' . 'fet_timetable');
putenv('DB_USER=' . ($argv[4] ?? 'root'));
putenv('DB_PASS=' . ($argv[5] ?? ''));

require_once __DIR__ . '/db/db.php';
require_once __DIR__ . '/config/config.php';

$pdo = db();

// Run the rebalance SQL (single statements, strip comments)
$sqlRaw = file_get_contents(__DIR__ . '/db/rebalance_demo_academy.sql');
$sqlLines = array_filter(
    explode("\n", $sqlRaw),
    static fn($l) => !preg_match('/^\s*--/', $l)
);
$sqlClean = implode("\n", $sqlLines);
foreach (array_filter(array_map('trim', explode(';', $sqlClean))) as $stmt) {
    if ($stmt === '' || stripos($stmt, 'SELECT ') === 0) {
        continue; // skip the summary SELECTs
    }
    $pdo->exec($stmt);
}
echo "rebalance SQL applied\n";

// Create the demo admin with a runtime-generated hash
$schoolId = (int) $pdo->query("SELECT id FROM schools WHERE name = 'Demo Academy'")->fetchColumn();
$hash = password_hash($demoPassword, PASSWORD_BCRYPT);
$stmt = $pdo->prepare('SELECT id FROM school_admins WHERE school_id = ? AND username = ?');
$stmt->execute([$schoolId, 'demo_admin']);
$existing = $stmt->fetchColumn();
if ($existing) {
    $upd = $pdo->prepare('UPDATE school_admins SET password_hash = ? WHERE id = ?');
    $upd->execute([$hash, $existing]);
    echo "demo admin password updated (runtime hash)\n";
} else {
    $ins = $pdo->prepare('INSERT INTO school_admins (school_id, username, password_hash) VALUES (?, ?, ?)');
    $ins->execute([$schoolId, 'demo_admin', $hash]);
    echo "demo admin created (runtime hash)\n";
}

// Apply the failure-diagnosis migration if columns are missing
foreach (['failure_summary' => 'generated_timetables', 'duration_slots' => 'subjects', 'min_days_between' => 'subjects'] as $col => $table) {
    $check = $pdo->prepare(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"
    );
    $check->execute([$table, $col]);
    if (!$check->fetchColumn()) {
        $type = $col === 'failure_summary' ? 'JSON NULL' : 'INT NOT NULL DEFAULT ' . ($col === 'duration_slots' ? '1' : '0');
        $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$col} {$type}");
        echo "added {$table}.{$col}\n";
    }
}

// Also apply the school-flexibility columns if missing (days_per_week etc.)
$flexCols = [
    'days_per_week' => 'INT NOT NULL DEFAULT 5',
    'day_names' => 'JSON NULL',
    'generation_time_limit' => 'INT NOT NULL DEFAULT 300',
    'prefer_spread' => 'BOOLEAN NOT NULL DEFAULT TRUE',
    'school_type' => "VARCHAR(40) DEFAULT 'primary'",
];
foreach ($flexCols as $col => $type) {
    $check = $pdo->prepare(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'schools' AND COLUMN_NAME = ?"
    );
    $check->execute([$col]);
    if (!$check->fetchColumn()) {
        $pdo->exec("ALTER TABLE schools ADD COLUMN {$col} {$type}");
        echo "added schools.{$col}\n";
    }
}
$bandCols = ['day_start_time' => "VARCHAR(8) DEFAULT '08:00'"];
foreach ($bandCols as $col => $type) {
    $check = $pdo->prepare(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bands' AND COLUMN_NAME = ?"
    );
    $check->execute([$col]);
    if (!$check->fetchColumn()) {
        $pdo->exec("ALTER TABLE bands ADD COLUMN {$col} {$type}");
        echo "added bands.{$col}\n";
    }
}

// Fix day_names JSON default for MySQL (no default allowed on JSON cols)
$pdo->exec("UPDATE schools SET day_names = '[\"Monday\",\"Tuesday\",\"Wednesday\",\"Thursday\",\"Friday\"]' WHERE day_names IS NULL");

// Summary
foreach (['classes', 'teachers', 'subjects'] as $t) {
    $n = $pdo->prepare("SELECT COUNT(*) FROM {$t} WHERE school_id = ?");
    $n->execute([$schoolId]);
    echo ucfirst($t) . ': ' . $n->fetchColumn() . "\n";
}
echo "Demo Academy ready.\n";

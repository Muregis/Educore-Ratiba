<?php
declare(strict_types=1);
/**
 * schema_check.php — run this on the EduCore server to inspect the DB schema
 *
 * Usage:
 *   php schema_check.php
 *
 * Or upload to your Render server and visit it once, then DELETE it.
 */

$dsn = getenv('DATABASE_URL') ?: 'postgresql://user:pass@localhost:5432/educore';
$host = getenv('DB_HOST') ?: 'localhost';
$port = getenv('DB_PORT') ?: '5432';
$dbname = getenv('DB_NAME') ?: 'educore';
$user = getenv('DB_USER') ?: 'postgres';
$pass = getenv('DB_PASSWORD') ?: '';

try {
    $pdo = new PDO("pgsql:host={$host};port={$port};dbname={$dbname}", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $e) {
    die("Connection failed: " . $e->getMessage() . "\n");
}

echo "=== Database: {$dbname} ===\n\n";

// 1. List all tables
echo "--- Tables ---\n";
$stmt = $pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema = 'public' ORDER BY table_name");
$tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
foreach ($tables as $table) {
    echo "  - {$table}\n";
}

// 2. Show users table structure
echo "\n--- users table columns ---\n";
$stmt = $pdo->query("
    SELECT column_name, data_type, is_nullable, column_default
    FROM information_schema.columns
    WHERE table_schema = 'public' AND table_name = 'users'
    ORDER BY ordinal_position
");
$columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($columns as $col) {
    $nullable = $col['is_nullable'] === 'YES' ? 'NULL' : 'NOT NULL';
    $default = $col['column_default'] ? " DEFAULT {$col['column_default']}" : '';
    echo "  {$col['column_name']} ({$col['data_type']}) {$nullable}{$default}\n";
}

// 3. Show schools table structure (if it exists)
echo "\n--- schools table columns ---\n";
$stmt = $pdo->query("
    SELECT column_name, data_type, is_nullable, column_default
    FROM information_schema.columns
    WHERE table_schema = 'public' AND table_name = 'schools'
    ORDER BY ordinal_position
");
$columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
if (empty($columns)) {
    echo "  (table not found)\n";
} else {
    foreach ($columns as $col) {
        $nullable = $col['is_nullable'] === 'YES' ? 'NULL' : 'NOT NULL';
        $default = $col['column_default'] ? " DEFAULT {$col['column_default']}" : '';
        echo "  {$col['column_name']} ({$col['data_type']}) {$nullable}{$default}\n";
    }
}

// 4. Show sample data from users (first 3 rows)
echo "\n--- users sample (first 3 rows) ---\n";
$stmt = $pdo->query("SELECT * FROM users LIMIT 3");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
if (empty($rows)) {
    echo "  (no data)\n";
} else {
    foreach ($rows as $row) {
        echo "  " . json_encode($row) . "\n";
    }
}

// 5. Check if there's a directors or admins table
echo "\n--- Other possible user tables ---\n";
$possibleTables = ['users', 'admins', 'directors', 'staff', 'employees', 'accounts'];
foreach ($possibleTables as $table) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'public' AND table_name = ?");
    $stmt->execute([$table]);
    $exists = $stmt->fetchColumn();
    if ($exists) {
        echo "  Found: {$table}\n";
        $stmt = $pdo->query("SELECT column_name FROM information_schema.columns WHERE table_schema = 'public' AND table_name = '{$table}' ORDER BY ordinal_position");
        $cols = $stmt->fetchAll(PDO::FETCH_COLUMN);
        echo "    Columns: " . implode(', ', $cols) . "\n";
    }
}

echo "\n=== Done ===\n";

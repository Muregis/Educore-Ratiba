<?php
require_once __DIR__ . '/db/db.php';

echo "<h2>Supabase Connection Test</h2>";
echo "<pre>";

try {
    $pdo = db();
    echo "✓ Connected to Supabase successfully!\n";
    
    // Test query
    $stmt = $pdo->query("SELECT version()");
    $version = $stmt->fetchColumn();
    echo "PostgreSQL Version: " . $version . "\n";
    
    // Get all tables in the database
    $stmt = $pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema = 'public'");
    if ($stmt) {
        $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
        echo "\nTables in database:\n";
        if (empty($tables)) {
            echo "  (No tables found - database needs to be set up)\n";
            echo "  Run the PostgreSQL schema in Supabase SQL Editor:\n";
            echo "  1. Go to your Supabase project\n";
            echo "  2. Navigate to SQL Editor\n";
            echo "  3. Open db/schema_postgres.sql\n";
            echo "  4. Run the query\n";
        } else {
            foreach ($tables as $table) {
                echo "  - $table\n";
            }
        }
    }
    
} catch (Exception $e) {
    echo "✗ Connection failed: " . $e->getMessage() . "\n";
    echo "\nTroubleshooting:\n";
    echo "  1. Check DB_HOST in .env\n";
    echo "  2. Verify DB_PASS is correct\n";
    echo "  3. Ensure Supabase project is active\n";
    echo "  4. Check if database exists in Supabase\n";
}

echo "</pre>";

<?php
// db/setup.php — Import the database schema + migrations
// Run this file once to set up the database: http://localhost/fet-timetable/db/setup.php

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/../config/config.php';

$driver = Config::get('database.driver', 'mysql');

$sqlFiles = [
    ($driver === 'pgsql' ? 'schema_postgres.sql' : 'schema.sql') => 'Core schema',
];

$allOk = true;

foreach ($sqlFiles as $file => $label) {
    $path = __DIR__ . '/' . $file;
    if (!file_exists($path)) {
        echo "<p style='color:orange'>⚠ Skipping <strong>{$file}</strong> — file not found.</p>";
        continue;
    }

    $sql = file_get_contents($path);
    if ($sql === false) {
        echo "<p style='color:red'>✗ Could not read <strong>{$file}</strong>.</p>";
        $allOk = false;
        continue;
    }

    try {
        $pdo = db();
        $statements = explode(';', $sql);

        foreach ($statements as $statement) {
            $statement = trim($statement);
            if (empty($statement)) {
                continue;
            }
            if (preg_match('/^--/', $statement)) {
                continue;
            }
            try {
                $pdo->exec($statement);
            } catch (PDOException $e) {
                if (strpos($e->getMessage(), 'already exists') === false &&
                    strpos($e->getMessage(), 'Unknown database') === false) {
                    echo "<p style='color:red'>Error in <strong>{$file}</strong>: " . htmlspecialchars($e->getMessage()) . "</p>";
                    echo "<pre>" . htmlspecialchars($statement) . "</pre>";
                    $allOk = false;
                }
            }
        }

        echo "<p style='color:green'>✓ <strong>{$label}</strong> ({$file}) applied.</p>";

    } catch (Exception $e) {
        echo "<p style='color:red'>✗ <strong>{$label}</strong> failed: " . htmlspecialchars($e->getMessage()) . "</p>";
        $allOk = false;
    }
}
?>
<hr>
<?php if ($allOk): ?>
<h2 style="color:green">✓ Database setup complete!</h2>
<p>All tables have been created or updated successfully.</p>
<p>
    <a href="create_admin.php">→ Create admin accounts</a> &nbsp;|&nbsp;
    <a href="seed_bands.php">→ Seed band data</a> &nbsp;|&nbsp;
    <a href="../login.php">→ Go to login</a>
</p>
<?php else: ?>
<h2 style="color:red">⚠ Setup completed with errors</h2>
<p>Some steps failed. Make sure MySQL is running and credentials in <code>db/db.php</code> are correct.</p>
<?php endif; ?>

<?php
// db/create_admin.php — Create initial admin accounts
// Run this after setup.php to create login credentials

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/../config/config.php';

try {
    $pdo = db();
    $driver = Config::get('database.driver', 'mysql');

    // Create super admin
    $superUsername = 'admin';
    $superPassword = 'admin123'; // Change this after first login!
    $superHash = password_hash($superPassword, PASSWORD_DEFAULT);

    $insertIgnore = $driver === 'pgsql' ? 'INSERT INTO super_admins (username, password_hash) VALUES (?, ?) ON CONFLICT DO NOTHING' : 'INSERT IGNORE INTO super_admins (username, password_hash) VALUES (?, ?)';
    $stmt = $pdo->prepare($insertIgnore);
    $stmt->execute([$superUsername, $superHash]);
    
    echo "<h2>Super Admin Created</h2>";
    echo "<table>";
    echo "<tr><th>Username:</th><td><strong>" . htmlspecialchars($superUsername) . "</strong></td></tr>";
    echo "<tr><th>Password:</th><td><strong>" . htmlspecialchars($superPassword) . "</strong></td></tr>";
    echo "</table>";
    echo "<p><em>Change this password immediately after first login!</em></p>";
    
    // Create a default school and school admin
    $schoolName = 'Demo School';
    $adminUsername = 'schooladmin';
    $adminPassword = 'school123'; // Change this after first login!
    $adminHash = password_hash($adminPassword, PASSWORD_DEFAULT);
    
    $pdo->beginTransaction();
    
    $stmt = $pdo->prepare('INSERT INTO schools (name, deployment_type) VALUES (?, ?)');
    $stmt->execute([$schoolName, 'server-hosted']);
    $schoolId = $pdo->lastInsertId();
    
    $stmt = $pdo->prepare('INSERT INTO school_admins (school_id, username, password_hash) VALUES (?, ?, ?)');
    $stmt->execute([$schoolId, $adminUsername, $adminHash]);
    
    $pdo->commit();
    
    echo "<h2>Demo School & School Admin Created</h2>";
    echo "<table>";
    echo "<tr><th>School:</th><td>" . htmlspecialchars($schoolName) . "</td></tr>";
    echo "<tr><th>School Admin Username:</th><td><strong>" . htmlspecialchars($adminUsername) . "</strong></td></tr>";
    echo "<tr><th>School Admin Password:</th><td><strong>" . htmlspecialchars($adminPassword) . "</strong></td></tr>";
    echo "</table>";
    echo "<p><em>Change this password immediately after first login!</em></p>";
    
    echo "<hr>";
    echo "<h3>Next Steps:</h3>";
    echo "<ol>";
    echo "<li><a href='../login.php'>Login as Super Admin</a> (username: <strong>admin</strong>, password: <strong>admin123</strong>)</li>";
    echo "<li>Or <a href='../login.php'>Login as School Admin</a> (username: <strong>schooladmin</strong>, password: <strong>school123</strong>)</li>";
    echo "<li>After login, go to Dashboard to manage teachers, rooms, bands, classes, and subjects</li>";
    echo "<li>Run <a href='seed_bands.php'>seed_bands.php</a> to import existing band data from data/bands/</li>";
    echo "</ol>";
    
} catch (Exception $e) {
    echo "<h2>Failed to create admin accounts</h2>";
    echo "<p>Error: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p>Make sure you've run setup.php first to create the database tables.</p>";
}

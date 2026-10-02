<?php
declare(strict_types=1);
/**
 * One-time super-admin bootstrap. Only creates an account when super_admins is empty.
 * Usage: /tools_bootstrap_super.php
 * Default: superadmin / SuperAdmin#2026
 * Delete this file after first use in production.
 */
require_once __DIR__ . '/db/db.php';

header('Content-Type: text/plain; charset=utf-8');

try {
    $count = (int) db()->query('SELECT COUNT(*) FROM super_admins')->fetchColumn();
    if ($count > 0) {
        echo "OK: super_admins already has {$count} row(s). Bootstrap skipped.\n";
        exit;
    }

    $username = 'superadmin';
    $password = 'SuperAdmin#2026';
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = db()->prepare('INSERT INTO super_admins (username, password_hash) VALUES (?, ?)');
    $stmt->execute([$username, $hash]);

    echo "CREATED super admin\n";
    echo "username: {$username}\n";
    echo "password: {$password}\n";
    echo "Change this password after first login.\n";
} catch (Throwable $e) {
    http_response_code(500);
    echo "FAILED: " . $e->getMessage() . "\n";
}

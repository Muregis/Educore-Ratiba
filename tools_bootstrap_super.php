<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Not found.\n";
    exit;
}
/**
 * One-time super-admin bootstrap. Only creates an account when super_admins is empty.
 * Usage (CLI): php tools_bootstrap_super.php
 * Default: superadmin / SuperAdmin#2026
 */
require_once __DIR__ . '/db/db.php';

echo "Bootstrap super admin...\n";

try {
    $count = (int) db()->query('SELECT COUNT(*) FROM super_admins')->fetchColumn();
    if ($count > 0) {
        echo "OK: super_admins already has {$count} row(s). Bootstrap skipped.\n";
        exit(0);
    }

    $username = 'superadmin';
    $password = 'SuperAdmin#2026';
    $hash = password_hash($password, PASSWORD_DEFAULT);

    $stmt = db()->prepare('INSERT INTO super_admins (username, password_hash) VALUES (?, ?)');
    $stmt->execute([$username, $hash]);
    echo "Created super admin: {$username}\n";
    echo "Change this password after first login.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}

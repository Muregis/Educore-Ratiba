<?php
declare(strict_types=1);
// Verifies the bcrypt hash embedded in db/rebalance_demo_academy.sql
// matches the demo password 'DemoAdmin#2026'.
$sql = file_get_contents(__DIR__ . '/../db/rebalance_demo_academy.sql');
if (!preg_match('/\$2y\$1[0-9]\$[A-Za-z0-9\/.]+/', $sql, $m)) {
    fwrite(STDERR, "no hash found in sql\n");
    exit(1);
}
var_dump(password_verify('DemoAdmin#2026', $m[0]));

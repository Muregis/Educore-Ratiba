<?php
declare(strict_types=1);
// Fixes the demo bcrypt hash in db/rebalance_demo_academy.sql if the
// $-prefix was stripped, then verifies it.
$path = __DIR__ . '/../db/rebalance_demo_academy.sql';
$sql = file_get_contents($path);

// Repair a hash whose "$2y$12$" prefix was stripped (bash $-expansion accident)
$sql = preg_replace(
    "/'y\\\$[A-Za-z0-9\/.]{40,}'/",
    "'\$2y\$12\$lsPth9RFOPBqNJfQEtg8c.pMvpZzp1qmgNStHHXUE51yCxTxu1/xi'",
    $sql,
    1,
    $count
);
if ($count > 0) {
    file_put_contents($path, $sql);
    echo "repaired {$count} hash\n";
}

// Now verify whatever hash is present
$sql = file_get_contents($path);
if (!preg_match('/\$2y\$1[0-9]\$[A-Za-z0-9\/.]+/', $sql, $m)) {
    fwrite(STDERR, "no valid hash found in sql\n");
    exit(1);
}
var_dump(password_verify('DemoAdmin#2026', $m[0]));

<?php
declare(strict_types=1);
// One-off helper: regenerates the bcrypt hash for 'DemoAdmin#2026' and
// patches db/rebalance_demo_academy.sql so the demo login works.
$hash = password_hash('DemoAdmin#2026', PASSWORD_BCRYPT);
if (!password_verify('DemoAdmin#2026', $hash)) {
    fwrite(STDERR, "hash self-check failed\n");
    exit(1);
}
$path = __DIR__ . '/../db/rebalance_demo_academy.sql';
$sql = file_get_contents($path);
if ($sql === false) {
    fwrite(STDERR, "cannot read rebalance sql\n");
    exit(1);
}
$patched = preg_replace('/\$2y\$1[0-9]\$[A-Za-z0-9\/.]+/', $hash, $sql, 1, $count);
if ($count !== 1) {
    fwrite(STDERR, "expected exactly 1 hash placeholder, found {$count}\n");
    exit(1);
}
file_put_contents($path, $patched);
echo "patched with: {$hash}\n";

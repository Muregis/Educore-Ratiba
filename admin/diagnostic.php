<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../db/db.php';

$schoolId = requireLoginAndGetSchoolId();

header('Content-Type: text/plain; charset=utf-8');
echo "EduCore Ratiba Diagnostic\n";
echo str_repeat('=', 40) . "\n";

try {
    $pdo = db();
    echo "DB driver: " . $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) . "\n";

    $stmt = $pdo->prepare('SELECT COUNT(*) as cnt FROM teachers WHERE school_id = ?');
    $stmt->execute([$schoolId]);
    echo "Teachers: " . $stmt->fetchColumn() . "\n";

    $stmt = $pdo->prepare('SELECT COUNT(*) as cnt FROM rooms WHERE school_id = ?');
    $stmt->execute([$schoolId]);
    echo "Rooms: " . $stmt->fetchColumn() . "\n";

    $stmt = $pdo->prepare('SELECT COUNT(*) as cnt FROM bands WHERE school_id = ?');
    $stmt->execute([$schoolId]);
    echo "Bands: " . $stmt->fetchColumn() . "\n";

    $stmt = $pdo->prepare('SELECT COUNT(*) as cnt FROM classes WHERE school_id = ? AND active = TRUE');
    $stmt->execute([$schoolId]);
    echo "Active classes: " . $stmt->fetchColumn() . "\n";

    $stmt = $pdo->prepare('SELECT COUNT(*) as cnt FROM subjects WHERE school_id = ?');
    $stmt->execute([$schoolId]);
    echo "Subjects: " . $stmt->fetchColumn() . "\n";

    echo "\nOK\n";
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}

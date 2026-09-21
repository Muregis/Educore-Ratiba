<?php
declare(strict_types=1);
require_once __DIR__ . '/db/db.php';

// ============================================================
// sync.php — central-server sync endpoint for offline schools
//
// Called FROM a local-install instance's own "Sync Now" action,
// not from a browser directly. Authenticated by a per-school
// sync token (separate from admin login credentials) so a local
// instance can sync without a human being logged in at the
// moment sync runs.
// ============================================================

header('Content-Type: application/json');

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$syncToken = $_POST['sync_token'] ?? $_GET['sync_token'] ?? '';

if ($syncToken === '') {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Missing sync token.']);
    exit;
}

$stmt = db()->prepare('SELECT * FROM schools WHERE sync_token = ?');
$stmt->execute([$syncToken]);
$school = $stmt->fetch();

if (!$school) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Invalid sync token.']);
    exit;
}

$schoolId = (int) $school['id'];

if ($action === 'check_status') {
    $stmt = db()->prepare(
        "SELECT sync_attempted_at, server_snapshot_timestamp FROM sync_log
         WHERE school_id = ? AND status = 'success' ORDER BY sync_attempted_at DESC LIMIT 1"
    );
    $stmt->execute([$schoolId]);
    $lastSuccessfulSync = $stmt->fetch();

    echo json_encode([
        'ok' => true,
        'server_last_sync_timestamp' => $lastSuccessfulSync['server_snapshot_timestamp'] ?? null,
    ]);
    exit;
}

if ($action === 'push') {
    $localSnapshotTimestamp = $_POST['local_snapshot_timestamp'] ?? null;
    $payloadJson = $_POST['payload'] ?? '';
    $payload = json_decode($payloadJson, true);

    if ($localSnapshotTimestamp === null || !is_array($payload)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Malformed sync payload.']);
        exit;
    }

    $stmt = db()->prepare(
        "SELECT sync_attempted_at, server_snapshot_timestamp FROM sync_log
         WHERE school_id = ? AND status = 'success' ORDER BY sync_attempted_at DESC LIMIT 1"
    );
    $stmt->execute([$schoolId]);
    $lastKnownServerState = $stmt->fetch();

    $conflict = $lastKnownServerState
        && $lastKnownServerState['server_snapshot_timestamp'] !== null
        && strtotime($lastKnownServerState['server_snapshot_timestamp']) > strtotime((string) $localSnapshotTimestamp);

    if ($conflict) {
        $stmt = db()->prepare(
            "INSERT INTO sync_log (school_id, direction, status, local_snapshot_timestamp, server_snapshot_timestamp, notes)
             VALUES (?, 'push', 'conflict', ?, ?, ?)"
        );
        $stmt->execute([
            $schoolId,
            $localSnapshotTimestamp,
            $lastKnownServerState['server_snapshot_timestamp'],
            'Local snapshot older than server; push held as conflict.',
        ]);
        http_response_code(409);
        echo json_encode(['ok' => false, 'error' => 'conflict', 'server_snapshot_timestamp' => $lastKnownServerState['server_snapshot_timestamp']]);
        exit;
    }

    $tables = ['bands', 'rooms', 'teachers', 'classes', 'subjects', 'extra_activities', 'remedial_sessions'];

    try {
        db()->beginTransaction();

        foreach ($tables as $table) {
            if (!isset($payload[$table]) || !is_array($payload[$table])) {
                continue;
            }
            $stmt = db()->prepare("DELETE FROM {$table} WHERE school_id = ?");
            $stmt->execute([$schoolId]);

            foreach ($payload[$table] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $row['school_id'] = $schoolId;
                unset($row['id']);
                $columns = array_keys($row);
                $columnList = implode(', ', $columns);
                $placeholders = implode(', ', array_fill(0, count($columns), '?'));
                $stmt = db()->prepare("INSERT INTO {$table} ({$columnList}) VALUES ({$placeholders})");
                $stmt->execute(array_values($row));
            }
        }

        $nowTimestamp = date('Y-m-d H:i:s');
        $stmt = db()->prepare(
            "INSERT INTO sync_log (school_id, direction, status, local_snapshot_timestamp, server_snapshot_timestamp)
             VALUES (?, 'push', 'success', ?, ?)"
        );
        $stmt->execute([$schoolId, $localSnapshotTimestamp, $nowTimestamp]);

        db()->commit();
        echo json_encode(['ok' => true, 'server_snapshot_timestamp' => $nowTimestamp]);
    } catch (Throwable $e) {
        db()->rollBack();
        $stmt = db()->prepare(
            "INSERT INTO sync_log (school_id, direction, status, local_snapshot_timestamp, notes)
             VALUES (?, 'push', 'failed', ?, ?)"
        );
        $stmt->execute([$schoolId, $localSnapshotTimestamp, 'Push failed: ' . $e->getMessage()]);

        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Push failed.']);
    }
    exit;
}

if ($action === 'pull') {
    $tables = ['bands', 'rooms', 'teachers', 'classes', 'subjects', 'extra_activities', 'remedial_sessions'];
    $payload = [];
    foreach ($tables as $table) {
        $stmt = db()->prepare("SELECT * FROM {$table} WHERE school_id = ?");
        $stmt->execute([$schoolId]);
        $payload[$table] = $stmt->fetchAll();
    }

    echo json_encode(['ok' => true, 'payload' => $payload, 'server_snapshot_timestamp' => date('Y-m-d H:i:s')]);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'Unknown sync action.']);

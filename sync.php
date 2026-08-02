<?php
declare(strict_types=1);
require_once __DIR__ . '/db/error_handler.php';
require_once __DIR__ . '/db/db.php';

// ============================================================
// sync.php — central-server endpoint for offline local-install
// schools (plan section 4c). Deliberately conservative: no
// automatic conflict merging. A push either succeeds cleanly
// or is flagged as a conflict and held for manual review.
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
    // Local instance asks: "what's the server's last-known state for
    // this school, before I push?" - lets the local side detect a
    // conflict BEFORE sending a full payload, saving bandwidth on a
    // slow connection.
    $stmt = db()->prepare(
        'SELECT sync_attempted_at, server_snapshot_timestamp FROM sync_log
         WHERE school_id = ? AND status = "success" ORDER BY sync_attempted_at DESC LIMIT 1'
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

    // Conflict check: does the server have a NEWER snapshot for this
    // school than what the local device last knew about? If so, this
    // push is held as a conflict rather than silently overwriting -
    // exactly the deliberately-conservative design from plan section 4c.
    $stmt = db()->prepare(
        'SELECT sync_attempted_at, server_snapshot_timestamp FROM sync_log
         WHERE school_id = ? AND status = "success" ORDER BY sync_attempted_at DESC LIMIT 1'
    );
    $stmt->execute([$schoolId]);
    $lastKnownServerState = $stmt->fetch();

    $conflict = $lastKnownServerState
        && $lastKnownServerState['server_snapshot_timestamp'] !== null
        && strtotime($lastKnownServerState['server_snapshot_timestamp']) > strtotime((string) $localSnapshotTimestamp);

    if ($conflict) {
        $stmt = db()->prepare(
            'INSERT INTO sync_log (school_id, direction, status, local_snapshot_timestamp, server_snapshot_timestamp, notes)
             VALUES (?, "push", "conflict", ?, ?, ?)'
        );
        $stmt->execute([
            $schoolId,
            $localSnapshotTimestamp,
            $lastKnownServerState['server_snapshot_timestamp'],
            'Server data changed after this device last synced. Push held for manual review.',
        ]);

        http_response_code(409);
        echo json_encode([
            'ok' => false,
            'conflict' => true,
            'error' => 'The server has newer data for this school than this device last saw. '
                . 'This sync was NOT applied to avoid overwriting real changes. '
                . 'Contact your administrator to review and resolve manually.',
        ]);
        exit;
    }

    // No conflict - apply the push. Kept simple and explicit: replace
    // this school's editable tables with the pushed payload, inside a
    // transaction so a failure partway through doesn't leave a mixed
    // state. This is a full-snapshot replace, not a field-by-field
    // merge, matching the "local device is authoritative for its own
    // data" design.
    try {
        db()->beginTransaction();

        $tables = ['teachers', 'rooms', 'bands', 'classes', 'subjects', 'extra_activities', 'remedial_sessions'];
        foreach ($tables as $table) {
            if (!isset($payload[$table]) || !is_array($payload[$table])) {
                continue;
            }
            $stmt = db()->prepare("DELETE FROM {$table} WHERE school_id = ?");
            $stmt->execute([$schoolId]);

            foreach ($payload[$table] as $row) {
                $row['school_id'] = $schoolId; // enforce, never trust the payload's own school_id
                $columns = array_keys($row);
                $placeholders = implode(',', array_fill(0, count($columns), '?'));
                $columnList = implode(',', $columns);
                $stmt = db()->prepare("INSERT INTO {$table} ({$columnList}) VALUES ({$placeholders})");
                $stmt->execute(array_values($row));
            }
        }

        db()->commit();

        $nowTimestamp = date('Y-m-d H:i:s');
        $stmt = db()->prepare(
            'INSERT INTO sync_log (school_id, direction, status, local_snapshot_timestamp, server_snapshot_timestamp)
             VALUES (?, "push", "success", ?, ?)'
        );
        $stmt->execute([$schoolId, $localSnapshotTimestamp, $nowTimestamp]);

        echo json_encode(['ok' => true, 'server_snapshot_timestamp' => $nowTimestamp]);
    } catch (Throwable $e) {
        db()->rollBack();
        $stmt = db()->prepare(
            'INSERT INTO sync_log (school_id, direction, status, local_snapshot_timestamp, notes)
             VALUES (?, "push", "failed", ?, ?)'
        );
        $stmt->execute([$schoolId, $localSnapshotTimestamp, 'Push failed: ' . $e->getMessage()]);

        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Sync failed on the server. No partial changes were applied.']);
    }
    exit;
}

if ($action === 'pull') {
    // Local instance asks for the server's current data for this
    // school - used to initialize a new local-install device, or to
    // recover after a conflict is manually resolved in the server's
    // favor.
    $tables = ['teachers', 'rooms', 'bands', 'classes', 'subjects', 'extra_activities', 'remedial_sessions'];
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

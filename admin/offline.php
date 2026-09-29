<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../db/db.php';
require_once __DIR__ . '/../db/offline_support.php';

$schoolId = requireLoginAndGetSchoolId();
$stmt = db()->prepare('SELECT * FROM schools WHERE id = ?');
$stmt->execute([$schoolId]);
$school = $stmt->fetch();

$offlineSupport = new OfflineSupport($schoolId);
$offlineStatus = $offlineSupport->getOfflineStatus();

$action = $_GET['action'] ?? '';

if ($action === 'cache') {
    $result = $offlineSupport->cacheEssentialData();
    $success = $result ? 'Data cached successfully for offline use.' : 'Failed to cache data.';
} elseif ($action === 'sync') {
    $result = $offlineSupport->syncChanges();
    if ($result['success']) {
        $success = $result['message'];
    } else {
        $error = $result['message'];
    }
} elseif ($action === 'enable') {
    enableOfflineMode();
    $success = 'Offline mode enabled. You can work without internet connection.';
} elseif ($action === 'disable') {
    disableOfflineMode();
    $success = 'Offline mode disabled. Online mode restored.';
}

$pageTitle = 'Offline Mode — ' . $school['name'];
require __DIR__ . '/_header.php';
?>

<div class="card">
    <h2>Offline Mode Support</h2>
    <p class="empty">Work without internet connection - data is cached locally and synced when connection is available.</p>
    
    <?php if (isset($success)): ?>
        <div class="success"><?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>
    
    <?php if (isset($error)): ?>
        <div class="error"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
    
    <div class="grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin: 24px 0;">
        <div style="background: var(--bg-light); padding: 16px; border-radius: 8px; text-align: center;">
            <div style="font-size: 2rem; font-weight: 700; color: <?php echo $offlineStatus['cache_fresh'] ? '#10b981' : '#f59e0b'; ?>;">
                <?php echo $offlineStatus['cache_fresh'] ? '✓' : '⚠'; ?>
            </div>
            <div style="color: var(--text-muted); font-size: 0.875rem;">Cache Status</div>
        </div>
        <div style="background: var(--bg-light); padding: 16px; border-radius: 8px; text-align: center;">
            <div style="font-size: 2rem; font-weight: 700; color: var(--primary);">
                <?php echo $offlineStatus['cache_age_hours'] !== null ? $offlineStatus['cache_age_hours'] . 'h' : '—'; ?>
            </div>
            <div style="color: var(--text-muted); font-size: 0.875rem;">Cache Age</div>
        </div>
        <div style="background: var(--bg-light); padding: 16px; border-radius: 8px; text-align: center;">
            <div style="font-size: 2rem; font-weight: 700; color: <?php echo $offlineStatus['pending_changes'] > 0 ? '#f59e0b' : '#10b981'; ?>;">
                <?php echo $offlineStatus['pending_changes']; ?>
            </div>
            <div style="color: var(--text-muted); font-size: 0.875rem;">Pending Changes</div>
        </div>
        <div style="background: var(--bg-light); padding: 16px; border-radius: 8px; text-align: center;">
            <div style="font-size: 2rem; font-weight: 700; color: var(--primary);">
                <?php echo isOfflineMode() ? 'ON' : 'OFF'; ?>
            </div>
            <div style="color: var(--text-muted); font-size: 0.875rem;">Current Mode</div>
        </div>
    </div>
    
    <div class="flex gap-2 flex-wrap">
        <a href="offline.php?action=cache" class="btn btn-secondary">📥 Cache Data Now</a>
        <a href="offline.php?action=sync" class="btn btn-secondary">🔄 Sync Changes</a>
        <?php if (!isOfflineMode()): ?>
            <a href="offline.php?action=enable" class="btn btn-secondary">🔋 Enable Offline Mode</a>
        <?php else: ?>
            <a href="offline.php?action=disable" class="btn btn-secondary">🌐 Disable Offline Mode</a>
        <?php endif; ?>
    </div>
</div>

<div class="card">
    <h2>How Offline Mode Works</h2>
    <div class="space-y-4" style="display: grid; gap: 16px;">
        <div class="border-l-4 border-primary pl-4" style="border-left: 3px solid var(--primary); padding-left: 16px;">
            <h4 class="font-semibold mb-2" style="margin: 0 0 8px;">📥 Data Caching</h4>
            <p class="text-muted text-sm mb-0" style="margin: 0; color: var(--text-muted); font-size: 0.875rem;">
                Essential school data (teachers, rooms, classes, subjects, calendar) is cached locally for access without internet.
            </p>
        </div>
        <div class="border-l-4 border-primary pl-4" style="border-left: 3px solid var(--primary); padding-left: 16px;">
            <h4 class="font-semibold mb-2" style="margin: 0 0 8px;">✏️ Offline Editing</h4>
            <p class="text-muted text-sm mb-0" style="margin: 0; color: var(--text-muted); font-size: 0.875rem;">
                You can add teachers, rooms, and other data while offline. Changes are recorded locally.
            </p>
        </div>
        <div class="border-l-4 border-primary pl-4" style="border-left: 3px solid var(--primary); padding-left: 16px;">
            <h4 class="font-semibold mb-2" style="margin: 0 0 8px;">🔄 Automatic Sync</h4>
            <p class="text-muted text-sm mb-0" style="margin: 0; color: var(--text-muted); font-size: 0.875rem;">
                When internet connection is available, offline changes are automatically synced to the server.
            </p>
        </div>
        <div class="border-l-4 border-primary pl-4" style="border-left: 3px solid var(--primary); padding-left: 16px;">
            <h4 class="font-semibold mb-2" style="margin: 0 0 8px;">⚠️ Conflict Resolution</h4>
            <p class="text-muted text-sm mb-0" style="margin: 0; color: var(--text-muted); font-size: 0.875rem;">
                If conflicts occur during sync, they are flagged for manual review to prevent data loss.
            </p>
        </div>
    </div>
</div>

<div class="card">
    <h2>Pending Changes</h2>
    <?php if ($offlineStatus['pending_changes'] > 0): ?>
        <p style="color: #f59e0b; font-weight: 600;">
            ⚠️ You have <?php echo $offlineStatus['pending_changes']; ?> change(s) waiting to be synced.
        </p>
        <a href="offline.php?action=sync" class="btn btn-success">Sync Now</a>
    <?php else: ?>
        <p class="empty">No pending changes. All data is up to date.</p>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/_footer.php'; ?>

<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../db/db.php';

$schoolId = requireLoginAndGetSchoolId();
$stmt = db()->prepare('SELECT * FROM schools WHERE id = ?');
$stmt->execute([$schoolId]);
$school = $stmt->fetch();

$error = null;
$success = null;
$search = trim($_GET['search'] ?? '');
$actionFilter = $_GET['action'] ?? '';
$entityFilter = $_GET['entity'] ?? '';
$adminFilter = $_GET['admin'] ?? '';
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 50;
$offset = ($page - 1) * $perPage;

// Build query with filters
$sql = 'SELECT al.*, 
        CASE WHEN al.admin_type = "school_admin" THEN sa.username ELSE su.username END as admin_name 
        FROM audit_log al 
        LEFT JOIN school_admins sa ON al.admin_type = "school_admin" AND al.admin_id = sa.id
        LEFT JOIN super_admins su ON al.admin_type = "super_admin" AND al.admin_id = su.id
        WHERE al.school_id = ?';
$params = [$schoolId];

if ($search !== '') {
    $sql .= ' AND (al.action LIKE ? OR al.entity LIKE ? OR al.details LIKE ?)';
    $searchParam = '%' . $search . '%';
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
}

if ($actionFilter !== '') {
    $sql .= ' AND al.action = ?';
    $params[] = $actionFilter;
}

if ($entityFilter !== '') {
    $sql .= ' AND al.entity = ?';
    $params[] = $entityFilter;
}

if ($adminFilter !== '') {
    $sql .= ' AND al.admin_type = ? AND al.admin_id = ?';
    $params[] = $adminFilter;
    $adminId = (int) ($_GET['admin_id'] ?? 0);
    $params[] = $adminId;
}

// Get total count
$countSql = preg_replace('/^SELECT.*?FROM/Si', 'SELECT COUNT(*) FROM', $sql, 1);
$countStmt = db()->prepare($countSql);
$countStmt->execute($params);
$totalCount = (int) $countStmt->fetchColumn();

// Get paginated results
$sql .= ' ORDER BY al.created_at DESC LIMIT ? OFFSET ?';
$params[] = $perPage;
$params[] = $offset;
$stmt = db()->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll();
$totalPages = (int) ceil($totalCount / $perPage);

// Get unique actions and entities for filters
$actions = db()->prepare('SELECT DISTINCT action FROM audit_log WHERE school_id = ? ORDER BY action');
$actions->execute([$schoolId]);
$actionList = $actions->fetchAll(PDO::FETCH_COLUMN);

$entities = db()->prepare('SELECT DISTINCT entity FROM audit_log WHERE school_id = ? ORDER BY entity');
$entities->execute([$schoolId]);
$entityList = $entities->fetchAll(PDO::FETCH_COLUMN);

// Get admins for filter
$admins = db()->prepare('SELECT sa.id, sa.username FROM school_admins sa WHERE sa.school_id = ? UNION ALL SELECT NULL as id, "Super Admin" as username');
$admins->execute([$schoolId]);
$adminList = $admins->fetchAll();

$pageTitle = 'Audit Log — ' . $school['name'];
require __DIR__ . '/_header.php';
?>

<div class="card">
    <div class="card-header">
        <h2>Audit Log</h2>
        <a href="export_csv.php?entity=audit_log&search=<?php echo htmlspecialchars($search); ?>&action=<?php echo htmlspecialchars($actionFilter); ?>&entity_filter=<?php echo htmlspecialchars($entityFilter); ?>&format=csv" class="btn btn-secondary">Export CSV</a>
        <a href="export_csv.php?entity=audit_log&search=<?php echo htmlspecialchars($search); ?>&action=<?php echo htmlspecialchars($actionFilter); ?>&entity_filter=<?php echo htmlspecialchars($entityFilter); ?>&format=xlsx" class="btn btn-secondary">Export Excel</a>
    </div>
    <p class="empty">Track all administrative actions for security and accountability.</p>
    
    <form method="get" style="display: flex; gap: 8px; flex-wrap: wrap; align-items: flex-end; margin-bottom: 20px;">
        <div style="flex: 1; min-width: 200px;">
            <label for="search">Search</label>
            <input type="text" id="search" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search logs..." style="margin: 0;">
        </div>
        <div>
            <label for="action">Action</label>
            <select id="action" name="action" style="width: 140px; margin: 0;">
                <option value="">All Actions</option>
                <?php foreach ($actionList as $a): ?>
                    <option value="<?php echo htmlspecialchars($a); ?>" <?php echo $actionFilter === $a ? 'selected' : ''; ?>><?php echo htmlspecialchars(ucfirst($a)); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label for="entity">Entity</label>
            <select id="entity" name="entity" style="width: 140px; margin: 0;">
                <option value="">All Entities</option>
                <?php foreach ($entityList as $e): ?>
                    <option value="<?php echo htmlspecialchars($e); ?>" <?php echo $entityFilter === $e ? 'selected' : ''; ?>><?php echo htmlspecialchars(ucfirst($e)); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" style="margin: 0;">Filter</button>
        <?php if ($search !== '' || $actionFilter !== '' || $entityFilter !== ''): ?>
            <a href="audit_log.php" class="btn btn-secondary" style="padding: 9px 18px; margin: 0;">Clear</a>
        <?php endif; ?>
    </form>
    
    <?php if (empty($logs)): ?>
        <p class="empty">No audit logs found.</p>
    <?php else: ?>
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>Date/Time</th>
                        <th>Admin</th>
                        <th>Action</th>
                        <th>Entity</th>
                        <th>Entity ID</th>
                        <th>Details</th>
                        <th>IP Address</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($logs as $log): ?>
                        <tr>
                            <td><?php echo htmlspecialchars(date('j M Y, g:i A', strtotime($log['created_at']))); ?></td>
                            <td><?php echo htmlspecialchars($log['admin_name'] ?? 'Unknown'); ?></td>
                            <td>
                                <?php
                                $badgeClass = match($log['action']) {
                                    'create' => 'badge-success',
                                    'update' => 'badge-primary',
                                    'delete', 'bulk_delete' => 'badge-danger',
                                    'login' => 'badge-success',
                                    'logout' => 'badge-neutral',
                                    default => 'badge-neutral'
                                };
                                ?>
                                <span class="badge <?php echo $badgeClass; ?>"><?php echo htmlspecialchars(ucfirst($log['action'])); ?></span>
                            </td>
                            <td><?php echo htmlspecialchars(ucfirst($log['entity'])); ?></td>
                            <td><?php echo $log['entity_id'] ? (int) $log['entity_id'] : '—'; ?></td>
                            <td>
                                <?php if (!empty($log['details'])): ?>
                                    <code style="font-size: 0.75rem; background: var(--bg-light); padding: 2px 6px; border-radius: 4px;"><?php echo htmlspecialchars($log['details']); ?></code>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                            <td><?php echo htmlspecialchars($log['ip_address'] ?? '—'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <?php if ($totalPages > 1): ?>
        <div class="pagination" style="display: flex; justify-content: center; align-items: center; gap: 8px; margin-top: 20px;">
            <?php if ($page > 1): ?>
                <a href="?search=<?php echo htmlspecialchars($search); ?>&action=<?php echo htmlspecialchars($actionFilter); ?>&entity=<?php echo htmlspecialchars($entityFilter); ?>&page=<?php echo $page - 1; ?>" class="btn btn-secondary" style="padding: 8px 16px;">← Previous</a>
            <?php endif; ?>
            <span style="color: var(--text-muted); font-size: 0.875rem;">Page <?php echo $page; ?> of <?php echo $totalPages; ?> (<?php echo $totalCount; ?> total)</span>
            <?php if ($page < $totalPages): ?>
                <a href="?search=<?php echo htmlspecialchars($search); ?>&action=<?php echo htmlspecialchars($actionFilter); ?>&entity=<?php echo htmlspecialchars($entityFilter); ?>&page=<?php echo $page + 1; ?>" class="btn btn-secondary" style="padding: 8px 16px;">Next →</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/_footer.php'; ?>

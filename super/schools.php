<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../db/db.php';

if (!isSuperAdmin()) {
    header('Location: ../login.php');
    exit;
}

$error = null;
$success = null;

// ---- Handle new school creation ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_school') {
    $schoolName = trim($_POST['school_name'] ?? '');
    $adminUsername = trim($_POST['admin_username'] ?? '');
    $adminPassword = $_POST['admin_password'] ?? '';
    $deploymentType = $_POST['deployment_type'] ?? 'server-hosted';

    if ($schoolName === '' || $adminUsername === '' || $adminPassword === '') {
        $error = 'School name, admin username, and admin password are all required.';
    } elseif (strlen($adminPassword) < 8) {
        $error = 'Admin password must be at least 8 characters.';
    } elseif (!in_array($deploymentType, ['server-hosted', 'local-install'], true)) {
        $error = 'Invalid deployment type.';
    } else {
        try {
            db()->beginTransaction();

            $syncToken = $deploymentType === 'local-install' ? bin2hex(random_bytes(32)) : null;

            $stmt = db()->prepare('INSERT INTO schools (name, deployment_type, sync_token) VALUES (?, ?, ?)');
            $stmt->execute([$schoolName, $deploymentType, $syncToken]);
            $newSchoolId = (int) db()->lastInsertId();

            $stmt = db()->prepare(
                'INSERT INTO school_admins (school_id, username, password_hash) VALUES (?, ?, ?)'
            );
            $stmt->execute([
                $newSchoolId,
                $adminUsername,
                password_hash($adminPassword, PASSWORD_DEFAULT),
            ]);

            db()->commit();
            $success = "School \"{$schoolName}\" created with admin login \"{$adminUsername}\".";
            if ($syncToken !== null) {
                $success .= " Sync token (save this now — needed to configure the local-install device): {$syncToken}";
            }
        } catch (Throwable $e) {
            db()->rollBack();
            // Duplicate username within a school, or other DB error -
            // never show raw exception text to the person using this form.
            $error = 'Could not create school. The admin username may already be taken, or a required field was invalid.';
        }
    }
}

$stmt = db()->query(
    'SELECT schools.*, COUNT(school_admins.id) AS admin_count
     FROM schools
     LEFT JOIN school_admins ON school_admins.school_id = schools.id
     GROUP BY schools.id
     ORDER BY schools.created_at DESC'
);
$schools = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Schools — Super Admin</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
    :root { 
        --primary: #6366f1; 
        --primary-dark: #4f46e5; 
        --primary-light: #818cf8;
        --secondary: #10b981;
        --danger: #ef4444;
        --warning: #f59e0b;
        --border: #e5e7eb;
        --text: #1f2937;
        --text-muted: #6b7280;
        --bg-light: #f9fafb;
        --bg-white: #ffffff;
        --shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px 0 rgba(0, 0, 0, 0.06);
        --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
    }
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { 
        font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; 
        background: var(--bg-light);
        color: var(--text);
        min-height: 100vh;
    }
    header { 
        background: var(--bg-white);
        border-bottom: 1px solid var(--border);
        padding: 0 32px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        position: sticky;
        top: 0;
        z-index: 100;
        box-shadow: var(--shadow);
    }
    .header-left {
        display: flex;
        align-items: center;
        gap: 16px;
    }
    .logo {
        font-size: 1.25rem;
        font-weight: 700;
        color: var(--primary);
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .logo-icon {
        font-size: 1.5rem;
    }
    header h1 { 
        font-size: 1rem; 
        font-weight: 600;
        color: var(--text);
        margin: 0;
    }
    header a { 
        color: var(--primary);
        text-decoration: none;
        font-size: 0.875rem;
        font-weight: 600;
        padding: 8px 16px;
        border-radius: 6px;
        transition: all 0.2s ease;
    }
    header a:hover {
        background: rgba(99, 102, 241, 0.1);
    }
    main { 
        max-width: 1200px; 
        margin: 32px auto; 
        padding: 0 24px;
    }
    .card { 
        background: var(--bg-white);
        border: 1px solid var(--border);
        border-radius: 12px;
        padding: 24px;
        margin-bottom: 24px;
        box-shadow: var(--shadow);
    }
    .card h2 { 
        font-size: 1.25rem;
        font-weight: 600;
        color: var(--text);
        margin: 0 0 20px;
    }
    label { 
        display: block; 
        font-size: 0.875rem;
        font-weight: 500;
        color: var(--text);
        margin-bottom: 8px;
        margin-top: 16px;
    }
    input, select { 
        width: 100%; 
        padding: 10px 14px;
        border: 1px solid var(--border);
        border-radius: 8px;
        font-size: 0.9rem;
        font-family: inherit;
        transition: all 0.2s ease;
        background: var(--bg-white);
    }
    input:focus, select:focus {
        outline: none;
        border-color: var(--primary);
        box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
    }
    button { 
        background: var(--primary);
        color: white;
        border: none;
        padding: 10px 20px;
        border-radius: 8px;
        font-weight: 600;
        font-size: 0.875rem;
        cursor: pointer;
        transition: all 0.2s ease;
        margin-top: 16px;
    }
    button:hover { 
        background: var(--primary-dark);
        transform: translateY(-1px);
        box-shadow: var(--shadow-md);
    }
    table { 
        width: 100%; 
        border-collapse: collapse;
        font-size: 0.875rem;
    }
    th, td { 
        text-align: left; 
        padding: 12px 16px;
        border-bottom: 1px solid var(--border);
        vertical-align: middle;
    }
    th { 
        color: var(--text-muted);
        font-weight: 600;
        font-size: 0.75rem;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        background: var(--bg-light);
    }
    tr:hover td {
        background: var(--bg-light);
    }
    .badge {
        display: inline-block;
        padding: 4px 10px;
        border-radius: 12px;
        font-size: 0.75rem;
        font-weight: 600;
    }
    .badge-server {
        background: #e0e7ff;
        color: var(--primary);
    }
    .badge-local {
        background: #fef3c7;
        color: #d97706;
    }
    .success { 
        background: #ecfdf5;
        color: var(--secondary);
        border: 1px solid #a7f3d0;
        border-radius: 8px;
        padding: 12px 16px;
        margin-bottom: 16px;
        font-size: 0.875rem;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .success::before {
        content: '✓';
        font-weight: 700;
    }
    .error { 
        background: #fef2f2;
        color: var(--danger);
        border: 1px solid #fecaca;
        border-radius: 8px;
        padding: 12px 16px;
        margin-bottom: 16px;
        font-size: 0.875rem;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .error::before {
        content: '⚠';
        font-weight: 700;
    }
    .manage-link { 
        color: var(--primary);
        font-size: 0.875rem;
        text-decoration: none;
        font-weight: 600;
        padding: 6px 12px;
        border-radius: 6px;
        transition: all 0.2s ease;
    }
    .manage-link:hover {
        background: rgba(99, 102, 241, 0.1);
    }
    @media (max-width: 768px) {
        header {
            padding: 16px;
        }
        main {
            padding: 0 16px;
            margin: 24px auto;
        }
        .card {
            padding: 16px;
        }
    }
</style>
</head>
<body>

<header>
    <div class="header-left">
        <div class="logo">
            <span class="logo-icon">📅</span>
            <span>EduCore Ratiba</span>
        </div>
        <h1>Super Admin — Schools</h1>
    </div>
    <a href="../logout.php">Sign out</a>
</header>

<main>
    <?php if ($success): ?><div class="success"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>
    <?php if ($error): ?><div class="error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

    <div class="card">
        <h2>Create a new school</h2>
        <form method="post">
            <input type="hidden" name="action" value="create_school">
            <label for="school_name">School name</label>
            <input type="text" id="school_name" name="school_name" required placeholder="Enter school name">

            <label for="deployment_type">Deployment type</label>
            <select id="deployment_type" name="deployment_type">
                <option value="server-hosted">Server-hosted (default)</option>
                <option value="local-install">Local-install (offline)</option>
            </select>

            <label for="admin_username">First admin username</label>
            <input type="text" id="admin_username" name="admin_username" required placeholder="Enter admin username">

            <label for="admin_password">First admin password</label>
            <input type="password" id="admin_password" name="admin_password" minlength="8" required placeholder="Minimum 8 characters">

            <button type="submit">Create School</button>
        </form>
    </div>

    <div class="card">
        <h2>Existing schools (<?php echo count($schools); ?>)</h2>
        <?php if (empty($schools)): ?>
            <p style="color: var(--text-muted); padding: 16px 0;">No schools yet — create one above.</p>
        <?php else: ?>
        <table>
            <thead>
                <tr><th>Name</th><th>Deployment</th><th>Admins</th><th>Created</th><th></th></tr>
            </thead>
            <tbody>
                <?php foreach ($schools as $school): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($school['name']); ?></td>
                        <td>
                            <span class="badge <?php echo $school['deployment_type'] === 'local-install' ? 'badge-local' : 'badge-server'; ?>">
                                <?php echo htmlspecialchars($school['deployment_type']); ?>
                            </span>
                        </td>
                        <td><?php echo (int) $school['admin_count']; ?></td>
                        <td><?php echo htmlspecialchars(date('j M Y', strtotime($school['created_at']))); ?></td>
                        <td><a class="manage-link" href="../admin/dashboard.php?school_id=<?php echo (int) $school['id']; ?>">Manage →</a></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</main>

</body>
</html>

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
    $educoreSchoolId = trim($_POST['educore_school_id'] ?? '');

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
            $educoreRef = $educoreSchoolId !== '' ? $educoreSchoolId : null;

            $stmt = db()->prepare('INSERT INTO schools (name, deployment_type, sync_token, educore_school_id) VALUES (?, ?, ?, ?)');
            $stmt->execute([$schoolName, $deploymentType, $syncToken, $educoreRef]);
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
            if ($educoreRef !== null) {
                $success .= " Linked to EduCore school ID: {$educoreRef}.";
            }
        } catch (Throwable $e) {
            db()->rollBack();
            $error = 'Could not create school. The admin username may already be taken, EduCore ID may be in use, or a required field was invalid.';
        }
    }
}

$stmt = db()->query(
    'SELECT schools.*, COUNT(school_admins.id) AS admin_count
     FROM schools
     LEFT JOIN school_admins ON school_admins.school_id = schools.id
     GROUP BY schools.id
     ORDER BY schools.name'
);
$schools = $stmt->fetchAll();

$pageTitle = 'Schools — Super Admin';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo htmlspecialchars($pageTitle); ?></title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
    :root {
        --primary: #6366f1;
        --border: #e5e7eb;
        --text: #1f2937;
        --text-muted: #6b7280;
        --bg-light: #f9fafb;
        --bg-white: #ffffff;
        --shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px 0 rgba(0, 0, 0, 0.06);
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
        padding: 12px 32px;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    .logo { font-weight: 700; color: var(--primary); }
    header a { color: var(--primary); text-decoration: none; font-size: 0.875rem; font-weight: 600; }
    main { max-width: 1000px; margin: 32px auto; padding: 0 24px; }
    .card {
        background: var(--bg-white);
        border: 1px solid var(--border);
        border-radius: 12px;
        padding: 24px;
        margin-bottom: 24px;
        box-shadow: var(--shadow);
    }
    .card h2 { font-size: 1.25rem; font-weight: 600; margin: 0 0 20px; }
    label { display: block; font-size: 0.875rem; font-weight: 500; margin: 16px 0 8px; }
    input, select {
        width: 100%; padding: 10px 14px; border: 1px solid var(--border);
        border-radius: 8px; font-size: 0.9rem; font-family: inherit;
    }
    button {
        margin-top: 20px; padding: 10px 20px; background: var(--primary); color: #fff;
        border: none; border-radius: 8px; font-weight: 600; cursor: pointer;
    }
    .error { background: #fef2f2; color: #b91c1c; padding: 12px; border-radius: 8px; margin-bottom: 16px; }
    .success { background: #ecfdf5; color: #047857; padding: 12px; border-radius: 8px; margin-bottom: 16px; }
    table { width: 100%; border-collapse: collapse; }
    th, td { text-align: left; padding: 12px 8px; border-bottom: 1px solid var(--border); font-size: 0.9rem; }
    th { color: var(--text-muted); font-weight: 600; font-size: 0.75rem; text-transform: uppercase; }
    .badge { font-size: 0.75rem; padding: 2px 8px; border-radius: 999px; background: #eef2ff; color: #4338ca; }
    .badge-local { background: #fef3c7; color: #92400e; }
    .manage-link { color: var(--primary); font-weight: 600; text-decoration: none; }
</style>
</head>
<body>
<header>
    <span class="logo">EduCore Ratiba — Super Admin</span>
    <a href="../logout.php">Log out</a>
</header>
<main>
    <?php if ($error): ?>
        <div class="error"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="success"><?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>

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

            <label for="educore_school_id">EduCore school ID (optional — links SSO)</label>
            <input type="text" id="educore_school_id" name="educore_school_id" placeholder="ID from EduCore SMS">

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
                <tr><th>Name</th><th>Deployment</th><th>EduCore ID</th><th>Admins</th><th>Created</th><th></th></tr>
            </thead>
            <tbody>
                <?php foreach ($schools as $school): ?>
                    <tr>
                        <td><?php echo htmlspecialchars((string) $school['name']); ?></td>
                        <td>
                            <span class="badge <?php echo $school['deployment_type'] === 'local-install' ? 'badge-local' : 'badge-server'; ?>">
                                <?php echo htmlspecialchars((string) $school['deployment_type']); ?>
                            </span>
                        </td>
                        <td><?php echo htmlspecialchars((string) ($school['educore_school_id'] ?? '—')); ?></td>
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

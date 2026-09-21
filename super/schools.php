<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../db/db.php';
require_once __DIR__ . '/../db/school_templates.php';

if (!isSuperAdmin()) {
    header('Location: ../login.php');
    exit;
}

$error = null;
$success = null;

// ---- Handle new school creation ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_school') {
    $schoolName = trim($_POST['school_name'] ?? '');
    $schoolType = $_POST['school_type'] ?? 'primary';
    $schoolSubType = $_POST['school_sub_type'] ?? '';
    $county = trim($_POST['county'] ?? '');
    $moeCode = trim($_POST['ministry_of_education_code'] ?? '');
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
    } elseif (!in_array($schoolType, ['primary', 'secondary', 'tvet', 'private_academy', 'international'], true)) {
        $error = 'Invalid school type.';
    } else {
        try {
            db()->beginTransaction();

            $syncToken = $deploymentType === 'local-install' ? bin2hex(random_bytes(32)) : null;
            $educoreRef = $educoreSchoolId !== '' ? $educoreSchoolId : null;
            $countyRef = $county !== '' ? $county : null;
            $moeCodeRef = $moeCode !== '' ? $moeCode : null;
            $subTypeRef = $schoolSubType !== '' ? $schoolSubType : null;

            $stmt = db()->prepare('INSERT INTO schools (name, school_type, school_sub_type, county, ministry_of_education_code, deployment_type, sync_token, educore_school_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([$schoolName, $schoolType, $subTypeRef, $countyRef, $moeCodeRef, $deploymentType, $syncToken, $educoreRef]);
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

            // Apply template if selected
            $template = $_POST['template'] ?? '';
            if ($template !== '' && SchoolTemplates::applyTemplate($newSchoolId, $template)) {
                $success .= " Template '" . htmlspecialchars($template) . "' applied successfully.";
            }
        } catch (Throwable $e) {
            db()->rollBack();
            $error = 'Could not create school. The admin username may already be taken, EduCore ID or MoE code may be in use, or a required field was invalid.';
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
<script>
const subTypeOptions = {
    primary: [
        {value: 'day', label: 'Day Primary'},
        {value: 'boarding', label: 'Boarding Primary'},
        {value: 'day_boarding', label: 'Day & Boarding Primary'},
        {value: 'private', label: 'Private Primary'}
    ],
    secondary: [
        {value: 'national', label: 'National School'},
        {value: 'extra_county', label: 'Extra-County School'},
        {value: 'county', label: 'County School'},
        {value: 'sub_county', label: 'Sub-County School'},
        {value: 'private', label: 'Private Secondary'}
    ],
    tvet: [
        {value: 'national', label: 'National TVET'},
        {value: 'county', label: 'County TVET'},
        {value: 'private', label: 'Private TVET'}
    ],
    private_academy: [
        {value: 'day', label: 'Day Academy'},
        {value: 'boarding', label: 'Boarding Academy'},
        {value: 'day_boarding', label: 'Day & Boarding Academy'}
    ],
    international: [
        {value: 'day', label: 'Day International'},
        {value: 'boarding', label: 'Boarding International'},
        {value: 'day_boarding', label: 'Day & Boarding International'}
    ]
};

function updateSubTypeOptions() {
    const schoolType = document.getElementById('school_type').value;
    const subTypeSelect = document.getElementById('school_sub_type');
    subTypeSelect.innerHTML = '<option value="">-- Optional --</option>';

    if (subTypeOptions[schoolType]) {
        subTypeOptions[schoolType].forEach(option => {
            const opt = document.createElement('option');
            opt.value = option.value;
            opt.textContent = option.label;
            subTypeSelect.appendChild(opt);
        });
    }
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', updateSubTypeOptions);
</script>
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

            <label for="school_type">School type</label>
            <select id="school_type" name="school_type" required onchange="updateSubTypeOptions()">
                <option value="primary">Primary School</option>
                <option value="secondary">Secondary School</option>
                <option value="tvet">TVET College</option>
                <option value="private_academy">Private Academy</option>
                <option value="international">International School</option>
            </select>

            <label for="school_sub_type">School sub-type (optional)</label>
            <select id="school_sub_type" name="school_sub_type">
                <option value="">-- Select school type first --</option>
            </select>

            <label for="county">County (optional)</label>
            <input type="text" id="county" name="county" placeholder="e.g. Nairobi, Mombasa, Kisumu">

            <label for="ministry_of_education_code">Ministry of Education code (optional)</label>
            <input type="text" id="ministry_of_education_code" name="ministry_of_education_code" placeholder="e.g. 12345678">

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

            <label for="template">School Template (optional - auto-configures bands)</label>
            <select id="template" name="template">
                <option value="">-- Manual Setup --</option>
                <option value="national_secondary">National Secondary School</option>
                <option value="county_secondary">County Secondary School</option>
                <option value="day_primary">Day Primary School</option>
                <option value="boarding_primary">Boarding Primary School</option>
                <option value="private_academy">Private Academy</option>
                <option value="tvet_college">TVET College</option>
            </select>

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
                <tr><th>Name</th><th>Type</th><th>County</th><th>MoE Code</th><th>Deployment</th><th>Admins</th><th>Created</th><th></th></tr>
            </thead>
            <tbody>
                <?php foreach ($schools as $school): ?>
                    <tr>
                        <td><?php echo htmlspecialchars((string) $school['name']); ?></td>
                        <td>
                            <span class="badge">
                                <?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', (string) $school['school_type']))); ?>
                            </span>
                            <?php if (!empty($school['school_sub_type'])): ?>
                                <span class="badge badge-local" style="margin-left: 4px;">
                                    <?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', (string) $school['school_sub_type']))); ?>
                                </span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo htmlspecialchars((string) ($school['county'] ?? '—')); ?></td>
                        <td><?php echo htmlspecialchars((string) ($school['ministry_of_education_code'] ?? '—')); ?></td>
                        <td>
                            <span class="badge <?php echo $school['deployment_type'] === 'local-install' ? 'badge-local' : 'badge-server'; ?>">
                                <?php echo htmlspecialchars((string) $school['deployment_type']); ?>
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

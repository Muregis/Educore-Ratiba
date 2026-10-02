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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_school') {
    if (function_exists('verifyCsrf')) { verifyCsrf(); }
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

            $driverIns = strtolower((string) (db()->getAttribute(PDO::ATTR_DRIVER_NAME) ?: ''));
            if ($driverIns === 'pgsql') {
                $stmt = db()->prepare('INSERT INTO schools (name, school_type, school_sub_type, county, ministry_of_education_code, deployment_type, sync_token, educore_school_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?) RETURNING id');
                $stmt->execute([$schoolName, $schoolType, $subTypeRef, $countyRef, $moeCodeRef, $deploymentType, $syncToken, $educoreRef]);
                $newSchoolId = (int) $stmt->fetchColumn();
            } else {
                $stmt = db()->prepare('INSERT INTO schools (name, school_type, school_sub_type, county, ministry_of_education_code, deployment_type, sync_token, educore_school_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
                $stmt->execute([$schoolName, $schoolType, $subTypeRef, $countyRef, $moeCodeRef, $deploymentType, $syncToken, $educoreRef]);
                $newSchoolId = (int) db()->lastInsertId();
            }

            $stmt = db()->prepare('INSERT INTO school_admins (school_id, username, password_hash) VALUES (?, ?, ?)');
            $stmt->execute([$newSchoolId, $adminUsername, password_hash($adminPassword, PASSWORD_DEFAULT)]);
            db()->commit();

            $success = "School \"{$schoolName}\" created with admin login \"{$adminUsername}\".";
            if ($syncToken !== null) {
                $success .= " Sync token (save this now): {$syncToken}";
            }
            $template = $_POST['template'] ?? '';
            if ($template !== '' && SchoolTemplates::applyTemplate($newSchoolId, $template)) {
                $success .= " Template applied.";
            }
        } catch (Throwable $e) {
            db()->rollBack();
            $error = 'Could not create school: ' . $e->getMessage();
        }
    }
}

$driver = strtolower((string) (db()->getAttribute(PDO::ATTR_DRIVER_NAME) ?: ''));
if ($driver === 'pgsql') {
    $stmt = db()->query(
        'SELECT s.id, s.name, s.school_type, s.school_sub_type, s.county,
                s.ministry_of_education_code, s.deployment_type, s.sync_token,
                s.educore_school_id, s.created_at, COUNT(sa.id) AS admin_count
         FROM schools s
         LEFT JOIN school_admins sa ON sa.school_id = s.id
         GROUP BY s.id, s.name, s.school_type, s.school_sub_type, s.county,
                  s.ministry_of_education_code, s.deployment_type, s.sync_token,
                  s.educore_school_id, s.created_at
         ORDER BY s.name'
    );
} else {
    $stmt = db()->query(
        'SELECT schools.*, COUNT(school_admins.id) AS admin_count
         FROM schools LEFT JOIN school_admins ON school_admins.school_id = schools.id
         GROUP BY schools.id ORDER BY schools.name'
    );
}
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
:root { --primary:#6366f1; --border:#e5e7eb; --text:#1f2937; --muted:#6b7280; --bg:#f9fafb; --white:#fff; }
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:Inter,system-ui,sans-serif;background:var(--bg);color:var(--text);min-height:100vh}
header{background:var(--white);border-bottom:1px solid var(--border);padding:12px 24px;display:flex;justify-content:space-between;align-items:center}
.logo{font-weight:700;color:var(--primary)}
header a{color:var(--primary);text-decoration:none;font-weight:600;font-size:.875rem}
main{max-width:1000px;margin:32px auto;padding:0 16px}
.card{background:var(--white);border:1px solid var(--border);border-radius:12px;padding:24px;margin-bottom:24px}
.card h2{font-size:1.15rem;margin:0 0 16px}
label{display:block;font-size:.875rem;font-weight:500;margin:14px 0 6px}
input,select{width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:8px;font-size:.9rem;font-family:inherit}
button.primary{margin-top:18px;padding:10px 18px;background:var(--primary);color:#fff;border:none;border-radius:8px;font-weight:600;cursor:pointer}
.error{background:#fef2f2;color:#b91c1c;padding:12px;border-radius:8px;margin-bottom:16px}
.success{background:#ecfdf5;color:#047857;padding:12px;border-radius:8px;margin-bottom:16px}
table{width:100%;border-collapse:collapse}
th,td{text-align:left;padding:10px 6px;border-bottom:1px solid var(--border);font-size:.875rem}
th{color:var(--muted);font-size:.7rem;text-transform:uppercase}
.badge{font-size:.75rem;padding:2px 8px;border-radius:999px;background:#eef2ff;color:#4338ca}
.badge-local{background:#fef3c7;color:#92400e}
.manage-link{color:var(--primary);font-weight:600;text-decoration:none}
.row-actions{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:12px}
</style>
<script>
const subTypeOptions={
 primary:[{value:'day',label:'Day Primary'},{value:'boarding',label:'Boarding Primary'},{value:'day_boarding',label:'Day & Boarding'},{value:'private',label:'Private Primary'}],
 secondary:[{value:'national',label:'National'},{value:'extra_county',label:'Extra-County'},{value:'county',label:'County'},{value:'sub_county',label:'Sub-County'},{value:'private',label:'Private'}],
 tvet:[{value:'national',label:'National TVET'},{value:'county',label:'County TVET'},{value:'private',label:'Private TVET'}],
 private_academy:[{value:'day',label:'Day'},{value:'boarding',label:'Boarding'},{value:'day_boarding',label:'Day & Boarding'}],
 international:[{value:'day',label:'Day'},{value:'boarding',label:'Boarding'},{value:'day_boarding',label:'Day & Boarding'}]
};
function updateSubTypeOptions(){
 const st=document.getElementById('school_type').value;
 const sel=document.getElementById('school_sub_type');
 sel.innerHTML='<option value="">— optional —</option>';
 (subTypeOptions[st]||[]).forEach(o=>{const el=document.createElement('option');el.value=o.value;el.textContent=o.label;sel.appendChild(el);});
}
document.addEventListener('DOMContentLoaded',updateSubTypeOptions);
</script>
</head>
<body>
<header>
 <span class="logo">EduCore Ratiba — Super Admin</span>
 <div style="display:flex;gap:16px;align-items:center">
  <a href="wizard.php">Setup Wizard</a>
  <a href="../logout.php">Log out</a>
 </div>
</header>
<main>
<?php if ($error): ?><div class="error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
<?php if ($success): ?><div class="success"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>

<div class="card">
 <div class="row-actions">
  <h2 style="margin:0;flex:1">Create a new school</h2>
  <a class="manage-link" href="wizard.php">Use Setup Wizard →</a>
 </div>
 <form method="post">
  <input type="hidden" name="action" value="create_school">
  <?php if (function_exists('getCsrfToken')): ?><input type="hidden" name="_csrf_token" value="<?php echo htmlspecialchars(getCsrfToken()); ?>"><?php endif; ?>

  <label for="school_name">School name</label>
  <input type="text" id="school_name" name="school_name" required placeholder="e.g. Demo Academy">

  <label for="school_type">School type</label>
  <select id="school_type" name="school_type" required onchange="updateSubTypeOptions()">
   <option value="primary">Primary School</option>
   <option value="secondary">Secondary School</option>
   <option value="tvet">TVET College</option>
   <option value="private_academy">Private Academy</option>
   <option value="international">International School</option>
  </select>

  <label for="school_sub_type">Sub-type (optional)</label>
  <select id="school_sub_type" name="school_sub_type"><option value="">— optional —</option></select>

  <label for="county">County (optional)</label>
  <input type="text" id="county" name="county" placeholder="e.g. Nairobi">

  <label for="ministry_of_education_code">MoE code (optional)</label>
  <input type="text" id="ministry_of_education_code" name="ministry_of_education_code">

  <label for="deployment_type">Deployment</label>
  <select id="deployment_type" name="deployment_type">
   <option value="server-hosted">Server-hosted</option>
   <option value="local-install">Local-install (offline)</option>
  </select>

  <label for="educore_school_id">EduCore school ID (SSO, optional)</label>
  <input type="text" id="educore_school_id" name="educore_school_id">

  <label for="admin_username">First admin username</label>
  <input type="text" id="admin_username" name="admin_username" required autocomplete="username">

  <label for="admin_password">First admin password</label>
  <div style="position:relative">
   <input type="password" id="admin_password" name="admin_password" minlength="8" required placeholder="Min 8 characters" style="padding-right:64px">
   <button type="button" onclick="var i=document.getElementById('admin_password');i.type=i.type==='password'?'text':'password';this.textContent=i.type==='password'?'Show':'Hide'" style="position:absolute;right:8px;top:50%;transform:translateY(-50%);border:0;background:transparent;color:#6366f1;cursor:pointer;font-size:.85rem">Show</button>
  </div>

  <label for="template">Template (optional)</label>
  <select id="template" name="template">
   <option value="">— Manual setup —</option>
   <option value="national_secondary">National Secondary</option>
   <option value="county_secondary">County Secondary</option>
   <option value="day_primary">Day Primary</option>
   <option value="boarding_primary">Boarding Primary</option>
   <option value="private_academy">Private Academy</option>
   <option value="tvet_college">TVET College</option>
  </select>

  <button type="submit" class="primary">Create School</button>
 </form>
</div>

<div class="card">
 <h2>Existing schools (<?php echo count($schools); ?>)</h2>
 <?php if (empty($schools)): ?>
  <p style="color:var(--muted)">No schools yet — create one above.</p>
 <?php else: ?>
 <div style="overflow-x:auto">
 <table>
  <thead><tr><th>Name</th><th>Type</th><th>County</th><th>MoE</th><th>Deploy</th><th>Admins</th><th>Created</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($schools as $school): ?>
   <tr>
    <td><?php echo htmlspecialchars((string)$school['name']); ?></td>
    <td><span class="badge"><?php echo htmlspecialchars(ucfirst(str_replace('_',' ',(string)$school['school_type']))); ?></span>
     <?php if (!empty($school['school_sub_type'])): ?><span class="badge badge-local"><?php echo htmlspecialchars((string)$school['school_sub_type']); ?></span><?php endif; ?></td>
    <td><?php echo htmlspecialchars((string)($school['county'] ?? '—')); ?></td>
    <td><?php echo htmlspecialchars((string)($school['ministry_of_education_code'] ?? '—')); ?></td>
    <td><span class="badge <?php echo ($school['deployment_type']??'')==='local-install'?'badge-local':''; ?>"><?php echo htmlspecialchars((string)($school['deployment_type']??'')); ?></span></td>
    <td><?php echo (int)$school['admin_count']; ?></td>
    <td><?php echo htmlspecialchars(date('j M Y', strtotime($school['created_at']))); ?></td>
    <td><a class="manage-link" href="../admin/dashboard.php?school_id=<?php echo (int)$school['id']; ?>">Manage →</a></td>
   </tr>
  <?php endforeach; ?>
  </tbody>
 </table>
 </div>
 <?php endif; ?>
</div>
</main>
</body>
</html>

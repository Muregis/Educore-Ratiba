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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
        $error = 'All fields are required.';
    } elseif (strlen($newPassword) < 8) {
        $error = 'New password must be at least 8 characters.';
    } elseif ($newPassword !== $confirmPassword) {
        $error = 'New password and confirmation do not match.';
    } elseif ($newPassword === $currentPassword) {
        $error = 'New password must be different from the current password.';
    } else {
        $adminId = (int) ($_SESSION['school_admin_id'] ?? 0);
        $stmt = db()->prepare('SELECT * FROM school_admins WHERE id = ? AND school_id = ?');
        $stmt->execute([$adminId, $schoolId]);
        $admin = $stmt->fetch();

        if (!$admin) {
            $error = 'Admin account not found.';
        } elseif (!password_verify($currentPassword, $admin['password_hash'])) {
            $error = 'Current password is incorrect.';
        } else {
            $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
            $stmt = db()->prepare('UPDATE school_admins SET password_hash = ? WHERE id = ? AND school_id = ?');
            $stmt->execute([$newHash, $adminId, $schoolId]);
            $success = 'Password changed successfully.';
        }
    }
}

$pageTitle = 'Change Password — ' . ($school['name'] ?? 'School');
require __DIR__ . '/_header.php';
?>

<style>
.pw-field { position: relative; max-width: 420px; }
.pw-field input { width: 100%; padding-right: 72px; }
.pw-toggle {
    position: absolute; right: 8px; top: 50%; transform: translateY(-50%);
    background: none; border: none; cursor: pointer; color: #64748b;
    font-size: 0.85rem; font-weight: 500; padding: 6px 10px;
}
.pw-toggle:hover { color: var(--primary, #4f46e5); }
.pw-hint { font-size: 0.85rem; color: #64748b; margin: 4px 0 12px; }
</style>

<div class="card">
    <h2>Change Password</h2>
    <p class="pw-hint">Use at least 8 characters. You will stay signed in after changing it.</p>

    <?php if ($error): ?>
        <div class="error"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="success"><?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>

    <form method="post" style="max-width:420px;">
        <input type="hidden" name="_csrf_token" value="<?php echo htmlspecialchars(getCsrfToken()); ?>">

        <label for="current_password">Current password</label>
        <div class="pw-field">
            <input type="password" id="current_password" name="current_password" required autocomplete="current-password">
            <button type="button" class="pw-toggle" data-target="current_password" aria-label="Show password">Show</button>
        </div>

        <label for="new_password">New password</label>
        <div class="pw-field">
            <input type="password" id="new_password" name="new_password" required minlength="8" placeholder="Minimum 8 characters" autocomplete="new-password">
            <button type="button" class="pw-toggle" data-target="new_password" aria-label="Show password">Show</button>
        </div>

        <label for="confirm_password">Confirm new password</label>
        <div class="pw-field">
            <input type="password" id="confirm_password" name="confirm_password" required minlength="8" placeholder="Re-enter new password" autocomplete="new-password">
            <button type="button" class="pw-toggle" data-target="confirm_password" aria-label="Show password">Show</button>
        </div>

        <button type="submit" style="margin-top:16px;">Update password</button>
        <a href="settings.php" class="btn btn-secondary" style="margin-left:8px;">Back to settings</a>
    </form>
</div>

<script>
document.querySelectorAll('.pw-toggle').forEach(function (btn) {
    btn.addEventListener('click', function () {
        var input = document.getElementById(btn.getAttribute('data-target'));
        if (!input) return;
        var show = input.type === 'password';
        input.type = show ? 'text' : 'password';
        btn.textContent = show ? 'Hide' : 'Show';
        btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
    });
});
</script>

<?php require __DIR__ . '/_footer.php'; ?>

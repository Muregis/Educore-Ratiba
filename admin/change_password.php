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

// Handle password change
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    
    if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
        $error = 'All fields are required.';
    } elseif (strlen($newPassword) < 8) {
        $error = 'New password must be at least 8 characters.';
    } elseif ($newPassword !== $confirmPassword) {
        $error = 'New password and confirmation do not match.';
    } else {
        // Get current admin
        $adminId = (int) $_SESSION['school_admin_id'];
        $stmt = db()->prepare('SELECT * FROM school_admins WHERE id = ? AND school_id = ?');
        $stmt->execute([$adminId, $schoolId]);
        $admin = $stmt->fetch();
        
        if (!$admin) {
            $error = 'Admin account not found.';
        } elseif (!password_verify($currentPassword, $admin['password_hash'])) {
            $error = 'Current password is incorrect.';
        } else {
            // Update password
            $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
            $stmt = db()->prepare('UPDATE school_admins SET password_hash = ? WHERE id = ?');
            $stmt->execute([$newHash, $adminId]);
            $success = 'Password changed successfully.';
        }
    }
}

$pageTitle = 'Change Password — ' . $school['name'];
require __DIR__ . '/_header.php';
?>

<div class="card">
    <h2>Change Password</h2>
    
    <?php if ($error): ?>
        <div class="error"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
    
    <?php if ($success): ?>
        <div class="success"><?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>
    
    <form method="post">
        <label for="current_password">Current Password</label>
        <input type="password" id="current_password" name="current_password" required>
        
        <label for="new_password">New Password</label>
        <input type="password" id="new_password" name="new_password" required minlength="8" placeholder="Minimum 8 characters">
        
        <label for="confirm_password">Confirm New Password</label>
        <input type="password" id="confirm_password" name="confirm_password" required minlength="8" placeholder="Re-enter new password">
        
        <button type="submit">Change Password</button>
    </form>
</div>

<?php require __DIR__ . '/_footer.php'; ?>

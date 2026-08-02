<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/db/error_handler.php';
require_once __DIR__ . '/db/db.php';

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    
    // Try school admin login first
    $stmt = db()->prepare('SELECT sa.*, s.name as school_name FROM school_admins sa JOIN schools s ON sa.school_id = s.id WHERE sa.username = ?');
    $stmt->execute([$username]);
    $admin = $stmt->fetch();
    
    if ($admin && password_verify($password, $admin['password_hash'])) {
        $_SESSION['school_admin_id'] = $admin['id'];
        $_SESSION['school_id'] = $admin['school_id'];
        $_SESSION['school_name'] = $admin['school_name'];
        header('Location: admin/dashboard.php');
        exit;
    }
    
    // Try super admin login
    $stmt = db()->prepare('SELECT * FROM super_admins WHERE username = ?');
    $stmt->execute([$username]);
    $superAdmin = $stmt->fetch();
    
    if ($superAdmin && password_verify($password, $superAdmin['password_hash'])) {
        $_SESSION['super_admin_id'] = $superAdmin['id'];
        $_SESSION['super_admin_username'] = $superAdmin['username'];
        header('Location: super/schools.php');
        exit;
    }
    
    $error = 'Invalid username or password.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Login — EduCore Ratiba</title>
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
        --shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
        --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
    }
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { 
        font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; 
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        min-height: 100vh;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 20px;
    }
    .login-container {
        width: 100%;
        max-width: 420px;
    }
    .login-card {
        background: var(--bg-white);
        border-radius: 16px;
        padding: 40px;
        box-shadow: var(--shadow-lg);
        animation: slideUp 0.5s ease-out;
    }
    @keyframes slideUp {
        from { opacity: 0; transform: translateY(20px); }
        to { opacity: 1; transform: translateY(0); }
    }
    .logo-section {
        text-align: center;
        margin-bottom: 32px;
    }
    .logo-icon {
        width: 64px;
        height: 64px;
        background: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 100%);
        border-radius: 16px;
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 0 auto 16px;
        font-size: 32px;
        color: white;
        font-weight: 700;
    }
    .login-card h1 {
        font-size: 1.5rem;
        font-weight: 700;
        color: var(--text);
        margin-bottom: 8px;
    }
    .login-card .subtitle {
        font-size: 0.875rem;
        color: var(--text-muted);
        margin-bottom: 24px;
    }
    .form-group {
        margin-bottom: 20px;
    }
    label {
        display: block;
        font-size: 0.875rem;
        font-weight: 500;
        color: var(--text);
        margin-bottom: 8px;
    }
    input {
        width: 100%;
        padding: 12px 16px;
        border: 2px solid var(--border);
        border-radius: 8px;
        font-size: 0.95rem;
        font-family: inherit;
        transition: all 0.2s ease;
        background: var(--bg-light);
    }
    input:focus {
        outline: none;
        border-color: var(--primary);
        background: var(--bg-white);
        box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
    }
    button {
        width: 100%;
        background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
        color: white;
        border: none;
        padding: 14px;
        border-radius: 8px;
        font-weight: 600;
        font-size: 1rem;
        cursor: pointer;
        transition: all 0.2s ease;
        margin-top: 8px;
    }
    button:hover {
        transform: translateY(-1px);
        box-shadow: var(--shadow);
    }
    button:active {
        transform: translateY(0);
    }
    .error {
        background: #fef2f2;
        color: var(--danger);
        border: 1px solid #fecaca;
        border-radius: 8px;
        padding: 12px 16px;
        margin-bottom: 20px;
        font-size: 0.875rem;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .error::before {
        content: '⚠';
        font-size: 1.1rem;
    }
    .footer {
        text-align: center;
        margin-top: 24px;
        padding-top: 24px;
        border-top: 1px solid var(--border);
        font-size: 0.875rem;
        color: var(--text-muted);
    }
    .footer a {
        color: var(--primary);
        text-decoration: none;
        font-weight: 500;
        transition: color 0.2s ease;
    }
    .footer a:hover {
        color: var(--primary-dark);
    }
</style>
</head>
<body>

<div class="login-container">
    <div class="login-card">
        <div class="logo-section">
            <div class="logo-icon">📅</div>
            <h1>EduCore Ratiba</h1>
            <p class="subtitle">Sign in to manage your school timetables</p>
        </div>
        
        <?php if ($error): ?>
            <div class="error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <form method="post">
            <div class="form-group">
                <label for="username">Username</label>
                <input type="text" id="username" name="username" required autofocus placeholder="Enter your username">
            </div>
            
            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" required placeholder="Enter your password">
            </div>
            
            <button type="submit">Sign In</button>
        </form>
        
        <div class="footer">
            <a href="super/schools.php">Super Admin Portal →</a>
        </div>
    </div>
</div>

</body>
</html>

<?php
declare(strict_types=1);
// admin/_header.php — shared shell for all school-admin screens.
// Include after session_start() + requireLoginAndGetSchoolId(), with
// $pageTitle and optional $school (array) already set.

// Determine current page for active nav highlighting
$_currentPage = basename($_SERVER['PHP_SELF']);

// Helper: returns 'active' class if current page matches
function navActive(string ...$pages): string {
    global $_currentPage;
    return in_array($_currentPage, $pages, true) ? ' active' : '';
}

// Ensure CSRF token is generated for all pages
getCsrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo htmlspecialchars($pageTitle ?? 'Admin'); ?> — EduCore Ratiba</title>
<meta name="csrf-token" content="<?php echo htmlspecialchars(getCsrfToken()); ?>">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<style>
    :root { 
        --primary: #6366f1; 
        --primary-dark: #4f46e5;
        --primary-darker: #3730a3;
        --primary-light: #818cf8;
        --primary-bg: rgba(99,102,241,0.08);
        --secondary: #10b981;
        --secondary-dark: #059669;
        --danger: #ef4444;
        --danger-bg: #fef2f2;
        --warning: #f59e0b;
        --warning-bg: #fffbeb;
        --border: #e5e7eb;
        --border-dark: #d1d5db;
        --text: #111827;
        --text-muted: #6b7280;
        --text-light: #9ca3af;
        --bg-light: #f9fafb;
        --bg-white: #ffffff;
        --shadow-xs: 0 1px 2px rgba(0,0,0,0.05);
        --shadow: 0 1px 3px rgba(0,0,0,0.1), 0 1px 2px rgba(0,0,0,0.06);
        --shadow-md: 0 4px 6px -1px rgba(0,0,0,0.1), 0 2px 4px -1px rgba(0,0,0,0.06);
        --shadow-lg: 0 10px 15px -3px rgba(0,0,0,0.1), 0 4px 6px -2px rgba(0,0,0,0.05);
        --radius: 10px;
        --radius-lg: 14px;
        --radius-xl: 18px;
        --transition: all 0.18s ease;
    }
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { 
        font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; 
        background: var(--bg-light);
        color: var(--text);
        min-height: 100vh;
        font-size: 0.9rem;
        line-height: 1.5;
    }

    /* ── HEADER ── */
    header { 
        background: var(--bg-white);
        border-bottom: 1px solid var(--border);
        padding: 0 28px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 12px;
        position: sticky;
        top: 0;
        z-index: 200;
        box-shadow: var(--shadow);
        min-height: 60px;
    }
    .header-left {
        display: flex;
        align-items: center;
        gap: 14px;
        flex-shrink: 0;
    }
    .logo {
        font-size: 1.15rem;
        font-weight: 800;
        color: var(--primary);
        display: flex;
        align-items: center;
        gap: 8px;
        text-decoration: none;
        letter-spacing: -0.02em;
    }
    .logo-icon {
        width: 34px;
        height: 34px;
        background: linear-gradient(135deg, var(--primary), var(--primary-light));
        border-radius: 9px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.1rem;
        box-shadow: 0 2px 8px rgba(99,102,241,0.3);
    }
    .school-pill {
        display: flex;
        align-items: center;
        gap: 6px;
        background: var(--bg-light);
        border: 1px solid var(--border);
        border-radius: 20px;
        padding: 4px 12px 4px 8px;
        font-size: 0.8rem;
        font-weight: 600;
        color: var(--text-muted);
        max-width: 200px;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }
    .school-pill-dot {
        width: 8px;
        height: 8px;
        background: var(--secondary);
        border-radius: 50%;
        flex-shrink: 0;
    }
    header nav { 
        display: flex;
        align-items: center;
        gap: 2px;
        flex-wrap: wrap;
    }
    header nav a { 
        color: var(--text-muted);
        text-decoration: none;
        font-size: 0.8rem;
        font-weight: 500;
        padding: 6px 10px;
        border-radius: 7px;
        transition: var(--transition);
        white-space: nowrap;
        letter-spacing: 0.01em;
    }
    header nav a:hover { 
        color: var(--primary);
        background: var(--primary-bg);
    }
    header nav a.active {
        color: var(--primary);
        background: var(--primary-bg);
        font-weight: 600;
    }
    .nav-divider {
        width: 1px;
        height: 20px;
        background: var(--border);
        margin: 0 4px;
    }
    .nav-signout {
        color: var(--text-muted) !important;
    }
    .nav-signout:hover {
        color: var(--danger) !important;
        background: var(--danger-bg) !important;
    }

    /* ── MAIN CONTENT ── */
    main { 
        max-width: 1200px; 
        margin: 28px auto; 
        padding: 0 24px;
    }

    /* ── CARDS ── */
    .card { 
        background: var(--bg-white);
        border: 1px solid var(--border);
        border-radius: var(--radius-lg);
        padding: 24px;
        margin-bottom: 20px;
        box-shadow: var(--shadow);
        transition: var(--transition);
    }
    .card h2 { 
        font-size: 1.1rem;
        font-weight: 700;
        color: var(--text);
        margin: 0 0 18px;
        letter-spacing: -0.01em;
    }
    .card-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 18px;
        gap: 12px;
        flex-wrap: wrap;
    }
    .card-header h2 {
        margin: 0;
    }

    /* ── FORMS ── */
    label { 
        display: block; 
        font-size: 0.8rem;
        font-weight: 600;
        color: var(--text);
        margin-bottom: 6px;
        margin-top: 14px;
        letter-spacing: 0.01em;
        text-transform: uppercase;
    }
    label:first-of-type { margin-top: 0; }
    input, select, textarea { 
        width: 100%; 
        padding: 9px 13px;
        border: 1.5px solid var(--border);
        border-radius: 8px;
        font-size: 0.875rem;
        font-family: inherit;
        transition: var(--transition);
        background: var(--bg-white);
        color: var(--text);
    }
    input:focus, select:focus, textarea:focus {
        outline: none;
        border-color: var(--primary);
        box-shadow: 0 0 0 3px rgba(99,102,241,0.12);
    }
    input::placeholder, textarea::placeholder {
        color: var(--text-light);
    }

    /* ── BUTTONS ── */
    button, .btn { 
        background: var(--primary);
        color: white;
        border: none;
        padding: 9px 18px;
        border-radius: 8px;
        font-weight: 600;
        font-size: 0.8rem;
        cursor: pointer;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        transition: var(--transition);
        letter-spacing: 0.01em;
        white-space: nowrap;
    }
    button:hover, .btn:hover { 
        background: var(--primary-dark);
        transform: translateY(-1px);
        box-shadow: var(--shadow-md);
    }
    button:active, .btn:active {
        transform: translateY(0);
    }
    .btn-danger { 
        background: transparent;
        color: var(--danger);
        border: 1.5px solid #fecaca;
    }
    .btn-danger:hover { 
        background: var(--danger-bg);
        border-color: #fca5a5;
        transform: none;
        box-shadow: none;
    }
    .btn-secondary { 
        background: var(--bg-white);
        color: var(--text);
        border: 1.5px solid var(--border);
    }
    .btn-secondary:hover { 
        background: var(--bg-light);
        border-color: var(--border-dark);
        transform: none;
        box-shadow: none;
    }
    .btn-success {
        background: var(--secondary);
    }
    .btn-success:hover {
        background: var(--secondary-dark);
    }
    .btn-sm {
        padding: 5px 12px;
        font-size: 0.75rem;
    }
    .btn-icon {
        padding: 6px;
        border-radius: 7px;
    }

    /* ── TABLES ── */
    .table-wrapper {
        overflow-x: auto;
        border-radius: var(--radius);
        border: 1px solid var(--border);
    }
    table { 
        width: 100%; 
        border-collapse: collapse;
        font-size: 0.85rem;
    }
    th, td { 
        text-align: left; 
        padding: 11px 14px;
        border-bottom: 1px solid var(--border);
        vertical-align: middle;
    }
    th { 
        color: var(--text-muted);
        font-weight: 600;
        font-size: 0.72rem;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        background: var(--bg-light);
    }
    tbody tr:last-child td {
        border-bottom: none;
    }
    tbody tr:hover td {
        background: rgba(99,102,241,0.025);
    }

    /* ── ALERTS ── */
    .alert {
        display: flex;
        align-items: flex-start;
        gap: 10px;
        border-radius: 9px;
        padding: 12px 15px;
        margin-bottom: 14px;
        font-size: 0.85rem;
        line-height: 1.5;
    }
    .alert-icon {
        flex-shrink: 0;
        font-size: 1.1rem;
        margin-top: 1px;
    }
    .success, .alert-success { 
        background: #f0fdf4;
        color: #166534;
        border: 1px solid #bbf7d0;
    }
    .error, .alert-error { 
        background: var(--danger-bg);
        color: #991b1b;
        border: 1px solid #fecaca;
    }
    .alert-warning {
        background: var(--warning-bg);
        color: #92400e;
        border: 1px solid #fde68a;
    }
    .alert-info {
        background: #eff6ff;
        color: #1e40af;
        border: 1px solid #bfdbfe;
    }

    /* ── BADGES ── */
    .badge {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        padding: 3px 9px;
        border-radius: 12px;
        font-size: 0.72rem;
        font-weight: 600;
        letter-spacing: 0.02em;
    }
    .badge-primary, .badge-server {
        background: #e0e7ff;
        color: var(--primary-dark);
    }
    .badge-success {
        background: #d1fae5;
        color: var(--secondary-dark);
    }
    .badge-warning, .badge-local {
        background: #fef3c7;
        color: #b45309;
    }
    .badge-danger {
        background: #fee2e2;
        color: #b91c1c;
    }
    .badge-neutral {
        background: #f3f4f6;
        color: var(--text-muted);
    }

    /* ── EMPTY STATE ── */
    .empty-state {
        text-align: center;
        padding: 40px 20px;
        color: var(--text-muted);
    }
    .empty-state-icon {
        font-size: 3rem;
        margin-bottom: 12px;
        opacity: 0.5;
    }
    .empty-state h3 {
        font-size: 1rem;
        font-weight: 600;
        color: var(--text);
        margin-bottom: 6px;
    }
    .empty-state p {
        font-size: 0.85rem;
        margin-bottom: 16px;
    }
    .empty { 
        color: var(--text-muted);
        font-size: 0.85rem;
        padding: 8px 0;
    }

    /* ── ROW ACTIONS ── */
    .row-actions { 
        white-space: nowrap;
        display: flex;
        gap: 6px;
        align-items: center;
    }
    .row-actions form { 
        display: inline;
    }

    /* ── SEARCH BAR ── */
    .search-bar {
        display: flex;
        gap: 8px;
        align-items: center;
        flex-wrap: wrap;
    }
    .search-bar input, .search-bar select {
        margin: 0;
        width: auto;
    }
    .search-bar input { min-width: 200px; }

    /* ── INLINE EDIT ── */
    .inline-edit-view { display: flex; align-items: center; gap: 8px; }
    .inline-edit-form { display: none; }
    .inline-edit-form.active { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
    .inline-edit-form input, .inline-edit-form select { 
        width: auto; 
        min-width: 100px; 
        padding: 5px 9px;
        font-size: 0.82rem;
        margin: 0;
    }

    /* ── PROGRESS / SETUP ── */
    .setup-step {
        display: flex;
        align-items: flex-start;
        gap: 14px;
        padding: 12px 0;
        border-bottom: 1px solid var(--border);
    }
    .setup-step:last-child { border-bottom: none; }
    .step-number {
        width: 32px;
        height: 32px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 0.8rem;
        font-weight: 700;
        flex-shrink: 0;
    }
    .step-done { background: #d1fae5; color: var(--secondary-dark); }
    .step-next { background: #e0e7ff; color: var(--primary-dark); }
    .step-pending { background: var(--bg-light); color: var(--text-muted); border: 1.5px solid var(--border); }
    .step-content h4 { font-size: 0.875rem; font-weight: 600; margin-bottom: 2px; }
    .step-content p { font-size: 0.8rem; color: var(--text-muted); }

    /* ── RESPONSIVE ── */
    @media (max-width: 768px) {
        header { padding: 12px 16px; }
        header nav { width: 100%; overflow-x: auto; padding-bottom: 6px; }
        main { padding: 0 14px; margin: 20px auto; }
        .card { padding: 16px; }
        .card-header { flex-direction: column; align-items: flex-start; }
        .search-bar { width: 100%; }
        .search-bar input { width: 100%; min-width: unset; }
    }
</style>
<script>
// ── CSRF helper for AJAX requests ──
function getCsrf() {
    return document.querySelector('meta[name="csrf-token"]')?.content || '';
}

// ── Legacy confirm delete helper ──
function confirmDelete(message) {
    return confirm(message || 'Delete this item? This cannot be undone.');
}

// ── Inline edit toggle ──
function toggleEdit(rowId) {
    const view = document.getElementById('view-' + rowId);
    const form = document.getElementById('edit-' + rowId);
    if (!view || !form) return;
    const isEditing = form.classList.contains('active');
    if (isEditing) {
        view.style.display = 'flex';
        form.classList.remove('active');
    } else {
        view.style.display = 'none';
        form.classList.add('active');
        const firstInput = form.querySelector('input, select');
        if (firstInput) firstInput.focus();
    }
}

// ── Auto-dismiss alerts after 4 s ──
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.success, .alert-success').forEach(el => {
        setTimeout(() => { el.style.transition = 'opacity 0.5s'; el.style.opacity = '0'; setTimeout(() => el.remove(), 500); }, 4000);
    });
});
</script>
</head>
<body>
<header>
    <div class="header-left">
        <a href="dashboard.php" class="logo">
            <div class="logo-icon">📅</div>
            <span>EduCore Ratiba</span>
        </a>
        <?php if (!empty($school['name'])): ?>
        <div class="school-pill">
            <div class="school-pill-dot"></div>
            <?php echo htmlspecialchars($school['name']); ?>
        </div>
        <?php endif; ?>
    </div>
    <nav>
        <a href="dashboard.php" class="<?php echo navActive('dashboard.php'); ?>">Dashboard</a>
        <a href="teachers.php" class="<?php echo navActive('teachers.php'); ?>">Teachers</a>
        <a href="rooms.php" class="<?php echo navActive('rooms.php'); ?>">Rooms</a>
        <a href="bands.php" class="<?php echo navActive('bands.php'); ?>">Bands</a>
        <a href="classes.php" class="<?php echo navActive('classes.php'); ?>">Classes</a>
        <a href="subjects.php" class="<?php echo navActive('subjects.php'); ?>">Subjects</a>
        <a href="activities.php" class="<?php echo navActive('activities.php'); ?>">Activities</a>
        <a href="remedials.php" class="<?php echo navActive('remedials.php'); ?>">Remedials</a>
        <div class="nav-divider"></div>
        <a href="generate.php" class="<?php echo navActive('generate.php'); ?>">⚡ Generate</a>
        <a href="edit-timetable.php" class="<?php echo navActive('edit-timetable.php'); ?>">✏️ Edit</a>
        <a href="view_timetable.php" class="<?php echo navActive('view_timetable.php'); ?>">📋 View</a>
        <div class="nav-divider"></div>
        <a href="help.php" class="<?php echo navActive('help.php'); ?>">❓ Help</a>
        <a href="audit_log.php" class="<?php echo navActive('audit_log.php'); ?>">🔍 Audit</a>
        <a href="change_password.php" class="<?php echo navActive('change_password.php'); ?>">🔑 Password</a>
        <a href="../logout.php" class="nav-signout">Sign out</a>
    </nav>
</header>
<main>

<?php
declare(strict_types=1);
// admin/_header.php — fixed sidebar (desktop) + drawer (mobile)

$_currentPage = basename($_SERVER['PHP_SELF'] ?? '');

function navActive(string ...$pages): string {
    global $_currentPage;
    return in_array($_currentPage, $pages, true) ? ' active' : '';
}

if (function_exists('getCsrfToken')) {
    getCsrfToken();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?php echo htmlspecialchars($pageTitle ?? 'Admin'); ?> — EduCore Ratiba</title>
<meta name="csrf-token" content="<?php echo htmlspecialchars(function_exists('getCsrfToken') ? getCsrfToken() : ''); ?>">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/shell.css">
<script src="assets/shell.js"></script>
</head>
<body>

<aside class="sidebar" id="sidebar" aria-label="Main navigation">
    <div class="sidebar-brand">
        <span class="logo-icon" aria-hidden="true">📅</span>
        <a href="dashboard.php" class="brand-text">EduCore Ratiba</a>
    </div>
    <?php if (!empty($school['name'])): ?>
    <div class="sidebar-school" title="<?php echo htmlspecialchars($school['name']); ?>">
        <span class="sidebar-school-dot"></span>
        <?php echo htmlspecialchars($school['name']); ?>
    </div>
    <?php endif; ?>

    <nav class="sidebar-nav">
        <div class="nav-section">Overview</div>
        <a href="dashboard.php" class="<?php echo navActive('dashboard.php'); ?>" onclick="closeSidebar()">
            <span class="nav-ico">🏠</span> Dashboard
        </a>

        <div class="nav-section">School data</div>
        <a href="teachers.php" class="<?php echo navActive('teachers.php'); ?>" onclick="closeSidebar()">
            <span class="nav-ico">👨‍🏫</span> Teachers
        </a>
        <a href="rooms.php" class="<?php echo navActive('rooms.php'); ?>" onclick="closeSidebar()">
            <span class="nav-ico">🏫</span> Rooms
        </a>
        <a href="bands.php" class="<?php echo navActive('bands.php'); ?>" onclick="closeSidebar()">
            <span class="nav-ico">📚</span> Bands
        </a>
        <a href="classes.php" class="<?php echo navActive('classes.php'); ?>" onclick="closeSidebar()">
            <span class="nav-ico">🎓</span> Classes
        </a>
        <a href="subjects.php" class="<?php echo navActive('subjects.php'); ?>" onclick="closeSidebar()">
            <span class="nav-ico">📖</span> Subjects
        </a>
        <a href="activities.php" class="<?php echo navActive('activities.php'); ?>" onclick="closeSidebar()">
            <span class="nav-ico">🧩</span> Activities
        </a>
        <a href="remedials.php" class="<?php echo navActive('remedials.php'); ?>" onclick="closeSidebar()">
            <span class="nav-ico">🔧</span> Remedials
        </a>

        <div class="nav-section">Timetable</div>
        <a href="settings.php" class="<?php echo navActive('settings.php'); ?>" onclick="closeSidebar()">
            <span class="nav-ico">⚙️</span> Settings
        </a>
        <a href="generate.php" class="<?php echo navActive('generate.php'); ?>" onclick="closeSidebar()">
            <span class="nav-ico">⚡</span> Generate
        </a>
        <a href="edit-timetable.php" class="<?php echo navActive('edit-timetable.php'); ?>" onclick="closeSidebar()">
            <span class="nav-ico">✏️</span> Edit
        </a>
        <a href="view_timetable.php" class="<?php echo navActive('view_timetable.php'); ?>" onclick="closeSidebar()">
            <span class="nav-ico">📋</span> View
        </a>
        <a href="calendar.php" class="<?php echo navActive('calendar.php'); ?>" onclick="closeSidebar()">
            <span class="nav-ico">📅</span> Calendar
        </a>
        <a href="reports.php" class="<?php echo navActive('reports.php'); ?>" onclick="closeSidebar()">
            <span class="nav-ico">📊</span> Reports
        </a>

        <div class="nav-section">System</div>
        <a href="offline.php" class="<?php echo navActive('offline.php'); ?>" onclick="closeSidebar()">
            <span class="nav-ico">🔋</span> Offline
        </a>
        <a href="payments.php" class="<?php echo navActive('payments.php'); ?>" onclick="closeSidebar()">
            <span class="nav-ico">💳</span> Payments
        </a>
        <a href="help.php" class="<?php echo navActive('help.php'); ?>" onclick="closeSidebar()">
            <span class="nav-ico">❓</span> Help
        </a>
        <a href="audit_log.php" class="<?php echo navActive('audit_log.php'); ?>" onclick="closeSidebar()">
            <span class="nav-ico">🔍</span> Audit
        </a>
        <a href="change_password.php" class="<?php echo navActive('change_password.php'); ?>" onclick="closeSidebar()">
            <span class="nav-ico">🔑</span> Password
        </a>
    </nav>

    <div class="sidebar-footer">
        <a href="../logout.php"><span class="nav-ico">🚪</span> Sign out</a>
    </div>
</aside>

<div class="sidebar-overlay" id="sidebar-overlay" onclick="closeSidebar()" aria-hidden="true"></div>

<header class="topbar">
    <button type="button" class="menu-toggle" onclick="toggleSidebar()" aria-label="Open menu">☰</button>
    <div class="topbar-title"><?php echo htmlspecialchars($pageTitle ?? 'Admin'); ?></div>
    <?php if (!empty($school['name'])): ?>
    <span class="topbar-school"><?php echo htmlspecialchars($school['name']); ?></span>
    <?php endif; ?>
</header>

<main class="app-main">

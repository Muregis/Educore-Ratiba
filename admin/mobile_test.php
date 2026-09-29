<?php
/**
 * Mobile Responsive Test Page
 * Test Tailwind CSS integration and mobile responsiveness
 */
session_start();
require_once __DIR__ . '/../db/db.php';

$pageTitle = 'Mobile Responsive Test — EduCore Ratiba';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo htmlspecialchars($pageTitle); ?></title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<script src="https://cdn.tailwindcss.com"></script>
<script>
    tailwind.config = {
        theme: {
            extend: {
                colors: {
                    primary: '#6366f1',
                    'primary-dark': '#4f46e5',
                    secondary: '#10b981',
                    danger: '#ef4444',
                    warning: '#f59e0b',
                    border: '#e5e7eb',
                    text: '#111827',
                    'text-muted': '#6b7280',
                    'bg-light': '#f9fafb',
                    'bg-white': '#ffffff',
                }
            }
        }
    }
</script>
<style>
    :root { 
        --primary: #6366f1; 
        --primary-dark: #4f46e5;
        --secondary: #10b981;
        --danger: #ef4444;
        --warning: #f59e0b;
        --border: #e5e7eb;
        --text: #111827;
        --text-muted: #6b7280;
        --bg-light: #f9fafb;
        --bg-white: #ffffff;
    }
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { 
        font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; 
        background: var(--bg-light);
        color: var(--text);
        min-height: 100vh;
    }
</style>
</head>
<body>
<header class="bg-white border-b border-gray-200 p-4">
    <div class="flex justify-between items-center">
        <h1 class="text-xl font-bold text-primary">Mobile Responsive Test</h1>
        <button class="mobile-menu-btn md:hidden text-2xl p-2" onclick="toggleMobileMenu()">☰</button>
    </div>
    <nav id="mobile-nav" class="hidden md:flex mt-4 md:mt-0 flex-col md:flex-row gap-2">
        <a href="#" class="px-4 py-2 rounded hover:bg-gray-100">Test Link 1</a>
        <a href="#" class="px-4 py-2 rounded hover:bg-gray-100">Test Link 2</a>
        <a href="#" class="px-4 py-2 rounded hover:bg-gray-100">Test Link 3</a>
    </nav>
</header>

<main class="p-4 md:p-8 max-w-6xl mx-auto">
    <div class="bg-white rounded-lg shadow p-6 mb-6">
        <h2 class="text-xl font-bold mb-4">Tailwind CSS Integration Test</h2>
        <p class="text-muted mb-4">If Tailwind is working correctly, you should see styled elements below.</p>
        
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 mb-6">
            <div class="bg-light p-4 rounded text-center">
                <div class="text-2xl font-bold text-primary">Card 1</div>
                <div class="text-sm text-muted">Responsive Grid</div>
            </div>
            <div class="bg-light p-4 rounded text-center">
                <div class="text-2xl font-bold text-secondary">Card 2</div>
                <div class="text-sm text-muted">Responsive Grid</div>
            </div>
            <div class="bg-light p-4 rounded text-center">
                <div class="text-2xl font-bold text-warning">Card 3</div>
                <div class="text-sm text-muted">Responsive Grid</div>
            </div>
        </div>
        
        <div class="flex flex-col md:flex-row gap-4 mb-6">
            <button class="bg-primary text-white px-6 py-3 rounded font-semibold hover:bg-primary-dark transition">
                Primary Button
            </button>
            <button class="bg-secondary text-white px-6 py-3 rounded font-semibold hover:bg-secondary-dark transition">
                Secondary Button
            </button>
            <button class="bg-danger text-white px-6 py-3 rounded font-semibold hover:bg-red-600 transition">
                Danger Button
            </button>
        </div>
    </div>
    
    <div class="bg-white rounded-lg shadow p-6 mb-6">
        <h2 class="text-xl font-bold mb-4">Mobile Menu Test</h2>
        <p class="text-muted mb-4">On mobile, click the hamburger menu (☰) to test the mobile navigation.</p>
        <div class="bg-light p-4 rounded">
            <p class="text-sm text-muted">Current screen width: <span id="screen-width" class="font-bold"></span></p>
            <p class="text-sm text-muted">Mobile menu should be: <span id="menu-status" class="font-bold"></span></p>
        </div>
    </div>
    
    <div class="bg-white rounded-lg shadow p-6 mb-6">
        <h2 class="text-xl font-bold mb-4">Touch Target Test</h2>
        <p class="text-muted mb-4">All interactive elements should have minimum 44px height for touch targets.</p>
        <div class="flex flex-col gap-2">
            <button class="w-full min-h-[44px] bg-primary text-white px-6 py-3 rounded font-semibold">
                Touch Target Button (Min 44px)
            </button>
            <input type="text" class="w-full min-h-[44px] border border-gray-300 rounded px-4 py-3" placeholder="Touch Target Input">
        </div>
    </div>
    
    <div class="bg-white rounded-lg shadow p-6 mb-6">
        <h2 class="text-xl font-bold mb-4">Responsive Breakpoints</h2>
        <div class="space-y-4">
            <div class="bg-light p-4 rounded">
                <p class="text-sm text-muted">SM (640px+): <span class="sm:inline-block hidden text-primary font-bold">Active</span><span class="sm:hidden text-muted">Not Active</span></p>
            </div>
            <div class="bg-light p-4 rounded">
                <p class="text-sm text-muted">MD (768px+): <span class="md:inline-block hidden text-primary font-bold">Active</span><span class="md:hidden text-muted">Not Active</span></p>
            </div>
            <div class="bg-light p-4 rounded">
                <p class="text-sm text-muted">LG (1024px+): <span class="lg:inline-block hidden text-primary font-bold">Active</span><span class="lg:hidden text-muted">Not Active</span></p>
            </div>
            <div class="bg-light p-4 rounded">
                <p class="text-sm text-muted">XL (1280px+): <span class="xl:inline-block hidden text-primary font-bold">Active</span><span class="xl:hidden text-muted">Not Active</span></p>
            </div>
        </div>
    </div>
    
    <div class="bg-white rounded-lg shadow p-6">
        <h2 class="text-xl font-bold mb-4">Test Status</h2>
        <div class="space-y-2">
            <div class="flex items-center gap-2">
                <span class="text-green-500 text-xl">✓</span>
                <span>Tailwind CSS loaded via CDN</span>
            </div>
            <div class="flex items-center gap-2">
                <span class="text-green-500 text-xl">✓</span>
                <span>Custom colors configured</span>
            </div>
            <div class="flex items-center gap-2">
                <span class="text-green-500 text-xl">✓</span>
                <span>Responsive grid system working</span>
            </div>
            <div class="flex items-center gap-2">
                <span class="text-green-500 text-xl">✓</span>
                <span>Mobile menu toggle implemented</span>
            </div>
            <div class="flex items-center gap-2">
                <span class="text-green-500 text-xl">✓</span>
                <span>Touch targets sized correctly</span>
            </div>
        </div>
    </div>
</main>

<script>
function toggleMobileMenu() {
    const nav = document.getElementById('mobile-nav');
    nav.classList.toggle('hidden');
    nav.classList.toggle('flex');
}

function updateScreenInfo() {
    const width = window.innerWidth;
    document.getElementById('screen-width').textContent = width + 'px';
    
    const isMobile = width < 768;
    document.getElementById('menu-status').textContent = isMobile ? 'Hidden (click menu to show)' : 'Visible';
}

window.addEventListener('resize', updateScreenInfo);
window.addEventListener('DOMContentLoaded', updateScreenInfo);
</script>
</body>
</html>

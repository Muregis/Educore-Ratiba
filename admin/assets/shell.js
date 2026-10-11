function getCsrf() {
    return document.querySelector('meta[name="csrf-token"]')?.content || '';
}

function confirmDelete(message) {
    return confirm(message || 'Delete this item? This cannot be undone.');
}

function openSidebar() {
    document.getElementById('sidebar')?.classList.add('open');
    document.getElementById('sidebar-overlay')?.classList.add('open');
    document.body.classList.add('nav-open');
}

function closeSidebar() {
    document.getElementById('sidebar')?.classList.remove('open');
    document.getElementById('sidebar-overlay')?.classList.remove('open');
    document.body.classList.remove('nav-open');
}

function toggleSidebar() {
    const s = document.getElementById('sidebar');
    if (s?.classList.contains('open')) closeSidebar();
    else openSidebar();
}

document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closeSidebar();
});

/* Touch gesture: Swipe left to close mobile drawer */
(function() {
    let touchStartX = 0;
    let touchStartY = 0;

    document.addEventListener('touchstart', function(e) {
        if (e.touches && e.touches.length === 1) {
            touchStartX = e.touches[0].clientX;
            touchStartY = e.touches[0].clientY;
        }
    }, { passive: true });

    document.addEventListener('touchend', function(e) {
        if (!document.body.classList.contains('nav-open')) return;
        if (!e.changedTouches || e.changedTouches.length === 0) return;

        const touchEndX = e.changedTouches[0].clientX;
        const touchEndY = e.changedTouches[0].clientY;
        const dx = touchEndX - touchStartX;
        const dy = touchEndY - touchStartY;

        // If swipe left is significant and more horizontal than vertical
        if (dx < -60 && Math.abs(dx) > Math.abs(dy)) {
            closeSidebar();
        }
    }, { passive: true });
})();

/* Day tab filter for mobile timetables (view_timetable.php and edit-timetable.php) */
function filterTimetableDay(btnOrDay, targetDay) {
    const day = typeof btnOrDay === 'string' ? btnOrDay : targetDay;

    // Update active tab buttons
    document.querySelectorAll('.day-tab').forEach(function(tab) {
        if (tab.getAttribute('data-day') === day) {
            tab.classList.add('active');
        } else {
            tab.classList.remove('active');
        }
    });

    // Update all timetable wraps on page
    document.querySelectorAll('.tt-wrap').forEach(function(wrap) {
        if (!day || day === 'all') {
            wrap.removeAttribute('data-filter-day');
            wrap.querySelectorAll('.tt-grid').forEach(function(grid) {
                grid.classList.remove('filtering-day');
            });
            // Reset break row colspans
            const daysCount = wrap.querySelectorAll('thead th[data-day]').length;
            wrap.querySelectorAll('.tt-break-row td:not(.tt-time)').forEach(function(td) {
                if (daysCount > 0) td.setAttribute('colspan', daysCount);
            });
        } else {
            wrap.setAttribute('data-filter-day', day);
            wrap.querySelectorAll('.tt-grid').forEach(function(grid) {
                grid.classList.add('filtering-day');
            });
            // When single day is filtered, break row spans 1 day column
            wrap.querySelectorAll('.tt-break-row td:not(.tt-time)').forEach(function(td) {
                td.setAttribute('colspan', '1');
            });
        }
    });
}

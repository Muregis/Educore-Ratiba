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

(() => {
    const header = document.querySelector('[data-member-header]');
    if (!header) return;
    const panels = () => header.querySelectorAll('[data-member-menu], .notification-dropdown');
    header.addEventListener('toggle', event => {
        if (!(event.target instanceof HTMLDetailsElement) || !event.target.open || !event.target.matches('[data-member-menu], .notification-dropdown')) return;
        panels().forEach(panel => { if (panel !== event.target) panel.open = false; });
    }, true);
    document.addEventListener('click', event => {
        panels().forEach(panel => { if (panel.open && !panel.contains(event.target)) panel.open = false; });
    });
    document.addEventListener('keydown', event => {
        if (event.key !== 'Escape') return;
        panels().forEach(panel => {
            if (!panel.open) return;
            panel.open = false;
            panel.querySelector('summary')?.focus();
        });
    });
})();

(() => {
    const button = document.querySelector('.admin-menu-toggle');
    const menu = document.getElementById('admin-menu');
    const backdrop = document.querySelector('.admin-menu-backdrop');
    const workspace = document.getElementById('admin-main');
    if (!button || !menu || !backdrop || !workspace) return;

    const mobile = window.matchMedia('(max-width: 760px)');
    const setExpanded = (expanded) => {
        const drawerOpen = mobile.matches && expanded;
        if (!expanded && menu.contains(document.activeElement)) button.focus({ preventScroll: true });
        backdrop.hidden = !drawerOpen;
        workspace.inert = drawerOpen;
        menu.hidden = !expanded;
        button.setAttribute('aria-expanded', String(expanded));
        button.setAttribute('aria-label', expanded ? '收起管理菜单' : '展开管理菜单');
        if (drawerOpen) menu.focus({ preventScroll: true });
    };

    button.hidden = false;
    setExpanded(!mobile.matches);
    mobile.addEventListener('change', () => setExpanded(!mobile.matches));
    button.addEventListener('click', () => setExpanded(menu.hidden));
    backdrop.addEventListener('click', () => setExpanded(false));
    document.addEventListener('keydown', (event) => {
        if (!event.defaultPrevented && event.key === 'Escape' && !backdrop.hidden) {
            event.preventDefault();
            setExpanded(false);
        }
    });
})();

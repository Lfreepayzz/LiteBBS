(() => {
  const root = document.documentElement;
  const system = window.matchMedia('(prefers-color-scheme: dark)');
  const storageKey = 'litebbs-default-color-scheme';
  let toggle;
  let mode;
  try { mode = localStorage.getItem(storageKey); } catch { /* Storage is optional. */ }

  const applyMode = () => {
    const dark = mode === 'dark' || (mode !== 'light' && system.matches);
    root.dataset.theme = dark ? 'dark' : 'light';
    toggle?.setAttribute('aria-pressed', String(dark));
  };
  applyMode();
  system.addEventListener('change', applyMode);

  document.addEventListener('DOMContentLoaded', () => {
    toggle = document.getElementById('theme-mode');
    if (toggle) {
      applyMode();
      toggle.hidden = false;
      toggle.addEventListener('click', () => {
        mode = root.dataset.theme === 'dark' ? 'light' : 'dark';
        try { localStorage.setItem(storageKey, mode); } catch { /* Keep the current page usable. */ }
        applyMode();
      });
    }

    const menu = document.getElementById('toggle-menu');
    if (menu) {
      const extensionNavigation = document.getElementById('extension-navigation');
      if (extensionNavigation) {
        const desktopNavigation = extensionNavigation.parentElement;
        const mobileNavigation = menu.querySelector('.nav-popover');
        const narrow = window.matchMedia('(max-width: 768px)');
        const placeNavigation = () => (narrow.matches ? mobileNavigation : desktopNavigation).append(extensionNavigation);
        placeNavigation();
        narrow.addEventListener('change', placeNavigation);
      }
      document.addEventListener('click', (event) => {
        if (menu.open && (!menu.contains(event.target) || event.target.closest('a'))) menu.open = false;
      });
      menu.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && menu.open) {
          menu.open = false;
          menu.querySelector('summary').focus();
        }
      });
    }

  });
})();

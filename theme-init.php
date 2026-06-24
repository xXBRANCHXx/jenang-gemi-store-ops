<?php
declare(strict_types=1);
?>
<script>
(() => {
  const themes = ['dark', 'light', 'system'];
  const legacyThemes = {
    graphite: 'dark',
    glass: 'light',
    ivory: 'light',
    prism: 'dark'
  };
  try {
    const storedTheme = window.localStorage.getItem('jg-admin-theme');
    const theme = themes.includes(storedTheme) ? storedTheme : (legacyThemes[storedTheme] || 'dark');
    const systemTheme = window.matchMedia('(prefers-color-scheme: light)');
    const resolvedTheme = theme === 'system' && systemTheme.matches
      ? 'light'
      : 'dark';
    document.documentElement.dataset.adminThemePreference = theme;
    document.documentElement.dataset.adminTheme = theme === 'system' ? resolvedTheme : theme;
    if (storedTheme !== theme) window.localStorage.setItem('jg-admin-theme', theme);
    const syncSystemTheme = () => {
      if (document.documentElement.dataset.adminThemePreference === 'system') {
        document.documentElement.dataset.adminTheme = systemTheme.matches ? 'light' : 'dark';
      }
    };
    if (typeof systemTheme.addEventListener === 'function') systemTheme.addEventListener('change', syncSystemTheme);
    else if (typeof systemTheme.addListener === 'function') systemTheme.addListener(syncSystemTheme);
  } catch (_error) {
    document.documentElement.dataset.adminThemePreference = 'dark';
    document.documentElement.dataset.adminTheme = 'dark';
  }
})();
</script>

<?php
declare(strict_types=1);
?>
<script>
(() => {
  const themes = ['dark', 'light', 'graphite', 'glass', 'ivory', 'prism'];
  try {
    const theme = window.localStorage.getItem('jg-admin-theme');
    document.documentElement.dataset.adminTheme = themes.includes(theme) ? theme : 'dark';
  } catch (_error) {
    document.documentElement.dataset.adminTheme = 'dark';
  }
})();
</script>

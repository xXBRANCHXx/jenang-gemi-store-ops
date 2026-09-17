(() => {
  const form = document.querySelector('[data-employee-login-form]');
  const username = form?.querySelector('[data-employee-username-input]');
  const employeeId = form?.querySelector('[data-employee-id-input]');
  const password = form?.querySelector('#admin_code');
  const tiles = Array.from(form?.querySelectorAll('[data-employee-login-id]') || []);
  if (!username || !employeeId) return;
  const findTile = () => {
    const value = username.value.trim().toLowerCase();
    const exact = tiles.find(tile => tile.dataset.employeeLoginId.toLowerCase() === value);
    if (exact) return exact;
    const legacy = tiles.filter(tile => tile.dataset.employeeLoginName.toLowerCase() === value);
    return legacy.length === 1 ? legacy[0] : null;
  };
  const syncIdentity = () => {
    const selected = findTile();
    employeeId.value = selected?.dataset.employeeLoginId || '';
    tiles.forEach(tile => {
      const active = tile === selected;
      tile.classList.toggle('is-active', active);
      tile.setAttribute('aria-pressed', String(active));
    });
    username.setCustomValidity(selected ? '' : 'Choose a valid employee account.');
    return selected;
  };
  tiles.forEach(tile => tile.addEventListener('click', () => {
    const changed = employeeId.value !== tile.dataset.employeeLoginId;
    username.value = tile.dataset.employeeLoginId;
    syncIdentity();
    if (changed && password) password.value = '';
    password?.focus();
  }));
  username.addEventListener('input', syncIdentity);
  username.addEventListener('change', syncIdentity);
  // Do not block a subsequent browser autofill with a stale custom error.
  password?.addEventListener('input', syncIdentity);
  form.addEventListener('submit', event => {
    const selected = syncIdentity();
    if (!selected) { event.preventDefault(); username.reportValidity(); return; }
    username.value = selected.dataset.employeeLoginId;
  });
  window.addEventListener('pageshow', syncIdentity);
})();

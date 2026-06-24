document.addEventListener('DOMContentLoaded', () => {
  const root = document.querySelector('[data-store-shell]');
  if (!root) return;

  const sidebarBackdrop = document.querySelector('[data-store-sidebar-backdrop]');
  const sidebarToggles = document.querySelectorAll('[data-store-sidebar-toggle]');
  const employeeProfilesModal = document.querySelector('[data-employee-profiles-modal]');
  const employeeProfileForm = document.querySelector('[data-employee-profile-form]');
  const employeeProfileError = document.querySelector('[data-employee-profile-error]');
  const employeeProfileList = document.querySelector('[data-employee-profile-list]');
  const compactSidebarQuery = window.matchMedia('(max-width: 760px)');
  const sidebarStorageKey = 'jg-store-sidebar-expanded';
  const employeeProfilesEndpoint = root.dataset.employeeProfilesEndpoint || '../api/employees-v2/';

  let employeeProfiles = [];

  const escapeHtml = (value) => String(value)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');

  const readJsonResponse = async (response, fallbackMessage) => {
    const text = await response.text();
    if (text.trim() === '') {
      throw new Error(`${fallbackMessage} Empty response from ${response.url || 'server'}. HTTP ${response.status}.`);
    }
    try {
      return JSON.parse(text);
    } catch (_error) {
      const preview = text.replace(/\s+/g, ' ').trim().slice(0, 220);
      throw new Error(`${fallbackMessage} Expected JSON but got HTTP ${response.status}: ${preview || 'no response body'}`);
    }
  };

  const storedSidebarExpanded = () => {
    try {
      const stored = window.localStorage.getItem(sidebarStorageKey);
      return stored === null ? true : stored === '1';
    } catch (_error) {
      return true;
    }
  };

  const setSidebarExpanded = (expanded, { persist = !compactSidebarQuery.matches } = {}) => {
    const nextExpanded = Boolean(expanded);
    root.classList.toggle('is-sidebar-expanded', nextExpanded);
    sidebarToggles.forEach((button) => {
      button.setAttribute('aria-expanded', String(nextExpanded));
      button.setAttribute('aria-label', nextExpanded ? 'Collapse navigation' : 'Open navigation');
      button.title = nextExpanded ? 'Collapse navigation' : 'Open navigation';
    });
    if (sidebarBackdrop) sidebarBackdrop.hidden = !(compactSidebarQuery.matches && nextExpanded);
    if (!persist) return;
    try {
      window.localStorage.setItem(sidebarStorageKey, nextExpanded ? '1' : '0');
    } catch (_error) {
      // Navigation remains usable without storage.
    }
  };

  const syncSidebarForViewport = () => {
    setSidebarExpanded(compactSidebarQuery.matches ? false : storedSidebarExpanded(), { persist: false });
  };

  const normalizeEmployeeId = (value) => String(value || '')
    .trim()
    .toLowerCase()
    .replace(/[^a-z0-9._-]+/g, '-')
    .replace(/^[._-]+|[._-]+$/g, '')
    .slice(0, 64);

  const showEmployeeProfileError = (message) => {
    if (!employeeProfileError) return;
    employeeProfileError.textContent = message;
    employeeProfileError.hidden = false;
  };

  const clearEmployeeProfileError = () => {
    if (!employeeProfileError) return;
    employeeProfileError.textContent = '';
    employeeProfileError.hidden = true;
  };

  const employeeProfileFields = () => ({
    id: employeeProfileForm?.querySelector('input[name="id"]') || null,
    displayName: employeeProfileForm?.querySelector('input[name="display_name"]') || null,
    pin: employeeProfileForm?.querySelector('input[name="pin"]') || null,
    active: employeeProfileForm?.querySelector('input[name="active"]') || null
  });

  const resetEmployeeProfileForm = () => {
    if (!employeeProfileForm) return;
    employeeProfileForm.reset();
    const fields = employeeProfileFields();
    if (fields.id instanceof HTMLInputElement) {
      fields.id.readOnly = false;
      fields.id.value = '';
      fields.id.dataset.userEdited = '';
    }
    if (fields.displayName instanceof HTMLInputElement) fields.displayName.value = '';
    if (fields.pin instanceof HTMLInputElement) {
      fields.pin.value = '';
      fields.pin.placeholder = 'Required for new profile';
    }
    if (fields.active instanceof HTMLInputElement) fields.active.checked = true;
    clearEmployeeProfileError();
  };

  const renderEmployeeProfiles = () => {
    if (!employeeProfileList) return;
    if (!employeeProfiles.length) {
      employeeProfileList.innerHTML = '<p class="admin-empty">No employee profiles yet.</p>';
      return;
    }

    employeeProfileList.innerHTML = employeeProfiles.map((employee) => {
      const isActive = Boolean(employee.active);
      return `
        <article class="admin-employee-profile-row">
          <span class="admin-employee-profile-avatar" aria-hidden="true">
            <svg viewBox="0 0 24 24"><path d="M20 21a8 8 0 0 0-16 0"/><circle cx="12" cy="7" r="4"/></svg>
          </span>
          <div class="admin-employee-profile-main">
            <strong>${escapeHtml(employee.display_name || employee.id)}</strong>
            <span>${escapeHtml(employee.id)}</span>
          </div>
          <span class="admin-status-badge ${isActive ? '' : 'admin-status-badge-warn'}">${isActive ? 'Active' : 'Inactive'}</span>
          <button type="button" class="admin-ghost-btn" data-edit-employee-profile="${escapeHtml(employee.id)}">Edit</button>
        </article>
      `;
    }).join('');
  };

  const loadEmployeeProfiles = async () => {
    if (!employeeProfilesModal) return;
    if (employeeProfileList) employeeProfileList.innerHTML = '<p class="admin-empty">Loading employee profiles.</p>';
    const response = await fetch(employeeProfilesEndpoint, {
      cache: 'no-store',
      credentials: 'same-origin',
      headers: { Accept: 'application/json' }
    });
    const payload = await readJsonResponse(response, 'Unable to load employee profiles.');
    if (!response.ok || payload.ok === false) {
      throw new Error(payload.error || 'Unable to load employee profiles.');
    }
    employeeProfiles = Array.isArray(payload.employees) ? payload.employees : [];
    renderEmployeeProfiles();
  };

  const editEmployeeProfile = (employeeId) => {
    const employee = employeeProfiles.find((item) => String(item.id || '') === employeeId);
    if (!employee || !employeeProfileForm) return;
    const fields = employeeProfileFields();
    if (fields.id instanceof HTMLInputElement) {
      fields.id.value = employee.id || '';
      fields.id.readOnly = true;
      fields.id.dataset.userEdited = '1';
    }
    if (fields.displayName instanceof HTMLInputElement) fields.displayName.value = employee.display_name || '';
    if (fields.pin instanceof HTMLInputElement) {
      fields.pin.value = '';
      fields.pin.placeholder = 'Leave blank to keep current PIN';
    }
    if (fields.active instanceof HTMLInputElement) fields.active.checked = Boolean(employee.active);
    clearEmployeeProfileError();
    if (fields.displayName instanceof HTMLInputElement) fields.displayName.focus();
  };

  const openEmployeeProfilesModal = () => {
    if (!employeeProfilesModal) return;
    resetEmployeeProfileForm();
    employeeProfilesModal.hidden = false;
    loadEmployeeProfiles()
      .then(() => {
        const fields = employeeProfileFields();
        if (fields.displayName instanceof HTMLInputElement) window.setTimeout(() => fields.displayName.focus(), 40);
      })
      .catch((error) => {
        employeeProfiles = [];
        renderEmployeeProfiles();
        showEmployeeProfileError(error instanceof Error ? error.message : 'Unable to load employee profiles.');
      });
  };

  const closeEmployeeProfilesModal = () => {
    if (employeeProfilesModal) employeeProfilesModal.hidden = true;
  };

  const saveEmployeeProfile = async () => {
    if (!employeeProfileForm) return;
    const fields = employeeProfileFields();
    const id = fields.id instanceof HTMLInputElement ? normalizeEmployeeId(fields.id.value) : '';
    const displayName = fields.displayName instanceof HTMLInputElement ? fields.displayName.value.trim() : '';
    const pin = fields.pin instanceof HTMLInputElement ? fields.pin.value : '';
    const active = fields.active instanceof HTMLInputElement ? fields.active.checked : true;

    clearEmployeeProfileError();

    const response = await fetch(employeeProfilesEndpoint, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({
        action: 'save_employee',
        id,
        display_name: displayName,
        pin,
        active
      })
    });
    const payload = await readJsonResponse(response, 'Unable to save employee profile.');
    if (!response.ok || payload.ok === false) {
      throw new Error(payload.error || 'Unable to save employee profile.');
    }

    employeeProfiles = Array.isArray(payload.employees) ? payload.employees : [];
    renderEmployeeProfiles();
    resetEmployeeProfileForm();
  };

  document.querySelectorAll('[data-open-employee-profiles]').forEach((button) => {
    button.addEventListener('click', openEmployeeProfilesModal);
  });
  document.querySelectorAll('[data-close-employee-profiles]').forEach((button) => {
    button.addEventListener('click', closeEmployeeProfilesModal);
  });
  document.querySelector('[data-new-employee-profile]')?.addEventListener('click', () => {
    resetEmployeeProfileForm();
    const fields = employeeProfileFields();
    if (fields.displayName instanceof HTMLInputElement) fields.displayName.focus();
  });
  employeeProfileList?.addEventListener('click', (event) => {
    const target = event.target instanceof Element ? event.target : null;
    const button = target?.closest('[data-edit-employee-profile]');
    if (!(button instanceof HTMLButtonElement)) return;
    editEmployeeProfile(button.dataset.editEmployeeProfile || '');
  });
  employeeProfileForm?.addEventListener('input', (event) => {
    const fields = employeeProfileFields();
    if (!(fields.id instanceof HTMLInputElement) || fields.id.readOnly) return;
    if (event.target === fields.id) {
      fields.id.dataset.userEdited = '1';
      fields.id.value = normalizeEmployeeId(fields.id.value);
      return;
    }
    if (event.target === fields.displayName && fields.id.dataset.userEdited !== '1') {
      fields.id.value = normalizeEmployeeId(fields.displayName?.value || '');
    }
  });
  employeeProfileForm?.addEventListener('submit', (event) => {
    event.preventDefault();
    saveEmployeeProfile().catch((error) => {
      showEmployeeProfileError(error instanceof Error ? error.message : 'Unable to save employee profile.');
    });
  });

  sidebarToggles.forEach((button) => {
    button.addEventListener('click', () => {
      setSidebarExpanded(!root.classList.contains('is-sidebar-expanded'));
    });
  });
  sidebarBackdrop?.addEventListener('click', () => setSidebarExpanded(false, { persist: false }));
  document.querySelectorAll('.admin-store-sidebar a').forEach((link) => {
    link.addEventListener('click', () => {
      if (compactSidebarQuery.matches) setSidebarExpanded(false, { persist: false });
    });
  });
  if (typeof compactSidebarQuery.addEventListener === 'function') {
    compactSidebarQuery.addEventListener('change', syncSidebarForViewport);
  } else if (typeof compactSidebarQuery.addListener === 'function') {
    compactSidebarQuery.addListener(syncSidebarForViewport);
  }

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && compactSidebarQuery.matches && root.classList.contains('is-sidebar-expanded')) {
      setSidebarExpanded(false, { persist: false });
      return;
    }
    if (event.key === 'Escape' && employeeProfilesModal?.hidden === false) {
      closeEmployeeProfilesModal();
    }
  });

  window.addEventListener('storage', (event) => {
    if (event.key === sidebarStorageKey && !compactSidebarQuery.matches) {
      setSidebarExpanded(event.newValue !== '0', { persist: false });
    }
  });

  syncSidebarForViewport();
});

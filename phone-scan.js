document.addEventListener('DOMContentLoaded', () => {
  const root = document.querySelector('[data-phone-scanner]');
  if (!root) return;

  const video = document.querySelector('[data-camera-video]');
  const statusNode = document.querySelector('[data-phone-status]');
  const errorNode = document.querySelector('[data-phone-error]');
  const confirmShell = document.querySelector('[data-phone-confirm]');
  const confirmProduct = document.querySelector('[data-confirm-product]');
  const confirmSku = document.querySelector('[data-confirm-sku]');
  const confirmSend = document.querySelector('[data-confirm-send]');
  const confirmCancel = document.querySelector('[data-confirm-cancel]');
  const standbyControls = document.querySelector('[data-phone-standby-controls]');
  const settingsButton = document.querySelector('[data-phone-settings]');
  const profileBadge = document.querySelector('[data-phone-profile-badge]');
  const profileStorageKey = 'jg-store-profile';
  const scanBridgeEndpoint = '../../api/scan-bridge/';
  let detector = null;
  let stream = null;
  let scanning = false;
  let cameraReady = false;
  let cameraStarting = false;
  let cameraFailed = false;
  let pendingScan = null;
  let audioContext = null;
  let wakeLock = null;
  let hapticWarningShown = false;
  let currentProfile = null;
  let activeSession = { active: false, order_id: '' };
  let standbyShell = null;
  let profileGate = null;
  let profiles = [];

  if (statusNode) statusNode.textContent = 'Ready';

  const setError = (message) => {
    if (!errorNode) return;
    errorNode.textContent = message;
    errorNode.hidden = message === '';
  };

  const normalizeProfile = (value) => String(value || '')
    .trim()
    .toLowerCase()
    .replace(/[^a-z0-9._-]+/g, '-')
    .replace(/^[._-]+|[._-]+$/g, '')
    .slice(0, 40);

  const escapeHtml = (value) => String(value ?? '')
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#039;');

  const readProfile = () => {
    const params = new URLSearchParams(window.location.search);
    const fromUrl = normalizeProfile(params.get('profile') || '');
    if (fromUrl) return { username: fromUrl };
    try {
      const stored = JSON.parse(window.localStorage.getItem(profileStorageKey) || 'null');
      const username = normalizeProfile(stored?.username || stored);
      return username ? { username } : null;
    } catch (_error) {
      return null;
    }
  };

  const setCurrentProfile = (username) => {
    const profile = normalizeProfile(username);
    if (!profile) throw new Error('Choose a company profile.');
    if (!profiles.includes(profile)) throw new Error('Choose a company profile from Settings.');
    currentProfile = { username: profile };
    window.localStorage.setItem(profileStorageKey, JSON.stringify(currentProfile));
    try {
      const url = new URL(window.location.href);
      url.searchParams.set('profile', profile);
      window.history.replaceState(null, '', url);
    } catch (_error) {
      // Local storage still carries the selected profile.
    }
    return currentProfile;
  };

  const loadProfiles = async () => {
    try {
      const response = await fetch(`${scanBridgeEndpoint}?profiles=1`, {
        cache: 'no-store',
        credentials: 'same-origin',
        headers: { Accept: 'application/json' }
      });
      const payload = await response.json();
      profiles = (Array.isArray(payload.profiles) ? payload.profiles : [])
        .map((profile) => normalizeProfile(profile.username || profile))
        .filter(Boolean)
        .sort();
    } catch (_error) {
      profiles = [];
    }
  };

  const updateProfileBadge = () => {
    if (profileBadge) profileBadge.textContent = currentProfile?.username || 'No profile';
  };

  const updateStandbyControls = () => {
    if (!standbyControls) return;
    standbyControls.hidden = Boolean(activeSession.active) || !currentProfile;
    updateProfileBadge();
  };

  const renderProfileGate = (mode = 'login') => {
    if (mode === 'login' && currentProfile) return;
    if (profileGate) profileGate.remove();
    const isSettings = mode === 'settings';
    const gate = document.createElement('div');
    profileGate = gate;
    gate.className = 'admin-store-login-shell';
    gate.innerHTML = `
      <form class="admin-store-login-card" data-phone-profile-form>
        <span class="admin-panel-kicker">${isSettings ? 'Scanner Settings' : 'Phone Scanner Login'}</span>
        <strong>${isSettings ? 'Change company profile' : 'Choose company profile'}</strong>
        <select class="admin-profile-input" name="profile" required>
          <option value="">Select company profile</option>
          ${profiles.map((profile) => `<option value="${escapeHtml(profile)}" ${profile === currentProfile?.username ? 'selected' : ''}>${escapeHtml(profile)}</option>`).join('')}
        </select>
        <p class="admin-form-error" data-profile-error hidden></p>
        <div class="admin-phone-actions">
          <button type="submit" class="admin-primary-btn">${isSettings ? 'Save' : 'Continue'}</button>
          ${isSettings ? '<button type="button" class="admin-ghost-btn" data-phone-profile-cancel>Cancel</button>' : ''}
        </div>
      </form>
    `;
    document.body.appendChild(gate);
    const form = gate.querySelector('[data-phone-profile-form]');
    const select = form?.querySelector('select[name="profile"]');
    const error = gate.querySelector('[data-profile-error]');
    if (error && !profiles.length) {
      error.textContent = 'Add company profiles in Store Settings first.';
      error.hidden = false;
    }
    select?.focus();
    form?.addEventListener('submit', async (event) => {
      event.preventDefault();
      try {
        setCurrentProfile(select?.value || '');
        gate.remove();
        profileGate = null;
        updateProfileBadge();
        updateStandbyControls();
        updateStandby();
        pollSession();
      } catch (saveError) {
        if (error) {
          error.textContent = saveError instanceof Error ? saveError.message : 'Unable to choose profile.';
          error.hidden = false;
        }
      }
    });
    gate.querySelector('[data-phone-profile-cancel]')?.addEventListener('click', () => {
      gate.remove();
      profileGate = null;
    });
  };

  const normalizeBarcode = (value) => {
    let barcode = String(value || '').trim().toUpperCase();
    if (/^\d+$/.test(barcode)) barcode = barcode.slice(0, -1);
    if (/^\d{11}$/.test(barcode)) return `0${barcode}`;
    return barcode;
  };

  const lookupProductName = async (sku) => {
    try {
      const response = await fetch(`../../api/scan-bridge/?sku=${encodeURIComponent(sku)}&t=${Date.now()}`, {
        cache: 'no-store',
        credentials: 'same-origin',
        headers: { Accept: 'application/json' }
      });
      if (!response.ok) return sku;
      const payload = await response.json();
      return String(payload.display_name || payload.product_name || sku);
    } catch (_error) {
      return sku;
    }
  };

  const vibrate = (pattern, warn = false) => {
    if (!('vibrate' in navigator)) {
      if (warn && !hapticWarningShown) {
        hapticWarningShown = true;
        setError('This browser is not exposing Android haptics to the page.');
      }
      return false;
    }

    const accepted = navigator.vibrate(pattern);
    if (!accepted && warn && !hapticWarningShown) {
      hapticWarningShown = true;
      setError('Android blocked vibration for this browser page.');
    }
    return accepted;
  };

  const vibrateScan = () => {
    vibrate([320, 90, 320, 90, 420]);
  };

  const startAudioFeedback = () => {
    const AudioContext = window.AudioContext || window.webkitAudioContext;
    if (!AudioContext) return null;
    if (!audioContext) audioContext = new AudioContext();
    if (audioContext.state === 'suspended') audioContext.resume().catch(() => {});
    return audioContext;
  };

  const playScanBeep = () => {
    const context = startAudioFeedback();
    if (!context) return;

    const now = context.currentTime;
    const gain = context.createGain();
    gain.gain.setValueAtTime(0.0001, now);
    gain.gain.exponentialRampToValueAtTime(0.36, now + 0.012);
    gain.gain.exponentialRampToValueAtTime(0.0001, now + 0.18);
    gain.connect(context.destination);

    [1040, 1560].forEach((frequency, index) => {
      const oscillator = context.createOscillator();
      oscillator.type = 'square';
      oscillator.frequency.setValueAtTime(frequency, now + index * 0.045);
      oscillator.connect(gain);
      oscillator.start(now + index * 0.045);
      oscillator.stop(now + 0.2);
    });
  };

  const openFullscreenScanner = () => {
    const target = root instanceof HTMLElement ? root : document.documentElement;
    if (!document.fullscreenElement && target.requestFullscreen) {
      target.requestFullscreen({ navigationUI: 'hide' }).catch(() => {});
    }

    if (screen.orientation?.lock) {
      screen.orientation.lock('landscape').catch(() => {});
    }
  };

  const keepScannerAwake = () => {
    if (!navigator.wakeLock?.request || wakeLock) return;
    navigator.wakeLock.request('screen')
      .then((lock) => {
        wakeLock = lock;
        wakeLock.addEventListener('release', () => {
          wakeLock = null;
        });
      })
      .catch(() => {});
  };

  const startCamera = async () => {
    if (cameraReady || cameraStarting || cameraFailed) return;
    cameraStarting = true;
    setError('');
    startAudioFeedback();
    openFullscreenScanner();
    keepScannerAwake();

    if (!('BarcodeDetector' in window)) {
      cameraFailed = true;
      setError('This phone browser does not support camera barcode detection.');
      cameraStarting = false;
      updateStandby();
      return;
    }

    try {
      detector = new window.BarcodeDetector({ formats: ['code_128', 'ean_13', 'ean_8', 'qr_code'] });
      stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' }, audio: false });
      if (video instanceof HTMLVideoElement) {
        video.srcObject = stream;
        await video.play();
      }
      cameraReady = true;
      vibrate([90, 40, 90], true);
    } catch (_error) {
      cameraFailed = true;
      setError('Camera permission failed. Allow camera access and reload the scanner.');
    } finally {
      cameraStarting = false;
      activateScanningIfReady();
    }
  };

  const stopCamera = () => {
    scanning = false;
    cameraReady = false;
    detector = null;
    if (stream) {
      stream.getTracks().forEach((track) => track.stop());
      stream = null;
    }
    if (video instanceof HTMLVideoElement) {
      video.pause();
      video.srcObject = null;
    }
  };

  const ensureStandbyShell = () => {
    if (standbyShell) return standbyShell;
    standbyShell = document.createElement('div');
    standbyShell.className = 'admin-phone-standby-shell';
    standbyShell.innerHTML = `
      <div class="admin-phone-standby-card">
        <span class="admin-panel-kicker">Standby</span>
        <strong data-standby-title>Waiting for scan step</strong>
        <small data-standby-detail>Login with the same profile that started the order.</small>
      </div>
    `;
    root.appendChild(standbyShell);
    return standbyShell;
  };

  const setStandbyText = (title, detail) => {
    const shell = ensureStandbyShell();
    const titleNode = shell.querySelector('[data-standby-title]');
    const detailNode = shell.querySelector('[data-standby-detail]');
    if (titleNode) titleNode.textContent = title;
    if (detailNode) detailNode.textContent = detail;
  };

  const isConfirmingScan = () => Boolean(pendingScan) || Boolean(confirmShell && !confirmShell.hidden);

  const updateStandby = () => {
    const shell = ensureStandbyShell();
    const profile = currentProfile?.username || 'no profile';
    updateStandbyControls();
    if (activeSession.active) {
      shell.hidden = cameraReady;
      const waitingText = cameraFailed ? 'Camera unavailable. Allow camera access and reload.' : 'Camera will turn on automatically.';
      setStandbyText(`Order ${activeSession.order_id}`, cameraReady ? 'Scanner active.' : waitingText);
      if (statusNode) statusNode.textContent = cameraReady ? `Scanning as ${profile}` : `Order ready for ${profile}`;
      return;
    }

    scanning = false;
    stopCamera();
    closeConfirmation();
    shell.hidden = false;
    setStandbyText('Waiting for scan step', `${profile} has no active order in /scan/.`);
    if (statusNode) statusNode.textContent = `Standby: ${profile}`;
    updateStandbyControls();
  };

  const activateScanningIfReady = () => {
    updateStandby();
    if (!activeSession.active) return;
    if (isConfirmingScan()) return;
    if (!cameraReady) {
      startCamera().catch(() => {});
      return;
    }
    if (scanning || !detector) return;
    scanning = true;
    detectLoop();
  };

  const pollSession = async () => {
    if (!currentProfile?.username) return;
    try {
      const response = await fetch(`${scanBridgeEndpoint}?status_profile=${encodeURIComponent(currentProfile.username)}&t=${Date.now()}`, {
        cache: 'no-store',
        credentials: 'same-origin',
        headers: { Accept: 'application/json' }
      });
      const payload = await response.json();
      activeSession = {
        active: Boolean(payload.active),
        order_id: String(payload.order_id || '')
      };
      activateScanningIfReady();
    } catch (_error) {
      activeSession = { active: false, order_id: '' };
      updateStandby();
    }
  };

  const resumeScanning = (delay = 700) => {
    if (!detector || !activeSession.active || isConfirmingScan()) return;
    window.setTimeout(() => {
      if (isConfirmingScan()) return;
      scanning = true;
      detectLoop();
    }, delay);
  };

  const showConfirmation = async (rawBarcode) => {
    if (!activeSession.active) {
      scanning = false;
      updateStandby();
      return;
    }
    const normalizedBarcode = normalizeBarcode(rawBarcode);
    pendingScan = { barcode: normalizedBarcode, productName: '' };
    const productName = await lookupProductName(normalizedBarcode);
    pendingScan = { barcode: normalizedBarcode, productName };
    if (confirmProduct) confirmProduct.textContent = productName;
    if (confirmSku) confirmSku.textContent = normalizedBarcode;
    if (statusNode) statusNode.textContent = 'Confirm scan';
    setError('');
    vibrateScan();
    playScanBeep();
    if (confirmShell) confirmShell.hidden = false;
  };

  const closeConfirmation = () => {
    if (confirmShell) confirmShell.hidden = true;
    pendingScan = null;
  };

  const sendScan = async (barcode) => {
    if (!currentProfile?.username || !activeSession.active || !activeSession.order_id) {
      throw new Error('No active order for this profile.');
    }
    setError('');
    const response = await fetch(scanBridgeEndpoint, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({
        barcode,
        profile: currentProfile.username,
        order_id: activeSession.order_id
      })
    });
    if (!response.ok) {
      throw new Error('Unable to send scan.');
    }
    if (statusNode) statusNode.textContent = `Sent ${barcode}`;
    window.setTimeout(() => {
      if (statusNode) statusNode.textContent = 'Ready';
    }, 900);
  };

  const detectLoop = async () => {
    if (!scanning || isConfirmingScan() || !detector || !(video instanceof HTMLVideoElement)) return;
    try {
      const codes = await detector.detect(video);
      const value = String(codes?.[0]?.rawValue || '').trim();
      if (value) {
        scanning = false;
        await showConfirmation(value);
        return;
      }
    } catch (_error) {
      // Continue camera preview; fallback demo button remains available.
    }
    window.requestAnimationFrame(detectLoop);
  };

  confirmSend?.addEventListener('click', async () => {
    if (!pendingScan) return;
    const { barcode } = pendingScan;
    try {
      vibrate([140, 50, 180], true);
      await sendScan(barcode);
      closeConfirmation();
      resumeScanning();
    } catch (_error) {
      setError('Unable to send scan.');
    }
  });

  confirmCancel?.addEventListener('click', () => {
    vibrate(40);
    closeConfirmation();
    if (statusNode) statusNode.textContent = 'Ready';
    resumeScanning(300);
  });

  settingsButton?.addEventListener('click', () => {
    if (activeSession.active) return;
    renderProfileGate('settings');
  });

  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') keepScannerAwake();
  });

  const initialize = async () => {
    await loadProfiles();
    currentProfile = readProfile();
    if (currentProfile && !profiles.includes(currentProfile.username)) {
      currentProfile = null;
    }
    renderProfileGate();
    updateStandby();
    pollSession();
    window.setInterval(pollSession, 2000);
  };

  initialize();
});

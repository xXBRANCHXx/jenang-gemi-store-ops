document.addEventListener('DOMContentLoaded', () => {
  const root = document.querySelector('[data-phone-scanner]');
  if (!root) return;

  const video = document.querySelector('[data-camera-video]');
  const startButton = document.querySelector('[data-start-camera]');
  const demoButton = document.querySelector('[data-demo-scan]');
  const statusNode = document.querySelector('[data-phone-status]');
  const errorNode = document.querySelector('[data-phone-error]');
  const confirmShell = document.querySelector('[data-phone-confirm]');
  const confirmProduct = document.querySelector('[data-confirm-product]');
  const confirmSku = document.querySelector('[data-confirm-sku]');
  const confirmSend = document.querySelector('[data-confirm-send]');
  const confirmCancel = document.querySelector('[data-confirm-cancel]');
  const demoCodes = ['010100150203', '020200250101', '010100150103', '010100150303'];
  let demoIndex = 0;
  let detector = null;
  let stream = null;
  let scanning = false;
  let pendingScan = null;

  if (statusNode) statusNode.textContent = 'Ready';

  const setError = (message) => {
    if (!errorNode) return;
    errorNode.textContent = message;
    errorNode.hidden = message === '';
  };

  const normalizeBarcode = (value) => {
    let barcode = String(value || '').trim().toUpperCase();
    if (/^\d+$/.test(barcode)) barcode = barcode.slice(0, -1);
    if (/^\d{11}$/.test(barcode)) return `0${barcode}`;
    return barcode;
  };

  const lookupProductName = async (sku) => {
    try {
      const response = await fetch(`../../api/scan-bridge/?sku=${encodeURIComponent(sku)}`, {
        credentials: 'same-origin',
        headers: { Accept: 'application/json' }
      });
      const payload = await response.json();
      return String(payload.product_name || sku);
    } catch (_error) {
      return sku;
    }
  };

  const vibrateScan = () => {
    if ('vibrate' in navigator) navigator.vibrate([80, 40, 80]);
  };

  const resumeScanning = (delay = 700) => {
    if (!detector) return;
    window.setTimeout(() => {
      scanning = true;
      detectLoop();
    }, delay);
  };

  const showConfirmation = async (rawBarcode) => {
    const normalizedBarcode = normalizeBarcode(rawBarcode);
    const productName = await lookupProductName(normalizedBarcode);
    pendingScan = { barcode: normalizedBarcode, productName };
    if (confirmProduct) confirmProduct.textContent = productName;
    if (confirmSku) confirmSku.textContent = normalizedBarcode;
    if (statusNode) statusNode.textContent = 'Confirm scan';
    setError('');
    vibrateScan();
    if (confirmShell) confirmShell.hidden = false;
  };

  const closeConfirmation = () => {
    if (confirmShell) confirmShell.hidden = true;
    pendingScan = null;
  };

  const sendScan = async (barcode) => {
    setError('');
    const response = await fetch('../../api/scan-bridge/', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({ barcode })
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
    if (!scanning || !detector || !(video instanceof HTMLVideoElement)) return;
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

  startButton?.addEventListener('click', async () => {
    if (!('BarcodeDetector' in window)) {
      setError('This phone browser does not support camera barcode detection. Use Demo Scan.');
      return;
    }

    try {
      detector = new window.BarcodeDetector({ formats: ['code_128', 'ean_13', 'ean_8', 'qr_code'] });
      stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' }, audio: false });
      if (video instanceof HTMLVideoElement) {
        video.srcObject = stream;
        await video.play();
      }
      scanning = true;
      detectLoop();
      startButton.textContent = 'Camera Active';
      startButton.disabled = true;
    } catch (_error) {
      setError('Camera permission failed. Use Demo Scan for the boss demo.');
    }
  });

  demoButton?.addEventListener('click', () => {
    const barcode = demoCodes[demoIndex % demoCodes.length];
    demoIndex += 1;
    scanning = false;
    showConfirmation(barcode).catch(() => setError('Unable to prepare demo scan.'));
  });

  confirmSend?.addEventListener('click', async () => {
    if (!pendingScan) return;
    const { barcode } = pendingScan;
    try {
      await sendScan(barcode);
      closeConfirmation();
      resumeScanning();
    } catch (_error) {
      setError('Unable to send scan.');
    }
  });

  confirmCancel?.addEventListener('click', () => {
    closeConfirmation();
    if (statusNode) statusNode.textContent = 'Ready';
    resumeScanning(300);
  });
});

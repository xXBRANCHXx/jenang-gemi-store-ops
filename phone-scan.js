document.addEventListener('DOMContentLoaded', () => {
  const root = document.querySelector('[data-phone-scanner]');
  if (!root) return;

  const video = document.querySelector('[data-camera-video]');
  const startButton = document.querySelector('[data-start-camera]');
  const demoButton = document.querySelector('[data-demo-scan]');
  const statusNode = document.querySelector('[data-phone-status]');
  const errorNode = document.querySelector('[data-phone-error]');
  const demoCodes = ['010100150203', '020200250101', '010100150103', '010100150303'];
  let demoIndex = 0;
  let detector = null;
  let stream = null;
  let scanning = false;

  if (statusNode) statusNode.textContent = 'Ready';

  const setError = (message) => {
    if (!errorNode) return;
    errorNode.textContent = message;
    errorNode.hidden = message === '';
  };

  const normalizeBarcode = (value) => {
    let barcode = String(value || '').trim().toUpperCase();
    if (/^\d+$/.test(barcode) && barcode.endsWith('8')) barcode = barcode.slice(0, -1);
    if (/^\d+$/.test(barcode) && !barcode.startsWith('0')) return `0${barcode}`;
    return barcode;
  };

  const sendScan = async (barcode) => {
    setError('');
    const normalizedBarcode = normalizeBarcode(barcode);
    await fetch('../../api/scan-bridge/', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({ barcode: normalizedBarcode })
    });
    if (statusNode) statusNode.textContent = `Sent ${normalizedBarcode}`;
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
        await sendScan(value);
        window.setTimeout(() => {
          scanning = true;
          detectLoop();
        }, 1200);
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
    sendScan(barcode).catch(() => setError('Unable to send demo scan.'));
  });
});

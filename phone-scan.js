document.addEventListener('DOMContentLoaded', () => {
  const root = document.querySelector('[data-phone-scanner]');
  if (!root) return;

  const video = document.querySelector('[data-camera-video]');
  const startButton = document.querySelector('[data-start-camera]');
  const demoButton = document.querySelector('[data-demo-scan]');
  const sessionNode = document.querySelector('[data-phone-session]');
  const errorNode = document.querySelector('[data-phone-error]');
  const params = new URLSearchParams(window.location.search);
  const session = params.get('session') || '';
  const demoCodes = ['JG010100150203', 'JG020200250101', 'JG010100150103', 'JG010100150303'];
  let demoIndex = 0;
  let detector = null;
  let stream = null;
  let scanning = false;

  if (sessionNode) sessionNode.textContent = session || 'Missing session';

  const setError = (message) => {
    if (!errorNode) return;
    errorNode.textContent = message;
    errorNode.hidden = message === '';
  };

  const sendScan = async (barcode) => {
    if (!session) {
      setError('Open this page from the scan screen link.');
      return;
    }

    setError('');
    await fetch(`../../api/scan-bridge/?session=${encodeURIComponent(session)}`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({ barcode })
    });
    if (sessionNode) sessionNode.textContent = `Sent ${barcode}`;
    window.setTimeout(() => {
      if (sessionNode) sessionNode.textContent = session;
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

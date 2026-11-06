(() => {
  const form = document.querySelector('[data-invoice-status]');
  if (!form || !window.fetch) return;
  const message = form.querySelector('[data-invoice-status-message]');
  const started = Date.now();
  let attempts = 0;
  let timer;
  let inFlight = false;
  let stopped = false;
  const schedule = () => {
    window.clearTimeout(timer);
    if (!stopped && !document.hidden && !inFlight && attempts < 9 && Date.now() - started < 180000) {
      timer = window.setTimeout(poll, 20000);
    }
  };
  const poll = async () => {
    if (stopped || document.hidden || inFlight || attempts >= 9 || Date.now() - started >= 180000) return;
    attempts += 1;
    inFlight = true;
    try {
      const response = await fetch(form.action, {
        method: 'POST',
        body: new FormData(form),
        credentials: 'same-origin',
        headers: {Accept: 'application/json', 'X-Storefront-Auto-Poll': '1'},
        referrerPolicy: 'no-referrer',
      });
      if (response.ok) {
        const data = await response.json();
        if (data.payment_status === 'paid' || (typeof data.order_status === 'string' && data.order_status !== 'pending_payment')) {
          stopped = true;
          window.location.reload();
          return;
        }
      } else if (message) {
        message.textContent = 'Status belum dapat diperiksa. Gunakan tombol periksa status atau hubungi bantuan.';
      }
    } catch (_) {
      if (message) message.textContent = 'Status belum dapat diperiksa. Gunakan tombol periksa status atau hubungi bantuan.';
    } finally {
      inFlight = false;
    }
    schedule();
  };
  document.addEventListener('visibilitychange', schedule);
  window.addEventListener('pagehide', () => {
    stopped = true;
    window.clearTimeout(timer);
  }, {once: true});
  schedule();
})();

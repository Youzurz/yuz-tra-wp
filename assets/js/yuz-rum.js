// Explicit administrator-only diagnostics. No messages, URLs or customer content.
(function () {
  const config = window.yuztraRum;
  if (!config || config.enabled !== true || typeof config.endpoint !== 'string' ||
      typeof config.nonce !== 'string' || !config.nonce) return;
  let endpoint;
  try {
    endpoint = new URL(config.endpoint, location.href);
    if (endpoint.origin !== location.origin || !/^https?:$/.test(endpoint.protocol)) return;
  } catch (_) { return; }
  let sent = 0;
  function ship(kind) {
    if (sent >= 10 || typeof fetch !== 'function') return;
    sent++;
    fetch(endpoint.href, {
      method: 'POST', credentials: 'same-origin', keepalive: true,
      headers: { 'content-type': 'application/json', 'X-WP-Nonce': config.nonce },
      body: JSON.stringify({ t: kind })
    }).catch(() => {});
  }
  window.addEventListener('error', () => ship('error'));
  window.addEventListener('unhandledrejection', () => ship('promise'));
  window.addEventListener('securitypolicyviolation', () => ship('csp'), { passive: true });
  document.addEventListener('yuz:switch:ajaxError', () => ship('switcher'));
})();

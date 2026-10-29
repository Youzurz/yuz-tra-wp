// assets/js/yuz-debug.js
(function (w) {
  // Activation via localStorage, query string, ou payload localisé
  const qs = new URL(w.location.href).searchParams;
  const cfg = (w.yuztraDebug || {});
  const enabled = cfg.enabled ?? (qs.has('yuztra_debug') ? qs.get('yuztra_debug') !== '0' : null);
  const ls = (() => { try { return localStorage; } catch (_) { return {}; }})();
  const on = (enabled !== null) ? enabled : (ls.yuztra_debug ? ls.yuztra_debug !== '0' : false);
  if (on) ls.yuztra_debug = '1';

  const prefix = '[YUZ]';
  const mk = (fn) => (...a) => {
    if (!on) return;
    (console[fn] || console.log).call(console, prefix, ...a);
  };

  w.YUZ = w.YUZ || {};
  w.YUZ.log  = mk('log');
  w.YUZ.info = mk('info');
  w.YUZ.warn = mk('warn');
  w.YUZ.err  = mk('error');

  // ACK global visible
  w.YUZ.info('debug online', {
    url: w.location.href,
    ts: new Date().toISOString()
  });
})(window);


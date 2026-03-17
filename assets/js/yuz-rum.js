var console = window.__YUZ_RELEASE_CONSOLE__ || {log:function(){},debug:function(){},info:function(){},warn:function(){},error:function(){}}; var yuz_release_console = console;


// assets/js/yuz-rum.js
(function () {
  const endpoint = '/wp-json/yuz/v1/jslog';

  function ship(payload) {
    try {
      const body = JSON.stringify(payload);
      if (navigator.sendBeacon) {
        const blob = new Blob([body], { type: 'application/json' });
        if (navigator.sendBeacon(endpoint, blob)) {
          return;
        }
      }
      if (typeof fetch === 'function') {
        fetch(endpoint, {
          method: 'POST',
          headers: { 'content-type': 'application/json' },
          body,
          keepalive: true,
        }).catch(() => {});
      }
    } catch (err) {
      if (console && yuz_release_console.debug) {
        yuz_release_console.debug('[YUZ][RUM] ship failed', err);
      }
    }
  }

  const now = () => new Date().toISOString();

  function pushRum(evt, shipPayload) {
    try {
      const entry = {
        ts: now(),
        kind: evt.kind,
        msg: evt.msg || evt.message || '',
        src: evt.src || evt.filename || '',
        ln: evt.ln || evt.lineno || 0,
        col: evt.col || evt.colno || 0,
        href: location.href,
        extra: evt.extra || {}
      };
      if (typeof window.YUZ_SAFE_REPORT === 'function') {
        window.YUZ_SAFE_REPORT().events.push(entry);
      } else {
        const fallback = window.YUZ_DETECTOR_REPORT = window.YUZ_DETECTOR_REPORT || { ts: now(), checks: {}, events: [], scripts: [] };
        fallback.events = Array.isArray(fallback.events) ? fallback.events : [];
        fallback.scripts = Array.isArray(fallback.scripts) ? fallback.scripts : [];
        fallback.events.push(entry);
      }
      if (console && yuz_release_console.debug) yuz_release_console.debug('[YUZ][RUM]', entry);
      if (shipPayload) {
        ship(shipPayload);
      }
    } catch (_) {}
  }

  window.addEventListener('error', function (e) {
    pushRum(
      { kind: 'error', message: e.message, filename: e.filename, lineno: e.lineno, colno: e.colno },
      { t: 'error', msg: String(e.message || ''), src: e.filename || '', ln: e.lineno || 0, col: e.colno || 0 }
    );
  });

  window.addEventListener('unhandledrejection', function (e) {
    const reason = String(e.reason || '');
    pushRum(
      { kind: 'unhandledrejection', msg: reason },
      { t: 'promise', msg: reason }
    );
  });

  window.addEventListener('securitypolicyviolation', function (e) {
    const message = e.violatedDirective + ' blocked ' + (e.blockedURI || '');
    pushRum(
      {
        kind: 'securitypolicyviolation',
        msg: message,
        src: e.sourceFile || '',
        ln: e.lineNumber || 0,
        col: e.columnNumber || 0,
        extra: { directive: e.violatedDirective || '', blocked: e.blockedURI || '' }
      },
      {
        t: 'csp',
        msg: message,
        src: e.sourceFile || '',
        ln: e.lineNumber || 0,
        col: e.columnNumber || 0,
        directive: e.violatedDirective || ''
      }
    );
  }, { passive: true });

  document.addEventListener('yuz:switch:ajaxError', function (e) {
    const detail = e.detail || {};
    pushRum(
      { kind: 'switcher', msg: String(detail.message || ''), extra: detail },
      { t: 'switcher', stage: 'ajaxError', detail }
    );
  });
})();

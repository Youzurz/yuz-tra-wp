var console = window.__YUZ_RELEASE_CONSOLE__ || {log:function(){},debug:function(){},info:function(){},warn:function(){},error:function(){}}; var yuz_release_console = console;


/* UMD minimal — mounts a compact translation bar into #yuz-editor-container */
(function (window, document) {
  'use strict';

  if (!window || !document) return;

  var CFG = window.yuzTraSettings || {};
  var hasParam = /[?&]yuz-edit-translation=1\b/.test(window.location.search);
  var searchParams;
  try { searchParams = new URLSearchParams(window.location.search); } catch (e) { searchParams = null; }

  // TEMP TRACE — boot diagnostics (remove after validation)
  try {
    yuz_release_console.log('[YUZ UMD][boot]', {
      param: hasParam,
      container: !!document.getElementById('yuz-editor-container'),
      haveSettings: !!window.yuzTraSettings,
      haveTE: !!window.yuzTE
    });
  } catch (_) {}

  function ensureContainer() {
    var el = document.getElementById('yuz-editor-container');
    if (!el) {
      el = document.createElement('div');
      el.id = 'yuz-editor-container';
      document.body.appendChild(el);
    }
    return el;
  }

  function nonceFor(action) {
    try { return (CFG.nonces && (CFG.nonces[action] || CFG.nonces.yuz_int_nonce || CFG.nonces.yuz_tra_nonce)) || ''; } catch (_) {}
    return '';
  }
  function el(tag, attrs, html) {
    var e = document.createElement(tag);
    if (attrs) for (var k in attrs) if (Object.prototype.hasOwnProperty.call(attrs, k)) e.setAttribute(k, attrs[k]);
    if (html) e.innerHTML = html;
    return e;
  }
  function toPairs(obj) {
    var out = [];
    if (!obj) return out;
    for (var k in obj) {
      if (Object.prototype.hasOwnProperty.call(obj, k)) out.push([k, obj[k]]);
    }
    return out;
  }

  function buildToolbar(progressOnly) {
    try { yuz_release_console.log('[YUZ UMD] buildToolbar:start', progressOnly ? '(progress-only)' : ''); } catch (_) {}
    var names = CFG.language_names || {};
    var langs = CFG.translation_langs || [];
    var def = CFG.default_language || '';
    var src = CFG.source_language || def;
    var cont = ensureContainer();
    if (!cont) {
      try { yuz_release_console.warn('[YUZ UMD] no #yuz-editor-container; abort'); } catch (_) {}
      return null;
    }
    cont.style.display = 'block';
    cont.setAttribute('aria-hidden', 'false');
    cont.innerHTML = '';

    var wrap = el('div', {
      class: 'yuz-mini-toolbar',
      style: 'position:fixed;left:16px;bottom:16px;right:16px;z-index:99999;background:#fff;border-radius:10px;box-shadow:0 10px 30px rgba(0,0,0,.18);padding:10px 12px;font:14px system-ui,-apple-system,Segoe UI,Roboto;color:#222;'
    });
    var row1 = el('div', { style: 'display:flex;gap:8px;align-items:center;flex-wrap:wrap;' });
    var selFrom = el('select', { id: 'yuz_from', style: 'min-width:160px' });
    var selTo = el('select', { id: 'yuz_to', style: 'min-width:200px' });
    var input = el('input', { type: 'text', id: 'yuz_text', placeholder: 'Enter text to translate…', style: 'flex:1;padding:6px 8px;border:1px solid #ddd;border-radius:6px' });
    var btnSave = el('button', { id: 'yuz_save', class: 'button' }, 'Save');
    var btnPub = el('button', { id: 'yuz_publish', class: 'button primary' }, 'Publish');

    function opt(v, t) { var o = el('option'); o.value = v; o.textContent = t; return o; }
    selFrom.appendChild(opt('', 'From…'));
    selFrom.appendChild(opt(src, (names[src] || src) + ' (source)'));
    selTo.appendChild(opt('', 'To…'));
    langs.forEach(function (l) { selTo.appendChild(opt(l, names[l] || l)); });

    row1.appendChild(selFrom);
    row1.appendChild(selTo);
    row1.appendChild(input);
    if (!progressOnly) {
      row1.appendChild(btnSave);
      row1.appendChild(btnPub);
    }
    wrap.appendChild(row1);
    cont.appendChild(wrap);

    function ajax(action, payload) {
      var xhr = new XMLHttpRequest();
      var p = new URLSearchParams();
      p.set('action', action);
      p.set('nonce', nonceFor(action));
      Object.keys(payload || {}).forEach(function (k) { p.set(k, payload[k]); });
      xhr.open('POST', CFG.ajax_url || '/wp-admin/admin-ajax.php');
      xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
      xhr.onload = function () {
        if (xhr.status >= 200 && xhr.status < 300) {
          try { yuz_release_console.log('[YUZ][OK]', action, JSON.parse(xhr.responseText)); }
          catch (_) { yuz_release_console.log('[YUZ][OK]', action, xhr.responseText); }
        } else {
          yuz_release_console.error('[YUZ][ERR]', action, xhr.status, xhr.responseText);
        }
      };
      xhr.send(p.toString());
    }

    if (!progressOnly) {
      btnSave.addEventListener('click', function (e) {
        e.preventDefault();
        var to = selTo.value || '';
        var txt = input.value || '';
        if (!to || !txt) { alert('Select target and enter text.'); return; }
        ajax('yuz_tra_te_upd_manual', { target_lang: to, text: txt, page_url: window.location.href });
      });
      btnPub.addEventListener('click', function (e) {
        e.preventDefault();
        var to = selTo.value || '';
        var txt = input.value || '';
        if (!to || !txt) { alert('Select target and enter text.'); return; }
        ajax('yuz_tra_te_upd_publish', { target_lang: to, text: txt, page_url: window.location.href });
      });
    }

    try { yuz_release_console.log('[YUZ UMD] mounted OK'); } catch (_) {}
    return wrap;
  }

  function mountMini(opts) {
    var cfg = opts || {};
    if (!hasParam) {
      try { yuz_release_console.log('[YUZ UMD] skip: ?yuz-edit-translation=1 missing'); } catch (_) {}
      return;
    }
    var root = buildToolbar(!!cfg.progressOnly);
    if (!root) return;

    if (window.__YUZ_UI_ACTIVE__) {
      window.__YUZ_UI_ACTIVE__.unmount = function () {
        try { root.remove(); } catch (_) {}
        var cont = document.getElementById('yuz-editor-container');
        if (cont) {
          cont.innerHTML = '';
          cont.style.display = 'none';
          cont.setAttribute('aria-hidden', 'true');
        }
      };
    }

    try { yuz_release_console.log('[YUZ-MINI] mounted', cfg.progressOnly ? '(progress-only)' : ''); } catch (_) {}
  }

  function overlayHandlesXpress() {
    return !!window.__YUZ_OVERLAY_HANDLES_XPRESS__;
  }

  function onMount(ev) {
    var detail = (ev && ev.detail) || {};
    var mode = detail.mode || detail.legacyMode;
    if (overlayHandlesXpress()) {
      if (mode === 'xpress' || mode === 'mini' || mode === 'xpress-progress' || mode === 'mini-progress') {
        return;
      }
    }
    if (mode === 'xpress' || mode === 'mini') { mountMini({ progressOnly: false }); }
    if (mode === 'xpress-progress' || mode === 'mini-progress') { mountMini({ progressOnly: true }); }
  }

  if (window.YUZ_UI) {
    document.addEventListener('yuz:ui:mount', onMount);
  } else if (searchParams && searchParams.get('yuz-mini') === '1' && !overlayHandlesXpress()) {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', function () { mountMini({ progressOnly: false }); }, { once: true });
    } else {
      mountMini({ progressOnly: false });
    }
  }
})();


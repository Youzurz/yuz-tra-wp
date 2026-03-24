var yuz_release_console={log(){},debug(){},info(){},warn(){},error(){},groupCollapsed(){},groupEnd(){},table(){}};


// Mute YUZ console noise by default; set yuzTraSettings.silent_logs = false to re-enable.
(function () {
  const settings = window.yuzTraSettings || {};
  const allowDebug = settings.silent_logs === false || settings.debug_logs === true;
  if (allowDebug) return;
  const mutePrefixes = [/^\[YUZ/i, /^\[yuz/i, /^\[toast]/i, /^\[INSTANT]/i, /^\[SMART]/i];
  const shouldMute = (first) => typeof first === 'string' && mutePrefixes.some((rx) => rx.test(first));
  ['warn', 'info', 'log', 'debug'].forEach((level) => {
    const orig = yuz_release_console[level];
    if (!orig) return;
    yuz_release_console[level] = function (...args) {
      if (args.length && shouldMute(args[0])) return;
      try { return orig.apply(console, args); } catch (_) { /* silent */ }
    };
  });
})();

// --- YUZ fallback: languages from localized settings (safe, non-invasif) ---
(function () {
  const DBG = /\byuzdebug=1\b/.test(window.location.search || '');
  const log = (level, msg, ctx) => {
    if (!DBG) return;
    const fn = yuz_release_console[level] || yuz_release_console.log;
    try { fn.call(console, '[YUZ][TE][DBG]', msg, ctx || {}); } catch (_) { }
  };
  log('info', '✏️ yuz-translation-editor.js chargé', { ts: new Date().toISOString() });

  // Expose for later blocks in this file
  window.__YUZ_TE_DBG__ = { DBG, log };
})();
(function () {
  var settings = window.yuzTraSettings && window.yuzTraSettings.general ? window.yuzTraSettings.general : null;
  if (!settings) return;
  var fromSettings = Array.isArray(settings.yuz_tra_translatable_languages) ? settings.yuz_tra_translatable_languages.slice() : [];
  if (!fromSettings.length) return;
  try {
    if (window.YUZ && window.YUZ.state && (!Array.isArray(window.YUZ.state.langs) || !window.YUZ.state.langs.length)) {
      window.YUZ.state.langs = fromSettings.slice();
    }
    if (window.yuz && window.yuz.state && (!Array.isArray(window.yuz.state.langs) || !window.yuz.state.langs.length)) {
      window.yuz.state.langs = fromSettings.slice();
    }
    window.__yuz_boot_langs__ = fromSettings.slice();
  } catch (_) { }
})();

// Bootstrap minimal yuzTraSettings even if localization missed.
(function () {
  const Y = (window.yuzTraSettings = window.yuzTraSettings || {});
  const dbg = (window.__YUZ_TE_DBG__ && window.__YUZ_TE_DBG__.log) || function () { };
  const hasContainer = !!document.getElementById('yuz-editor-container');
  dbg('info', 'bootstrap yzTraSettings fallback', {
    hasContainer,
    ajax: !!Y.ajax_url,
    nonces: Y.nonces ? Object.keys(Y.nonces) : [],
    yuzTE_nonces: window.yuzTE ? Object.keys(window.yuzTE.nonces || {}) : [],
  });
  // CSP/inline-free fallback: if the inline yuzTraSettings localization was stripped,
  // reuse the yzTE payload (nonces + ajax_url) so AJAX fallbacks can work.
  if ((!Y.nonces || !Object.keys(Y.nonces).length) && window.yuzTE && window.yuzTE.nonces) {
    Y.nonces = Object.assign({}, window.yuzTE.nonces);
    Y.ajax_url = Y.ajax_url || window.yuzTE.ajax_url;
    dbg('warn', 'patched yuzTraSettings from yzTE (inline blocked?)', {
      ajax: Y.ajax_url,
      nonces: Object.keys(Y.nonces || {}),
    });
  }
  if (!Y.general || !Array.isArray(Y.general.yuz_tra_translatable_languages) || !Y.general.yuz_tra_translatable_languages.length) {
    const N = (Y.nonces && (Y.nonces.yuz_tra_ws_get_languages || Y.nonces.yuz_tra_nonce)) || '';
    const params = new URLSearchParams({ action: 'yuz_tra_ws_get_languages', yuz_tra_nonce: N, nonce: N, security: N });
    const ajax = Y.ajax_url || '/wp-admin/admin-ajax.php';
    fetch(ajax, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
      body: params
    })
      .then(r => r.json())
      .then(j => {
        if (j && j.success) {
          // Compat: l'endpoint renvoie { langs:[{code,...}], targets:[...] }
          const raw = Array.isArray(j.data?.languages) ? j.data.languages
            : Array.isArray(j.data?.langs) ? j.data.langs
              : [];
          const codes = raw.map(item => (item?.language_code || item?.code || item)).filter(Boolean);
          window.yuzTraSettings.general = window.yuzTraSettings.general || {};
          window.yuzTraSettings.general.yuz_tra_translatable_languages = codes;
          dbg('info', '[yuz] fallback langs from ajax', codes);
        } else {
          dbg('warn', '[yuz] fallback ajax failed', j);
        }
      })
      .catch(err => dbg('warn', '[yuz] fallback ajax error', err));
  }
})();

(function () {
  // Évite la dépendance stricte à l'inline YUZ_PARAMS (bloqué si CSP refuse l'inline).
  // Reconstruit un YUZ_PARAMS minimal à partir du payload localisé dans yuzTraSettings.
  try {
    const perms = window.yuzTraSettings && window.yuzTraSettings.permissions;
    const alias = (window.yuzTraSettings && window.yuzTraSettings.current_user) || null;
    const canEdit = perms ? !!(perms.canTranslate || perms.canManage || perms.canPublish) : null;
    window.YUZ_PARAMS = window.YUZ_PARAMS || {};
    if (typeof window.YUZ_PARAMS.canEdit === 'undefined' && canEdit !== null) {
      window.YUZ_PARAMS.canEdit = canEdit;
    }
    if (!window.YUZ_PARAMS.alias && alias) {
      window.YUZ_PARAMS.alias = alias;
    }
  } catch (_) { /* silencieux */ }
})();

(function () {
  try {
    const params = new URLSearchParams(window.location.search);
    const ss = window.sessionStorage;
    if (params.get('yuz_umd') === '1') {
      window.YUZ_FALLBACK_UMD = true;
      const url = params.get('yuz_umd_url');
      if (url) {
        window.YUZ_FALLBACK_UMD_URL = url;
        ss && ss.setItem('YUZ_FALLBACK_UMD_URL', url);
      }
      ss && ss.setItem('YUZ_FALLBACK_UMD', '1');
    } else if (params.get('yuz_umd') === '0') {
      window.YUZ_FALLBACK_UMD = false;
      ss && ss.removeItem('YUZ_FALLBACK_UMD');
      ss && ss.removeItem('YUZ_FALLBACK_UMD_URL');
    } else if (ss && ss.getItem('YUZ_FALLBACK_UMD') === '1') {
      window.YUZ_FALLBACK_UMD = true;
      window.YUZ_FALLBACK_UMD_URL = ss.getItem('YUZ_FALLBACK_UMD_URL') || window.YUZ_FALLBACK_UMD_URL;
    }
  } catch (_) { }
})();

// Quick visibility probe for the container on load
(function () {
  const dbg = (window.__YUZ_TE_DBG__ && window.__YUZ_TE_DBG__.log) || function () { };
  document.addEventListener('DOMContentLoaded', function () {
    const c = document.getElementById('yuz-editor-container');
    if (!c) {
      dbg('warn', 'No #yuz-editor-container on DOMContentLoaded');
      return;
    }
    const style = window.getComputedStyle ? window.getComputedStyle(c) : null;
    dbg('info', '#yuz-editor-container detected', {
      display: style ? style.display : undefined,
      visibility: style ? style.visibility : undefined,
      hiddenAttr: c.getAttribute('hidden'),
      classes: c.className,
      children: c.children ? c.children.length : 0,
    });
  });
})();

// Post-mount watchdog: warn if editor app not mounted after 5s
(function () {
  const dbg = (window.__YUZ_TE_DBG__ && window.__YUZ_TE_DBG__.log) || function () { };
  setTimeout(() => {
    const app = window.YUZ_StringTranslationApp;
    const c = document.getElementById('yuz-editor-container');
    if (!app || !c || !c.children || c.children.length === 0) {
      dbg('warn', 'Editor not mounted after 5s', {
        hasApp: !!app,
        hasContainer: !!c,
        containerChildren: c && c.children ? c.children.length : null,
        yzNonces: window.yuzTraSettings && window.yuzTraSettings.nonces ? Object.keys(window.yuzTraSettings.nonces || {}) : [],
        teNonces: window.yuzTE && window.yuzTE.nonces ? window.yuzTE.nonces : {},
      });
    }
  }, 5000);
})();

// Dans l’éditeur (widget) — utilisé par "Add" / "Add all" en preview
function applyOne(item) {
  const el = document.querySelector(item.path);
  if (!el) {
    window.dispatchEvent(new CustomEvent('yuz:sniff:miss', { detail: { path: item.path, sample: item.value } }));
    return false;
  }
  const set = (attr, val) => el.setAttribute(attr, val);

  switch (item.type) {
    case 'attr:placeholder': set('placeholder', item.value); break;
    case 'attr:aria-label': set('aria-label', item.value); break;
    case 'attr:title': set('title', item.value); break;
    case 'attr:label': set('label', item.value); break;
    default:
      el.textContent = item.value;
  }

  window.dispatchEvent(new CustomEvent('yuz:sniff:apply', {
    detail: { path: item.path, type: item.type, translated: item.value, el }
  }));
  return true;
}

document.addEventListener('yuz:inspector:targets:form-legends', (e) => {
  const items = (e.detail && e.detail.items) || [];
  items.forEach(applyOne);
});

// Overlay diagnostic & fallback (ESM + legacy)
(() => {
  const DEBUG = /\b(?:\?|&)yuzdebug=1\b/.test(location.search) || window.YUZ_DEBUG === true;
  const onReady = (fn) =>
    (document.readyState === 'complete' || document.readyState === 'interactive')
      ? fn()
      : document.addEventListener('DOMContentLoaded', fn, { once: true });
  const selModule = "script#yuz-te-module[data-yuz-module='true'][type='module']";
  const selContainer = "#yuz-editor-container, .yuz-translation-editor, [data-yuz-editor-root], #yuz-te-advanced";

  const firstFailure = (checks) => {
    const order = ['hasParam', 'canEdit', 'pipeline', 'tag', 'container'];
    for (const step of order) {
      if (step === 'pipeline') {
        const p = checks.pipeline || {};
        if (!(p.registered && p.enqueued && p.done && p.src)) return step;
      } else if (step === 'tag') {
        const t = checks.tag || {};
        if (!(t.dom_seen && t.type === 'module')) return step;
      } else if (step === 'container') {
        if (!checks.container) return step;
      } else if (!checks[step]) {
        return step;
      }
    }
    return null;
  };

  onReady(() => {
    const ensureReport = () => {
      const rep = window.YUZ_DETECTOR_REPORT || {};
      rep.ts = rep.ts || new Date().toISOString();
      rep.checks = rep.checks && typeof rep.checks === 'object' ? rep.checks : {};
      rep.events = Array.isArray(rep.events) ? rep.events : [];
      rep.scripts = Array.isArray(rep.scripts) ? rep.scripts : [];
      window.YUZ_DETECTOR_REPORT = rep;
      return rep;
    };

    const report = typeof window.YUZ_SAFE_REPORT === 'function' ? window.YUZ_SAFE_REPORT() : ensureReport();
    const tag = document.querySelector(selModule);
    const hasContainer = () => !!document.querySelector(selContainer);
    const containerFound = hasContainer();

    const pipeline = {
      registered: true,
      enqueued: true,
      done: !!tag,
      src: tag ? tag.src : null,
    };

    const tagInfo = tag ? {
      dom_seen: true,
      id: tag.id || null,
      type: tag.type || null,
      hasNonce: !!tag.nonce,
      integrity: tag.getAttribute ? !!tag.getAttribute('integrity') : false,
    } : { dom_seen: false };

    const checks = {
      hasParam: typeof window.YUZ_PARAMS !== 'undefined',
      canEdit: !!(window.YUZ_PARAMS && window.YUZ_PARAMS.canEdit),
      alias: (window.YUZ_PARAMS && window.YUZ_PARAMS.alias) || null,
      boot: 'editor-js',
      pipeline,
      tag: tagInfo,
      container: containerFound,
    };

    report.checks = Object.assign({}, report.checks || {}, checks);
    report.checks.first_failure = firstFailure(report.checks);

    if (DEBUG && console && yuz_release_console.table) {
    }
    if (tag) {
      if (console && yuz_release_console.info) yuz_release_console.info('[YUZ][Overlay] module tag OK');
    } else if (console && yuz_release_console.warn) {
    }

    if (!report.checks.container && typeof MutationObserver === 'function') {
      let settled = false;
      const observer = new MutationObserver(() => {
        if (hasContainer()) {
          report.checks.container = true;
          settled = true;
          observer.disconnect();
          if (DEBUG && console && yuz_release_console.info) {
          }
        }
      });
      observer.observe(document.documentElement, { childList: true, subtree: true });
      setTimeout(() => {
        if (!settled) {
          observer.disconnect();
          if (DEBUG && console && yuz_release_console.warn) {
          }
        }
      }, 10000);
    }

    if (!tag && window.YUZ_FALLBACK_UMD === true && window.YUZ_FALLBACK_UMD_URL) {
      try {
        const s = document.createElement('script');
        s.src = String(window.YUZ_FALLBACK_UMD_URL);
        s.async = true;
        s.defer = true;
        s.dataset.yuzFallback = '1';
        s.setAttribute('data-cfasync', 'false');
        s.setAttribute('data-noptimize', '1');
        s.setAttribute('data-no-optimize', '1');
        s.setAttribute('data-rocketlazyload', 'ignore');
        s.setAttribute('data-rocket-ignore', 'true');
        s.setAttribute('data-litespeed', 'ignore');
        const nonceCarrier = document.querySelector('script[nonce]');
        const nonce = nonceCarrier ? (nonceCarrier.getAttribute('nonce') || nonceCarrier.nonce || '') : '';
        if (nonce) s.setAttribute('nonce', nonce);
        const moduleTag = document.querySelector(selModule);
        const integrity = moduleTag && moduleTag.getAttribute ? moduleTag.getAttribute('integrity') : '';
        const crossorigin = moduleTag && moduleTag.getAttribute ? moduleTag.getAttribute('crossorigin') : '';
        if (integrity) {
          s.setAttribute('integrity', integrity);
          s.setAttribute('crossorigin', crossorigin || 'anonymous');
        }
        document.head.appendChild(s);
        if (console && yuz_release_console.warn) yuz_release_console.warn('[YUZ][Overlay] Legacy UMD loaded as fallback');
      } catch (err) {
        if (console && yuz_release_console.error) yuz_release_console.error('[YUZ][Overlay] Fallback UMD failed', err);
      }
    }
  });
})();

/* =======================================================================================
 * assets/js/widgets/yuz-translation-editor.js — patched (ESM imports, Vue jQuery only)
 * Inline Translation Editor (front/admin overlay)
 *
 * Changement clé:
 *   - Remplacement des globals par imports ESM (optionnels, commentés)
 *     import Vue from 'vue';
 *     import $ from 'jquery';
 *   - Remplacements:
 *     window.Vue  → Vue
 *     window.jQuery / jQuery → $
 *   - Aucune dépendance à vue-router / select2 / he ici
 *   - Ajouts: YUZ_SKIP unifié, sniffer agressif, nettoyage fort, scanner aligné
 * ======================================================================================= */

// --- N19 hotfix: AJAX + nonce helper ----------------------------
const __T__ = window.yuzTraSettings || {};
const __AJAX__ = __T__.ajax_url || '/wp-admin/admin-ajax.php';
const __NONCES__ = (__T__.nonces || {});
const __NONCE_ROUTES__ = {
  yuz_get_pending_translations: ['int', 'yuz_int_nonce', 'yuz_get_pending_translations'],
  yuz_publish_translations: ['con', 'yuz_con_nonce', 'yuz_publish_translations', 'yuz_publish_controller'],
  yuz_mass_publish: ['con', 'yuz_con_nonce', 'yuz_publish_controller'],
  yuz_translate: ['hvy', 'yuz_hvy_nonce', 'yuz_translate', 'yuz_api_nonce'],
  yuz_tra_tm_translate: ['hvy', 'yuz_hvy_nonce', 'yuz_tra_tm_translate', 'yuz_api_nonce'],
  yuz_tra_tm_del_translation: ['del', 'yuz_del_nonce', 'yuz_tra_tm_del_translation', 'yuz_tra_delete_translation'],
  yuz_tra_delete_translation: ['del', 'yuz_del_nonce', 'yuz_tra_delete_translation'],
  yuz_tra_tm_test_api: ['api', 'yuz_api_nonce', 'yuz_tra_tm_test_api'],
  yuz_tra_ws_get_languages: ['tra', 'yuz_tra_nonce', 'yuz_tra_ws_get_languages']
};

// Per-action HTTP timeouts (ms) — translations are slower than UI defaults
const __ACTION_TIMEOUTS__ = {
  yuz_tra_tm_translate: 30000,
  yuz_translate: 30000,
  yuz_tra_ws_get_languages: 15000
};

// Lightweight internal diagnostics (no-op if console missing)
const diagProbe = (label, payload = {}) => {
};

/** Retourne le meilleur nonce pour une action donnée */
function __nonceFor__(action) {
  const routing = __NONCE_ROUTES__[action];
  if (routing) {
    for (const key of routing) {
      if (key && __NONCES__[key]) {
        return __NONCES__[key];
      }
    }
  }
  return __NONCES__[action] || __NONCES__.tra || __NONCES__.yuz_tra_nonce || __NONCES__.yuz_tra_ajax || '';
}
try { window.__nonceFor__ = __nonceFor__; } catch (_) { }

/** POST standardisé vers admin-ajax avec nonce auto */
async function yuzPost(action, payload = {}) {
  const n = __nonceFor__(action);
  const params = new URLSearchParams();

  const appendParam = (key, value) => {
    if (value === undefined || value === null) return;
    if (Array.isArray(value)) {
      const items = value.filter(v => v !== undefined && v !== null);
      if (!items.length) return;
      items.forEach(item => appendParam(`${key}[]`, item));
      return;
    }
    if (typeof value === 'object') {
      try {
        params.append(key, JSON.stringify(value));
      } catch (_) {
        params.append(key, String(value));
      }
      return;
    }
    if (value === true) {
      params.append(key, '1');
      return;
    }
    if (value === false) {
      params.append(key, '0');
      return;
    }
    params.append(key, String(value));
  };

  appendParam('action', action);
  Object.entries(payload || {}).forEach(([key, value]) => {
    appendParam(key, value);
  });

  // On pousse les deux clés possibles (compat check_ajax_referer)
  if (n && !params.has('yuz_tra_nonce') && !params.has('security')) {
    params.set('yuz_tra_nonce', n);
    params.set('security', n);
  }

  const controller = new AbortController();
  const timeoutMs = __ACTION_TIMEOUTS__[action] || 8000;
  const to = setTimeout(() => controller.abort(), timeoutMs);

  try {
    const r = await fetch(__AJAX__, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
      body: params,
      signal: controller.signal
    });

    // L’API peut renvoyer JSON ou texte WP_Ajax_Response
    const txt = await r.text();
    if (!r.ok) {
      const error = new Error(r.statusText || `HTTP ${r.status}`);
      error.status = r.status;
      error.statusText = r.statusText;
      error.responseText = txt;
      throw error;
    }
    try { return JSON.parse(txt); } catch (_) { return txt; }
  } finally {
    clearTimeout(to);
  }
}
// ----------------------------------------------------------------

// import Vue from 'vue';
// import $ from 'jquery';

(function (w) {
  if (!w.axios || !w.axios.interceptors || !w.axios.interceptors.request || typeof w.axios.interceptors.request.use !== 'function') {
    return;
  }

  function toQS(input) {
    if (input instanceof URLSearchParams) return input;
    if (input instanceof FormData) {
      const params = new URLSearchParams();
      for (const [k, v] of input.entries()) { params.append(k, v); }
      return params;
    }
    if (typeof input === 'string') {
      return new URLSearchParams(input);
    }
    const params = new URLSearchParams();
    if (input && typeof input === 'object') {
      Object.keys(input).forEach(key => {
        const value = input[key];
        if (Array.isArray(value)) { value.forEach(item => params.append(key, item)); }
        else if (value !== undefined && value !== null) { params.append(key, value); }
      });
    }
    return params;
  }

  function getNonceFor(action) {
    try {
      if (typeof w.__nonceFor__ === 'function') {
        const maybe = w.__nonceFor__(action);
        if (maybe) return maybe;
      }
      if (w.yuzTraSettings && w.yuzTraSettings.nonces && w.yuzTraSettings.nonces[action]) {
        return w.yuzTraSettings.nonces[action];
      }
      if (w.CLAR && w.CLAR.nonces && w.CLAR.nonces[action]) {
        return w.CLAR.nonces[action];
      }
    } catch (_) { }
    return '';
  }

  w.axios.interceptors.request.use(cfg => {
    try {
      const method = (cfg.method || 'get').toLowerCase();
      const url = String(cfg.url || '');
      if (!url.includes('/admin-ajax.php') || method !== 'post') {
        return cfg;
      }

      const params = toQS(cfg.data);
      const action = params.get('action') || '';
      const nonce = getNonceFor(action);

      if (nonce && !params.has('yuz_tra_nonce') && !params.has('security')) {
        params.set('yuz_tra_nonce', nonce);
        params.set('security', nonce);
      }

      cfg.data = params;
      cfg.headers = Object.assign({}, cfg.headers, {
        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
      });
    } catch (err) {
    }
    return cfg;
  });
})(window);

const Vue = window.Vue;
const $ = window.jQuery;

(function (window, document) {
  'use strict';

  let currentOverlayMode = null;

  // Signal to legacy mini-toolbar script that the overlay now handles xPress mode
  try { window.__YUZ_OVERLAY_HANDLES_XPRESS__ = true; } catch (_) { }

  // -------------------------------
  // Guards & short helpers
  // -------------------------------

  if (!window || !document) return;

  let moduleTag = document.querySelector('#yuz-te-module');
  const isImportedModule = (typeof document.currentScript === 'undefined' || document.currentScript === null);
  if (!moduleTag && !isImportedModule) {
  }

  // Be tolerant: some environments localize late. Retry once on DOMContentLoaded
  let Y = window.yuzTE;
  if (!Y) {
    document.addEventListener('DOMContentLoaded', function () {
      if (!window.yuzTE) return; // if still missing, stay silent
      // re-run minimal bootstrap if defined later (handled by loader/enqueue elsewhere)
    });
    // Continue without noisy warning; other modules may not require the editor
    return;
  }

  if (!Y.ajax_url) {
    return;
  }

  // --- Ignore-list unifiée pour tous les scanners (visible & mapper)
  const YUZ_SKIP =
    'style,script,noscript,template,textarea,code,pre,' +
    '[hidden],[aria-hidden="true"],#wpadminbar,' +
    '#yuz-editor-container,#yuz-translation-editor,' +
    '[data-yuz="no-translate"],' +
    '.cookie-banner,#cookie-banner,.cky-consent-container,' +
    '.wp-block-cover__background,' +
    '[class*="cookie"],[id*="cookie"],.newsletter-section,.newsletter-form';

  // Expose pour les autres modules (mapDomToItems, etc.)
  try { window.YUZ_SKIP_SELECTORS = YUZ_SKIP; } catch (_) { }

  const CANONICAL_URL_OMIT_PARAMS = [
    'yuz-edit-translation',
    'yuz-edit-translation-url',
    'yuzprobe',
    'debug_dom',
    'dom_log',
    'yuzdebug'
  ];

  const canonicalPageUrl = (rawUrl) => {
    const fallback = () => String(rawUrl || window.location.href || '').split('#')[0];
    try {
      const url = new URL(rawUrl || window.location.href, window.location.origin);
      CANONICAL_URL_OMIT_PARAMS.forEach((param) => url.searchParams.delete(param));
      return url.toString().split('#')[0];
    } catch (_) {
      return fallback();
    }
  };

  // Figer les nonces pour empêcher une mutation/injection tardive
  try { if (Y.nonces && !Object.isFrozen(Y.nonces)) Object.freeze(Y.nonces); } catch (_) { }
  // Sources possibles injectées côté WP/YUZ
  const CFG = (window.YUZ_TE_CFG
    || window.yuzTraSettings
    || window.YUZ_PARAMS
    || {});
  const TRACE = CFG.trace || Math.random().toString(16).slice(2);
  const norm = (s) => (s || '').toString().replace('-', '_');
  const canonicalOrigin = (value) => {
    const normalized = (value == null ? '' : String(value)).trim().toLowerCase();
    if (normalized === 'auto') {
      return 'machine';
    }
    if (['manual', 'machine', 'dock', 'gettext', 'dom'].includes(normalized)) {
      return normalized;
    }
    return 'manual';
  };
  // Normalise la liste des langues (selon la structure réelle)
  const translationLangs = (() => {
    if (Array.isArray(CFG.langs)) return CFG.langs;
    if (Array.isArray(CFG.translatable_languages)) return CFG.translatable_languages;

    const Y = window.yuzTraSettings || {};
    const fromCfg = Array.isArray(CFG?.translation_langs) ? CFG.translation_langs : [];
    if (fromCfg.length) return fromCfg;

    const fromStructured = Y?.settings?.general?.yuz_tra_translatable_languages;
    if (Array.isArray(fromStructured) && fromStructured.length) return fromStructured;

    const fromFlat = Y?.general?.yuz_tra_translatable_languages;
    if (Array.isArray(fromFlat) && fromFlat.length) return fromFlat;

    if (Array.isArray(window.__yuz_boot_langs__) && window.__yuz_boot_langs__.length) {
      return window.__yuz_boot_langs__;
    }

    return [];
  })();

  const resolveAssetBase = () => {
    try {
      const script = document.querySelector('script[src*="yuz-translation-editor"]');
      if (script && script.src) {
        const base = script.src.replace(/js\/widgets\/yuz-translation-editor(?:\.min)?\.js.*$/, '');
        if (base) {
          return base;
        }
      }
    } catch (_) { }
    const cfgBase = (window.yuzTraSettings && (window.yuzTraSettings.assets_base || window.yuzTraSettings.assetsBase)) || '';
    if (cfgBase) {
      return cfgBase.replace(/\/$/, '') + '/';
    }
    return '/wp-content/plugins/yuz-tra/assets/';
  };

  const ASSET_BASE = resolveAssetBase();
  const publishControllerScriptUrl = () => (ASSET_BASE.replace(/\/$/, '') + '/js/yuz-publish-controller.js');
  const publishControllerCssUrl = () => (ASSET_BASE.replace(/\/$/, '') + '/css/yuz-publish-controller.css');

  const ensurePublishControllerAssets = (() => {
    let inflight = null;
    return () => {
      if (window.YUZ_PUBLISH && typeof window.YUZ_PUBLISH.showDockReview === 'function') {
        return Promise.resolve(true);
      }
      if (inflight) {
        return inflight;
      }
      inflight = new Promise((resolve) => {
        try {
          const cssHref = publishControllerCssUrl();
          if (cssHref && !document.querySelector('link[data-yuz-publish-css="1"]')) {
            const link = document.createElement('link');
            link.rel = 'stylesheet';
            link.href = cssHref;
            link.dataset.yuzPublishCss = '1';
            document.head.appendChild(link);
          }
        } catch (_) { }

        const script = document.createElement('script');
        script.src = publishControllerScriptUrl();
        script.async = true;
        script.onload = () => {
          resolve(true);
        };
        script.onerror = () => {
          resolve(false);
        };
        document.head.appendChild(script);
      }).finally(() => {
        inflight = null;
      });
      return inflight;
    };
  })();

  const describeReviewDockState = () => {
    const scriptLoaded = !!document.querySelector('script[src*="yuz-publish-controller"]');
    const cssLoaded = !!document.querySelector('link[data-yuz-publish-css="1"]');
    const publish = window.YUZ_PUBLISH || null;
    return {
      hasWindow: !!publish,
      hasShowDock: !!(publish && typeof publish.showDockReview === 'function'),
      pendingCount: publish && publish.state && Array.isArray(publish.state.pending)
        ? publish.state.pending.length
        : null,
      scriptLoaded,
      cssLoaded
    };
  };

  const TGTS = translationLangs.filter(Boolean).map(String);
  const HAS_TARGETS = translationLangs.length > 0;
  const CAN_SWAP_LANG = !!(CFG && CFG.flags && (CFG.flags.te_allow_source_swap || CFG.flags.allow_source_swap));
  const canonicalMode = (mode) => {
    const value = (mode || '').toString().toLowerCase();
    if (value === 'advanced' || value === 'full') return 'advanced';
    if (value === 'essential' || value === 'compact') return 'essential';
    if (value === 'xpress-progress' || value === 'mini-progress') return 'xpress-progress';
    if (value === 'xpress' || value === 'mini') return 'xpress';
    return 'advanced';
  };
  const setUiDataset = (mode) => {
    const canonical = canonicalMode(mode);
    const datasetMode = canonical === 'xpress-progress' ? 'xpress' : canonical;
    try { document.documentElement.dataset.yuzUi = datasetMode; } catch (_) { }
  };
  const MODE_CHOICES = [
    { value: 'advanced', label: 'Advanced' },
    { value: 'essential', label: 'Essential' },
    { value: 'xpress', label: 'Xpress' }
  ];
  const INITIAL_MODE = canonicalMode(
    (window.YUZ_UI && typeof window.YUZ_UI.getMode === 'function' && window.YUZ_UI.getMode())
    || (typeof localStorage !== 'undefined' ? localStorage.getItem('yuz.ui.mode') : null)
    || 'advanced'
  );
  const EMPTY_MESSAGE = "Aucune langue cible configurée. Allez dans Admin → YUZ TRA → Languages et cochez « Translatable ».";

  setUiDataset(INITIAL_MODE);

  const fromLang = (CFG?.effective_from ?? CFG?.source_language ?? CFG?.default_language ?? 'en_US');
  const LVL = { critical: '🟥 [CRITICAL]', warning: '🟨 [WARNING]', success: '🟩 [SUCCESS]', info: '🟦 [INFO]' };
  const log = (level, msg, ctx) => {
    const ts = new Date().toISOString();
    let out = `${LVL[level] || ''} ${msg} at ${ts}`;
    if (ctx) { try { out += ` | ${JSON.stringify(ctx)}`; } catch (_) { } }
    (level === 'critical' ? yuz_release_console.error : (level === 'warning' ? yuz_release_console.warn : yuz_release_console.log))(out);
  };
  let hvyNonceWarned = false;

  // Fallback toast (silencieux si pas de lib de toast)
  const toast = (message, type = 'info') => {
    try {
      if (window.CLAR && typeof window.CLAR.toast === 'function') {
        window.CLAR.toast(message, { type });
      } else {
        const m = (type === 'error') ? 'error' : (type === 'warning' ? 'warn' : 'log');
      }
    } catch (_) { }
  };

  const ACTION = {
    // fetch/search → tra
    TM_GET: 'yuz_tra_tm_get_translations',
    TM_SEARCH: 'yuz_tra_tm_search',
    // save/bulk → int
    TE_SAVE: 'yuz_save_translation',
    TE_PUBLISH: 'yuz_tra_te_upd_publish',
    TE_CREATE: 'yuz_tra_te_cre_translation',
    // optional maintenance
    TM_DEL: 'yuz_tra_tm_del_translation',
    TM_CREATE_PAGE: 'yuz_tra_tm_cre_page',
    // suggest_ai → hvy (si présent)
    AI_SUGGEST_BATCH: 'yuz_ai_batch_translate'
  };

  const PREVIEW_STORAGE_KEY = 'yuz.preview_toggle';

  if (typeof window.__yuzInitPipelineProbe !== 'function') {
    window.__yuzInitPipelineProbe = function initPipelineProbe(originLabel = 'te') {
      const search = window.location.search || '';
      const forcedOff = /\byuzprobe=0\b/.test(search);
      const forcedOn = /\byuzprobe=1\b/.test(search);
      const telemetryFlag = window.yuzTraSettings?.telemetry?.pipeline_probe;
      const enabled = !forcedOff && (forcedOn || telemetryFlag === true || window.YUZ_DEBUG === true);
      const endpoint = window.yuzTraSettings?.ajax_url || window.ajaxurl || '/wp-admin/admin-ajax.php';

      if (!enabled || !endpoint) {
        const noop = () => { };
        noop.enabled = false;
        return noop;
      }

      const limitArray = (input, max = 20) => {
        if (!Array.isArray(input)) return input;
        if (input.length <= max) return input;
        const subset = input.slice(0, max);
        subset.push(`+${input.length - max}`);
        return subset;
      };

      const sanitizeValue = (value, depth = 0) => {
        if (value == null) return value;
        if (Array.isArray(value)) {
          return limitArray(value.map((entry) => sanitizeValue(entry, depth + 1)));
        }
        if (typeof value === 'object') {
          if (depth >= 2) return '[Object]';
          const out = {};
          Object.entries(value).slice(0, 8).forEach(([key, val]) => {
            out[key] = sanitizeValue(val, depth + 1);
          });
          return out;
        }
        if (typeof value === 'string' && value.length > 400) {
          return value.slice(0, 400) + '…';
        }
        return value;
      };

      const alias = window.YUZ_PARAMS?.alias || window.yuzTraSettings?.current_user || '';

      const send = (event, detail = {}) => {
        try {
          const context = sanitizeValue(detail) || {};
          context.agent = originLabel;
          context.alias = context.alias || alias || null;
          context.url = context.url || window.location.href.split('#')[0];
          context.ts = new Date().toISOString();
          if (context.ids) {
            const ids = Array.isArray(context.ids) ? context.ids : [context.ids];
            context.ids = limitArray(ids);
          }
          const payload = new URLSearchParams({
            action: 'yuz_dom_log',
            event: `pipeline:${event}`,
            context: JSON.stringify(context)
          });
          const data = payload.toString();
          if (navigator.sendBeacon) {
            navigator.sendBeacon(endpoint, new Blob([data], { type: 'application/x-www-form-urlencoded' }));
            return;
          }
          fetch(endpoint, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: data,
            credentials: 'include'
          }).catch(() => { });
        } catch (probeErr) {
        }
      };
      send.enabled = true;
      return send;
    };
  }

  const pipelineProbe = (() => {
    if (typeof window.YUZ_PIPE_PROBE === 'function') {
      return window.YUZ_PIPE_PROBE;
    }
    const factory = window.__yuzInitPipelineProbe || (() => () => { });
    const fn = factory('translation-editor');
    window.YUZ_PIPE_PROBE = fn;
    return fn;
  })();


  const GROUP = {
    [ACTION.TM_GET]: 'tra',
    [ACTION.TM_SEARCH]: 'tra',
    [ACTION.TE_SAVE]: 'tra',
    [ACTION.TE_PUBLISH]: 'int',
    [ACTION.TE_CREATE]: 'int',
    [ACTION.TM_DEL]: 'int',
    [ACTION.TM_CREATE_PAGE]: 'int',
    [ACTION.AI_SUGGEST_BATCH]: 'hvy'
  };


  const GROUP_NONCE_FALLBACK = {
    tra: ['yuz_tra_nonce', 'yuz_int_nonce', 'yuz_api_nonce'],
    int: ['yuz_int_nonce', 'yuz_tra_nonce', 'yuz_api_nonce'],
    hvy: ['yuz_hvy_nonce', 'yuz_api_nonce', 'yuz_tra_nonce']
  };

  // Nonce strict: si absent → on bloque la requête
  const nonceFor = (action) => {
    const nonces = (Y && Y.nonces) || {};
    const direct = nonces[action];
    if (direct) return direct;

    const group = GROUP[action];
    const fallbacks = GROUP_NONCE_FALLBACK[group] || [];
    for (const alias of fallbacks) {
      if (alias && nonces[alias]) return nonces[alias];
    }

    for (const alias of ['yuz_tra_nonce', 'yuz_int_nonce', 'yuz_api_nonce', 'yuz_del_nonce']) {
      if (nonces[alias]) return nonces[alias];
    }

    const shared = __nonceFor__(action);
    if (shared) return shared;

    log('critical', '[YUZ][TE] Nonce absent — requête bloquée', { action, group: group || '?' });
    throw new Error('MISSING_NONCE');
  };

  // --- Patch 2: Response shape helpers (compat results/translations/strings) ---
  const isOk = (r) => !!(r && (r.success === true || r.success === 1 || r.success === '1'));

  function pickArray(obj, keys) {
    for (const k of keys) {
      const v = obj && obj[k];
      if (Array.isArray(v)) return v;
    }
    return [];
  }

  function normalizeTranslationEntry(entry) {
    if (!entry || typeof entry !== 'object') {
      const text = typeof entry === 'string' ? entry : '';
      return {
        translated: text,
        status: text ? '1' : '0',
        translation_id: 0
      };
    }
    const clone = { ...entry };
    const translationId = toInt(
      clone.translation_id
      || clone.translationId
      || clone.id
      || 0
    );
    const rawStatus = clone.status;
    let status = rawStatus != null && rawStatus !== '' ? String(rawStatus) : '';
    const textCandidate = clone.edited != null ? clone.edited : clone.translated;
    if (!status) {
      status = (textCandidate != null && String(textCandidate).trim().length) ? '1' : '0';
    }
    clone.status = status;
    clone.translation_id = translationId;
    return clone;
  }

  function normalizeTranslationMap(map) {
    const out = {};
    if (!map || typeof map !== 'object') return out;
    Object.keys(map).forEach(code => {
      out[code] = normalizeTranslationEntry(map[code]);
    });
    return out;
  }

  function toInt(value) {
    const parsed = parseInt(value, 10);
    return Number.isNaN(parsed) ? 0 : parsed;
  }

  function asString(value) {
    return value == null ? '' : String(value);
  }

  function normalizeStrings(data) {
    const rows = pickArray(data || {}, ['strings', 'results', 'translations']);
    return rows.map(s => {
      const translationRawId = s.translation_id ?? s.translationId ?? s.dbID ?? 0;
      const translationNumericId = toInt(translationRawId);
      const stringRawId = s.string_id ?? s.id ?? s.dbID ?? translationRawId;
      const stringNumericId = toInt(stringRawId);
      const stringKey = asString(s.id || stringRawId || stringNumericId || '');

      const baseTranslations = normalizeTranslationMap(s.translations || s.targets || {});
      const targetCode = asString(
        s.target_lang_code || s.language_code || s.target_lang || s.lang || ''
      ).trim();
      const translatedText = asString(
        s.translated_text || s.translation || s.translated || ''
      );
      if (targetCode && !baseTranslations[targetCode] && translatedText) {
        baseTranslations[targetCode] = normalizeTranslationEntry({
          translated: translatedText,
          status: s.status != null ? s.status : '1'
        });
      }

      return {
        id: stringKey || (stringNumericId ? String(stringNumericId) : ''),
        translation_id: translationNumericId,
        string_id: stringNumericId,
        original: asString(s.original || s.source || s.text || ''),
        page_url: asString(s.page_url || s.url || ''),
        post_id: toInt(s.post_id || s.page_id || 0),
        context: asString(s.context || s.block_context || 'content'),
        block_id: asString(s.block_id || ''),
        source_lang_id: toInt(s.source_lang_id || s.source_id || 0),
        target_lang_id: toInt(s.target_lang_id || s.target_id || 0),
        source_lang_code: asString(s.source_lang_code || s.source_language || ''),
        target_lang_code: targetCode,
        translations: baseTranslations,
      };
    });
  }

  function pickTranslationEntry(item, code) {
    if (!item || !code) return null;
    const map = item.translations;
    if (!map || typeof map !== 'object') return null;
    const normalized = String(code || '').trim();
    if (!normalized) return null;
    const candidates = [normalized];
    if (normalized.includes('-')) {
      candidates.push(normalized.replace(/-/g, '_'));
    }
    if (normalized.includes('_')) {
      candidates.push(normalized.replace(/_/g, '-'));
    }
    const lower = normalized.toLowerCase();
    candidates.push(lower);
    candidates.push(lower.replace(/_/g, '-'));
    const tried = new Set();
    for (const key of candidates) {
      if (!key || tried.has(key)) continue;
      tried.add(key);
      if (map[key]) return map[key];
    }
    const keys = Object.keys(map);
    for (const existingKey of keys) {
      if (String(existingKey).toLowerCase() === lower) {
        return map[existingKey];
      }
    }
    return null;
  }

  function pickTranslationId(item, code) {
    const entry = pickTranslationEntry(item, code);
    if (entry) {
      const entryId = toInt(entry.translation_id || entry.id || 0);
      if (entryId > 0) return entryId;
    }
    const fallback = typeof code === 'string'
      ? code.replace(/-/g, '_').replace(/__/g, '_')
      : '';
    if (fallback && fallback !== code) {
      const altEntry = pickTranslationEntry(item, fallback);
      if (altEntry) {
        const altId = toInt(altEntry.translation_id || altEntry.id || 0);
        if (altId > 0) return altId;
      }
    }
    return toInt(item?.translation_id || item?.dbID || 0);
  }

  function pickStringId(item) {
    if (!item) return 0;
    return toInt(item.string_id || item.id || 0);
  }

  function pickSuggestions(data) {
    const rows = pickArray(data || {}, ['suggestions', 'results', 'translations']);
    return rows.map(x =>
      typeof x === 'string' ? x
        : x.translated_text || x.translation || x.translated || x.text || ''
    ).filter(Boolean);
  }

  // --- Helpers critiques (BLOC A) ---
  function getParam(name, url) {
    url = url || window.location.href;
    name = name.replace(/[\[\]]/g, "\\$&");
    var regex = new RegExp("[?&]" + name + "(=([^&#]*)|&|#|$)"),
      results = regex.exec(url);
    if (!results || !results[2]) return "";
    return decodeURIComponent(results[2].replace(/\+/g, " "));
  }

  // Jamais envoyer "auto" au serveur
  function normalizeTargetLang(candidate, langs, fallbacks) {
    let code = candidate || '';
    if (code && code !== 'auto') return code;
    for (const f of (fallbacks || [])) {
      if (f && f !== 'auto') return f;
    }
    if (Array.isArray(langs) && langs.length && langs[0].language_code) {
      return langs[0].language_code;
    }
    return '';
  }

  // ACT-03/05: utilise uniquement Y (yuzTE). Vérifie même-origine. (BLOC B)
  const apiPost = (action, payload = {}) => {
    let nonceValue;
    try { nonceValue = nonceFor(action); }
    catch (e) {
      const rejected = $.Deferred();
      rejected.reject(e);
      return rejected.promise();
    }

    const data = { ...payload };
    if (TRACE && data.yuz_trace === undefined) {
      data.yuz_trace = TRACE;
    }
    if (nonceValue) {
      if (data.nonce === undefined) data.nonce = nonceValue;
      if (data._ajax_nonce === undefined) data._ajax_nonce = nonceValue;
      if (data.yuz_tra_nonce === undefined) data.yuz_tra_nonce = nonceValue;
    }

    const deferred = $.Deferred();
    yuzPost(action, data)
      .then((result) => deferred.resolve(result))
      .catch((error) => deferred.reject(error));
    return deferred.promise();
  };

  // Throttle & Debounce
  const throttle = (fn, wait = 300) => {
    let last = 0, timer = null, lastArgs = null, lastThis = null;
    return function throttled(...args) {
      const now = Date.now(), remaining = wait - (now - last);
      lastArgs = args; lastThis = this;
      if (remaining <= 0) { clearTimeout(timer); timer = null; last = now; fn.apply(lastThis, lastArgs); lastArgs = lastThis = null; }
      else if (!timer) { timer = setTimeout(() => { last = Date.now(); timer = null; fn.apply(lastThis, lastArgs); lastArgs = lastThis = null; }, remaining); }
    };
  };
  const debounce = (fn, wait = 400) => {
    let t = null;
    return function (...args) { clearTimeout(t); t = setTimeout(() => fn.apply(this, args), wait); };
  };

  const bus = (name, detail) => {
    try {
      document.dispatchEvent(new CustomEvent(name, { detail }));
    } catch (err) {
    }
  };

  const ensureTranslateLogs = (() => {
    let wired = false;
    return () => {
      if (wired) return;
      wired = true;
      document.addEventListener('yuz:translate:request', (e) => {
      });
      document.addEventListener('yuz:translate:success', (e) => {
      });
      document.addEventListener('yuz:translate:error', (e) => {
      });
    };
  })();
  ensureTranslateLogs();

  const TRANSLATE_ENDPOINT = __AJAX__;

  const TRANSLATE_NONCE = __nonceFor__('yuz_translate');

  function nonceForTM() {
    return __nonceFor__('yuz_tra_tm_translate');
  }

  // -------------------------------
  // Sniffer & nettoyage agressifs
  // -------------------------------

  function yuzLooksLikeCode(text) {
    if (!text) return false;
    const s = String(text).trim();
    const hints = [
      /<[a-z][^>]*>/i,                                   // HTML tags
      /:[^;]+;/,                                         // CSS prop
      /@[a-z-]+/i,                                       // @media, @keyframes, …
      /{[^}]*}/,                                         // { ... }
      /\b(function|const|let|var|=>|return|if\s*\(|for\s*\(|while\s*\(|document\.|window\.|addEventListener)\b/
    ];
    let hits = 0; for (const re of hints) if (re.test(s)) hits++;
    const props = (s.match(/:[^;]+;/g) || []).length;
    const punct = (s.match(/[{}[\];<>]/g) || []).length;
    const letters = (s.match(/[A-Za-zÀ-ÿ]/g) || []).length;
    const density = (punct + props) / Math.max(1, letters);
    return hits >= 2 || props >= 2 || density > 0.25;
  }

  function stripCssJsNoise(text) {
    if (!text) return '';
    let t = String(text);

    t = t
      .replace(/<style[\s\S]*?<\/style>/gi, ' ')
      .replace(/<script[\s\S]*?<\/script>/gi, ' ')
      .replace(/<!--[\s\S]*?-->/g, ' ')
      .replace(/<\/?\w+[^>]*>/g, ' ')
      .replace(/@[a-z-]+\s[^{]+{[^}]*}/gi, ' ')
      .replace(/{[^}]*}/g, ' ')
      .replace(/(^|\n)\s*[^:\n]{0,80}:[^;]+;?/gim, ' ')
      .replace(/\/\*[\s\S]*?\*\//g, ' ')
      .replace(/(^|[^:])\/\/[^\n]*/g, ' ')
      .replace(/(^|\n)\s*(var|let|const|function|\)\s*=>)[^\n]*/g, ' ')
      .replace(/\b(document|window|addEventListener|querySelector(All)?|localStorage)\b[^\n]*/g, ' ')
      .replace(/\s+/g, ' ')
      .trim();

    if (yuzLooksLikeCode(t)) return '';
    if (!/[A-Za-zÀ-ÿ]{2}/.test(t)) return '';
    return t;
  }

  function yuzShouldSkip(text = '') {
    const trimmed = String(text || '').trim();
    if (trimmed.length < 2) return true;
    return yuzLooksLikeCode(trimmed);
  }

  // Expose pour réutilisation ailleurs (mapper, setters…)
  try {
    window.stripCssJsNoise = stripCssJsNoise;
    window.yuzLooksLikeCode = yuzLooksLikeCode;
    window.yuzShouldSkip = yuzShouldSkip;
  } catch (_) { }

  // --- Visible-text scanner used by the editor (TreeWalker)
  function yuzEditorVisibleText(root) {
    const out = [];
    if (!root || root.nodeType !== 1) return '';
    const SKIP = YUZ_SKIP; // ← unifié
    const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, {
      acceptNode(node) {
        const p = node.parentElement;
        if (!p || p.closest(SKIP)) return NodeFilter.FILTER_REJECT;
        const cs = getComputedStyle(p);
        if (cs.display === 'none' || cs.visibility === 'hidden' || parseFloat(cs.opacity) === 0) return NodeFilter.FILTER_REJECT;
        let t = (node.nodeValue || '').trim();
        if (!t) return NodeFilter.FILTER_REJECT;
        if (yuzLooksLikeCode(t)) return NodeFilter.FILTER_REJECT; // heuristique agressive
        // Shortcodes WP
        t = t.replace(/\[(\/)?[a-z0-9_-]+[^\]]*\]/ig, '').trim();
        return t ? NodeFilter.FILTER_ACCEPT : NodeFilter.FILTER_REJECT;
      }
    });
    while (walker.nextNode()) out.push(walker.currentNode.nodeValue.trim());
    return out.join(' ').replace(/\s+/g, ' ').trim();
  }
  try { window.yuzEditorVisibleText = yuzEditorVisibleText; } catch (_) { }

  const ajaxPromise = (action, payload = {}) => new Promise((resolve, reject) => {
    const DEBUG = /\b(?:\?|&)yuzdebug=1\b/.test(location.search) || window.YUZ_DEBUG === true;
    // Ajoute un trace-id corrélé si absent
    try {
      if (payload && typeof payload === 'object' && !payload.yuz_trace) {
        const trace = (window.yuzTraSettings && (window.yuzTraSettings.trace || '')) || String(Date.now());
        payload.yuz_trace = trace;
      }
    } catch (_) { }

    if (DEBUG) {
      try {
        const peek = Object.assign({}, payload);
        if (typeof peek.text === 'string') peek.text = `len:${peek.text.length}`;
        if (typeof peek.original_text === 'string') peek.original_text = `len:${peek.original_text.length}`;
      } catch (_) { }
    }

    apiPost(action, payload)
      .done(result => {
        if (DEBUG) {
        }
        resolve(result);
      })
      .fail(error => {
        if (DEBUG) {
        }
        reject(error);
      });
  });

  const sleep = (ms = 0) => new Promise(resolve => setTimeout(resolve, ms));

  function yuzProgress(vm, stats) {
    if (!vm || typeof vm !== 'object') return;
    const total = Math.max(0, stats?.total || 0);
    const done = Math.max(0, stats?.done || 0);
    const skipped = Math.max(0, stats?.skipped || 0);
    const running = !!stats?.running;
    const percent = total > 0 ? Math.min(100, Math.round(((done + skipped) / total) * 100)) : 0;

    if (!vm.autoBatch) {
      vm.autoBatch = { total: 0, done: 0, skipped: 0, running: false, percent: 0 };
    }
    vm.autoBatch.total = total;
    vm.autoBatch.done = done;
    vm.autoBatch.skipped = skipped;
    vm.autoBatch.running = running;
    vm.autoBatch.percent = percent;

    const bar = document.querySelector('.yuz-progress-bar span');
    if (bar) bar.style.width = percent + '%';
    const label = document.querySelector('.yuz-progress-label');
    if (label) label.textContent = `${percent}% Complete`;
  }

  if (typeof window !== 'undefined') {
    if (!window.ajaxurl && TRANSLATE_ENDPOINT) window.ajaxurl = TRANSLATE_ENDPOINT;
    if (!window.yuzNonce && TRANSLATE_NONCE) window.yuzNonce = TRANSLATE_NONCE;
    if (!TRANSLATE_NONCE) {
      try { yuz_release_console.warn('[YUZ] Missing nonce for yuz_translate; instant mode may fail.'); } catch (_) { }
    }
  }

  // --- Canoniseur unique côté front : xx_XX / xx / auto → xx
  function canonLT(code) {
    if (!code || code === 'auto') return 'auto';
    const s = String(code).trim().replace('-', '_').toLowerCase();
    const ALIAS = {
      en_us: 'en', en_gb: 'en', en_ca: 'en', en_au: 'en',
      fr_fr: 'fr', fr_ca: 'fr',
      es_es: 'es', es_mx: 'es',
      pt_br: 'pt', pt_pt: 'pt',
      zh_cn: 'zh', zh_tw: 'zh', zh_hans: 'zh', zh_hant: 'zh',
      de_de: 'de', it_it: 'it', nl_nl: 'nl',
      ja_jp: 'ja', ko_kr: 'ko', ru_ru: 'ru'
    };
    if (ALIAS[s]) return ALIAS[s];
    const m = s.match(/^([a-z]{2,3})(?:_[a-z0-9]{2,8})?$/i);
    return (m && m[1]) ? m[1].toLowerCase() : s.slice(0, 2);
  }

  function configHint() {
    const cfg = window.yuzTraSettings || {};
    const hints = [];
    if (!cfg.ajax_url) hints.push('ajax_url manquant');
    const nonces = cfg.nonces || {};
    if (!nonces.yuz_hvy_nonce && !nonces.yuz_api_nonce && !nonces.yuz_tra_tm_translate) {
      hints.push('nonce API absent');
    }
    const api = cfg.api_settings || cfg.api || {};
    if (api && typeof api === 'object' && Object.keys(api).length === 0) {
      hints.push('API non configurée');
    }
    return hints.join(' | ');
  }

  function autoFlag(flagName, fallback) {
    const cfg = window.yuzTraSettings || {};
    if (typeof cfg[flagName] === 'boolean') return cfg[flagName];
    return fallback;
  }

  // --- Anti-rafales : cache global + verrous de requêtes en cours -------
  const LT_CACHE = new Map();
  const LT_INFLIGHT = new Map();

  function hashText(s) {
    return String(s).trim().slice(0, 200);
  }

  function cacheKey(from, to, text) {
    return `${from}|${to}|${hashText(text)}`;
  }

  function pickTranslatedText(response) {
    const txt =
      response?.translated_text
      || response?.data?.translated_text
      || response?.data?.results?.[0]?.translated_text
      || response?.data?.translations?.[0]?.translated_text
      || response?.results?.[0]?.translated_text
      || response?.translations?.[0]?.translated_text
      || response?.data?.results?.[0]?.translation
      || response?.data?.translations?.[0]?.translation
      || response?.results?.[0]?.translation
      || response?.translations?.[0]?.translation
      || response?.data?.results?.[0]?.translated
      || response?.data?.translations?.[0]?.translated
      || response?.results?.[0]?.translated
      || response?.translations?.[0]?.translated
      || response?.data?.results?.['0']?.translated_text
      || response?.data?.translations?.['0']?.translated_text
      || response?.results?.['0']?.translated_text
      || response?.translations?.['0']?.translated_text
      || response?.data?.results?.['0']?.translation
      || response?.data?.translations?.['0']?.translation
      || response?.results?.['0']?.translation
      || response?.translations?.['0']?.translation
      || response?.data?.results?.['0']?.translated
      || response?.data?.translations?.['0']?.translated
      || response?.results?.['0']?.translated
      || response?.translations?.['0']?.translated
      || '';
    return String(txt || '').trim();
  }

  async function translateAjax({ text, from, to }) {
    const cleaned = stripCssJsNoise(text || '');
    if (!cleaned) throw new Error('empty_text');

    const requestedFrom = typeof from === 'string' ? from.trim() : (from || '');
    const requestedTo = typeof to === 'string' ? to.trim() : (to || '');
    const fromCanon = canonLT(requestedFrom || CFG.source_language || CFG.default_language || '');
    const toCanon = canonLT(requestedTo || (CFG.translation_langs && CFG.translation_langs[0]) || '');
    if (!toCanon) throw new Error('missing_target');

    const key = cacheKey(fromCanon, toCanon, cleaned);

    if (LT_CACHE.has(key)) {
      return LT_CACHE.get(key);
    }
    if (LT_INFLIGHT.has(key)) {
      return LT_INFLIGHT.get(key);
    }

    const promise = (async () => {
      bus('yuz:translate:request', { text: cleaned, from: fromCanon, to: toCanon });
      try {
        const response = await tmTranslateAjax({
          text: cleaned,
          from: fromCanon,
          to: toCanon,
          requestedFrom,
          requestedTo,
          persist: 0
        });

        const translated = pickTranslatedText(response);
        if (translated) {
          LT_CACHE.set(key, translated);
          bus('yuz:translate:success', { text: cleaned, out: translated, from: fromCanon, to: toCanon });
          return translated;
        }

        const backendError = response?.error || response?.data?.error || response?.message || response?.data?.message;
        if (backendError) throw new Error(`translate_failed:${backendError}`);

        const pair = `${fromCanon || requestedFrom || 'auto'}->${toCanon || requestedTo || 'auto'}`;
        const hint = configHint();
        throw new Error(hint ? `empty_translation (${hint})` : `translate_failed:pair_not_supported (${pair})`);
      } catch (err) {
        bus('yuz:translate:error', { text: cleaned, from: fromCanon, to: toCanon, error: err?.message || String(err) });
        throw err;
      } finally {
        LT_INFLIGHT.delete(key);
      }
    })();

    LT_INFLIGHT.set(key, promise);
    return promise;
  }

  async function tmTranslateAjax(params = {}) {
    const nonce = nonceForTM();
    if (!nonce) throw new Error('Missing TM nonce');

    const reqId = params.req_id
      || params.reqId
      || (window.crypto?.randomUUID?.() ?? Math.random().toString(36).slice(2));

    let items = [];
    if (params.payload !== undefined) {
      if (Array.isArray(params.payload)) {
        items = params.payload.slice();
      } else if (typeof params.payload === 'string') {
        try {
          const parsed = JSON.parse(params.payload);
          if (Array.isArray(parsed)) items = parsed;
        } catch (_) {
          throw new Error('invalid_payload');
        }
      }
    }

    if (!items.length && typeof params.text === 'string') items = [{ i: 0, text: params.text }];

    items = items.map((row, index) => {
      const idx = typeof row.i === 'number' ? row.i : index;
      const text = stripCssJsNoise(row.text || '');
      return { ...row, i: idx, text };
    }).filter(row => row.text !== '');

    if (!items.length) throw new Error('empty_payload');

    const singleMode = items.length === 1;

    const fromInput = (typeof params.requestedFrom === 'string' ? params.requestedFrom.trim()
      : (typeof params.from === 'string' ? params.from.trim()
        : (typeof params.source === 'string' ? params.source.trim() : '')));
    const toInput = (typeof params.requestedTo === 'string' ? params.requestedTo.trim()
      : (typeof params.to === 'string' ? params.to.trim()
        : (typeof params.target === 'string' ? params.target.trim() : '')));

    const fromCanon = canonLT(fromInput || params.from || window.yuzTraSettings?.source_language || 'auto');
    const toCanon = canonLT(toInput || params.to || window.yuzTraSettings?.translatable_languages?.[0] || '');
    if (!toCanon) throw new Error('missing_target');

    const basePayload = {
      req_id: reqId,
      payload: JSON.stringify(items),
    };
    if (params.persist === undefined) {
      basePayload.persist = '1';
    } else {
      basePayload.persist = params.persist ? '1' : '0';
    }
    if (typeof params.context === 'string' && params.context.trim() !== '') {
      basePayload.context = params.context.trim();
    }
    if (TRACE) {
      basePayload.yuz_trace = TRACE;
    }

    async function postOnce(src, tgt) {
      const payload = {
        ...basePayload,
        source: src || 'auto',
        target: tgt,
      };

      let json;
      try {
        json = await yuzPost('yuz_tra_tm_translate', payload);
      } catch (error) {
        throw error;
      }

      if (typeof json === 'string') {
        try { json = JSON.parse(json); } catch (_) { json = { raw: json }; }
      }

      if (!json || json.success !== true) {
        const err = json?.data?.message || json?.data?.error || json?.message || 'tm_failed';
        throw new Error(err);
      }

      const translatedSingle = (() => {
        if (!singleMode) return '';
        const key = items[0].i;
        const bag = json?.data?.results
          ?? json?.data?.translations
          ?? json?.results
          ?? json?.translations
          ?? null;
        const pick = (b, k) => Array.isArray(b) ? b[k] : (b && b[k]) || null;
        const node = bag ? pick(bag, key) : null;
        const txt =
          node?.translated_text
          || node?.translation
          || node?.translated
          || json?.data?.translated_text
          || json?.translated_text
          || '';
        return String(txt || '').trim();
      })();

      if (translatedSingle) {
        json.translated_text = translatedSingle;
        if (params.autoApply !== false) {
          const ta = document.querySelector('#yuz-te-translation');
          if (ta) {
            ta.value = translatedSingle;
            ta.dispatchEvent(new Event('input', { bubbles: true }));
          }
        }
      } else if (singleMode) {
      }

      return json;
    }

    let json = await postOnce(fromCanon, toCanon);
    // Only retry with raw requested codes in single-item mode. In batch mode,
    // translatedSingle is intentionally empty and a retry would be redundant.
    if (singleMode && !json?.translated_text && (fromCanon !== fromInput || toCanon !== toInput)) {
      json = await postOnce(fromInput, toInput);
    }

    return json;
  }

  // Instant translate mode (auto-suggestions quand on tape)
  function installInstantMode(modalEl) {
    if (!modalEl) return () => { };
    const src = modalEl.querySelector('textarea[name="yuz-original"]');
    const dst = modalEl.querySelector('textarea[name="yuz-translation"]');
    const fromSel = modalEl.querySelector('[name="yuz-from"]');
    const toSel = modalEl.querySelector('[name="yuz-to"]');
    if (!src || !dst || !fromSel || !toSel) return () => { };

    let timer = null;
    let lastToken = 0;
    const schedule = () => {
      if (timer) clearTimeout(timer);
      timer = setTimeout(async () => {
        const textRaw = (src.value || '').trim();
        const from = (fromSel.value || '').trim();
        const to = (toSel.value || '').trim();
        if (!textRaw || !to) return;
        const cleaned = stripCssJsNoise(textRaw);
        if (!cleaned) return;
        const token = ++lastToken;
        bus('yuz:translate:request', { text: cleaned, from, to, mode: 'instant' });
        try {
          const out = await translateAjax({ text: cleaned, from, to });
          if (token !== lastToken) return;
          dst.value = out;
          bus('yuz:translate:success', { text: cleaned, out, from, to });
        } catch (error) {
          if (token !== lastToken) return;
          const message = error instanceof Error ? error.message : String(error);
          bus('yuz:translate:error', { text: cleaned, from, to, error: message });
          toast(`Traduction échouée : ${message}`, 'error');
        }
      }, 350);
    };

    src.addEventListener('input', schedule);
    fromSel.addEventListener('change', schedule);
    toSel.addEventListener('change', schedule);
    schedule();

    return () => {
      if (timer) clearTimeout(timer);
      src.removeEventListener('input', schedule);
      fromSel.removeEventListener('change', schedule);
      toSel.removeEventListener('change', schedule);
    };
  }

  // Ensure styles / container shell
  function ensureOverlayStyles() {
    if (document.getElementById('yuz-te-style')) return;
    const style = document.createElement('style');
    style.id = 'yuz-te-style';
    style.textContent = `
#yuz-editor-container{
  position:fixed;
  inset:0;
  z-index:2147483647;
  pointer-events:auto;
}
#yuz-editor-container .yuz-mask{
  position:fixed;
  inset:0;
  background:rgba(15,23,42,.25);
  pointer-events:auto;
  backdrop-filter:blur(4px);
}
.yuz-editor-overlay{font-family:'SF Pro Text',system-ui,-apple-system,'Segoe UI',sans-serif;}
#yuz-editor-container .yuz-modal{position:fixed;top:24px;left:50%;transform:translateX(-50%);width:clamp(320px,calc(100vw - 48px),680px);max-height:90vh;background:rgba(255,255,255,.96);border-radius:18px;border:1px solid rgba(148,163,184,.25);box-shadow:0 28px 80px rgba(15,23,42,.2);overflow:hidden;pointer-events:auto;z-index:2147483647;display:flex;flex-direction:column;color:#0f172a;line-height:1.45;backdrop-filter:blur(22px);}
#yuz-editor-container .yuz-modal.yuz-te--essential{width:clamp(320px,calc(100vw - 48px),560px);}
#yuz-editor-container .yuz-modal.yuz-te--xpress{width:clamp(320px,calc(100vw - 48px),500px);}
.yuz-header{display:flex;flex-direction:column;gap:18px;padding:24px 28px 16px;background:linear-gradient(180deg,rgba(248,250,252,.97),rgba(255,255,255,.92));border-bottom:1px solid rgba(226,232,240,.65);cursor:grab;user-select:none;touch-action:none;}
.yuz-header:active{cursor:grabbing;}
.yuz-header-row{display:flex;align-items:center;gap:16px;flex-wrap:wrap;width:100%;}
.yuz-header-row--brand{justify-content:space-between;align-items:center;}
.yuz-header-row--mode{justify-content:space-between;gap:16px;}
.yuz-header-row--main{justify-content:flex-start;gap:12px;align-items:center;}
.yuz-header-row--main .yuz-btn{flex:1 1 0;min-width:0;}
.yuz-header-brand{display:flex;align-items:center;gap:12px;font-weight:600;letter-spacing:-0.015em;min-width:0;}
.yuz-logo{width:64px;height:64px;min-width:64px;min-height:64px;display:inline-flex;align-items:center;justify-content:center;color:#0ea5e9;}
.yuz-btn.yuz-header-close-square{width:64px;height:64px;min-width:64px;min-height:64px;padding:0;border-radius:18px;display:inline-flex;align-items:center;justify-content:center;text-transform:uppercase;font-weight:700;letter-spacing:.02em;}
.yuz-logo svg{display:block;width:100%;height:100%;}
.yuz-brand-text{display:flex;flex-direction:column;line-height:1.1;gap:2px;}
.yuz-brand-text strong{font-size:16px;color:#0f172a;}
.yuz-editor-title{font-size:12px;font-weight:600;color:#475569;text-transform:uppercase;letter-spacing:0.18em;}
.yuz-mode{display:flex;align-items:center;gap:12px;flex:1 1 auto;}
.yuz-mode-label{font-size:11px;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:0.18em;order:2;}
.yuz-btn--mode{order:1;align-items:center;justify-content:space-between;gap:10px;padding:.55rem .95rem;border-radius:12px;background:linear-gradient(135deg,rgba(241,245,249,.92),rgba(255,255,255,.98));border:1px solid rgba(148,163,184,.45);color:#0f172a;font:600 13px/1.2 'SF Pro Text',system-ui,-apple-system,'Segoe UI',sans-serif;box-shadow:0 6px 14px rgba(15,23,42,.12);cursor:pointer;transition:all .18s ease;min-width:max-content;flex:1 1 auto;}
.yuz-btn--mode:hover{background:#fff;}
.yuz-btn--mode:focus{outline:none;box-shadow:0 0 0 3px rgba(14,165,233,.18);}
.yuz-mode-current{font-weight:600;}
.yuz-mode-cycle{font-size:14px;opacity:.65;}
.yuz-header-row--mode{align-items:stretch;}
.yuz-header-row--mode .yuz-mode{align-items:stretch;}
.yuz-header-row--mode .yuz-btn--mode{min-width:0;}
.yuz-header-row--mode .yuz-header-strings{flex:1 1 auto;display:inline-flex;justify-content:center;min-width:0;}
.yuz-header-row--main .yuz-btn{flex:1 1 0;min-width:0;}
.yuz-btn{appearance:none;display:inline-flex;align-items:center;justify-content:center;border:1px solid rgba(148,163,184,.45);background:rgba(248,250,252,.85);color:#0f172a;padding:.6rem .95rem;border-radius:12px;font:600 12px/1.2 'SF Pro Text',system-ui,-apple-system,'Segoe UI',sans-serif;cursor:pointer;transition:all .18s ease;box-shadow:0 1px 4px rgba(15,23,42,.08);min-width:max-content;line-height:1;}
.yuz-btn:hover{background:#f1f5f9;}
.yuz-btn:focus{outline:none;box-shadow:0 0 0 3px rgba(14,165,233,.2);}
.yuz-btn[disabled]{opacity:.45;cursor:not-allowed;box-shadow:none;}
.yuz-btn--ghost{background:transparent;border:1px solid var(--yuz-border,rgba(148,163,184,.45));}
.yuz-btn--primary{background:#0066cc;border-color:#0066cc;color:#fff;box-shadow:0 10px 24px rgba(0,102,204,.25);}
.yuz-btn--accent{background:#22c55e;border-color:#22c55e;color:#fff;box-shadow:0 10px 24px rgba(34,197,94,.25);}
.yuz-btn--warning{background:#f5c400;border-color:#f5c400;color:#1f2937;box-shadow:0 10px 24px rgba(245,196,0,.25);}
.yuz-btn--danger{background:#ef4444;border-color:#ef4444;color:#fff;box-shadow:0 10px 26px rgba(239,68,68,.25);}
.yuz-btn--circle{width:40px;height:40px;padding:0;border-radius:999px;display:flex;align-items:center;justify-content:center;font-size:16px;}
.yuz-btn--halo{position:relative;box-shadow:0 0 0 2px rgba(14,165,233,.32);}
.yuz-btn--halo::after{content:'';position:absolute;inset:-4px;border-radius:inherit;box-shadow:0 0 18px rgba(14,165,233,.45);opacity:.9;pointer-events:none;}
.yuz-body{display:flex;flex:1 1 auto;flex-direction:column;min-height:0;background:transparent;}
.yuz-scroll{flex:1 1 auto;overflow-y:auto;min-height:0;display:flex;flex-direction:column;gap:0;}
.yuz-section{padding:18px 20px;border-bottom:1px solid rgba(226,232,240,.7);background:rgba(255,255,255,.94);}
.yuz-section:last-of-type{border-bottom:0;}
.yuz-section-row{display:flex;align-items:center;justify-content:space-between;gap:12px;}
.yuz-progress-bar{height:8px;margin-top:10px;background:rgba(226,232,240,.9);border-radius:999px;overflow:hidden;}
.yuz-progress-bar span{display:block;height:100%;background:linear-gradient(90deg,#0ea5e9,#14b8a6);transition:width .25s ease-out;}
.yuz-loader{display:flex;align-items:center;justify-content:center;gap:10px;margin-top:10px;}
.yuz-spinner{width:36px;height:36px;border-radius:50%;background:conic-gradient(#38bdf8,#22d3ee,#10b981,#84cc16,#f59e0b,#ef4444);-webkit-mask:radial-gradient(farthest-side,transparent 60%,#000 61%);mask:radial-gradient(farthest-side,transparent 60%,#000 61%);animation:yuz-spin 1s linear infinite reverse;box-shadow:0 2px 6px rgba(14,165,233,.15);}
@keyframes yuz-spin{to{transform:rotate(-1turn)}}
.yuz-btn.yuz-busy::after{content:'';display:inline-block;width:8px;height:8px;margin-left:8px;border-radius:999px;background:#0ea5e9;animation:yuz-pulse .8s ease-in-out infinite;}
@keyframes yuz-pulse{0%{transform:scale(.75);opacity:.6}50%{transform:scale(1.15);opacity:1}100%{transform:scale(.75);opacity:.6}}
.yuz-caption{margin-top:6px;font-size:12px;color:#64748b;letter-spacing:.01em;}
.yuz-lang-grid{display:grid;grid-template-columns:1fr auto 1fr;gap:18px;align-items:end;}
.yuz-field{display:flex;flex-direction:column;gap:6px;}
.yuz-label{font-size:13px;font-weight:600;color:#0f172a;}
.yuz-textarea{width:100%;min-height:140px;border-radius:14px;border:1px solid rgba(148,163,184,.35);padding:12px 14px;font:500 13px/1.5 'SF Pro Text',system-ui,-apple-system,'Segoe UI',sans-serif;color:#0f172a;background:rgba(248,250,252,.88);resize:vertical;transition:border-color .18s ease,box-shadow .18s ease;writing-mode:horizontal-tb;display:block;}
.yuz-textarea:focus{outline:none;border-color:#0ea5e9;box-shadow:0 0 0 3px rgba(14,165,233,.18);}
.yuz-textarea.is-original{background:rgba(241,245,249,.85);color:#1e293b;}
.yuz-suggestions{margin-top:8px;}
.yuz-suggestions ul{margin:0;padding-left:18px;}
.yuz-suggestions li{margin-bottom:4px;cursor:pointer;color:#0f172a;}
.yuz-suggestions li:hover{text-decoration:underline;}
.yuz-section--lang{display:flex;flex-direction:column;gap:18px;}
.yuz-section--lang .yuz-caption{margin-top:10px;font-size:12px;color:#4b5563;}
.yuz-section--fields{display:flex;flex-direction:column;gap:18px;}
.yuz-translation{display:flex;flex-direction:column;gap:14px;}
.yuz-translation-box{position:relative;}
.yuz-translation-textarea{width:100%;min-height:140px;padding:12px 14px;}
.yuz-actions{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:6px 4px;background:rgba(255,255,255,.6);border-radius:12px;border:1px solid rgba(226,232,240,.7);}
.yuz-actions-left,.yuz-actions-right{display:flex;align-items:center;gap:10px;}
.yuz-actions-left .yuz-btn,.yuz-actions-right .yuz-btn{min-width:max-content;}
.yuz-float-prev,.yuz-float-next{display:none;}
.yuz-section--search{padding:16px 20px;border-top:1px solid rgba(226,232,240,.7);background:rgba(255,255,255,.94);}
.yuz-search{display:flex;align-items:center;gap:10px;}
.yuz-search-input{flex:1 1 auto;padding:.55rem .85rem;border-radius:10px;border:1px solid rgba(148,163,184,.45);font:500 13px/1.4 'SF Pro Text',system-ui,-apple-system,'Segoe UI',sans-serif;color:#0f172a;background:rgba(255,255,255,.95);}
.yuz-search-input:focus{outline:none;border-color:#0ea5e9;box-shadow:0 0 0 3px rgba(14,165,233,.18);}
.yuz-search-loading{margin-left:auto;font-size:12px;color:#475569;opacity:.75;}
.yuz-empty{padding:22px 20px;color:#0b7285;font:600 14px/1.45 'SF Pro Text',system-ui,-apple-system,'Segoe UI',sans-serif;text-align:center;}
.yuz-body textarea{cursor:text;}
body.yuz-inspector-active #yuz-editor-container .yuz-modal{margin-right:320px;transition:margin-right .25s ease;}
.yuz-inspector-dock{position:fixed;top:24px;right:-320px;width:300px;max-width:90vw;height:calc(100vh - 48px);background:rgba(15,23,42,.92);color:#f8fafc;border-radius:16px 0 0 16px;box-shadow:-20px 0 40px rgba(15,23,42,.35);display:flex;flex-direction:column;gap:14px;padding:18px 20px;pointer-events:auto;z-index:2147483647;transition:right .25s ease;}
.yuz-inspector-dock.is-visible{right:24px;}
.yuz-inspector-dock__title{font-size:15px;font-weight:600;margin:0;}
.yuz-inspector-dock__controls{display:flex;flex-wrap:wrap;gap:8px;align-items:center;}
.yuz-inspector-dock__close{margin-left:auto;background:#ef4444;color:#fef2f2;border-color:rgba(248,113,113,.25);text-transform:uppercase;font-weight:600;letter-spacing:.03em;}
.yuz-inspector-dock__close:hover{background:#dc2626;color:#fff;}
.yuz-inspector-dock__list{flex:1 1 auto;overflow:auto;list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:10px;}
.yuz-inspector-dock__item{background:rgba(15,23,42,.25);border:1px solid rgba(148,163,184,.25);border-radius:12px;padding:10px;display:flex;flex-direction:column;gap:6px;}
.yuz-inspector-dock__meta{font-size:11px;color:rgba(226,232,240,.75);}
.yuz-inspector-dock__actions{display:flex;gap:8px;}
.yuz-inspector-dock select{background:rgba(17,24,39,.9);color:#e2e8f0;border:1px solid rgba(148,163,184,.35);border-radius:10px;padding:.45rem .6rem;font:500 12px/1.2 'SF Pro Text',system-ui,-apple-system,'Segoe UI',sans-serif;}
/* Hide language switchers while edit overlay is active */
html[data-yuz-edit="1"] .yuz-language-switcher,
html[data-yuz-edit="1"] .yuz-shortcode-switcher,
html[data-yuz-edit="1"] #yuz-floating-switcher{display:none !important}
@media (max-width: 640px){
  .yuz-header{align-items:flex-start;}
  .yuz-header-row--mode{flex-direction:column;align-items:flex-start;gap:10px;}
  .yuz-header-row--mode .yuz-btn--mode{width:100%;}
  .yuz-header-row--mode .yuz-header-strings{flex:0 0 auto;}
  .yuz-header-row--main{flex-wrap:wrap;gap:10px;}
  .yuz-header-row--main .yuz-btn{flex:1 1 100%;}
  .yuz-actions{gap:8px;padding:4px 2px;}
  .yuz-actions-left{display:none;}
  .yuz-float-prev,.yuz-float-next{display:inline-flex;position:absolute;top:12px;z-index:2;opacity:.75;backdrop-filter:saturate(120%) blur(2px);}
  .yuz-float-prev{left:12px;}
  .yuz-float-next{right:12px;}
  .yuz-translation-textarea{padding-top:52px;}
  .yuz-search{flex-wrap:wrap;gap:8px;}
  .yuz-search-input{flex:1 1 100%;}
}
@media (max-width: 1024px){
  body.yuz-inspector-active #yuz-editor-container .yuz-modal{margin-right:0;}
  .yuz-inspector-dock.is-visible{right:12px;width:calc(100vw - 36px);}
}
`;
    document.head.appendChild(style);
  }

  function ensureContainer(id = 'yuz-editor-container') {
    ensureOverlayStyles();
    let el = document.getElementById(id);
    if (!el) {
      el = document.createElement('div');
      el.id = id;
      document.body.appendChild(el);
    }
    el.style.position = 'fixed';
    el.style.inset = '0';
    el.style.zIndex = '2147483647';
    el.style.pointerEvents = 'auto';
    el.removeAttribute('aria-hidden');
    return el;
  }

  // Fallback modals en mode édition front (clar-home) quand yuz-forms n'est pas chargé.
  function ensureEditModeModalFallback() {
    try {
      const isEditMode = /[?&]yuz-edit-translation=1\b/.test(window.location.search || '');
      const body = document.body;
      if (!isEditMode || !body || !body.classList.contains('clar-home')) return;

      const wf = (window.yuzForms = window.yuzForms || {});
      wf.modals = Array.isArray(wf.modals) ? wf.modals : [];

      const mustHave = [
        { id: 'inscriptionModal', trigger: '#openQuickSignup', close: '.modal-close', label: 'Quick Signup' },
        { id: 'quickDetailsModal', trigger: '#openQuickDetails', close: '#closeQuickDetails', label: 'Quick Details' }
      ];
      mustHave.forEach((cfg) => {
        if (!cfg.id) return;
        const exists = wf.modals.find((m) => m && m.id === cfg.id);
        if (!exists) wf.modals.push(cfg);
      });

      if (typeof wf.openModal !== 'function') {
        wf.openModal = function (modalId) {
          const modal = document.getElementById(modalId);
          if (!modal) return;
          const overlay = modal.closest('.modal-overlay');
          const show = (el) => {
            if (!el) return;
            el.style.display = 'flex';
            el.style.visibility = 'visible';
            el.style.opacity = '1';
            el.style.pointerEvents = 'auto';
            el.classList.add('active');
          };
          show(overlay);
          show(modal);
        };
      }

      if (typeof wf.closeModal !== 'function') {
        wf.closeModal = function (modalId) {
          const modal = document.getElementById(modalId);
          if (!modal) return;
          const overlay = modal.closest('.modal-overlay');
          const hide = (el) => {
            if (!el) return;
            el.classList.remove('active');
            el.style.display = '';
            el.style.visibility = '';
            el.style.opacity = '';
            el.style.pointerEvents = '';
          };
          hide(modal);
          hide(overlay);
        };
      }

      if (typeof wf.initModals !== 'function') {
        wf.initModals = function () {
          this.modals.forEach((m) => {
            if (!m || !m.id) return;
            const triggerSel = m.trigger;
            const closeSel = m.close;
            const modal = document.getElementById(m.id);
            const bindOnce = (node, type, handler, key) => {
              if (!node) return;
              const flag = `data-yuz-modal-bound-${key || type}`;
              if (node.getAttribute(flag)) return;
              node.setAttribute(flag, '1');
              node.addEventListener(type, handler);
            };
            if (triggerSel) {
              document.querySelectorAll(triggerSel).forEach((node) =>
                bindOnce(node, 'click', (e) => { try { e.preventDefault(); } catch (_) { } this.openModal(m.id); }, 'trigger')
              );
            }
            if (modal && closeSel) {
              const closeNode = modal.querySelector(closeSel);
              bindOnce(closeNode, 'click', (e) => { try { e.preventDefault(); } catch (_) { } this.closeModal(m.id); }, 'close');
              bindOnce(modal, 'click', (e) => { if (e.target === modal) this.closeModal(m.id); }, 'backdrop');
            }
          });
        };
      }

      wf.initModals();
    } catch (err) {
    }
  }

  // -------------------------------
  // Vue app (Vue importée)
  // -------------------------------
  const mountApp = (options = {}) => {
    ensureEditModeModalFallback();
    ensureContainer();

    const app = new Vue({
      el: '#yuz-editor-container',
      data() {
        return {
          // --- UI / modes ---
          modeOptions: MODE_CHOICES,
          activeMode: INITIAL_MODE,
          isLocked: false,
          layout: 'detailed',
          ADV: true, // utilisé dans le template (bouton Reset)

          // --- langues & items ---
          items: [],
          index: -1,
          languages: [],
          languageNames: {},
          sourceLanguage: (window.yuzTraSettings?.source_language || '').replace('-', '_'),
          currentLanguage: '',
          fromLanguages: [],
          toLanguages: [],
          fromChoices: [],
          toChoices: [],

          // --- état / chargement ---
          // ⚠️ passe en booléen (au lieu d’un compteur)
          loading: false,
          booting: false,
          error: '',
          emptyState: '',
          urlToLoad: canonicalPageUrl(window.location.href),

          // --- édition & suggestions ---
          dirtySet: new Set(),
          suggestions: [],
          autoBatch: { total: 0, done: 0, skipped: 0, running: false, percent: 0 },

          // --- recherche (mode simple : 1 bouton) ---
          query: '',
          totalCount: 0,
          hasPagination: false,     // force l’UI “un seul bouton”
          canPrev: false,           // conservés pour compat
          canNext: false,           // idem
          searchResults: [],
          searchSnapshot: '',
          _debouncedSearch: null,
          _lastTicket: 0,

          // --- placeholders legacy pour compat éventuelle ---
          phaseOrder: ['page'],
          phaseIndex: 0,
          pageByPhase: { page: 0, instant: 0 },
          totalByPhase: { page: 0, instant: 0 },
          hasMoreByPhase: { page: false, instant: false },
          previewActive: false,

          // --- inspector bridge ---
          inspectorActive: false,
          inspectorLoading: false,
          diagnostics: {
            open: false,
            reason: '',
            target: '',
            updatedAt: '',
            unmapped: [],
            missing: [],
            autoErrors: []
          },
        };
      },

      computed: { // ici
        panelMode() {
          const mode = canonicalMode(this.activeMode || 'advanced');
          return mode === 'xpress-progress' ? 'xpress' : mode;
        },
        currentModeLabel() {
          const list = Array.isArray(this.modeOptions) && this.modeOptions.length ? this.modeOptions : MODE_CHOICES;
          const current = canonicalMode(this.activeMode || 'advanced');
          const hit = list.find(opt => {
            const normalized = canonicalMode(opt.value);
            return normalized === current || (current === 'xpress-progress' && normalized === 'xpress');
          });
          return hit ? hit.label : 'Advanced';
        },
        nextModeLabel() {
          const list = Array.isArray(this.modeOptions) && this.modeOptions.length ? this.modeOptions : MODE_CHOICES;
          const current = canonicalMode(this.activeMode || 'advanced');
          const idx = list.findIndex(opt => {
            const normalized = canonicalMode(opt.value);
            return normalized === current || (current === 'xpress-progress' && normalized === 'xpress');
          });
          const safeIdx = idx >= 0 ? idx : 0;
          const next = list[(safeIdx + 1) % list.length];
          return next ? next.label : '';
        },
        modeCycleTooltip() {
          const next = this.nextModeLabel;
          return next ? `Switch mode (next: ${next})` : 'Switch mode';
        },
        lockLabel() {
          return this.isLocked ? 'Unlock page' : 'Lock page';
        },
        lockTooltip() {
          return this.isLocked
            ? 'Unlock the page to resume interacting with the content behind the editor.'
            : 'Lock the page to freeze the underlying page while you translate.';
        },
        canInvert() {
          return !!CAN_SWAP_LANG;
        },
        swapTooltip() {
          return this.canInvert
            ? 'Swap source and target languages.'
            : 'Source and target roles are locked. Update language settings to change roles.';
        },
        previewLabel() {
          return this.previewActive ? 'Preview (On)' : 'Preview (Off)';
        },
        previewTooltip() {
          return this.previewActive
            ? 'Disable automatic preview redirect.'
            : 'Enable automatic preview redirect.';
        },
        current() {
          return (this.index >= 0 && this.index < this.items.length) ? this.items[this.index] : null;
        },
        editedValue: {
          get() {
            if (!this.current) return '';
            const t = (this.current.translations || {})[this.getCurrentLanguage()] || {};
            return t.edited != null ? t.edited : (t.translated || '');
          },
          set(val) {
            if (!this.current) return;
            const code = this.getCurrentLanguage();
            this.ensureTranslationEntry(
              this.current,
              code,
              { edited: val },
              { autoStatus: '1', textForStatus: val }
            );
            this.dirtySet.add(this.current.id);
            if (this.throttledSuggest) this.throttledSuggest();
            if (this.throttledSaveOne) this.throttledSaveOne();
          }
        },
        progress() {
          const basePercent = (() => {
            if (!this.items.length || !this.languages.length || !this.getCurrentLanguage()) return 0;
            const code = this.getCurrentLanguage();
            const num = this.items.reduce((acc, it) => {
              const t = (it.translations || {})[code];
              if (!t) return acc;
              const rawStatus = t.status != null ? String(t.status) : '';
              if (rawStatus === '1' || rawStatus === '2') return acc + 1;
              const edited = t.edited != null ? String(t.edited).trim() : '';
              const translated = t.translated != null ? String(t.translated).trim() : '';
              return (edited || translated) ? acc + 1 : acc;
            }, 0);
            return Math.round((num / this.items.length) * 100);
          })();

          const batchPercent = this.autoBatch ? (this.autoBatch.percent || 0) : 0;
          if (this.autoBatch && this.autoBatch.running) {
            return batchPercent;
          }
          return Math.max(basePercent, batchPercent);
        },
        searchIndicator() {
          if (!this.searchResults.length) return '';
          const current = this.searchIndex >= 0 ? this.searchIndex + 1 : 0;
          return `${current}/${this.searchResults.length}`;
        }
      },
      created() {
        // Build throttled funcs (déjà utilisées ailleurs)
        this.throttledSuggest = throttle(() => {
          if (!this.current) return;
          this.fetchSuggestions(this.current.original);
        }, 500);

        this.throttledSaveOne = debounce(() => {
          if (!this.current) return;
          this.saveOne(this.current);
        }, 1200);

        // ✅ debounce de la recherche (Enter + bouton → même chemin)
        this._debouncedSearch = debounce(() => this._doSearch(), 250);

        // Inspector internals
        this._unmappedMap = new Map();
        this._origIndex = new Map();
        this._domBridgeBound = false;
        this._boundDomScan = null;
        this._inspectorPromise = null;
        this._inspectorBridge = null;
      },
      mounted() {
        this.updateMaskLock();
        this.restorePosition();
        this.setupDragHandle();
        this.$nextTick(() => this.setupDragHandle());
        this.setupInstantMode();
        this.$nextTick(() => this.setupInstantMode());
        this.bindKeys();
        this.setupDomBridge();
        this.initPreviewSetting();
        if (!HAS_TARGETS) {
          this.booting = false;
          this.loading = 0;
          this.error = EMPTY_MESSAGE;
          this.isLocked = true;
          return;
        }
        this.bootstrap();
      },
      beforeDestroy() {
        this.detachDragHandle();
        this.detachInstantMode();
        this.teardownDomBridge();
      },
      methods: {
        search() {
          if (typeof this.onSearchClick === 'function' && this.onSearchClick !== this.search) {
            this.onSearchClick();
            return;
          }
          if (typeof this.smartSearchStart === 'function') {
            this.smartSearchStart();
            return;
          }
          if (typeof this._debouncedSearch === 'function') {
            this._debouncedSearch();
          }
        },

        // Optional probe hook to trace inspector pipeline without flooding logs
        inspectorProbe(event, detail = {}) {
          try {
            const tap = window.__YUZ_INSPECTOR_PROBE || window.YUZ_INSPECTOR_PROBE;
            if (typeof tap === 'function') tap(event, detail);
          } catch (_) { /* silent */ }
        },

        toggleInspector() {
          if (this.inspectorLoading) return;
          if (this.inspectorActive) {
            this.disableInspector();
          } else {
            this.enableInspector();
          }
        },

        enableInspector() {
          if (this.inspectorActive || this.inspectorLoading) return;
          this.inspectorLoading = true;
          this.ensureInspectorModule()
            .then((mod) => {
              if (!mod || typeof mod.create !== 'function') {
                throw new Error('Inspector module unavailable');
              }
              if (!this._inspectorBridge) {
                this._inspectorBridge = mod.create(this);
              }
              if (this._inspectorBridge && typeof this._inspectorBridge.enable === 'function') {
                this._inspectorBridge.enable();
              }
              this.inspectorActive = true;
              this.setupDomBridge();
              this.inspectorProbe('enable', { modals: this._inspectorBridge?.modals?.length, language: this.getCurrentLanguage() || '' });
            })
            .catch((err) => {
              toast('Inspector unavailable for this page.', 'warning');
              this.inspectorProbe('enable_fail', { error: err && err.message ? err.message : String(err || '') });
            })
            .finally(() => {
              this.inspectorLoading = false;
            });
        },

        disableInspector() {
          if (!this.inspectorActive) return;
          if (this._inspectorBridge && typeof this._inspectorBridge.disable === 'function') {
            try { this._inspectorBridge.disable(); } catch (_) { }
          }
          this.clearInspectorHighlights();
          this.inspectorActive = false;
          this.updateDiagnostics('inspector-off', { unmapped: [] });
          if (this._inspectorBridge && typeof this._inspectorBridge.update === 'function') {
            try { this._inspectorBridge.update({ unmapped: [], missing: [] }); } catch (_) { }
          }
          this.inspectorProbe('disable', {});
          // Keep the base DOM bridge alive so manual selection still works without the inspector
          this.mapDomToItems();
        },

        ensureInspectorModule() {
          if (window.YUZInspector && typeof window.YUZInspector.create === 'function') {
            return Promise.resolve(window.YUZInspector);
          }
          if (this._inspectorPromise) {
            return this._inspectorPromise;
          }
          const url = this.getInspectorAssetUrl();
          if (!url) {
            return Promise.reject(new Error('Inspector asset URL unavailable'));
          }
          this._inspectorPromise = new Promise((resolve, reject) => {
            const existing = document.querySelector('script[data-yuz-inspector="1"]');
            if (existing && existing.dataset.yuzLoaded === '1') {
              resolve(window.YUZInspector);
              return;
            }
            const script = existing || document.createElement('script');
            script.src = url;
            script.async = true;
            script.dataset.yuzInspector = '1';
            const onLoad = () => {
              script.dataset.yuzLoaded = '1';
              resolve(window.YUZInspector);
            };
            const onError = (err) => {
              reject(err || new Error('Inspector asset failed to load'));
            };
            script.addEventListener('load', onLoad, { once: true });
            script.addEventListener('error', onError, { once: true });
            if (!existing) {
              try {
                let nonce = null;
                const clar = window.CLAR;
                if (clar && clar.cspNonce) {
                  nonce = clar.cspNonce;
                } else {
                  const carrier = document.querySelector('script[nonce]');
                  if (carrier) {
                    nonce = carrier.getAttribute('nonce') || carrier.nonce || null;
                  }
                  if (!nonce) {
                    const meta = document.querySelector('meta[http-equiv="Content-Security-Policy"]');
                    if (meta && typeof meta.content === 'string') {
                      const match = meta.content.match(/'nonce-([^']+)'/);
                      if (match && match[1]) {
                        nonce = match[1];
                      }
                    }
                  }
                }
                if (nonce) {
                  script.setAttribute('nonce', nonce);
                }
              } catch (_) { }
              document.head.appendChild(script);
            } else if (existing.dataset.yuzLoaded === '1') {
              onLoad();
            }
          }).finally(() => {
            this._inspectorPromise = null;
          });
          return this._inspectorPromise;
        },

        getInspectorAssetUrl() {
          try {
            const script = document.querySelector('script[src*="yuz-translation-editor"]');
            if (script && script.src) {
              const base = script.src.replace(/yuz-translation-editor(?:\\.min)?\\.js.*$/i, '');
              if (base) {
                return base + 'inspector/yuz-inspector.js';
              }
            }
          } catch (_) { }
          const fallback =
            (window.yuzTraSettings && (window.yuzTraSettings.assets_base || window.yuzTraSettings.assetsBase)) || '';
          if (fallback) {
            return fallback.replace(/\/$/, '') + '/js/widgets/inspector/yuz-inspector.js';
          }
          return '/wp-content/plugins/yuz-tra/assets/js/widgets/inspector/yuz-inspector.js';
        },

        async _doSearch() {
          if (!this.query || this.loading) return;
          this.loading = true;
          const ticket = ++this._lastTicket;

          try {
            const params = { q: this.query, scope: 'page' };
            const res = await (
              this.$yuz?.api?.search
                ? this.$yuz.api.search(params)
                : ajaxPromise('yuz_tra_tm_search', { ...params, limit: 50, index: 0 })
            );

            if (ticket !== this._lastTicket) return;

            this.searchSnapshot = this.query;
            this.totalCount =
              (typeof res?.total === 'number' ? res.total : 0) ||
              (typeof res?.data?.total === 'number' ? res.data.total : 0);

            const rows = Array.isArray(res?.results)
              ? res.results
              : (Array.isArray(res?.data?.results) ? res.data.results : []);

            this.searchResults = rows;

            this.totalByPhase.page = this.totalCount;
            this.totalByPhase.instant = 0;
            this.pageByPhase.page = 0;
            this.pageByPhase.instant = 0;
            this.hasMoreByPhase.page = false;
            this.hasMoreByPhase.instant = false;
            this.canPrev = false;
            this.canNext = false;

            if (rows && rows.length) this.focusSearchHit(rows[0]);
          } catch (e) {
            this.error = 'Search failed';
          } finally {
            if (ticket === this._lastTicket) this.loading = false;
          }
        },

        ensureTranslationEntry(item, code, patch = {}, options = {}) {
          if (!item) return null;
          if (!item.translations || typeof item.translations !== 'object') {
            item.translations = {};
          }
          const current = item.translations[code] && typeof item.translations[code] === 'object'
            ? item.translations[code]
            : {};
          const next = { ...current, ...patch };

          const statusPref = options.setStatus !== undefined
            ? options.setStatus
            : (patch.status !== undefined ? patch.status : next.status);

          let status = statusPref;
          if (status == null || status === '') {
            const seed = options.textForStatus !== undefined
              ? options.textForStatus
              : (next.edited != null ? next.edited : next.translated);
            if (options.autoStatus && seed != null && String(seed).trim().length) {
              status = options.autoStatus;
            }
          }
          if (status == null || status === '') {
            status = '0';
          }
          next.status = String(status);

          if (options.clearEdited) {
            delete next.edited;
          }

          // Vue 3 reactive objects: direct assign is fine
          item.translations[code] = next;
          return next;
        },

        setupDragHandle() {
          this.detachDragHandle();
          const modalEl = this.$refs.modal || document.querySelector('#yuz-editor-container .yuz-modal');
          const header = modalEl?.querySelector('.yuz-header');
          if (!modalEl || !header) return;
          const state = { grabbing: false, startX: 0, startY: 0, originX: 24, originY: 24 };
          const clamp = (value, min, max) => Math.max(min, Math.min(max, value));
          const savePosition = () => {
            try {
              localStorage.setItem('yuz.modal.left', parseInt(modalEl.style.left, 10) || 24);
              localStorage.setItem('yuz.modal.top', parseInt(modalEl.style.top, 10) || 24);
            } catch (_) { }
          };
          const onPointerDown = (e) => {
            if (typeof e.button === 'number' && e.button !== 0) return;
            const interactive = e.target.closest('button, [role="button"], input, select, textarea, a');
            if (interactive && interactive !== header) return;
            state.grabbing = true;
            state.startX = e.clientX;
            state.startY = e.clientY;
            const rect = modalEl.getBoundingClientRect();
            state.originX = rect.left;
            state.originY = rect.top;
            if (typeof header.setPointerCapture === 'function') {
              try { header.setPointerCapture(e.pointerId); } catch (_) { }
            }
          };
          const onPointerMove = (e) => {
            if (!state.grabbing) return;
            const nextX = state.originX + (e.clientX - state.startX);
            const nextY = state.originY + (e.clientY - state.startY);
            const maxX = Math.max(8, window.innerWidth - modalEl.offsetWidth - 8);
            const maxY = Math.max(8, window.innerHeight - modalEl.offsetHeight - 8);
            modalEl.style.left = clamp(nextX, 8, maxX) + 'px';
            modalEl.style.top = clamp(nextY, 8, maxY) + 'px';
            modalEl.style.right = '';
            modalEl.style.bottom = '';
          };
          const onPointerEnd = (e) => {
            if (!state.grabbing) return;
            state.grabbing = false;
            if (typeof header.releasePointerCapture === 'function') {
              try { header.releasePointerCapture(e.pointerId); } catch (_) { }
            }
            savePosition();
          };
          header.addEventListener('pointerdown', onPointerDown);
          header.addEventListener('pointermove', onPointerMove);
          header.addEventListener('pointerup', onPointerEnd);
          header.addEventListener('pointercancel', onPointerEnd);
          this._dragHandle = { header, onPointerDown, onPointerMove, onPointerEnd };
        },
        detachDragHandle() {
          const bind = this._dragHandle;
          if (!bind || !bind.header) return;
          bind.header.removeEventListener('pointerdown', bind.onPointerDown);
          bind.header.removeEventListener('pointermove', bind.onPointerMove);
          bind.header.removeEventListener('pointerup', bind.onPointerEnd);
          bind.header.removeEventListener('pointercancel', bind.onPointerEnd);
          this._dragHandle = null;
        },
        setupInstantMode() {
          this.detachInstantMode();
          const modalEl = this.$refs.modal || document.querySelector('#yuz-editor-container .yuz-modal');
          if (!modalEl) return;
          this._instantCleanup = installInstantMode(modalEl);
        },
        detachInstantMode() {
          if (typeof this._instantCleanup === 'function') {
            try { this._instantCleanup(); }
            catch (_) { }
          }
          this._instantCleanup = null;
        },
        cycleMode() {
          if (!Array.isArray(this.modeOptions) || !this.modeOptions.length) return;
          const current = canonicalMode(this.activeMode || 'advanced');
          let index = this.modeOptions.findIndex(opt => {
            const normalized = canonicalMode(opt.value);
            return normalized === current || (current === 'xpress-progress' && normalized === 'xpress');
          });
          if (index < 0) index = 0;
          const next = this.modeOptions[(index + 1) % this.modeOptions.length];
          this.switchMode(next.value);
        },
        toggleLayout() { this.layout = this.layout === 'detailed' ? 'compact' : 'detailed'; },
        // Refresh: re-bootstrap strings and languages
        refresh() { this.bootstrap(); },

        // Inverse From/To
        invertLangs() {
          if (!this.canInvert) return;
          const cur = this.getCurrentLanguage();
          const src = this.sourceLanguage || 'auto';
          if (!cur) return;
          this.sourceLanguage = cur;
          this.currentLanguage = src === 'auto' ? cur : src; // if auto, keep cur as target
        },

        buildSavePayload(item, code, translatedText, options = {}) {
          if (!item || !code) return null;

          const baseTranslationId = options.translationId != null
            ? toInt(options.translationId)
            : toInt(item.translation_id || item.dbID || 0);
          const postId = toInt(item.post_id || 0);
          const context = (item.context || 'content').toString();
          const blockId = item.block_id != null ? String(item.block_id) : '';
          const originalText = options.originalText != null ? options.originalText : (item.original || '');
          const sourceCode = options.sourceCode || this.sourceLanguage || CFG.source_language || CFG.default_language || '';
          const pageUrl = this.normalizePageUrl(options.pageUrl || item.page_url || this.urlToLoad);
          const originHint = (options.origin || '').toLowerCase();
          const defaultStatus = originHint === 'machine' ? 2 : 4;
          const statusCandidate = options.status != null ? parseInt(options.status, 10) : defaultStatus;
          const status = Number.isNaN(statusCandidate) || statusCandidate < 0 ? defaultStatus : statusCandidate;

          const normalizeLocale = (value) => {
            if (!value) return '';
            const raw = String(value).trim();
            if (!raw) return '';
            return raw
              .replace(/-/g, '_')
              .split('_')
              .filter(Boolean)
              .map((part, index) => (index === 0 ? part.toLowerCase() : part.toUpperCase()))
              .join('_');
          };

          const fallbackSource = CFG.source_language || CFG.default_language || 'fr_FR';
          const normalizedSource = (() => {
            const candidate = sourceCode && sourceCode !== 'auto' ? sourceCode : fallbackSource;
            const normalized = normalizeLocale(candidate);
            if (normalized && normalized !== 'auto') return normalized;
            const fallbackNormalized = normalizeLocale(fallbackSource);
            return fallbackNormalized && fallbackNormalized !== 'auto' ? fallbackNormalized : fallbackSource;
          })();

          const rawTargetOption =
            options.targetLangs ??
            options.target_langs ??
            options.targetLang ??
            options.target_lang ??
            options.to ??
            null;

          let targetLangs = Array.isArray(rawTargetOption)
            ? rawTargetOption.slice()
            : (rawTargetOption != null ? [rawTargetOption] : []);
          if (!targetLangs.length && code && code !== 'auto') {
            targetLangs = [code];
          }
          targetLangs = targetLangs
            .map(value => normalizeLocale(value))
            .filter(lang => lang && lang !== 'auto');

          const uniqueTargetLangs = [];
          targetLangs.forEach(lang => {
            if (!uniqueTargetLangs.includes(lang)) uniqueTargetLangs.push(lang);
          });
          if (!uniqueTargetLangs.length) {
            const fallbackTargetList = Array.isArray(CFG.translation_langs) ? CFG.translation_langs : [];
            const fallbackTarget = fallbackTargetList.length ? normalizeLocale(fallbackTargetList[0]) : '';
            if (fallbackTarget && fallbackTarget !== 'auto') {
              uniqueTargetLangs.push(fallbackTarget);
            }
          }
          if (!uniqueTargetLangs.length) {
            uniqueTargetLangs.push('en_US');
          }
          const primaryTarget = uniqueTargetLangs[0] || '';

          const normalizedTarget = normalizeLocale(primaryTarget || code);
          const resolvedTranslationId = baseTranslationId > 0
            ? baseTranslationId
            : pickTranslationId(item, normalizedTarget || code);

          const payloadOrigin = canonicalOrigin(options.origin || 'manual');
          const payload = {
            string_id: resolvedTranslationId,
            language_code: normalizedTarget || code,
            translated_text: translatedText != null ? translatedText : '',
            original_text: originalText,
            post_id: postId,
            context,
            block_id: blockId,
            page_url: pageUrl,
            status,
            origin: payloadOrigin,
            target_langs: uniqueTargetLangs
          };

          if (normalizedSource) {
            payload.source_lang = normalizedSource;
            payload.source_lang_code = normalizedSource;
          }
          if (item.source_lang_id) {
            payload.source_lang_id = toInt(item.source_lang_id);
          }
          if (item.target_lang_id) {
            payload.target_lang_id = toInt(item.target_lang_id);
          }

          if (typeof options.isBatch !== 'undefined') {
            payload.is_batch = options.isBatch ? '1' : '0';
          }

          return payload;
        },

        saveTranslationForItem(item, code, translatedText, options = {}) {
          let translationId = pickTranslationId(item, code);
          if (options.forceCreate) {
            translationId = 0;
          }
          const payload = this.buildSavePayload(item, code, translatedText, {
            ...options,
            translationId
          });
          if (!payload) return Promise.reject(new Error('invalid_payload'));

          const hasId = translationId > 0;

          diagProbe('saveTranslation.start', {
            itemId: item?.id || null,
            translationId,
            code,
            status: payload?.status,
            origin: payload?.origin || options.origin || null,
            context: payload?.context || null,
            original_len: (payload?.original_text || '').length,
            translated_len: (payload?.translated_text || '').length,
            forceCreate: !!options.forceCreate
          });

          const doSave = () => ajaxPromise(ACTION.TE_SAVE, payload).then(r => {
            if (!r || !r.success) throw (r?.data?.message || r?.message || 'save_failed');
            const savedId = toInt(r?.data?.id || r?.data?.ID || translationId);
            if (savedId) {
              item.translation_id = savedId;
              if (!item.id) item.id = String(savedId);
            }
            this.ensureTranslationEntry(
              item,
              code,
              { translated: payload.translated_text, translation_id: savedId || translationId },
              { setStatus: String(payload.status ?? '2'), clearEdited: true, textForStatus: payload.translated_text }
            );
            this.dirtySet.delete(item.id);
            const payloadOrigin = canonicalOrigin(payload.origin || options.origin || 'manual');
            const targetId = savedId || translationId || pickTranslationId(item, code);
            if (payloadOrigin === 'manual' && targetId) {
              try {
                document.dispatchEvent(new CustomEvent('yuz:manualEdit', {
                  detail: {
                    id: String(targetId),
                    lang: code,
                    context: payload.context || ''
                  }
                }));
              } catch (err) {
                // no-op
              }
            }
            diagProbe('saveTranslation.ok', {
              itemId: item?.id || null,
              translationId: savedId || translationId || null,
              code,
              status: payload?.status,
              origin: payload?.origin || options.origin || null
            });
            return r;
          });

          if (hasId) return doSave();

          // Pas d’ID → on crée la string côté serveur, puis on sauvegarde la traduction
          return ajaxPromise(ACTION.TE_CREATE, {
            text: payload.original_text,          // <-- exigé par le PHP
            target_langs: [code],                 // <-- exigé par le PHP (array)
            page_url: payload.page_url,
            context: payload.context,
            block_id: payload.block_id,
            source_lang: payload.source_lang || 'auto',
            status: payload.status
          }).then(cr => {
            if (!cr || !cr.success) throw (cr?.data?.message || 'create_failed');
            const newId = toInt(cr?.data?.id || cr?.data?.ID);
            if (!newId) throw 'create_no_id';
            item.translation_id = newId;
            item.id = String(newId);
            translationId = newId;
            payload.string_id = newId; // alimente le payload pour TE_SAVE
            diagProbe('saveTranslation.created_string', {
              newId,
              code,
              context: payload?.context || null,
              original_len: (payload?.original_text || '').length
            });
            return doSave();
          });
        },

        publishTarget(targetLang) {
          if (!targetLang) return Promise.resolve();
          return ajaxPromise(ACTION.TE_PUBLISH, {
            target_lang: targetLang,
            page_url: this.normalizePageUrl(this.urlToLoad),
            origin: 'manual'
          });
        },

        // Publish current item (int)
        publishOne() {
          if (!this.current) return;
          const code = this.getCurrentLanguage();
          this.loading++;
          this.publishTarget(code).then(() => {
            const t = (this.current.translations || {})[code] || {};
            const text = t.edited != null ? t.edited : (t.translated || '');
            this.ensureTranslationEntry(
              this.current,
              code,
              { translated: text },
              { setStatus: '2', clearEdited: true, textForStatus: text }
            );
            this.dirtySet.delete(this.current.id);
            toast('Publié.', 'success');
          }).catch(() => {
            toast('Échec de la publication.', 'error');
          }).finally(() => {
            this.loading = Math.max(0, this.loading - 1);
          });
        },

        // Preview in new tab (computed URL with target_lang)
        previewCurrent() {
          try {
            const url = new URL(this.urlToLoad, window.location.origin);
            const code = this.getCurrentLanguage();
            url.searchParams.set('yuz-target-lang', code);
            window.open(url.toString(), '_blank', 'noopener');
          } catch (e) { }
        },

        // Reset overlay position
        resetPosition() {
          const el = this.$refs.modal || document.querySelector('#yuz-editor-container .yuz-modal');
          if (!el) return;
          el.style.left = '24px';
          el.style.top = '24px';
          el.style.right = '';
          el.style.bottom = '';
          try {
            localStorage.removeItem('yuz.modal.left');
            localStorage.removeItem('yuz.modal.top');
          } catch (_) { }
        },

        restorePosition() {
          const el = this.$refs.modal || document.querySelector('#yuz-editor-container .yuz-modal');
          if (!el) return;
          const l = parseInt(localStorage.getItem('yuz.modal.left'), 10);
          const t = parseInt(localStorage.getItem('yuz.modal.top'), 10);
          if (!Number.isNaN(l)) el.style.left = l + 'px';
          if (!Number.isNaN(t)) el.style.top = t + 'px';
        },

        toggleLock() {
          this.isLocked = !this.isLocked;
          this.updateMaskLock();
        },

        onFromChanged(val) {
          const v = norm(val);
          this.sourceLanguage = v;
          const TGTS = Array.isArray(CFG.translation_langs) ? CFG.translation_langs.map(norm) : [];
          this.toLanguages = TGTS.filter(c => c !== v);
          if (!this.toLanguages.includes(this.currentLanguage)) {
            this.changeLang(this.toLanguages[0] || '');
          }
        },

        updateMaskLock() {
          const mask = document.querySelector('#yuz-editor-container .yuz-mask');
          if (mask) {
            mask.style.pointerEvents = this.isLocked ? 'auto' : 'none';
          }
        },

        placeholderUndo() {
          toast('Undo will be available soon.', 'info');
        },

        ensureDefaultFromOption() {
          try {
            const DEFAULT = norm(CFG.default_language || '');
            if (!DEFAULT) return;

            const hasChoice = this.fromChoices.some(choice => norm(choice.value) === DEFAULT);
            if (hasChoice) return;

            const hasTranslation = this.items.some(item => {
              const entry = (item && item.translations) ? item.translations[DEFAULT] : null;
              if (!entry) return false;
              const text = entry.edited != null ? entry.edited : (entry.translated || entry.machine || entry.value || '');
              return !!String(text).trim();
            });
            if (!hasTranslation) return;

            const idx = (CFG.lang_index && CFG.lang_index[DEFAULT]) || {};
            const label = idx.name || idx.label || DEFAULT;
            const flag = idx.flag || idx.icon || '';

            const list = [...this.fromChoices, { value: DEFAULT, label, flag }];
            const seen = new Set();
            const deduped = [];
            list.forEach(choice => {
              const key = choice && choice.value ? norm(choice.value) : '';
              if (!key || seen.has(key)) return;
              seen.add(key);
              deduped.push(choice);
            });
            this.fromChoices = deduped;

            if (!this.fromLanguages.some(lang => norm(lang) === DEFAULT)) {
              this.fromLanguages = [...this.fromLanguages, DEFAULT];
            }

            this.toChoices = this.toChoices.filter(choice => norm(choice.value) !== DEFAULT);
          } catch (error) {
          }
        },

        initPreviewSetting() {
          let stored = null;
          try {
            stored = localStorage.getItem(PREVIEW_STORAGE_KEY);
          } catch (_) { }
          if (stored === '1' || stored === 'true') {
            this.previewActive = true;
          }
          this.emitPreviewStatus('init');
        },

        togglePreview() {
          this.previewActive = !this.previewActive;
          this.persistPreviewSetting();
          this.emitPreviewStatus('toggle');
          toast(
            this.previewActive ? 'Preview enabled.' : 'Preview disabled.',
            this.previewActive ? 'success' : 'info'
          );
          if (this.previewActive) {
            this.triggerPreviewRedirect();
          }
        },

        persistPreviewSetting() {
          try {
            localStorage.setItem(PREVIEW_STORAGE_KEY, this.previewActive ? '1' : '0');
          } catch (_) { }
        },

        emitPreviewStatus(reason = 'update') {
          try {
            window.dispatchEvent(new CustomEvent('yuz:preview_toggle', {
              detail: {
                enabled: this.previewActive,
                reason,
                language: this.getCurrentLanguage(),
                pageUrl: this.normalizePageUrl(this.urlToLoad)
              }
            }));
          } catch (error) {
          }
        },

        triggerPreviewRedirect() {
          const lang = this.getCurrentLanguage();
          if (!lang) return;
          const target = this.computePreviewUrl(lang);
          if (!target || target === this.urlToLoad) {
            return;
          }
          try {
            window.location.href = target;
          } catch (error) {
          }
        },

        computePreviewUrl(lang) {
          try {
            const base = this.urlToLoad || window.location.href.split('#')[0];
            const sw = window.yuzSW || {};
            if (typeof sw.getUrlForLanguage === 'function') {
              const viaSwitcher = sw.getUrlForLanguage(lang, base, {});
              if (viaSwitcher) return viaSwitcher;
            }
            if (typeof sw.get_url_for_language === 'function') {
              const legacy = sw.get_url_for_language(lang, base, {});
              if (legacy) return legacy;
            }
            if (sw.transforms && typeof sw.transforms.build === 'function') {
              const fromTransform = sw.transforms.build(lang, base);
              if (fromTransform) return fromTransform;
            }
            const url = new URL(base, window.location.origin);
            if (url.searchParams.has('lang')) {
              url.searchParams.set('lang', lang);
              return url.toString();
            }
            url.searchParams.set('yuz-target-lang', lang);
            return url.toString();
          } catch (error) {
          }
          return this.urlToLoad;
        },

        closeModal() {
          try {
            // 1) Détruire proprement l’app Vue (évite fuites/handlers résiduels)
            if (this && typeof this.$destroy === 'function') {
              this.$destroy();
            }
          } catch (_) { }

          try {
            // 2) Retirer les conteneurs overlay si présents (#yuz-editor-container et fallback #yuz-te-advanced)
            const nodes = document.querySelectorAll('#yuz-editor-container, #yuz-te-advanced');
            nodes.forEach(n => { if (n && n.parentNode) n.parentNode.removeChild(n); });
          } catch (_) { }

          // 3) Sortir du mode édition: retirer le paramètre de l’URL (sans recharger)
          try {
            const cur = new URL(window.location.href);
            cur.searchParams.delete('yuz-edit-translation');
            cur.searchParams.delete('yuz-edit-translation-url');
            // Met à jour la barre d’adresse; notre listener de "preserve param" ne s’appliquera plus
            window.history.replaceState({}, document.title, cur.href);
          } catch (_) { }

          // 4) État global
          try { window.YUZ_EDITOR_APP = null; } catch (_) { }
          try { currentOverlayMode = null; } catch (_) { }
          try { delete document.documentElement.dataset.yuzEdit; } catch (_) { }

          // 5) Notifier les observateurs éventuels
          try { document.dispatchEvent(new CustomEvent('yuz:ui:unmount')); } catch (_) { }
          // 6) Désactiver la préservation auto du paramètre d’édition
          try {
            if (window.__yuzPreserveParamHandler__) {
              document.removeEventListener('click', window.__yuzPreserveParamHandler__, true);
              window.__yuzPreserveParamHandler__ = null;
            }
          } catch (_) { }
        },

        switchMode(mode) {
          const canonical = canonicalMode(mode);
          if (canonicalMode(this.activeMode) === canonical) return;
          this.activeMode = canonical;
          try { localStorage.setItem('yuz.ui.mode', canonical); } catch (_) { }
          setUiDataset(canonical);
          if (window.YUZ_UI && typeof window.YUZ_UI.switchTo === 'function') {
            window.YUZ_UI.switchTo(canonical);
          } else {
            document.dispatchEvent(new CustomEvent('yuz:ui:mount', { detail: { mode: canonical } }));
          }
        },

        // Utilitaire: récupère la langue courante sans collision nom → propriété vs méthode
        getCurrentLanguage() {
          return this.currentLanguage || (window.yuzTE && window.yuzTE.defaultLang) || '';
        },

        // ---------- Bootstrap ---------- (BLOC C)
        bootstrap() {
          // From/To from server truth
          const DEF = norm(CFG.default_language);
          const SRC = norm(CFG.source_language);
          const FROMS = Array.isArray(CFG.from_options) ? CFG.from_options.map(norm) : [SRC || DEF];
          const FROM = norm(CFG.effective_from || FROMS[0] || DEF);
          const TGTS = Array.isArray(CFG.translation_langs) ? CFG.translation_langs.map(norm) : [];
          // Build labeled choices
          const IDX = CFG.lang_index || {};
          const label = (c) => (IDX[c] && IDX[c].name) ? IDX[c].name : c;
          const FROM_CHOICES = Array.isArray(CFG.from_choices) && CFG.from_choices.length
            ? CFG.from_choices.map(o => ({ value: norm(o.value), label: o.label || label(norm(o.value)), flag: o.flag || '' }))
            : FROMS.map(c => ({ value: c, label: label(c), flag: '' }));
          const TO_CHOICES_ALL = Array.isArray(CFG.to_choices) && CFG.to_choices.length
            ? CFG.to_choices.map(o => ({ value: norm(o.value), label: o.label || label(norm(o.value)), flag: o.flag || '' }))
            : TGTS.map(c => ({ value: c, label: label(c), flag: '' }));
          this.fromLanguages = FROMS;
          this.sourceLanguage = FROM;
          this.fromChoices = FROM_CHOICES;
          this.toLanguages = TGTS.filter(c => c !== this.sourceLanguage);
          this.toChoices = TO_CHOICES_ALL.filter(o => o.value !== this.sourceLanguage);
          if (!this.toLanguages.length) {
            this.booting = false;
            this.loading = 0;
            this.error = '';
            toast(EMPTY_MESSAGE, 'warning');
            return;
          }
          this.booting = true;
          this.loading++;

          const page_url =
            getParam('yuz-edit-translation-url') ||
            (this.urlToLoad || window.location.href.split('#')[0]);

          // Ensure current target is valid and not equal to From
          const target_lang = this.currentLanguage && this.toLanguages.includes(this.currentLanguage)
            ? this.currentLanguage
            : (this.toLanguages[0] || '');

          apiPost(ACTION.TM_GET, {
            page_url,
            bootstrap: 1,
            limit: 50,
            offset: 0,
            target_lang
          })
            .done(r => {
              if (!isOk(r)) throw new Error(r?.data?.message || 'Load failed');
              const data = r.data || {};
              let langs = Array.isArray(data.languages) ? data.languages : (Array.isArray(data.langs) ? data.langs : []);
              if (!Array.isArray(langs) || !langs.length) {
                const CFG = window.yuzTraSettings || {};
                const SGEN = CFG?.settings?.general || {};
                const GEN = CFG?.general || {};

                const fromMeta = Array.isArray(CFG.language_meta)
                  ? CFG.language_meta.filter(meta => meta && meta.code && !meta.is_source)
                  : [];
                if (fromMeta.length) {
                  langs = fromMeta.map(meta => ({
                    language_code: meta.code,
                    language_name: meta.name || meta.code
                  }));
                }

                if (!langs.length && Array.isArray(CFG.translation_langs) && CFG.translation_langs.length) {
                  langs = CFG.translation_langs.map(code => {
                    const idx = (CFG.lang_index && CFG.lang_index[code]) || {};
                    return {
                      language_code: code,
                      language_name: idx.name || idx.label || code
                    };
                  });
                }

                if (!langs.length) {
                  let codes = Array.isArray(SGEN.yuz_tra_translatable_languages)
                    ? SGEN.yuz_tra_translatable_languages.slice()
                    : [];
                  if (!codes.length && Array.isArray(GEN.yuz_tra_translatable_languages)) {
                    codes = GEN.yuz_tra_translatable_languages.slice();
                  }
                  if (!codes.length && Array.isArray(window.__yuz_boot_langs__)) {
                    codes = window.__yuz_boot_langs__.slice();
                  }
                  if (codes.length) {
                    langs = codes.map(code => {
                      const idx = (CFG.lang_index && CFG.lang_index[code]) || {};
                      return {
                        language_code: code,
                        language_name: idx.name || idx.label || code
                      };
                    });
                  }
                }

                if (!langs.length) {
                  const msg = 'Aucune langue cible configurée. Allez dans Admin → YUZ TRA → Languages et cochez « Translatable ».';
                  if (typeof fail === 'function') {
                    return fail('6_container', msg);
                  }
                  this.booting = false;
                  this.loading = 0;
                  this.error = msg;
                  toast(msg, 'warning');
                  return;
                }

              }
              this.languages = langs;
              this.$nextTick(() => {
                const fromSel = document.querySelector('[data-yuz-role="from-lang"]');
                const toSel = document.querySelector('[data-yuz-role="to-lang"]');
                const syncDisable = () => {
                  if (!fromSel || !toSel) return;
                  [...toSel.options].forEach(o => { o.disabled = (o.value === fromSel.value); });
                  if (toSel.value === fromSel.value) {
                    const alt = [...toSel.options].find(o => !o.disabled);
                    if (alt) {
                      toSel.value = alt.value;
                    }
                  }
                };
                if (fromSel && !fromSel.dataset.yuzSyncBound) {
                  fromSel.addEventListener('change', syncDisable, { passive: true });
                  fromSel.dataset.yuzSyncBound = '1';
                }
                if (toSel && !toSel.dataset.yuzSyncBound) {
                  toSel.addEventListener('change', syncDisable, { passive: true });
                  toSel.dataset.yuzSyncBound = '1';
                }
                syncDisable();
              });
              this.languageNames = langs.reduce((acc, l) => (acc[l.language_code] = l.language_name || l.name || l.language_code, acc), {});
              this.currentLanguage = (data.current_language && this.toLanguages.includes(data.current_language))
                ? data.current_language
                : (this.currentLanguage && this.toLanguages.includes(this.currentLanguage) ? this.currentLanguage : (this.toLanguages[0] || ''));
              this.items = normalizeStrings(data)
                .map(it => {
                  const cleaned = stripCssJsNoise(it.original || '');
                  // jette ce qui reste du code/bruit
                  if (!cleaned || yuzLooksLikeCode(cleaned)) return null;
                  return { ...it, original: cleaned }; // on écrase l'original par la version nettoyée
                })
                .filter(Boolean);

              this.items = this.items.filter(it => {
                const sel = String(it.selector_path || '');
                const txt = String(it.original || '');
                // 1) écarte les blocs de recherche
                if (/wp-block-search|search-form|\[role="search"\]/i.test(sel)) return false;
                // 2) évite les libellés génériques de ce bloc
                if (/^\s*(Search|Type here\.\.\.|Rechercher|Recherche)\s*$/i.test(txt)) return false;
                return true;
              });

              const pruned = (data?.strings?.length || data?.results?.length || 0) - this.items.length;
              const IS_DEBUG = /\b(?:\?|&)yuz_debug=1\b/.test(window.location.search);
              if (IS_DEBUG && pruned > 0) { try { yuz_release_console.warn('[YUZ][filter] pruned noisy entries:', pruned); } catch (_) { } }

              this.ensureDefaultFromOption();
              this.index = this.items.length ? 0 : -1;
              log('success', '[YUZ][TE] Bootstrap OK', { langs: this.languages.length, strings: this.items.length });
              this.$nextTick(() => {
                this.mapDomToItems();
                if (autoFlag('auto_translate_on_boot', true)) {
                  this.autoTranslateIfAppropriate();
                }
              });
            })
            .fail(xhr => {
              const status = xhr?.status;
              this.error = status === 401 || status === 403 ? 'Permissions insuffisantes.' : 'Erreur de chargement.';
              log('critical', '[YUZ][TE] Bootstrap error', { status, body: xhr?.responseText?.slice?.(0, 300) });
            })
            .always(() => { this.loading = Math.max(0, this.loading - 1); this.booting = false; });
        },

        // ---------- Navigation ----------
        prev() {
          if (!this.items.length) return;
          this.index = (this.index <= 0) ? this.items.length - 1 : this.index - 1;
          this.suggestions = [];
          this.$nextTick(() => {
            if (autoFlag('auto_translate_on_navigation', true)) {
              this.autoTranslateIfAppropriate();
            }
          });
        },
        next() {
          if (!this.items.length) return;
          this.index = (this.index >= this.items.length - 1) ? 0 : this.index + 1;
          this.suggestions = [];
          this.$nextTick(() => {
            if (autoFlag('auto_translate_on_navigation', true)) {
              this.autoTranslateIfAppropriate();
            }
          });
        },

        // ----- SMART SEARCH (1 bouton, les clics = next) ----- (BLOC D)
        resetSmart(q) {
          this.searchSnapshot = (q || '').trim();

          // phases & pagination
          this.phaseIndex = 0;
          this.pageByPhase.page = 0;
          this.pageByPhase.instant = 0;

          // méta
          this.totalByPhase.page = 0;
          this.totalByPhase.instant = 0;
          this.hasMoreByPhase.page = false;
          this.hasMoreByPhase.instant = false;

          // legacy/compat
          this.searchResults = [];
          this.searchIndex = -1;
          this.searchTotal = 0;
          this.searchHasMore = false;
          this.searchPage = 0;

          this.updateSearchButtons();
        },

        currentPhase() {
          return this.phaseOrder[this.phaseIndex] || 'page'; // ordre défini dans data()
        },

        updateSearchButtons() {
          const ph = this.currentPhase();
          this.canPrev = (this.pageByPhase[ph] > 0) || (this.phaseIndex > 0);
          this.canNext = !!(this.hasMoreByPhase[ph] || (this.phaseIndex < this.phaseOrder.length - 1));
        },

        async runSearchOnce() {
          const q = (this.searchSnapshot || '').trim();
          if (!q) { toast('Enter text to search.', 'info'); return false; }

          const ph = this.currentPhase();              // 'page' ou 'instant'
          const page = this.pageByPhase[ph] | 0;

          const page_url =
            getParam('yuz-edit-translation-url') ||
            (this.urlToLoad || window.location.href.split('#')[0]);

          const target_lang = normalizeTargetLang(
            this.getCurrentLanguage(),
            this.languages,
            [(Y && Y.defaultLang), (Y && Y.site_settings && Y.site_settings.default_lang)]
          );

          this.loading++;
          try {
            const r = await ajaxPromise('yuz_tra_tm_search', {
              q,
              target_lang,
              page_url,
              scope: ph,     // 'page' ou 'instant'
              limit: 50,
              index: page
            });

            if (!r || !r.success || !r.data) throw new Error('search_failed');
            const d = r.data || {};
            const rows = Array.isArray(d.results) ? d.results : [];

            // résultats courants
            this.searchResults = rows;
            this.searchIndex = rows.length ? 0 : -1;

            // méta de la phase courante
            this.totalByPhase[ph] = parseInt(d.total || 0, 10) || 0;
            this.hasMoreByPhase[ph] = !!d.has_more;
            this.pageByPhase[ph] = parseInt(d.index ?? page, 10) || 0;

            // compat UI existante
            this.searchTotal = (this.totalByPhase.page | 0) + (this.totalByPhase.instant | 0);
            this.searchHasMore = this.hasMoreByPhase[ph];
            this.searchPage = this.pageByPhase[ph];

            // focus premier hit
            if (rows.length) this.focusSearchHit(rows[0]);

            this.updateSearchButtons();

            // si rien dans cette phase et plus rien à paginer → bascule auto vers la phase suivante
            if (!rows.length && !this.hasMoreByPhase[ph] && this.phaseIndex < this.phaseOrder.length - 1) {
              this.phaseIndex++;
              return this.runSearchOnce();
            }

            if (!rows.length) toast('No results on this step.', 'info');
            return rows.length > 0;
          } catch (err) {
            log('critical', '[YUZ][TE] Search error', { err });
            toast('Erreur de recherche.', 'error');
            return false;
          } finally {
            this.loading = Math.max(0, this.loading - 1);
          }
        },

        // 1er clic = lance/relance avec la saisie courante ; clics suivants = NEXT (page puis phase)
        onSearchClick() {
          const q = (this.query || '').trim();
          if (!q) { toast('Enter text to search.', 'info'); return; }

          // saisie modifiée → reset + run
          if (q !== this.searchSnapshot) {
            this.resetSmart(q);
            this.runSearchOnce();
            return;
          }

          // même requête → NEXT (avance page, sinon bascule phase, sinon boucle)
          const ph = this.currentPhase();
          if (this.hasMoreByPhase[ph]) {
            this.pageByPhase[ph] += 1;
            this.runSearchOnce();
          } else if (this.phaseIndex < this.phaseOrder.length - 1) {
            this.phaseIndex += 1;
            this.runSearchOnce();
          } else {
            // boucle complète : retour au début
            this.phaseIndex = 0;
            this.pageByPhase.page = 0;
            this.pageByPhase.instant = 0;
            this.runSearchOnce();
          }
        },

        // Entrée = clic intelligent (pas de Shift / navigateSearch)
        onSearchEnter(_e) {
          this.onSearchClick();
        },

        // Bouton Prev “intelligent” — phases 'page' / 'instant'
        prevSmart() {
          if (!this.searchSnapshot) {
            toast('Nothing to go back to. Start a search.', 'info');
            return;
          }

          const ph = this.currentPhase();
          const curPage = this.pageByPhase[ph] | 0;

          if (curPage > 0) {
            this.pageByPhase[ph] = Math.max(0, curPage - 1);
            this.runSearchOnce();
            return;
          }

          if (this.phaseIndex > 0) {
            // recule d’une phase et saute sur sa dernière page connue si possible
            this.phaseIndex -= 1;
            const prevPh = this.currentPhase();

            // ⚠️ garde aligné avec runSearchOnce() (limit: 50)
            const LIMIT = 50;
            const total = parseInt(this.totalByPhase[prevPh] || 0, 10) || 0;

            this.pageByPhase[prevPh] = total > 0
              ? Math.max(0, Math.ceil(total / LIMIT) - 1)
              : 0;

            this.runSearchOnce();
            return;
          }

          toast('Start of results.', 'info');
        },

        // ----- focusSearchHit reste inchangée -----
        focusSearchHit(hit) {
          if (!hit) return;

          const targetLang = (hit.language_code || hit.target_lang || '') + '';
          if (targetLang && targetLang !== this.getCurrentLanguage()) {
            this.changeLang(targetLang);
          }

          const hitId = toInt(hit.id || hit.string_id || hit.translation_id || 0);
          const hitBlock = hit.block_id ? String(hit.block_id) : '';

          const canon = s => String(s || '').replace(/\s+/g, ' ').trim();
          const fold = s => canon(s).normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();

          const qOrig = fold(hit.original_text || '');

          const matchIndex = this.items.findIndex(it => {
            const ids = [toInt(it.id), toInt(it.translation_id), toInt(it.string_id)].filter(Boolean);
            if (hitId && ids.includes(hitId)) return true;
            if (hitBlock && it.block_id && String(it.block_id) === hitBlock) return true;

            // match souple (diacritiques/casse/espaces)
            const orig = fold(it.original);
            return qOrig && (orig === qOrig || orig.includes(qOrig) || qOrig.includes(orig));
          });

          if (matchIndex < 0) { toast('Result outside current page.', 'warning'); return; }

          this.index = matchIndex;

          // Focus + highlight du terme dans l’original
          this.$nextTick(() => {
            if (autoFlag('auto_translate_on_navigation', true)) {
              this.autoTranslateIfAppropriate();
            }
            const src = this.$el && this.$el.querySelector('textarea[name="yuz-original"]');
            const dst = this.$el && this.$el.querySelector('textarea[name="yuz-translation"]');
            const q = (this.searchSnapshot || '').trim();
            if (src && q) {
              const foldSrc = fold(src.value);
              const pos = foldSrc.indexOf(fold(q));
              if (pos >= 0) {
                try { src.focus(); requestAnimationFrame(() => src.setSelectionRange(pos, pos + q.length)); } catch (_) { }
              }
            }
            if (dst) dst.focus();
          });
        },

        // ---------- Save one / bulk (save/bulk = int) ----------
        saveOne(item) {
          if (!item) return;
          const code = this.getCurrentLanguage();
          const t = (item.translations || {})[code] || {};
          const edited = t.edited != null ? t.edited : (t.translated || '');
          if (!edited) return;

          const statusParsed = parseInt(t.status != null ? t.status : item.status, 10);
          const status = Number.isNaN(statusParsed) || statusParsed <= 0 ? 2 : statusParsed;

          // >>> Aligne la sauvegarde avec le mode Instant
          const ctx = item.context || (this.currentPhase && this.currentPhase() === 'instant' ? 'instant' : 'content');

          this.loading++;
          this.saveTranslationForItem(item, code, edited, {
            status,
            pageUrl: this.normalizePageUrl(item.page_url || this.urlToLoad),
            origin: 'manual',
            originalText: item.original || '',
            context: ctx,                 // <<< clé pour que scope:'instant' les retrouve
          }).then(() => {
            toast('Enregistré.', 'success');
          }).catch(err => {
            log('critical', '[YUZ][TE] Save error', { err });
            toast('Enregistrement impossible.', 'error');
          }).finally(() => {
            this.loading = Math.max(0, this.loading - 1);
          });
        },

        saveAll() {
          if (!this.dirtySet.size) { toast('Aucun changement à enregistrer.', 'warning'); return; }
          const code = this.getCurrentLanguage();
          const payload = [];
          this.items.forEach(it => {
            if (this.dirtySet.has(it.id)) {
              const t = (it.translations || {})[code] || {};
              const edited = t.edited != null ? t.edited : t.translated || '';
              if (edited) payload.push({ string_id: it.id, translated_text: edited });
            }
          });
          if (!payload.length) { toast('Aucun changement valide.', 'warning'); return; }

          this.loading++;
          apiPost(ACTION.TE_PUBLISH, {
            page_url: this.normalizePageUrl(this.urlToLoad),
            target_lang: code,
            entries: JSON.stringify(payload),
            origin: 'manual'
          }).done(r => {
            if (r && r.success) {
              payload.forEach(p => {
                const it = this.items.find(x => x.id === p.string_id);
                if (!it) return;
                this.ensureTranslationEntry(
                  it,
                  code,
                  { translated: p.translated_text },
                  { setStatus: '2', clearEdited: true, textForStatus: p.translated_text }
                );
                this.dirtySet.delete(it.id);
              });
              toast(r.data?.message || 'Traductions enregistrées.', 'success');
              log('success', '[YUZ][TE] Bulk save OK', { count: payload.length });
            } else {
              toast(r?.data?.message || 'Échec enregistrement groupé.', 'error');
              log('warning', '[YUZ][TE] Bulk save failed', r);
            }
          }).fail(xhr => {
            log('critical', '[YUZ][TE] Bulk save error', { status: xhr?.status });
            toast('Erreur AJAX lors de l’enregistrement.', 'error');
          }).always(() => { this.loading = Math.max(0, this.loading - 1); });
        },

        // ---------- Suggest AI (suggest_ai = hvy) ----------
        fetchSuggestions(text) {
          if (!text || text.trim().length < 2) return;

          const action = Y.nonces && Y.nonces[ACTION.AI_SUGGEST_BATCH]
            ? ACTION.AI_SUGGEST_BATCH
            : null;

          if (!action) {
            if (!hvyNonceWarned) {
              log('info', '[YUZ][TE] Suggestions désactivées (nonce HVY absent)');
              hvyNonceWarned = true;
            }
            return;
          }

          const code = this.getCurrentLanguage();
          const src = this.sourceLanguage || 'auto';
          apiPost(action, {
            source_lang: src,
            target_langs: JSON.stringify([code]),
            texts: JSON.stringify([text])
          }).done(r => {
            if (isOk(r)) {
              this.suggestions = pickSuggestions(r.data || {}).slice(0, 5);
              log('success', '[YUZ][TE] Suggestions OK', { count: this.suggestions.length });
            } else {
              log('warning', '[YUZ][TE] Suggestions failed', r);
            }
          }).fail(xhr => {
            log('critical', '[YUZ][TE] Suggestions error', { status: xhr?.status });
          });
        },

        applySuggestion(s) {
          if (!this.current) return;
          const code = this.getCurrentLanguage();
          this.ensureTranslationEntry(
            this.current,
            code,
            { edited: s },
            { autoStatus: '1', textForStatus: s }
          );
          this.dirtySet.add(this.current.id);
        },

        // --- Batch EFFICACE (instant) : 1 POST, mapping indexé {i->texte}
        async translateBatchByIndex({ payload, from, to, persist = 1, context = 'instant', reqId = null }) {
          if (!Array.isArray(payload) || !payload.length) throw new Error('empty_payload');
          const req = reqId || (window.crypto?.randomUUID?.() ?? Math.random().toString(36).slice(2));
          const MAX_ATTEMPTS = 3; // 1 try + 2 retries on transient API hiccups
          const RETRY_BASE_DELAY_MS = 600;
          const isTransient = (err) => {
            const msg = (err && err.message) ? err.message : String(err || '');
            return /lt_http|timeout|502|fetch|aborted|28/.test(msg.toLowerCase());
          };

          const doCall = async () => {
            const res = await tmTranslateAjax({
              payload, from, to, persist,
              context,
              req_id: req,
              autoApply: false
            });
            const bag = res?.data?.results ?? res?.results ?? res?.data?.translations ?? res?.translations ?? {};
            const pick = (b, k) => Array.isArray(b) ? b[k] : b[k];
            const map = {};
            for (const row of payload) {
              const k = row.i;
              const node = pick(bag, k);
              const txt = (v => (v == null ? '' : String(v)))(
                node?.translated_text || node?.translation || node?.translated || ''
              ).trim();
              if (txt) map[k] = txt;
            }
            return map;
          };

          let lastErr = null;
          for (let attempt = 0; attempt < MAX_ATTEMPTS; attempt++) {
            try {
              return await doCall();
            } catch (err) {
              lastErr = err;
              if (!isTransient(err) || attempt === MAX_ATTEMPTS - 1) {
                throw err;
              }
              const delay = RETRY_BASE_DELAY_MS * (attempt + 1);
              if (typeof pipelineProbe === 'function') {
                pipelineProbe('auto_chunk_retry', { req, attempt: attempt + 1, delay });
              }
              await sleep(delay);
            }
          }
          throw lastErr;
        },


        // Auto Translate current string via TM translate endpoint (LibreTranslate)
        async autoTranslateCurrent() {
          if (!this.current) { return false; }
          const index = this.index;
          const original = stripCssJsNoise((this.current.original || '').toString());
          const srcRaw = this.sourceLanguage || window.yuzTraSettings?.source_language || 'auto';
          const targetRaw = this.getCurrentLanguage();
          if (index < 0) { toast('Select an item first.', 'warning'); return false; }
          if (!original) { toast('Original text is empty.', 'warning'); return false; }
          if (!targetRaw || targetRaw === srcRaw) {
            toast('Choose a different "To" language than "From".', 'warning');
            return false;
          }

          const reqId = window.crypto?.randomUUID?.() || Math.random().toString(36).slice(2);
          const fromCode = (srcRaw || '').trim() || 'auto';
          const targetCode = (targetRaw || '').trim();
          if (!targetCode) {
            toast('Target language is not translatable.', 'warning');
            return false;
          }

          const existing = (this.current.translations && this.current.translations[targetRaw]) || null;
          const manualEdited = !!(existing && typeof existing.edited === 'string' && existing.edited.trim() !== '' && existing.edited !== existing.translated);
          if (manualEdited) {
            log('info', '[YUZ][TE] autoTranslate skipped (manual edit present)', { index, target: targetRaw });
            return false;
          }

          try {
            const res = await tmTranslateAjax({
              text: original,
              from: fromCode,
              to: targetCode,
              persist: 0,
              req_id: reqId,
              autoApply: false
            });

            const translated = (val => (val == null ? '' : String(val)))(
              res?.translated_text
              || res?.data?.translated_text
              || (Array.isArray(res?.data?.results) && res.data.results[0]?.translated_text)
              || (Array.isArray(res?.data?.translations) && res.data.translations[0]?.translated_text)
              || ''
            ).trim();

            if (!translated) {
              toast('No translation received.', 'warning');
              return false;
            }

            const targetKey = targetRaw;
            this.ensureTranslationEntry(
              this.current,
              targetKey,
              { translated, edited: translated },
              { setStatus: '1', textForStatus: translated }
            );
            this.dirtySet.add(this.current.id);
            if (typeof this.throttledSaveOne === 'function') {
              try { this.throttledSaveOne(); } catch (_) { }
            }
            toast('Translation applied.', 'success');
            return true;
          } catch (error) {
            toast('Error during translation.', 'error');
            return false;
          }
        },


        autoTranslateIfAppropriate() {
          const item = this.current;
          if (!item) return;
          const target = this.getCurrentLanguage();
          const source = this.sourceLanguage || window.yuzTraSettings?.source_language || 'auto';
          if (!target || target === source) return;

          // Anti-rafale : ignorer les déclenchements répétés sur la même paire item/lang dans un laps très court
          const key = `${item.id || item.translation_id || item.original || ''}::${target}`;
          const now = Date.now();
          if (this._autoTranslateGuard && this._autoTranslateGuard.key === key && (now - this._autoTranslateGuard.ts) < 1000) {
            return;
          }
          this._autoTranslateGuard = { key, ts: now };

          // Ne pas relancer si la traduction existe déjà
          const existing = pickTranslationEntry(item, target);
          if (existing && existing.translated && String(existing.translated).trim() !== '') {
            return;
          }

          const manualEdited = !!(existing && typeof existing.edited === 'string' && existing.edited.trim() !== '' && existing.edited !== existing.translated);
          if (manualEdited) return;
          this.autoTranslateCurrent();
        },

        // Batch auto-translate all untranslated items for current target (chunked)
        async autoTranslateAll() {
          if (this.autoBatch?.running) {
            toast('Auto translate already running.', 'info');
            return;
          }

          const targetRaw = (this.getCurrentLanguage() || '').trim();
          const srcRaw = (this.sourceLanguage || '').trim() || 'auto';
          if (!targetRaw) { toast('Select a target language first.', 'warning'); return; }
          if (targetRaw === srcRaw) { toast('Choose a different "To" language than "From".', 'warning'); return; }

          const skipped = [];
          const queue = (this.items || []).reduce((acc, item, index) => {
            const originalText = asString(item.original || item.text || '');
            const entry = ((item.translations || {})[targetRaw]) || {};
            const alreadyTranslated = !!(entry.translated && String(entry.translated).trim().length);
            const alreadyEdited = !!(entry.edited && String(entry.edited).trim().length);
            if (alreadyTranslated || alreadyEdited) {
              skipped.push({ index, reason: alreadyTranslated ? 'translated' : 'edited', text: originalText.slice(0, 80) });
              return acc;
            }

            acc.push({
              item,
              index,
              originalText,
              postId: toInt(item.post_id || 0),
              context: 'instant', // <- cible instant
              blockId: asString(item.block_id || ''),
              pageUrl: asString(this.normalizePageUrl(item.page_url || this.urlToLoad || window.location.href.split('#')[0])),
              stringId: pickStringId(item) || toInt(item.translation_id || item.id || 0)
            });
            return acc;
          }, []);

          this.inspectorProbe('auto-queue', {
            total: (this.items || []).length,
            queued: queue.length,
            skipped: skipped.length,
            skippedSample: skipped.slice(0, 5)
          });

          if (!queue.length) {
            toast('Nothing to translate.', 'info');
            pipelineProbe('auto_skip', { reason: 'nothing_to_translate', target: targetRaw, from: srcRaw });
            return;
          }

          const batchId = window.crypto?.randomUUID?.() || Math.random().toString(36).slice(2);
          const stats = { total: queue.length, done: 0, skipped: 0, running: true };
          const publishQueue = [];
          yuzProgress(this, stats);
          pipelineProbe('auto_start', { batchId, queued: queue.length, from: srcRaw, to: targetRaw });

          bus('yuz:translate:request', { mode: 'queue', from: srcRaw, to: targetRaw, count: stats.total, reqId: batchId });
          this.loading++;

          try {
            // Small, responsive chunks to reduce initial latency
            const CHUNK_SIZE = 10; // smaller payload to avoid overloading single-worker LT
            const CHUNK_DELAY_MS = 120; // let the API breathe between calls
            // Optional: limited concurrency for saving to speed up persistence without overloading
            const SAVE_CONCURRENCY = 1; // anti-rafales côté persistance

            // Helper for pooled saves
            const savePool = [];
            const runSave = async (task) => {
              if (SAVE_CONCURRENCY <= 1) return task();
              const p = task();
              savePool.push(p);
              if (savePool.length >= SAVE_CONCURRENCY) {
                await Promise.race(savePool).catch(() => { });
                // prune settled
                for (let i = savePool.length - 1; i >= 0; i--) {
                  if (typeof savePool[i]?.then !== 'function') savePool.splice(i, 1);
                }
              }
              return p;
            };

            for (let start = 0; start < queue.length; start += CHUNK_SIZE) {
              const slice = queue.slice(start, start + CHUNK_SIZE);
              const payload = slice.map((e) => ({
                i: e.index,                 // keep global index to simplify mapping
                text: e.originalText,
                post_id: e.postId,
                context: 'instant',
                block_id: e.blockId,
                string_id: e.stringId
              }));

              // Translate this chunk
              let map = {};
              try {
                map = await this.translateBatchByIndex({
                  payload,
                  from: srcRaw,
                  to: targetRaw,
                  persist: 1,
                  context: 'instant',
                  reqId: batchId
                });
                pipelineProbe('auto_chunk_translated', {
                  batchId,
                  offset: start,
                  size: slice.length,
                  translated: Object.keys(map || {}).length,
                  target: targetRaw
                });
              } catch (chunkErr) {
                pipelineProbe('auto_chunk_error', {
                  batchId,
                  offset: start,
                  size: slice.length,
                  error: chunkErr instanceof Error ? chunkErr.message : String(chunkErr)
                });
                // mark all as skipped for this chunk
                stats.skipped += slice.length;
                yuzProgress(this, stats);
                continue;
              }

              // Apply + save per item in chunk
              for (const entry of slice) {
                const translatedText = (map[entry.index] || '').trim();

                if (!translatedText || yuzShouldSkip(entry.originalText)) {
                  stats.skipped++; yuzProgress(this, stats); continue;
                }

                this.ensureTranslationEntry(
                  entry.item,
                  targetRaw,
                  { translated: translatedText, edited: translatedText },
                  { autoStatus: '1', textForStatus: translatedText }
                );
                if (entry.item.id) this.dirtySet.add(entry.item.id);

                await runSave(() => this.saveTranslationForItem(entry.item, targetRaw, translatedText, {
                  status: 2,
                  pageUrl: entry.pageUrl,
                  origin: 'auto',
                  originalText: entry.originalText,
                  sourceCode: srcRaw,
                  context: 'instant',
                  isBatch: true,
                  forceCreate: true,
                }).then((res) => {
                  stats.done++;
                  const savedId = parseInt(res?.data?.id || res?.data?.ID || entry.item.translation_id || entry.item.id, 10);
                  if (!Number.isNaN(savedId) && savedId > 0) {
                    publishQueue.push({
                      id: String(savedId),
                      original: entry.originalText,
                      translated: translatedText
                    });
                  }
                  return res;
                })).catch(() => { stats.skipped++; });

                yuzProgress(this, stats);
              }

              // Let UI breathe between chunks
              await sleep(CHUNK_DELAY_MS);
            }

            // Flush remaining save promises if pooling is used
            if (savePool.length) {
              try { await Promise.allSettled(savePool); } catch (_) { }
            }

            stats.running = false;
            yuzProgress(this, stats);
            const hasQueue = publishQueue.length > 0;
            if (hasQueue) {
              const ids = publishQueue.map(item => item.id);
              if (window.YUZ_Editor && typeof window.YUZ_Editor.notifyAutoTranslateDone === 'function') {
                window.YUZ_Editor.notifyAutoTranslateDone(publishQueue, { ids, origin: 'machine', isBatch: true });
              } else {
                document.dispatchEvent(new CustomEvent('yuz:autoTranslateDone', {
                  detail: {
                    ids,
                    items: publishQueue,
                    origin: 'machine',
                    isBatch: true
                  }
                }));
              }
              const attemptShowDock = (label) => {
                if (!(window.YUZ_PUBLISH && typeof window.YUZ_PUBLISH.showDockReview === 'function')) {
                  return false;
                }
                try {
                  if (window.YUZ_PUBLISH.state && Array.isArray(window.YUZ_PUBLISH.state.pending)) {
                    window.YUZ_PUBLISH.state.pending = ids.slice();
                  }
                  window.YUZ_PUBLISH.showDockReview(ids, publishQueue);
                  pipelineProbe('auto_review_panel', {
                    batchId,
                    ids,
                    done: stats.done,
                    skipped: stats.skipped,
                    target: targetRaw
                  });
                  return true;
                } catch (dockErr) {
                  pipelineProbe('auto_review_error', {
                    batchId,
                    ids,
                    message: dockErr instanceof Error ? dockErr.message : String(dockErr)
                  });
                  return false;
                }
              };

              let dockDisplayed = attemptShowDock('immediate');
              if (!dockDisplayed) {
                const assetsReady = await ensurePublishControllerAssets();
                if (assetsReady) {
                  dockDisplayed = attemptShowDock('after_lazy_load');
                }
              }

              if (!dockDisplayed) {
                try {
                  toast('Panneau de validation indisponible (voir console).', 'warning');
                } catch (_) { }
                pipelineProbe('review_panel_missing', {
                  batchId,
                  idsCount: ids.length,
                  reason: 'yuz_publish_unavailable'
                });
              }
              toast(`Auto-translate ready for review (${stats.done}/${stats.total})`, 'info');
              pipelineProbe('auto_ready', {
                batchId,
                ids,
                done: stats.done,
                skipped: stats.skipped,
                total: stats.total,
                target: targetRaw
              });
            } else {
              toast('No translations ready for review.', 'warning');
              pipelineProbe('auto_empty_queue', {
                batchId,
                done: stats.done,
                skipped: stats.skipped,
                total: stats.total,
                target: targetRaw
              });
            }
            bus('yuz:translate:success', { mode: 'queue', from: srcRaw, to: targetRaw, count: stats.done, skipped: stats.skipped, reqId: batchId });
          } catch (fatalErr) {
            stats.running = false;
            yuzProgress(this, stats);
            bus('yuz:translate:error', {
              mode: 'queue',
              from: srcRaw,
              to: targetRaw,
              error: fatalErr instanceof Error ? fatalErr.message : String(fatalErr),
              reqId: batchId
            });
            toast('Error during translation.', 'error');
            pipelineProbe('auto_error', {
              batchId,
              message: fatalErr instanceof Error ? fatalErr.message : String(fatalErr),
              stack: fatalErr?.stack ? fatalErr.stack.slice(0, 400) : undefined
            });
          } finally {
            stats.running = false;
            yuzProgress(this, stats);
            this.loading = Math.max(0, this.loading - 1);
            pipelineProbe('auto_finish', {
              batchId,
              done: stats.done,
              skipped: stats.skipped,
              total: stats.total,
              target: targetRaw
            });
          }
        },

        // ---------- Misc ----------
        changeLang(newCode) {
          if (!newCode) return;
          this.currentLanguage = newCode;
          this.suggestions = [];
          this.$nextTick(() => {
            if (autoFlag('auto_translate_on_lang_change', true)) {
              this.autoTranslateIfAppropriate();
            }
          });
        },

        bindKeys() {
          document.addEventListener('keydown', (e) => {
            const mod = navigator.platform.includes('Mac') ? e.metaKey : e.ctrlKey;
            if (mod && e.altKey && e.key === 'ArrowRight') { e.preventDefault(); this.next(); }
            if (mod && e.altKey && e.key === 'ArrowLeft') { e.preventDefault(); this.prev(); }
            if (mod && e.key.toLowerCase() === 's') { e.preventDefault(); this.saveAll(); }
            if (e.key === 'Escape') { e.preventDefault(); this.closeModal(); }
          });
        },

        // ---------------- DOM Bridge: hover/select to focus an item ----------------
        setupDomBridge() {
          if (this._domBridgeBound) return;
          try {
            if (!document.getElementById('yuz-te-highlight-style')) {
              const css = document.createElement('style');
              css.id = 'yuz-te-highlight-style';
              try { if (window.CLAR && window.CLAR.cspNonce) { css.setAttribute('nonce', window.CLAR.cspNonce); } } catch (e) { }
              css.textContent = '.yuz-te-hl{outline:2px solid #0b7285;outline-offset:2px;cursor:pointer}';
              document.head.appendChild(css);
            }
          } catch (_) { }

          this._boundDomScan = () => this.mapDomToItems();
          window.addEventListener('yuz_iframe_page_updated', this._boundDomScan);
          document.addEventListener('mouseup', this.onMouseUpSelect);
          this._domBridgeBound = true;
          this.mapDomToItems();
        },

        teardownDomBridge() {
          if (!this._domBridgeBound) return;
          if (this._boundDomScan) {
            window.removeEventListener('yuz_iframe_page_updated', this._boundDomScan);
          }
          document.removeEventListener('mouseup', this.onMouseUpSelect);
          this._boundDomScan = null;
          this._domBridgeBound = false;
        },

        clearInspectorHighlights() {
          try {
            document.querySelectorAll('[data-yuz-te-map]').forEach((el) => {
              el.removeAttribute('data-yuz-te-map');
              el.classList.remove('yuz-te-hl', 'yuz-te-hl-pulse');
              el.removeEventListener('mouseenter', this._hlEnter, { passive: true });
              el.removeEventListener('mouseleave', this._hlLeave, { passive: true });
              el.removeEventListener('click', this._hlClick);
            });
            document.querySelectorAll('[data-yuz-te-candidate]').forEach((el) => {
              el.removeAttribute('data-yuz-te-candidate');
              el.classList.remove('yuz-te-hl', 'yuz-te-hl-pulse');
              el.removeEventListener('mouseenter', this._hlEnter, { passive: true });
              el.removeEventListener('mouseleave', this._hlLeave, { passive: true });
              el.removeEventListener('click', this._candidateClick);
            });
          } catch (_) { }
          this._unmappedMap = new Map();
        },

        getInspectorPageUrl() {
          try {
            return this.normalizePageUrl(this.urlToLoad || window.location.href.split('#')[0] || '');
          } catch (_) {
            return this.normalizePageUrl(this.urlToLoad || '');
          }
        },

        normalizeInspectorText(value) {
          return String(value || '').replace(/\s+/g, ' ').trim();
        },

        normalizeInspectorKeyPart(value) {
          return this.normalizeInspectorText(value).replace(/\|/g, '¦');
        },

        buildInspectorKeys(text, meta = {}) {
          const normText = this.normalizeInspectorText(text);
          if (!normText) return [];

          const attribute = this.normalizeInspectorKeyPart(meta.attribute || meta.attr || '');
          const descriptor = this.normalizeInspectorKeyPart(meta.descriptor || meta.path || '');
          const blockId = this.normalizeInspectorKeyPart(meta.blockId || meta.block_id || '');
          const pageUrl = this.normalizeInspectorKeyPart(meta.pageUrl || meta.page_url || this.getInspectorPageUrl());
          const scope = this.normalizeInspectorKeyPart(meta.scope || meta.context || '');

          const keys = [];
          const pushUnique = (parts) => {
            const key = JSON.stringify(parts);
            if (key && !keys.includes(key)) keys.push(key);
          };

          // Most specific: full context
          pushUnique([normText, attribute, blockId, pageUrl, descriptor, scope]);
          // Relax: drop descriptor (items may not have it yet)
          pushUnique([normText, attribute, blockId, pageUrl, scope]);
          // Relax further: text + attribute
          pushUnique([normText, attribute]);
          // Fallback: text only (keeps legacy behaviour when no metadata is available)
          pushUnique([normText]);
          return keys;
        },

        normalizePageUrl(url) {
          return canonicalPageUrl(url || this.urlToLoad || window.location.href);
        },

        getInspectorMetaFromItem(item, pageUrlFallback) {
          if (!item) return {};
          const meta = item.meta || {};
          return {
            attribute: meta.attribute || '',
            descriptor: meta.descriptor || meta.path || '',
            blockId: (item.block_id != null ? item.block_id : (meta.blockId || '')),
            pageUrl: this.normalizePageUrl(item.page_url || meta.pageUrl || pageUrlFallback || ''),
            scope: meta.scope || item.context || ''
          };
        },

        extractBlockId(el) {
          if (!el || typeof el.closest !== 'function') return '';
          const attrs = ['data-block-id', 'data-block', 'data-yuz-block-id', 'data-yuz-block'];
          const selector = attrs.map((attr) => `[${attr}]`).join(',');
          const holder = selector ? el.closest(selector) : null;
          if (!holder || typeof holder.getAttribute !== 'function') return '';
          for (let i = 0; i < attrs.length; i++) {
            const attr = attrs[i];
            const direct = holder.getAttribute(attr);
            if (direct) return this.normalizeInspectorText(direct);
            const camel = attr.replace(/^data-/, '').replace(/-([a-z])/g, (_, c) => c.toUpperCase());
            if (holder.dataset && holder.dataset[camel] != null) {
              return this.normalizeInspectorText(holder.dataset[camel]);
            }
          }
          return '';
        },

        buildOriginalIndex() {
          const m = new Map();
          const fallbackPage = this.getInspectorPageUrl();
          for (let i = 0; i < this.items.length; i++) {
            const original = this.normalizeInspectorText(this.items[i].original || this.items[i].text || '');
            if (!original) continue;
            const meta = this.getInspectorMetaFromItem(this.items[i], fallbackPage);
            const keys = this.buildInspectorKeys(original, meta);
            keys.forEach((key) => {
              if (key && !m.has(key)) m.set(key, i);
            });
          }
          this._origIndex = m;
        },

        runInspectorScan(options = {}) {
          const basicOnly = options.basic === true;
          const inspectorOn = this.inspectorActive === true;
          if (!inspectorOn && !basicOnly) return [];
          if (!Array.isArray(this.items) || !this.items.length) {
            this._unmappedMap = new Map();
            if (inspectorOn) {
              const missing = this.collectMissingTranslations(this.currentLanguage);
              this.updateDiagnostics('dom-scan-empty', { unmapped: [], missing });
              this.inspectorProbe('scan_empty', { missing: missing.length });
              if (this._inspectorBridge && typeof this._inspectorBridge.update === 'function') {
                this._inspectorBridge.update({ reason: 'dom-scan-empty', unmapped: [], missing });
                this.inspectorProbe('bridge.update', { reason: 'dom-scan-empty', unmapped: 0, missing: missing.length });
              }
            }
            return [];
          }

          this.buildOriginalIndex();

          const detach = (selector) => {
            document.querySelectorAll(selector).forEach((el) => {
              el.removeEventListener('mouseenter', this._hlEnter, { passive: true });
              el.removeEventListener('mouseleave', this._hlLeave, { passive: true });
              el.removeEventListener('click', this._hlClick);
              el.removeEventListener('click', this._candidateClick);
              el.classList.remove('yuz-te-hl', 'yuz-te-hl-pulse');
              el.removeAttribute('data-yuz-te-map');
              el.removeAttribute('data-yuz-te-candidate');
            });
          };
          detach('[data-yuz-te-map]');
          detach('[data-yuz-te-candidate]');
          this._unmappedMap = new Map();

          const baseSkip = (window.YUZ_SKIP_SELECTORS || 'style,script,noscript,template,textarea,code,pre,[hidden],[aria-hidden="true"],#wpadminbar,#yuz-editor-container,#yuz-translation-editor,[data-yuz="no-translate"]');
          const SKIP = '#yuz-editor-container .yuz-modal,#wpadminbar,' + baseSkip;

          const canon = (s) => this.normalizeInspectorText(s);
          const looksLikeCode = window.yuzLooksLikeCode || function (s) {
            if (!s) return false;
            s = String(s);
            return /<[a-z][^>]*>/i.test(s) || /:[^;]+;/.test(s) || /{[^}]*}/.test(s) ||
              /\b(function|const|let|var|=>|return|document\.|window\.|addEventListener)\b/.test(s);
          };
          const looksLikeTemporal = window.yuzLooksLikeTemporal || function (s) {
            if (!s) return false;
            const txt = String(s).trim();
            if (!txt) return false;
            if (/^\d{1,4}$/.test(txt)) return true;
            if (/^\d{1,2}:\d{2}$/.test(txt)) return true;
            if (/^\d{1,2}h(?:\d{2})?$/i.test(txt)) return true;
            if (/^\d{1,2}[\/\-]\d{1,2}(?:[\/\-]\d{2,4})?$/.test(txt)) return true;
            if (/^(janv|févr|fevr|mars|avr|mai|juin|juil|août|sept|oct|nov|déc|jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)\.?$/i.test(txt)) return true;
            if (/^(lun|mar|mer|jeu|ven|sam|dim|mon|tue|wed|thu|fri|sat|sun)\.?$/i.test(txt)) return true;
            if (/^\d{1,2}\s+(?:janv|févr|fevr|mars|avr|mai|juin|juil|août|sept|oct|nov|dec)\.?$/i.test(txt)) return true;
            return false;
          };
          const isInvisible = (el) => {
            const cs = getComputedStyle(el);
            return cs.display === 'none' || cs.visibility === 'hidden' || parseFloat(cs.opacity) === 0;
          };
          const allowCandidates = inspectorOn && options.allowCandidates !== false && !basicOnly;
          const pageContext = this.getInspectorPageUrl();

          this._hlEnter = this._hlEnter || ((e) => { e.currentTarget.classList.add('yuz-te-hl'); });
          this._hlLeave = this._hlLeave || ((e) => { e.currentTarget.classList.remove('yuz-te-hl'); });
          this._hlClick = this._hlClick || ((e) => {
            const el = e.currentTarget;
            const n = parseInt(el?.dataset?.yuzTeMap || '-1', 10);
            if (!isNaN(n) && n >= 0 && n < this.items.length) {
              this.focusExisting(n);
            }
          });
          this._candidateClick = this._candidateClick || ((e) => {
            const id = e?.currentTarget?.dataset?.yuzTeCandidate;
            if (!id) return;
            if (this.diagnostics && !this.diagnostics.open) {
              this.diagnostics.open = true;
            }
            this.focusDiagnostic({ id });
            this.updateDiagnostics('candidate-click');
          });

          const findMatchIndex = (text, meta = {}) => {
            const keys = this.buildInspectorKeys(text, Object.assign({ pageUrl: pageContext }, meta));
            for (let i = 0; i < keys.length; i++) {
              const key = keys[i];
              if (key && this._origIndex.has(key)) return this._origIndex.get(key);
            }
            return undefined;
          };

          const unmapped = [];
          const seenNodes = new Set();
          const unmappedKeys = new Set();
          let candidateSeq = 0;

          const registerCandidate = (host, text, meta = {}) => {
            if (!allowCandidates) return;
            if (!host || !text) return;
            if (host.closest && host.closest(SKIP)) return;
            if (isInvisible(host)) return;
            const descriptor = meta.descriptor || this.describeDomNode(host);
            const blockId = meta.blockId || this.extractBlockId(host);
            const candidateMeta = Object.assign({}, meta, {
              descriptor,
              blockId,
              pageUrl: meta.pageUrl || pageContext
            });
            const candidateKeys = this.buildInspectorKeys(text, candidateMeta);
            const key = candidateKeys[0] || `${text}::${descriptor}::${candidateMeta.attribute || ''}::${blockId}`;
            if (unmappedKeys.has(key)) return;
            const snippet = text.length > 200 ? `${text.slice(0, 197)}…` : text;
            const candidateId = `cand_${Date.now()}_${++candidateSeq}`;
            host.dataset.yuzTeCandidate = candidateId;
            host.addEventListener('mouseenter', this._hlEnter, { passive: true });
            host.addEventListener('mouseleave', this._hlLeave, { passive: true });
            host.addEventListener('click', this._candidateClick);
            this._unmappedMap.set(candidateId, {
              element: host,
              text,
              snippet,
              descriptor,
              meta: candidateMeta
            });
            unmappedKeys.add(key);
            unmapped.push({
              id: candidateId,
              text,
              snippet,
              path: descriptor,
              meta: candidateMeta
            });
            diagProbe('candidate.register', {
              id: candidateId,
              text: snippet,
              attribute: meta.attribute || null,
              blockId,
              descriptor,
              unmappedSize: this._unmappedMap.size
            });
          };

          const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT, {
            acceptNode: (node) => {
              const pe = node && node.parentElement;
              if (!pe) return NodeFilter.FILTER_REJECT;
              if (pe.closest && pe.closest(SKIP)) return NodeFilter.FILTER_REJECT;
              if (isInvisible(pe)) return NodeFilter.FILTER_REJECT;

              let t = canon(node.nodeValue);
              if (!t || t.length < 2) return NodeFilter.FILTER_REJECT;
              if (looksLikeCode(t)) return NodeFilter.FILTER_REJECT;
              t = t.replace(/\[(\/)?[a-z0-9_-]+[^\]]*\]/ig, '').trim();
              if (!t) return NodeFilter.FILTER_REJECT;
              return NodeFilter.FILTER_ACCEPT;
            }
          });

          while (walker.nextNode()) {
            const tn = walker.currentNode;
            if (seenNodes.has(tn)) continue;

            let text = canon(tn.nodeValue);
            if (!text || looksLikeTemporal(text)) continue;
            const host = tn.parentElement;
            if (!host) continue;

            const descriptor = this.describeDomNode(host);
            const blockId = this.extractBlockId(host);
            const idx = findMatchIndex(text, { descriptor, blockId });
            if (typeof idx === 'number') {
              diagProbe('inspect.map_existing', { text, index: idx, descriptor, blockId });
              host.dataset.yuzTeMap = String(idx);
              host.addEventListener('mouseenter', this._hlEnter, { passive: true });
              host.addEventListener('mouseleave', this._hlLeave, { passive: true });
              host.addEventListener('click', this._hlClick);
              seenNodes.add(tn);
              continue;
            }

            registerCandidate(host, text, { type: 'text', descriptor, blockId });
          }

          const attrSelectors = [
            '[placeholder]',
            '[aria-label]',
            '[data-field-label]',
            '[data-label]'
          ];
          document.querySelectorAll(attrSelectors.join(',')).forEach((el) => {
            if (!el || !el.getAttribute) return;
            if (el.closest && el.closest(SKIP)) return;
            if (isInvisible(el)) return;

            ['placeholder', 'aria-label', 'data-field-label', 'data-label'].forEach((attr) => {
              const raw = el.getAttribute(attr);
              const text = canon(raw);
              if (!text || looksLikeTemporal(text) || looksLikeCode(text)) return;
              const descriptor = this.describeDomNode(el);
              const blockId = this.extractBlockId(el);
              const idx = findMatchIndex(text, { attribute: attr, descriptor, blockId });
              if (typeof idx === 'number') {
                diagProbe('inspect.map_existing_attr', { text, attribute: attr, descriptor, blockId });
                return;
              }
              registerCandidate(el, text, { type: 'attribute', attribute: attr, descriptor, blockId });
            });
          });

          document.querySelectorAll('[aria-labelledby]').forEach((el) => {
            if (!el || !el.getAttribute) return;
            if (el.closest && el.closest(SKIP)) return;
            if (isInvisible(el)) return;
            const ref = el.getAttribute('aria-labelledby');
            if (!ref) return;
            const firstId = ref.split(/\s+/)[0];
            if (!firstId) return;
            const label = document.getElementById(firstId);
            if (!label) return;
            const text = canon(label.textContent || '');
            if (!text || looksLikeTemporal(text) || looksLikeCode(text)) return;
            const descriptor = this.describeDomNode(el);
            const blockId = this.extractBlockId(el);
            const idx = findMatchIndex(text, { attribute: 'aria-labelledby', descriptor, blockId });
            if (typeof idx === 'number') return;
            registerCandidate(el, text, { type: 'attribute', attribute: 'aria-labelledby', descriptor, blockId });
          });

          if (inspectorOn) {
            const reason = options.reason || 'dom-scan';
            const missing = this.collectMissingTranslations(this.currentLanguage);
            this.updateDiagnostics(reason, { unmapped, missing });
            this.inspectorProbe('scan_done', { reason, unmapped: unmapped.length, missing: missing.length });
            diagProbe('inspect.scan', {
              reason,
              unmapped: unmapped.length,
              missing: missing.length,
              items: this.items.length,
              unmappedMapSize: this._unmappedMap instanceof Map ? this._unmappedMap.size : 0
            });
            if (this._inspectorBridge && typeof this._inspectorBridge.update === 'function') {
              this._inspectorBridge.update({ reason, unmapped, missing });
              this.inspectorProbe('bridge.update', { reason, unmapped: unmapped.length, missing: missing.length });
            }
          }
          return unmapped;
        },

        mapDomToItems() {
          const opts = this.inspectorActive ? { reason: 'dom-scan-event' } : { reason: 'dom-scan-event', basic: true };
          this.runInspectorScan(opts);
        },

        onMouseUpSelect() {
          try {
            if (!Array.isArray(this.items) || !this.items.length) return;
            const sel = window.getSelection && window.getSelection();
            if (!sel || !sel.rangeCount) return;

            const range = sel.getRangeAt(0);
            const common = (range.commonAncestorContainer.nodeType === 1)
              ? range.commonAncestorContainer
              : range.commonAncestorContainer.parentElement;

            // Unifiés
            const baseSkip = (window.YUZ_SKIP_SELECTORS || 'style,script,noscript,template,textarea,code,pre,[hidden],[aria-hidden="true"],#wpadminbar,#yuz-editor-container,#yuz-translation-editor,[data-yuz="no-translate"]');
            const SKIP = '#yuz-editor-container .yuz-modal,#wpadminbar,' + baseSkip;
            if (common && common.closest && common.closest(SKIP)) return;

            const scanner = window.yuzEditorVisibleText || function (root) {
              if (!root) return '';
              const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, {
                acceptNode: (n) => (n.parentElement && !n.parentElement.closest(SKIP) ? NodeFilter.FILTER_ACCEPT : NodeFilter.FILTER_REJECT)
              });
              const out = [];
              while (walker.nextNode()) out.push(String(walker.currentNode.nodeValue || '').trim());
              return out.join(' ').replace(/\s+/g, ' ').trim();
            };
            const canon = (s) => String(s || '').replace(/\s+/g, ' ').trim();
            const stripNoise = window.stripCssJsNoise || ((s) => s);
            const looksLikeCode = window.yuzLooksLikeCode || function (s) {
              if (!s) return false;
              s = String(s);
              return /<[a-z][^>]*>/i.test(s) || /:[^;]+;/.test(s) || /{[^}]*}/.test(s) ||
                /\b(function|const|let|var|=>|return|document\.|window\.|addEventListener)\b/.test(s);
            };

            // Texte “visible” prioritaire, sinon sélection brute
            let txt = canon(scanner(common)) || canon(sel.toString());
            if (!txt || txt.length < 2) return;

            // Nettoyage/filtrage anti CSS/JS/HTML
            txt = canon(stripNoise(txt));
            if (!txt || txt.length < 2 || looksLikeCode(txt)) return;

            // Correspondance avec items (exacte → approx)
            let i = this.items.findIndex(it => canon(it.original) === txt);
            if (i < 0) {
              i = this.items.findIndex(it => {
                const o = canon(it.original);
                return (o && txt && (o.indexOf(txt) !== -1 || txt.indexOf(o) !== -1));
              });
            }

            // Ajout ad-hoc si rien trouvé, mais uniquement si texte “valide”
            if (i < 0) {
              this.injectAdhocItem(txt, { context: 'content', pageUrl: this.normalizePageUrl(this.urlToLoad) });
              return;
            }

            // Focus sur l’item trouvé
            this.focusExisting(i);
          } catch (_) { /* no-op */ }
        },

        injectAdhocItem(text, meta = {}) {
          const original = String(text || '').trim();
          if (!original) return null;
          const pos = Math.max(0, this.index | 0);
          const tempId = meta.id || `adhoc_${Date.now()}`;
          const context = meta.attribute ? `attr:${meta.attribute}` : (meta.context || 'instant');
          const pageUrl = this.normalizePageUrl(meta.pageUrl || this.urlToLoad);
          const item = {
            id: tempId,
            translation_id: 0,
            original,
            page_url: pageUrl,
            context,
            block_id: meta.blockId || '',
            translations: {},
            meta: {
              attribute: meta.attribute || '',
              descriptor: meta.descriptor || '',
              scope: meta.scope || '',
              blockId: meta.blockId || '',
              pageUrl
            }
          };
          this.items.splice(pos, 0, item);
          diagProbe('adhoc.inject', {
            id: tempId,
            original,
            context,
            pageUrl: item.page_url,
            itemsAfter: this.items.length
          });
          this.index = pos;
          this.suggestions = [];
          this.manualOverride = false;
          this.dirtySet.add(tempId);
          this.$nextTick(() => {
            if (autoFlag('auto_translate_on_navigation', true)) {
              this.autoTranslateIfAppropriate();
            }
            this.emitOverlayScan('adhoc-insert');
            this.updateDiagnostics('adhoc-insert');
          });
          return item;
        },

        createAdhocFromCandidate(id, fallback) {
          if (!id) return;
          let info = null;
          if (this._unmappedMap instanceof Map) {
            info = this._unmappedMap.get(id) || null;
          }
          // Fallback (ex: map déjà nettoyé avant le clic)
          if (!info && fallback) {
            info = {
              text: fallback.text || fallback.snippet || '',
              meta: fallback.meta || {},
              element: null,
              descriptor: fallback.path || ''
            };
          }
          if (!info || !info.text) {
            this.inspectorProbe('candidate-miss', { id, hasFallback: !!fallback });
            return;
          }
          const meta = info.meta || {};
          const item = this.injectAdhocItem(info.text, {
            context: 'instant',
            pageUrl: this.normalizePageUrl(meta.pageUrl || this.urlToLoad),
            attribute: meta.attribute,
            descriptor: meta.descriptor,
            blockId: meta.blockId,
            scope: meta.scope
          });
          diagProbe('candidate.create', {
            id,
            text: info.text,
            attribute: meta.attribute || null,
            blockId: meta.blockId || null,
            unmappedBefore: this._unmappedMap instanceof Map ? this._unmappedMap.size : 0,
            itemsBefore: Array.isArray(this.items) ? this.items.length : 0
          });
          if (info.element) {
            try {
              delete info.element.dataset.yuzTeCandidate;
              info.element.classList.remove('yuz-te-hl', 'yuz-te-hl-pulse');
            } catch (_) { }
          }
          this._unmappedMap.delete(id);
          if (item) {
            diagProbe('candidate.injected', {
              id,
              index: this.index,
              itemsAfter: Array.isArray(this.items) ? this.items.length : 0
            });
            this.focusExisting(this.index);
          }
          this.updateDiagnostics('candidate-add');
          this.inspectorProbe('candidate-add', { id, text: info.text, attribute: meta.attribute || null });
        },

        focusExisting(index) {
          const idx = Number(index);
          if (Number.isNaN(idx) || idx < 0 || idx >= (this.items?.length || 0)) return;
          this.index = idx;
          this.suggestions = [];
          this.$nextTick(() => {
            if (autoFlag('auto_translate_on_navigation', true)) {
              this.autoTranslateIfAppropriate();
            }
          });
        },

        emitOverlayScan(reason) {
          const payload = { reason: reason || 'overlay-scan' };
          try { window.dispatchEvent(new CustomEvent('yuz:overlay:scan', { detail: payload })); } catch (_) { }
          if (typeof this.mapDomToItems === 'function') {
            try { this.mapDomToItems(); } catch (_) { }
          }
        },

        updateDiagnostics(reason, payload = {}) {
          try {
            const target = this.currentLanguage || '';
            const missing = Array.isArray(payload.missing)
              ? payload.missing
              : this.collectMissingTranslations(target);
            const unmapped = Array.isArray(payload.unmapped) ? payload.unmapped : (this.diagnostics.unmapped || []);
            this.diagnostics = Object.assign({}, this.diagnostics || {}, {
              reason: reason || '',
              target,
              updatedAt: new Date().toISOString(),
              missing,
              unmapped
            });
          } catch (err) {
          }
        },

        collectMissingTranslations(targetCode) {
          const list = [];
          if (!targetCode) return list;
          const canon = (s) => (s == null ? '' : String(s)).trim();
          (this.items || []).forEach((item, index) => {
            const entry = (item && item.translations && item.translations[targetCode]) || {};
            const translated = canon(entry.edited || entry.translated || '');
            if (!translated) {
              list.push({
                id: item?.id || item?.translation_id || `missing_${index}`,
                index,
                original: canon(item?.original || item?.text || ''),
                page: item?.page_url || ''
              });
            }
          });
          return list;
        },

        describeDomNode(el) {
          if (!el || !el.nodeType || el.nodeType !== 1) return '';
          const chain = [];
          let node = el;
          let depth = 0;
          while (node && node.nodeType === 1 && depth < 6 && node !== document.body) {
            let selector = node.tagName.toLowerCase();
            if (node.id) {
              selector += `#${node.id}`;
              chain.unshift(selector);
              break;
            }
            const classList = Array.from(node.classList || []).filter(Boolean);
            if (classList.length) {
              selector += '.' + classList.slice(0, 2).join('.');
            } else {
              let nth = 1;
              let prev = node.previousElementSibling;
              while (prev) {
                if (prev.tagName === node.tagName) nth += 1;
                prev = prev.previousElementSibling;
              }
              selector += `:nth-of-type(${nth})`;
            }
            chain.unshift(selector);
            node = node.parentElement;
            depth += 1;
          }
          return chain.join(' > ');
        },

        scrollElementIntoView(el) {
          if (!el || typeof el.scrollIntoView !== 'function') return;
          try {
            el.scrollIntoView({ behavior: 'smooth', block: 'center', inline: 'nearest' });
          } catch (_) {
            try { el.scrollIntoView(); } catch (_) { }
          }
        },

        focusDiagnostic(entry) {
          if (!entry) return;
          const id = entry.id || entry.selector;
          if (!id || !(this._unmappedMap instanceof Map)) return;
          const info = this._unmappedMap.get(id);
          if (!info || !info.element) return;
          const el = info.element;
          this.scrollElementIntoView(el);
          try {
            el.classList.add('yuz-te-hl', 'yuz-te-hl-pulse');
            setTimeout(() => el.classList.remove('yuz-te-hl-pulse'), 1200);
          } catch (_) { }
        },

      },
      template: `
        <div class="yuz-overlay">
          <div class="yuz-mask"></div>
          <div :id="'yuz-te-' + panelMode" ref="modal" class="yuz-modal" :class="'yuz-te--' + panelMode" role="dialog" aria-label="YUZ Translation Editor" :data-yuz-mode="panelMode">
            <div class="yuz-header" ref="yuzHeader">
              <div class="yuz-header-row yuz-header-row--brand">
                <div class="yuz-header-brand">
                  <span class="yuz-logo" aria-hidden="true">
                    <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none">
                      <!-- bulle arrière (bleu clair) -->
                      <rect x="2.5" y="5" width="12" height="9" rx="3" fill="#E0F2FE"/>
                      <path d="M7 14l-2.2 2.2V14" fill="#E0F2FE"/>

                      <!-- bulle avant (bleu YUZ) -->
                      <rect x="9.5" y="8" width="12" height="9" rx="3" fill="#0EA5E9"/>
                      <path d="M18 17l2.2 2.2V17" fill="#0EA5E9"/>

                      <!-- Y moderne, plus petit -->
                      <!-- pointes plus proches + tige raccourcie -->
                      <path d="M6.2 7.2 L8.5 9.2 M10.8 7.2 L8.5 9.2 M8.5 9.2 L8.5 12.0"
                            stroke="#0284C7" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/>

                      <!-- 文 en blanc, centré dans la bulle avant -->
                      <text x="15.5" y="13.9" text-anchor="middle"
                            font-size="6.1" font-weight="700" fill="#FFFFFF"
                            font-family="system-ui, -apple-system, 'Segoe UI', 'Noto Sans CJK JP', sans-serif">文</text>
                    </svg>
                  </span>
                  <div class="yuz-brand-text">
                    <strong>YUZ</strong>
                    <span class="yuz-editor-title">Translation Editor</span>
                  </div>
                </div>
                <button type="button" class="yuz-btn yuz-btn--danger yuz-header-close-square" @click="closeModal">Close</button>
              </div>
              <div class="yuz-header-row yuz-header-row--mode">
                <div class="yuz-mode">
                  <button type="button" class="yuz-btn yuz-btn--mode" @click="cycleMode" :title="modeCycleTooltip" :aria-label="'Cycle mode (current: ' + currentModeLabel + ')'">
                    <span class="yuz-mode-current">{{ currentModeLabel }}</span>
                    <span class="yuz-mode-cycle" aria-hidden="true">&#8635;</span>
                  </button>
                  <span class="yuz-mode-label">Mode</span>
                </div>
                <button type="button" id="yuz-te-strings" class="yuz-btn yuz-btn--ghost yuz-header-strings" data-yuz-open-strings>Strings</button>
              </div>
              <div class="yuz-header-row yuz-header-row--main">
                <button type="button" class="yuz-btn yuz-btn--ghost yuz-header-lock yuz-lock" data-yuz-lock @click="toggleLock" :aria-pressed="isLocked" :title="lockTooltip">{{ lockLabel }}</button>
                <button v-if="ADV" type="button" class="yuz-btn yuz-btn--ghost yuz-header-reset" @click="resetPosition">Reset</button>
                <button
                  type="button"
                  class="yuz-btn yuz-btn--ghost yuz-header-inspector"
                  :class="{ 'yuz-btn--halo': inspectorActive }"
                  @click="toggleInspector"
                  :aria-pressed="inspectorActive"
                  title="Inspector"
                >Inspector</button>
                <button
                  type="button"
                  class="yuz-btn yuz-btn--ghost yuz-header-preview"
                  :class="{ 'is-active': previewActive, 'yuz-btn--halo': previewActive }"
                  @click="togglePreview"
                  :title="previewTooltip"
                >{{ previewLabel }}</button>
                <button type="button" class="yuz-btn yuz-btn--warning yuz-header-undo" @click="placeholderUndo">Undo</button>
              </div>
            </div>

            <div v-if="error" class="yuz-empty">{{ error }}</div>
            <div v-else-if="emptyState" class="yuz-empty">{{ emptyState }}</div>
            <div v-else class="yuz-body">
              <div class="yuz-scroll">
                <section class="yuz-section yuz-section--progress">
                  <div class="yuz-section-row">
                    <span>Translation Progress</span>
                    <button type="button" @click="refresh" class="yuz-btn yuz-btn--ghost">Refresh</button>
                  </div>
                  <div class="yuz-progress-bar">
                    <span :style="{ width: (progress || 0) + '%' }"></span>
                  </div>
                  <div class="yuz-caption yuz-progress-label">{{ progress }}% Complete</div>
                  <div
                    v-if="autoBatch && autoBatch.running && ((autoBatch.done|0) + (autoBatch.skipped|0)) === 0"
                    class="yuz-loader" aria-live="polite" aria-label="Starting translation…">
                    <div class="yuz-spinner" aria-hidden="true"></div>
                    <div class="yuz-caption">Starting…</div>
                  </div>
                </section>

                <section class="yuz-section yuz-section--lang">
                  <div class="yuz-lang-grid">
                    <div class="yuz-field">
                      <label class="yuz-label">From</label>
                      <select name="yuz-from" class="yuz-select" :value="sourceLanguage" @change="onFromChanged($event.target.value)">
                        <option v-for="o in fromChoices" :key="'f_'+o.value" :value="o.value">{{ o.label }}</option>
                      </select>
                    </div>
                    <button type="button" class="yuz-btn yuz-btn--ghost yuz-btn--circle" :disabled="!canInvert" @click="canInvert && invertLangs()" :title="swapTooltip" aria-label="Invert From/To">⇄</button>
                    <div class="yuz-field">
                      <label class="yuz-label">To</label>
                      <select name="yuz-to" class="yuz-select" :value="currentLanguage" @change="changeLang($event.target.value)">
                        <option v-for="o in toChoices" :key="'t_'+o.value" :value="o.value">{{ o.label }}</option>
                      </select>
                    </div>
                  </div>
                  <div class="yuz-caption">Element {{ index>=0 ? index + 1 : 0 }} of {{ items.length }}</div>
                </section>

                <section class="yuz-section yuz-section--fields">
                  <div class="yuz-field yuz-field--original">
                    <label class="yuz-label">Original</label>
                    <textarea name="yuz-original" class="yuz-textarea is-original" :value="current && current.original || ''" readonly></textarea>
                  </div>
                  <div class="yuz-field yuz-field--translation">
                    <label class="yuz-label">Translation</label>
                    <div class="yuz-translation">
                      <div class="yuz-translation-box">
                        <textarea name="yuz-translation" class="yuz-textarea yuz-translation-textarea" :value="editedValue" @input="editedValue=$event.target.value" @focus="current && fetchSuggestions(current.original)"></textarea>
                        <button type="button" class="yuz-btn yuz-btn--ghost yuz-btn--circle yuz-float-prev" aria-label="Previous item" @click="prev">←</button>
                        <button type="button" class="yuz-btn yuz-btn--ghost yuz-btn--circle yuz-float-next" aria-label="Next item" @click="next">→</button>
                      </div>
                      <div v-if="suggestions.length" class="yuz-suggestions">
                        <div class="yuz-label">Suggestions</div>
                        <ul>
                          <li v-for="(s,i) in suggestions" :key="'sug_'+i" @click="applySuggestion(s)">{{ s }}</li>
                        </ul>
                      </div>
                      <div class="yuz-actions">
                        <div class="yuz-actions-left">
                          <button type="button" @click="prev" class="yuz-btn yuz-btn--ghost yuz-btn--circle" title="Previous item">←</button>
                          <button type="button" @click="next" class="yuz-btn yuz-btn--ghost yuz-btn--circle" title="Next item">→</button>
                        </div>
                        <div class="yuz-actions-right">
                          <button
                            type="button"
                            @click="autoTranslateAll"
                            :aria-busy="!!(autoBatch && autoBatch.running)"
                            :class="['yuz-btn','yuz-btn--accent', (autoBatch && autoBatch.running) ? 'yuz-busy' : '']"
                          >Auto Translate</button>
                          <button type="button" @click="saveOne(current)" class="yuz-btn yuz-btn--primary">Save</button>
                          <button type="button" @click="publishOne" class="yuz-btn yuz-btn--ghost">Publish</button>
                        </div>
                      </div>
                    </div>
                  </div>
                </section>
              <section class="yuz-section yuz-section--search">
                  <div class="yuz-search" style="gap:10px;">
                    <input
                      class="yuz-search-input"
                      v-model.trim="query"
                      placeholder="Search in this page…"
                      @keydown.enter.prevent="search"
                    />
                    <button
                      type="button"
                      class="yuz-btn yuz-btn--ghost"
                      :disabled="loading || !query"
                      @click="search">
                      Search
                    </button>

                    <span v-if="totalCount>0" class="yuz-search-count">{{ totalCount }} results</span>
                    <span v-if="loading" class="yuz-search-loading">Loading…</span>
                  </div>
                </section>
              </div>
            </div>
          </div>
        </div>
      `
    });

    window.YUZ_EDITOR_APP = app;
    const initialMode = options && options.mode ? canonicalMode(options.mode) : null;
    if (initialMode) {
      app.$yuzCompact = initialMode === 'essential';
    } else {
      app.$yuzCompact = !!(options && options.compact);
    }
    setUiDataset(initialMode || app.activeMode);
    return app;
  };

  // -------------------------------
  // Boot once DOM is ready
  // -------------------------------
  const wait = (ok, cb, tries = 100, interval = 80) => {
    const id = setInterval(() => {
      if (ok()) { clearInterval(id); cb(); }
      else if (--tries <= 0) {
        clearInterval(id);
        log('critical', '[YUZ][TE] Dépendances non disponibles.');
        const lateTry = () => { if (ok()) { cb(); } };
        if (document.readyState === 'complete') { setTimeout(lateTry, 1200); }
        else {
          window.addEventListener('load', () => setTimeout(lateTry, 600), { once: true });
        }
      }
    }, interval);
  };

  const cleanupOverlay = () => {
    const hadApp = !!window.YUZ_EDITOR_APP || currentOverlayMode !== null;
    try { window.YUZ_EDITOR_APP?.unmount?.(); } catch (e) { }
    try { window.YUZ_EDITOR_APP?.$destroy?.(); } catch (e) { }
    const modal = document.querySelector('#yuz-editor-container .yuz-modal');
    if (modal) modal.remove();
    const host = document.getElementById('yuz-editor-container');
    if (host) host.remove();
    window.YUZ_EDITOR_APP = null;
    currentOverlayMode = null;
    try { delete document.documentElement.dataset.yuzEdit; } catch (_) { }
    if (hadApp) { try { yuz_release_console.log('[YUZ-TE] unmounted'); } catch (_) { } }
  };

  const assignOverlayUnmount = () => {
    if (!window.__YUZ_UI_ACTIVE__) return;
    window.__YUZ_UI_ACTIVE__.unmount = () => {
      try { cleanupOverlay(); } catch (_) { }
    };
  };

  const mountOverlay = ({ compact = false, mode = null } = {}) => {
    const desired = mode ? canonicalMode(mode) : (compact ? 'essential' : 'advanced');
    const allowedModes = ['advanced', 'essential', 'xpress', 'xpress-progress'];
    if (!allowedModes.includes(desired)) {
      cleanupOverlay();
      return;
    }
    if (currentOverlayMode === desired && window.YUZ_EDITOR_APP) {
      assignOverlayUnmount();
      return;
    }
    wait(
      () => typeof $ !== 'undefined' && typeof Vue !== 'undefined',
      () => {
        log('success', '[YUZ][TE] Dépendances OK. Mounting editor…');
        cleanupOverlay();
        const app = mountApp({ mode: desired });
        if (app) {
          app.$yuzCompact = desired === 'essential';
          app.activeMode = desired;
          app.updateMaskLock();
          // Filet de sécurité : s'assurer que l'overlay est visible et interactif
          try {
            const host = document.getElementById('yuz-editor-container');
            const modal = host ? host.querySelector('.yuz-modal') : null;
            if (host) {
              host.style.pointerEvents = 'auto';
              host.style.display = 'block';
              host.style.visibility = 'visible';
              host.removeAttribute('aria-hidden');
            }
            if (modal) {
              modal.style.display = 'flex';
              modal.style.opacity = '1';
              modal.style.visibility = 'visible';
            }
          } catch (_) { }
        }
        try { document.documentElement.dataset.yuzEdit = '1'; } catch (_) { }
        currentOverlayMode = desired;
        assignOverlayUnmount();
        try {
          const label = desired === 'essential'
            ? '(essential)'
            : desired === 'xpress' || desired === 'xpress-progress'
              ? '(xpress)'
              : '(advanced)';
        } catch (_) { }
      }
    );
  };

  const onMount = (ev) => {
    const detail = (ev && ev.detail) || {};
    const requested = detail.mode || detail.legacyMode || detail;
    const mode = canonicalMode(requested || 'advanced');
    setUiDataset(mode);
    if (mode === 'advanced' || mode === 'essential' || mode === 'xpress' || mode === 'xpress-progress') {
      mountOverlay({ mode });
      return;
    }
    cleanupOverlay();
  };

  if (window.YUZ_UI) {
    document.addEventListener('yuz:ui:mount', onMount);
    if (window.__YUZ_UI_ACTIVE__ && window.__YUZ_UI_ACTIVE__.id) {
      onMount({ detail: { mode: window.__YUZ_UI_ACTIVE__.id } });
    }
  } else {
    document.addEventListener('yuz:ui:mount', onMount);
    if (/[?&]yuz-edit-translation=1\b/.test(window.location.search)) {
      document.addEventListener('DOMContentLoaded', () => mountOverlay({ mode: 'advanced' }), { once: true });
    }
  }

})(window, document);

(function () {
  const ROOT = document.querySelector('#yuz-editor-container') || document.body;
  const targets = [
    '#yuz-te-advanced.yuz-modal .yuz-actions-right .yuz-btn--accent',
    '#yuz-te-essential.yuz-modal .yuz-actions-right .yuz-btn--accent',
    '#yuz-te-xpress.yuz-modal .yuz-actions-right .yuz-btn--accent'
  ].join(',');

  function relabel() {
    document.querySelectorAll(targets).forEach(btn => {
      if (btn.dataset._yuzAutoShortened) return;
      btn.dataset._yuzAutoShortened = '1';
      btn.dataset._yuzPrev = (btn.textContent || '').trim();
      btn.textContent = 'Auto';
      btn.setAttribute('aria-label', 'Auto translate');
      btn.classList.add('yuz-icon-translate');
      // keep it compact
      btn.style.minWidth = '0';
    });
  }

  relabel();
  new MutationObserver(relabel).observe(ROOT, { childList: true, subtree: true });
})();

// === SMART SEARCH + INSTANT v1.0 ===
(function () {
  const ROOT = document.querySelector('#yuz-editor-container') || document.body;
  const targets = [
    '#yuz-te-advanced.yuz-modal .yuz-actions-right .yuz-btn--accent',
    '#yuz-te-essential.yuz-modal .yuz-actions-right .yuz-btn--accent',
    '#yuz-te-xpress.yuz-modal .yuz-actions-right .yuz-btn--accent'
  ].join(',');

  function relabel() {
    document.querySelectorAll(targets).forEach(btn => {
      if (btn.dataset._yuzAutoShortened) return;
      btn.dataset._yuzAutoShortened = '1';
      btn.dataset._yuzPrev = (btn.textContent || '').trim();
      btn.textContent = 'Auto';
      btn.setAttribute('aria-label', 'Auto translate');
      btn.classList.add('yuz-icon-translate');
      btn.style.minWidth = '0';
    });
  }

  relabel();
  new MutationObserver(relabel).observe(ROOT, { childList: true, subtree: true });
})();

// === SMART SEARCH — ONE BUTTON + OCCURRENCES (legacy bundle) v2.3 ===
(function () {
  if (typeof window === 'undefined') return;
  if (window.__YUZ_SMART_SEARCH_OBSERVER__) return;

  const toastFallback = (msg, lvl = 'info') => {
    try {
      const method = lvl === 'error' ? 'error' : lvl === 'warning' ? 'warn' : 'log';
    } catch (_) { }
  };

  const canon = (s) => String(s || '').replace(/\s+/g, ' ').trim();
  const fold = (s) => canon(s)
    .replace(/-/g, ' ')
    .normalize('NFD').replace(/[\u0300-\u036f]/g, '')
    .toLowerCase();

  function findOccurrences(text, term) {
    const T = fold(term);
    const S = fold(text);
    if (!T || !S) return [];
    const out = [];
    let i = 0;
    while (i <= S.length - T.length) {
      const k = S.indexOf(T, i);
      if (k < 0) break;
      out.push({ start: k, end: k + T.length });
      i = k + T.length;
    }
    return out;
  }

  function focusOccurrence(app, occ) {
    if (!occ) return;
    app.$nextTick(() => {
      try {
        const src = app.$el && app.$el.querySelector('textarea[name="yuz-original"]');
        if (!src) return;
        src.focus();
        requestAnimationFrame(() => src.setSelectionRange(occ.start, occ.end));
      } catch (_) { }
    });
  }

  function normalizePhaseName(phase) {
    const raw = String(phase || '').trim().toLowerCase();
    if (!raw) return null;
    if (raw === 'classic') return 'page';
    return raw;
  }

  function ensurePhaseState(app) {
    const configured = (() => {
      const cfg = window.yuzTraSettings && window.yuzTraSettings.smart_search_phase_order;
      if (Array.isArray(cfg)) return cfg;
      if (typeof cfg === 'string') return cfg.split(/[,\s]+/).filter(Boolean);
      return [];
    })();

    const order = [];
    const push = (phase) => {
      const normalized = normalizePhaseName(phase);
      if (!normalized) return;
      if (!order.includes(normalized)) order.push(normalized);
    };

    configured.forEach(push);
    if (Array.isArray(app.phaseOrder)) app.phaseOrder.forEach(push);
    ['instant', 'page'].forEach(push);

    app.phaseOrder = order.length ? order : ['page'];

    if (!app.pageByPhase) app.pageByPhase = {};
    if (!app.totalByPhase) app.totalByPhase = {};
    if (!app.hasMoreByPhase) app.hasMoreByPhase = {};

    app.phaseOrder.forEach((phase) => {
      if (!(phase in app.pageByPhase)) app.pageByPhase[phase] = 0;
      if (!(phase in app.totalByPhase)) app.totalByPhase[phase] = 0;
      if (!(phase in app.hasMoreByPhase)) app.hasMoreByPhase[phase] = false;
    });

    const maxIndex = Math.max(0, app.phaseOrder.length - 1);
    app.phaseIndex = Math.min(maxIndex, Math.max(0, app.phaseIndex | 0));
  }

  function installSmartSearch(app) {
    if (!app || app._smartSearchInstalled) return;

    const toast = window.toast || toastFallback;
    ensurePhaseState(app);

    app._occ = { list: [], idx: -1, key: null, term: '' };

    app._recomputeOcc = function () {
      const q = canon(this.searchSnapshot || '');
      if (!q || !this.current) {
        this._occ = { list: [], idx: -1, key: null, term: fold(q) };
        return;
      }
      const key = String(this.current.id || this.current.string_id || this.current.translation_id || this.current.original || '');
      const list = findOccurrences(this.current.original || '', q);
      this._occ = { list, idx: list.length ? 0 : -1, key, term: fold(q) };
      if (list.length) focusOccurrence(this, list[0]);
    };

    const focusOrig = app.focusSearchHit && app.focusSearchHit.bind(app);
    app.focusSearchHit = function (hit) {
      if (focusOrig) focusOrig(hit);
      this._recomputeOcc();
    };

    app.smartSearchStart = async function () {
      const q = canon(this.query || this.searchQuery || '');
      if (!q) { toast('Enter text to search.'); return; }
      ensurePhaseState(this);
      if (q !== this.searchSnapshot) this.resetSmart(q);

      const ok = await this.runSearchOnce();
      if (!ok) return;

      if (Array.isArray(this.searchResults) && this.searchResults.length) {
        this.searchIndex = 0;
        this.focusSearchHit(this.searchResults[0]);
      } else {
        this._recomputeOcc();
      }
    };

    app.smartSearchNext = async function () {
      const q = canon(this.searchSnapshot || this.query || this.searchQuery || '');
      if (!q) { toast('Enter text to search.'); return; }

      ensurePhaseState(this);

      const ph = this.currentPhase ? this.currentPhase() : (this.phaseOrder?.[this.phaseIndex] || 'page');
      const rows = Array.isArray(this.searchResults) ? this.searchResults : [];
      const hasMorePage = !!(this.hasMoreByPhase && this.hasMoreByPhase[ph]);
      const curIdx = this.searchIndex | 0;

      if (this._occ.list.length && this._occ.idx >= 0 && this._occ.idx < this._occ.list.length - 1) {
        this._occ.idx += 1;
        focusOccurrence(this, this._occ.list[this._occ.idx]);
        return;
      }

      if (rows.length && curIdx < rows.length - 1) {
        this.searchIndex = curIdx + 1;
        this.focusSearchHit(rows[this.searchIndex]);
        return;
      }

      if (hasMorePage) {
        this.pageByPhase[ph] = (this.pageByPhase[ph] | 0) + 1;
        const ok = await this.runSearchOnce();
        if (!ok) return;
        return;
      }

      if (this.phaseIndex < (this.phaseOrder?.length || 2) - 1) {
        this.phaseIndex += 1;
        const ok = await this.runSearchOnce();
        if (!ok) toast('No results in next phase.');
        return;
      }

      toast('End of results.');
    };

    app._smartSearchInstalled = true;
  }

  let lastApp = null;
  const hydrate = () => {
    const current = window.YUZ_EDITOR_APP;
    if (current && current !== lastApp) {
      lastApp = current;
      installSmartSearch(current);
    }
  };

  const observer = setInterval(hydrate, 250);
  window.__YUZ_SMART_SEARCH_OBSERVER__ = observer;
  hydrate();

  window.addEventListener('yuz:ui:mount', () => { setTimeout(hydrate, 0); });
  window.addEventListener('yuz:ui:unmount', () => { lastApp = null; });
  window.addEventListener('beforeunload', () => clearInterval(observer), { once: true });
})();

// === INSTANT INGEST (persist) — non-ESM, exposé dev ===
(function () {
  var Y = window.yuzTE || {};
  var app = window.YUZ_EDITOR_APP || {};
  var from = app.sourceLanguage || 'auto';
  var to = (app.getCurrentLanguage && app.getCurrentLanguage()) || (window.yuzTraSettings?.translation_langs?.[0] || '');

  async function instantIngest(list) {
    var clean = s => String(s || '').replace(/[\r\n\t]+/g, ' ').trim();
    var payload = (list || []).map((t, i) => ({ i, text: clean(t) })).filter(r => r.text);

    if (!payload.length || !to) {
      return {};
    }

    var reqId = (crypto && crypto.randomUUID ? crypto.randomUUID() : Math.random().toString(36).slice(2));
    var params = {
      req_id: reqId,
      payload: JSON.stringify(payload),
      source: from || 'auto',
      target: to,
      context: 'instant',
      persist: '1'
    };

    try {
      var response = await yuzPost('yuz_tra_tm_translate', params);
      if (typeof response === 'string') {
        try { response = JSON.parse(response); } catch (_) { response = { raw: response }; }
      }
      return response && response.data;
    } catch (error) {
      return {};
    }
  }

  // exposé (debug / scripts console)
  window.instantIngest = instantIngest;
})();

// === Preserve overlay param across navigation (front) ===
(function (window, document) {
  try {
    // Only when overlay is active
    if (!/[?&]yuz-edit-translation=1\b/.test(window.location.search)) return;

    const isSameOrigin = (href) => {
      try { return new URL(href, window.location.origin).origin === window.location.origin; } catch (_) { return false; }
    };

    const preserveHandler = function (e) {
      const a = e.target && e.target.closest && e.target.closest('a[href]');
      if (!a) return;

      // Skip non-HTTP(S)/special links
      const href = a.getAttribute('href') || '';
      if (!href || href.startsWith('#') || href.startsWith('mailto:') || href.startsWith('tel:') || href.startsWith('javascript:')) return;

      // Only rewrite same-origin links
      if (!isSameOrigin(href)) return;

      const url = new URL(href, window.location.origin);
      if (!url.searchParams.has('yuz-edit-translation')) {
        url.searchParams.set('yuz-edit-translation', '1');
        a.setAttribute('href', url.href);
      }
    };
    // Store ref to allow removal on close
    window.__yuzPreserveParamHandler__ = preserveHandler;
    document.addEventListener('click', preserveHandler, true); // capture phase to adjust before default navigation
  } catch (_) {
    // silent
  }
})(window, document);

/*!
 * yuz-translate-dom-changes.js — Unified-Plus (2025-11-12)
 * Single engine replacing legacy/v8/v9 while preserving telemetry & boot logic.
 * - Exposes legacy API: window.YUZ_Translator (+ YUZ_TranslatorReady Promise)
 * - v9-style walker + MutationObserver with strong text-node filters
 * - Batch AJAX (yuz_get_regular) for both batch & single lookups
 * - Sends multiple nonce keys: nonce, ajax_nonce, yuz_tra_nonce, yuz_nonce
 * - Emits 'yuz:translator:ready' when ready
 * - Keeps PREPATCH_VIDEO, BOOT_FIX, TRACE_METRICS, ERROR_TELEMETRY helpers
 */
(function () {
  try {
    // Allow debug DOM logging via query param ?debug_dom=1
    try {
      const qs = new URLSearchParams((window.location && window.location.search) || '');
      if (qs.has('debug_dom')) {
        window.yuzTraSettings = window.yuzTraSettings || {};
        window.yuzTraSettings.debug_dom = true;
        window.yuzTraSettings.dom_log = true;
      }
    } catch (_) { /* ignore */ }
    console.log('[YUZ_DOM][BOOT_SMOKE] URL =', location.href);
    console.log('[YUZ_DOM][BOOT_SMOKE] keys(yuzTraSettings) =',
      window.yuzTraSettings ? Object.keys(window.yuzTraSettings) : 'MISSING'
    );
    if (!window.yuzTraSettings) {
      console.warn('[YUZ_DOM][BOOT_SMOKE] ABORT: window.yuzTraSettings is missing.');
      return;
    }
  } catch (e) {
    try { console.error('[YUZ_DOM][BOOT_SMOKE] exception', e); } catch (_) { }
  }
  /* ============================================================
   * [YUZ_PREPATCH_VIDEO] blocks early autoplay rejection
   * ============================================================ */
  (function () {
    const safePlay = (video) => {
      if (!video || typeof video.play !== 'function') return;
      try {
        const p = video.play();
        if (p && typeof p.catch === 'function') {
          p.catch(err => {
            try { console.debug('[YUZ_PREPATCH_VIDEO] Autoplay blocked:', err && err.message ? err.message : err); } catch (_) { }
            video.muted = true;
            video.setAttribute('playsinline', '');
            try { video.pause(); } catch (_) { }
          });
        }
      } catch (e) {
        try { console.debug('[YUZ_PREPATCH_VIDEO] Early play() error:', e.message); } catch (_) { }
      }
    };
    const maybeFix = () => { try { document.querySelectorAll('video').forEach(v => safePlay(v)); } catch (_) { } };
    if (document.readyState === 'loading') document.addEventListener('readystatechange', maybeFix);
    else maybeFix();
    window.addEventListener('unhandledrejection', (ev) => {
      try {
        const msg = (ev && ev.reason && ev.reason.message) || (ev && ev.reason && ev.reason.toString && ev.reason.toString()) || (ev && ev.reason) || '';
        if (msg && /play\(\)/i.test(msg)) { console.debug('[YUZ_PREPATCH_VIDEO] prevented global rejection:', msg); if (typeof ev.preventDefault === 'function') ev.preventDefault(); }
      } catch (_) { }
    });
  })();

  /* ============================================================
   * domLogEvent helper (multi-nonce) — before guards so BOOT_FIX can use it
   * ============================================================ */
  function __yuzGetMultiNonce() {
    try {
      const s = window.yuzTraSettings || {};
      const nn = s.nonces || {};
      const nval = nn.ajax_nonce || nn.yuz_tra_nonce || nn.yuz_nonce || nn.nonce || '';
      return {
        nonce: (nn.yuz_tra_nonce || nval),
        ajax_nonce: nval,
        yuz_tra_nonce: nval,
        yuz_nonce: nval
      };
    } catch (_) {
      return {};
    }
  }

  function canonicalPageUrl(rawUrl) {
    try {
      const omit = ['yuz-edit-translation', 'yuz-edit-translation-url', 'yuzprobe', 'debug_dom', 'dom_log', 'yuzdebug'];
      const u = new URL(rawUrl || (location && location.href) || '', window.location.origin);
      omit.forEach((p) => u.searchParams.delete(p));
      return u.toString().split('#')[0];
    } catch (_) {
      try { return location && location.href ? location.href.split('#')[0] : ''; } catch (e) { return ''; }
    }
  }

  function sanitizeBlockKeyPart(value) {
    try {
      return String(value || '')
        .trim()
        .replace(/\s+/g, '-')
        .replace(/[^A-Za-z0-9:_-]+/g, '-')
        .replace(/-+/g, '-')
        .replace(/^-+|-+$/g, '')
        .slice(0, 80);
    } catch (_) {
      return '';
    }
  }

  function deriveNodeFingerprint(el) {
    try {
      if (!el || el.nodeType !== 1) return '';
      const bits = [];
      const tag = sanitizeBlockKeyPart((el.tagName || '').toLowerCase());
      const role = sanitizeBlockKeyPart(el.getAttribute && el.getAttribute('role'));
      const type = sanitizeBlockKeyPart(el.getAttribute && el.getAttribute('type'));
      const classes = el.classList ? Array.from(el.classList).slice(0, 3).map(sanitizeBlockKeyPart).filter(Boolean).join('.') : '';
      const parent = el && el.parentElement ? el.parentElement : null;
      const parentTag = sanitizeBlockKeyPart(parent && parent.tagName ? parent.tagName.toLowerCase() : '');
      const parentClasses = parent && parent.classList ? Array.from(parent.classList).slice(0, 2).map(sanitizeBlockKeyPart).filter(Boolean).join('.') : '';
      let index = 0;
      if (parent && parent.children) {
        const siblings = Array.from(parent.children).filter((node) => node && node.tagName === el.tagName);
        const siblingIndex = siblings.indexOf(el);
        index = siblingIndex >= 0 ? siblingIndex : 0;
      }
      if (tag) bits.push(tag);
      if (role) bits.push(`r-${role}`);
      if (type) bits.push(`t-${type}`);
      if (classes) bits.push(`c-${classes}`);
      if (parentTag) bits.push(`p-${parentTag}`);
      if (parentClasses) bits.push(`pc-${parentClasses}`);
      bits.push(`i-${index}`);
      return bits.filter(Boolean).join('__').slice(0, 120);
    } catch (_) {
      return '';
    }
  }

  function buildBlockKeyFromNode(el, attr) {
    try {
      const attrKey = sanitizeBlockKeyPart(String(attr || '').replace(/^:/, ''));
      const attribute = attrKey ? `:${attrKey}` : '';
      const form = el && el.closest ? el.closest('form') : null;
      const modal = el && el.closest ? el.closest('[role="dialog"],dialog,[aria-modal="true"],[data-modal],[data-component*="modal"],.modal') : null;
      let containerType = '';
      let containerId = '';
      if (form) {
        containerType = 'form';
        containerId = form.id || form.name || form.getAttribute('data-form-type') || form.getAttribute('data-form-channel') || '';
      } else if (modal) {
        containerType = 'modal';
        containerId = modal.id || modal.getAttribute('data-modal-id') || modal.getAttribute('data-component') || '';
      }
      if (!containerType) containerType = 'node';
      if (!containerId) containerId = el && el.id ? el.id : '';
      let fieldKey = el && (
        el.getAttribute('name')
        || el.getAttribute('id')
        || el.getAttribute('for')
        || el.getAttribute('data-i18n-key')
        || el.getAttribute('aria-controls')
        || el.getAttribute('data-modal-id')
        || ''
      ) || '';
      containerId = sanitizeBlockKeyPart(containerId);
      fieldKey = sanitizeBlockKeyPart(fieldKey);
      if (!fieldKey) fieldKey = deriveNodeFingerprint(el);
      const base = [containerType, containerId, fieldKey].join('|');
      return base + attribute;
    } catch (_) {
      const attrKey = sanitizeBlockKeyPart(String(attr || '').replace(/^:/, ''));
      return attrKey ? `:${attrKey}` : '';
    }
  }

  let PENDING_BLOCK_KEYS = new Map();

  function domLogEvent(event, ctx, langOverride) {
    try {
      const s = window.yuzTraSettings || {};
      const allowNetLog = !!(s.debug_dom || s.dom_log || s.debug);
      if (!allowNetLog) return; // avoid flooding admin-ajax unless explicitly enabled
      if (!s.ajax_url) return;
      if (!window.jQuery || !jQuery.post) return;

      const normalizeLangLite = (v) => (v ? String(v).trim().replace(/-/g, '_') : '');
      const candidateLang = normalizeLangLite(
        langOverride
        || s.current_language
        || s.default_language
        || (document.documentElement && document.documentElement.lang)
        || (navigator.languages && navigator.languages[0])
        || navigator.language
        || ''
      );

      const payload = {
        action: 'yuz_dom_log',
        event,
        context: JSON.stringify(ctx || {}),
        page_url: canonicalPageUrl()
      };

      if (candidateLang) payload.lang = candidateLang;

      Object.assign(payload, __yuzGetMultiNonce());
      jQuery.post(s.ajax_url, payload).fail(function () {});
    } catch (_) { /* noop */ }
  }

  /* ============================================================
   * Hard guard: do nothing without minimal settings
   * ============================================================ */
  (function (w) {
    var s = w && w.yuzTraSettings;
    if (!s || typeof s.ajax_url !== 'string' || !s.nonces) {
      console.error('[YUZ-TRA][TRANSVERSE] yuzTraSettings missing or invalid. Expected: {ajax_url:string, nonces:object}');
      try { domLogEvent('missing_settings', { ajax_url: !!(s && s.ajax_url), nonces: !!(s && s.nonces) }); } catch (_) { }
      return;
    }
  })(window);

  /* ============================================================
   * [YUZ_DOM_BOOT_FIX] Translator readiness watcher (instrumented)
   * ============================================================ */
  (function () {
    const fallbackLog = (event, payload = {}) => { try { console.debug('[YUZ_DOM_BOOT_FIX]', event, payload); } catch (_) { } };
    if (!window.__YUZ_DOM_LOG) {
      // When available, forward to admin-ajax with multi-nonce
      window.__YUZ_DOM_LOG = (evt, data) => {
        try { domLogEvent(evt, data); } catch (_) { fallbackLog(evt, data); }
      };
    }
    const log = (event, payload = {}) => {
      const logger = window.__YUZ_DOM_LOG || fallbackLog;
      try { logger(event, payload); } catch (_) { fallbackLog(event, payload); }
    };

    const safeVideoAutoplay = () => {
      const vids = document.querySelectorAll('video[autoplay]'); if (!vids.length) return;
      vids.forEach(v => {
        try {
          v.muted = true; v.setAttribute('playsinline', '');
          const p = v.play(); if (p && typeof p.catch === 'function') p.catch(err => log('video_autoplay_blocked', { src: v.src, message: err && err.message }));
        } catch (e) { log('video_autoplay_error', { src: v.src, message: e && e.message }); }
      });
      log('video_autoplay_init', { count: vids.length });
    };
    if (document.readyState === 'complete' || document.readyState === 'interactive') safeVideoAutoplay();
    else document.addEventListener('DOMContentLoaded', safeVideoAutoplay);

    const MAX_WAIT_MS = 15000, STEP_START_MS = 50, STEP_MULT = 1.3;
    let waited = 0, step = STEP_START_MS;
    const waitForTranslator = (resolve) => {
      try { if (window.YUZ_Translator && typeof window.YUZ_Translator.scan_now === 'function') { log('translator_ready', { waited_ms: waited }); resolve(true); return; } } catch (_) { }
      if (waited >= MAX_WAIT_MS) { log('translator_timeout', { waited_ms: waited }); resolve(false); return; }
      waited += step; step = Math.min(step * STEP_MULT, 1000);
      requestAnimationFrame(() => waitForTranslator(resolve));
    };
    const translatorPromise = new Promise((resolve) => { log('translator_wait_start', { max_ms: MAX_WAIT_MS }); waitForTranslator(resolve); });
    if (!window.YUZ_TranslatorReady || typeof window.YUZ_TranslatorReady.then !== 'function') window.YUZ_TranslatorReady = translatorPromise;
    else window.YUZ_TranslatorReady = window.YUZ_TranslatorReady.then(() => translatorPromise);
  })();

  /* ============================================================
   * [YUZ_TRACE_METRICS] Performance logging for Translator readiness
   * ============================================================ */
  (function () {
    const trace = (event, payload = {}) => {
      const logger = window.__YUZ_DOM_LOG || ((evt, data) => { try { console.debug('[YUZ_TRACE]', evt, data); } catch (_) { } });
      logger(event, Object.assign({ trace: true }, payload));
    };
    const perf = window.YUZ_TracePerf = window.YUZ_TracePerf || { start: (performance && performance.now ? performance.now() : Date.now()), domContentLoaded: null, translatorReady: null, total: null };
    const markDomReady = () => { if (perf.domContentLoaded !== null) return; perf.domContentLoaded = (performance && performance.now ? performance.now() : Date.now()); trace('perf_dom_ready', { t_ms: (perf.domContentLoaded - perf.start).toFixed(1) }); };
    if (document.readyState === 'complete' || document.readyState === 'interactive') markDomReady();
    else document.addEventListener('DOMContentLoaded', markDomReady, { once: true, passive: true });
    (function attachPerfWatcher() {
      if (!window.YUZ_TranslatorReady || typeof window.YUZ_TranslatorReady.then !== 'function') { setTimeout(attachPerfWatcher, 100); return; }
      window.YUZ_TranslatorReady.then(ok => {
        perf.translatorReady = (performance && performance.now ? performance.now() : Date.now());
        perf.total = (perf.translatorReady - perf.start).toFixed(1);
        const sinceDom = perf.domContentLoaded !== null ? (perf.translatorReady - perf.domContentLoaded).toFixed(1) : perf.total;
        trace('perf_translator_ready', { ready: ok, t_total_ms: perf.total, t_since_dom_ms: sinceDom });
      });
    })();
  })();

  /* ============================================================
   * Unified Engine
   * ============================================================ */
  console.log("YUZ-TRANS BEFORE LOAD :: window.yuzTraSettings =", window.yuzTraSettings);
  console.log("YUZ-TRANS BEFORE LOAD :: translations =", window.yuzTraSettings && window.yuzTraSettings.translations);
  console.log("YUZ-TRANS BEFORE LOAD :: current_language =", window.yuzTraSettings && window.yuzTraSettings.current_language);
  (() => {
    try {
      // Singleton guard
      if (window.__YUZ_DOM_ENGINE && window.__YUZ_DOM_ENGINE !== 'unified') return;
      if (window.__YUZ_DOM_ENGINE === 'unified') return;
      window.__YUZ_DOM_ENGINE = 'unified';

      // Utils
      const now = () => (typeof performance !== 'undefined' && performance.now) ? performance.now() : Date.now();
      const safeEmit = (name, detail) => { try { document.dispatchEvent(new CustomEvent(`yuz:${name}`, { detail })); } catch (_) { } };
      const capture = (evt, payload) => {
        try {
          if (typeof window.YUZ_Capture === 'function') return window.YUZ_Capture(evt, payload);
          safeEmit('capture', { evt, payload });
        } catch (_) { }
      };

      // Settings
      const settings = window.yuzTraSettings || {};
      const translationsRoot = settings.translations || {};
      const normalizeLang = (value) => {
        if (!value) return '';
        const raw = String(value).trim().replace(/-/g, '_');
        if (!raw) return '';
        const parts = raw.split('_').filter(Boolean);
        if (!parts.length) return '';
        if (parts.length === 1) {
          const root = parts[0].toLowerCase();
          return `${root}_${root === 'en' ? 'US' : root.toUpperCase()}`;
        }
        return `${parts[0].toLowerCase()}_${(parts[1] || parts[0]).toUpperCase()}`;
      };
      const normalizeList = (list) => {
        if (!Array.isArray(list)) return [];
        const seen = new Set();
        const out = [];
        list.map(normalizeLang).forEach((code) => {
          if (!code || seen.has(code)) return;
          seen.add(code);
          out.push(code);
        });
        return out;
      };

      const rawSource = settings.source_language || settings.source_lang || settings.original_language || settings.default_language || '';
      const rawDefault = settings.default_language || '';
      const rawCurrent = settings.current_language || settings.language || (settings.user && settings.user.lang) || '';
      const SOURCE_LANG = normalizeLang(rawSource);
      const DEFAULT_LANG = normalizeLang(rawDefault);
      let lang = normalizeLang(rawCurrent);
      const rawTranslationLangs = settings.translation_langs || settings.translation_lang || [];

      settings.source_language = SOURCE_LANG;
      settings.default_language = DEFAULT_LANG;
      settings.current_language = lang;
      settings.languages = normalizeList(settings.languages || []);
      settings.translation_langs = normalizeList(Array.isArray(rawTranslationLangs) ? rawTranslationLangs : [rawTranslationLangs]);
      const PAGE_URL = canonicalPageUrl(settings.page_url || '');
      const POST_ID = Number.parseInt(settings.post_id, 10) || 0;
      // ---- DOM batch queue (chunked to avoid freezing large scans)
      // Smaller batches reduce memory spikes on large DOMs
      const DOM_BATCH_SIZE = 50;
      const DOM_QUEUE = (window.YUZ && Array.isArray(window.YUZ.DOM_QUEUE)) ? window.YUZ.DOM_QUEUE : [];
      if (!window.YUZ) window.YUZ = {};
      window.YUZ.DOM_QUEUE = DOM_QUEUE;
      let DOM_QUEUE_RUNNING = false;
      let DOM_CURRENT_BATCH = [];
      const IN_FLIGHT_KEYS = new Set();
      const FAILED_KEYS = new Map();
      const FAIL_COOLDOWN_MS = 4000;
      const GLOBAL_FAIL_STORAGE_KEY = 'yuz:regular:fail-until:' + String(lang || 'xx');
      let GLOBAL_FAIL_UNTIL_TS = 0;
      const GLOBAL_FAIL_COOLDOWN_MS = 5 * 60 * 1000;
      const syncGlobalFailUntil = (nextTs) => {
        const safeTs = Number(nextTs) || 0;
        GLOBAL_FAIL_UNTIL_TS = safeTs;
        try {
          if (safeTs > 0) {
            localStorage.setItem(GLOBAL_FAIL_STORAGE_KEY, String(safeTs));
          } else {
            localStorage.removeItem(GLOBAL_FAIL_STORAGE_KEY);
          }
        } catch (_) { }
      };
      try {
        const storedTs = Number(localStorage.getItem(GLOBAL_FAIL_STORAGE_KEY) || 0);
        if (storedTs > Date.now()) {
          GLOBAL_FAIL_UNTIL_TS = storedTs;
        } else if (storedTs > 0) {
          localStorage.removeItem(GLOBAL_FAIL_STORAGE_KEY);
        }
      } catch (_) { }
      const enqueueDomBatches = (items) => {
        if (!Array.isArray(items) || !items.length) return;
        const normalizedItems = items
          .map((item) => normalizeBatchString(item, 'queue'))
          .filter(Boolean);
        if (!normalizedItems.length) return;
        for (let i = 0; i < normalizedItems.length; i += DOM_BATCH_SIZE) {
          DOM_QUEUE.push(normalizedItems.slice(i, i + DOM_BATCH_SIZE));
        }
      };
      const pushToBatch = (elem) => {
        const safeItem = normalizeBatchString(elem, 'current-batch');
        if (!safeItem) return;
        DOM_CURRENT_BATCH.push(safeItem);
        if (DOM_CURRENT_BATCH.length >= DOM_BATCH_SIZE) {
          DOM_QUEUE.push(DOM_CURRENT_BATCH);
          DOM_CURRENT_BATCH = [];
        }
      };
      const flushFinalBatch = () => {
        if (DOM_CURRENT_BATCH.length) {
          DOM_QUEUE.push(DOM_CURRENT_BATCH);
          DOM_CURRENT_BATCH = [];
        }
      };
      const processDomQueue = async (applyFn) => {
        if (DOM_QUEUE_RUNNING) return { results: [], meta: [] };
        DOM_QUEUE_RUNNING = true;
        const aggregated = [];
        const aggregatedMeta = [];
        while (DOM_QUEUE.length) {
          const batch = DOM_QUEUE.shift();
          const translated = await fetchViaBatch(batch);
          const meta = Array.isArray(translated && translated.meta) ? translated.meta : [];
          applyFn(batch, translated, meta, aggregatedMeta);
          Array.prototype.push.apply(aggregated, Array.isArray(translated) ? translated : []);
        }
        DOM_QUEUE_RUNNING = false;
        aggregated.meta = aggregatedMeta;
        return aggregated;
      };
      // Optional: pre-scan DOM into paginated batches (no-op if translator already handles)
      function scanDOMForTranslation() {
        try {
          const scope = document.body; if (!scope) return;
          const walker = document.createTreeWalker(scope, NodeFilter.SHOW_TEXT, {
            acceptNode(node) {
              if (!node || node.nodeType !== 3) return NodeFilter.FILTER_REJECT;
              const txt = typeof node.nodeValue === 'string' ? node.nodeValue.trim() : '';
              if (!txt || txt.length < 2) return NodeFilter.FILTER_REJECT;
              const parent = node.parentElement;
              if (parent) {
                if (isInNoTranslateSubtree(parent)) return NodeFilter.FILTER_REJECT;
                const tag = parent.tagName;
                if (tag === 'SCRIPT' || tag === 'STYLE' || tag === 'CANVAS' || tag === 'SVG') {
                  return NodeFilter.FILTER_REJECT;
                }
              }
              return NodeFilter.FILTER_ACCEPT;
            }
          });
          let node;
          while ((node = walker.nextNode())) {
            const txt = sanitizeTextCandidate(node && node.nodeValue);
            if (!txt) continue;
            pushToBatch(txt);
          }
          flushFinalBatch();
          dbg('[YUZ-DOM][QUEUE] scan complete', {
            batches: DOM_QUEUE.length,
            total: DOM_QUEUE.reduce((acc, b) => acc + (Array.isArray(b) ? b.length : 0), 0),
            batchSize: DOM_BATCH_SIZE
          });
        } catch (e) { capture('dom_queue_scan_exception', { message: e && e.message }); }
      }
      document.addEventListener('DOMContentLoaded', scanDOMForTranslation, { once: true });

      const formatIssues = {};
      [
        ['source_language', rawSource, SOURCE_LANG],
        ['default_language', rawDefault, DEFAULT_LANG],
        ['current_language', rawCurrent, lang],
      ].forEach(([label, raw, normalized]) => {
        if (raw && normalized && raw !== normalized) {
          formatIssues[label] = { raw, normalized };
        } else if (raw && !normalized) {
          formatIssues[label] = { raw, normalized: '(empty)' };
        }
      });

      const DEBUG_TRANSLATOR = !!(settings.debug_translator || settings.debug_dom || settings.debug);
      const debugMax = 2000;
      let debugCount = 0;
      const dbg = (event, payload = {}) => {
        if (!DEBUG_TRANSLATOR) return;
        if (debugCount >= debugMax) return;
        debugCount++;
        try {
          const logger = window.__YUZ_DOM_LOG || ((e, p) => { try { console.debug('[YUZ_TRANSLATOR_DEBUG]', e, p); } catch (_) { } });
          logger(event, Object.assign({ lang, count: debugCount }, payload));
        } catch (_) { }
      };

      dbg('boot_settings', {
        url: (function () { try { return location.href; } catch (_) { return ''; } })(),
        current_language: lang,
        default_language: DEFAULT_LANG,
        source_language: SOURCE_LANG,
        languages: settings.languages,
        translations_keys: Object.keys(translationsRoot || {}),
      });

      if (Object.keys(formatIssues).length) {
        try { domLogEvent('lang_normalized', formatIssues, lang); }
        catch (_) { dbg('lang_normalized', formatIssues); }
      }

      if (!translationsRoot || !lang || !translationsRoot[lang]) {
        dbg('no_translations', { lang, keys: Object.keys(translationsRoot || {}) });
        try { domLogEvent('no_translations', { lang, keys: Object.keys(translationsRoot || {}) }, lang); } catch (_) { }
      }

      const resolveLang = () => {
        if (lang) return lang;
        const candidates = [];
        if (DEFAULT_LANG) { candidates.push(DEFAULT_LANG); }
        if (SOURCE_LANG) { candidates.push(SOURCE_LANG); }
        if (Array.isArray(settings.languages)) { candidates.push(...settings.languages); }
        if (Array.isArray(settings.translation_langs)) { candidates.push(...settings.translation_langs); }
        candidates.push(...Object.keys(translationsRoot || {}));

        const seen = new Set();
        for (const candidate of candidates.map(normalizeLang)) {
          if (!candidate || seen.has(candidate)) { continue; }
          seen.add(candidate);
          return candidate;
        }
        return lang;
      };

      lang = resolveLang();
      settings.current_language = lang;
      if (!lang) {
        try { console.warn('[YUZ_TRANSLATOR] Aborting: missing current_language'); } catch (_) {}
        return;
      }
      const dictRoot = settings.translations && lang && settings.translations[lang];
      const dict = (dictRoot && (dictRoot.translationsArray || dictRoot.map || dictRoot.dict)) || {};
      const guard = window.YUZTR = window.YUZTR || {};
      const NORMALIZATION_LOG_LIMIT = 60;
      let normalizationLogCount = 0;
      const normalizeSafeString = (value, where) => {
        if (typeof value !== 'string') return '';
        if (/^\[object Object\]$/i.test(value.trim())) return '';
        const raw = value;
        let s = value.replace(/\u00a0/g, ' '); // nbsp -> space
        s = s.replace(/[\u2018\u2019]/g, "'").replace(/[\u201c\u201d]/g, '"'); // curly quotes
        s = s.trim().replace(/\s+/g, ' ');
        if (!s) return '';
        if (s.length > 5000) return '';
        if (/[{}#<>]/.test(s) && s.split(/\s+/).length > 12) return '';
        if (s !== raw && normalizationLogCount < NORMALIZATION_LOG_LIMIT && DEBUG_TRANSLATOR) {
          normalizationLogCount++;
          try {
            const payload = { from: raw.slice(0, 160), to: s.slice(0, 160), where: where || 'unknown', count: normalizationLogCount };
            const logger = window.__YUZ_DOM_LOG || ((e, p) => { try { console.debug('[YUZ_TRANSLATOR_DEBUG]', e, p); } catch (_) { } });
            logger('normalized_string', payload);
          } catch (_) { }
        }
        return s;
      };
      const guardSafeString = typeof guard.safeString === 'function' ? guard.safeString : normalizeSafeString;
      const registerTranslationMeta = typeof guard.registerTranslation === 'function' ? guard.registerTranslation : () => null;
      const lookupTranslationMeta = typeof guard.lookup === 'function' ? guard.lookup : () => null;
      const normalizedDict = {};
      if (dict && typeof dict === 'object') {
        try {
          Object.keys(dict).forEach(originalKey => {
            const safeKey = guardSafeString(originalKey);
            if (safeKey && safeKey !== originalKey && typeof dict[safeKey] === 'undefined') {
              normalizedDict[safeKey] = dict[originalKey];
            }
          });
        } catch (_) { /* noop */ }
      }
      const getDictValue = key => {
        if (typeof dict[key] === 'string') return dict[key];
        if (typeof normalizedDict[key] === 'string') return normalizedDict[key];
        return null;
      };
      const setDictValue = (key, value) => {
        dict[key] = value;
        if (normalizedDict[key]) {
          delete normalizedDict[key];
        }
      };
      const initialDictKeys = new Set(Object.keys(dict || {}));
      const HAS_STORAGE = (() => { try { return typeof localStorage !== 'undefined'; } catch (_) { return false; } })();
      const LOCAL_DICT_KEY = `yuz:dict:v2:${lang}`;
      const LOCAL_DICT_TTL_MS = 7 * 24 * 3600 * 1000; // 7 days
      const LOCAL_DICT_LIMIT = 2000;
      let localDict = {};
      let localDictLoaded = false;
      let localDictTimer = null;
      const shouldSkipRemoteBatch = () => {
        try {
          const path = String((location && location.pathname) || '').toLowerCase();
          if (/\/business\/?$/.test(path)) return true;
          return !!(document.body && document.body.classList && document.body.classList.contains('page-business'));
        } catch (_) {
          return false;
        }
      };
      const trimLocalDict = () => {
        try {
          const keys = Object.keys(localDict);
          if (keys.length <= LOCAL_DICT_LIMIT) return;
          const overflow = keys.length - LOCAL_DICT_LIMIT;
          for (let i = 0; i < overflow; i++) {
            delete localDict[keys[i]];
          }
        } catch (_) { }
      };
      const flushLocalDict = () => {
        if (!HAS_STORAGE) return;
        try {
          trimLocalDict();
          localStorage.setItem(LOCAL_DICT_KEY, JSON.stringify({ t: Date.now(), dict: localDict }));
        } catch (_) { }
      };
      const scheduleLocalDictFlush = () => {
        if (!HAS_STORAGE) return;
        if (localDictTimer) return;
        localDictTimer = setTimeout(() => {
          localDictTimer = null;
          flushLocalDict();
        }, 800);
      };
      const loadLocalDict = () => {
        if (!HAS_STORAGE || localDictLoaded) return;
        localDictLoaded = true;
        try {
          const raw = localStorage.getItem(LOCAL_DICT_KEY);
          if (!raw) return;
          const parsed = JSON.parse(raw);
          const ts = Number(parsed && (parsed.t || parsed.ts));
          const stored = parsed && (parsed.dict || parsed.v);
          if (!ts || !stored || typeof stored !== 'object') return;
          if ((Date.now() - ts) > LOCAL_DICT_TTL_MS) {
            localStorage.removeItem(LOCAL_DICT_KEY);
            return;
          }
          Object.keys(stored).forEach((k) => {
            const v = typeof stored[k] === 'string' ? stored[k] : '';
            if (!k || !v) return;
            localDict[k] = v;
            if (typeof getDictValue(k) !== 'string') setDictValue(k, v);
          });
        } catch (_) { }
      };
      const rememberLocal = (key, value) => {
        if (!HAS_STORAGE) return;
        if (!key || typeof value !== 'string' || value === '' || initialDictKeys.has(key)) return;
        try { localDict[key] = value; } catch (_) { }
        scheduleLocalDictFlush();
      };
      loadLocalDict();


      // Public API
      const API = (window.YUZ_Translator = window.YUZ_Translator || {});
      API.__engine = 'unified';
      API.isReady = false;
      let _resolveReady;
      if (!window.YUZ_TranslatorReady || typeof window.YUZ_TranslatorReady.then !== 'function') {
        window.YUZ_TranslatorReady = new Promise(r => { _resolveReady = r; });
      }

      // Strong filters
      const SKIP_TAGS = new Set(['script', 'style', 'noscript', 'template', 'textarea', 'iframe']);
      const NO_TRANSLATE_SELECTOR = [
        '[translate="no"]',
        '.notranslate',
        '[data-yuz-translate="false"]',
        '[data-yuz-translate="0"]',
        '[data-yuz-guard="no-translate"]',
        '[data-yuz-guard="false"]',
        '[data-no-translate]',
        '[data-notranslate]'
      ].join(',');
      const TRANSLATE_ALLOW_SELECTOR = [
        '[data-yuz-translate-allow="true"]',
        '[data-yuz-translate-allow="1"]',
        '[data-yuz-translate-allow="force"]'
      ].join(',');
      function hasTranslateOverride(el) {
        try { return !!(el && el.closest && el.closest(TRANSLATE_ALLOW_SELECTOR)); }
        catch (_) { return false; }
      }
      function isInNoTranslateSubtree(el) {
        try {
          if (hasTranslateOverride(el)) return false;
          const hit = el && el.closest && el.closest(NO_TRANSLATE_SELECTOR);
          if (!hit) return false;
          if (hit === document.documentElement && document.documentElement && document.documentElement.hasAttribute('data-yuz-translate-allow-root')) {
            return false;
          }
          return true;
        }
        catch (_) { return false; }
      }
      const LETTERS_RE = /[A-Za-zÀ-ÖØ-öø-ÿ]/;
      function sanitizeTextCandidate(value) {
        const safe = guardSafeString(typeof value === 'string' ? value : '', 'text');
        if (!safe) return '';
        if (!LETTERS_RE.test(safe)) return '';
        return safe;
      }
      function normalizeBatchString(item, where = 'batch') {
        let raw = '';
        if (typeof item === 'string') {
          raw = item;
        } else if (typeof item === 'number' || typeof item === 'boolean') {
          raw = String(item);
        } else if (item && typeof item === 'object') {
          const candidates = ['text', 'original', 'original_text', 'value', 'label'];
          for (const key of candidates) {
            const candidate = item[key];
            if (typeof candidate === 'string') {
              raw = candidate;
              break;
            }
            if (typeof candidate === 'number' || typeof candidate === 'boolean') {
              raw = String(candidate);
              break;
            }
          }
        }
        const safe = sanitizeTextCandidate(raw);
        if (!safe && item && typeof item === 'object') {
          dbg('batch_item_dropped', {
            where,
            keys: Object.keys(item).slice(0, 6)
          });
        }
        return safe;
      }
      function isValidTextNode(node) {
        dbg('check_isValidTextNode', {
          value: node && node.nodeValue,
          parent: node && node.parentNode && node.parentNode.nodeName,
          sanitized: sanitizeTextCandidate(node && node.nodeValue)
        });
        if (!node || node.nodeType !== 3) return false;
        const pe = node.parentElement;
        if (pe && isInNoTranslateSubtree(pe)) return false;
        const p = node.parentNode && node.parentNode.nodeName ? node.parentNode.nodeName.toLowerCase() : '';
        if (SKIP_TAGS.has(p)) return false;
        const sanitized = sanitizeTextCandidate(node.nodeValue);
        if (!sanitized) return false;
        node.__yuzSanitized = sanitized;
        return true;
      }

      function translateTextNode(node) {
        try {
          dbg('pre_translateTextNode', { raw: node && node.nodeValue });
          if (!isValidTextNode(node)) {
            dbg('skip_translateTextNode', { reason: 'invalid', value: node && node.nodeValue });
            return;
          }
          const key = sanitizeTextCandidate(node && node.nodeValue);
          if (!key) {
            dbg('skip_translateTextNode', { reason: 'empty_key', value: node && node.nodeValue });
            return;
          }
          const val = getDictValue(key);
          if (typeof val === 'string' && val !== node.nodeValue) {
            node.nodeValue = val;
            dbg('text_translated', { original: key, translated: val });
            dbg('post_translateTextNode', { original: key, translated: val, newValue: node && node.nodeValue });
            domLogEvent('translation_applied', { original: key, translated: val, type: 'text' });
          } else if (typeof val !== 'string') {
            dbg('text_missing_translation', { original: key });
            domLogEvent('text_missing_translation', { original: key, parent: node && node.parentNode && node.parentNode.nodeName });
          }
        } catch (e) { capture('translateText_exception', { message: e && e.message, stack: e && e.stack }); }
      }

      function translateDOM(root) {
        try {
          const scope = root || document.body; if (!scope) return 0;
          const walker = document.createTreeWalker(scope, NodeFilter.SHOW_TEXT, null);
          let n, count = 0;
          while ((n = walker.nextNode())) { translateTextNode(n); count++; }
          return count;
        } catch (e) { capture('translateDOM_exception', { message: e && e.message, stack: e && e.stack }); return 0; }
      }

      // Single fetch is implemented by calling the same batch route (yuz_get_regular)
      async function fetchViaBatch(strings) {
        return new Promise((resolve) => {
          try {
            const nn = settings.nonces || {};
            const nval = nn.ajax_nonce || nn.yuz_tra_nonce || nn.yuz_nonce || nn.nonce || '';
            const safeStrings = (Array.isArray(strings) ? strings : [])
              .map((s) => normalizeBatchString(s, 'fetchViaBatch'))
              .filter(Boolean);
            if (!safeStrings.length) { resolve([]); return; }

            const output = safeStrings.map((s) => {
              const cached = getDictValue(s);
              return (typeof cached === 'string' && cached !== '') ? cached : null;
            });
            const meta = safeStrings.map(() => null);
            const nowTs = Date.now();

            if (shouldSkipRemoteBatch()) {
              output.meta = meta;
              resolve(output);
              return;
            }

            if (GLOBAL_FAIL_UNTIL_TS && nowTs < GLOBAL_FAIL_UNTIL_TS) {
              output.meta = meta;
              resolve(output);
              return;
            }

            const requestStrings = [];
            const requestIdx = [];
            safeStrings.forEach((s, idx) => {
              if (typeof output[idx] === 'string') {
                meta[idx] = lookupTranslationMeta(s);
                return;
              }
              const lastFail = FAILED_KEYS.get(s) || 0;
              if (lastFail && (nowTs - lastFail) < FAIL_COOLDOWN_MS) return;
              if (IN_FLIGHT_KEYS.has(s)) return;
              requestStrings.push(s);
              requestIdx.push(idx);
            });

            if (!requestStrings.length) {
              output.meta = meta;
              resolve(output);
              return;
            }

            const limitedStrings = requestStrings.slice(0, DOM_BATCH_SIZE);
            const limitedIdx = requestIdx.slice(0, DOM_BATCH_SIZE);
            if (requestStrings.length > limitedStrings.length) {
              dbg('batch_truncated', { requested: requestStrings.length, sent: limitedStrings.length });
            }
            limitedStrings.forEach((s) => IN_FLIGHT_KEYS.add(s));

            // Dummy block keys & skip arrays (shape compatible)
            const blockKeys = limitedStrings.map((s) => {
              if (PENDING_BLOCK_KEYS && PENDING_BLOCK_KEYS.has(s)) {
                return PENDING_BLOCK_KEYS.get(s) || '';
              }
              return '';
            });
            const skip = limitedStrings.map(() => true);
            const cid = 'dom-' + Math.random().toString(36).slice(2) + '-' + Date.now();
            const data = {
              action: 'yuz_get_regular',
              nonce: (nn.yuz_tra_nonce || nval),
              ajax_nonce: nval, yuz_tra_nonce: nval, yuz_nonce: nval,
              all_languages: 'false',
              // target = page lang
              language: (settings.current_language || '').toString(),
              // source = actual content language provided by settings
              original_language: SOURCE_LANG,
              lang_from: SOURCE_LANG,
              is_source: settings.is_source ? '1' : '0',
              is_default: settings.is_default ? '1' : '0',
              originals: JSON.stringify(limitedStrings),
              block_keys: JSON.stringify(blockKeys),
              skip_machine_translation: JSON.stringify(skip),
              dynamic_strings: 'true',
              page_url: PAGE_URL,
              post_id: POST_ID,
              cid
            };

            dbg('batch_request', { cid, count: limitedStrings.length, samples: limitedStrings.slice(0, 5) });
            try { domLogEvent('yuz_get_regular_request', { cid, count: limitedStrings.length, status: 'start' }); } catch (_) { }
            jQuery.ajax({
              url: settings.ajax_url,
              type: 'POST',
              dataType: 'json',
              headers: { 'X-YUZ-TRACE': '1' },
              data,
              success: (resp) => {
                try {
                  syncGlobalFailUntil(0);
                  if (!resp || resp.success === false) {
                    console.warn('[YUZ][yuz_get_regular][cid=' + cid + '] server error resp', resp);
                    dbg('batch_error_resp', { cid, resp });
                  } else {
                    dbg('batch_success', { cid, rows: Array.isArray(resp && resp.data) ? resp.data.length : 0 });
                  }
                  try { domLogEvent('yuz_get_regular_request', { cid, count: limitedStrings.length, status: 'success', rows: Array.isArray(resp && resp.data) ? resp.data.length : 0 }); } catch (_) { }
                } catch (_) { }
                const out = output;
                try {
                  const rows = (resp && resp.data) || [];
                  let translatedCount = 0; let missingCount = 0;
                  for (let i = 0; i < limitedStrings.length; i++) {
                    const s = limitedStrings[i];
                    const outIdx = limitedIdx[i];
                    const hit = rows.find(r => (r.original || '').trim() === s);
                    const translatedNode = hit && hit.translationsArray && hit.translationsArray[data.language];
                    const tr = translatedNode && translatedNode.translated;
                    const status = translatedNode && translatedNode.status;
                    const translationId = Number(hit && hit.translation_id);
                    let record = registerTranslationMeta(s, {
                      translation_id: translationId,
                      translated: typeof tr === 'string' ? tr : '',
                      status
                    });
                    if (!record && Number.isInteger(translationId) && translationId > 0) {
                      record = { id: translationId, original: s, translated: typeof tr === 'string' ? tr : '', status };
                    }
                    meta[outIdx] = record || null;
                    if (typeof tr === 'string' && tr !== '') {
                      setDictValue(s, tr);
                      rememberLocal(s, tr);
                      out[outIdx] = tr;
                      translatedCount++;
                      FAILED_KEYS.delete(s);
                    } else {
                      missingCount++;
                      out[outIdx] = null;
                      FAILED_KEYS.set(s, Date.now());
                    }
                  }
                  try {
                    const sampleMissing = safeStrings.filter((_, i) => typeof out[i] !== 'string').slice(0, 5);
                    domLogEvent('yuz_get_regular_batch_stats', {
                      cid,
                      requested: limitedStrings.length,
                      translated: translatedCount,
                      missing: missingCount,
                      sample_missing: sampleMissing
                    });
                  } catch (_) { }
                } catch (_) { /* keep out as is */ }
                limitedStrings.forEach((s) => IN_FLIGHT_KEYS.delete(s));
                out.meta = meta;
                resolve(out);
              },
              error: (xhr) => {
                dbg('batch_http_error', { cid, status: xhr && xhr.status });
                syncGlobalFailUntil(Date.now() + GLOBAL_FAIL_COOLDOWN_MS);
                try { console.error('[YUZ][yuz_get_regular][cid=' + cid + '] HTTP error', xhr && xhr.status); } catch (_) { }
                try { domLogEvent('yuz_get_regular_request', { cid, count: limitedStrings.length, status: 'http_error', http_status: xhr && xhr.status }); } catch (_) { }
                limitedStrings.forEach((s) => { FAILED_KEYS.set(s, Date.now()); IN_FLIGHT_KEYS.delete(s); });
                const fallback = output;
                fallback.meta = meta;
                resolve(fallback);
              }
            });
          } catch (_) {
            const empty = [];
            empty.meta = [];
            resolve(empty);
          }
        });
      }

      // Attribute translation helpers (placeholder, title, aria-label, safe value)
      const ATTRS = ['placeholder', 'data-placeholder', 'title', 'aria-label'];
      const VALUE_SAFE_TYPES = new Set(['button', 'submit', 'reset']);
      function shouldTranslateValueAttr(el) {
        try {
          if (!el || el.tagName !== 'INPUT') return false;
          const t = (el.getAttribute('type') || '').toLowerCase();
          return VALUE_SAFE_TYPES.has(t);
        } catch (_) { return false; }
      }

      const sanitizeAttrCandidate = (value, where) => {
        const safe = guardSafeString(typeof value === 'string' ? value : '', where || 'attr');
        return safe;
      };

      function translateElementAttributes(el, attrName) {
        try {
          if (!el || el.nodeType !== 1) return 0;
          if (isInNoTranslateSubtree(el)) return 0;
          const attrOnly = attrName && ATTRS.includes(attrName);
          const checkValue = attrName === 'value';
          if (attrName && !attrOnly && !checkValue) return 0;
          let applied = 0;
          const attrsToCheck = attrOnly ? [attrName] : ATTRS;
          for (const a of attrsToCheck) {
            const v = el.getAttribute && el.getAttribute(a);
            const key = sanitizeAttrCandidate(v, `attr:${a}`);
            if (!key) continue;
            const val = getDictValue(key);
            if (typeof val === 'string' && val !== v) {
              el.setAttribute(a, val);
              applied++;
              dbg('attr_translated', { attr: a, original: key, translated: val, node: el.tagName });
              domLogEvent('translation_applied', { original: key, translated: val, type: `attr:${a}` });
            } else if (typeof val !== 'string') {
              dbg('attr_missing_translation', { attr: a, original: key, node: el.tagName });
              domLogEvent('attr_missing_translation', { attr: a, original: key, node: el.tagName });
            }
          }
          if (!checkValue || !shouldTranslateValueAttr(el)) return applied;
          const rawValue = el.value || '';
          const valueKey = sanitizeAttrCandidate(rawValue, 'value');
          if (!valueKey) return applied;
          const translatedValue = getDictValue(valueKey);
          if (typeof translatedValue === 'string' && translatedValue !== rawValue) {
            el.value = translatedValue;
            applied++;
            dbg('value_translated', { original: valueKey, translated: translatedValue, node: el.tagName });
            domLogEvent('translation_applied', { original: valueKey, translated: translatedValue, type: 'value' });
          } else if (typeof translatedValue !== 'string') {
            dbg('value_missing_translation', { original: valueKey, node: el.tagName });
            domLogEvent('value_missing_translation', { original: valueKey, node: el.tagName });
          }
          return applied;
        } catch (_) {
          return 0;
        }
      }

      function translateAttributesInTree(root) {
        try {
          dbg('scan_attributes_start', { root });
          const scope = root || document.body; if (!scope) return 0;
          let applied = 0;
          const all = scope.querySelectorAll('*');
          for (const el of all) {
            if (isInNoTranslateSubtree(el)) continue;
            applied += translateElementAttributes(el);
          }
          return applied;
        } catch (e) { capture('translate_attributes_exception', { message: e && e.message, stack: e && e.stack }); return 0; }
      }

      function collectAttributeStrings(root) {
        const scope = root || document.body; if (!scope) return { nodesInfo: [], strings: [] };
        const nodesInfo = [];
        const strings = [];
        const seen = new Set();
        const push = (el, attr, val) => {
          if (isInNoTranslateSubtree(el)) return;
          const key = sanitizeAttrCandidate(val);
          if (!key) return;
          if (typeof getDictValue(key) === 'string') return;
          if (seen.has(key)) {
            nodesInfo.push({ node: el, attribute: attr, original: key });
            return;
          }
          seen.add(key);
          strings.push(key);
          nodesInfo.push({ node: el, attribute: attr, original: key });
        };
        try {
          const all = scope.querySelectorAll('*');
          for (const el of all) {
            try {
              for (const a of ATTRS) {
                const v = el.getAttribute && el.getAttribute(a);
                if (v) push(el, a, v);
              }
              if (shouldTranslateValueAttr(el)) push(el, 'value', el.value || '');
            } catch (_) { }
          }
        } catch (_) { }
        return { nodesInfo, strings };
      }

      function collectTranslatableNodes(limit = 200) {
        const ids = [];
        try {
          const scope = document.body;
          if (!scope) return ids;
          const nodes = scope.querySelectorAll('*');
          for (const el of nodes) {
            if (el.children && el.children.length > 0) continue;
            const text = sanitizeTextCandidate((el.innerText || el.textContent || ''));
            if (!text) continue;
            const record = lookupTranslationMeta(text);
            const id = record && Number(record.id);
            if (!Number.isInteger(id) || id <= 0) continue;
            ids.push(id);
            if (limit > 0 && ids.length >= limit) break;
          }
        } catch (_) { }
        return Array.from(new Set(ids));
      }

      // Translate plain innerText of an element (for markup inserted via innerHTML)
      function translateElementInnerText(el) {
        try {
          if (!el || el.nodeType !== 1) return false;
          if (isInNoTranslateSubtree(el)) return false;
          // only translate leaf elements with a single text node child
          if (el.childElementCount && el.childElementCount > 0) return false;
          if (!el.firstChild || el.firstChild.nodeType !== 3) return false;
          if (el.childNodes.length !== 1) return false;
          const tag = (el.tagName || '').toLowerCase();
          if (SKIP_TAGS.has(tag) || tag === 'form' || tag === 'input' || tag === 'select' || tag === 'textarea') return false;

          const raw = (el.textContent || '').trim();
          const key = sanitizeTextCandidate(raw);
          if (!key) {
            dbg('innerText_missing', { raw });
            return false;
          }
          const val = getDictValue(key);
          if (val && val !== raw) {
            el.textContent = val;
            dbg('innerText_translated', { raw, translated: val, tag: el.tagName });
            domLogEvent('translation_applied', { original: raw, translated: val, type: 'innerText', tag: el.tagName });
            return true;
          }
          dbg('innerText_missing_translation', { raw, tag: el.tagName });
          return false;
        } catch (_) { return false; }
      }

      // Optional detector (extended) — text nodes + attributes via fetchViaBatch
      async function detectMissing() {
        try {
          const scope = document.body; if (!scope) return [];
          const walker = document.createTreeWalker(scope, NodeFilter.SHOW_TEXT, null);
          const missing = new Set();
          let n;
          while ((n = walker.nextNode())) {
            if (!isValidTextNode(n)) continue;
            const raw = sanitizeTextCandidate(n && n.nodeValue);
            if (raw && typeof getDictValue(raw) !== 'string') missing.add(raw);
          }

          // Attributes to consider
          const { nodesInfo, strings: attrStrings } = collectAttributeStrings(scope);

          const textArr = Array.from(missing);
          dbg("detect_missing_collect", { missing: textArr });
          const combined = Array.from(new Set(textArr.concat(attrStrings)));
          if (!combined.length) return [];

          // Map attr strings to block keys for the upcoming batch (placeholders/titles/etc.)
          PENDING_BLOCK_KEYS = new Map();
          nodesInfo.forEach((info) => {
            const key = info && info.original;
            const el = info && info.node;
            const attr = info && info.attribute;
            if (!key || !el) return;
            const bk = buildBlockKeyFromNode(el, attr);
            if (bk) PENDING_BLOCK_KEYS.set(key, bk);
          });

          dbg('detect_missing_start', { textCount: textArr.length, attrCount: attrStrings.length, batchSize: DOM_BATCH_SIZE, total: combined.length });
          try { domLogEvent('detect_missing_collect', { textCount: textArr.length, attrCount: attrStrings.length, total: combined.length, samples: combined.slice(0, 10) }); } catch (_) { }
          enqueueDomBatches(combined);

          const appliedIds = [];
          const items = [];
          const applyBatch = (batch, translated, meta, aggregatedMeta) => {
            batch.forEach((k, i) => {
              const tr = translated[i];
              if (typeof tr !== 'string') {
                dbg('detect_missing_null', { original: k });
                aggregatedMeta.push(meta[i] || null);
                return;
              }
              setDictValue(k, tr);
              rememberLocal(k, tr);
              const record = meta[i] || lookupTranslationMeta(k);
              aggregatedMeta.push(record || null);
              const translationId = record && Number(record.id);
              if (Number.isInteger(translationId) && translationId > 0) {
                appliedIds.push(translationId);
                items.push({
                  id: translationId,
                  translation_id: translationId,
                  original: k,
                  original_text: k,
                  translated: tr,
                  translated_text: tr
                });
              }
            });
          };

          const translatedAll = await processDomQueue(applyBatch);
          dbg('detect_missing_resp', {
            received: Array.isArray(translatedAll) ? translatedAll.length : 0,
            meta: Array.isArray(translatedAll.meta) ? translatedAll.meta.length : 0,
            batches: Math.ceil(combined.length / DOM_BATCH_SIZE)
          });

          // Apply attribute updates for any attribute strings we just learned
          if (attrStrings.length) {
            try {
              for (const info of nodesInfo) {
                const k = info && info.original;
                const tr = k && getDictValue(k);
                if (!k || typeof tr !== 'string') continue;
                const el = info.node;
                if (!el || el.nodeType !== 1) continue;
                if (info.attribute === 'value' && !shouldTranslateValueAttr(el)) continue;
                if (info.attribute === 'value') el.value = tr; else el.setAttribute(info.attribute, tr);
                dbg('attr_applied_from_missing', { attr: info.attribute, original: k, translated: tr, node: el.tagName });
              }
            } catch (_) { }
          }

          if (appliedIds.length) { safeEmit('autoTranslateDone', { count: appliedIds.length, ids: appliedIds, items }); }
          return translatedAll;
        } catch (e) { capture('detect_missing_exception', { message: e && e.message, stack: e && e.stack }); return []; }
        finally {
          try { PENDING_BLOCK_KEYS.clear(); } catch (_) { PENDING_BLOCK_KEYS = new Map(); }
        }
      }

      // ============ Legacy-compatible API ============
      const ctx = { observer: null, observedRoot: null, lastScanAt: 0 };

      API.scan_now = function (forceFull = true) {
        try {
          const scope = document.body;
          const textCount = translateDOM(scope);
          let attrCount = 0;
          try { attrCount = translateAttributesInTree(scope); } catch (_) { }
          ctx.lastScanAt = now();
          dbg('scan_now', { forceFull: !!forceFull, textCount, attrCount });
          return textCount + attrCount;
        }
        catch (e) { capture('scan_exception', { message: e && e.message, stack: e && e.stack }); return 0; }
      };

      API.detect_new_strings = function () { return detectMissing(); };

      API.observeRoot = function (root) {
        try {
          const target = root || document.body; if (!target) return false;
          if (ctx.observer) { try { ctx.observer.disconnect(); } catch (_) { } }
          const options = { childList: true, subtree: true, characterData: true, attributes: true, attributeFilter: ATTRS.concat(['value']) };
          ctx.observer = new MutationObserver(muts => {
            let touch = false;
            for (const m of muts) {
              dbg("mutation_detected", {
                type: m.type,
                addedNodes: [...m.addedNodes].map(n => ({
                  type: n.nodeType,
                  value: n.nodeValue,
                  tag: n.tagName
                }))
              });
              if (m.type === 'characterData') { translateTextNode(m.target); touch = true; }
              else if (m.type === 'childList') {
                for (const added of m.addedNodes) {
                  if (added.nodeType === 3) { translateTextNode(added); touch = true; }
                  else if (added.nodeType === 1) {
                    translateElementInnerText(added);
                    translateDOM(added);
                    translateAttributesInTree(added);
                    touch = true;
                  }
                }
              } else if (m.type === 'attributes') {
                translateElementAttributes(m.target, m.attributeName || '');
                touch = true;
              }
            }
            if (touch) safeEmit('auto_rescan_done', { at: now() });
          });
          ctx.observer.observe(target, options);
          ctx.observedRoot = target;
          return true;
        } catch (e) { capture('observer_exception', { message: e && e.message, stack: e && e.stack }); return false; }
      };

      API.stop_observer = function () {
        try { if (ctx.observer) ctx.observer.disconnect(); ctx.observer = null; ctx.observedRoot = null; return true; }
        catch (e) { capture('observer_stop_exception', { message: e && e.message, stack: e && e.stack }); return false; }
      };

      const MODAL_RESCAN_EVENTS = [
        'yuz:modal:open',
        'yuz:modal:opened',
        'yuz:forms:auto-opened',
        'clar:modal:open',
        'clar:modal:opened',
        'clar:wizard:open',
        'clar:dialog:open',
        'clar:open',
        'clar:loaded'
      ];
      const MODAL_RESCAN_DELAY_MS = 180;
      const MODAL_RESCAN_COOLDOWN_MS = 240;
      let modalRescanTimer = null;
      let lastModalRescanAt = 0;

      function scheduleModalRescan(reason, root) {
        const currentAt = Date.now();
        if (modalRescanTimer && (currentAt - lastModalRescanAt) < MODAL_RESCAN_COOLDOWN_MS) {
          return;
        }
        lastModalRescanAt = currentAt;
        if (modalRescanTimer) {
          clearTimeout(modalRescanTimer);
        }
        modalRescanTimer = setTimeout(() => {
          modalRescanTimer = null;
          const scope = root && root.nodeType === 1 ? root : document.body;
          try {
            const textCount = translateDOM(scope);
            let attrCount = 0;
            try { attrCount = translateAttributesInTree(scope); } catch (_) { }
            ctx.lastScanAt = now();
            dbg('modal_rescan', {
              reason,
              id: scope && scope.id ? scope.id : null,
              textCount,
              attrCount
            });
            safeEmit('auto_rescan', {
              reason,
              id: scope && scope.id ? scope.id : null,
              textCount,
              attrCount
            });
            if (!shouldSkipRemoteBatch()) {
              setTimeout(() => {
                try { detectMissing(); } catch (_) { }
              }, 0);
            }
          } catch (e) {
            capture('modal_rescan_exception', { message: e && e.message, stack: e && e.stack, reason });
          }
        }, MODAL_RESCAN_DELAY_MS);
      }

      function bindModalRescanListeners() {
        if (guard.__modalRescanBound) {
          return;
        }
        guard.__modalRescanBound = true;
        const rerun = (evt) => {
          const detail = evt && evt.detail && typeof evt.detail === 'object' ? evt.detail : {};
          const root = detail && detail.el && detail.el.nodeType === 1 ? detail.el : document.body;
          scheduleModalRescan(evt && evt.type ? evt.type : 'modal-event', root);
        };
        MODAL_RESCAN_EVENTS.forEach((eventName) => {
          document.addEventListener(eventName, rerun, { passive: true });
        });
      };

      // Batch API compatibility: two call shapes supported
      API.__send_translation_batch = async function (a, b, c, d) {
        try {
          // Shape A: (__send_translation_batch(strings[])) → route to yuz_get_regular and return string[]|null[]
          if (Array.isArray(a) && !b && !c && !d) {
            return fetchViaBatch(a);
          }
          // Shape B: (__send_translation_batch(nodesInfo[], strings[], skip[], doneFn)) — call yuz_get_regular and return raw resp
          const nodesInfo = Array.isArray(a) ? a : [];
          const strings = Array.isArray(b) ? b : [];
          const sanitizedPairs = strings
            .map((value, idx) => ({
              text: normalizeBatchString(value, 'shape-b'),
              nodeInfo: nodesInfo[idx] || null
            }))
            .filter((entry) => !!entry.text);
          const limitedPairs = sanitizedPairs.slice(0, DOM_BATCH_SIZE);
          const limitedNodesInfo = limitedPairs.map((entry) => entry.nodeInfo);
          const limitedStrings = limitedPairs.map((entry) => entry.text);
          const done = (typeof d === 'function') ? d : function () { };

          return new Promise((resolve) => {
            try {
              if (!limitedStrings.length) {
                try { done(); } catch (_) { }
                resolve(null);
                return;
              }

              if (shouldSkipRemoteBatch()) {
                try { done(); } catch (_) { }
                resolve(null);
                return;
              }

              if (GLOBAL_FAIL_UNTIL_TS && Date.now() < GLOBAL_FAIL_UNTIL_TS) {
                try { done(); } catch (_) { }
                resolve(null);
                return;
              }

              const activeLang = (settings.current_language || '').toString();
              const nn = settings.nonces || {};
              const nval = nn.ajax_nonce || nn.yuz_tra_nonce || nn.yuz_nonce || nn.nonce || '';
              const blockKeys = limitedNodesInfo.map(ni => {
                const el = ni && ni.node && ni.node.nodeType === 1 ? ni.node : null;
                const attr = (ni && ni.attribute) ? ni.attribute : '';
                return buildBlockKeyFromNode(el, attr);
              });

              const cid = 'dom-' + Math.random().toString(36).slice(2) + '-' + Date.now();
              jQuery.ajax({
                url: settings.ajax_url, type: 'POST', dataType: 'json',
                data: {
                  action: 'yuz_get_regular',
                  nonce: (nn && nn.yuz_tra_nonce) || nval,
                  ajax_nonce: nval, yuz_tra_nonce: nval, yuz_nonce: nval,
                  all_languages: 'false',
                  // target = page lang
                  language: activeLang,
                  // source = actual content language provided by settings
                  original_language: SOURCE_LANG,
                  lang_from: SOURCE_LANG,
                  is_source: settings.is_source ? '1' : '0',
                  is_default: settings.is_default ? '1' : '0',
                  originals: JSON.stringify(limitedStrings),
                  block_keys: JSON.stringify(blockKeys),
                  skip_machine_translation: JSON.stringify(limitedStrings.map(() => true)),
                  dynamic_strings: 'true',
                  page_url: PAGE_URL || canonicalPageUrl(),
                  post_id: POST_ID,
                  cid
                },
                success: (resp) => {
                  syncGlobalFailUntil(0);
                  try { done(); } catch (_) { }
                  try { if (!resp || resp.success === false) console.warn('[YUZ][batch][cid=' + cid + '] server error resp', resp); } catch (_) { }
                  resolve(resp);
                },
                error: (xhr) => {
                  syncGlobalFailUntil(Date.now() + GLOBAL_FAIL_COOLDOWN_MS);
                  try { done(); } catch (_) { }
                  try { console.error('[YUZ][batch][cid=' + cid + '] HTTP error', xhr && xhr.status); } catch (_) { }
                  resolve(null);
                }
              });
            } catch (e) { try { done(); } catch (_) { } resolve(null); }
          });
        } catch (e) { capture('ajax_exception', { message: e && e.message, stack: e && e.stack }); return []; }
      };

      // Boot
      const start = () => {
          try {
          translateDOM();
          // also translate attributes such as placeholder/title/aria-label/safe value
          try { translateAttributesInTree(document.body); } catch (_) { }
          API.observeRoot(document.body);
          bindModalRescanListeners();
          const isEditOrAdmin = /\byuz-edit-translation=1\b/.test(location.search) || /\/wp-admin\//.test(location.pathname);
          if (!isEditOrAdmin && !shouldSkipRemoteBatch()) setTimeout(() => { detectMissing(); }, 1200);
          API.isReady = true;
          try { if (_resolveReady) _resolveReady(API); } catch (_) { }
          try { document.dispatchEvent(new CustomEvent('yuz:translator:ready', { detail: { api: 'YUZ_Translator', engine: 'unified' } })); } catch (_) { }
        } catch (e) { capture('init_exception', { message: e && e.message, stack: e && e.stack }); }
      };
      if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, { once: true });
      else start();
    } catch (fatal) { try { console.error('[YUZ][unified] fatal', fatal); } catch (_) { } }
  })();

})();

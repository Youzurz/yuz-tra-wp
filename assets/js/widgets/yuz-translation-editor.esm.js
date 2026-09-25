/* =======================================================================================
 * assets/js/widgets/yuz-translation-editor.esm.js — patched (ESM imports, Vue + jQuery)
 * Inline Translation Editor (front/admin overlay)
 *
 * Changements notables (par rapport à ta version) :
 *   - Ajout d’un helper `toast()` (manquait → appels existants plantaient silencieusement).
 *   - Avertissement HVY (AI suggest) affiché **une seule fois** (anti-spam console).
 *   - Correction utilitaire getParam(): échappe correctement les crochets.
 *   - Enrichissement du DOM bridge :
 *       • liste skipSelectors alignée sur la version non-ESM (bannières, modaux, quick-forms, etc.)
 *       • nettoyage de l’attribut data-yuz-te-map préexistant.
 *   - Traces console plus claires sur bootstrap / search / erreurs.
 *   - ***Patch compat réponses backend: helpers isOk/pickArray/normalizeStrings/pickSuggestions,
 *     et tolérance aux formes results/translations/strings.***
 * ======================================================================================= */

import Vue from 'vue';
import $ from 'jquery';

(function (window, document) {
  'use strict';

  if (!window || !document) return;

  // Charge la config runtime (nonces, ajax_url, etc.)
  let Y = window.yuzTE;
  if (!Y) {
    document.addEventListener('DOMContentLoaded', function () {
      // si yuzTE n'est toujours pas présent, on reste silencieux
    });
    return;
  }

  if (!Y.ajax_url) {
    console.error('[YUZ][TE] ajax_url manquant — abandon.');
    return;
  }

  const NONCES = (Y && Y.nonces) || {};
  const NONCE_ROUTES = {
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
  const pickNonce = (action) => {
    const route = NONCE_ROUTES[action] || [];
    for (const key of route) {
      if (key && NONCES[key]) {
        return NONCES[key];
      }
    }
    return NONCES.tra || NONCES.yuz_tra_nonce || '';
  };

  // Gèle les nonces contre les mutations tardives
  try { if (Y.nonces && !Object.isFrozen(Y.nonces)) Object.freeze(Y.nonces); } catch (_) {}

  const LVL = { critical:'🟥 [CRITICAL]', warning:'🟨 [WARNING]', success:'🟩 [SUCCESS]', info:'🟦 [INFO]' };
  const log = (level, msg, ctx) => {
    const ts = new Date().toISOString();
    let out = `${LVL[level]||''} ${msg} at ${ts}`;
    if (ctx) { try { out += ` | ${JSON.stringify(ctx)}`; } catch(_){} }
    (level==='critical'?console.error:(level==='warning'?console.warn:console.log))(out);
  };

  // Fallback toast (si absence de librairie CLAR)
  const toast = (message, type = 'info') => {
    try {
      if (window.CLAR && typeof window.CLAR.toast === 'function') {
        window.CLAR.toast(message, { type });
      } else {
        const m = (type === 'error') ? 'error' : (type === 'warning' ? 'warn' : 'log');
        console[m]('[toast]', message);
      }
    } catch (_) {}
  };

  const CFG = window.yuzTraSettings || {};
  const TRACE = CFG.trace || Math.random().toString(16).slice(2);

  const TRANSLATE_ENDPOINT = (() => {
    const raw = (Y && Y.ajax_url) || (typeof window !== 'undefined' && window.ajaxurl) || Y.ajax_url;
    try { return new URL(raw, window.location.origin).toString(); }
    catch (_) { return Y.ajax_url; }
  })();

  const TRANSLATE_NONCE = pickNonce('yuz_translate');

  function nonceForTM() {
    return pickNonce('yuz_tra_tm_translate');
  }

  function stripCssJsNoise(text) {
    if (!text) return '';

    let cleaned = String(text)
      .replace(/<script[\s\S]*?<\/script>/gi, ' ')
      .replace(/<style[\s\S]*?<\/style>/gi, ' ')
      .replace(/\/\*[\s\S]*?\*\//g, ' ')
      .replace(/(^|[^:\w])\/\/[^\n\r]*/g, '$1 ')
      .replace(/[#.\w-][^{]{0,160}\{[^}]*\}/g, ' ')
      .replace(/[(){}@]+/g, ' ')
      .replace(/\s{2,}/g, ' ')
      .trim();

    if (!cleaned) return '';

    const markers = [
      /vos préférences de confidentialité/i,
      /j['’`]?accepte de recevoir des e-mails/i,
      /facebook\s+twitter\s+linkedin\s+discord/i,
      /addEventListener|localStorage|document\.|window\./i,
      /cookies?\s+(et\s+collectons|collectons|et\s+collecte)/i,
      /\)\s*\}.*$/
    ];

    for (const marker of markers) {
      const match = cleaned.match(marker);
      if (match && typeof match.index === 'number') {
        cleaned = cleaned.slice(0, match.index);
      }
    }

    cleaned = cleaned
      .replace(/\s{2,}/g, ' ')
      .replace(/[|/\\·•]+\s*$/g, '')
      .trim();

    if (!cleaned) return '';
    if (!/[A-Za-zÀ-ÿ]{2}/.test(cleaned)) return '';
    return cleaned;
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

  // Traduction instantanée → tolérante à toutes les formes de réponse
  async function translateAjax({ text, from, to }) {
    const cleaned = stripCssJsNoise(text || '');
    try { console.debug('[YUZ][translateAjax] cleaned', cleaned.slice(0, 160)); } catch (_) {}
    if (!cleaned) throw new Error('empty_payload');

    const response = await tmTranslateAjax({
      text: cleaned,
      from: typeof from === 'string' ? from.trim() : from,
      to: typeof to   === 'string' ? to.trim()   : to,
      persist: 0
    });

    const translated =
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

    if (translated) return translated;

    const backendError = response?.error || response?.data?.error || response?.message || response?.data?.message;
    if (backendError) throw new Error(`translate_failed:${backendError}`);

    try { console.warn('[YUZ][translateAjax] RAW JSON (no translated_text)', response); } catch (_) {}
    throw new Error('empty_translation');
  }

  // Appel AJAX TM (batch ou single) + canonisation des codes + retry 1× + logs
  async function tmTranslateAjax(params = {}) {
    const url = window.yuzTE?.ajax_url || TRANSLATE_ENDPOINT;
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

    const from0 = (typeof params.from === 'string' ? params.from.trim()
                : (typeof params.source === 'string' ? params.source.trim() : ''));
    const to0 = (typeof params.to === 'string' ? params.to.trim()
              : (typeof params.target === 'string' ? params.target.trim() : ''));

    const fromCanon = canonLT(from0 || window.yuzTraSettings?.source_language || 'auto');
    const toCanon = canonLT(to0 || window.yuzTraSettings?.translatable_languages?.[0] || '');
    if (!toCanon) throw new Error('missing_target');

    const body = new URLSearchParams();
    body.set('action', 'yuz_tra_tm_translate');
    body.set('_ajax_nonce', nonce);
    body.set('nonce', nonce);
    body.set('yuz_tra_nonce', nonce);
    body.set('req_id', reqId);
    body.set('payload', JSON.stringify(items));
    if (params.persist !== undefined) body.set('persist', params.persist ? '1' : '0');
    if (TRACE) body.set('yuz_trace', TRACE);

    async function postOnce(src, tgt) {
      body.set('source', src || 'auto');
      body.set('target', tgt);

      console.groupCollapsed('[YUZ][TM] ⇢ POST', reqId);
      console.debug('[YUZ][TM] params', { source: src || 'auto', target: tgt, items: items.length });

      const res = await fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
        body: body.toString(),
        credentials: 'same-origin'
      });

      const json = await res.json().catch(() => ({}));
      if (!res.ok || json?.success !== true) {
        const err = json?.data?.message || json?.data?.error || json?.message || `HTTP ${res.status}`;
        console.warn('[YUZ][TM] ✗', err);
        console.groupEnd();
        throw new Error(err || 'tm_failed');
      }

      const translatedSingle = (() => {
        if (items.length !== 1) return '';
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
        console.debug('[YUZ][TM] ✓ translatedSingle', translatedSingle);
      } else {
        try { console.warn('[YUZ][TM] ✓ but no translatedSingle — RAW JSON', json); } catch (_) {}
      }

      console.groupEnd();
      return json;
    }

    let json = await postOnce(fromCanon, toCanon);
    if (!json?.translated_text && (fromCanon !== from0 || toCanon !== to0)) {
      console.info('[YUZ][TM] retry with canonical codes', { fromCanon, toCanon, reqId });
      json = await postOnce(fromCanon, toCanon);
    }

    return json;
  }

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


  // Nonce strict: si absent → on bloque la requête
  const nonceFor = (action) => {
    const n = Y && Y.nonces && Y.nonces[action];
    if (!n) {
      log('critical','[YUZ][TE] Nonce absent — requête bloquée', { action, group: GROUP[action]||'?' });
      throw new Error('MISSING_NONCE');
    }
    return n;
  };

  // --- Patch 1: Response shape helpers (compat results/translations/strings) ---
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
        status: text ? '1' : '0'
      };
    }
    const clone = { ...entry };
    const rawStatus = clone.status;
    let status = rawStatus != null && rawStatus !== '' ? String(rawStatus) : '';
    const textCandidate = clone.edited != null ? clone.edited : clone.translated;
    if (!status) {
      status = textCandidate && String(textCandidate).trim().length ? '1' : '0';
    }
    clone.status = status;
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
      const rawId = s.dbID ?? s.string_id ?? s.id;
      const numericId = toInt(rawId);
      const stringKey = asString(s.id || rawId || numericId || '');

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
        id: stringKey || (numericId ? String(numericId) : ''),
        translation_id: numericId,
        original: asString(s.original || s.source || s.text || ''),
        page_url: asString(s.page_url || s.url || ''),
        post_id: toInt(s.post_id || s.page_id || 0),
        context: asString(s.context || s.block_context || 'content'),
        block_id: asString(s.block_id || ''),
        source_lang_id: toInt(s.source_lang_id || s.source_id || 0),
        target_lang_id: toInt(s.target_lang_id || s.target_id || 0),
        source_lang_code: asString(s.source_lang_code || s.source_language || ''),
        target_lang_code: targetCode,
        translations: baseTranslations
      };
    });
  }

  function pickSuggestions(data) {
    const rows = pickArray(data || {}, ['suggestions', 'results', 'translations']);
    // chaque entry peut être string, {translated_text}, {text}, …
    return rows.map(x =>
      typeof x === 'string' ? x
        : x.translated_text || x.translation || x.translated || x.text || ''
    ).filter(Boolean);
  }

  // --- Helpers critiques ---
  function getParam(name, url) {
    url = url || window.location.href;
    name = name.replace(/[\[\]]/g, "\\$&"); // <-- fix: échappe [ et ]
    var regex = new RegExp("[?&]" + name + "(=([^&#]*)|&|#|$)"),
        results = regex.exec(url);
    if (!results || !results[2]) return "";
    return decodeURIComponent(results[2].replace(/\+/g, " "));
  }

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

  // Appel AJAX (WordPress admin-ajax) avec contrôle même-origine
  const apiPost = (action, payload = {}) => {
    let n;
    try { n = nonceFor(action); }
    catch (e) {
      try { console.error('[YUZ][TE] Sécurité: nonce manquant pour', action); } catch(_){ }
      return $.Deferred().reject(e).promise();
    }

    let urlStr = Y.ajax_url || Y.ajax_url;
    let url;
    try { url = new URL(urlStr, window.location.origin); } catch (_) { url = new URL(Y.ajax_url, window.location.origin); }
    if (url.origin !== window.location.origin) {
      log('critical','[YUZ][TE] ajax_url cross-origin — bloqué', { ajax_url: urlStr });
      return $.Deferred().reject(new Error('CROSS_ORIGIN')).promise();
    }

    const data = { action, nonce: n, yuz_tra_nonce: n, _ajax_nonce: n, ...payload };
    try { console.debug('[YUZ][TE][POST]', { url: url.toString(), ...data }); } catch(_) {}

    return $.ajax({
      url: url.toString(),
      type: 'POST',
      dataType: 'json',
      data
    });
  };

  // Throttle & Debounce
  const throttle = (fn, wait=300) => {
    let last=0, timer=null, lastArgs=null, lastThis=null;
    return function throttled(...args){
      const now=Date.now(), remaining = wait - (now-last);
      lastArgs=args; lastThis=this;
      if (remaining<=0){ clearTimeout(timer); timer=null; last=now; fn.apply(lastThis,lastArgs); lastArgs=lastThis=null; }
      else if (!timer){ timer=setTimeout(()=>{ last=Date.now(); timer=null; fn.apply(lastThis,lastArgs); lastArgs=lastThis=null; }, remaining); }
    };
  };
  const debounce = (fn, wait=400) => {
    let t=null;
    return function (...args){ clearTimeout(t); t=setTimeout(()=>fn.apply(this,args), wait); };
  };

  // Ensure container
  function ensureContainer(id='yuz-editor-container') {
    let el = document.getElementById(id);
    if (!el) {
      el = document.createElement('div');
      el.id = id;
      document.body.appendChild(el);
    }
    return el;
  }

  // -------------------------------
  // Vue app (Vue importée)
  // -------------------------------
  const mountApp = () => {
    ensureContainer();

    return new Vue({
      el: '#yuz-editor-container',
      data() {
        return {
          booting: true,
          error: '',
          ADV: !!(Y && Y.flags && Y.flags.editor_advanced_ui),
          layout: 'detailed',
          mode: (Y && Y.site_settings && Y.site_settings.translation_mode) || 'half',
          // Languages & context
          sourceLanguage: 'auto',
          currentLanguage: '',
          languages: [],               // [{language_code, language_name, native_name?}]
          languageNames: {},           // { code: name }
          // Strings
          items: [],                   // [{id, original, translations: { [code]: {translated, status} }}]
          index: -1,
          dirtySet: new Set(),         // ids with unsaved edits
          suggestions: [],
          // UI
          urlToLoad: (Y.site_settings && Y.site_settings.url_to_load) || window.location.href.split('#')[0],
          loading: 0,
          query: '',
          // Throttled handlers
          throttledSuggest: null,
          throttledSaveOne: null,
          _hvyWarned: false            // anti-spam HVY
        };
      },
      computed: {
        current() {
          return (this.index>=0 && this.index<this.items.length) ? this.items[this.index] : null;
        },
        editedValue: {
          get() {
            if (!this.current) return '';
            const t = (this.current.translations||{})[this.getCurrentLanguage()] || {};
            return t.edited != null ? t.edited : (t.translated || '');
          },
          set(val) {
            if (!this.current) return;
            const code = this.getCurrentLanguage();
            if (!this.current.translations) this.current.translations = {};
            if (!this.current.translations[code]) this.current.translations[code] = { translated:'', status:'0' };
            this.current.translations[code].edited = val;
            this.dirtySet.add(this.current.id);
            if (this.throttledSuggest) this.throttledSuggest();
            if (this.throttledSaveOne) this.throttledSaveOne();
          }
        },
        progress() {
          if (!this.items.length || !this.languages.length || !this.getCurrentLanguage()) return 0;
          const code = this.getCurrentLanguage();
          const num = this.items.reduce((acc, it) => {
            const t = (it.translations||{})[code];
            return acc + ((t && (t.status==='1' || t.status==='2')) ? 1 : 0);
          }, 0);
          return Math.round( (num / this.items.length) * 100 );
        }
      },
      created() {
        // Build throttled funcs
        this.throttledSuggest = throttle(() => {
          if (!this.current) return;
          this.fetchSuggestions(this.current.original);
        }, 500);
        this.throttledSaveOne = debounce(() => {
          if (!this.current) return;
          this.saveOne(this.current);
        }, 1200);
      },
      mounted() {
        this.bootstrap();
        this.bindKeys();
        this.setupDomBridge();
      },
      methods: {
        toggleLayout() { this.layout = this.layout === 'detailed' ? 'compact' : 'detailed'; },
        refresh() { this.bootstrap(); },

        invertLangs() {
          const cur = this.getCurrentLanguage();
          const src = this.sourceLanguage || 'auto';
          if (!cur) return;
          this.sourceLanguage = cur;
          this.currentLanguage = src === 'auto' ? cur : src; // if auto, keep cur as target
        },

        publishOne() {
          if (!this.current) return;
          const code = this.getCurrentLanguage();
          const t = (this.current.translations||{})[code] || {};
          const payload = {
            string_id: this.current.id,
            target_lang: code,
            page_url: this.urlToLoad,
            translated_text: t.edited != null ? t.edited : t.translated || ''
          };
          this.loading++;
          apiPost(ACTION.TE_PUBLISH, payload)
            .done(r => {
              if (r && r.success) {
                if (!this.current.translations) this.current.translations = {};
                if (!this.current.translations[code]) this.current.translations[code] = {};
                this.current.translations[code].translated = payload.translated_text;
                this.current.translations[code].status = '2';
                delete this.current.translations[code].edited;
                this.dirtySet.delete(this.current.id);
                toast('Publié.', 'success');
              } else {
                toast(r?.data?.message || 'Échec de la publication.', 'error');
              }
            })
            .fail(() => {
              toast('Erreur AJAX lors de la publication.', 'error');
            })
            .always(() => { this.loading = Math.max(0, this.loading-1); });
        },

        previewCurrent() {
          try {
            const url = new URL(this.urlToLoad, window.location.origin);
            const code = this.getCurrentLanguage();
            url.searchParams.set('yuz-target-lang', code);
            window.open(url.toString(), '_blank', 'noopener');
          } catch (e) {}
        },

        resetPosition() {
          const el = document.getElementById('yuz-translation-editor');
          if (!el) return;
          el.style.left = '20px';
          el.style.right = '';
          el.style.top = '';
          el.style.bottom = '20px';
        },

        getCurrentLanguage(){
          return this.currentLanguage || (window.yuzTE && window.yuzTE.defaultLang) || '';
        },

        // ---------- Bootstrap ----------
        bootstrap() {
          this.booting = true;
          this.loading++;

          const page_url =
            getParam('yuz-edit-translation-url') ||
            (this.urlToLoad || window.location.href.split('#')[0]);

          const target_lang = normalizeTargetLang(
            this.getCurrentLanguage(),
            this.languages,
            [
              (Y && Y.defaultLang),
              (Y && Y.site_settings && Y.site_settings.default_lang)
            ]
          );

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
            // Languages
            const langs = Array.isArray(data.languages) ? data.languages : (Array.isArray(data.langs) ? data.langs : []);
            this.languages = langs;
            this.languageNames = langs.reduce((acc, l) => (acc[l.language_code] = l.language_name || l.name || l.language_code, acc), {});
            // Source & current
            this.sourceLanguage = (this.mode === 'full') ? 'auto' : (data.source_language || 'auto');
            this.currentLanguage = normalizeTargetLang(
              data.current_language || this.currentLanguage,
              this.languages,
              [
                (Y && Y.defaultLang),
                (Y && Y.site_settings && Y.site_settings.default_lang)
              ]
            );
            // Strings
            this.items = normalizeStrings(data)
  .map(it => {
    const cleaned = stripCssJsNoise(it.original || '');
    // jette ce qui reste du code/bruit
    if (!cleaned || yuzLooksLikeCode(cleaned)) return null;
    return { ...it, original: cleaned }; // on écrase l'original par la version nettoyée
  })
  .filter(Boolean);

const pruned = (data?.strings?.length || data?.results?.length || 0) - this.items.length;
if (pruned > 0) { try { console.warn('[YUZ][filter] pruned noisy entries:', pruned); } catch(_){} }

            this.index = this.items.length ? 0 : -1;
            log('success','[YUZ][TE] Bootstrap OK', { langs: this.languages.length, strings: this.items.length });
            this.$nextTick(()=> this.mapDomToItems());
          })
          .fail(xhr => {
            const status = xhr?.status;
            this.error = status===401||status===403 ? 'Permissions insuffisantes.' : 'Erreur de chargement.';
            log('critical','[YUZ][TE] Bootstrap error', { status, body: xhr?.responseText?.slice?.(0,300) });
          })
          .always(() => { this.loading = Math.max(0, this.loading-1); this.booting = false; });
        },

        // ---------- Navigation ----------
        prev() {
          if (!this.items.length) return;
          this.index = (this.index <= 0) ? this.items.length-1 : this.index-1;
          this.suggestions = [];
        },
        next() {
          if (!this.items.length) return;
          this.index = (this.index >= this.items.length-1) ? 0 : this.index + 1;
          this.suggestions = [];
        },

        // ---------- Search / Filter (fetch/search = tra) ----------
        search() {
          const q = (this.query||'').trim();
          this.loading++;

          const page_url =
            getParam('yuz-edit-translation-url') ||
            (this.urlToLoad || window.location.href.split('#')[0]);

          const target_lang = normalizeTargetLang(
            this.getCurrentLanguage(),
            this.languages,
            [
              (Y && Y.defaultLang),
              (Y && Y.site_settings && Y.site_settings.default_lang)
            ]
          );

          apiPost(ACTION.TM_GET, { page_url, q, target_lang, limit: 50, offset: 0 })
            .done(r => {
              if (isOk(r)) {
                this.items = normalizeStrings(r.data || {});
                this.index = this.items.length ? 0 : -1;
                log('success','[YUZ][TE] Search OK', { q, count: this.items.length });
              } else {
                toast(r?.data?.message || 'Aucun résultat.', 'warning');
              }
            })
            .fail(xhr => {
              log('critical','[YUZ][TE] Search error', { status: xhr?.status, body: xhr?.responseText?.slice?.(0,200) });
              toast('Erreur de recherche.', 'error');
            })
            .always(()=> { this.loading = Math.max(0, this.loading-1); });
        },

        // ---------- Save one / bulk (save/bulk = int) ----------
        saveOne(item) {
          if (!item) return;
          const code = this.getCurrentLanguage();
          const t = (item.translations||{})[code] || {};
          const edited = t.edited != null ? t.edited : t.translated || '';
          if (!edited) return;

          const translationId = toInt(item.translation_id || item.dbID || item.string_id || item.id || 0);
          const postId = toInt(item.post_id || 0);
          const context = (item.context || 'content').toString();
          const blockId = (item.block_id != null ? String(item.block_id) : '');
          const sourceCode = this.sourceLanguage || CFG.source_language || CFG.default_language || '';
          const statusParsed = parseInt(t.status != null ? t.status : item.status, 10);
          const status = Number.isNaN(statusParsed) ? 0 : statusParsed;
          const pageUrl = item.page_url || this.urlToLoad;

          const payload = {
            string_id: translationId,
            target_lang: code,
            language_code: code,
            translated_text: edited,
            original_text: item.original || '',
            post_id: postId,
            context,
            block_id: blockId,
            page_url: pageUrl,
            status,
            origin: 'manual'
          };

          if (sourceCode) {
            payload.source_lang = sourceCode;
            payload.source_lang_code = sourceCode;
          }
          if (item.source_lang_id) {
            payload.source_lang_id = toInt(item.source_lang_id);
          }
          if (item.target_lang_id) {
            payload.target_lang_id = toInt(item.target_lang_id);
          }

          apiPost(ACTION.TE_SAVE, payload).done(r => {
            if (r && r.success) {
              const savedId = toInt(r?.data?.id || (r?.data && r.data.ID));
              if (savedId) {
                item.translation_id = savedId;
              }
              if (!item.translations) item.translations = {};
              if (!item.translations[code]) item.translations[code] = {};
              item.translations[code].translated = edited;
              item.translations[code].status = '2';
              delete item.translations[code].edited;
              this.dirtySet.delete(item.id);
              log('success','[YUZ][TE] Saved', { id: item.id, lang: code });
            } else {
              toast(r?.data?.message || 'Échec de l’enregistrement.', 'error');
            }
          }).fail(xhr => {
            log('critical','[YUZ][TE] Save error', { status: xhr?.status });
          });
        },

        saveAll() {
          if (!this.dirtySet.size) { toast('Aucun changement à enregistrer.', 'warning'); return; }
          const code = this.getCurrentLanguage();
          const payload = [];
          this.items.forEach(it => {
            if (this.dirtySet.has(it.id)) {
              const t = (it.translations||{})[code] || {};
              const edited = t.edited != null ? t.edited : t.translated || '';
              if (edited) payload.push({ string_id: it.id, translated_text: edited });
            }
          });
          if (!payload.length) { toast('Aucun changement valide.', 'warning'); return; }

          this.loading++;
          apiPost(ACTION.TE_PUBLISH, {
            page_url: this.urlToLoad,
            target_lang: code,
            entries: JSON.stringify(payload)
          }).done(r => {
            if (r && r.success) {
              payload.forEach(p => {
                const it = this.items.find(x => x.id===p.string_id);
                if (!it) return;
                if (!it.translations) it.translations = {};
                if (!it.translations[code]) it.translations[code] = {};
                it.translations[code].translated = p.translated_text;
                it.translations[code].status = '2';
                delete it.translations[code].edited;
                this.dirtySet.delete(it.id);
              });
              toast(r.data?.message || 'Traductions enregistrées.', 'success');
              log('success','[YUZ][TE] Bulk save OK', { count: payload.length });
            } else {
              toast(r?.data?.message || 'Échec enregistrement groupé.', 'error');
              log('warning','[YUZ][TE] Bulk save failed', r);
            }
          }).fail(xhr => {
            log('critical','[YUZ][TE] Bulk save error', { status: xhr?.status });
            toast('Erreur AJAX lors de l’enregistrement.', 'error');
          }).always(()=> { this.loading = Math.max(0, this.loading-1); });
        },

        // ---------- Suggest AI (suggest_ai = hvy) ----------
        fetchSuggestions(text) {
          const inText = (text || '').trim();
          if (!inText || inText.length < 2) return;

          const action = Y.nonces && Y.nonces[ACTION.AI_SUGGEST_BATCH]
            ? ACTION.AI_SUGGEST_BATCH
            : null;

          if (!action) {
            if (!this._hvyWarned) {
              log('info','[YUZ][TE] Suggestions désactivées (nonce HVY absent)');
              this._hvyWarned = true; // anti-spam
            }
            return;
          }

          const code = this.getCurrentLanguage();
          const src = this.sourceLanguage || 'auto';
          apiPost(action, {
            source_lang: src,
            target_langs: JSON.stringify([code]),
            texts: JSON.stringify([inText])
          }).done(r => {
            if (isOk(r)) {
              this.suggestions = pickSuggestions(r.data || {}).slice(0, 5);
              log('success','[YUZ][TE] Suggestions OK', { count: this.suggestions.length });
            } else {
              log('warning','[YUZ][TE] Suggestions failed', r);
            }
          }).fail(xhr => {
            log('critical','[YUZ][TE] Suggestions error', { status: xhr?.status });
          });
        },

        applySuggestion(s) {
          if (!this.current) return;
          const code = this.getCurrentLanguage();
          if (!this.current.translations) this.current.translations = {};
          if (!this.current.translations[code]) this.current.translations[code] = {};
          this.current.translations[code].edited = s;
          this.dirtySet.add(this.current.id);
        },

        // ---------- Misc ----------
        changeLang(newCode) {
          if (!newCode) return;
          this.currentLanguage = newCode;
          this.suggestions = [];
        },

        bindKeys() {
          document.addEventListener('keydown', (e) => {
            const mod = navigator.platform.includes('Mac') ? e.metaKey : e.ctrlKey;
            if (mod && e.altKey && e.key === 'ArrowRight') { e.preventDefault(); this.next(); }
            if (mod && e.altKey && e.key === 'ArrowLeft')  { e.preventDefault(); this.prev(); }
            if (mod && e.key.toLowerCase() === 's') { e.preventDefault(); this.saveAll(); }
          });
        },

        // ---------------- DOM Bridge: hover/select to focus an item ----------------
        setupDomBridge() {
          try {
            if (!document.getElementById('yuz-te-highlight-style')) {
              const css = document.createElement('style');
              css.id = 'yuz-te-highlight-style';
              try { if (window.CLAR && window.CLAR.cspNonce) { css.setAttribute('nonce', window.CLAR.cspNonce); } } catch(e) {}
              css.textContent = '.yuz-te-hl{outline:2px solid #0b7285;outline-offset:2px;cursor:pointer}';
              document.head.appendChild(css);
            }
          } catch(_){ }

          window.addEventListener('yuz_iframe_page_updated', () => this.mapDomToItems());
          document.addEventListener('mouseup', this.onMouseUpSelect);
        },

        buildOriginalIndex() {
          const m = new Map();
          for (let i = 0; i < this.items.length; i++) {
            const s = (this.items[i].original||'').trim();
            if (s && !m.has(s)) m.set(s, i);
          }
          this._origIndex = m;
        },

        mapDomToItems() {
          if (!Array.isArray(this.items) || !this.items.length) return;
          this.buildOriginalIndex();

          // Nettoyage préalable des anciens marquages
          try { document.querySelectorAll('[data-yuz-te-map]')?.forEach(el => el.removeAttribute('data-yuz-te-map')); } catch(_) {}

          // Aligne la liste d’exclusion avec la variante non-ESM
          const skipSelectors = [
            '#yuz-translation-editor',
            '#yuz-editor-container .yuz-modal',
            '#wpadminbar',
            '.modal-overlay',
            '.cookie-banner',
            '#cookie-banner',
            '#yuz-consent-banner',
            '.cookie-consent',
            '.cookie-consent-banner',
            '.cky-consent-container',
            '.cky-consent-overlay',
            '.cky-overlay',
            '.privacy-banner',
            '.banner-privacy',
            '.newsletter-section',
            '.newsletter-form',
            '.signup-form',
            '.quickform-container',
            '.quick-details-container',
            '.quick-details-button',
            '.details-row',
            '.details-item',
            '.details-video-wrapper',
            '.details-text-wrapper',
            '#inscriptionModal',
            '.inscription-modal',
            '#quickDetailsModal'
          ].join(',');

          const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT, {
            acceptNode: (node) => {
              if (!node || !node.parentElement) return NodeFilter.FILTER_REJECT;
              const pe = node.parentElement;
              // Exclusions strictes
              if (pe.closest('#yuz-translation-editor') || pe.closest('#yuz-editor-container .yuz-modal') || pe.closest('#wpadminbar')) return NodeFilter.FILTER_REJECT;
              if (pe.closest('style, script, noscript, template, textarea, code, pre')) return NodeFilter.FILTER_REJECT;
              if (skipSelectors && pe.closest(skipSelectors)) return NodeFilter.FILTER_REJECT;
              // Texte utile uniquement
              const t = (node.nodeValue||'').trim();
              if (!t || t.length < 2) return NodeFilter.FILTER_REJECT;
              // Filtre heuristique anti CSS/JS
              if (/[{][^}]*}/.test(t) || /#\w+\s*\{/.test(t)) return NodeFilter.FILTER_REJECT;
              return NodeFilter.FILTER_ACCEPT;
            }
          });

          const seen = new Set();
          while (walker.nextNode()) {
            const tn = walker.currentNode;
            const text = (tn.nodeValue||'').trim();
            if (seen.has(tn) || !this._origIndex.has(text)) continue;
            const idx = this._origIndex.get(text);
            const host = tn.parentElement;
            host.dataset.yuzTeMap = String(idx);
            host.addEventListener('mouseenter', this._hlEnter || (this._hlEnter = (e)=>{ e.currentTarget.classList.add('yuz-te-hl'); }), { passive: true });
            host.addEventListener('mouseleave', this._hlLeave || (this._hlLeave = (e)=>{ e.currentTarget.classList.remove('yuz-te-hl'); }), { passive: true });
            host.addEventListener('click', this._hlClick || (this._hlClick = (e)=>{
              const el = e.currentTarget;
              const n = parseInt(el?.dataset?.yuzTeMap||'-1', 10);
              if (!isNaN(n) && n>=0 && n<this.items.length) { this.index = n; this.suggestions = []; }
            }));
            seen.add(tn);
          }
        },

        onMouseUpSelect() {
          try {
            const sel = window.getSelection();
            if (!sel || !sel.toString) return;
            const txt = sel.toString().trim();
            if (!txt || txt.length < 2) return;
            let i = -1;
            const exact = this.items.findIndex(it => (it.original||'').trim() === txt);
            if (exact >= 0) i = exact; else {
              const approx = this.items.findIndex(it => (it.original||'').indexOf(txt) !== -1);
              if (approx >= 0) i = approx;
            }
            if (i >= 0) { this.index = i; this.suggestions = []; }
          } catch(_){ }
        }
      },
      template: `
        <div id="yuz-translation-editor" style="position:fixed;left:20px;bottom:20px;z-index:99998;max-width:720px;width:720px;background:#fff;border-radius:12px;box-shadow:0 12px 28px rgba(0,0,0,.16);overflow:hidden">
          <div style="display:flex;align-items:center;justify-content:space-between;padding:10px 14px;background:#0b7285;color:#fff">
            <strong>YUZ Translation Editor</strong>
            <div style="display:flex;gap:8px;align-items:center">
              <button @click="toggleLayout" class="button" title="Switch layout">{{ layout==='detailed' ? 'Compact' : 'Detailed' }}</button>
              <button @click="invertLangs" class="button" title="Invert From/To" aria-label="Invert From/To">⇄</button>
              <button v-if="ADV" @click="refresh" class="button">Refresh</button>
              <button v-if="ADV" @click="publishOne" class="button">Publish</button>
              <button v-if="ADV" @click="previewCurrent" class="button">Preview</button>
              <button @click="saveAll" class="button button-primary">Save all</button>
              <button v-if="ADV" @click="resetPosition" class="button">Reset Position</button>
              <button @click="$el.style.display='none'" class="button">Close</button>
            </div>
          </div>

          <div v-if="error" style="padding:10px 14px;background:#fff5f5;color:#c92a2a">{{ error }}</div>

          <div style="padding:12px 14px;display:grid;grid-template-columns:1fr 1fr;gap:8px;border-bottom:1px solid #f1f3f5">
            <div>
              <label style="display:block;font-weight:600;margin-bottom:4px">From</label>
              <select v-model="sourceLanguage" style="width:100%">
                <option value="auto">Auto</option>
                <option v-for="l in languages" :key="'s_'+l.language_code" :value="l.language_code">{{ l.language_name }}</option>
              </select>
            </div>
            <div>
              <label style="display:block;font-weight:600;margin-bottom:4px">To</label>
              <select :value="currentLanguage" @change="changeLang($event.target.value)" style="width:100%">
                <option v-for="l in languages" :key="'t_'+l.language_code" :value="l.language_code">{{ l.language_name }}</option>
              </select>
            </div>
          </div>

          <div style="padding:10px 14px;display:flex;gap:8px;align-items:center;border-bottom:1px solid #f1f3f5">
            <button @click="prev" class="button">← Prev</button>
            <div>Item {{ index>=0 ? index + 1 : 0 }} / {{ items.length }}</div>
            <button @click="next" class="button">Next →</button>
            <div style="margin-left:auto">Progress: <strong>{{ progress }}%</strong></div>
          </div>

          <div style="padding:0 14px 8px 14px">
            <div style="height:6px;background:#e9ecef;border-radius:9999px;overflow:hidden">
              <div :style="{width: (progress || 0) + '%', height: '100%', background: '#0b7285', transition: 'width .25s'}"></div>
            </div>
          </div>

          <div v-if="current && layout==='detailed'" style="padding:12px 14px;display:grid;grid-template-columns:1fr 1fr;gap:12px">
            <div>
              <label style="font-weight:600;display:block;margin-bottom:6px">Original</label>
              <textarea :value="current.original" readonly style="width:100%;min-height:120px"></textarea>
            </div>
            <div>
              <label style="font-weight:600;display:block;margin-bottom:6px">Translation ({{ languageNames[getCurrentLanguage()] || getCurrentLanguage() }})</label>
              <textarea :value="editedValue" @input="editedValue=$event.target.value" @focus="current && fetchSuggestions(current.original)" style="width:100%;min-height:120px"></textarea>

              <div v-if="suggestions.length" style="margin-top:8px">
                <div style="font-weight:600;margin-bottom:4px">Suggestions</div>
                <ul style="margin:0;padding-left:16px">
                  <li v-for="(s,i) in suggestions" :key="'sug_'+i" style="cursor:pointer" @click="applySuggestion(s)">{{ s }}</li>
                </ul>
              </div>
            </div>
          </div>

          <div style="padding:12px 14px;border-top:1px solid #f1f3f5;display:flex;gap:8px;align-items:center">
            <input v-model="query" @keyup.enter="search" placeholder="Search…" style="flex:1 1 auto;padding:8px;border:1px solid #dee2e6;border-radius:6px"/>
            <button @click="search" class="button">Search</button>
            <span v-if="loading" style="margin-left:auto;opacity:.7">Loading…</span>
          </div>
        </div>
      `
    });
  };

  // -------------------------------
  // Boot une fois les dépendances prêtes
  // -------------------------------
  const wait = (ok, cb, tries=100, interval=80) => {
    const id = setInterval(() => {
      if (ok()) { clearInterval(id); cb(); }
      else if (--tries<=0) {
        clearInterval(id);
        log('critical','[YUZ][TE] Dépendances non disponibles.');
        const lateTry = () => { if (ok()) { cb(); } };
        if (document.readyState === 'complete') { setTimeout(lateTry, 1200); }
        else {
          window.addEventListener('load', () => setTimeout(lateTry, 600), { once: true });
        }
      }
    }, interval);
  };

  wait(
    () => typeof $ !== 'undefined' && typeof Vue !== 'undefined',
    () => {
      log('success','[YUZ][TE] Dépendances OK. Mounting editor…');
      mountApp();
    }
  );

})(window, document);

// === SMART SEARCH — ONE BUTTON + OCCURRENCES (ESM) v2.3 ===
(() => {
  const toastFallback = (msg, lvl = 'info') => {
    try {
      const method = lvl === 'error' ? 'error' : lvl === 'warning' ? 'warn' : 'log';
      console[method](`[toast:${lvl}]`, msg);
    } catch (_) {}
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
      } catch (_) {}
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

    app.onSearchClick = function () {
      const q = canon(this.query || this.searchQuery || '');
      if (!q) { toast('Enter text to search.'); return; }
      return (q !== this.searchSnapshot) ? this.smartSearchStart() : this.smartSearchNext();
    };

    app.onSearchEnter = function () { this.onSearchClick(); };

    app._smartSearchInstalled = true;
    try {
      console.log(`[SMART][ESM] one-button search installed (phases: ${app.phaseOrder.join('→')})`);
    } catch (_) {}
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
  hydrate();

  if (typeof window !== 'undefined') {
    if (window.__YUZ_SMART_SEARCH_OBSERVER__) {
      try { clearInterval(window.__YUZ_SMART_SEARCH_OBSERVER__); } catch (_) {}
    }
    window.__YUZ_SMART_SEARCH_OBSERVER__ = observer;
    window.addEventListener('yuz:ui:mount', () => { setTimeout(hydrate, 0); });
    window.addEventListener('yuz:ui:unmount', () => { lastApp = null; });
    window.addEventListener('beforeunload', () => clearInterval(observer), { once: true });
  }
})();

// === INSTANT INGEST (persist) — ESM ===
(() => {
  const Y   = window.yuzTE || {};
  const app = window.YUZ_EDITOR_APP || {};
  const ajaxURL = Y.ajax_url || Y.ajax_url;
  const nonce   = pickNonce('yuz_tra_tm_translate');
  const from    = app.sourceLanguage || 'auto';
  const to      = (app.getCurrentLanguage && app.getCurrentLanguage()) || 'en_US';

  async function instantIngest(list){
    const clean   = s => String(s || '').replace(/[\r\n\t]+/g,' ').trim();
    const payload = (list || []).map((t,i) => ({ i, text: clean(t) })).filter(r => r.text);

    const body = new URLSearchParams();
    body.set('action','yuz_tra_tm_translate');
    body.set('_ajax_nonce', nonce);
    body.set('nonce',       nonce);
    body.set('yuz_tra_nonce', nonce);
    body.set('req_id', (crypto && crypto.randomUUID ? crypto.randomUUID() : Math.random().toString(36).slice(2)));
    body.set('payload', JSON.stringify(payload));
    body.set('source', from || 'auto');
    body.set('target', to);
    body.set('context','instant');   // <— crucial : tag “instant”
    body.set('persist','1');         // <— on stocke

    const res = await fetch(ajaxURL, {
      method: 'POST',
      headers: { 'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8' },
      credentials: 'same-origin',
      body: body.toString()
    });
    const j = await res.json().catch(() => ({}));
    console.log('[INSTANT][ESM] persisted', j && j.data);
    return j && j.data;
  }

  // exposé dev
  window.instantIngest = instantIngest;
})();

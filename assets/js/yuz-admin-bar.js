/**
 * assets/js/yuz-admin-bar.js
 * Admin bar helpers (standard).
 * Require: window.yuzAB localized with:
 *  - can_manage_options:boolean, can_translate:boolean
 *  - plugin_version:string
 *  - target_languages:string[], language_names:object
 *  - nonces:{yuz_tra_nonce:string}
 *  - urls:{ serviceModule:string }
 */

(function (window, document) {
  'use strict';

  const AB = window.yuzAB || {};
  const log   = (...a) => console.log('[YUZ][ADMINBAR]', ...a);
  const warn  = (...a) => console.warn('[YUZ][ADMINBAR]', ...a);
  const error = (...a) => console.error('[YUZ][ADMINBAR]', ...a);

  // ---- Guards ---------------------------------------------------------------
  if (!window.jQuery) { error('jQuery unavailable, aborting'); return; }
  if (!AB || (AB.can_manage_options === false && AB.can_translate === false)) {
    warn('yuzAB absent or no rights — exit'); return;
  }

  const $ = window.jQuery;
  const isAdmin = /\/wp-admin\//.test(location.pathname);

  // ---- Remember last front page (for front-first fallback) ------------------
  try {
    if (!isAdmin) {
      localStorage.setItem('yuz_last_page', location.href.split('#')[0]);
    }
  } catch (_) {}

  // ---- Service loader (translateNow provider) -------------------------------
  const serviceUrl = (AB.urls && AB.urls.serviceModule) ||
                     '/wp-content/plugins/yuz-tra/assets/js/yuz-translation-service.js';
  const ver = AB.plugin_version || Date.now();
  let serviceLoaded = false;

  async function ensureService() {
    // 0) Already available?
    if (serviceLoaded && typeof window.translateNow === 'function') return true;
    if (window.YUZ_TranslationService && typeof window.YUZ_TranslationService.startTranslation === 'function') {
      // Provide a thin translateNow wrapper on top of UMD
      window.translateNow = async function ({ page_url, target_langs, text }) {
        const nonce = (AB.nonces && AB.nonces.yuz_tra_nonce) || AB.nonce || '';
        return window.YUZ_TranslationService.startTranslation({
          action: 'yuz_start_translation',
          page_url,
          target_langs,
          text,
          endpoint: AB.ajax_url || (window.yuzTraSettings && window.yuzTraSettings.ajax_url) || (() => { throw new Error('YUZ-TRA: AJAX endpoint not configured'); })()
        });
      };
      serviceLoaded = true;
      log('translateNow ready via UMD');
      return true;
    }

    // 1) Try dynamic ESM import (may fail under CSP/optimizers)
    try {
      const mod = await import(`${serviceUrl}?ver=${encodeURIComponent(ver)}`);
      if (mod && typeof mod.translateNow === 'function') {
        window.yuzService   = mod;
        window.translateNow = mod.translateNow;
        serviceLoaded = true;
        log('translateNow ready (ESM)');
        return true;
      }
      error('ESM loaded but translateNow missing');
    } catch (e) {
      warn('ESM import failed; trying UMD script', e);
    }

    // 2) Fallback: load classic UMD script tag, then expose translateNow
    try {
      await new Promise((resolve, reject) => {
        const s = document.createElement('script');
        s.src = `${serviceUrl}?ver=${encodeURIComponent(ver)}`;
        s.async = true;
        s.onload = () => resolve();
        s.onerror = () => reject(new Error('UMD load error'));
        document.head.appendChild(s);
      });
      if (window.YUZ_TranslationService && typeof window.YUZ_TranslationService.startTranslation === 'function') {
        window.translateNow = async function ({ page_url, target_langs, text }) {
          const nonce = (AB.nonces && AB.nonces.yuz_tra_nonce) || AB.nonce || '';
          return window.YUZ_TranslationService.startTranslation({
            action: 'yuz_start_translation',
            page_url,
            target_langs,
            text,
            endpoint: AB.ajax_url || (window.yuzTraSettings && window.yuzTraSettings.ajax_url) || (() => { throw new Error('YUZ-TRA: AJAX endpoint not configured'); })()
          });
        };
        serviceLoaded = true;
        log('translateNow ready (UMD)');
        return true;
      }
      error('UMD loaded but service API missing');
    } catch (e) {
      error('Failed to load service UMD', e);
    }

    return false;
  }

  // ---- Core action: Translate Now ------------------------------------------
  async function handleTranslateNowClick(ev) {
    try {
      // si le lien pointe déjà vers l’overlay → laisser le nav
      const a = ev.target.closest('a');
      const href = a && (a.getAttribute('href') || a.href) || '';
      if (href && href.indexOf('yuz-edit-translation=1') !== -1) return;

      ev.preventDefault();

      // 1) Essayer le service module (backed by AJAX)
      const ok = await ensureService();
      if (ok) {
        const currentPageUrl = location.href.split('#')[0];

        const langs = Array.isArray(AB.target_languages) && AB.target_languages.length
          ? AB.target_languages
          : Object.keys(AB.language_names || {});

        if (!langs.length) {
          warn('No target languages configured → fallback to overlay');
          return frontOverlayFallback();
        }

        const nonce = (AB.nonces && AB.nonces.yuz_tra_nonce) || AB.nonce || '';

        try {
          const result = await window.translateNow({
            nonce,
            page_url: currentPageUrl,
            target_langs: langs,
            distinct: false,
          });
          if (result && result.success && result.data && result.data.editor_url) {
            location.href = result.data.editor_url;
            return;
          }
          warn('translateNow did not return an editor_url → fallback', result);
        } catch (err) {
          error('translateNow error, fallback to overlay', err);
        }
      }

      // 2) Fallback : basculer sur le front overlay
      frontOverlayFallback();
    } catch (e) {
      error('Translate Now handler failed', e);
      frontOverlayFallback();
    }
  }

  function frontOverlayFallback() {
    try {
      const last = localStorage.getItem('yuz_last_page');
      const base = (last && !/\/wp-admin\//.test(last)) ? last : '/';
      const url = new URL(base, location.origin);
      url.searchParams.set('yuz-edit-translation', '1');
      location.href = url.href;
    } catch (_) {
      location.href = '/?yuz-edit-translation=1';
    }
  }

  // ---- Liseré actif de l’onglet Translate Site -----------------------------
  function highlightTranslateSiteTab() {
    const hasOverlay = new URL(location.href).searchParams.has('yuz-edit-translation');
    if (!hasOverlay) return;

    // WP nav-tabs en admin settings
    const sel = [
      'a.nav-tab[href*="page=yuz-translation-settings"][href*="tab=translate-site"]',
      'a[href*="page=yuz-translation-settings&tab=translate-site"]'
    ].join(',');

    const tab = document.querySelector(sel);
    if (tab) {
      tab.classList.add('nav-tab-active');
      // optionnel: enlever l’actif à ses siblings
      const sibs = tab.parentElement && tab.parentElement.querySelectorAll('.nav-tab');
      if (sibs) {
        sibs.forEach(el => { if (el !== tab) el.classList.remove('nav-tab-active'); });
      }
    }
  }

  // ---- Wire events ----------------------------------------------------------
  function bindClicks() {
    // Admin-bar WordPress (plusieurs ids selon versions/implémentations)
    document.addEventListener('click', function (e) {
      const hit = e.target.closest(
        '#wp-admin-bar-yuz_translate_now a, ' +
        '#wp-admin-bar-yuz-translate-now a, ' +
        '.yuz-translate-now, ' +
        '[data-yuz-translate-now]'
      );
      if (!hit) return;
      handleTranslateNowClick(e);
    });
  }

  // ---- Init -----------------------------------------------------------------
  $(function () {
    log('init');

    // Liseré actif si overlay
    highlightTranslateSiteTab();

    // Clics sur Translate Now
    bindClicks();

    // Petit confort: si un bouton dédié existe dans le DOM, le marquer "primary"
    const btn = document.querySelector('[data-yuz-translate-now]');
    if (btn && btn.classList && !btn.classList.contains('button-primary')) {
      btn.classList.add('button', 'button-primary');
    }
  });

})(window, document);

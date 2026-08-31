/* assets/js/yuz-translation-admin.js */
(() => {
  'use strict';

  const CFG = window.yuzTS || {};
  if (!CFG.ajax_url) {
    console.warn('[YUZ][TS] ajax_url manquant. Handlers will still attach for redirect-only actions.');
  }

  // Map logique → action AJAX
  const ACTIONS = {
    'get_settings':        'yuz_tra_ts_get_settings',
    'update_settings':     'yuz_tra_ts_upd_settings',
    'create_full':         'yuz_tra_ts_cre_fulltra',
    'start_translation':   'yuz_tra_ts_start_translation'
  };

  // Redirection vers l'éditeur front (équivalent du clic sur l'onglet de TranslatePress)
  function gotoEditor() {
    try {
      // 1) Essaye la dernière page front mémorisée
      let target = null;
      try {
        const last = localStorage.getItem('yuz_last_page');
        if (last && !/\/wp-admin\//.test(last)) target = last;
      } catch (_) {}

      // 2) Sinon home_url si fourni, sinon racine du site
      if (!target) {
        const home = (CFG && CFG.home_url) ? CFG.home_url : (window.location.origin || '/');
        target = String(home || '/');
      }

      // 3) Ajoute le flag d'édition et navigue
      try {
        const url = new URL(target, window.location.origin);
        url.searchParams.set('yuz-edit-translation', '1');
        console.log('[YUZ][TS] gotoEditor → redirect', { target: url.href });
        window.location.href = url.href;
      } catch (_) {
        // Fallback ultra-simple
        const href = String(target) + (String(target).indexOf('?')===-1?'?':'&') + 'yuz-edit-translation=1';
        console.log('[YUZ][TS] gotoEditor (fallback) → redirect', { target: href });
        window.location.href = href;
      }
    } catch (e) {
      console.error('[YUZ][TS] gotoEditor failed', e);
    }
  }

  // Récupère le nonce adapté à l'action (fallback sur yuz_tra_nonce)
  function nonceFor(actionKey) {
    const action = ACTIONS[actionKey] || actionKey;
    return (CFG.nonces && (CFG.nonces[action] || CFG.nonces.yuz_tra_nonce)) || '';
  }

  // Fusionne dataset + inputs du formulaire voisin
  function buildPayload(target, extra = {}) {
    const data = { ...extra };

    // 1) dataset direct
    for (const [k, v] of Object.entries(target.dataset)) {
      // dataset camelCase => kebab ou snake au besoin côté PHP si nécessaire
      data[k] = v;
    }

    // 2) si dans un <form> → serialize
    const form = target.closest('form') || target.form || target.closest('[data-form-scope]');
    if (form) {
      const fd = new FormData(form);
      for (const [k, v] of fd.entries()) data[k] = v;
    }

    return data;
  }

  // Post AJAX (fetch)
  async function postAjax(actionKey, payload = {}, opts = {}) {
    const action = ACTIONS[actionKey] || actionKey;
    const body   = new FormData();
    body.set('action', action);
    body.set('_ajax_nonce', nonceFor(actionKey));

    Object.keys(payload || {}).forEach((k) => {
      // Normalise objets/arrays
      const val = payload[k];
      body.set(k, (typeof val === 'object') ? JSON.stringify(val) : val);
    });

    const res = await fetch(CFG.ajax_url, {
      method: 'POST',
      credentials: 'same-origin',
      body
    });

    // Essaie JSON, fallback texte
    const ct = res.headers.get('content-type') || '';
    if (ct.includes('application/json')) {
      return await res.json();
    }
    return await res.text();
  }

  // UI helpers (state/busy)
  function setBusy(el, busy = true) {
    if (!el) return;
    el.toggleAttribute('data-busy', busy);
    if ('disabled' in el) el.disabled = !!busy;
    el.classList.toggle('is-busy', !!busy);
  }

  function toast(type, msg) {
    // placeholder non intrusif (console)
    const tag = (type === 'error') ? 'error' : (type === 'warn' ? 'warn' : 'log');
    console[tag](`[YUZ][TS][${type.toUpperCase()}] ${msg}`);
  }

  // Handler principal (click / change / submit)
  async function handleAction(actionKey, target, event) {
    if (!actionKey) return;

    // Le bouton "Translate Now" doit se comporter comme l'onglet TranslatePress → redirection front
    if (actionKey === 'start_translation') {
      if (event) { try { event.preventDefault(); } catch (_) {} }
      gotoEditor();
      return;
    }

    const payload = buildPayload(target, { current_tab: 'translate-site' });

    try {
      setBusy(target, true);
      const json = await postAjax(actionKey, payload);

      // Convention WordPress AJAX : { success: bool, data: ... }
      if (typeof json === 'object' && json) {
        if (json.success) {
          toast('info', `${actionKey} ✓`);
          document.dispatchEvent(new CustomEvent('yuz:ts:success', { detail: { action: actionKey, payload, json } }));
        } else {
          toast('error', `${actionKey} ✗`);
          document.dispatchEvent(new CustomEvent('yuz:ts:fail', { detail: { action: actionKey, payload, json } }));
        }
      } else {
        // Réponse non JSON (ex: HTML/texte) — on notifie quand même
        toast('info', `${actionKey} → (text)`);
        document.dispatchEvent(new CustomEvent('yuz:ts:done', { detail: { action: actionKey, payload, text: json } }));
      }
    } catch (e) {
      console.error(e);
      toast('error', `${actionKey} exception`);
      document.dispatchEvent(new CustomEvent('yuz:ts:error', { detail: { action: actionKey, payload, error: e } }));
    } finally {
      setBusy(target, false);
      if (event && event.type === 'submit') {
        // Empêche double soumission visuelle
        try { event.preventDefault(); } catch (_) {}
      }
    }
  }

  // DÉLÉGATION — CLICK
  document.addEventListener('click', (e) => {
    const el = e.target.closest('[data-yuz-ts-action]');
    if (!el) return;
    e.preventDefault();
    const actionKey = el.getAttribute('data-yuz-ts-action');
    console.log('[YUZ][TS] click captured', { actionKey, id: el.id, classes: el.className });
    handleAction(actionKey, el, e);
  });

  // DÉLÉGATION — CHANGE (ex: <select>…)
  document.addEventListener('change', (e) => {
    const el = e.target.closest('[data-yuz-ts-action][data-yuz-ts-on="change"]');
    if (!el) return;
    const actionKey = el.getAttribute('data-yuz-ts-action');
    handleAction(actionKey, el, e);
  });

  // DÉLÉGATION — SUBMIT (formulaire marqué)
  document.addEventListener('submit', (e) => {
    const form = e.target.closest('form[data-yuz-ts-action]');
    if (!form) return;
    e.preventDefault();
    const actionKey = form.getAttribute('data-yuz-ts-action');
    handleAction(actionKey, form, e);
  });

  // UX — Liseré actif sur l'onglet cliqué (nav-tab)
  document.addEventListener('click', (e) => {
    const a = e.target.closest('a.nav-tab');
    if (!a) return;
    // Ne pas perturber un changement d'URL (laisser le navigateur naviguer)
    // mais marquer visuellement immédiatement l'onglet comme actif.
    try {
      const wrap = a.parentElement;
      if (wrap) {
        wrap.querySelectorAll('.nav-tab').forEach(el => el.classList.remove('nav-tab-active'));
      }
      a.classList.add('nav-tab-active');
    } catch (_) {}
  });

  console.log('[YUZ][TS] delegated handlers ready');
  console.log('[YUZ][TS] CFG snapshot', { ajax_url: CFG.ajax_url, hasHome: !!CFG.home_url, nonceKeys: Object.keys(CFG.nonces || {}) });
})();

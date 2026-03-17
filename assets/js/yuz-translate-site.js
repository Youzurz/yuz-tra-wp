var console = window.__YUZ_RELEASE_CONSOLE__ || {log:function(){},debug:function(){},info:function(){},warn:function(){},error:function(){}}; var yuz_release_console = console;


/**
 * assets/js/yuz-translate-site.js
 * Translate Site — admin tab & light front usage.
 *
 * Discipline:
 *  - ACT-03: ne lit que les données localisées pour CE module → window.yuzTS
 *  - ACT-05: aucune dépendance aux objets d’autres onglets (pas de yuzTraSettings)
 *
 * Localisation attendue (côté PHP - handle: 'yuz-translation-site'):
 *   window.yuzTS = {
 *     ajax_url: "...",
 *     nonces: {
 *       yuz_tra_ts_get_settings:     "...",
 *       yuz_tra_ts_upd_settings:     "...",
 *       yuz_tra_ts_cre_fulltra:      "...",
 *       yuz_tra_ts_start_translation:"..."
 *     },
 *     site_settings: { ... } // (optionnel) pré-hydratation
 *   }
 */

(function (window, document, $) {
  'use strict';

  // ---- Guards
  if (!window || !document) return;
  // Tolerant bootstrap: allow late localization (fallback attached to yuz-global)
  let Y = window.yuzTS;
  if (Y) {
    yuz_release_console.log('[YUZ][SITE] bootstrap global yuzTS', { hasNonces: !!(Y.nonces && Object.keys(Y.nonces||{}).length), nonceKeys: Object.keys(Y.nonces || {}) });
  }
  // Fallback: build from yuzTraSettings/yuzAS when localization was blocked by CSP
  function buildFromGlobalFallback() {
    try {
      const G = window.yuzTraSettings || window.yuzAS || {};
      const ajax_url = G.ajax_url || (G.settings && G.settings.ajax_url) || (window.ajaxurl || '/wp-admin/admin-ajax.php');
      const nonces   = (G.nonces && typeof G.nonces === 'object') ? G.nonces : {};
      // Require at least the two TS nonces
      if (!nonces['yuz_tra_ts_get_settings'] && !nonces['yuz_tra_ts_upd_settings']) return null;
      yuz_release_console.log('[YUZ][SITE] fallback constructed from yuzTraSettings/yuzAS', { ajax_url, nonceKeys: Object.keys(nonces || {}) });
      return { ajax_url, nonces, site_settings: (G.site_settings || {}) };
    } catch(_) { return null; }
  }

  if (!Y) {
    const fb = buildFromGlobalFallback();
    if (fb) {
      Y = fb;
    }
    document.addEventListener('DOMContentLoaded', function () {
      if (!Y && window.yuzTS) {
        // If it appears late, initialize now
        Y = window.yuzTS;
        yuz_release_console.log('[YUZ][SITE] bootstrap late yuzTS', { nonceKeys: Object.keys(Y.nonces || {}) });
      } else if (!Y) {
        const fb2 = buildFromGlobalFallback();
        if (fb2) { Y = fb2; }
      }
      if (Y) {
        yuz_release_console.log('[YUZ][SITE] init with yuzTS', { nonceKeys: Object.keys(Y.nonces || {}) });
        try { fetchAndInit(); bindChangeHandlers(); bindTranslateNow(); } catch (_) {}
      }
    });
    // Avoid noisy warning; the page can still function without JS
    return;
  }

  if (!Y.ajax_url) {
    yuz_release_console.error('[YUZ][SITE] ajax_url manquant dans yuzTS. Abandon.');
    return;
  }

  // ---- Log / UX helpers
  const LVL = { critical:'🟥 [CRITICAL]', warning:'🟨 [WARNING]', success:'🟩 [SUCCESS]', info:'🟦 [INFO]' };
  function log(level, msg, ctx) {
    const ts = new Date().toISOString();
    let s = `${LVL[level]||''} ${msg} at ${ts}`;
    if (ctx) { try { s += ` | ${JSON.stringify(ctx)}`; } catch(_){} }
    (level==='critical'?yuz_release_console.error:level==='warning'?yuz_release_console.warn:yuz_release_console.log)(s);
  }
  function toast(message, type='info', duration=2600) {
    const el = document.createElement('div');
    el.className = 'yuz-toast';
    el.style.cssText = 'position:fixed;right:16px;bottom:16px;padding:10px 14px;border-radius:8px;color:#fff;font:14px system-ui, -apple-system, Segoe UI, Roboto;z-index:99999;box-shadow:0 6px 18px rgba(0,0,0,.2);opacity:.98';
    el.style.background = type==='success'?'#2fb344':type==='error'?'#d6336c':type==='warning'?'#ffd43b':'#228be6';
    if (type==='warning') el.style.color = '#222';
    el.textContent = message;
    document.body.appendChild(el);
    setTimeout(()=>{ el.style.transition='opacity .25s'; el.style.opacity='0'; setTimeout(()=>el.remove(), 280); }, duration);
  }

  // ---- Actions / nonces
  const ACTION = {
    TS_GET   : 'yuz_tra_ts_get_settings',
    TS_UPD   : 'yuz_tra_ts_upd_settings',
    TS_FULL  : 'yuz_tra_ts_cre_fulltra',
    TS_START : 'yuz_tra_ts_start_translation',
  };
  function nonceFor(action) {
    if (Y && Y.nonces && Y.nonces[action]) return Y.nonces[action];
    return '';
  }

  // ---- Selectors (admin onglet)
  const SEL = {
    form                      : 'form#yuz-translate-site-form',
    enable_extra_languages    : '#yuz_tra_ts_settings\\[enable_extra_languages\\]',
    // Support both legacy and current names for the AI toggle
    // Prefer current 'enable_ai' but accept legacy 'enable_youzuruz'
    enable_ai                 : '#yuz_tra_ts_settings\\[enable_ai\\], #yuz_tra_ts_settings\\[enable_youzuruz\\]',
    translate_seo             : '#yuz_tra_ts_settings\\[translate_seo\\]',
    require_complete          : '#yuz_tra_ts_settings\\[require_complete\\]',
    user_role_emulation       : '#yuz_tra_ts_settings\\[user_role_emulation\\]',
    menu_per_lang             : '#yuz_tra_ts_settings\\[menu_per_lang\\]',
    browser_language_detect   : '#yuz_tra_ts_settings\\[browser_language_detect\\]',
    block_browser_translation : '#yuz_tra_ts_settings\\[block_browser_translation\\]',
    translation_mode          : '#yuz_tra_ts_settings\\[translation_mode\\]',
    allowed_roles             : '#yuz_tra_allowed_roles',
    translate_now_btn         : '#yuz_tra_translate_now',
    // zone conditionnelle
    row_translate_seo         : '.yuz-row-translate-seo'
  };

  // ---- AJAX helpers
  function handleXhrError(xhr, fallbackMsg) {
    const status = xhr?.status;
    if (status === 401 || status === 403) {
      toast('Permissions insuffisantes (401/403).', 'error');
      log('critical','[YUZ][SITE] 401/403', { status, response: xhr?.responseText?.slice?.(0,200) });
    } else {
      toast(fallbackMsg || 'Erreur AJAX.', 'error');
      log('critical','[YUZ][SITE] AJAX error', { status, response: xhr?.responseText?.slice?.(0,300) });
    }
  }

  function postAction(action, payload) {
    const cid = Date.now().toString(36) + Math.random().toString(36).slice(2,8);
    const data = Object.assign({ action, nonce: nonceFor(action), cid }, payload||{});
    log('info','[YUZ][SITE] AJAX POST', { action, cid, keys: Object.keys(payload||{}) });
    return $.ajax({ url: Y.ajax_url, type: 'POST', data })
      .always((res, status, xhr) => {
        // jQuery signature: for success -> (data, textStatus, jqXHR), for fail -> (jqXHR, textStatus)
        let ok = (typeof status === 'string') ? (status === 'success') : (xhr && xhr.status >= 200 && xhr.status < 300);
        try {
          const code = (xhr && xhr.status) || (res && res.status) || 0;
          log(ok? 'success' : 'warning', '[YUZ][SITE] AJAX DONE', { action, cid, status: status || (ok?'success':'error'), code });
        } catch(_) {}
      });
  }

  // Lightweight tracer for this module's AJAX
  (function attachAjaxTracer(){
    try {
      $(document).ajaxSend(function(_e, _xhr, settings){
        try {
          const isAdminAjax = /admin-ajax\.php/.test(String(settings.url||''));
          if (!isAdminAjax) return;
          const params = typeof settings.data === 'string' ? Object.fromEntries(new URLSearchParams(settings.data)) : (settings.data||{});
          const action = params.action || '';
          if (!/^yuz_tra_ts_/.test(action)) return; // only this module
          log('info','[TRACE][SEND]', { action, cid: params.cid||'(none)' });
        } catch(_) {}
      });
      $(document).ajaxComplete(function(_e, _xhr, settings){
        try {
          const isAdminAjax = /admin-ajax\.php/.test(String(settings.url||''));
          if (!isAdminAjax) return;
          const params = typeof settings.data === 'string' ? Object.fromEntries(new URLSearchParams(settings.data)) : (settings.data||{});
          const action = params.action || '';
          if (!/^yuz_tra_ts_/.test(action)) return;
          log('info','[TRACE][COMPLETE]', { action, cid: params.cid||'(none)' });
        } catch(_) {}
      });
    } catch(_) {}
  })();

  // ---- Admin UI logic
  function asBool(value) {
    if (value === null || value === undefined) { return false; }
    if (typeof value === 'boolean') { return value; }
    if (typeof value === 'number') { return value !== 0; }
    if (typeof value === 'string') {
      const trimmed = value.trim().toLowerCase();
      if (trimmed === '' || trimmed === '0' || trimmed === 'false' || trimmed === 'off' || trimmed === 'no') {
        return false;
      }
      return true;
    }
    return !!value;
  }

  function collectSettings() {
    const rolesValue = $(SEL.allowed_roles).val();
    const roles = Array.isArray(rolesValue)
      ? rolesValue.map(v => String(v))
      : rolesValue ? [String(rolesValue)] : [];

    return {
      enable_extra_languages   : $(SEL.enable_extra_languages).is(':checked') ? '1' : '0',
      enable_ai                : $(SEL.enable_ai).is(':checked') ? '1' : '0',
      enable_youzuruz          : $(SEL.enable_ai).is(':checked') ? '1' : '0',
      translate_seo            : $(SEL.translate_seo).is(':checked') ? '1' : '0',
      require_complete         : $(SEL.require_complete).is(':checked') ? '1' : '0',
      user_role_emulation      : $(SEL.user_role_emulation).val() || 'administrator',
      menu_per_lang            : $(SEL.menu_per_lang).is(':checked') ? '1' : '0',
      browser_language_detect  : $(SEL.browser_language_detect).is(':checked') ? '1' : '0',
      block_browser_translation: $(SEL.block_browser_translation).is(':checked') ? '1' : '0',
      translation_mode         : $(SEL.translation_mode).val() || 'half',
      allowed_roles            : roles
    };
  }

  function hydrateSettings(s) {
    if (!s || typeof s !== 'object') return;
    $(SEL.enable_extra_languages).prop('checked', asBool(s.enable_extra_languages || s.enable_extra));
    $(SEL.enable_ai).prop('checked', asBool(s.enable_youzuruz || s.enable_ai));
    $(SEL.translate_seo).prop('checked', asBool(s.translate_seo));
    $(SEL.require_complete).prop('checked', asBool(s.require_complete));
    $(SEL.user_role_emulation).val(s.user_role_emulation || 'administrator');
    $(SEL.menu_per_lang).prop('checked', asBool(s.menu_per_lang));
    $(SEL.browser_language_detect).prop('checked', asBool(s.browser_language_detect));
    $(SEL.block_browser_translation).prop('checked', asBool(s.block_browser_translation));
    $(SEL.translation_mode).val(s.translation_mode || 'half');
    if (Array.isArray(s.allowed_roles)) {
      $(SEL.allowed_roles).val(s.allowed_roles.map(String));
    }
    toggleFields();
  }

  function ensureModeField() {
    if ($(SEL.translation_mode).length) return; // already present
    const $table = $(SEL.form).find('table.form-table');
    if (!$table.length) return;
    const row = (
      '<tr class="yuz-row-translation-mode">' +
      ' <th scope="row"><label for="yuz_tra_ts_settings_translation_mode">' +
      '   Translation Mode' +
      ' </label></th>' +
      ' <td>' +
      '   <select id="yuz_tra_ts_settings_translation_mode" name="yuz_tra_ts_settings[translation_mode]" style="min-width:200px">' +
      '     <option value="half">Half (interactive)</option>' +
      '     <option value="full">Full (automatic)</option>' +
      '   </select>' +
      '   <p class="description">Full = background auto for all; Half = editor-driven.</p>' +
      ' </td>' +
      '</tr>'
    );
    // Insert after AI row if present, else at end
    const $aiRow = $table.find('tr:has(#yuz_tra_ts_settings\\[enable_ai\\])').first();
    if ($aiRow.length) { $(row).insertAfter($aiRow); }
    else { $table.append(row); }
  }

  function toggleFields() {
    const enabledAI = $(SEL.enable_ai).is(':checked');
    // masquer/afficher la ligne SEO (selon ton markup)
    if ($(SEL.row_translate_seo).length) {
      $(SEL.row_translate_seo).toggle(!!enabledAI);
    } else {
      // fallback: cherche la ligne par proximité
      $(SEL.translate_seo).closest('tr, .yuz-row, .form-table tr').toggle(!!enabledAI);
    }
  }

  function bindChangeHandlers() {
    const inputs = [
      SEL.enable_extra_languages, SEL.enable_ai, SEL.translate_seo, SEL.require_complete,
      SEL.user_role_emulation, SEL.menu_per_lang, SEL.browser_language_detect,
      SEL.block_browser_translation,
      SEL.allowed_roles,
      SEL.translation_mode
    ].join(', ');
    $(document).off('change.yuzTS').on('change.yuzTS', inputs, function () {
      if (this === $(SEL.enable_ai)[0]) toggleFields();
      const settings = collectSettings();
      log('info','[YUZ][SITE] Update settings payload', settings);
      postAction(ACTION.TS_UPD, { site_settings: settings })
        .done(r => {
          if (r && r.success) {
            toast(r.data?.message || 'Paramètres enregistrés.', 'success');
            log('success','[YUZ][SITE] Settings saved', r.data);
          } else {
            toast(r?.data?.message || 'Échec de l’enregistrement.', 'error');
            log('warning','[YUZ][SITE] Save failed', r);
          }
        })
        .fail(xhr => handleXhrError(xhr, 'Échec AJAX lors de l’enregistrement.'));
    });

    // Submit formulaire (si présent)
    $(document).off('submit.yuzTS', SEL.form).on('submit.yuzTS', SEL.form, function (e) {
      e.preventDefault();
      const settings = collectSettings();
      postAction(ACTION.TS_UPD, { site_settings: settings })
        .done(r => {
          if (r && r.success) {
            toast(r.data?.message || 'Paramètres enregistrés.', 'success');
          } else {
            toast(r?.data?.message || 'Échec de l’enregistrement.', 'error');
          }
        })
        .fail(xhr => handleXhrError(xhr, 'Échec AJAX lors de l’enregistrement.'));
    });
  }

  function bindTranslateNow() {
    $(document).off('click.yuzTS', SEL.translate_now_btn).on('click.yuzTS', SEL.translate_now_btn, function (e) {
      e.preventDefault();

      // Redirection front-first avec ?yuz-edit-translation=1
      // 1) Essaye dernière page front mémorisée
      let target = null;
      try {
        const last = localStorage.getItem('yuz_last_page');
        if (last && !/\/wp-admin\//.test(last)) target = last;
      } catch(_) {}
      // 2) Sinon, utilisez l'URL fournie (home)
      if (!target) target = (Y.home_url || '/');
      try {
        const url = new URL(target, window.location.origin);
        url.searchParams.set('yuz-edit-translation', '1');
        window.location.href = url.href;
      } catch(_) {
        // Fallback ultra-simple
        window.location.href = String(target) + (String(target).indexOf('?')===-1?'?':'&') + 'yuz-edit-translation=1';
      }
    });
  }

  function fetchAndInit() {
    // Pré-hydrate depuis yuzTS.site_settings si déjà fourni
    if (Y.site_settings && $(SEL.form).length) {
      hydrateSettings(Y.site_settings);
    }

    // Récupère les réglages depuis le serveur (admin)
    if ($(SEL.form).length) {
      postAction(ACTION.TS_GET)
        .done(r => {
          if (r && r.success) {
            const s = r.data?.settings || r.data || {};
            hydrateSettings(s);
            log('success','[YUZ][SITE] Settings loaded');
          } else {
            log('warning','[YUZ][SITE] Load settings failed', r);
          }
        })
        .fail(xhr => handleXhrError(xhr, 'Erreur de chargement des paramètres.'));
    }
  }

  // ---- Boot
  document.addEventListener('DOMContentLoaded', function () {
    const isAdminContext = !!document.querySelector(SEL.form);

    if (isAdminContext) {
      ensureModeField();
      log('info','[YUZ][SITE] Admin Translate Site detected');
      fetchAndInit();
      bindChangeHandlers();
      bindTranslateNow();
    } else {
      // Front: on expose juste le bouton si présent
      log('info','[YUZ][SITE] Front mode');
      bindTranslateNow();
    }

    log('success','[YUZ][SITE] yuz-translate-site.js initialized');
  });

})(window, document, window.jQuery);

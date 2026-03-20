var yuz_release_console={log(){},debug(){},info(){},warn(){},error(){},groupCollapsed(){},groupEnd(){},table(){}};


(function($){
  // Guard: only run on YUZ Translation settings page (General tab context)
  if (
    !document.body.classList.contains('toplevel_page_yuz-translation-settings') &&
    !document.querySelector('#yuz_tra_translatable_list')
  ) {
    return;
  }

  /**
   * assets/js/yuz-general-settings.js
   * General tab only – uses window.yuzGS + per-action nonces.
   * Disciplines: ACT-02, ACT-03, ACT-04, ACT-11
   */
  /* ===========================================================================================
     --- SAFE LOGGER (ne jette jamais) ---
     =========================================================================================== */
  window.LOG = window.LOG || {
  critical: '🟥[CRIT]',
  error: '🟥[ERR]',
  warning: '🟨[WARN]',
  info: '🟦[INFO]',
  success: '🟩[OK]'
  };


  function yuzSafeReplacer(key, value) {
  try {
  if (value instanceof Element) return '<' + value.tagName.toLowerCase() + '…>';
  if (value && value.window === value) return '[window]';
  if (value instanceof XMLHttpRequest) return '[XHR]';
    } catch (_) {}
  return value;
  }
  function log(level, message, ctx) {
  try {
  var ts = new Date().toISOString();
  var head = (window.LOG && window.LOG[level] ? window.LOG[level] : '[' + String(level).toUpperCase() + ']')
  + ' ' + String(message) + ' at ' + ts;
  if (typeof ctx !== 'undefined') {
  try { head += ' | ' + JSON.stringify(ctx, yuzSafeReplacer); }
  catch (_) { head += ' | [ctx unserializable]'; }
      }
  var fn = (level === 'critical' || level === 'error') ? 'error' : (level === 'warning' ? 'warn' : 'log');
      (console && console[fn] ? console[fn] : yuz_release_console.log)(head);
    } catch (e) {
    }
  }

  var sortableFallbackPromise = null;
  function loadSortableFallback() {
    if (window.Sortable) return Promise.resolve(window.Sortable);
    if (!sortableFallbackPromise) {
      sortableFallbackPromise = new Promise(function(resolve, reject){
        var script = document.createElement('script');
        script.src = 'https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js';
        script.async = true;
        script.onload = function(){ resolve(window.Sortable); };
        script.onerror = function(){ reject(new Error('SORTABLE_LOAD_FAILED')); };
        document.head.appendChild(script);
      });
    }
    return sortableFallbackPromise;
  }
  // --- debounce inchangé ---
  function debounce(fn, delay) {
    var t;
    return function () {
      var ctx = this, args = arguments;
      clearTimeout(t);
      t = setTimeout(function(){ fn.apply(ctx, args); }, delay);
    };
  }
  /* ===========================================================================================
     --- SHIM AJAX: force le paramètre "nonce" pour TOUT $.post ---
     =========================================================================================== */
  (function ($) {
  if (!$ || !$.post) return;
  var __post = $.post;
  $.post = function (url, data, success, dataType) {
  try {
  // Correlation id
  var cid = (Date.now().toString(36) + Math.random().toString(36).slice(2,8));
  if (data instanceof FormData) { if (!data.has('cid')) data.append('cid', cid); }
  else if (typeof data === 'string') { var p0 = new URLSearchParams(data); if (!p0.has('cid')) { p0.set('cid', cid); data = p0.toString(); } }
  else if (data && typeof data === 'object') { if (!('cid' in data)) data.cid = cid; }
  if (data && data.action && /^yuz_/.test(String(data.action)) && typeof data.nonce === 'undefined') {
  var n = (window.yuzGS && window.yuzGS.nonces && window.yuzGS.nonces[data.action])
  || (window.yuzGS && window.yuzGS.nonce);
  if (n) data.nonce = n;
        }
  if (data && data.security && !data.nonce) data.nonce = data.security;
  if (data && data._ajax_nonce && !data.nonce) data.nonce = data._ajax_nonce;
      } catch (_) { /* no-op */ }
  return __post.call($, url, data, success, dataType);
    };
  })(jQuery);

  /* ===========================================================================================
     --- Lightweight tracer for YUZ GENERAL actions (WS / LS / SW) ---
     =========================================================================================== */
  (function ($) {
    if (!($ && $.ajaxPrefilter)) return;
    try {
      $(document).ajaxSend(function (_e, _xhr, settings) {
        const url = String(settings && settings.url || '');
        if (url.indexOf('admin-ajax.php') === -1) return;
        let params = {};
        try {
          if (typeof settings.data === 'string') params = Object.fromEntries(new URLSearchParams(settings.data));
          else if (settings.data && typeof settings.data === 'object') params = settings.data;
        } catch(_) {}
        const action = params.action || '';
        if (!/^yuz_tra_(ws|ls|sw)_/.test(action)) return; // only this tab family
        log('info', '[TRACE][SEND][GENERAL]', { action: action, cid: params.cid || '(none)' });
      });
      $(document).ajaxComplete(function (_e, _xhr, settings) {
        const url = String(settings && settings.url || '');
        if (url.indexOf('admin-ajax.php') === -1) return;
        let params = {};
        try {
          if (typeof settings.data === 'string') params = Object.fromEntries(new URLSearchParams(settings.data));
          else if (settings.data && typeof settings.data === 'object') params = settings.data;
        } catch(_) {}
        const action = params.action || '';
        if (!/^yuz_tra_(ws|ls|sw)_/.test(action)) return;
        log('info', '[TRACE][COMPLETE][GENERAL]', { action: action, cid: params.cid || '(none)' });
      });
    } catch(_) {}
  })(jQuery);
  /* ===========================================================================================
     --- Nonces: mapping action -> "clé de groupe" (à compléter au besoin) + injection ---
     =========================================================================================== */
  window.NONCE_KEY = Object.freeze({
  // Website Languages (onglet General)
  'yuz_tra_ws_get_languages' : 'yuz_tra_nonce',
  'yuz_tra_ws_cre_language' : 'yuz_tra_nonce',
  'yuz_tra_ws_cre_alllang' : 'yuz_tra_nonce',
  'yuz_tra_ws_del_language' : 'yuz_tra_nonce',
  'yuz_tra_ws_del_alllang' : 'yuz_tra_nonce',
  'yuz_tra_ws_upd_settings' : 'yuz_tra_nonce',
  'yuz_tra_ws_upd_deflang' : 'yuz_tra_nonce',
  'yuz_tra_ws_upd_srclang' : 'yuz_tra_nonce',
  'yuz_tra_ws_upd_translatable' : 'yuz_tra_nonce',
  'yuz_tra_ws_upd_swapsrc' : 'yuz_tra_nonce',
  'yuz_tra_ws_upd_weights' : 'yuz_hvy_nonce', // lourd → heavy nonce
  // Language Settings (LS)
  'yuz_tra_ls_get_settings' : 'yuz_tra_nonce',
  'yuz_tra_ls_upd_settings' : 'yuz_con_nonce',
  // Switcher (SW)
  'yuz_tra_sw_get_settings' : 'yuz_tra_nonce',
  'yuz_tra_sw_upd_settings' : 'yuz_con_nonce',
  // Translation Manager (TM)
  'yuz_tra_tm_test_api' : 'yuz_api_nonce',
  });
  // --- Helper: récupère un nonce pour une action donnée
  function nonceFor(action) {
  const groupKey = (window.NONCE_KEY && window.NONCE_KEY[action]) || 'yuz_tra_nonce';
  if (window.yuzGS?.nonces?.[action]) return window.yuzGS.nonces[action];
  if (window.yuzGS?.nonces?.[groupKey]) return window.yuzGS.nonces[groupKey];
  if (window.yuzGS?.nonce) return window.yuzGS.nonce;
  return null;
  }
  // --- Ajout automatique du nonce (legacy & appels directs)
  jQuery.ajaxPrefilter(function(opts, orig /*, jqXHR */) {
  const isPost = (opts.type || '').toUpperCase() === 'POST';
  const ajaxUrl = (window.yuzGS && window.yuzGS.ajax_url) || '';
  const urlStr = String(opts.url || '');
  const isAdminAjax = urlStr.indexOf('admin-ajax.php') !== -1 || (ajaxUrl && urlStr.indexOf(ajaxUrl) === 0);
  if (!isPost || !isAdminAjax) return;
  const hasNonceAlready = (() => {
  if (!orig || typeof orig.data === 'undefined' || orig.data === null) return false;
  if (orig.data instanceof FormData) return orig.data.has('nonce') || orig.data.has('_ajax_nonce') || orig.data.has('security');
  if (typeof orig.data === 'string') {
  const p = new URLSearchParams(orig.data);
  return p.has('nonce') || p.has('_ajax_nonce') || p.has('security');
      }
  if (typeof orig.data === 'object') return ('nonce' in orig.data) || ('_ajax_nonce' in orig.data) || ('security' in orig.data);
  return false;
    })();
  if (hasNonceAlready) return;
    function extractAction() {
  if (orig && orig.data instanceof FormData && orig.data.has('action')) return orig.data.get('action');
  if (orig && typeof orig.data === 'string') {
  const p = new URLSearchParams(orig.data);
  if (p.get('action')) return p.get('action');
      }
  if (orig && typeof orig.data === 'object' && orig.data && orig.data.action) return orig.data.action;
  try { const u = new URL(opts.url, window.location.href); return u.searchParams.get('action'); } catch(e) {}
  return null;
    }
  const action = extractAction();
  if (!action) return;
  // Only handle YUZ plugin actions to avoid interfering with WP core (e.g., heartbeat)
  if (!/^yuz_/.test(action)) return;
    const n = nonceFor(action);
    if (!n) { yuz_release_console.warn('[YUZ][AJAX] No nonce found for action:', action); return; }
  if (orig.data instanceof FormData) { orig.data.append('nonce', n); return; }
  if (typeof orig.data === 'string') {
  const p = new URLSearchParams(orig.data); p.set('nonce', n);
  opts.data = p.toString();
  return;
    }
  if (typeof orig.data === 'object' && orig.data) {
  orig.data.nonce = n; opts.data = orig.data; return;
    }
  opts.data = 'nonce=' + encodeURIComponent(n) + (action ? '&action=' + encodeURIComponent(action) : '');
  });
  /* ===========================================================================================
     (optionnel dev) trace qu’on envoie bien nonce=...
     =========================================================================================== */
  jQuery(document).ajaxSend(function(e, xhr, settings) {
  try {
  if (settings && typeof settings.data === 'string' && settings.data.indexOf('action=yuz_tra_') >= 0) {
  yuz_release_console.log('[TRACE AJAX SEND]', settings.data); // doit contenir &nonce=xxxxxxxx
      }
    } catch (_) {}
  });
  /* ===========================================================================================
     --- AJAX PREFILTER TRACE: log chaque appel admin-ajax + nonce envoyé ---
     =========================================================================================== */
  (function ($) {
  if (!($ && $.ajaxPrefilter)) return;
  function parseParams(maybeStrOrObj) {
  var out = {};
  try {
  if (typeof maybeStrOrObj === 'string') {
  try {
  var sp = new URLSearchParams(maybeStrOrObj);
  sp.forEach(function(v, k){ out[k] = v; });
          } catch (_) {
            (maybeStrOrObj.split('&') || []).forEach(function(kv){
  if (!kv) return;
  var i = kv.indexOf('=');
  var k = i >= 0 ? decodeURIComponent(kv.slice(0,i)) : decodeURIComponent(kv);
  var v = i >= 0 ? decodeURIComponent(kv.slice(i+1)) : '';
  out[k] = v;
            });
          }
        } else if (maybeStrOrObj && typeof maybeStrOrObj === 'object') {
  Object.keys(maybeStrOrObj).forEach(function(k){ out[k] = maybeStrOrObj[k]; });
        }
      } catch(_) {}
  return out;
    }
  $.ajaxPrefilter(function (options, originalOptions/*, jqXHR */) {
  try {
  var adminUrl = (window.yuzGS && window.yuzGS.ajax_url) || '';
  var url = options && options.url || '';
  var isAdmin = (adminUrl && typeof url === 'string' && url.indexOf(adminUrl) === 0) || (typeof url === 'string' && url.indexOf('admin-ajax.php') !== -1);
  if (!isAdmin) return;
    var params = parseParams(options && options.data);
  if (typeof url === 'string' && url.indexOf('?') >= 0) {
  var q = url.split('?')[1] || '';
  var fromUrl = parseParams(q);
  Object.keys(fromUrl).forEach(function(k){ if (typeof params[k] === 'undefined') params[k] = fromUrl[k]; });
            }
    var actionVal = params.action || (originalOptions && originalOptions.data && originalOptions.data.action) || '';
    // Limit logging to YUZ actions to reduce noise and avoid confusion
    if (!/^yuz_/.test(actionVal || '')) return;
    var nonceVal = params.nonce || params.security || params._ajax_nonce || '';
    log('info', '[AJAX PREFILTER] admin-ajax call', {
  method: (options.type || options.method || 'GET'),
  action: actionVal || '(no action)',
  nonce: nonceVal || '(no nonce)',
  url: url
            });
          } catch (e) {
  try { log('warning', '[AJAX PREFILTER] error', { message: e && e.message }); } catch(_) {}
          }
    });
  })(jQuery);
  /* ===========================================================================================
     MODULE PRINCIPAL
     =========================================================================================== */
  (function (window, document, $, undefined) {
  'use strict';
  /* ========== HARD GUARDS ========== */
  if (!window.yuzGS) {
  return;
    }
  // Ensure legacy entry points (window.yuzTraSettings.*) remain populated for mixed-era scripts.
  try {
  window.yuzTraSettings = window.yuzTraSettings || {};
  var legacy = window.yuzTraSettings;
  var origin = window.yuzGS || {};
  if (origin.ajax_url && !legacy.ajax_url) legacy.ajax_url = origin.ajax_url;
  if (origin.nonces) {
  legacy.nonces = jQuery.extend(true, {}, origin.nonces, legacy.nonces || {});
    }
  if (origin.settings) {
  legacy.settings = jQuery.extend(true, {}, origin.settings, legacy.settings || {});
  if (origin.settings.general) {
  legacy.general = jQuery.extend(true, {}, origin.settings.general, legacy.general || {});
      }
    }
  if (origin.switcher) {
  legacy.switcher = jQuery.extend(true, {}, origin.switcher, legacy.switcher || {});
    }
  if (origin.capabilities) {
  legacy.capabilities = jQuery.extend(true, {}, origin.capabilities, legacy.capabilities || {});
    }
  } catch (syncErr) {
  }
  /* ========== CONSTANTS ========== */
  const ACTION = {
  WS_GET: 'yuz_tra_ws_get_languages',
  WS_ADD: 'yuz_tra_ws_cre_language',
  WS_ADD_ALL: 'yuz_tra_ws_cre_alllang',
  WS_DEL: 'yuz_tra_ws_del_language',
  WS_DEL_ALL: 'yuz_tra_ws_del_alllang',
  WS_UPD_WEIGHTS: 'yuz_tra_ws_upd_weights',
  WS_UPD_SETTINGS: 'yuz_tra_ws_upd_settings',
  WS_UPD_DEFLANG: 'yuz_tra_ws_upd_deflang',
  WS_UPD_SRCLANG: 'yuz_tra_ws_upd_srclang',
  LS_GET: 'yuz_tra_ls_get_settings',
  LS_UPD: 'yuz_tra_ls_upd_settings',
  SW_GET: 'yuz_tra_sw_get_settings',
  SW_UPD: 'yuz_tra_sw_upd_settings',
  TM_TEST_API: 'yuz_tra_tm_test_api',
    };
  // On s’appuie sur window.NONCE_KEY pour éviter les divergences
  const NONCE_KEY = window.NONCE_KEY || {};
  /* ========== HELPERS ========== */
  var DEFAULT_MSG = {
  blocked_alert: '[YUZ] Alert bloquée: ',
  blocked_confirm: '[YUZ] Confirm bloqué: ',
  confirm_default: false,
  unknown_error: 'Unknown error',
  ajax_error: 'Erreur AJAX :',
  no_languages_text: 'No translatable languages set. Add languages to start translating.',
  offline_banner: 'Vous semblez hors-ligne. Les actions seront réessayées automatiquement.',
  remove_text: 'Remove',
  no_language_selected: 'Aucune langue sélectionnée.',
  language_already_added: 'Langue déjà ajoutée.',
  language_added: 'Langue ajoutée avec succès.',
  all_languages_removed: 'Toutes les langues supprimées.',
  failed_add_language: 'Échec de l’ajout de la langue.',
  failed_remove_language: 'Échec de la suppression de la langue.',
  select_language_text: 'Select a language to add'
    };
  function MSG() {
  var y = window.yuzGS || {};
  return $.extend({}, DEFAULT_MSG, y.messages || {});
    }
  var DEFAULT_SEL = {
  prevent_prompt_elements: '.wrap .yuz-tra-field',
  yuz_language_list: '#yuz_tra_translatable_list',
      // Align with CSS (assets/css/yuz-general-settings.css)
      yuz_drag_handle: '.yuz-tra-drag-handle',
  yuz_add_language: '#yuz_tra_yuz_add_language_btn',
  yuz_add_language_select: '#yuz_tra_yuz_add_language_select',
  yuz_add_all: '#yuz_tra_yuz_add_all_languages_btn',
  yuz_remove_all: '#yuz_tra_yuz_remove_all_languages_btn',
      // Align with CSS
      yuz_remove_language: '.yuz-tra-remove-language',
  	// Align with PHP input names
  	yuz_default_language: 'select[name="yuz_tra_ws_settings[yuz_default_language]"]',
  	yuz_source_language: 'select[name="yuz_tra_ws_settings[yuz_source_language]"]',
  	// Legacy fallbacks (older markup)
  	yuz_default_language_alt:'select[name="yuz_tra_ws_settings[yuz_tra_default_language]"]',
  	yuz_source_language_alt: 'select[name="yuz_tra_ws_settings[yuz_tra_source_language]"]',
  yuz_native_language_name:'#yuz_tra_ls_settings\\[native_language_name\\]',
  yuz_use_subdirectory: '#yuz_tra_ls_settings\\[use_subdirectory\\]',
  yuz_force_lang_in_links: '#yuz_tra_ls_settings\\[force_lang_in_links\\]',
  yuz_shortcode_enabled: '#yuz_tra_sw_settings\\[shortcode_enabled\\]',
  yuz_shortcode_format: '#yuz_tra_sw_settings\\[shortcode_format\\]',
  yuz_menu_enabled: '#yuz_tra_sw_settings\\[menu_enabled\\]',
  yuz_menu_format: '#yuz_tra_sw_settings\\[menu_format\\]',
  yuz_floating_enabled: '#yuz_tra_sw_settings\\[floating_enabled\\]',
  yuz_floating_format: '#yuz_tra_sw_settings\\[floating_format\\]',
  yuz_floating_theme: '#yuz_tra_sw_settings\\[floating_theme\\]',
  yuz_floating_position: '#yuz_tra_sw_settings\\[floating_position\\]',
  yuz_show_poweredby: '#yuz_tra_sw_settings\\[show_poweredby\\]',
    };
  function SEL() {
  var y = window.yuzGS || {};
  return $.extend({}, DEFAULT_SEL, y.selectors || {});
    }
  function nonceForLocal(action) {
  var y = window.yuzGS || {};
  var key = NONCE_KEY[action] || 'yuz_tra_nonce';
  if (y.nonces && typeof y.nonces === 'object' && y.nonces[key]) return y.nonces[key];
  if (y.nonces && typeof y.nonces === 'object' && y.nonces[action]) return y.nonces[action];
  if (y.nonce) return y.nonce;
  return '';
    }
  // Helper: object → x-www-form-urlencoded string
  function toQuery(obj) {
  try {
  var p = new URLSearchParams();
  Object.keys(obj || {}).forEach(function(k){ p.append(k, obj[k]); });
  return p.toString();
        } catch (e) {
  return '';
        }
    }
  /* ========== OFFLINE BANNER + FETCH RETRY (conservé pour d'autres modules si besoin) ========== */
  function ensureNetBanner() {
  if (document.getElementById('yuz-net-banner')) return;
  var bar = document.createElement('div');
  bar.id = 'yuz-net-banner';
  bar.style.cssText = 'position:fixed;left:50%;transform:translateX(-50%);bottom:12px;z-index:99999;padding:8px 12px;border-radius:8px;display:none;font-size:12px;background:#222;color:#fff;box-shadow:0 4px 12px rgba(0,0,0,.25)';
  bar.textContent = MSG().offline_banner;
  document.body.appendChild(bar);
    }
  function showNet(status) {
  ensureNetBanner();
  var bar = document.getElementById('yuz-net-banner');
  if (!bar) return;
  if (status === 'offline') {
  bar.style.display = 'block';
  bar.style.background = '#b02a37';
  bar.textContent = MSG().offline_banner;
      } else {
  bar.style.background = '#198754';
  bar.textContent = 'Connexion rétablie.';
  bar.style.display = 'block';
  setTimeout(function(){ bar.style.display = 'none'; bar.textContent = MSG().offline_banner; }, 1800);
      }
    }
  window.addEventListener('offline', function(){ showNet('offline'); });
  window.addEventListener('online', function(){ showNet('online'); });
  async function retryFetch(url, options, tries, backoffMs) {
  tries = tries || 3;
  backoffMs = backoffMs || 500;
  var lastErr;
  for (var i=0; i<tries; i++) {
  var controller = new AbortController();
  var timeout = setTimeout(function(){ controller.abort(); }, (options && options.timeoutMs) || 12000);
  try {
  try {
  var _adminUrl = (window.yuzGS && window.yuzGS.ajax_url) || '';
  var _isAdmin = _adminUrl && typeof url === 'string' && url.indexOf(_adminUrl) === 0;
  if (_isAdmin) {
  var _params = {};
  var _body = options && options.body;
  if (_body instanceof URLSearchParams) { _body.forEach(function(v,k){ _params[k] = v; }); }
  else if (typeof _body === 'string') {
  try { var _sp = new URLSearchParams(_body); _sp.forEach(function(v,k){ _params[k] = v; }); } catch(_) {}
              }
  var _action = _params.action || '';
  var _nonce = _params.nonce || _params.security || _params._ajax_nonce || '';
  log('info', '[FETCH TRACE] admin-ajax call', {
  method: (options && options.method) || 'GET',
  action: _action || '(no action)',
  nonce: _nonce || '(no nonce)',
  url: url
              });
            }
          } catch(_) {}
  var res = await fetch(url, $.extend({}, options, { signal: controller.signal, credentials: 'same-origin' }));
  clearTimeout(timeout);
  return res;
        } catch (e) {
  clearTimeout(timeout);
  lastErr = e;
  if (e.name === 'AbortError' || !navigator.onLine) showNet('offline');
  if (i < tries - 1) { await new Promise(function(r){ setTimeout(r, backoffMs * Math.pow(2, i)); }); }
        }
      }
  throw lastErr;
    }
  /* =========================
       1) Parse JSON “sûr”
       ========================= */
  function parseJsonSafely(res) {
  var ct = (res.headers && res.headers.get && res.headers.get('content-type')) || '';
  if (ct.indexOf('application/json') === -1) {
  return res.text().then(function(t){
  var head = (t || '').slice(0, 500);
  throw new Error('HTTP ' + res.status + ' ' + res.statusText + ' — non-JSON body:\n' + head);
        });
      }
  return res.json();
    }
  /* ========== DEP CHECK ========== */
  function waitForDependencies(callback) {
  var maxAttempts = 100, interval = 100, attempts = 0;
  var t = setInterval(function(){
  attempts++;
  var y = window.yuzGS;
  if (window.jQuery && window.jQuery.ui && window.jQuery.ui.sortable && y && y.ajax_url) {
  log('success', 'Deps OK after ' + attempts + ' ticks');
  clearInterval(t);
  callback(window.jQuery);
        } else if (attempts >= maxAttempts) {
  log('critical', 'Deps failed after ' + maxAttempts + ' ticks', {
  jQuery: !!window.jQuery, jQueryUI: !!(window.jQuery && window.jQuery.ui),
  Sortable: !!(window.jQuery && window.jQuery.ui && window.jQuery.ui.sortable),
  ajax_url: !!(y && y.ajax_url)
          });
  clearInterval(t);
        }
      }, interval);
    }
  /* ========== RENDERING ========== */
  function buildLanguageRow(lang, msg) {
  var slugValue = lang.slug || String(lang.language_code || '').toLowerCase().replace('-', '_');
  var codeValue = lang.code || lang.language_code;
  var html = '' +
  '<li class="yuz-tra-language-row ui-sortable-handle" data-language-code="' + lang.language_code + '" ' +
  ' style="background:#f8f9fa;padding:10px;margin-bottom:8px;display:flex;align-items:center;gap:16px;">' +
  ' <span class="yuz-tra-drag-handle dashicons dashicons-menu" style="cursor:move;"></span>' +
  ' <span style="flex:1;">' + lang.language_name + ' (' + lang.language_code + ')</span>' +
  ' <input type="text" class="yuz-tra-regular-text" ' +
  ' name="yuz_tra_ws_settings[yuz_tra_slug][' + lang.language_code + ']" value="' + slugValue + '" style="width:100px;" />' +
  ' <input type="text" class="yuz-tra-regular-text" ' +
  ' name="yuz_tra_ws_settings[yuz_tra_code][' + lang.language_code + ']" value="' + codeValue + '" style="width:100px;" />' +
  ' <button type="button" class="yuz-tra-remove-language yuz-btn-remove" data-language-code="' + lang.language_code + '" title="' + msg.remove_text + '">' +
  ' <span class="yuz-tra-icon" aria-hidden="true">' +
  ' <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
  ' <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>' +
  ' </svg>' +
  ' </span>' +
  ' </button>' +
  ' <input type="hidden" name="yuz_tra_ws_settings[yuz_tra_translatable_languages][]" value="' + lang.language_code + '" />' +
  '</li>';
  return $(html);
    }
  function renderLanguages(listSelector, languages, msg) {
  var $list = $(listSelector);
  $list.empty();
  if (!languages || !languages.length) {
  $list.append('<li class="yuz-no-languages" style="color:#6c757d;">' + msg.no_languages_text + '</li>');
  return;
      }
  for (var i=0;i<languages.length;i++) $list.append(buildLanguageRow(languages[i], msg));
  // Refresh sortable only if already initialized
  try { if ($list.data('uiSortable') && typeof $list.sortable === 'function') { $list.sortable('refresh'); } } catch(_) {}
    }
  /* ========== COLLECTORS ========== */
  function collectWebsiteSettings(sel) {
  var langs = [];
  $(sel.yuz_language_list + ' input[name="yuz_tra_ws_settings[yuz_translatable_languages][]"], ' + sel.yuz_language_list + ' input[name="yuz_tra_ws_settings[yuz_tra_translatable_languages][]"]').each(function () {
  langs.push($(this).val());
      });
  var slugs = {};
  $(sel.yuz_language_list + ' input[name^="yuz_tra_ws_settings[yuz_tra_slug]"], ' + sel.yuz_language_list + ' input[name^="yuz_tra_ws_settings[yuz_slug]"]').each(function () {
  var name = String($(this).attr('name')||'');
  var m = name.match(/\[(?:yuz_tra_slug|yuz_slug)\]\[([^\]]+)\]/);
  if (m && m[1]) slugs[m[1]] = $(this).val();
      });
  var codes = {};
  $(sel.yuz_language_list + ' input[name^="yuz_tra_ws_settings[yuz_tra_code]"], ' + sel.yuz_language_list + ' input[name^="yuz_tra_ws_settings[yuz_code]"]').each(function () {
  var name = String($(this).attr('name')||'');
  var m = name.match(/\[(?:yuz_tra_code|yuz_code)\]\[([^\]]+)\]/);
  if (m && m[1]) codes[m[1]] = $(this).val();
      });
  // Default/Source: try new selectors first, then legacy fallbacks
  var def = $(sel.yuz_default_language).val() || $(sel.yuz_default_language_alt).val() || '';
  var src = $(sel.yuz_source_language).val()  || $(sel.yuz_source_language_alt).val()  || '';
  return {
      yuz_default_language: def,
      yuz_source_language: src,
      yuz_translatable_languages: langs,
      yuz_tra_slug: slugs,
      yuz_tra_code: codes,
      yuz_slug: slugs,
      yuz_code: codes
      };
    }
  function collectLanguageSettings(sel) {
  return {
  native_language_name: $(sel.yuz_native_language_name).is(':checked') ? '1' : '0',
  use_subdirectory: $(sel.yuz_use_subdirectory).is(':checked') ? '1' : '0',
  force_lang_in_links: $(sel.yuz_force_lang_in_links).is(':checked') ? '1' : '0'
      };
    }
  function collectSwitcherSettings(sel) {
  return {
  shortcode_enabled: $(sel.yuz_shortcode_enabled).is(':checked') ? '1' : '0',
  shortcode_format: $(sel.yuz_shortcode_format).val() || 'flags-full-names',
  menu_enabled: $(sel.yuz_menu_enabled).is(':checked') ? '1' : '0',
  menu_format: $(sel.yuz_menu_format).val() || 'flags-full-names',
  floating_enabled: $(sel.yuz_floating_enabled).is(':checked') ? '1' : '0',
  floating_format: $(sel.yuz_floating_format).val() || 'flags-full-names',
  floating_theme: $(sel.yuz_floating_theme).val() || 'light',
  floating_position: $(sel.yuz_floating_position).val() || 'bottom-right',
  show_poweredby: $(sel.yuz_show_poweredby).is(':checked') ? '1' : '0'
      };
    }
  function asChecked(value) {
  return value === true || value === '1' || value === 1 || value === 'true';
    }
  /* ========== AJAX WRAPPERS (avec injection nonce systématique) ========== */
  function ajaxPost(url, data, ok, ko) {
  try {
  // Add correlation id
  var cid = (Date.now().toString(36) + Math.random().toString(36).slice(2,8));
  if (data instanceof FormData) { if (!data.has('cid')) data.append('cid', cid); }
  else if (typeof data === 'string') { var pC = new URLSearchParams(data); if (!pC.has('cid')) { pC.set('cid', cid); data = pC.toString(); } }
  else if (data && typeof data === 'object') { if (!('cid' in data)) data.cid = cid; }
  // Ajouter automatiquement le nonce selon l'action
  var action = null;
  if (data instanceof FormData) {
  action = data.get('action') || null;
  if (action && !(data.has('nonce') || data.has('_ajax_nonce') || data.has('security'))) {
  var n = nonceForLocal(action);
  if (n) data.append('nonce', n);
          }
        } else if (typeof data === 'string') {
  var p = new URLSearchParams(data);
  action = p.get('action');
  if (action) {
  var n2 = nonceForLocal(action);
  if (n2 && !(p.has('nonce') || p.has('_ajax_nonce') || p.has('security'))) p.set('nonce', n2);
          }
  data = p.toString();
        } else if (data && typeof data === 'object') {
  action = data.action || null;
  if (action && (typeof data.nonce === 'undefined' && typeof data._ajax_nonce === 'undefined' && typeof data.security === 'undefined')) {
  var n3 = nonceForLocal(action);
  if (n3) data.nonce = n3;
          }
        }
      } catch (_) {}
  return $.ajax({ url: url, type: 'POST', data: data, dataType: 'json' })
        .done(function(r){ ok && ok(r); })
        .fail(function(xhr, status, err){
  ko && ko(xhr, status, err);
  if (!navigator.onLine) showNet('offline');
        });
    }
  /* =========================
       2) Sortable robuste
       ========================= */
  function saveWeights(sel, y, msg) {
  log('info', 'DND detected – saving weights');
  var $list = $(sel.yuz_language_list);
  var $status = $('<span>Saving...</span>').css('color','#000').insertAfter($list);
  var weights = {};
  $(sel.yuz_language_list + ' .yuz-tra-language-row').each(function(idx, li){
  var code = li.getAttribute('data-language-code');
  weights[code] = idx + 1;
      });
  var action = ACTION.WS_UPD_WEIGHTS;
  ajaxPost(y.ajax_url, { action: action, weights: weights },
  function(res){
  if (res && res.success) {
  renderLanguages(sel.yuz_language_list, (res.data && res.data.languages) || [], msg);
  ensureSortable(sel, y, msg); // ✅ rebind après re-render
  $status.text('✔ Order saved').css('color','green').fadeOut(2000);
  log('success', 'Weights saved');
          } else {
  $status.text('✖ ' + ((res && res.data && res.data.message) || ((res && res.error) || MSG().unknown_error))).css('color','red').fadeOut(4000);
  log('warning','Weights save failed', res);
          }
        },
  function(_, __, e){ $status.text('✖ AJAX error: ' + e).css('color','red').fadeOut(4000); log('critical','Weights save AJAX error', e); }
      );
    }
  // ✅ Nouveau: garantit que le drag est toujours opérationnel après chaque render
  function ensureSortable(sel, y, msg){
  const $list = $(sel.yuz_language_list);
  if (!$list.length) return;

  var usedJqueryUI = false;
  if (typeof $list.sortable === 'function') {
    try {
      if ($list.data('uiSortable')) {
        $list.sortable('option','handle', sel.yuz_drag_handle);
        $list.sortable('refresh');
      } else {
        $list.sortable({
          items: '.yuz-tra-language-row',
          axis: 'y',
          handle: sel.yuz_drag_handle,
          cancel: 'input,select,textarea,a',
          update: () => saveWeights(sel, y, msg)
        });
      }
      usedJqueryUI = true;
    } catch (e) {
      log('warning','ensureSortable failed',{ e: e && e.message });
    }
  }

  if (usedJqueryUI) return;

  const listEl = $list.get(0);
  if (!listEl) return;

  loadSortableFallback()
    .then(function(Sortable){
      if (!Sortable) return;
      if (listEl.yuzSortable) {
        listEl.yuzSortable.option('handle', sel.yuz_drag_handle);
        listEl.yuzSortable.option('animation', 150);
        listEl.yuzSortable.option('draggable', '.yuz-tra-language-row');
        listEl.yuzSortable.option('onEnd', function(){ saveWeights(sel, y, msg); });
      } else {
        listEl.yuzSortable = Sortable.create(listEl, {
          handle: sel.yuz_drag_handle,
          animation: 150,
          draggable: '.yuz-tra-language-row',
          onEnd: function(){ saveWeights(sel, y, msg); }
        });
      }
    })
    .catch(function(err){
      log('warning','Sortable fallback failed',{ err: err && err.message });
    });
  }
  // (on garde initSortable pour compat mais on ne l’appelle plus)
  function initSortable(sel, y, msg) {
  var $list = $(sel.yuz_language_list);
  if (!$list.length) { log('warning', 'Translatable list not found'); return; }
  if (typeof $list.sortable !== 'function') { log('critical','jQuery UI Sortable missing'); alert('Erreur : jQuery UI Sortable non disponible.'); return; }
  var hasHandle = $list.find(sel.yuz_drag_handle).length > 0;
  try {
  $list.sortable({
  items: '.yuz-tra-language-row',
  axis: 'y',
  handle: hasHandle ? sel.yuz_drag_handle : undefined,
  cancel: 'input,select,textarea,a',
  update: function(){ saveWeights(sel, y, msg); }
        });
  if (typeof $list.disableSelection === 'function') $list.disableSelection();
      } catch (err) {
      }
    }
  /* ========== INIT CHAINS (désormais via ajaxPost + nonce auto) ========== */
  function fetchTranslatableLanguages(y, msg, sel, cb) {
  var action = ACTION.WS_GET;
  ajaxPost(y.ajax_url, { action: action }, function (r) {
  if (r && r.success && r.data) {
  if (!r.data || !Array.isArray(r.data.languages)) {
  log('critical','WS_GET contract violation: missing data.languages', r && r.data);
          }
  cb((r.data.languages || []), (r.data.non_translatable || []));
        } else {
  alert(msg.ajax_error + ' ' + (r && r.data && r.data.message ? r.data.message : msg.unknown_error));
  cb([], []);
        }
      }, function (xhr) {
  alert(msg.ajax_error + ' ' + (xhr && xhr.statusText ? xhr.statusText : ''));
  cb([], []);
      });
    }
  function fetchLanguageSettings(y, cb) {
  var action = ACTION.LS_GET;
  var run = debounce(function(){
  ajaxPost(y.ajax_url, { action: action }, function(json){
  if (json && json.success) cb((json.data && json.data.settings) || json.data || {});
  else alert('Erreur : ' + ((json && json.data && json.data.message) || MSG().unknown_error));
        }, function(xhr){ alert('Erreur AJAX (Language Settings) : ' + (xhr && xhr.statusText)); });
      }, 50);
  run();
    }
  function fetchSwitcherSettings(y, cb) {
  var action = ACTION.SW_GET;
  var run = debounce(function(){
  ajaxPost(y.ajax_url, { action: action }, function(json){
  if (json && json.success) cb(json.data || {});
  else alert('Erreur : ' + ((json && json.data && json.data.message) || MSG().unknown_error));
        }, function(xhr){ alert('Erreur AJAX (Switcher Settings) : ' + (xhr && xhr.statusText)); });
      }, 50);
  run();
    }
  function updateWebsiteSettings(y, settings) {
  // ✅ on n’abandonne plus si les selects ne sont pas présents/vides
  // Supprime warnings pour default/source vides
  // Ne pas envoyer une liste translatable vide (cela effacerait tout côté serveur)
  try {
  if (Array.isArray(settings.yuz_translatable_languages) && settings.yuz_translatable_languages.length === 0) {
  delete settings.yuz_translatable_languages;
        }
      } catch(_) {}
  var action = ACTION.WS_UPD_SETTINGS;
  ajaxPost(y.ajax_url, { action: action, website_languages: settings },
  function(res){ if (!(res && res.success)) alert('Erreur : ' + ((res && res.data && res.data.message) || MSG().unknown_error)); }
      );
    }
  function updateLanguageSettings(y, settings) {
  var action = ACTION.LS_UPD;
  ajaxPost(y.ajax_url, { action: action, language_settings: settings },
  function(res){ if (!(res && res.success)) alert('Erreur : ' + ((res && res.data && res.data.message) || MSG().unknown_error)); }
      );
    }
  function updateSwitcherSettings(y, settings) {
  var action = ACTION.SW_UPD;
  ajaxPost(y.ajax_url, { action: action, switcher_settings: settings },
  function(res){ if (!(res && res.success)) alert('Erreur : ' + ((res && res.data && res.data.message) || MSG().unknown_error)); }
      );
    }
  /* ========== UI BINDINGS (FLAT) ========== */
  function bindCoreHandlers(y, msg, sel) {
  // Add one
  $(document).on('click.yuzTra', sel.yuz_add_language, debounce(function(e){
    e.preventDefault();
    var code = $(sel.yuz_add_language_select).val();
    if (!code) { alert(msg.no_language_selected); return; }
    // Guard: do not add default or source language
    var def = $(sel.yuz_default_language).val() || $(sel.yuz_default_language_alt).val() || '';
    var src = $(sel.yuz_source_language).val()  || $(sel.yuz_source_language_alt).val()  || '';
    var isDefault = code === def;
    var isSource = code === src;
    if (isSource || (isDefault && def && def === src)) {
      alert('Selected language cannot be added because it is the active source language.');
      return;
    }
    log('info','[WS_ADD][CLICK]',{code:code});
  var exists = $(sel.yuz_language_list + ' .yuz-tra-language-row').filter(function(_, li){
    return (li.getAttribute && li.getAttribute('data-language-code')) === code;
  }).length;
    if (exists) { alert(msg.language_already_added); return; }
    var action = ACTION.WS_ADD;
    ajaxPost(y.ajax_url, { action: action, language_code: code },
  function(r){
  if (r && r.success && r.data && Array.isArray(r.data.languages)) {
    alert(msg.language_added);
    renderLanguages(sel.yuz_language_list, r.data.languages, msg);
    ensureSortable(sel, y, msg);
    $(sel.yuz_add_language_select).val('');
  } else {
    alert('Erreur : ' + ((r && r.error) || msg.failed_add_language));
  }
        }
      );
    }, 200));
  // Add all
  var addAllOriginalLabel = null;
  $(document).on('click.yuzTra', sel.yuz_add_all, debounce(function(e){
    e.preventDefault();
    var bulkAction = ACTION.WS_ADD_ALL;
    var $btnAddAll = $(sel.yuz_add_all);
    var $btnRemoveAll = $(sel.yuz_remove_all);
    if (addAllOriginalLabel === null) { addAllOriginalLabel = $btnAddAll.text(); }
    log('info','[WS_ADD_ALL][CLICK]',{});
    ajaxPost(y.ajax_url, { action: bulkAction }, function(r){
      if (r && r.success && r.data && Array.isArray(r.data.languages)) {
        renderLanguages(sel.yuz_language_list, r.data.languages, msg);
        ensureSortable(sel, y, msg);
        alert('Toutes les langues sont maintenant actives. Utilisez « Remove All Languages » pour repartir d\'une liste vide.');
        var bulkLabel = msg.all_languages_active || 'All languages active';
        $btnAddAll.prop('disabled', true).attr('aria-disabled', 'true').text(bulkLabel);
        $btnRemoveAll.prop('disabled', false).attr('aria-disabled', 'false');
      } else {
        alert((r && r.data && r.data.message) || 'Bulk add failed');
      }
    }, function(){ alert('Bulk add failed'); });
  }, 200));
  // Remove all
  $(document).on('click.yuzTra', sel.yuz_remove_all, debounce(function(e){
    e.preventDefault();
    var action = ACTION.WS_DEL_ALL;
    log('info','[WS_DEL_ALL][CLICK]',{});
    ajaxPost(y.ajax_url, { action: action },
      function(r){
        if (r && r.success && r.data && Array.isArray(r.data.languages)) {
          alert(msg.all_languages_removed);
          renderLanguages(sel.yuz_language_list, r.data.languages, msg);
          ensureSortable(sel, y, msg);
          var $btnAddAll = $(sel.yuz_add_all);
          if (addAllOriginalLabel !== null) {
            $btnAddAll.prop('disabled', false).attr('aria-disabled', 'false').text(addAllOriginalLabel);
          }
        } else {
          alert('Erreur : ' + ((r && r.data && r.data.message) || msg.failed_remove_language));
        }
      }
    );
  }, 200));
  // Remove one
  $(document).on('click.yuzTra', sel.yuz_remove_language, debounce(function(e){
    e.preventDefault();
    // Be robust: jQuery .data('language-code') may not resolve dashes; fallback to attribute/LI
    var code = $(this).attr('data-language-code') || $(this).data('languageCode') || $(this).closest('.yuz-tra-language-row').attr('data-language-code');
    log('info','[WS_DEL][CLICK]',{code:code});
    $(this).closest('li').remove();
    var action = ACTION.WS_DEL;
  ajaxPost(y.ajax_url, { action: action, language_code: code },
  function(r){
  if (r && r.success && r.data && Array.isArray(r.data.languages)) {
    alert(MSG().language_removed || 'Langue supprimée avec succès.');
    renderLanguages(sel.yuz_language_list, r.data.languages, msg);
    ensureSortable(sel, y, msg);
  } else alert('Erreur : ' + ((r && r.data && r.data.message) || msg.failed_remove_language));
        }
      );
    }, 200));
      // Default/Source change → immediate WS updates + persist website settings
      $(document).on('change.yuzTra', sel.yuz_default_language + ', ' + sel.yuz_default_language_alt, function(){
  var code = $(this).val(); if (!code) return;
  var action = ACTION.WS_UPD_DEFLANG;
  ajaxPost(y.ajax_url, { action: action, language_code: code });
  updateWebsiteSettings(y, collectWebsiteSettings(sel));
      });
      $(document).on('change.yuzTra', sel.yuz_source_language + ', ' + sel.yuz_source_language_alt, function(){
  var code = $(this).val(); if (!code) return;
  var action = ACTION.WS_UPD_SRCLANG;
  ajaxPost(y.ajax_url, { action: action, language_code: code });
  updateWebsiteSettings(y, collectWebsiteSettings(sel));
      });
  // Slugs/Codes change → persist website settings
  $(document).on('change.yuzTra', 'input[name^="yuz_tra_ws_settings[yuz_tra_slug]"], input[name^="yuz_tra_ws_settings[yuz_slug]"], input[name^="yuz_tra_ws_settings[yuz_tra_code]"], input[name^="yuz_tra_ws_settings[yuz_code]"]', debounce(function(){
  updateWebsiteSettings(y, collectWebsiteSettings(sel));
      }, 250));
    }
  function fetchLanguageSettingsAndBind(y, sel) {
  function toggleLanguageSettings() {
  var useSubdir = $(sel.yuz_use_subdirectory).is(':checked');
  $('.yuz-force-lang-in-links').toggle(useSubdir);
      }
  fetchLanguageSettings(y, function(settings){
  if (Object.prototype.hasOwnProperty.call(settings, 'native_language_name')) $(sel.yuz_native_language_name).prop('checked', asChecked(settings.native_language_name));
  if (Object.prototype.hasOwnProperty.call(settings, 'use_subdirectory')) $(sel.yuz_use_subdirectory).prop('checked', asChecked(settings.use_subdirectory)).on('change', toggleLanguageSettings);
  if (Object.prototype.hasOwnProperty.call(settings, 'force_lang_in_links')) $(sel.yuz_force_lang_in_links).prop('checked', asChecked(settings.force_lang_in_links));
  $('.yuz-tra-field input[type="checkbox"]').off('change.yuzTra').on('change.yuzTra', debounce(function(){
  updateLanguageSettings(y, collectLanguageSettings(sel));
        }, 150));
  toggleLanguageSettings();
      });
    }
  function fetchSwitcherSettingsAndBind(y, sel) {
  fetchSwitcherSettings(y, function(opts){
  $(sel.yuz_shortcode_enabled).prop('checked', asChecked(opts.shortcode_enabled));
  $(sel.yuz_shortcode_format).val(opts.shortcode_format || 'flags-full-names');
  $(sel.yuz_menu_enabled).prop('checked', asChecked(opts.menu_enabled));
  $(sel.yuz_menu_format).val(opts.menu_format || 'flags-full-names');
  $(sel.yuz_floating_enabled).prop('checked', asChecked(opts.floating_enabled));
  $(sel.yuz_floating_format).val(opts.floating_format || 'flags-full-names');
  $(sel.yuz_floating_theme).val(opts.floating_theme || 'light');
  $(sel.yuz_floating_position).val(opts.floating_position || 'bottom-right');
  $(sel.yuz_show_poweredby).prop('checked', asChecked(opts.show_poweredby));
  var watch = [
  sel.yuz_shortcode_enabled, sel.yuz_shortcode_format,
  sel.yuz_menu_enabled, sel.yuz_menu_format,
  sel.yuz_floating_enabled, sel.yuz_floating_format,
  sel.yuz_floating_theme, sel.yuz_floating_position,
  sel.yuz_show_poweredby
        ].join(', ');
  $(document).off('change.yuzTra.switcher').on('change.yuzTra.switcher', watch, debounce(function(){
  updateSwitcherSettings(y, collectSwitcherSettings(sel));
        }, 150));
      });
    }
  function preventThirdPartyPrompts(sel, msg) {
  var $scope = $(sel.prevent_prompt_elements);
  var origAlert = window.alert, origConfirm = window.confirm;
  window.alert = function (message) {
  if ($scope.is(':focus') || $scope.find(':focus').length > 0) { log('warning', msg.blocked_alert + message); return; }
  origAlert(message);
      };
  window.confirm = function (message) {
  if ($scope.is(':focus') || $scope.find(':focus').length > 0) { log('warning', msg.blocked_confirm + message); return msg.confirm_default; }
  return origConfirm(message);
      };
    }
  /* ========== MAIN BOOT ========== */
  waitForDependencies(function($){
  var y = window.yuzGS;
  var msg = MSG();
  var sel = SEL();
  // Guard: only on General tab
  var tab = (y && y.current_tab) ? y.current_tab : 'general';
  if (tab !== 'general') {
  log('info','[YUZ][GENERAL][SKIP] current_tab=' + tab);
  return;
      }
  if (!y.ajax_url) {
  log('critical', 'yuzGS.ajax_url missing'); alert('Erreur critique : Configuration JavaScript manquante. Recharger la page.');
  return;
      }
  $(function(){
  log('info', 'DOM ready, jQuery=' + $.fn.jquery);
        [
  'yuz_language_list','yuz_drag_handle','yuz_add_language','yuz_add_language_select',
  'yuz_add_all','yuz_remove_all','yuz_remove_language','yuz_default_language',
  'yuz_source_language','yuz_native_language_name','yuz_use_subdirectory',
  'yuz_force_lang_in_links','yuz_shortcode_enabled','yuz_shortcode_format',
  'yuz_menu_enabled','yuz_menu_format','yuz_floating_enabled','yuz_floating_format',
  'yuz_floating_theme','yuz_floating_position','yuz_show_poweredby'
        ].forEach(function(k){
  var s = sel[k];
  if (!s) return;
  var n = $(s).length;
  // Some selectors are dynamic-only (rendered after initial boot or inside rows)
  var relaxed = (
    k === 'yuz_default_language' ||
    k === 'yuz_source_language'  ||
    k === 'yuz_drag_handle'      ||
    k === 'yuz_remove_language'
  );
  log(n ? 'success' : (relaxed ? 'info' : 'warning'), 'Selector ' + k + ' => ' + n + ' match(es)', { selector: s });
        });
  preventThirdPartyPrompts(sel, msg);
  fetchTranslatableLanguages(y, msg, sel, function(existingLangs, availableLangs){
  var $select = $(sel.yuz_add_language_select);
  if ($select.length) {
  $select.empty().append($('<option>', { value:'', text: msg.select_language_text }));
  if (availableLangs && availableLangs.length) {
  for (var i=0;i<availableLangs.length;i++) {
  var L = availableLangs[i];
  $select.append($('<option>', { value: L.language_code, text: (L.language_name + ' (' + L.language_code + ')') }));
              }
            }
          } else {
  log('warning', 'Add-language select not found');
          }
  renderLanguages(sel.yuz_language_list, existingLangs, msg);
  ensureSortable(sel, y, msg); // ✅
        });
  bindCoreHandlers(y, msg, sel);
  fetchLanguageSettingsAndBind(y, sel);
  fetchSwitcherSettingsAndBind(y, sel);
  // Optional test API button (via ajaxPost → nonce auto)
  if ($('#yuz_test_api_btn').length) {
  $('#yuz_test_api_btn').on('click', debounce(function(e){
  e.preventDefault();
  var action = ACTION.TM_TEST_API;
  ajaxPost(y.ajax_url, {
  action: action,
  provider: $('#yuz_api_provider').val(),
  endpoint: $('#yuz_api_endpoint').val(),
  api_key: $('#yuz_api_key').val()
            }, function(json){
  if (json && json.success) { alert('API test: OK'); }
  else { alert('API test: ' + ((json && json.message) || (json && json.error && json.error.message) || 'Erreur')); }
            }, function(err){ alert('Erreur API test'); });
          }, 200));
        }
  log('success','[YUZ][JS][GENERAL][INIT] General Settings ready.');
      });
    });
  })(window, document, jQuery);

})(jQuery);

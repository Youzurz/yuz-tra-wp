var console = window.__YUZ_RELEASE_CONSOLE__ || {log:function(){},debug:function(){},info:function(){},warn:function(){},error:function(){}}; var yuz_release_console = console;

(() => {
  // Wait until PHP localized payload is present
  function ready(fn){ if (document.readyState!=='loading') fn(); else document.addEventListener('DOMContentLoaded', fn); }

  function alias(source, path, def) {
    // read deep (a.b.c) → value or default
    try {
      return path.split('.').reduce((o,k)=> (o && (k in o) ? o[k] : undefined), source) ?? def;
    } catch(_) { return def; }
  }

  async function postAjax(action, payload) {
    const Y = window.yuzTraSettings || {};
    const nonce = (Y.nonces && (Y.nonces[action] || Y.nonces.yuz_tra_nonce)) || Y.nonce;
    const body = new URLSearchParams({ action, nonce });
    Object.entries(payload || {}).forEach(([k,v])=> body.append(k, typeof v==='boolean'? (v? '1':'0') : (v ?? '')));
    const r = await fetch(Y.ajax_url, { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'}, body });
    return r.ok ? r.json().catch(()=> ({})) : r.text().then(t=> { throw new Error(`HTTP ${r.status} ${t}`); });
  }

  // Public helpers: canonical update + re-fetch SSOT
  async function ssotUpdate(section, data) {
    // n447 canonical endpoint
    await postAjax('yuz_settings_update', { section, data: JSON.stringify(data||{}) });
    // immediately re-fetch full config so UI reads the bumped version
    const fresh = await postAjax('yuz_get_all', {});
    if (fresh && fresh.success && fresh.data) {
      window.yuzTraSettings = fresh.data;
    }
    return fresh;
  }

  ready(() => {
    const Y = window.yuzTraSettings || {};
    // Build a flat compat view that proxies to SSOT
    const compat = new Proxy({}, {
      get(_, key){
        switch (key) {
          // General
          case 'source_lang':              return alias(Y, 'general.yuz_tra_source_language', '');
          case 'default_lang':             return alias(Y, 'general.yuz_tra_default_language', '');
          case 'translatable_languages':   return alias(Y, 'general.yuz_tra_translatable_languages', []);

          // Settings (UI)
          case 'url_to_load':              return alias(Y, 'settings.url_to_load', '');
          case 'native_language_name':     return !!alias(Y, 'settings.native_language_name', false);
          case 'force_lang_in_links':      return !!alias(Y, 'settings.force_lang_in_links', false);
          case 'use_subdirectory':         return !!alias(Y, 'settings.use_subdirectory', false);

          // API settings
          case 'api_provider':             return alias(Y, 'api_settings.api_provider', '');
          case 'libre_url':                return alias(Y, 'api_settings.libre_url', '');
          case 'libre_key':                return alias(Y, 'api_settings.libre_key', '');
          case 'google_key':               return alias(Y, 'api_settings.google_key', '');
          case 'deepl_key':                return alias(Y, 'api_settings.deepl_key', '');
          case 'enable_auto_translate':    return !!alias(Y, 'api_settings.enable_auto_translate', false);

          // Switcher
          case 'shortcode_enabled':        return !!alias(Y, 'switcher.shortcode_enabled', false);
          case 'shortcode_format':         return alias(Y, 'switcher.shortcode_format', 'flags-full-names');
          case 'menu_enabled':             return !!alias(Y, 'switcher.menu_enabled', false);
          case 'menu_format':              return alias(Y, 'switcher.menu_format', 'flags-full-names');
          case 'floating_enabled':         return !!alias(Y, 'switcher.floating_enabled', false);
          case 'floating_format':          return alias(Y, 'switcher.floating_format', 'flags-full-names');
          case 'floating_theme':           return alias(Y, 'switcher.floating_theme', 'light');
          case 'floating_position':        return alias(Y, 'switcher.floating_position', 'bottom-right');
          case 'show_poweredby':           return !!alias(Y, 'switcher.show_poweredby', false);

          // Site settings
          case 'translate_seo':            return !!alias(Y, 'site_settings.translate_seo', false);
          case 'enable_ai':                return !!alias(Y, 'site_settings.enable_ai', false);
          case 'browser_language_detect':  return !!alias(Y, 'site_settings.browser_language_detect', false);

          // Fallback to original payload (ajax_url, nonces, etc.)
          default: return Y[key];
        }
      },
      set(_, key, value){
        // Collect writes into the right SSOT section; we don’t mutate in place—callers should use save* below.
        return false;
      }
    });

    // Expose compat view + save shims the widget can call (legacy names preserved)
    window.yuzShim = {
      compat,    // reads on old flat keys
      saveGeneral:   (data)=> ssotUpdate('general',       data),
      saveSettings:  (data)=> ssotUpdate('settings',      data),
      saveSwitcher:  (data)=> ssotUpdate('switcher',      data),
      saveSite:      (data)=> ssotUpdate('site_settings', data),
      saveAPI:       (data)=> ssotUpdate('api_settings',  data),
      refreshAll:    ()=> postAjax('yuz_get_all', {}).then(j=>{ if(j?.success&&j.data){ window.yuzTraSettings=j.data; } return j; }),
    };

    // Last step: for “zero-touch” legacy code, mirror flat reads:
    // Any code that does window.yuzTraSettings.<flat> will still work for GET (not for SET).
    Object.defineProperties(window.yuzTraSettings, {
      source_lang:             { get(){ return compat.source_lang; } },
      default_lang:            { get(){ return compat.default_lang; } },
      translatable_languages:  { get(){ return compat.translatable_languages; } },
      url_to_load:             { get(){ return compat.url_to_load; } },
      native_language_name:    { get(){ return compat.native_language_name; } },
      force_lang_in_links:     { get(){ return compat.force_lang_in_links; } },
      use_subdirectory:        { get(){ return compat.use_subdirectory; } },
      api_provider:            { get(){ return compat.api_provider; } },
      libre_url:               { get(){ return compat.libre_url; } },
      libre_key:               { get(){ return compat.libre_key; } },
      google_key:              { get(){ return compat.google_key; } },
      deepl_key:               { get(){ return compat.deepl_key; } },
      enable_auto_translate:   { get(){ return compat.enable_auto_translate; } },
      shortcode_enabled:       { get(){ return compat.shortcode_enabled; } },
      shortcode_format:        { get(){ return compat.shortcode_format; } },
      menu_enabled:            { get(){ return compat.menu_enabled; } },
      menu_format:             { get(){ return compat.menu_format; } },
      floating_enabled:        { get(){ return compat.floating_enabled; } },
      floating_format:         { get(){ return compat.floating_format; } },
      floating_theme:          { get(){ return compat.floating_theme; } },
      floating_position:       { get(){ return compat.floating_position; } },
      show_poweredby:          { get(){ return compat.show_poweredby; } },
      translate_seo:           { get(){ return compat.translate_seo; } },
      enable_ai:               { get(){ return compat.enable_ai; } },
      browser_language_detect: { get(){ return compat.browser_language_detect; } },
    });
  });
})();
var yuz_release_console={log(){},debug(){},info(){},warn(){},error(){},groupCollapsed(){},groupEnd(){},table(){}};


/*! YUZ-TE bootstrap shim (safe overlay if main script crashed) */
// --- hotfix: ensure nonce for admin-ajax posts (safe, idempotent) ---
(function () {
  const Y = window.yuzTraSettings || {};
  const QUIET = (Y.silent_logs !== false) && !/\byuzdebug=1\b/.test(window.location.search || '');
  const AJAX = Y.ajax_url || '/wp-admin/admin-ajax.php';
  const NONCES = Y.nonces || {};
  const nonceFor = (action) =>
    NONCES[action] || NONCES.yuz_tra_nonce || NONCES.yuz_tra_ws_get_languages || Y.nonce || '';

  // Helper universel (fetch)
  window.yuzPost = async function (action, payload = {}) {
    const n = nonceFor(action);
    const body = new URLSearchParams({ action, ...payload });
    if (n) { body.set('yuz_tra_nonce', n); body.set('nonce', n); body.set('security', n); }
    const r = await fetch(AJAX, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8','X-Requested-With':'XMLHttpRequest' },
      body
    });
    const t = await r.text();
    try { return JSON.parse(t); } catch { return t; }
  };

  // Si jQuery est présent, patch les POST existants
  if (window.jQuery && jQuery.ajaxPrefilter) {
    jQuery.ajaxPrefilter(function (opts, orig, jq) {
      try {
        if (/admin-ajax\.php/.test(opts.url || '')) {
          const d = new URLSearchParams(orig.data || '');
          const a = d.get('action') || '';
          const n = nonceFor(a);
          if (n && !d.has('yuz_tra_nonce') && !d.has('nonce') && !d.has('security')) {
            d.set('yuz_tra_nonce', n); d.set('nonce', n); d.set('security', n);
            opts.data = d.toString();
          }
        }
      } catch (e) { if (!QUIET) yuz_release_console.warn('[yuz hotfix]', e); }
    });
  }
})();

(function (w, d) {
  let QUIET = false;
  try {
    const CFG = w.yuzTraSettings || {};
    QUIET = (CFG.silent_logs !== false) && !/\byuzdebug=1\b/.test(w.location.search || '');
    try { d.documentElement.dataset.yuzUi = 'advanced'; } catch (_) {}
    if (d.querySelector('#yuz-editor-container .yuz-modal')) return; // déjà monté
    const norm = s => (s || '').toString().replace('-', '_');

    // Récup données serveur, avec fallbacks "compat"
    const DEF  = norm(CFG.default_language);
    const SRC  = norm(CFG.source_language);
    const FROM = norm(CFG.effective_from || SRC || DEF);
    const TGTS = (Array.isArray(CFG.translation_langs) && CFG.translation_langs.length)
      ? CFG.translation_langs.map(norm)
      // compat: certaines builds exposent encore "yuz_tra_translatable_languages"
      : (CFG.yuz_tra_general && Array.isArray(CFG.yuz_tra_general.yuz_tra_translatable_languages))
        ? CFG.yuz_tra_general.yuz_tra_translatable_languages.map(norm)
        : [];

    const NAMES = CFG.language_names || {}; // ex: {fr_FR:"French (France)", en_GB:"English (UK)"}
    const NO_TARGETS = !TGTS.length;

    if (NO_TARGETS && !QUIET) {
    }

    // Host + styles
    const labelFor = (code, suffix='') => {
      if (!code) return suffix || '';
      const lookup = NAMES[code];
      if (lookup) return suffix ? `${lookup}${suffix}` : lookup;
      if (typeof w.yuz_lang_label === 'function') {
        const lbl = w.yuz_lang_label(code);
        if (lbl) return suffix ? `${lbl}${suffix}` : lbl;
      }
      const normalized = code.replace(/_/g, '-');
      return suffix ? `${normalized}${suffix}` : normalized;
    };

    const host = d.createElement('div');
    host.id = 'yuz-editor-container';
    host.innerHTML = `
      <style id="yuz-te-shim-css">
        #yuz-editor-container{position:fixed;inset:0;z-index:2147483646;}
        .yuz-te-overlay{position:absolute;inset:0;background:rgba(3,7,18,.55);}
        #yuz-te-advanced{
          position:absolute; left:24px; bottom:24px; width:380px; max-height:80vh; overflow:auto;
          background:#fff; color:#111; border-radius:12px; box-shadow:0 10px 40px rgba(0,0,0,.35);
          padding:16px; font:14px/1.4 system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,'Helvetica Neue',Arial;
        }
        #yuz-te-advanced h3{margin:0 0 10px; font-size:16px;}
        #yuz-te-advanced .y-alert{margin:12px 0 0;padding:12px;border-radius:10px;background:#f8fafc;border:1px solid rgba(15,23,42,.12);color:#0f172a;font-size:13px;line-height:1.5;}
        #yuz-te-advanced .y-alert strong{display:block;font-weight:600;margin-bottom:4px;}
        .y-row{display:flex; gap:8px; align-items:center;}
        .y-space{height:8px;}
        .y-select{width:100%; padding:8px; border:1px solid #e5e7eb; border-radius:8px; font:500 13px/1.4 system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,'Helvetica Neue',Arial;}
        .y-text{width:100%; padding:8px; border:1px solid #e5e7eb; border-radius:8px; writing-mode:horizontal-tb; white-space:pre-wrap; line-height:1.5; font:500 13px/1.5 system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,'Helvetica Neue',Arial;}
        .y-actions{display:flex; gap:8px; justify-content:flex-end;}
        .y-btn{padding:8px 10px; border-radius:8px; border:1px solid #e5e7eb; background:#f3f4f6; cursor:pointer;}
        .y-btn.y-primary{background:#2563eb; color:#fff; border-color:#1d4ed8;}
        .y-top{display:flex;justify-content:space-between;align-items:center;}
        .y-badge{font-size:12px;color:#6b7280}
        .y-close{background:#ef4444;color:#fff}
      </style>
      <div class="yuz-te-overlay"></div>
      <div id="yuz-te-advanced" class="yuz-modal yuz-te--advanced" role="dialog" aria-label="YUZ Translation Editor" data-yuz-mode="advanced">
        <div class="y-top">
          <h3>YUZ Translation Editor</h3>
          <div class="y-row">
            <button class="y-btn" data-y-reset>Reset position</button>
            <button class="y-btn y-close" data-y-close>Close</button>
          </div>
        </div>
        <div class="y-badge">Shim mode (secours) — l’overlay principal a échoué à monter.</div>
        <div class="y-alert" data-y-no-targets style="display:none">
          <strong>Aucune langue cible configurée.</strong>
          Configurez une langue « Translatable » dans YUZ TRA → Languages pour activer l’éditeur.
        </div>
        <div class="y-space"></div>

        <div class="y-row">
          <div style="flex:1">
            <div style="font-size:12px;margin-bottom:4px">From</div>
            <select class="y-select" id="y-from"></select>
          </div>
          <div style="flex:1">
            <div style="font-size:12px;margin-bottom:4px">To</div>
            <select class="y-select" id="y-to"></select>
          </div>
        </div>

        <div class="y-space"></div>
        <div style="font-size:12px;margin-bottom:4px">Original</div>
        <textarea class="y-text" id="y-orig" rows="4" placeholder="..."></textarea>
        <div class="y-space"></div>
        <div style="font-size:12px;margin-bottom:4px">Translation</div>
        <textarea class="y-text" id="y-trad" rows="4" placeholder="..."></textarea>

        <div class="y-space"></div>
        <div class="y-actions">
          <button class="y-btn" data-y-autotrans>Auto translate</button>
          <button class="y-btn y-primary" data-y-save>Save</button>
          <button class="y-btn" data-y-publish disabled>Publish</button>
        </div>
      </div>
    `;
    d.body.appendChild(host);

    // Remplir les selects (friendly names si fournis)
    const $from = d.getElementById('y-from');
    const $to   = d.getElementById('y-to');
    const fromChoices = Array.from(new Set([FROM, DEF].filter(Boolean)));
    fromChoices.forEach(c=>{
      const o=d.createElement('option'); o.value=c; o.textContent=labelFor(c, c===FROM ? ' (source)' : ''); $from.appendChild(o);
    });
    TGTS.filter(t=>t!==FROM).forEach(c=>{
      const o=d.createElement('option'); o.value=c; o.textContent=labelFor(c); $to.appendChild(o);
    });

    // Events basiques
    host.querySelector('[data-y-close]')?.addEventListener('click',()=>host.remove());
    host.querySelector('[data-y-reset]')?.addEventListener('click',()=>{
      const modal = d.querySelector('#yuz-editor-container .yuz-modal');
      modal.style.left='24px'; modal.style.bottom='24px';
    });

    if (NO_TARGETS) {
      const badge = host.querySelector('[data-y-no-targets]');
      if (badge) badge.style.display = 'block';
      if (!QUIET) {
      }
    }

    if (!QUIET) {
    }
  } catch (e) {
    if (!QUIET) yuz_release_console.error('[YUZ-TE shim] failed:', e);
  }
})(window, document);

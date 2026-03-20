var yuz_release_console={log(){},debug(){},info(){},warn(){},error(){},groupCollapsed(){},groupEnd(){},table(){}};


/**
 * assets/js/widgets/yuz-language-switcher.js
 * Front-end language switcher (shortcode/menu/floating).
 *
 * Discipline:
 *  - ACT-03: utilise uniquement les données localisées (yuzSW) et ses nonces par action
 *  - ACT-05: ne dépend d’aucun autre module/onglet
 *
 * Localisation attendue (côté PHP - handle: yuz-language-switcher):
 *   window.yuzSW = {
 *     ajax_url: "...",
 *     nonces: { yuz_tra_sw_switch_language: "..." },
 *     switcher: {
 *       // au moins:
 *       shortcode_enabled, shortcode_format,
 *       menu_enabled,      menu_format,
 *       floating_enabled,  floating_format,
 *       floating_theme,    floating_position,
 *       show_poweredby
 *     },
 *     // (facultatif si exposé)
 *     translation_langs: ["en_US","fr_FR",...],
 *     language_names:    { "en_US":"English","fr_FR":"Français",... },
 *     flags_path:        "https://.../assets/flags/?v=1.0.0",
 *     flags_file_name:   { "en_US":"en-us.png","fr_FR":"fr-fr.png", ... },
 *     default_language:  "en_US",
 *   }
 */
(function (window, document, React, ReactDOM, $) {
  'use strict';

  // ---- Guards minimales
  if (!window || !document) return;
  if (!window.yuzSW) {
    return;
  }

  // --------- Fallback helpers (no inline handlers) ----------
  function twoLetter(code){ return (code || '').slice(0, 2).toUpperCase(); }
  function replaceWithFallback(el, code){
    try {
      if (!el || (el.dataset && el.dataset.replaced === '1')) return;
      const span = document.createElement('span');
      span.className = 'yuz-flag-fallback';
      span.textContent = twoLetter(code) || '??';
      el.replaceWith(span);
      span.dataset.replaced = '1';
    } catch(_) {}
  }
  function attachFlagFallbacks(root){
    const imgs = (root || document).querySelectorAll('img.yuz-flag');
    imgs.forEach(img => {
      const code = img.getAttribute('data-lang-code') || '';
      const txt  = img.getAttribute('data-fallback-text') || twoLetter(code);
      const handler = () => replaceWithFallback(img, txt);
      img.addEventListener('error', handler, { once: true });
      // Déjà chargé mais cassé ?
      if (img.complete && (!img.naturalWidth || !img.naturalHeight)) handler();
    });
  }

  const Y = window.yuzSW || {};

  const ROOT_SELECTOR = '.yuz-language-switcher';
  const BUTTON_SELECTOR = '.yuz-current-lang';
  const DROPDOWN_SELECTOR = '.yuz-language-dropdown';
  const FOCUSABLE_SELECTOR = 'a[href], button:not([disabled]), [tabindex]:not([tabindex="-1"])';

  function getSwitcherElements(target) {
    const root = target && target.closest ? target.closest(ROOT_SELECTOR) : null;
    if (!root) { return { root: null, button: null, dropdown: null }; }
    const button = root.querySelector(BUTTON_SELECTOR);
    const dropdown = root.querySelector(DROPDOWN_SELECTOR);
    return { root, button, dropdown };
  }

  function computeDropdownDirection(root) {
    if (!root) return;
    const button = root.querySelector(BUTTON_SELECTOR);
    const dropdown = root.querySelector(DROPDOWN_SELECTOR);
    if (!button || !dropdown) return;

    if (root.dataset && root.dataset.autoFlip === '0') {
      dropdown.classList.remove('drop-up');
      dropdown.classList.add('drop-down');
      return;
    }

    dropdown.classList.remove('drop-up', 'drop-down', 'align-right');
    dropdown.style.maxHeight = '';

    const viewportHeight = window.innerHeight || document.documentElement.clientHeight || 0;
    const viewportWidth  = window.innerWidth  || document.documentElement.clientWidth  || 0;
    const buttonRect = button.getBoundingClientRect();
    const theoreticalHeight = dropdown.scrollHeight || 0;
    const maxHeight = Math.max(160, Math.round(Math.min(viewportHeight - 24, viewportHeight * 0.6)));
    const dropdownHeight = Math.min(theoreticalHeight, maxHeight);
    const spaceBelow = viewportHeight - buttonRect.bottom;
    const spaceAbove = buttonRect.top;
    const openUp = spaceBelow < dropdownHeight && spaceAbove > spaceBelow;

    dropdown.classList.add(openUp ? 'drop-up' : 'drop-down');

    const dropRect = dropdown.getBoundingClientRect();
    if (dropRect.right > viewportWidth && (dropRect.width <= viewportWidth)) {
      dropdown.classList.add('align-right');
    }

    dropdown.style.maxHeight = `${dropdownHeight}px`;
    dropdown.style.overflowY = 'auto';
  }

  function closeSwitcher(root, opts) {
    if (!root) return;
    const options = opts || {};
    const { button, dropdown } = getSwitcherElements(root);
    root.classList.remove('open');
    if (dropdown) {
      dropdown.classList.remove('drop-up', 'drop-down', 'align-right');
      dropdown.style.maxHeight = '';
      dropdown.style.overflowY = '';
    }
    if (button) {
      button.setAttribute('aria-expanded', 'false');
      if (options.returnFocus) {
        try { button.focus({ preventScroll: true }); }
        catch (_) { button.focus(); }
      }
    }
  }

  function closeAllSwitchers(except, opts) {
    document.querySelectorAll(ROOT_SELECTOR).forEach((root) => {
      if (except && root === except) return;
      const button = root.querySelector(BUTTON_SELECTOR);
      const isOpen = root.classList.contains('open') || (button && button.getAttribute('aria-expanded') === 'true');
      if (isOpen) {
        closeSwitcher(root, opts);
      }
    });
  }

  function focusFirstItem(root) {
    if (!root) return;
    const dropdown = root.querySelector(DROPDOWN_SELECTOR);
    if (!dropdown) return;
    const focusable = dropdown.querySelectorAll(FOCUSABLE_SELECTOR);
    if (!focusable.length) return;
    const first = focusable[0];
    try { first.focus({ preventScroll: true }); }
    catch (_) { first.focus(); }
  }

  function openSwitcher(root) {
    if (!root) return;
    const { button, dropdown } = getSwitcherElements(root);
    if (!button || !dropdown) return;
    closeAllSwitchers(root);
    root.classList.add('open');
    button.setAttribute('aria-expanded', 'true');
    computeDropdownDirection(root);
    focusFirstItem(root);
  }

  function recomputeOpenSwitchers() {
    document.querySelectorAll(`${ROOT_SELECTOR}.open`).forEach((root) => computeDropdownDirection(root));
  }

  const STEALTH_STORAGE_KEY = 'yuz_sw_stealth_mode';
  const STEALTH_CLASS = 'yuz-stealth-mode';
  const STEALTH_TOGGLE_CLASS = 'yuz-switcher-stealth-toggle';

  function isStealthSupported(root) {
    if (!root) return false;
    const mode = String(root.getAttribute('data-switcher-mode') || '').toLowerCase();
    return mode === 'floating' || root.id === 'yuz-floating-switcher';
  }

  function getStealthPreference() {
    try {
      return window.localStorage.getItem(STEALTH_STORAGE_KEY) === '1';
    } catch (_) {
      return false;
    }
  }

  function saveStealthPreference(enabled) {
    try {
      window.localStorage.setItem(STEALTH_STORAGE_KEY, enabled ? '1' : '0');
    } catch (_) {}
  }

  function setStealthState(root, enabled) {
    if (!root) return;
    root.classList.toggle(STEALTH_CLASS, !!enabled);
    root.setAttribute('data-stealth', enabled ? '1' : '0');
    const toggle = root.querySelector(`.${STEALTH_TOGGLE_CLASS}`);
    if (toggle) {
      const isOn = !!enabled;
      toggle.setAttribute('aria-pressed', isOn ? 'true' : 'false');
      toggle.setAttribute('aria-label', isOn ? 'Restore switcher visibility' : 'Reduce switcher visibility');
      toggle.setAttribute('title', isOn ? 'Restore switcher visibility' : 'Reduce switcher visibility');
    }
  }

  function ensureStealthToggle(root) {
    if (!isStealthSupported(root)) return;
    if (root.querySelector(`.${STEALTH_TOGGLE_CLASS}`)) return;

    const button = document.createElement('button');
    button.type = 'button';
    button.className = STEALTH_TOGGLE_CLASS;
    button.textContent = 'S';
    button.addEventListener('click', (event) => {
      event.preventDefault();
      event.stopPropagation();
      const next = !root.classList.contains(STEALTH_CLASS);
      setStealthState(root, next);
      saveStealthPreference(next);
      if (next) {
        closeSwitcher(root);
      }
    });

    root.appendChild(button);
  }

  function initStealthControls(scope) {
    if (scope && scope.matches && scope.matches(ROOT_SELECTOR)) {
      ensureStealthToggle(scope);
      setStealthState(scope, getStealthPreference());
      return;
    }

    document.querySelectorAll(ROOT_SELECTOR).forEach((root) => {
      ensureStealthToggle(root);
      setStealthState(root, getStealthPreference());
    });
  }

  // ---- Log helper
  const LOG = {
    critical: '🔴 [CRITICAL]',
    warning : '🟡 [WARNING]',
    success : '🟢 [SUCCESS]',
    info    : '🔵 [INFO]',
  };
  function log(level, message, ctx) {
    const ts = new Date().toISOString();
    let s = `${LOG[level] || ''} ${message} at ${ts}`;
    if (ctx) { try { s += ` | ${JSON.stringify(ctx)}`; } catch(_){} }
    (level === 'critical' ? yuz_release_console.error :
     level === 'warning'  ? yuz_release_console.warn  : yuz_release_console.log)(s);
  }

  const ACTION_RESOLVE = (Y && Y.endpoints && Y.endpoints.resolve_url) || 'yuz_tra_sw_resolve_url';

  function resolveCanonicalUrl(code, currentUrl) {
    return new Promise((resolve) => {
      try {
        const ajax = (Y && Y.ajax_url) || window.ajaxurl || '';
        const nonce = nonceFor(ACTION_RESOLVE);
        const langCode = typeof code === 'string' ? code : '';

        if (!ajax || !nonce || !langCode) {
          resolve('');
          return;
        }

        $.ajax({
          url: ajax,
          type: 'POST',
          data: {
            action: ACTION_RESOLVE,
            nonce,
            language_code: langCode,
            current_url: currentUrl || window.location.href
          }
        }).done((response) => {
          if (response && response.success && response.data && response.data.url) {
            resolve(response.data.url);
          } else {
            resolve('');
          }
        }).fail(() => resolve(''));
      } catch (err) {
        log('warning','[SWITCHER] resolveCanonicalUrl failed', err?.message || err);
        resolve('');
      }
    });
  }

  function navigateWithResolve(code, fallbackUrl) {
    const fallback = fallbackUrl || addOrReplaceQuery(window.location.href, 'lang', code);
    resolveCanonicalUrl(code).then((resolved) => {
      const target = resolved || fallback;
      clearWooFragments();
      window.location.href = target;
    });
  }

  // ---- Nonce per action
  function nonceFor(action) {
    if (Y && Y.nonces && Y.nonces[action]) return Y.nonces[action];
    return '';
  }

  // ---- Utils
  function addOrReplaceQuery(url, key, value) {
    try {
      const u = new URL(url, window.location.origin);
      u.searchParams.set(key, value);
      return u.toString();
    } catch (_) {
      // Fallback naïf
      const k = encodeURIComponent(key), v = encodeURIComponent(value);
      return url.indexOf('?') >= 0
        ? url.replace(new RegExp('([?&])' + k + '=[^&]*'), '$1' + k + '=' + v) + (url.indexOf(k + '=') === -1 ? '&' + k + '=' + v : '')
        : url + '?' + k + '=' + v;
    }
  }

  function clearWooFragments() {
    try {
      if (typeof wc_cart_fragments_params !== 'undefined' && wc_cart_fragments_params.fragment_name) {
        window.sessionStorage.removeItem(wc_cart_fragments_params.fragment_name);
        log('info','[WC] Cart fragments cleared');
      }
    } catch(_) {}
  }

  // ---- Normalisation settings switcher (canonical keys only)
  const SW = (function normalizeSwitcher(s) {
    s = s || {};
    return {
      shortcode_enabled : !!s.shortcode_enabled,
      shortcode_format  : (s.shortcode_format  ?? 'flags-full-names'),
      menu_enabled      : !!s.menu_enabled,
      menu_format       : (s.menu_format       ?? 'flags-full-names'),
      floating_enabled  : !!s.floating_enabled,
      floating_format   : (s.floating_format   ?? 'flags-full-names'),
      floating_theme    : (s.floating_theme    ?? 'dark'),
      floating_position : (s.floating_position ?? 'bottom-right'),
      show_poweredby    : !!s.show_poweredby,
      classes           : (s.classes ?? 'yuz-switcher'),
      position          : (s.position ?? 'bottom-right'),
      native_language_name: !!s.native_language_name,
    };
  })(Y.switcher);

  // ---- Langues: construit une liste minimale à partir de ce qui est disponible
  function resolveLanguageList(rootEl) {
    // 1) data-languages="en_US,fr_FR" prioritaire si fourni sur le conteneur
    const attr = rootEl?.getAttribute('data-languages');
    let codes = attr ? attr.split(',').map(c => c.trim()).filter(Boolean) : null;

    // 2) Sinon, essaye depuis yuzSW.translation_langs
    if (!codes || !codes.length) {
      if (Array.isArray(Y.translation_langs) && Y.translation_langs.length) {
        codes = Y.translation_langs.slice();
      }
    }
    // 3) Fallback : default_language seule
    if (!codes || !codes.length) {
      const dl = Y.default_language || document.documentElement.lang || 'en';
      codes = [String(dl)];
    }

    const names = Y.language_names || {};
    const flagsMap = Y.flags_file_name || {};
    // Map minimal pour les langues sans pays explicite
    const lang2flag = { en:'gb', fr:'fr', es:'es', de:'de', it:'it', pt:'pt', ar:'sa' };

    return codes.map(code => {
      const name = names[code] || String(code).toUpperCase();
      let flagFile = flagsMap[code];
      if (!flagFile) {
        const norm = String(code).toLowerCase().replace('_','-');
        // Si locale contient un pays, prendre la partie pays; sinon mapper la langue → drapeau
        let cc = norm.indexOf('-') > -1 ? norm.split('-')[1] : '';
        if (!cc) cc = lang2flag[norm] || 'xx';
        flagFile = cc + '.svg';
      }
      return { language_code: code, language_name: name, flag_file: flagFile };
    });
  }

  function currentLangCode() {
    // 1) URL param ?lang=XX prioritaire
    try {
      const u = new URL(window.location.href);
      const v = u.searchParams.get('lang');
      if (v) return v;
    } catch(_) {}
    // 2) html lang
    const htmlLang = (document.documentElement.getAttribute('lang') || '').trim();
    if (htmlLang) return htmlLang;
    // 3) default localisé
    return Y.default_language || 'en';
  }

  // ---- Rendu élément (React) ----------------------------------------------
  function hasReact() {
    return !!(window.React && window.ReactDOM);
  }

  const LanguageSwitcher = ({ settings, languages, currentLang, ajaxUrl, nonce, flags, names, poweredBy }) => {
    const [isOpen, setIsOpen] = React.useState(false);
    const ref = React.useRef(null);

    const format = settings.floating_format || settings.menu_format || settings.shortcode_format || 'flags-full-names';
    const theme  = settings.floating_theme  || 'dark';
    const pos    = settings.floating_position || settings.position || 'bottom-right';

    function getName(lang) {
      // Si LS a demandé les noms natifs (quand exposés), ici on ne les a pas tous → on reste sur names map si disponible
      return lang.language_name || (names && names[lang.language_code]) || lang.language_code.toUpperCase();
    }

    function flagUrl(lang) {
      if (!flags || !lang.flag_file) return null;
      return flags.replace(/\/?$/, '/') + lang.flag_file; // flags peut être "…/flags/?v=1.0.0" → géré côté serveur; sinon simple dossier
    }

    function renderDisplay(lang, fmt) {
      const hasFlags = !!flags;
      if (fmt.includes('flags') && hasFlags) {
        return React.createElement('img', {
          className: 'yuz-flag',
          src: flagUrl(lang),
          alt: getName(lang),
          title: getName(lang),
          width: '18',
          height: '12',
          'data-lang-code': lang.language_code || '',
          onError: (e) => replaceWithFallback(e.currentTarget, lang.language_code || '')
        });
      }
      return fmt.includes('short')
        ? (lang.language_code || '').toUpperCase()
        : getName(lang);
    }

    function toggle() {
      setIsOpen(v => !v);
    }

    React.useEffect(() => {
      const onDoc = (e) => {
        if (ref.current && !ref.current.contains(e.target)) setIsOpen(false);
      };
      document.addEventListener('click', onDoc);
      return () => document.removeEventListener('click', onDoc);
    }, []);

    function doAjaxSwitch(code) {
      if (!ajaxUrl || !nonce) return false;
      const action = 'yuz_tra_sw_switch_language';
      $.ajax({
        url: ajaxUrl,
        type: 'POST',
        data: { action, nonce, lang: code, language_code: code },
        success: (response) => {
          if (response && response.success && response.data && response.data.url) {
            clearWooFragments();
            window.location.href = response.data.url;
          } else {
            log('warning','[SWITCHER] AJAX switch failed, fallback navigation', response?.data);
            navigateWithResolve(code);
          }
        },
        error: (xhr, status, err) => {
          log('critical','[SWITCHER] AJAX error, fallback navigation', { status, err });
          navigateWithResolve(code);
        }
      });
      return true;
    }

    function onChoose(code, e) {
      e && e.preventDefault && e.preventDefault();
      const used = doAjaxSwitch(code);
      if (!used) {
        navigateWithResolve(code);
      }
    }

    const current = languages.find(l => l.language_code === currentLang) || languages[0] || { language_code: currentLang, language_name: currentLang };

    return React.createElement(
      'div',
      { className: `yuz-language-switcher ${settings.classes || ''} yuz-theme-${theme} yuz-position-${pos}`, ref },
      [
        React.createElement(
          'button',
          { className: 'yuz-current-lang', onClick: toggle, 'aria-expanded': isOpen, 'aria-label': `Current language: ${getName(current)}, click to change` },
          [
            renderDisplay(current, format),
            React.createElement('span', { className: 'yuz-arrow', key: 'arr' }, '▼')
          ]
        ),
        isOpen && React.createElement(
          'ul',
          { className: 'yuz-language-dropdown', key: 'dd' },
          [
            ...languages.map(lang => React.createElement(
              'li',
              { key: lang.language_code },
              React.createElement(
                'a',
                {
                  href: addOrReplaceQuery(window.location.href, 'lang', lang.language_code),
                  onClick: onChoose.bind(null, lang.language_code),
                  'data-lang-code': lang.language_code,
                  'aria-label': `Switch to ${getName(lang)}`
                },
                renderDisplay(lang, format)
              )
            )),
            !!poweredBy && settings.show_poweredby && React.createElement(
              'li',
              { className: 'yuz-powered-by', key: 'pb' },
              ['Powered by ', React.createElement('a', { href: 'https://yuzurz.com', target: '_blank', rel: 'nofollow' }, 'YoUZurz')]
            )
          ]
        )
      ]
    );
  };

  // ---- Bootstrap (React si dispo, sinon jQuery fallback)
  function mountReactSwitchers() {
    const nodes = document.querySelectorAll('.yuz-shortcode-switcher, .yuz-language-switcher, #yuz-floating-switcher');
    if (!nodes.length) return;

    nodes.forEach((node) => {
      if (node.dataset.yuzMounted) return;
      if (node.dataset && node.dataset.ssr === '1') {
        initStealthControls(node);
        log('info','[SWITCHER] SSR node flagged, skipping React remount');
        return;
      }

      // Prépare données
      const langs = resolveLanguageList(node);
      const cur   = currentLangCode();
      const ajax  = Y.ajax_url || window.ajaxurl || '';
      const nonce = nonceFor('yuz_tra_sw_switch_language');

      // Nettoie contenu puis monte React
      try { node.replaceChildren(); } catch(_) { node.innerHTML=''; }

      // Détermine la base URL des drapeaux: priorité à la localisation PHP,
      // sinon lit la variable CSS --yuz-flags-url injectée par YUZ_Assets.
      let flagsBase = Y.flags_path || '';
      try {
        if (!flagsBase) {
          const cssVar = getComputedStyle(document.documentElement).getPropertyValue('--yuz-flags-url');
          if (cssVar) flagsBase = cssVar.trim();
        }
      } catch(_) {}

      ReactDOM.render(
        React.createElement(LanguageSwitcher, {
          settings: SW,
          languages: langs,
          currentLang: cur,
          ajaxUrl: ajax,
          nonce: nonce,
          flags: flagsBase, // base des drapeaux (localisé ou via var CSS)
          names: Y.language_names || {},
          poweredBy: true
        }),
        node
      );
      // Ceinture et bretelles : si une <img> casse avant onError, on traite ici.
      attachFlagFallbacks(node);
      initStealthControls(node);

      node.dataset.yuzMounted = '1';
      log('success','[SWITCHER] React instance mounted');
    });
  }

  function mountjQueryFallback() {
    const $doc = $(document);

    $doc.on('click', `${ROOT_SELECTOR} ${BUTTON_SELECTOR}`, function (e) {
      e.preventDefault();
      const { root } = getSwitcherElements(e.currentTarget);
      if (!root) return;
      const isOpen = root.classList.contains('open');
      if (isOpen) {
        closeSwitcher(root);
      } else {
        openSwitcher(root);
      }
    });

    $doc.on('click', function (e) {
      if (!$(e.target).closest(ROOT_SELECTOR).length) {
        closeAllSwitchers(null);
      }
    });

    $doc.on('keydown', function (e) {
      if (e.key === 'Escape') {
        closeAllSwitchers(null, { returnFocus: true });
      }
    });

    $doc.on('keydown', `${ROOT_SELECTOR} ${DROPDOWN_SELECTOR}`, function (e) {
      const root = getSwitcherElements(e.currentTarget).root;
      if (!root) return;
      if (e.key === 'Escape') {
        e.preventDefault();
        closeSwitcher(root, { returnFocus: true });
        return;
      }
      if (e.key === 'Tab') {
        const dropdown = e.currentTarget;
        const focusable = dropdown.querySelectorAll(FOCUSABLE_SELECTOR);
        if (!focusable.length) return;
        const first = focusable[0];
        const last = focusable[focusable.length - 1];
        if (!e.shiftKey && document.activeElement === last) {
          e.preventDefault();
          closeSwitcher(root, { returnFocus: true });
        } else if (e.shiftKey && document.activeElement === first) {
          e.preventDefault();
          closeSwitcher(root, { returnFocus: true });
        }
      }
    });

    $doc.on('click', `${ROOT_SELECTOR} ${DROPDOWN_SELECTOR} a`, function (e) {
      e.preventDefault();
      const link = e.currentTarget;
      const root = getSwitcherElements(link).root;
      const href = link.getAttribute('href') || '';
      let code = link.getAttribute('data-lang-code') || '';
      if (!code && href) {
        const match = href.match(/[?&]lang=([^&]+)/);
        if (match) code = decodeURIComponent(match[1]);
      }

      const ajax = Y.ajax_url || window.ajaxurl || '';
      const nonce = nonceFor('yuz_tra_sw_switch_language');

      const navigate = (url) => {
        const fallbackTarget = url || href || (code ? addOrReplaceQuery(window.location.href, 'lang', code) : window.location.href);
        navigateWithResolve(code, fallbackTarget);
      };

      closeSwitcher(root);

      if (ajax && nonce && code) {
        $.post(ajax, { action: 'yuz_tra_sw_switch_language', nonce, lang: code, language_code: code }, function (resp) {
          if (resp && resp.success && resp.data && resp.data.url) {
            navigate(resp.data.url);
          } else {
            log('warning','[SWITCHER][jQuery] AJAX failed, fallback nav');
            navigate(href);
          }
        }).fail(function () {
          log('critical','[SWITCHER][jQuery] AJAX error, fallback nav');
          navigate(href);
        });
      } else {
        navigate(href);
      }
    });

    let raf = null;
    const schedule = () => {
      if (raf) cancelAnimationFrame(raf);
      raf = requestAnimationFrame(recomputeOpenSwitchers);
    };
    window.addEventListener('resize', schedule);
    window.addEventListener('scroll', schedule, { passive: true });

    log('info','[SWITCHER] jQuery fallback active');
    attachFlagFallbacks(document);
    initStealthControls(document);
  }

  document.addEventListener('DOMContentLoaded', function () {
    // Choix du mode
    if (hasReact()) {
      try {
        mountReactSwitchers();
      } catch (e) {
        log('critical','[SWITCHER] React mount error, using jQuery fallback', e);
        mountjQueryFallback();
      }
    } else {
      mountjQueryFallback();
    }
    // Traite aussi le cas où des images existent avant toute action
    attachFlagFallbacks(document);
    initStealthControls(document);
  });

})(window, document, window.React, window.ReactDOM, window.jQuery);

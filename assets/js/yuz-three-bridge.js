var console = window.__YUZ_RELEASE_CONSOLE__ || {log:function(){},debug:function(){},info:function(){},warn:function(){},error:function(){}}; var yuz_release_console = console;


(function () {
  var existing = window.THREE || null;
  var READY_EVENT = 'three:ready';
  var DATA_KEY = 'data-yuz-three';
  var MODULE_SOURCES = [
    'https://cdn.jsdelivr.net/npm/three@0.160.0/build/three.module.min.js',
    'https://cdn.jsdelivr.net/npm/three@0.160.0/build/three.module.js'
  ];
  var LEGACY_SOURCE = 'https://cdn.jsdelivr.net/npm/three@0.149.0/build/three.min.js';

  function dispatchReady(ns, origin) {
    var detail = {
      revision: ns && ns.REVISION ? ns.REVISION : 'esm',
      origin: origin || 'module'
    };
    try {
      window.dispatchEvent(new CustomEvent(READY_EVENT, { detail: detail }));
    } catch (err) {
      yuz_release_console.warn('[YUZ][three] ready dispatch failed', err);
    }
  }

  function attachThree(ns, origin) {
    if (!ns) return;
    window.THREE = ns;
    dispatchReady(ns, origin);
  }

  function wrapCoverAnimations() {
    const wrap = (key) => {
      const original = window[key];
      if (typeof original !== 'function' || original.__yuzThreeGuard) return;
      const guarded = function (...args) {
        const canvas = args[0];
        const threeReady = typeof window.THREE !== 'undefined' && window.THREE && typeof window.THREE.Scene === 'function';
        if (!threeReady) {
          if (canvas && canvas.dataset) {
            const attempts = parseInt(canvas.dataset.yuzThreeAwait || '0', 10) || 0;
            if (attempts >= 5) {
              yuz_release_console.warn(`[YUZ][three] ${key}: THREE.js indisponible, animation ignorée.`);
              return;
            }
            canvas.dataset.yuzThreeAwait = String(attempts + 1);
            setTimeout(() => guarded.apply(this, args), 300 * (attempts + 1));
          } else {
            yuz_release_console.warn(`[YUZ][three] ${key}: THREE.js indisponible, appel différé.`);
            setTimeout(() => guarded.apply(this, args), 300);
          }
          return;
        }
        if (canvas && canvas.dataset) {
          delete canvas.dataset.yuzThreeAwait;
        }
        return original.apply(this, args);
      };
      guarded.__yuzThreeGuard = true;
      window[key] = guarded;
    };

    const scan = () => {
      Object.keys(window).forEach((key) => {
        if (/^cover\d*$/.test(key) || key === 'cover') {
          wrap(key);
        }
      });
    };

    scan();
    setTimeout(scan, 0);
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', scan, { once: true });
    } else {
      setTimeout(scan, 800);
    }
  }

  if (existing) {
    attachThree(existing, 'existing');
    wrapCoverAnimations();
    return;
  }

  wrapCoverAnimations();

  var SUPPORTS_MODULE = (function () {
    var script = document.createElement('script');
    return 'noModule' in script;
  })();

  function loadModuleSources(urls, index) {
    if (!SUPPORTS_MODULE || !urls || index >= urls.length) {
      return Promise.resolve(null);
    }

    var url = urls[index];
    return new Promise(function (resolve) {
      var script = document.createElement('script');
      script.type = 'module';
      script.textContent = "import * as THREE from " + JSON.stringify(url) + "; window.THREE = THREE;";
      script.onload = function () {
        script.remove();
        resolve({ ns: window.THREE || null, url: url });
      };
      script.onerror = function () {
        script.remove();
        yuz_release_console.warn('[YUZ][three] module load failed', url);
        resolve(null);
      };
      (document.head || document.documentElement).appendChild(script);
    }).then(function (payload) {
      if (payload && payload.ns) {
        return payload;
      }
      return loadModuleSources(urls, index + 1);
    });
  }

  function loadLegacy(src) {
    if (!src) return Promise.resolve(null);
    return new Promise(function (resolve, reject) {
      var script = document.createElement('script');
      script.src = src;
      script.async = false;
      script.defer = false;
      script.setAttribute(DATA_KEY, src);
      script.onload = function () {
        resolve(window.THREE || null);
      };
      script.onerror = function (err) {
        reject(err || new Error('load_failed'));
      };
      (document.head || document.documentElement).appendChild(script);
    });
  }

  (SUPPORTS_MODULE ? loadModuleSources(MODULE_SOURCES, 0) : Promise.resolve(null))
    .then(function (payload) {
      if (payload && payload.ns) {
        attachThree(payload.ns, payload.url);
        return;
      }
      return loadLegacy(LEGACY_SOURCE).then(function (legacy) {
        if (legacy) {
          attachThree(legacy, LEGACY_SOURCE);
        } else {
          yuz_release_console.error('[YUZ][three] Legacy loader produced no namespace');
        }
      }).catch(function (err) {
        yuz_release_console.error('[YUZ][three] Legacy loader failed', err);
      });
    })
    .catch(function (err) {
      yuz_release_console.error('[YUZ][three] Module loader crashed', err);
      loadLegacy(LEGACY_SOURCE).then(function (legacy) {
        if (legacy) attachThree(legacy, LEGACY_SOURCE);
      }).catch(function (err2) {
        yuz_release_console.error('[YUZ][three] Unable to provide THREE', err2);
      });
    });
})();


(function () {
  var existing = window.THREE || null;
  var READY_EVENT = 'three:ready';
  var DATA_KEY = 'data-yuz-three';
  // Optional decoration uses a namespace supplied by the host, never downloads code.
  if (!existing) return;
  var MODULE_SOURCES = [];
  var LEGACY_SOURCE = '';

  function dispatchReady(ns, origin) {
    var detail = {
      revision: ns && ns.REVISION ? ns.REVISION : 'esm',
      origin: origin || 'module'
    };
    try {
      window.dispatchEvent(new CustomEvent(READY_EVENT, { detail: detail }));
    } catch (err) {
      console.warn('[YUZ][three] ready dispatch failed', err);
    }
  }

  function attachThree(ns, origin) {
    if (!ns) return;
    window.THREE = ns;
    dispatchReady(ns, origin);
  }

  function createSceneLifecycle(label) {
    const nativeRequestAnimationFrame = window.requestAnimationFrame
      ? window.requestAnimationFrame.bind(window)
      : null;
    const nativeCancelAnimationFrame = window.cancelAnimationFrame
      ? window.cancelAnimationFrame.bind(window)
      : null;
    const nativeAddEventListener = window.addEventListener.bind(window);
    const nativeRemoveEventListener = window.removeEventListener.bind(window);
    const frameIds = new Set();
    const listeners = [];
    const renderers = new Set();
    let disposed = false;

    function scopedRequestAnimationFrame(callback) {
      if (!nativeRequestAnimationFrame || disposed) {
        return 0;
      }

      const frameId = nativeRequestAnimationFrame(function (timestamp) {
        frameIds.delete(frameId);
        if (disposed) {
          return;
        }
        runWithLifecycle(function () {
          callback(timestamp);
        });
      });
      frameIds.add(frameId);
      return frameId;
    }

    function scopedCancelAnimationFrame(frameId) {
      frameIds.delete(frameId);
      if (nativeCancelAnimationFrame) {
        nativeCancelAnimationFrame(frameId);
      }
    }

    function scopedAddEventListener(type, listener, options) {
      listeners.push({ type: type, listener: listener, options: options });
      return nativeAddEventListener(type, listener, options);
    }

    function scopedRemoveEventListener(type, listener, options) {
      for (let i = listeners.length - 1; i >= 0; i--) {
        const item = listeners[i];
        if (item.type === type && item.listener === listener) {
          listeners.splice(i, 1);
        }
      }
      return nativeRemoveEventListener(type, listener, options);
    }

    function installRendererGuard() {
      const ns = window.THREE;
      if (!ns || typeof ns.WebGLRenderer !== 'function') {
        return function () {};
      }

      const OriginalRenderer = ns.WebGLRenderer;
      if (OriginalRenderer.__yuzRendererLifecycleGuard) {
        return function () {};
      }

      function GuardedWebGLRenderer(...rendererArgs) {
        const renderer = new OriginalRenderer(...rendererArgs);
        renderers.add(renderer);
        return renderer;
      }

      GuardedWebGLRenderer.prototype = OriginalRenderer.prototype;
      if (Object.setPrototypeOf) {
        Object.setPrototypeOf(GuardedWebGLRenderer, OriginalRenderer);
      }
      GuardedWebGLRenderer.__yuzRendererLifecycleGuard = true;
      ns.WebGLRenderer = GuardedWebGLRenderer;

      return function restoreRenderer() {
        if (ns.WebGLRenderer === GuardedWebGLRenderer) {
          ns.WebGLRenderer = OriginalRenderer;
        }
      };
    }

    function runWithLifecycle(callback) {
      const previousRequestAnimationFrame = window.requestAnimationFrame;
      const previousCancelAnimationFrame = window.cancelAnimationFrame;
      const previousAddEventListener = window.addEventListener;
      const previousRemoveEventListener = window.removeEventListener;
      const restoreRenderer = installRendererGuard();

      window.requestAnimationFrame = scopedRequestAnimationFrame;
      window.cancelAnimationFrame = scopedCancelAnimationFrame;
      window.addEventListener = scopedAddEventListener;
      window.removeEventListener = scopedRemoveEventListener;

      try {
        return callback();
      } finally {
        if (window.requestAnimationFrame === scopedRequestAnimationFrame) {
          window.requestAnimationFrame = previousRequestAnimationFrame;
        }
        if (window.cancelAnimationFrame === scopedCancelAnimationFrame) {
          window.cancelAnimationFrame = previousCancelAnimationFrame;
        }
        if (window.addEventListener === scopedAddEventListener) {
          window.addEventListener = previousAddEventListener;
        }
        if (window.removeEventListener === scopedRemoveEventListener) {
          window.removeEventListener = previousRemoveEventListener;
        }
        restoreRenderer();
      }
    }

    function releaseRenderer(renderer) {
      if (!renderer) {
        return;
      }

      try {
        if (typeof renderer.dispose === 'function') {
          renderer.dispose();
        }
      } catch (err) {
        console.warn('[YUZ][three] renderer.dispose failed', label, err);
      }

      try {
        if (typeof renderer.forceContextLoss === 'function') {
          renderer.forceContextLoss();
        }
      } catch (err) {
        console.warn('[YUZ][three] renderer.forceContextLoss failed', label, err);
      }

      const canvas = renderer.domElement;
      if (canvas && typeof canvas.getContext === 'function') {
        try {
          const gl = canvas.getContext('webgl2') || canvas.getContext('webgl') || canvas.getContext('experimental-webgl');
          const ext = gl && gl.getExtension && gl.getExtension('WEBGL_lose_context');
          if (ext && typeof ext.loseContext === 'function') {
            ext.loseContext();
          }
        } catch (err) {
          console.warn('[YUZ][three] explicit context loss failed', label, err);
        }
      }
    }

    return {
      run: runWithLifecycle,
      dispose: function () {
        if (disposed) {
          return;
        }
        disposed = true;

        frameIds.forEach(function (frameId) {
          if (nativeCancelAnimationFrame) {
            nativeCancelAnimationFrame(frameId);
          }
        });
        frameIds.clear();

        listeners.splice(0).forEach(function (item) {
          try {
            nativeRemoveEventListener(item.type, item.listener, item.options);
          } catch (err) {
            console.warn('[YUZ][three] listener cleanup failed', label, err);
          }
        });

        renderers.forEach(releaseRenderer);
        renderers.clear();
      }
    };
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
              console.warn(`[YUZ][three] ${key}: THREE.js indisponible, animation ignorée.`);
              return;
            }
            canvas.dataset.yuzThreeAwait = String(attempts + 1);
            setTimeout(() => guarded.apply(this, args), 300 * (attempts + 1));
          } else {
            console.warn(`[YUZ][three] ${key}: THREE.js indisponible, appel différé.`);
            setTimeout(() => guarded.apply(this, args), 300);
          }
          return;
        }
        if (canvas && canvas.dataset) {
          delete canvas.dataset.yuzThreeAwait;
        }
        const lifecycle = createSceneLifecycle(key);
        let sceneInstance;
        try {
          sceneInstance = lifecycle.run(() => original.apply(this, args));
        } catch (err) {
          lifecycle.dispose();
          throw err;
        }

        if (sceneInstance && typeof sceneInstance === 'object') {
          const originalDispose = sceneInstance.dispose;
          sceneInstance.dispose = function (...disposeArgs) {
            try {
              if (typeof originalDispose === 'function') {
                return originalDispose.apply(sceneInstance, disposeArgs);
              }
            } finally {
              lifecycle.dispose();
            }
          };
          return sceneInstance;
        }

        return {
          dispose: function () {
            lifecycle.dispose();
          }
        };
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
        console.warn('[YUZ][three] module load failed', url);
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
          console.error('[YUZ][three] Legacy loader produced no namespace');
        }
      }).catch(function (err) {
        console.error('[YUZ][three] Legacy loader failed', err);
      });
    })
    .catch(function (err) {
      console.error('[YUZ][three] Module loader crashed', err);
      loadLegacy(LEGACY_SOURCE).then(function (legacy) {
        if (legacy) attachThree(legacy, LEGACY_SOURCE);
      }).catch(function (err2) {
        console.error('[YUZ][three] Unable to provide THREE', err2);
      });
    });
})();

(function (global) {
  if (global.YUZInspector) return;

  const BODY_CLASS = 'yuz-inspector-active';
  const DOCK_ID = 'yuz-inspector-dock';
  const ALL_SCOPE = '__all__';

  const noop = () => { };
  const normalizeToken = (value) => String(value || '').trim();
  const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
  const getThemeScriptVersion = () => {
    const value = global.CLAR && (global.CLAR.dynamicScriptVersion || global.CLAR.scriptVersion);
    return value ? String(value) : '';
  };
  const appendVersion = (src) => {
    const version = getThemeScriptVersion();
    if (!version) return src;
    const separator = src.includes('?') ? '&' : '?';
    return `${src}${separator}v=${encodeURIComponent(version)}`;
  };
  const loadStandaloneScript = (src, testFn) => new Promise((resolve, reject) => {
    try {
      if (typeof testFn === 'function' && testFn()) {
        resolve(true);
        return;
      }
      const match = Array.from(document.scripts || []).find((node) => {
        const current = String((node && node.src) || '');
        return current.indexOf(src) >= 0;
      });
      if (match) {
        let settled = false;
        const finish = (ok) => {
          if (settled) return;
          settled = true;
          if (ok) resolve(true);
          else reject(new Error(`Script unavailable: ${src}`));
        };
        match.addEventListener('load', () => finish(true), { once: true });
        match.addEventListener('error', () => finish(false), { once: true });
        setTimeout(() => finish(typeof testFn !== 'function' || testFn()), 700);
        return;
      }
      const script = document.createElement('script');
      script.src = appendVersion(src);
      const nonce = global.CLAR && (global.CLAR.cspNonce || global.CLAR.csp_nonce);
      if (nonce) {
        script.nonce = nonce;
        script.setAttribute('nonce', nonce);
      }
      script.onload = () => {
        if (typeof testFn === 'function' && !testFn()) {
          reject(new Error(`Script loaded without expected global: ${src}`));
          return;
        }
        resolve(true);
      };
      script.onerror = () => reject(new Error(`Failed to load script: ${src}`));
      document.head.appendChild(script);
    } catch (error) {
      reject(error);
    }
  });
  const canonicalModalKey = (value) => normalizeToken(value)
    .toLowerCase()
    .replace(/^form:/, '')
    .replace(/^modal[-_:]?/, '')
    .replace(/[^a-z0-9]+/g, '');

  const buildModalIdCandidates = (modalId) => {
    const raw = normalizeToken(modalId);
    if (!raw) return [];
    const seeds = new Set([
      raw,
      raw.replace(/^form:/i, ''),
      raw.replace(/^modal[-_:]?/i, '')
    ]);
    if (raw.includes(':')) {
      seeds.add(raw.split(':').pop());
    }
    const out = new Set();
    const add = (token) => {
      const value = normalizeToken(token);
      if (value) out.add(value);
    };
    seeds.forEach((seed) => {
      if (!seed) return;
      const dash = seed.replace(/_/g, '-');
      const under = seed.replace(/-/g, '_');
      [seed, dash, under].forEach((base) => {
        add(base);
        add(`modal-${base}`);
        add(`modal_${base}`);
      });
    });
    return Array.from(out);
  };

  const firstSelectorMatch = (selectors) => {
    if (!selectors) return null;
    const list = Array.isArray(selectors) ? selectors : [selectors];
    return list
      .map((sel) => {
        if (!sel) return null;
        if (typeof sel === 'string') return document.querySelector(sel);
        return null;
      })
      .find(Boolean);
  };

  const tryClickSelectors = (selectors) => {
    const el = firstSelectorMatch(selectors);
    if (!el) return false;
    try {
      el.dispatchEvent(new Event('click', { bubbles: true }));
    } catch (_) {
      try { el.click(); } catch (_) { }
    }
    return true;
  };

  const isModalVisible = (node) => {
    if (!node || node.nodeType !== 1) return false;
    const cs = window.getComputedStyle(node);
    if (cs.display === 'none' || cs.visibility === 'hidden' || parseFloat(cs.opacity || '0') === 0) return false;
    const rect = node.getBoundingClientRect();
    return rect.width > 0 && rect.height > 0;
  };

  const toggleBridgeDom = (modalEl, overlayEl, show, meta = {}) => {
    if (!modalEl && !overlayEl) return false;
    const modalDisplay = meta.display || 'block';
    const overlayDisplay = meta.overlayDisplay || 'flex';
    const apply = (node, display) => {
      if (!node) return;
      node.classList[show ? 'add' : 'remove']('active');
      if (show) {
        node.style.setProperty('display', display, 'important');
        node.style.setProperty('opacity', '1', 'important');
        node.style.setProperty('visibility', 'visible', 'important');
        node.style.setProperty('pointer-events', 'auto', 'important');
        node.removeAttribute('aria-hidden');
      } else {
        node.style.removeProperty('display');
        node.style.removeProperty('opacity');
        node.style.removeProperty('visibility');
        node.style.removeProperty('pointer-events');
        node.setAttribute('aria-hidden', 'true');
      }
    };
    apply(overlayEl, overlayDisplay);
    apply(modalEl, modalDisplay);
    return true;
  };

  const resolveModalId = (modalId) => {
    if (!modalId) return modalId;
    const id = normalizeToken(modalId);
    const candidates = buildModalIdCandidates(id);
    const lower = new Set(candidates.map((token) => token.toLowerCase()));
    const canonical = new Set(candidates.map(canonicalModalKey).filter(Boolean));

    const domIds = Array.from(document.querySelectorAll('[id]'));
    const exactDom = domIds.find((node) => typeof node.id === 'string' && lower.has(node.id.toLowerCase()));
    if (exactDom && exactDom.id) return exactDom.id;

    const canonicalDom = domIds.find((node) => {
      if (!node || typeof node.id !== 'string') return false;
      const key = canonicalModalKey(node.id);
      return !!key && canonical.has(key);
    });
    if (canonicalDom && canonicalDom.id) return canonicalDom.id;

    const forms = global.yuzForms;
    if (forms && Array.isArray(forms.modals)) {
      const exactCfg = forms.modals.find((m) => m && typeof m.id === 'string' && lower.has(m.id.toLowerCase()));
      if (exactCfg && exactCfg.id) return exactCfg.id;
      const canonicalCfg = forms.modals.find((m) => {
        if (!m || typeof m.id !== 'string') return false;
        const key = canonicalModalKey(m.id);
        return !!key && canonical.has(key);
      });
      if (canonicalCfg && canonicalCfg.id) return canonicalCfg.id;
    }

    if (global.CLAR && Array.isArray(global.CLAR.modals)) {
      const exactClar = global.CLAR.modals.find((m) => {
        const currentId = typeof m === 'string' ? m : (m && m.id);
        return typeof currentId === 'string' && lower.has(currentId.toLowerCase());
      });
      if (exactClar) return typeof exactClar === 'string' ? exactClar : exactClar.id;

      const canonicalClar = global.CLAR.modals.find((m) => {
        const currentId = typeof m === 'string' ? m : (m && m.id);
        if (typeof currentId !== 'string') return false;
        const key = canonicalModalKey(currentId);
        return !!key && canonical.has(key);
      });
      if (canonicalClar) return typeof canonicalClar === 'string' ? canonicalClar : canonicalClar.id;
    }

    return id;
  };

  const callModalApis = (action, modalId) => {
    const forms = global.yuzForms;
    const canonicalId = resolveModalId(modalId);
    const actionMap = action === 'open' ? 'openModal' : 'closeModal';
    const actionSucceeded = () => {
      const node = canonicalId ? document.getElementById(canonicalId) : null;
      if (!node) return false;
      if (action === 'open') {
        return node.classList.contains('active') || isModalVisible(node);
      }
      return !node.classList.contains('active') && !isModalVisible(node);
    };
    if (forms && typeof forms[actionMap] === 'function') {
      try {
        forms[actionMap](canonicalId);
        if (actionSucceeded()) return true;
      } catch (_) { }
    }
    if (global.CLAR) {
      const clarFn = action === 'open' ? global.CLAR.openModalsForTranslation : global.CLAR.closeModalForTranslation;
      if (typeof clarFn === 'function') {
        try {
          clarFn.call(global.CLAR, canonicalId);
          if (actionSucceeded()) return true;
        } catch (_) { }
      }
    }
    return false;
  };

  const buildBridgeFallbackHandlers = (modal) => {
    if (!modal || !modal.id) return null;
    const getModalEl = () => {
      const id = modal.id || '';
      const variants = [];
      if (id) {
        variants.push(`#${id}`);
        const lower = id.toLowerCase();
        const upper = id.toUpperCase();
        if (lower !== id) variants.push(`#${lower}`);
        if (upper !== id && upper !== lower) variants.push(`#${upper}`);
      }
      return firstSelectorMatch([
        ...variants,
        modal.selector,
        modal.meta && modal.meta.selector,
        ...(modal.meta && Array.isArray(modal.meta.selectors) ? modal.meta.selectors : [])
      ]);
    };
    const getOverlayEl = (modalEl) => {
      const overlaySel = modal.meta && (modal.meta.overlaySelector || modal.meta.backdropSelector || modal.meta.overlay);
      return firstSelectorMatch(overlaySel) || (modalEl && modalEl.closest ? modalEl.closest('.modal-overlay') : null);
    };
    const open = () => {
      if (callModalApis('open', modal.id)) return true;
      tryClickSelectors(modal.triggers);
      const el = getModalEl();
      const overlay = getOverlayEl(el);
      return toggleBridgeDom(el, overlay, true, modal.meta || {});
    };
    const close = () => {
      if (callModalApis('close', modal.id)) return true;
      tryClickSelectors(modal.closers);
      const el = getModalEl();
      const overlay = getOverlayEl(el);
      return toggleBridgeDom(el, overlay, false, modal.meta || {});
    };
    return { open, close };
  };

  const isScheduleModal = (modalId) => canonicalModalKey(modalId).indexOf('scheduleus') >= 0;

  const ensureThemeFormsLoaded = async () => {
    if (global.yuzForms && typeof global.yuzForms.loadScriptIfNeeded === 'function') return true;
    try {
      await loadStandaloneScript(
        '/wp-content/themes/twentytwentyfive-child/assets/js/yuz-forms.js',
        () => !!(global.yuzForms && typeof global.yuzForms.loadScriptIfNeeded === 'function')
      );
    } catch (_) { }
    return !!(global.yuzForms && typeof global.yuzForms.loadScriptIfNeeded === 'function');
  };

  const ensureScheduleScriptLoaded = async () => {
    if (global.scheduleUS && typeof global.scheduleUS.init === 'function') return true;
    if (!(await ensureThemeFormsLoaded())) return false;
    const forms = global.yuzForms;
    if (!forms || typeof forms.loadScriptIfNeeded !== 'function') return false;
    try {
      const options = typeof forms.getSafeScriptOptions === 'function'
        ? forms.getSafeScriptOptions('scheduleUS')
        : {};
      await forms.loadScriptIfNeeded(
        'scheduleUS',
        '/wp-content/themes/twentytwentyfive-child/assets/js/schedule-us.js',
        options
      );
    } catch (_) { }
    return !!(global.scheduleUS && typeof global.scheduleUS.init === 'function');
  };

  const ensureScheduleGenerated = async () => {
    const form = document.querySelector('#rdvForm');
    if (!form) return;
    const api = global.scheduleUS;
    if (api && typeof api.generateDateOptions === 'function') {
      form.querySelectorAll('.calendar-group').forEach((group) => {
        const dateContainer = group.querySelector('.date-selector');
        const hourContainer = group.querySelector('.hour-selector');
        if (!dateContainer || !hourContainer) return;
        if (dateContainer.querySelector('.date-label')) return;
        try { api.generateDateOptions(dateContainer, hourContainer); } catch (_) { }
      });
    }
  };

  const waitForScheduleReady = async (modalId) => {
    if (!isScheduleModal(modalId)) return;
    const isReady = () => {
      const form = document.querySelector('#rdvForm');
      if (!form) return false;
      const initialized = form.dataset && form.dataset.yuzScheduleUsInitialized === '1';
      const hasDateLabels = document.querySelectorAll('#rdvSchedule .date-label').length > 0;
      const hasScheduleText = document.querySelectorAll('#rdvSchedule .schedule-text').length > 0;
      return initialized && hasScheduleText && hasDateLabels;
    };

    if (!isReady()) {
      await ensureScheduleScriptLoaded();
    }

    if (!isReady() && global.scheduleUS && typeof global.scheduleUS.init === 'function') {
      try {
        const result = global.scheduleUS.init();
        if (result && typeof result.then === 'function') {
          await Promise.race([result, sleep(900)]);
        }
      } catch (_) { }
    }

    const deadline = Date.now() + 1800;
    while (Date.now() < deadline) {
      await ensureScheduleGenerated();
      if (isReady()) return;
      await sleep(60);
    }

    await ensureScheduleGenerated();
  };

  const detectFallbackModals = () => {
    const modals = [];
    const handlers = {};

    const toggleElement = (selectors, show) => {
      const el = selectors.map((sel) => {
        if (!sel) return null;
        if (typeof sel === 'string') return document.querySelector(sel);
        return null;
      }).find(Boolean);
      if (!el) return false;
      const overlay = el.closest('.modal-overlay');
      if (overlay) {
        overlay.classList[show ? 'add' : 'remove']('active');
      }
      el.classList[show ? 'add' : 'remove']('active');
      if (show) {
        try {
          el.style.setProperty('display', 'block', 'important');
          el.style.setProperty('opacity', '1', 'important');
          el.style.setProperty('visibility', 'visible', 'important');
          el.style.setProperty('pointer-events', 'auto', 'important');
        } catch (_) { }
      } else {
        try {
          el.style.removeProperty('display');
          el.style.removeProperty('opacity');
          el.style.removeProperty('visibility');
          el.style.removeProperty('pointer-events');
        } catch (_) { }
      }
      return true;
    };

    const register = (id, label, selectors) => {
      const sel = Array.isArray(selectors) ? selectors : [selectors];
      const exists = sel.some((s) => (typeof s === 'string' ? document.querySelector(s) : null));
      if (!exists || modals.find((m) => m.id === id)) return;
      modals.push({ id, label });
      handlers[id] = {
        open: () => toggleElement(sel, true),
        close: () => toggleElement(sel, false)
      };
    };

    // Opt-in fallback modals can be provided via settings instead of hard-coding site-specific IDs.
    const cfg =
      (global.yuzTraSettings && global.yuzTraSettings.inspector && global.yuzTraSettings.inspector.fallback_modals)
      || (global.yuzInspectorConfig && global.yuzInspectorConfig.fallbackModals)
      || [];

    if (Array.isArray(cfg)) {
      cfg.forEach((entry) => {
        if (!entry || !entry.id) return;
        register(entry.id, entry.label || entry.id, entry.selectors || entry.selector || []);
      });
    }

    // === Détection centrée formulaires (form → conteneur modal) ===
    const isUsefulForm = (form) => {
      if (!form || form.tagName !== 'FORM') return false;
      if (form.closest('#' + DOCK_ID)) return false;
      if (form.closest('#wpadminbar')) return false;
      const fields = form.querySelectorAll('input:not([type="hidden"]), textarea, select');
      return fields.length > 0;
    };

    const findModalContainer = (form) => {
      // 1) Conteneurs sémantiques
      let el = form.closest('[role="dialog"], [aria-modal="true"]');
      if (el) return el;

      // 2) Heuristique overlay
      el = form.parentElement;
      while (el && el !== document.body) {
        const cs = window.getComputedStyle(el);
        const pos = cs.position;
        const zi = parseInt(cs.zIndex || '0', 10);
        const rect = el.getBoundingClientRect();
        const covers = rect.width >= window.innerWidth * 0.3 && rect.height >= window.innerHeight * 0.3;
        const isOverlayish = (pos === 'fixed' || pos === 'absolute') && covers && (zi >= 900 || cs.pointerEvents === 'auto');
        if (isOverlayish) return el;
        el = el.parentElement;
      }
      return null;
    };

    const isFullscreenOverlay = (node) => {
      if (!node || node.nodeType !== 1) return false;
      const cs = window.getComputedStyle(node);
      if (cs.position !== 'fixed') return false;
      const r = node.getBoundingClientRect();
      const covers = r.width >= window.innerWidth * 0.8 && r.height >= window.innerHeight * 0.8;
      return covers || node.classList.contains('modal-overlay');
    };

    const isNodeVisible = (node) => {
      if (!node || node.nodeType !== 1) return false;
      const cs = window.getComputedStyle(node);
      if (cs.display === 'none' || cs.visibility === 'hidden' || parseFloat(cs.opacity || '0') === 0) return false;
      const rect = node.getBoundingClientRect();
      return rect.width > 0 && rect.height > 0;
    };

    const forceVisibility = (node, show) => {
      if (!node) return false;
      const overlay = isFullscreenOverlay(node);
      node.classList[show ? 'add' : 'remove']('active');
      if (show) {
        node.style.setProperty('display', overlay ? 'flex' : 'block', 'important');
        if (overlay) node.style.setProperty('z-index', '2147483647', 'important');
        node.style.setProperty('opacity', '1', 'important');
        node.style.setProperty('visibility', 'visible', 'important');
        node.style.setProperty('pointer-events', 'auto', 'important');
        node.removeAttribute('aria-hidden');
      } else {
        node.style.removeProperty('display');
        node.style.removeProperty('z-index');
        node.style.removeProperty('opacity');
        node.style.removeProperty('visibility');
        node.style.removeProperty('pointer-events');
        node.setAttribute('aria-hidden', 'true');
      }
      return true;
    };

    const ensureVisible = (node) => {
      if (!node) return false;
      if (isNodeVisible(node)) return true;
      return forceVisibility(node, true);
    };

    const forceModalVisibleById = (id, meta = {}) => {
      if (!id) return false;
      const canonicalId = resolveModalId(id);
      const selectors = [
        `#${canonicalId}`,
        meta.selector,
        ...(Array.isArray(meta.selectors) ? meta.selectors : [])
      ].filter(Boolean);
      const el = selectors.map((sel) => (typeof sel === 'string' ? document.querySelector(sel) : null)).find(Boolean);
      if (!el) return false;
      const overlay = el.closest ? el.closest('.modal-overlay') : null;
      const display = meta.display || 'flex';
      if (overlay && overlay !== el) {
        forceVisibility(overlay, true);
        overlay.style.setProperty('display', display, 'important');
      }
      forceVisibility(el, true);
      el.style.setProperty('display', display, 'important');
      return true;
    };

    const autoDetectFormModals = () => {
      const forms = Array.from(document.querySelectorAll('form')).filter(isUsefulForm);
      forms.forEach((form, idx) => {
        const container = findModalContainer(form);
        if (!container) return;
        const formType = form.getAttribute('data-form-type') || form.id || form.getAttribute('name') || `form-${idx}`;
        const entryId = `form:${formType}`;
        const label =
          form.getAttribute('aria-label')
          || form.querySelector('h1,h2,h3,h4,h5,h6')?.textContent?.trim()
          || formType
          || 'Form modal';
        if (modals.find((m) => m.id === entryId)) return;
        modals.push({ id: entryId, label });
        handlers[entryId] = {
          open: () => {
            if (callModalApis('open', formType)) return true;
            return ensureVisible(container);
          },
          close: () => {
            if (callModalApis('close', formType)) return true;
            return forceVisibility(container, false);
          },
          form
        };
      });
    };

    autoDetectFormModals();

    return { modals, handlers };
  };

  const readModalsFromBridge = () => {
    const list = [];
    const handlers = {};
    const isEditMode = typeof window !== 'undefined' && /[?&]yuz-edit-translation=1\b/.test(window.location.search || '');
    const body = typeof document !== 'undefined' ? document.body : null;
    const hasBodyClass = (cls) => (body && body.classList ? body.classList.contains(cls) : false);
    const hasId = (id) => {
      if (!id) return false;
      const needle = String(id).toLowerCase();
      return !!list.find((m) => typeof m.id === 'string' && m.id.toLowerCase() === needle);
    };
    const addModal = (modal) => {
      if (!modal || !modal.id || hasId(modal.id)) return;
      list.push(modal);
      const h = buildBridgeFallbackHandlers(modal);
      if (h) {
        handlers[modal.id] = h;
      }
    };
    const bridge = global.yuzModalBridge;
    if (bridge && Array.isArray(bridge.modals)) {
      bridge.modals.forEach(addModal);
    }
    const forms = global.yuzForms;
    if (forms && Array.isArray(forms.modals)) {
      forms.modals.forEach((cfg) => {
        if (!cfg || !cfg.id) return;
        addModal({
          id: cfg.id,
          label: cfg.type || cfg.label || cfg.id,
          triggers: cfg.trigger ? [cfg.trigger] : [],
          closers: cfg.close ? [cfg.close] : [],
          meta: {
            selector: cfg.selector || null,
            overlaySelector: cfg.overlaySelector || null,
            display: cfg.display || 'flex'
          }
        });
      });
    }
    if (global.CLAR && Array.isArray(global.CLAR.modals)) {
      global.CLAR.modals.forEach(addModal);
    }

    // Safety net: when in edit mode on clar-home, ensure home modals are registered even if yuzForms not loaded.
    if (isEditMode && hasBodyClass('clar-home')) {
      [
        {
          id: 'inscriptionModal',
          label: 'Quick Signup',
          triggers: ['#openQuickSignup'],
          closers: ['.modal-close'],
          meta: { display: 'flex' }
        },
        {
          id: 'quickDetailsModal',
          label: 'Quick Details',
          triggers: ['#openQuickDetails'],
          closers: ['#closeQuickDetails', '.modal-close'],
          meta: { display: 'flex' }
        }
      ].forEach(addModal);
    }

    const fallback = detectFallbackModals();
    fallback.modals.forEach(addModal);
    Object.keys(fallback.handlers || {}).forEach((id) => {
      handlers[id] = fallback.handlers[id];
    });
    return { list, handlers };
  };

  function create(app) {
    if (!app) throw new Error('Inspector requires an application instance.');

    // Optional probe hook (ex: window.__YUZ_INSPECTOR_PROBE = (evt, data)=>console.log(evt,data))
    const probe = (event, detail = {}) => {
      try {
        const tap = window.__YUZ_INSPECTOR_PROBE || window.YUZ_INSPECTOR_PROBE;
        if (typeof tap === 'function') tap(event, detail);
      } catch (_) { /* silent */ }
    };

    const state = {
      app,
      active: false,
      dock: null,
      controls: null,
      list: null,
      modalSelect: null,
      modals: [],
      modalHandlers: {},
      selectedModal: '',
      unmapped: [],
      missing: [],
      listeners: [],
      lastScanAt: 0,
      controlsBound: false,
      filterInput: null,
      filterQuery: '',
      bound: {}
    };

    const bind = (target, event, handler, options) => {
      if (!target || typeof target.addEventListener !== 'function') return;
      target.addEventListener(event, handler, options || false);
      state.listeners.push([target, event, handler, options || false]);
    };

    const unbindAll = () => {
      while (state.listeners.length) {
        const [target, event, handler, options] = state.listeners.pop();
        try { target.removeEventListener(event, handler, options); } catch (_) { }
      }
    };

    const refreshScan = async (reason) => {
      if (!state.app || typeof state.app.runInspectorScan !== 'function') return;
      const now = Date.now();
      if (reason !== 'manual-scan' && now - state.lastScanAt < 200) return;
      state.lastScanAt = now;
      const selected = state.modals.find((modal) => modal && modal.id === state.selectedModal) || null;
      try {
        await waitForScheduleReady(state.selectedModal || (selected && selected.id) || '');
      } catch (_) { }
      try {
        state.app.runInspectorScan({
          reason,
          selectedModal: state.selectedModal || '',
          selectedModalLabel: selected && selected.label ? selected.label : ''
        });
      } catch (_) { }
    };

    const ensureTriggerBindings = () => {
      if (!Array.isArray(state.modals) || !state.modals.length) return;
      const seen = state.bound || {};
      const mark = (key) => {
        if (seen[key]) return false;
        seen[key] = true;
        return true;
      };
      const register = (selectors, action, modalId, fn) => {
        const arr = Array.isArray(selectors) ? selectors : [selectors];
        arr.forEach((sel) => {
          if (!sel || typeof sel !== 'string') return;
          const key = `${action}:${modalId}:${sel}`;
          if (!mark(key)) return;
          const nodes = document.querySelectorAll(sel);
          nodes.forEach((node) => {
            bind(node, 'click', (evt) => {
              try { evt.preventDefault(); } catch (_) { }
              try { fn(); } catch (_) { }
            });
          });
        });
      };

      state.modals.forEach((modal) => {
        if (!modal || !modal.id) return;
        const handler = state.modalHandlers[modal.id];
        const openFn = async () => {
          if (handler && typeof handler.open === 'function') {
            try {
              const result = handler.open();
              if (result && typeof result.then === 'function') await result;
            } catch (_) { }
          } else {
            try {
              const result = callModalApis('open', modal.id);
              if (result && typeof result.then === 'function') await result;
            } catch (_) { }
          }
          refreshScan('modal-open');
        };
        const closeFn = () => {
          if (handler && typeof handler.close === 'function') {
            try { handler.close(); } catch (_) { }
          } else {
            callModalApis('close', modal.id);
          }
          refreshScan('modal-close');
        };
        const triggers = modal.triggers || (modal.meta && modal.meta.triggers) || [];
        const closers = modal.closers || (modal.meta && modal.meta.closers) || [];
        register(triggers, 'open', modal.id, openFn);
        register(closers, 'close', modal.id, closeFn);
      });

      state.bound = seen;
    };

    const addAllCandidates = () => {
      if (!state.unmapped.length) return;
      state.unmapped.forEach((entry) => {
        state.app.createAdhocFromCandidate(entry.id, entry);
      });
      refreshScan('bulk-add');
    };

    const openSelectedModal = async () => {
      const id = state.selectedModal;
      const forms = global.yuzForms;
      const handler = id ? state.modalHandlers[id] : null;
      try { console.info('[YUZ][Inspector] open request', id || '(unset)'); } catch (_) { }
      if (!id) {
        try { console.warn('[YUZ][Inspector] no modal selected'); } catch (_) { }
        return;
      }
      if (id === ALL_SCOPE) {
        for (const modal of state.modals) {
          const h = state.modalHandlers[modal.id];
          if (h && typeof h.open === 'function') {
            try {
              const result = h.open();
              if (result && typeof result.then === 'function') await result;
            } catch (_) { }
            continue;
          }
          if (forms && typeof forms.openModal === 'function') {
            try { await forms.openModal(modal.id); } catch (_) { }
            continue;
          }
          if (global.CLAR && typeof global.CLAR.openModalsForTranslation === 'function') {
            try { global.CLAR.openModalsForTranslation(modal.id); } catch (_) { }
          }
        }
        refreshScan('modal-open');
        return;
      }
      if (handler && typeof handler.open === 'function') {
        try {
          const result = handler.open();
          if (result && typeof result.then === 'function') await result;
        } catch (_) { }
        refreshScan('modal-open');
        return;
      }
      if (forms && typeof forms.openModal === 'function') {
        try { await forms.openModal(id); } catch (_) { }
      } else if (global.CLAR && typeof global.CLAR.openModalsForTranslation === 'function') {
        global.CLAR.openModalsForTranslation(id);
      }
      const meta = (state.modals.find((m) => m && m.id === id) || {}).meta || {};
      forceModalVisibleById(id, meta);
      refreshScan('modal-open');
    };

    const closeSelectedModal = () => {
      const id = state.selectedModal;
      const forms = global.yuzForms;
      if (!id) {
        return;
      }
      try { console.info('[YUZ][Inspector] close request', id); } catch (_) { }
      const closeOne = (modalId) => {
        if (!modalId) return;
        const handler = state.modalHandlers[modalId];
        if (handler && typeof handler.close === 'function') {
          try { handler.close(); } catch (_) { }
          return;
        }
        if (forms && typeof forms.closeModal === 'function') {
          try { forms.closeModal(modalId); } catch (_) { }
        } else if (global.CLAR && typeof global.CLAR.closeModalForTranslation === 'function') {
          try { global.CLAR.closeModalForTranslation(modalId); } catch (_) { }
        } else {
          const el = document.getElementById(modalId);
          if (el) {
            el.classList.remove('active');
            el.style.removeProperty('pointer-events');
            el.style.removeProperty('opacity');
            el.style.removeProperty('visibility');
            el.style.removeProperty('display');
          }
        }
      };
      if (id === ALL_SCOPE) {
        state.modals.forEach((modal) => closeOne(modal.id));
      } else {
        closeOne(id);
      }
      refreshScan('modal-close');
    };

    const handlers = {
      controlsClick: (evt) => {
        const btn = evt.target.closest('[data-action]');
        if (!btn) return;
        const action = btn.dataset.action;
        if (action === 'open-modal') {
          evt.preventDefault();
          openSelectedModal();
          return;
        }
        if (action === 'close-modal') {
          evt.preventDefault();
          closeSelectedModal();
          return;
        }
        if (action === 'close-inspector') {
          evt.preventDefault();
          if (state.app && typeof state.app.disableInspector === 'function') {
            try { state.app.disableInspector(); } catch (_) { }
          } else if (state.dock) {
            state.dock.classList.remove('is-visible');
            document.body.classList.remove(BODY_CLASS);
          }
          return;
        }
        if (action === 'scan') {
          evt.preventDefault();
          refreshScan('manual-scan');
          return;
        }
        if (action === 'add-all') {
          evt.preventDefault();
          addAllCandidates();
        }
      },
      modalChange: (evt) => {
        const value = evt.target.value || '';
        state.selectedModal = value;
      },
      filterInput: (evt) => {
        state.filterQuery = String((evt && evt.target && evt.target.value) || '').trim();
        render();
      },
      listClick: (evt) => {
        const button = evt.target.closest('[data-action]');
        if (!button) return;
        const wrap = button.closest('[data-id]');
        const id = wrap && wrap.dataset.id;
        if (!id) return;
        evt.preventDefault();
        if (button.dataset.action === 'highlight') {
          state.app.focusDiagnostic({ id });
          return;
        }
        if (button.dataset.action === 'add') {
          const entry = state.unmapped.find((item) => item && item.id === id);
          state.app.createAdhocFromCandidate(id, entry);
          refreshScan('candidate-add');
        }
      }
    };

    const ensureDock = () => {
      if (state.dock) return;
      const dock = document.createElement('div');
      dock.id = DOCK_ID;
      dock.className = 'yuz-inspector-dock';
      dock.setAttribute('data-yuz', 'no-translate');
      dock.innerHTML = `
        <div class="yuz-inspector-dock__head" style="display:flex;align-items:center;gap:10px;">
          <h3 class="yuz-inspector-dock__title" style="margin:0;">Inspector</h3>
          <button type="button" class="yuz-btn yuz-btn--danger yuz-inspector-dock__close" data-action="close-inspector" style="margin-left:auto;">Close inspector</button>
        </div>
        <div class="yuz-inspector-dock__controls" data-role="controls">
          <select data-role="modal-select" class="yuz-inspector-dock__select"></select>
          <div class="yuz-inspector-dock__quick-actions" data-role="quick-actions">
            <button type="button" class="yuz-btn yuz-btn--ghost" data-action="open-modal">Open</button>
            <button type="button" class="yuz-btn yuz-btn--ghost" data-action="close-modal">Close</button>
            <button type="button" class="yuz-btn yuz-btn--ghost" data-action="scan">Scan</button>
          </div>
          <input type="search" class="yuz-inspector-dock__filter" data-role="filter" placeholder="Filter captures..." autocomplete="off" spellcheck="false" />
          <button type="button" class="yuz-btn yuz-btn--primary" data-action="add-all">Add all</button>
        </div>
        <ul class="yuz-inspector-dock__list" data-role="list"></ul>
      `;
      document.body.appendChild(dock);
      state.dock = dock;
      state.controls = dock.querySelector('[data-role="controls"]');
      state.modalSelect = dock.querySelector('[data-role="modal-select"]');
      state.filterInput = dock.querySelector('[data-role="filter"]');
      state.list = dock.querySelector('[data-role="list"]');
    };

    const ensureDockListeners = () => {
      if (!state.controls || state.controlsBound) return;
      bind(state.dock, 'click', handlers.controlsClick);
      bind(state.modalSelect, 'change', handlers.modalChange);
      bind(state.filterInput, 'input', handlers.filterInput);
      bind(state.list, 'click', handlers.listClick);
      state.controlsBound = true;
    };

    const syncModals = () => {
      const { list, handlers } = readModalsFromBridge();
      state.modals = list;
      state.modalHandlers = handlers || {};
      if (state.selectedModal && !list.find((modal) => modal.id === state.selectedModal)) {
        state.selectedModal = '';
      }
      if (!state.selectedModal && list.length) {
        state.selectedModal = list[0].id;
      }
      ensureTriggerBindings();
      render();
    };

    const render = () => {
      if (!state.dock) return;
      // Update select
      if (state.modalSelect) {
        const frag = document.createDocumentFragment();
        const optPlaceholder = document.createElement('option');
        optPlaceholder.value = '';
        optPlaceholder.textContent = 'Select a modal…';
        optPlaceholder.disabled = true;
        optPlaceholder.selected = !state.selectedModal;
        frag.appendChild(optPlaceholder);

        const optAll = document.createElement('option');
        optAll.value = ALL_SCOPE;
        optAll.textContent = 'All modals';
        frag.appendChild(optAll);
        state.modals.forEach((modal) => {
          const opt = document.createElement('option');
          opt.value = modal.id;
          opt.textContent = modal.label || modal.id;
          frag.appendChild(opt);
        });
        state.modalSelect.innerHTML = '';
        state.modalSelect.appendChild(frag);
        state.modalSelect.value = state.selectedModal || '';
        if (!state.selectedModal) {
          state.modalSelect.selectedIndex = 0;
        }
      }

      if (!state.list) return;
      state.list.innerHTML = '';
      const query = String(state.filterQuery || '').trim().toLowerCase();
      const filtered = !query
        ? state.unmapped
        : state.unmapped.filter((entry) => {
          const meta = entry && entry.meta ? entry.meta : {};
          const haystack = [
            entry && entry.snippet,
            entry && entry.text,
            entry && entry.path,
            meta.attribute,
            meta.type,
            meta.descriptor,
            meta.blockId
          ].filter(Boolean).join(' ').toLowerCase();
          return haystack.includes(query);
        });
      if (!state.unmapped.length) {
        const empty = document.createElement('li');
        empty.className = 'yuz-inspector-dock__item';
        empty.textContent = state.active
          ? (state.selectedModal ? 'No unmapped DOM text detected. Open a modal and scan.' : 'Select a modal, open it, then run a scan.')
          : 'Inspector inactive.';
        state.list.appendChild(empty);
        return;
      }

      if (!filtered.length) {
        const empty = document.createElement('li');
        empty.className = 'yuz-inspector-dock__item';
        empty.textContent = 'No captures match this filter.';
        state.list.appendChild(empty);
        return;
      }

      filtered.forEach((entry) => {
        const li = document.createElement('li');
        li.className = 'yuz-inspector-dock__item';
        li.dataset.id = entry.id;
        const text = document.createElement('div');
        text.textContent = entry.snippet || entry.text || '';
        const meta = document.createElement('div');
        meta.className = 'yuz-inspector-dock__meta';
        const metaBits = [];
        if (entry.path) metaBits.push(entry.path);
        if (entry.meta && entry.meta.attribute) {
          metaBits.push(`@${entry.meta.attribute}`);
        } else if (entry.meta && entry.meta.type) {
          metaBits.push(entry.meta.type);
        }
        meta.textContent = metaBits.join(' • ');
        const actions = document.createElement('div');
        actions.className = 'yuz-inspector-dock__actions';
        actions.innerHTML = `
          <button type="button" class="yuz-btn yuz-btn--ghost" data-action="highlight">Highlight</button>
          <button type="button" class="yuz-btn yuz-btn--accent" data-action="add">Add</button>
        `;
        li.appendChild(text);
        li.appendChild(meta);
        li.appendChild(actions);
        state.list.appendChild(li);
      });
    };

    const attachBridgeListeners = () => {
      const onRegistered = () => syncModals();
      const onReady = () => syncModals();
      const onModalEvent = () => refreshScan('modal-event');

      bind(document, 'yuz:modals:registered', onRegistered);
      bind(document, 'yuz:modals:ready', onReady);
      bind(document, 'yuz:modals:bridge-ready', onReady);
      bind(document, 'yuz:modal:open', onModalEvent);
      bind(document, 'yuz:modal:close', onModalEvent);
      bind(document, 'yuz:forms:auto-opened', onModalEvent);
    };

    return {
      enable() {
        if (state.active) return;
        ensureDock();
        ensureDockListeners();
        syncModals();
        attachBridgeListeners();
        state.active = true;
        if (state.dock) state.dock.classList.add('is-visible');
        try { document.body.classList.add(BODY_CLASS); } catch (_) { }
        if (global.CLAR && typeof global.CLAR.setModalTranslationMode === 'function') {
          try { global.CLAR.setModalTranslationMode(true); } catch (_) { }
        }
        probe('enable', { selectedModal: state.selectedModal, modals: state.modals.length });
        render();
        if (state.selectedModal) {
          refreshScan('inspector-on');
        }
      },

      disable() {
        if (!state.active) return;
        state.active = false;
        unbindAll();
        state.controlsBound = false;
        if (state.dock) state.dock.classList.remove('is-visible');
        try { document.body.classList.remove(BODY_CLASS); } catch (_) { }
        if (global.CLAR && typeof global.CLAR.setModalTranslationMode === 'function') {
          try { global.CLAR.setModalTranslationMode(false); } catch (_) { }
        }
        state.unmapped = [];
        state.selectedModal = '';
        state.modalHandlers = {};
        state.filterQuery = '';
        if (state.filterInput) state.filterInput.value = '';
        render();
        probe('disable', {});
      },

      update(payload = {}) {
        state.unmapped = Array.isArray(payload.unmapped) ? payload.unmapped : [];
        state.missing = Array.isArray(payload.missing) ? payload.missing : [];
        probe('update', {
          reason: payload.reason || '',
          unmapped: state.unmapped.length,
          missing: state.missing.length,
          selectedModal: state.selectedModal
        });
        render();
      },

      destroy() {
        this.disable();
        if (state.dock && state.dock.parentNode) {
          state.dock.parentNode.removeChild(state.dock);
        }
        state.dock = null;
        state.list = null;
        state.controls = null;
        state.modalSelect = null;
        state.filterInput = null;
      }
    };
  }

  global.YUZInspector = { create };
})(window);

(function () {
  const CFG = window.yuzTraSettings || {};
  const q = new URLSearchParams(location.search);

  const MODE_ALIASES = {
    advanced: 'advanced',
    full: 'advanced',
    essential: 'essential',
    compact: 'essential',
    xpress: 'xpress',
    mini: 'xpress',
    'xpress-progress': 'xpress-progress',
    'mini-progress': 'xpress-progress'
  };

  const canonicalMode = (mode) => {
    const key = (mode || '').toString().toLowerCase();
    return MODE_ALIASES[key] || 'advanced';
  };

  const legacyFor = (mode) => {
    if (mode === 'essential') return 'compact';
    if (mode === 'xpress') return 'mini';
    if (mode === 'xpress-progress') return 'mini-progress';
    return 'full';
  };

  const emitMode = (mode) => {
    const canonical = canonicalMode(mode);
    const legacy = legacyFor(canonical);
    const datasetMode = canonical === 'xpress-progress' ? 'xpress' : canonical;
    try { document.documentElement.dataset.yuzUi = datasetMode; }
    catch (_) {}
    document.dispatchEvent(new CustomEvent('yuz:ui:mount', { detail: { mode: canonical, legacyMode: legacy } }));
    try { console.log('[YUZ-CTRL] mode=', canonical, '(legacy:', legacy + ')'); } catch (_) {}
  };

  const urlMode = q.get('yuz-mode');
  const lsMode  = localStorage.getItem('yuz.ui.mode');
  const chosen  = canonicalMode(urlMode || lsMode || 'advanced');

  if (window.__YUZ_UI_ACTIVE__) return;
  window.__YUZ_UI_ACTIVE__ = { id: chosen, unmount: null };

  window.YUZ_UI = {
    getMode() { return window.__YUZ_UI_ACTIVE__?.id || null; },
    switchTo(next) {
      const canonical = canonicalMode(next);
      if (canonical === window.__YUZ_UI_ACTIVE__?.id) return;
      try { window.__YUZ_UI_ACTIVE__?.unmount?.(); } catch (e) {}
      window.__YUZ_UI_ACTIVE__ = { id: canonical, unmount: null };
      localStorage.setItem('yuz.ui.mode', canonical);
      emitMode(canonical);
    }
  };

  const dispatchMount = () => emitMode(chosen);

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', dispatchMount, { once: true });
  } else {
    dispatchMount();
  }
})();


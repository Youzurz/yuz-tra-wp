
/**
 * YUZ Guard — shared sanitization and translation metadata helpers.
 * The goal is to keep only real human-facing strings and map them to DB IDs.
 */
(function (w) {
  if (typeof w === 'undefined') {
    return;
  }

  const root = w.YUZTR = w.YUZTR || {};
  const MAX_LEN = 5000;
  const BLOCK_RE = /[\{\}\#\<\>]/;
  const store = root.__map || new Map();
  root.__map = store;

  /**
   * Returns a sanitized string or null when the value should be ignored.
   */
  root.safeString = function (txt) {
    if (typeof txt !== 'string') {
      return null;
    }
    const trimmed = txt.trim();
    if (!trimmed) {
      return null;
    }
    if (trimmed.length > MAX_LEN) {
      return null;
    }
    if (BLOCK_RE.test(trimmed) && trimmed.split(/\s+/).length > 12) {
      return null;
    }
    return trimmed;
  };

  /**
   * Registers translation metadata so other modules can lookup the DB id.
   */
  root.registerTranslation = function (original, payload) {
    const safe = root.safeString(original);
    if (!safe || !payload) {
      return null;
    }
    const idCandidate = payload.translation_id ?? payload.id ?? payload.translationId;
    const id = Number(idCandidate);
    if (!Number.isInteger(id) || id <= 0) {
      return null;
    }
    const record = {
      id,
      original: safe,
      translated: typeof payload.translated === 'string' ? payload.translated : '',
      status: payload.status ?? null
    };
    store.set(safe, record);
    return record;
  };

  /**
   * Returns the stored record for a given sanitized string.
   */
  root.lookup = function (original) {
    const safe = root.safeString(original);
    if (!safe) {
      return null;
    }
    return store.get(safe) || null;
  };

  /**
   * Helper to clear cached metadata (mainly used by tests).
   */
  root.reset = function () {
    store.clear();
  };
})(window);

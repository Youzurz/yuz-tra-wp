/**
 * assets/js/yuz-translation-service.js
 *
 * Helper AJAX pour YUZ (WordPress admin-ajax, x-www-form-urlencoded).
 *
 * Politique: UMD/vanilla uniquement (aucun import/export ESM).
 * - Pas de dépendance CDN ni de type="module".
 * - Expose une API globale window.YUZ_TranslationService (compat WP classique).
 * - Retries/backoff + timeout via AbortController.
 */

/* ---------------------------------- Defaults -------------------------------- */

const DEFAULT_API_CONFIG = Object.freeze({ RATE_LIMIT: 20, CACHE_TTL: 30_000 });
const DEFAULT_AJAX_OPTIONS_FOR = (_action) => ({ timeout: 12_000 });
const DEFAULT_NONCE_FIELDS = (_action) => ({ nonce: '', yuz_tra_nonce: '', _ajax_nonce: '' });
const DEFAULT_TRANSLATE_NOW = (..._args) => {
  throw new Error('[YUZ] translateNow indisponible (charge d’abord yuz-translation-service.js)');
};

// Configuration locale (UMD only)
const API_CFG                = DEFAULT_API_CONFIG;
const ajaxOptionsForEffective = DEFAULT_AJAX_OPTIONS_FOR;
const nonceFieldsLocal        = (action) => {
  const matrix = (typeof window !== 'undefined' && window.yuzNonceMatrix) || {};
  const bucket = matrix[action] || 'yuz_tra_nonce';
  const n = getNonce(bucket);
  return { nonce: n, yuz_tra_nonce: n, _ajax_nonce: n };
};
const nonceFieldsEffective   = nonceFieldsLocal;
let translateNowEffective  = DEFAULT_TRANSLATE_NOW;

/* ---------------------------------- Utils ----------------------------------- */

/**
 * Convertit un objet en URLSearchParams avec sérialisation JSON pour objets/arrays.
 * @param {Record<string, any>} obj
 * @returns {URLSearchParams}
 */
function toFormParams(obj) {
  const params = new URLSearchParams();
  if (!obj || typeof obj !== 'object') return params;
  Object.keys(obj).forEach((key) => {
    const val = obj[key];
    if (val === undefined || val === null) return;
    const isPOJO = typeof val === 'object';
    params.append(key, isPOJO ? JSON.stringify(val) : String(val));
  });
  return params;
}

/**
 * fetch avec timeout via AbortController.
 * @param {RequestInfo} input
 * @param {RequestInit & { timeout?: number }} init
 */
async function fetchWithTimeout(input, init = {}) {
  const controller = new AbortController();
  const { timeout, ...rest } = init;
  const t = typeof timeout === 'number' && timeout > 0
    ? setTimeout(() => controller.abort(), timeout)
    : null;

  try {
    const res = await fetch(input, { ...rest, signal: controller.signal });
    return res;
  } finally {
    if (t) clearTimeout(t);
  }
}

/**
 * Détermine si un code HTTP est « temporaire » et mérite un retry.
 * @param {number} status
 */
function isTransientStatus(status) {
  return status === 408 || status === 429 || status === 500 || status === 502 || status === 503 || status === 504;
}

/* -------------------------------- Transport --------------------------------- */

/**
 * Appelle l’endpoint WP admin-ajax avec body x-www-form-urlencoded.
 * NE LIT PAS de globals (endpoint/nonce/data fournis par l’appelant).
 * @param {{ endpoint:string, action:string, nonce:string, data?:Record<string,any>, timeout?:number, retries?:number, backoffMs?:number }} opts
 * @returns {Promise<any>} Réponse JSON WordPress (objet tel quel)
 * @throws {Error} si échec réseau définitif ou HTTP non récupérable
 */
async function apiRequest(opts) {
  const {
    endpoint,
    action,
    nonce,
    data = {},
    timeout = 12_000,
    retries = 2,
    backoffMs = 500
  } = opts || {};

  if (!endpoint || typeof endpoint !== 'string') {
    throw new Error('[yuz-translation-service] "endpoint" requis (string).');
  }
  if (!action || typeof action !== 'string') {
    throw new Error('[yuz-translation-service] "action" requis (string).');
  }
  if (!nonce || typeof nonce !== 'string') {
    // On force la présence d’un nonce: sécurité côté serveur.
    throw new Error('[yuz-translation-service] "nonce" requis (string).');
  }

  const body = toFormParams({ action, nonce, ...data });

  let attempt = 0;
  let lastErr = null;

  while (attempt <= retries) {
    try {
      const res = await fetchWithTimeout(endpoint, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
        body,
        timeout
      });

      if (!res.ok) {
        if (isTransientStatus(res.status) && attempt < retries) {
          await new Promise(r => setTimeout(r, backoffMs * Math.pow(2, attempt)));
          attempt++;
          continue;
        }
        const text = await res.text().catch(() => '');
        const err = new Error(`HTTP ${res.status} ${res.statusText}`);
        // @ts-ignore
        err.status = res.status;
        // @ts-ignore
        err.responseText = text;
        throw err;
      }

      const json = await res.json().catch(() => ({}));
      return json;

    } catch (e) {
      lastErr = e;
      const status = e?.status;
      const transient = status ? isTransientStatus(status) : true; // sans status → considéré transitoire
      if (attempt < retries && transient) {
        await new Promise(r => setTimeout(r, backoffMs * Math.pow(2, attempt)));
        attempt++;
        continue;
      }
      throw e;
    }
  }

  if (lastErr) throw lastErr;
  throw new Error('Échec de la requête AJAX (inconnu).');
}

/**
 * Fabrique un client préconfiguré (endpoint/timeout/retries/backoff).
 * @param {{ endpoint:string, timeout?:number, retries?:number, backoffMs?:number }} options
 */
function makeClient(options) {
  const {
    endpoint,
    timeout = 12_000,
    retries = 2,
    backoffMs = 500
  } = options || {};

  if (!endpoint || typeof endpoint !== 'string') {
    throw new Error('[yuz-translation-service] "endpoint" requis pour makeClient().');
  }

  return {
    /**
     * Effectue une requête avec les paramètres par défaut du client.
     * @param {{ action: string, nonce: string, data?: Record<string, any>, timeout?: number, retries?: number, backoffMs?: number }} req
     */
    request(req) {
      return apiRequest({
        endpoint,
        action: req.action,
        nonce: req.nonce,
        data: req.data || {},
        timeout: req.timeout ?? timeout,
        retries: req.retries ?? retries,
        backoffMs: req.backoffMs ?? backoffMs
      });
    },
    get endpoint() { return endpoint; }
  };
}

/* ------------------- Commodités (centralisation des nonces) ------------------ */

/**
 * Convenience: démarre une traduction via l’API WP.
 * - Centralise l’envoi de nonce + alias (_ajax_nonce, yuz_tra_nonce)
 * - Utilise l’endpoint global si non fourni.
 *
 * @param {{ action: string, page_url: string, target_langs: string[]|string, text?: string,
 *           endpoint?: string, timeout?: number, retries?: number, backoffMs?: number }} opts
 * @returns {Promise<any>} Réponse JSON WordPress
 */
async function startTranslation(opts = {}) {
  const {
    action,
    page_url,
    target_langs,
    text,
    endpoint,
    timeout,
    retries,
    backoffMs
  } = opts;

  const required = { action, page_url, target_langs, text };
  for (const [key, value] of Object.entries(required)) {
    if (value === undefined || value === null || (Array.isArray(value) && value.length === 0)) {
      // eslint-disable-next-line no-console
      console.error(`Paramètre ${key} manquant`, required);
      throw new Error(`Paramètre ${key} manquant`);
    }
  }

  // Endpoint: paramètre explicite > global WP > fallback standard
  const ep =
    endpoint
    || (typeof window !== 'undefined' && window.yuzTraSettings && window.yuzTraSettings.ajax_url)
    || '/wp-admin/admin-ajax.php';

  // Nonces alignés (via shim si dispo, sinon fallback local)
  const nf = nonceFieldsEffective(action);
  const mainNonce = nf.nonce; // apiRequest exige 'nonce'
  const { nonce: _drop, ...nonceAliases } = nf; // alias envoyés dans data

  return apiRequest({
    endpoint: ep,
    action,
    nonce: mainNonce,
    data: {
      ...nonceAliases,
      page_url,
      target_langs,
      text
    },
    timeout,
    retries,
    backoffMs
  });
}

/* -------------------------- Exposition globale (UMD) ------------------------- */

// Expose en global pour les scripts non-ESM
(() => {
  const G = (typeof window !== 'undefined' ? (window.YUZ_TranslationService = window.YUZ_TranslationService || {}) : {});
  G.API_CONFIG      = API_CFG;
  G.ajaxOptionsFor  = ajaxOptionsForEffective;
  G.nonceFields     = nonceFieldsEffective;      // pour cohérence avec l’ESM shim
  G.translateNow    = translateNowEffective;
  G.apiRequest      = apiRequest;
  G.makeClient      = makeClient;
  G.startTranslation= startTranslation;
})();
// Aucun export: fichier classique non-module

// Fournit une implémentation translateNow par défaut basée sur startTranslation
// pour compatibilité immédiate avec l’Admin Bar (pas d’ESM requis).
try {
  translateNowEffective = async function ({ page_url, target_langs, text }) {
    const action  = 'yuz_start_translation';
    const endpoint = (typeof window !== 'undefined' && window.yuzTraSettings && window.yuzTraSettings.ajax_url) || '/wp-admin/admin-ajax.php';
    return startTranslation({ action, page_url, target_langs, text, endpoint });
  };
  if (typeof window !== 'undefined') {
    window.translateNow = translateNowEffective;
    if (window.YUZ_TranslationService) {
      window.YUZ_TranslationService.translateNow = translateNowEffective;
    }
  }
} catch (_) {}
function getNonce(bucket) {
  if (typeof window === 'undefined') return '';
  const key = bucket || 'yuz_tra_nonce';
  if (typeof window.yuzGetNonce === 'function') {
    return window.yuzGetNonce(key) || '';
  }
  const store = window.yuzNonce || {};
  return store[key] || '';
}

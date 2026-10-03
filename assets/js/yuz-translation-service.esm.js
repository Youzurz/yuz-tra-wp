/**
 * assets/js/yuz-translation-service.esm.js
 * Shim ESM : ré-exporte ce que la version UMD met sur window.YUZ_TranslationService
 * → zéro dépendance directe, pas d’imports ESM qui se chevauchent.
 */

/* global window */
/**
 * TRANSVERSE CONTRACT (T-01):
 *   This file MUST read ONLY window.yuztraSettings { ajax_url, nonces }.
 *   It MUST NOT read any UI payload (yuztraGS, yuztraTS, yuztraTE, …).
 * Guard: hard fail with actionable error if contract is not satisfied.
 */
/* eslint-disable no-console */
(function (w) {
  var s = w && w.yuztraSettings;
  if (!s || typeof s.ajax_url !== 'string' || !s.nonces) {
    console.error('[YUZ-TRA][TRANSVERSE] yuztraSettings missing or invalid. ' +
      'class-yuz-assets must inject the minimal config BEFORE this script. ' +
      'Expected shape: {ajax_url:string, nonces:object}');
    return; // hard stop: prevents undefined access later
  }
})(window);
const S = window.YUZTRA_TranslationService || {};

export const API_CONFIG     = S.API_CONFIG     || Object.freeze({ RATE_LIMIT: 20, CACHE_TTL: 30000 });
export const ajaxOptionsFor = S.ajaxOptionsFor || ((_action) => ({ timeout: 12000 }));
export const nonceFields    = S.nonceFields    || ((_action) => ({ nonce: '', yuztra_nonce: '', _ajax_nonce: '' }));
export const translateNow   = S.translateNow   || ((..._args) => {
  throw new Error('[YUZ] translateNow indisponible (charger yuz-translation-service.js avant)');
});

// exports facultatifs si exposés côté global (sinon undefined — OK)
export const apiRequest       = S.apiRequest;
export const startTranslation = S.startTranslation;

// export par défaut pratique
export default { API_CONFIG, ajaxOptionsFor, nonceFields, translateNow, apiRequest, startTranslation };


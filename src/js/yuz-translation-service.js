// src/js/yuz-translation-service.js
// Shim minimal : fournit les exports nommés attendus par ton éditeur.
//
// S'appuie si présent sur un service global déjà chargé (window.YUZTranslationService)
// et sur les settings localisés (yuztraSettings / yuztraTE / yuztraTS).

const g = typeof window !== 'undefined' ? window : {};
const globalSvc =
  g.YUZTranslationService ||
  g.yuztraTranslationService ||
  {};

// Préfère l’objet localisé le plus riche disponible
const S = g.yuztraSettings || g.yuztraTE || g.yuztraTS || {};

// URL AJAX (fallback sur ajaxurl de WP)
export const API_CONFIG = {
  ajaxUrl: S.ajax_url || g.ajaxurl || (() => { throw new Error('YUZ-TRA: AJAX endpoint not configured'); })(),
};

// Pool de nonces connus (avec fallback générique)
const NONCES = (S.nonces || {});
const DEFAULT_NONCE =
  NONCES.yuztra_nonce ||
  S.nonce ||
  '';

// Rend un set de champs nonce compatibles WP (on met les deux clés possibles)
export function nonceFields(action) {
  const val =
    (action && NONCES[action]) ? NONCES[action] :
    DEFAULT_NONCE;
  return { _ajax_nonce: val, nonce: val };
}

// Fabrique un objet d’options compatible jQuery.ajax / axios (méthode, url, data)
export function ajaxOptionsFor(action, data = {}) {
  const payload = { action, ...data, ...nonceFields(action) };
  return {
    method: 'POST',
    url: API_CONFIG.ajaxUrl,
    data: payload,
  };
}

// Si un vrai service global existe, on lui laisse la priorité pour les méthodes
const svc = {
  API_CONFIG,
  ajaxOptionsFor: globalSvc.ajaxOptionsFor || ajaxOptionsFor,
  nonceFields:    globalSvc.nonceFields    || nonceFields,
};

export default svc;

/**
 * yuz-global.js
 * Tracks the last opened page for YUZ Translation.
 */
(function(window, document, undefined) {
    'use strict';

    // Vérification de yuztraSettings en mode DEV uniquement
    (function(w) {
        var dev = !!w.YUZTRA_ASSETS_DEBUG || (w.yuztraDebug && w.yuztraDebug.dev);
        if (dev && typeof w.yuztraSettings === 'undefined') {
            console.warn('[YUZ-DEBUG] yuztraSettings absent (DEV only)');
        }
    })(window);

    const now = new Date().toISOString();
    console.log(`[YUZ][JS][GLOBAL][PLAN] Planning last page tracking at ${now}`);
    
    document.addEventListener('DOMContentLoaded', function() {
        console.log(`[YUZ][JS][GLOBAL][DO] Tracking last page at ${now}`);
        try {
            localStorage.setItem('yuztra_last_page', window.location.href);
            console.log('[YUZ][JS][GLOBAL][CHECK] Last page tracked:', window.location.href);
        } catch (e) {
            console.warn('[YUZ][JS][GLOBAL][WARN] Failed to access localStorage:', e);
        }
    });
    
    console.log(`[YUZ][JS][GLOBAL][ACT] Last page tracking initialized at ${now}`);
})(window, document);


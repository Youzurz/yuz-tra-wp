var console = window.__YUZ_RELEASE_CONSOLE__ || {log:function(){},debug:function(){},info:function(){},warn:function(){},error:function(){}}; var yuz_release_console = console;


/**
 * yuz-global.js
 * Tracks the last opened page for YUZ Translation.
 */
(function(window, document, undefined) {
    'use strict';

    // Vérification de yuzTraSettings en mode DEV uniquement
    (function(w) {
        var dev = !!w.YUZ_DEBUG || (w.yuzDebug && w.yuzDebug.dev);
        if (dev && typeof w.yuzTraSettings === 'undefined') {
            yuz_release_console.warn('[YUZ-DEBUG] yuzTraSettings absent (DEV only)');
        }
    })(window);

    const now = new Date().toISOString();
    yuz_release_console.log(`[YUZ][JS][GLOBAL][PLAN] Planning last page tracking at ${now}`);
    
    document.addEventListener('DOMContentLoaded', function() {
        yuz_release_console.log(`[YUZ][JS][GLOBAL][DO] Tracking last page at ${now}`);
        try {
            localStorage.setItem('yuz_last_page', window.location.href);
            yuz_release_console.log('[YUZ][JS][GLOBAL][CHECK] Last page tracked:', window.location.href);
        } catch (e) {
            yuz_release_console.warn('[YUZ][JS][GLOBAL][WARN] Failed to access localStorage:', e);
        }
    });
    
    yuz_release_console.log(`[YUZ][JS][GLOBAL][ACT] Last page tracking initialized at ${now}`);
})(window, document);


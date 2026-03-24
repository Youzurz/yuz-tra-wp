

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
        }
    })(window);

    const now = new Date().toISOString();
    document.addEventListener('DOMContentLoaded', function() {
        try {
            localStorage.setItem('yuz_last_page', window.location.href);
        } catch (e) {
        }
    });
    
})(window, document);


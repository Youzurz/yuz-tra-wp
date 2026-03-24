

/**
 * yuz-update-database.js
 * Manages database update functionality for YUZ Translation.
 */
/**
 * TRANSVERSE CONTRACT (T-01):
 *   This file MUST read ONLY window.yuzTraSettings { ajax_url, nonces }.
 *   It MUST NOT read any UI payload (yuzGS, yuzTS, yuzTE, …).
 * Guard: hard fail with actionable error if contract is not satisfied.
 */
/* eslint-disable no-console */
(function (w) {
  var s = w && w.yuzTraSettings;
  if (!s || typeof s.ajax_url !== 'string' || !s.nonces) {
    return; // hard stop: prevents undefined access later
  }
})(window);
jQuery(document).ready(function($) {
    // Vérifier la présence de yuzTraSettings
    if (!window.yuzTraSettings || !window.yuzTraSettings.ajax_url || !window.yuzTraSettings.nonces?.yuz_update_database) {
        YUZ_Assets.log_colored('critical', 'yuzTraSettings is missing or incomplete', {
            ajax_url: window.yuzTraSettings?.ajax_url,
            nonce: window.yuzTraSettings?.nonces?.yuz_update_database
        });
        return;
    }

    function triggerDatabaseUpdate() {
        YUZ_Assets.log_colored('info', 'Initiating database update');
        $.ajax({
            url: yuzTraSettings.ajax_url,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'yuz_update_database',
                nonce: yuzTraSettings.nonces.yuz_update_database,
                initiate_update: true
            },
            success: (response) => {
                if (response.success) {
                    YUZ_Assets.log_colored('success', 'Database update progress', { message: response.data?.message });
                    $('#yuz-update-database-progress').append(response.data?.message || 'Update in progress...');
                    if (response.data?.yuz_update_completed === 'no') {
                        triggerDatabaseUpdate(); // Continue si non terminé
                    } else {
                        YUZ_Assets.log_colored('success', 'Database update completed', { response });
                    }
                } else {
                    YUZ_Assets.log_colored('critical', 'Database update failed', { message: response.data?.message || 'Unknown error' });
                    $('#yuz-update-database-progress').append('Error: ' + (response.data?.message || 'Unknown error'));
                }
            },
            error: (xhr) => {
                YUZ_Assets.log_colored('critical', 'AJAX error during database update', { responseText: xhr.responseText });
                $('#yuz-update-database-progress').append('Error: ' + (xhr.responseText || 'AJAX error'));
            }
        });
    }

    // Attacher l'événement au bouton si présent
    $('#yuz-update-database').on('click', function(e) {
        e.preventDefault();
        triggerDatabaseUpdate();
    });

    YUZ_Assets.log_colored('success', 'yuz-update-database.js initialized');
});


/**
 * yuz-update-database.js
 * Manages database update functionality for YUZ Translation.
 */
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
jQuery(document).ready(function($) {
    // Vérifier la présence de yuztraSettings
    if (!window.yuztraSettings || !window.yuztraSettings.ajax_url || !window.yuztraSettings.nonces?.yuztra_update_database) {
        YUZTRA_Assets.log_colored('critical', 'yuztraSettings is missing or incomplete', {
            ajax_url: window.yuztraSettings?.ajax_url,
            nonce: window.yuztraSettings?.nonces?.yuztra_update_database
        });
        return;
    }

    function triggerDatabaseUpdate() {
        YUZTRA_Assets.log_colored('info', 'Initiating database update');
        $.ajax({
            url: yuztraSettings.ajax_url,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'yuztra_update_database',
                nonce: yuztraSettings.nonces.yuztra_update_database,
                initiate_update: true
            },
            success: (response) => {
                if (response.success) {
                    YUZTRA_Assets.log_colored('success', 'Database update progress', { message: response.data?.message });
                    $('#yuz-update-database-progress').append(response.data?.message || 'Update in progress...');
                    if (response.data?.yuztra_update_completed === 'no') {
                        triggerDatabaseUpdate(); // Continue si non terminé
                    } else {
                        YUZTRA_Assets.log_colored('success', 'Database update completed', { response });
                    }
                } else {
                    YUZTRA_Assets.log_colored('critical', 'Database update failed', { message: response.data?.message || 'Unknown error' });
                    $('#yuz-update-database-progress').append('Error: ' + (response.data?.message || 'Unknown error'));
                }
            },
            error: (xhr) => {
                YUZTRA_Assets.log_colored('critical', 'AJAX error during database update', { responseText: xhr.responseText });
                $('#yuz-update-database-progress').append('Error: ' + (xhr.responseText || 'AJAX error'));
            }
        });
    }

    // Attacher l'événement au bouton si présent
    $('#yuz-update-database').on('click', function(e) {
        e.preventDefault();
        triggerDatabaseUpdate();
    });

    YUZTRA_Assets.log_colored('success', 'yuz-update-database.js initialized');
});


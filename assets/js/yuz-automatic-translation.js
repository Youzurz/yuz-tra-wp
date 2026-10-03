/**
 * yuz-automatic-translation.js
 * Manages the Automatic Translation tab interface for YUZ Translation.
 */
(function(window, document, undefined) {
    'use strict';

    function initAutoTrans($) {
        // Accept both legacy and new localization objects
        var y = window.yuztraAT || window.yuztraSettings || null;
        if (!y || !y.ajax_url) {
            YUZTRA_Assets.log_colored('warning', '[AT] Missing localization (yuztraAT/yuztraSettings), exiting');
            return;
        }
        if (typeof $ === 'undefined') {
            YUZTRA_Assets.log_colored('critical', 'jQuery unavailable, aborting');
            return;
        }

        // Toggle UI fields
        function toggleCronField() {
            var mode = $('#yuztra_translation_mode').val();
            $('.yuz-cron-interval').toggle(mode === 'silent' || mode === 'all');
        }

        function toggleAutoTranslationField() {
            var mode = $('#yuztra_translation_mode').val();
            if (mode === 'manual') {
                $('#yuztra_enable_auto_translate').prop('disabled', true);
                $('.yuz-auto-translation').css('opacity', '0.6');
            } else {
                $('#yuztra_enable_auto_translate').prop('disabled', false);
                $('.yuz-auto-translation').css('opacity', '1');
            }
        }

        function toggleProviderFields() {
            var provider = $('#yuztra_api_provider').val();
            $('.yuz-tra-api-provider-field').hide();
            $('.yuz-' + provider).show();
        }

        function toggleFields() {
            toggleCronField();
            toggleAutoTranslationField();
            toggleProviderFields();
        }

        $('#yuztra_translation_mode').on('change', function() {
            toggleCronField();
            toggleAutoTranslationField();
        });
        $('#yuztra_api_provider').on('change', toggleProviderFields);
        toggleFields();

        // Let the native PHP form handler process saves (server already implemented).
        // Intentionally no JS interception here to avoid schema mismatches.

        // PATCH 5 — always send the yuz_api_nonce (or per-action) using URLSearchParams
        async function postAT(action, payload){
            const Y = (window.yuztraTS || y || {});
            const body = new URLSearchParams();
            const cid  = Date.now().toString(36) + Math.random().toString(36).slice(2,8);
            body.set('action', action);
            body.set('cid', cid);
            body.set('nonce',
                (Y.nonces && (Y.nonces[action] || Y.nonces['yuztra_api_nonce'])) || ''
            );
            Object.entries(payload || {}).forEach(([k,v])=>{
                if (v !== null && typeof v === 'object' && !Array.isArray(v)) {
                    Object.entries(v).forEach(([nestedKey, nestedValue]) => {
                        body.set(`${k}[${nestedKey}]`, nestedValue == null ? '' : String(nestedValue));
                    });
                    return;
                }
                body.set(k, v == null ? '' : String(v));
            });
            YUZTRA_Assets.log_colored('info', '[AT][POST]', { action, cid, keys: Object.keys(payload||{}) });
            const res = await fetch((window.ajaxurl || Y.ajax_url), { method:'POST', body });
            const json = await res.json();
            YUZTRA_Assets.log_colored(res.ok?'success':'warning', '[AT][DONE]', { action, cid, status: res.status });
            return json;
        }

        // Test Connection button (uses postAT with yuz_api_nonce)
        $('#yuztra_test_btn').on('click', async function(e) {
            e.preventDefault();
            YUZTRA_Assets.log_colored('info', 'Test Connection triggered');
            $('#yuztra_test_status').text('…Test en cours…').css('color', '#000');
            const provider = $('#yuztra_api_provider').val();
            let endpoint = '';
            let apiKey = '';
            const extraSettings = {};
            switch (provider) {
                case 'ollama':
                    endpoint = $('#yuztra_ollama_url').val().trim();
                    extraSettings.model = $('#yuztra_model').val().trim();
                    break;
                case 'libretranslate':
                    endpoint = $('#yuztra_libre_url').val().trim();
                    apiKey = $('#yuztra_libre_key').val().trim();
                    extraSettings.alternatives = $('#yuztra_alternatives').val().trim();
                    break;
                case 'deepl':
                    endpoint = $('#yuztra_deepl_free').is(':checked')
                        ? 'https://api-free.deepl.com/v2/translate'
                        : 'https://api.deepl.com/v2/translate';
                    apiKey = $('#yuztra_deepl_key').val().trim();
                    extraSettings.deepl_free = $('#yuztra_deepl_free').is(':checked') ? '1' : '0';
                    break;
                case 'google':
                    endpoint = 'https://translation.googleapis.com/language/translate/v2';
                    apiKey = $('#yuztra_google_key').val().trim();
                    extraSettings.google_project = $('#yuztra_google_project').val().trim();
                    break;
                case 'custom':
                    endpoint = $('#yuztra_custom_url').val().trim();
                    apiKey = $('#yuztra_custom_key').val().trim();
                    extraSettings.custom_auth = $('#yuztra_custom_auth').val().trim();
                    extraSettings.custom_method = $('#yuztra_custom_method').val().trim();
                    extraSettings.custom_format = $('#yuztra_custom_format').val().trim();
                    break;
                default:
                    $('#yuztra_test_status').text('✖ Provider invalide').css('color', 'red');
                    YUZTRA_Assets.log_colored('warning', 'Invalid provider');
                    return;
            }
            if (!endpoint && ['libretranslate', 'custom'].includes(provider)) {
                $('#yuztra_test_status').text('✖ Endpoint requis').css('color', 'red');
                YUZTRA_Assets.log_colored('warning', 'Endpoint missing');
                return;
            }
            if (!apiKey && ['deepl', 'google', 'custom'].includes(provider)) {
                $('#yuztra_test_status').text('✖ Clé API requise').css('color', 'red');
                YUZTRA_Assets.log_colored('warning', 'API key missing');
                return;
            }
            YUZTRA_Assets.log_colored('info', 'Test connection payload', {
                provider,
                endpoint,
                apiKeyPresent: Boolean(apiKey),
                extraSettings
            });
            try {
                const response = await postAT('yuztra_at_get_api_test', {
                    provider: provider,
                    endpoint: endpoint,
                    api_key: apiKey,
                    extra_settings: extraSettings
                });
                if (response && response.success) {
                    const message = Array.isArray(response.data?.message) ? response.data.message.join('<br>') : (response.data?.message || 'OK');
                    $('#yuztra_test_status').html(`✔ ${message}`).css('color', 'green');
                    YUZTRA_Assets.log_colored('success', 'Test connection successful', { message });
                } else {
                    const msg = (response && (response.data?.message || response.message)) || 'Échec inconnu';
                    $('#yuztra_test_status').text(`✖ ${msg}`).css('color', 'red');
                    YUZTRA_Assets.log_colored('critical', 'Test connection failed', { message: msg });
                }
            } catch (err) {
                YUZTRA_Assets.log_colored('critical', 'AJAX error', { error: String(err) });
                $('#yuztra_test_status').text(`Erreur AJAX : ${String(err)}`).css('color', 'red');
            }
        });

        // Reset Settings button (only bind if feature + nonce + element exist)
        if ($('#yuztra_reset_settings').length && (y.nonces && y.nonces['yuztra_at_reset_settings'])) {
            $('#yuztra_reset_settings').on('click', async function(e) {
                e.preventDefault();
                YUZTRA_Assets.log_colored('info', 'Reset Settings button clicked');
                try {
                    const response = await postAT('yuztra_at_reset_settings', {});
                    if (response && response.success) {
                        YUZTRA_Assets.log_colored('success', 'Settings reset', { data: response.data });
                        alert('Settings reset successfully.');
                        location.reload();
                    } else {
                        YUZTRA_Assets.log_colored('critical', 'Reset failed', { message: response.data?.message || 'Unknown error' });
                        alert('Failed to reset settings: ' + (response.data?.message || 'Unknown error'));
                    }
                } catch(err) {
                    YUZTRA_Assets.log_colored('critical', 'AJAX error', { error: String(err) });
                    alert('Error resetting settings');
                }
            });
        }

        // Silent Translation button (only bind if present)
        if ($('#yuztra_silent_translation').length) {
            $('#yuztra_silent_translation').on('click', function(e) {
                e.preventDefault();
                YUZTRA_Assets.log_colored('info', 'Silent Translation button clicked');
                // Endpoint not available yet; provide a friendly hint instead of a failing AJAX call
                alert('Silent translation is not available in this build.');
            });
        }

        // Tracer (scopé AT)
        (function attachATTracer(){
            try {
                $(document).ajaxSend(function(_e,_xhr,settings){
                    const isAdmin = /admin-ajax\.php/.test(String(settings.url||''));
                    if (!isAdmin) return;
                    let p = {}; try { if (typeof settings.data==='string') p = Object.fromEntries(new URLSearchParams(settings.data)); else if (settings.data) p=settings.data; } catch(_){}
                    const action = p.action||''; if (!/^yuztra_at_/.test(action)) return;
                    YUZTRA_Assets.log_colored('info','[TRACE][SEND][AT]',{ action: action, cid: p.cid||'(none)' });
                });
                $(document).ajaxComplete(function(_e,_xhr,settings){
                    const isAdmin = /admin-ajax\.php/.test(String(settings.url||''));
                    if (!isAdmin) return;
                    let p = {}; try { if (typeof settings.data==='string') p = Object.fromEntries(new URLSearchParams(settings.data)); else if (settings.data) p=settings.data; } catch(_){}
                    const action = p.action||''; if (!/^yuztra_at_/.test(action)) return;
                    YUZTRA_Assets.log_colored('info','[TRACE][COMPLETE][AT]',{ action: action, cid: p.cid||'(none)' });
                });
            } catch(_) {}
        })();

        YUZTRA_Assets.log_colored('success', 'Automatic Translation interface initialized at ' + new Date().toISOString());
    }

    jQuery(document).ready(function($) {
        initAutoTrans($);
    });
})(window, document);

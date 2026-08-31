<?php
/**
 * Debug probe utilities for YUZ-TRA.
 *
 * @package YUZ_Translation
 */

defined('ABSPATH') || exit;

if (!class_exists('YUZ_Debug_Probe')) {
    final class YUZ_Debug_Probe {
        /**
         * Probe is opt-in only in production.
         */
        public static function enabled(): bool {
            if (defined('YUZ_DEBUG_PROBE')) {
                return (bool) YUZ_DEBUG_PROBE;
            }

            if (defined('YUZ_TRA_DEBUG') && YUZ_TRA_DEBUG) {
                return true;
            }

            if (defined('CLAR_DEBUG_DIAG') && CLAR_DEBUG_DIAG) {
                return true;
            }

            return false;
        }

        /**
         * Bootstraps the probe when the plugin loads.
         */
        public static function init(): void {
            if (!self::enabled()) {
                return;
            }

            if (!defined('YUZ_DEBUG_PROBE_LOG')) {
                define('YUZ_DEBUG_PROBE_LOG', WP_CONTENT_DIR . '/yuz-debug.log');
            }

            add_action('wp_ajax_yuz_save_translation', [__CLASS__, 'capture_ajax'], 0);
            add_action('wp_ajax_yuz_tra_tm_get_translations', [__CLASS__, 'capture_ajax'], 0);
            add_action('wp_print_footer_scripts', [__CLASS__, 'inject_client_hooks']);
            add_action('wp_ajax_yuz_probe_client_log', [__CLASS__, 'handle_client_log']);

            foreach (self::transient_keys() as $transient_key) {
                add_filter('pre_transient_' . $transient_key, [__CLASS__, 'observe_transient_get'], 10, 2);
                add_filter('pre_set_transient_' . $transient_key, [__CLASS__, 'observe_transient_set'], 10, 3);
            }
        }

        /**
         * Records a structured entry in the probe log.
         */
        public static function log(string $event, array $context = []): void {
            if (!self::enabled()) {
                return;
            }

            $record = [
                'event'   => $event,
                'time'    => current_time('mysql'),
                'request' => [
                    'method' => $_SERVER['REQUEST_METHOD'] ?? '',
                    'uri'    => $_SERVER['REQUEST_URI'] ?? '',
                ],
                'user'    => is_user_logged_in() ? get_current_user_id() : 0,
                'context' => $context,
            ];

            $json = wp_json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            if ($json === false) {
                $json = '{"event":"' . $event . '","error":"json_encode_failed"}';
            }

            file_put_contents(YUZ_DEBUG_PROBE_LOG, $json . "\n", FILE_APPEND);
        }

        /**
         * AJAX capture hook for diagnostics.
         */
        public static function capture_ajax(): void {
            $payload = [
                'post'   => array_map('wp_unslash', $_POST), // phpcs:ignore WordPress.Security.NonceVerification.Missing
                'get'    => array_map('wp_unslash', $_GET),  // phpcs:ignore WordPress.Security.NonceVerification.Missing
                'action' => $_POST['action'] ?? $_GET['action'] ?? '', // phpcs:ignore WordPress.Security.NonceVerification.Missing
            ];
            self::log('ajax_capture', $payload);
        }

        /**
         * Observes transient reads.
         */
        public static function observe_transient_get($value, string $transient) {
            if (self::is_probe_transient($transient)) {
                self::log('transient_check', [
                    'transient'  => $transient,
                    'from_cache' => $value !== false,
                ]);
            }
            return $value;
        }

        /**
         * Observes transient writes.
         */
        public static function observe_transient_set($value, string $transient, $expiration) {
            if (self::is_probe_transient($transient)) {
                self::log('transient_set', [
                    'transient'  => $transient,
                    'expiration' => $expiration,
                    'size'       => is_array($value) ? count($value) : null,
                ]);
            }
            return $value;
        }

        /**
         * Injects a lightweight client logger when the inline editor is active.
         */
        public static function inject_client_hooks(): void {
            if (is_admin()) {
                return;
            }
            if (!isset($_GET['yuz-edit-translation'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
                return;
            }
            ?>
            <script>
            (function(w){
                if (!w || w.__YUZ_DEBUG_PROBE__) { return; }
                w.__YUZ_DEBUG_PROBE__ = true;
                var logEndpoint = function(kind, data){
                    if (!w.fetch) { return; }
                    try {
                        var fd = new FormData();
                        fd.append('action', 'yuz_probe_client_log');
                        fd.append('kind', kind);
                        fd.append('payload', JSON.stringify(data));
                        w.fetch('<?php echo esc_url(admin_url('admin-ajax.php')); ?>', {
                            method: 'POST',
                            credentials: 'same-origin',
                            body: fd
                        }).catch(function(){});
                    } catch(e) {
                        console.warn('yuz-probe failed', e);
                    }
                };
                if (w.fetch) {
                    var originalFetch = w.fetch;
                    w.fetch = function(){
                        var args = Array.prototype.slice.call(arguments);
                        var url = args[0];
                        if (typeof url === 'string' && url.indexOf('yuz') !== -1) {
                            logEndpoint('fetch', { url: url });
                        }
                        return originalFetch.apply(this, args).then(function(response){
                            if (typeof url === 'string' && url.indexOf('yuz') !== -1) {
                                logEndpoint('fetch-response', { url: url, status: response.status });
                            }
                            return response;
                        });
                    };
                }
                if (w.jQuery && w.jQuery.ajax) {
                    var $ = w.jQuery;
                    var originalAjax = $.ajax;
                    $.ajax = function(options){
                        var opts = options || {};
                        if (opts && opts.data && typeof opts.data === 'object' && opts.data.action) {
                            logEndpoint('jquery-ajax', { action: opts.data.action, url: opts.url || '(admin-ajax)' });
                        }
                        var xhr = originalAjax.apply(this, arguments);
                        if (xhr && xhr.then) {
                            xhr.then(function(resp){
                                if (opts && opts.data && opts.data.action) {
                                    logEndpoint('jquery-ajax-done', { action: opts.data.action, success: !!(resp && resp.success) });
                                }
                            }).catch(function(){
                                if (opts && opts.data && opts.data.action) {
                                    logEndpoint('jquery-ajax-fail', { action: opts.data.action });
                                }
                            });
                        }
                        return xhr;
                    };
                }
            })(window);
            </script>
            <?php
        }

        /**
         * Receives client logs via admin-ajax.
         */
        public static function handle_client_log(): void {
            $kind    = isset($_POST['kind']) ? sanitize_text_field(wp_unslash($_POST['kind'])) : 'client';
            $payload = isset($_POST['payload']) ? wp_unslash($_POST['payload']) : '';
            self::log('client_trace', [
                'kind'    => $kind,
                'payload' => $payload,
            ]);
            wp_send_json_success();
        }

        /**
         * Helper: determine if transient key is monitored.
         */
        private static function is_probe_transient(string $transient): bool {
            return strpos($transient, 'yuz_tra_') === 0 || strpos($transient, 'yuztra') === 0;
        }

        /**
         * Helper: returns the list of transient keys we observe.
         *
         * @return string[]
         */
        private static function transient_keys(): array {
            $keys = [];
            $suffixes = [
                \YUZ_Languages::TRANSIENT_TRANSLATABLE,
                \YUZ_Languages::TRANSIENT_ALL,
                \YUZ_Languages::TRANSIENT_DEFAULT_LANGUAGE,
                \YUZ_Languages::TRANSIENT_SOURCE_LANGUAGE,
            ];
            foreach ($suffixes as $suffix) {
                $keys[] = yuz_settings_transient_key($suffix);
            }
            return $keys;
        }
    }

    YUZ_Debug_Probe::init();
}

// Backwards compatibility helpers (legacy global functions).
if (!function_exists('yuz_debug_probe_log')) {
    function yuz_debug_probe_log(string $event, array $context = []): void {
        YUZ_Debug_Probe::log($event, $context);
    }
}

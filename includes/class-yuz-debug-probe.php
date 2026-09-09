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
                    'method' => sanitize_key(wp_unslash($_SERVER['REQUEST_METHOD'] ?? '')),
                ],
                'user'    => is_user_logged_in() ? get_current_user_id() : 0,
                'context' => $context,
            ];

            $json = wp_json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            if ($json === false) {
                $json = '{"event":"' . $event . '","error":"json_encode_failed"}';
            }

            // Bounded private diagnostics: never write publicly accessible log files.
            $records = (array) get_option('yuz_tra_debug_probe_records', []);
            $records[] = $record;
            update_option('yuz_tra_debug_probe_records', array_slice($records, -50), false);
        }

        /**
         * AJAX capture hook for diagnostics.
         */
        public static function capture_ajax(): void {
            if (!current_user_can('manage_options') && !current_user_can('yuz_translate_content')) {
                return;
            }
            $payload = [
                // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Probe records keys only before the real handler validates its nonce.
                'post_keys' => array_map('sanitize_key', array_keys($_POST)),
                // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Probe records keys only.
                'get_keys'  => array_map('sanitize_key', array_keys($_GET)),
                // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Routing metadata only.
                'action'    => isset($_POST['action']) ? sanitize_key(wp_unslash((string) $_POST['action'])) : '',
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
            // Server-side, opt-in diagnostics are available without intercepting browser APIs.
        }

        /**
         * Receives client logs via admin-ajax.
         */
        public static function handle_client_log(): void {
            if (!current_user_can('manage_options') && !current_user_can('yuz_translate_content')) {
                wp_send_json_error(['error' => 'forbidden'], 403);
            }
            check_ajax_referer('yuz_log_nonce', 'nonce');
            $kind    = isset($_POST['kind']) ? sanitize_text_field(wp_unslash($_POST['kind'])) : 'client';
            $payload = isset($_POST['payload']) ? sanitize_textarea_field(wp_unslash($_POST['payload'])) : '';
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

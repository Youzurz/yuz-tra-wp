<?php
/**
 * Output buffering bootstrap for front-end translations.
 *
 * @package YUZ_Translation
 */

defined('ABSPATH') || exit;

if (!class_exists('YUZ_Front_Buffer')) {
    final class YUZ_Front_Buffer {
        private static bool $started = false;

        /**
         * Bootstrap the buffer ASAP on init.
         */
        public static function init(): void {
            add_action('init', [__CLASS__, 'maybe_start'], 0);
            if (did_action('init')) {
                // We're already in the init cycle; start buffering right away.
                self::maybe_start();
            }
        }

        /**
         * Start buffering when running on the public front-end.
         */
        public static function maybe_start(): void {
            if (self::$started) {
                return;
            }

            if (is_admin()
                || (function_exists('wp_doing_ajax') && wp_doing_ajax())
                || (defined('REST_REQUEST') && REST_REQUEST)
                || (defined('DOING_CRON') && DOING_CRON)
            ) {
                return;
            }

            if (!apply_filters('yuz_tra_enable_front_buffer', true)) {
                return;
            }

            self::$started = true;
            ob_start([__CLASS__, 'buffer_callback']);
        }

        /**
         * Buffer callback converting the outgoing payload.
         *
         * @param string $output
         * @return string
         */
        public static function buffer_callback(string $output): string {
            try {
                if (!class_exists('YUZ_Front_Renderer')) {
                    require_once YUZ_TRA_INCLUDES . 'class-yuz-front-renderer.php';
                }
                return YUZ_Front_Renderer::translate_page($output);
            } catch (\Throwable $e) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('[YUZ-TRA] front buffer failed: ' . $e->getMessage());
                }
                return $output;
            }
        }
    }
}

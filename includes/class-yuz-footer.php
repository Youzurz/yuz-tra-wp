<?php
/**
 * Central footer orchestrator (front + admin).
 *
 * Ensures a single entry point for late HTML output while allowing
 * modules to hook into dedicated internal actions.
 */

defined('ABSPATH') || exit;

if (!class_exists('YUZ_Footer')) {
    final class YUZ_Footer {
        public static function init(): void {
            add_action('wp_footer', [__CLASS__, 'render_front'], 5);
            add_action('admin_footer', [__CLASS__, 'render_admin'], 20);
        }

        public static function render_front(): void {
            /**
             * Fires in the public footer once the YUZ footer orchestrator runs.
             * Modules should hook here instead of registering their own wp_footer callbacks.
             */
            if (!is_admin() && !defined('YUZ_EDITOR_ROOT_PRINTED')) {
                echo '<div id="yuz-editor-container" data-yuz-editor-root></div>';
                define('YUZ_EDITOR_ROOT_PRINTED', true);
                if (isset($_GET['yuzdebug'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
                }
                do_action('yuz/editor_root_printed');
            }
            do_action('yuz/footer/frontend');
        }

        public static function render_admin(): void {
            /**
             * Fires in the admin footer for YUZ specific output (e.g. editor containers).
             */
            do_action('yuz/footer/admin');
        }
    }
}

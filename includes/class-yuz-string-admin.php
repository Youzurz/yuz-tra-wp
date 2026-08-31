<?php
/**
 * Class YUZ_String_Admin
 * Admin page to translate admin strings (String Translation Editor).
 *
 * @package YUZ_Translation
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

if (!class_exists('YUZ_String_Admin')) {
    class YUZ_String_Admin {
        public static function init(): void {
            add_action('yuz-tra_page_yuz-translation-strings', [__CLASS__, 'render_tab']);
            // Keep direct links valid even when the optional submenu is hidden.
            add_action('admin_menu', [__CLASS__, 'register_menu']);
        }

        public static function register_menu(): void {
            // Parent slug must match top‑level menu created in YUZ_Settings::add_admin_page
            $parent = apply_filters('yuz_tra_show_translate_admin_submenu', false)
                ? 'yuz-translation-settings' : '';
            add_submenu_page(
                $parent,
                __('String Translation', 'yuz-translation'),
                __('Strings', 'yuz-translation'),
                'yuz_translate_strings',
                'yuz-string-translation-editor',
                [__CLASS__, 'render_page']
            );
        }

        public static function render_page(): void {
            if (
                !current_user_can('yuz_translate_strings')
                && !current_user_can('yuz_translate_content')
                && !current_user_can('manage_options')
            ) {
                wp_die(esc_html__('Unauthorized', 'yuz-translation'));
            }
            $tab_url = add_query_arg(
                ['page' => 'yuz-translation-settings', 'tab' => 'strings'],
                admin_url('admin.php')
            );
            echo '<div class="wrap"><h1>' . esc_html__('String Translation', 'yuz-translation') . '</h1>';
            echo '<p class="description">' . esc_html__('This tool now lives in the YUZ-TRA "Strings" tab.', 'yuz-translation') . ' ';
            echo '<a href="' . esc_url($tab_url) . '">' . esc_html__('Open the Strings tab', 'yuz-translation') . '</a>.</p>';
            self::render_tab_content();
            echo '</div>';
        }

        public static function render_tab(): void {
            if (
                !current_user_can('yuz_translate_strings')
                && !current_user_can('yuz_translate_content')
                && !current_user_can('manage_options')
            ) {
                wp_die(esc_html__('Unauthorized', 'yuz-translation'));
            }
            self::render_tab_content();
        }

        private static function render_tab_content(): void {
            echo '<div class="yuz-strings-tab">';
            echo '<p>' . esc_html__('Translate WordPress, plugin and theme strings. Scan the installed code, choose a language, then review and publish translations. Only published translations are applied.', 'yuz-translation') . '</p>';
            echo '<p>';
            echo '<a class="button button-primary" href="#" data-yuz-open-strings data-tab="gettext">' . esc_html__('Open Gettext', 'yuz-translation') . '</a> ';
            echo '</p>';
            echo '<p class="description">' . esc_html__('Tip: Alt+G toggles the dock when it is loaded.', 'yuz-translation') . '</p>';
            echo '</div>';
        }
    }
}

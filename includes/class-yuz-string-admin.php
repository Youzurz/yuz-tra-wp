<?php
/**
 * Class YUZ_String_Admin
 * Admin page to translate admin strings (String Translation Editor).
 *
 * @package YUZ_Translation
 */

defined('ABSPATH') or exit;

if (!class_exists('YUZ_String_Admin')) {
    class YUZ_String_Admin {
        public static function init(): void {
            add_action('yuz-tra_page_yuz-translation-strings', [__CLASS__, 'render_tab']);
            if (apply_filters('yuz_tra_show_translate_admin_submenu', false)) {
                add_action('admin_menu', [__CLASS__, 'register_menu']);
            }
        }

        public static function register_menu(): void {
            // Parent slug must match top‑level menu created in YUZ_Settings::add_admin_page
            $parent = 'yuz-translation-settings';
            add_submenu_page(
                $parent,
                __('String Translation', 'yuz_translation'),
                __('Strings', 'yuz_tra'),
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
                wp_die(__('Unauthorized', 'yuz_translation'));
            }
            $tab_url = add_query_arg(
                ['page' => 'yuz-translation-settings', 'tab' => 'strings'],
                admin_url('admin.php')
            );
            echo '<div class="wrap"><h1>' . esc_html__('String Translation', 'yuz_translation') . '</h1>';
            echo '<p class="description">' . esc_html__('This tool now lives in the YUZ-TRA "Strings" tab.', 'yuz_tra') . ' ';
            echo '<a href="' . esc_url($tab_url) . '">' . esc_html__('Open the Strings tab', 'yuz_tra') . '</a>.</p>';
            self::render_tab_content();
            echo '</div>';
        }

        public static function render_tab(): void {
            if (
                !current_user_can('yuz_translate_strings')
                && !current_user_can('yuz_translate_content')
                && !current_user_can('manage_options')
            ) {
                wp_die(__('Unauthorized', 'yuz_translation'));
            }
            self::render_tab_content();
        }

        private static function render_tab_content(): void {
            echo '<div class="yuz-strings-tab">';
            echo '<p>' . esc_html__('Manage gettext, slugs, and email templates from the Strings dock.', 'yuz_tra') . '</p>';
            echo '<p>';
            echo '<a class="button button-primary" href="#" data-yuz-open-strings data-tab="gettext">' . esc_html__('Open Gettext', 'yuz_tra') . '</a> ';
            echo '<a class="button" href="#" data-yuz-open-strings data-tab="slugs">' . esc_html__('Open Slugs', 'yuz_tra') . '</a> ';
            echo '<a class="button" href="#" data-yuz-open-strings data-tab="emails">' . esc_html__('Open Emails', 'yuz_tra') . '</a>';
            echo '</p>';
            echo '<p class="description">' . esc_html__('Tip: Alt+G toggles the dock when it is loaded.', 'yuz_tra') . '</p>';
            echo '</div>';
        }
    }
}

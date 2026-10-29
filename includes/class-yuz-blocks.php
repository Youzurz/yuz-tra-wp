<?php
/**
 * Class YUZ_Blocks
 * Registers server-side blocks for FSE integration (no enqueue here).
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

if (!class_exists('YUZTRA_Blocks')) {

class YUZTRA_Blocks {
    public static function init(): void {
        add_action('init', [__CLASS__, 'register_blocks']);
    }

    public static function register_blocks(): void {
        if (!function_exists('register_block_type')) return;

        // Language Switcher block (server-side render)
        register_block_type('yuztra/language-switcher', [
            'api_version'      => 2,
            'render_callback'  => [__CLASS__, 'render_language_switcher_block'],
            'attributes'       => [
                'format' => [
                    'type'    => 'string',
                    'default' => 'flags-full-names',
                ],
                'theme' => [
                    'type'    => 'string',
                    'default' => 'light',
                ],
                'showPoweredBy' => [
                    'type'    => 'boolean',
                    'default' => false,
                ],
            ],
            'supports'         => [
                'align'   => ['left','center','right','wide','full'],
                'anchor'  => true,
                'html'    => false,
            ],
        ]);
    }

    public static function render_language_switcher_block(array $attributes = [], string $content = '', $block = null): string {
        // Safety: ensure Switcher exists
        if (!class_exists('YUZTRA_Switcher')) return '';

        // Map block attrs to partial vars
        $format         = isset($attributes['format']) ? (string)$attributes['format'] : 'flags-full-names';
        $theme          = isset($attributes['theme']) ? (string)$attributes['theme'] : 'light';
        $switcher_settings = (array) get_option('yuztra_sw_settings', []);
        $show_poweredby = !empty($attributes['showPoweredBy']) && !empty($switcher_settings['show_poweredby']);

        // Ask assets factory to include CSS/JS when flushing
        if (class_exists('YUZTRA_Assets')) { YUZTRA_Assets::require('switcher'); }

        // Use the same renderer as shortcode mode
        $buffer_level = ob_get_level();
        try {
            // Build variables expected by partial
            $languages = class_exists('YUZTRA_Services') && method_exists('YUZTRA_Services','languages')
                ? \YUZTRA_Services::languages()->get_translatable_languages()
                : [];

            ob_start();
            // Replicate include logic from YUZ_Switcher::render_shortcode_switcher
            $mode            = 'shortcode';
            $use_native_name = !empty(get_option('yuztra_settings', [])['native_language_name']);
            $current_lang    = get_locale();
            $translated_strings = [
                /* translators: %s: current language name or code. */
                'current_lang_label' => esc_html__('Current language: %s, click to change', 'yuz-tra'),
                /* translators: %s: target language name or code. */
                'switch_to_label'    => esc_html__('Switch to %s', 'yuz-tra'),
                'powered_by'         => esc_html__('Powered by', 'yuz-tra'),
                'powered_by_yuzurz'  => esc_html__('YoUZurz', 'yuz-tra'),
            ];
            include plugin_dir_path(YUZTRA_PLUGIN_FILE) . 'partials/yuz-language-switcher.php';
            return (string) ob_get_clean();
        } catch (\Throwable $e) {
            while (ob_get_level() > $buffer_level) ob_end_clean();
            if (class_exists('YUZTRA_Logger')) {
                (new \YUZTRA_Logger())->log('warning', 'Language Switcher block render failed: '.$e->getMessage());
            }
            return '';
        }
    }
}
}

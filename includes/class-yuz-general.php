<?php
/**
 * Class YUZ_General
 * Manages the General Settings tab in the YUZ-TRA admin interface.
 *
 * @package YUZ_Translation
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

require_once YUZ_TRA_INCLUDES . 'class-yuz-contracts.php';
require_once YUZ_TRA_INCLUDES . 'class-yuz-fallbacks.php';

use YUZTRA\Interfaces\GeneralInterface;
use YUZTRA\Interfaces\SettingsInterface;
use YUZTRA\Interfaces\LanguagesInterface;
use YUZTRA\Interfaces\AjaxInterface;
use YUZTRA\Interfaces\RendererInterface;
use YUZTRA\Interfaces\LoggerInterface;
use YUZTRA\Fallbacks\NullSettings;
use YUZTRA\Fallbacks\NullAjax;
use YUZTRA\Fallbacks\NullTranslationManager;
use YUZTRA\Fallbacks\NullLanguageManager;
use YUZTRA\Fallbacks\NullLogger;
use YUZTRA\Fallbacks\NullLanguages;
use YUZTRA\Fallbacks\NullDB;
use YUZTRA\Fallbacks\NullHealthCheck;

if (interface_exists('YUZTRA\Interfaces\GeneralInterface')) {

class YUZ_General implements GeneralInterface {
    private $settings;
    private $ajax;
    private $languages;
    private $renderer;
    private $logger;

    public function __construct(
        SettingsInterface $settings,
        LanguagesInterface $languages,
        AjaxInterface $ajax,
        RendererInterface $renderer,
        LoggerInterface $logger
    ) {
        $this->settings  = $settings;
        $this->languages = $languages;
        $this->ajax      = $ajax;
        $this->renderer  = $renderer;
        $this->logger    = $logger;
        $this->logger->log('info', 'YUZ_General instantiated');
    }

    private static bool $booted = false;

    public static function init(): void {
        if (self::$booted) return;
        self::$booted = true;

        if (!defined('YUZ_TRA_INCLUDES') || !defined('YUZ_TRA_PLUGIN_FILE')) {
            error_log('🟥 [CRITICAL] YUZ-TRA: required constants missing — halting YUZ_General::init');
            wp_die('Critical error: YUZ-TRA constants missing.');
        }

        // Fallbacks légers
        $logger = class_exists('YUZ_Logger') ? new \YUZ_Logger() : new NullLogger();

        $health = class_exists('YUZ_Health_Check') ? new \YUZ_Health_Check($logger) : new NullHealthCheck();
        $db     = class_exists('YUZ_DB') ? new \YUZ_DB($logger, $health) : (class_exists('\YUZTRA\Fallbacks\NullDB') ? new NullDB() : null);

        $langs  = (class_exists('YUZ_Languages') && $db) ? new \YUZ_Languages(new NullSettings(), $db) : new NullLanguages();

        $translationManager = class_exists('YUZ_Translation_Manager') ? new NullTranslationManager() : new NullTranslationManager();
        $languageManager    = class_exists('YUZ_LanguageManager')    ? new NullLanguageManager()    : new NullLanguageManager();
        $ajax               = class_exists('YUZ_Ajax') ? new \YUZ_Ajax($translationManager, $languageManager, $db) : new NullAjax();

        $settings = class_exists('YUZ_Settings')
            ? new \YUZ_Settings($langs, $ajax, $translationManager, $languageManager, $logger)
            : new NullSettings();

        $renderer = class_exists('YUZ_Renderer')
            ? new \YUZ_Renderer($ajax, $settings, $logger, $langs)
            : new class implements \YUZTRA\Interfaces\RendererInterface {
                public static function init(): void {}
                public function render_tab(): void { echo '<div class="wrap"><h1>General</h1><p>Renderer unavailable.</p></div>'; }
                public function render_translate_site_button(array $s=[]): void {}
                public function render_support_extra_languages_field(array $s=[]): void {}
                public function render_youzuruz_ai_field(array $s=[]): void {}
                public function render_translate_seo_field(array $s=[]): void {}
                public function render_publish_only_complete_field(array $s=[]): void {}
                public function render_translate_by_role_field(array $s=[]): void {}
                public function render_menu_per_language_field(array $s=[]): void {}
                public function render_browser_language_detect_field(array $s=[]): void {}
                public function render_default_language_field(array $s=[]): void {}
                public function render_source_language_field(array $s=[]): void {}
                public function render_translatable_languages_field(array $s=[]): void {}
                public function render_native_language_name_field(array $s=[]): void {}
                public function render_use_subdirectory_field(array $s=[]): void {}
                public function render_force_lang_in_links_field(array $s=[]): void {}
                public function render_shortcode_block(array $s=[]): void {}
                public function render_menu_item_block(array $s=[]): void {}
                public function render_floating_language_selection_block(array $s=[]): void {}
                public function render_powered_by_block(array $s=[]): void {}
                public function render_advanced_tab(array $s=[]): void {}
                public function render_addons_tab(array $s=[]): void {}
                public function render_licences_tab(array $s=[]): void {}
                public function render_enable_auto_translation_field(array $s=[]): void {}
                public function render_translation_mode_field(array $s=[]): void {}
                public function render_cron_interval_field(array $s=[]): void {}
                public function render_api_provider_field(array $s=[]): void {}
                public function render_libretranslate_fields(array $s=[]): void {}
                public function render_alternatives_field(array $s=[]): void {}
                public function render_google_fields(array $s=[]): void {}
                public function render_deepl_fields(array $s=[]): void {}
                public function render_custom_fields(array $s=[]): void {}
                public function render_char_limit_field(array $s=[]): void {}
                public function render_requests_limit_field(array $s=[]): void {}
                public function render_block_crawlers_field(array $s=[]): void {}
                public function render_log_queries_field(array $s=[]): void {}
                public function render_test_api_connection(array $s=[]): void {}
                public function render_monitoring_dashboard(array $s=[]): void {}
            };

        $instance = new self($settings, $langs, $ajax, $renderer, $logger);

        add_action('yuz-tra_page_yuz-translation-general', [$instance, 'render_tab']);

        $logger->log('success', 'YUZ_General initialized');
    }

    /**
     * Normalise le schéma attendu pour `yuz_tra_general`.
     */
    private static function normalize_general(array $g): array {
        $def  = isset($g['yuz_tra_default_language']) ? (string) $g['yuz_tra_default_language'] : '';
        $src  = isset($g['yuz_tra_source_language'])  ? (string) $g['yuz_tra_source_language']  : '';
        $tgt  = isset($g['yuz_tra_translatable_languages']) && is_array($g['yuz_tra_translatable_languages']) ? array_values($g['yuz_tra_translatable_languages']) : [];

        $slug = isset($g['yuz_tra_slug']) && is_array($g['yuz_tra_slug']) ? $g['yuz_tra_slug'] : [];
        $code = isset($g['yuz_tra_code']) && is_array($g['yuz_tra_code']) ? $g['yuz_tra_code'] : [];

        // Ne garder que les entrées cohérentes (lang présentes dans la liste).
        $tgt = array_values(array_unique(array_filter(array_map('strval', $tgt))));
        $slugNorm = [];
        $codeNorm = [];
        foreach ($tgt as $loc) {
            $slugNorm[$loc] = isset($slug[$loc]) ? (string)$slug[$loc] : strtolower(str_replace('_','-',$loc));
            $codeNorm[$loc] = isset($code[$loc]) ? (string)$code[$loc] : $loc;
        }

        return [
            'yuz_tra_default_language'       => $def,
            'yuz_tra_source_language'        => $src,
            'yuz_tra_translatable_languages' => $tgt,
            'yuz_tra_slug'                   => $slugNorm,
            'yuz_tra_code'                   => $codeNorm,
        ];
    }

    public static function sanitize_ws_settings($value): array {
        $value = is_array($value) ? $value : [];
        return self::normalize_general($value);
    }

    /**
     * Rendu + sauvegarde de l’onglet General.
     * IMPORTANT : lecture/écriture DIRECTES sur les options WP.
     */
    public function render_tab() {
        if (!current_user_can('manage_options')) {
            $this->logger->log('critical', 'User lacks manage_options in YUZ_General::render_tab');
            wp_die('Unauthorized');
        }

        $options_general   = (array) get_option('yuz_tra_ws_settings', []);
        $options_settings  = (array) get_option('yuz_tra_ls_settings', []);
        $switcher_settings = (array) get_option('yuz_tra_sw_settings', []);


        settings_errors('yuz_tra_general_settings_group');

        ?>
        <div class="wrap">
            <h1><?php esc_html_e('General', 'yuz-tra'); ?></h1>

            <form id="yuz-general-settings-form" method="post" action="options.php">
                <?php settings_fields('yuz_tra_general_settings_group'); ?>
                <?php wp_nonce_field('yuz_con_nonce', 'nonce'); ?>
                <input type="hidden" name="option_page" value="yuz_tra_general_settings_group" />
                <input type="hidden" name="action" value="update" />

                <!-- Sentinels -->
                <input type="hidden" name="yuz_tra_ws_settings[_sentinel]" value="1" />
                <input type="hidden" name="yuz_tra_ls_settings[_sentinel]" value="1" />
                <input type="hidden" name="yuz_tra_sw_settings[_sentinel]" value="1" />

                <div class="yuz-section" data-section="website_languages">
                    <h2 class="yuz-section-title"><?php esc_html_e('Website Languages', 'yuz-tra'); ?></h2>
                    <hr>
                    <table class="form-table">
                        <?php
                        // Ces champs lisent/écrivent UNIQUEMENT yuz_tra_general
                        $this->renderer->render_default_language_field($options_general);
                        $this->renderer->render_source_language_field($options_general);
                        $this->renderer->render_translatable_languages_field($options_general);
                        ?>
                    </table>
                </div>

                <div class="yuz-section" data-section="language_settings">
                    <h2 class="yuz-section-title"><?php esc_html_e('Language Settings', 'yuz-tra'); ?></h2>
                    <hr>
                    <table class="form-table">
                        <?php
                        $this->renderer->render_native_language_name_field($options_settings);
                        $this->renderer->render_use_subdirectory_field($options_settings);
                        $this->renderer->render_force_lang_in_links_field($options_settings);
                        ?>
                    </table>
                </div>

                <div class="yuz-section" data-section="language_switcher">
                    <h2 class="yuz-section-title"><?php esc_html_e('Language Switcher', 'yuz-tra'); ?></h2>
                    <hr>
                    <?php
                    $this->renderer->render_shortcode_block($switcher_settings);
                    $this->renderer->render_menu_item_block($switcher_settings);
                    $this->renderer->render_floating_language_selection_block($switcher_settings);
                    $this->renderer->render_powered_by_block($switcher_settings);
                    ?>
                </div>

                <?php submit_button(__('Save Changes', 'yuz-tra')); ?>
            </form>
        </div>
        <?php

        $this->logger->log('success', 'Finished YUZ_General::render_tab');
    }
}

} else {

class YUZ_General {
    public static function init() {
        (new YUZ_Logger())->log('critical', 'GeneralInterface missing, disabling YUZ_General admin init');
        add_action('admin_notices', function() {
            ?>
            <div class="notice notice-error is-dismissible">
                <p><?php esc_html_e('Error: GeneralInterface missing. YUZ_General functionality disabled. Please check plugin files.', 'yuz-tra'); ?></p>
            </div>
            <?php
        });
    }
}

}

if (class_exists('YUZ_General')) {
    (new YUZ_Logger())->log('success', 'YUZ_General class created successfully at ' . current_time('mysql'));
    add_action('admin_init', ['YUZ_General', 'init']);
} else {
    (new YUZ_Logger())->log('critical', 'Failed to create YUZ_General class at ' . current_time('mysql'));
}

<?php
/**
 * YUZ CORE RULES — DO NOT VIOLATE
 *
 * Objectif :
 *   Verrouiller par VERBES les actions autorisées par fichier central.
 *   Tout verbe non listé ci-dessous est INTERDIT dans ce fichier.
 *
 * Exclusivités (fichiers centraux) — autorité unique :
 *
 * - class-yuz-assets.php  (Rôle: Assets)
 *     VERBES AUTORISÉS UNIQUEMENT ICI :
 *       - wp_register_script, wp_register_style
 *       - wp_enqueue_script, wp_enqueue_style
 *       - wp_localize_script
 *       - wp_add_inline_script, wp_add_inline_style
 *       - wp_set_script_translations
 *       - add_action('admin_enqueue_scripts' | 'wp_enqueue_scripts' | 'enqueue_block_editor_assets')
 *       - add_filter('script_loader_tag' | 'style_loader_tag' | 'clar_inline_script_hashes')
 *     INTERDIT ailleurs : tout enregistrement/enfilage/localisation/altération <script>/<link>.
 *
 * - class-yuz-ajax.php  (Rôle: AJAX)
 *     VERBES AUTORISÉS UNIQUEMENT ICI :
 *       - add_action('wp_ajax_*' | 'wp_ajax_nopriv_*')
 *       - check_ajax_referer
 *       - current_user_can
 *       - sanitize_* (toutes variantes), esc_* (toutes variantes)
 *       - wp_send_json, wp_send_json_success, wp_send_json_error
 *       - wp_die (uniquement fin d’endpoint)
 *     INTERDIT ailleurs : tout câblage d'actions AJAX, émission JSON des endpoints, contrôle caps pour AJAX.
 *
 * - class-yuz-renderer.php  (Rôle: Rendu Admin)
 *     VERBES AUTORISÉS UNIQUEMENT ICI :
 *       - add_menu_page, add_submenu_page
 *       - add_settings_section, add_settings_field (déclaration UI)
 *       - render_* (fonctions de sortie/templates), require template admin
 *       - wp_nonce_field (pour les formulaires d’admin)
 *     INTERDIT ailleurs : ajout de pages/menus d’admin ou de sections/champs Settings API.
 *
 * - class-yuz-contracts.php  (Rôle: Contrats)
 *     VERBES AUTORISÉS :
 *       - interface, trait (déclarations uniquement)
 *     INTERDIT : logique, hooks, sorties, accès WP_*.
 *
 * - class-yuz-fallbacks.php  (Rôle: Nulls/Fallbacks)
 *     VERBES AUTORISÉS : 
 *       - class Null Fallback* (implémentations minimales des contrats) 
 *     INTERDIT : hooks, I/O, enqueues, endpoints.
 *
 * Règle d’or (globale) :
 *   Aucun autre fichier ne doit enregistrer/enfiler/localiser des assets,
 *   ni câbler des hooks AJAX/menus d’admin,
 *   ni altérer les balises <script>/<link>,
 *   ni émettre des réponses JSON d’endpoint.
 *
 * Conseils :
 *   — Toute logique transverse doit passer par services/contrats, jamais par un hook non autorisé.
 *   — Les chemins d’assets ne doivent JAMAIS être câblés en dur hors class-yuz-assets.php.
 */

defined('ABSPATH') or exit;

require_once YUZ_TRA_INCLUDES . 'class-yuz-contracts.php';
require_once YUZ_TRA_INCLUDES . 'class-yuz-fallbacks.php';
require_once YUZ_TRA_INCLUDES . 'class-yuz-url-converter.php';
require_once YUZ_TRA_INCLUDES . 'class-yuz-services.php';

use YUZTRA\Interfaces\SwitcherInterface;
use YUZTRA\Interfaces\SettingsInterface;
use YUZTRA\Interfaces\UrlConverterInterface;
use YUZTRA\Interfaces\LoggerInterface;
use YUZTRA\Interfaces\AjaxInterface;
use YUZTRA\Interfaces\LanguagesInterface;
use YUZTRA\Fallbacks\NullLanguages;
use YUZTRA\Fallbacks\NullLanguageManager;
use YUZTRA\Fallbacks\NullTranslationManager;
use YUZTRA\Fallbacks\NullSettings;
use YUZTRA\Fallbacks\NullAjax;
use YUZTRA\Fallbacks\NullUrlConverter;
use YUZTRA\Fallbacks\NullLogger;

/**
 * Shim : permet d'appeler get_url_for_language($code) (1 arg) depuis le partial historique.
 */
if (!class_exists('YUZ_Switcher_Url_Converter_Shim')) {
    class YUZ_Switcher_Url_Converter_Shim implements UrlConverterInterface
    {
        private UrlConverterInterface $inner;

        public function __construct(UrlConverterInterface $inner)
        {
            $this->inner = $inner;
        }

        // Signature alignée sur l'interface (3 paramètres, $context optionnel)
        public function get_url_for_language(string $lang_code, string $current_url, array $context = []): string
        {
            if ($current_url === '' && method_exists($this->inner, 'cur_page_url')) {
                $current_url = $this->inner->cur_page_url();
            }
            return $this->inner->get_url_for_language($lang_code, $current_url, $context);
        }

        // Méthodes explicites pour respecter strictement l'interface
        public function get_active_locale(): string
        {
            return method_exists($this->inner, 'get_active_locale')
                ? $this->inner->get_active_locale()
                : (function_exists('get_locale') ? (string) get_locale() : 'en_US');
        }

        public function slug_for_locale(string $locale): string
        {
            return method_exists($this->inner, 'slug_for_locale')
                ? $this->inner->slug_for_locale($locale)
                : strtolower(str_replace('_', '-', $locale));
        }

        // Aides conservées pour compat shim
        public function cur_page_url(): string
        {
            return method_exists($this->inner, 'cur_page_url') ? $this->inner->cur_page_url() : '';
        }

        public function toAbsolute(string $url): string
        {
            return method_exists($this->inner, 'toAbsolute') ? $this->inner->toAbsolute($url) : $url;
        }

        public function toRelative(string $url): string
        {
            return method_exists($this->inner, 'toRelative') ? $this->inner->toRelative($url) : $url;
        }

        public function normalize(string $url): string
        {
            return method_exists($this->inner, 'normalize') ? $this->inner->normalize($url) : $url;
        }

        public function __call($name, $arguments)
        {
            return $this->inner->$name(...$arguments);
        }
    }
}


if (!class_exists('YUZ_Switcher')) {
class YUZ_Switcher implements SwitcherInterface {
    private const CACHE_GROUP   = 'yuz-switcher';
    private const CACHE_TTL     = 600;
    private const CACHE_VERSION = 1;

    private $languages;
    private $settings;
    private $ajax;
    private $url_converter;
    private $logger;
    private $db;

    /** @var array<string,string> */
    private static array $render_cache = [];

    public function __construct(
        LanguagesInterface $languages = null,
        SettingsInterface $settings = null,
        AjaxInterface $ajax = null,
        UrlConverterInterface $url_converter = null,
        LoggerInterface $logger = null,
        $db = null // pas de typehint strict ici pour éviter les fatals si la classe n'est pas chargée
    ) {
        $this->languages     = $languages ?? new NullLanguages();
        $this->settings      = $settings ?? new NullSettings();
        $this->ajax          = $ajax ?? new NullAjax();
        $this->url_converter = $url_converter ?? new NullUrlConverter();
        $this->logger        = $logger ?? new NullLogger();
        $this->db            = $db;
        $this->logger->log('info', 'YUZ_Switcher instantiated with dependencies');
    }

    private static $initialized = false;

    public static function init(): void {
        if (self::$initialized) { return; }
        self::$initialized = true;

        $logger       = class_exists('YUZ_Logger') ? new \YUZ_Logger() : new NullLogger();
        $health_check = class_exists('YUZ_Health_Check') ? new \YUZ_Health_Check($logger) : null;
        $db           = class_exists('YUZ_DB') ? new \YUZ_DB($logger, $health_check) : null;

        // Dépendances via la factory
        $settings  = class_exists('YUZ_Services') && method_exists('YUZ_Services','settings')  ? \YUZ_Services::settings()  : new NullSettings();
        $languages = class_exists('YUZ_Services') && method_exists('YUZ_Services','languages') ? \YUZ_Services::languages() : new NullLanguages();

        // Convertisseur réel + SHIM (pour compat du partial)
        $real_converter = new \YUZ_Url_Converter($settings);
        $url_converter  = new YUZ_Switcher_Url_Converter_Shim($real_converter);

        $instance = new self($languages, $settings, null, $url_converter, $logger, $db);

        $flags = method_exists($settings, 'runtime_flags') ? (array)$settings->runtime_flags() : ['switcher_enabled' => true];
        if (empty($flags['switcher_enabled'])) {
            $instance->logger->log('info', 'Switcher disabled via flags, skipping init');
            return;
        }

        $instance->logger->log('info', 'Initializing YUZ_Switcher');

        // Shortcode + menu + floating + hreflang
        add_shortcode('yuz_language_switcher', [$instance, 'render_shortcode_switcher']);
        add_filter('wp_nav_menu_items',       [$instance, 'add_menu_switcher'], 10, 2);
        add_action('yuz/footer/frontend',     [$instance, 'render_floating_switcher'], 10);
        add_action('wp_head',                 [$instance, 'add_hreflang_tags']);

        $instance->logger->log('success', 'YUZ_Switcher initialized');
    }

    protected function log($level, $message, $context = []) {
        $this->logger->log($level, $message, array_merge($context, ['class' => __CLASS__]));
    }

    /**
     * Rend le switcher en shortcode.
     */
    public function render_shortcode_switcher(array $atts): string {
        $this->log('info', 'Rendering shortcode switcher');

        $flags = method_exists($this->settings,'runtime_flags') ? (array)$this->settings->runtime_flags() : ['switcher_enabled'=>true];
        if (empty($flags['switcher_enabled'])) { return ''; }

        $settings = $this->normalize_switcher_settings(
            (array) $this->settings->get_option('yuz_tra_switcher')
        );
        if (!$this->is_switcher_mode_enabled($settings, 'shortcode_enabled')) { return ''; }

        $atts = shortcode_atts([
            'format'    => $settings['shortcode_format'] ?? 'flags-full-names',
            // unify widget options across modes; keep safe defaults
            'mode'      => 'shortcode',                // shortcode | floating | menu
            'theme'     => $settings['floating_theme']   ?? 'dark',
            'position'  => $settings['floating_position'] ?? 'bottom-right',
            'poweredby' => $settings['show_poweredby']   ?? false,
        ], $atts, 'yuz_language_switcher');

        $languages = $this->languages->get_translatable_languages();
        if (empty($languages)) { return ''; }

        // Variables attendues par le partial
        $format          = sanitize_text_field($atts['format']);
        $mode_in         = sanitize_text_field($atts['mode']);
        $mode            = in_array($mode_in, ['shortcode','floating','menu'], true) ? $mode_in : 'shortcode';
        $theme           = sanitize_text_field($atts['theme']);
        $position_attr   = sanitize_text_field($atts['position']);
        if ($position_attr === '' && isset($settings['floating_position'])) {
            $position_attr = sanitize_text_field($settings['floating_position']);
        }
        // Apply fixed positioning for floating and shortcode (inherits floating position by default)
        $position        = ($mode === 'floating')
            ? sanitize_text_field($settings['floating_position'] ?? 'bottom-right')
            : $position_attr;
        $show_poweredby  = filter_var($atts['poweredby'], FILTER_VALIDATE_BOOLEAN) || !empty($settings['show_poweredby']);
        $current_lang    = method_exists($this->url_converter, 'get_active_locale')
            ? $this->url_converter->get_active_locale()
            : get_locale();
        $use_native_name = !empty(get_option('yuz_tra_settings', [])['native_language_name']);
        $translated_strings = [
            'current_lang_label' => esc_html__('Current language: %s, click to change', 'yuz_translation'),
            'switch_to_label'    => esc_html__('Switch to %s', 'yuz_translation'),
            'powered_by'         => esc_html__('Powered by', 'yuz_translation'),
            'powered_by_yuzurz'  => esc_html__('YoUZurz', 'yuz_translation'),
        ];

        $current_url_for_switcher = $this->current_url_for_switcher();
        $cache_key = $this->build_switcher_cache_key([
            'mode'          => $mode,
            'format'        => $format,
            'theme'         => $theme,
            'position'      => $position,
            'current_lang'  => $current_lang,
            'poweredby'     => $show_poweredby ? 1 : 0,
            'native'        => $use_native_name ? 1 : 0,
            'languages'     => $this->languages_signature($languages),
            'url_sig'       => $this->url_signature($current_url_for_switcher),
        ]);

        $lang_codes = array_values(array_filter(array_map(static function($lang) {
            return method_exists($lang, 'get_code') ? (string) $lang->get_code() : null;
        }, (array) $languages)));

        $this->log('debug', 'Switcher context prepared', [
            'mode'          => $mode,
            'format'        => $format,
            'theme'         => $theme,
            'position'      => $position,
            'current_lang'  => $current_lang,
            'language_codes'=> $lang_codes,
            'cache_key'     => $cache_key,
            'url_signature' => $this->url_signature($current_url_for_switcher),
        ]);

        // Enqueue via fabrique
        if (method_exists('YUZ_Assets','require')) { YUZ_Assets::require('switcher'); }

        $render_context = [
            'format'                 => $format,
            'mode'                   => $mode,
            'theme'                  => $theme,
            'position'               => $position,
            'show_poweredby'         => $show_poweredby,
            'current_lang'           => $current_lang,
            'use_native_name'        => $use_native_name,
            'languages'              => $languages,
            'translated_strings'     => $translated_strings,
            'settings'               => $settings,
            'current_url_for_switcher' => $current_url_for_switcher,
        ];
        [$output, $from_cache] = $this->render_with_cache($cache_key, function () use ($render_context) {
            return $this->render_switcher_partial($render_context);
        });

        $this->log($from_cache ? 'info' : 'success', 'Shortcode switcher rendered successfully', [
            'cache_key'    => $cache_key,
            'from_cache'   => $from_cache,
            'language_cnt' => count($languages),
        ]);
        return $output;
    }

    /**
     * Ajoute le switcher dans un menu WP.
     */
    public function add_menu_switcher(string $items, object $args): string {
        $this->log('info', 'Adding language switcher to menu');

        $flags = method_exists($this->settings,'runtime_flags') ? (array)$this->settings->runtime_flags() : ['switcher_enabled'=>true];
        if (empty($flags['switcher_enabled'])) { return $items; }

        $settings = $this->normalize_switcher_settings(
            (array) $this->settings->get_option('yuz_tra_switcher')
        );
        if (!$this->is_switcher_mode_enabled($settings, 'menu_enabled')) { return $items; }

        $languages = $this->languages->get_translatable_languages();
        if (empty($languages)) { return $items; }

        $format          = $settings['menu_format'] ?? 'flags-full-names';
        $theme           = $settings['floating_theme']   ?? 'dark';
        $position        = $settings['floating_position']?? 'bottom-right';
        $show_poweredby  = !empty($settings['show_poweredby']);
        $current_lang    = method_exists($this->url_converter, 'get_active_locale')
            ? $this->url_converter->get_active_locale()
            : get_locale();
        $mode            = 'menu';
        $use_native_name = !empty(get_option('yuz_tra_settings', [])['native_language_name']);
        $translated_strings = [
            'current_lang_label' => esc_html__('Current language: %s, click to change', 'yuz_translation'),
            'switch_to_label'    => esc_html__('Switch to %s', 'yuz_translation'),
            'powered_by'         => esc_html__('Powered by', 'yuz_translation'),
            'powered_by_yuzurz'  => esc_html__('YoUZurz', 'yuz_translation'),
        ];

        $current_url_for_switcher = $this->current_url_for_switcher();
        $cache_key = $this->build_switcher_cache_key([
            'mode'          => $mode,
            'format'        => $format,
            'theme'         => $theme,
            'position'      => $position,
            'current_lang'  => $current_lang,
            'poweredby'     => $show_poweredby ? 1 : 0,
            'native'        => $use_native_name ? 1 : 0,
            'languages'     => $this->languages_signature($languages),
            'url_sig'       => $this->url_signature($current_url_for_switcher),
        ]);

        $lang_codes = array_values(array_filter(array_map(static function($lang) {
            return method_exists($lang, 'get_code') ? (string) $lang->get_code() : null;
        }, (array) $languages)));

        $this->log('debug', 'Switcher context prepared', [
            'mode'          => $mode,
            'format'        => $format,
            'theme'         => $theme,
            'position'      => $position,
            'current_lang'  => $current_lang,
            'language_codes'=> $lang_codes,
            'cache_key'     => $cache_key,
            'url_signature' => $this->url_signature($current_url_for_switcher),
        ]);

        if (method_exists('YUZ_Assets','require')) { YUZ_Assets::require('switcher'); }

        $menu_context = [
            'format'                 => $format,
            'mode'                   => $mode,
            'theme'                  => $theme,
            'position'               => $position,
            'show_poweredby'         => $show_poweredby,
            'current_lang'           => $current_lang,
            'use_native_name'        => $use_native_name,
            'languages'              => $languages,
            'translated_strings'     => $translated_strings,
            'settings'               => $settings,
            'current_url_for_switcher' => $current_url_for_switcher,
        ];
        [$menu_item, $from_cache] = $this->render_with_cache($cache_key, function () use ($menu_context) {
            return $this->render_switcher_partial($menu_context);
        });

        $items .= '<li class="menu-item yuz-language-switcher mode-in-menu">' . $menu_item . '</li>';
        $this->log($from_cache ? 'info' : 'success', 'Language switcher added to menu', [
            'cache_key'    => $cache_key,
            'from_cache'   => $from_cache,
            'language_cnt' => count($languages),
        ]);
        return $items;
    }

    /**
     * Rend le switcher flottant en footer.
     */
    public function render_floating_switcher(): void {
        $this->log('info', 'Rendering floating language switcher');

        $flags = method_exists($this->settings,'runtime_flags') ? (array)$this->settings->runtime_flags() : ['switcher_enabled'=>true];
        if (empty($flags['switcher_enabled'])) { return; }

        $settings = $this->normalize_switcher_settings(
            (array) $this->settings->get_option('yuz_tra_switcher')
        );
        if (!$this->is_switcher_mode_enabled($settings, 'floating_enabled')) { return; }

        $languages = $this->languages->get_translatable_languages();
        if (!is_array($languages) || count($languages) < 2) { return; }

        $format          = $settings['floating_format'] ?? 'flags-full-names';
        $theme           = $settings['floating_theme']  ?? 'dark';
        $position        = $settings['floating_position'] ?? 'bottom-right';
        $show_poweredby  = !empty($settings['show_poweredby']);
        $current_lang    = method_exists($this->url_converter, 'get_active_locale')
            ? $this->url_converter->get_active_locale()
            : get_locale();
        $mode            = 'floating';
        $use_native_name = !empty(get_option('yuz_tra_settings', [])['native_language_name']);
        $translated_strings = [
            'current_lang_label' => esc_html__('Current language: %s, click to change', 'yuz_translation'),
            'switch_to_label'    => esc_html__('Switch to %s', 'yuz_translation'),
            'powered_by'         => esc_html__('Powered by', 'yuz_translation'),
            'powered_by_yuzurz'  => esc_html__('YoUZurz', 'yuz_translation'),
        ];

        $current_url_for_switcher = $this->current_url_for_switcher();
        $cache_key = $this->build_switcher_cache_key([
            'mode'          => $mode,
            'format'        => $format,
            'theme'         => $theme,
            'position'      => $position,
            'current_lang'  => $current_lang,
            'poweredby'     => $show_poweredby ? 1 : 0,
            'native'        => $use_native_name ? 1 : 0,
            'languages'     => $this->languages_signature($languages),
            'url_sig'       => $this->url_signature($current_url_for_switcher),
        ]);

        $lang_codes = array_values(array_filter(array_map(static function($lang) {
            return method_exists($lang, 'get_code') ? (string) $lang->get_code() : null;
        }, (array) $languages)));

        $this->log('debug', 'Switcher context prepared', [
            'mode'          => $mode,
            'format'        => $format,
            'theme'         => $theme,
            'position'      => $position,
            'current_lang'  => $current_lang,
            'language_codes'=> $lang_codes,
            'cache_key'     => $cache_key,
            'url_signature' => $this->url_signature($current_url_for_switcher),
        ]);

        if (method_exists('YUZ_Assets','require')) { YUZ_Assets::require('switcher'); }

        $floating_context = [
            'format'                 => $format,
            'mode'                   => $mode,
            'theme'                  => $theme,
            'position'               => $position,
            'show_poweredby'         => $show_poweredby,
            'current_lang'           => $current_lang,
            'use_native_name'        => $use_native_name,
            'languages'              => $languages,
            'translated_strings'     => $translated_strings,
            'settings'               => $settings,
            'current_url_for_switcher' => $current_url_for_switcher,
        ];
        [$output, $from_cache] = $this->render_with_cache($cache_key, function () use ($floating_context) {
            return $this->render_switcher_partial($floating_context);
        });

        echo $output;
        $this->log($from_cache ? 'info' : 'success', 'Floating switcher rendered successfully', [
            'cache_key'    => $cache_key,
            'from_cache'   => $from_cache,
            'language_cnt' => count($languages),
        ]);
    }

    /**
     * Ajoute les balises hreflang <link rel="alternate">.
     */
    public function add_hreflang_tags(): void {
        $this->log('info', 'Generating hreflang tags');

        $flags = method_exists($this->settings,'runtime_flags') ? (array)$this->settings->runtime_flags() : ['switcher_enabled'=>true];
        if (empty($flags['switcher_enabled'])) { return; }

        $languages = $this->languages->get_translatable_languages();
        if (empty($languages)) { return; }

        $current_url = $this->url_converter->cur_page_url();

        foreach ($languages as $lang) {
            if (!method_exists($lang, 'get_code')) { continue; }
            $lang_code = (string)$lang->get_code();
            $href = esc_url($this->url_converter->get_url_for_language($lang_code, $current_url));
            echo "<link rel=\"alternate\" hreflang=\"{$lang_code}\" href=\"{$href}\" />\n";
        }

        $this->log('success', 'Hreflang tags generated successfully');
    }

    /* -----------------------------------------------------------
     * --- Shims pour satisfaire SwitcherInterface sans violer
     * --- les Core Rules (no enqueue ici, pas d’AJAX/JSON ici)
     * ----------------------------------------------------------- */

    /** @inheritDoc */
    public function enqueue_scripts(): void {
        // No-op: les assets sont gérés exclusivement par class-yuz-assets.php
        // (on garde la méthode pour compat avec l’interface)
    }

    /** @inheritDoc */
    public function yuz_tra_sw_switch_language(): void {
        // No-op: les endpoints/JSON sont exclusifs à class-yuz-ajax.php
        // L’AJAX réel est implémenté dans YUZ_Ajax::yuz_tra_sw_switch_language()
    }

    /** @inheritDoc */
    public function switchLanguage(string $lang_code): void {
        // Compat: si jamais appelé côté PHP, on calcule simplement l’URL,
        // sans redirection ni sortie JSON (respect des Core Rules)
        try {
            $current = method_exists($this->url_converter, 'cur_page_url')
                ? $this->url_converter->cur_page_url()
                : '';
            $url = $this->url_converter->get_url_for_language($lang_code, $current);
            // Optionnel: log/filtre, mais on ne fait pas de redirect ici.
            do_action('yuz/switcher/php_switch_computed', $lang_code, $url);
        } catch (\Throwable $e) {
            if (class_exists('YUZ_Logger')) {
                (new \YUZ_Logger())->log('warning', 'switchLanguage shim failed: '.$e->getMessage());
            }
        }
    }

    /** @inheritDoc */
    public function getAvailableLanguages(): array {
        // Retourne la liste via le manager central, ou []
        if (method_exists($this->languages, 'get_translatable_languages')) {
            return (array) $this->languages->get_translatable_languages();
        }
        return [];
    }

    /**
     * (optionnel) API publique de compat
     * Ces deux méthodes existent déjà et correspondent à l’interface.
     */
    public function render_shortcode(array $atts): string { return $this->render_shortcode_switcher($atts); }
    public function add_menu_item(string $items, object $args): string { return $this->add_menu_switcher($items, $args); }

    /**
     * Fournit la config JS si besoin ailleurs (non utilisée ici).
     */
    private function get_js_settings(): array {
        $flags = method_exists($this->settings, 'runtime_flags') ? (array)$this->settings->runtime_flags() : [];
        $sw_settings = $this->normalize_switcher_settings(
            (array) $this->settings->get_option('yuz_tra_switcher')
        );

        $langs = [];
        $list  = $this->languages->get_translatable_languages();
        if (is_array($list)) {
            foreach ($list as $lang) {
                try {
                    $code  = method_exists($lang,'get_code')  ? (string)$lang->get_code()  : '';
                    $label = method_exists($lang,'get_label') ? (string)$lang->get_label()
                           : (method_exists($lang,'get_name') ? (string)$lang->get_name() : $code);
                    if ($code !== '') { $langs[] = ['code'=>$code, 'label'=>$label]; }
                } catch (\Throwable $e) { /* ignore */ }
            }
        }

        return [
            'flags' => [
                'switcher_enabled' => !empty($flags['switcher_enabled']),
            ],
            'settings' => [
                'format'        => $sw_settings['shortcode_format'] ?? $sw_settings['menu_format'] ?? $sw_settings['floating_format'] ?? 'flags-full-names',
                'show_poweredby'=> !empty($sw_settings['show_poweredby']),
            ],
            'context' => [
                'current_url'  => method_exists($this->url_converter,'cur_page_url') ? $this->url_converter->cur_page_url() : '',
                'current_lang' => get_locale(),
            ],
            'languages' => $langs,
            'ajax' => [
                'url'           => admin_url('admin-ajax.php'),
                'nonce'         => wp_create_nonce('yuz_tra_nonce'),
                'action'        => 'yuz_tra_sw_switch_language',
                'centralAction' => 'yuz_tra_ajax',
                'route'         => 'sw_switch_language',
            ],
        ];
    }

    private function current_url_for_switcher(): string {
        if (!method_exists($this->url_converter, 'cur_page_url')) {
            return '';
        }
        $url = (string) $this->url_converter->cur_page_url();
        if ($url !== '') {
            $url = remove_query_arg(['yuz-edit-translation', 'yuz-edit-translation-url'], $url);
        }
        return $url;
    }

    private function normalize_switcher_settings(array $settings): array {
        $toggles = ['shortcode_enabled', 'menu_enabled', 'floating_enabled'];
        $has_enabled_toggle = false;
        foreach ($toggles as $toggle) {
            if (array_key_exists($toggle, $settings) && $this->switcher_flag_is_true($settings[$toggle])) {
                $has_enabled_toggle = true;
                break;
            }
        }
        if (!$has_enabled_toggle) {
            foreach ($toggles as $toggle) {
                $settings[$toggle] = true;
            }
        }
        return $settings;
    }

    private function is_switcher_mode_enabled(array $settings, string $key): bool {
        if (!array_key_exists($key, $settings)) {
            return true;
        }
        return $this->switcher_flag_is_true($settings[$key]);
    }

    private function switcher_flag_is_true($value): bool {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return ((int) $value) === 1;
        }
        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
        }
        return false;
    }

    private function languages_signature($languages): string {
        if (!is_array($languages)) {
            return 'none';
        }
        $parts = [];
        foreach ($languages as $language) {
            $code = method_exists($language, 'get_code')
                ? (string) $language->get_code()
                : (method_exists($language, 'get_language_code') ? (string) $language->get_language_code() : '');
            $slug = method_exists($language, 'get_slug')
                ? (string) $language->get_slug()
                : (method_exists($language, 'getSlug') ? (string) $language->getSlug() : '');
            $parts[] = $code . ':' . $slug;
        }
        return implode('|', $parts);
    }

    private function url_signature(string $url): string {
        if ($url === '') {
            return 'no-url';
        }
        return substr(sha1($url), 0, 12);
    }

    private function build_switcher_cache_key(array $context): string {
        $context['cache_version'] = self::CACHE_VERSION;
        ksort($context);
        $hash = substr(sha1(wp_json_encode($context)), 0, 32);
        if (class_exists('YUZ_Settings_Service')) {
            return YUZ_Settings_Service::cache_key('switcher:' . $hash);
        }
        return 'yuztra:switcher:' . $hash;
    }

    /**
     * @param callable():string $renderer
     * @return array{0:string,1:bool}
     */
    private function render_with_cache(string $cache_key, callable $renderer): array {
        if (isset(self::$render_cache[$cache_key])) {
            $this->log('debug', 'Switcher cache hit (request)', ['cache_key' => $cache_key]);
            return [self::$render_cache[$cache_key], true];
        }

        if ($this->cache_available()) {
            $cached = wp_cache_get($cache_key, self::CACHE_GROUP);
            if (is_string($cached)) {
                self::$render_cache[$cache_key] = $cached;
                $this->log('debug', 'Switcher cache hit (persistent)', ['cache_key' => $cache_key]);
                return [$cached, true];
            }
        }

        $output = (string) $renderer();
        self::$render_cache[$cache_key] = $output;

        if ($this->cache_available()) {
            wp_cache_set($cache_key, $output, self::CACHE_GROUP, self::CACHE_TTL);
            $this->log('debug', 'Switcher cache stored', [
                'cache_key' => $cache_key,
                'ttl'       => self::CACHE_TTL,
            ]);
        }

        return [$output, false];
    }

    private function render_switcher_partial(array $context): string {
        $format                 = $context['format'] ?? 'flags-full-names';
        $mode                   = $context['mode'] ?? 'shortcode';
        $theme                  = $context['theme'] ?? 'dark';
        $position               = $context['position'] ?? 'bottom-right';
        $show_poweredby         = !empty($context['show_poweredby']);
        $current_lang           = $context['current_lang'] ?? get_locale();
        $use_native_name        = !empty($context['use_native_name']);
        $languages              = $context['languages'] ?? [];
        $translated_strings     = $context['translated_strings'] ?? [];
        $settings               = $context['settings'] ?? [];
        $current_url_for_switcher = $context['current_url_for_switcher'] ?? '';

        ob_start();
        include plugin_dir_path(YUZ_TRA_PLUGIN_FILE) . 'partials/yuz-language-switcher.php';
        return (string) ob_get_clean();
    }

    private function cache_available(): bool {
        return function_exists('wp_cache_get') && function_exists('wp_cache_set');
    }
}
}

<?php
/**
 * Class YUZ_Languages
 * Manages language-related operations for the YUZ-TRA plugin.
 *
 * @package YUZ_Translation
 */

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

// Load core interfaces & fallbacks
require_once YUZ_TRA_INCLUDES . 'class-yuz-contracts.php';
require_once YUZ_TRA_INCLUDES . 'class-yuz-fallbacks.php';
require_once YUZ_TRA_INCLUDES . 'class-yuz-logger.php';
require_once YUZ_TRA_INCLUDES . 'class-yuz-health-check.php';
require_once YUZ_TRA_INCLUDES . 'class-yuz-db.php';
require_once YUZ_TRA_INCLUDES . 'class-yuz-ajax.php';
require_once YUZ_TRA_INCLUDES . 'class-yuz-language.php';

use YUZTRA\Interfaces\Language;
use YUZTRA\Interfaces\SettingsInterface;
use YUZTRA\Interfaces\DBInterface;
use YUZTRA\Interfaces\LanguagesInterface;
use YUZTRA\Interfaces\AjaxInterface;
use YUZTRA\Interfaces\LanguageManagerInterface;
use YUZTRA\Fallbacks\NullSettings;
use YUZTRA\Fallbacks\NullDB;
use YUZTRA\Fallbacks\NullLanguages;
use YUZTRA\Fallbacks\NullAjax;
use YUZTRA\Fallbacks\NullTranslationManager;
use YUZTRA\Fallbacks\NullLanguageManager;

/**
 * Fusion de YUZ_LanguageManager et YUZ_Languages en une classe unique
 * implémentant les deux interfaces.
 */
class YUZ_Languages implements LanguagesInterface, LanguageManagerInterface {

    const DB_VERSION = '1.0.2'; // Aligned with class-yuz-db.php

    /** @var SettingsInterface */
    private SettingsInterface $settings;

    /** @var DBInterface */
    private DBInterface $db;

    /** @var LanguagesInterface|null */
    private ?LanguagesInterface $languages = null;

    /** @var mixed Adapter (optionnel) pour la détection */
    protected $adapter = null;

    const TRANSIENT_TTL = 3600; // TTL ~1h

    // Transient & caches keys (exposées publiquement pour usage externe sûr)
    public const TRANSIENT_LOCK_SETTINGS_SYNC = 'settings_sync_lock';
    public const TRANSIENT_DEFAULT_LANGUAGE   = 'default_language';
    public const TRANSIENT_SOURCE_LANGUAGE    = 'source_language';
    public const TRANSIENT_TRANSLATABLE       = 'translatable_languages';
    public const TRANSIENT_ALL                = 'all_languages';

    public const CACHE_SETTINGS_SYNC          = 'settings_sync';
    public const CACHE_LANGUAGE_PREFIX        = 'language_';

    /** @var array<string,int> */
    private static array $languageIdCache = [];

    /**
     * Filet de sécurité : noms natifs pour quelques locales communes.
     * Sert uniquement de fallback si DB/WP/intl ne répondent pas.
     */
    private const NATIVE_NAMES = [
        'ar_SA' => 'العربية',
        'az_AZ' => 'Azərbaycan dili',
        'eu_ES' => 'Euskara',
        'bn_BD' => 'বাংলা',
        'bg_BG' => 'Български',
        'ca_ES' => 'Català',
        'zh_CN' => '中文 (简体)',
        'zh_TW' => '中文 (繁體)',
        'cs_CZ' => 'Čeština',
        'da_DK' => 'Dansk',
        'nl_NL' => 'Nederlands',
        'en_GB' => 'English (UK)',
        'en_US' => 'English (US)', // fallback minimal, logique
        'et_EE' => 'Eesti',
        'fi_FI' => 'Suomi',
        'fr_FR' => 'Français',
        'gl_ES' => 'Galego',
        'de_DE' => 'Deutsch',
        'el_GR' => 'Ελληνικά',
        'he_IL' => 'עברית',
        'hi_IN' => 'हिन्दी',
        'hu_HU' => 'Magyar',
        'id_ID' => 'Bahasa Indonesia',
        'ga_IE' => 'Gaelige',
        'it_IT' => 'Italiano',
        'ja_JP' => '日本語',
        'lv_LV' => 'Latviešu',
        'lt_LT' => 'Lietuvių',
        'ms_MY' => 'Bahasa Melayu',
        'no_NO' => 'Norsk',
        'fa_IR' => 'فارسی',
        'pl_PL' => 'Polski',
        'pt_BR' => 'Português (Brasil)',
        'pt_PT' => 'Português (Portugal)',
        'ro_RO' => 'Română',
        'ru_RU' => 'Русский',
        'sk_SK' => 'Slovenčina',
        'sl_SI' => 'Slovenščina',
        'es_ES' => 'Español',
        'sv_SE' => 'Svenska',
        'tl_PH' => 'Tagalog',
        'th_TH' => 'ไทย',
        'tr_TR' => 'Türkçe',
        'uk_UA' => 'Українська',
        'ur_PK' => 'اردو',
        'vi_VN' => 'Tiếng Việt',
    ];

    /**
     * Constructor to inject dependencies.
     *
     * @param SettingsInterface $settings
     * @param DBInterface $db
     */
    public function __construct(SettingsInterface $settings, DBInterface $db) {
        $this->settings = $settings;
        $this->db       = $db;
    }

    // Setters pour injection/stubs
    public function set_settings(SettingsInterface $settings): void {
        $this->settings = $settings;
    }
    public function set_languages(LanguagesInterface $languages): void {
        $this->languages = $languages;
    }

    /** Normalise un code langue vers xx_XX */
    private static function normalize_code(?string $lc): ?string {
        if (!$lc) return null;
        $lc = str_replace('-', '_', trim($lc));
        if (strlen($lc) === 2) {
            $lc = strtolower($lc) === 'en' ? 'en_US' : strtolower($lc) . '_' . strtoupper($lc);
        }
        if (preg_match('/^[a-z]{2}_[a-z]{2}$/i', $lc)) {
            $lc = substr($lc, 0, 2) . '_' . strtoupper(substr($lc, 3, 2));
        }
        return $lc;
    }

    /**
     * Resolve le nom natif d’une langue en priorisant DB/WP/intl puis fallback statique.
     */
    private function resolve_native_name(string $code, ?string $fallback = null): string {
        $norm = self::normalize_code($code) ?? '';
        // 1) DB / fallback (déjà fiable)
        if (is_string($fallback) && $fallback !== '') {
            return $fallback;
        }
        // 2) WordPress catalog
        if (function_exists('wp_get_available_translations')) {
            $catalog = wp_get_available_translations();
            if (isset($catalog[$norm]['native_name']) && $catalog[$norm]['native_name']) {
                return $catalog[$norm]['native_name'];
            }
            $short = substr($norm, 0, 2);
            foreach ($catalog as $loc => $info) {
                if (substr($loc, 0, 2) === $short && !empty($info['native_name'])) {
                    return $info['native_name'];
                }
            }
        }
        // 3) PHP intl
        if (class_exists('\Locale')) {
            $locale = $norm ?: 'en_US';
            $name   = \Locale::getDisplayLanguage($locale, $locale);
            if (is_string($name) && $name !== '') {
                return $name;
            }
        }
        // 4) Fallback statique minimal
        if (isset(self::NATIVE_NAMES[$norm])) {
            return self::NATIVE_NAMES[$norm];
        }
        // 5) Dernier recours
        return strtoupper($norm ?: $code);
    }

    /**
     * Initializes the class.
     */
    public static function init() {
        if (class_exists('YUZ_Services')) {
            YUZ_Services::init(); // boot & wiring
            $instance = YUZ_Services::languages();

            add_action('yuz_tra_after_enforce_language_rules', [ $instance, 'sync_settings' ], 10, 3);
            add_action('wp_ajax_yuz_tra_yuz_lang_settings',        [$instance, 'yuz_lang_settings']);
            add_action('wp_ajax_yuz_tra_yuz_swap_source_and_target',[$instance, 'swap_source_and_target']);
            add_action('wp_ajax_yuz_tra_ajax_update_weights',       [$instance, 'ajax_update_weights']);

            // Avoid double registration with YUZ_Ajax: only register if not already present
            if (!has_action('wp_ajax_yuz_tra_ws_cre_language'))   add_action('wp_ajax_yuz_tra_ws_cre_language',   [$instance, 'ajax_add_language']);
            if (!has_action('wp_ajax_yuz_tra_ws_cre_alllang'))    add_action('wp_ajax_yuz_tra_ws_cre_alllang',    [$instance, 'ajax_add_all_languages']);
            if (!has_action('wp_ajax_yuz_tra_ws_del_language'))   add_action('wp_ajax_yuz_tra_ws_del_language',   [$instance, 'ajax_remove_language']);
            if (!has_action('wp_ajax_yuz_tra_ws_del_alllang'))    add_action('wp_ajax_yuz_tra_ws_del_alllang',    [$instance, 'ajax_remove_all_languages']);
            if (!has_action('wp_ajax_yuz_tra_ws_upd_settings'))   add_action('wp_ajax_yuz_tra_ws_upd_settings',   [$instance, 'ajax_update_ws_settings']); // slugs/codes/default/source
        }
    }

    private static function languages_table(): string {
        global $wpdb;
        if (!isset($wpdb) || !($wpdb instanceof \wpdb)) {
            return '';
        }
        return $wpdb->prefix . 'yuz_tra_languages';
    }

    private static function table_exists(): bool {
        global $wpdb;
        $table = self::languages_table();
        if ($table === '' || !isset($wpdb) || !($wpdb instanceof \wpdb)) {
            return false;
        }
        $like = str_replace(['_', '%'], ['\\_', '\\%'], $table);
        return (bool) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $like));
    }

    private static function fetch_flagged_code(string $flagColumn): ?string {
        global $wpdb;
        if (!self::table_exists()) {
            return null;
        }
        $flag = $flagColumn === 'is_source' ? 'is_source' : 'is_default';
        $table = self::languages_table();
        $code  = $wpdb->get_var("SELECT language_code FROM {$table} WHERE {$flag} = 1 LIMIT 1");
        return is_string($code) && $code !== '' ? $code : null;
    }

    private static function pick_fallback_code(): ?string {
        global $wpdb;
        if (!self::table_exists()) {
            return null;
        }
        $table = self::languages_table();
        $code  = $wpdb->get_var("SELECT language_code FROM {$table} ORDER BY is_default DESC, is_source DESC, language_weight DESC, id ASC LIMIT 1");
        return is_string($code) && $code !== '' ? $code : null;
    }

    private static function code_exists(?string $code): bool {
        global $wpdb;
        if (!self::table_exists() || !$code) {
            return false;
        }
        $table = self::languages_table();
        return (bool) $wpdb->get_var($wpdb->prepare("SELECT 1 FROM {$table} WHERE language_code = %s LIMIT 1", $code));
    }

    public static function language_id(string $code): ?int {
        $normalized = self::normalize_code($code);
        if (!$normalized) {
            return null;
        }
        $key = strtoupper($normalized);
        if (array_key_exists($key, self::$languageIdCache)) {
            $cached = self::$languageIdCache[$key];
            return $cached > 0 ? $cached : null;
        }
        if (!self::table_exists()) {
            self::$languageIdCache[$key] = 0;
            return null;
        }
        global $wpdb;
        $table = self::languages_table();
        $id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE language_code = %s LIMIT 1",
            $normalized
        ));
        self::$languageIdCache[$key] = $id;
        return $id > 0 ? $id : null;
    }

    private static function bias_meta_key(string $source, string $default): string {
        $from = strtolower(str_replace('_', '-', $source));
        $to   = strtolower(str_replace('_', '-', $default));
        return "_yuz_bias_repaired_{$from}_to_{$to}";
    }

    public static function repair_bias(int $limit = 3): void {
        $source  = self::get_source_code();
        $default = self::get_default_code();
        if (!$source || !$default || strcasecmp($source, $default) === 0) {
            return;
        }
        if (!function_exists('get_post_types') || !function_exists('get_posts')) {
            return;
        }

        $lock_key = 'yuz_lang_bias_lock';
        if (get_transient($lock_key)) {
            return;
        }
        set_transient($lock_key, 1, MINUTE_IN_SECONDS);

        try {
            self::process_bias_batch($source, $default, max(1, (int) apply_filters('yuz/lang/bias_batch', $limit)));
        } finally {
            delete_transient($lock_key);
        }
    }

    private static function process_bias_batch(string $source, string $default, int $limit): void {
        $source_id  = self::language_id($source);
        $default_id = self::language_id($default);
        if (!$source_id || !$default_id) {
            return;
        }

        $meta_key = self::bias_meta_key($source, $default);
        $post_types = apply_filters('yuz/lang/bias_post_types', array_keys(get_post_types(['public' => true])));

        $query = new \WP_Query([
            'post_type'      => $post_types,
            'post_status'    => 'publish',
            'posts_per_page' => $limit,
            'meta_query'     => [
                [
                    'key'     => $meta_key,
                    'compare' => 'NOT EXISTS',
                ],
            ],
            'orderby'        => 'ID',
            'order'          => 'ASC',
            'fields'         => 'ids',
        ]);

        if (empty($query->posts)) {
            return;
        }

        $translator = class_exists('YUZ_Services') && method_exists('YUZ_Services', 'tm')
            ? YUZ_Services::tm()
            : null;
        if (!$translator || !method_exists($translator, 'translate')) {
            return;
        }

        foreach ($query->posts as $post_id) {
            self::repair_single_post((int) $post_id, $source, $default, $source_id, $default_id, $translator, $meta_key);
        }
        wp_reset_postdata();
    }

    private static function repair_single_post(int $post_id, string $source_code, string $default_code, int $source_id, int $default_id, $translator, string $meta_key): void {
        $post = get_post($post_id);
        if (!$post) {
            return;
        }

        $original_content = (string) $post->post_content;
        $original_title   = (string) $post->post_title;
        $original_excerpt = (string) $post->post_excerpt;

        $translated_content = self::guard_markup_safety(
            $original_content,
            self::translate_rich_content($translator, $original_content, $source_id, $default_id),
            $post_id,
            'content'
        );
        $translated_title   = self::translate_text($translator, $original_title, $source_id, $default_id);
        $translated_excerpt = $original_excerpt !== ''
            ? self::guard_markup_safety(
                $original_excerpt,
                self::translate_rich_content($translator, $original_excerpt, $source_id, $default_id),
                $post_id,
                'excerpt'
            )
            : null;

        if (($translated_content === null || $translated_content === '') && class_exists('YUZ_Front_Renderer')) {
            $translated_content = YUZ_Front_Renderer::translate_post_field($original_content, $post_id, 'content');
        }
        if (($translated_title === null || $translated_title === '') && class_exists('YUZ_Front_Renderer')) {
            $translated_title = YUZ_Front_Renderer::translate_post_field($original_title, $post_id, 'title');
        }
        if ($original_excerpt !== '' && ($translated_excerpt === null || $translated_excerpt === '') && class_exists('YUZ_Front_Renderer')) {
            $translated_excerpt = YUZ_Front_Renderer::translate_post_field($original_excerpt, $post_id, 'excerpt');
        }

        $translated_content = self::guard_markup_safety($original_content, $translated_content, $post_id, 'content');
        $translated_excerpt = $original_excerpt !== '' ? self::guard_markup_safety($original_excerpt, $translated_excerpt, $post_id, 'excerpt') : null;

        if ($translated_content !== null && $translated_content !== '') {
            self::upsert_translation($post_id, 'content', $original_content, $translated_content, $source_id, $default_id, $default_code, 2, 'machine');
        }
        if ($translated_title !== null && $translated_title !== '') {
            self::upsert_translation($post_id, 'title', $original_title, $translated_title, $source_id, $default_id, $default_code, 2, 'machine');
        }
        if ($translated_excerpt !== null && $translated_excerpt !== '') {
            self::upsert_translation($post_id, 'excerpt', $original_excerpt, $translated_excerpt, $source_id, $default_id, $default_code, 2, 'machine');
        }

        self::upsert_mirror($post_id, 'content', $original_content, $source_id, $source_code);
        if ($original_title !== '') {
            self::upsert_mirror($post_id, 'title', $original_title, $source_id, $source_code);
        }
        if ($original_excerpt !== '') {
            self::upsert_mirror($post_id, 'excerpt', $original_excerpt, $source_id, $source_code);
        }

        update_post_meta($post_id, $meta_key, current_time('mysql'));

        if (class_exists('YUZ_Logger')) {
            (new YUZ_Logger())->log('info', 'Bias repair applied', [
                'post_id'     => $post_id,
                'source_lang' => $source_code,
                'default_lang'=> $default_code,
            ]);
        }
    }

    private static function translate_text($translator, string $text, int $source_id, int $target_id): ?string {
        $text = trim($text);
        if ($text === '' || $source_id === $target_id) {
            return $text === '' ? null : $text;
        }
        try {
            $result = $translator->translate($text, $source_id, $target_id);
            if (is_string($result)) {
                $result = trim($result);
            }
            if (is_string($result) && $result !== '') {
                return $result;
            }
        } catch (\Throwable $e) {
            if (class_exists('YUZ_Logger')) {
                (new YUZ_Logger())->log('warning', 'Bias translation failed', [
                    'message' => $e->getMessage(),
                    'source'  => $source_id,
                    'target'  => $target_id,
                ]);
            }
        }
        return null;
    }

    /**
     * Reject translated candidates that strip structural markup from the original text.
     * This prevents layout loss when translators return plain text for HTML/Gutenberg content.
     */
    private static function guard_markup_safety(string $original, ?string $candidate, ?int $post_id = null, string $context = 'content'): ?string {
        if ($candidate === null || $candidate === '') {
            return $candidate;
        }
        if (!self::contains_markup($original)) {
            return $candidate;
        }
        if (!self::contains_markup($candidate)) {
            if (class_exists('YUZ_Logger')) {
                (new YUZ_Logger())->log('warning', 'Discarded translation without markup', [
                    'post_id'   => $post_id,
                    'context'   => $context,
                    'snippet'   => substr($candidate, 0, 120),
                    'original_has_markup' => true,
                ]);
            }
            return null;
        }
        return $candidate;
    }

    private static function translate_rich_content($translator, string $content, int $source_id, int $target_id): ?string {
        $trimmed = trim($content);
        if ($trimmed === '') {
            return $content;
        }
        if (!self::contains_markup($content)) {
            return self::translate_text($translator, $content, $source_id, $target_id);
        }
        if (!class_exists('\DOMDocument')) {
            return self::translate_text($translator, $content, $source_id, $target_id);
        }

        libxml_use_internal_errors(true);
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->preserveWhiteSpace = false;
        $loaded = @$dom->loadHTML('<?xml encoding="UTF-8"?>' . $content, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        if (!$loaded) {
            return self::translate_text($translator, $content, $source_id, $target_id);
        }

        $xpath = new \DOMXPath($dom);
        $text_nodes = $xpath->query('//text()[normalize-space()]');
        if (!$text_nodes || $text_nodes->length === 0) {
            return self::translate_text($translator, $content, $source_id, $target_id);
        }

        $segments = [];
        $nodes = [];
        foreach ($text_nodes as $node) {
            $parent = $node->parentNode;
            if ($parent instanceof \DOMElement && self::should_skip_translation_node($parent)) {
                continue;
            }
            $raw = (string) $node->nodeValue;
            $trim = trim($raw);
            if ($trim === '') {
                continue;
            }
            if (!isset($segments[$trim])) {
                $segments[$trim] = null;
            }
            $nodes[] = [
                'node' => $node,
                'raw'  => $raw,
                'trim' => $trim,
            ];
        }

        if (empty($nodes)) {
            return self::translate_text($translator, $content, $source_id, $target_id);
        }

        foreach ($segments as $segment => $_) {
            $translated = self::translate_text($translator, $segment, $source_id, $target_id);
            if (!is_string($translated) || $translated === '') {
                $translated = $segment;
            }
            $segments[$segment] = $translated;
        }

        foreach ($nodes as $info) {
            $trim = $info['trim'];
            if (!isset($segments[$trim])) {
                continue;
            }
            $translated = $segments[$trim];
            $replacement = self::rebuild_text_with_padding($info['raw'], $trim, $translated);
            if ($replacement !== null) {
                $info['node']->nodeValue = $replacement;
            }
        }

        $result = $dom->saveHTML();
        if (strpos($result, '<?xml encoding="UTF-8"?>') === 0) {
            $result = substr($result, 23);
        } elseif (strpos($result, '<?xml encoding="UTF-8">') === 0) {
            $result = substr($result, 22);
        }
        return $result;
    }

    private static function rebuild_text_with_padding(string $raw, string $trimmed, string $translated): ?string {
        if ($trimmed === '') {
            return null;
        }
        $pattern = '/' . preg_quote($trimmed, '/') . '/u';
        $replaced = preg_replace($pattern, $translated, $raw, 1);
        if ($replaced === null) {
            $replaced = $translated;
        }
        return html_entity_decode($replaced, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private static function should_skip_translation_node(\DOMElement $element): bool {
        $skip_tags = ['script', 'style', 'noscript', 'template', 'textarea', 'code', 'pre', 'svg', 'symbol'];

        while ($element instanceof \DOMElement) {
            $tag = strtolower($element->tagName);
            if (in_array($tag, $skip_tags, true)) {
                return true;
            }
            if ($element->hasAttribute('data-yuz') && $element->getAttribute('data-yuz') === 'no-translate') {
                return true;
            }
            if ($element->hasAttribute('hidden')) {
                return true;
            }
            if ($element->getAttribute('aria-hidden') === 'true') {
                return true;
            }
            $element = $element->parentNode instanceof \DOMElement ? $element->parentNode : null;
        }

        return false;
    }

    /** Detects whether a text contains HTML tags, Gutenberg comments or shortcodes. */
    private static function contains_markup(string $text): bool {
        if ($text === '') {
            return false;
        }
        if (strpos($text, '<') !== false) {
            return true;
        }
        if (strpos($text, '<!--') !== false) {
            return true;
        }
        return (bool) preg_match('/\[[a-z0-9_-]+[^\]]*\]/i', $text);
    }

    private static function upsert_translation(int $post_id, string $context, string $original_text, string $translated_text, int $source_lang_id, int $target_lang_id, string $target_code, int $status = 1, string $origin = 'machine'): void {
        if (!self::table_exists()) {
            return;
        }
        global $wpdb;
        $translations = str_replace('yuz_tra_languages', 'yuz_tra_translations', self::languages_table());
        $existing_id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$translations} WHERE post_id = %d AND context = %s AND source_lang_id = %d AND target_lang_id = %d AND block_id = '' LIMIT 1",
            $post_id,
            $context,
            $source_lang_id,
            $target_lang_id
        ));

        $now = current_time('mysql');
        $normalized_origin = $origin === 'manual' ? 'manual' : 'machine';
        $normalized_code = self::normalize_code($target_code) ?? $target_code;

        $data = [
            'original_text'   => $original_text,
            'translated_text' => $translated_text,
            'language_code'   => $normalized_code,
            'status'          => (int) $status,
            'origin'          => $normalized_origin,
            'updated_at'      => $now,
        ];
        $format = ['%s', '%s', '%s', '%d', '%s', '%s'];

        if ($existing_id > 0) {
            $wpdb->update($translations, $data, ['id' => $existing_id], $format, ['%d']);
            return;
        }

        $insert = [
            'post_id'        => $post_id,
            'context'        => $context,
            'block_id'       => '',
            'source_lang_id' => $source_lang_id,
            'target_lang_id' => $target_lang_id,
            'language_code'  => $normalized_code,
            'original_text'  => $original_text,
            'translated_text'=> $translated_text,
            'status'         => (int) $status,
            'origin'         => $normalized_origin,
            'created_at'     => $now,
            'updated_at'     => $now,
        ];

        $wpdb->insert(
            $translations,
            $insert,
            ['%d','%s','%s','%d','%d','%s','%s','%s','%d','%s','%s','%s']
        );
    }

    private static function upsert_mirror(int $post_id, string $context, string $text, int $language_id, string $language_code): void {
        self::upsert_translation(
            $post_id,
            $context,
            $text,
            $text,
            $language_id,
            $language_id,
            $language_code,
            2,
            'manual'
        );
    }

    public static function get_source_code(): ?string {
        return self::normalize_code(self::fetch_flagged_code('is_source'));
    }

    public static function get_default_code(): ?string {
        return self::normalize_code(self::fetch_flagged_code('is_default'));
    }

    public static function enforce_invariants(): void {
        global $wpdb;
        if (!self::table_exists()) {
            return;
        }

        $table   = self::languages_table();
        $default = self::get_default_code() ?: self::normalize_code(self::pick_fallback_code());
        $source  = self::get_source_code() ?: $default;

        if ($default && !self::code_exists($default)) {
            $default = self::normalize_code(self::pick_fallback_code());
        }
        if ($source && !self::code_exists($source)) {
            $source = $default;
        }

        if ($default) {
            $wpdb->query($wpdb->prepare(
                "UPDATE {$table} SET is_default = CASE WHEN language_code = %s THEN 1 ELSE 0 END",
                $default
            ));
        }

        if ($source) {
            $wpdb->query($wpdb->prepare(
                "UPDATE {$table} SET is_source = CASE WHEN language_code = %s THEN 1 ELSE 0 END",
                $source
            ));
        }

        $codes = array_values(array_unique(array_filter([$default, $source])));
        if (!empty($codes)) {
            $placeholders = implode(',', array_fill(0, count($codes), '%s'));
            $wpdb->query($wpdb->prepare(
                "UPDATE {$table} SET is_translatable = CASE WHEN language_code IN ({$placeholders}) THEN 1 ELSE is_translatable END",
                ...$codes
            ));
        }
    }

    /* ========== Queries “métier” utilisées par UI/TM ========== */

    /** Codes des langues activées pour traduction */
    public function get_target_codes(): array {
        $o       = (array) ($this->settings->get_option('yuz_tra_settings'));
        $enabled = (array) ($o['translation-languages'] ?? []);
        // Sécurise: ne renvoyer que des codes connus par le catalogue
        if ($this->languages) {
            $known   = array_map(function($L) { return $L->language_code; }, $this->languages->get_translatable_languages());
            $enabled = array_values(array_intersect($enabled, $known));
        }
        return $enabled;
    }

    /** Pour le switcher/UI : liste normalisée {code, label, flag, enabled, role} */
    public function get_switcher_items(): array {
        $items = [];
        if (!$this->languages) return $items;
        $src = $this->get_source_language();
        foreach ($this->languages->get_all_languages() as $L) {
            $code    = $L->language_code;
            $items[] = [
                'code'    => $code,
                'label'   => !empty($L->native_name) ? (string) $L->native_name : $this->resolve_native_name($code),
                'flag'    => $L->flag ?? null,
                'enabled' => $code === $src ? true : in_array($code, $this->get_target_codes(), true),
                'role'    => $code === $src ? 'source' : 'target',
            ];
        }
        return $items;
    }

    /** Activer/désactiver une langue cible (utilisé par l’UI) */
    public function set_enabled(string $code, bool $enabled): bool {
        $o    = (array) $this->settings->get_option('yuz_tra_settings');
        $list = isset($o['translation-languages']) && is_array($o['translation-languages']) ? $o['translation-languages'] : [];
        $has  = in_array($code, $list, true);

        if ($enabled && !$has) {
            $list[] = $code;
        } elseif (!$enabled && $has) {
            $list = array_values(array_diff($list, [$code]));
        } else {
            return true; // rien à faire
        }
        $o['translation-languages'] = $list;
        return (bool) $this->settings->update_option('yuz_tra_settings', $o);
    }

    /** Utilitaire : vérifier qu’un code est activé pour traduction */
    public function is_enabled(string $code): bool {
        return in_array($code, $this->get_target_codes(), true);
    }

    /**
     * Syncs settings with language data.
     */
    public function sync_settings($source_code = null, $default_code = null, $translatable_code = null) {
        $lock_key = 'yuz_tra_settings_sync_lock';
        if (get_transient($lock_key)) {
            (new YUZ_Logger())->log('warning', 'Concurrent sync_settings attempt detected');
            return;
        }
        set_transient($lock_key, true, 30);

        $this->db->ensure_tables();
        global $wpdb;
        $table_name = $wpdb->prefix . 'yuz_tra_languages';
        if (!$wpdb->get_var("SHOW TABLES LIKE '{$table_name}'")) {
            (new YUZ_Logger())->log('critical', "Table $table_name missing after recreation attempt");
            delete_transient($lock_key);
            return;
        }

        $settings               = $this->settings->get_option('yuz_tra_settings');
        $translatable_languages = $this->get_translatable_languages();
        $valid_languages        = array_map(function($lang) {
            return is_object($lang) ? $lang->language_code : $lang;
        }, $translatable_languages);

        $default_language = $this->get_default_language() ?? get_locale();
        $source_language  = $this->get_source_language()  ?? $default_language;

        $settings_updated = false;
        if (!isset($settings['yuz_tra_default_language']) || $settings['yuz_tra_default_language'] !== $default_language) {
            $settings['yuz_tra_default_language'] = $default_language;
            $settings_updated = true;
        }
        if (!isset($settings['yuz_tra_source_language']) || $settings['yuz_tra_source_language'] !== $source_language) {
            $settings['yuz_tra_source_language'] = $source_language;
            $settings_updated = true;
        }
        if (!isset($settings['translation-languages']) || $settings['translation-languages'] !== $valid_languages) {
            $settings['translation-languages'] = $valid_languages;
            $settings_updated = true;
        }

        if ($settings_updated) {
            $this->settings->update_option('yuz_tra_settings', $settings);
            wp_cache_set('yuz_tra_settings_sync', true, 'yuz-tra', 3600);
            (new YUZ_Logger())->log('info', 'Synced yuz_tra_settings with languages', ['settings' => $settings]);
        }
        delete_transient($lock_key);
    }

    /**
     * is_default = <html lang> (auto). Override manuel si présent.
     * → La détection “prend fin” dès qu’une valeur manuelle est configurée.
     */
    public function get_default_language(): string {
        $S      = (array) $this->settings->get_option('yuz_tra_general', []);
        $manual = self::normalize_code($S['yuz_tra_default_manual'] ?? $S['yuz_tra_default_language'] ?? null);
        if ($manual) {
            return $manual;
        }

        $db_default = self::normalize_code(self::get_default_code());
        if ($db_default) {
            return $db_default;
        }

        $auto = function_exists('get_bloginfo') ? get_bloginfo('language')
              : (function_exists('get_locale') ? get_locale() : 'en_US');
        return self::normalize_code($auto) ?: 'en_US';
    }

    /**
     * is_source = auto (API) avec override manuel si présent ; sinon fallback sur default.
     * → La détection “prend fin” dès qu’une valeur manuelle est configurée.
     */
    public function get_source_language(): string {
        $S      = (array) $this->settings->get_option('yuz_tra_general', []);
        $manual = self::normalize_code($S['yuz_tra_source_manual'] ?? $S['yuz_tra_source_language'] ?? null);
        if ($manual) {
            return $manual;
        }

        $db_source = self::normalize_code(self::get_source_code());
        if ($db_source) {
            return $db_source;
        }

        $minC = (float)($S['yuz_tra_detect_min_confidence'] ?? 0.70);
        $maxL = (int)  ($S['yuz_tra_detect_max_len']        ?? 8000);

        $det = $this->detect_content_language([
            'post_id'        => function_exists('get_queried_object_id') ? (int)(get_queried_object_id() ?: 0) : 0,
            'min_confidence' => $minC,
            'max_len'        => $maxL,
        ]);
        if (is_array($det) && !empty($det['code']) && ($det['confidence'] ?? 0.0) >= $minC) {
            $code = self::normalize_code($det['code']);
            if ($code) {
                return $code;
            }
        }

        return $this->get_default_language();
    }

     public static function display_languages() {
        global $wpdb;

        $languages = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}yuz_tra_languages ORDER BY is_default DESC");

        if ($languages) {
            echo '<h3>Languages List</h3>';
            echo '<table>';
            foreach ($languages as $language) {
                echo '<tr>';
                echo '<td>' . esc_html($language->language_name) . '</td>';
                echo '<td>' . esc_html($language->language_code) . '</td>';
                echo '<td>' . esc_html($language->slug) . '</td>';
                echo '<td>' . ($language->is_default ? 'Default' : '') . '</td>';
                echo '<td>' . ($language->is_translatable ? 'Translatable' : 'Not Translatable') . '</td>';
                echo '</tr>';
            }
            echo '</table>';
        } else {
            echo '<p>No languages found.</p>';
        }
    }

    /**
     * Détection via adapter si dispo, sinon endpoint LibreTranslate /detect (option yuz_tra_api_settings).
     * Retourne ['code'=>'fr_FR','confidence'=>0.92] ou null. Jamais bloquant.
     */
    protected function detect_content_language(array $opts = []) : ?array {
        $post_id = (int)($opts['post_id'] ?? 0);
        $max_len = (int)($opts['max_len'] ?? 8000);
        $min_c   = (float)($opts['min_confidence'] ?? 0.70);

        // 1) Préparer le texte
        $text = '';
        if ($post_id && function_exists('get_post')) {
            $p = get_post($post_id);
            if ($p && $p->post_content) $text = wp_strip_all_tags($p->post_content);
        } elseif (function_exists('is_singular') && is_singular() && function_exists('get_post')) {
            $p = get_post();
            if ($p) $text = wp_strip_all_tags(($p->post_title ?? '') . ' ' . ($p->post_excerpt ?? ''));
        }
        $text = trim(mb_substr($text, 0, $max_len));
        if ($text === '') return null;

        // 2) Adapter prioritaire si présent
        if (isset($this->adapter) && method_exists($this->adapter, 'detectLanguage')) {
            try {
                $res = $this->adapter->detectLanguage($text); // ex: ['language'=>'fr','confidence'=>0.93]
                if (is_array($res) && !empty($res['language'])) {
                    $code = self::normalize_code(str_replace('-', '_', (string)$res['language']));
                    $conf = (float)($res['confidence'] ?? 0.0);
                    if ($code && $conf >= $min_c) return ['code' => $code, 'confidence' => $conf];
                }
            } catch (\Throwable $e) {}
        }

        // 3) Fallback LibreTranslate (optionnel)
        if (!function_exists('wp_remote_post')) return null;
        $api      = (array) get_option('yuz_tra_api_settings', []);
        $endpoint = rtrim((string)($api['endpoint'] ?? ''), '/');
        if ($endpoint === '') return null;

        $url  = $endpoint . '/detect';
        $args = ['timeout' => 8, 'body' => ['q' => $text]];
        if (!empty($api['api_key'])) $args['body']['api_key'] = $api['api_key'];

        try {
            $res = wp_remote_post($url, $args);
            if (is_wp_error($res)) return null;
            if (wp_remote_retrieve_response_code($res) !== 200) return null;
            $body = json_decode(wp_remote_retrieve_body($res), true);
            if (!is_array($body) || empty($body[0]['language'])) return null;

            $lang = (string)$body[0]['language']; // ex: 'fr'
            $conf = (float)($body[0]['confidence'] ?? 0.0);
            $code = self::normalize_code($lang);
            if ($code && $conf >= $min_c) return ['code' => $code, 'confidence' => $conf];
        } catch (\Throwable $e) {}

        return null;
    }

    /**
     * Returns the effective source language code:
     * - explicit source when set
     * - else fall back to default
     */
    public function get_effective_source_code(): string {
        $src = (string) $this->get_source_language();
        if (!empty($src)) return $src;
        return (string) $this->get_default_language();
    }

    /**
     * Updates language settings (UI toggles).
     */
    public function yuz_lang_settings(array $settings): void {
        global $wpdb;
        $updated_settings                         = $this->settings->get_option('yuz_tra_settings');
        $updated_settings['native_language_name'] = !empty($settings['yuz_use_native_names']) ? '1' : '';
        $updated_settings['use_subdirectory']     = !empty($settings['yuz_use_subdirectory']) ? '1' : '';
        $updated_settings['force_lang_in_links']  = !empty($settings['yuz_force_language_in_links']) ? '1' : '';
        $this->settings->update_option('yuz_tra_settings', $updated_settings);
        $this->purge_caches();
        (new YUZ_Logger())->log('debug', 'Language settings saved', [
            'native_language_name' => $updated_settings['native_language_name'],
            'use_subdirectory'     => $updated_settings['use_subdirectory'],
            'force_lang_in_links'  => $updated_settings['force_lang_in_links'],
        ]);
    }

    /**
     * Retrieves translatable languages.
     *
     * @return array<YUZ_Language>
     */
    public function get_translatable_languages(): array {
        global $wpdb;
        $table_name = $wpdb->prefix . 'yuz_tra_languages';
        if (!$wpdb->get_var("SHOW TABLES LIKE '{$table_name}'")) {
            (new YUZ_Logger())->log('critical', "Table $table_name missing");
            return apply_filters('yuz_tra_translatable_languages', []);
        }

        $suffix        = self::TRANSIENT_TRANSLATABLE;
        $transient_key = class_exists('YUZ_Settings_Service')
            ? YUZ_Settings_Service::transient_key($suffix)
            : (function_exists('yuz_settings_transient_key') ? yuz_settings_transient_key($suffix) : 'yuz_tra_translatable_languages');

        $languages = false;
        if (function_exists('yuz_settings_cache_get')) {
            $languages = yuz_settings_cache_get($suffix);
        }
        if ($languages === false) {
            $languages = get_transient($transient_key);
        }

        if ($languages === false) {
            $attempts = 3; $results = null;
            while ($attempts > 0) {
                $results = $wpdb->get_results(
                    "SELECT language_code, language_name, native_name, language_weight, is_translatable, is_source, is_default
                     FROM {$table_name}
                     WHERE is_translatable = 1
                     ORDER BY language_weight ASC",
                    ARRAY_A
                );
                if ($results !== null) break;
                $attempts--;
                (new YUZ_Logger())->log('warning', "Retry attempt for retrieving translatable languages, attempts left: {$attempts}");
                usleep(100000);
            }
            if ($results === null) {
                (new YUZ_Logger())->log('error', "Failed to fetch translatable languages: " . $wpdb->last_error);
                return apply_filters('yuz_tra_translatable_languages', []);
            }

            $languages = [];
            foreach ($results as $lang) {
                if (!isset($lang['language_code']) || !isset($lang['language_name'])) {
                    (new YUZ_Logger())->log('warning', 'Invalid language row in translatable languages', ['row' => $lang]);
                    continue;
                }
                $language_obj              = new YUZ_Language($lang);
                $language_obj->native_name = $this->resolve_native_name($lang['language_code'], $lang['native_name'] ?? null);
                $languages[]               = $language_obj;
            }

            if (empty($languages)) {
                (new YUZ_Logger())->log('warning', "No translatable languages found in $table_name");
            } else {
                (new YUZ_Logger())->log('info', "Retrieved " . count($languages) . " translatable languages");
            }
            if (function_exists('yuz_settings_cache_set')) {
                yuz_settings_cache_set($suffix, $languages, 'yuz-tra', self::TRANSIENT_TTL);
            }
            set_transient($transient_key, $languages, self::TRANSIENT_TTL);
        }

        // ✅ Fallback options → évite l’éditeur “vide” si la DB n’est pas encore sync
        if (empty($languages)) {
            $opt = get_option('yuz_tra_general', []);
            $codes = array_values(array_unique(array_filter((array)($opt['yuz_tra_translatable_languages'] ?? []))));
            if (!empty($codes)) {
                $fallback = [];
                foreach ($codes as $code) {
                    // On construit un objet minimal YUZ_Language (code + label)
                    $label = function_exists('yuz_lang_label') ? yuz_lang_label($code) : strtoupper(str_replace('_','-',$code));
                    $fallback[] = new YUZ_Language([
                        'language_code'   => $code,
                        'language_name'   => $label,
                        'native_name'     => $this->resolve_native_name($code),
                        'is_translatable' => 1,
                        'is_source'       => 0,
                        'is_default'      => 0,
                    ]);
                }
                (new YUZ_Logger())->log('warning', 'fallback_translatable_languages_from_options', ['count' => count($fallback)]);
                $languages = $fallback;
            }
        }
        return apply_filters('yuz_tra_translatable_languages', $languages);
    }

    /**
     * Retrieves all languages.
     *
     * @return array<YUZ_Language>
     */
    public function get_all_languages(): array {
        global $wpdb;
        $table_name = $wpdb->prefix . 'yuz_tra_languages';
        if (!$wpdb->get_var("SHOW TABLES LIKE '{$table_name}'")) {
            (new YUZ_Logger())->log('critical', "Table $table_name missing");
            return apply_filters('yuz_tra_all_languages', []);
        }

        $suffix        = self::TRANSIENT_ALL;
        $transient_key = class_exists('YUZ_Settings_Service')
            ? YUZ_Settings_Service::transient_key($suffix)
            : (function_exists('yuz_settings_transient_key') ? yuz_settings_transient_key($suffix) : 'yuz_tra_all_languages');

        $languages = false;
        if (function_exists('yuz_settings_cache_get')) {
            $languages = yuz_settings_cache_get($suffix);
        }
        if ($languages === false) {
            $languages = get_transient($transient_key);
        }

        if ($languages === false) {
            $attempts = 3; $results = null;
            while ($attempts > 0) {
                $results = $wpdb->get_results("SELECT * FROM {$table_name}", ARRAY_A);
                if ($results !== null) break;
                $attempts--;
                (new YUZ_Logger())->log('warning', "Retry attempt for retrieving all languages, attempts left: {$attempts}");
                usleep(100000);
            }
            if ($results === null) {
                (new YUZ_Logger())->log('error', "Failed to fetch all languages: " . $wpdb->last_error);
                return apply_filters('yuz_tra_all_languages', []);
            }

            $languages = [];
            foreach ($results as $lang) {
                if (!isset($lang['language_code']) || !isset($lang['language_name'])) {
                    (new YUZ_Logger())->log('warning', 'Invalid language row in all languages', ['row' => $lang]);
                    continue;
                }
                $language_obj              = new YUZ_Language($lang);
                $language_obj->native_name = $this->resolve_native_name($lang['language_code'], $lang['native_name'] ?? null);
                $languages[]               = $language_obj;
            }

            if (function_exists('yuz_settings_cache_set')) {
                yuz_settings_cache_set($suffix, $languages, 'yuz-tra', self::TRANSIENT_TTL);
            }
            set_transient($transient_key, $languages, self::TRANSIENT_TTL);
            (new YUZ_Logger())->log('info', "Retrieved all languages: " . count($languages) . " entries");
        }

        return apply_filters('yuz_tra_all_languages', $languages);
    }

    /**
     * Retrieves a language by code.
     *
     * @param string $code
     * @return ?Language
     */
    public function get_by_code(string $code): ?Language {
        global $wpdb;
        $table_name = $wpdb->prefix . 'yuz_tra_languages';
        if (!$wpdb->get_var("SHOW TABLES LIKE '{$table_name}'")) {
            (new YUZ_Logger())->log('critical', "Table $table_name missing");
            return apply_filters('yuz_tra_language_by_code', null, $code);
        }

        $cache_key = 'yuz_tra_language_' . $code;
        $language  = wp_cache_get($cache_key, 'yuz-tra');
        if ($language !== false) {
            return apply_filters('yuz_tra_language_by_code', $language, $code);
        }

        $attempts = 3; $result = null;
        while ($attempts > 0) {
            $result = $wpdb->get_row(
                $wpdb->prepare("SELECT * FROM {$table_name} WHERE language_code = %s LIMIT 1", $code),
                ARRAY_A
            );
            if ($result !== null) break;
            $attempts--;
            (new YUZ_Logger())->log('warning', "Retry attempt for retrieving language {$code}, attempts left: {$attempts}");
            usleep(100000);
        }

        if ($result) {
            if (!isset($result['language_name'])) {
                (new YUZ_Logger())->log('warning', "Language by code {$code} missing language_name");
                $result['language_name'] = strtoupper($code); // fallback immédiat
            }
            $language              = new YUZ_Language($result);
            $language->native_name = $this->resolve_native_name($code, $result['native_name'] ?? null);
            (new YUZ_Logger())->log('info', "Language found for code: $code, native name: {$language->native_name}");
        } else {
            $language = null;
            (new YUZ_Logger())->log('warning', "No language found for code: $code");
        }

        wp_cache_set($cache_key, $language, 'yuz-tra', 3600);
        return apply_filters('yuz_tra_language_by_code', $language, $code);
    }

    /**
     * Aligne la DB (is_default/is_source) sur les valeurs EFFECTIVES (auto + overrides).
     * Pas d’insertion magique ; option miroir mise à jour.
     */
    public function enforce_language_rules($source = null, $default = null, $translatable = null): void {
        global $wpdb;

        $requested_default = self::normalize_code($default);
        $requested_source  = self::normalize_code($source);
        $table_exists      = self::table_exists();

        if ($table_exists) {
            $table = self::languages_table();

            if ($requested_default && self::code_exists($requested_default)) {
                $wpdb->query($wpdb->prepare(
                    "UPDATE {$table} SET is_default = CASE WHEN language_code = %s THEN 1 ELSE 0 END",
                    $requested_default
                ));
            }

            if ($requested_source && self::code_exists($requested_source)) {
                $wpdb->query($wpdb->prepare(
                    "UPDATE {$table} SET is_source = CASE WHEN language_code = %s THEN 1 ELSE 0 END",
                    $requested_source
                ));
            }

            if (!empty($translatable)) {
                $wanted = array_filter(array_map([self::class, 'normalize_code'], (array) $translatable));
                if (!empty($wanted)) {
                    $placeholders = implode(',', array_fill(0, count($wanted), '%s'));
                    $wpdb->query($wpdb->prepare(
                        "UPDATE {$table} SET is_translatable = CASE WHEN language_code IN ({$placeholders}) THEN 1 ELSE is_translatable END",
                        ...$wanted
                    ));
                }
            }
        }

        self::enforce_invariants();

        $auto_locale   = function_exists('get_locale') ? self::normalize_code(get_locale()) : 'en_US';
        $fallback_auto = $auto_locale ?: 'en_US';

        $final_default = self::get_default_code()
            ?: ($requested_default ?: $fallback_auto);
        $final_source  = self::get_source_code()
            ?: ($requested_source ?: $final_default);

        if (!$final_source) {
            $final_source = $final_default ?: $fallback_auto;
        }
        if (!$final_default) {
            $final_default = $fallback_auto;
        }

        // Option miroir UI/front (vérité partagée)
        $S = (array) $this->settings->get_option('yuz_tra_general', []);
        $S['yuz_tra_default_language'] = $final_default;
        $S['yuz_tra_source_language']  = $final_source;
        if ($table_exists) {
            $table = self::languages_table();
            $rows = (array) $wpdb->get_col("SELECT language_code FROM {$table} WHERE is_translatable = 1");
            $rows = array_values(array_unique(array_merge(
                $rows,
                array_filter([$final_source, $final_default])
            )));
            $S['yuz_tra_translatable_languages'] = $rows;
        } elseif (!empty($translatable)) {
            $rows = array_filter(array_map([self::class, 'normalize_code'], (array) $translatable));
            $rows = array_values(array_unique(array_merge($rows, array_filter([$final_source, $final_default]))));
            $S['yuz_tra_translatable_languages'] = $rows;
        }
        $this->settings->update_option('yuz_tra_general', $S);

        if (method_exists($this, 'purge_caches')) $this->purge_caches();
        do_action('yuz_tra_after_enforce_language_rules', $final_source, $final_default, $translatable);
    }

    /**
     * Handles AJAX request to update language weights.
     */
    public function ajax_update_weights() {
        YUZ_Ajax::__handleRequest(
            'yuz_yuz_nonce',
            ['weights'],
            function ($data) {
                $weights   = (array)($data['weights'] ?? []);
                $sanitized = [];
                foreach ($weights as $code => $w) {
                    $sanitized[sanitize_text_field($code)] = (int) $w;
                }
                $ok = $this->update_language_weights($sanitized);
                return $ok
                    ? ['success' => true, 'message' => __('Language weights updated successfully', 'yuz_translation'), 'timestamp' => current_time('mysql')]
                    : ['success' => false, 'error'   => ['code' => 'update_failed', 'message' => __('Failed to update language weights','yuz_translation'), 'details' => ''], 'timestamp' => current_time('mysql')];
            }
        );
    }

    /**
     * Updates language weights.
     */
    public function update_language_weights(array $weights): bool {
        global $wpdb;
        $table_name = $wpdb->prefix . 'yuz_tra_languages';
        if (!$wpdb->get_var("SHOW TABLES LIKE '{$table_name}'")) {
            (new YUZ_Logger())->log('critical', "Table $table_name missing after recreation attempt");
            return false;
        }
        try {
            $wpdb->query('START TRANSACTION');
            foreach ($weights as $language_code => $weight) {
                if ($this->get_by_code($language_code) === null) {
                    throw new \Exception("Update weights: Invalid code {$language_code}");
                }
                $attempts = 3; $result = null;
                while ($attempts > 0) {
                    $result = $wpdb->update(
                        $table_name,
                        ['language_weight' => (int) $weight],
                        ['language_code' => sanitize_text_field($language_code), 'is_translatable' => 1],
                        ['%d'],
                        ['%s', '%d']
                    );
                    if ($result !== null) break;
                    $attempts--;
                    (new YUZ_Logger())->log('warning', "Retry attempt for updating language weight for {$language_code}, attempts left: {$attempts}");
                    usleep(100000);
                }
                if ($result === false) {
                    throw new \Exception("Failed to update language weight for {$language_code}: " . $wpdb->last_error);
                }
            }
            $wpdb->query('COMMIT');
            (new YUZ_Logger())->log('success', 'Language weights updated', ['weights' => $weights]);
            return true;
        } catch (\Exception $e) {
            $wpdb->query('ROLLBACK');
            (new YUZ_Logger())->log('error', $e->getMessage());
            return false;
        }
    }

    /**
     * Expose la liste des langues disponibles (translatable).
     *
     * @return array
     */
    public function get_available_languages(): array {
        return $this->get_translatable_languages();
    }

    public function swap_source_and_target(string $new_source_code): bool {
        global $wpdb;
        // 0) Préconditions DB
        $this->db->ensure_tables();
        $table = $wpdb->prefix . 'yuz_tra_languages';
        if (!$wpdb->get_var("SHOW TABLES LIKE '{$table}'")) {
            (new YUZ_Logger())->log('critical', "swap_source_and_target: table {$table} missing");
            return false;
        }
        // 1) Sanitize & existence
        $new = sanitize_text_field($new_source_code);
        if ($this->get_by_code($new) === null) {
            (new YUZ_Logger())->log('warning', "swap_source_and_target: unknown language code {$new}");
            return false;
        }
        // 2) Ancien "source"
        $old = $this->get_source_language();
        if (!is_string($old) || $old === '') {
            (new YUZ_Logger())->log('critical', 'swap_source_and_target: current source language not found');
            return false;
        }
        if ($old === $new) {
            (new YUZ_Logger())->log('info', "swap_source_and_target: {$new} is already the source language");
            return true;
        }

        try {
            $wpdb->query('START TRANSACTION');
            // 3) Ancien source devient translatable
            $r1 = $wpdb->update(
                $table,
                ['is_source' => 0, 'is_translatable' => 1],
                ['language_code' => $old],
                ['%d','%d'],
                ['%s']
            );
            if ($r1 === false) throw new \RuntimeException("DB error unsetting old source: ".$wpdb->last_error);
            // 4) Nouveau source
            $r2 = $wpdb->update(
                $table,
                ['is_source' => 1, 'is_translatable' => 1],
                ['language_code' => $new],
                ['%d','%d'],
                ['%s']
            );
            if ($r2 === false) throw new \RuntimeException("DB error setting new source: ".$wpdb->last_error);
            $wpdb->query('COMMIT');

            // 5) Options & règles
            $opts = $this->settings->get_option('yuz_tra_settings', []);
            if (!is_array($opts)) { $opts = []; }
            $opts['yuz_tra_source_language'] = $new;
            $this->settings->update_option('yuz_tra_settings', $opts);

            // Cohérence supplémentaire
            $this->enforce_language_rules(null, $opts['yuz_tra_default_language'] ?? null, null);

            // 6) Caches
            if (function_exists('wp_cache_delete')) {
                wp_cache_delete('yuz_tra_language_' . $old, 'yuz-tra');
                wp_cache_delete('yuz_tra_language_' . $new, 'yuz-tra');
            }
            $this->purge_caches();

            (new YUZ_Logger())->log('success', "swap_source_and_target: {$old} ➜ {$new}");
            return true;
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            (new YUZ_Logger())->log('critical', 'swap_source_and_target failed: '.$e->getMessage());
            return false;
        }
    }

    public function ajax_add_language() {
        YUZ_Ajax::__handleRequest('yuz_tra_nonce', ['language_code'], function($data) {
            $code = sanitize_text_field($data['language_code']);
            if (!$this->get_by_code($code)) return ['success' => false, 'error' => 'Invalid code'];
            $updated = $this->db->update('yuz_tra_languages', ['is_translatable' => 1], ['language_code' => $code]);
            if ($updated) {
                $this->purge_caches();
                return ['success' => true, 'languages' => $this->get_translatable_languages()];
            }
            return ['success' => false, 'error' => 'Update failed'];
        });
    }

    public function ajax_add_all_languages() {
        YUZ_Ajax::__handleRequest('yuz_tra_nonce', [], function() {
            $all = $this->get_all_languages();
            foreach ($all as $lang) {
                if ($lang->language_code !== $this->get_default_language()) { // Skip default
                    $this->db->update('yuz_tra_languages', ['is_translatable' => 1], ['language_code' => $lang->language_code]);
                }
            }
            $this->purge_caches();
            return ['success' => true, 'languages' => $this->get_translatable_languages()];
        });
    }

    public function ajax_remove_language() {
        YUZ_Ajax::__handleRequest('yuz_tra_nonce', ['language_code'], function($data) {
            $code = sanitize_text_field($data['language_code']);
            if ($code === $this->get_source_language() || $code === $this->get_default_language()) {
                return ['success' => false, 'error' => 'Cannot remove source/default'];
            }
            $updated = $this->db->update('yuz_tra_languages', ['is_translatable' => 0], ['language_code' => $code]);
            if ($updated) {
                $this->purge_caches();
                return ['success' => true, 'languages' => $this->get_translatable_languages()];
            }
            return ['success' => false, 'error' => 'Update failed'];
        });
    }

    public function ajax_remove_all_languages() {
        YUZ_Ajax::__handleRequest('yuz_tra_nonce', [], function() {
            $this->db->update('yuz_tra_languages', ['is_translatable' => 0], ['is_translatable' => 1]);
            $this->purge_caches();
            return ['success' => true, 'languages' => []];
        });
    }

    public function ajax_update_ws_settings() {
        YUZ_Ajax::__handleRequest('yuz_tra_nonce', ['website_languages'], function($data) {
            global $wpdb;

            $ws       = isset($data['website_languages']) && is_array($data['website_languages']) ? $data['website_languages'] : [];
            $settings = $this->settings->get_option('yuz_tra_general', []);
            if (!is_array($settings)) { $settings = []; }

            // ENREGISTRER l’intention MANUELLE (override) — pas de toggle direct des flags DB ici
            if (array_key_exists('yuz_default_language', $ws)) {
                $val = trim((string) $ws['yuz_default_language']);
                if ($val === '') {
                    unset($settings['yuz_tra_default_manual']); // retour en auto
                } else {
                    $settings['yuz_tra_default_manual'] = sanitize_text_field($val);
                }
            }
            if (array_key_exists('yuz_source_language', $ws)) {
                $val = trim((string) $ws['yuz_source_language']);
                if ($val === '') {
                    unset($settings['yuz_tra_source_manual']); // retour en auto
                } else {
                    $settings['yuz_tra_source_manual'] = sanitize_text_field($val);
                }
            }

            // (slug/code inchangés — miroirs UI/front)
            if (isset($ws['yuz_slug']) && is_array($ws['yuz_slug'])) {
                $settings['yuz_tra_slug'] = array_map('sanitize_text_field', $ws['yuz_slug']);
            }
            if (isset($ws['yuz_code']) && is_array($ws['yuz_code'])) {
                $settings['yuz_tra_code'] = array_map('sanitize_text_field', $ws['yuz_code']);
            }

            $this->settings->update_option('yuz_tra_general', $settings);

            // Recalcule les EFFECTIFS (auto + override) et aligne la DB
            $this->enforce_language_rules();
            $this->purge_caches();

            return ['success' => true];
        });
    }

    /** Purge transients/caches internes */
    private function purge_caches(): void {
        $suffixes = [
            self::TRANSIENT_TRANSLATABLE,
            self::TRANSIENT_ALL,
            self::TRANSIENT_DEFAULT_LANGUAGE,
            self::TRANSIENT_SOURCE_LANGUAGE,
        ];

        foreach ($suffixes as $suffix) {
            if (function_exists('yuz_settings_delete_transient')) {
                yuz_settings_delete_transient($suffix);
            }
            if (function_exists('yuz_settings_cache_delete')) {
                yuz_settings_cache_delete($suffix);
            }
        }

        foreach (['yuz_tra_translatable_languages', 'yuz_tra_all_languages', 'yuz_tra_default_language', 'yuz_tra_source_language'] as $legacyTransient) {
            delete_transient($legacyTransient);
        }

        if (function_exists('wp_cache_delete')) {
            foreach (['all_languages', 'translatable_languages', 'default_language', 'source_language'] as $legacyCache) {
                wp_cache_delete($legacyCache, 'yuz-tra');
            }
        }

        if (class_exists('YUZ_Settings_Service')) {
            YUZ_Settings_Service::touch();
        }
    }
}

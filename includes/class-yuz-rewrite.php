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
require_once YUZ_TRA_INCLUDES . 'class-yuz-url-converter.php';
require_once YUZ_TRA_INCLUDES . 'class-yuz-fallbacks.php';

use YUZTRA\Interfaces\LanguagesInterface;
use YUZTRA\Interfaces\SettingsInterface;
use YUZTRA\Interfaces\RewriteInterface;
use YUZTRA\Fallbacks\NullLanguages;
use YUZTRA\Fallbacks\NullSettings;
use YUZTRA\Fallbacks\NullTranslateAdapter;
use YUZTRA\Fallbacks\NullLogger;
use YUZTRA\Fallbacks\NullAjax;
use YUZTRA\Fallbacks\NullLanguageManager;
use YUZTRA\Fallbacks\NullTranslationManager;

if (!class_exists('YUZ_Rewrite')) {
class YUZ_Rewrite implements RewriteInterface {
    private $languages;
    private $settings;
    private $db;
    private $url_converter;
    private $is_converting = false;

    public function __construct(LanguagesInterface $languages, SettingsInterface $settings, YUZ_DB $db) {
        $this->languages = $languages;
        $this->settings  = $settings;
        $this->db        = $db;
        $this->url_converter = new YUZ_Url_Converter($settings);
        (new YUZ_Logger())->log('info', 'YUZ_Rewrite instantiated with dependencies');
    }

    public static function init() {
        $logger = new YUZ_Logger();
        $health = new YUZ_Health_Check($logger);
        $db     = new YUZ_DB($logger, $health);

        // 1) Languages — privilégie la factory centrale
        if (class_exists('YUZ_Services') && method_exists('YUZ_Services', 'languages')) {
            $languages = YUZ_Services::languages();
        } else {
            $languages = class_exists('YUZ_Languages')
                ? new YUZ_Languages(new NullSettings(), $db)
                : new NullLanguages();
        }

        // 2) Settings — privilégie la factory centrale (sinon provisoire)
        if (class_exists('YUZ_Services') && method_exists('YUZ_Services', 'settings')) {
            $settings = YUZ_Services::settings();
        } elseif (class_exists('YUZ_Settings')) {
            // provisoire: Ajax/TM seront recâblés après création du TM réel
            $settings = new YUZ_Settings($languages, new NullAjax(), new NullTranslationManager(), new NullLanguageManager(), $logger);
        } else {
            $settings = new NullSettings();
        }

        // 3) Translation Manager — standard via Services::tm() si possible
        if (class_exists('YUZ_Services') && method_exists('YUZ_Services', 'tm')) {
            $translationManager = YUZ_Services::tm();
        } elseif (class_exists('YUZ_API_Manager')) {
            // Adaptateurs
            $adapters = [
                'custom'         => class_exists('YUZ_Custom_Translate_Adapter') ? new YUZ_Custom_Translate_Adapter()   : new NullTranslateAdapter(),
                'libretranslate' => class_exists('YUZ_Libre_Translate_Adapter')  ? new YUZ_Libre_Translate_Adapter()    : new NullTranslateAdapter(),
                'deepl'          => class_exists('YUZ_DeepL_Translate_Adapter')  ? new YUZ_DeepL_Translate_Adapter()    : new NullTranslateAdapter(),
                'google'         => class_exists('YUZ_Google_Translate_Adapter') ? new YUZ_Google_Translate_Adapter()   : new NullTranslateAdapter(),
            ];

            // ✅ Signature officielle: (adapters, SettingsInterface, LanguagesInterface, AjaxInterface|null, DBInterface, LoggerInterface)
            $translationManager = new YUZ_API_Manager(
                $adapters,
                $settings,    // ✅ 2) Settings
                $languages,   // ✅ 3) Languages
                null,         // ✅ 4) Ajax branché après
                $db,          // ✅ 5) DB
                $logger       // ✅ 6) Logger
            );
        } else {
            $translationManager = new NullTranslationManager();
        }

        // 4) Language Manager (corrige le nom de classe)
        $languageManager = class_exists('YUZ_Language_Manager')
            ? new YUZ_Language_Manager()
            : new NullLanguageManager();

        // 5) Ajax central (sans inline JS)
        $ajax = class_exists('YUZ_Ajax')
            ? new YUZ_Ajax($translationManager, $languageManager, $db)
            : new NullAjax();

        // 6) Branchement Ajax → TM (setter camelCase)
        if (method_exists($translationManager, 'setAjax')) {
            $translationManager->setAjax($ajax);
        }

        // 7) Si Settings n’est pas issu de Services, on le recâble proprement avec les deps réelles
        if (!(class_exists('YUZ_Services') && method_exists('YUZ_Services', 'settings')) && class_exists('YUZ_Settings') && !($settings instanceof NullSettings)) {
            $settings = new YUZ_Settings($languages, $ajax, $translationManager, $languageManager, $logger);
        }

        // 8) Instance + hooks
        $instance = new self($languages, $settings, $db);

        add_action('init',        [$instance, 'register_rewrite_rules']);
        add_filter('query_vars',  [$instance, 'add_query_vars']);
        add_action('template_redirect', [$instance, 'force_lang_in_links']);
        add_action('pre_get_posts',     [$instance, 'ensure_front_page_slug']);
        add_action('parse_request',     [$instance, 'guard_front_request'], 1);
        add_action('admin_init',  [$instance, 'maybe_flush_rules']);
        add_action('wp_ajax_yuz_tra_rw_flush_rules', [$instance, 'ajax_flush_rules']);

        add_filter('home_url',               [$instance, 'filter_home_url'], 10, 4);
        add_filter('post_type_link',         [$instance, 'filter_post_link'], 10, 4);
        add_filter('page_link',              [$instance, 'filter_page_link'], 10, 3); // ✅ 3 args
        add_filter('term_link',              [$instance, 'filter_term_link'], 10, 3);
        add_filter('category_link',          [$instance, 'filter_category_link'], 10, 2);
        add_filter('author_link',            [$instance, 'filter_author_link'], 10, 2);
        add_filter('paginate_links',         [$instance, 'filter_paginate_links']);
        add_filter('walker_nav_menu_start_el', [$instance, 'filter_nav_menu_item'], 10, 4);

        $logger->log('success', 'YUZ_Rewrite class initialized successfully');
    }

    public function register_rewrite_rules(): void {
        (new YUZ_Logger())->log('info', 'Registering rewrite rules');

        $settings = $this->settings->get_option('yuz_tra_settings');
        if (!is_array($settings)) {
            $settings = [];
            (new YUZ_Logger())->log('warning', 'yuz_tra_settings is not an array, using defaults');
        }
        if ($this->should_auto_enable_subdirectories($settings)) {
            $settings['use_subdirectory'] = true;
        }
        if (!isset($settings['use_subdirectory'])) {
            $settings['use_subdirectory'] = false;
            (new YUZ_Logger())->log('info', 'use_subdirectory not set, defaulting to false');
        }
        if (empty($settings['use_subdirectory'])) {
            (new YUZ_Logger())->log('info', 'use_subdirectory disabled, skipping subdirectory rules');
            return;
        }

        $this->db->ensure_tables();
        global $wpdb;
        $table_name = $wpdb->prefix . 'yuz_tra_languages';
        if (!$wpdb->get_var("SHOW TABLES LIKE '$table_name'")) {
            (new YUZ_Logger())->log('critical', 'Language table missing after recreation attempt');
            return;
        }

        $general_settings = $this->settings->get_option('yuz_tra_general');
        if (!is_array($general_settings) || empty($general_settings)) {
            $general_settings = get_option('yuz_tra_general', []);
        }
        $slug_map = [];
        if (is_array($general_settings) && isset($general_settings['yuz_tra_slug']) && is_array($general_settings['yuz_tra_slug'])) {
            $slug_map = array_filter($general_settings['yuz_tra_slug']);
        }

        $languages = [];
        $attempts  = 3;
        while ($attempts > 0) {
            $languages = $wpdb->get_results("SELECT language_code, slug FROM {$table_name} WHERE is_translatable = 1");
            if ($languages !== null) {
                break;
            }
            $attempts--;
            (new YUZ_Logger())->log('warning', "Retry attempt for fetching translatable languages, attempts left: {$attempts}");
            usleep(100000);
        }
        if ($wpdb->last_error) {
            (new YUZ_Logger())->log('critical', 'Database query failed: ' . $wpdb->last_error);
            return;
        }
        if (empty($languages)) {
            (new YUZ_Logger())->log('info', 'No translatable languages found, skipping rewrite rules');
            return;
        }

        $show_on_front = get_option('show_on_front', 'posts');
        $front_page_id = absint(get_option('page_on_front'));
        $front_slug    = $front_page_id ? get_post_field('post_name', $front_page_id) : '';

        foreach ($languages as $lang) {
            $lang_code = sanitize_text_field($lang->language_code);
            if (empty($lang_code)) {
                (new YUZ_Logger())->log('warning', 'Invalid language code: ' . print_r($lang, true));
                continue;
            }

            $raw_slug = '';
            if (isset($lang->slug) && $lang->slug !== null && $lang->slug !== '') {
                $raw_slug = (string) $lang->slug;
            } elseif (isset($slug_map[$lang_code])) {
                $raw_slug = (string) $slug_map[$lang_code];
            }

            $slug = strtolower(trim($raw_slug));
            if ($slug === '') {
                $slug = strtolower($lang_code);
            }

            $slug = str_replace([' ', '_'], '-', $slug);
            $slug = preg_replace('/[^a-z0-9\-]+/i', '-', $slug);
            $slug = preg_replace('/-+/', '-', $slug);
            $slug = trim($slug, '-');

            if ($slug === '') {
                $slug = strtolower(str_replace('_', '-', $lang_code));
            }

            $regex_slug = preg_quote($slug, '#');

            add_rewrite_rule("^{$regex_slug}/(.+?)/?$",
                'index.php?lang=' . $lang_code . '&pagename=$matches[1]', 'top');

            $front_rule = 'index.php?lang=' . $lang_code;
            if ('page' === $show_on_front && $front_page_id > 0 && $front_slug) {
                $front_rule .= '&pagename=' . $front_slug;
            }

            add_rewrite_rule("^{$regex_slug}/?$", $front_rule, 'top');

            (new YUZ_Logger())->log('info', 'Added rewrite rule for language', [
                'code' => $lang_code,
                'slug' => $slug,
            ]);
        }

        (new YUZ_Logger())->log('success', 'Rewrite rules registered for ' . count($languages) . ' languages');
    }

    private function should_auto_enable_subdirectories(array $settings): bool {
        if (!array_key_exists('use_subdirectory', $settings)) {
            return true;
        }
        $value = $settings['use_subdirectory'];
        if ($value === '' || $value === null) {
            return true;
        }
        return false;
    }

    public function add_query_vars(array $vars): array {
        $vars[] = 'lang';
        (new YUZ_Logger())->log('info', 'Added query var: lang');
        return $vars;
    }

    public function filter_home_url($url, $path, $orig_scheme, $blog_id) {
        if ($this->should_skip_link_filter()) {
            return $url;
        }

        return $this->convert_url((string) $url);
    }

    public function filter_post_link($permalink, $post, $leavename, $sample) {
        if ($this->should_skip_link_filter() || !$permalink) {
            return $permalink;
        }

        if (!($post instanceof \WP_Post)) {
            return $permalink;
        }

        $context = [
            'object_type' => 'post',
            'object_id'   => (int) $post->ID,
            'post_type'   => (string) $post->post_type,
        ];

        return $this->convert_url((string) $permalink, $context);
    }

    // ✅ Paramètre $sample rendu optionnel pour éviter tout fatal si un hook tiers n’en passe que 2.
    public function filter_page_link($link, $post_id, $sample = false) {
        if ($this->should_skip_link_filter() || !$link) {
            return $link;
        }

        $post = get_post($post_id);
        $context = [];
        if ($post instanceof \WP_Post) {
            $context = [
                'object_type' => 'post',
                'object_id'   => (int) $post->ID,
                'post_type'   => (string) $post->post_type,
            ];
        }

        return $this->convert_url((string) $link, $context);
    }

    public function filter_term_link($termlink, $term, $taxonomy) {
        if ($this->should_skip_link_filter() || !$termlink) {
            return $termlink;
        }

        if (!($term instanceof \WP_Term)) {
            return $this->convert_url((string) $termlink);
        }

        $context = [
            'object_type' => 'term',
            'object_id'   => (int) $term->term_id,
            'taxonomy'    => (string) $taxonomy,
        ];

        return $this->convert_url((string) $termlink, $context);
    }

    public function filter_category_link($termlink, $term_id) {
        if ($this->should_skip_link_filter() || !$termlink) {
            return $termlink;
        }

        $term = get_term($term_id, 'category');
        $context = [];
        if ($term && !is_wp_error($term)) {
            $context = [
                'object_type' => 'term',
                'object_id'   => (int) $term->term_id,
                'taxonomy'    => (string) $term->taxonomy,
            ];
        }

        return $this->convert_url((string) $termlink, $context);
    }

    public function filter_author_link($link, $author_id) {
        if ($this->should_skip_link_filter() || !$link) {
            return $link;
        }

        $context = [
            'object_type' => 'author',
            'object_id'   => (int) $author_id,
        ];

        return $this->convert_url((string) $link, $context);
    }

    public function filter_paginate_links($links) {
        if ($this->should_skip_link_filter() || empty($links)) {
            return $links;
        }

        if (is_array($links)) {
            foreach ($links as &$item) {
                if (!is_string($item) || $item === '') {
                    continue;
                }
                $item = stripos($item, 'href=') !== false
                    ? $this->convert_html_links($item)
                    : $this->convert_url($item);
            }
            unset($item);
            return $links;
        }

        if (is_string($links)) {
            if (stripos($links, 'href=') !== false) {
                return $this->convert_html_links($links);
            }
            return $this->convert_url($links);
        }

        return $links;
    }

    public function filter_nav_menu_item($item_output, $item, $depth, $args) {
        if ($this->should_skip_link_filter() || !is_string($item_output) || $item_output === '') {
            return $item_output;
        }

        $context = [];
        if ($item instanceof \WP_Post) {
            if ($item->type === 'taxonomy') {
                $context = [
                    'object_type' => 'term',
                    'object_id'   => (int) $item->object_id,
                    'taxonomy'    => (string) $item->object,
                ];
            } elseif ($item->type === 'post_type') {
                $context = [
                    'object_type' => 'post',
                    'object_id'   => (int) $item->object_id,
                    'post_type'   => (string) $item->object,
                ];
            }
        }

        return $this->convert_html_links($item_output, $context);
    }

    private function should_skip_link_filter(): bool
    {
        if (is_admin() && !wp_doing_ajax()) {
            return true;
        }
        if (defined('REST_REQUEST') && REST_REQUEST) {
            return true;
        }
        if (defined('WP_CLI') && WP_CLI) {
            return true;
        }
        if (defined('DOING_CRON') && DOING_CRON) {
            return true;
        }
        return false;
    }

    private function get_default_locale(): string
    {
        $general = (array) $this->settings->get_option('yuz_tra_general');
        $default = $general['yuz_tra_default_language'] ?? get_locale();
        return is_string($default) && $default !== '' ? $default : get_locale();
    }

    private function convert_url(?string $url, array $context = []): string
    {
        $url = (string) $url;
        if ($url === '') {
            return $url;
        }

        if ($this->is_converting) {
            return $url;
        }

        $settings    = (array) $this->settings->get_option('yuz_tra_settings');
        $use_subdir  = !empty($settings['use_subdirectory']);
        $force_param = !empty($settings['force_lang_in_links']);

        $active_locale  = $this->url_converter->get_active_locale();
        $default_locale = $this->get_default_locale();

        if (!$use_subdir && !$force_param && strcasecmp($active_locale, $default_locale) === 0) {
            return $url;
        }

        $this->is_converting = true;
        try {
            return $this->url_converter->get_url_for_language($active_locale, $url, $context);
        } catch (\Throwable $e) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
            }
            return $url;
        } finally {
            $this->is_converting = false;
        }
    }

    private function convert_html_links(string $html, array $context = []): string
    {
        return preg_replace_callback(
            "/href=(\"|')(.*?)(\\1)/i",
            function ($matches) use ($context) {
                $original  = $matches[2];
                $converted = $this->convert_url($original, $context);
                if ($converted === $original) {
                    return $matches[0];
                }
                $quote = $matches[1];
                return 'href=' . $quote . esc_url($converted) . $quote;
            },
            $html
        );
    }

    private function slug_for_locale(string $locale): string
    {
        $general = (array) $this->settings->get_option('yuz_tra_general');
        $map     = (array) ($general['yuz_tra_slug'] ?? []);

        if (!empty($map[$locale])) {
            return sanitize_title($map[$locale]);
        }

        $normalized = strtolower(str_replace('-', '_', $locale));
        $parts      = explode('_', $normalized);
        $root       = $parts[0] ?? $normalized;

        return sanitize_title($root);
    }

    public function force_lang_in_links(): void {
        $settings = $this->settings->get_option('yuz_tra_settings');
        if (!is_array($settings)) {
            $settings = [];
            (new YUZ_Logger())->log('warning', 'yuz_tra_settings is not an array in force_lang_in_links, using defaults');
        }
        if (!isset($settings['force_lang_in_links'])) {
            $settings['force_lang_in_links'] = false;
            (new YUZ_Logger())->log('info', 'force_lang_in_links not set, defaulting to false');
        }

        YUZ_Health_Check::ensure(
            is_array($settings) && isset($settings['force_lang_in_links']),
            'Invalid yuz_tra_settings configuration for force_lang_in_links',
            __METHOD__
        );

        $use_subdir = !empty($settings['use_subdirectory']);

        if ($use_subdir) {
            $current_url = $this->url_converter->cur_page_url();

            if (isset($_GET['lang']) && !$this->should_skip_canonical_redirect()) {
                $requested_lang = sanitize_text_field(wp_unslash((string) $_GET['lang']));
                $base_url       = remove_query_arg('lang', $current_url);
                $target         = $this->url_converter->get_url_for_language($requested_lang, $base_url);

                if (!empty($target) && $target !== $current_url) {
                    (new YUZ_Logger())->log('info', 'Redirecting param lang URL to canonical slug', [
                        'from' => $current_url,
                        'to'   => $target,
                    ]);
                    wp_safe_redirect($target, 301);
                    exit;
                }
            }

            if ($this->should_skip_canonical_redirect()) {
                (new YUZ_Logger())->log('info', 'Skipping canonical redirect due to current context');
                return;
            }

            $active_locale  = $this->url_converter->get_active_locale();
            $default_locale = $this->get_default_locale();

            if (strcasecmp($active_locale, $default_locale) !== 0) {
                $path        = parse_url($current_url, PHP_URL_PATH) ?: '/';
                $first_seg   = trim(explode('/', ltrim($path, '/'))[0] ?? '');
                $expected    = $this->slug_for_locale($active_locale);

                if ($expected && strcasecmp($first_seg, $expected) !== 0) {
                    $target = $this->url_converter->get_url_for_language($active_locale, $current_url);
                    if (!empty($target) && $target !== $current_url) {
                        (new YUZ_Logger())->log('info', 'Redirecting to canonical slug for active locale', [
                            'from' => $current_url,
                            'to'   => $target,
                        ]);
                        wp_safe_redirect($target, 302);
                        exit;
                    }
                }
            }

            return;
        }

        if (empty($settings['force_lang_in_links'])) {
            (new YUZ_Logger())->log('info', 'force_lang_in_links disabled, skipping');
            return;
        }

        $current_lang = get_query_var('lang');
        if ($current_lang) {
            (new YUZ_Logger())->log('info', "Current language already set: $current_lang");
            return;
        }

        $general      = $this->settings->get_option('yuz_tra_general');
        $default_lang = !empty($general['yuz_tra_default_language'])
            ? $general['yuz_tra_default_language']
            : $this->languages->get_default_language();

        if (!$this->is_valid_language($default_lang)) {
            (new YUZ_Logger())->log('warning', "Invalid default language '$default_lang' in force_lang_in_links, no redirection performed");
            return;
        }

        $current_url = $this->url_converter->cur_page_url();
        $new_url     = add_query_arg('lang', $default_lang, $current_url);

        if ($current_url !== $new_url) {
            wp_safe_redirect($new_url);
            exit;
        }

        (new YUZ_Logger())->log('success', "Redirected to URL with lang parameter: $new_url");
    }

    public function maybe_flush_rules(): void {
        $last_flush       = $this->settings->get_option('yuz_rewrite_flush_version', '0');
        $current_version  = '1.0.3';
        $settings         = $this->settings->get_option('yuz_tra_settings');

        YUZ_Health_Check::ensure(
            is_array($settings),
            'Invalid yuz_tra_settings configuration in maybe_flush_rules',
            __METHOD__
        );

        $settings_hash       = md5(json_encode($settings));
        $last_settings_hash  = $this->settings->get_option('yuz_rewrite_settings_hash', '');

        if ($last_flush === $current_version && $last_settings_hash === $settings_hash) {
            (new YUZ_Logger())->log('info', 'No need to flush rewrite rules');
            return;
        }

        flush_rewrite_rules();
        $this->settings->update_option('yuz_rewrite_flush_version', $current_version);
        $this->settings->update_option('yuz_rewrite_settings_hash', $settings_hash);

        (new YUZ_Logger())->log('success', "Rewrite rules flushed and version updated to $current_version");
    }

    public function ajax_flush_rules(): void {
        // Sécurité standard AJAX sans dépendre de YUZ_Ajax
        check_ajax_referer('yuz_con_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error([
                'code'    => 'unauthorized',
                'message' => __('Unauthorized', 'yuz_translation'),
            ], 403);
        }
        $this->maybe_flush_rules();
        wp_send_json_success([
            'message' => __('Rewrite rules flushed', 'yuz_translation'),
        ]);
    }

    private function is_valid_language($lang_code) {
        global $wpdb;
        $this->db->ensure_tables();
        $table_name = $wpdb->prefix . 'yuz_tra_languages';

        if (!$wpdb->get_var("SHOW TABLES LIKE '$table_name'")) {
            (new YUZ_Logger())->log('critical', 'Language table missing after recreation attempt');
            return false;
        }

        $count    = null;
        $attempts = 3;
        while ($attempts > 0) {
            $count = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$table_name} WHERE language_code = %s AND is_translatable = 1",
                $lang_code
            ));
            if ($count !== null) {
                break;
            }
            $attempts--;
            (new YUZ_Logger())->log('warning', "Retry attempt for validating language code {$lang_code}, attempts left: {$attempts}");
            usleep(100000);
        }

        if ($wpdb->last_error) {
            (new YUZ_Logger())->log('critical', 'Database query failed in is_valid_language: ' . $wpdb->last_error);
            return false;
        }

        return $count > 0;
    }

    private function should_skip_canonical_redirect(): bool
    {
        if (is_admin() || wp_doing_ajax()) {
            return true;
        }

        if ((defined('REST_REQUEST') && REST_REQUEST) || (defined('DOING_CRON') && DOING_CRON)) {
            return true;
        }

        if (defined('WP_CLI') && WP_CLI) {
            return true;
        }

        if (function_exists('is_customize_preview') && is_customize_preview()) {
            return true;
        }

        if (is_feed() || is_preview()) {
            return true;
        }

        return false;
    }

    public function applyRules(array $rules): string {
        (new YUZ_Logger())->log('info', 'Applying rewrite rules');
        return implode(',', $rules);
    }

    /**
     * Avant WP_Query: si la requête racine avec ?lang n’a pas mappé la page d’accueil, on injecte page_id/pagename.
     */
    public function guard_front_request($wp): void
    {
        if (!($wp instanceof \WP)) {
            return;
        }

        if (is_admin() || (defined('DOING_AJAX') && DOING_AJAX)) {
            return;
        }

        if ('page' !== get_option('show_on_front')) {
            return;
        }

        $front_id = absint(get_option('page_on_front'));
        if (!$front_id) {
            return;
        }
        $front_slug = get_post_field('post_name', $front_id);
        if (!$front_slug) {
            return;
        }

        $request_path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
        $path_is_root = $request_path === null || $request_path === '' || $request_path === '/';
        if (!$path_is_root) {
            return;
        }

        $lang = '';
        if (isset($wp->query_vars['lang'])) {
            $lang = (string) $wp->query_vars['lang'];
        } elseif (isset($_GET['lang'])) {
            $lang = (string) $_GET['lang'];
        }

        $has_page = !empty($wp->query_vars['page_id']) || !empty($wp->query_vars['pagename']);
        if ($has_page) {
            return;
        }

        $wp->query_vars['page_id']  = $front_id;
        $wp->query_vars['pagename'] = $front_slug;

        (new YUZ_Logger())->log('info', 'Guard front request: injected front page for root + lang', [
            'lang' => $lang,
            'path' => $request_path,
            'front_id' => $front_id,
            'front_slug' => $front_slug,
        ]);
    }

    public function ensure_front_page_slug($query): void
    {
        $logger = new YUZ_Logger();
        $requested_lang = $query->get('lang');
        $request_path   = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
        $path_is_root   = $request_path === null || $request_path === '' || $request_path === '/';

        if (!($query instanceof \WP_Query)) {
            return;
        }

        if (!$query->is_main_query() || is_admin()) {
            if ($requested_lang && $path_is_root) {
                $logger->log('debug', 'Front-page guard skipped (not main or admin)', [
                    'is_main_query' => $query->is_main_query(),
                    'is_admin'      => is_admin(),
                    'lang'          => $requested_lang,
                    'path'          => $request_path,
                ]);
            }
            return;
        }

        if ('page' !== get_option('show_on_front')) {
            if ($requested_lang && $path_is_root) {
                $logger->log('debug', 'Front-page guard skipped (show_on_front!=page)', [
                    'show_on_front' => get_option('show_on_front'),
                    'lang'          => $requested_lang,
                ]);
            }
            return;
        }

        $front_id = absint(get_option('page_on_front'));
        if (!$front_id) {
            if ($requested_lang && $path_is_root) {
                $logger->log('warning', 'Front-page guard missing front_id', [
                    'lang' => $requested_lang,
                ]);
            }
            return;
        }

        $front_slug = get_post_field('post_name', $front_id);
        if (!$front_slug) {
            if ($requested_lang && $path_is_root) {
                $logger->log('warning', 'Front-page guard missing slug', [
                    'front_id' => $front_id,
                    'lang'     => $requested_lang,
                ]);
            }
            return;
        }

        $query_page_id = (int) $query->get('page_id');
        $query_slug    = $query->get('pagename');

        // If WP already targeted the front page ID but slug is missing, inject it for consistency.
        if ($query_slug === '' && $query_page_id === $front_id) {
            $query->set('pagename', $front_slug);
            $logger->log('info', 'Injected front-page slug for main query (missing pagename)', [
                'slug' => $front_slug,
                'lang' => $requested_lang,
            ]);
            return;
        }

        // If a different target is already set, keep it untouched.
        if ($query_slug || $query_page_id) {
            return;
        }

        // Root requests with lang param (or any extra query) can lose the front mapping; restore it.
        if ($path_is_root) {
            $query->set('page_id', $front_id);
            $query->set('pagename', $front_slug);
            $logger->log('info', 'Forced front-page mapping on root request', [
                'slug' => $front_slug,
                'lang' => $requested_lang,
                'path' => $request_path,
            ]);
        }
    }
}
}

if ( class_exists( 'YUZ_Rewrite' ) ) {
    add_action( 'init', [ 'YUZ_Rewrite', 'init' ], 20 );
    (new YUZ_Logger())->log( 'info', 'YUZ_Rewrite init hooked on init at ' . current_time( 'mysql' ) );
} else {
    (new YUZ_Logger())->log( 'critical', 'Failed to create YUZ_Rewrite class' );
}

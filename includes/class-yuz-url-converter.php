<?php
/**
 * Class YUZ_Url_Converter
 * Construction / modification d’URL pour le switch de langue.
 *
 * @package YUZ_Translation
 */

defined('ABSPATH') or exit;

require_once YUZ_TRA_INCLUDES . 'class-yuz-contracts.php';
require_once YUZ_TRA_INCLUDES . 'helpers/lang-helpers.php';

use YUZTRA\Interfaces\UrlConverterInterface;

/**
 * Convertisseur d'URL multilingue.
 * - Supporte query param ?lang=XX et préfixe /{slug}/ (si activé).
 * - Se base sur yuz_tra_all_settings (general + settings) ou un objet settings injecté.
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

class YUZ_Url_Converter implements UrlConverterInterface // ici (20)
{
    /** @var mixed|null */
    private $settings;

    /** @var string|null */
    private $home_url_cache = null;

    /** @var string|null */
    private $active_locale = null;

    /**
     * Cache for translated slugs per object.
     *
     * @var array<string, string|null>
     */
    private $slug_cache = [];

    /**
     * Cache for locale slugs (per locale).
     *
     * @var array<string, string>
     */
    private $locale_slug_cache = [];

    /**
     * Cached map of database slugs keyed by locale.
     *
     * @var array<string, string>|null
     */
    private $db_slug_map_cache = null;

    public function __construct($settings = null)
    {
        $this->settings = $settings;
    }

    /* ---------------------------------- LOG ---------------------------------- */

    protected function log($level, $message, array $ctx = [])
    {
        // Level gating (align with YUZ_Core): default 'warning', WP_DEBUG => 'info', constant override
        $LEVELS = [ 'debug' => 5, 'info' => 10, 'success' => 15, 'warning' => 20, 'error' => 30, 'critical' => 40 ];
        $lvl = strtolower((string)$level);
        if (!isset($LEVELS[$lvl])) { $lvl = 'info'; }
        $threshold = 'warning';
        if (defined('YUZ_TRA_LOG_LEVEL') && is_string(YUZ_TRA_LOG_LEVEL) && isset($LEVELS[strtolower(YUZ_TRA_LOG_LEVEL)])) {
            $threshold = strtolower(YUZ_TRA_LOG_LEVEL);
        } elseif (defined('WP_DEBUG') && WP_DEBUG) {
            $threshold = 'info';
        }
        if ($LEVELS[$lvl] < $LEVELS[$threshold]) { return; }

        $prefixes = [
            'critical' => '🟥 [CRITICAL]',
            'warning'  => '🟨 [WARNING]',
            'success'  => '🟩 [SUCCESS]',
            'info'     => '🟦 [INFO]',
            'debug'    => '🟪 [DEBUG]',
        ];
        $prefix = $prefixes[$lvl] ?? $prefixes['info'];
        $suffix = $ctx ? ' | Context: ' . wp_json_encode($ctx) : '';
        error_log("YUZ-TRA: {$prefix} {$message}{$suffix} at " . current_time('mysql'));
    }

    /* ---------------------------- SETTINGS HELPERS --------------------------- */

    private function get_all_settings(): array
    {
        // Prefer consolidated storage; if a Settings object is injected, ask it first
        if (is_object($this->settings)) {
            try {
                if (method_exists($this->settings, 'get_option')) {
                    $all = (array) $this->settings->get_option('yuz_tra_all_settings');
                    if (!empty($all)) {
                        return $this->ensure_settings_shape($all);
                    }
                }
            } catch (\Throwable $e) {
                // ignore and fallback
            }
            // Fallback: compose from standalone options if consolidated is absent
            try {
                $general  = (array) get_option('yuz_tra_general', []);
                $switch   = (array) get_option('yuz_tra_switcher', []);
                $site     = (array) get_option('yuz_tra_site_settings', []);
                $extra    = (array) get_option('yuz_tra_settings', []);
                $language = (array) get_option('yuz_tra_language_settings', []);

                return $this->ensure_settings_shape([
                    'yuz_tra_general'           => $general,
                    'yuz_tra_switcher'          => $switch,
                    'yuz_tra_site_settings'     => $site,
                    'yuz_tra_settings'          => $extra,
                    'yuz_tra_language_settings' => $language,
                ]);
            } catch (\Throwable $e) {
                // swallow and try consolidated option below
            }
        }

        $all = yuz_settings_get_all();
        $all = is_array($all) ? $all : [];

        return $this->ensure_settings_shape($all);
    }

    private function ensure_settings_shape(array $all): array
    {
        $defaults = [
            'yuz_tra_general'           => [],
            'yuz_tra_switcher'          => [],
            'yuz_tra_site_settings'     => [],
            'yuz_tra_settings'          => [],
            'yuz_tra_language_settings' => [],
        ];

        foreach ($defaults as $key => $base) {
            if (!isset($all[$key]) || !is_array($all[$key])) {
                switch ($key) {
                    case 'yuz_tra_general':
                        $all[$key] = (array) get_option('yuz_tra_general', []);
                        break;
                    case 'yuz_tra_switcher':
                        $all[$key] = (array) get_option('yuz_tra_switcher', []);
                        break;
                    case 'yuz_tra_site_settings':
                        $all[$key] = (array) get_option('yuz_tra_site_settings', []);
                        break;
                    case 'yuz_tra_settings':
                        $all[$key] = (array) get_option('yuz_tra_settings', []);
                        break;
                    case 'yuz_tra_language_settings':
                        $all[$key] = (array) get_option('yuz_tra_language_settings', []);
                        break;
                    default:
                        $all[$key] = $base;
                }
            } else {
                $all[$key] = (array) $all[$key];
            }
        }

        return $all;
    }

    private function get_default_language(): string
    {
        $all = $this->get_all_settings();
        $candidates = [
            $all['yuz_tra_general']['yuz_tra_default_language'] ?? null,
            $all['yuz_tra_language_settings']['default_language'] ?? null,
            $all['yuz_tra_settings']['default_language'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                return function_exists('sanitize_text_field')
                    ? sanitize_text_field($candidate)
                    : trim($candidate);
            }
        }

        $locale = function_exists('get_locale') ? get_locale() : 'fr_FR';
        $locale = is_string($locale) && $locale !== '' ? $locale : 'fr_FR';
        return function_exists('sanitize_text_field') ? sanitize_text_field($locale) : $locale;
    }

    private function get_source_language(): string
    {
        $all = $this->get_all_settings();
        $candidates = [
            $all['yuz_tra_general']['yuz_tra_source_language'] ?? null,
            $all['yuz_tra_language_settings']['source_language'] ?? null,
            $all['yuz_tra_settings']['source_language'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                return function_exists('sanitize_text_field')
                    ? sanitize_text_field($candidate)
                    : trim($candidate);
            }
        }

        return $this->get_default_language();
    }

    /**
     * @return string[] locales (ex: ['en_GB','fr_FR'])
     */
    private function get_translatable_locales(): array
    {
        $all = $this->get_all_settings();
        $arr = $all['yuz_tra_general']['yuz_tra_translatable_languages'] ?? [];
        $locales = array_values(array_filter(is_array($arr) ? $arr : []));

        // Filet de sécurité : toujours inclure les langues source et par défaut pour éviter les switchers incomplets
        foreach ([$this->get_default_language(), $this->get_source_language()] as $extra) {
            if (!is_string($extra) || $extra === '') {
                continue;
            }
            $normalized = strtoupper(str_replace('-', '_', $extra));
            if ($normalized === '') {
                continue;
            }
            if (!in_array($normalized, $locales, true)) {
                $locales[] = $normalized;
            }
        }

        return array_values($locales);
    }

    /**
     * Map locale => slug (ex: ['en_GB'=>'en_gb'])
     */
    private function get_slug_map(): array
    {
        $merged = [];

        $db_map = $this->get_db_slug_map();
        foreach ($db_map as $locale => $slug) {
            if ($locale === '' || $slug === '') {
                continue;
            }
            $merged[$locale] = $slug;
        }

        $settings_map = $this->get_slug_map_from_settings();
        foreach ($settings_map as $locale => $slug) {
            if ($locale === '' || $slug === '') {
                continue;
            }
            if (!isset($merged[$locale])) {
                $merged[$locale] = $slug;
            }
        }

        return $merged;
    }

    private function get_slug_map_from_settings(): array
    {
        $all = $this->get_all_settings();
        $maps = [];

        $general_map = $all['yuz_tra_general']['yuz_tra_slug'] ?? [];
        if (is_array($general_map)) {
            $maps[] = $general_map;
        }

        $settings_map = $all['yuz_tra_settings']['yuz_tra_slug'] ?? [];
        if (is_array($settings_map)) {
            $maps[] = $settings_map;
        }

        $language_settings = $all['yuz_tra_language_settings'] ?? [];
        if (is_array($language_settings)) {
            foreach (['slug_map', 'slugs', 'yuz_tra_slug'] as $key) {
                if (!empty($language_settings[$key]) && is_array($language_settings[$key])) {
                    $maps[] = $language_settings[$key];
                }
            }
        }

        $merged = [];
        foreach ($maps as $map) {
            foreach ($map as $locale => $slug) {
                if (!is_string($locale) || $locale === '') {
                    continue;
                }
                $slug_str = is_string($slug) ? trim($slug) : '';
                if ($slug_str === '') {
                    continue;
                }

                $locale_key = function_exists('sanitize_text_field')
                    ? sanitize_text_field($locale)
                    : trim($locale);
                if ($locale_key === '') {
                    continue;
                }

                $slug_key = $this->normalize_slug_value($slug_str);

                if ($slug_key === '') {
                    continue;
                }

                if (!isset($merged[$locale_key])) {
                    $merged[$locale_key] = $slug_key;
                }
            }
        }

        return $merged;
    }

    private function normalize_slug_value($slug): string
    {
        if (!is_string($slug)) {
            return '';
        }

        $normalized = strtolower(trim($slug));
        if ($normalized === '') {
            return '';
        }

        $normalized = str_replace([' ', '_'], '-', $normalized);
        $normalized = preg_replace('/[^a-z0-9\-]+/i', '-', $normalized);
        if (!is_string($normalized)) {
            $normalized = '';
        }

        $normalized = trim($normalized, '-');
        return $normalized;
    }

    private function get_db_slug_map(): array
    {
        if ($this->db_slug_map_cache !== null) {
            return $this->db_slug_map_cache;
        }

        $this->db_slug_map_cache = [];

        try {
            global $wpdb;
            if (!isset($wpdb) || !($wpdb instanceof \wpdb)) {
                return $this->db_slug_map_cache;
            }

            $table = $wpdb->prefix . 'yuz_tra_languages';
            if ($table === '') {
                return $this->db_slug_map_cache;
            }

            $results = $wpdb->get_results("SELECT locale, slug FROM {$table}", ARRAY_A);
            if (is_array($results)) {
                foreach ($results as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $locale = isset($row['locale']) ? (string) $row['locale'] : '';
                    $slug   = isset($row['slug']) ? (string) $row['slug'] : '';

                    $locale = function_exists('sanitize_text_field')
                        ? sanitize_text_field($locale)
                        : trim($locale);
                    $slug = $this->normalize_slug_value($slug);

                    if ($locale === '' || $slug === '') {
                        continue;
                    }

                    $this->db_slug_map_cache[$locale] = $slug;
                }
            }
        } catch (\Throwable $e) {
            // swallow DB issues and keep fallback behaviour
        }

        return $this->db_slug_map_cache;
    }


    /**
     * Map locale => code (ex: ['en_GB'=>'en_GB'])
     */
    private function get_code_map(): array
    {
        $all = $this->get_all_settings();
        $map = $all['yuz_tra_general']['yuz_tra_code'] ?? [];
        return is_array($map) ? $map : [];
    }

    /**
     * True si /{slug}/ doit être utilisé (au lieu de ?lang=)
     */
    private function use_subdirectory(): bool
    {
        $all = $this->get_all_settings();
        $language_flag = $all['yuz_tra_language_settings']['use_subdirectory'] ?? null;
        $settings_flag = $all['yuz_tra_settings']['use_subdirectory'] ?? null;
        $flag = $language_flag ?? $settings_flag;
        return $this->is_truthy($flag);
    }

    private function should_add_subdirectory_for_default(): bool
    {
        $all = $this->get_all_settings();
        $language_flag = $all['yuz_tra_language_settings']['add_subdir_for_default'] ?? null;
        $settings = (array) ($all['yuz_tra_settings'] ?? []);
        $candidates = [
            $language_flag,
            $settings['add_subdir_for_default'] ?? null,
            $settings['subdirectory_for_default'] ?? null,
            $settings['subdirectory_default_language'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if ($this->is_truthy($candidate)) {
                return true;
            }
        }

        return false;
    }

    private function is_truthy($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (int) $value === 1;
        }
        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
        }
        return false;
    }

    /* ------------------------------- URL HELPERS ------------------------------ */

    /**
     * Home URL mise en cache.
     */
    private function get_cached_home_url(): string
    {
        if ($this->home_url_cache === null) {
            $home = '';

            if (defined('WP_HOME') && WP_HOME) {
                $home = (string) WP_HOME;
            }

            if ($home === '') {
                $scheme = 'http';
                if (
                    (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                    || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
                ) {
                    $scheme = 'https';
                }

                $host = $_SERVER['HTTP_HOST'] ?? '';
                if ($host === '' && isset($_SERVER['SERVER_NAME'])) {
                    $host = (string) $_SERVER['SERVER_NAME'];
                }

                if ($host !== '') {
                    $port = '';
                    if (!empty($_SERVER['SERVER_PORT']) && !in_array((string) $_SERVER['SERVER_PORT'], ['80', '443'], true) && strpos($host, ':') === false) {
                        $port = ':' . (string) $_SERVER['SERVER_PORT'];
                    }
                    $home = $scheme . '://' . $host . $port;
                }
            }

            if ($home === '') {
                $raw_home = get_option('home');
                if (is_string($raw_home) && $raw_home !== '') {
                    $home = $raw_home;
                }
            }

            if ($home === '') {
                $raw_site = get_option('siteurl');
                if (is_string($raw_site) && $raw_site !== '') {
                    $home = $raw_site;
                }
            }

            if ($home === '') {
                $home = 'http://localhost';
            }

            $this->home_url_cache = rtrim($home, '/');
            // This is a noisy trace: keep at debug level
            $this->log('debug', 'Cached home URL', ['home' => $this->home_url_cache]);
        }
        return $this->home_url_cache;
    }

    /**
     * URL courante.
     */
    // includes/class-yuz-url-converter.php
public function cur_page_url(): string
{
    static $depth = 0;
    if (++$depth > 2) {
        $depth--;
        return $this->get_cached_home_url(); // garde anti-boucle
    }

    try {
        $request_uri = $_SERVER['REQUEST_URI'] ?? '/';

        // Détecter le scheme/host réels de la requête
        $scheme = 'http';
        if (
            (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
        ) {
            $scheme = 'https';
        }
        $reqHost = $_SERVER['HTTP_HOST'] ?? parse_url($this->get_cached_home_url(), PHP_URL_HOST) ?? '';
        $port    = '';
        if (!empty($_SERVER['SERVER_PORT']) && !in_array((string)$_SERVER['SERVER_PORT'], ['80','443'], true)) {
            $port = ':' . (string) $_SERVER['SERVER_PORT'];
        }

        // Reconstruire l'absolue depuis le contexte (et pas home_url)
        $absolute = $scheme . '://' . $reqHost . $port . $request_uri;

        $parts = wp_parse_url($absolute);
        if (!is_array($parts)) {
            return $this->get_cached_home_url();
        }

        // Nettoyage des QS internes
        $query_args = [];
        if (!empty($parts['query'])) {
            parse_str($parts['query'], $query_args);
            foreach (array_keys($query_args) as $key) {
                if (stripos((string)$key, 'yuz-edit-translation') === 0) {
                    unset($query_args[$key]);
                }
            }
        }

        $path     = isset($parts['path']) && $parts['path'] !== '' ? $parts['path'] : '/';
        $query    = $query_args ? '?' . http_build_query($query_args) : '';
        $fragment = isset($parts['fragment']) && $parts['fragment'] !== '' ? '#' . $parts['fragment'] : '';

        return $scheme . '://' . $reqHost . $port . $path . $query . $fragment;

    } finally {
        $depth--;
    }
}


    private function resolve_locale_and_slug(string $input): array
    {
        $candidate = trim((string) $input);
        if ($candidate === '') {
            return [null, null];
        }

        $locales  = $this->get_translatable_locales();
        $slug_map = $this->get_slug_map();
        $code_map = $this->get_code_map();

        // Trace candidate + config to debug locale resolution issues
        $serializer = function_exists('wp_json_encode') ? 'wp_json_encode' : 'json_encode';
        $this->log('debug', 'Resolving locale and slug', [
            'input'    => $candidate,
            'locales'  => $locales,
            'slug_map' => $slug_map,
            'code_map' => $code_map,
        ]);
        error_log('[YUZ-URL][resolve_locale_and_slug] input=' . $candidate . ' locales=' . implode(',', $locales) . ' slug_map=' . (string) $serializer($slug_map) . ' code_map=' . (string) $serializer($code_map));

        $normalized_upper = strtoupper(str_replace('-', '_', $candidate));
        $candidate_lower  = strtolower($candidate);

        // 1) Direct locale match (case insensitive)
        foreach ($locales as $locale) {
            if (strcasecmp($normalized_upper, strtoupper($locale)) === 0) {
                return [$locale, $this->slug_for_locale($locale)];
            }
        }

        // 2) Direct slug match from configured map
        foreach ($slug_map as $locale => $slug) {
            if ($slug !== '' && strcasecmp($candidate_lower, strtolower($slug)) === 0) {
                $resolved_slug = $this->slug_for_locale($locale);
                return [$locale, $resolved_slug !== '' ? $resolved_slug : $slug];
            }
        }

        // 3) Code map match (ex: locale code)
        foreach ($code_map as $locale => $code) {
            if ($code && strcasecmp($candidate_lower, strtolower((string) $code)) === 0) {
                return [$locale, $this->slug_for_locale($locale)];
            }
        }

        // 4) Two-letter root (en, fr, …)
        if (preg_match('/^[a-z]{2}$/i', $candidate)) {
            $resolved = '';
            if (function_exists('yuz_resolve_target_locale')) {
                $resolved = (string) yuz_resolve_target_locale($candidate);
            }
            if ($resolved !== '') {
                $normalized = strtoupper(str_replace('-', '_', $resolved));
                foreach ($locales as $locale) {
                    if (strcasecmp($normalized, strtoupper($locale)) === 0) {
                        return [$locale, $this->slug_for_locale($locale)];
                    }
                }
            }

            foreach ($locales as $locale) {
                if (stripos($locale, strtoupper($candidate) . '_') === 0) {
                    return [$locale, $this->slug_for_locale($locale)];
                }
            }
        }

        // 5) Compare against normalized slugs
        $slug_candidate = strtolower(str_replace('_', '-', $candidate));
        foreach ($slug_map as $locale => $slug) {
            if ($slug !== '' && strcasecmp($slug_candidate, strtolower($slug)) === 0) {
                $resolved_slug = $this->slug_for_locale($locale);
                return [$locale, $resolved_slug !== '' ? $resolved_slug : $slug];
            }
        }

        foreach ($locales as $locale) {
            $slug = $this->slug_for_locale($locale);
            if ($slug !== '' && strcasecmp($slug, $slug_candidate) === 0) {
                return [$locale, $slug];
            }
        }

        return [null, null];
    }

    public function slug_for_locale(string $locale): string
    {
        $locale = function_exists('sanitize_text_field')
            ? sanitize_text_field($locale ?: '')
            : trim((string) $locale);
        if ($locale === '') {
            return '';
        }

        $normalized = strtoupper(str_replace('-', '_', $locale));
        if (isset($this->locale_slug_cache[$normalized])) {
            return $this->locale_slug_cache[$normalized];
        }

        $slug = '';

        $db_map = $this->get_db_slug_map();
        if (!empty($db_map)) {
            foreach ($db_map as $loc => $db_slug) {
                $loc_normalized = strtoupper(str_replace('-', '_', $loc));
                if ($loc_normalized === $normalized && $db_slug !== '') {
                    $slug = $db_slug;
                    break;
                }
            }
        }

        if ($slug === '') {
            $settings_map = $this->get_slug_map_from_settings();
            foreach ($settings_map as $loc => $value) {
                $loc_normalized = strtoupper(str_replace('-', '_', $loc));
                if ($loc_normalized === $normalized && $value !== '') {
                    $slug = $value;
                    break;
                }
            }
        }

        $slug = $this->normalize_slug_value($slug);

        if ($slug === '') {
            $slug = $this->normalize_slug_value(str_replace('_', '-', $locale));
        }
        if ($slug === '') {
            $slug = $this->normalize_slug_value($locale);
        }

        return $this->locale_slug_cache[$normalized] = $slug;
    }

    private function should_use_subdirectory_for_locale(?string $locale, ?string $slug): bool
    {
        if (!$this->use_subdirectory() || !$locale || !$slug) {
            return false;
        }

        return !$this->should_skip_default_slug($locale);
    }

    private function should_skip_default_slug(string $locale): bool
    {
        if ($this->should_add_subdirectory_for_default()) {
            return false;
        }

        $default = strtoupper(str_replace('-', '_', $this->get_default_language()));
        $locale  = strtoupper(str_replace('-', '_', $locale));

        return $default !== '' && $default === $locale;
    }

    private function locale_to_query_param(string $locale): string
    {
        $code_map = $this->get_code_map();
        if (isset($code_map[$locale]) && $code_map[$locale] !== '') {
            return strtolower((string) $code_map[$locale]);
        }

        $slug_map = $this->get_slug_map();
        if (isset($slug_map[$locale]) && $slug_map[$locale] !== '') {
            return strtolower((string) $slug_map[$locale]);
        }
        foreach ($slug_map as $loc => $slug) {
            if ($slug !== '' && strcasecmp($loc, $locale) === 0) {
                return strtolower((string) $slug);
            }
        }

        $normalized = strtolower(str_replace('-', '_', $locale));
        return substr($normalized, 0, 2);
    }

    private function should_skip_path(string $path): bool
    {
        $normalized = '/' . ltrim($path, '/');
        $normalized = strtolower($normalized);

        $exclusions = [
            '/wp-admin',
            '/wp-login.php',
            '/wp-json',
            '/wp-includes',
            '/wp-content',
            '/xmlrpc.php',
            '/wp-cron.php',
        ];

        foreach ($exclusions as $prefix) {
            if (substr($normalized, 0, strlen($prefix)) === $prefix) {
                return true;
            }
        }

        return false;
    }

    private function should_skip_full_url(string $url): bool
    {
        $url = trim($url);
        if ($url === '' || $url === '#') {
            return true;
        }
        return (bool) preg_match('#^(?:mailto:|tel:|javascript:)#i', $url);
    }

    private function normalize_path(string $path): string
    {
        if ($path === '') {
            return '/';
        }

        $path = preg_replace('#/{2,}#', '/', $path);
        if ($path === '') {
            return '/';
        }
        if ($path[0] !== '/') {
            $path = '/' . $path;
        }

        return $path;
    }

    private function hosts_match(?array $home_parts, ?array $url_parts): bool
    {
        $url_host  = $url_parts['host'] ?? '';
        $home_host = $home_parts['host'] ?? '';

        if ($url_host === '' || $home_host === '') {
            return true;
        }

        if (!strcasecmp($url_host, $home_host)) {
            $url_port  = isset($url_parts['port']) ? (string) $url_parts['port'] : '';
            $home_port = isset($home_parts['port']) ? (string) $home_parts['port'] : '';
            return $url_port === $home_port;
        }

        return false;
    }

    private function apply_translated_slug_segments(array $segments, array $context, string $locale): array
    {
        if (empty($segments)) {
            return $segments;
        }

        $object_type = strtolower((string) ($context['object_type'] ?? ''));
        $object_id   = isset($context['object_id']) ? (int) $context['object_id'] : 0;

        if ($object_id <= 0) {
            return $segments;
        }

        if ($object_type === '') {
            $object_type = 'post';
        }

        $translated = $this->lookup_translated_slug($object_type, $object_id, $locale, $context);
        if ($translated) {
            $segments[count($segments) - 1] = $translated;
        }

        return $segments;
    }

    private function lookup_translated_slug(string $object_type, int $object_id, string $locale, array $context): ?string
    {
        if ($object_id <= 0 || $locale === '') {
            return null;
        }

        $lang = strtoupper(str_replace('-', '_', $locale));
        $key  = strtolower($object_type) . ':' . $object_id . ':' . $lang;

        if (array_key_exists($key, $this->slug_cache)) {
            return $this->slug_cache[$key];
        }

        global $wpdb;

        static $slugs_table_exists = null;
        static $translations_table_exists = null;

        $slug = null;
        $table_slugs = $wpdb->prefix . 'yuz_tra_slugs';
        if ($slugs_table_exists !== false) {
            if ($slugs_table_exists === null) {
                $slugs_table_exists = (bool) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table_slugs));
            }
            if ($slugs_table_exists) {
                $slug = $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT slug FROM {$table_slugs} WHERE object_type = %s AND object_id = %d AND lang = %s LIMIT 1",
                        strtolower($object_type),
                        $object_id,
                        $lang
                    )
                );
            }
        }

        if (!$slug && $object_type === 'post') {
            $table_translations = $wpdb->prefix . 'yuz_tra_translations';
            if ($translations_table_exists !== false) {
                if ($translations_table_exists === null) {
                    $translations_table_exists = (bool) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table_translations));
                }
                if ($translations_table_exists) {
                    $slug = $wpdb->get_var(
                        $wpdb->prepare(
                            "SELECT translated_slug FROM {$table_translations}
                             WHERE post_id = %d AND language_code = %s AND translated_slug <> ''
                             ORDER BY updated_at DESC LIMIT 1",
                            $object_id,
                            $lang
                        )
                    );
                }
            }
        }

        $normalized_slug = ($slug !== null && $slug !== '')
            ? $this->normalize_slug_value($slug)
            : '';
        $this->slug_cache[$key] = $normalized_slug !== '' ? $normalized_slug : null;

        return $this->slug_cache[$key];
    }

    /**
     * Supprime un préfixe /{slug}/ existant dans un path.
     */
    private function strip_existing_lang_prefix(string $path): string
    {
        $path = '/' . ltrim($path, '/');
        $slugs = array_values($this->get_slug_map());
        if (!$slugs) {
            return $path;
        }
        $regex = '#^/(?:' . implode('|', array_map('preg_quote', $slugs)) . ')(/|$)#i';
        return preg_replace($regex, '/', $path, 1);
    }

    /**
     * Construit l’URL finale (préfixe slug ou query ?lang=).
     */
    private function build_language_url(string $base_url, string $path, string $query, string $fragment, ?string $locale, ?string $slug, array $context): string
    {
        $scheme_host = rtrim($base_url, '/');
        $path        = $this->normalize_path($path);

        if ($this->should_skip_path($path)) {
            $query_string = ($query !== '') ? '?' . $query : '';
            return $scheme_host . $path . $query_string . $fragment;
        }

        if (empty($context['object_id'])) {
            $guessed_id = url_to_postid($scheme_host . $path . ($query ? '?' . $query : ''));
            if ($guessed_id) {
                $context['object_id']   = $guessed_id;
                $context['object_type'] = 'post';
                $context['post_type']   = get_post_type($guessed_id);
            }
        }

        $clean            = $this->strip_existing_lang_prefix($path);
        $has_trailing     = $clean !== '/' && substr($clean, -1) === '/';
        $segments         = $clean === '/' ? [] : array_filter(explode('/', trim($clean, '/')), 'strlen');
        $query_arguments  = [];
        if ($query !== '') {
            parse_str($query, $query_arguments);
        }

        $slug = $this->normalize_slug_value($slug);

        if ($locale) {
            $segments = $this->apply_translated_slug_segments(array_values($segments), $context, $locale);
        } else {
            $segments = array_values($segments);
        }

        if ($this->should_use_subdirectory_for_locale($locale, $slug)) {
            unset($query_arguments['lang']);
            array_unshift($segments, $slug);
        } elseif ($locale) {
            $query_arguments['lang'] = $this->locale_to_query_param($locale);
        }

        $rebuilt_path = $segments ? '/' . implode('/', $segments) : '/';
        if ($has_trailing && $rebuilt_path !== '/') {
            $rebuilt_path .= '/';
        }
        $rebuilt_path = $this->normalize_path($rebuilt_path);

        $query_string = $query_arguments ? '?' . http_build_query($query_arguments) : '';

        return $scheme_host . $rebuilt_path . $query_string . $fragment;
    }

    /**
 * Convertit une URL potentiellement relative en URL absolue sur le site courant.
 */
public function toAbsolute(string $url): string
{
    $url = trim($url);

    // Vide ⇒ home
    if ($url === '') {
        return $this->get_cached_home_url() . '/';
    }

    // Déjà absolue ?
    $parts = wp_parse_url($url);
    if (!empty($parts['scheme']) && !empty($parts['host'])) {
        return $url;
    }

    // Si relative, on préfixe par le host (home_url sans slash final) + chemin propre
    $base = $this->get_cached_home_url();
    if (isset($url[0]) && $url[0] === '/') {
        return $base . $url;
    }

    // relative sans slash initial
    return $base . '/' . ltrim($url, '/');
}

/**
 * Convertit une URL (absolue ou relative) en chemin relatif (chemin + query + fragment).
 */
public function toRelative(string $url): string
{
    $url = trim($url);
    if ($url === '') {
        return '/';
    }

    $abs = $this->toAbsolute($url);
    $parts = wp_parse_url($abs);

    $path = $parts['path'] ?? '/';
    $query = isset($parts['query']) && $parts['query'] !== '' ? '?' . $parts['query'] : '';
    $fragment = isset($parts['fragment']) && $parts['fragment'] !== '' ? '#' . $parts['fragment'] : '';

    return $path . $query . $fragment;
}

/**
 * Normalise une URL : absolue, schéma/host homogènes, pas de double-slash, trailing slash cohérent.
 */
public function normalize(string $url): string
{
    $abs = $this->toAbsolute($url);
    $abs = preg_replace('#(?<!:)/{2,}#', '/', $abs);

    $home = $this->get_cached_home_url();
    $homeParts = wp_parse_url($home);
    $urlParts  = wp_parse_url($abs);

    // ⚠️ ne pas forcer l'host si différent : on garde l'URL telle quelle pour éviter les cross-host "canoniques"
    if (!empty($urlParts) && $this->hosts_match($homeParts, $urlParts)) {
        $scheme = $homeParts['scheme'] ?? 'https';
        $host   = $homeParts['host']   ?? '';
        $port   = isset($homeParts['port']) ? ':' . $homeParts['port'] : '';
        $path   = $urlParts['path']    ?? '/';
        $query  = isset($urlParts['query']) && $urlParts['query'] !== '' ? '?' . $urlParts['query'] : '';
        $frag   = isset($urlParts['fragment']) && $urlParts['fragment'] !== '' ? '#' . $urlParts['fragment'] : '';

        if ($path !== '/' && substr($path, -1) === '/') {
            $path = rtrim($path, '/');
        }
        $abs = $scheme . '://' . $host . $port . ($path === '' ? '/' : $path) . $query . $frag;
    }

    return $abs;
}


    /* ------------------------------- PUBLIC API ------------------------------ */

    /**
     * Retourne l’URL de la page courante dans la langue cible.
     *
     * @param string $lang_code en / en_GB / en-gb / slug
     * @param string|null $url  URL base (sinon page courante)
     */
    public function get_url_for_language(string $lang_code, string $current_url, array $context = []): string
    {
        $lang_code = function_exists('sanitize_text_field')
            ? sanitize_text_field($lang_code ?: '')
            : trim((string) $lang_code);
        if ($lang_code === '') {
            return $current_url;
        }

        // Decode HTML entities that may appear if a previously-escaped href was reused
        if ($current_url !== '') {
            if (function_exists('wp_specialchars_decode')) {
                $current_url = wp_specialchars_decode($current_url, ENT_QUOTES);
            } else {
                $current_url = html_entity_decode($current_url, ENT_QUOTES, 'UTF-8');
            }
        }

        $base = (trim($current_url) !== '') ? $current_url : $this->cur_page_url();

        if ($this->should_skip_full_url($base)) {
            return $base;
        }

        try {
            $absolute = $this->toAbsolute($base);
            $parts    = wp_parse_url($absolute);
            if (!$parts) {
                return $base;
            }

            $home      = $this->get_cached_home_url();
            $homeParts = wp_parse_url($home);
            if (!$this->hosts_match($homeParts, $parts)) {
                return $base;
            }

            $path     = $parts['path'] ?? '/';
            $query    = $parts['query'] ?? '';
            $fragment = isset($parts['fragment']) ? '#' . $parts['fragment'] : '';

            $normalized_path = $path === '' ? '/' : $path;
            $home_path       = parse_url($home . '/', PHP_URL_PATH) ?: '/';
            $front_id        = absint(get_option('page_on_front'));
            $front_slug      = $front_id ? get_post_field('post_name', $front_id) : '';

            if (!$this->use_subdirectory()
                && 'page' === get_option('show_on_front')
                && $front_slug
                && ($normalized_path === '/' || untrailingslashit($normalized_path) === untrailingslashit($home_path))
            ) {
                $args = [];
                if ($query !== '') {
                    parse_str($query, $args);
                }
                $args['pagename'] = $front_slug;
                $query = http_build_query($args);
            }

            if ($query !== '') {
                $query_args = [];
                parse_str($query, $query_args);
                foreach (array_keys($query_args) as $key) {
                    if (stripos((string) $key, 'yuz-edit-translation') === 0) {
                        unset($query_args[$key]);
                    }
                }
                $query = $query_args ? http_build_query($query_args) : '';
            }

            [$locale, $slug] = $this->resolve_locale_and_slug((string) $lang_code);

            if (!$locale) {
                $this->log('warning', 'Unknown target lang in get_url_for_language()', [
                    'input' => $lang_code,
                    'url'   => $base,
                ]);
                return $base;
            }

            if ($slug === '') {
                $slug = $this->slug_for_locale($locale);
            }

            $final_url = $this->build_language_url($home, $path, $query, $fragment, $locale, $slug, $context);

            if (!filter_var($final_url, FILTER_VALIDATE_URL)) {
                $this->log('critical', 'Invalid URL generated, falling back to base', [
                    'generated' => $final_url,
                    'base'      => $base,
                ]);
                return $base;
            }

            $this->log('success', 'URL conversion completed', [
                'input' => $lang_code,
                'final' => $final_url,
            ]);

            if (!headers_sent()) {
                header('x-yuz-target-locale: ' . $locale);
                header('x-yuz-target-slug: ' . $slug);
                header('x-yuz-target-url: ' . $final_url);
            }
            error_log('[YUZ-URL] lang=' . $lang_code . ' base=' . $base . ' locale=' . $locale . ' slug=' . $slug . ' final=' . $final_url);

            return $final_url;
        } catch (\Throwable $e) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[YUZ-TRA][ERROR] get_url_for_language:EX ' . $e->getMessage());
            }
            return $base;
        }
    }

    /**
     * Retourne la locale active (fr_FR, en_US…) détectée pour la requête courante.
     */
    public function get_active_locale(): string
    {
        if ($this->active_locale !== null) {
            return $this->active_locale;
        }

        $query_lang = '';
        if (function_exists('get_query_var')) {
            $query_lang = (string) get_query_var('lang', '');
        }
        if ($query_lang === '' && isset($_GET['lang'])) {
            $query_lang = (string) $_GET['lang'];
        }

        if ($query_lang !== '') {
            $query_lang = function_exists('sanitize_text_field')
                ? sanitize_text_field($query_lang)
                : trim($query_lang);
            [$locale] = $this->resolve_locale_and_slug($query_lang);
            if ($locale) {
                return $this->active_locale = $locale;
            }
        }

        $detected = $this->detect_locale_from_url($this->cur_page_url());
        if ($detected) {
            return $this->active_locale = $detected;
        }

        return $this->active_locale = $this->get_default_language();
    }

    /**
     * Détecte la locale explicitement encodée dans une URL (?lang= / slug).
     *
     * @param string $url
     * @return string|null
     */
    public function detect_locale_from_url(string $url): ?string
    {
        // Decode entities in case an escaped href (&#038;) was fed back here
        if ($url !== '') {
            $url = function_exists('wp_specialchars_decode')
                ? wp_specialchars_decode($url, ENT_QUOTES)
                : html_entity_decode($url, ENT_QUOTES, 'UTF-8');
        }
        $url = trim($url);
        if ($url === '') {
            return null;
        }

        try {
            $absolute = $this->toAbsolute($url);
        } catch (\Throwable $ignored) {
            $absolute = $url;
        }

        $parts = wp_parse_url($absolute);
        if (!is_array($parts)) {
            return null;
        }

        $query_lang = '';
        if (!empty($parts['query'])) {
            $query_args = [];
            parse_str($parts['query'], $query_args);
            if (!empty($query_args['lang'])) {
                $query_lang = (string) $query_args['lang'];
            }
        }

        if ($query_lang !== '') {
            [$locale] = $this->resolve_locale_and_slug($query_lang);
            if ($locale) {
                return $locale;
            }
        }

        $path = isset($parts['path']) ? (string) $parts['path'] : '/';
        if ($path === '') {
            $path = '/';
        }

        $home_parts = wp_parse_url($this->get_cached_home_url());
        $base_path  = isset($home_parts['path']) ? rtrim((string) $home_parts['path'], '/') : '';
        if ($base_path !== '' && stripos($path, $base_path) === 0) {
            $trimmed = substr($path, strlen($base_path));
            if ($trimmed !== false && $trimmed !== '') {
                $path = $trimmed;
            }
        }

        $segments = array_values(array_filter(explode('/', trim($path, '/'))));
        if (!empty($segments)) {
            $first = strtolower((string) $segments[0]);
            if ($first !== '') {
                $slug_map = $this->get_slug_map();
                foreach ($slug_map as $locale => $slug) {
                    if ($slug !== '' && strtolower((string) $slug) === $first) {
                        return $locale;
                    }
                }
                foreach ($this->get_translatable_locales() as $locale) {
                    $slug = $this->slug_for_locale($locale);
                    if ($slug !== '' && strtolower($slug) === $first) {
                        return $locale;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Déduit la langue courante à partir de l’URL (?lang= / slug) ou du locale WP.
     * Retourne un code 2 lettres (en, fr, …) pour rester simple côté UI.
     */
    public function get_lang_from_url_string(): string
    {
        $locale = $this->get_active_locale();
        $lang   = strtolower(substr(str_replace('-', '_', $locale), 0, 2));
        if ($lang === '') {
            $fallback = strtolower(substr(str_replace('-', '_', $this->get_default_language()), 0, 2));
            $lang = $fallback ?: 'en';
        }
        $this->log('info', 'Language from active locale', ['locale' => $locale, 'lang' => $lang]);
        return $lang;
    }
}

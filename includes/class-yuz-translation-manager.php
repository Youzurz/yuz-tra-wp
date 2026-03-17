<?php
/**
 * Core translation retrieval and caching for front-end.
 * Compatible with YUZ Translate DOM Engine v9+
 */

if (!defined('ABSPATH')) exit;

class YUZ_Translation_Manager {

    const CACHE_KEY_PREFIX = 'yuz_tra_dict_';
    const CACHE_TTL = 3600; // 1h

    private const LANGS_CACHE_KEY   = 'yuz_langs_payload_v1';
    private const LANGS_CACHE_GROUP = 'yuz_tra';
    private const LANGS_CACHE_TTL   = 300; // 5 min cache for langs payload

    /**
     * Bootstrap legacy front container so publish-controller has a host.
     */
    public static function init(): void {
        add_action('yuz/footer/frontend', [__CLASS__, 'print_container'], 5);
    }

    /**
     * Print editor container when the overlay is requested by a translator.
     */
    public static function print_container(): void {
        if (is_admin()) {
            return;
        }
        if (!class_exists('YUZ_Capabilities') || !\YUZ_Capabilities::user_is_translator()) {
            if (isset($_GET['yuz-edit-translation'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
                error_log('[YUZ_TM][CONTAINER] Translator capability missing while ?yuz-edit-translation is present');
            }
            return;
        }

        // Always render for translators on the front so the JS overlay can mount,
        // even when the query string helper (?yuz-edit-translation=1) is absent.
        if (!defined('YUZ_EDITOR_ROOT_PRINTED')) {
            echo "\n<!-- YUZ-TRA Editor Container (server-driven) -->\n";
            echo '<div id="yuz-editor-container" class="yuz-editor-overlay" aria-hidden="false" data-yuz-editor-root></div>' . "\n";
            define('YUZ_EDITOR_ROOT_PRINTED', true);
            do_action('yuz/editor_root_printed');
            echo "<!-- /YUZ-TRA Editor Container -->\n";
            if (isset($_GET['yuzdebug'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
                $uid = get_current_user_id();
                $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
                error_log('[YUZ_TM][CONTAINER] printed (user=' . $uid . ', uri=' . $uri . ')');
            }
        }
    }

    /**
     * Retrieve translations for current language, with cache and fallbacks.
     */
    public static function get_frontend_translations() {
        $lang         = self::normalize_lang_code(self::get_current_lang());
        $ws_settings  = function_exists('get_option') ? (array) get_option('yuz_tra_ws_settings', []) : [];
        $default_lang = self::normalize_lang_code($ws_settings['yuz_tra_default_language'] ?? (function_exists('get_locale') ? get_locale() : ''));
        $source_lang  = self::normalize_lang_code($ws_settings['yuz_tra_source_language'] ?? $default_lang);

        if (defined('YUZ_TRA_DEBUG_FRONT') && YUZ_TRA_DEBUG_FRONT) {
            error_log('[YUZ_TM][GET_FRONTEND_TRANSLATIONS][IN] ' . wp_json_encode([
                'request_uri' => $_SERVER['REQUEST_URI'] ?? '',
                'lang'        => $lang,
                'source'      => $source_lang,
                'default'     => $default_lang,
            ]));
        }

        $dictCount = static function ($payload, $langKey) {
            if (!is_array($payload)) {
                return 0;
            }
            $target = $langKey;
            if ($target === '' || !isset($payload[$target])) {
                $keys = array_keys($payload);
                $target = isset($keys[0]) ? (string) $keys[0] : '';
            }
            if ($target === '') {
                return 0;
            }
            $entry = $payload[$target] ?? null;
            if (is_array($entry) && isset($entry['translationsArray']) && is_array($entry['translationsArray'])) {
                return count($entry['translationsArray']);
            }
            return 0;
        };

        if (!$lang) {
            if (defined('YUZ_TRA_DEBUG_FRONT') && YUZ_TRA_DEBUG_FRONT) {
                error_log('[YUZ_TM][GET_FRONTEND_TRANSLATIONS][OUT] ' . wp_json_encode([
                    'request_uri' => $_SERVER['REQUEST_URI'] ?? '',
                    'lang'        => $lang,
                    'dict_count'  => 0,
                    'all_langs'   => [],
                ]));
            }
            return [];
        }

        $cache_key = self::CACHE_KEY_PREFIX . $lang;
        $cached = wp_cache_get($cache_key, 'yuz_tra');
        if ($cached && is_array($cached)) {
            if (defined('YUZ_TRA_DEBUG_FRONT') && YUZ_TRA_DEBUG_FRONT) {
                error_log('[YUZ_TM][GET_FRONTEND_TRANSLATIONS][OUT] ' . wp_json_encode([
                    'request_uri' => $_SERVER['REQUEST_URI'] ?? '',
                    'lang'        => $lang,
                    'dict_count'  => $dictCount($cached, $lang),
                    'all_langs'   => array_keys($cached),
                ]));
            }
            return $cached;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'yuz_tra_translations';
        $targets = array_values(array_unique(array_filter([
            $lang,
            strlen($lang) === 5 ? substr($lang, 0, 2) : '',
        ])));
        if (empty($targets)) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($targets), '%s'));

        // Quick success validated filter (status IN)
        $rows = $wpdb->get_results(
            $wpdb->prepare("
                SELECT language_code, original_text, translated_text
                FROM {$table}
                WHERE translated_text IS NOT NULL
                  AND translated_text <> ''
                  AND status IN (1,2,3,4)
                  AND language_code IN ({$placeholders})
            ", ...$targets),
            ARRAY_A
        );

        if ($rows && class_exists('YUZ_Logger')) {
            $offspec = [];
            foreach ($rows as $r) {
                $raw  = isset($r['language_code']) ? (string) $r['language_code'] : '';
                $norm = self::normalize_lang_code($raw);
                if ($raw !== '' && $norm !== '' && $raw !== $norm) {
                    $offspec[$raw] = $norm;
                }
            }
            if (!empty($offspec)) {
                try { (new \YUZ_Logger())->log('warning', '[TM] Non-canonical language_code in translations', ['map' => $offspec]); } catch (\Throwable $e) {}
            }
        }
        if ($rows && class_exists('YUZ_Logger')) {
            try {
                (new \YUZ_Logger())->log('info', '[TM] translations fetched', [
                    'lang'  => $lang,
                    'count' => count($rows),
                    'target_variants' => $targets,
                ]);
            } catch (\Throwable $e) {}
        }

        if (!$rows) {
            if (defined('YUZ_TRA_DEBUG_FRONT') && YUZ_TRA_DEBUG_FRONT) {
                error_log('[YUZ_TM][GET_FRONTEND_TRANSLATIONS][OUT] ' . wp_json_encode([
                    'request_uri' => $_SERVER['REQUEST_URI'] ?? '',
                    'lang'        => $lang,
                    'dict_count'  => 0,
                    'all_langs'   => [],
                ]));
            }
            wp_cache_set($cache_key, [], 'yuz_tra', self::CACHE_TTL);
            return [];
        }

        $dict = [];
        foreach ($rows as $r) {
            $key = trim(stripslashes($r['original_text']));
            $val = trim(stripslashes($r['translated_text']));
            if ($key !== '' && $val !== '') {
                $dict[$key] = $val;
            }
        }

        $result = [
            $lang => [ 'translationsArray' => $dict ],
        ];

        if (defined('YUZ_TRA_DEBUG_FRONT') && YUZ_TRA_DEBUG_FRONT) {
            error_log('[YUZ_TM][GET_FRONTEND_TRANSLATIONS][OUT] ' . wp_json_encode([
                'request_uri' => $_SERVER['REQUEST_URI'] ?? '',
                'lang'        => $lang,
                'dict_count'  => $dictCount($result, $lang),
                'all_langs'   => array_keys($result),
            ]));
        }

        wp_cache_set($cache_key, $result, 'yuz_tra', self::CACHE_TTL);
        return $result;
    }

    /**
     * Clear translation cache for all langs or one specific language.
     */
    public static function clear_cache($lang = null) {
        global $wpdb;
        if ($lang) {
            wp_cache_delete(self::CACHE_KEY_PREFIX . $lang, 'yuz_tra');
        } else {
            $langs = self::get_all_langs();
            foreach ($langs as $l) {
                wp_cache_delete(self::CACHE_KEY_PREFIX . $l, 'yuz_tra');
            }
        }
    }

    /**
     * Return all languages that have translations.
     */
    public static function get_all_langs() {
        global $wpdb;
        $table = $wpdb->prefix . 'yuz_tra_translations';
        $langs = $wpdb->get_col("SELECT DISTINCT language_code FROM {$table}");
        return is_array($langs) ? array_filter($langs) : [];
    }

   public static function get_current_lang() {
    $candidates = [];

    // 1️⃣ Query var / switcher (?lang=…)
    $query_lang = isset($_GET['lang']) ? sanitize_text_field((string) $_GET['lang']) : '';
    if ($query_lang !== '') {
        $candidates[] = $query_lang;
    }

    // 2️⃣ Hooks from other multilingual plugins (Polylang / WPML…)
    $filtered_lang = apply_filters('yuz_current_language', '');
    if (is_string($filtered_lang) && $filtered_lang !== '') {
        $candidates[] = $filtered_lang;
    }

    // 3️⃣ Workspace settings (persisted via wizard)
    $ws_settings = (array) get_option('yuz_tra_ws_settings', []);
    foreach (['yuz_tra_current_language', 'yuz_tra_default_language', 'yuz_tra_source_language'] as $key) {
        if (!empty($ws_settings[$key]) && is_string($ws_settings[$key])) {
            $candidates[] = (string) $ws_settings[$key];
        }
    }

    // 4️⃣ Legacy options
    $default_lang = get_option('yuz_tra_default_lang');
    $source_lang  = get_option('yuz_tra_source_lang');
    if (is_string($default_lang) && $default_lang !== '') {
        $candidates[] = $default_lang;
    }
    if (is_string($source_lang) && $source_lang !== '') {
        $candidates[] = $source_lang;
    }

    // 5️⃣ Locale WordPress actuelle
    $locale = function_exists('get_locale') ? (string) get_locale() : '';
    if ($locale !== '') {
        $candidates[] = $locale;
    }

    foreach ($candidates as $candidate) {
        $normalized = self::normalize_lang_code($candidate);
        if (is_string($normalized) && $normalized !== '') {
            return $normalized;
        }
    }

    return '';
}

    /**
 * Construit les settings front YUZ (sans hard-coding).
 */
    public static function build_frontend_settings(): array {
        // 1️⃣ Langues configurées dans YUZ
        $ws = (array) get_option('yuz_tra_ws_settings', []);
        $source_lang  = self::normalize_lang_code($ws['yuz_tra_source_language']  ?? '');
        $default_lang = self::normalize_lang_code($ws['yuz_tra_default_language'] ?? '');
        $current_lang = self::normalize_lang_code(self::get_current_lang());

        // 2️⃣ Statuts contextuels
        $is_source       = ($current_lang && $source_lang && $current_lang === $source_lang);
        $is_default      = ($current_lang && $default_lang && $current_lang === $default_lang);
        $is_translatable = !$is_source; // typiquement, la langue source n’est pas traduisible

        // 3️⃣ Dictionnaire de traductions (DB)
        $translations = self::get_frontend_translations();

        // 4️⃣ Langues/metadata pour le front (publish-controller, switcher…)
        $langs_payload = self::get_langs_payload();
        $translation_langs = (array) ($langs_payload['translation_langs'] ?? []);
        $language_meta     = (array) ($langs_payload['language_meta'] ?? []);
        $source_lang       = $langs_payload['source_language']  ?: $source_lang;
        $default_lang      = $langs_payload['default_language'] ?: $default_lang;
        $languages         = array_values(array_unique(array_filter(array_merge(
            [$source_lang, $default_lang, $current_lang],
            $translation_langs,
            array_keys($language_meta)
        ))));
        $is_source       = ($current_lang && $source_lang && $current_lang === $source_lang);
        $is_default      = ($current_lang && $default_lang && $current_lang === $default_lang);
        $is_translatable = ($current_lang !== '' && !$is_source);

        // SMOKE LOG — toujours actif pour le front (après normalisation)
        if (!is_admin()) {
            try {
                $counts = [];
                foreach ($translations as $lang => $dict) {
                    $counts[$lang] = is_array($dict) ? count($dict) : -1;
                }
                error_log(
                    '[YUZ_TM][FRONT_SMOKE] lang=' . $current_lang
                    . ' | def=' . $default_lang
                    . ' | src=' . $source_lang
                    . ' | counts=' . wp_json_encode($counts)
                );
            } catch (\Throwable $e) {
                error_log('[YUZ_TM][FRONT_SMOKE_ERR] ' . $e->getMessage());
            }
        }

        // 5️⃣ URL dynamique pour trace (si plugin actif)
        $upload_dir = wp_get_upload_dir();
        $trace_path = trailingslashit($upload_dir['baseurl']) . 'yuz-trace.log';

        // 6️⃣ Contextes de page (post_id + URL canonique pour le front)
        $current_post_id = is_singular() ? (int) get_queried_object_id() : 0;
        $current_url     = '';
        if (!empty($_SERVER['REQUEST_URI'])) {
            $current_url = esc_url_raw(home_url(add_query_arg([], wp_unslash($_SERVER['REQUEST_URI']))));
        }

        // 7️⃣ Construction de l’objet final
        return [
            'ajax_url'          => admin_url('admin-ajax.php'),
            'trace_upload_url'  => esc_url_raw($trace_path),
            'source_language'   => $source_lang,
            'default_language'  => $default_lang,
            'current_language'  => $current_lang,
            'is_source'         => $is_source,
            'is_default'        => $is_default,
            'is_translatable'   => $is_translatable,
            'languages'         => $languages,
            'translation_langs' => $translation_langs,
            'language_meta'     => $language_meta,
            'translations'      => $translations,
            'post_id'           => $current_post_id,
            'page_url'          => $current_url,
            'permissions'       => (class_exists('YUZ_Capabilities') && method_exists('YUZ_Capabilities', 'permissions_payload'))
                ? \YUZ_Capabilities::permissions_payload()
                : [],
        ];
    }

    /**
     * Injects window.yuzTraSettings in footer for front-end scripts.
     */
    public static function inject_frontend_settings() {
        $settings = self::build_frontend_settings();
        echo '<script id="yuz-translate-settings">window.yuzTraSettings = ' . wp_json_encode($settings, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . ';</script>';
    }

    /**
     * Build a language payload consumed by JS controllers and admin screens.
     */
    public static function get_langs_payload(bool $force = false): array {
        if (!$force) {
            $cached = wp_cache_get(self::LANGS_CACHE_KEY, self::LANGS_CACHE_GROUP);
            if (is_array($cached)) {
                return self::normalize_langs_payload($cached);
            }
        }

        $meta = self::collect_language_meta();
        $translation_langs = array_values(
            array_unique(
                array_filter(
                    array_map(
                        'strval',
                        array_keys(
                            array_filter(
                                $meta,
                                static fn ($flags) => !empty($flags['is_translatable'])
                            )
                        )
                    )
                )
            )
        );

        $payload = [
            'translation_langs' => $translation_langs,
            'language_meta'     => $meta,
            'source_language'   => self::detect_source_language($meta),
            'default_language'  => self::detect_default_language($meta),
        ];

        $payload = self::normalize_langs_payload(apply_filters('yuz_tra_langs_payload', $payload));
        wp_cache_set(self::LANGS_CACHE_KEY, $payload, self::LANGS_CACHE_GROUP, self::LANGS_CACHE_TTL);

        return $payload;
    }

    /**
     * Collect language metadata from dedicated table or fall back to options.
     */
    private static function collect_language_meta(): array {
        global $wpdb;

        $meta  = [];
        $table = $wpdb->prefix . 'yuz_tra_languages';
        $like  = str_replace(['_', '%'], ['\\_', '\\%'], $table);
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $like));
        $mergeFlags = static function (array $existing, array $incoming): array {
            $base = array_merge(['is_default' => false, 'is_source' => false, 'is_translatable' => false], $existing);
            return [
                'is_default'      => !empty($base['is_default']) || !empty($incoming['is_default']),
                'is_source'       => !empty($base['is_source']) || !empty($incoming['is_source']),
                'is_translatable' => !empty($base['is_translatable']) || !empty($incoming['is_translatable']),
            ];
        };

        if ($exists) {
            $rows = $wpdb->get_results(
                "SELECT language_code, is_default, is_source, is_translatable FROM {$table}",
                ARRAY_A
            );
            if ($rows) {
                foreach ($rows as $row) {
                    $raw  = isset($row['language_code']) ? (string) $row['language_code'] : '';
                    $code = self::normalize_lang_code($raw);
                    if ($code === '') {
                        continue;
                    }
                    if ($raw !== '' && $raw !== $code) {
                        self::log_lang_format('lang_meta_normalized', ['raw' => $raw, 'normalized' => $code]);
                    }
                    $flags = [
                        'is_default'      => !empty($row['is_default']),
                        'is_source'       => !empty($row['is_source']),
                        'is_translatable' => !empty($row['is_translatable']),
                    ];
                    $meta[$code] = $mergeFlags($meta[$code] ?? [], $flags);
                }
            }
        }

        if (empty($meta)) {
            $general = get_option('yuz_tra_general', []);
            $general = is_array($general) ? $general : [];
            $source  = self::normalize_lang_code($general['yuz_tra_source_language'] ?? '');
            $default = self::normalize_lang_code($general['yuz_tra_default_language'] ?? '');
            $targets = array_filter(
                array_map([self::class, 'normalize_lang_code'], (array) ($general['yuz_tra_translatable_languages'] ?? [])),
                'strlen'
            );

            foreach ($targets as $code) {
                $meta[$code] = $mergeFlags($meta[$code] ?? [], [
                    'is_default'      => ($code === $default),
                    'is_source'       => ($code === $source),
                    'is_translatable' => true,
                ]);
            }

            if ($source !== '' && !isset($meta[$source])) {
                $meta[$source] = $mergeFlags($meta[$source] ?? [], [
                    'is_default'      => ($source === $default),
                    'is_source'       => true,
                    'is_translatable' => true,
                ]);
            }
        }

        return $meta;
    }

    private static function detect_source_language(array $meta): string {
        foreach ($meta as $code => $flags) {
            if (!empty($flags['is_source'])) {
                return (string) $code;
            }
        }
        $fallback = function_exists('get_locale') ? (string) get_locale() : '';
        return self::normalize_lang_code($fallback);
    }

    private static function detect_default_language(array $meta): string {
        foreach ($meta as $code => $flags) {
            if (!empty($flags['is_default'])) {
                return (string) $code;
            }
        }
        $fallback = function_exists('get_locale') ? (string) get_locale() : '';
        return self::normalize_lang_code($fallback);
    }

    private static function normalize_langs_payload(array $payload): array {
        $normalize = [self::class, 'normalize_lang_code'];

        $payload['source_language']  = $normalize($payload['source_language'] ?? '');
        $payload['default_language'] = $normalize($payload['default_language'] ?? '');
        $payload['translation_langs'] = array_values(array_unique(array_filter(array_map(
            $normalize,
            (array) ($payload['translation_langs'] ?? [])
        ))));

        $meta_norm = [];
        foreach ((array) ($payload['language_meta'] ?? []) as $code => $flags) {
            $norm = $normalize($code);
            if ($norm === '') {
                continue;
            }
            $meta_norm[$norm] = [
                'is_default'      => !empty($meta_norm[$norm]['is_default']) || !empty($flags['is_default']),
                'is_source'       => !empty($meta_norm[$norm]['is_source']) || !empty($flags['is_source']),
                'is_translatable' => !empty($meta_norm[$norm]['is_translatable']) || !empty($flags['is_translatable']),
            ];
        }

        $payload['language_meta'] = $meta_norm;

        return $payload;
    }

    private static function log_lang_format(string $tag, array $ctx): void {
        static $seen = [];
        if (isset($seen[$tag])) {
            return;
        }
        $seen[$tag] = true;

        try {
            if (class_exists('\YUZ_Logger')) {
                (new \YUZ_Logger())->log('warning', $tag, $ctx);
            }
        } catch (\Throwable $e) {
            // swallow logging failure
        }
    }

    private static function normalize_lang_code($code): string {
        if (function_exists('yuz_normalize_language_code')) {
            return yuz_normalize_language_code($code);
        }

        $code = str_replace('-', '_', trim((string) $code));
        if ($code === '' || $code === 'auto') {
            return '';
        }

        $parts = array_values(array_filter(explode('_', $code), 'strlen'));
        if (empty($parts)) {
            return '';
        }

        if (count($parts) === 1) {
            $root  = strtolower($parts[0]);
            $parts = [$root, $root === 'en' ? 'US' : strtoupper($root)];
        } else {
            $parts[0] = strtolower($parts[0]);
            $parts[1] = strtoupper($parts[1]);
        }

        return implode('_', array_slice($parts, 0, 2));
    }
}

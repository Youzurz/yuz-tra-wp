<?php
/**
 * Class YUZ_Assets — Orchestrator of the plugin assets (admin, front, editor)
 *
 * Mission
 * -------
 * The **only authorized file** to register/enqueue/localize the plugin's JS & CSS.
 * Ensures isolation by context (tab/admin/front/editor), consistency of loads,
 * and the security/performance invariants around cross-cutting scripts.
 *
 * Discipline (refactor)
 * ---------------------
 * - ACT-01  : Strict whitelist by tab (no cross-load).
 * - ACT-02/11 : The General tab consumes only `yuzGS` (optional legacy shim in DEV).
 * - ACT-03  : Localization PER ACTION, only once, **in `before`**.
 * - ACT-04  : Guards/Freeze to prevent crashes/overwrites.
 * - ACT-05  : Never load scripts from other tabs.
 * - ACT-06  : Service helper (`yuz-translation-service`) without internal UI localization.
 * - ACT-07  : Dom-watch localized **only** if enqueued.
 * - ACT-12..15 : AI tab isolated (`yuzAI`), job pattern, quotas/confidentiality handled by JS/PHP.
 *
 * Transversal Contract (canonical)
 * --------------------------------
 * RULE T-01:
 *   Any TRANSVERSAL script (network/services/maintenance) ONLY READS:
 *     - window.yuzTraSettings.ajax_url  (string)
 *     - window.yuzTraSettings.nonces    (object)
 *   No UI data transits through `yuzTraSettings`.
 *
 * RULE T-02:
 *   As soon as a transversal script is registered/enqueued, this file injects **inline `before`**
 *   `var/assign yuzTraSettings = { ajax_url, nonces(min) };` on its handle.
 *
 *
 * UI Payloads (dedicated, never via yuzTraSettings)
 * -------------------------------------------------
 * - `yuzGS`, `yuzTS`, `yuzTE`, `yuzAT`, `yuzSW`, `yuzAB`, … are localized on their respective handles,
 *   **only** when the bundle/tab requires it.
 *
 * Public API & interface compliance
 * ---------------------------------
 * - Implements `AssetsInterface` via **wrappers**:
 *     • `enqueue_admin_scripts(string $hook)`  → delegates to `enqueue_admin($hook)`
 *     • `enqueue_front_scripts()`              → delegates to `enqueue_front()`
 *     • `add_module_type($tag, $handle, $src)` → delegates to `blk_filter_script_tag(...)`
 * - Filters: `script_loader_tag` (forces `type="module"` for ESM handles, removes `type=module` from vendors),
 *            `clar_inline_script_hashes` (declares the hashes of critical inlines).
 * - Registration helpers: vendors/CDN fallback, `register_*_if_exists()`, versioning by `filemtime`.
 * - Style injection: `inject_flags_css_root_var_static()` sets `--yuz-flags-url` on relevant styles.
 *
 * Bundle system (refactor)
 * ------------------------
 * - API: `YUZ_Assets::require('bundle')` → schedules a controlled load.
 * - Flush: `flush_admin()` / `flush_front()` triggered at the end of enqueue hooks.
 * - Each bundle declares: style handle, script handle, deps, in_footer, and one or more localizations
 *   via extension filters (`apply_filters('yuz/assets/payload/...')`).
 *
 * File exclusivity (Core Rules)
 * -----------------------------
 * - **class-yuz-assets.php** (this file): registration/enqueue/localization/inline/translations.
 * - class-yuz-ajax.php       : AJAX endpoints (`wp_ajax_*` hooks), caps, `wp_send_json*`, `check_ajax_referer`.
 * - class-yuz-renderer.php   : admin menus/screens, Settings API, template rendering.
 * - class-yuz-contracts.php  : `interface`/`trait` declarations only (no hooks/I/O).
 * - class-yuz-fallbacks.php  : Minimal Null Objects/Fallbacks (no hooks/I/O).
 *
 * Forbidden (reminder)
 * --------------------
 * - Register/enqueue/localize assets *outside* of this file.
 * - Wire AJAX hooks/admin menus *outside* their dedicated files.
 * - Alter <script>/<link> tags anywhere else than via this file.
 * - Transit UI data through `yuzTraSettings`.
* -------------------------------------------
* • INLINE SCRIPTS are **forbidden** across the entire YUZ-TRA scope.
* • Transition phase: controlled tolerance via nonce  hashes until the plugin
*  has fully migrated its payloads to external assets.
* • To ENFORCE “no-inline YUZ” right now, activate this filter:
*      add_filter('clar_forbid_yuz_inline', '__return_true');
*  (Disabled by default to avoid breaking before full migration.)
*------------------------------------------------------------
 *   from `yuz-tra.php` down to the last PHP file, under CSP compliance.
 *
 * Extension points
 * ----------------
 * - Payload filters: `yuz/assets/payload/*` (general, translation-editor, switcher, …).
 * - Adding a bundle: declare its config in `bundles()`  call it via `YUZ_Assets::require(...)`.
 *
 * @package YUZ_Translation
 */

/** ======================================================================
 *  Sommaire (zones  ordre)
 *  1) Zone: Constantes & Handles (const)
 *  2) Zone: État & Contexte (state)
 *  3) Zone: Orchestrator (core)
 *  4) Zone: Config.Paths (cfg)
 *  5) Zone: Diagnostics.Logger (log)
 *  6) Zone: Flags.Resolver (flag)
 *  7) Zone: Languages.Repository (lang)
 *  8) Zone: Security.NonceManager (sec)
 *  9) Zone: Guards.Front (guard)
 * 10) Zone: Registrar (reg)
 * 11) Zone: Enqueuer.Admin (enq-admin)
 * 12) Zone: Enqueuer.Front (enq-front)
 * 13) Zone: BlockEditorSupport (blk)
 * 14) Zone: Payloads (pay)
 * 15) Zone: Bundles (bun)
 * 16) Zone: Overlay & Probes (ovl)
 * 17) Zone: Debug (dbg)
 * 18) Zone: Shims & Compat (shim)
 * ====================================================================== */

defined('ABSPATH') || exit;

// Debug plugin: silencieux par défaut en prod.
// Active si YUZ_DEBUG=true ou WP_DEBUG=true.
if (!defined('YUZ_DEBUG')) {
    define('YUZ_DEBUG', false);
}

require_once YUZ_TRA_INCLUDES . 'class-yuz-contracts.php';
require_once YUZ_TRA_INCLUDES . 'class-yuz-fallbacks.php';
if (!class_exists('YUZ_Translation_Manager')) {
    require_once YUZ_TRA_INCLUDES . 'class-yuz-translation-manager.php';
}

use YUZTRA\Interfaces\{AssetsInterface, LanguagesInterface, SettingsInterface};
use YUZTRA\Fallbacks\{NullSettings, NullLanguages};

/** ======================================================================
 * Zone: Helpers globaux (compat)
 * ====================================================================== */
/** ========= Helpers ========= */
if (!function_exists('yuz__is_string_editor_screen')) {
    function yuz__is_string_editor_screen(): bool {
        if (class_exists(__NAMESPACE__ . '\\YUZ_Assets')) {
            $assets = YUZ_Assets::get();
            $assets->log_deprecated(__FUNCTION__, '1.0.0');
            return $assets->guard_is_string_editor_screen();
        }
        return is_admin() && isset($_GET['page']) && $_GET['page'] === 'yuz-string-translation-editor';
    }
}
if (!function_exists('yuz__plugin_base_url')) {
    function yuz__plugin_base_url(): string {
        if (class_exists(__NAMESPACE__ . '\\YUZ_Assets')) {
            $assets = YUZ_Assets::get();
            $assets->log_deprecated(__FUNCTION__, '1.0.0');
            return $assets->cfg_base_url();
        }
        return defined('YUZ_TRA_PLUGIN_FILE')
            ? trailingslashit(plugin_dir_url(YUZ_TRA_PLUGIN_FILE))
            : trailingslashit(dirname(plugins_url('yuz-tra/yuz-tra.php')));
    }
}
if (!function_exists('yuz__plugin_base_path')) {
    function yuz__plugin_base_path(): string {
        if (class_exists(__NAMESPACE__ . '\\YUZ_Assets')) {
            $assets = YUZ_Assets::get();
            $assets->log_deprecated(__FUNCTION__, '1.0.0');
            return $assets->cfg_base_path();
        }
        return defined('YUZ_TRA_PLUGIN_FILE')
            ? trailingslashit(plugin_dir_path(YUZ_TRA_PLUGIN_FILE))
            : trailingslashit(dirname(__DIR__));
    }
}
if (!function_exists('yuz__plugin_asset_url')) {
    function yuz__plugin_asset_url(string $rel): string {
        if (class_exists(__NAMESPACE__ . '\\YUZ_Assets')) {
            $assets = YUZ_Assets::get();
            $assets->log_deprecated(__FUNCTION__, '1.0.0');
            return $assets->cfg_asset_url($rel);
        }
        return yuz__plugin_base_url() . 'assets/' . ltrim($rel, '/');
    }
}
if (!function_exists('yuz__plugin_asset_path')) {
    function yuz__plugin_asset_path(string $rel): string {
        if (class_exists(__NAMESPACE__ . '\\YUZ_Assets')) {
            $assets = YUZ_Assets::get();
            $assets->log_deprecated(__FUNCTION__, '1.0.0');
            return $assets->cfg_asset_path($rel);
        }
        return yuz__plugin_base_path() . 'assets/' . ltrim($rel, '/');
    }
}

if (!function_exists('yuz_json_safe')) {
    function yuz_json_safe($value) {
        $json = wp_json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return str_replace('</script', '<\/script', $json);
    }
}

/** === Flags (partials/switcher) === */
if (!function_exists('yuz_locale_to_flag_code')) {
    function yuz_locale_to_flag_code(string $locale): string {
        if (class_exists(__NAMESPACE__ . '\\YUZ_Assets')) {
            $assets = YUZ_Assets::get();
            $assets->log_deprecated(__FUNCTION__, '1.0.0');
            return $assets->flag_code($locale);
        }
        $l = strtolower(str_replace('_', '-', $locale));
        if (strpos($l, '-') !== false) {
            [, $country] = explode('-', $l, 2);
            return $country;
        }
        static $lang2flag = ['en'=>'gb','fr'=>'fr','es'=>'es','de'=>'de','it'=>'it','pt'=>'pt','ar'=>'sa','nl'=>'nl','pl'=>'pl','ru'=>'ru','ja'=>'jp','zh'=>'cn'];
        return $lang2flag[$l] ?? 'xx';
    }
}
if (!function_exists('yuz_flag_url')) {
    function yuz_flag_url(string $locale_or_code): string {
        if (class_exists(__NAMESPACE__ . '\\YUZ_Assets')) {
            $assets = YUZ_Assets::get();
            $assets->log_deprecated(__FUNCTION__, '1.0.0');
            return $assets->flag_url($locale_or_code);
        }
        $lc = strtolower($locale_or_code);
        $code = (strpos($lc, '-') !== false || strpos($lc, '_') !== false) ? yuz_locale_to_flag_code($lc) : $lc;
        $base_url = trailingslashit(defined('YUZ_TRA_FLAGS_URL')
            ? YUZ_TRA_FLAGS_URL
            : yuz__plugin_asset_url('flags/'));
        $base_dir = trailingslashit(defined('YUZ_TRA_ASSETS_DIR')
            ? YUZ_TRA_ASSETS_DIR . 'flags/'
            : yuz__plugin_asset_path('flags/'));
        $svg = $code . '.svg'; $png = $code . '.png';
        if (@file_exists($base_dir . $svg)) return $base_url . $svg;
        if (@file_exists($base_dir . $png)) return $base_url . $png;
        return $base_url . $svg;
    }
}

if (!function_exists('yuz_get_languages_index')) {
    function yuz_get_languages_index(): array {
        if (class_exists(__NAMESPACE__ . '\\YUZ_Assets')) {
            $assets = YUZ_Assets::get();
            $assets->log_deprecated(__FUNCTION__, '1.0.0');
            return $assets->lang_index();
        }
        global $wpdb;
        $tbl = $wpdb->prefix . 'yuz_tra_languages';
        $rows = (array) $wpdb->get_results(
            "SELECT language_code, language_name, native_name, slug, browser_slug FROM `{$tbl}`",
            ARRAY_A
        );
        $index = [];
        foreach ($rows as $r) {
            $code = (string) ($r['language_code'] ?? '');
            if ($code === '') {
                continue;
            }

            $name   = (string) ($r['language_name'] ?? '');
            $native = (string) ($r['native_name'] ?? '');

            $label = $name !== '' ? $name : $native;
            if ($label === '' && function_exists('yuz_human_label_from_locale')) {
                $label = yuz_human_label_from_locale($code);
            }
            if ($label === '') {
                $label = strtoupper(str_replace('_', '-', $code));
            }

            if ($native === '') {
                $native = $label;
            }

            $index[$code] = [
                'code'         => $code,
                'name'         => $label,
                'native'       => $native,
                'slug'         => (string) ($r['slug'] ?? ''),
                'browser_slug' => (string) ($r['browser_slug'] ?? ''),
                'flag'         => function_exists('yuz_flag_url') ? yuz_flag_url($code) : '',
            ];
        }
        return $index;
    }
}
if (!function_exists('yuz_lang_label')) {
    function yuz_lang_label(string $code): string {
        if (class_exists(__NAMESPACE__ . '\\YUZ_Assets')) {
            $assets = YUZ_Assets::get();
            $assets->log_deprecated(__FUNCTION__, '1.0.0');
            return $assets->lang_label($code);
        }
        static $idx;
        if ($idx === null) {
            $idx = yuz_get_languages_index();
        }

        if (isset($idx[$code]['name']) && $idx[$code]['name'] !== '') {
            return (string) $idx[$code]['name'];
        }

        return function_exists('yuz_human_label_from_locale')
            ? (yuz_human_label_from_locale($code) ?: $code)
            : $code;
    }
}
if (!function_exists('yuz_lang_choices')) {
    function yuz_lang_choices(array $codes): array {
        if (class_exists(__NAMESPACE__ . '\\YUZ_Assets')) {
            $assets = YUZ_Assets::get();
            $assets->log_deprecated(__FUNCTION__, '1.0.0');
            return $assets->lang_choices($codes);
        }
        $idx = yuz_get_languages_index();
        $out = [];
        foreach ($codes as $c) {
            $c = (string) $c;
            if ($c === '') {
                continue;
            }

            $row = $idx[$c] ?? [];
            $out[] = [
                'value' => $c,
                'label' => $row['name'] ?? yuz_lang_label($c),
                'flag'  => $row['flag'] ?? '',
            ];
        }
        return $out;
    }
}

// === helpers diag ===
if (!function_exists('yuz_diag_log')) {
    function yuz_diag_log(string $tag, array $ctx = []): void {
        try {
            $trace = $ctx['trace'] ?? ($_REQUEST['yuz_trace'] ?? '');
            $ctx['trace'] = $trace;
            if (class_exists(__NAMESPACE__ . '\\YUZ_Assets')) {
                $logger = YUZ_Assets::get();
                $logger->log_debug('diag_' . $tag, $ctx);
            }
        } catch (\Throwable $e) {
            // noop
        }
    }
}

if (!class_exists('YUZ_Assets')):

final class YUZ_Assets implements AssetsInterface {

    /** ======================================================================
     * Zone: État & Contexte (state)
     * ====================================================================== */
    private $languages;
    private $settings;

    private static $inst;
    private static $booted = false;
    private static $t_hooks = false;
    private static $overlay_printed = false;
    private static $te_hard_printed = false;
    private static $te_module_tag_printed = false;
    private static $hooks_registered = false;
    private bool $reg_registered = false;
    private array $cache_ver = [];
    private array $sri_cache = [];
    private array $nonces_cache = [
        'full' => null,
        'min'  => null,
    ];
    private ?array $lang_index_cache = null;
    private bool $string_editor_bundle_missing = false;
    private bool $editor_nonce_filter_added = false;
    private bool $review_style_filter_added = false;
    private ?array $editor_settings_payload = null;

    public static function get(): self
    {
        if (!self::$inst) {
            $languages = class_exists('YUZ_Languages')
                ? new \YUZ_Languages(\YUZ_Services::settings(), \YUZ_Services::db())
                : new NullLanguages();
            $settings  = class_exists('YUZ_Settings')
                ? \YUZ_Services::settings()
                : new NullSettings();
            self::$inst = new self($languages, $settings);
        }
        return self::$inst;
    }

    /** ======================================================================
     * Zone: Constantes & Handles (const)
     * ====================================================================== */
    /** Cross-cutting scripts → require minimal injection (ajax_url  nonces) */
    private const T_HANDLES = [
        'yuz-translation-service','yuz-translation-engine','yuz-dom-watch',
        'yuz-translate-dom-changes','yuz-update-db','yuz-update-database','yuz-assets'
    ];

    /** Handles explicitement gérés par script_loader_tag */
    private const PLUGIN_HANDLES = [
        'yuz-assets',
        'yuz-translation-service',
        'yuz-ui-controller',
        'yuz-editor-shim',
        'yuz-string-editor',
        'yuz-language-switcher',
        'yuz-translation-editor',
        'yuz-translation-editor-umd',
        'yuz-strings-dock',
        'yuz-admin-bar',
        'yuz-general-settings',
        'yuz-translation-site',
        'yuz-automatic-translation',
        'yuz-update-db',
        'yuz-translation-engine',
        'yuz-dom-watch',
        'yuz-addons-js',
        'yuz-licenses',
        'yuz-ai-translation',
        'yuz-gutenberg-editor',
        'yuz-string-translation-editor',
        'yuz-string-translation-editor-umd',
        'yuz-debug',
        'yuz-sniffer',
        'yuz-vue',
        'yuz-vue-router',
        'yuz-axios',
        'yuz-select2',
        'yuz-he',
    ];

    /** Flag global pour ajouter type="module" lorsqu'autorisé */
    private const USE_MODULE_TAG = true;

    /** Handles allowed in ESM (others served classic). Policy now UMD-only. */
    private const ESM_WHITELIST = [];

    /** Vendors (NEVER as module) — include jquery to neutralize global “module-izers” */
    private const VENDOR_HANDLES = [
        'jquery','yuz-vue','yuz-vue-router','yuz-axios','yuz-select2','yuz-he'
    ];

    /** Canonical nonce buckets */
    private const OFFICIAL_NONCES = [
        'yuz_tra_nonce',
        'yuz_con_nonce',
        'yuz_int_nonce',
        'yuz_del_nonce',
        'yuz_log_nonce',
        'yuz_hvy_nonce',
        'yuz_api_nonce',
    ];
    private const NONCE_ALIASES = [
        'tra' => 'yuz_tra_nonce',
        'con' => 'yuz_con_nonce',
        'int' => 'yuz_int_nonce',
        'del' => 'yuz_del_nonce',
        'log' => 'yuz_log_nonce',
        'hvy' => 'yuz_hvy_nonce',
        'api' => 'yuz_api_nonce',
    ];
    private const ACTION_NONCE_MATRIX = [
        'yuz_get_pending_translations' => 'yuz_int_nonce',
        'yuz_publish_translations'     => 'yuz_con_nonce',
        'yuz_mass_publish'             => 'yuz_con_nonce',
        'yuz_get_publish_review'       => 'yuz_int_nonce',
        'yuz_tra_te_cre_tstart'        => 'yuz_tra_nonce',
        'yuz_tra_te_cre_translation'   => 'yuz_int_nonce',
        'yuz_tra_te_upd_manual'        => 'yuz_int_nonce',
        'yuz_tra_te_upd_publish'       => 'yuz_int_nonce',
        'yuz_save_translation'         => 'yuz_tra_nonce',
        'yuz_translate'                => 'yuz_hvy_nonce',
        'yuz_tra_tm_translate'         => 'yuz_hvy_nonce',
        'yuz_tra_tm_cre_translation'   => 'yuz_int_nonce',
        'yuz_tra_tm_cre_page'          => 'yuz_int_nonce',
        'yuz_tra_tm_get_translations'  => 'yuz_tra_nonce',
        'yuz_tra_tm_search'            => 'yuz_tra_nonce',
        'yuz_tra_delete_translation'   => 'yuz_del_nonce',
        'yuz_tra_tm_del_translation'   => 'yuz_del_nonce',
        'yuz_ai_test_connection'       => 'yuz_api_nonce',
        'yuz_ai_save_settings'         => 'yuz_con_nonce',
        'yuz_ai_translate_text'        => 'yuz_int_nonce',
        'yuz_ai_batch_translate'       => 'yuz_hvy_nonce',
        'yuz_ai_glossary_upload'       => 'yuz_con_nonce',
        'yuz_ai_job_status'            => 'yuz_int_nonce',
        'yuz_tra_tm_test_api'          => 'yuz_api_nonce',
        'yuz_tra_ws_get_languages'     => 'yuz_tra_nonce',
        'yuz_tra_maintenance'          => 'yuz_hvy_nonce',
        'yuz_tra_js_get_gtxtscan'      => 'yuz_log_nonce',
        'yuz_tra_js_get_regular'       => 'yuz_tra_nonce',
        'yuz_trace_beacon'             => 'yuz_log_nonce',
        'yuz_tra_js_upd_database'      => 'yuz_hvy_nonce',
        'yuz_tra_js_upd_bulkedit'      => 'yuz_hvy_nonce',
        'yuz_tra_js_upd_auto'          => 'yuz_con_nonce',
        'yuz_tra_js_upd_manual'        => 'yuz_int_nonce',
        'yuz_tra_js_upd_publish'       => 'yuz_int_nonce',
    ];

    /** Shims/Compat — public pour être lisible hors classe */
    public const DISABLE_SHIMS = false;

    /** Handles dynamically marked as "module" */
    private static $module_dynamic = [];
    private static bool $nonces_bootstrapped = false;

    /** Mark handles as "module" at runtime */
    public static function mark_module_handles(...$handles): void {
        foreach ($handles as $h) { if (is_string($h) && $h !== '') self::$module_dynamic[$h] = true; }
    }

    /** ======================================================================
     * Zone: Config.Paths (cfg)
     * ====================================================================== */
    /** Centralised plugin base URL */
    public function cfg_base_url(): string {
        if (defined('YUZ_TRA_PLUGIN_FILE')) {
            return trailingslashit(plugin_dir_url(YUZ_TRA_PLUGIN_FILE));
        }
        return trailingslashit(dirname(plugins_url('yuz-tra/yuz-tra.php')));
    }

    /** Centralised plugin base path */
    public function cfg_base_path(): string {
        if (defined('YUZ_TRA_PLUGIN_FILE')) {
            return trailingslashit(plugin_dir_path(YUZ_TRA_PLUGIN_FILE));
        }
        return trailingslashit(dirname(__DIR__));
    }

    /** Asset URL helper */
    public function cfg_asset_url(string $rel): string {
        return trailingslashit($this->cfg_base_url() . 'assets') . ltrim($rel, '/');
    }

    /** Asset path helper */
    public function cfg_asset_path(string $rel): string {
        return trailingslashit($this->cfg_base_path() . 'assets') . ltrim(str_replace('\\', '/', $rel), '/');
    }

    /** Versioning helper */
    public function cfg_ver(string $path_or_url): string {
        if (isset($this->cache_ver[$path_or_url])) {
            return $this->cache_ver[$path_or_url];
        }

        $candidate = $path_or_url;
        if (strpos($path_or_url, '://') === false && !is_file($path_or_url)) {
            $candidate = $this->cfg_asset_path($path_or_url);
        }

        if (is_file($candidate)) {
            $hash = @md5_file($candidate);
            if (is_string($hash) && $hash !== '') {
                return $this->cache_ver[$path_or_url] = $hash;
            }
            $mtime = @filemtime($candidate);
            return $this->cache_ver[$path_or_url] = $mtime ? (string) $mtime : (string) time();
        }

        return $this->cache_ver[$path_or_url] = substr(md5($path_or_url), 0, 8);
    }

    /** Compute SHA-384 integrity hash for local assets. */
    private function asset_sri(string $rel): ?string {
        if (array_key_exists($rel, $this->sri_cache)) {
            return $this->sri_cache[$rel];
        }

        $path = $this->cfg_asset_path($rel);
        if (!is_readable($path)) {
            return $this->sri_cache[$rel] = null;
        }

        $contents = @file_get_contents($path);
        if ($contents === false) {
            return $this->sri_cache[$rel] = null;
        }

        return $this->sri_cache[$rel] = 'sha384-' . base64_encode(hash('sha384', $contents, true));
    }

    /** ======================================================================
     * Zone: Diagnostics.Logger (log)
     * ====================================================================== */
    /** @zone log */
    public function log_debug(string $tag, array $ctx = []): void
    {
        if (!$this->debug_on()) { return; }
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        yuz_tra_debug_log(sprintf('[YUZ][%s] %s', $tag, wp_json_encode($ctx, JSON_UNESCAPED_SLASHES)));
    }

    /** Deprecated helper logger */
    public function log_deprecated(string $what, string $since): void {
        if (function_exists('_doing_it_wrong')) {
            /* translators: %s: version number. */
            _doing_it_wrong( esc_html( $what ), sprintf( esc_html__( 'Deprecated since %s', 'yuz-tra' ), esc_html( $since ) ), esc_html( $since ) );
        }
        $this->log_debug('deprecated', ['what' => $what, 'since' => $since]);
    }

    /** ======================================================================
     * Zone: Flags.Resolver (flag)
     * ====================================================================== */
    /** Flag code resolver */
    public function flag_code(string $locale): string {
        $normalized = strtolower(str_replace('_', '-', $locale));
        if (strpos($normalized, '-') !== false) {
            $parts = explode('-', $normalized, 2);
            return $parts[1];
        }
        static $lang2flag = [
            'en' => 'gb','fr' => 'fr','es' => 'es','de' => 'de','it' => 'it','pt' => 'pt',
            'ar' => 'sa','nl' => 'nl','pl' => 'pl','ru' => 'ru','ja' => 'jp','zh' => 'cn',
        ];
        return $lang2flag[$normalized] ?? 'xx';
    }

    /** Flag URL resolver */
    public function flag_url(string $locale_or_code): string {
        $code = strtolower($locale_or_code);
        if (strpos($code, '-') !== false || strpos($code, '_') !== false) {
            $code = $this->flag_code($code);
        }

        $base_url = trailingslashit(defined('YUZ_TRA_FLAGS_URL')
            ? YUZ_TRA_FLAGS_URL
            : $this->cfg_asset_url('flags/'));

        $base_dir = trailingslashit(defined('YUZ_TRA_ASSETS_DIR')
            ? YUZ_TRA_ASSETS_DIR . 'flags/'
            : $this->cfg_asset_path('flags/'));

        $svg = $code . '.svg';
        $png = $code . '.png';

        if (@file_exists($base_dir . $svg)) {
            return $base_url . $svg;
        }
        if (@file_exists($base_dir . $png)) {
            return $base_url . $png;
        }
        return $base_url . $svg;
    }

    /** ======================================================================
     * Zone: Languages.Repository (lang)
     * ====================================================================== */
    public function lang_index(): array {
        if (is_array($this->lang_index_cache)) {
            return $this->lang_index_cache;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'yuz_tra_languages';
        $rows  = (array) $wpdb->get_results(
            "SELECT language_code, language_name, native_name, slug, browser_slug FROM `{$table}`",
            ARRAY_A
        );

        $index = [];
        foreach ($rows as $row) {
            $code = (string) ($row['language_code'] ?? '');
            if ($code === '') {
                continue;
            }

            $name   = (string) ($row['language_name'] ?? '');
            $native = (string) ($row['native_name'] ?? '');

            $label = $name !== '' ? $name : $native;
            if ($label === '' && function_exists('yuz_human_label_from_locale')) {
                $label = (string) yuz_human_label_from_locale($code);
            }
            if ($label === '') {
                $label = strtoupper(str_replace('_', '-', $code));
            }

            if ($native === '') {
                $native = $label;
            }

            $index[$code] = [
                'code'         => $code,
                'name'         => $label,
                'native'       => $native,
                'slug'         => (string) ($row['slug'] ?? ''),
                'browser_slug' => (string) ($row['browser_slug'] ?? ''),
                'flag'         => $this->flag_url($code),
            ];
        }

        return $this->lang_index_cache = $index;
    }

    public function lang_label(string $code): string {
        $index = $this->lang_index();
        if (isset($index[$code]['name']) && $index[$code]['name'] !== '') {
            return (string) $index[$code]['name'];
        }

        if (function_exists('yuz_human_label_from_locale')) {
            $fallback = (string) yuz_human_label_from_locale($code);
            if ($fallback !== '') {
                return $fallback;
            }
        }

        return (string) $code;
    }

    public function lang_choices(array $codes): array {
        $index = $this->lang_index();
        $out   = [];

        foreach ($codes as $code) {
            $code = (string) $code;
            if ($code === '') {
                continue;
            }

            $row = $index[$code] ?? [];
            $out[] = [
                'value' => $code,
                'label' => $row['name'] ?? $this->lang_label($code),
                'flag'  => $row['flag'] ?? $this->flag_url($code),
            ];
        }

        return $out;
    }

    /** ======================================================================
     * Zone: Security.NonceManager (sec)
     * ====================================================================== */
    /** Build full nonce map */
    public function sec_build_nonces(): array {
        if (is_array($this->nonces_cache['full'])) {
            return $this->nonces_cache['full'];
        }

        $nonces = [];
        foreach (self::OFFICIAL_NONCES as $key) {
            $nonces[$key] = wp_create_nonce($key);
        }

        return $this->nonces_cache['full'] = $nonces;
    }

    /** Nonce picker (scope array or 'min') */
    public function sec_pick_nonces(string|array $scope): array {
        if ($scope === 'min') {
            if (!is_array($this->nonces_cache['min'])) {
                $this->nonces_cache['min'] = $this->sec_build_nonces();
            }
            return $this->nonces_cache['min'];
        }

        if (!is_array($scope)) {
            return $this->sec_build_nonces();
        }

        $all = $this->sec_build_nonces();
        $picked = [];
        foreach ($scope as $key) {
            if (!is_string($key) || $key === '') {
                continue;
            }
            $bucket = $this->sec_resolve_nonce_bucket($key);
            if ($bucket && isset($all[$bucket]) && !isset($picked[$bucket])) {
                $picked[$bucket] = $all[$bucket];
            }
        }
        return $picked;
    }

    private function sec_resolve_nonce_bucket(string $identifier): ?string {
        $identifier = trim($identifier);
        if ($identifier === '') {
            return null;
        }

        if (isset(self::ACTION_NONCE_MATRIX[$identifier])) {
            return self::ACTION_NONCE_MATRIX[$identifier];
        }

        if (in_array($identifier, self::OFFICIAL_NONCES, true)) {
            return $identifier;
        }

        if (isset(self::NONCE_ALIASES[$identifier])) {
            return self::NONCE_ALIASES[$identifier];
        }

        return null;
    }

    /** SHA-384 hash helper for inline allowances */
    public function sec_inline_hash(string $raw): string {
        return 'sha384-' . base64_encode(hash('sha384', $raw, true));
    }

    /** @zone sec */
    public function sec_csp_nonce(): string
    {
        $nonce = '';

        if (function_exists('clar_csp_nonce')) {
            $nonce = clar_csp_nonce();
        }

        if (!$nonce) {
            $nonce = apply_filters('yuz/csp_nonce', '');
        }

        if (!$nonce && class_exists('Clar_Script_Manager') && method_exists('Clar_Script_Manager', 'get_nonce')) {
            $nonce = \Clar_Script_Manager::get_nonce();
        }

        if (!$nonce && defined('CLAR_CSP_NONCE')) {
            $nonce = (string) CLAR_CSP_NONCE;
        }

        if (!$nonce && isset($GLOBALS['clar_csp_nonce'])) {
            $nonce = (string) $GLOBALS['clar_csp_nonce'];
        }

        return is_string($nonce) ? $nonce : '';
    }

    /** ======================================================================
     * Zone: Guards.Front (guard)
     * ====================================================================== */
    public function guard_is_front_edit(): bool {
        $param  = isset($_GET['yuz-edit-translation']) && $_GET['yuz-edit-translation'] === '1'; // phpcs:ignore
        $dbg    = isset($_GET['yuzdebug']); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $can_edit = class_exists('YUZ_Capabilities')
            ? \YUZ_Capabilities::user_is_translator()
            : ( current_user_can('manage_options') || current_user_can('yuz_translate_content') || current_user_can('edit_posts') );

        $ok = !is_admin()
            && is_user_logged_in()
            && $can_edit;

        // Debug parameters never bypass authentication or translator capabilities.

        $this->log_debug('guard_is_front_edit', [
            'param'     => $param,
            'debug'     => $dbg,
            'is_logged' => is_user_logged_in(),
            'roles'     => function_exists('wp_get_current_user') ? wp_get_current_user()->roles : [],
            'can_edit'  => $can_edit,
            'ok'        => $ok,
        ]);
        return $param && $ok;
    }

    public function guard_is_string_editor_screen(): bool {
        return is_admin() && isset($_GET['page']) && $_GET['page'] === 'yuz-string-translation-editor';
    }

    public function guard_strip_legacy_string_editor(): void {
        if (is_admin()) {
            return;
        }
        foreach (['yuz-string-translation-editor','yuz-string-translation-editor-umd'] as $handle) {
            if (wp_script_is($handle, 'enqueued')) {
                wp_dequeue_script($handle);
            }
        }
    }

    /** Browser-safe ESM detection (no bare specifiers) */
    private function looks_browser_esm(string $abs): bool {
        if (!@is_file($abs)) return false;
        $chunk = @file_get_contents($abs, false, null, 0, 8192);
        if ($chunk === false) return false;
        if (!preg_match_all('/\bimport\s(?:[^"\']from\s)?["\']([^"\'])["\']/m', $chunk, $m)) return false;
        foreach ($m[1] as $spec) {
            if (preg_match('#^(?:\.{1,2}/|/|https?://)#', $spec)) return true; // relative or URL
        }
        return false; // bare imports (e.g. 'vue') → not browser-safe
    }

    public function ovl_print_overlay_root(): void {
        if (!$this->guard_is_front_edit() || self::$overlay_printed) {
            return;
        }
        self::$overlay_printed = true;
        echo "<div id=\"yuz-te-advanced\" class=\"yuz-editor-overlay\" aria-live=\"polite\"></div>\n";
    }

    /**
     * Inject the empty Translation Editor module tag in the <head>.
     * Required for the overlay bootstrap JS to detect the module presence.
     */
    public function ovl_print_te_module_tag(): void {
        if (self::$te_module_tag_printed) {
            return;
        }
        $should_print = $this->guard_is_front_edit();
        if (!$should_print) {
            $should_print = wp_script_is('yuz-translation-editor', 'enqueued')
                || wp_script_is('yuz-translation-editor', 'to_do')
                || wp_script_is('yuz-translation-editor', 'registered');
        }
        if (!$should_print) {
            return;
        }
        self::$te_module_tag_printed = true;
        $nonce = $this->sec_csp_nonce();
        $attrs = [
            'id' => 'yuz-te-module',
            'type' => 'module',
            'data-yuz-module' => 'true',
        ];
        if ($nonce) {
            $attrs['nonce'] = $nonce;
        }
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_get_inline_script_tag returns a safely generated script tag.
        echo wp_get_inline_script_tag('', $attrs) . "\n";
    }

    public function ovl_loader_tag_probe($tag, $handle, $src) {
        if ($handle === 'yuz-translation-editor') {
            $this->dbg_overlay_loader_hit($src);
        }
        return $tag;
    }

    public function ovl_footer_state_probe(): void {
        $this->dbg_overlay_footer_state([
            'enqueued' => (int) wp_script_is('yuz-translation-editor', 'enqueued'),
            'done'     => (int) wp_script_is('yuz-translation-editor', 'done'),
            'todo'     => (int) wp_script_is('yuz-translation-editor', 'to_do'),
        ]);
    }

    public function print_te_overlay_root(): void { // @deprecated 1.0.0
        $this->ovl_print_overlay_root();
    }

    public function ovl_print_te_bootstrap(): void {
        if (!$this->guard_is_front_edit() || self::$te_hard_printed) {
            return;
        }
        self::$te_hard_printed = true;

        $handle = 'yuz-translation-editor';
        if (!wp_script_is($handle, 'registered') && !wp_script_is($handle, 'enqueued')) {
            $this->log_debug('missing_handle', ['handle' => $handle]);
            return;
        }

        $this->pay_localize_transverse_min($handle);
        if (!wp_script_is($handle, 'enqueued')) {
            wp_enqueue_script($handle);
        }

        $wp_scripts = function_exists('wp_scripts') ? wp_scripts() : null;
        $already_localized = false;
        if ($wp_scripts && method_exists($wp_scripts, 'get_data')) {
            $flag = $wp_scripts->get_data($handle, 'yuz_te_localized');
            if ($flag) {
                $already_localized = true;
            } else {
                $inline = $wp_scripts->get_data($handle, 'data');
                if (is_string($inline) && strpos($inline, 'var yuzTE') !== false) {
                    $already_localized = true;
                }
            }
        }

        if (!$already_localized) {
            $payload = $this->pay_te();
            if (!empty($payload)) {
                $this->localize($handle, 'yuzTE', $payload);
            }
            if ($wp_scripts && method_exists($wp_scripts, 'add_data')) {
                $wp_scripts->add_data($handle, 'yuz_te_localized', true);
            } else {
                wp_script_add_data($handle, 'yuz_te_localized', true);
            }
        }
    }

    /** ======================================================================
     * Zone: Orchestrator (core)
     * ====================================================================== */
    public static function init(): void {
        $instance = self::get();
        if (self::$hooks_registered) {
            return;
        }
        self::$hooks_registered = true;

        self::add_hooks($instance);
        $instance->dbg_boot();

        $GLOBALS['yuz_assets_singleton'] = $instance;
    }

    private static function add_hooks(self $instance): void {
        add_filter('load_script_translations', ['YUZ_String_Catalog','script_translations'], 20, 4);
        add_filter('pre_load_script_translations', ['YUZ_String_Catalog','missing_script_translations'], 20, 4);
        add_action('admin_enqueue_scripts', [$instance, 'enqueue_admin'], 100);
        add_action('wp_enqueue_scripts',    [$instance, 'enqueue_front'], 100);
        add_action('wp_enqueue_scripts',    [$instance, 'hook_editor_assets'], 110);
        add_action('wp_enqueue_scripts',    [$instance, 'guard_strip_legacy_string_editor'], 99);
        add_action('enqueue_block_editor_assets', [$instance, 'blk_enqueue_block_editor']);
        add_action('wp_enqueue_scripts', static function () {
            static $done = false;
            if ($done) {
                return;
            }
            $done = true;
            if (class_exists('\YUZ\YUZ_Assets')) {
                \YUZ\YUZ_Assets::get()->enq_editor_if_needed();
            } elseif (class_exists('YUZ_Assets')) {
                YUZ_Assets::get()->enq_editor_if_needed();
            }
        }, 100);

        add_action('wp_enqueue_scripts',    [__CLASS__, 'register_three_bridge'], 120);
        add_action('admin_enqueue_scripts', [__CLASS__, 'register_three_bridge'], 120);

        add_action('wp_print_styles',   [__CLASS__, 'inject_flags_css_root_var_static'], 99);
        add_action('admin_print_styles',[__CLASS__, 'inject_flags_css_root_var_static'], 99);

        add_filter('script_loader_tag', [$instance, 'ovl_loader_tag_probe'], 1, 3);
        add_filter('script_loader_tag', [$instance, 'blk_filter_script_tag'], 20, 3);
        add_filter('clar_inline_script_hashes', [$instance, 'add_yuz_tra_settings_hash'], 10, 1);
        add_filter('clar_inline_style_hashes', [$instance, 'add_flags_inline_css_hash'], 10, 1);

        add_action('admin_init', [$instance, 'reg_register_all'], 9);
        add_action('init',       [$instance, 'reg_register_all'], 9);

        add_action('wp_print_footer_scripts', [$instance, 'ovl_footer_state_probe'], -1);
        add_action('wp_head', [$instance, 'ovl_print_te_module_tag'], 0);
        add_action('yuz/footer/frontend', [$instance, 'ovl_print_overlay_root'], 5);
        add_action('yuz/footer/frontend', [$instance, 'ovl_print_te_bootstrap'], 6);
        add_action('yuz/footer/frontend', [$instance, 'print_editor_settings_fallback'], 999);
        add_action('wp_print_footer_scripts', [$instance, 'ovl_print_overlay_root'], 0);
        add_action('wp_body_open', [$instance, 'ovl_print_overlay_root'], 1);
        add_action('admin_print_footer_scripts', [$instance, 'blk_print_missing_bundle_notice'], 999);

        self::hook_transverse();
        self::bun_boot();
    }

    private function __construct(LanguagesInterface $languages, SettingsInterface $settings) {
        $this->languages = $languages;
        $this->settings  = $settings;
    }

    private static bool $bun_hooks_done = false;

    private static function bun_boot(): void {
        if (self::$bun_hooks_done) {
            return;
        }
        self::$bun_hooks_done = true;

        $hooks = [
            ['wp_enqueue_scripts', 'bun_flush_front'],
            ['admin_enqueue_scripts', 'bun_flush_admin'],
            ['enqueue_block_editor_assets', 'bun_flush_admin'],
            ['login_enqueue_scripts', 'bun_flush_front'],
            ['network_admin_enqueue_scripts', 'bun_flush_admin'],
            ['user_admin_enqueue_scripts', 'bun_flush_admin'],
        ];

        foreach ($hooks as [$hook, $method]) {
            add_action($hook, [__CLASS__, $method], 20);
        }

        add_action('enqueue_block_assets', [__CLASS__, 'bun_handle_enqueue_block_assets'], 20);
        add_action('customize_controls_enqueue_scripts', [__CLASS__, 'bun_flush_admin'], 20);
        add_action('customize_preview_init', [__CLASS__, 'bun_handle_customize_preview_init'], 1);
    }

    public static function bun_handle_enqueue_block_assets($context = null): void {
    is_admin() ? self::bun_flush_admin() : self::bun_flush_front();

}
public static function bun_handle_customize_preview_init(): void {
    add_action('wp_enqueue_scripts', [__CLASS__, 'bun_flush_front'], 20);
}

    public static function hook_transverse(): void {
        if (self::$t_hooks) return; self::$t_hooks = true;
        $cb = [__CLASS__,'enforce_transverse_on_print'];
        add_action('wp_print_scripts',          $cb, 1);
        add_action('admin_print_scripts',       $cb, 1);
        add_action('wp_print_footer_scripts',   $cb, 1);
        add_action('admin_print_footer_scripts',$cb, 1);
    }

    /** ======================================================================
     * Zone: Registrar (reg)
     * ====================================================================== */
    public function reg_register_all(): void {
        if ($this->reg_registered) {
            return;
        }
        $this->reg_registered = true;
        $this->reg_register_vendors();
        $this->reg_register_assets();
        $this->reg_register_string_editor();
    }

    private function reg_register_vendors(): void {
        $this->reg_register_vendor('yuz-vue',        'vendor/vue/vue.min.js',               [], '2.7.16');
        $this->reg_register_vendor('yuz-vue-router', 'vendor/vue-router/vue-router.min.js', ['yuz-vue'], '3.5.3');
        $this->reg_register_vendor('yuz-axios',      'vendor/axios.min.js',                 [], '1.11.0');
        $this->reg_register_vendor('yuz-he',         'vendor/he.min.js',                    [], '1.2.0');
    }

    private function reg_register_assets(): void {
        $this->reg_register('yuz-select2','vendor/select2/js/select2.full.min.js',['jquery'], true);
        $this->reg_register_style('yuz-select2','vendor/select2/css/select2.min.css',[]);
        $this->reg_register('yuz-assets','js/yuz-assets.js',['jquery']);
        $this->reg_register('yuz-translation-service','js/yuz-translation-service.js',['yuz-assets']);
        $this->reg_register('yuz-ui-controller','js/widgets/yuz-ui-controller.js',['yuz-assets']);
        $this->reg_register('yuz-editor-shim','js/fix/yuz-editor-shim.js',[], true);
        $this->reg_register('yuz-gutenberg-editor','js/yuz-gutenberg-editor.js',['wp-edit-post','wp-plugins','wp-element','wp-data'], true);
        $this->reg_register('yuz-string-editor','js/yuz-string-editor.js',['jquery','yuz-assets','yuz-ui-controller']);
        $this->reg_register('yuz-language-switcher','js/widgets/yuz-language-switcher.js',['jquery','yuz-assets']);
        $this->reg_register_style('yuz-language-switcher','css/yuz-language-switcher.css',[]);
        $this->reg_register_style('yuz-translation-editor','css/yuz-translation-editor.css',[]);
        $this->reg_register('yuz-translation-editor','js/widgets/yuz-translation-editor.js',['yuz-assets']);
        $this->reg_register('yuz-inspector','js/widgets/inspector/yuz-inspector.js',['yuz-assets']);
        $this->reg_register_style('yuz-strings-dock','css/yuz-string-catalog.css',[]);
        $this->reg_register('yuz-strings-dock','js/yuz-strings-dock.js',[]);
        $this->reg_register_style('yuz-general-settings','css/yuz-general-settings.css',[]);
        $this->reg_register('yuz-admin-bar','js/yuz-admin-bar.js',[]);
        $this->reg_register('yuz-general-settings','js/yuz-general-settings.js',['jquery','jquery-ui-sortable']);
        $this->reg_register('yuz-translation-site','js/yuz-translate-site.js',['jquery','yuz-axios','yuz-assets','yuz-select2','yuz-translation-service']);
        $this->reg_register('yuz-translation-admin','js/yuz-translation-admin.js',['yuz-translation-site']);
        $this->reg_register('yuz-automatic-translation','js/yuz-automatic-translation.js',['jquery','yuz-assets']);
        $this->reg_register('yuz-update-db','js/yuz-update-database.js',['jquery','yuz-assets']);
        $this->reg_register('yuz-guard','js/yuz-guard.js',[], true);

        $engine_rel = 'js/yuz-translate-dom-changes.js';
        $engine_path = $this->cfg_asset_path($engine_rel);
        $engine_registered = $this->reg_register('yuz-translation-engine', $engine_rel, ['jquery','yuz-assets','yuz-guard']);
        if ($engine_registered && file_exists($engine_path)) {
            $version = $this->cfg_ver($engine_path);
            wp_register_script('yuz-dom-watch', false, ['yuz-translation-engine'], $version, true);
        }
        $this->reg_register('yuz-addons-js','js/yuz-addons.js',['jquery','yuz-assets']);
        $this->reg_register('yuz-licenses','js/yuz-licenses.js',['jquery','yuz-assets']);
        $this->reg_register('yuz-ai-translation','js/yuz-ai-translation.js',['jquery','yuz-assets']);
        $this->reg_register('yuz-debug','js/yuz-debug.js',[], true);
    }

    private function reg_register_string_editor(): void {
        $umd = 'js/yuz-string-translation-editor.umd.js';
        $esm = 'js/yuz-string-translation-editor.esm.js';
        $umd_path = $this->cfg_asset_path($umd);
        $esm_path = $this->cfg_asset_path($esm);

        if (file_exists($umd_path)) {
            $this->reg_register('yuz-string-translation-editor-umd',$umd,['yuz-vue','yuz-vue-router','jquery','yuz-select2','yuz-he'], true);
            return;
        }

        if (file_exists($esm_path) && $this->looks_browser_esm($esm_path)) {
            self::mark_module_handles('yuz-string-translation-editor');
            $this->reg_register('yuz-string-translation-editor',$esm,[], true);
        }
    }

    public static function register_three_bridge(): void {
        $bridge_rel  = 'js/yuz-three-bridge.js';
        $assets = self::get();
        $bridge_path = $assets->cfg_asset_path($bridge_rel);
        if (!@is_file($bridge_path)) {
            return;
        }

        $was_enqueued   = wp_script_is('three', 'enqueued');
        $was_registered = wp_script_is('three', 'registered');

        if ($was_enqueued || $was_registered) {
            wp_deregister_script('three');
        }

        wp_register_script(
            'three',
            $assets->cfg_asset_url($bridge_rel),
            [],
            (string) @filemtime($bridge_path),
            true
        );
        if ($was_enqueued) {
            wp_enqueue_script('three');
        }
    }




    /** ======================================================================
     * Zone: Enqueue (enq)
     * ====================================================================== */

        
    public function enq_editor_if_needed(): void {
        // 0) Conditions: overlay demandé + user loggé
        if ( empty($_GET['yuz-edit-translation']) || !is_user_logged_in() ) {
            return;
        }

        // 1) S'assurer que tout est enregistré (utilise l'existant)
        $this->reg_register_all();

        // 2) Enqueue CSS de l'éditeur
        if ( wp_style_is('yuz-translation-editor', 'registered') ) {
            wp_enqueue_style('yuz-translation-editor');
        }

        // 3) Enqueue SCRIPTS (ordre conforme à tes deps)
        if ( wp_script_is('yuz-assets', 'registered') ) {
            wp_enqueue_script('yuz-assets');
        }
        if ( wp_script_is('yuz-editor-shim', 'registered') ) {
            wp_enqueue_script('yuz-editor-shim'); // optionnel, non bloquant
        }
        if ( wp_script_is('yuz-translation-editor', 'registered') ) {
            wp_enqueue_script('yuz-translation-editor');
        }

        // 4) Settings = DB -> fallback options (aucune autre logique ajoutée)
        $opt = get_option('yuz_tra_general', []);
        $db  = (class_exists('YUZ_Languages') && method_exists('YUZ_Languages','get_all')) ? \YUZ_Languages::get_all() : [];

        $trans = array_values(array_map(
            fn($r)=> (is_array($r) && !empty($r['language_code'])) ? $r['language_code'] : null,
            array_filter((array)$db, fn($r)=> is_array($r) && !empty($r['is_translatable']))
        ));
        $trans = array_values(array_filter($trans));
        if (!$trans) {
            $trans = array_values((array)($opt['yuz_tra_translatable_languages'] ?? []));
        }

        $settings = [
            'ajax_url' => $this->ajax(),
            'general'  => [
                'yuz_tra_default_language'        => $opt['yuz_tra_default_language'] ?? null,
                'yuz_tra_source_language'         => $opt['yuz_tra_source_language'] ?? null,
                'yuz_tra_translatable_languages'  => $trans,
            ],
        ];

        // 5) Localiser sur la DÉPENDANCE (yuz-assets) → garantit l'ordre
        if ( wp_script_is('yuz-assets', 'enqueued') ) {
            wp_localize_script('yuz-assets', 'yuzTraSettings', $settings);
        }

        // 6) CSP : ajoute le nonce sur ces scripts (respecte ton système)
        $nonce = $this->sec_csp_nonce();
        if (!$nonce && function_exists('clar_get_current_nonce')) {
            $nonce = clar_get_current_nonce();
        }
        if ($nonce) {
            add_filter('script_loader_tag', function($tag, $handle) use ($nonce){
                if (in_array($handle, ['yuz-assets','yuz-editor-shim','yuz-translation-editor','yuz-inspector'], true)) {
                    if (strpos($tag, ' nonce=') === false) {
                        $tag = str_replace('<script ', '<script nonce="'.$nonce.'" ', $tag);
                    }
                }
                return $tag;
            }, 10, 2);
        }

        // 7) Trace courte (même logger que le reste)
        if ( method_exists($this, 'log_debug') ) {
            $this->log_debug('front_enqueue_editor', [
                'enq_assets'  => wp_script_is('yuz-assets','enqueued') ? 1 : 0,
                'enq_editor'  => wp_script_is('yuz-translation-editor','enqueued') ? 1 : 0,
                'has_loc'     => 1,
                'langs_count' => count($settings['general']['yuz_tra_translatable_languages']),
            ]);
        }
    }


    /** ======================================================================
     * Zone: Enqueuer.Admin (enq-admin)
     * ====================================================================== */
    public function enqueue_admin(string $hook): void {
        if (!$this->is_yuz_page()) {
            return;
        }

        $tab = $this->tab();

        // The catalog is a standalone admin screen, not the legacy visual editor.
        // Do not boot two competing interfaces (Vue, overlays and selection tools).
        if ($tab === 'strings' || $this->guard_is_string_editor_screen()) {
            $this->localize('yuz-strings-dock', 'yuzStrings', $this->pay_strings());
            $this->enqueue_script('yuz-strings-dock');
            $this->enqueue_style('yuz-strings-dock');
            return;
        }

        wp_enqueue_script('jquery');

        if ($this->is_script_ready('yuz-assets')) {
            $this->prepare_transverse('yuz-assets');
            $this->localize('yuz-assets', 'yuzAS', $this->pay_js_settings());
            $this->enqueue_script('yuz-assets');
        }

        if ($tab === 'general') {
            wp_enqueue_script('jquery-ui-sortable');
            if ($this->is_script_ready('yuz-general-settings')) {
                $this->localize('yuz-general-settings', 'yuzGS', $this->pay_general());
                $this->enqueue_script('yuz-general-settings');
            }
            $this->enqueue_style('yuz-general-settings');
        } elseif ($tab === 'translate-site') {
            $this->enqueue_script('yuz-vue');
            $this->enqueue_script('yuz-axios');
            $this->enqueue_script('yuz-select2');
            $this->enqueue_style('yuz-select2');
            $this->enqueue_style('yuz-general-settings');

            if ($this->is_script_ready('yuz-translation-service')) {
                $this->prepare_transverse('yuz-translation-service');
                $this->enqueue_script('yuz-translation-service');
            }

            if ($this->is_script_ready('yuz-translation-site')) {
                $this->localize('yuz-translation-site', 'yuzTS', $this->pay_ts());
                $this->enqueue_script('yuz-translation-site');
                // Fallback inline bootstrap (CSP-safe via nonce/hash) if wp_localize_script is blocked
                add_action('admin_print_footer_scripts', function () {
                    if ($this->tab() !== 'translate-site') { return; }
                    $payload = $this->pay_ts();
                    if (!is_array($payload) || empty($payload)) { return; }
                    $json = wp_json_encode($payload);
                    $nonce = $this->sec_csp_nonce();
                    $attrs = [];
                    if ($nonce) {
                        $attrs['nonce'] = $nonce;
                    }
                    $script = "(function(w){try{if(!w.yuzTS||!w.yuzTS.ajax_url){w.yuzTS=" . $json . ";console.log('[YUZ][SITE] inline bootstrap yuzTS', {nonceKeys:Object.keys(w.yuzTS.nonces||{})});}}catch(e){console.error('[YUZ][SITE] inline bootstrap failed', e);}})(window);";
                    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_get_inline_script_tag returns a safely generated script tag.
                    echo "\n" . wp_get_inline_script_tag($script, $attrs) . "\n";
                }, 5);
                if ($this->is_script_ready('yuz-translation-admin')) {
                    $this->enqueue_script('yuz-translation-admin');
                }
            }
        } elseif ($tab === 'automatic-translation') {
            if ($this->is_script_ready('yuz-automatic-translation')) {
                $this->localize('yuz-automatic-translation', 'yuzAT', $this->pay_automatic_translation());
                $this->enqueue_script('yuz-automatic-translation');
            }
            $this->enqueue_style('yuz-general-settings');
        } elseif ($tab === 'strings') {
            if ($this->is_script_ready('yuz-strings-dock')) {
                $this->localize('yuz-strings-dock', 'yuzStrings', $this->pay_strings());
                $this->enqueue_script('yuz-strings-dock');
            }
            $this->enqueue_style('yuz-strings-dock');
        } elseif ($tab === 'advanced') {
            if ($this->is_script_ready('yuz-update-db')) {
                $this->prepare_transverse('yuz-update-db');
                $this->enqueue_script('yuz-update-db');
            }
            $this->enqueue_dom_refactor_modules();
            $this->enqueue_dom_v9_engine();

            $engine_handle = $this->is_script_ready('yuz-translation-engine') ? 'yuz-translation-engine' : 'yuz-dom-watch';
            if ($engine_handle && $this->is_script_ready($engine_handle)) {
                $this->prepare_transverse($engine_handle);
                $this->localize($engine_handle, 'yuzJS', $this->pay_dom_watch());
                $this->enqueue_script($engine_handle);
            }
            $this->enqueue_style('yuz-general-settings');
        } elseif ($tab === 'ad') {
            if ($this->is_script_ready('yuz-addons-js')) {
                $this->localize('yuz-addons-js', 'yuzAO', $this->pay_addons());
                $this->enqueue_script('yuz-addons-js');
            }
            $this->enqueue_style('yuz-general-settings');
        } elseif ($tab === 'licenses') {
            if ($this->is_script_ready('yuz-licenses')) {
                $this->localize('yuz-licenses', 'yuzLC', $this->pay_licenses());
                $this->enqueue_script('yuz-licenses');
            }
            $this->enqueue_style('yuz-general-settings');
        } elseif ($tab === 'ai-translation') {
            if ($this->is_script_ready('yuz-ai-translation')) {
                $this->localize('yuz-ai-translation', 'yuzAI', $this->pay_ai());
                $this->enqueue_script('yuz-ai-translation');
            }
            $this->enqueue_style('yuz-general-settings');
        }

        // pourquoi pour moi c'est du front, mais c'est peut-être considéré comme du back dans le monde wordpress, parce qu'il faut la capacité d'admin ?

        if ($tab === 'translate-site') {
            if ($this->is_script_ready('yuz-admin-bar')) {
                $this->localize('yuz-admin-bar', 'yuzAB', $this->pay_admin_bar());
                $this->enqueue_script('yuz-admin-bar');
            }
        } else {
            $this->enqueue_script('yuz-admin-bar');
        }

        if ($this->guard_is_string_editor_screen()) {
            $this->blk_enqueue_string_editor();
        }

        self::inject_flags_css_root_var_static();
    }

    private function enqueue_dom_refactor_modules(): void
    {
        static $loaded = false;
        if ($loaded) {
            return;
        }

        $base_url = defined('YUZ_TRA_PLUGIN_FILE')
            ? trailingslashit(plugins_url('assets/js/', YUZ_TRA_PLUGIN_FILE))
            : trailingslashit(plugins_url('yuz-tra/assets/js/'));
        $base_path = defined('YUZ_TRA_PLUGIN_FILE')
            ? trailingslashit(plugin_dir_path(YUZ_TRA_PLUGIN_FILE) . 'assets/js')
            : trailingslashit(dirname(__FILE__, 2) . '/assets/js');

        $modules = [
            'yuz-core-tmp'       => 'yuz-core.tmp.js',
            'yuz-translator-tmp' => 'yuz-translator.tmp.js',
            'yuz-dom-core-tmp'   => 'yuz-dom-core.tmp.js',
        ];

         foreach ($modules as $handle => $file) {
            $absolute = $base_path . $file;
            if (!file_exists($absolute)) {
                yuz_tra_debug_log("[YUZ_TRA] Module manquant : {$file}");
                continue;
            }
            wp_register_script(
                $handle,
                $base_url . $file,
                [],
                (string) @filemtime($absolute),
                true
            );
            // ensure transverse bootstrap is present before enqueue
            $this->prepare_transverse($handle);
            wp_enqueue_script($handle);
        }

        $loaded = true;
    }

    private function enqueue_dom_v9_engine(): void
{
    static $done = false;
    if ($done) return;

    if ($this->is_script_ready('yuz-translation-engine')) {
        // Localisation idempotente des settings
        try { $this->localize('yuz-translation-engine', 'yuzTraSettings', $this->build_frontend_settings()); } catch (\Throwable $e) {}
        // Transverses éventuels (ajax/nonces, etc.)
        try { $this->prepare_transverse('yuz-translation-engine'); } catch (\Throwable $e) {}
        wp_enqueue_script('yuz-translation-engine');
    }

    $done = true;
}

private function enqueue_dom_v8_engine(): void
{
    static $done = false;
    if ($done) return;

    if ($this->is_script_ready('yuz-translation-engine')) {
        try { $this->localize('yuz-translation-engine', 'yuzTraSettings', $this->build_frontend_settings()); } catch (\Throwable $e) {}
        try { $this->prepare_transverse('yuz-translation-engine'); } catch (\Throwable $e) {}
        wp_enqueue_script('yuz-translation-engine');
    }

    $done = true;
}


    public function hook_editor_assets(): void {
        if (!is_user_logged_in()) {
            return;
        }
        if (empty($_GET['yuz-edit-translation'])) {
            return;
        }

        $ver  = defined('YUZ_TRA_VERSION') ? YUZ_TRA_VERSION : time();
        $base = defined('YUZ_TRA_PLUGIN_FILE')
            ? plugins_url('assets/js/', YUZ_TRA_PLUGIN_FILE)
            : plugins_url('yuz-tra/assets/js/');

        $base_js = trailingslashit($base);
        wp_register_script('yuz-editor-shim', $base_js . 'fix/yuz-editor-shim.js', [], $ver, true);
        wp_register_script('yuz-translation-editor', $base_js . 'widgets/yuz-translation-editor.js', ['yuz-editor-shim'], $ver, true);
        wp_script_add_data('yuz-translation-editor', 'type', 'module');
        $integrity = null;
        if (defined('YUZ_TRA_SRI_HASH') && YUZ_TRA_SRI_HASH) {
            $integrity = YUZ_TRA_SRI_HASH;
        } else {
            $integrity = $this->asset_sri('js/widgets/yuz-translation-editor.js');
        }
        if ($integrity) {
            wp_script_add_data('yuz-translation-editor', 'integrity', $integrity);
            wp_script_add_data('yuz-translation-editor', 'crossorigin', 'anonymous');
        }
        wp_register_script('yuz-inspector', $base_js . 'widgets/inspector/yuz-inspector.js', ['yuz-translation-editor'], $ver, true);

        wp_enqueue_script('yuz-editor-shim');
        wp_enqueue_script('yuz-translation-editor');
        wp_enqueue_script('yuz-inspector');

        $can_edit = class_exists('YUZ_Capabilities')
            ? \YUZ_Capabilities::user_is_translator()
            : ( current_user_can('manage_options') || current_user_can('yuz_translate_content') );
        if (class_exists('YUZ_Logger')) {
            (new \YUZ_Logger())->log('info', 'YUZ editor enqueue guard', [
                'param'         => isset($_GET['yuz-edit-translation']) ? $_GET['yuz-edit-translation'] : '(none)', // phpcs:ignore
                'user_id'       => get_current_user_id(),
                'roles'         => function_exists('wp_get_current_user') ? (wp_get_current_user()->roles ?? []) : [],
                'can_edit'      => $can_edit,
                'csp_inline'    => function_exists('clar_csp_enabled') ? clar_csp_enabled() : null,
                'forbid_inline' => function_exists('clar_forbid_yuz_inline') ? clar_forbid_yuz_inline() : null,
            ]);
        }
        $params = wp_json_encode(['canEdit' => (bool) $can_edit]);
        if ($params !== false) {
            wp_add_inline_script(
                'yuz-translation-editor',
                'window.YUZ_PARAMS = window.YUZ_PARAMS || ' . $params . ';',
                'before'
            );
        }

        $settings = $this->build_editor_settings_payload();
        if (!empty($settings['permissions'])) {
            $perms = wp_json_encode($settings['permissions']);
            if ($perms !== false) {
                wp_add_inline_script('yuz-editor-shim', 'window.yuzPermissions = ' . $perms . ';', 'before');
                wp_add_inline_script(
                    'yuz-editor-shim',
                    'if (typeof window !== "undefined" && window.yuzPermissions && !window.__YUZ_PERM_LOGGED__) { try { console.log("YUZ PERM:", window.yuzPermissions); } catch (e) {} window.__YUZ_PERM_LOGGED__ = true; }',
                    'after'
                );
            }
        }
        if (defined('WP_DEBUG') && WP_DEBUG) {
            $langs = isset($settings['translation_langs']) && is_array($settings['translation_langs']) ? $settings['translation_langs'] : [];
            $meta  = isset($settings['language_meta']) && is_array($settings['language_meta']) ? $settings['language_meta'] : [];
            yuz_tra_debug_log(
                sprintf(
                    '[YUZ][front_enqueue_editor] langs_count=%d meta_keys=%s',
                    count($langs),
                    implode(',', array_keys($meta))
                )
            );
        }
        wp_localize_script('yuz-editor-shim', 'yuzTraSettings', $settings);

        $nonce = $this->sec_csp_nonce();
        if (!$nonce && function_exists('clar_get_current_nonce')) {
            $nonce = clar_get_current_nonce();
        }
        if ($nonce && !$this->editor_nonce_filter_added) {
            $nonce_attr = esc_attr($nonce);
            add_filter('script_loader_tag', static function ($tag, $handle) use ($nonce_attr) {
                if (in_array($handle, ['yuz-editor-shim', 'yuz-translation-editor'], true)) {
                    if (strpos($tag, ' nonce=') === false) {
                        $tag = str_replace('<script ', '<script nonce="' . $nonce_attr . '" ', $tag);
                    }
                }
                return $tag;
            }, 10, 2);
            $this->editor_nonce_filter_added = true;
        }
    }

    public function print_editor_settings_fallback(): void {
        if (empty($_GET['yuz-edit-translation']) || !is_user_logged_in()) {
            return;
        }
        if (wp_script_is('yuz-translation-editor', 'enqueued')) {
            return;
        }

        $settings = $this->build_editor_settings_payload();
        $json     = wp_json_encode($settings, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            $json = '{}';
        }
        $json = str_replace('</script', '<\/script', $json);

        $nonce = $this->sec_csp_nonce();
        if (!$nonce && function_exists('clar_get_current_nonce')) {
            $nonce = clar_get_current_nonce();
        }
        if (!$nonce && function_exists('clar_get_nonce')) {
            $nonce = clar_get_nonce();
        }

        $attrs = [];
        if ($nonce) {
            $attrs['nonce'] = $nonce;
        }
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_get_inline_script_tag returns a safely generated script tag.
        echo wp_get_inline_script_tag('window.yuzTraSettings=' . $json . ';', $attrs) . PHP_EOL;
    }

    private function build_editor_settings_payload(): array {
        if (is_array($this->editor_settings_payload)) {
            return $this->editor_settings_payload;
        }

        $opt_general = get_option('yuz_tra_general', []);
        $opt_general = is_array($opt_general) ? $opt_general : [];
        $lang_cfg = $this->language_runtime_config();

        $payload = [];
        if (class_exists('YUZ_Translation_Manager') && method_exists('YUZ_Translation_Manager', 'get_langs_payload')) {
            try {
                $payload = \YUZ_Translation_Manager::get_langs_payload();
            } catch (\Throwable $e) {
                $payload = [];
            }
        }

        $translation_langs = array_values(
            array_filter(
                array_map([$this, 'normalize_locale_code'], (array) $lang_cfg['langs']),
                static fn ($code) => $code !== ''
            )
        );
        if (empty($translation_langs)) {
            $translation_langs = array_values(
                array_filter(
                    array_map([$this, 'normalize_locale_code'], (array) ($payload['translation_langs'] ?? [])),
                    static fn ($code) => $code !== ''
                )
            );
        }

        $language_meta = is_array($payload['language_meta'] ?? []) ? $payload['language_meta'] : [];
        $normalized_meta = [];
        foreach ($language_meta as $code => $meta) {
            $norm = $this->normalize_locale_code((string) $code);
            if ($norm === '') {
                continue;
            }
            $normalized_meta[$norm] = array_merge($normalized_meta[$norm] ?? [], is_array($meta) ? $meta : []);
        }
        $language_meta = $normalized_meta;

        if (empty($translation_langs) && !empty($opt_general['yuz_tra_translatable_languages'])) {
            $translation_langs = array_values(
                array_filter(
                    array_map([$this, 'normalize_locale_code'], (array) $opt_general['yuz_tra_translatable_languages']),
                    static fn ($code) => $code !== ''
                )
            );
        }

        foreach ($translation_langs as $code) {
            if (!isset($language_meta[$code])) {
                $language_meta[$code] = [
                    'is_translatable' => true,
                    'is_source'       => false,
                    'is_default'      => false,
                ];
            }
        }

        $source_language  = (string) $lang_cfg['source'];
        $default_language = (string) $lang_cfg['default'];

        foreach ($language_meta as $code => &$meta) {
            $meta['is_source']       = ($code === $source_language);
            $meta['is_default']      = ($code === $default_language);
            $meta['is_translatable'] = in_array($code, $translation_langs, true);
        }
        unset($meta);

        $rest_root = function_exists('rest_url') ? trailingslashit(rest_url()) : home_url('/wp-json/');

        $nonce_registry = [];
        foreach (self::OFFICIAL_NONCES as $nonce_key) {
            $nonce_registry[$nonce_key] = wp_create_nonce($nonce_key);
        }
        $this->ensure_global_nonces($nonce_registry);

        $settings = [
            'ajax_url' => $this->ajax(),
            'rest_root'  => esc_url_raw($rest_root),
            'rest_nonce' => wp_create_nonce('wp_rest'),
            'translation_langs' => $translation_langs,
            'language_meta'     => $language_meta,
            'source_language'   => $source_language,
            'default_language'  => $default_language,
            'effective_from'    => $source_language,
            'from_options'      => (array) $lang_cfg['from_options'],
            'default_target'    => (string) $lang_cfg['default_target'],
            'general' => [
                'yuz_tra_default_language'       => $default_language,
                'yuz_tra_source_language'        => $source_language,
                'yuz_tra_translatable_languages' => $translation_langs,
            ],
            'settings' => [
                'general' => [
                    'yuz_tra_translatable_languages' => $translation_langs,
                ],
            ],
            'lang_index' => $this->lang_index(),
            'from_choices' => $this->lang_choices((array) $lang_cfg['from_options']),
            'to_choices'   => $this->lang_choices($translation_langs),
            'permissions' => (class_exists('YUZ_Capabilities') && method_exists('YUZ_Capabilities', 'permissions_payload'))
                ? \YUZ_Capabilities::permissions_payload()
                : [],
        ];

        $this->editor_settings_payload = $settings;
        return $settings;
    }

   /** ======================================================================
 * Zone: Enqueuer.Front (enq-front)
 * ====================================================================== */
public function enqueue_front(): void {

    $ver = defined('YUZ_TRANSLATION_VERSION') ? YUZ_TRANSLATION_VERSION : time();
    $base_url = plugin_dir_url(__FILE__) . '../assets/';

    // ------------------------------------------------------------
    // Core handles (Review Panel)
    // ------------------------------------------------------------
    $review_style  = 'yuz-review-dock';
    $review_script = 'yuz-publish-controller';

    if (!wp_script_is($review_script, 'registered')) {
        wp_register_script(
            $review_script,
            $base_url . 'js/yuz-publish-controller.js',
            ['jquery', 'wp-api-request', 'yuz-guard'],
            $ver,
            true
        );
    }

    if (!wp_style_is($review_style, 'registered')) {
        wp_register_style(
            $review_style,
            $base_url . 'css/yuz-publish-controller.css',
            [],
            $ver
        );
    }

    $cap_source = class_exists('YUZ_Capabilities') ? 'cap_class' : 'wp_caps';
    $can_review = class_exists('YUZ_Capabilities')
        ? \YUZ_Capabilities::user_can_publish()
        : ( current_user_can('manage_options') || current_user_can('yuz_translate_content') );

    if (defined('WP_DEBUG') && WP_DEBUG) {
        try {
            yuz_tra_debug_log('[YUZ][enqueue_front] reviewer capability check: ' . wp_json_encode([
                'can_review' => $can_review,
                'source'     => $cap_source,
                'is_admin'   => is_admin(),
                'user_id'    => get_current_user_id(),
                'caps'       => wp_get_current_user() ? array_keys((array) wp_get_current_user()->allcaps) : []
            ]));
        } catch (\Throwable $ignored) {}
    }

    if ($can_review) {
        $this->ensure_review_dock_style_filter();
        wp_enqueue_style($review_style);
        if (wp_script_is($review_script, 'registered')) {
            if (defined('WP_DEBUG') && WP_DEBUG && class_exists('YUZ_Capabilities') && method_exists('YUZ_Capabilities', 'permissions_payload')) {
                try {
                    yuz_tra_debug_log(wp_json_encode(\YUZ_Capabilities::permissions_payload()));
                } catch (\Throwable $ignored) {}
            }
            $settings = $this->build_editor_settings_payload();
            wp_localize_script($review_script, 'yuzTraSettings', $settings);
            if (!empty($settings['permissions'])) {
                $perms = wp_json_encode($settings['permissions']);
                if ($perms !== false) {
                    wp_add_inline_script($review_script, 'window.yuzPermissions = ' . $perms . ';', 'before');
                    wp_add_inline_script(
                        $review_script,
                        'if (typeof window !== "undefined" && window.yuzPermissions && !window.__YUZ_PERM_LOGGED__) { try { console.log("YUZ PERM:", window.yuzPermissions); } catch (e) {} window.__YUZ_PERM_LOGGED__ = true; }',
                        'after'
                    );
                }
            }
            wp_enqueue_script($review_script);
        }
    } elseif (defined('WP_DEBUG') && WP_DEBUG) {
        try {
            yuz_tra_debug_log('[YUZ][enqueue_front] reviewer assets not enqueued (missing capability).');
            if (class_exists('YUZ_Capabilities') && method_exists('YUZ_Capabilities', 'permissions_payload')) {
                $perms_payload = (array) \YUZ_Capabilities::permissions_payload();
                do_action('yuz/front_permissions_payload', $perms_payload, [
                    'can_review' => false,
                    'source'     => $cap_source,
                    'user_id'    => function_exists('get_current_user_id') ? get_current_user_id() : 0,
                ]);
                $encoded = wp_json_encode($perms_payload);
                if ($encoded !== false) {
                    yuz_tra_debug_log('[YUZ][enqueue_front] front permissions payload ' . $encoded);
                }
            }
        } catch (\Throwable $ignored) {}
    }

    // ------------------------------------------------------------
    // Language context
    // ------------------------------------------------------------
    $ws = (array) get_option('yuz_tra_ws_settings', []);
    $ls = (array) get_option('yuz_tra_ls_settings', []);
    $sw = (array) get_option('yuz_tra_sw_settings', []);

    $default_lang = (string) ($ws['yuz_tra_default_language'] ?? '');
    $source_lang  = (string) ($ws['yuz_tra_source_language'] ?? '');
    $codes        = (array) ($ws['yuz_tra_code'] ?? $ws['yuz_tra_translatable_languages'] ?? []);

    $site_locale  = function_exists('get_locale') ? (string) get_locale() : 'en_US';
    if ($default_lang === '') { $default_lang = $site_locale; }
    if ($source_lang  === '') { $source_lang  = $default_lang; }

    $current_lang = apply_filters('yuz_current_language', $site_locale);

    $language_meta = (class_exists('YUZ_Language') && method_exists('YUZ_Language', 'get_all_meta'))
        ? (array) YUZ_Language::get_all_meta()
        : [];

    if (class_exists('YUZ_Translation_Manager') && method_exists('YUZ_Translation_Manager', 'init')) {
        YUZ_Translation_Manager::init();
    }

    $translations = [];
    if (class_exists('YUZ_Translation_Manager') &&
        method_exists('YUZ_Translation_Manager', 'get_frontend_translations')) {
        $translations = (array) YUZ_Translation_Manager::get_frontend_translations();
    }

    foreach ($translations as $code => &$entry) {
        if (!is_array($entry)) {
            $entry = ['translationsArray' => []];
            continue;
        }
        if (!isset($entry['translationsArray'])) {
            if (isset($entry['translations']) && is_array($entry['translations'])) {
                $entry['translationsArray'] = $entry['translations'];
                unset($entry['translations']);
            } else {
                $entry = ['translationsArray' => (array) $entry];
            }
        }
    }
    unset($entry);

    // ------------------------------------------------------------
    // Localize + Enqueue
    // ------------------------------------------------------------
    $engine_handle = '';
    foreach (['yuz-translation-engine', 'yuz-translate-dom-changes', 'yuz-dom-watch'] as $candidate) {
        if ($this->is_script_ready($candidate)) {
            $engine_handle = $candidate;
            break;
        }
    }

    if ($engine_handle !== '') {
        // Ensure ajax_url + nonces are injected even if someone enqueues the handle directly.
        $this->prepare_transverse($engine_handle);

        if (wp_script_is($engine_handle, 'registered')) {
            $settings = $this->pay_transverse_min();
            // Respect la détection faite par pay_transverse_min(); ne remplace qu'en absence de valeur.
            if (empty($settings['current_language'])) {
                $settings['current_language'] = $current_lang ?: ($settings['current_language'] ?? '');
            }
            if (empty($settings['default_language'])) {
                $settings['default_language'] = $default_lang ?: ($settings['default_language'] ?? '');
            }
            if (empty($settings['source_language'])) {
                $settings['source_language']  = $source_lang  ?: ($settings['source_language'] ?? '');
            }
            $settings['languages']        = array_values(array_filter($codes, 'is_string'));
            $settings['language_meta']    = $language_meta;
            $settings['translations']     = !empty($translations) ? $translations : ($settings['translations'] ?? []);
            $settings['link_settings']    = [
                'native_language_name' => !empty($ls['native_language_name']),
                'use_subdirectory'     => !empty($ls['use_subdirectory']),
                'force_lang_in_links'  => !empty($ls['force_lang_in_links']),
            ];
            $settings['switcher_settings'] = [
                'shortcode_enabled' => !empty($sw['shortcode_enabled']),
                'menu_enabled'      => !empty($sw['menu_enabled']),
                'floating_enabled'  => !empty($sw['floating_enabled']),
                'format'            => (string) ($sw['shortcode_format'] ?? $sw['floating_format'] ?? 'flags-full-names'),
                'theme'             => (string) ($sw['floating_theme'] ?? 'dark'),
                'position'          => (string) ($sw['floating_position'] ?? 'bottom-right'),
                'show_poweredby'    => !empty($sw['show_poweredby']),
            ];

            if (defined('YUZ_TRA_DEBUG_FRONT') && YUZ_TRA_DEBUG_FRONT) {
                yuz_tra_debug_log('[YUZ_TRA][LOCALIZE] ' . wp_json_encode([
                    'handle'            => $engine_handle,
                    'request_uri'       => sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'] ?? '')),
                    'has_settings'      => !empty($settings),
                    'keys'              => array_keys($settings ?? []),
                    'translations_keys' => array_keys($settings['translations'] ?? []),
                ]));
            }

            wp_localize_script($engine_handle, 'yuzTraSettings', $settings);
            wp_enqueue_script($engine_handle);
        }
    }

    // ------------------------------------------------------------
    // Remaining logic
    // ------------------------------------------------------------
    $cfg = $this->settings->get_js_config();
    $cfg = is_array($cfg) ? $cfg : [];
    $sw  = is_array($cfg['switcher'] ?? []) ? $cfg['switcher'] : [];

    $has_shortcode = function_exists('shortcode_exists') && shortcode_exists('yuz_language_switcher');
    $has_block     = function_exists('has_block') && has_block('yuz/language-switcher');

    $need = !empty($sw['yuz_shortcode_enabled'])
         || !empty($sw['yuz_floating_enabled'])
         || !empty($sw['yuz_menu_enabled'])
         || $has_shortcode || $has_block
         || isset($_GET['yuz-edit-translation']);

    if (!$need) {
        return;
    }

        if ($this->is_script_ready('yuz-assets')) {
            $this->prepare_transverse('yuz-assets');
            $this->localize('yuz-assets', 'yuzAS', $this->pay_js_settings());
            $this->enqueue_script('yuz-assets');
        }

    if ($this->is_script_ready('yuz-language-switcher')) {
        $this->localize('yuz-language-switcher', 'yuzSW', $this->pay_switcher());
        $this->enqueue_script('yuz-language-switcher');
        $this->enqueue_style('yuz-language-switcher');
    }

    $can_edit = class_exists('YUZ_Capabilities')
        ? \YUZ_Capabilities::user_is_translator()
        : ( current_user_can('manage_options') || current_user_can('yuz_translate_content') );
    $can_edit = is_user_logged_in() && $can_edit;

    if (isset($_GET['yuz-edit-translation']) && $can_edit) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            try {
                yuz_tra_debug_log('[YUZ][front_edit] enqueue editor stack (can_edit=' . ($can_edit ? '1' : '0') . ', user=' . get_current_user_id() . ')');
            } catch (\Throwable $ignored) {}
        }
        $this->enqueue_script('yuz-vue');
        $this->enqueue_script('yuz-axios');
        $this->enqueue_script('yuz-select2');
        $this->enqueue_style('yuz-select2');

            if ($this->is_script_ready('yuz-translation-service')) {
                $this->prepare_transverse('yuz-translation-service');
                $this->enqueue_script('yuz-translation-service');
            }

        if ($this->is_script_ready('yuz-translation-site')) {
            $this->localize('yuz-translation-site', 'yuzTS', $this->pay_ts());
            $this->enqueue_script('yuz-translation-site');
        }

        $force_mini = isset($_GET['yuz-mini']) && current_user_can('edit_posts');

        if (!$force_mini) {
            if ($this->is_script_ready('yuz-strings-dock')) {
                $this->localize('yuz-strings-dock', 'yuzStrings', $this->pay_strings());
                $this->enqueue_script('yuz-strings-dock');
            }
            $this->enqueue_style('yuz-strings-dock');

            if ($this->is_script_ready('yuz-editor-shim')) {
                $this->enqueue_script('yuz-editor-shim');
            }

            // Force localization/enqueue of the translation editor overlay in front-edit mode.
            if ($this->is_script_ready('yuz-translation-editor')) {
                // Inject minimal yzTraSettings for editor in case upstream bundles skipped it.
                $this->prepare_transverse('yuz-translation-editor');
                wp_localize_script('yuz-translation-editor', 'yuzTraSettings', $this->pay_transverse_min());
                $this->localize('yuz-translation-editor', 'yuzTE', $this->pay_te());
                $this->enqueue_style('yuz-translation-editor');
                $this->enqueue_script('yuz-translation-editor');
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    try {
                        yuz_tra_debug_log('[YUZ][front_edit] enqueued yuz-translation-editor + style');
                    } catch (\Throwable $ignored) {}
                }
            }

            wp_dequeue_script('yuz-string-editor');
            wp_deregister_script('yuz-string-editor');
        } else {
            if ($this->is_script_ready('yuz-string-editor')) {
                $this->prepare_transverse('yuz-string-editor');
                $this->localize('yuz-string-editor', 'yuzSE', $this->pay_js_settings());
                $this->localize('yuz-string-editor', 'yuzTE', $this->pay_te());
                $this->enqueue_script('yuz-string-editor');
            }
        }

        if ($this->is_script_ready('yuz-admin-bar')) {
            $this->localize('yuz-admin-bar', 'yuzAB', $this->pay_admin_bar());
            $this->enqueue_script('yuz-admin-bar');
        }
    } else {
        $this->enqueue_script('yuz-admin-bar');
    }

    try {
        $has_modal_shortcode = false;
        if (function_exists('has_shortcode')) {
            $pid = function_exists('get_queried_object_id') ? (int) get_queried_object_id() : 0;
            $content = $pid ? (string) get_post_field('post_content', $pid) : '';
            $has_modal_shortcode = $content && has_shortcode($content, 'yuz_modal');
        }
        $has_modal_block = function_exists('has_block') && has_block('yuz/mod-modal');
        $modal_registry  = class_exists('\\YUZ\\Modals\\Plugin')
            ? (array) \YUZ\Modals\Plugin::instance()->get_modals()
            : [];
        $has_runtime_modals = !empty($modal_registry);

        // Always load the DOM engine when available (was previously gated to /business).
        $this->enqueue_dom_refactor_modules();
        $this->enqueue_dom_v9_engine();
        $engine_handle = $this->is_script_ready('yuz-translation-engine') ? 'yuz-translation-engine' : 'yuz-dom-watch';
        if ($engine_handle && $this->is_script_ready($engine_handle)) {
            $this->enqueue_script($engine_handle);
        }
    } catch (\Throwable $e) {}

    self::inject_flags_css_root_var_static();

    if (wp_script_is('yuz-translate', 'registered')) {
        wp_localize_script('yuz-translate', 'yuzTraSettings', YUZ_Translation_Manager::build_frontend_settings());
        wp_enqueue_script('yuz-translate');
    } elseif (wp_script_is('yuz-publish-controller', 'registered')) {
        wp_localize_script('yuz-publish-controller', 'yuzTraSettings', YUZ_Translation_Manager::build_frontend_settings());
        wp_enqueue_script('yuz-publish-controller');
    }
}


    /** ======================================================================
     * Zone: BlockEditorSupport (blk)
     * ====================================================================== */
    public function blk_enqueue_block_editor(): void {
        if (!$this->is_script_ready('yuz-gutenberg-editor')) {
            return;
        }
        global $post;
        $url = $post ? get_permalink($post) : home_url('/');
        if ($this->inline_allowed()) {
            wp_localize_script('yuz-gutenberg-editor','yuzGB',['url_to_load'=>esc_url($url)]);
        }
        $this->enqueue_script('yuz-gutenberg-editor');
        if (function_exists('wp_set_script_translations')) {
            wp_set_script_translations('yuz-gutenberg-editor', 'yuz-tra', plugin_dir_path(__FILE__) . '../languages');
        }
    }

    /**
     * String Translation Editor — **UMD prioritaire**, ESM si (et seulement si) browser-ESM valide.
     */
    private function blk_enqueue_string_editor(): void {
        // Vendors (classic)
        $this->enqueue_script('yuz-vue');
        $this->enqueue_script('yuz-vue-router');
        $this->enqueue_script('yuz-select2');
        $this->enqueue_style('yuz-select2');
        $this->enqueue_script('yuz-he');

        $esm_abs = $this->cfg_asset_path('js/yuz-string-translation-editor.esm.js');
        $umd_abs = $this->cfg_asset_path('js/yuz-string-translation-editor.umd.js');

        $payload = $this->pay_string_editor();

        // 1) UMD FIRST (robuste face aux optimizers)
        if (file_exists($umd_abs)) {
            if ($this->is_script_ready('yuz-string-translation-editor-umd')) {
                $this->prepare_transverse('yuz-string-translation-editor-umd');
                $this->localize('yuz-string-translation-editor-umd', 'yuzSE', $payload);
                $this->enqueue_script('yuz-string-translation-editor-umd');
            }
            return;
        }

        // 2) Fallback ESM si on n’a pas d’UMD et que le fichier est **vraiment** browser-ESM
        if (file_exists($esm_abs) && $this->looks_browser_esm($esm_abs)) {
            self::mark_module_handles('yuz-string-translation-editor');
            if ($this->is_script_ready('yuz-string-translation-editor')) {
                $this->prepare_transverse('yuz-string-translation-editor');
                $this->localize('yuz-string-translation-editor', 'yuzSE', $payload);
                $this->enqueue_script('yuz-string-translation-editor');
            }
            return;
        }

        // 3) Sinon: erreur explicite
        $this->blk_schedule_missing_bundle_notice();
    }

    private function blk_schedule_missing_bundle_notice(): void {
        $this->string_editor_bundle_missing = true;
    }

    public function blk_print_missing_bundle_notice(): void {
        if (!$this->string_editor_bundle_missing || !$this->inline_allowed()) {
            return;
        }
        $this->string_editor_bundle_missing = false;
        wp_register_script('yuz-tra-missing-bundle', false, [], YUZ_TRA_VERSION, true);
        wp_enqueue_script('yuz-tra-missing-bundle');
        wp_add_inline_script('yuz-tra-missing-bundle', "console.error('[YUZ] String Editor bundle missing.');");
    }

    /** Module policy */
    public function blk_filter_script_tag(string $tag, string $handle, string $src): string {
        if (!in_array($handle, self::PLUGIN_HANDLES, true)) {
            return $tag;
        }

        if ($handle === 'yuz-translation-editor') {
            return $this->blk_render_te_bootstrap($handle, $src);
        }

        $is_vendor = in_array($handle, self::VENDOR_HANDLES, true);

        if ($is_vendor) {
            $tag = preg_replace('/\stype=("|\')module\1/i', '', $tag);
            if (strpos($tag, 'data-noptimize') === false) {
                $tag = str_replace('<script ', '<script data-cfasync="false" data-noptimize="1" data-no-optimize="1" data-rocketlazyload="ignore" ', $tag);
            }
        } else {
            $is_esm = in_array($handle, self::ESM_WHITELIST, true) || isset(self::$module_dynamic[$handle]);
            if ($is_esm && self::USE_MODULE_TAG && stripos($tag, ' type=') === false) {
                $tag = str_replace(
                    '<script ',
                    '<script type="module" data-cfasync="false" data-noptimize="1" data-no-optimize="1" data-rocketlazyload="ignore" ',
                    $tag
                );
            } elseif (!$is_esm) {
                $tag = preg_replace('/\stype=("|\')module\1/i', '', $tag);
            }
        }

        return $this->blk_inject_plugin_attributes($tag, $handle, $is_vendor);
    }

    private function blk_force_te_module_attrs(string $tag): string {
        if (strpos($tag, ' type=') !== false) {
            $tag = preg_replace('/\stype=(["\']).*?\1/i', ' type="module"', $tag);
        } else {
            $tag = str_replace('<script ', '<script type="module" ', $tag);
        }

        if (stripos($tag, 'id="yuz-te-module"') === false) {
            $tag = str_replace('<script ', '<script id="yuz-te-module" ', $tag);
        }

        if (stripos($tag, 'data-yuz-module="true"') === false) {
            $tag = str_replace('<script ', '<script data-yuz-module="true" ', $tag);
        }

        foreach ([
            'data-cfasync="false"',
            'data-noptimize="1"',
            'data-no-optimize="1"',
            'data-rocketlazyload="ignore"',
            'data-rocket-ignore="true"',
            'data-litespeed="ignore"',
        ] as $attr) {
            if (stripos($tag, $attr) === false) {
                $tag = str_replace('<script ', '<script ' . $attr . ' ', $tag);
            }
        }

        return $tag;
    }

    private function blk_render_te_bootstrap(string $handle, string $src): string {
        $nonce = $this->sec_csp_nonce();
        if (!$nonce && function_exists('clar_get_current_nonce')) {
            $nonce = clar_get_current_nonce();
        }

        $wp_scripts = function_exists('wp_scripts') ? wp_scripts() : null;
        $integrity = '';
        $crossorigin = '';

        if ($wp_scripts && method_exists($wp_scripts, 'get_data')) {
            $integrity = (string) $wp_scripts->get_data($handle, 'integrity');
            $crossorigin = (string) $wp_scripts->get_data($handle, 'crossorigin');
        }

        if (defined('YUZ_TRA_SRI_HASH') && YUZ_TRA_SRI_HASH) {
            $integrity = YUZ_TRA_SRI_HASH;
            if ($crossorigin === '') {
                $crossorigin = 'anonymous';
            }
        }

        if ($integrity === '') {
            $dynamic = $this->asset_sri('js/widgets/yuz-translation-editor.js');
            if ($dynamic) {
                $integrity = $dynamic;
                if ($crossorigin === '') {
                    $crossorigin = 'anonymous';
                }
                if ($wp_scripts && method_exists($wp_scripts, 'add_data')) {
                    $wp_scripts->add_data($handle, 'integrity', $integrity);
                    $wp_scripts->add_data($handle, 'crossorigin', $crossorigin);
                } else {
                    wp_script_add_data($handle, 'integrity', $integrity);
                    wp_script_add_data($handle, 'crossorigin', $crossorigin);
                }
            }
        }

        $attrs = [
            'id="yuz-te-module"',
            'type="module"',
            'data-yuz="1"',
            'data-handle="' . esc_attr($handle) . '"',
            'data-yuz-module="true"',
            'data-cfasync="false"',
            'data-noptimize="1"',
            'data-no-optimize="1"',
            'data-rocketlazyload="ignore"',
            'data-rocket-ignore="true"',
            'data-litespeed="ignore"',
        ];

        if ($nonce) {
            $attrs[] = 'nonce="' . esc_attr($nonce) . '"';
        }

        if ($integrity !== '') {
            $attrs[] = 'integrity="' . esc_attr($integrity) . '"';
            $attrs[] = 'crossorigin="' . esc_attr($crossorigin ?: 'anonymous') . '"';
        }

        $attrs_str = implode(' ', $attrs);
        // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- This is a script_loader_tag filter returning the registered tag.
        return sprintf('<script %s src="%s"></script>', $attrs_str, esc_url($src));
    }

    private function blk_inject_plugin_attributes(string $tag, string $handle, bool $is_vendor = false): string
    {
        $attrs = [];
        $should_module = self::USE_MODULE_TAG
            && !$is_vendor
            && stripos($tag, ' type=') === false
            && (
                $handle === 'yuz-translation-editor'
                || in_array($handle, self::ESM_WHITELIST, true)
                || isset(self::$module_dynamic[$handle])
            );

        if ($should_module) {
            $attrs[] = 'type="module"';
        }

        $nonce = $this->sec_csp_nonce();
        if ($nonce && stripos($tag, ' nonce=') === false) {
            $attrs[] = 'nonce="' . esc_attr($nonce) . '"';
        }

        if (function_exists('wp_scripts')) {
            $wp_scripts = wp_scripts();
            if ($wp_scripts && method_exists($wp_scripts, 'get_data')) {
                $integrity = $wp_scripts->get_data($handle, 'integrity');
                if (is_string($integrity) && $integrity !== '' && stripos($tag, ' integrity=') === false) {
                    $attrs[] = 'integrity="' . esc_attr($integrity) . '"';
                    if (stripos($tag, ' crossorigin=') === false) {
                        $attrs[] = 'crossorigin="anonymous"';
                    }
                }
            }
        }

        if (stripos($tag, ' data-yuz=') === false) {
            $attrs[] = 'data-yuz="1"';
            $attrs[] = 'data-handle="' . esc_attr($handle) . '"';
        }

        if (!$attrs) {
            $result = $tag;
        } else {
            $result = preg_replace('/<script\b/i', '<script ' . implode(' ', $attrs), $tag, 1, $count);
            if ($count === 0) {
                $pos = strpos($tag, '>');
                if ($pos !== false) {
                    $result = substr($tag, 0, $pos) . ' ' . implode(' ', $attrs) . substr($tag, $pos);
                } else {
                    $result = $tag;
                }
            }
        }

        if (defined('CLAR_CSP_TRACE') && CLAR_CSP_TRACE && $handle === 'yuz-translation-editor') {
            $has = (bool) preg_match('/\snonce=(["\'])(.*?)\1/i', $result, $m);
            $attr = $has ? $m[2] : null;
            $this->log_debug('csp_inject_tag', ['has_nonce' => (bool) $has, 'nonce' => $attr]);
        }

        return $result;
    }

    public static function enforce_transverse_on_print(): void {
        global $wp_scripts;
        if (!is_object($wp_scripts)) return;
        $q = is_array($wp_scripts->queue) ? $wp_scripts->queue : [];
        $targets = array_values(array_intersect($q, self::T_HANDLES));
        if (!$targets) return;
        $inst = $GLOBALS['yuz_assets_singleton'] ?? self::$inst;
        if (!($inst instanceof self)) return;
        foreach ($targets as $h) {
            if (defined('YUZ_TRA_DEBUG_FRONT') && YUZ_TRA_DEBUG_FRONT) {
                yuz_tra_debug_log('[YUZ_TRA][ENFORCE_T_MIN] ' . wp_json_encode([
                    'request_uri' => sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'] ?? '')),
                    'handle'      => $h,
                ]));
            }
            // Force the minimal localized settings (with nonces) before the script tag prints.
            $inst->pay_localize_transverse_min($h);
            $inst->ensure_t_min($h);
        }
    }

    /** Minimal yuzTraSettings merger (no overwrite) */
    private function ensure_t_min(string $handle): void {
        if (!wp_script_is($handle,'registered') && !wp_script_is($handle,'enqueued')) return;
        if (!$this->inline_allowed()) {
            return;
        }
        $m = ['ajax_url'=>$this->ajax()];
        $settings = $this->pay_transverse_min();
        if (defined('YUZ_TRA_DEBUG_FRONT') && YUZ_TRA_DEBUG_FRONT) {
            yuz_tra_debug_log('[YUZ_TRA][ENSURE_T_MIN] ' . wp_json_encode([
                'request_uri'  => sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'] ?? '')),
                'handle'       => $handle,
                'has_ajax_url' => !empty($settings['ajax_url']),
                'has_nonces'   => !empty($settings['nonces']),
            ]));
        }
        wp_add_inline_script(
            $handle,
            'window.yuzTraSettings=(function(c){try{return Object.assign({},c||{},'.wp_json_encode($m).');}catch(e){return c||'.wp_json_encode($m).';}})(window.yuzTraSettings);',
            'before'
        );
    }
    /** ======================================================================
     * Zone: Payloads (pay)
     * ====================================================================== */
    /** @zone pay */
    private function language_runtime_config(): array
    {
        $general = get_option('yuz_tra_general', []);
        $general = is_array($general) ? $general : [];
        $ws = get_option('yuz_tra_ws_settings', []);
        $ws = is_array($ws) ? $ws : [];

        $normalize = function ($value): string {
            return $this->normalize_locale_code(is_scalar($value) ? (string) $value : '');
        };

        $default = $normalize($general['yuz_tra_default_language'] ?? $ws['yuz_tra_default_language'] ?? '');
        if ($default === '') {
            $default = $normalize($this->languages->get_default_language() ?? get_locale()) ?: 'en_US';
        }

        $source = $normalize($general['yuz_tra_source_language'] ?? $ws['yuz_tra_source_language'] ?? '');
        if ($source === '') {
            $source = $normalize($this->languages->get_source_language() ?? $default) ?: $default;
        }

        $codes = [];
        $append_codes = function ($values) use (&$codes, $normalize): void {
            foreach ((array) $values as $key => $value) {
                foreach ([$value, is_string($key) ? $key : ''] as $candidate) {
                    $code = $normalize($candidate);
                    if ($code !== '' && !in_array($code, $codes, true)) {
                        $codes[] = $code;
                    }
                }
            }
        };

        $append_codes($general['yuz_tra_translatable_languages'] ?? []);
        $append_codes($ws['yuz_tra_translatable_languages'] ?? []);
        $append_codes($general['yuz_tra_code'] ?? []);
        $append_codes($ws['yuz_tra_code'] ?? []);

        foreach ((array) $this->languages->get_translatable_languages() as $entry) {
            $code = is_object($entry) && isset($entry->language_code)
                ? $normalize((string) $entry->language_code)
                : $normalize((string) $entry);
            if ($code !== '' && !in_array($code, $codes, true)) {
                $codes[] = $code;
            }
        }

        foreach ([$source, $default] as $code) {
            if ($code !== '' && !in_array($code, $codes, true)) {
                $codes[] = $code;
            }
        }

        $from_options = array_values(array_unique(array_filter([$source, $default], 'strlen')));

        return [
            'default'        => $default,
            'source'         => $source,
            'langs'          => $codes,
            'from_options'   => $from_options,
            'default_target' => $this->pick_default_target($codes, $source),
            'general'        => $general,
            'ws'             => $ws,
        ];
    }

    private function pick_default_target(array $codes, string $source): string
    {
        $source = $this->normalize_locale_code($source);
        foreach ($codes as $candidate) {
            $code = $this->normalize_locale_code((string) $candidate);
            if ($code !== '' && $code !== $source) {
                return $code;
            }
        }
        return $codes[0] ?? '';
    }

    /** @zone pay */
    public function pay_localize_transverse_min(string $bootstrap_handle): void
    {
        static $done = false;
        if ($done) { return; }
        if (!$this->inline_allowed()) {
            $done = true;
            return;
        }
        if (!wp_script_is($bootstrap_handle, 'registered') && !wp_script_is($bootstrap_handle, 'enqueued')) {
            return;
        }
        $done = true;
        wp_localize_script($bootstrap_handle, 'yuzTraSettings', $this->pay_transverse_min());
    }

    /** @zone pay */
    public function pay_transverse_min(): array
    {
        $lang_cfg = $this->language_runtime_config();
        $default = $lang_cfg['default'];
        $source  = $lang_cfg['source'];
        $current = '';
        if (class_exists('YUZ_Front_Renderer') && method_exists('YUZ_Front_Renderer', 'get_active_language')) {
            $current = \YUZ_Front_Renderer::get_active_language();
        }
        $current = $this->normalize_locale_code($current) ?: $default;

        $codes = (array) $lang_cfg['langs'];
        if (!in_array($source, $codes, true)) {
            $codes[] = $source;
        }
        if (!in_array($default, $codes, true)) {
            $codes[] = $default;
        }
        if (!in_array($current, $codes, true)) {
            $codes[] = $current;
        }

        $detected = $this->detect_request_locale($codes);
        if ($detected !== '') {
            $current = $detected;
        }

        $index = $this->lang_index();
        $language_meta = [];
        foreach ($codes as $code) {
            $meta = $index[$code] ?? null;
            $language_meta[] = [
                'code'       => $code,
                'name'       => $meta['name'] ?? strtoupper(str_replace('_', '-', $code)),
                'native'     => $meta['native'] ?? ($meta['name'] ?? strtoupper(str_replace('_', '-', $code))),
                'flag'       => $meta['flag'] ?? $this->flag_url($code),
                'is_default' => strcasecmp($code, $default) === 0,
                'is_source'  => strcasecmp($code, $source) === 0,
            ];
        }

        $available_langs = $language_meta;
        $available_lang_codes = array_values(array_filter(array_map(static function ($entry) {
            return isset($entry['code']) ? (string) $entry['code'] : '';
        }, $available_langs), 'strlen'));

        $translations = [];
        if (class_exists('YUZ_Translation_Manager') && method_exists('YUZ_Translation_Manager', 'get_frontend_translations')) {
            $translations = YUZ_Translation_Manager::get_frontend_translations();
        }
        if (empty($translations)) {
            $dictionary_codes = $available_lang_codes;
            foreach ($available_lang_codes as $code) {
                $short = substr($code, 0, 2);
                if ($short && !in_array($short, $dictionary_codes, true)) {
                    $dictionary_codes[] = $short;
                }
            }
            $translations = $this->restrict_translations_dictionary(
                $this->load_front_dictionary(),
                $dictionary_codes
            );
        }

        $current_short = substr($current, 0, 2);
        $is_source_flag = strcasecmp($current, $source) === 0;
        $is_default_flag = strcasecmp($current, $default) === 0;
        $has_dictionary = isset($translations[$current]) || ($current_short !== '' && isset($translations[$current_short]));
        $is_translatable = (!$is_source_flag) && in_array($current, $available_lang_codes, true);

        $trace_upload_url = $this->resolve_trace_upload_url();
        $page_url = $this->resolve_request_page_url();
        $post_id = $this->resolve_request_post_id($page_url, $available_lang_codes);

        $this->ensure_global_nonces($this->sec_build_nonces());

        $settings = [
            'ajax_url'          => $this->ajax(),
            'current_language'  => $current,
            'default_language'  => $default,
            'source_language'   => $source,
            'page_url'          => $page_url,
            'post_id'           => $post_id,
            'lang_from'         => $source,
            'is_source'         => $is_source_flag,
            'is_default'        => $is_default_flag,
            'is_translatable'   => $is_translatable,
            'languages'         => $available_langs,
            'languages_list'    => $available_langs,
            'language_meta'     => $available_langs,
            'translations'      => $translations,
            'trace_upload_url'  => $trace_upload_url,
            'nonces'            => array_merge(
                [
                    'ajax'        => wp_create_nonce('yuz_ajax_nonce'),
                    'trace'       => wp_create_nonce('yuz_trace_nonce'),
                    'yuz_tra_ajax'=> wp_create_nonce('yuz_tra_ajax'),
                ],
                (array) $this->sec_pick_nonces('min')
            ),
        ];

        if (isset($_GET['yuzdebug'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            try {
                yuz_tra_debug_log('[YUZ_DBG][pay_transverse_min] ' . wp_json_encode([
                    'ajax_url' => $settings['ajax_url'],
                    'nonces'   => array_keys($settings['nonces'] ?? []),
                    'user_id'  => get_current_user_id(),
                    'logged'   => is_user_logged_in(),
                    'req'      => sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'] ?? '')),
                ]));
            } catch (\Throwable $ignored) {}
        }

        if (defined('YUZ_TRA_DEBUG_FRONT') && YUZ_TRA_DEBUG_FRONT) {
            $log_languages = array_values(array_filter(array_map(static function ($entry) {
                if (is_array($entry) && isset($entry['code'])) {
                    return (string) $entry['code'];
                }
                return is_string($entry) ? $entry : '';
            }, $settings['languages'] ?? []), 'strlen'));
            yuz_tra_debug_log('[YUZ_TRA][PAY_TRANSVERSE_MIN] ' . wp_json_encode([
                'url'              => sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'] ?? '')),
                'lang_current'     => $settings['current_language'] ?? null,
                'lang_default'     => $settings['default_language'] ?? null,
                'lang_source'      => $settings['source_language'] ?? null,
                'languages'        => $log_languages,
                'has_translations' => !empty($settings['translations']),
                'translation_keys' => array_keys($settings['translations'] ?? []),
            ]));
        }

        return $settings;
    }

    /** @zone pay */
    public function pay_js_settings(): array
    {
        $cfg = $this->settings->get_js_config();
        $cfg = is_array($cfg) ? $cfg : [];
        $cfg = array_replace(['settings' => [], 'switcher' => []], $cfg);

        $settings = is_array($cfg['settings']) ? $cfg['settings'] : [];
        $switcher = is_array($cfg['switcher']) ? $cfg['switcher'] : [];

        $lang_cfg = $this->language_runtime_config();
        $default  = $lang_cfg['default'];
        $source   = $lang_cfg['source'];
        $langs    = (array) $lang_cfg['langs'];

        $index = $this->lang_index();
        $names = [];
        foreach ($langs as $code) {
            $row = $index[$code] ?? null;
            if (is_array($row) && (!empty($row['native']) || !empty($row['name']))) {
                $names[$code] = $row['native'] ?: $row['name'];
                continue;
            }
            $language = $this->languages->get_by_code($code);
            $names[$code] = ($language && !empty($language->language_name))
                ? $language->language_name
                : strtoupper($code);
        }

        $language_meta = [];
        foreach ($langs as $code) {
            $language_meta[] = [
                'code'       => $code,
                'name'       => $names[$code] ?? strtoupper($code),
                'is_default' => ($code === $default),
                'is_source'  => ($code === $source),
                'active'     => true,
            ];
        }

        $current = get_query_var('lang');
        if (!$current && isset($_GET['lang'])) {
            $current = sanitize_text_field(wp_unslash((string) $_GET['lang']));
        }
        if (!$current || !in_array($current, $langs, true)) {
            $first_target = '';
            foreach ($langs as $candidate) {
                if ($candidate !== $source) {
                    $first_target = $candidate;
                    break;
                }
            }
            $current = $first_target ?: ($langs[0] ?? $source);
        }

        $effective_from = $source ?: $default;
        $from_options   = (array) $lang_cfg['from_options'];
        $default_target = (string) $lang_cfg['default_target'];

        try {
            $trace = bin2hex(random_bytes(4));
        } catch (\Throwable $e) {
            $trace = substr(md5(uniqid('', true)), 0, 8);
        }

        if (!isset($settings['general']) || !is_array($settings['general'])) {
            $settings['general'] = [];
        }
        $settings['general']['yuz_tra_default_language']       = $default;
        $settings['general']['yuz_tra_source_language']        = $source;
        $settings['general']['yuz_tra_translatable_languages'] = $langs;

        $payload = [
            'ajax_url'           => $this->ajax(),
            'settings'           => $settings,
            'switcher'           => $switcher,
            'default_language'   => $default,
            'source_language'    => $source,
            'translation_langs'  => $langs,
            'language_names'     => $names,
            'language_meta'      => $language_meta,
            'current_page_lang'  => $current,
            'effective_from'     => $effective_from,
            'from_options'       => $from_options,
            'default_target'     => $default_target,
            'trace'              => $trace,
            'nonces'             => $this->build_nonces(),
        ];

        $payload['lang_index']   = $index;
        $payload['from_choices'] = $this->lang_choices($from_options);
        $payload['to_choices']   = $this->lang_choices($langs);

        if (function_exists('yuz_diag_log')) {
            yuz_diag_log('js_settings', [
                'trace'          => $trace,
                'def'            => $default,
                'src'            => $source,
                'langs'          => $langs,
                'effective_from' => $effective_from,
                'from_options'   => $from_options,
            ]);
        }

        return $payload;
    }

    /** @zone pay */
    public function pay_general(): array
    {
        $js = $this->pay_js_settings();
        return [
            'ajax_url'     => $this->ajax(),
            'nonce'        => wp_create_nonce('yuz_tra_nonce'),
            'current_tab'  => 'general',
            'settings'     => $js['settings'] ?? [],
            'switcher'     => $js['switcher'] ?? [],
            'capabilities' => ['can_manage_options' => current_user_can('manage_options')],
            'nonces'       => $this->sec_pick_nonces([
                'yuz_tra_ws_get_languages','yuz_tra_ws_cre_language','yuz_tra_ws_cre_alllang','yuz_tra_ws_del_language','yuz_tra_ws_del_alllang',
                'yuz_tra_ws_upd_settings','yuz_tra_ws_upd_weights','yuz_tra_ws_upd_deflang','yuz_tra_ws_upd_srclang',
                'yuz_tra_ls_get_settings','yuz_tra_sw_get_settings','yuz_tra_sw_upd_settings',
                // Include TS nonces for fallback when yuzTS localization is blocked by CSP
                'yuz_tra_ts_get_settings','yuz_tra_ts_upd_settings',
                'yuz_tra_tm_test_api','yuz_tra_nonce','yuz_con_nonce','yuz_hvy_nonce','yuz_api_nonce','yuz_del_nonce',
            ]),
        ];
    }

    /** @zone pay */
    public function pay_ts(): array
    {
        $settings = (array) $this->settings->get_option('yuz_tra_ts_settings');

        // Legacy fallbacks (older aliases / imports)
        if (empty($settings)) {
            $legacy = get_option('yuz_tra_site_settings', []);
            if (is_array($legacy) && !empty($legacy)) {
                $settings = $legacy;
            }
        }
        if (empty($settings)) {
            $legacy = get_option('yuz_translation_site_settings', []);
            if (is_array($legacy) && !empty($legacy)) {
                $settings = $legacy;
            }
        }

        if (!isset($settings['url_to_load']) || $settings['url_to_load'] === '') {
            $settings['url_to_load'] = home_url('/');
        }

        if (!isset($settings['allowed_roles']) || !is_array($settings['allowed_roles'])) {
            $fallback = get_option('yuz_tra_allowed_roles', []);
            if (empty($fallback) && function_exists('yuz_tra_default_allowed_roles')) {
                $fallback = yuz_tra_default_allowed_roles();
            }
            $settings['allowed_roles'] = array_values(array_unique(array_filter((array) $fallback)));
        }

        return [
            'ajax_url'      => $this->ajax(),
            'home_url'      => home_url('/'),
            'nonces'        => $this->sec_pick_nonces([
                'yuz_tra_ts_get_settings','yuz_tra_ts_upd_settings',
                'yuz_tra_ts_cre_fulltra','yuz_tra_ts_start_translation','yuz_api_nonce',
            ]),
            'site_settings' => $settings,
        ];
    }

    /** @zone pay */
    public function pay_te(): array
    {
        $payload = [
            'ajax_url' => $this->ajax(),
            'nonces'   => $this->sec_pick_nonces([
                'yuz_tra_te_cre_tstart','yuz_tra_te_cre_translation','yuz_tra_te_upd_manual',
                'yuz_tra_te_upd_publish','yuz_save_translation','yuz_tra_tm_cre_page',
                'yuz_tra_tm_get_translations','yuz_tra_tm_search','yuz_tra_tm_del_translation',
                'yuz_tra_tm_translate','yuz_translate','yuz_api_nonce',
            ]),
            'flags'        => ['editor_advanced_ui' => true],
            'site_settings'=> ['url_to_load' => home_url('/')],
        ];

        if (isset($_GET['yuzdebug'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            try {
                yuz_tra_debug_log('[YUZ_DBG][pay_te] ' . wp_json_encode([
                    'ajax_url' => $payload['ajax_url'],
                    'nonces'   => $payload['nonces'],
                    'user_id'  => get_current_user_id(),
                    'logged'   => is_user_logged_in(),
                    'req'      => sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'] ?? '')),
                ]));
            } catch (\Throwable $ignored) {}
        }

        return $payload;
    }

    /** @zone pay */
    public function pay_strings(): array
    {
        $default = '';
        $source  = '';
        if (method_exists($this->languages, 'get_default_language')) {
            $default_obj = $this->languages->get_default_language();
            if (is_object($default_obj) && isset($default_obj->language_code)) {
                $default = (string) $default_obj->language_code;
            } else {
                $default = (string) $default_obj;
            }
        }
        if (method_exists($this->languages, 'get_source_language')) {
            $source_obj = $this->languages->get_source_language();
            if (is_object($source_obj) && isset($source_obj->language_code)) {
                $source = (string) $source_obj->language_code;
            } else {
                $source = (string) $source_obj;
            }
        }

        $payload = [
            'ajax_url'    => $this->ajax(),
            'nonce'       => wp_create_nonce('yuz_int_nonce'),
            'defaultLang' => $default ?: $source,
            'sourceLang'  => $source,
            'languages'   => YUZ_String_Catalog::languages(),
            'editorLang'  => YUZ_String_Catalog::locale(get_user_locale()),
            'canScan'     => current_user_can('manage_options'),
            'userId'      => get_current_user_id(),
        ];

        return apply_filters('yuz/assets/payload/strings', $payload);
    }

    /** @zone pay */
    public function pay_automatic_translation(): array
    {
        return [
            'ajax_url'      => $this->ajax(),
            'nonces'        => $this->sec_pick_nonces([
                'yuz_tra_at_get_api_settings','yuz_tra_at_upd_api_settings','yuz_tra_at_del_api_settings',
                'yuz_tra_at_get_api_test','yuz_tra_tm_test_api','yuz_tra_at_cre_tsilent',
            ]),
            'api_settings'  => YUZ_Translation_Budget::settings(),
            'yuz_api_nonce' => wp_create_nonce('yuz_api_nonce'),
        ];
    }

    /** @zone pay */
    public function pay_addons(): array
    {
        return [
            'ajax_url'      => $this->ajax(),
            'nonces'        => $this->sec_pick_nonces(['yuz_add_list','yuz_add_toggle','yuz_add_install','yuz_add_update','yuz_add_delete']),
            'addons'        => method_exists('YUZ_Addons', 'get_all') ? (array) \YUZ_Addons::get_all() : [],
            'capabilities'  => ['can_manage_options' => current_user_can('manage_options')],
        ];
    }

    /** @zone pay */
    public function pay_licenses(): array
    {
        return [
            'ajax_url'      => $this->ajax(),
            'nonces'        => $this->sec_pick_nonces(['yuz_lic_get_status','yuz_lic_ping','yuz_lic_activate','yuz_lic_deactivate','yuz_lic_update_key','yuz_lic_delete']),
            'license'       => [
                'key'    => (string) get_option('yuz_tra_license_key', ''),
                'status' => (string) get_option('yuz_tra_license_status', 'inactive'),
            ],
            'capabilities'  => ['can_manage_options' => current_user_can('manage_options')],
        ];
    }

    /** @zone pay */
    public function pay_ai(): array
    {
        return [
            'ajax_url' => $this->ajax(),
            'nonces'   => $this->sec_pick_nonces(['yuz_ai_test_connection','yuz_ai_save_settings','yuz_ai_translate_text','yuz_ai_batch_translate','yuz_ai_glossary_upload','yuz_ai_job_status']),
        ];
    }

    /** @zone pay */
    public function pay_admin_bar(): array
    {
        $js = $this->pay_js_settings();
        return [
            'ajax_url'         => $this->ajax(),
            'nonces'           => ['yuz_tra_nonce' => $js['nonces']['yuz_tra_nonce'] ?? wp_create_nonce('yuz_tra_nonce')],
            'can_manage_options'=> current_user_can('manage_options'),
            'can_translate'    => class_exists('YUZ_Capabilities')
                ? \YUZ_Capabilities::user_is_translator()
                : current_user_can('yuz_translate_content'),
            'plugin_version'   => defined('YUZ_TRA_VERSION') ? (string) YUZ_TRA_VERSION : 'dev',
            'target_languages' => $js['translation_langs'] ?? [],
            'language_names'   => $js['language_names'] ?? [],
            'permissions'      => (class_exists('YUZ_Capabilities') && method_exists('YUZ_Capabilities', 'permissions_payload'))
                ? \YUZ_Capabilities::permissions_payload()
                : [],
            'urls'             => [
                'serviceModule' => $this->cfg_asset_url('js/yuz-translation-service.js'),
                'editorModule'  => $this->cfg_asset_url('js/widgets/yuz-translation-editor.js'),
            ],
        ];
    }

    /** @zone pay */
    public function pay_string_editor(): array
    {
        $js = $this->pay_js_settings();
        $config = ['items_per_page' => 20, 'see_more_max_length' => 140];
        $strings = apply_filters('yuz/assets/payload/string-editor/strings', []);
        $default_actions = apply_filters('yuz/assets/payload/string-editor/default-actions', [
            'actions'      => ['edit' => __('Edit', 'yuz-tra'), 'delete' => __('Delete', 'yuz-tra')],
            'bulk_actions' => [
                'publish' => ['name' => __('Publish', 'yuz-tra')],
                'delete'  => ['name' => __('Delete', 'yuz-tra')],
            ],
        ]);
        $status_filters = apply_filters('yuz/assets/payload/string-editor/status-filters', [
            'translation_status' => [
                'published'          => __('Published', 'yuz-tra'),
                'queued'             => __('Queued for publish', 'yuz-tra'),
                'pending_review'     => __('Pending review', 'yuz-tra'),
                'machine_translated' => __('Machine translated', 'yuz-tra'),
                'not_translated'     => __('Untranslated', 'yuz-tra'),
                'archived'           => __('Archived', 'yuz-tra'),
            ],
        ]);
        $types_cfg = apply_filters('yuz/assets/payload/string-editor/types-config', [
            'strings' => [
                'category_based'          => false,
                'name'                    => __('Strings', 'yuz-tra'),
                'type'                    => 'strings',
                'table_columns'           => ['original' => __('Original', 'yuz-tra'), 'context' => __('Context', 'yuz-tra')],
                'filters'                 => [],
                'add_new'                 => false,
                'search_name'             => __('Search Strings', 'yuz-tra'),
                'scan_gettext'            => true,
                'show_original_language'  => true,
            ],
        ]);

        return [
            'ajax_url'                   => $this->ajax(),
            'st_editor_strings'          => $strings,
            'default_actions'            => $default_actions,
            'translation_status_filters' => $status_filters,
            'config'                     => $config,
            'yuz_settings'               => $js['settings'] ?? [],
            'language_names'             => $js['language_names'] ?? [],
            'flags_path'                 => trailingslashit($this->cfg_asset_url('flags/')),
            'editor_nonces'              => $this->sec_pick_nonces(['yuz_scan_gettext','yuz_string_translation_bulk_action_publish','yuz_string_translation_bulk_action_delete','yuz_tra_nonce']),
            'string_types_config'        => $types_cfg,
            'upgraded_gettext'           => true,
            'notice_upgrade_gettext'     => '',
            'notice_upgrade_slugs'       => '',
            'upsale_slugs'               => false,
            'upsale_slugs_text'          => '',
        ];
    }

    /** @zone pay */
    public function pay_switcher(): array
    {
        $payload = apply_filters('yuz/assets/payload/switcher', []);
        return is_array($payload) ? $payload : [];
    }

    /** @zone pay */
    public function pay_dom_watch(): array
    {
        $nonces = $this->sec_pick_nonces(['yuz_tra_js_get_regular']);

        // Provide minimal language context to the DOM watcher without bloating yuzTraSettings.
        // This avoids any hard-coded mapping on the client by letting it pick among
        // DB-declared translatable languages.
        $lang_cfg = $this->language_runtime_config();
        $codes = (array) $lang_cfg['langs'];

        // Determine a sensible default target distinct from the source/default
        $default = (string) $lang_cfg['default'];
        $source  = (string) $lang_cfg['source'];
        $default_target = (string) $lang_cfg['default_target'];

        return [
            'ajax_url' => $this->ajax(),
            'nonces'   => [
                'yuz_tra_js_get_regular' => $nonces['yuz_tra_js_get_regular'] ?? wp_create_nonce('yuz_tra_nonce'),
            ],
            'languages'        => $codes,
            'default_language' => $default,
            'default_target'   => $default_target,
        ];
    }

    /* ================= Settings / nonces ================= */
    /** @deprecated 1.0.0 Use pay_js_settings() */
    private function js_settings(): array {
        return $this->pay_js_settings();
    }

    private function nonces_min(): array { return $this->sec_pick_nonces('min'); }

    private function build_nonces(): array {
        return $this->sec_build_nonces();
    }

    private function pick_nonces(array $keys): array {
        return $this->sec_pick_nonces($keys);
    }

    private function ensure_global_nonces(array $nonces): void {
        if (self::$nonces_bootstrapped) {
            return;
        }
        $payload = [];
        foreach (self::OFFICIAL_NONCES as $key) {
            if (isset($nonces[$key])) {
                $payload[$key] = $nonces[$key];
            }
        }
        if (!$payload) {
            return;
        }
        $handle = 'yuz-nonce-bootstrap';
        if (!wp_script_is($handle, 'registered')) {
            wp_register_script($handle, false, [], defined('YUZ_TRA_VERSION') ? YUZ_TRA_VERSION : '1.2.2', true);
        }
        if (!wp_script_is($handle, 'enqueued')) {
            wp_enqueue_script($handle);
        }
        wp_localize_script($handle, 'yuzNonce', $payload);
        wp_localize_script($handle, 'yuzNonceMatrix', self::ACTION_NONCE_MATRIX);
        $inline = <<<'JS'
(function(){
  if (typeof window === 'undefined') { return; }
  var matrix = window.yuzNonceMatrix || (typeof yuzNonceMatrix !== 'undefined' ? yuzNonceMatrix : {});
  window.yuzNonceMatrix = matrix;
  var store = window.yuzNonce || (typeof yuzNonce !== 'undefined' ? yuzNonce : {});
  window.yuzNonce = Object.freeze(store);
  window.yuzResolveNonce = window.yuzResolveNonce || function(identifier){
    var bucket = (identifier && matrix[identifier]) ? matrix[identifier] : identifier;
    bucket = (typeof bucket === 'string' && bucket) ? bucket : 'yuz_tra_nonce';
    return window.yuzNonce[bucket] || '';
  };
  window.yuzGetNonce = window.yuzGetNonce || window.yuzResolveNonce;

  var nonceProxy = null;
  if (typeof window.Proxy === 'function') {
    nonceProxy = new Proxy({}, {
      get: function(_target, prop){
        if (typeof prop === 'string') {
          return window.yuzResolveNonce(prop);
        }
        return undefined;
      }
    });
    window.yuzNonceProxy = nonceProxy;
  }

  var settingsStore = window.yuzTraSettings || {};
  function attachNonceHelpers(target) {
    target = target || {};
    target.nonce = window.yuzResolveNonce('yuz_tra_nonce');
    if (nonceProxy) {
      Object.defineProperty(target, 'nonces', {
        configurable: true,
        enumerable: false,
        get: function(){ return nonceProxy; },
        set: function(){ return true; }
      });
    }
    return target;
  }
  settingsStore = attachNonceHelpers(settingsStore);

  try {
    Object.defineProperty(window, 'yuzTraSettings', {
      configurable: true,
      enumerable: true,
      get: function(){ return settingsStore; },
      set: function(next){
        settingsStore = attachNonceHelpers(next || {});
      }
    });
  } catch (e) {
    window.yuzTraSettings = settingsStore;
  }
})();
JS;
        wp_add_inline_script($handle, $inline, 'after');
        self::$nonces_bootstrapped = true;
    }

    private function inline_allowed(): bool
    {
        return !function_exists('clar_forbid_yuz_inline') || !clar_forbid_yuz_inline();
    }

    private function localize(string $handle, string $global, array $payload): void {
        if (!wp_script_is($handle,'registered') && !wp_script_is($handle,'enqueued')) return;
        // Toujours localiser: la politique CSP est gérée via les hashes fournis
        // par add_yuz_tra_settings_hash() et Clar Script Manager.
        wp_localize_script($handle, $global, $payload);
    }

    private function ajax(): string {
        // Preserve the WordPress subdirectory, while keeping browser credentials
        // on its current origin (including a private reverse-proxy hostname).
        return admin_url('admin-ajax.php', 'relative');
    }

    public function add_yuz_tra_settings_hash(array $hashes): array {
        $mini = $this->pay_transverse_min();
        $hashes[] = $this->inline_hash('window.yuzTraSettings = window.yuzTraSettings || ' . wp_json_encode($mini) . ';');
        $hashes[] = $this->inline_hash('var yuzTraSettings=' . wp_json_encode($mini) . ';');

        // UI payload globals provided via wp_localize_script
        // These hashes allow Clar_Script_Manager/CSP to accept the inline "var <Global>=...;"
        try {
            $as = wp_json_encode($this->pay_js_settings());
            $hashes[] = $this->inline_hash('var yuzAS=' . $as . ';');
            $hashes[] = $this->inline_hash('var yuzAS = ' . $as . ';');
            $hashes[] = $this->inline_hash('window.yuzAS=' . $as . ';');
            $hashes[] = $this->inline_hash('window.yuzAS = ' . $as . ';');
        } catch (\Throwable $e) {}
        try {
            $se = wp_json_encode($this->pay_string_editor());
            $hashes[] = $this->inline_hash('var yuzSE=' . $se . ';');
            $hashes[] = $this->inline_hash('var yuzSE = ' . $se . ';');
            $hashes[] = $this->inline_hash('window.yuzSE=' . $se . ';');
            $hashes[] = $this->inline_hash('window.yuzSE = ' . $se . ';');
        } catch (\Throwable $e) {}
        try {
            $te = wp_json_encode($this->pay_te());
            $hashes[] = $this->inline_hash('var yuzTE=' . $te . ';');
            $hashes[] = $this->inline_hash('var yuzTE = ' . $te . ';');
            $hashes[] = $this->inline_hash('window.yuzTE=' . $te . ';');
            $hashes[] = $this->inline_hash('window.yuzTE = ' . $te . ';');
        } catch (\Throwable $e) {}
        try {
            $ts = wp_json_encode($this->pay_ts());
            // Cover spacing and window. prefix variants to match wp_localize_script output under CSP
            $hashes[] = $this->inline_hash('var yuzTS=' . $ts . ';');
            $hashes[] = $this->inline_hash('var yuzTS = ' . $ts . ';');
            $hashes[] = $this->inline_hash('window.yuzTS=' . $ts . ';');
            $hashes[] = $this->inline_hash('window.yuzTS = ' . $ts . ';');
        } catch (\Throwable $e) {}
        try {
            $ab = wp_json_encode($this->pay_admin_bar());
            $hashes[] = $this->inline_hash('var yuzAB=' . $ab . ';');
            $hashes[] = $this->inline_hash('var yuzAB = ' . $ab . ';');
            $hashes[] = $this->inline_hash('window.yuzAB=' . $ab . ';');
            $hashes[] = $this->inline_hash('window.yuzAB = ' . $ab . ';');
        } catch (\Throwable $e) {}
        // Admin-only (harmless on front)
        try {
            $gs = wp_json_encode($this->pay_general());
            $hashes[] = $this->inline_hash('var yuzGS=' . $gs . ';');
            $hashes[] = $this->inline_hash('var yuzGS = ' . $gs . ';');
            $hashes[] = $this->inline_hash('window.yuzGS=' . $gs . ';');
            $hashes[] = $this->inline_hash('window.yuzGS = ' . $gs . ';');
        } catch (\Throwable $e) {}
        return $hashes;
    }

    public function add_flags_inline_css_hash(array $hashes): array {
        $css = ':root{--yuz-flags-url:"' . esc_url(trailingslashit($this->cfg_asset_url('flags'))) . '";}';
        $hashes[] = $this->sec_inline_hash($css);
        return $hashes;
    }

    private function inline_hash(string $s): string {
        return $this->sec_inline_hash($s);
    }

    private function ver(string $rel): string {
        return $this->cfg_ver($this->cfg_asset_path($rel));
    }

    private function reg_register_vendor(string $handle, string $rel, array $deps = [], ?string $ver = null, bool $in_footer = true): void {
        $path = $this->cfg_asset_path($rel);
        $url  = $this->cfg_asset_url($rel);
        // Dependencies ship in the ZIP; never contact a CDN as a fallback.
        if (!file_exists($path)) { $this->log_debug('missing_bundled_dependency', ['handle' => $handle]); return; }
        $source = $url;
        $version = $ver ?? ($source === $url ? $this->cfg_ver($path) : null);
        wp_register_script($handle, $source, $deps, $version, $in_footer);
        $nonce = $this->sec_csp_nonce();
        if ($nonce) {
            wp_script_add_data($handle, 'nonce', $nonce);
        }
        if ($handle === 'yuz-translation-editor' && defined('CLAR_CSP_TRACE') && CLAR_CSP_TRACE) {
            $this->log_debug('csp_set_nonce', ['handle' => 'yuz-translation-editor', 'nonce' => $nonce]);
        }
    }

    private function reg_register(string $handle, string $rel, array $deps = [], bool $in_footer = true): bool {
        $path = $this->cfg_asset_path($rel);
        if (!file_exists($path)) return false;
        if (in_array($handle, self::ESM_WHITELIST, true) || $this->looks_browser_esm($path)) {
            self::mark_module_handles($handle);
        }
        $url = $this->cfg_asset_url($rel);
        $version = $this->cfg_ver($path);

        wp_register_script($handle, $url, $deps, $version, $in_footer);

        $hash = @hash_file('sha384', $path, true);
        if (is_string($hash) && $hash !== '') {
            $integrity = 'sha384-' . base64_encode($hash);
            wp_script_add_data($handle, 'integrity', $integrity);
            wp_script_add_data($handle, 'crossorigin', 'anonymous');
        }
        $nonce = $this->sec_csp_nonce();
        if ($nonce) {
            wp_script_add_data($handle, 'nonce', $nonce);
        }
        if ($handle === 'yuz-translation-editor' && defined('CLAR_CSP_TRACE') && CLAR_CSP_TRACE) {
            $this->log_debug('csp_set_nonce', ['handle' => 'yuz-translation-editor', 'nonce' => $nonce]);
        }
        return true;
    }

    private function reg_register_style(string $handle, string $rel, array $deps = []): bool {
        $path = $this->cfg_asset_path($rel);
        if (!file_exists($path)) return false;
        wp_register_style($handle, $this->cfg_asset_url($rel), $deps, $this->cfg_ver($path));
        $nonce = $this->sec_csp_nonce();
        if ($nonce) {
            wp_style_add_data($handle, 'nonce', $nonce);
        }
        return true;
    }

    private function is_script_ready(string $handle): bool {
        return wp_script_is($handle, 'registered') || wp_script_is($handle, 'enqueued');
    }

    private function is_style_ready(string $handle): bool {
        return wp_style_is($handle, 'registered') || wp_style_is($handle, 'enqueued');
    }

    private function enqueue_script(string $handle): bool {
        if (!$this->is_script_ready($handle)) {
            return false;
        }
        wp_enqueue_script($handle);
        return true;
    }

    private function enqueue_style(string $handle): bool {
        if (!$this->is_style_ready($handle)) {
            return false;
        }
        global $wp_styles;
        $src = $wp_styles->registered[$handle]->src ?? '';
        if (!$src || strpos(wp_parse_url($src, PHP_URL_PATH) ?: '', '.css') === false) {
            return false;
        }
        wp_enqueue_style($handle);
        return true;
    }

    private function prepare_transverse(string $handle): void {
        if (!$this->is_script_ready($handle)) {
            return;
        }
        $this->pay_localize_transverse_min($handle);
        $this->ensure_t_min($handle);
    }

    private function is_yuz_page(): bool {
        if (isset($_GET['page']) && $_GET['page'] === 'yuz-translation-settings') return true;
        if (isset($_GET['page']) && $_GET['page'] === 'yuz-string-translation-editor') return true;
        return isset($_GET['yuz-edit-translation']);
    }

    private function tab(): string {
        if (($_GET['page'] ?? '') === 'yuz-string-translation-editor') return 'strings';
        $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'general';
        $map = [
            'general'=>'general','translate-site'=>'translate-site','strings'=>'strings','string'=>'strings','automatic-translation'=>'automatic-translation','advanced'=>'advanced',
            'addons'=>'ad','ad'=>'ad','licenses'=>'licenses','ai'=>'ai-translation','ai-translation'=>'ai-translation'
        ];
        return $map[$tab] ?? 'general';
    }

    public static function inject_flags_css_root_var_static(): void {
        $assets = self::get();
        if (!$assets->inline_allowed()) {
            return;
        }
        $url  = esc_url($assets->cfg_asset_url('flags/'));
        foreach (['yuz-language-switcher','yuz-translation-editor','yuz-general-settings'] as $h) {
            if (wp_style_is($h,'registered') || wp_style_is($h,'enqueued')) {
                wp_add_inline_style($h, ':root{--yuz-flags-url:"'.$url.'";}');
            }
        }
    }

    /* ===== Bundles (opt-in factory) ===== */
    private static array $bun_queue = [];

    /** ======================================================================
     * Zone: Bundles (bun)
     * ====================================================================== */
    public static function bun_require(string $bundle): void
    {
        self::$bun_queue[$bundle] = true;
    }

    /** @deprecated 1.0.0 Use bun_require() */
    public static function require(string $bundle): void
    {
        self::bun_require($bundle);
    }

    public static function bun_flush_front(): void
    {
        self::bun_flush('front');
    }

    public static function bun_flush_admin(): void
    {
        self::bun_flush('admin');
    }

    /** @deprecated 1.0.0 Use bun_flush_front()/bun_flush_admin() */
    public static function flush_front(): void { self::bun_flush_front(); }
    /** @deprecated 1.0.0 Use bun_flush_front()/bun_flush_admin() */
    public static function flush_admin(): void { self::bun_flush_admin(); }

    private static function bun_flush(string $context): void
    {
        if (empty(self::$bun_queue)) {
            return;
        }

        $catalog = self::bun_catalog();
        $instance = self::get();

        foreach (array_keys(self::$bun_queue) as $key) {
            $definition = $catalog[$key] ?? null;
            if (!$definition) {
                unset(self::$bun_queue[$key]);
                continue;
            }

            $visibility = $definition['scope'] ?? 'both';
            if ($visibility !== 'both' && $visibility !== $context) {
                continue;
            }

            foreach ($definition['styles'] ?? [] as $handle) {
                if (wp_style_is($handle, 'registered')) {
                    wp_enqueue_style($handle);
                }
            }

            foreach ($definition['scripts'] ?? [] as $handle) {
                if (!wp_script_is($handle, 'registered')) {
                    continue;
                }
                $instance->pay_localize_transverse_min($handle);
                wp_enqueue_script($handle);

                foreach ($definition['localize'][$handle] ?? [] as $localization) {
                    $global  = $localization['global'] ?? null;
                    $payload = $localization['payload'] ?? null;
                    if (!$global || !is_callable($payload)) {
                        continue;
                    }
                    if (!$instance->inline_allowed()) {
                        continue;
                    }
                    $data = call_user_func($payload);
                    if (is_array($data) && $data !== []) {
                        wp_localize_script($handle, $global, $data);
                    }
                }
            }

            unset(self::$bun_queue[$key]);
        }
    }

    private static function bun_catalog(): array
    {
        $self = self::get();

        return [
            'admin-general' => [
                'scope'   => 'admin',
                'styles'  => ['yuz-general-settings'],
                'scripts' => ['yuz-general-settings'],
                'localize'=> [
                    'yuz-general-settings' => [
                        ['global' => 'yuzGS', 'payload' => [$self, 'pay_general']],
                    ],
                ],
            ],
            'translation-editor' => [
                'scope'   => 'both',
                'styles'  => ['yuz-translation-editor'],
                'scripts' => ['yuz-translation-editor'],
                'localize'=> [
                    'yuz-translation-editor' => [
                        ['global' => 'yuzTE', 'payload' => [$self, 'pay_te']],
                    ],
                ],
            ],
            'switcher' => [
                'scope'   => 'front',
                'styles'  => ['yuz-language-switcher'],
                'scripts' => ['yuz-language-switcher'],
                'localize'=> [
                    'yuz-language-switcher' => [
                        ['global' => 'yuzSW', 'payload' => [$self, 'pay_switcher']],
                    ],
                ],
            ],
        ];
    }

    /* ===== Impl. AssetsInterface — wrappers ===== */
    public function enqueue_admin_scripts(string $hook): void { $this->enqueue_admin($hook); }
    public function enqueue_front_scripts(): void { $this->enqueue_front(); }
    public function add_module_type(string $tag, string $handle, string $src): string { return $this->blk_filter_script_tag($tag,$handle,$src); }


    /**
     * Best-effort detection of the requested locale from the current HTTP request.
     */
    private function detect_request_locale(array $available_codes): string
    {
        $available = array_values(array_filter(array_map([$this, 'normalize_locale_code'], $available_codes), 'strlen'));
        if (empty($available)) {
            return '';
        }

        $candidates = [];
        if (function_exists('get_query_var')) {
            $query_lang = (string) get_query_var('lang', '');
            if ($query_lang !== '') {
                $candidates[] = $query_lang;
            }
        }
        if (isset($_GET['lang'])) {
            $candidates[] = (string) $_GET['lang'];
        }
        if (isset($_SERVER['HTTP_X_YUZ_LANG'])) {
            $candidates[] = sanitize_text_field(wp_unslash($_SERVER['HTTP_X_YUZ_LANG']));
        }

        foreach ($candidates as $candidate) {
            $normalized = $this->normalize_locale_code($candidate);
            if ($normalized !== '' && in_array($normalized, $available, true)) {
                return $normalized;
            }
        }

        $path = '';
        if (isset($_SERVER['REQUEST_URI'])) {
            $raw = sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI']));
            $parsed = wp_parse_url($raw, PHP_URL_PATH);
            $path = is_string($parsed) ? $parsed : '';
        }
        if ($path !== '') {
            $segments = array_values(array_filter(explode('/', trim($path, '/'))));
            $first = $segments[0] ?? '';
            if ($first !== '') {
                $first = strtolower($first);
                foreach ($available as $locale) {
                    if ($this->slug_from_locale($locale) === $first) {
                        return $locale;
                    }
                }
            }
        }

        return '';
    }

    private function resolve_request_page_url(): string
    {
        try {
            if (!class_exists('YUZ_Url_Converter') && defined('YUZ_TRA_INCLUDES')) {
                $converter_file = YUZ_TRA_INCLUDES . 'class-yuz-url-converter.php';
                if (is_readable($converter_file)) {
                    require_once $converter_file;
                }
            }
            if (class_exists('YUZ_Url_Converter')) {
                $settings = null;
                if (class_exists('YUZ_Services') && method_exists('YUZ_Services', 'settings')) {
                    $settings = \YUZ_Services::settings();
                }
                $converter = new \YUZ_Url_Converter($settings);
                $current = (string) $converter->cur_page_url();
                if ($current !== '') {
                    return esc_url_raw($current);
                }
            }
        } catch (\Throwable $ignored) {}

        $request_uri = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '/';
        $host = isset($_SERVER['HTTP_HOST']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_HOST'])) : (string) wp_parse_url(home_url('/'), PHP_URL_HOST);
        if ($host === '') {
            return home_url('/');
        }
        $scheme = is_ssl() ? 'https' : 'http';
        return esc_url_raw($scheme . '://' . $host . $request_uri);
    }

    private function resolve_request_post_id(string $page_url, array $available_codes): int
    {
        $post_id = function_exists('get_queried_object_id') ? absint(get_queried_object_id()) : 0;
        if ($post_id > 0) {
            return $post_id;
        }

        $page_url = trim($page_url);
        if ($page_url !== '') {
            $direct = url_to_postid($page_url);
            if ($direct) {
                return (int) $direct;
            }

            $parts = wp_parse_url($page_url);
            if (is_array($parts)) {
                $path = isset($parts['path']) ? (string) $parts['path'] : '';
                if ($path !== '') {
                    $home_parts = wp_parse_url(home_url('/'));
                    $home_path = isset($home_parts['path']) ? rtrim((string) $home_parts['path'], '/') : '';
                    if ($home_path !== '' && stripos($path, $home_path) === 0) {
                        $trimmed = substr($path, strlen($home_path));
                        if ($trimmed !== false) {
                            $path = $trimmed;
                        }
                    }

                    $clean_path = trim($path, '/');
                    $segments = array_values(array_filter(explode('/', $clean_path), 'strlen'));
                    $candidates = [];
                    if ($clean_path !== '') {
                        $candidates[] = $clean_path;
                    }

                    $available = array_values(array_filter(array_map([$this, 'normalize_locale_code'], $available_codes), 'strlen'));
                    if (count($segments) > 1 && $available) {
                        $first = strtolower($segments[0]);
                        foreach ($available as $locale) {
                            if ($this->slug_from_locale($locale) === $first) {
                                $stripped = implode('/', array_slice($segments, 1));
                                if ($stripped !== '') {
                                    $candidates[] = $stripped;
                                }
                                break;
                            }
                        }
                    }

                    foreach (array_values(array_unique($candidates)) as $candidate) {
                        $found = get_page_by_path($candidate);
                        if ($found && isset($found->ID)) {
                            return (int) $found->ID;
                        }
                        $candidate_url = home_url('/' . ltrim($candidate, '/'));
                        $pid = url_to_postid($candidate_url);
                        if ($pid) {
                            return (int) $pid;
                        }
                    }
                }
            }
        }

        $front = absint(get_option('page_on_front'));
        $posts = absint(get_option('page_for_posts'));
        return $front ?: $posts;
    }

    private function is_suspicious_front_dictionary_entry(string $original, string $translated): bool
    {
        $original = trim($original);
        $translated = trim($translated);
        if ($original === '' || $translated === '') {
            return true;
        }

        if (strlen($original) > 1800 || strlen($translated) > 6000) {
            return true;
        }

        $separator_score = substr_count($original, '•')
            + substr_count($original, '>')
            + substr_count($original, '{')
            + substr_count($original, '}')
            + substr_count($translated, '{')
            + substr_count($translated, '}');
        if ($separator_score > 18) {
            return true;
        }

        $pattern = '/(body\s+\.|div#|span\.|option:nth-of-type|aria-label|wp-block|#yuz-|\.yuz-|data-yuz|>\s*#|>\s*\.[A-Za-z0-9_-]+)/i';
        return preg_match($pattern, $original) === 1 || preg_match($pattern, $translated) === 1;
    }

    private function load_front_dictionary(): array
    {
        static $dictionary = null;
        if (is_array($dictionary)) {
            return $dictionary;
        }
        if (function_exists('wp_cache_get')) {
            $cached = wp_cache_get('yuz_front_dictionary', 'yuz-tra');
            if (is_array($cached)) {
                $dictionary = $cached;
                return $dictionary;
            }
        }
        global $wpdb;
        $dictionary = [];
        if (!isset($wpdb)) {
            return $dictionary;
        }
        $table = $wpdb->prefix . 'yuz_tra_translations';
        $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
        if (!$exists) {
            return $dictionary;
        }
        $rows = $wpdb->get_results(
            "SELECT original_text, translated_text, language_code, status FROM {$table} WHERE translated_text IS NOT NULL AND translated_text <> '' AND status >= 1",
            ARRAY_A
        );
        if (!is_array($rows)) {
            return $dictionary;
        }
        $normalize_chain_key = static function (string $value): string {
            $value = str_replace("\xc2\xa0", ' ', $value);
            $value = str_replace(["\u{2018}", "\u{2019}"], "'", $value);
            $value = str_replace(["\u{201C}", "\u{201D}"], '"', $value);
            $value = preg_replace('/\s+/u', ' ', $value);
            return is_string($value) ? trim($value) : '';
        };

        $translated_lookup = [];
        foreach ($rows as $row) {
            $original = isset($row['original_text']) ? trim((string) $row['original_text']) : '';
            $translated = isset($row['translated_text']) ? (string) $row['translated_text'] : '';
            if ($original === '' || $translated === '' || $this->is_suspicious_front_dictionary_entry($original, $translated)) {
                continue;
            }
            $normalized = $normalize_chain_key($translated);
            if ($normalized !== '') {
                $translated_lookup[$normalized] = true;
            }
        }

        foreach ($rows as $row) {
            $original = isset($row['original_text']) ? trim((string) $row['original_text']) : '';
            $langCode = $this->normalize_locale_code($row['language_code'] ?? '');
            $translated = isset($row['translated_text']) ? (string) $row['translated_text'] : '';
            if ($original === '' || $langCode === '' || $translated === '' || $this->is_suspicious_front_dictionary_entry($original, $translated)) {
                continue;
            }
            $normalized_original = $normalize_chain_key($original);
            $normalized_translated = $normalize_chain_key($translated);
            if ($normalized_original !== '' && $normalized_original !== $normalized_translated && isset($translated_lookup[$normalized_original])) {
                continue;
            }
            if (!isset($dictionary[$langCode])) {
                $dictionary[$langCode] = ['translationsArray' => []];
            }
            $dictionary[$langCode]['translationsArray'][$original] = $translated;

            $short = substr($langCode, 0, 2);
            if ($short && $short !== $langCode) {
                if (!isset($dictionary[$short])) {
                    $dictionary[$short] = ['translationsArray' => []];
                }
                $dictionary[$short]['translationsArray'][$original] = $translated;
            }
        }
        if (function_exists('wp_cache_set')) {
            $ttl = defined('MINUTE_IN_SECONDS') ? MINUTE_IN_SECONDS : 60;
            wp_cache_set('yuz_front_dictionary', $dictionary, 'yuz-tra', $ttl);
        }
        return $dictionary;
    }

    private function restrict_translations_dictionary(array $dictionary, array $allowed_codes): array
    {
        if (empty($dictionary) || empty($allowed_codes)) {
            return [];
        }
        $lookup = [];
        foreach ($allowed_codes as $code) {
            $normalized = $this->normalize_locale_code($code);
            if ($normalized !== '') {
                $lookup[$normalized] = true;
            }
        }
        if (!$lookup) {
            return [];
        }
        $filtered = [];
        foreach ($lookup as $code => $_) {
            if (isset($dictionary[$code])) {
                $filtered[$code] = $dictionary[$code];
            }
        }
        return $filtered;
    }

    private function resolve_trace_upload_url(): string
    {
        $fallback = '/wp-content/uploads/yuz-trace.log';
        if (!function_exists('wp_upload_dir')) {
            return $fallback;
        }
        $uploads = wp_upload_dir();
        if (!is_array($uploads)) {
            return $fallback;
        }
        if (!empty($uploads['baseurl'])) {
            return trailingslashit($uploads['baseurl']) . 'yuz-trace.log';
        }
        return $fallback;
    }

    private function normalize_locale_code($locale): string
    {
        if (!is_string($locale) || $locale === '') {
            return '';
        }
        $locale = str_replace('-', '_', $locale);
        $parts  = array_values(array_filter(explode('_', $locale)));
        if (!$parts) {
            return '';
        }
        $lang = strtolower($parts[0]);
        if (count($parts) === 1) {
            return $lang;
        }
        return $lang . '_' . strtoupper($parts[1]);
    }

    private function slug_from_locale(string $locale): string
    {
        $normalized = $this->normalize_locale_code($locale);
        return strtolower(str_replace('_', '-', $normalized));
    }

    private function ensure_review_dock_style_filter(): void
    {
        if ($this->review_style_filter_added) {
            return;
        }

        add_filter('style_loader_tag', static function ($html, $handle) {
            if ($handle === 'yuz-review-dock' && strpos($html, 'data-yuz-publish-css="1"') === false) {
                return str_replace('<link ', '<link data-yuz-publish-css="1" ', $html);
            }

            return $html;
        }, 10, 2);

        $this->review_style_filter_added = true;
    }

    /** ======================================================================
     * Zone: Debug (dbg)
     * ====================================================================== */
    /** ---- DEBUG LOGGER & probes ---- */
    private static bool $dbg_hooks_done = false;

    /** @zone dbg */
    private function debug_on(): bool
    {
        return (defined('YUZ_DEBUG') && YUZ_DEBUG) || (defined('WP_DEBUG') && WP_DEBUG);
    }

    /** @zone dbg */
    public function dbg_boot(): void
    {
        if (self::$dbg_hooks_done || !$this->debug_on()) { return; }
        self::$dbg_hooks_done = true;
        // init debug optionnelle (ex: add_action pour sniffer)
    }

    /** @zone dbg */
    public function dbg_enqueue(): void
    {
        if (!$this->debug_on()) { return; }
        // traces légères d’enqueue si besoin
    }

    /** @zone dbg */
    public function dbg_ack_all_enqueued(): void
    {
        if (!$this->debug_on()) { return; }
        // dump des handles enqueued si utile
    }

    /** @zone dbg */
    public function dbg_overlay_loader_hit(string $src): void
    {
        if (!$this->debug_on()) { return; }
        $this->log_debug('ovl_loader_tag', ['src' => $src]);
    }

    /** @zone dbg */
    public function dbg_overlay_footer_state(array $state): void
    {
        if (!$this->debug_on()) { return; }
        $this->log_debug('ovl_footer_state', $state);
    }

    /** @zone dbg */
    public function dbg_front_enqueue(array $ctx): void
    {
        if (!$this->debug_on()) { return; }
        $this->log_debug('front_enqueue_probe', $ctx);
    }




} // end class


YUZ_Assets::init();
endif;

/** ======================================================================
 * Zone: Shims & Compat (shim)
 * ====================================================================== */
if (class_exists(__NAMESPACE__ . '\\YUZ_Assets') && !YUZ_Assets::DISABLE_SHIMS) {
    /**
     * @deprecated 1.0.0 Use YUZ\YUZ_Assets::get()->cfg_asset_url()
     */
    if (!function_exists('yuz_asset_url')) {
        function yuz_asset_url(string $rel): string {
            $assets = YUZ_Assets::get();
            $assets->log_deprecated(__FUNCTION__, '1.0.0');
            return $assets->cfg_asset_url($rel);
        }
    }
}

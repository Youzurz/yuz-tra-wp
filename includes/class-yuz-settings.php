<?php
/**
 * Class YUZ_Settings
 * Handles settings management for the YUZ-TRA plugin.
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
if ( ! defined( 'ABSPATH' ) ) { exit; }
define('YUZ_MIGRATION_MODE', false);

// Minimal requires (interfaces/fallbacks only, no heavy classes)
require_once YUZ_TRA_INCLUDES . 'class-yuz-contracts.php';
require_once YUZ_TRA_INCLUDES . 'class-yuz-fallbacks.php';
// Routeur d’affichage : on s’appuie sur YUZ_Renderer::render_tab()
require_once YUZ_TRA_INCLUDES . 'class-yuz-renderer.php';

use YUZTRA\Interfaces\SettingsInterface;
use YUZTRA\Interfaces\LanguagesInterface;
use YUZTRA\Interfaces\Language;
use YUZTRA\Interfaces\AjaxInterface;
use YUZTRA\Interfaces\TranslationManagerInterface;
use YUZTRA\Interfaces\LanguageManagerInterface;
use YUZTRA\Interfaces\LoggerInterface;
use YUZTRA\Fallbacks\NullLanguages;
use YUZTRA\Fallbacks\NullAjax;
use YUZTRA\Fallbacks\NullTranslationManager;
use YUZTRA\Fallbacks\NullLanguageManager;
use YUZTRA\Fallbacks\NullLogger;

if (!class_exists('YUZ_Settings')) {
class YUZ_Settings implements SettingsInterface {

    /** @var LanguagesInterface */
    private $languages;
    /** @var AjaxInterface */
    private $ajax;
    /** @var TranslationManagerInterface */
    private $translationManager;
    /** @var LanguageManagerInterface */
    private $languageManager;
    /** @var LoggerInterface */
    private $logger;
    /** @var mixed|null éviter notices si renderer absent */
    private $renderer = null;

    /** @var bool guard sanitize_all recursion */
    private $in_sanitize_all = false;

    /**
     * Constructor with injected dependencies (stubs OK).
     */
    public function __construct(
        LanguagesInterface $languages,
        AjaxInterface $ajax,
        TranslationManagerInterface $translationManager,
        LanguageManagerInterface $languageManager,
        LoggerInterface $logger
    ) {
        $this->languages         = $languages;
        $this->ajax              = $ajax;
        $this->translationManager= $translationManager;
        $this->languageManager   = $languageManager;
        $this->logger            = $logger;

        $this->maybe_migrate_site_settings();
        $this->logger->log('info', 'YUZ_Settings instantiated with dependencies');

        add_action('wp_ajax_yuz_tra_sw_upd_settings', [$this, 'ajax_router']);
        add_action('wp_ajax_yuz_tra_ls_upd_settings', [$this, 'ajax_router']);
    }

    private function can_run_site_settings_migration(): bool {
        if (defined('WP_CLI') && WP_CLI) {
            return true;
        }

        if (defined('DOING_CRON') && DOING_CRON) {
            return true;
        }

        if (defined('DOING_AJAX') && DOING_AJAX) {
            return false;
        }

        return is_admin();
    }

    /**
     * Initializes lightly (hooks only, no heavy ops).
     */
    public static function init(): void {
        self::ensure_defaults();
        self::guard_admin_init_privacy_policies();
        // Stub deps for minimal init
        $logger   = new NullLogger();
        $instance = new self(
            new NullLanguages(),
            new NullAjax(),
            new NullTranslationManager(),
            new NullLanguageManager(),
            $logger
        );

        // Hooks (admin only, conditional in Core)
        add_action('admin_init', [$instance, 'register_settings']);
        add_action('admin_menu', [$instance, 'add_admin_page']);
        $logger->log('success', 'YUZ_Settings initialized');

        // One-time migrations (ADMIN ONLY) — OK d’utiliser get_option() sur legacy ici
        add_action('admin_init', function () use ($logger) {
            try { \YUZ_Settings::run_canonical_migration($logger); }
            catch (\Throwable $e) { $logger->log('warning', 'Canonical migration failed: ' . $e->getMessage()); }
        });
    }

    /**
     * Central runtime_flags: merge in-memory defaults with stored, no write.
     * FRONT-SAFE: lit uniquement le SSOT.
     */
    public static function runtime_flags(): array {
        $defaults = [
            'switcher_enabled'        => true,
            'editor_enabled'          => true,
            'editor_advanced_ui'      => false,
            'dynamic_strings'         => false,
            'mode_manual_enabled'     => true,
            'mode_semi_enabled'       => false,
            'mode_background_enabled' => false,
            'api_type'                => 'libretranslate',
            'adminbar_enabled'        => true,
            'translate_admin_enabled' => true,
        ];

        $stored = yuz_settings_get_all(); // SSOT only (runtime cache en amont)
        $flags  = array_replace_recursive($defaults, is_array($stored) ? $stored : []);

        // >>> DÉDUCTION RUNTIME DES MODES (ne touche pas la DB)
        $api  = isset($flags['yuz_tra_api_settings']) && is_array($flags['yuz_tra_api_settings'])
            ? $flags['yuz_tra_api_settings'] : [];
        $canonical_api = get_option('yuz_tra_at_settings', []);
        if (is_array($canonical_api) && $canonical_api) $api = $canonical_api;
        $mode = isset($api['translation_mode']) ? (string)$api['translation_mode'] : 'manual';
        $auto = !empty($api['enable_auto_translate']);

        // UI override from Translate Site (full/half)
        if (isset($flags['yuz_tra_site_settings']) && is_array($flags['yuz_tra_site_settings'])) {
            $uiMode = (string) ($flags['yuz_tra_site_settings']['translation_mode'] ?? '');
            if ($uiMode === 'full')  { $mode = 'background'; }
            elseif ($uiMode === 'half') { $mode = 'semi'; }
        }

        $flags['mode_semi_enabled']       = ($mode === 'semi') || ($mode === 'manual' && $auto);
        $flags['mode_background_enabled'] = $auto && in_array($mode, ['background','silent','auto','all'], true);
        $flags['mode_manual_enabled']     = in_array($mode, ['manual','semi','silent','auto','all','background'], true);

        if ($mode === 'all') {
            $flags['mode_background_enabled'] = $auto;
            $flags['mode_semi_enabled']       = true;
        }

        // Elevate advanced toggles
        if (isset($flags['yuz_tra_advanced']) && is_array($flags['yuz_tra_advanced'])) {
            $adv = (array) $flags['yuz_tra_advanced'];
            if (array_key_exists('editor_advanced_ui', $adv)) {
                $flags['editor_advanced_ui'] = !empty($adv['editor_advanced_ui']);
            }
            if (array_key_exists('translate_admin_enabled', $adv)) {
                $flags['translate_admin_enabled'] = !empty($adv['translate_admin_enabled']);
            }
        }

        return $flags;
    }

    /**
     * Gets option value — FRONT-SAFE: pas de fallback unitaire.
     * - 'yuz_tra_all_settings' → SSOT complet
     * - toute autre clé → section du SSOT si présente, sinon []
     */
    public function get_option(string $option_name) {
        $all = yuz_settings_get_all(); // SSOT + runtime cache
        if ($option_name === 'yuz_tra_all_settings') {
            return is_array($all) ? $all : [];
        }
        if (is_array($all) && array_key_exists($option_name, $all)) {
            return is_array($all[$option_name]) ? $all[$option_name] : [];
        }
        // Aucun fallback DB (legacy) en front
        return [];
    }

    /**
     * Sanitizes option (par section).
     */
    public function sanitize_option(string $option_name, $value) {
        $lookup = function_exists('yuz_settings_registry_lookup') ? yuz_settings_registry_lookup() : [];
        if (!isset($lookup[$option_name])) {
            return is_array($value) ? $value : [];
        }

        $canonical = $lookup[$option_name]['canonical'];
        $alias     = $lookup[$option_name]['alias'];

        $prepared  = $this->prepare_legacy_payload($alias, $value);
        $sanitized = yuz_settings_sanitize_section($canonical, $prepared);

        switch ($canonical) {
            case 'yuz_tra_ws_settings':
                $this->logger->log('success', 'Sanitized website languages settings', ['settings' => $sanitized]);
                break;

            case 'yuz_tra_ls_settings':
                $this->logger->log('success', 'Sanitized language settings toggles', ['settings' => $sanitized]);
                break;

            case 'yuz_tra_sw_settings':
                $this->logger->log('success', 'Sanitized language switcher settings', ['settings' => $sanitized]);
                break;

            case 'yuz_tra_ts_settings':
                if (empty($sanitized['allowed_roles'])) {
                    $sanitized['allowed_roles'] = ['administrator', 'editor', 'translator'];
                }
                $this->logger->log('success', 'Sanitized translate site settings', ['settings' => $sanitized]);
                break;

            case 'yuz_tra_at_settings':
                $this->logger->log('success', 'Sanitized automatic translation settings', ['settings' => $sanitized]);
                break;

            case 'yuz_tra_av_settings':
                $this->logger->log('success', 'Sanitized advanced settings', ['settings' => $sanitized]);
                break;

            case 'yuz_tra_ad_settings':
            case 'yuz_tra_li_settings':
                $this->logger->log('success', sprintf('Sanitized list settings (%s)', $canonical), ['settings' => $sanitized]);
                break;

            case 'yuz_tra_ai_settings':
                $this->logger->log('success', 'Sanitized AI settings', ['settings' => $sanitized]);
                break;
        }

        return $sanitized;
    }

    private function prepare_legacy_payload(string $alias, $value): array {
        $payload = is_array($value) ? $value : [];

        if ($alias === 'yuz_tra_general') {
            if (!isset($payload['yuz_tra_slug']) && isset($payload['yuz_slug'])) {
                $payload['yuz_tra_slug'] = $payload['yuz_slug'];
            }
            if (!isset($payload['yuz_tra_code']) && isset($payload['yuz_code'])) {
                $payload['yuz_tra_code'] = $payload['yuz_code'];
            }
            if (!isset($payload['yuz_tra_translatable_languages']) && isset($payload['yuz_translatable_languages'])) {
                $payload['yuz_tra_translatable_languages'] = $payload['yuz_translatable_languages'];
            }
            if (!isset($payload['yuz_tra_default_language']) && isset($payload['yuz_default_language'])) {
                $payload['yuz_tra_default_language'] = $payload['yuz_default_language'];
            }
            if (!isset($payload['yuz_tra_source_language']) && isset($payload['yuz_source_language'])) {
                $payload['yuz_tra_source_language'] = $payload['yuz_source_language'];
            }
        } elseif ($alias === 'yuz_tra_settings') {
            $allowed = ['native_language_name', 'use_subdirectory', 'force_lang_in_links'];
            $payload = array_intersect_key($payload, array_flip($allowed));
        } elseif ($alias === 'yuz_tra_site_settings') {
            if (isset($payload['allowed_roles']) && !is_array($payload['allowed_roles'])) {
                $payload['allowed_roles'] = [$payload['allowed_roles']];
            }
        }

        return $payload;
    }

    private function maybe_migrate_site_settings(): void {
        if (!$this->can_run_site_settings_migration()) {
            return;
        }

        $legacy = get_option('yuz_tra_site_settings', null);
        if ($legacy === null || $legacy === false || $legacy === []) {
            return;
        }

        $legacy_arr = is_array($legacy) ? $legacy : (array) maybe_unserialize($legacy);
        if (function_exists('yuz_settings_sanitize_section')) {
            $legacy_arr = yuz_settings_sanitize_section('yuz_tra_ts_settings', $legacy_arr);
        }

        $canonical = get_option('yuz_tra_ts_settings', []);
        if (is_array($canonical) && !empty($canonical)) {
            if (function_exists('yuz_settings_sanitize_section')) {
                $canonical = yuz_settings_sanitize_section('yuz_tra_ts_settings', $canonical);
            }
            $merged = array_merge($legacy_arr, $canonical);
        } else {
            $merged = $legacy_arr;
        }

        update_option('yuz_tra_ts_settings', $merged, false);
        delete_option('yuz_tra_site_settings');

        if ($this->logger) {
            $this->logger->log('info', 'Migrated legacy yuz_tra_site_settings to yuz_tra_ts_settings', ['settings' => $merged]);
        }
    }

    private function maybe_sync_languages_table(array $sanitized, $raw): void {
        global $wpdb;
        $tbl = $wpdb->prefix . 'yuz_tra_languages';
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $tbl));
        if (!$exists) {
            $this->logger->log('warning', 'yuz_tra_languages missing during website languages update — options will still be updated');
            return;
        }

        $rawArray = is_array($raw) ? $raw : [];
        $talsProvided = array_key_exists('yuz_tra_translatable_languages', $rawArray)
            || array_key_exists('yuz_translatable_languages', $rawArray);
        if (!$talsProvided) {
            $this->logger->log('info', 'update_option(yuz_tra_ws_settings): skipping DB updates (translatable list not provided)');
            return;
        }

        $this->handle_db_updates($sanitized, $tbl);
        try {
            $src  = (string) ($sanitized['yuz_tra_source_language'] ?? '');
            $def  = (string) ($sanitized['yuz_tra_default_language'] ?? '');
            $tals = (array) ($sanitized['yuz_tra_translatable_languages'] ?? []);
            $this->languageManager->enforce_language_rules($src, $def, $tals);
        } catch (\Throwable $e) {
            $this->logger->log('warning', 'enforce_language_rules failed after DB update: ' . $e->getMessage());
        }
    }

    private static function guard_admin_init_privacy_policies(): void {
        if ( is_admin() ) {
            return;
        }

        add_action( 'admin_init', [ __CLASS__, 'suppress_privacy_policy_callbacks' ], 0 );
    }

    public static function suppress_privacy_policy_callbacks(): void {
        remove_action( 'admin_init', [ 'Akismet_Admin', 'admin_init' ], 10 );
        self::remove_method_callbacks( 'admin_init', 'add_privacy_policy_content' );
    }

    private static function remove_method_callbacks( string $hook_name, string $method_name ): void {
        global $wp_filter;

        if ( empty( $wp_filter[ $hook_name ] ) ) {
            return;
        }

        $hook = $wp_filter[ $hook_name ];
        if ( ! ( $hook instanceof \WP_Hook ) || empty( $hook->callbacks ) ) {
            return;
        }

        foreach ( $hook->callbacks as $priority => $callbacks ) {
            foreach ( $callbacks as $callback ) {
                if ( ! is_array( $callback['function'] ) ) {
                    continue;
                }

                $target = $callback['function'];
                if (
                    count( $target ) === 2
                    && is_object( $target[0] )
                    && is_string( $target[1] )
                    && $target[1] === $method_name
                ) {
                    remove_action( $hook_name, $target, $priority );
                }
            }
        }
    }

    private function sync_url_to_load($raw): void {
        $url = esc_url_raw(is_array($raw) ? '' : (string) wp_unslash($raw ?? ''));
        yuz_settings_update(function (array $current) use ($url) {
            $api = isset($current['yuz_tra_at_settings']) && is_array($current['yuz_tra_at_settings'])
                ? $current['yuz_tra_at_settings']
                : yuz_settings_section_default('yuz_tra_at_settings');
            $api['url_to_load'] = $url;
            $current['yuz_tra_at_settings'] = $api;
            return $current;
        });
    }

    /**
     * Updates option (only on submit) → écrit dans les options canoniques.
     */
    public function update_option(string $option_name, $value): bool {
        $lookup = function_exists('yuz_settings_registry_lookup') ? yuz_settings_registry_lookup() : [];
        if (!isset($lookup[$option_name])) {
            $this->logger->log('warning', sprintf('Attempt to update unknown option \"%s\"', $option_name));
            return false;
        }

        $canonical = $lookup[$option_name]['canonical'];
        $alias     = $lookup[$option_name]['alias'];

        if ($alias === 'yuz_tra_settings' && is_array($value) && array_key_exists('url_to_load', $value)) {
            $this->sync_url_to_load($value['url_to_load']);
        }

        $sanitized = $this->sanitize_option($alias, $value);
        $defaults  = function_exists('yuz_settings_section_default')
            ? yuz_settings_section_default($canonical)
            : [];
        if (is_array($sanitized) && is_array($defaults) && $defaults) {
            $sanitized = array_replace($defaults, $sanitized);
        }

        $existing = get_option($canonical, []);
        if (!is_array($existing)) {
            $existing = [];
        } elseif (function_exists('yuz_settings_sanitize_section')) {
            $existing = yuz_settings_sanitize_section($canonical, $existing);
        }

        if (
            $existing
            && $defaults
            && $existing !== $defaults
            && $sanitized === $defaults
            && !self::payload_has_meaningful_values($value)
        ) {
            $this->logger->log(
                'warning',
                sprintf('Skipped empty overwrite for %s; retaining existing settings', $canonical),
                ['submitted' => $value]
            );
            return true;
        }

        if ($canonical === 'yuz_tra_ws_settings') {
            $this->maybe_sync_languages_table($sanitized, $value);
        }

        $result = yuz_settings_update(function (array $current) use ($canonical, $sanitized) {
            $current[$canonical] = $sanitized;
            return $current;
        });

        if ($result && in_array($canonical, ['yuz_tra_ws_settings', 'yuz_tra_sw_settings', 'yuz_tra_ls_settings'], true)) {
            do_action('yuz_tra_settings_updated', $alias, $sanitized);
        }
        return (bool) $result;
    }

    public function ajax_router() {
        error_log('[YUZ][AJAX] handling ' . ($_POST['action'] ?? '(none)') . ' with data=' . wp_json_encode($_POST));
        $action = sanitize_text_field($_POST['action'] ?? '');
        $nonce  = $_POST['nonce'] ?? '';

        $nonce_ok = wp_verify_nonce($nonce, 'yuz_tra_nonce') || wp_verify_nonce($nonce, 'yuz_con_nonce');
        if (!$nonce_ok) {
            error_log('[YUZ][AJAX] invalid nonce for ' . $action);
            wp_send_json_error(['error' => 'Invalid nonce']);
        }

        switch ($action) {
            case 'yuz_tra_sw_upd_settings':
                if (!current_user_can('manage_options')) {
                    wp_send_json_error(['error' => 'Insufficient permissions'], 403);
                }
                $data = isset($_POST['switcher_settings'])
                    ? (array) wp_unslash($_POST['switcher_settings'])
                    : [];
                $this->update_settings_bucket('yuz_tra_sw_settings', $data);
                break;

            case 'yuz_tra_ls_upd_settings':
                if (!current_user_can('manage_options')) {
                    wp_send_json_error(['error' => 'Insufficient permissions'], 403);
                }
                $data = isset($_POST['language_settings'])
                    ? (array) wp_unslash($_POST['language_settings'])
                    : [];
                $this->update_settings_bucket('yuz_tra_ls_settings', $data);
                break;

            default:
                error_log('[YUZ][AJAX] unknown action ' . $action);
                wp_send_json_error(['error' => 'Unknown action']);
        }
    }

    /**
     * 🔧 Méthode utilitaire : fusionne, sanitise et met à jour un bucket canonique.
     */
    private function update_settings_bucket(string $option_name, array $data): void {
        $old = get_option($option_name, []);
        $old = is_array($old) ? $old : [];
        $payload = $data;

        $merged    = array_merge($old, $payload);
        $sanitized = function_exists('yuz_settings_sanitize_section')
            ? yuz_settings_sanitize_section($option_name, $merged)
            : $merged;

        update_option($option_name, $sanitized);
        error_log('[YUZ][UPDATE] ' . $option_name . '=' . wp_json_encode($sanitized));

        wp_send_json_success([
            'updated' => $sanitized,
            'option'  => $option_name,
        ]);
    }

    private static function cast_switcher_for_legacy(array $in): array {
        $out = [];
        $boolKeys = ['shortcode_enabled', 'menu_enabled', 'floating_enabled', 'show_poweredby'];
        foreach ($boolKeys as $k) {
            if (array_key_exists($k, $in)) { $out[$k] = !empty($in[$k]) ? 1 : 0; }
        }
        $strKeys = ['shortcode_format','menu_format','floating_format','floating_theme','floating_position'];
        foreach ($strKeys as $k) {
            if (array_key_exists($k, $in)) { $out[$k] = is_string($in[$k]) ? $in[$k] : strval($in[$k]); }
        }
        return $out;
    }

    /**
     * Merge switcher values into standalone yuz_tra_settings under legacy yuz_* keys.
     * Does not touch consolidated all_settings to avoid polluting that structure.
     */
    private static function sync_switcher_into_yuz_tra_settings(array $switcher): void {
        $map = [
            'shortcode_enabled'  => 'yuz_shortcode_enabled',
            'shortcode_format'   => 'yuz_shortcode_format',
            'menu_enabled'       => 'yuz_menu_enabled',
            'menu_format'        => 'yuz_menu_format',
            'floating_enabled'   => 'yuz_floating_enabled',
            'floating_format'    => 'yuz_floating_format',
            'floating_theme'     => 'yuz_floating_theme',
            'floating_position'  => 'yuz_floating_position',
            'show_poweredby'     => 'yuz_show_poweredby',
        ];
        $legacyPatch = [];
        foreach ($map as $from => $to) {
            if (!array_key_exists($from, $switcher)) { continue; }
            $val = $switcher[$from];
            if (in_array($from, ['shortcode_enabled','menu_enabled','floating_enabled','show_poweredby'], true)) {
                $legacyPatch[$to] = (bool) $val;
            } else {
                $legacyPatch[$to] = is_string($val) ? $val : strval($val);
            }
        }
        yuz_settings_update(function (array $current) use ($legacyPatch) {
            $existing = isset($current['yuz_tra_settings']) && is_array($current['yuz_tra_settings'])
                ? $current['yuz_tra_settings']
                : [];
            $current['yuz_tra_settings'] = array_merge($existing, $legacyPatch);
            return $current;
        });
    }

    /**
     * Gets JS config.
     */
    public function get_js_config(): array {
        $all = self::runtime_flags(); // SSOT only

        $gen = $all['yuz_tra_general'] ?? [];
        $dl  = $gen['yuz_tra_default_language'] ?? ($gen['yuz_default_language'] ?? $this->languages->get_default_language());
        $sl  = $gen['yuz_tra_source_language']  ?? ($gen['yuz_source_language']  ?? $this->languages->get_source_language());
        $tl  = $gen['yuz_tra_translatable_languages'] ?? ($gen['yuz_translatable_languages'] ?? $this->languages->get_translatable_languages());

        $settings = [
            'yuz_tra_default_language' => $dl,
            'yuz_tra_source_language'  => $sl,
            'translation-languages'    => $tl,
            'url_to_load'              => $all['yuz_tra_settings']['url_to_load'] ?? home_url(),
            'yuz_shortcode_enabled'    => $all['yuz_tra_switcher']['shortcode_enabled'] ?? false,
        ];
        // Expose full site settings to JS (used by editor/site modules)
        $settings['site_settings'] = is_array($all['yuz_tra_site_settings'] ?? null) ? $all['yuz_tra_site_settings'] : [];

        return [
            // ⬇⬇⬇ ce que tes scripts attendent vraiment
            'ajax_url'            => admin_url('admin-ajax.php'),
            'rest_url'            => esc_url_raw( rest_url('yuz-tra/v1') ),
            'can_manage_options'  => current_user_can('manage_options'),
            'capabilities'        => ['can_manage_options' => current_user_can('manage_options')],
            // existant
            'settings' => $settings,
            'switcher' => $all['yuz_tra_switcher'] ?? [],
        ];
    }

    /**
     * Gets setting value (flags).
     */
    public function get(string $key): mixed {
        $flags = self::runtime_flags(); // Lire via flags (SSOT only)
        return $flags[$key] ?? null;
    }

    /**
     * Sets setting value (via update_option, only on submit).
     */
    public function set(string $key, mixed $value): void {
        $this->update_option($key, $value);
    }

    /**
     * Adds admin page.
     */
    public function add_admin_page() {
        // Page principale → route vers l’onglet "General"
        add_menu_page(
            __('YUZ-TRA', 'yuz-translation'),
            __('YUZ-TRA', 'yuz-translation'),
            'manage_options',
            'yuz-translation-settings',
            [$this, 'render_settings_page'],
            'dashicons-translation',
            80
        );
        $this->logger->log('success', 'Admin menu pages added for YUZ-TRA (router -> do_action per page)');
    }

    /**
     * Registers settings (SSOT).
     */
    public function register_settings() {
        if (!function_exists('yuz_settings_registry')) {
            $this->logger->log('critical', 'Cannot register settings: registry helper missing');
            return;
        }

        $registry = yuz_settings_registry();
        foreach ($registry as $canonical => $config) {
            $defaults = [];
            if (class_exists('YUZ_Options_Bridge') && method_exists('YUZ_Options_Bridge', 'get_defaults')) {
                $defaults = YUZ_Options_Bridge::get_defaults($canonical);
            } else {
                $defaults = yuz_settings_section_default($canonical);
            }

            // Enregistrer dans le groupe correspondant à la source d'écriture réelle
            $group = self::group_for_canonical($canonical);
            register_setting(
                $group,
                $canonical,
                [
                    'sanitize_callback' => function ($value) use ($canonical) {
                        return yuz_settings_sanitize_section($canonical, $value);
                    },
                    'default' => $defaults,
                ]
            );
        }

        $this->logger->log('info', 'Registered canonical settings options', ['count' => count($registry)]);
    }

    /**
     * Mappe un bucket canonique vers le groupe Settings API utilisé par l’UI.
     */
    private static function group_for_canonical(string $canonical): string {
        $general = ['yuz_tra_ws_settings', 'yuz_tra_ls_settings', 'yuz_tra_sw_settings'];
        if (in_array($canonical, $general, true)) {
            return 'yuz_tra_general_settings_group';
        }
        if ($canonical === 'yuz_tra_at_settings') {
            return 'yuz_tra_at_settings';
        }
        if ($canonical === 'yuz_tra_ts_settings') {
            return 'yuz_tra_ts_settings_group';
        }
        return 'yuz_tra_settings_group';
    }

    /**
     * Sanitizes all options (SSOT).
     */
    public function sanitize_all_options($value) {
        if ($this->in_sanitize_all) {
            // Déjà en cours → casse toute boucle
            return is_array($value) ? $value : [];
        }
        $this->in_sanitize_all = true;
        try {
            $in = is_array($value) ? $value : [];

            // même liste de sections que v398 (et +)
            $sections = [
                'yuz_tra_general',
                'yuz_tra_settings',
                'yuz_tra_switcher',
                'yuz_tra_site_settings',
                'yuz_tra_api_settings',
                'yuz_tra_advanced',
                'yuz_tra_addons',
                'yuz_tra_licenses',
                'yuz_tra_ai',
            ];

            $sanitized = [];
            foreach ($sections as $section) {
                $sanitized[$section] = $this->sanitize_option($section, $in[$section] ?? []);
            }
            return $sanitized;
        } finally {
            $this->in_sanitize_all = false;
        }
    }

    /**
     * Canonical migration that redistributes legacy options across the new settings schema.
     */
    public static function run_canonical_migration($logger = null): void {
        static $executed = false;
        if ($executed) { return; }
        $executed = true;

        // Accept truthy "enable logging" flags but guard against bare booleans.
        if ($logger === true) {
            $logger = class_exists('NullLogger') ? new NullLogger() : null;
        } elseif ($logger && (!is_object($logger) || !method_exists($logger, 'log'))) {
            $logger = null;
        }

        if (!function_exists('yuz_settings_registry')) {
            if ($logger) { $logger->log('warning', 'Migration skipped: settings registry unavailable'); }
            return;
        }

        $registry = yuz_settings_registry();
        if (empty($registry)) {
            if ($logger) { $logger->log('info', 'Migration skipped: empty registry'); }
            return;
        }

        $allLegacy = self::legacy_array(self::get_raw_option('yuz_tra_all_settings'));

        $generalRaw  = self::merge_precedence([
            self::get_raw_option('yuz_tra_general'),
            $allLegacy['yuz_tra_general'] ?? [],
        ]);
        $settingsRaw = self::merge_precedence([
            self::get_raw_option('yuz_tra_settings'),
            $allLegacy['yuz_tra_settings'] ?? [],
        ]);
        $switcherRaw = self::merge_precedence([
            self::get_raw_option('yuz_tra_switcher_settings'),
            self::get_raw_option('yuz_tra_switcher'),
            $allLegacy['yuz_tra_switcher'] ?? [],
        ]);
        $siteRaw = self::merge_precedence([
            self::get_raw_option('yuz_translation_site_settings'),
            self::get_raw_option('yuz_tra_site_settings'),
            $allLegacy['yuz_tra_site_settings'] ?? [],
        ]);
        if (empty($siteRaw['allowed_roles'])) {
            $siteRaw['allowed_roles'] = self::get_raw_option('yuz_tra_allowed_roles') ?: [];
        }
        $apiRaw = self::merge_precedence([
            self::get_raw_option('yuz_tra_api_settings'),
            $allLegacy['yuz_tra_api_settings'] ?? [],
            get_option('yuz_tra_at_settings', []),
        ]);
        if (!isset($apiRaw['url_to_load']) && isset($settingsRaw['url_to_load'])) {
            $apiRaw['url_to_load'] = $settingsRaw['url_to_load'];
        }
        if (!isset($apiRaw['source_language_id']) && isset($settingsRaw['source_language_id'])) {
            $apiRaw['source_language_id'] = $settingsRaw['source_language_id'];
        }
        if (!isset($apiRaw['api_adapter']) && isset($settingsRaw['api_adapter'])) {
            $apiRaw['api_adapter'] = $settingsRaw['api_adapter'];
        }

        $advancedRaw = self::merge_precedence([
            get_option('yuz_tra_advanced', []),
            $allLegacy['yuz_tra_advanced'] ?? [],
        ]);
        $addonsRaw   = self::pick_first_non_empty([
            $allLegacy['yuz_tra_addons'] ?? [],
            get_option('yuz_tra_addons', []),
        ]);
        $licensesRaw = self::pick_first_non_empty([
            $allLegacy['yuz_tra_licenses'] ?? [],
            get_option('yuz_tra_licenses', []),
        ]);
        $aiRaw       = self::merge_precedence([
            get_option('yuz_tra_ai', []),
            $allLegacy['yuz_tra_ai'] ?? [],
        ]);

        $lsRaw = [
            'native_language_name' => !empty($settingsRaw['native_language_name']),
            'use_subdirectory'     => !empty($settingsRaw['use_subdirectory']),
            'force_lang_in_links'  => !empty($settingsRaw['force_lang_in_links']),
        ];

        $desired = [
            'yuz_tra_ws_settings' => yuz_settings_sanitize_section('yuz_tra_ws_settings', $generalRaw),
            'yuz_tra_ls_settings' => yuz_settings_sanitize_section('yuz_tra_ls_settings', $lsRaw),
            'yuz_tra_sw_settings' => yuz_settings_sanitize_section('yuz_tra_sw_settings', $switcherRaw),
            'yuz_tra_ts_settings' => yuz_settings_sanitize_section('yuz_tra_ts_settings', $siteRaw),
            'yuz_tra_at_settings' => yuz_settings_sanitize_section('yuz_tra_at_settings', $apiRaw),
            'yuz_tra_av_settings' => yuz_settings_sanitize_section('yuz_tra_av_settings', $advancedRaw),
            'yuz_tra_ad_settings' => yuz_settings_sanitize_section('yuz_tra_ad_settings', $addonsRaw),
            'yuz_tra_li_settings' => yuz_settings_sanitize_section('yuz_tra_li_settings', $licensesRaw),
            'yuz_tra_ai_settings' => yuz_settings_sanitize_section('yuz_tra_ai_settings', $aiRaw),
        ];

        $preState = self::evaluate_canonical_sections($desired);
        if ($preState['match'] && $preState['has_data']) {
            if ($logger) { $logger->log('info', '✅ Canonical options already populated; skipping migration'); }
            return;
        }

        $updated = yuz_settings_update_all($desired);
        if ($updated) {
            yuz_settings_runtime_flush();

            $validated = true;
            foreach ($desired as $canonical => $expected) {
                $data = get_option($canonical, null);
                if (!is_array($data)) {
                    $validated = false;
                    if ($logger) {
                        $logger->log('warning', sprintf('⚠️ Section %s is missing or not an array during post-validation', $canonical));
                    }
                    break;
                }

                $normalized = yuz_settings_sanitize_section($canonical, $data);
                if ($normalized !== $expected) {
                    $validated = false;
                    if ($logger) {
                        $logger->log(
                            'warning',
                            sprintf('⚠️ Section %s differs from expected canonical data', $canonical),
                            [
                                'expected' => $expected,
                                'actual'   => $normalized,
                            ]
                        );
                    }
                    break;
                }
            }

            if ($validated) {
                delete_option('yuz_tra_all_settings');
                delete_option('yuz_tra_settings');
                if ($logger) {
                    $logger->log('success', '🎯 Canonical settings migration validated and cleaned up', ['sections' => array_keys($desired)]);
                }
            } elseif ($logger) {
                $logger->log('warning', '⚠️ Migration incomplete — legacy options kept for safety');
            }
        } elseif ($logger) {
            $logger->log('info', 'ℹ️ No canonical update detected; keeping legacy options');
        }
    }

    private static function legacy_array($value): array {
        if (is_array($value)) { return $value; }
        if (is_object($value)) { return (array) $value; }
        return [];
    }

    private static function merge_precedence(array $sources): array {
        $merged = [];
        foreach ($sources as $source) {
            $arr = self::legacy_array($source);
            if (empty($arr)) { continue; }
            $merged = array_replace($merged, $arr);
        }
        return $merged;
    }

    private static function pick_first_non_empty(array $sources): array {
        foreach ($sources as $source) {
            $arr = self::legacy_array($source);
            if (!empty($arr)) { return $arr; }
        }
        return [];
    }

    private static function get_raw_option(string $option_name) {
        global $wpdb;
        $raw = $wpdb->get_var(
            $wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $option_name)
        );
        if ($raw === null) {
            return [];
        }
        return maybe_unserialize($raw);
    }

    private static function evaluate_canonical_sections(array $desired): array {
        $allPresent = true;
        $match      = true;
        $hasData    = false;

        foreach ($desired as $canonical => $section) {
            $stored = get_option($canonical, '__missing__');
            if ($stored === '__missing__') {
                $allPresent = false;
                $match      = false;
                continue;
            }
            if (!is_array($stored)) {
                $match = false;
                continue;
            }

            $normalized = yuz_settings_sanitize_section($canonical, $stored);

            if ($normalized !== $section) {
                $match = false;
            }

            if (!$hasData && self::array_has_meaningful_data($normalized)) {
                $hasData = true;
            }
        }

        return [
            'all_present' => $allPresent,
            'match'       => $match,
            'has_data'    => $hasData,
        ];
    }

    private static function array_has_meaningful_data($value): bool {
        if (is_array($value)) {
            return count($value) > 0;
        }
        return $value !== null;
    }

    private static function payload_has_meaningful_values($value): bool {
        if (is_array($value)) {
            foreach ($value as $node) {
                if (self::payload_has_meaningful_values($node)) {
                    return true;
                }
            }
            return false;
        }
        if (is_bool($value)) {
            return $value === true;
        }
        if (is_numeric($value)) {
            return (float) $value !== 0.0;
        }
        if (is_string($value)) {
            return trim($value) !== '';
        }
        return !empty($value);
    }

    /**
     * Validates all canonical settings sections and returns a unified report.
     */
    public static function validate_all(): array {
        $sections = [
            'yuz_tra_ws_settings' => 'validate_ws',
            'yuz_tra_ls_settings' => 'validate_ls',
            'yuz_tra_sw_settings' => 'validate_sw',
            'yuz_tra_ts_settings' => 'validate_ts',
            'yuz_tra_at_settings' => 'validate_at',
            'yuz_tra_av_settings' => 'validate_av',
            'yuz_tra_ad_settings' => 'validate_ad',
            'yuz_tra_li_settings' => 'validate_li',
            'yuz_tra_ai_settings' => 'validate_ai',
        ];

        $report = [
            'success'  => true,
            'warnings' => [],
            'errors'   => [],
        ];

        $all = yuz_settings_get_all();
        foreach ($sections as $canonical => $method) {
            $data = isset($all[$canonical]) && is_array($all[$canonical]) ? $all[$canonical] : [];
            if (!method_exists(__CLASS__, $method)) {
                $report['warnings'][] = sprintf('%s: validator %s missing', $canonical, $method);
                continue;
            }

            $result = self::$method($data);
            foreach ($result['warnings'] as $warning) {
                $report['warnings'][] = sprintf('%s: %s', $canonical, $warning);
            }
            foreach ($result['errors'] as $error) {
                $report['errors'][] = sprintf('%s: %s', $canonical, $error);
            }
        }

        if (!empty($report['errors'])) {
            $report['success'] = false;
        }

        return $report;
    }

    private static function validate_ws(array $section): array {
        $errors = [];
        $warnings = [];

        if (empty($section['yuz_tra_default_language']) || !is_string($section['yuz_tra_default_language'])) {
            $errors[] = 'yuz_tra_default_language missing or invalid';
        }
        if (empty($section['yuz_tra_source_language']) || !is_string($section['yuz_tra_source_language'])) {
            $errors[] = 'yuz_tra_source_language missing or invalid';
        }

        if (!isset($section['yuz_tra_translatable_languages']) || !is_array($section['yuz_tra_translatable_languages'])) {
            $warnings[] = 'yuz_tra_translatable_languages not set; defaulting to empty array';
        } else {
            foreach ($section['yuz_tra_translatable_languages'] as $code) {
                if (!is_string($code) || $code === '') {
                    $warnings[] = 'yuz_tra_translatable_languages contains invalid entries';
                    break;
                }
            }
        }

        foreach (['yuz_tra_slug', 'yuz_tra_code'] as $listKey) {
            if (isset($section[$listKey]) && is_array($section[$listKey])) {
                foreach ($section[$listKey] as $value) {
                    if (!is_string($value)) {
                        $warnings[] = sprintf('%s contains non-string values', $listKey);
                        break;
                    }
                }
            }
        }

        $warnings = array_merge($warnings, self::diff_warnings('yuz_tra_ws_settings', $section));
        return self::validation_result($errors, $warnings);
    }

    private static function validate_ls(array $section): array {
        $errors = [];
        $warnings = [];
        $toggles = ['native_language_name','use_subdirectory','force_lang_in_links'];
        foreach ($toggles as $key) {
            if (!array_key_exists($key, $section)) {
                $warnings[] = sprintf('%s missing; assuming disabled', $key);
                continue;
            }
            if (!in_array($section[$key], ['', '1'], true)) {
                $errors[] = sprintf('%s must be an empty string or \"1\"', $key);
            }
        }
        $warnings = array_merge($warnings, self::diff_warnings('yuz_tra_ls_settings', $section));
        return self::validation_result($errors, $warnings);
    }

    private static function validate_sw(array $section): array {
        $errors = [];
        $warnings = [];
        $bools = ['shortcode_enabled','menu_enabled','floating_enabled','show_poweredby'];
        foreach ($bools as $key) {
            if (!array_key_exists($key, $section)) {
                $warnings[] = sprintf('%s missing; assuming false', $key);
                continue;
            }
            if (!is_bool($section[$key])) {
                $errors[] = sprintf('%s must be boolean', $key);
            }
        }
        $strings = ['shortcode_format','menu_format','floating_format','floating_theme','floating_position'];
        foreach ($strings as $key) {
            if (isset($section[$key]) && !is_string($section[$key])) {
                $warnings[] = sprintf('%s coerced to string', $key);
            }
        }
        $warnings = array_merge($warnings, self::diff_warnings('yuz_tra_sw_settings', $section));
        return self::validation_result($errors, $warnings);
    }

    private static function validate_ts(array $section): array {
        $errors = [];
        $warnings = [];
        $flags = ['enable_extra_languages','translate_seo','require_complete','menu_per_lang','browser_language_detect','enable_ai','enable_youzuruz'];
        foreach ($flags as $key) {
            if (!array_key_exists($key, $section)) {
                $warnings[] = sprintf('%s missing; assuming disabled', $key);
                continue;
            }
            if (!in_array($section[$key], ['1', '0'], true)) {
                $errors[] = sprintf('%s must be \"1\" or \"0\"', $key);
            }
        }
        if (empty($section['user_role_emulation']) || !is_string($section['user_role_emulation'])) {
            $errors[] = 'user_role_emulation missing or invalid';
        }
        if (empty($section['allowed_roles']) || !is_array($section['allowed_roles'])) {
            $errors[] = 'allowed_roles must be a non-empty array';
        } else {
            foreach ($section['allowed_roles'] as $role) {
                if (!is_string($role) || $role === '') {
                    $errors[] = 'allowed_roles contains invalid entries';
                    break;
                }
            }
        }
        if (isset($section['translation_mode']) && !in_array($section['translation_mode'], ['full', 'half'], true)) {
            $warnings[] = 'translation_mode will be coerced to a supported value';
        }
        $warnings = array_merge($warnings, self::diff_warnings('yuz_tra_ts_settings', $section));
        return self::validation_result($errors, $warnings);
    }

    private static function validate_at(array $section): array {
        $errors = [];
        $warnings = [];
        $intKeys = ['source_language_id','alternatives','char_limit','requests_limit'];
        foreach ($intKeys as $key) {
            if (isset($section[$key]) && (!is_int($section[$key]) || $section[$key] < 0)) {
                $errors[] = sprintf('%s must be a non-negative integer', $key);
            }
        }
        $boolKeys = ['enable_auto_translate','deepl_free','block_crawlers','log_queries'];
        foreach ($boolKeys as $key) {
            if (isset($section[$key]) && !is_bool($section[$key])) {
                $errors[] = sprintf('%s must be boolean', $key);
            }
        }
        $urlKeys = ['url_to_load','libre_url','custom_url'];
        foreach ($urlKeys as $key) {
            if (isset($section[$key]) && !is_string($section[$key])) {
                $warnings[] = sprintf('%s coerced to string', $key);
            }
        }
        $stringKeys = ['api_provider','api_adapter','libre_key','google_key','google_project','deepl_key','custom_key','custom_auth','custom_method','custom_format','cron_interval'];
        foreach ($stringKeys as $key) {
            if (isset($section[$key]) && !is_string($section[$key])) {
                $warnings[] = sprintf('%s coerced to string', $key);
            }
        }
        $warnings = array_merge($warnings, self::diff_warnings('yuz_tra_at_settings', $section));
        return self::validation_result($errors, $warnings);
    }

    private static function validate_av(array $section): array {
        $errors = [];
        $warnings = [];
        $keys = ['fix_dynamic_content','disable_dynamic_translation'];
        foreach ($keys as $key) {
            if (isset($section[$key]) && !is_bool($section[$key])) {
                $errors[] = sprintf('%s must be boolean', $key);
            }
        }
        $warnings = array_merge($warnings, self::diff_warnings('yuz_tra_av_settings', $section));
        return self::validation_result($errors, $warnings);
    }

    private static function validate_ad(array $section): array {
        $errors = [];
        $warnings = [];
        if (!is_array($section)) {
            $errors[] = 'value must be an array of addon identifiers';
            return self::validation_result($errors, $warnings);
        }
        foreach ($section as $value) {
            if (!is_string($value) || $value === '') {
                $errors[] = 'contains non-string addon identifiers';
                break;
            }
        }
        return self::validation_result($errors, $warnings);
    }

    private static function validate_li(array $section): array {
        $errors = [];
        $warnings = [];
        if (!is_array($section)) {
            $errors[] = 'value must be an array of license identifiers';
            return self::validation_result($errors, $warnings);
        }
        foreach ($section as $value) {
            if (!is_string($value) || $value === '') {
                $errors[] = 'contains non-string license values';
                break;
            }
        }
        return self::validation_result($errors, $warnings);
    }

    private static function validate_ai(array $section): array {
        $errors = [];
        $warnings = [];
        if (!array_key_exists('enabled', $section)) {
            $warnings[] = 'enabled missing; assuming false';
        } elseif (!is_bool($section['enabled'])) {
            $errors[] = 'enabled must be boolean';
        }
        $warnings = array_merge($warnings, self::diff_warnings('yuz_tra_ai_settings', $section));
        return self::validation_result($errors, $warnings);
    }

    private static function diff_warnings(string $canonical, array $current): array {
        $sanitized = yuz_settings_sanitize_section($canonical, $current);
        if ($sanitized !== $current) {
            return ['values will be normalised on next save'];
        }
        return [];
    }

    private static function validation_result(array $errors, array $warnings): array {
        return [
            'errors'   => $errors,
            'warnings' => $warnings,
        ];
    }

    /**
     * Default SSOT (étendu).
     */
    private function get_default_all_settings() {
        $defaults = [];
        $registry = function_exists('yuz_settings_registry') ? yuz_settings_registry() : [];

        foreach ($registry as $canonical => $config) {
            $sectionDefaults = yuz_settings_section_default($canonical);
            if ($canonical === 'yuz_tra_ws_settings') {
                $sectionDefaults['yuz_tra_default_language'] = $this->languages->get_default_language();
                $sectionDefaults['yuz_tra_source_language']  = $this->languages->get_source_language();
            }
            $defaults[$canonical] = $sectionDefaults;
            $defaults[$config['alias']] = $sectionDefaults;
        }

        return $defaults;
    }

    /**
     * Verifies settings.
     */
    private function verify_settings($s) {
        $err = [];
        if (empty($s['yuz_tra_default_language']))       { $err[] = 'Default language missing'; }
        if (empty($s['yuz_tra_source_language']))        { $err[] = 'Source language missing'; }
        if (empty($s['yuz_tra_translatable_languages'])) { $err[] = 'No translatable languages selected'; }
        return ['valid' => empty($err), 'errors' => $err];
    }

    public function render_settings_page() {
        // Slugs officiels
        $tabs = [
            'general'               => __('General', 'yuz-translation'),
            'translate-site'        => __('Translate Site', 'yuz-translation'),
            'strings'               => __('Strings', 'yuz-translation'),
            'automatic-translation' => __('Automatic Translation', 'yuz-translation'),
            'advanced'              => __('Advanced', 'yuz-translation'),
            'addons'                => __('Add-ons', 'yuz-translation'),
            'licenses'              => __('Licenses', 'yuz-translation'),
        ];
        // Back-compat : anciens slugs → officiels
        $aliases = [
            'translate_site'        => 'translate-site',
            'automatic'             => 'automatic-translation',
            'automatic_translation' => 'automatic-translation',
            'licences'              => 'licenses',
            'string'                => 'strings',
        ];

        $raw = isset($_GET['tab']) ? (string) $_GET['tab'] : 'general';
        $current = sanitize_key($raw);
        if (isset($aliases[$current])) { $current = $aliases[$current]; }
        if (!isset($tabs[$current]))   { $current = 'general'; }

        // Slug de page (doit matcher add_menu_page)
        $page = isset($_GET['page']) ? sanitize_key($_GET['page']) : 'yuz-translation-settings';

        echo '<div class="wrap yuz-admin-shell" id="yuz-settings">';
        require_once __DIR__ . '/class-yuz-release.php';
        YUZ_Release::render();

        $locale = function_exists('get_user_locale') ? get_user_locale() : get_locale();

        // essaie d'obtenir un renderer si dispo (propriété ou singleton)
        $renderer = (isset($this->renderer) && is_object($this->renderer))
            ? $this->renderer
            : ((class_exists('YUZ_Renderer') && method_exists('YUZ_Renderer', 'instance')) ? YUZ_Renderer::instance() : null);

        $rendered_toolbar = false;
        if ($renderer && method_exists($renderer, 'render_support_toolbar')) {
            try { $renderer->render_support_toolbar(['source'=>'settings-header','tab'=>$current,'locale'=>$locale]); $rendered_toolbar = true; }
            catch (\ArgumentCountError $e) { $renderer->render_support_toolbar($locale); $rendered_toolbar = true; }
            catch (\Throwable $e) { /* fallback */ }
        }

        if (!$rendered_toolbar) {
            echo '<div class="yuz-admin-toolbar" data-yuz-toolbar>'
               . '<a class="button button-secondary yuz-admin-toolbar__button yuz-admin-toolbar__button--secondary" target="_blank" rel="noopener noreferrer" href="https://youzurz.com/yuz-tra/support">' . esc_html__('Support', 'yuz-translation') . '</a>'
               . '<a class="button button-secondary yuz-admin-toolbar__button yuz-admin-toolbar__button--secondary" target="_blank" rel="noopener noreferrer" href="https://youzurz.com/yuz-tra/documentation/">'    . esc_html__('Documentation', 'yuz-translation') . '</a>'
               . '<a class="button button-primary yuz-admin-toolbar__button yuz-admin-toolbar__button--primary" target="_blank" rel="noopener noreferrer" href="https://youzurz.com/yuz-tra/pricing/">'. esc_html__('Free features and costs', 'yuz-translation') . '</a>'
               . '</div>';
        }

        echo '<h1>' . esc_html__('YUZ-TRA', 'yuz-translation') . '</h1>';

        // onglets
        echo '<h2 class="nav-tab-wrapper yuz-admin-tabs">';
        foreach ($tabs as $slug => $label) {
            $url = add_query_arg(['page' => $page, 'tab' => $slug], admin_url('admin.php'));
            printf(
                '<a href="%s" class="nav-tab%s">%s</a>',
                esc_url($url),
                ($current === $slug ? ' nav-tab-active' : ''),
                esc_html($label)
            );
        }
        echo '</h2>';

        // rendu du contenu d’onglet (via hooks) ou fallback
        $candidates = [];
        $candidates[] = 'yuz-tra_page_yuz-translation-' . $current;                        // officiel
        $candidates[] = 'yuz-tra_page_yuz-translation_' . str_replace('-', '_', $current); // legacy underscore
        $candidates[] = 'yuz-tra_page_yuz-translation-' . str_replace('_', '-', $current); // normalisation
        if ($current === 'automatic-translation') { $candidates[] = 'yuz-tra_page_yuz-translation-automatic'; }
        if ($current === 'translate-site')        { $candidates[] = 'yuz-tra_page_yuz-translation-translate_site'; }

        $handled = false;
        foreach ($candidates as $hook) {
            if (has_action($hook)) { do_action($hook); $handled = true; break; }
        }
        if (!$handled) {
            echo '<div class="notice notice-info"><p>'
               . esc_html__('This tab is not implemented yet.', 'yuz-translation')
               . '</p></div>';
        }

        echo '</div>'; // .wrap
    }

        /**
         * Initialise les réglages par défaut de YUZ Translation (remplace le ensure_default_settings() du Core).
         * Cette méthode ne fait AUCUNE migration destructive, elle ne fait qu’assurer la présence des clés minimales.
         */
        public static function ensure_defaults(): void {
            $defaults = [
                // Do NOT enable front switcher features from here (admin core).
                'yuz_floating_enabled' => false,
                'yuz_floating_format' => 'short-names',
                'yuz_floating_theme' => 'dark',
                'yuz_floating_position' => 'bottom-right',
                'yuz_menu_enabled' => false,
                'yuz_menu_format' => 'short-names',
                'yuz_shortcode_enabled' => false,
                'source_language_id' => 0,
                'api_adapter' => 'libretranslate',
            ];

            $option_name = 'yuz_tra_settings';
            $current = get_option($option_name, []);

            if (!is_array($current)) {
                $current = [];
            }

            if (empty($current)) {
                add_option($option_name, $defaults);
                if (function_exists('error_log')) {
                    error_log('🟩 YUZ_Settings: création de yuz_tra_settings (defaults).');
                }
                return;
            }

            $updated = array_merge($defaults, $current);
            if ($updated !== $current) {
                update_option($option_name, $updated, false);
                if (function_exists('error_log')) {
                    error_log('🟨 YUZ_Settings: ajout de clés manquantes dans yuz_tra_settings.');
                }
            }
        }
}
}

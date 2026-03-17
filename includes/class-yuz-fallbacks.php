<?php
/**
 * Class YUZ_Fallbacks
 * Centralizes fallback (null) implementations for interfaces in the YUZ-TRA plugin.
 *
 * @package YUZ_Translation
 */
/**
 * Progressive Centralization Plan:
 * This file serves as the central location for fallback implementations like NullLanguageManager.
 * To centralize progressively:
 * 1. In each file with a local fallback (e.g., anonymous class or local NullLanguageManager), replace it with: use YUZTRA\Fallbacks\NullLanguageManager; and instantiate new NullLanguageManager() where needed.
 * 2. Remove the local definition once replaced.
 * Files to update (based on current code):
 * - includes/class-yuz-assets.php: Replace anonymous class or local NullLanguageManager with centralized one.
 * - includes/class-yuz-translation-manager.php: Update NullLanguageManager to extend/use centralized version; align with LanguageManagerInterface if needed.
 * - includes/class-yuz-switcher.php: Replace NullLanguages with centralized NullLanguageManager if applicable.
 * - Any other files (e.g., class-yuz-services.php if exists): Check for local stubs and migrate.
 * After migration in a file, test for TypeErrors and remove old code.
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

namespace YUZTRA\Fallbacks;

defined('ABSPATH') or exit;

// Load global interface
require_once YUZ_TRA_INCLUDES . 'class-yuz-contracts.php';

use YUZTRA\Interfaces\LanguageManagerInterface;
use YUZTRA\Interfaces\LanguagesInterface;
use YUZTRA\Interfaces\TranslationManagerInterface;
use YUZTRA\Interfaces\SettingsInterface;
use YUZTRA\Interfaces\AjaxInterface;
use YUZTRA\Interfaces\UrlConverterInterface;
use YUZTRA\Interfaces\LoggerInterface;
use YUZTRA\Interfaces\TranslateAdapterInterface;
use YUZTRA\Interfaces\DBInterface;
use YUZTRA\Interfaces\Language;
use YUZTRA\Interfaces\EnvironmentInterface;
use YUZTRA\Interfaces\RendererInterface;

/** ---------------------------
 *  NullLanguageManager
 *  --------------------------- */
if (!class_exists('NullLanguageManager')) {
class NullLanguageManager implements LanguageManagerInterface {

    public function get_translatable_languages(): array {
        if (class_exists('\\YUZ_Logger')) {
            (new \YUZ_Logger())->log('warning', 'Languages unavailable, returning empty array', ['class' => __CLASS__]);
        }
        return [];
    }

    public function get_all_languages(): array {
        if (class_exists('\\YUZ_Logger')) {
            (new \YUZ_Logger())->log('warning', 'Languages unavailable, returning empty array', ['class' => __CLASS__]);
        }
        return [];
    }

    // Signature bool — doit matcher l'interface
    public function swap_source_and_target(string $new_source_code): bool {
        if (class_exists('\\YUZ_Logger')) {
            (new \YUZ_Logger())->log('warning', 'NullLanguageManager::swap_source_and_target no-op', ['new' => $new_source_code]);
        }
        return false; // no-op
    }

    public function get_source_language(): string {
        if (class_exists('\\YUZ_Logger')) {
            (new \YUZ_Logger())->log('warning', 'Returning default locale as source language', ['class' => __CLASS__]);
        }
        return get_locale();
    }

    public function enforce_language_rules($source = null, $default = null, $translatable = null): void {
        // no-op
    }
}
}

/** ---------------------------
 *  NullLanguages
 *  --------------------------- */
if (!class_exists('NullLanguages')) {
class NullLanguages extends NullLanguageManager implements LanguagesInterface {

    public function get_by_code(string $code): ?Language {
        if (class_exists('\\YUZ_Logger')) {
            (new \YUZ_Logger())->log('warning', "No language found for code {$code}", ['class' => __CLASS__]);
        }
        return null;
    }

    public function get_default_language(): string {
        if (class_exists('\\YUZ_Logger')) {
            (new \YUZ_Logger())->log('warning', 'Returning default locale as default language', ['class' => __CLASS__]);
        }
        return get_locale();
    }

    // Aligne la signature sur le parent (bool)
    public function swap_source_and_target(string $new_source_code): bool {
        return parent::swap_source_and_target($new_source_code);
    }
}
}

/** ---------------------------
 *  NullTranslationManager
 *  --------------------------- */
if (!class_exists('NullTranslationManager')) {
class NullTranslationManager implements TranslationManagerInterface {

    public function translate(string $text, int $source_lang_id, int $target_lang_id): ?string {
        if (class_exists('\\YUZ_Logger')) {
            (new \YUZ_Logger())->log('warning', 'TranslationManager unavailable, returning null', ['class' => __CLASS__]);
        }
        return null;
    }

    public function test_api_conn(array $settings): bool {
        if (class_exists('\\YUZ_Logger')) {
            (new \YUZ_Logger())->log('warning', 'Translate adapter unavailable, returning false', ['class' => __CLASS__]);
        }
        return false;
    }

    public function run_full_site_translation(): void {
        // no-op
    }

    public static function init(): void {
        // no-op
    }

    public function isConfigured(): bool {
        if (class_exists('\\YUZ_Logger')) {
            (new \YUZ_Logger())->log('warning', 'TranslationManager unavailable, returning false for isConfigured', ['class' => __CLASS__]);
        }
        return false;
    }
}
}

/** ---------------------------
 *  NullSettings
 *  --------------------------- */
if (!class_exists('NullSettings')) {
class NullSettings implements SettingsInterface {

    public function get_option(string $option_name) {
        $all = function_exists('yuz_settings_get_all') ? (array) yuz_settings_get_all() : [];
        if ($option_name === 'yuz_tra_all_settings') {
            return $all;
        }
        return $all[$option_name] ?? [];
    }

    public function sanitize_option(string $option_name, $value) {
        if (!function_exists('yuz_settings_sanitize_section')) {
            return is_array($value) ? $value : [];
        }
        return yuz_settings_sanitize_section($option_name, is_array($value) ? $value : []);
    }

    public function update_option(string $option_name, $value): bool {
        if (!function_exists('yuz_settings_replace_section')) {
            return false;
        }
        $payload = is_array($value) ? $value : [];
        return yuz_settings_replace_section($option_name, $payload);
    }

    public function get_js_config(): array {
        if (class_exists('YUZ_Translation_Manager') && method_exists('YUZ_Translation_Manager', 'build_frontend_settings')) {
            return (array) YUZ_Translation_Manager::build_frontend_settings();
        }
        return function_exists('yuz_settings_get_all') ? (array) yuz_settings_get_all() : [];
    }

    public function get(string $key): mixed {
        $all = $this->get_option('yuz_tra_all_settings');
        return $all[$key] ?? null;
    }

    public function set(string $key, mixed $value): void {
        $all = $this->get_option('yuz_tra_all_settings');
        if (!is_array($all)) {
            $all = [];
        }
        $all[$key] = $value;
        $this->update_option('yuz_tra_all_settings', $all);
    }

    /**
     * Added fallback to remove "Call to undefined method NullSettings::runtime_flags()"
     * and provide safe defaults on unmanaged environments.
     */
    public function runtime_flags(): array {
        if (class_exists('YUZ_Settings') && method_exists('YUZ_Settings', 'runtime_flags')) {
            return YUZ_Settings::runtime_flags();
        }
        $defaults = [
            'switcher_enabled'        => true,
            'editor_enabled'          => true,
            'adminbar_enabled'        => true,
            'dynamic_strings'         => false,
            'mode_manual_enabled'     => true,
            'mode_semi_enabled'       => false,
            'mode_background_enabled' => false,
        ];
        $stored = function_exists('yuz_settings_get_all') ? (array) yuz_settings_get_all() : [];
        return array_replace_recursive($defaults, $stored);
    }
}
}

/** ---------------------------
 *  NullAjax
 *  --------------------------- */
if (!class_exists('NullAjax')) {
class NullAjax implements AjaxInterface {

    public function __handleRequest($nonce, $required, $cb, $optional = [], $admin = false) {
        if (class_exists('\\YUZ_Logger')) {
            (new \YUZ_Logger())->log('warning', 'AJAX unavailable, sending error response', ['class' => __CLASS__]);
        }
        wp_send_json_error(['message' => 'AJAX unavailable']);
    }

    public function handleRequest(string $nonce_key, array $required_params, callable $action_callback, array $optional_params = [], bool $require_admin = false): void {
        if (class_exists('\\YUZ_Logger')) {
            (new \YUZ_Logger())->log('warning', "AJAX handler unavailable, returning error for nonce {$nonce_key}", ['class' => __CLASS__]);
        }
        wp_send_json([
            'success'   => false,
            'error'     => ['code' => 'ajax_unavailable', 'message' => 'AJAX handler unavailable', 'details' => ''],
            'timestamp' => current_time('mysql')
        ]);
    }

    public function registerEndpoints(): void {
        // no-op
    }
}
}

/** ---------------------------
 *  NullUrlConverter
 *  --------------------------- */
if (!class_exists('NullUrlConverter')) {
class NullUrlConverter implements UrlConverterInterface {

    public function cur_page_url(): string {
        if (class_exists('\YUZ_Logger')) {
            (new \YUZ_Logger())->log('warning', 'URL converter unavailable, returning home URL', ['class' => __CLASS__]);
        }
        return home_url();
    }

    public function get_active_locale(): string {
        return function_exists('get_locale') ? (string) get_locale() : 'en_US';
    }

    public function slug_for_locale(string $locale): string {
        $locale = function_exists('sanitize_text_field')
            ? sanitize_text_field($locale ?: '')
            : trim((string) $locale);
        return $locale === '' ? '' : strtolower(str_replace('_', '-', $locale));
    }

    public function get_url_for_language(string $lang_code, string $current_url, array $context = []): string {
        if (class_exists('\YUZ_Logger')) {
            (new \YUZ_Logger())->log('warning', 'URL converter unavailable, returning original URL', ['class' => __CLASS__]);
        }
        return $current_url;
    }

    public function toAbsolute(string $url): string { return $url; }
    public function toRelative(string $url): string { return $url; }
    public function normalize(string $url): string  { return $url; }
}
}

/** ---------------------------
 *  NullLogger
 *  --------------------------- */
if (!class_exists('NullLogger')) {
class NullLogger implements LoggerInterface {
    public function log(string $level, string $message, array $context = []): void {
        $enabled = (defined('YUZ_TRA_DEBUG') && YUZ_TRA_DEBUG) || (defined('WP_DEBUG') && WP_DEBUG);
        if (!$enabled) {
            return;
        }
        yuz_tra_release_error_log(sprintf('[%s] %s: %s', $level, __CLASS__, $message));
    }
    public function setLevel(string $level): void {
        // no-op
    }
}
}

/** ---------------------------
 *  NullTranslateAdapter
 *  --------------------------- */
if (!class_exists('NullTranslateAdapter')) {
class NullTranslateAdapter implements TranslateAdapterInterface {
    public function translate($text, $source_lang, $target_lang, $settings): ?string {
        if (class_exists('\\YUZ_Logger')) {
            (new \YUZ_Logger())->log('warning', 'Translate adapter unavailable, returning null', ['class' => __CLASS__]);
        }
        return null;
    }
    public function test_api_conn(array $settings): bool {
        if (class_exists('\\YUZ_Logger')) {
            (new \YUZ_Logger())->log('warning', 'Translate adapter unavailable, returning false', ['class' => __CLASS__]);
        }
        return false;
    }
}
}

/** ---------------------------
 *  FallbackTranslateProvider
 *  --------------------------- */
if (!class_exists('FallbackTranslateProvider')) {
class FallbackTranslateProvider {
    public static function translate_many(array $items, string $source, string $target, array $meta = []): array {
        $req_id = isset($meta['req_id']) ? sanitize_text_field((string) $meta['req_id']) : wp_generate_uuid4();
        $trace  = isset($meta['trace']) ? sanitize_text_field((string) $meta['trace']) : '';

        $is_canonical = static function ($code): bool {
            return $code === 'auto' || (is_string($code) && preg_match('/^[a-z]{2,3}$/', $code));
        };

        if (!$is_canonical($source) || !$is_canonical($target)) {
            $msg = sprintf('[YUZ][FALLBACK] non-canonical req=%s src=%s tgt=%s', $req_id, $source, $target);
            yuz_tra_release_error_log($msg);
            return new \WP_Error('yuz_non_canonical', $msg);
        }

        $normalized = [];
        foreach ($items as $row) {
            if (!is_array($row)) {
                continue;
            }
            $idx = isset($row['i']) ? (int) $row['i'] : null;
            if ($idx === null) {
                continue;
            }

            $normalized[$idx] = [
                'i'               => $idx,
                'translated_text' => '',
                'error'           => 'fallback_unavailable',
            ];
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            yuz_tra_release_error_log(sprintf('[YUZ][FALLBACK] req=%s src=%s tgt=%s count=%d RAW=%s',
                $req_id,
                $source,
                $target,
                count($normalized),
                substr(wp_json_encode($normalized, JSON_UNESCAPED_UNICODE), 0, 512)
            ));
        }

        return [
            'data' => [
                'results' => $normalized,
                'source'  => $source,
                'target'  => $target,
            ],
            'meta' => [
                'req_id'   => $req_id,
                'trace'    => $trace,
                'fallback' => true,
            ],
        ];
    }
}
}

/** ---------------------------
 *  NullDB
 *  --------------------------- */
if (!class_exists('NullDB')) {
class NullDB implements DBInterface {
    public function ensure_tables(): bool {
        if (class_exists('\\YUZ_Logger')) {
            (new \YUZ_Logger())->log('warning', 'Database unavailable, skipping table creation', ['class' => __CLASS__]);
        }
        return false;
    }
    public function store_translation($translation_data): bool {
        if (class_exists('\\YUZ_Logger')) {
            (new \YUZ_Logger())->log('warning', 'Database unavailable, cannot store translation', ['class' => __CLASS__]);
        }
        return false;
    }
    public static function init(): void { /* no-op */ }
    public function check_schema(): void { /* no-op */ }
    public function log_action(string $action, string $message, array $details = [], int $success = 1): void { /* no-op */ }
    public function query(string $sql): array { return []; }
    public function execute(string $sql): void { /* no-op */ }
}
}

/** ---------------------------
 *  NullEnvironment
 *  --------------------------- */
if (!class_exists('NullEnvironment')) {
class NullEnvironment implements EnvironmentInterface {
    public static function init(LanguagesInterface $lang_manager = null): void { /* no-op */ }
    public static function detect_user_environment(): void { /* no-op */ }
    public function getEnv(string $key): mixed {
        if (class_exists('\\YUZ_Logger')) {
            (new \YUZ_Logger())->log('warning', sprintf('Environment unavailable, returning null for key %s', $key), ['class' => __CLASS__]);
        }
        return null;
    }
    public function isProduction(): bool {
        if (class_exists('\\YUZ_Logger')) {
            (new \YUZ_Logger())->log('warning', 'Environment unavailable, returning false for isProduction', ['class' => __CLASS__]);
        }
        return false;
    }
}
}

/** ---------------------------
 *  NullRenderer
 *  --------------------------- */
if (!class_exists('NullRenderer')) {
class NullRenderer implements RendererInterface {
    public static function init(): void {
        // no-op
        if (class_exists('\\YUZ_Logger')) {
            (new \YUZ_Logger())->log('warning', 'Renderer unavailable, no-op for init', ['class' => __CLASS__]);
        }
    }
    public function render_tab(): void {
        // no-op
        if (class_exists('\\YUZ_Logger')) {
            (new \YUZ_Logger())->log('warning', 'Renderer unavailable, no-op for render_tab', ['class' => __CLASS__]);
        }
    }
    public function render_default_language_field(array $settings = []): void { // here (329)
        // no-op
        if (class_exists('\\YUZ_Logger')) {
            (new \YUZ_Logger())->log('warning', 'Renderer unavailable, no-op for render_default_language_field', ['class' => __CLASS__]);
        }
    }
    public function render_source_language_field(array $settings = []): void {
        // no-op
        if (class_exists('\\YUZ_Logger')) {
            (new \YUZ_Logger())->log('warning', 'Renderer unavailable, no-op for render_source_language_field', ['class' => __CLASS__]);
        }
    }
    public function render_translatable_languages_field(array $settings = []): void {
        // no-op
        if (class_exists('\\YUZ_Logger')) {
            (new \YUZ_Logger())->log('warning', 'Renderer unavailable, no-op for render_translatable_languages_field', ['class' => __CLASS__]);
        }
    }
    public function render_native_language_name_field(array $settings = []): void {
        // no-op
        if (class_exists('\\YUZ_Logger')) {
            (new \YUZ_Logger())->log('warning', 'Renderer unavailable, no-op for render_native_language_name_field', ['class' => __CLASS__]);
        }
    }
    public function render_use_subdirectory_field(array $settings = []): void {
        // no-op
        if (class_exists('\\YUZ_Logger')) {
            (new \YUZ_Logger())->log('warning', 'Renderer unavailable, no-op for render_use_subdirectory_field', ['class' => __CLASS__]);
        }
    }
    public function render_force_lang_in_links_field(array $settings = []): void {
        // no-op
        if (class_exists('\\YUZ_Logger')) {
            (new \YUZ_Logger())->log('warning', 'Renderer unavailable, no-op for render_force_lang_in_links_field', ['class' => __CLASS__]);
        }
    }
    public function render_shortcode_block(array $settings = []): void {
        // no-op
        if (class_exists('\\YUZ_Logger')) {
            (new \YUZ_Logger())->log('warning', 'Renderer unavailable, no-op for render_shortcode_block', ['class' => __CLASS__]);
        }
    }
    public function render_menu_item_block(array $settings = []): void {
        // no-op
        if (class_exists('\\YUZ_Logger')) {
            (new \YUZ_Logger())->log('warning', 'Renderer unavailable, no-op for render_menu_item_block', ['class' => __CLASS__]);
        }
    }
    public function render_floating_language_selection_block(array $settings = []): void {
        // no-op
        if (class_exists('\\YUZ_Logger')) {
            (new \YUZ_Logger())->log('warning', 'Renderer unavailable, no-op for render_floating_language_selection_block', ['class' => __CLASS__]);
        }
    }
    public function render_powered_by_block(array $settings = []): void {
        // no-op
        if (class_exists('\\YUZ_Logger')) {
            (new \YUZ_Logger())->log('warning', 'Renderer unavailable, no-op for render_powered_by_block', ['class' => __CLASS__]);
        }
    }
    public function render_translate_site_button(array $settings = []): void {
        // no-op
        if (class_exists('\\YUZ_Logger')) {
            (new \YUZ_Logger())->log('warning', 'Renderer unavailable, no-op for render_translate_site_button', ['class' => __CLASS__]);
        }
    }
    public function render_support_extra_languages_field(array $settings = []): void {
        // no-op
        if (class_exists('\\YUZ_Logger')) {
            (new \YUZ_Logger())->log('warning', 'Renderer unavailable, no-op for render_support_extra_languages_field', ['class' => __CLASS__]);
        }
    }
    public function render_youzuruz_ai_field(array $settings = []): void {
        // no-op
        if (class_exists('\\YUZ_Logger')) {
            (new \YUZ_Logger())->log('warning', 'Renderer unavailable, no-op for render_youzuruz_ai_field', ['class' => __CLASS__]);
        }
    }
    public function render_translate_seo_field(array $settings = []): void {
        // no-op
        if (class_exists('\\YUZ_Logger')) {
            (new \YUZ_Logger())->log('warning', 'Renderer unavailable, no-op for render_translate_seo_field', ['class' => __CLASS__]);
        }
    }
    public function render_publish_only_complete_field(array $settings = []): void {
        // no-op
        if (class_exists('\\YUZ_Logger')) {
            (new \YUZ_Logger())->log('warning', 'Renderer unavailable, no-op for render_publish_only_complete_field', ['class' => __CLASS__]);
        }
    }
    public function render_translate_by_role_field(array $settings = []): void {
        // no-op
        if (class_exists('\\YUZ_Logger')) {
            (new \YUZ_Logger())->log('warning', 'Renderer unavailable, no-op for render_translate_by_role_field', ['class' => __CLASS__]);
        }
    }
    public function render_menu_per_language_field(array $settings = []): void {
        // no-op
        if (class_exists('\\YUZ_Logger')) {
            (new \YUZ_Logger())->log('warning', 'Renderer unavailable, no-op for render_menu_per_language_field', ['class' => __CLASS__]);
        }
    }
    public function render_browser_language_detect_field(array $settings = []): void {
        // no-op
        if (class_exists('\\YUZ_Logger')) {
            (new \YUZ_Logger())->log('warning', 'Renderer unavailable, no-op for render_browser_language_detect_field', ['class' => __CLASS__]);
        }
    }
    public function render_block_browser_translation_field(array $settings = []): void {
        // no-op
        if (class_exists('\\YUZ_Logger')) {
            (new \YUZ_Logger())->log('warning', 'Renderer unavailable, no-op for render_block_browser_translation_field', ['class' => __CLASS__]);
        }
    }
    public function render_enable_auto_translation_field(array $settings = []): void {
        // no-op
        if (class_exists('\\YUZ_Logger')) {
            (new \YUZ_Logger())->log('warning', 'Renderer unavailable, no-op for render_enable_auto_translation_field', ['class' => __CLASS__]);
        }
    }
    public function render_translation_mode_field(array $settings = []): void {
        // no-op
        if (class_exists('\\YUZ_Logger')) {
            (new \YUZ_Logger())->log('warning', 'Renderer unavailable, no-op for render_translation_mode_field', ['class' => __CLASS__]);
        }
    }
    public function render_cron_interval_field(array $settings = []): void {
        // no-op
        if (class_exists('\\YUZ_Logger')) {
            (new \YUZ_Logger())->log('warning', 'Renderer unavailable, no-op for render_cron_interval_field', ['class' => __CLASS__]);
        }
    }
    public function render_api_provider_field(array $settings = []): void {
        // no-op
        if (class_exists('\\YUZ_Logger')) {
            (new \YUZ_Logger())->log('warning', 'Renderer unavailable, no-op for render_api_provider_field', ['class' => __CLASS__]);
        }
    }
    public function render_libretranslate_fields(array $settings = []): void {
        // no-op
        if (class_exists('\\YUZ_Logger')) {
            (new \YUZ_Logger())->log('warning', 'Renderer unavailable, no-op for render_libretranslate_fields', ['class' => __CLASS__]);
        }
    }
    public function render_alternatives_field(array $settings = []): void {
        // no-op
        if (class_exists('\\YUZ_Logger')) {
            (new \YUZ_Logger())->log('warning', 'Renderer unavailable, no-op for render_alternatives_field', ['class' => __CLASS__]);
        }
    }
    public function render_google_fields(array $settings = []): void {
        // no-op
        if (class_exists('\\YUZ_Logger')) {
            (new \YUZ_Logger())->log('warning', 'Renderer unavailable, no-op for render_google_fields', ['class' => __CLASS__]);
        }
    }
    public function render_deepl_fields(array $settings = []): void {
        // no-op
        if (class_exists('\\YUZ_Logger')) {
            (new \YUZ_Logger())->log('warning', 'Renderer unavailable, no-op for render_deepl_fields', ['class' => __CLASS__]);
        }
    }
    public function render_custom_fields(array $settings = []): void {
        // no-op
        if (class_exists('\\YUZ_Logger')) {
            (new \YUZ_Logger())->log('warning', 'Renderer unavailable, no-op for render_custom_fields', ['class' => __CLASS__]);
        }
    }
    public function render_char_limit_field(array $settings = []): void {
        // no-op
        if (class_exists('\\YUZ_Logger')) {
            (new \YUZ_Logger())->log('warning', 'Renderer unavailable, no-op for render_char_limit_field', ['class' => __CLASS__]);
        }
    }
    public function render_requests_limit_field(array $settings = []): void {
        // no-op
        if (class_exists('\\YUZ_Logger')) {
            (new \YUZ_Logger())->log('warning', 'Renderer unavailable, no-op for render_requests_limit_field', ['class' => __CLASS__]);
        }
    }
    public function render_block_crawlers_field(array $settings = []): void {
        // no-op
        if (class_exists('\\YUZ_Logger')) {
            (new \YUZ_Logger())->log('warning', 'Renderer unavailable, no-op for render_block_crawlers_field', ['class' => __CLASS__]);
        }
    }
    public function render_log_queries_field(array $settings = []): void {
        // no-op
        if (class_exists('\\YUZ_Logger')) {
            (new \YUZ_Logger())->log('warning', 'Renderer unavailable, no-op for render_log_queries_field', ['class' => __CLASS__]);
        }
    }
    public function render_test_api_connection(array $settings = []): void {
        // no-op
        if (class_exists('\\YUZ_Logger')) {
            (new \YUZ_Logger())->log('warning', 'Renderer unavailable, no-op for render_test_api_connection', ['class' => __CLASS__]);
        }
    }
    public function render_monitoring_dashboard(array $settings = []): void {
        // no-op
        if (class_exists('\\YUZ_Logger')) {
            (new \YUZ_Logger())->log('warning', 'Renderer unavailable, no-op for render_monitoring_dashboard', ['class' => __CLASS__]);
        }
    }
    public function render_advanced_tab(array $settings = []): void {
        // no-op
        if (class_exists('\\YUZ_Logger')) {
            (new \YUZ_Logger())->log('warning', 'Renderer unavailable, no-op for render_advanced_tab', ['class' => __CLASS__]);
        }
    }
    public function render_addons_tab(array $settings = []): void {
        // no-op
        if (class_exists('\\YUZ_Logger')) {
            (new \YUZ_Logger())->log('warning', 'Renderer unavailable, no-op for render_addons_tab', ['class' => __CLASS__]);
        }
    }
    public function render_licences_tab(array $settings = []): void {
        // no-op
        if (class_exists('\\YUZ_Logger')) {
            (new \YUZ_Logger())->log('warning', 'Renderer unavailable, no-op for render_licences_tab', ['class' => __CLASS__]);
        }
    }
}
}

/** ---------------------------
 *  Fallback adapters aliases
 *  --------------------------- */
if ( ! class_exists('YUZ_Custom_Translate_Adapter') ) {
class YUZ_Custom_Translate_Adapter extends NullTranslateAdapter {}
}
if ( ! class_exists('YUZ_Libre_Translate_Adapter') ) {
class YUZ_Libre_Translate_Adapter extends NullTranslateAdapter {}
}
if (!class_exists('\\YUZ_DeepL_Translate_Adapter')) {
class YUZ_DeepL_Translate_Adapter extends NullTranslateAdapter {}
}
if (!class_exists('\\YUZ_Google_Translate_Adapter')) {
class YUZ_Google_Translate_Adapter extends NullTranslateAdapter {}
}

/** ---------------------------
 *  Alias YUZ_Environment to fallback
 *  --------------------------- */
if (!class_exists('YUZ_Environment')) {
class YUZ_Environment extends NullEnvironment {}
}

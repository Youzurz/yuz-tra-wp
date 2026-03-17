<?php
/**
 * YUZ-TRA Plugin Global Interfaces and Fallbacks
 *
 * NOTE : on y « inlinera » ici l’interface GeneralInterface manquante,
 * afin qu’elle soit toujours disponible avant tout appel.
 * Centralise toutes les définitions d'interfaces pour l’injection de dépendances de l'extension YUZ Translation
 * et la sécurité de typage, ainsi que les implémentations fallback (null) pour ces interfaces.
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

namespace YUZTRA\Interfaces;
defined('ABSPATH') or exit;

// --- DÉCLARATION INLINE DE GeneralInterface ---
if (! interface_exists(GeneralInterface::class)) {
 /**
  * Interface générale pour l'initialisation du plugin.
  */
 interface GeneralInterface
 {
  /**
   * Méthode d'initialisation de base.
   *
   * @return void
   */
  public static function init(): void;
 }
}
// -----------------------------------------------

/**
 * Base Logger.
 * Used by all logging components.
 */
interface LoggerInterface {
public function log(string $level, string $message, array $context = []): void;
public function setLevel(string $level): void;
}
/**
 * Adapter for translation APIs (Google, DeepL, Libre, Custom…).
 */
interface TranslateAdapterInterface {
public function translate(string $text, string $source_lang, string $target_lang, array $settings): ?string;
public function test_api_conn(array $settings): bool;
}
/**
 * Provides language listings and lookup.
 */
interface LanguagesInterface {
public function get_default_language(): string;
public function get_source_language(): string;
public function get_translatable_languages(): array;
public function get_all_languages(): array;
public function get_by_code(string $code): ?Language;
/**
      * Enforce language language rules to prevent conflicts.
      *
      * @param mixed $source Code de la langue source (ou null pour laisser inchangé).
      * @param mixed $default Code de la langue par défaut (ou null pour laisser inchangé).
      * @param mixed $translatable Code de la langue translatable (ou null pour laisser inchangé).
      */
public function enforce_language_rules($source = null, $default = null, $translatable = null): void;
public function swap_source_and_target(string $new_source_code): bool;
}
/**
 * Manages source/target swap and rule enforcement only (thin wrapper).
 */
interface LanguageManagerInterface {
public function get_translatable_languages(): array;
public function get_all_languages(): array;
public function swap_source_and_target(string $new_source_code): bool;
public function get_source_language(): string;
/**
      * Enforce language rules to prevent conflicts.
      *
      * @param mixed $source Code of the source language (or null to skip).
      * @param mixed $default Code of the default language (or null to skip).
      * @param mixed $translatable Code of the translatable language (or null to skip).
      */
public function enforce_language_rules($source = null, $default = null, $translatable = null): void;
}
/**
 * Database abstraction for storing/fetching translations.
 */
interface DBInterface {
public function ensure_tables(): bool;
public function store_translation(array $translation_data): bool;
public static function init(): void;
public function check_schema(): void;
public function log_action(string $action, string $message, array $details = [], int $success = 1): void;
public function query(string $sql): array;
public function execute(string $sql): void;
}
/**
 * Handles plugin settings via WP options.
 */
interface SettingsInterface {
public function get_option(string $option_name);
public function sanitize_option(string $option_name, $value);
public function update_option(string $option_name, $value): bool;
public function get_js_config(): array;
public function get(string $key): mixed;
public function set(string $key, mixed $value): void;
}
/**
 * Gère le comportement du sélecteur de langue (shortcode, menu, flottant, ajax…).
 */
interface SwitcherInterface {
public static function init(): void;
public function render_shortcode_switcher(array $atts): string;
public function enqueue_scripts(): void;
public function add_menu_switcher(string $items, object $args): string;
public function render_floating_switcher(): void;
public function add_hreflang_tags(): void;
public function yuz_tra_sw_switch_language(): void;
public function switchLanguage(string $lang_code): void;
public function getAvailableLanguages(): array;
public function render_shortcode(array $atts): string;
public function add_menu_item(string $items, object $args): string;
}
/**
 * Converts URLs between languages, absolute/relative, normalizes.
 */
interface UrlConverterInterface {
public function cur_page_url(): string;
public function get_active_locale(): string;
public function slug_for_locale(string $locale): string;
public function get_url_for_language(string $lang_code, string $current_url, array $context = []): string;
public function toAbsolute(string $url): string;
public function toRelative(string $url): string;
public function normalize(string $url): string;
}
/**
 * Health check utility for invariants.
 */
interface HealthCheckInterface {
public static function ensure(bool $condition, string $message, string $method): void;
public function report(): array;
}
/**
 * Core translation manager (batch/full-site).
 */
interface TranslationManagerInterface {
public static function init(): void;
public function isConfigured(): bool;
public function translate(string $text, int $source_lang_id, int $target_lang_id): ?string;
public function test_api_conn(array $settings);
public function run_full_site_translation(): void;
}
/**
 * Service layer for AJAX translation requests.
 */
interface ServicesInterface {
public function handle_translation_request(string $text, string $source_lang, string $target_lang): ?string;
public function yuz_tra_sv_handle_translation(): void;
public function request(array $params): ?string;
public function register(): void;
public function execute(string $service, array $params): mixed;
}
/**
 * Rewrite rules manager.
 */
interface RewriteInterface {
public function register_rewrite_rules(): void;
public function add_query_vars(array $vars): array;
public function force_lang_in_links(): void;
public function maybe_flush_rules(): void;
public function ajax_flush_rules(): void;
public function applyRules(array $rules): string;
}
/**
 * Renders admin UI (tabs, fields…).
 */
interface RendererInterface {
public static function init(): void;
public function render_tab(): void;
public function render_translate_site_button(array $settings = []): void;
public function render_support_extra_languages_field(array $settings = []): void;
public function render_youzuruz_ai_field(array $settings = []): void;
public function render_translate_seo_field(array $settings = []): void;
public function render_publish_only_complete_field(array $settings = []): void;
public function render_translate_by_role_field(array $settings = []): void;
public function render_menu_per_language_field(array $settings = []): void;
public function render_browser_language_detect_field(array $settings = []): void;
public function render_block_browser_translation_field(array $settings = []): void;
public function render_default_language_field(array $settings = []): void;
public function render_source_language_field(array $settings = []): void;
public function render_translatable_languages_field(array $settings = []): void;
public function render_native_language_name_field(array $settings = []): void;
public function render_use_subdirectory_field(array $settings = []): void;
public function render_force_lang_in_links_field(array $settings = []): void;
public function render_shortcode_block(array $settings = []): void;
public function render_menu_item_block(array $settings = []): void;
public function render_floating_language_selection_block(array $settings = []): void;
public function render_powered_by_block(array $settings = []): void;
public function render_advanced_tab(array $settings = []): void;
public function render_addons_tab(array $settings = []): void;
public function render_licences_tab(array $settings = []): void;
public function render_enable_auto_translation_field(array $settings = []): void;
public function render_translation_mode_field(array $settings = []): void;
public function render_cron_interval_field(array $settings = []): void;
public function render_api_provider_field(array $settings = []): void;
public function render_libretranslate_fields(array $settings = []): void;
public function render_alternatives_field(array $settings = []): void;
public function render_google_fields(array $settings = []): void;
public function render_deepl_fields(array $settings = []): void;
public function render_custom_fields(array $settings = []): void;
public function render_char_limit_field(array $settings = []): void;
public function render_requests_limit_field(array $settings = []): void;
public function render_block_crawlers_field(array $settings = []): void;
public function render_log_queries_field(array $settings = []): void;
public function render_test_api_connection(array $settings = []): void;
public function render_monitoring_dashboard(array $settings = []): void;
}
/**
 * AJAX handler abstraction.
 */
interface AjaxInterface {
public function handleRequest(
string $nonce_key,
array $required_params,
callable $action_callback,
array $optional_params = [],
bool $require_admin = false
     ): void;
public function registerEndpoints(): void;
}
/**
 * Assets enqueuing (scripts, styles).
 */
interface AssetsInterface {
public static function init(): void;
public function enqueue_admin_scripts(string $hook): void;
public function enqueue_front_scripts(): void;
public function add_module_type(string $tag, string $handle, string $src): string; // Changed from void to string
public function add_yuz_tra_settings_hash(array $hashes): array; // Changed from void to array
}
/**
 * Automatic / batch translation runner.
 */
interface AutomaticTranslationInterface {
public static function init(): void;
public function enqueue_scripts(string $hook): void;
public function render_tab(): void;
public function run_batch(): void;
public function translateBatch(array $items): array;
}
/**
* --- IA: stub minimal pour éviter les fatals ---
*/
namespace YUZTRA\Interfaces;

if (!interface_exists(__NAMESPACE__ . '\\AIInterface')) {
    interface AIInterface {
        public static function init(): void;
    }
}

/**
 * Plugin bootstrap.
 */
interface CoreInterface {
public static function init(): void;
}
/**
 * Cron scheduler for periodic jobs.
 */
interface CronInterface {
public function run_batch(): void;
public function schedule(string $event): void;
public function clearSchedule(): void;
}
/**
 * CSV import functionality for languages.
 */
interface CsvImporterInterface {
public function add_import_page(): void;
public function render_import_form(): void;
public function handle_import(): void;
public function import_csv(string $file_path): bool;
public function import(string $path): array;
public function validateRow(array $row): bool;
}
/**
 * User environment detection.
 */
interface EnvironmentInterface {
public static function init(LanguagesInterface $lang_manager = null): void;
public static function detect_user_environment(): void;
public function getEnv(string $key): mixed;
public function isProduction(): bool;
}
/**
 * Frontend rendering integration.
 */
interface FrontendInterface {
public static function init(?LanguageManagerInterface $languageManager = null, ?AssetsInterface $assets = null): void;
public function render(): void;
}
/**
 * In-page editor integration.
 */
interface EditorInterface {
public function renderEditor(): void;
public function saveTranslation(array $data): bool;
}
/**
 * Represents a language entity.
 */
interface Language {
public function get_code(): string;
public function get_name(): string;
public function get_native_name(): ?string;
public function getLocale(): string;
}
/**
 * PDCA (Plan-Do-Check-Act) manager.
 */
interface PDCAInterface {
public function plan(): void;
public function do(): void;
public function check(): bool;
public function act(): void;
public static function run_all(): void;
}
/**
 * Admin‐bar integration.
 */
 interface AdminBarInterface {
   /** WordPress passe WP_Admin_Bar en 1er argument */
   public function add_admin_items($wp_admin_bar): void;
 }

/**
 * Add-ons manager.
 */
interface AddonsInterface {
public function register_addons(array $addons): void;
public function loadAddon(string $addon): bool;
}
/**
 * Advanced tab controller.
 */
interface AdvancedInterface {
public static function init(): void;
public function enqueue_scripts(string $hook): void;
public function render_advanced_tab(): void;
}
/**
 * Site‐wide translation controller.
 */
interface SiteTranslationInterface {
public function translate_site(): void;
public function translatePage(): string;
public function setTargetLanguage(string $lang): void;
}

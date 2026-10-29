<?php
/**
 * Class YUZ_Automatic_Translation
 * Manages the Automatic Translation tab in the YUZ-TRA admin interface.
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

// Move all use statements before requires
use YUZTRA\Interfaces\AutomaticTranslationInterface;
use YUZTRA\Interfaces\SettingsInterface;
use YUZTRA\Interfaces\LanguagesInterface;
use YUZTRA\Interfaces\TranslationManagerInterface;
use YUZTRA\Interfaces\AjaxInterface;
use YUZTRA\Interfaces\LoggerInterface;
use YUZTRA\Interfaces\RendererInterface;

use YUZTRA\Fallbacks\NullAjax;
use YUZTRA\Fallbacks\NullSettings;
use YUZTRA\Fallbacks\NullRenderer;
use YUZTRA\Fallbacks\NullLogger;
use YUZTRA\Fallbacks\NullTranslationManager;
use YUZTRA\Fallbacks\NullLanguages;
use YUZTRA\Fallbacks\NullLanguageManager;
use YUZTRA\Fallbacks\NullTranslateAdapter;
use YUZTRA\Fallbacks\NullHealthCheck;
use YUZTRA\Fallbacks\NullDB;

require_once YUZTRA_INCLUDES . 'class-yuz-contracts.php';
require_once YUZTRA_INCLUDES . 'class-yuz-fallbacks.php';


class YUZTRA_Automatic_Translation implements AutomaticTranslationInterface {
    private $translation_manager;
    private $settings;
    private $languages;
    private $logger;
    private $ajax;
    private $renderer;

    public function __construct(
        AjaxInterface $ajax,
        SettingsInterface $settings,
        RendererInterface $renderer,
        LoggerInterface $logger,
        TranslationManagerInterface $translation_manager,
        LanguagesInterface $languages = null
    ) {
        $this->ajax = $ajax;
        $this->settings = $settings;
        $this->renderer = $renderer;
        $this->logger = $logger;
        $this->translation_manager = $translation_manager;
        $this->languages = $languages ?: new NullLanguages();

        if (method_exists($this->translation_manager, 'init')) {
            $this->translation_manager->init();
        }
        $this->logger->log('info', 'YUZTRA_Automatic_Translation instantiated with dependencies');
    }

    private static bool $booted = false;

    /**
     * Initializes the class.
     */
    public static function init(): void {
        if (self::$booted) {
            return;
        }
        self::$booted = true;

        // Gardes minimales
        if (!defined('YUZTRA_INCLUDES') || !defined('YUZTRA_PLUGIN_FILE')) {
            yuztra_debug_log('🟥 [CRITICAL] YUZ-TRA: required constants missing — halting YUZTRA_Automatic_Translation::init');
            wp_die(esc_html__('Critical error: YUZ-TRA constants missing.', 'yuz-tra'));
        }

        // 1) Logger / Health / DB
        $logger = class_exists('YUZTRA_Logger') ? new YUZTRA_Logger() : new NullLogger();
        $health_check = class_exists('YUZTRA_Health_Check') ? new YUZTRA_Health_Check($logger) : new NullHealthCheck();
        $db = class_exists('YUZTRA_DB') ? new YUZTRA_DB($logger, $health_check) : new NullDB();

        // 2) Languages pour TM/Settings
        $languages_for_tm = class_exists('YUZTRA_Languages')
            ? new YUZTRA_Languages(new NullSettings(), $db)
            : new NullLanguages();

        // A single provider factory for every editor.
        $translation_manager = YUZTRA_Services::tm();

        // 4) Language Manager (si dispo)
        $language_manager = class_exists('YUZTRA_Language_Manager')
            ? new YUZTRA_Language_Manager()
            : new NullLanguageManager();

        // 5) Ajax pour l’UI
        $ajax = class_exists('YUZTRA_Ajax')
            ? new YUZTRA_Ajax($translation_manager, $language_manager, $db) // Fixed: pass $db
            : new NullAjax();

        // 6) Languages pour Settings
        $languages = class_exists('YUZTRA_Languages')
            ? new YUZTRA_Languages(new NullSettings(), $db)
            : new NullLanguages();

        // 7) Settings & Renderer (complets pour l'UI)
        $settings = class_exists('YUZTRA_Settings')
            ? new YUZTRA_Settings($languages, $ajax, $translation_manager, $language_manager, $logger)
            : new NullSettings();

        $renderer = class_exists('YUZTRA_Renderer')
            ? new YUZTRA_Renderer($ajax, $settings, $logger, $languages)
            : new NullRenderer();

        // 8) Instanciation du contrôleur + hooks
        $instance = new self($ajax, $settings, $renderer, $logger, $translation_manager, $languages);

        // Hooks de page (toutes variantes acceptées)
        $hooks = [
            'yuz-tra_page_yuz-translation-automatic-translation',
            'yuz-tra_page_yuz-translation-automatic',
            // compat vieux routeur si présent encore quelque part :
            'yuz-tra_page_yuz-translation-settings_automatic-translation',
        ];

        foreach ($hooks as $hk) {
            add_action($hk, [$instance, 'render_tab']);
        }

        $logger->log('info', 'Initializing YUZTRA_Automatic_Translation');

        // (B) Enqueue conditionnel
        if (method_exists($instance, 'enqueue_scripts')) {
            add_action('admin_enqueue_scripts', [$instance, 'enqueue_scripts']);
        }

        // (C) AJAX bouton "Run automatic batch"
        if (method_exists($instance, 'ajax_run_batch')) {
            add_action('wp_ajax_yuztra_at_run_batch', [$instance, 'ajax_run_batch']);
        }

        // (D) Worker CRON
        if (!has_action('yuztra_auto_translation_tick')) {
            add_action('yuztra_auto_translation_tick', function () use ($instance, $translation_manager, $logger) {
                if (method_exists($instance, 'run_batch')) {
                    $instance->run_batch();
                    return;
                }
                if (method_exists($translation_manager, 'run_full_site_translation')) {
                    $translation_manager->run_full_site_translation();
                    return;
                }
                $logger->log('warning', 'No runner available for automatic translation tick');
            });
        }

        $logger->log('success', 'YUZTRA_Automatic_Translation initialized (hooked to yuz-tra_page_yuz-translation-automatic)');
    }

    /**
     * Lightweight queue used by API manager as a generic entry point.
     * Schedules the background tick that will call run_batch() via action hook.
     */
    public static function queue_full_site(): bool {
        try {
            $settings = YUZTRA_Translation_Budget::settings();
            if (empty($settings['enable_auto_translate']) || !in_array($settings['translation_mode'] ?? '', ['silent','all','auto'],true) || !has_action('yuztra_auto_translation_tick')) return false;
            if (!wp_next_scheduled('yuztra_auto_translation_tick')) {
                $scheduled = wp_schedule_single_event(time() + 1, 'yuztra_auto_translation_tick', [], true);
                if (is_wp_error($scheduled) || !$scheduled) return false;
            }
            if (class_exists('YUZTRA_Logger')) {
                (new YUZTRA_Logger())->log('info', '[AUTO] queue_full_site scheduled yuztra_auto_translation_tick');
            }
            return true;
        } catch (\Throwable $e) {
            if (class_exists('YUZTRA_Logger')) {
                (new YUZTRA_Logger())->log('warning', '[AUTO] queue_full_site failed', ['error' => $e->getMessage()]);
            }
            return false;
        }
    }

    private static function handle_ajax_batch(): void {
        if (!defined('DOING_AJAX') || !DOING_AJAX) {
            return;
        }
        // Explicit load of the translation manager
        $manager_file = YUZTRA_INCLUDES . 'class-yuz-api-manager.php';
        if (file_exists($manager_file)) {
            require_once $manager_file;
            if (class_exists('YUZTRA_API_Manager')) {
                YUZTRA_API_Manager::init();
            }
        }
    }

    // Les assets de l'onglet "Automatic translation" sont désormais gérés par YUZ_Assets.
    public function enqueue_scripts(string $hook): void {
        if (isset($this->logger)) {
            $this->logger->log('info', 'enqueue_scripts delegated to YUZTRA_Assets');
        }
        return;
    }

    /**
     * Renders the Automatic Translation tab.
     */
    public function render_tab(): void {
        $can_translate = class_exists('YUZTRA_Capabilities')
            ? \YUZTRA_Capabilities::user_is_translator()
            : (current_user_can('manage_options') || current_user_can('yuztra_translate_content'));
        if (!$can_translate) {
            $this->logger->log('critical', 'User lacks yuztra_translate_content capability in YUZTRA_Automatic_Translation::render_tab');
            wp_die(esc_html__('Unauthorized', 'yuz-tra'));
        }

        // Classic POST fallback for form submits (prevents raw JSON echo)
        if (sanitize_key(wp_unslash($_SERVER['REQUEST_METHOD'] ?? '')) === 'POST' && isset($_POST['nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'yuztra_con_nonce')) {
            if (!current_user_can('manage_options')) wp_die(esc_html__('Unauthorized','yuz-tra'));
            $this->logger->log('info', 'Processing POST submit in YUZTRA_Automatic_Translation::render_tab');
            $input = isset($_POST['yuztra_at_settings']) && is_array($_POST['yuztra_at_settings'])
                ? map_deep(wp_unslash($_POST['yuztra_at_settings']), 'sanitize_text_field')
                : [];

            if (!empty($input)) {
                $settings = $this->settings->sanitize_option('yuztra_at_settings', $input);
                $ok = $this->settings->update_option('yuztra_at_settings', $settings);

                if ($ok) {
                    $this->translation_manager->set_api_settings($settings);
                    YUZTRA_Cron::reconcile_schedule();

                    echo '<div class="updated"><p>'.esc_html__('Translation settings saved.', 'yuz-tra').'</p></div>';
                } else {
                    echo '<div class="error"><p>'.esc_html__('Failed to update API settings', 'yuz-tra').'</p></div>';
                }
            }
        }

        // Récupération robuste des réglages: consolide + fallback standalone
        $settings = $this->settings->get_option('yuztra_at_settings');
        if (!is_array($settings)) { $settings = []; }

        $standalone_api = get_option('yuztra_at_settings', []);
        if (is_array($standalone_api) && !empty($standalone_api)) {
            // Le standalone prime pour l'affichage si le consolidé est vide/ancien
            $settings = array_replace($settings, $standalone_api);
        }
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Automatic Translation', 'yuz-tra'); ?></h1>

            <?php if (isset($_GET['settings-updated']) && $_GET['settings-updated'] === 'true'): ?>
                <div class="updated"><p><?php esc_html_e('Translation settings saved.', 'yuz-tra'); ?></p></div>
            <?php endif; ?>

            <form method="post" action="options.php">
            <?php
            // Doit correspondre au premier paramètre de register_setting(...)
            // register_setting('yuz_tra_at_settings', 'yuz_tra_at_settings', ...)
            settings_fields('yuztra_at_settings');

            // (Facultatif) afficher d’éventuels messages de settings_errors()
            settings_errors();
            ?>

            <?php
            // Ces render_* doivent sortir des inputs avec name="yuz_tra_at_settings[...]" !
            $this->renderer->render_translation_mode_field($settings);     // ex: name="yuz_tra_at_settings[translation_mode]"
            $this->renderer->render_cron_interval_field($settings);        // ex: name="yuz_tra_at_settings[cron_interval]"
            $this->renderer->render_enable_auto_translation_field($settings); // ex: name="yuz_tra_at_settings[enable_auto_translate]"
            ?>

            <div class="yuz-section">
                <h2 class="yuz-section-title"><?php esc_html_e('API Settings', 'yuz-tra'); ?></h2>
                <hr>
                <table class="form-table">
                    <?php
                    YUZTRA_Health_Check::ensure(
                        method_exists('YUZTRA_Renderer', 'render_api_provider_field'),
                        'Renderer method missing: render_api_provider_field',
                        __METHOD__
                    );

                    // ⚠️ Tous ces champs doivent aussi utiliser le même option_name :
                    // name="yuz_tra_at_settings[libre_url]" etc.
                    $this->renderer->render_api_provider_field($settings);
                    $this->renderer->render_libretranslate_fields($settings);
                    $this->renderer->render_alternatives_field($settings);
                    $this->renderer->render_google_fields($settings);
                    $this->renderer->render_deepl_fields($settings);
                    $this->renderer->render_custom_fields($settings);
                    $this->renderer->render_char_limit_field($settings);
                    $this->renderer->render_requests_limit_field($settings);
                    $this->renderer->render_block_crawlers_field($settings);
                    $this->renderer->render_log_queries_field($settings);
                    ?>
                </table>
            </div>

            <?php submit_button(__('Save Changes', 'yuz-tra')); ?>
        </form>
        </div>
        <?php
        $this->logger->log('success', 'Finished YUZTRA_Automatic_Translation::render_tab');
        $this->renderer->render_test_api_connection($settings);
        $this->renderer->render_monitoring_dashboard($settings);
    }

    /**
     * Runs the batch translation process.
     */
    public function run_batch(): void {
        $result=YUZTRA_Cron::run_batch();
        $this->logger->log(empty($result['failed']) ? 'info' : 'warning','Silent batch result',$result);
    }

    public function ajax_run_batch() {
        check_ajax_referer('yuztra_hvy_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(['message'=>'forbidden'],403);
        $settings=YUZTRA_Translation_Budget::settings();
        if (empty($settings['enable_auto_translate']) || !in_array($settings['translation_mode'] ?? 'manual',['silent','all','auto'],true)) wp_send_json_error(['message'=>'Enable silent translation in settings before queuing a batch.'],400);
        if (!self::queue_full_site()) wp_send_json_error(['message'=>'batch_schedule_failed'],503);
        wp_send_json_success(['status'=>'queued','scheduled_at'=>wp_next_scheduled('yuztra_auto_translation_tick'),
            'message' => __('Automatic translation queued. It will run in background.', 'yuz-tra')]);
    }

    /**
     * Translates a batch of items.
     *
     * @param array $items
     * @return array
     */
    public function translateBatch(array $items): array {
        $results = [];
        foreach ($items as $item) {
            if (!is_array($item) || !isset($item['text'], $item['source'], $item['target'])) {
                throw new InvalidArgumentException('batch_requires_text_source_target');
            }
            $results[] = YUZTRA_Services::translate_entrypoint($item);
        }
        return $results;
    }
}

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

defined('ABSPATH') or exit;

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

require_once YUZ_TRA_INCLUDES . 'class-yuz-contracts.php';
require_once YUZ_TRA_INCLUDES . 'class-yuz-fallbacks.php';

class YUZ_Automatic_Translation implements AutomaticTranslationInterface {
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
        $this->logger->log('info', 'YUZ_Automatic_Translation instantiated with dependencies');
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
        if (!defined('YUZ_TRA_INCLUDES') || !defined('YUZ_TRA_PLUGIN_FILE')) {
            error_log('🟥 [CRITICAL] YUZ-TRA: required constants missing — halting YUZ_Automatic_Translation::init');
            wp_die(__('Critical error: YUZ-TRA constants missing.', 'yuz_translation'));
        }

        // 1) Logger / Health / DB
        $logger = class_exists('YUZ_Logger') ? new YUZ_Logger() : new NullLogger();
        $health_check = class_exists('YUZ_Health_Check') ? new YUZ_Health_Check($logger) : new NullHealthCheck();
        $db = class_exists('YUZ_DB') ? new YUZ_DB($logger, $health_check) : new NullDB();

        // 2) Languages pour TM/Settings
        $languages_for_tm = class_exists('YUZ_Languages')
            ? new YUZ_Languages(new NullSettings(), $db)
            : new NullLanguages();

        // 3) TM + adaptateurs (avec Settings minimal pour respecter la signature)
        if (class_exists('YUZ_API_Manager')) {
            $adapters = [
                'libretranslate' => class_exists('YUZ_Libre_Translate_Adapter') ? new YUZ_Libre_Translate_Adapter() : new NullTranslateAdapter(),
                'deepl'          => class_exists('YUZ_DeepL_Translate_Adapter') ? new YUZ_DeepL_Translate_Adapter() : new NullTranslateAdapter(),
                'google'         => class_exists('YUZ_Google_Translate_Adapter') ? new YUZ_Google_Translate_Adapter() : new NullTranslateAdapter(),
                'custom'         => class_exists('YUZ_Custom_Translate_Adapter') ? new YUZ_Custom_Translate_Adapter() : new NullTranslateAdapter(),
            ];
            $ajax_stub = new NullAjax();

            // Settings minimal pour le TM (évite de passer YUZ_Languages en #2)
            $settings_for_tm = class_exists('YUZ_Settings')
                ? new YUZ_Settings($languages_for_tm, $ajax_stub, new NullTranslationManager(), new NullLanguageManager(), $logger)
                : new NullSettings();

            // Signature: ($adapters, $settings, $languages, $ajax, $db, $logger)
            $translation_manager = new YUZ_API_Manager(
                $adapters,
                $settings_for_tm,
                $languages_for_tm,
                $ajax_stub,
                $db,
                $logger
            );
        } else {
            $translation_manager = new NullTranslationManager();
        }

        // 4) Language Manager (si dispo)
        $language_manager = class_exists('YUZ_Language_Manager')
            ? new YUZ_Language_Manager()
            : new NullLanguageManager();

        // 5) Ajax pour l’UI
        $ajax = class_exists('YUZ_Ajax')
            ? new YUZ_Ajax($translation_manager, $language_manager, $db) // Fixed: pass $db
            : new NullAjax();

        // 6) Languages pour Settings
        $languages = class_exists('YUZ_Languages')
            ? new YUZ_Languages(new NullSettings(), $db)
            : new NullLanguages();

        // 7) Settings & Renderer (complets pour l'UI)
        $settings = class_exists('YUZ_Settings')
            ? new YUZ_Settings($languages, $ajax, $translation_manager, $language_manager, $logger)
            : new NullSettings();

        $renderer = class_exists('YUZ_Renderer')
            ? new YUZ_Renderer($ajax, $settings, $logger, $languages)
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

        $logger->log('info', 'Initializing YUZ_Automatic_Translation');

        // (B) Enqueue conditionnel
        if (method_exists($instance, 'enqueue_scripts')) {
            add_action('admin_enqueue_scripts', [$instance, 'enqueue_scripts']);
        }

        // (C) AJAX bouton "Run automatic batch"
        if (method_exists($instance, 'ajax_run_batch')) {
            add_action('wp_ajax_yuz_tra_at_run_batch', [$instance, 'ajax_run_batch']);
        }

        // (D) Worker CRON
        if (!has_action('yuz_auto_translation_tick')) {
            add_action('yuz_auto_translation_tick', function () use ($instance, $translation_manager, $logger) {
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

        $logger->log('success', 'YUZ_Automatic_Translation initialized (hooked to yuz-tra_page_yuz-translation-automatic)');
    }

    /**
     * Lightweight queue used by API manager as a generic entry point.
     * Schedules the background tick that will call run_batch() via action hook.
     */
    public static function queue_full_site(): bool {
        try {
            if (!wp_next_scheduled('yuz_auto_translation_tick')) {
                wp_schedule_single_event(time() + 1, 'yuz_auto_translation_tick');
            }
            if (class_exists('YUZ_Logger')) {
                (new YUZ_Logger())->log('info', '[AUTO] queue_full_site scheduled yuz_auto_translation_tick');
            }
            return true;
        } catch (\Throwable $e) {
            if (class_exists('YUZ_Logger')) {
                (new YUZ_Logger())->log('warning', '[AUTO] queue_full_site failed', ['error' => $e->getMessage()]);
            }
            return false;
        }
    }

    private static function handle_ajax_batch(): void {
        if (!defined('DOING_AJAX') || !DOING_AJAX) {
            return;
        }
        // Explicit load of the translation manager
        $manager_file = YUZ_TRA_INCLUDES . 'class-yuz-api-manager.php';
        if (file_exists($manager_file)) {
            require_once $manager_file;
            if (class_exists('YUZ_API_Manager')) {
                YUZ_API_Manager::init();
            }
        }
    }

    // Les assets de l'onglet "Automatic translation" sont désormais gérés par YUZ_Assets.
    public function enqueue_scripts(string $hook): void {
        if (isset($this->logger)) {
            $this->logger->log('info', 'enqueue_scripts delegated to YUZ_Assets');
        }
        return;
    }

    /**
     * Renders the Automatic Translation tab.
     */
    public function render_tab(): void {
        $can_translate = class_exists('YUZ_Capabilities')
            ? \YUZ_Capabilities::user_is_translator()
            : (current_user_can('manage_options') || current_user_can('yuz_translate_content'));
        if (!$can_translate) {
            $this->logger->log('critical', 'User lacks yuz_translate_content capability in YUZ_Automatic_Translation::render_tab');
            wp_die(esc_html__('Unauthorized', 'yuz_translation'));
        }

        // Classic POST fallback for form submits (prevents raw JSON echo)
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['nonce']) && wp_verify_nonce($_POST['nonce'], 'yuz_con_nonce')) {
            $this->logger->log('info', 'Processing POST submit in YUZ_Automatic_Translation::render_tab');
            $input = isset($_POST['yuz_tra_at_settings']) && is_array($_POST['yuz_tra_at_settings'])
                ? (array) wp_unslash($_POST['yuz_tra_at_settings'])
                : [];

            if (!empty($input)) {
                $settings = $this->settings->sanitize_option('yuz_tra_at_settings', $input);
                $ok = $this->settings->update_option('yuz_tra_at_settings', $settings);

                if ($ok) {
                    // Avoid fatal if TM stub lacks the method
                    if (is_object($this->translation_manager) && method_exists($this->translation_manager, 'set_api_settings')) {
                        $this->translation_manager->set_api_settings([
                            'api_type'  => $settings['api_provider'],
                            'endpoint'  => $settings[$settings['api_provider'] . '_url'] ?? $settings['custom_url'],
                            'api_key'   => $settings[$settings['api_provider'] . '_key'] ?? $settings['custom_key'],
                            'extra_settings' => [
                                'alternatives'    => $settings['alternatives'],
                                'deepl_free'      => $settings['deepl_free'],
                                'google_project'  => $settings['google_project'],
                                'custom_auth'     => $settings['custom_auth'],
                                'custom_method'   => $settings['custom_method'],
                                'custom_format'   => $settings['custom_format']
                            ]
                        ]);
                    }

                    wp_clear_scheduled_hook('yuz_tra_batch_translate');

                    if (in_array($settings['translation_mode'], ['silent', 'all'], true)) {
                        wp_schedule_event(time(), $settings['cron_interval'], 'yuz_tra_batch_translate');
                        $this->logger->log('info', 'Rescheduled WP-Cron event yuz_tra_batch_translate with interval: ' . $settings['cron_interval']);
                    }

                    echo '<div class="updated"><p>'.esc_html__('Translation settings saved.', 'yuz_tra').'</p></div>';
                } else {
                    echo '<div class="error"><p>'.esc_html__('Failed to update API settings', 'yuz_tra').'</p></div>';
                }
            }
        }

        // Récupération robuste des réglages: consolide + fallback standalone
        $settings = $this->settings->get_option('yuz_tra_at_settings');
        if (!is_array($settings)) { $settings = []; }

        $standalone_api = get_option('yuz_tra_at_settings', []);
        if (is_array($standalone_api) && !empty($standalone_api)) {
            // Le standalone prime pour l'affichage si le consolidé est vide/ancien
            $settings = array_replace($settings, $standalone_api);
        }
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Automatic Translation', 'yuz_translation'); ?></h1>

            <?php if (isset($_GET['settings-updated']) && $_GET['settings-updated'] === 'true'): ?>
                <div class="updated"><p><?php esc_html_e('Translation settings saved.', 'yuz_tra'); ?></p></div>
            <?php endif; ?>

            <form method="post" action="options.php">
            <?php
            // Doit correspondre au premier paramètre de register_setting(...)
            // register_setting('yuz_tra_at_settings', 'yuz_tra_at_settings', ...)
            settings_fields('yuz_tra_at_settings');

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
                <h2 class="yuz-section-title"><?php esc_html_e('API Settings', 'yuz_translation'); ?></h2>
                <hr>
                <table class="form-table">
                    <?php
                    YUZ_Health_Check::ensure(
                        method_exists('YUZ_Renderer', 'render_api_provider_field'),
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

            <?php submit_button(__('Save Changes', 'yuz_translation')); ?>
        </form>
        </div>
        <?php
        $this->logger->log('success', 'Finished YUZ_Automatic_Translation::render_tab');
        $this->renderer->render_test_api_connection($settings);
        $this->renderer->render_monitoring_dashboard($settings);
    }

    /**
     * Runs the batch translation process.
     */
    public function run_batch(): void {
        $this->logger->log('info', 'Running silent translation batch at ' . current_time('mysql'));

        YUZ_Health_Check::ensure(
            method_exists($this->translation_manager, 'run_full_site_translation'),
            'Translation manager method missing: run_full_site_translation',
            __METHOD__
        );

        $success = $this->translation_manager->run_full_site_translation();

        if ($success) {
            $this->logger->log('success', 'Batch translation completed');
        } else {
            $this->logger->log('error', 'Batch translation failed or partial');
        }
    }

    public function ajax_run_batch() {
        check_ajax_referer('yuz_hvy_nonce', 'nonce');
        if (!wp_next_scheduled('yuz_auto_translation_tick')) {
            wp_schedule_single_event(time() + 1, 'yuz_auto_translation_tick');
        }
        wp_send_json_success(['message' => __('Automatic translation queued. It will run in background.', 'yuz_translation')]);
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
            // you may want to delegate to your translation manager here:
            // $results[] = $this->translation_manager->translate(...);
            $results[] = $item; // stub: return the item unmodified
        }
        return $results;
    }
}

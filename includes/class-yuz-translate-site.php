<?php
/**
 * Class YUZ_Translate_Site
 * Manages the Translate Site tab in the YUZ-TRA admin interface.
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
use YUZTRA\Interfaces\SiteTranslationInterface;
use YUZTRA\Interfaces\AjaxInterface;
use YUZTRA\Interfaces\SettingsInterface;
use YUZTRA\Interfaces\RendererInterface;
use YUZTRA\Interfaces\LoggerInterface;
use YUZTRA\Interfaces\TranslationManagerInterface;

use YUZTRA\Fallbacks\NullAjax;
use YUZTRA\Fallbacks\NullSettings;
use YUZTRA\Fallbacks\NullRenderer;
use YUZTRA\Fallbacks\NullLogger;
use YUZTRA\Fallbacks\NullTranslationManager;
use YUZTRA\Fallbacks\NullTranslateAdapter;
use YUZTRA\Fallbacks\NullLanguages;
use YUZTRA\Fallbacks\NullLanguageManager;
use YUZTRA\Fallbacks\NullHealthCheck;
use YUZTRA\Fallbacks\NullDB;

require_once YUZ_TRA_INCLUDES . 'class-yuz-contracts.php';
require_once YUZ_TRA_INCLUDES . 'class-yuz-fallbacks.php';

if (!class_exists('YUZ_Translate_Site')) {

class YUZ_Translate_Site implements SiteTranslationInterface {

    private $ajax;
    private $settings;
    private $renderer;
    private $logger;
    private $translation_manager;

    /**
     * Constructor to inject dependencies.
     *
     * @param AjaxInterface $ajax
     * @param SettingsInterface $settings
     * @param RendererInterface $renderer
     * @param LoggerInterface $logger
     * @param TranslationManagerInterface $translation_manager
     */
    public function __construct(
        AjaxInterface $ajax = null,
        SettingsInterface $settings = null,
        RendererInterface $renderer = null,
        LoggerInterface $logger = null,
        TranslationManagerInterface $translation_manager = null
    ) {
        $this->ajax = $ajax ?? new NullAjax();
        $this->settings = $settings ?? new NullSettings();
        $this->renderer = $renderer ?? new NullRenderer();
        $this->logger = $logger ?? new NullLogger();
        $this->translation_manager = $translation_manager ?? new NullTranslationManager();

        $this->logger->log('info', 'YUZ_Translate_Site instantiated with dependencies');
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
            error_log('🟥 [CRITICAL] YUZ-TRA: required constants missing — halting YUZ_Translate_Site::init at ' . (function_exists('current_time') ? current_time('mysql') : gmdate('Y-m-d H:i:s')));
            wp_die(esc_html__('Critical error: YUZ-TRA constants missing.', 'yuz-tra'));
        }

        // 1) Logger / Health / DB
        $logger = class_exists('YUZ_Logger') ? new YUZ_Logger() : new NullLogger();
        $health_check = class_exists('YUZ_Health_Check') ? new YUZ_Health_Check($logger) : new NullHealthCheck();
        $db = class_exists('YUZ_DB') ? new YUZ_DB($logger, $health_check) : new NullDB();

        // 2) Languages pour TM/Settings
        $languages_for_tm = class_exists('YUZ_Languages')
            ? new YUZ_Languages(new NullSettings(), $db)
            : new NullLanguages();

        // A single provider factory for every editor.
        $translation_manager = YUZ_Services::tm();

        // 4) Language Manager (réel si dispo, sinon fallback)
        $language_manager = class_exists('YUZ_Language_Manager')
            ? new YUZ_Language_Manager()
            : new NullLanguageManager();

        // 5) Ajax de l’onglet
        $ajax = class_exists('YUZ_Ajax')
            ? new YUZ_Ajax($translation_manager, $language_manager, $db) // Fixed: pass $db
            : new NullAjax();

        // 6) Languages pour Settings (tu peux réutiliser $languages_for_tm si tu veux)
        $languages = class_exists('YUZ_Languages')
            ? new YUZ_Languages(new NullSettings(), $db)
            : new NullLanguages();

        // 7) Settings & Renderer (version complète)
        $settings = class_exists('YUZ_Settings')
            ? new YUZ_Settings($languages, $ajax, $translation_manager, $language_manager, $logger)
            : new NullSettings();

        $renderer = class_exists('YUZ_Renderer')
            ? new YUZ_Renderer($ajax, $settings, $logger, $languages)
            : new NullRenderer();

        // 8) Instanciation du contrôleur + hooks
        $instance = new self($ajax, $settings, $renderer, $logger, $translation_manager);

        $instance->logger->log('info', 'Initializing YUZ_Translate_Site at ' . (function_exists('current_time') ? current_time('mysql') : gmdate('Y-m-d H:i:s')));

        // (A) Rendu de l’onglet "Translate Site" (routeur par page)
        add_action('yuz-tra_page_yuz-translation-translate-site', [$instance, 'render_tab']);

        // (B) Enqueue conditionnel pour cet onglet
// LEGACY→YUZ_Assets:         add_action('admin_enqueue_scripts', [$instance, 'enqueue_scripts']);

        // (C) Admin bar — unifié dans YUZ_Admin_Bar (évite les doublons)
        // add_action('admin_bar_menu', [$instance, 'add_admin_bar_menu'], 100, 1);

        // (D) AJAX handled centrally by YUZ_Ajax; avoid duplicate registration here

        // (E) Worker CRON pour exécuter en tâche de fond
        if (!has_action('yuz_continue_translation')) {
            add_action('yuz_continue_translation', function () use ($translation_manager) {
                if (method_exists($translation_manager, 'run_full_site_translation')) {
                    $translation_manager->run_full_site_translation();
                }
            });
        }

        $instance->logger->log('success', 'YUZ_Translate_Site initialized successfully (hooked to yuz-tra_page_yuz-translation-translate-site)');
    }

    /**
     * Logs a message with context.
     *
     * @param string $level Log level.
     * @param string $message Log message.
     * @param array $context Additional context.
     */
    private function log($level, $message, $context = []) {
        $this->logger->log($level, $message, array_merge($context, ['class' => __CLASS__]));
    }

    /**
     * Enqueues scripts for the admin interface.
     *
     * @param string $hook Admin page hook.
     */
    public function enqueue_scripts($hook) {
        $this->log('info', 'enqueue_scripts delegated to YUZ_Assets');
        return;
    }

    /**
     * Lance la traduction du site complet.
     */
    public function translate_site(): void {
        $this->logger->log('info', 'Starting full site translation in YUZ_Translate_Site::translate_site');

        YUZ_Health_Check::ensure(
            method_exists($this->translation_manager, 'run_full_site_translation'),
            'Translation manager method missing: run_full_site_translation',
            __METHOD__
        );

        $this->translation_manager->run_full_site_translation();
        $this->logger->log('success', 'Full site translation initiated');
    }

    /**
     * Traduit la page courante et renvoie le contenu traduit.
     *
     * @return string
     */
    public function translatePage(): string {
        $this->logger->log('info', 'Translating current page in YUZ_Translate_Site::translatePage');

        YUZ_Health_Check::ensure(
            method_exists($this->translation_manager, 'translate_current_page'),
            'Translation manager method missing: translate_current_page',
            __METHOD__
        );

        $translated_content = $this->translation_manager->translate_current_page();

        if (empty($translated_content)) {
            $this->logger->log('warning', 'No content returned for current page translation');
            return '';
        }

        $this->logger->log('success', 'Current page translated successfully');
        return $translated_content;
    }

    /**
     * Définit la langue cible pour les traductions suivantes.
     *
     * @param string $lang Code langue cible (ex : 'fr', 'en', …)
     */
    public function setTargetLanguage(string $lang): void {
        $this->logger->log('info', "Setting target language to {$lang} in YUZ_Translate_Site::setTargetLanguage");

        $sanitized_lang = $this->settings->sanitize_option('yuz_tra_target_language', $lang);
        $success = $this->settings->update_option('yuz_tra_target_language', $sanitized_lang);

        if ($success) {
            $this->logger->log('success', "Target language set to {$sanitized_lang}");
        } else {
            $this->logger->log('error', "Failed to set target language to {$sanitized_lang}");
        }
    }

    /**
     * Renders the Translate Site tab.
     */
    public function render_tab() {
        $can_translate = class_exists('YUZ_Capabilities')
            ? \YUZ_Capabilities::user_is_translator()
            : (current_user_can('manage_options') || current_user_can('yuz_translate_content'));
        if ( ! $can_translate ) {
            $this->log('critical', 'User lacks yuz_translate_content capability in YUZ_Translate_Site::render_tab');
            wp_die(esc_html__('Unauthorized', 'yuz-tra'));
        }

        // Server-side POST fallback (in addition to AJAX) for persistence when JS is disabled
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'yuz_con_nonce')) {
            $this->log('info', 'Processing POST submit for Translate Site settings');

            $input = isset($_POST['yuz_tra_ts_settings']) && is_array($_POST['yuz_tra_ts_settings'])
                ? (array) wp_unslash($_POST['yuz_tra_ts_settings'])
                : [];

            if (!empty($input)) {
                $settings = $this->settings->sanitize_option('yuz_tra_ts_settings', $input);

                $ok = true;
                if (function_exists('yuz_settings_update_all')) {
                    $ok = (bool) yuz_settings_update_all(['yuz_tra_ts_settings' => $settings]);
                } else {
                    $ok = $this->get_settings()->update_option('yuz_tra_ts_settings', $settings);
                }

                if ($ok) {
                    delete_option('yuz_tra_site_settings');
                    $canonical = function_exists('yuz_settings_get_all') ? (array) (yuz_settings_get_all()['yuz_tra_ts_settings'] ?? []) : [];
                    $this->log('success', 'Translate Site settings saved via POST', [
                        'submitted' => $settings,
                        'canonical_after' => $canonical,
                    ]);
                    echo '<div class="updated"><p>'.esc_html__('Translate Site settings saved.', 'yuz-tra').'</p></div>';
                } else {
                    $this->log('error', 'Translate Site POST save failed', ['submitted' => $settings]);
                    echo '<div class="error"><p>'.esc_html__('Failed to save settings.', 'yuz-tra').'</p></div>';
                }
            } else {
                $this->log('warning', 'Translate Site POST with empty payload');
            }
        }

        // Récupération robuste: consolide + fallback standalone (legacy)
        $settings = $this->settings->get_option('yuz_tra_ts_settings');
        if (!is_array($settings)) { $settings = []; }
        if (function_exists('yuz_settings_sanitize_section')) {
            $settings = yuz_settings_sanitize_section('yuz_tra_ts_settings', $settings);
        }

        // Legacy/standalone option name parfois utilisée par l’UI ou imports
        $legacy = get_option('yuz_translation_site_settings', []);
        if (is_array($legacy) && !empty($legacy)) {
            if (function_exists('yuz_settings_sanitize_section')) {
                $legacy = yuz_settings_sanitize_section('yuz_tra_ts_settings', $legacy);
            }
            foreach ($legacy as $key => $value) {
                if (!array_key_exists($key, $settings) || $settings[$key] === '' || $settings[$key] === null) {
                    $settings[$key] = $value;
                }
            }
        }
        $alias    = get_option('yuz_tra_site_settings', []);
        $this->log('info', 'Translate Site render settings', [
            'canonical' => $settings,
            'alias'     => $alias,
        ]);
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Translate Site', 'yuz-tra'); ?></h1>

            <?php if (isset($_GET['settings-updated']) && $_GET['settings-updated'] === 'true'): ?>
                <div class="updated"><p><?php esc_html_e('Translate Site settings saved.', 'yuz-tra'); ?></p></div>
            <?php endif; ?>

            <form id="yuz-translate-site-form" method="post" action="" data-yuz-ts-action="update_settings">
                <?php wp_nonce_field('yuz_con_nonce', 'nonce'); ?>

                <?php
                $this->log('info', 'Rendering Translate Now button in YUZ_Translate_Site::render_tab');
                $this->renderer->render_translate_site_button();
                ?>

                <div class="yuz-section">
                    <h2 class="yuz-section-title"><?php esc_html_e('Options', 'yuz-tra'); ?></h2>
                    <hr>
                    <table class="form-table">
                        <?php
                        YUZ_Health_Check::ensure(
                            method_exists($this->renderer, 'render_support_extra_languages_field'),
                            'Renderer method missing: render_support_extra_languages_field',
                            __METHOD__
                        );
                        $this->log('info', 'Rendering Support Extra Languages field');
                        $this->renderer->render_support_extra_languages_field($settings);

                        $this->log('info', 'Rendering YoUZuruz AI field');
                        $this->renderer->render_youzuruz_ai_field($settings);

                        $this->log('info', 'Rendering Translate SEO field');
                        $this->renderer->render_translate_seo_field($settings);

                        // New: permissions by role (sync caps on save via AJAX)
                        $this->log('info', 'Rendering Allowed Roles field');
                        $this->renderer->render_allowed_roles_field($settings);

                        $this->log('info', 'Rendering Publish Only Complete field');
                        $this->renderer->render_publish_only_complete_field($settings);
                        // NOTE: Ne garder qu’un seul champ de rôles côté UI.
                        // Le champ « Allowed roles for translation » suffit; on supprime le doublon
                        // « Translate by User Role » pour éviter la confusion.

                        $this->log('info', 'Rendering Menu per Language field');
                        $this->renderer->render_menu_per_language_field($settings);

                        $this->log('info', 'Rendering Browser Language Detect field');
                        $this->renderer->render_browser_language_detect_field($settings);

                        $this->log('info', 'Rendering Block Browser Translation field');
                        $this->renderer->render_block_browser_translation_field($settings);

                        $this->log('info', 'Finished rendering fields in YUZ_Translate_Site::render_tab');
                        ?>
                    </table>
                </div>

                <?php submit_button(__('Save Changes', 'yuz-tra')); ?>
            </form>
        </div>
        <?php
        $this->log('info', 'Finished rendering Translate Site tab');
    }

    /**
     * Handles AJAX request to start full site translation.
     *
     * @return void
     */
    public function ajax_start_full_translation() {
        check_ajax_referer('yuz_hvy_nonce', 'nonce');
        $this->log('info', 'Queue full site translation (AJAX)');

        if (!wp_next_scheduled('yuz_continue_translation')) {
            wp_schedule_single_event(time() + 1, 'yuz_continue_translation');
        }

        wp_send_json_success(['message' => __('Translation queued. It will run in background.', 'yuz-tra')]);
    }

    /**
     * Adds Translate Now to admin bar (sans inline JS — CSP compliant).
     *
     * @param WP_Admin_Bar $wp_admin_bar Admin bar instance.
     */
    public function add_admin_bar_menu( $wp_admin_bar ) {
    if ( ! is_user_logged_in() || ! is_admin_bar_showing() ) {
        return;
    }
    if ( ! current_user_can( 'yuz_translate_content' ) ) {
        $this->log( 'warning', 'User lacks yuz_translate_content capability for admin bar menu' );
        return;
    }

    $this->log( 'info', 'Adding Translate Now to admin bar (no inline onclick)' );

    // URL front courante, sinon Home depuis /wp-admin
    $scheme      = is_ssl() ? 'https' : 'http';
    $host        = $_SERVER['HTTP_HOST'] ?? wp_parse_url( home_url(), PHP_URL_HOST );
    $uri         = sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'] ?? '/'));
    $current_url = $scheme . '://' . $host . $uri;

    $target = is_admin() ? home_url( '/' ) : $current_url;
    // Purge toute langue héritée (évite un en_US forcé)
    $target     = remove_query_arg( [ 'yuz-target-lang', 'target_lang' ], $target );
    $editor_url = add_query_arg( [ 'yuz-edit-translation' => 1 ], $target );

    $wp_admin_bar->add_node( [
        'id'    => 'yuz-translate-now',
        'title' => __( 'Translate Now', 'yuz-tra' ),
        'href'  => $editor_url,
        'meta'  => [
            'class'    => 'yuz-translate-now',
            'title'    => __( 'Start translation for this page', 'yuz-tra' ),
            'tabindex' => -1,
        ],
    ] );

    // Sous-item vers l’écran Translate Site (slug/tabs corrects)
    $settings_url = add_query_arg(
        ['page' => 'yuz-translation-settings', 'tab' => 'translate-site'],
        admin_url('admin.php')
    );
    $wp_admin_bar->add_node( [
        'id'     => 'yuz-translation-settings',
        'parent' => 'yuz-translate-now',
        'title'  => __( 'Translation settings', 'yuz-tra' ),
        'href'   => $settings_url,
    ] );

    $this->log( 'info', 'Translate Now added to admin bar (delegated listener active)' );
}


} // class
} // if class_exists

if ( class_exists( 'YUZ_Translate_Site' ) ) {
    (new YUZ_Logger())->log('success', 'YUZ_Translate_Site class created successfully at ' . current_time('mysql'));
    // On initialise sur admin_init, une fois que toutes les classes (YUZ_Languages, etc.) sont bien chargées
    add_action( 'admin_init', [ 'YUZ_Translate_Site', 'init' ] );
} else {
    (new YUZ_Logger())->log('critical', 'Failed to create YUZ_Translate_Site class at ' . current_time('mysql'));
}

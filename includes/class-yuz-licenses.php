<?php
/**
 * Class YUZ_Licenses
 * Gère l’onglet Licenses (activation / affichage).
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
// Contrats + Fallbacks (idempotents)
require_once YUZ_TRA_INCLUDES . 'class-yuz-contracts.php';
require_once YUZ_TRA_INCLUDES . 'class-yuz-fallbacks.php';
use YUZTRA\Interfaces\RendererInterface;
use YUZTRA\Interfaces\SettingsInterface;
use YUZTRA\Interfaces\LoggerInterface;
use YUZTRA\Fallbacks\NullAjax;
use YUZTRA\Fallbacks\NullLogger;
use YUZTRA\Fallbacks\NullRenderer;
use YUZTRA\Fallbacks\NullSettings;
use YUZTRA\Fallbacks\NullLanguages;
use YUZTRA\Fallbacks\NullTranslationManager;
use YUZTRA\Fallbacks\NullLanguageManager;
use YUZTRA\Fallbacks\NullDB;
use YUZTRA\Fallbacks\NullHealthCheck;
if (!class_exists('YUZ_Licenses')) {
class YUZ_Licenses {
private RendererInterface $renderer;
private SettingsInterface $settings;
private LoggerInterface $logger;
public function __construct(
RendererInterface $renderer,
SettingsInterface $settings,
LoggerInterface $logger
        ) {
$this->renderer = $renderer;
$this->settings = $settings;
$this->logger = $logger;
        }
private static bool $booted = false;
/**
         * Init aligné avec le routeur par onglet (YUZ_Settings -> do_action()).
         */
public static function init(): void {
if (self::$booted) {
    return;
}
self::$booted = true;
// Gardes minimales
if (!defined('YUZ_TRA_INCLUDES') || !defined('YUZ_TRA_PLUGIN_FILE')) {
yuz_tra_release_error_log('🟥 [CRITICAL] YUZ-TRA: required constants missing — halting YUZ_Licenses::init at ' . (function_exists('current_time') ? current_time('mysql') : date('Y-m-d H:i:s')));
wp_die(__('Critical error: YUZ-TRA constants missing.', 'yuz_translation'));
            }
        // Logger
        $logger = class_exists('YUZ_Logger') ? new \YUZ_Logger() : new NullLogger();
        // DB + Languages d'abord (YUZ_Ajax exige un DBInterface)
        $db = class_exists('YUZ_DB')
                ? new \YUZ_DB($logger, (class_exists('YUZ_Health_Check') ? new \YUZ_Health_Check($logger) : new NullHealthCheck()))
                : new NullDB();
        $languages = class_exists('YUZ_Languages') ? new \YUZ_Languages(new NullSettings(), $db) : new NullLanguages();
        // Ajax minimal avec DB fourni (signature à 3 arguments)
        $ajax = class_exists('YUZ_Ajax')
                ? new \YUZ_Ajax(new NullTranslationManager(), new NullLanguageManager(), $db)
                : new NullAjax();
        // Settings “légers” dépendant de $ajax et $languages
        $settings = class_exists('YUZ_Settings')
                ? new \YUZ_Settings($languages, $ajax, new NullTranslationManager(), new NullLanguageManager(), $logger)
                : new NullSettings();
        // Renderer (ou fallback)
        $renderer = class_exists('YUZ_Renderer')
                ? new \YUZ_Renderer($ajax, $settings, $logger, $languages)
                : new NullRenderer();
// Instance + hooks
$instance = new self($renderer, $settings, $logger);
// 👉 Rendu via le routeur admin (YUZ_Settings → do_action)
add_action('yuz-tra_page_yuz-translation-licenses', [$instance, 'render_tab']);
// (Optionnel) Enqueue si tu as une méthode dédiée
if (method_exists($instance, 'enqueue_scripts')) {
// LEGACY→YUZ_Assets: add_action('admin_enqueue_scripts', [$instance, 'enqueue_scripts']);
            }
$logger->log('success', 'YUZ_Licenses initialized (hooked to yuz-tra_page_yuz-translation-licenses)');
        }
/**
         * Rendu de l’onglet Licenses
         */
public function render_tab() {
    if (isset($this->renderer) && method_exists($this->renderer, 'render_licenses_content')) {
        $this->renderer->render_licenses_content(['source' => 'licenses-tab']);
        return;
    }
    // fallback minimal si jamais le renderer n'est pas dispo :
    echo '<div class="yuz-license-wrap" style="max-width:980px;">';
    if (isset($this->renderer)) {
        $this->renderer->render_license_account_card();
        $this->renderer->render_license_ai_card();
        $this->renderer->render_license_plans_card();
    }
    echo '</div>';
}

    }
}


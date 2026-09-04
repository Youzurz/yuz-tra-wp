<?php
/**
 * Class YUZ_Admin_Bar
 * Intégration au WordPress Admin Bar.
 *
 * @package YUZ_Translation
 * @implements YUZTRA\Interfaces\AdminBarInterface
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

// Contrats + fallbacks uniquement (dépendances légères)
require_once YUZ_TRA_INCLUDES . 'class-yuz-contracts.php';
require_once YUZ_TRA_INCLUDES . 'class-yuz-fallbacks.php';

use YUZTRA\Interfaces\AdminBarInterface;
use YUZTRA\Interfaces\LoggerInterface;
use YUZTRA\Fallbacks\NullLogger;
use YUZTRA\Fallbacks\NullLanguages;
use YUZTRA\Fallbacks\NullAjax;
use YUZTRA\Fallbacks\NullTranslationManager;
use YUZTRA\Fallbacks\NullLanguageManager;

if (!class_exists('YUZ_Admin_Bar')) {
    class YUZ_Admin_Bar implements AdminBarInterface
    {
        private LoggerInterface $logger;

        public function __construct(LoggerInterface $logger)
        {
            $this->logger = $logger;
        }

        public static function init(): void
        {
            // Logger robuste
            $logger = class_exists('YUZ_Logger') ? new \YUZ_Logger() : new NullLogger();

            // ⚠️ Ne PAS appeler runtime_flags() en statique
            $flags = [];
            try {
                if (class_exists('YUZ_Settings')) {
                    $settings = new \YUZ_Settings(
                        new NullLanguages(),
                        new NullAjax(),
                        new NullTranslationManager(),
                        new NullLanguageManager(),
                        $logger
                    );
                    if (method_exists($settings, 'runtime_flags')) {
                        $flags = (array) $settings->runtime_flags();
                    }
                }
            } catch (\Throwable $e) {
                error_log('🟥 [CRITICAL] YUZ_Admin_Bar::init runtime_flags failed: ' . $e->getMessage());
            }

            // Flag optionnel : par défaut on laisse l’admin-bar activée
            $adminbar_enabled = isset($flags['adminbar_enabled']) ? (bool) $flags['adminbar_enabled'] : true;

            if (!$adminbar_enabled || !is_admin_bar_showing()) {
                $logger->log('info', 'YUZ_Admin_Bar: disabled by flags or admin bar hidden — skipping init');
                return;
            }

            $instance = new self($logger);
            $instance->register_hooks();
            $logger->log('success', 'YUZ_Admin_Bar initialized');
        }

        private function register_hooks(): void
        {
            // WP passe 1 argument ($wp_admin_bar)
            add_action('admin_bar_menu', [$this, 'add_admin_items'], 100, 1);
        }

/** @param \WP_Admin_Bar $wp_admin_bar */
/** @param \WP_Admin_Bar $wp_admin_bar */
public function add_admin_items($wp_admin_bar): void
{
    // Gardes minimales
    if ( ! is_user_logged_in() || ! is_admin_bar_showing() ) {
        return;
    }

    // ⚠️ Capacité requise : seuls les utilisateurs autorisés voient le menu + "Traduire cette page"
    $can_translate = class_exists('YUZ_Capabilities')
        ? \YUZ_Capabilities::user_is_translator()
        : (current_user_can('manage_options') || current_user_can('yuz_translate_content'));
    if ( ! $can_translate ) {
        return;
    }

    // URL de contexte fiable (front = URL courante ; admin = home)
    $scheme      = is_ssl() ? 'https' : 'http';
    $host        = $_SERVER['HTTP_HOST']  ?? wp_parse_url( home_url(), PHP_URL_HOST );
    $uri         = $_SERVER['REQUEST_URI'] ?? '/';
    $current_url = $scheme . '://' . $host . $uri;

    $target = is_admin() ? home_url('/') : $current_url;

    // Neutralise tout forçage de langue présent dans l’URL
    $clean_target = remove_query_arg([ 'yuz-target-lang', 'target_lang' ], $target);

    // L’éditeur est déclenché par un simple flag ; AUCUNE langue forcée ici.
    $editor_url = add_query_arg([ 'yuz-edit-translation' => 1 ], $clean_target);

    // Racine “YUZ-TRA”
    $wp_admin_bar->add_menu([
        'id'    => 'yuz_translation',
        'title' => 'YUZ-TRA',
        'href'  => add_query_arg(
            [ 'page' => 'yuz-translation-settings', 'tab' => 'translate-site' ],
            admin_url('admin.php')
        ),
        'meta'  => [ 'class' => 'yuz-translation-root' ],
    ]);

    // Enfant 1 : Translate Page (ouvre directement l’éditeur)
    $wp_admin_bar->add_menu([
        'id'     => 'yuz_translate_now',
        'parent' => 'yuz_translation',
        'title'  => __( 'Translate Page', 'yuz-tra' ),
        'href'   => esc_url($editor_url),
        'meta'   => [
            'class' => 'yuz-translate-now',
            'title' => __( 'Open translation editor for this page', 'yuz-tra' ),
        ],
    ]);

    // Enfant 2 : Paramètres de traduction — slug correct
    $settings_url = add_query_arg(
        [ 'page' => 'yuz-translation-settings', 'tab' => 'translate-site' ],
        admin_url('admin.php')
    );

    $wp_admin_bar->add_menu([
        'id'     => 'yuz_translation_settings',
        'parent' => 'yuz_translation',
        'title'  => __( 'Translation Settings', 'yuz-tra' ),
        'href'   => esc_url($settings_url),
        'meta'   => [ 'class' => 'yuz-translation-settings' ],
    ]);

    // Enfant 3 : Traduire l’admin (ouvre l’éditeur de chaînes), si activé par flags
    $show_admin_item = true;
    try {
        if (class_exists('YUZ_Settings')) {
            $settings = new \YUZ_Settings(new NullLanguages(), new NullAjax(), new NullTranslationManager(), new NullLanguageManager(), $this->logger);
            if (method_exists($settings, 'runtime_flags')) {
                $f = (array)$settings->runtime_flags();
                $show_admin_item = !empty($f['translate_admin_enabled']);
            }
        }
    } catch (\Throwable $e) { /* ignore and show by default */ }

    if ($show_admin_item) {
        $admin_url = add_query_arg(
            [ 'page' => 'yuz-translation-settings', 'tab' => 'strings' ],
            admin_url('admin.php')
        );
        $wp_admin_bar->add_menu([
            'id'     => 'yuz_translate_admin',
            'parent' => 'yuz_translation',
            'title'  => __( 'Strings', 'yuz-tra' ),
            'href'   => esc_url($admin_url),
            'meta'   => [ 'class' => 'yuz-translate-admin' ],
        ]);
    }
}

    }
}

// Hook d’initialisation après `init` (textdomain déjà chargé)
add_action('init', ['YUZ_Admin_Bar', 'init'], 40);

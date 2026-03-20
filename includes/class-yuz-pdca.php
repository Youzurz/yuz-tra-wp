<?php
/**
 * class-yuz-pdca.php
 * Central PDCA orchestrator for YUZ-TRA plugin
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

require_once YUZ_TRA_INCLUDES . 'class-yuz-contracts.php';
use YUZTRA\Interfaces\PDCAInterface;

class YUZ_PDCA_Manager implements PDCAInterface {
    private static $components = [
        // Core plugin files
        'Bootstrap'                          => 'yuz-tra.php',
        'Core'                               => 'includes/class-yuz-core.php',
        'DB'                                 => 'includes/class-yuz-db.php',
        'Assets'                             => 'includes/class-yuz-assets.php',
        'Settings'                           => 'includes/class-yuz-settings.php',
        'Renderer'                           => 'includes/class-yuz-renderer.php',
        'Health Check'                      => 'includes/class-yuz-health-check.php',
        'Data Repository'                   => 'includes/class-yuz-data-repository.php',
        'AJAX'                               => 'includes/class-yuz-ajax.php',
        'Translation Manager'                => 'includes/class-yuz-translation-manager.php',
        'Languages'                          => 'includes/class-yuz-languages.php',
        'Services'                           => 'includes/class-yuz-services.php',
        'Editor'                             => 'includes/class-yuz-editor.php',
        'Rewrite'                            => 'includes/class-yuz-rewrite.php',
        'Switchers'                          => 'includes/class-yuz-switcher.php',
        'General Settings Tab'               => 'includes/class-yuz-general.php',
        'Cron'                               => 'includes/class-yuz-cron.php',
        'Admin Bar'                          => 'includes/class-yuz-admin-bar.php',
        'CSV Importer'                       => 'includes/class-yuz-csv-importer.php',
        'User Environment Detector'          => 'includes/class-yuz-user-environment.php',
        'Frontend Loader'                    => 'includes/class-yuz-frontend.php',
        'Custom Translate Adapter'           => 'includes/class-yuz-custom-translate-adapter.php',
        'Translate Adapter'                  => 'includes/class-yuz-translate-adapter.php',
        'LibreTranslate Adapter'             => 'includes/class-yuz-libre-translate-adapter.php',
        'DeepL Translate Adapter'            => 'includes/class-yuz-deepl-translate-adapter.php',
        'Google Translate Adapter'           => 'includes/class-yuz-google-translate-adapter.php',
        'Automatic Translation'              => 'includes/class-yuz-automatic-translation.php',
        'Translate Site'                     => 'includes/class-yuz-translate-site.php',
        'License Manager'                    => 'includes/class-yuz-licenses.php',
        'Add-ons Manager'                    => 'includes/class-yuz-addons.php',
        'Advanced Settings'                  => 'includes/class-yuz-advanced.php',
        'Language Entity'                    => 'includes/class-yuz-language.php',
        // JavaScript assets
        'Front-end Debug'                    => 'assets/js/yuz-frontend-debug.user.js',
        'Widgets: Translation Editor'        => 'assets/js/widgets/yuz-translation-editor.js',
        'Widgets: Translation Switcher'      => 'assets/js/widgets/yuz-language-switcher.js',
        'Admin Bar Script'                   => 'assets/js/yuz-admin-bar.js',
        'General Settings Script'            => 'assets/js/yuz-general-settings.js',
        'Translation Site Script'            => 'assets/js/yuz-translate-site.js',
        'Automatic Translation Script'       => 'assets/js/yuz-automatic-translation.js',
        'Global Script'                      => 'assets/js/yuz-global.js',
        'General Debug Script'               => 'assets/js/yuz-general-debug.js',
        // CSS assets
        'Language Switcher Styles'           => 'assets/css/yuz-language-switcher.css',
        'Translation Editor Styles'          => 'assets/css/yuz-translation-editor.css',
        'General Settings Styles'            => 'assets/css/yuz-general-settings.css',
        'Translation Admin Styles'           => 'assets/css/yuz-translation-admin.css',
    ];

    public static function run_all(): void {
        $results = [];

        foreach (self::$components as $name => $path) {
            $full = plugin_dir_path(__DIR__) . $path;
            $exists = file_exists($full);
            $results[$name] = $exists ? 'OK' : 'KO';
        }

        // TODO: push $results vers un log dédié ou stocker en option pour affichage admin
    }

    /**
     * PLAN phase.
     */
   public function plan(): void {
    }

    public function do(): void {
    }

    public function check(): bool {
        return true;
    }

    public function act(): void {
    }
}

<?php
/**
 * Class YUZ_Health_Check
 * Vérification d’état & alerts (tables, requêtes…).
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
require_once YUZ_TRA_INCLUDES . 'class-yuz-contracts.php';
// use YUZTRA\Interfaces\HealthCheckInterface;
//require_once YUZ_TRA_INCLUDES . 'class-yuz-logger.php';
use YUZTRA\Interfaces\HealthCheckInterface;
use YUZTRA\Interfaces\LoggerInterface;
if (!class_exists('YUZ_Health_Check')) {
    class YUZ_Health_Check implements HealthCheckInterface { // here
        /** @var LoggerInterface */
        private static $logger;
        public static function init(): void {
            // Lightweight checks without blocking
            if (!self::$logger) {
                self::$logger = new YUZ_Logger();
                self::$logger->init();
            }
            if (version_compare(PHP_VERSION, '7.4', '<')) {
                self::$logger->log('warning', 'PHP version below recommended 7.4', ['php_version' => PHP_VERSION]);
            }
            if (memory_get_usage(true) / 1024 / 1024 > 128) {
                self::$logger->log('warning', 'High memory usage detected', ['memory_mb' => memory_get_usage(true) / 1024 / 1024]);
            }
        }

    /**
 * Retourne une instance de logger, en la créant au besoin.
 *
 * @return LoggerInterface
 */
private static function getLogger(): LoggerInterface {
    if ( ! self::$logger ) {
        self::$logger = new \YUZ_Logger();
    }
    return self::$logger;
}

        /**
         * Ensure a condition is met; log a critical error and halt (if in admin) on failure.
         *
         * @param bool $condition The condition to verify.
         * @param string $message Descriptive message on failure.
         * @param string $method Context or method name.
         * @return void
         */
        public static function ensure(bool $condition, string $message, string $method): void {
    $logger = self::getLogger();
    if ( ! $condition ) {
        $log_context = ['context' => $method, 'message' => $message];
        $logger->log('critical', "Health check failed: $message", $log_context);
        error_log("🟥 [CRITICAL] YUZ-TRA: $message in $method");
        if ( is_admin() ) {
            wp_die( esc_html( "Critical error: $message" ) );
        }
    }
}


        /**
         * {@inheritdoc}
         *
         * Retourne un tableau associatif avec l’état de chaque contrôle de santé.
         *
         * @return array<string, bool> Map des checks et leur statut (true = ok, false = failed).
         */
        public function report(): array {
            global $wpdb;
            $db_connection = $wpdb->check_connection();
            $table_name = $wpdb->prefix . 'yuz_tra_languages';
            $tables_exist = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name;
            return [
                'db_connection_alive' => $db_connection,
                'db_tables_exist' => $tables_exist,
                'php_version_sufficient' => version_compare(PHP_VERSION, '7.4', '>='),
                'memory_limit_sufficient' => (int) ini_get('memory_limit') >= 128,
            ];
        }
        // ... other health-check methods remain unchanged ...
    }
}

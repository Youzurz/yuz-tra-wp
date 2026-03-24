<?php
/**
 * Class YUZ_Cron
 * Enregistrement & handlers des tâches WP-Cron.
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

defined( 'ABSPATH' ) or exit;
require_once YUZ_TRA_INCLUDES . 'class-yuz-contracts.php';

use YUZTRA\Interfaces\CronInterface;
use YUZTRA\Interfaces\SettingsInterface;
use YUZTRA\Interfaces\LanguagesInterface;
use YUZTRA\Interfaces\TranslationManagerInterface;

if ( ! class_exists( 'YUZ_Cron' ) ) {
    class YUZ_Cron implements CronInterface {
        /**
         * Initialise la gestion du Cron.
         */
        public static function init() {
            // Hook du handler sur l'événement
            add_action( 'yuz_tra_batch_translate', [ __CLASS__, 'run_batch' ] );
            // Si besoin d'un intervalle sur mesure, on peut l'ajouter ici :
            add_filter( 'cron_schedules', [ __CLASS__, 'add_cron_intervals' ] );
            // Planification initiale (ex. après activation)
            $settings = get_option( 'yuz_tra_api_settings', [] );
            $mode = $settings['translation_mode'] ?? 'manual';
            $interval = $settings['cron_interval'] ?? 'hourly';
            if ( in_array( $mode, [ 'silent', 'all' ], true ) && ! wp_next_scheduled( 'yuz_tra_batch_translate' ) ) {
                wp_schedule_event( time(), $interval, 'yuz_tra_batch_translate' );
            }
        }

        /**
         * Exemple : ajouter un intervalle personnalisé si besoin.
         *
         * @param array $schedules
         * @return array
         */
        public static function add_cron_intervals( $schedules ) {
            $schedules['every_five_minutes'] = [
                'interval' => 300,
                'display' => __( 'Every Five Minutes', 'yuz_translation' ),
            ];
            return $schedules;
        }

        /**
         * Récupère une langue source valide, fallback si nécessaire.
         *
         * @return string
         */
        private static function get_valid_source_language() {
            $settings = get_option( 'yuz_tra_general', [] );
            $source_lang = $settings['source_language'] ?? '';
            $valid_langs = array_column( YUZ_Languages::get_translatable_languages(), 'language_code' );
            if ( $source_lang && in_array( $source_lang, $valid_langs, true ) ) {
                return $source_lang;
            }
            $site_locale = get_locale();
            $site_lang = substr( $site_locale, 0, 2 );
            if ( in_array( $site_lang, $valid_langs, true ) ) {
                return $site_lang;
            }
            if ( in_array( 'en', $valid_langs, true ) ) {
                return 'en';
            }
            return '';
        }

        /**
         * Handler principal du batch.
         */
        public static function run_batch() {
            if (!wp_doing_cron()) {
                return;
            }
            // MODIF: Lock transient 60s + backoff 15s (Phase 8: lock/backoff)
            $lock_key = 'yuz_tra_batch_lock';
            if (get_transient($lock_key)) {
                wp_schedule_single_event(time() + 15, 'yuz_tra_batch_translate'); // Backoff 15s
                return;
            }
            set_transient($lock_key, true, 60); // Lock 60s
            $source = self::get_valid_source_language();
            if ( ! $source ) {
                delete_transient($lock_key);
                return;
            }
            $targets = YUZ_Languages::get_translatable_languages();
            $posts = get_posts( [
                'post_type' => 'any',
                'posts_per_page' => 10,
                'post_status' => 'publish',
            ] );
            global $wpdb;
            foreach ( $posts as $post ) {
                foreach ( $targets as $lang ) {
                    $code = is_object( $lang ) ? $lang->language_code : $lang;
                    $translated = YUZ_API_Manager::translate( $post->post_content, $source, $code );
                    if ( $translated ) {
                        $wpdb->insert(
                            $wpdb->prefix . 'yuz_tra_translations',
                            [
                                'post_id' => $post->ID,
                                'language_code' => $code,
                                'translated_text' => $translated,
                                'status' => 1,
                                'created_at' => current_time( 'mysql' ),
                            ]
                        );
                    }
                }
            }
            delete_transient($lock_key); // Release lock
        }
    }
}
// On hook tout de suite l'init() pour charger le Cron
add_action( 'init', [ 'YUZ_Cron', 'init' ], 15 );


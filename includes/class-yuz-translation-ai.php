<?php
/**
 * Class YUZ_Translation_AI
 * Intégration d’IA tierce pour suggestions de traduction.
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

require_once YUZ_TRA_INCLUDES . 'class-yuz-contracts.php';
use YUZTRA\Interfaces\AIInterface;

if (!class_exists('YUZ_Translation_AI')) {
    class YUZ_Translation_AI implements AIInterface {

        /**
         * Initializes the AI translation module.
         */
        public static function init(): void {
            // Soft-gate (en plus du gate dans YUZ_Core)
            $site = get_option('yuz_tra_site_settings', ['enable_ai' => '0']);
            $enabled = ($site['enable_ai'] ?? '0') === '1';
            if (!$enabled) {
                return;
            }

            // AJAX uniquement (les assets sont gérés ailleurs)
            add_action('wp_ajax_yuz_ai_translate',       [__CLASS__, 'ai_translate']);
            add_action('wp_ajax_yuz_ai_update_status',   [__CLASS__, 'ai_update_status']);
        }

        /**
         * Handles AI translation AJAX request.
         */
        public static function ai_translate() {
            check_ajax_referer('yuz_api_nonce', 'nonce');

            $text        = sanitize_text_field($_POST['text'] ?? '');
            $source_lang = sanitize_text_field($_POST['source_lang'] ?? '');
            $target_lang = sanitize_text_field($_POST['target_lang'] ?? '');

            if ($text === '' || $source_lang === '' || $target_lang === '') {
                wp_send_json_error(['message' => 'Missing required parameters']);
                return;
            }

            // NOTE: module IA encore “capuchonné” — on garde le flux existant tel quel.
            // API manager (ex-TM)
            $manager = class_exists('YUZ_API_Manager')
                ? new \YUZ_API_Manager(
                    [],
                    (class_exists('YUZ_Services') ? \YUZ_Services::settings() : new \YUZTRA\Fallbacks\NullSettings()),
                    (class_exists('YUZ_Services') ? \YUZ_Services::languages() : new \YUZTRA\Fallbacks\NullLanguages()),
                    null,
                    (class_exists('YUZ_Services') ? \YUZ_Services::db() : new \YUZTRA\Fallbacks\NullDB()),
                    new YUZ_Logger()
                )
                : null;
            $api_settings = get_option('yuz_tra_api_settings', ['api_provider' => 'libretranslate']);
            if ($manager && method_exists($manager, 'set_api_settings')) {
                $manager->set_api_settings($api_settings);
            }

            $advanced_settings = get_option('yuz_tra_advanced', ['ai_model' => 'default', 'custom_ai_endpoint' => '']);
            $translated = null;

            if (($advanced_settings['ai_model'] ?? 'default') === 'custom' && !empty($advanced_settings['custom_ai_endpoint'])) {
                $translated = self::custom_ai_translate($text, $source_lang, $target_lang, $advanced_settings['custom_ai_endpoint']);
            } else {
                if ($manager && method_exists($manager, 'translate')) {
                    $translated = $manager->translate($text, $source_lang, $target_lang);
                }
            }

            if ($translated) {
                self::log_colored('success', 'AI translation successful', ['target_lang' => $target_lang]);
                wp_send_json_success(['translated_text' => $translated]);
            } else {
                self::log_colored('critical', 'AI translation failed', ['target_lang' => $target_lang]);
                wp_send_json_error(['message' => 'AI translation failed']);
            }
        }

        /**
         * Updates the AI enable status via AJAX.
         */
        public static function ai_update_status() {
            check_ajax_referer('yuz_api_nonce', 'nonce');

            $enable_ai = sanitize_text_field($_POST['enable_ai'] ?? '0');
            if (!in_array($enable_ai, ['0', '1'], true)) {
                wp_send_json_error(['message' => 'Invalid enable_ai value']);
                return;
            }

            $settings = get_option('yuz_tra_site_settings', ['enable_ai' => '0']);
            $settings['enable_ai'] = $enable_ai;

            if (update_option('yuz_tra_site_settings', $settings)) {
                self::log_colored('success', 'AI status updated successfully', ['enable_ai' => $enable_ai]);
                wp_send_json_success(['message' => 'AI status updated']);
            } else {
                self::log_colored('warning', 'Failed to update AI status', ['enable_ai' => $enable_ai]);
                wp_send_json_error(['message' => 'Failed to update AI status']);
            }
        }

        /**
         * Performs a custom AI translation request.
         *
         * @param string $text The text to translate.
         * @param string $source_lang The source language code.
         * @param string $target_lang The target language code.
         * @param string $endpoint The custom AI endpoint.
         * @return string|null The translated text or null on failure.
         */
        private static function custom_ai_translate($text, $source_lang, $target_lang, $endpoint) {
            // Timeout court pour l’IA custom
            $response = wp_remote_post($endpoint, [
                'body'    => json_encode(['q' => $text, 'source' => $source_lang, 'target' => $target_lang]),
                'headers' => ['Content-Type' => 'application/json'],
                'timeout' => 2,
            ]);

            if (is_wp_error($response)) {
                self::log_colored('critical', 'Custom AI request failed', ['error' => $response->get_error_message()]);
                return null;
            }

            $response_code = wp_remote_retrieve_response_code($response);
            if ($response_code === 200) {
                $body = json_decode(wp_remote_retrieve_body($response), true);
                return $body['translatedText'] ?? $body['translation'] ?? null; // Supporte plusieurs formats
            }

            self::log_colored('warning', 'Custom AI request returned non-200 status', ['code' => $response_code]);
            return null;
        }

        /**
         * Logs a message with a colored emoji for debugging.
         *
         * @param string $level The log level (critical, warning, success, info).
         * @param string $message The message to log.
         * @param array  $context Additional context data.
         */
        private static function log_colored($level, $message, $context = []) {
            $prefixes = [
                'critical' => '🟥 [CRITICAL]',
                'warning'  => '🟨 [WARNING]',
                'success'  => '🟩 [SUCCESS]',
                'info'     => '🟦 [INFO]',
            ];
            $prefix = $prefixes[$level] ?? $prefixes['info'];

            $log_message = "YUZ-TRA-AI: $prefix $message";
            if (!empty($context)) {
                $log_message .= ' | Context: ' . print_r($context, true);
            }
            yuz_tra_release_error_log($log_message);
        }
    }
}

// Bootstrap
YUZ_Translation_AI::init();

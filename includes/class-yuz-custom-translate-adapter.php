<?php
/**
 * Class YUZ_Custom_Translate_Adapter
 * Translation adapter for a custom API.
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

use YUZTRA\Interfaces\TranslateAdapterInterface;

if (!function_exists('yuz_tra_adapter_log')) {
    function yuz_tra_adapter_log(...$args) {
        $enabled = (defined('YUZ_TRA_DEBUG') && YUZ_TRA_DEBUG) || (defined('WP_DEBUG') && WP_DEBUG);
        if (!$enabled) {
            return;
        }
        error_log(...$args);
    }
}

// PLAN: Prepare creation of YUZ_Custom_Translate_Adapter for custom API
yuz_tra_adapter_log('YUZ-TRA: [PLAN] Preparing to create YUZ_Custom_Translate_Adapter class at ' . current_time('mysql'));

if (!class_exists('YUZ_Custom_Translate_Adapter')) {
    class YUZ_Custom_Translate_Adapter implements TranslateAdapterInterface {
        private $httpClient;
        private $config;

        /**
         * Constructor to inject dependencies.
         *
         * @param HttpClientInterface|null $httpClient HTTP client for making requests.
         * @param array $config Configuration settings for the adapter.
         */
        public function __construct(HttpClientInterface $httpClient = null, array $config = []) {
            $this->httpClient = $httpClient;
            $this->config = $config;
        }

        /**
 * Tests connectivity to the custom translation API.
 *
 * @param array $settings API settings (endpoint, API key).
 * @return bool True si la connexion est OK, false sinon.
 */
public function test_api_conn(array $settings): bool {
    yuz_tra_adapter_log('YUZ-TRA: [DO] Testing API connection at ' . current_time('mysql'));

    $options = [
        'headers' => [ 'Content-Type' => 'application/json' ],
        'body'    => wp_json_encode([ 'api_key' => $settings['api_key'] ]),
        'timeout' => 5,
    ];

    if ($this->httpClient) {
        $response = $this->httpClient->post($settings['endpoint'], $options);
    } else {
        $response = wp_remote_post($settings['endpoint'], $options);
    }

    if (is_wp_error($response)) {
        yuz_tra_adapter_log('YUZ-TRA: [ERROR] API connection failed: ' . $response->get_error_message());
        return false;
    }

    $status = wp_remote_retrieve_response_code($response);
    if ($status < 200 || $status >= 300) {
        yuz_tra_adapter_log("YUZ-TRA: [ERROR] API returned HTTP {$status}");
        return false;
    }

    yuz_tra_adapter_log('YUZ-TRA: [ACT] API connection successful');
    return true;
}


        /**
         * Translates text using a custom API.
         *
         * @param string $text The text to translate.
         * @param string $source_lang The source language code.
         * @param string $target_lang The target language code.
         * @param array $settings API settings (endpoint, API key).
         * @return string|null The translated text, or null on failure.
         */
        public function translate(string $text, string $source_lang, string $target_lang, array $settings): ?string {
            yuz_tra_adapter_log('YUZ-TRA: [DO] Translating using custom API at ' . current_time('mysql'));

            // Build request payload
            $payload = [
                'text'   => $text,
                'source' => $source_lang,
                'target' => $target_lang,
                'api_key'=> $settings['api_key'] ?? '',
            ];

            $options = [
                'headers' => [ 'Content-Type' => 'application/json' ],
                'body'    => wp_json_encode($payload),
                'timeout' => 15,
            ];

            if ($this->httpClient) {
                $response = $this->httpClient->post($settings['endpoint'], $options);
            } else {
                $response = wp_remote_post($settings['endpoint'], $options);
            }

            if (is_wp_error($response)) {
                yuz_tra_adapter_log('YUZ-TRA: [ERROR] API request failed: ' . $response->get_error_message());
                return null;
            }

            $status = wp_remote_retrieve_response_code($response);
            if ($status < 200 || $status >= 300) {
                yuz_tra_adapter_log("YUZ-TRA: [ERROR] HTTP $status from translation API");
                return null;
            }

            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body, true);
            if (!isset($data['translated_text']) || empty($data['translated_text'])) {
                yuz_tra_adapter_log('YUZ-TRA: [ERROR] Invalid API response: missing translated_text');
                return null;
            }

            yuz_tra_adapter_log('YUZ-TRA: [ACT] Translation successful at ' . current_time('mysql'));
            return $data['translated_text'];
        }
    }

    // ACT: Log the result of class creation
    if (class_exists('YUZ_Custom_Translate_Adapter')) {
        yuz_tra_adapter_log('YUZ-TRA: [ACT] YUZ_Custom_Translate_Adapter class created successfully at ' . current_time('mysql'));
    } else {
        yuz_tra_adapter_log('YUZ-TRA: [ACT] Failed to create YUZ_Custom_Translate_Adapter class at ' . current_time('mysql'));
    }
}

<?php
/**
 * Class YUZ_DeepL_Translate_Adapter
 * DeepL translation adapter for the YUZ-TRA plugin.
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

// Include the contracts file
require_once YUZ_TRA_INCLUDES . 'class-yuz-contracts.php';

use YUZTRA\Interfaces\TranslateAdapterInterface;

// Assuming HttpClientInterface is defined elsewhere or needs to be added
// It should support methods like post() and get() compatible with wp_remote_* structure

class YUZ_DeepL_Translate_Adapter implements TranslateAdapterInterface {
    private $httpClient;
    private $apiKey;

    /**
     * Constructor to inject dependencies.
     *
     * @param HttpClientInterface $httpClient HTTP client for making requests.
     * @param string $apiKey DeepL API key.
     */
    public function __construct(HttpClientInterface $httpClient = null, string $apiKey = '') {
        $this->httpClient = $httpClient;
        $this->apiKey = $apiKey;
    }

    /**
     * Translates the given text using DeepL API.
     *
     * @param string $text Text to translate.
     * @param string $source_lang Source language code.
     * @param string $target_lang Target language code.
     * @param array $settings Translation settings.
     * @return string|null Translated text or null on failure.
     */
    // public function translate(string $text, string $source_lang, string $target_lang, array $settings) {
    public function translate($text, $source_lang, $target_lang, $settings): ?string {
        // DO: Perform translation using DeepL API
        yuz_tra_release_error_log('YUZ-TRA: [DO] Translating using DeepL API at ' . current_time('mysql'));

        // Validate API key (fallback to injected if not in settings)
        $api_key = !empty($settings['api_key']) ? $settings['api_key'] : $this->apiKey;
        if (empty($api_key)) {
            yuz_tra_release_error_log('YUZ-TRA: [ERROR] DeepL API key not provided');
            return null;
        }

        // Determine the API URL based on deepl_free
        $api_url = !empty($settings['deepl_free']) ? 'https://api-free.deepl.com/v2/translate' : 'https://api.deepl.com/v2/translate';

        // DeepL API expects language codes in uppercase (e.g., "EN" instead of "en_US")
        $source_lang = strtoupper(substr($source_lang, 0, 2)); // Simplify to "EN"
        $target_lang = strtoupper(substr($target_lang, 0, 2)); // Simplify to "FR"

        // Prepare the API request body
        $body = [
            'text'               => [$text], // DeepL API v2 expects text as an array
            'source_lang'        => $source_lang,
            'target_lang'        => $target_lang,
            'auth_key'           => $api_key,
            'tag_handling'       => 'html',
            'outline_detection'  => 0,
            'preserve_formatting'=> 1,
        ];

        // Make the request using injected HTTP client
        $response = $this->httpClient->post($api_url, [
            'body'    => $body,
            'headers' => [
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
        ]);

        // Health check (assuming YUZ_Health_Check is defined elsewhere)
        if (class_exists('YUZ_Health_Check')) {
            YUZ_Health_Check::ensure(
                ! is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200,
                sprintf(
                    'API %s returned %s / %s',
                    __CLASS__,
                    wp_remote_retrieve_response_code($response),
                    is_wp_error($response) ? $response->get_error_message() : 'No body'
                ),
                __METHOD__
            );
        }

        if (is_wp_error($response)) {
            yuz_tra_release_error_log('YUZ-TRA: [ERROR] DeepL API request failed: ' . $response->get_error_message());
            return null;
        }

        $response_body = wp_remote_retrieve_body($response);
        $data = json_decode($response_body, true);

        // Check for errors in the response
        if (isset($data['message'])) {
            yuz_tra_release_error_log('YUZ-TRA: [ERROR] DeepL API error: ' . $data['message']);
            return null;
        }

        // Extract the translated text
        if (!isset($data['translations'][0]['text'])) {
            yuz_tra_release_error_log('YUZ-TRA: [ERROR] DeepL API response invalid: ' . print_r($response_body, true));
            return null;
        }

        yuz_tra_release_error_log("YUZ-TRA: [CHECK] DeepL translation succeeded: " . $data['translations'][0]['text'] . ' at ' . current_time('mysql', true));
        yuz_tra_release_error_log("YUZ-TRA: [ACT] DeepL translation completed successfully at " . current_time('mysql', true));
        return $data['translations'][0]['text'];
    }

    /**
     * Tests the DeepL API connection.
     *
     * @param array $settings API settings.
     * @return array Connection test result.
     */
    public function test_api_conn(array $settings): bool{
    yuz_tra_release_error_log('YUZ-TRA: [DO] Testing DeepL connection at ' . current_time('mysql'));

        // Validate API key (fallback to injected if not in settings)
          // Récupère la clé API depuis $settings ou injectée
    $api_key = $settings['api_key'] ?? $this->apiKey;
    if (empty($api_key)) {
        yuz_tra_release_error_log('YUZ-TRA: [ERROR] DeepL API key not provided');
        return false;
    }

        // Determine the base endpoint (consistent with translate)
        // Construit l’URL de test (/usage)
    $base = $settings['deepl_free'] ?? false
        ? 'https://api-free.deepl.com/v2'
        : 'https://api.deepl.com/v2';
    $url  = rtrim($base, '/') . '/usage';

    // En-tête d’authentification
    $args = [
        'headers' => ['Authorization' => 'DeepL-Auth-Key ' . $api_key],
        'timeout' => 5,
    ];

       // Exécute la requête
    $response = $this->httpClient
        ? $this->httpClient->get($url, $args)
        : wp_remote_get($url, $args);

    if (is_wp_error($response)) {
        yuz_tra_release_error_log('YUZ-TRA: [ERROR] DeepL connection test failed: ' . $response->get_error_message());
        return false;
    }

    $code = wp_remote_retrieve_response_code($response);
    if ($code >= 200 && $code < 300) {
        yuz_tra_release_error_log('YUZ-TRA: [ACT] DeepL connection test succeeded');
        return true;
    }

    yuz_tra_release_error_log("YUZ-TRA: [ERROR] DeepL connection test HTTP {$code}");
    return false;
}
}

/*
4. Exemple de DeepL Translate Adapter (pratiques officielles)

Pour utiliser la librairie officielle DeepL PHP (via Composer) :

# Ajouter la dépendance DeepL
composer require deeplcom/deepl-php

# Exemple d’implémentation (functions.php ou plugin)
require __DIR__ . '/vendor/autoload.php';

function traduire_avec_deepl($texte, $langue_cible = 'FR') {
    $authKey = 'VOTRE_CLE_API'; // Remplacez par votre clé réelle
    $client  = new \DeepL\DeepLClient($authKey);
    $result  = $client->translateText($texte, null, $langue_cible);
    return $result->text;
}

// Exemple d’utilisation
add_filter('the_content', function($content) {
    return traduire_avec_deepl($content, 'DE');
});

📡 Option 2 : sans dépendance – appel cURL direct dans WordPress

function deepLWG($text, $lang) {
    $key    = 'VOTRE_CLE_API';
    $apiUrl = 'https://api.deepl.com/v2/translate'; // ou api-free.deepl.com pour Free

    $data = [
        'auth_key'   => $key,
        'text'       => $text,
        'target_lang'=> $lang,
    ];

    $response = wp_remote_post($apiUrl, [
        'body'    => $data,
        'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
    ]);

    if (is_wp_error($response)) {
        return $text;
    }

    $body = wp_remote_retrieve_body($response);
    $json = json_decode($body, true);
    return $json['translations'][0]['text'] ?? $text;
}
*/

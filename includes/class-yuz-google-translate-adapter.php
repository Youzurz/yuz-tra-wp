<?php
/**
 * Class YUZ_Google_Translate_Adapter
 * Google translation adapter for the YUZ-TRA plugin.
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
// For now, we'll assume the injected client has methods compatible with wp_remote_post/get (returns array or WP_Error)

class YUZ_Google_Translate_Adapter implements TranslateAdapterInterface {
    private $httpClient;
    private $apiKey;

    /**
     * Constructor to inject dependencies.
     *
     * @param HttpClientInterface $httpClient HTTP client for making requests.
     * @param string $apiKey Google API key.
     */
    public function __construct(HttpClientInterface $httpClient = null, string $apiKey = '') {
    $this->httpClient = $httpClient;
    $this->apiKey     = $apiKey;
}


    /**
     * Translates the given text using Google API.
     *
     * @param string $text Text to translate.
     * @param string $source_lang Source language code.
     * @param string $target_lang Target language code.
     * @param array $settings Translation settings (e.g., 'endpoint').
     * @return string|null Translated text or null on failure.
     */
    // public function translate(string $text, string $source_lang, string $target_lang, array $settings) {
    public function translate($text, $source_lang, $target_lang, $settings): ?string {
        // DO: Perform translation using Google Translate API
        yuz_tra_release_error_log('YUZ-TRA: [DO] Translating using Google Translate API at ' . current_time('mysql'));

        if (empty($this->apiKey)) {
            yuz_tra_release_error_log('YUZ-TRA: [ERROR] Google Translate API key not provided');
            return null;
        }

        $endpoint = $settings['endpoint'] ?? 'https://translation.googleapis.com/language/translate/v2';
        $url = $endpoint . '?key=' . urlencode($this->apiKey);

        // Google Translate API expects language codes in a specific format (e.g., "en-US" instead of "en_US")
        $source_lang = str_replace('_', '-', $source_lang);
        $target_lang = str_replace('_', '-', $target_lang);

        // Prepare the API request
        $response = $this->httpClient->post($url, [
            'body' => json_encode([
                'q' => $text,
                'source' => $source_lang,
                'target' => $target_lang,
                'format' => 'html'
            ]),
            'headers' => [
                'Content-Type' => 'application/json',
            ],
        ]);

        // Vérification ajoutée, adaptée du second extrait (DeepL)
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

        if (is_wp_error($response)) {
            yuz_tra_release_error_log('YUZ-TRA: [ERROR] Google Translate API request failed: ' . $response->get_error_message());
            return null;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        // Check for errors in the response
        if (isset($data['error'])) {
            yuz_tra_release_error_log('YUZ-TRA: [ERROR] Google Translate API error: ' . $data['error']['message']);
            return null;
        }

        // Extract the translated text
        if (!isset($data['data']['translations'][0]['translatedText'])) {
            yuz_tra_release_error_log('YUZ-TRA: [ERROR] Google Translate API response invalid: ' . print_r($body, true));
            return null;
        }

        yuz_tra_release_error_log("YUZ-TRA: [CHECK] Google Translate translation succeeded: " . $data['data']['translations'][0]['translatedText'] . ' at ' . current_time('mysql'));
        yuz_tra_release_error_log("YUZ-TRA: [ACT] Google Translate translation completed successfully at " . current_time('mysql'));
        return $data['data']['translations'][0]['translatedText'];
    }

    /**
     * Tests the Google API connection.
     *
     * @param array $settings API settings.
     * @return array Connection test result.
     */
    /**
 * {@inheritdoc}
 */
public function test_api_conn(array $settings): bool
{
    // DO: Perform connection test using Google Translate API
    yuz_tra_release_error_log('YUZ-TRA: [DO] Testing Google Translate connection at ' . current_time('mysql'));

    // Récupère la clé API (injectée ou passée en settings)
    $apiKey = $settings['api_key'] ?? $this->apiKey;
    if (empty($apiKey)) {
        yuz_tra_release_error_log('YUZ-TRA: [ERROR] Google Translate API key not provided for connection test');
        return false;
    }

    // URL de test : liste des langues supportées
    $endpoint = $settings['endpoint'] ?? 'https://translation.googleapis.com/language/translate/v2/languages';
    $url = $endpoint . '?key=' . urlencode($apiKey);

    // Prépare la requête
    $args = [
        'headers' => ['Content-Type' => 'application/json'],
        'timeout' => 5,
    ];

    // Exécute la requête avec fallback
    $response = $this->httpClient
        ? $this->httpClient->get($url, $args)
        : wp_remote_get($url, $args);

    if (is_wp_error($response)) {
        $error_message = $response->get_error_message();
        yuz_tra_release_error_log("YUZ-TRA: [CHECK] Google Translate connection test failed: " . $error_message . ' at ' . current_time('mysql'));
        yuz_tra_release_error_log("YUZ-TRA: [ACT] Google Translate connection test failed at " . current_time('mysql'));
        return false;
    }

    $code = wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);

    // Échec si HTTP ≠ 200 ou réponse d’erreur
    if ($code !== 200 || isset($data['error'])) {
        $message = isset($data['error']['message']) ? $data['error']['message'] : 'Invalid response code: ' . $code;
        yuz_tra_release_error_log("YUZ-TRA: [CHECK] Google Translate connection test failed with status: " . $code . ' - ' . $message . ' at ' . current_time('mysql'));
        yuz_tra_release_error_log("YUZ-TRA: [ACT] Google Translate connection test failed at " . current_time('mysql'));
        return false;
    }

    yuz_tra_release_error_log("YUZ-TRA: [CHECK] Google Translate connection test succeeded at " . current_time('mysql'));
    yuz_tra_release_error_log("YUZ-TRA: [ACT] Google Translate connection test completed successfully at " . current_time('mysql'));
    return true;
}
}

/*
4. Exemple de Google Translate Adapter (pratiques officielles)

Pour utiliser la librairie officielle Google Cloud Translate (via Composer) :

# Installer Composer si nécessaire
php -r "copy('https://getcomposer.org/installer','composer-setup.php');"
php composer-setup.php
php -r "unlink('composer-setup.php');"
sudo mv composer.phar /usr/local/bin/composer
sudo chmod +x /usr/local/bin/composer

# Ajouter la dépendance Google Cloud Translate
composer require google/cloud-translate

# Adapter votre classe avec la librairie officielle :
use Google\Cloud\Translate\V2\TranslateClient;

class YUZ_Google_Translate_Adapter {
    public function translate($text, $source, $target, $settings) {
        $client = new TranslateClient([
            'key' => $settings['api_key'],
        ]);
        $result = $client->translate($text, [
            'source' => str_replace('_','-',$source),
            'target' => str_replace('_','-',$target),
        ]);
        return $result['text'] ?? null;
    }
}

# Option “cURL” sans dépendance (via l’API WP) :
public function translate($text, $source, $target, $settings) {
    $response = wp_remote_post(
        'https://translation.googleapis.com/language/translate/v2',
        [
            'body' => [
                'key'    => $settings['api_key'],
                'q'      => $text,
                'source' => str_replace('_','-',$source),
                'target' => str_replace('_','-',$target),
                'format' => 'html',
            ],
        ]
    );
    if (is_wp_error($response)) {
        return null;
    }
    $data = json_decode(wp_remote_retrieve_body($response), true);
    return $data['data']['translations'][0]['translatedText'] ?? null;
}
*/


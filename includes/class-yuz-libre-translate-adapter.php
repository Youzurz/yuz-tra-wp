<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
/**
 * Class YUZ_Libre_Translate_Adapter
 * LibreTranslate translation adapter for the YUZ-TRA plugin.
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
// Include necessary files
require_once plugin_dir_path(dirname(__FILE__)) . 'includes/class-yuz-translate-adapter.php';
require_once YUZ_TRA_INCLUDES . 'class-yuz-contracts.php';
use YUZTRA\Interfaces\TranslateAdapterInterface;
if (!function_exists('yuz_tra_adapter_log')) {
    function yuz_tra_adapter_log(...$args) {
        $enabled = (defined('YUZ_TRA_DEBUG') && YUZ_TRA_DEBUG) || (defined('WP_DEBUG') && WP_DEBUG);
        if (!$enabled) {
            return;
        }
        yuz_tra_debug_log(...$args);
    }
}
// Assuming HttpClientInterface is defined elsewhere or needs to be added
// For now, we'll use WP's wp_remote_request as fallback if no client injected
// PLAN: Prepare creation of YUZ_Libre_Translate_Adapter class for LibreTranslate API
yuz_tra_adapter_log('YUZ-TRA: [PLAN] Preparing to create YUZ_Libre_Translate_Adapter class at ' . current_time('mysql'));
if (!class_exists('YUZ_Libre_Translate_Adapter')) {
    class YUZ_Libre_Translate_Adapter extends YUZ_Translate_Adapter implements TranslateAdapterInterface {
        private $httpClient;
        private $config;
        /**
         * Constructor to inject dependencies (if applicable).
         *
         * @param HttpClientInterface $httpClient HTTP client for making requests.
         * @param array $config Configuration settings for the adapter.
         */
        public function __construct(HttpClientInterface $httpClient = null, array $config = []) {
            $this->httpClient = $httpClient;
            $this->config = $config;
        }
        /**
         * Translates the given text using LibreTranslate API.
         *
         * @param string $text Text to translate.
         * @param string $source_lang Source language code.
         * @param string $target_lang Target language code.
         * @param array $settings Translation settings.
         * @return string|null Translated text or null on failure.
         */
        /**
         * {@inheritdoc}
         */
        public function translate(string $text, string $source_lang, string $target_lang, array $settings): ?string {
            yuz_tra_adapter_log('YUZ-TRA: [DO] Translating text with LibreTranslate at ' . current_time('mysql') . ': ' . substr($text, 0, 50));
            $effective_settings = array_merge($this->config, $settings);
            if (empty($text)) {
                yuz_tra_adapter_log('YUZ-TRA: [ERROR] Missing text for translation');
                return null;
            }
            // Fetch available languages dynamically (best effort: do not block if unavailable)
            $available_languages = $this->get_available_languages($effective_settings);
            if (empty($available_languages)) {
                yuz_tra_adapter_log('YUZ-TRA: [WARN] Languages endpoint unavailable; proceeding without strict check');
            }
            // Handle source language dynamically
            $settings = get_option('yuz_tra_settings', []);
            if (!is_array($settings)) {
                $settings = [];
            }
            $general = get_option('yuz_tra_general', []);
            if (!is_array($general)) {
                $general = [];
            }

            $source_pref = $settings['source_language']
                ?? $general['yuz_tra_source_language']
                ?? $settings['default_language']
                ?? get_locale();
            $target_list = (array)($settings['translatable_languages']
                ?? $general['yuz_tra_translatable_languages']
                ?? []);

            $source_locale = $source_lang !== '' ? $source_lang : $source_pref;
            $target_locale = $target_lang !== '' ? $target_lang : ($target_list[0] ?? '');

            $mapped_source = $this->map_language_code($source_locale, 'auto');
            $mapped_target = $this->map_language_code($target_locale, 'en');
            if ($mapped_target === 'auto') {
                $mapped_target = $this->map_language_code($target_list[0] ?? 'en', 'en');
            }

            $available_map = array_map('strtolower', $available_languages);
            if (!empty($available_map)) {
                if ($mapped_source !== 'auto' && !in_array($mapped_source, $available_map, true)) {
                    yuz_tra_adapter_log('YUZ-TRA: [WARNING] Source language not in LT list: ' . $mapped_source);
                }
                if (!in_array($mapped_target, $available_map, true)) {
                    yuz_tra_adapter_log('YUZ-TRA: [WARNING] Target language not in LT list: ' . $mapped_target);
                }
            }

            $source_lang = $mapped_source;
            $target_lang = $mapped_target;
            // Validate endpoint
            $endpoint = rtrim($effective_settings['endpoint'] ?? '', '/');
            $endpoint = rtrim((string) apply_filters('yuz_tra_libre_translate_endpoint', $endpoint, $effective_settings), '/');
            if ($endpoint === '') {
                yuz_tra_adapter_log('YUZ-TRA: [ERROR] LibreTranslate endpoint is not configured');
                return null;
            }
            // Construct URL
            $url = preg_match('#/translate/?$#', $endpoint) ? $endpoint : $endpoint . '/translate';
            yuz_tra_adapter_log('YUZ-TRA: [INFO] Constructed LibreTranslate URL: ' . $url);
            $api_key = (string) apply_filters('yuz_tra_libre_translate_api_key', $effective_settings['api_key'] ?? '', $effective_settings);
            // Prepare request
            $args = [
                'method' => 'POST',
                'timeout' => 15,
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
                'body' => wp_json_encode([
                    'q' => $text,
                    'source' => $source_lang,
                    'target' => $target_lang,
                    'format' => 'text',
                    'alternatives' => min(3, max(0, (int) ($effective_settings['alternatives'] ?? 0))),
                    'api_key' => $api_key,
                ]),
            ];
            // Make request using injected client if available, else fallback
            if ($this->httpClient) {
                // Assuming HttpClientInterface has a request method
                $response = $this->httpClient->request('POST', $url, $args);
            } else {
                $response = wp_remote_request($url, $args);
            }
            if (is_wp_error($response)) {
                $msg = $response->get_error_message();
                yuz_tra_adapter_log('YUZ-TRA: [ERROR] LibreTranslate translation failed: ' . $msg);
                throw new \RuntimeException( esc_html( 'LT_HTTP_ERR:' . $msg ) );
            }
            $status_code = wp_remote_retrieve_response_code($response);
            $body = wp_remote_retrieve_body($response);
            yuz_tra_adapter_log('YUZ-TRA: [INFO] LibreTranslate translation response: Status ' . $status_code . ', Body: ' . $body);
            if ($status_code !== 200) {
                yuz_tra_adapter_log('YUZ-TRA: [ERROR] LibreTranslate translation failed with status: ' . $status_code);
                throw new \RuntimeException( esc_html( 'LT_HTTP_' . $status_code ) );
            }
            $data = json_decode($body, true);
            // Accept multiple response shapes from Libre-compatible providers
            $pickTranslated = static function ($j) {
                if (!is_array($j)) return '';
                // 1) Top-level common keys
                foreach (['translatedText','translated_text','translation','translated'] as $k) {
                    if (isset($j[$k]) && is_string($j[$k]) && $j[$k] !== '') return (string) $j[$k];
                }
                // 2) data.*
                if (isset($j['data']) && is_array($j['data'])) {
                    foreach (['translatedText','translated_text','translation','translated'] as $k) {
                        if (isset($j['data'][$k]) && is_string($j['data'][$k]) && $j['data'][$k] !== '') return (string) $j['data'][$k];
                    }
                    if (isset($j['data']['translations']) && is_array($j['data']['translations']) && !empty($j['data']['translations'])) {
                        $node = $j['data']['translations'][0];
                        if (is_array($node)) {
                            foreach (['translatedText','translated_text','translation','translated'] as $k) {
                                if (isset($node[$k]) && is_string($node[$k]) && $node[$k] !== '') return (string) $node[$k];
                            }
                        } elseif (is_string($node) && $node !== '') {
                            return (string) $node;
                        }
                    }
                }
                // 3) translations[] at top-level
                if (isset($j['translations']) && is_array($j['translations']) && !empty($j['translations'])) {
                    $node = $j['translations'][0];
                    if (is_array($node)) {
                        foreach (['translatedText','translated_text','translation','translated'] as $k) {
                            if (isset($node[$k]) && is_string($node[$k]) && $node[$k] !== '') return (string) $node[$k];
                        }
                    } elseif (is_string($node) && $node !== '') {
                        return (string) $node;
                    }
                }
                return '';
            };

            $translated = $pickTranslated($data);
            if ($translated !== '') {
                yuz_tra_adapter_log('YUZ-TRA: [ACT] Translation successful');
                return $translated;
            }

            // if we reach here, log a short peek of the body for diagnostics
            $peek = $body;
            if (is_string($peek)) {
                $peek = preg_replace('/\s+/u',' ', $peek);
                if ($peek === null) { $peek = ''; }
                $peek = substr($peek, 0, 200);
            }
            yuz_tra_adapter_log('YUZ-TRA: [ERROR] No translated text in response; peek=' . $peek);
            // Trace empty response for downstream diagnostics (only when uploads dir writable)
            try {
                $trace_on = (defined('YUZ_TRA_TRACE_AUTO') && YUZ_TRA_TRACE_AUTO) || !empty($_SERVER['HTTP_X_YUZ_TRACE']);
                if ($trace_on && function_exists('wp_upload_dir')) {
                    $up = wp_upload_dir();
                    if (!empty($up['basedir'])) {
                        $file = trailingslashit($up['basedir']) . 'yuz-trace.log';
                        $ctx = [
                            'adapter'  => 'libretranslate',
                            'source'   => $source_lang,
                            'target'   => $target_lang,
                            'len_in'   => strlen($text),
                            'peek'     => $peek,
                            'status'   => $status_code,
                        ];
                        $row = gmdate('Y-m-d H:i:s') . " UTC LT.EMPTY_RESPONSE " . wp_json_encode($ctx, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) . PHP_EOL;
                        yuz_tra_adapter_log($row, 3, $file);
                    }
                }
            } catch (\Throwable $ignored) {}
            throw new \RuntimeException('LT_EMPTY_BODY');
        }
        /**
         * Tests the API connection.
         *
         * @param array $settings API settings.
         * @return array Connection test result.
         */
        /**
         * {@inheritdoc}
         */
        public function test_api_conn(array $settings): bool
        {
            yuz_tra_adapter_log('YUZ-TRA: [DO] Testing LibreTranslate API connection at ' . current_time('mysql'));
            $effective = array_merge($this->config, $settings);
            $endpoint = rtrim($effective['endpoint'] ?? '', '/');
            if (empty($endpoint)) {
                yuz_tra_adapter_log('YUZ-TRA: [ERROR] No endpoint for LibreTranslate');
                return false;
            }
            // On s’assure qu’on va bien taper sur /translate
            $url = preg_match('#/translate/?$#', $endpoint) ? $endpoint : $endpoint . '/translate';
            $args = [
                'method' => 'POST',
                'timeout' => 10,
                'headers' => ['Content-Type' => 'application/json'],
                'body' => wp_json_encode([
                    'q' => 'Hello',
                    'source' => 'en',
                    'target' => 'es',
                    'api_key'=> $effective['api_key'] ?? '',
                ]),
            ];
            $response = $this->httpClient
                ? $this->httpClient->request('POST', $url, $args)
                : wp_remote_request($url, $args);
            if (is_wp_error($response)) {
                yuz_tra_adapter_log('YUZ-TRA: [ERROR] LibreTranslate test failed: ' . $response->get_error_message());
                return false;
            }
            $code = wp_remote_retrieve_response_code($response);
            if ($code < 200 || $code >= 300) {
                yuz_tra_adapter_log("YUZ-TRA: [ERROR] LibreTranslate test test returned HTTP {$code}");
                return false;
            }
            yuz_tra_adapter_log('YUZ-TRA: [ACT] LibreTranslate API OK');
            return true;
        }

        /**
         * Batch translate helper. Returns map original => translated (or null).
         */
        public function translate_batch(array $texts, string $source_lang, string $target_lang, array $settings): array {
            $texts = array_values(array_filter(array_map('strval', $texts), static function ($v) { return $v !== ''; }));
            if (empty($texts)) return [];
            $effective_settings = array_merge($this->config, $settings);
            $endpoint = rtrim($effective_settings['endpoint'] ?? '', '/');
            $endpoint = rtrim((string) apply_filters('yuz_tra_libre_translate_endpoint', $endpoint, $effective_settings), '/');
            if ($endpoint === '') {
                yuz_tra_adapter_log('YUZ-TRA: [ERROR] LibreTranslate endpoint is not configured');
                return array_fill_keys($texts, null);
            }
            $url = preg_match('#/translate/?$#', $endpoint) ? $endpoint : $endpoint . '/translate';
            $api_key = (string) apply_filters('yuz_tra_libre_translate_api_key', $effective_settings['api_key'] ?? '', $effective_settings);
            $payload = [
                'q' => array_values($texts),
                'source' => $this->map_language_code($source_lang, 'auto'),
                'target' => $this->map_language_code($target_lang, 'en'),
                'format' => 'text',
                'api_key' => $api_key,
            ];
            $args = [
                'method' => 'POST',
                'timeout' => 20,
                'headers' => ['Content-Type' => 'application/json'],
                'body' => wp_json_encode($payload),
            ];
            $response = $this->httpClient
                ? $this->httpClient->request('POST', $url, $args)
                : wp_remote_request($url, $args);
            if (is_wp_error($response)) {
                yuz_tra_adapter_log('YUZ-TRA: [ERROR] LibreTranslate batch failed: ' . $response->get_error_message());
                return [];
            }
            $code = wp_remote_retrieve_response_code($response);
            $body = wp_remote_retrieve_body($response);
            if ($code < 200 || $code >= 300) {
                yuz_tra_adapter_log("YUZ-TRA: [ERROR] LibreTranslate batch HTTP {$code}");
                return [];
            }
            $data = json_decode($body, true);
            $out = [];
            if (is_array($data)) {
                $is_list = array_keys($data) === range(0, count($data) - 1);
                // Normalise into a list to preserve order, whatever the provider shape is
                $as_list = [];
                if (isset($data['translatedText']) && is_array($data['translatedText'])) {
                    $as_list = array_values($data['translatedText']);
                } elseif ($is_list) {
                    $as_list = $data;
                } elseif (isset($data['translations']) && is_array($data['translations'])) {
                    $as_list = array_values($data['translations']);
                }

                if (!empty($as_list)) {
                    foreach ($texts as $idx => $orig) {
                        $val = $as_list[$idx] ?? null;
                        if (is_array($val) && isset($val['translatedText'])) {
                            $val = $val['translatedText'];
                        }
                        $out[$orig] = is_string($val) ? (string) $val : null;
                    }
                } else {
                    // Fallback: adapter returned a map keyed by original text
                    foreach ($texts as $orig) {
                        $val = $data[$orig] ?? null;
                        if (is_array($val) && isset($val['translatedText'])) {
                            $val = $val['translatedText'];
                        }
                        $out[$orig] = is_string($val) ? (string) $val : null;
                    }
                }
            }
            return $out;
        }
        /**
         * {@inheritdoc}
         */
        public function testConnection(array $settings): bool
        {
            // Fusionne la config injectée et ce que passe le manager
            $effective = array_merge($this->config, $settings);
            // Appel test_api_conn() qui renvoie bool directement
            return $this->test_api_conn($effective);
        }
        /**
         * Checks if the adapter supports a specific language or feature.
         *
         * @param string $feature Feature or language code to check support for.
         * @return bool True if supported, false otherwise.
         */
        public function supports(string $feature): bool {
            // Fetch available languages dynamically using config if set
            $available_languages = $this->get_available_languages($this->config);
            return in_array($feature, $available_languages);
        }
        /**
         * Fetches available languages from LibreTranslate API.
         *
         * @param array $settings API settings (endpoint, API key).
         * @return string[] Array of language codes or empty array on failure.
         */
        private function get_available_languages($settings) {
            yuz_tra_adapter_log('YUZ-TRA: [DO] Fetching available languages from LibreTranslate at ' . current_time('mysql'));
            $endpoint = rtrim($settings['endpoint'] ?? '', '/');
            if (empty($endpoint)) {
                yuz_tra_adapter_log('YUZ-TRA: [ERROR] No endpoint provided for fetching languages');
                return [];
            }
            $load_only = $settings['load_only'] ?? ($settings['languages'] ?? '');
            $cache_key = 'yuz_lt_languages_' . md5($endpoint . '|' . (is_array($load_only) ? implode(',', $load_only) : (string) $load_only) . '|' . (!empty($settings['api_key']) ? 'k' : 'nok'));
            $cache_ttl = (defined('HOUR_IN_SECONDS') ? HOUR_IN_SECONDS : 3600) * 6;
            $cached = get_transient($cache_key);
            if (is_array($cached) && !empty($cached)) {
                yuz_tra_adapter_log('YUZ-TRA: [INFO] LibreTranslate languages cache hit: ' . $cache_key);
                return $cached;
            }
            $url = $endpoint . '/languages';
            if (!empty($settings['api_key'])) {
                $url = add_query_arg('api_key', $settings['api_key'], $url);
            }
            yuz_tra_adapter_log('YUZ-TRA: [INFO] Constructed LibreTranslate languages URL: ' . $url);
            $args = [
                'method' => 'GET',
                'timeout' => 10,
                'headers' => [],
            ];
            // Make request using injected client if available, else fallback
            if ($this->httpClient) {
                // Assuming HttpClientInterface has a request method
                $response = $this->httpClient->request('GET', $url, $args);
            } else {
                $response = wp_remote_request($url, $args);
            }
            if (is_wp_error($response)) {
                yuz_tra_adapter_log('YUZ-TRA: [ERROR] Failed to fetch languages: ' . $response->get_error_message());
                return [];
            }
            $status_code = wp_remote_retrieve_response_code($response);
            $body = wp_remote_retrieve_body($response);
            yuz_tra_adapter_log('YUZ-TRA: [INFO] LibreTranslate languages response: Status ' . $status_code . ', Body: ' . $body);
            if ($status_code !== 200) {
                yuz_tra_adapter_log('YUZ-TRA: [ERROR] Failed to fetch languages with status: ' . $status_code);
                return [];
            }
            $data = json_decode($body, true);
            if (!is_array($data)) {
                yuz_tra_adapter_log('YUZ-TRA: [ERROR] Invalid language data format');
                return [];
            }
            // Accept both shapes: [{code:'en',...}] or ['en','fr',...]
            if (array_keys($data) === range(0, count($data) - 1)) {
                if (!empty($data) && is_array($data[0])) {
                    $languages = array_column($data, 'code');
                } else {
                    $languages = array_map('strval', $data);
                }
            } else {
                $languages = array_column($data, 'code');
            }
            yuz_tra_adapter_log('YUZ-TRA: [ACT] Retrieved languages: ' . implode(', ', $languages));
            if (!empty($languages)) {
                set_transient($cache_key, $languages, $cache_ttl);
            }
            return $languages;
        }
        /**
         * Maps WordPress locale to LibreTranslate language code.
         *
         * @param string $locale The WordPress locale (e.g., 'en_US').
         * @return string The mapped language code (e.g., 'en').
         */
        private function map_language_code(string $locale, string $fallback = 'auto'): string {
            $normalized = strtolower(trim($locale));
            if ($normalized === '' || $normalized === 'auto') {
                return $fallback;
            }

            $normalized = str_replace([' ', '.', '_'], '-', $normalized);

            $aliases = [
                'zh-cn' => 'zh',
                'zh-tw' => 'zh',
                'zh-hans' => 'zh',
                'zh-hant' => 'zh',
                'pt-br' => 'pt',
                'pt-pt' => 'pt',
                'iw' => 'he',
                'iw-il' => 'he',
                'no' => 'nb',
                'nb-no' => 'nb',
                'nn' => 'nb',
                'nn-no' => 'nb',
            ];

            if (isset($aliases[$normalized])) {
                return $aliases[$normalized];
            }

            $root = substr($normalized, 0, 2);
            return $root !== '' ? $root : $fallback;
        }
    }
}
// ACT: Log class creation
if (class_exists('YUZ_Libre_Translate_Adapter')) {
    yuz_tra_adapter_log('YUZ-TRA: [ACT] YUZ_Libre_Translate_Adapter created successfully at ' . current_time('mysql'));
} else {
    yuz_tra_adapter_log('YUZ-TRA: [ACT] Failed to create YUZ_Libre_Translate_Adapter at ' . current_time('mysql'));
}
// Note: Add unit tests for translate(), test_api_conn(), and supports() in a separate test file.
// Example test: assertTrue($adapter->supports('en'));.

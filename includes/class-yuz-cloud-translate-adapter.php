<?php
/**
 * Class YUZTRA_Cloud_Translate_Adapter
 *
 * Official Cloud adapter for Youzurz TRS Engine.
 * Provides high-speed, sovereign AI translation without requiring local server setup.
 *
 * @package YUZ_Translation
 */

if (!defined('ABSPATH')) { exit; }

require_once YUZTRA_INCLUDES . 'class-yuz-contracts.php';
require_once YUZTRA_INCLUDES . 'class-yuz-translate-adapter.php';

use YUZTRA\Interfaces\TranslateAdapterInterface;

if (!class_exists('YUZTRA_Cloud_Translate_Adapter')) {
    class YUZTRA_Cloud_Translate_Adapter extends YUZTRA_Translate_Adapter implements TranslateAdapterInterface {
        
        const DEFAULT_CLOUD_ENDPOINT = 'https://ltr.youzurz.com/translate';

        /**
         * Test connectivity to the Youzurz TRS Cloud Engine.
         *
         * @param array $settings Adapter settings.
         * @return bool True if connection is healthy, false otherwise.
         */
        public function testConnection(array $settings): bool {
            return $this->test_api_conn($settings);
        }

        /**
         * Compatibility method for TranslateAdapterInterface.
         *
         * @param array $settings Adapter settings.
         * @return bool True if connection is healthy, false otherwise.
         */
        public function test_api_conn(array $settings): bool {
            $endpoint = !empty($settings['cloud_endpoint']) ? esc_url_raw($settings['cloud_endpoint']) : self::DEFAULT_CLOUD_ENDPOINT;

            $test_payload = [
                'q'      => 'Hello',
                'source' => 'en',
                'target' => 'fr',
                'format' => 'text'
            ];

            $response = wp_remote_post($endpoint, [
                'timeout'     => 10,
                'headers'     => [
                    'Content-Type' => 'application/json',
                    'Accept'       => 'application/json',
                ],
                'body'        => wp_json_encode($test_payload),
                'sslverify'   => true,
            ]);

            if (is_wp_error($response)) {
                if (function_exists('yuztra_debug_log')) {
                    yuztra_debug_log('YUZ-TRA Cloud testConnection WP_Error: ' . $response->get_error_message());
                }
                return false;
            }

            $code = wp_remote_retrieve_response_code($response);
            return ($code >= 200 && $code < 300);
        }

        /**
         * Translates text using the Youzurz TRS Cloud API.
         *
         * @param string $text Source text to translate.
         * @param string $source_lang Source language code (e.g. 'fr', 'en').
         * @param string $target_lang Target language code.
         * @param array $settings Settings containing cloud token & endpoint.
         * @return string|null Translated string or null on failure.
         */
        public function translate($text, $source_lang, $target_lang, $settings): ?string {
            if (empty(trim($text))) {
                return $text;
            }

            $endpoint = !empty($settings['cloud_endpoint']) ? esc_url_raw($settings['cloud_endpoint']) : self::DEFAULT_CLOUD_ENDPOINT;
            $token    = !empty($settings['cloud_token']) ? sanitize_text_field($settings['cloud_token']) : '';

            // Normalize language codes (e.g. 'fr_FR' -> 'fr')
            $src = strtolower(explode('_', str_replace('-', '_', $source_lang))[0]);
            $tgt = strtolower(explode('_', str_replace('-', '_', $target_lang))[0]);

            $payload = [
                'q'      => $text,
                'source' => $src,
                'target' => $tgt,
                'format' => 'html'
            ];

            $headers = [
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
            ];

            if (!empty($token)) {
                $headers['Authorization'] = 'Bearer ' . $token;
            }

            $response = wp_remote_post($endpoint, [
                'timeout'   => 15,
                'headers'   => $headers,
                'body'      => wp_json_encode($payload),
                'sslverify' => true,
            ]);

            if (is_wp_error($response)) {
                if (function_exists('yuztra_debug_log')) {
                    yuztra_debug_log('YUZ-TRA Cloud translate WP_Error: ' . $response->get_error_message());
                }
                return null;
            }

            $code = wp_remote_retrieve_response_code($response);
            if ($code < 200 || $code >= 300) {
                if (function_exists('yuztra_debug_log')) {
                    yuztra_debug_log('YUZ-TRA Cloud translate non-200 response: ' . $code);
                }
                return null;
            }

            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body, true);

            if (isset($data['translatedText']) && is_string($data['translatedText'])) {
                return $data['translatedText'];
            }

            return null;
        }
    }
}

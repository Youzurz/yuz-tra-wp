<?php
/**
 * Class YUZ_API_Manager
 *
 * Automatic translation adapters manager (migrated from former YUZ_Translation_Manager).
 */

defined('ABSPATH') || exit;

require_once YUZ_TRA_INCLUDES . 'class-yuz-contracts.php';
require_once YUZ_TRA_INCLUDES . 'class-yuz-fallbacks.php';

use YUZTRA\Interfaces\TranslateAdapterInterface;
use YUZTRA\Interfaces\TranslationManagerInterface;
use YUZTRA\Interfaces\SettingsInterface;
use YUZTRA\Interfaces\DBInterface;
use YUZTRA\Interfaces\LoggerInterface;
use YUZTRA\Interfaces\AjaxInterface;
use YUZTRA\Interfaces\Language;
use YUZTRA\Interfaces\LanguagesInterface;
use YUZTRA\Fallbacks\NullTranslateAdapter;
use YUZTRA\Fallbacks\NullLanguages;
use YUZTRA\Fallbacks\NullAjax;
use YUZTRA\Fallbacks\NullDB;
use YUZTRA\Fallbacks\NullLogger;
use YUZTRA\Fallbacks\NullTranslationManager;
use YUZTRA\Fallbacks\NullLanguageManager;

if (!class_exists('YUZ_API_Manager')) {
class YUZ_API_Manager implements TranslationManagerInterface {
    private $adapters = [];
    private SettingsInterface $settings;
    private array $api_settings = [];
    private $languages;
    private $ajax;
    private $db;
    private $logger;
    private $language_cache = [];

    public function __construct(
        array $adapters,
        SettingsInterface $settings,
        LanguagesInterface $languages = null,
        AjaxInterface $ajax = null,
        DBInterface $db = null,
        LoggerInterface $logger = null
    ) {
        $this->adapters = $adapters;
        $this->settings = $settings;
        $this->languages = $languages ?? new NullLanguages();
        $this->ajax = $ajax ?? new NullAjax();
        $this->db = $db ?? new NullDB();
        $this->logger = $logger ?? new NullLogger();
        $this->register_adapters();
        $this->logger->log('info', 'YUZ_API_Manager instancié', ['class' => __CLASS__]);
    }

    public static function init(): void {
        $logger = class_exists('YUZ_Logger') ? new YUZ_Logger() : new NullLogger();
        $logger->log('info', 'YUZ_API_Manager init: hooks set.', ['class' => __CLASS__]);
    }

    public function setAjax(AjaxInterface $ajax): void { $this->ajax = $ajax; }

    private function register_adapters() {
        $this->logger->log('info', 'Adapters status (lazy): ' . implode(', ', array_keys($this->adapters)), ['class' => __CLASS__]);
    }

    private function get_language_code(int $lang_id): ?string {
        global $wpdb;
        $table = $wpdb->prefix . 'yuz_tra_languages';
        return $wpdb->get_var($wpdb->prepare("SELECT language_code FROM $table WHERE id = %d", $lang_id));
    }

    private function get_language_id_with_retry(string $language_code): ?int {
        global $wpdb;
        if (isset($this->language_cache[$language_code])) return $this->language_cache[$language_code];
        $attempts = 3;
        while ($attempts > 0) {
            $lang_id = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}yuz_tra_languages WHERE language_code = %s",
                $language_code
            ));
            if ($lang_id !== null) { $this->language_cache[$language_code] = (int)$lang_id; return (int)$lang_id; }
            $attempts--; usleep(100000);
        }
        return null;
    }

    private function check_existing_translation(int $post_id, string $target_language): int {
        global $wpdb;
        $table_name = $wpdb->prefix . 'yuz_tra_translations';
        $attempts = 3;
        while ($attempts > 0) {
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $table_name WHERE post_id = %d AND context = 'content' AND language_code = %s",
                $post_id, $target_language
            ));
            if ($existing !== null) return (int)$existing;
            $attempts--; usleep(100000);
        }
        return 0;
    }

    private function store_translation(int $post_id, string $original_text, string $translated_text, int $source_lang_id, int $target_lang_id, string $target_language): bool {
        global $wpdb;
        $table_name = $wpdb->prefix . 'yuz_tra_translations';
        $attempts = 3;
        while ($attempts > 0) {
            $result = $wpdb->insert($table_name, [
                'post_id'=>$post_id,'context'=>'content','block_id'=>'','original_text'=>$original_text,
                'translated_text'=>$translated_text,'translated_slug'=>null,'source_lang_id'=>$source_lang_id,
                'target_lang_id'=>$target_lang_id,'language_code'=>$target_language,'status'=>1,'origin'=>'machine',
                'revision_of'=>null,'created_at'=>current_time('mysql'),'updated_at'=>current_time('mysql')
            ], ['%d','%s','%s','%s','%s','%s','%d','%d','%s','%d','%s','%s','%s','%s']);
            if ($result !== false) return true;
            $attempts--; usleep(100000);
        }
        return false;
    }

    public function set_api_settings($settings) { $this->api_settings = $settings; }

    public function translate(string $text, int $source_lang_id, int $target_lang_id): ?string {
        $flags = $this->settings->runtime_flags();
        if (!$flags['mode_manual_enabled'] && !$flags['mode_semi_enabled'] && !$flags['mode_background_enabled']) return null;
        $source_lang = $this->get_language_code($source_lang_id);
        $target_lang = $this->get_language_code($target_lang_id);
        if (!$source_lang || !$target_lang) return null;
        if (empty($this->api_settings['api_type']) || !isset($this->adapters[$this->api_settings['api_type']])) return null;
        $source_lang = str_replace('-', '_', $source_lang); $target_lang = str_replace('-', '_', $target_lang);
        $adapter = $this->adapters[$this->api_settings['api_type']];
        $translated_text = $adapter->translate($text, $source_lang, $target_lang, $this->api_settings);
        if ($translated_text === null) {
            foreach (array_keys($this->adapters) as $alt_adapter) {
                if ($alt_adapter === $this->api_settings['api_type']) continue;
                $translated_text = $this->adapters[$alt_adapter]->translate($text, $source_lang, $target_lang, $this->api_settings);
                if ($translated_text !== null) break;
            }
        }
        return $translated_text ?? null;
    }

    /**
     * Batch translation helper (best-effort). Returns assoc original => translated (or null on miss).
     */
    public function translate_batch(array $texts, int $source_lang_id, int $target_lang_id): array {
        $result = [];
        if (empty($texts)) return $result;

        $flags = $this->settings->runtime_flags();
        if (!$flags['mode_manual_enabled'] && !$flags['mode_semi_enabled'] && !$flags['mode_background_enabled']) {
            return $result;
        }

        $source_lang = $this->get_language_code($source_lang_id);
        $target_lang = $this->get_language_code($target_lang_id);
        if (!$source_lang || !$target_lang) return $result;
        $source_lang = str_replace('-', '_', $source_lang);
        $target_lang = str_replace('-', '_', $target_lang);

        $provider = $this->api_settings['api_type'] ?? null;
        $adapters = $provider && isset($this->adapters[$provider]) ? [$provider] : [];
        foreach (array_keys($this->adapters) as $alt) {
            if (!in_array($alt, $adapters, true)) $adapters[] = $alt;
        }
        $is_list = static function (array $arr): bool {
            return array_keys($arr) === range(0, count($arr) - 1);
        };

        foreach ($adapters as $adapter_key) {
            if (!isset($this->adapters[$adapter_key])) continue;
            $adapter = $this->adapters[$adapter_key];
            try {
                if (method_exists($adapter, 'translate_batch')) {
                    $translated = $adapter->translate_batch($texts, $source_lang, $target_lang, $this->api_settings);
                    if (is_array($translated)) {
                        if (isset($translated['translatedText']) && is_array($translated['translatedText'])) {
                            foreach ($texts as $idx => $orig) {
                                $result[$orig] = $translated['translatedText'][$idx] ?? null;
                            }
                            return $result;
                        }
                        if ($is_list($translated)) {
                            foreach ($texts as $idx => $orig) {
                                $val = $translated[$idx] ?? null;
                                if (is_array($val) && isset($val['translatedText'])) {
                                    $val = $val['translatedText'];
                                }
                                $result[$orig] = is_string($val) ? $val : null;
                            }
                            return $result;
                        }
                        return $translated;
                    }
                }
            } catch (\Throwable $e) {
                $this->logger->log('warning', 'translate_batch adapter error', [
                    'adapter' => $adapter_key,
                    'message' => $e->getMessage()
                ]);
            }
        }

        // Fallback to per-item translate to preserve behaviour
        foreach ($texts as $t) {
            $result[$t] = $this->translate($t, $source_lang_id, $target_lang_id);
        }
        return $result;
    }

    public function isConfigured(): bool {
        try {
            $api = get_option('yuz_tra_api_settings', []);
            $provider = $api['api_provider'] ?? ($this->api_settings['api_type'] ?? null);
            if (!$provider) return false;
            return isset($this->adapters[$provider]);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Implements TranslationManagerInterface::test_api_conn
     * Delegates to the currently selected adapter and wraps result.
     *
     * @param array $settings {provider?, endpoint?, api_key?, extra_settings?}
     * @return array {success: bool, message: string, translatedText?: string}
     */
    public function test_api_conn(array $settings) {
        try {
            // Resolve provider from explicit settings, instance settings, or stored option
            $api_opt  = is_array($settings) ? $settings : [];
            $api_db   = get_option('yuz_tra_api_settings', []);
            $provider = $api_opt['provider']
                ?? $api_opt['engine']
                ?? ($this->api_settings['api_type'] ?? null)
                ?? ($api_db['api_provider'] ?? null);

            if (!$provider || !isset($this->adapters[$provider])) {
                return [ 'success' => false, 'message' => 'Adapter not found or provider missing' ];
            }

            $adapter = $this->adapters[$provider];

            // Primary path: adapter exposes test_api_conn(array): bool
            if (method_exists($adapter, 'test_api_conn')) {
                $ok = (bool) $adapter->test_api_conn($settings);
                return [ 'success' => $ok, 'message' => $ok ? 'OK' : 'Connection failed' ];
            }

            // Fallbacks: legacy/test methods sometimes named differently
            if (method_exists($adapter, 'testConnection')) {
                $ok = (bool) $adapter->testConnection($settings);
                return [ 'success' => $ok, 'message' => $ok ? 'OK' : 'Connection failed' ];
            }
            if (method_exists($adapter, 'ping')) {
                $ok = (bool) $adapter->ping();
                return [ 'success' => $ok, 'message' => $ok ? 'OK' : 'Ping failed' ];
            }

            // Last resort: no-op success but explicit
            return [ 'success' => true, 'message' => 'No dedicated test. Using noop.' ];
        } catch (\Throwable $e) {
            if (class_exists('WP_Error')) {
                return new \WP_Error('yuz_api_test', $e->getMessage());
            }
            return [ 'success' => false, 'message' => $e->getMessage() ];
        }
    }

    public function run_full_site_translation(): void {
        // Minimal placeholder: real batch logic can be provided by dedicated class (Automatic Translation)
        if (method_exists('YUZ_Automatic_Translation','queue_full_site')) {
            try { \YUZ_Automatic_Translation::queue_full_site(); return; } catch (\Throwable $e) {}
        }
        if ($this->logger) {
            $this->logger->log('info', '[API] run_full_site_translation invoked (no-op placeholder)');
        }
    }
}
}

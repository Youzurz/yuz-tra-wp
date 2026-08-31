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

    public function set_api_settings($settings) {
        $this->api_settings = $this->resolve_api_settings(is_array($settings) ? $settings : []);
    }

    private function normalize_api_settings(array $settings): array {
        $normalized = $settings;
        $extra = [];

        if (isset($normalized['extra_settings'])) {
            if (is_array($normalized['extra_settings'])) {
                $extra = $normalized['extra_settings'];
            } elseif (is_string($normalized['extra_settings'])) {
                $decoded = json_decode($normalized['extra_settings'], true);
                if (is_array($decoded)) {
                    $extra = $decoded;
                }
            }
        }

        foreach ($extra as $key => $value) {
            if (!array_key_exists($key, $normalized) || $normalized[$key] === '' || $normalized[$key] === null) {
                $normalized[$key] = $value;
            }
        }

        // The canonical selector wins over stale legacy aliases merged by the options bridge.
        $provider = $normalized['api_provider']
            ?? $normalized['provider']
            ?? $normalized['api_type']
            ?? $normalized['api_adapter']
            ?? null;

        if ($provider) {
            if (!empty($normalized['api_type']) && $normalized['api_type'] !== $provider) {
                unset($normalized['endpoint'], $normalized['api_key']);
            }
            $normalized['api_type'] = $provider;
            $normalized['provider'] = $provider;
            $normalized['api_provider'] = $provider;

            $prefix = $provider === 'libretranslate' ? 'libre' : $provider;
            if ($provider === 'openai' && empty($normalized['model']) && !empty($normalized['openai_model'])) {
                $normalized['model'] = $normalized['openai_model'];
            }
            $endpoint_key = $prefix . '_url';
            $api_key_key = $prefix . '_key';

            if (empty($normalized[$endpoint_key]) && !empty($normalized[$provider . '_url'])) {
                $normalized[$endpoint_key] = $normalized[$provider . '_url'];
            }
            if (empty($normalized[$api_key_key]) && !empty($normalized[$provider . '_key'])) {
                $normalized[$api_key_key] = $normalized[$provider . '_key'];
            }

            if (empty($normalized['endpoint']) && !empty($normalized[$endpoint_key])) {
                $normalized['endpoint'] = $normalized[$endpoint_key];
            }

            if (empty($normalized['api_key']) && !empty($normalized[$api_key_key])) {
                $normalized['api_key'] = $normalized[$api_key_key];
            }

            if (empty($normalized['endpoint'])) {
                if ($provider === 'google') {
                    $normalized['endpoint'] = 'https://translation.googleapis.com/language/translate/v2';
                } elseif ($provider === 'deepl') {
                    $normalized['endpoint'] = !empty($normalized['deepl_free'])
                        ? 'https://api-free.deepl.com/v2/translate'
                        : 'https://api.deepl.com/v2/translate';
                } elseif ($provider === 'openai') {
                    $normalized['endpoint'] = 'https://api.openai.com/v1/chat/completions';
                }
            }
        }

        return $normalized;
    }

    private function resolve_api_settings(array $settings = []): array {
        $explicit = $this->normalize_api_settings($settings);
        if (!empty($explicit['api_type']) && isset($this->adapters[$explicit['api_type']])) {
            return $explicit;
        }

        $current = $this->normalize_api_settings($this->api_settings);
        if (!empty($current['api_type']) && isset($this->adapters[$current['api_type']])) {
            return array_merge($current, $explicit);
        }

        $stored = get_option('yuz_tra_at_settings', []);
        if (!is_array($stored) || empty($stored)) {
            $stored = get_option('yuz_tra_api_settings', []);
        }

        if (!is_array($stored)) {
            $stored = [];
        }

        return $this->normalize_api_settings(array_merge($stored, $current, $explicit));
    }

    public function translate(string $text, int $source_lang_id, int $target_lang_id): ?string {
        $flags = $this->settings->runtime_flags();
        if (!$flags['mode_manual_enabled'] && !$flags['mode_semi_enabled'] && !$flags['mode_background_enabled']) return null;
        $source_lang = $this->get_language_code($source_lang_id);
        $target_lang = $this->get_language_code($target_lang_id);
        if (!$source_lang || !$target_lang) return null;
        return $this->translate_text($text, $source_lang, $target_lang);
    }

    /** Single provider channel shared by editors and background jobs. */
    public function translate_text(string $text, string $source, string $target, array $context = []): string {
        if ($text === '' || strlen($text) > 20000 || $target === '' || $target === 'auto') throw new InvalidArgumentException('invalid_translation_request');
        $source = str_replace('-', '_', $source);
        $target = str_replace('-', '_', $target);
        if (strcasecmp($source, $target) === 0) return $text;
        $api = $this->resolve_api_settings();
        $provider = $api['api_type'] ?? '';
        require_once YUZ_TRA_INCLUDES . 'class-yuz-translation-budget.php';
        $context = array_intersect_key($context, array_flip(['domain','context','original','placeholders','deadline']));
        $retrieved = YUZ_Translation_Memory::retrieve($text,$source,$target,$context);
        if ($retrieved['exact'] !== null) {
            return strtr($retrieved['exact'], array_flip($context['placeholders'] ?? []));
        }
        if (!$provider || !isset($this->adapters[$provider]) || empty($api['endpoint'])) throw new RuntimeException('translation_provider_not_configured');
        $api['translation_context']=$context;
        $api['retrieved_context']=$retrieved;
        $api['deadline']=$context['deadline'] ?? 0;
        $cache_api=array_intersect_key($api,array_flip(['endpoint','model','model_revision','api_key',
            'num_ctx','num_predict','temperature','deepl_free','alternatives','google_project',
            'custom_auth','custom_method','custom_format','translation_context','retrieved_context']));
        unset($cache_api['deadline'],$cache_api['translation_context']['deadline']);
        $fingerprint = wp_json_encode([$provider,hash('sha256',wp_json_encode($cache_api)), $source, $target, 'translation-1']);
        return YUZ_Translation_Budget::run($text, $fingerprint, function () use ($text,$source,$target,$api,$provider) {
            $result=$this->adapters[$provider]->translate($text, $source, $target, $api);
            if (!is_string($result) || trim($result)==='') throw new RuntimeException('empty_provider_translation');
            if (strlen($result)>40000) throw new RuntimeException('translation_output_too_large');
            if (YUZ_String_Catalog::tokens($text)!==YUZ_String_Catalog::tokens($result)) throw new RuntimeException('provider_changed_placeholder');
            preg_match_all('/YUZKEEP[0-9]+TOKEN/',$text,$before);
            preg_match_all('/YUZKEEP[0-9]+TOKEN/',$result,$after);
            sort($before[0]); sort($after[0]);
            if ($before[0]!==$after[0]) throw new RuntimeException('provider_changed_placeholder');
            return $result;
        });
    }
    public function requires_review(): bool {
        return ($this->resolve_api_settings()['api_type'] ?? '') === 'ollama';
    }

    /**
     * Batch translation helper (best-effort). Returns assoc original => translated (or null on miss).
     */
    public function translate_batch(array $texts, int $source_lang_id, int $target_lang_id): array {
        $result = [];
        foreach (array_unique($texts) as $text) $result[$text] = $this->translate($text, $source_lang_id, $target_lang_id);
        return $result;
    }

    public function isConfigured(): bool {
        try {
            $api = $this->resolve_api_settings();
            $provider = $api['api_provider'] ?? ($api['api_type'] ?? null);
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
            $api_opt  = $this->resolve_api_settings(is_array($settings) ? $settings : []);
            $provider = $api_opt['provider']
                ?? $api_opt['engine']
                ?? ($api_opt['api_type'] ?? null);

            if (!$provider || !isset($this->adapters[$provider])) {
                return [ 'success' => false, 'message' => 'Adapter not found or provider missing' ];
            }

            $adapter = $this->adapters[$provider];

            // Primary path: adapter exposes test_api_conn(array): bool
            if (method_exists($adapter, 'test_api_conn')) {
                $result = $adapter->test_api_conn($api_opt);
                $ok = is_array($result) ? (($result['success'] ?? false) === true) : $result === true;
                return [ 'success' => $ok, 'message' => $ok ? 'OK' : 'Connection failed' ];
            }

            // Fallbacks: legacy/test methods sometimes named differently
            if (method_exists($adapter, 'testConnection')) {
                $result = $adapter->testConnection($api_opt);
                $ok = is_array($result) ? (($result['success'] ?? false) === true) : $result === true;
                return [ 'success' => $ok, 'message' => $ok ? 'OK' : 'Connection failed' ];
            }
            if (method_exists($adapter, 'ping')) {
                $ok = (bool) $adapter->ping();
                return [ 'success' => $ok, 'message' => $ok ? 'OK' : 'Ping failed' ];
            }

            return [ 'success' => false, 'message' => 'Provider connection test unavailable' ];
        } catch (\Throwable $e) {
            if (class_exists('WP_Error')) {
                return new \WP_Error('yuz_api_test', $e->getMessage());
            }
            return [ 'success' => false, 'message' => $e->getMessage() ];
        }
    }

    public function run_full_site_translation(): void {
        // WP-Cron already owns the worker at this point; do not requeue a no-op.
        if (function_exists("wp_doing_cron") && wp_doing_cron()
            && class_exists("YUZ_Cron") && method_exists("YUZ_Cron", "run_batch")) {
            \YUZ_Cron::run_batch();
            return;
        }
        if (method_exists("YUZ_Automatic_Translation", "queue_full_site")) {
            try { \YUZ_Automatic_Translation::queue_full_site(); } catch (\Throwable $e) {
                if ($this->logger) {
                    $this->logger->log("warning", "[API] Unable to queue full-site translation", ["error" => $e->getMessage()]);
                }
            }
        }
    }
}
}

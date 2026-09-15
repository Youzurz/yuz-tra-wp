<?php
/**
 * Front-end orchestrator with HARD probes & truthful diagnostics.
 * No fake "success": every step is measured, logged, and exposed.
 *
 * @package YUZ_Translation
 */

defined('ABSPATH') || exit;

require_once YUZ_TRA_INCLUDES . 'class-yuz-front-renderer.php';

use YUZTRA\Interfaces\AssetsInterface;
use YUZTRA\Interfaces\FrontendInterface;
use YUZTRA\Interfaces\LanguageManagerInterface;

if (!class_exists('YUZ_Frontend')) {
    final class YUZ_Frontend implements FrontendInterface {
        /** True when the active language matches the detected source language. */
        private bool $active_language_is_source = true;
        /** Optional logger if available (YUZ_Logger or compatible PSR-3 style). */
        private $logger = null;
        /** Per-request correlation id. */
        private string $trace_id;
        /** In-memory counters for this request. */
        private array $counters = [
            'content_subst'   => 0,
            'title_subst'     => 0,
            'excerpt_subst'   => 0,
            'fallbacks'       => 0,
            'empty_hits'      => 0,
            'wp_errors'       => 0,
        ];
        /** Probe results collected during setup(). */
        private array $probe_results = [];
        /** Enable/disable heavy probes; overridable via option or query param. */
        private bool $probes_enabled = false;

        public function __construct() {
            $this->trace_id = substr(wp_hash(microtime(true) . random_int(PHP_INT_MIN, PHP_INT_MAX)), 0, 12);

            if (class_exists('YUZ_Logger')) {
                try {
                    $this->logger = new YUZ_Logger();
                } catch (\Throwable $e) {
                    $this->logger = null;
                }
            }

            // Hard shutdown probe to catch fatals in our request lifecycle.
            register_shutdown_function([$this, 'shutdown_probe']);
        }

        /** Bootstrap hooks. */
        public static function init(?LanguageManagerInterface $languageManager = null, ?AssetsInterface $assets = null): void {
            unset($languageManager, $assets);
            $instance = new self();

            // Correlation header as early as possible.
            add_action('send_headers', static function() use ($instance) {
                if (!headers_sent()) {
                    // Remplace toute trace préexistante pour éviter les doublons qui grossissent les headers.
                    header('X-YUZ-Trace: ' . $instance->trace_id, true);
                }
            }, 1);

            add_action('rest_api_init', [$instance, 'register_rest']);
            add_action('wp', [$instance, 'setup'], 20);

            // Menus translation stays through renderer.
            add_filter('wp_nav_menu_objects', [YUZ_Front_Renderer::class, 'translate_menu_items'], 60);

            // Optional: block native browser translation (front only).
            add_filter('language_attributes', [$instance, 'filter_language_attributes'], 20);
            add_action('wp_head', [$instance, 'emit_browser_translation_blocker'], 2);

            // Allow opt-in console beacon for JS errors (disabled by default).
            add_action('wp_head', [$instance, 'emit_debug_beacon'], 999);

            // AJAX minimal probe (used by self-check if enabled).
            add_action('wp_ajax_yuz_probe', [$instance, 'ajax_probe']);
        }

        /** Register REST endpoints for health and client error intake. */
        public function register_rest(): void {
            if (!function_exists('register_rest_route')) {
                return;
            }
            register_rest_route('yuz/v1', '/health', [
                'methods'  => 'GET',
                'callback' => function() {
                    return rest_ensure_response($this->health_payload());
                },
                'permission_callback' => static function() {
                    return current_user_can('manage_options');
                },
            ]);

            register_rest_route('yuz/v1', '/js-error', [
                'methods'  => 'POST',
                'callback' => function(WP_REST_Request $req) {
                    $payload = [
                        'message' => substr(sanitize_text_field((string) $req->get_param('message')), 0, 500),
                        'stack'   => substr(sanitize_textarea_field((string) $req->get_param('stack')), 0, 4000),
                        'source'  => esc_url_raw((string) $req->get_param('source')),
                        'line'    => (int) ($req->get_param('line') ?? 0),
                        'col'     => (int) ($req->get_param('col') ?? 0),
                        'ua'      => isset($_SERVER['HTTP_USER_AGENT']) ? substr(sanitize_text_field(wp_unslash(sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])))), 0, 255) : '',
                    ];
                    $this->log('error', 'js_error', $payload);
                    return rest_ensure_response(['ok' => true]);
                },
                'permission_callback' => function() {
                    return is_user_logged_in()
                        && (current_user_can('manage_options') || current_user_can('yuz_translate_content'));
                },
            ]);
        }

        /** Register filters once query is set. */
        public function setup(): void {
            if (is_admin()) {
                return;
            }

            // Probes can be toggled runtime.
            $this->probes_enabled = (bool) get_option('yuz_probes_enabled', false);

            $active  = YUZ_Front_Renderer::get_active_language();
            $default = YUZ_Front_Renderer::get_default_language();
            $source  = YUZ_Front_Renderer::get_source_language();

            $this->log('info', 'setup', [
                'active_lang'  => $active,
                'default_lang' => $default,
                'source_lang'  => $source,
                'is_singular'  => is_singular(),
                'queried_id'   => get_queried_object_id(),
                'trace_id'     => $this->trace_id,
                'probes'       => $this->probes_enabled,
            ]);

            $this->active_language_is_source = (strcasecmp($active, $source) === 0);

            // Collect environment probes (truthful status, no greenwashing).
            if ($this->probes_enabled) {
                $this->probe_results = $this->probe_environment($active, $default);
            }

            if ($this->active_language_is_source) {
                return; // nothing to translate for source language.
            }

            add_filter('the_content', [$this, 'filter_content'], 60);
            add_filter('the_excerpt', [$this, 'filter_excerpt'], 60);
            add_filter('the_title',   [$this, 'filter_title'], 60, 2);
            add_filter('single_post_title', [$this, 'filter_single_title'], 60);
            add_filter('get_the_archive_title', [$this, 'filter_archive_title'], 60, 2);
            add_filter('document_title_parts', [$this, 'filter_document_title_parts'], 60);
        }

        /**
         * Add translate="no" to <html> and allow YoUZurz to keep translating.
         *
         * @param string $output Current language attributes.
         * @return string
         */
        public function filter_language_attributes(string $output): string {
            if (is_admin() || !$this->should_block_browser_translation()) {
                return $output;
            }
            $attrs = $this->append_html_attribute($output, 'translate', 'no');
            // Allow YoUZurz DOM translator to ignore only the root-level translate="no".
            $attrs = $this->append_html_attribute($attrs, 'data-yuz-translate-allow-root', 'true');
            return $attrs;
        }

        /**
         * Emit notranslate metadata for Chromium-based browsers.
         */
        public function emit_browser_translation_blocker(): void {
            if (is_admin() || !$this->should_block_browser_translation()) {
                return;
            }
            echo "\n" . '<meta name="google" content="notranslate" />' . "\n";
        }

        /**
         * Decide if native browser translation should be blocked.
         */
        private function should_block_browser_translation(): bool {
            $settings = function_exists('yuz_settings_get_all') ? (array) yuz_settings_get_all() : [];
            $ts = $settings['yuz_tra_ts_settings'] ?? null;
            if (!is_array($ts)) {
                $ts = get_option('yuz_tra_ts_settings', []);
            }
            return !empty($ts['block_browser_translation']);
        }

        /**
         * Safely append an HTML attribute if missing.
         */
        private function append_html_attribute(string $attrs, string $name, string $value): string {
            $pattern = '/\\b' . preg_quote($name, '/') . '\\s*=\\s*([\"\']).*?\\1/i';
            if (preg_match($pattern, $attrs)) {
                return $attrs;
            }
            $attrs = trim($attrs);
            $suffix = $name . '="' . esc_attr($value) . '"';
            return $attrs === '' ? $suffix : ($attrs . ' ' . $suffix);
        }

        /** Content filter. */
        public function filter_content(string $content): string {
            if ($content === '' || $this->active_language_is_source) {
                return $content;
            }
            $post_id = $this->get_current_post_id();
            if ($post_id <= 0) {
                $this->log('debug', 'filter_content skipped (no post)', ['len' => strlen($content)]);
                return $content;
            }
            $translated = YUZ_Front_Renderer::translate_post_field($content, $post_id, 'content');
            if ($translated === '') {
                $this->counters['empty_hits']++;
            }
            $translated = $this->verify_integrity('content', $content, $translated, $post_id);
            $this->counters['content_subst'] += (int) ($translated !== $content);

            $this->log('debug', 'filter_content', [
                'post_id' => $post_id,
                'len_src' => strlen($content),
                'len_out' => strlen($translated),
                'changed' => $translated !== $content,
            ]);
            return $translated;
        }

        /** Excerpt filter. */
        public function filter_excerpt(string $excerpt): string {
            if ($excerpt === '' || $this->active_language_is_source) {
                return $excerpt;
            }
            $post_id = $this->get_current_post_id();
            $translated = YUZ_Front_Renderer::translate_post_field($excerpt, $post_id, 'excerpt');
            if ($translated === '') {
                $this->counters['empty_hits']++;
            }
            $translated = $this->verify_integrity('excerpt', $excerpt, $translated, $post_id);
            $this->counters['excerpt_subst'] += (int) ($translated !== $excerpt);
            return $translated;
        }

        /** Title filter (core the_title). */
        public function filter_title(string $title, $post_id = 0): string {
            if ($title === '' || $this->active_language_is_source) {
                return $title;
            }
            $pid = (int) ($post_id ?: $this->get_current_post_id());
            $translated = YUZ_Front_Renderer::translate_post_field($title, $pid, 'title');
            if ($translated === '') {
                $this->counters['empty_hits']++;
            }
            $translated = $this->verify_integrity('title', $title, $translated, $pid);
            $this->counters['title_subst'] += (int) ($translated !== $title);

            $this->log('debug', 'filter_title', [
                'post_id' => $pid,
                'len_src' => strlen($title),
                'len_out' => strlen($translated),
                'changed' => $translated !== $title,
            ]);
            return wp_kses_post($translated);
        }

        /** Title from single_post_title(). */
        public function filter_single_title(string $title): string {
            return $this->filter_title($title, $this->get_current_post_id());
        }

        /** Archive title pass-through (kept for future i18n). */
        public function filter_archive_title(string $title, string $original): string {
            unset($original);
            if ($this->active_language_is_source) {
                return $title;
            }
            return $title; // no-op for now; still recorded by debug beacons.
        }

        /** Document title parts hook. */
        public function filter_document_title_parts(array $parts): array {
            if ($this->active_language_is_source) {
                return $parts;
            }
            // We keep document title untouched for now; only log metrics.
            $this->log('debug', 'document_title_parts', ['parts' => array_keys($parts)]);
            return $parts;
        }

        /** Current post id from main query if available. */
        private function get_current_post_id(): int {
            if (is_singular()) {
                $id = get_queried_object_id();
                if ($id) {
                    return (int) $id;
                }
            }
            return 0;
        }

        /**
         * Integrity & sanity checks for a substitution.
         *
         * Rules:
         *  - Never hide metrics: everything is logged for post-mortem analysis.
         *  - Hard fallback whenever the markup looks unsafe (`status === fail`).
         *  - Allow the site owner (option/filter) to fallback even on "suspect" cases.
         */
        private function verify_integrity(string $context, string $source, string $translated, int $post_id): string {
            $metrics = $this->collect_metrics($context, $source, $translated, $post_id);

            // Always log the full metrics payload.
            $this->log('debug', 'integrity_metrics', $metrics);
            $status = $metrics['status'] ?? 'ok';
            if ($status !== 'ok') {
                $level   = ($status === 'fail') ? 'warning' : 'notice';
                $message = ($status === 'fail') ? 'integrity_fail' : 'integrity_suspect';
                $this->log($level, $message, $metrics);
            }

            // Decide whether we fallback to the source content.
            $should_fallback = ($status === 'fail');
            if ($status === 'suspect') {
                $default_suspect = (bool) get_option('yuz_force_fallback_on_integrity_fail', false);
                $should_fallback = apply_filters(
                    'yuz_tra_integrity_should_fallback_on_suspect',
                    $default_suspect,
                    $metrics,
                    $context,
                    $post_id
                );
            }

            $should_fallback = apply_filters(
                'yuz_tra_integrity_should_fallback',
                $should_fallback,
                $metrics,
                $context,
                $post_id
            );

            if ($should_fallback) {
                $this->counters['fallbacks']++;
                $this->log('notice', 'integrity_fallback', [
                    'context' => $context,
                    'post_id' => $post_id,
                    'status'  => $status,
                    'reasons' => $metrics['reasons'] ?? [],
                ]);
                return $source;
            }

            return $translated;
        }

        /** Build metrics and verdict for a substitution. */
        private function collect_metrics(string $context, string $src, string $dst, int $post_id): array {
            $len_src = strlen($src);
            $len_dst = strlen($dst);
            $delta   = $len_dst - $len_src;

            $len_ratio = $len_src > 0 ? ($len_dst / max($len_src, 1)) : null;
            if ($len_ratio !== null) {
                $len_ratio = (float) round($len_ratio, 2);
            }

            $hash_src = $len_src > 0 ? substr(sha1($src), 0, 12) : '';
            $hash_dst = $len_dst > 0 ? substr(sha1($dst), 0, 12) : '';

            $blocks_src = substr_count($src, '<!-- wp:');
            $blocks_dst = substr_count($dst, '<!-- wp:');
            $block_delta = $blocks_dst - $blocks_src;

            $div_src = substr_count($src, '<div');
            $div_dst = substr_count($dst, '<div');
            $div_delta = $div_dst - $div_src;

            $tags_src = substr_count($src, '<');
            $tags_dst = substr_count($dst, '<');

            $html_ok   = $this->looks_balanced($dst);
            $too_short = ($len_src > 0 && $len_dst === 0);
            $too_long  = ($len_src > 0 && $len_dst > ($len_src * 4));

            $status = 'ok';
            $reasons = [];

            if (!$html_ok) {
                $status = 'fail';
                $reasons[] = 'html_unbalanced';
            }
            if ($too_short) {
                $status = 'fail';
                $reasons[] = 'now_empty';
            }
            if ($tags_src > 0 && $tags_dst === 0) {
                $status = 'fail';
                $reasons[] = 'lost_markup';
            }
            if ($blocks_src > 0 && $blocks_dst === 0) {
                $status = 'fail';
                $reasons[] = 'lost_blocks';
            }
            if ($too_long) {
                if ($status !== 'fail') {
                    $status = 'suspect';
                }
                $reasons[] = 'delta_out_of_bounds';
            }

            if ($status !== 'fail') {
                if ($len_ratio !== null && ($len_ratio < 0.5 || $len_ratio > 1.5)) {
                    $status = 'suspect';
                    $reasons[] = 'len_ratio';
                }
                if ($block_delta !== 0) {
                    $status = 'suspect';
                    $reasons[] = 'block_delta';
                }
                if (abs($div_delta) > 5) {
                    $status = 'suspect';
                    $reasons[] = 'div_delta';
                }
            } else {
                if ($block_delta !== 0) {
                    $reasons[] = 'block_delta';
                }
                if ($div_delta !== 0) {
                    $reasons[] = 'div_delta';
                }
            }
            if ($div_src > 0 && $div_dst === 0) {
                $reasons[] = 'lost_divs';
                if ($status !== 'fail') {
                    $status = 'suspect';
                }
            }

            $active  = YUZ_Front_Renderer::get_active_language();
            $default = YUZ_Front_Renderer::get_default_language();

            return [
                'trace'            => $this->trace_id,
                'context'          => $context,
                'post_id'          => $post_id,
                'status'           => $status,
                'reasons'          => $reasons,
                'len_src'          => $len_src,
                'len_dst'          => $len_dst,
                'len_ratio'        => $len_ratio,
                'delta'            => $delta,
                'hash_src'         => $hash_src,
                'hash_dst'         => $hash_dst,
                'blocks_src'       => $blocks_src,
                'blocks_dst'       => $blocks_dst,
                'block_delta'      => $block_delta,
                'div_src'          => $div_src,
                'div_dst'          => $div_dst,
                'div_delta'        => $div_delta,
                'tags_src'         => $tags_src,
                'tags_dst'         => $tags_dst,
                'html_balance_ok'  => $html_ok,
                'active_language'  => $active,
                'default_language' => $default,
            ];
        }

        /** Extremely cheap balance detector (angle brackets count). */
        private function looks_balanced(string $html): bool {
            if ($html === '') {
                return true; // empty is fine
            }
            $open  = substr_count($html, '<');
            $close = substr_count($html, '>');
            if ($open === 0 && $close === 0) { return true; }
            return abs($open - $close) <= 10;
        }

        /** One-line beacon in the page head while WP_DEBUG & ?yuz_debug=1. */
        public function emit_debug_beacon(): void {
            $debug_on = defined('WP_DEBUG') && WP_DEBUG;
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display toggle for authenticated diagnostics.
            $q = isset($_GET['yuz_debug']) ? (int) $_GET['yuz_debug'] : 0;
            if (!$debug_on || $q !== 1 || !is_user_logged_in() || (!current_user_can('manage_options') && !current_user_can('yuz_translate_content'))) { return; }
            $data = esc_attr(wp_json_encode([
                'trace'    => $this->trace_id,
                'counters' => $this->counters,
                'probes'   => $this->probe_results,
            ]));
            echo "\n<!-- YUZ DEBUG BEACON: {$data} -->\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        }

        /** HTTP self-probe via admin-ajax.php (only on-demand). */
        public function ajax_probe(): void {
            if (!current_user_can('manage_options') && !current_user_can('yuz_translate_content')) {
                wp_send_json_error(['error' => 'forbidden'], 403);
            }
            check_ajax_referer('yuz_log_nonce', 'nonce');
            wp_send_json_success(['trace' => $this->trace_id, 'time' => time()]);
        }

        /** Compose a health payload suitable for REST exposure. */
        private function health_payload(): array {
            $active  = YUZ_Front_Renderer::get_active_language();
            $default = YUZ_Front_Renderer::get_default_language();
            return [
                'trace'     => $this->trace_id,
                'time'      => time(),
                'site_url'  => site_url('/'),
                'home_url'  => home_url('/'),
                'locale'    => get_locale(),
                'active'    => $active,
                'default'   => $default,
                'is_default'=> (strcasecmp($active, $default) === 0),
                'probes'    => $this->probe_results,
                'counters'  => $this->counters,
            ];
        }

        /** Run a set of hard probes: everything returns a truthful status+detail. */
        private function probe_environment(string $active, string $default): array {
            $probes = [];
            $push = static function(string $name, string $status, string $detail = '', array $extra = []) use (&$probes) {
                $probes[$name] = array_merge([
                    'status' => $status, // ok|warn|fail
                    'detail' => $detail,
                ], $extra);
            };

            // WP core debug flags.
            $push('wp_debug', (defined('WP_DEBUG') && WP_DEBUG) ? 'ok' : 'warn', 'WP_DEBUG suggests dev visibility');
            $push('wp_debug_log', (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) ? 'ok' : 'warn', 'WP_DEBUG_LOG controls error_log');

            // Renderer availability.
            if (class_exists('YUZ_Front_Renderer')) {
                $push('renderer', 'ok');
            } else {
                $push('renderer', 'fail', 'YUZ_Front_Renderer missing');
            }

            // REST API reachability (routing exists at least).
            if (function_exists('rest_url')) {
                $url = rest_url('yuz/v1/health');
                $push('rest_api', $url ? 'ok' : 'fail', $url ? 'endpoint prepared' : 'rest_url empty', ['url' => $url]);
            } else {
                $push('rest_api', 'fail', 'rest_url function missing');
            }

            // admin-ajax reachable? Do a local loopback only when explicitly requested.
            $ajax_check = 'skipped';
            if (isset($_GET['yuz_loopback']) && (int) $_GET['yuz_loopback'] === 1) {
                $resp = wp_remote_post(admin_url('admin-ajax.php'), [
                    'timeout' => 3,
                    'body'    => ['action' => 'yuz_probe'],
                ]);
                if (is_wp_error($resp)) {
                    $ajax_check = 'fail';
                    $this->counters['wp_errors']++;
                    $this->log('error', 'ajax_loopback_error', ['error' => $resp->get_error_message()]);
                } else {
                    $code = (int) wp_remote_retrieve_response_code($resp);
                    $ajax_check = ($code === 200) ? 'ok' : 'fail';
                }
            }
            $push('ajax_loopback', $ajax_check === 'skipped' ? 'warn' : $ajax_check, $ajax_check === 'skipped' ? 'add ?yuz_loopback=1 to test' : '');

            // Languages sanity.
            $push('language_default_set', $default ? 'ok' : 'fail', $default ?: '');
            $push('language_active_set',  $active  ? 'ok' : 'fail', $active ?: '');
            $push('language_is_default', ($active && $default && strcasecmp($active, $default) === 0) ? 'ok' : 'warn');

            // Filesystem log path probe.
            $log_dir = wp_upload_dir()['basedir'] . '/yuz-logs';
            $log_ok = wp_mkdir_p($log_dir);
            $push('log_directory', $log_ok ? 'ok' : 'fail', $log_dir);

            return $probes;
        }

        /** Shutdown hook to capture last PHP error (truthful, uncensored). */
        public function shutdown_probe(): void {
            $err = error_get_last();
            if (!$err) { return; }
            $type = $err['type'] ?? 0;
            // Fatal-like errors list.
            $fatal = in_array($type, [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR], true);
            if ($fatal) {
                $this->log('error', 'php_fatal', [
                    'type'    => $type,
                    'message' => (string) ($err['message'] ?? ''),
                    'file'    => (string) ($err['file'] ?? ''),
                    'line'    => (int)    ($err['line'] ?? 0),
                    'trace'   => $this->trace_id,
                ]);
            }
        }

        /** Central logging with JSON context, no greenwashing. */
        private function log(string $level, string $message, array $context = []): void {
            $payload = ['trace' => $this->trace_id] + $context;
            // Preferred: plugin logger.
            if ($this->logger && method_exists($this->logger, 'log')) {
                try { $this->logger->log($level, '[Frontend] ' . $message, $payload); return; } catch (\Throwable $e) { /*fallback*/ }
            }
            // Fallback: error_log.
            $line = sprintf('[YUZ][%s][%s] %s %s', strtoupper($level), $this->trace_id, $message, wp_json_encode($payload));
            yuz_tra_debug_log($line);
        }

        /** Interface requirement; rendering is done via hooks. */
        public function render(): void {
            // No direct output: filters and buffer handle translations.
        }
    }
}

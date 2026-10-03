<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
/**
 * YUZ CORE RULES — DO NOT VIOLATE
 *
 * Objectif :
 *   Verrouiller par VERBES les actions autorisées par fichier central.
 *   Tout verbe non listé ci-dessous est INTERDIT dans ce fichier.
 *
 * centralise des politiques: class-yuz-contracts.php (les interfaces), class-yuz-fallbacks.php (les fallbacks), class-yuz-ajax.php (la communication), class-yuz-assets.php (les assets css, js, ...), class-yuz-renderer.php (les rendus UI), yuz-core.php (le bootstrap).
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

require_once YUZTRA_INCLUDES . 'class-yuz-contracts.php';
require_once YUZTRA_INCLUDES . 'class-yuz-fallbacks.php';
if (!class_exists('YUZTRA_String_Service')) {
    require_once YUZTRA_INCLUDES . 'class-yuz-string-service.php';
}
require_once YUZTRA_INCLUDES . 'helpers/html-translator.php';

use YUZTRA\Interfaces\AjaxInterface;
use YUZTRA\Interfaces\TranslationManagerInterface;
use YUZTRA\Interfaces\LanguageManagerInterface;
use YUZTRA\Interfaces\DBInterface;
use YUZTRA\Fallbacks\NullTranslationManager;
use YUZTRA\Fallbacks\NullLanguageManager;
use YUZTRA\Fallbacks\NullAjax;

if (!class_exists('YUZTRA_Ajax')) {

    class YUZTRA_Ajax implements AjaxInterface {

        private const OFFICIAL_NONCES = [
            'yuztra_nonce',
            'yuztra_con_nonce',
            'yuztra_int_nonce',
            'yuztra_del_nonce',
            'yuztra_log_nonce',
            'yuztra_hvy_nonce',
            'yuztra_api_nonce',
        ];

        private static bool $bootstrapped = false;
        private static string $current_req_id = '';
        /** Endpoints soumis à un anti-rafale (limite courte glissante par IP+action). */
        private const RATE_LIMITED_ACTIONS = [
            'yuztra_tm_translate',
            'yuztra_translate',
            'yuztra_tm_translate_item',
            'yuztra_get_regular',
            'yuztra_js_get_regular',
        ];

        /** Read-only routes required to translate pages for logged-out visitors. */
        private const PUBLIC_AJAX_ACTIONS = [
            'yuztra_public_lookup',
        ];

        /** @var \YUZ_Logger|\YUZTRA\Fallbacks\NullLogger */
        private $logger;

        private TranslationManagerInterface $translation_manager;
        private LanguageManagerInterface $language_manager;
        private DBInterface $db;
        private ?\YUZTRA_Settings $settings = null;

        /**
         * Low-level trace logger to private option storage.
         * Lightweight and explicitly enabled server-side with YUZ_TRA_TRACE_AUTO.
         */
        private function trace_log(string $tag, array $ctx = []): void {
            try {
                $trace_on = defined('YUZTRA_TRACE_AUTO') && YUZTRA_TRACE_AUTO;
                if (!$trace_on) return;
                if (!is_user_logged_in() || (!current_user_can('manage_options') && !current_user_can('yuztra_translate_content'))) return;
                $row  = sprintf('%s %s %s%s',
                    gmdate('Y-m-d H:i:s') . ' UTC',
                    $tag,
                    wp_json_encode($ctx, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
                    PHP_EOL
                );
                yuztra_debug_log($row);
            } catch (\Throwable $ignored) {}
        }

        /**
         * Simple rate-limit glissant par IP + action pour contenir les rafales Ajax.
         * Retourne un tableau avec allow(bool) et retry_after(int) en secondes.
         */
        private static function check_rate_limit(string $action): array {
            if (!in_array($action, self::RATE_LIMITED_ACTIONS, true)) {
                return ['allow' => true, 'retry_after' => 0];
            }
            // Bypass rate limit for authenticated translators (prevents editor floods from being throttled)
            if (is_user_logged_in() && current_user_can('yuztra_translate_content')) {
                return ['allow' => true, 'retry_after' => 0];
            }
            $window = defined('YUZTRA_RATE_LIMIT_WINDOW') ? (int) YUZTRA_RATE_LIMIT_WINDOW : 10; // seconds
            $limit  = defined('YUZTRA_RATE_LIMIT_LIMIT') ? (int) YUZTRA_RATE_LIMIT_LIMIT : 100; // requests per window
            if ($window <= 0) { $window = 10; }
            if ($limit <= 0)  { $limit  = 100; }
            $ip = isset($_SERVER['REMOTE_ADDR']) ? trim(sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']))) : 'unknown';
            if ($ip === '') $ip = 'unknown';

            $key = 'yuztra_rate_' . md5($action . '|' . $ip);
            $now = time();

            $bucket = get_transient($key);
            if (!is_array($bucket) || !isset($bucket['reset_at'], $bucket['count'])) {
                $bucket = ['reset_at' => $now + $window, 'count' => 0];
            }

            if ($bucket['reset_at'] <= $now) {
                $bucket = ['reset_at' => $now + $window, 'count' => 0];
            }

            if ($bucket['count'] >= $limit) {
                $retry = max(1, $bucket['reset_at'] - $now);
                return ['allow' => false, 'retry_after' => $retry];
            }

            $bucket['count']++;
            set_transient($key, $bucket, $window);
            return ['allow' => true, 'retry_after' => max(0, $bucket['reset_at'] - $now)];
        }

        private static function normalize_nonce_key($nonce_key): string {
            $key = is_string($nonce_key) ? sanitize_key($nonce_key) : '';
            if ($key === '' || !in_array($key, self::OFFICIAL_NONCES, true)) {
                $key = 'yuztra_nonce';
            }
            return $key;
        }

        public function __construct(
    ?TranslationManagerInterface $translation_manager = null,
    ?LanguageManagerInterface $language_manager = null,
    ?DBInterface $db = null
) {
    // Logger / Health
    $logger = class_exists('YUZTRA_Logger') ? new \YUZTRA_Logger() : new \YUZTRA\Fallbacks\NullLogger();
    $this->logger = $logger;
    $health = class_exists('YUZTRA_Health_Check') ? new \YUZTRA_Health_Check($logger) : new \YUZTRA\Fallbacks\NullHealth();

    // DB
    if (!$db) {
        if (class_exists('YUZTRA_DB')) {
            $db = new \YUZTRA_DB($logger, $health);
        } else {
            // Fallback inerte si YUZ_DB indisponible
            $db = new \YUZTRA\Fallbacks\NullDB();
        }
    }

    // Language manager
    if (!$language_manager) {
        if (class_exists('YUZTRA_Languages')) {
            $language_manager = new \YUZTRA_Languages(new \YUZTRA\Fallbacks\NullSettings(), $db);
        } elseif (class_exists('YUZTRA_LanguageManager')) {
            $language_manager = new \YUZTRA_LanguageManager(new \YUZTRA\Fallbacks\NullSettings(), $db);
        } else {
            $language_manager = new \YUZTRA\Fallbacks\NullLanguageManager();
        }
    }

    // Translation manager
    if (!$translation_manager) {
        try {
            $translation_manager = (class_exists('YUZTRA_Services') && method_exists('YUZTRA_Services', 'tm'))
                ? \YUZTRA_Services::tm()
                : new \YUZTRA\Fallbacks\NullTranslationManager();
        } catch (\Throwable $e) {
            $translation_manager = new \YUZTRA\Fallbacks\NullTranslationManager();
        }
    }

    $this->translation_manager = $translation_manager;
    $this->language_manager    = $language_manager;
    $this->db                  = $db;
}


        public function registerEndpoints(): void {
    // Endpoints du module Advanced (déplacés depuis YUZ_Advanced)
    add_action('wp_ajax_yuztra_run_diagnostics', [$this, 'ajax_run_diagnostics']);
    add_action('wp_ajax_yuztra_clear_cache',     [$this, 'ajax_clear_cache']);
    add_action('wp_ajax_yuztra_get_languages', [$this, 'ajax_get_languages']);
    add_action('wp_ajax_nopriv_yuztra_get_languages', [$this, 'ajax_get_languages']);
    add_action('wp_ajax_yuztra_diag_chain', [$this, 'yuztra_diag_chain']);

    // Maintenance (admin‑only)
    add_action('wp_ajax_yuztra_maintenance', [$this, 'ajax_maintenance']);

    // Lightweight DOM debug logger for authenticated translators only.
    add_action('wp_ajax_yuztra_dom_log',       [$this, 'yuztra_dom_log']);

    // Front trace beacon -> uploads/yuz-trace.log
    add_action('wp_ajax_yuztra_trace_beacon', [$this, 'yuztra_trace_beacon']);

    // One-shot diagnostic hook (requires nonce + trace flag)
    add_action('wp_ajax_yuztra_diag', [$this, 'yuztra_diag']);
}

	private function is_trace_request(): bool {
            return defined('YUZTRA_TRACE_AUTO')
                && YUZTRA_TRACE_AUTO
                && is_user_logged_in()
                && (current_user_can('manage_options') || current_user_can('yuztra_translate_content'));
        }

        private function emit_trace_marker(string $marker, array $context = []): bool {
            $payload = wp_json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($payload === false) {
                $payload = '{}';
            }
            $line = sprintf('[%s] %s', $marker, $payload);
            return yuztra_debug_log($line);
        }



        private function get_settings(): \YUZTRA_Settings {
            if ($this->settings instanceof \YUZTRA_Settings) {
                return $this->settings;
            }
            if (class_exists('YUZTRA_Services') && method_exists('YUZTRA_Services', 'settings')) {
                $service_settings = \YUZTRA_Services::settings();
                if ($service_settings instanceof \YUZTRA_Settings) {
                    return $this->settings = $service_settings;
                }
            }
            $logger = class_exists('YUZTRA_Logger') ? new \YUZTRA_Logger() : new \YUZTRA\Fallbacks\NullLogger();
            // Prefer real languages if available; fall back to NullLanguages
            $langs = class_exists('YUZTRA_Languages') ? new \YUZTRA_Languages(new \YUZTRA\Fallbacks\NullSettings(), $this->db) : new \YUZTRA\Fallbacks\NullLanguages();
            $this->settings = new \YUZTRA_Settings(
                $langs,
                new \YUZTRA\Fallbacks\NullAjax(),
                ($this->translation_manager ?? new \YUZTRA\Fallbacks\NullTranslationManager()),
                ($this->language_manager ?? new \YUZTRA\Fallbacks\NullLanguageManager()),
                $logger
            );
            return $this->settings;
        }

        /**
         * Invalidate language-related caches/transients so subsequent payloads reflect DB writes.
         * Optionally accepts a list of language codes to clear per-language caches.
         */
        private function invalidate_language_caches(array $codes = []): void {
            $suffixes = [
                \YUZTRA_Languages::TRANSIENT_TRANSLATABLE,
                \YUZTRA_Languages::TRANSIENT_ALL,
                \YUZTRA_Languages::TRANSIENT_DEFAULT_LANGUAGE,
                \YUZTRA_Languages::TRANSIENT_SOURCE_LANGUAGE,
            ];

            foreach ($suffixes as $suffix) {
                if (function_exists('yuztra_settings_delete_transient')) {
                    yuztra_settings_delete_transient($suffix);
                }
                if (function_exists('yuztra_settings_cache_delete')) {
                    yuztra_settings_cache_delete($suffix);
                }
            }

            // Legacy transients (pre-versioned) - defensive purge
            foreach (['yuztra_translatable_languages', 'yuztra_all_languages', 'yuztra_default_language', 'yuztra_source_language'] as $legacy) {
                delete_transient($legacy);
            }

            // Legacy object cache fragments
            if (function_exists('wp_cache_delete')) {
                foreach (['all_languages', 'translatable_languages', 'default_language', 'source_language'] as $legacyCache) {
                    wp_cache_delete($legacyCache, 'yuz-tra');
                }
            }

            if (function_exists('yuztra_settings_cache_delete')) {
                foreach ($codes as $code) {
                    $suffix = \YUZTRA_Languages::CACHE_LANGUAGE_PREFIX . sanitize_key($code);
                    yuztra_settings_cache_delete($suffix);
                }
            }

            if (class_exists('YUZTRA_Settings_Service')) {
                YUZTRA_Settings_Service::touch();
            } elseif (function_exists('yuztra_settings_get_all') && function_exists('yuztra_settings_update_all')) {
                $current = yuztra_settings_get_all();
                if (is_array($current)) {
                    yuztra_settings_update_all($current);
                }
            }
        }

        /** Resolve only the canonical translations table, never a backup wildcard. */
        private function resolve_translations_table(\wpdb $wpdb): string {
            $canonical = $wpdb->prefix . 'yuz_tra_translations';
            $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($canonical)));
            if (!is_string($found) || !hash_equals($canonical, $found)) {
                throw new \RuntimeException('canonical_translations_table_missing');
            }
            return $canonical;
        }

        /** Compute quick maintenance metrics */
        private function maintenance_metrics(\wpdb $wpdb, string $table): array {
            $total = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i', $table));
            $empties = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %i WHERE TRIM(COALESCE(translated_text,''))=''", $table));
            $dups = (int) $wpdb->get_var(
                $wpdb->prepare("SELECT COUNT(*) FROM (\n" .
                " SELECT MIN(id) AS keep_id\n" .
                " FROM %i\n" .
                " GROUP BY post_id, COALESCE(block_id,''), language_code, COALESCE(context,''), COALESCE(original_text,'')\n" .
                " HAVING COUNT(*)>1\n" .
                ") x", $table)
            );
            $locales = (array) $wpdb->get_results($wpdb->prepare('SELECT language_code, COUNT(*) c FROM %i GROUP BY language_code ORDER BY c DESC', $table), ARRAY_A);
            return [
                'table'   => $table,
                'total'   => $total,
                'empties' => $empties,
                'dups'    => $dups,
                'locales' => $locales,
            ];
        }

        /** Perform safe backup of the table (structure + data) */
        private function maintenance_backup(\wpdb $wpdb, string $table): array {
            $ts = gmdate('Ymd_His');
            $backup = $table . '_bak_' . $ts;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- Temporary backup table is created only for the explicit maintenance operation and uses validated table identifiers.
            $wpdb->query($wpdb->prepare('CREATE TABLE %i LIKE %i', $backup, $table));
            $wpdb->query($wpdb->prepare('INSERT INTO %i SELECT * FROM %i', $backup, $table));
            return ['backup_table' => $backup];
        }

        /** Normalize locales safely: hyphen->underscore, lang lower, region upper */
        private function maintenance_normalize_locales(\wpdb $wpdb, string $table): int {
            return (int) $wpdb->query($wpdb->prepare("UPDATE %i\n"
                 . "SET language_code = CASE\n"
                 . "  WHEN language_code LIKE %s OR language_code LIKE %s THEN\n"
                 . "    CONCAT(LOWER(SUBSTRING_INDEX(REPLACE(language_code,'-','_'),'_',1)),'_',UPPER(SUBSTRING_INDEX(REPLACE(language_code,'-','_'),'_',-1)))\n"
                 . "  ELSE LOWER(language_code) END", $table, '%-%', '%\\_%'));
        }

        /** Delete empty translated_text rows */
        private function maintenance_delete_empties(\wpdb $wpdb, string $table): int {
            return (int) $wpdb->query($wpdb->prepare("DELETE FROM %i WHERE TRIM(COALESCE(translated_text,''))=''", $table));
        }

        /** Deduplicate logical duplicates keeping highest id */
        private function maintenance_dedupe(\wpdb $wpdb, string $table): int {
            $sql = $wpdb->prepare("DELETE t1 FROM %i t1\n"
                 . "JOIN %i t2\n"
                 . "  ON  t1.post_id = t2.post_id\n"
                 . "  AND COALESCE(t1.block_id,'') = COALESCE(t2.block_id,'')\n"
                 . "  AND t1.language_code = t2.language_code\n"
                 . "  AND COALESCE(t1.context,'') = COALESCE(t2.context,'')\n"
                 . "  AND COALESCE(t1.original_text,'') = COALESCE(t2.original_text,'')\n"
                 . "  AND t1.id < t2.id", $table, $table);
            return (int) $wpdb->query($wpdb->prepare("DELETE t1 FROM %i t1\n"
                 . "JOIN %i t2\n"
                 . "  ON  t1.post_id = t2.post_id\n"
                 . "  AND COALESCE(t1.block_id,'') = COALESCE(t2.block_id,'')\n"
                 . "  AND t1.language_code = t2.language_code\n"
                 . "  AND COALESCE(t1.context,'') = COALESCE(t2.context,'')\n"
                 . "  AND COALESCE(t1.original_text,'') = COALESCE(t2.original_text,'')\n"
                 . "  AND t1.id < t2.id", $table, $table));
        }

        /** Normalize a locale code to xx or xx_YY; returns '' if invalid/auto */
        private function normalize_locale_code(?string $locale): string {
            $l = is_string($locale) ? trim($locale) : '';
            if ($l === '' || strtolower($l) === 'auto') return '';
            $l = str_replace('-', '_', $l);
            $parts = array_values(array_filter(explode('_', $l)));
            if (!$parts) return '';
            $lang = strtolower(array_shift($parts));
            if (!$lang) return '';
            if (!$parts) return $lang;
            $region = strtoupper(implode('_', $parts));
            $norm = $lang . '_' . $region;
            return strtolower($norm) === 'auto' ? '' : $norm;
        }

        /** Extract best-effort locale candidate from a URL (?lang= or slug /xx[-YY]/) */
        private function extract_locale_from_url(string $url): string {
            if ($url === '') return '';
            $candidate = '';
            try {
                $qs = wp_parse_url($url, PHP_URL_QUERY);
                if (is_string($qs)) {
                    parse_str($qs, $params);
                    if (!empty($params['lang'])) {
                        $candidate = (string) $params['lang'];
                    }
                }
            } catch (\Throwable $e) {}
            if ($candidate !== '') return $this->normalize_locale_code($candidate);
            try {
                $path = (string) (wp_parse_url($url, PHP_URL_PATH) ?? '');
                $seg  = ltrim($path, '/');
                $seg  = explode('/', $seg)[0] ?? '';
                if ($seg !== '') {
                    $seg = str_replace('-', '_', strtolower($seg));
                    return $this->normalize_locale_code($seg);
                }
            } catch (\Throwable $e) {}
            return '';
        }

        /** Fetch translatable language codes with weights and flags */
        private function get_translatable_index(\wpdb $wpdb): array {
            $table = $wpdb->prefix . 'yuz_tra_languages';
            $rows = (array) $wpdb->get_results(
                $wpdb->prepare(
                    'SELECT language_code, language_weight, is_translatable, is_source, is_default FROM %i',
                    $table
                ),
                ARRAY_A
            );
            $codes = [];
            $weights = [];
            $source = '';
            foreach ($rows as $r) {
                $code = isset($r['language_code']) ? (string) $r['language_code'] : '';
                if ($code === '') continue;
                $codes[] = $code;
                $weights[$code] = isset($r['language_weight']) ? (int) $r['language_weight'] : 0;
                if (!empty($r['is_source'])) { $source = $code; }
            }
            // Filter to declared translatable set if flag exists
            $translatable = array_values(array_filter($rows, static function($r){ return !empty($r['is_translatable']); }));
            if ($translatable) {
                $codes = array_values(array_map(static function($r){ return (string) $r['language_code']; }, $translatable));
            }
            return [
                'codes'   => array_values(array_unique(array_filter($codes, 'strlen'))),
                'weights' => $weights,
                'source'  => $source,
            ];
        }

        /** Compute default target (highest weight not equal to source) */
        private function compute_default_target(array $codes, array $weights, string $source): string {
            $best = '';
            $bestW = PHP_INT_MIN;
            foreach ($codes as $c) {
                if ($c === '' || $c === $source) continue;
                $w = isset($weights[$c]) ? (int) $weights[$c] : 0;
                if ($w > $bestW) { $bestW = $w; $best = $c; }
            }
            return $best !== '' ? $best : ($codes[0] ?? '');
        }

        /** Best-match a candidate against available codes (lang-region aware) */
        private function best_match_locale(string $candidate, array $available, array $weights, string $default_target): string {
            $avail = array_values(array_unique(array_map([$this,'normalize_locale_code'], $available)));
            $cand  = $this->normalize_locale_code($candidate);
            if ($cand !== '' && in_array($cand, $avail, true)) return $cand;
            if ($cand !== '') {
                $base = explode('_', $cand)[0];
                $same = array_values(array_filter($avail, static function($c) use($base){ return explode('_', $c)[0] === $base; }));
                if (count($same) === 1) return $same[0];
                if (count($same) > 1) {
                    // pick highest weight among same base
                    $pick = '';
                    $wmax = PHP_INT_MIN;
                    foreach ($same as $c) { $w = isset($weights[$c]) ? (int) $weights[$c] : 0; if ($w > $wmax) { $wmax = $w; $pick = $c; } }
                    if ($pick !== '') return $pick;
                    return $same[0];
                }
            }
            return $default_target !== '' ? $default_target : ($avail[0] ?? $cand);
        }

        /**
         * AJAX: Minimal DOM logger for field diagnostics.
         * - Accepts event (string) and context (JSON string or plain text)
         * - Private option storage, diagnostics must be explicitly enabled.
         * - Authenticated translator and log nonce required.
         */
        public function yuztra_dom_log(): void {
            if (!current_user_can('manage_options') && !current_user_can('yuztra_translate_content')) {
                wp_send_json_error(['ok' => false, 'error' => 'forbidden'], 403);
            }
            check_ajax_referer('yuztra_log_nonce', 'nonce');

            $event   = isset($_POST['event']) ? sanitize_text_field(wp_unslash((string) $_POST['event'])) : '';
            $rawCtx  = isset($_POST['context']) ? sanitize_textarea_field(wp_unslash((string) $_POST['context'])) : '';
            $context = null;
            if ($rawCtx !== '') {
                $decoded = json_decode($rawCtx, true);
                $context = is_array($decoded) ? $decoded : [];
            } else {
                $context = [];
            }

            // Enrich with basics
            $context['url']        = $context['url'] ?? (isset($_POST['page_url']) ? esc_url_raw(wp_unslash((string) $_POST['page_url'])) : '');
            $context['lang']       = $context['lang'] ?? (isset($_POST['lang']) ? sanitize_text_field(wp_unslash((string) $_POST['lang'])) : '');
            $context['user_agent'] = $context['user_agent'] ?? (isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '');

            $line = sprintf(
                "%s 🟪 DOMLOG %s %s\n",
                gmdate('Y-m-d H:i:s') . ' UTC',
                $event !== '' ? $event : 'event',
                wp_json_encode(['field_count'=>count($context)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            );

            // Private, opt-in persistence. No publicly downloadable diagnostic file.
            $written = yuztra_debug_log($line);
            // Mirror to dedicated trace file when TRACE is enabled (keeps DOM noise out of other logs)
            try {
                $this->trace_log('DOM.' . ($event !== '' ? $event : 'event'), [
                    'url'  => $context['url'] ?? '',
                    'lang' => $context['lang'] ?? '',
                    'ua'   => isset($context['user_agent']) ? (string)$context['user_agent'] : '',
                ]);
            } catch (\Throwable $ignored) {}

            // Also log through the main logger for visibility in yuz-log.log
            if ($this->logger && method_exists($this->logger, 'log')) {
                try { $this->logger->log('info', 'YUZ-DOM', ['event' => $event, 'field_count' => count($context)]); } catch (\Throwable $ignored) {}
            }

        if ($written) {
            wp_send_json_success(['ok' => true]);
        } else {
            wp_send_json_error(['ok' => false, 'message' => 'diagnostics_disabled_or_write_failed'], 503);
        }
    }

        /**
         * AJAX: Trace beacon endpoint (priv + nopriv) used by front-side sendBeacon/fetch fallbacks.
         * Accepts a JSON payload (marker + context) and mirrors it to uploads/yuz-trace.log.
         */
        public function yuztra_trace_beacon(): void {
            if (!current_user_can('manage_options') && !current_user_can('yuztra_translate_content')) {
                wp_send_json_error(['ok' => false, 'error' => 'forbidden'], 403);
            }
            check_ajax_referer('yuztra_log_nonce', 'nonce');
            $payload_raw = isset($_POST['payload']) ? sanitize_textarea_field(wp_unslash((string) $_POST['payload'])) : '';
            if ($payload_raw === '') {
                wp_send_json_error(['ok' => false, 'error' => 'empty_payload'], 400);
            }

            $decoded = json_decode($payload_raw, true);
            if (!is_array($decoded)) {
                $decoded = [];
            }

            $marker = isset($decoded['marker']) ? sanitize_text_field((string) $decoded['marker']) : 'YUZTRA_TRACE_STATE';
            if ($marker === '') {
                $marker = 'YUZTRA_TRACE_STATE';
            }

            $decoded['remote_ip'] = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']))) : '';
            $decoded['user_agent'] = isset($_SERVER['HTTP_USER_AGENT']) ? substr(sanitize_text_field(sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT']))), 0, 255) : '';

            try {
                if (!$this->emit_trace_marker($marker, ['field_count'=>count($decoded)])) {
                    wp_send_json_error(['ok'=>false,'error'=>'diagnostics_disabled_or_write_failed'],503);
                }
            } catch (\Throwable $e) {
                wp_send_json_error(['ok' => false, 'error' => 'emit_failed'], 500);
            }

            wp_send_json_success(['ok' => true]);
        }

        public function yuztra_diag(): void {
            if (!current_user_can('manage_options')) wp_send_json_error(['ok'=>false,'error'=>'forbidden'],403);
            $nonce = isset($_POST['yuztra_nonce']) ? sanitize_text_field(wp_unslash((string) $_POST['yuztra_nonce'])) : '';
            if (!$nonce || !wp_verify_nonce($nonce, 'yuztra_nonce')) {
                wp_send_json_error(['ok' => false, 'error' => 'bad_nonce'], 403);
            }

            global $wpdb;
            $page = isset($_POST['page']) ? esc_url_raw(wp_unslash((string) $_POST['page'])) : '';
            $trans_table = $wpdb->prefix . 'yuz_tra_translations';
            $summary = [
                'time'               => current_time('mysql'),
                'page'               => $page,
                'db_rows'            => 0,
                'invalid_entries'    => 0,
                'empty_translations' => 0,
            ];

            if (!$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($trans_table)))) {
                $summary['error'] = 'missing_table';
                $this->emit_trace_marker('YUZTRA_DIAG_SUMMARY', $summary);
                wp_send_json_error(['ok' => false, 'summary' => $summary], 500);
            }

            $rows = (array) $wpdb->get_results($wpdb->prepare('SELECT original_text, translated_text, language_code FROM %i ORDER BY updated_at DESC LIMIT 50', $trans_table), ARRAY_A);
            $summary['db_rows'] = count($rows);

            $normalized = [];
            foreach ($rows as $row) {
                $original = $this->normalize_newlines((string) ($row['original_text'] ?? ''));
                $langCode = $this->normalize_locale_code((string) ($row['language_code'] ?? ''));
                if ($original === '' || $langCode === '') {
                    $summary['invalid_entries']++;
                    continue;
                }
                if (!isset($normalized[$original])) {
                    $normalized[$original] = [
                        'original' => $original,
                        'translationsArray' => [],
                    ];
                }
                $translated = $this->normalize_newlines((string) ($row['translated_text'] ?? ''));
                if ($translated === '') {
                    $summary['empty_translations']++;
                }
                $normalized[$original]['translationsArray'][$langCode] = [
                    'translated' => $translated,
                    'status'     => $translated === '' ? 'empty' : 'ok',
                ];
            }

            $normalized_sample = array_slice(array_values($normalized), 0, 5);
            $raw_sample = array_slice($rows, 0, 5);

            $this->emit_trace_marker('YUZTRA_DIAG_SUMMARY', [
                'time'               => $summary['time'],
                'page'               => $summary['page'],
                'db_rows'            => $summary['db_rows'],
                'invalid_entries'    => $summary['invalid_entries'],
                'empty_translations' => $summary['empty_translations'],
            ]);

            wp_send_json_success([
                'ok'                => true,
                'summary'           => $summary,
                'raw_sample'        => $raw_sample,
                'normalized_sample' => $normalized_sample,
            ]);
        }

        /** AJAX: unified maintenance router */
        public function ajax_maintenance(): void {
            if (!current_user_can('manage_options')) {
                wp_send_json_error(['message' => 'forbidden'], 403);
            }
            // Accept nonce from 'nonce' param (consistent with admin forms  JS)
            check_ajax_referer('yuztra_hvy_nonce', 'nonce');
            global $wpdb;
            $table = $this->resolve_translations_table($wpdb);
            $task = isset($_REQUEST['task']) ? sanitize_key(wp_unslash((string) $_REQUEST['task'])) : 'metrics';

            $resp = ['task' => $task, 'table' => $table];
            switch ($task) {
                case 'metrics':
                    $resp += $this->maintenance_metrics($wpdb, $table);
                    break;
                case 'backup':
                    $resp += $this->maintenance_backup($wpdb, $table);
                    break;
                case 'delete_empties':
                    $resp['deleted'] = $this->maintenance_delete_empties($wpdb, $table);
                    break;
                case 'normalize_locales':
                    $resp['updated'] = $this->maintenance_normalize_locales($wpdb, $table);
                    break;
                case 'dedupe':
                    $resp['deleted'] = $this->maintenance_dedupe($wpdb, $table);
                    break;
                case 'cleanup_all':
                    $this->maintenance_backup($wpdb, $table);
                    $resp['deleted_empties'] = $this->maintenance_delete_empties($wpdb, $table);
                    $resp['normalized'] = $this->maintenance_normalize_locales($wpdb, $table);
                    $resp['deleted_dups'] = $this->maintenance_dedupe($wpdb, $table);
                    $resp['after'] = $this->maintenance_metrics($wpdb, $table);
                    break;
                default:
                    wp_send_json_error(['message' => 'unknown_task'], 400);
            }

            wp_send_json_success($resp);
        }

        private static function load_languages_map(): array {
            global $wpdb;
            $rows = (array) $wpdb->get_results(
                $wpdb->prepare(
                    'SELECT language_code, language_name, is_translatable FROM %i',
                    $wpdb->prefix . 'yuz_tra_languages'
                ),
                ARRAY_A
            );
            $map = [];
            foreach ($rows as $r) {
                $code = (string)($r['language_code'] ?? '');
                if ($code === '') continue;
                $map[$code] = [
                    'label' => (string)($r['language_name'] ?? $code),
                    'is_translatable' => (int)($r['is_translatable'] ?? 0),
                ];
            }

            if (empty($map)) {
                $g = get_option('yuztra_general', []);
                $list = $g['yuztra_translatable_languages'] ?? ($g['yuztra_translatable_languages'] ?? []);
                foreach ((array)$list as $code) {
                    $c = (string)$code; if ($c==='') continue;
                    $map[$c] = ['label'=>$c, 'is_translatable'=>1];
                }
            }
            if (empty($map)) {
                $cfg = get_option('yuztra_translation_config', []);
                foreach ((array)($cfg['target_languages'] ?? []) as $code) {
                    $c = (string)$code; if ($c==='') continue;
                    $map[$c] = ['label'=>$c, 'is_translatable'=>1];
                }
            }
            if (empty($map)) {
                foreach ((array)get_option('yuztra_enabled_languages', []) as $code) {
                    $c = (string)$code; if ($c==='') continue;
                    $map[$c] = ['label'=>$c, 'is_translatable'=>1];
                }
            }
            return $map;
        }

        /** Retourne le tableau normalisé des langues configurées */
        private static function get_configured_languages(): array {
            $map = self::load_languages_map();
            $out = [];
            foreach ($map as $code => $row) {
                if (!is_string($code) || $code==='') {
                    continue;
                }
                $out[] = [
                    'code' => $code,
                    'label' => (string)($row['label'] ?? strtoupper($code)),
                    'is_translatable' => !empty($row['is_translatable']),
                ];
            }

            return $out;
        }

        /** Cible “intelligente” : si aucune langue n’est marquée translatable, on prend TOUTES les langues configurées */
        private static function get_target_language_codes(array $langs): array {
            $targets = array_values(array_map(
                static fn($l) => $l['code'],
                array_filter($langs, static fn($l) => !empty($l['is_translatable']))
            ));

            if (count($targets) === 0) {
                $targets = array_values(array_map(static fn($l) => $l['code'], $langs));
            }

            return $targets;
        }

        public function ajax_get_languages() {
            // Public read-only endpoint: no request-derived diagnostics or writes.
            $langs   = self::get_configured_languages();
            $targets = self::get_target_language_codes($langs);

            // Compat: expose "languages" et "langs"
            wp_send_json_success([
                'languages' => $langs,
                'langs'     => $langs,
                'targets'   => $targets,
            ]);
        }

        /**
         * Compat wrapper (ancienne signature)
         */
        public function handleRequest( string $nonce_key, array $required_params, callable $action_callback, array $optional_params = [], bool $require_admin = false ): void {
            if ($require_admin && !current_user_can('manage_options')) {
                wp_send_json_error([
                    'message' => 'unauthorized',
                    'code'    => 'unauthorized'
                ], 403);
            }
            self::__handleRequest($nonce_key, $required_params, $action_callback, true);
        }

        public static function init()
        {
            if (self::$bootstrapped) {
                if (class_exists('YUZTRA_Logger')) {
                    (new YUZTRA_Logger())->log('notice', 'YUZTRA_Ajax::init skipped (already bootstrapped)');
                }
                return;
            }

            // 1) Charger proactivement la classe langues
            if (!class_exists('YUZTRA_Languages') && defined('YUZTRA_INCLUDES')) { // problème ic à cause de la ligne 344
                $path = trailingslashit(YUZTRA_INCLUDES) . 'class-yuz-languages.php';
                if (file_exists($path)) {
                    require_once $path;
                }
            }

            // 2) classes requises (log  die propre si manquantes)
            $required_classes = ['YUZTRA_Settings', 'YUZTRA_DB', 'YUZTRA_Health_Check', 'YUZTRA_Logger'];
            foreach ($required_classes as $class) {
                if (!class_exists($class)) {
                    yuztra_debug_log("🟥 [CRITICAL] YUZ-TRA: $class missing — halting AJAX initialization. [" . current_time('mysql') . "]");
                    /* translators: %s: missing PHP class name. */
                    wp_die( esc_html( sprintf( esc_html__( 'Critical error: %s class missing.', 'yuz-tra' ), $class ) ) );
                }
            }

            $logger       = new YUZTRA_Logger();
            $logger->log('debug', 'Initializing YUZTRA_Ajax class at ' . current_time('mysql'));
            $health_check = new YUZTRA_Health_Check($logger);
            $db           = new YUZTRA_DB($logger, $health_check);

            // Stubs
            $stub_settings         = new \YUZTRA\Fallbacks\NullSettings();
            $stub_languages        = new \YUZTRA\Fallbacks\NullLanguages();
            $stub_language_manager = new \YUZTRA\Fallbacks\NullLanguageManager();
            $stub_tm               = new \YUZTRA\Fallbacks\NullTranslationManager();

            // 3) Manager de langues : privilégier YUZ_Languages
            $languages = class_exists('YUZTRA_Languages')
                ? new YUZTRA_Languages($stub_settings, $db)
                : $stub_languages;

            $language_manager = ($languages instanceof \YUZTRA\Interfaces\LanguageManagerInterface)
                ? $languages
                : (class_exists('YUZTRA_LanguageManager') ? new YUZTRA_LanguageManager($stub_settings, $db) : $stub_language_manager);

            // 4) TM
            try {
                $translation_manager = (class_exists('YUZTRA_Services') && method_exists('YUZTRA_Services', 'tm'))
                    ? YUZTRA_Services::tm()
                    : $stub_tm;
            } catch (\Throwable $e) {
                $logger->log('warning', 'Falling back to NullTranslationManager in YUZTRA_Ajax::init(): ' . $e->getMessage());
                $translation_manager = $stub_tm;
            }

            // 5) Instance
            $instance = new self($translation_manager, $language_manager, $db);

            // 6) Enregistrement des actions
            foreach (['yuztra_ai_test_connection','yuztra_ai_save_settings','yuztra_ai_translate_text',
                'yuztra_ai_translate','yuztra_ai_batch_translate','yuztra_ai_glossary_upload','yuztra_ai_job_status',
                'yuztra_ai_update_status'] as $action) {
                add_action('wp_ajax_'.$action, [$instance,'yuztra_ai_request']);
            }
            $actions = [
                'website_languages' => [
                    'yuztra_ws_get_languages'   => 'yuztra_ws_get_languages',
                    'yuz_tra_languages'          => 'yuztra_ws_get_languages',
                    'yuztra_ws_cre_language'    => 'yuztra_ws_cre_language',
                    'yuztra_ws_cre_alllang'     => 'yuztra_ws_cre_alllang',
                    'yuztra_ws_del_language'    => 'yuztra_ws_del_language',
                    'yuztra_ws_del_alllang'     => 'yuztra_ws_del_alllang',
                    'yuztra_ws_upd_weights'     => 'yuztra_ws_upd_weights',
                    'yuztra_ws_upd_swapsrc'     => 'yuztra_ws_upd_swapsrc',
                    'yuztra_ws_upd_settings'    => 'yuztra_ws_upd_settings',
                    'yuztra_ws_upd_deflang'     => 'yuztra_ws_upd_deflang',
                    'yuztra_ws_upd_srclang'     => 'yuztra_ws_upd_srclang',
                    'yuztra_ws_upd_translatable'=> 'yuztra_ws_upd_translatable',
                    'yuztra_ws_remove_all'      => 'yuztra_ws_remove_all',
                ],
                'language_settings' => [
                    'yuztra_ls_get_settings' => 'yuztra_ls_get_settings',
                    'yuztra_ls_upd_settings' => 'yuztra_ls_upd_settings',
                ],
                'language_switcher' => [
                    'yuztra_sw_get_settings'    => 'yuztra_sw_get_settings',
                    'yuztra_sw_upd_settings'    => 'yuztra_sw_upd_settings',
                    'yuztra_sw_switch_language' => 'yuztra_sw_switch_language',
                    'yuztra_sw_resolve_url'     => 'yuztra_sw_resolve_url',
                ],
                'translate_site_settings' => [
                    'yuztra_ts_get_settings'     => 'yuztra_ts_get_settings',
                    'yuztra_ts_upd_settings'     => 'yuztra_ts_upd_settings',
                    'yuztra_ts_cre_fulltra'      => 'yuztra_ts_cre_fulltra',
                    'yuztra_ts_start_translation'=> 'yuztra_ts_start_translation',
                ],
                'translation_editor' => [
                    'yuztra_te_cre_tstart'      => 'yuztra_te_cre_tstart',
                    'yuztra_te_cre_translation' => 'yuztra_te_cre_translation',
                    'yuztra_te_upd_manual'      => 'yuztra_te_upd_manual',
                    'yuztra_te_upd_publish'     => 'yuztra_te_upd_publish',
                    'yuztra_get_publish_review' => 'yuztra_get_pending_translations', // Backward compat for legacy JS
                    'yuztra_get_pending_translations' => 'yuztra_get_pending_translations',
                    'yuztra_publish_translations'     => 'yuztra_publish_translations',
                ],
                'automatic_translation' => [
                    'yuztra_at_get_api_settings' => 'yuztra_at_get_api_settings',
                    'yuztra_at_upd_api_settings' => 'yuztra_at_upd_api_settings',
                    'yuztra_at_del_api_settings' => 'yuztra_at_del_api_settings',
                    'yuztra_at_get_api_test'     => 'yuztra_at_get_api_test',
                    'yuztra_at_cre_tsilent'      => 'yuztra_at_cre_tsilent',
                ],
                'translation_manager' => [
                    'yuztra_tm_cre_translation' => 'yuztra_tm_cre_translation',
                    'yuztra_tm_cre_page'        => 'yuztra_tm_cre_page',
                    'yuztra_tm_get_translations'=> 'yuztra_tm_get_translations',
                    'yuztra_tm_search'          => 'yuztra_tm_search',
                    'yuztra_tm_del_translation' => 'yuztra_tm_del_translation',
                    'yuztra_tm_translate'       => 'yuztra_tm_translate',
                    'yuztra_translate'              => 'yuztra_translate',
                    'yuztra_tm_test_api'        => 'yuztra_tm_test_api',
                ],
                'strings' => [
                    'yuztra_strings'    => 'yuztra_strings',
                    'yuztra_gt_search'      => 'yuztra_gt_search',
                    'yuztra_gt_save'        => 'yuztra_gt_save',
                    'yuztra_slugs_search'   => 'yuztra_slugs_search',
                    'yuztra_slugs_save'     => 'yuztra_slugs_save',
                    'yuztra_eml_search'     => 'yuztra_eml_search',
                    'yuztra_eml_save'       => 'yuztra_eml_save',
                ],
                'javascript_actions' => [
                    'yuztra_js_upd_database' => 'yuztra_js_upd_database',
                    'yuztra_js_upd_bulkedit' => 'yuztra_js_upd_bulkedit',
                    'yuztra_js_get_gtxtscan' => 'yuztra_js_get_gtxtscan',
                    'yuztra_js_get_regular'  => 'yuztra_js_get_regular',
                    'yuztra_get_regular'         => 'yuztra_get_regular',
                    'yuztra_public_lookup'   => 'yuztra_public_lookup',
                    'yuztra_js_upd_auto'     => 'yuztra_js_upd_auto',
                ],
                'general_settings' => [
                    'yuztra_delete_translation' => 'yuztra_delete_translation',
                ],
                'batch' => [
                    'yuztra_batch' => 'handle_ajax_batch',
                ],
            ];

            foreach ($actions as $module => $module_actions) {
                $registered = 0;
                foreach ($module_actions as $action => $method) {
                    add_action("wp_ajax_{$action}",        [$instance, $method]);
                    if (in_array($action, self::PUBLIC_AJAX_ACTIONS, true)) {
                        add_action("wp_ajax_nopriv_{$action}", [$instance, $method]);
                    }
                    YUZTRA_Health_Check::ensure(
                        has_action("wp_ajax_{$action}"),
                        "Missing AJAX handler for action '{$action}' in module '{$module}'",
                        __METHOD__
                    );
                    $registered++;
                }
                if ($registered > 0) {
                    $logger->log('success', "Registered {$registered} AJAX action(s) for module {$module}");
                }
            }


            // Register editor save AFTER we have an instance
            add_action('wp_ajax_yuztra_save_translation', [$instance, 'yuztra_save_translation']);
            // (moved registration of yuz_save_translation after we instantiate $instance) /// ici

            $logger->log('success', 'YUZTRA_Ajax class initialized successfully');
            // Endpoints additionnels (Advanced)
            $instance->registerEndpoints();

            self::$bootstrapped = true;
        }

        /** -------------------- CORE AJAX WRAPPER -------------------- */
        /** Bounded request tree, preserving the slashed convention of legacy callbacks. */
        private static function sanitize_callback_request(array $request): array {
            if (strlen(wp_json_encode($request) ?: '') > 2000000) {
                throw new InvalidArgumentException('request_too_large');
            }
            $clean = static function ($value, string $key, int $depth) use (&$clean) {
                if ($depth > 8) throw new InvalidArgumentException('request_too_deep');
                if ($key === 'target_langs') return self::validated_target_languages($value);
                if (is_array($value)) {
                    if (count($value) > 500) throw new InvalidArgumentException('request_array_too_large');
                    $out = [];
                    foreach ($value as $child_key => $child) {
                        if (!is_int($child_key) && !preg_match('/^[a-zA-Z0-9_.:\\-]{1,128}$/D', (string) $child_key)) {
                            throw new InvalidArgumentException('invalid_request_key');
                        }
                        $out[$child_key] = $clean($child, is_int($child_key) ? $key : $child_key, $depth + 1);
                    }
                    return $out;
                }
                if (is_bool($value) || is_int($value) || is_float($value) || $value === null) return $value;
                if (!is_string($value) || wp_check_invalid_utf8($value) !== $value) {
                    throw new InvalidArgumentException('invalid_request_value');
                }
                if (in_array($key, ['originals','block_keys','skip_machine_translation','items','ids','texts','entries','payload','data'], true)
                    && preg_match('/^\\s*[\\[{]/', $value)) {
                    $decoded = json_decode($value, true, 10);
                    if (!is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) throw new InvalidArgumentException('invalid_request_json');
                    return wp_json_encode($clean($decoded, $key, $depth + 1));
                }
                if (in_array($key, ['action','context','object_type','provider','mode'], true)) return sanitize_key($value);
                if (in_array($key, ['page_url','current_url','url','endpoint'], true)) return esc_url_raw($value);
                if (in_array($key, ['post_id','page_id','object_id','translation_id','source_lang_id','target_lang_id','offset','limit'], true)) {
                    if (!ctype_digit($value)) throw new InvalidArgumentException('invalid_request_integer');
                    return (string) absint($value);
                }
                // Markup is allowed for translation content; scripts/events are never allowed.
                return wp_kses_post($value);
            };
            return wp_slash($clean(wp_unslash($request), '', 0));
        }

        public static function __handleRequest($nonce_key, array $required = [], callable $callback = null, bool $return_json = true) {
            // Refuse before buffering, logging or invoking any callback. A refusal
            // must not be caught as an application failure and logged afterwards.
            $nonce_param = '';
            foreach (['nonce', '_ajax_nonce', 'security'] as $nonce_field) {
                if (isset($_REQUEST[$nonce_field]) && is_string($_REQUEST[$nonce_field])) {
                    $nonce_param = sanitize_text_field(wp_unslash($_REQUEST[$nonce_field]));
                    break;
                }
            }
            $nonce_key = self::normalize_nonce_key($nonce_key);
            if (!$nonce_param || !wp_verify_nonce($nonce_param, $nonce_key)) {
                wp_send_json_error(['message' => 'invalid_nonce', 'code' => 'invalid_nonce', 'expected' => $nonce_key], 403);
                return;
            }
            if ($return_json && !headers_sent()) {
                header('Content-Type: application/json; charset=' . get_bloginfo('charset'));
            }

            // Tamponner toute sortie parasite
            ob_start();
            $obStarted = true;

            try {
                self::ensure_req_id();
                // Correlation id (si fourni par le client)
                $cid    = is_string($_REQUEST['cid'] ?? null) ? sanitize_text_field(wp_unslash($_REQUEST['cid'])) : null;
                $action = is_string($_REQUEST['action'] ?? null) ? sanitize_key(wp_unslash($_REQUEST['action'])) : '';

                // These routes translate content; reject before rate-limit writes.
                if (in_array($action, self::RATE_LIMITED_ACTIONS, true)
                    && !current_user_can('manage_options') && !current_user_can('yuztra_translate_content')) {
                    if ($obStarted) { ob_end_clean(); $obStarted = false; }
                    wp_send_json_error(['message' => 'forbidden', 'code' => 'forbidden'], 403);
                    return;
                }
                // Anti-rafale basique côté serveur (IP+action)
                $rl = self::check_rate_limit($action);
                if (!$rl['allow']) {
                    if ($obStarted) { ob_end_clean(); }
                    self::send_json_error([
                        'message'     => 'rate_limited',
                        'retry_after' => (int) $rl['retry_after'],
                        'action'      => $action,
                    ], 429);
                }

                // Pre-normalize synonyms for required keys (e.g., language ← lang/target_lang)
                if (is_array($required) && in_array('language', $required, true) && !isset($_REQUEST['language'])) {
                    foreach (['lang', 'target_lang'] as $alt) {
                        if (isset($_REQUEST[$alt]) && $_REQUEST[$alt] !== '') {
                            $val = is_string($_REQUEST[$alt]) ? sanitize_text_field(wp_unslash($_REQUEST[$alt])) : '';
                            if ($val !== '') { $_REQUEST['language'] = $val; break; }
                        }
                    }
                }
                // Normalize common locale shapes to en_US/fr_FR
                if (isset($_REQUEST['language']) && is_string($_REQUEST['language'])) {
                    $L = str_replace('-', '_', sanitize_text_field(wp_unslash($_REQUEST['language'])));
                    if (strpos($L, '_') !== false) {
                        list($a, $b) = explode('_', $L, 2);
                        $_REQUEST['language'] = strtolower($a) . '_' . strtoupper($b);
                    } else {
                        $_REQUEST['language'] = strtolower($L);
                    }
                }

                // Vérif champs requis
                foreach ($required as $key) {
                    if (!isset($_REQUEST[$key])) {
                        $payload = ['message' => "Missing parameter: {$key}", 'code' => 'missing_param', 'param' => $key];
                        if ($obStarted) { ob_end_clean(); }
                        self::send_json_error($payload, 400);
                    }
                }

                // Exécute l’action
                $request_data = self::sanitize_callback_request($_REQUEST);
                $data = $callback ? call_user_func($callback, $request_data) : [];

                // Vide et inspecte le tampon
                $noise = $obStarted ? ob_get_clean() : '';
                if (!empty($noise)) {
                    (new YUZTRA_Logger())->log('warning', 'Output captured before JSON', ['bytes' => strlen($noise)]);
                }

                if (is_wp_error($data)) {
                    self::send_json_error(['message'=>$data->get_error_message(),'code'=>$data->get_error_code()],503);
                }
                if (is_array($data) && (($data['ok'] ?? null) === false || ($data['success'] ?? null) === false)) {
                    if (!isset($data['message'])) $data['message']=is_string($data['error'] ?? null) ? $data['error'] : 'operation_failed';
                    self::send_json_error($data,422);
                }
                self::send_json_success($data ?? []);
            } catch (\Throwable $e) {
                if ($obStarted) { @ob_end_clean(); }
                try {
                    (new YUZTRA_Logger())->log('error', 'AJAX handler threw', [
                        'type'    => get_class($e),
                    ]);
                } catch (\Throwable $ignored) {}
                $code = ($e instanceof \InvalidArgumentException) ? 400 : 500;
                self::send_json_error([
                    'message' => $e->getMessage(),
                    'code'    => $e->getCode(),
                ], $code);
            }
        }

        /** -------------------- HELPERS -------------------- */
        private function ensure_db_tables(): void {
            try { $this->db->ensure_tables(); }
            catch (\Throwable $e) { throw new \Exception( esc_html( 'DB init failed: ' . $e->getMessage() ) ); }
        }

        private function normalize_lang_item($l): array {
            if (is_object($l) && method_exists($l, 'to_array')) {
                $arr = $l->to_array();
                $arr = is_array($arr) ? $arr : (array)$arr;
            }
            if (!isset($arr)) {
                if (is_object($l)) {
                    $arr = get_object_vars($l);
                } elseif (is_array($l)) {
                    $arr = $l;
                } else {
                    $arr = ['language_code' => (string) $l];
                }
            }

            if (isset($arr['language_code'])) {
                $arr['language_code'] = $this->normalize_language_code((string) $arr['language_code']);
            }

            return $arr;
        }

        private function normalize_lang_list($list): array {
            if (!is_array($list)) return [];
            return array_map([$this, 'normalize_lang_item'], $list);
        }

        private function get_lang_code($l): ?string {
            if (is_object($l)) {
                if (isset($l->language_code)) return (string)$l->language_code;
                if (method_exists($l, 'get_language_code')) return (string)$l->get_language_code();
                if (method_exists($l, 'to_array')) {
                    $arr = $l->to_array();
                    if (is_array($arr) && isset($arr['language_code'])) return (string)$arr['language_code'];
                }
                $vars = get_object_vars($l);
                return isset($vars['language_code']) ? (string)$vars['language_code'] : null;
            }
            if (is_array($l) && isset($l['language_code'])) return (string)$l['language_code'];
            if (is_string($l)) return $l;
            return null;
        }

        private function build_ws_payload(): array {
    global $wpdb;
    $this->ensure_db_tables();

    $table = $wpdb->prefix . 'yuz_tra_languages';

    // 1) La DB est l'autorité: le manager peut servir des objets minimaux ou périmés.
    $dbAll = $wpdb->get_results($wpdb->prepare('SELECT * FROM %i ORDER BY language_weight ASC', $table), ARRAY_A);
    $dbAll = is_array($dbAll) ? $dbAll : [];

    $dbTrans = array_values(array_filter($dbAll, static function ($row) {
        return !empty($row['is_translatable']) || !empty($row['is_default']) || !empty($row['is_source']);
    }));

    $all = !empty($dbAll) ? $dbAll : $this->language_manager->get_all_languages();
    $trans = !empty($dbTrans) ? $dbTrans : $this->language_manager->get_translatable_languages();

    if (!is_array($trans) || empty($trans)) {
        $opt   = get_option('yuztra_general', []);
        $codes = array_values(array_unique(array_filter((array)($opt['yuztra_translatable_languages'] ?? []))));
        if (!empty($codes)) {
            $trans = array_map(fn($c)=>['language_code'=>$c,'is_translatable'=>1], $codes);
        }
    }
    if (!is_array($all) || empty($all)) {
        $all = [];
    }

    // 2) Normalisation / merge
    $allN       = $this->normalize_lang_list($all);
    $indexAll   = [];
    foreach ($allN as $row) {
        $code = isset($row['language_code']) ? (string)$row['language_code'] : null;
        if ($code !== null && $code !== '') { $indexAll[$code] = $row; }
    }
    $normalizedTrans = $this->normalize_lang_list($trans);
    $mergedTrans     = array_map(function ($entry) use ($indexAll) {
        $code = isset($entry['language_code']) ? (string)$entry['language_code'] : null;
        return ($code && isset($indexAll[$code])) ? array_merge($indexAll[$code], $entry) : $entry;
    }, $normalizedTrans);
    $translatableCodes = [];
    foreach ($mergedTrans as $row) {
        $code = isset($row['language_code']) ? (string) $row['language_code'] : '';
        if ($code !== '') {
            $translatableCodes[$code] = true;
        }
    }

    // 3) Non-translatables (planifiés)
    $non = array_values(array_filter($allN, static function ($row) use ($translatableCodes) {
        $code = isset($row['language_code']) ? (string) $row['language_code'] : '';
        if ($code === '') {
            return false;
        }
        return !isset($translatableCodes[$code]);
    }));

    // 4) Journaliser MAINTENANT (après calcul) - logger robuste (pas de méthode locale)
    if (class_exists('YUZTRA_Logger')) {
        (new YUZTRA_Logger())->log('debug', 'ws_payload', [
            'trans_count' => count($mergedTrans),
            'non_count'   => count($non),
            'codes'       => array_map(fn($r)=>$r['language_code'] ?? '', array_slice($mergedTrans, 0, 10)),
        ]);
    }

    return ['languages'=>$mergedTrans, 'non_translatable'=>$non];
}


        /* -------------------- WEBSITE LANGUAGES -------------------- */
public function yuztra_ws_get_languages() {
    // Reject non-string nonce values before WordPress verifies the request.
    $verified = false;
    foreach (['yuztra_nonce', 'nonce', 'security'] as $nonce_field) {
        if (isset($_REQUEST[$nonce_field]) && is_string($_REQUEST[$nonce_field])
            && check_ajax_referer('yuztra_nonce', $nonce_field, false)) {
            $verified = true;
            break;
        }
    }

    if (!$verified) {
        $this->log_debug('ajax-incoming-yuztra_ws_get_languages:verify', [
            'verify_yuz_tra_nonce' => false,
        ]);
        wp_send_json_error(['code'=>'bad_nonce','msg'=>'invalid_nonce'], 403);
    }

    if (!current_user_can('manage_options') && !current_user_can('yuztra_translate_content')) {
        wp_send_json_error(['code'=>'forbidden','msg'=>'insufficient_permissions'], 403);
    }

    $this->log_debug('ajax-incoming-yuztra_ws_get_languages:verify', [
        'verify_yuz_tra_nonce' => 1,
    ]);

    // 5) Construire le payload + métriques
    $payload = $this->build_ws_payload();
    $langs   = is_array($payload['languages'] ?? null) ? $payload['languages'] : [];
    $nontr   = is_array($payload['non_translatable'] ?? null) ? $payload['non_translatable'] : [];

    $this->log_debug('ajax-incoming-yuztra_ws_get_languages:payload', [
        'nb_total_langs'  => count($langs) + count($nontr),
        'nb_translatable' => count($langs),
        'nb_non_trans'    => count($nontr),
    ]);

    wp_send_json_success($payload);
}

/** Helper de log (ne divulgue pas de secrets) */
private function log_debug($tag, array $data) {
    if (class_exists('\YUZ\YUZTRA_Assets')) {
        \YUZ\YUZTRA_Assets::get()->log_debug($tag, $data);
    } elseif (class_exists('YUZTRA_Logger')) {
        (new YUZTRA_Logger())->log('debug', $tag, $data);
    } else {
        yuztra_debug_log($tag . ': ' . wp_json_encode($data));
    }
}


        public function yuztra_ws_cre_language() {
            // Vérif stricte via check_ajax_referer (clé front attendue: 'nonce')
            check_ajax_referer('yuztra_nonce', 'nonce');

            self::__handleRequest('yuztra_nonce',
                ['language_code'],
                function ($data) {
                    if (!current_user_can('manage_options')) {
                        wp_send_json_error(['message' => 'unauthorized', 'code' => 'unauthorized'], 403);
                    }
                    global $wpdb;
                    $this->ensure_db_tables();

                    $code  = sanitize_text_field($data['language_code']);
                    $table = $wpdb->prefix . 'yuz_tra_languages';

                    // ✅ Ne pas bloquer la source : on autorise à la marquer translatable.

                    // existe ?
                    $exists = (int)$wpdb->get_var($wpdb->prepare(
                        'SELECT COUNT(*) FROM %i WHERE language_code = %s',
                        $table,
                        $code
                    ));

                    if ($exists === 0) {
                        (new YUZTRA_Logger())->log('info', "[WS_ADD] inserting new language {$code}");
                        // Métadonnées si dispo via référentiel
                        $langObj      = method_exists($this->language_manager, 'get_by_code') ? $this->language_manager->get_by_code($code) : null;
                        $languageName = $langObj->language_name ?? strtoupper($code);
                        $slug         = $langObj->slug ?? strtolower(str_replace('_','-',$code));
                        $browserSlug  = $langObj->browser_slug ?? strtolower(substr($code,0,2));

                        $maxWeight = (int)$wpdb->get_var($wpdb->prepare('SELECT MAX(language_weight) FROM %i', $table));
                        $ok = $wpdb->insert($table, [
                            'language_name'    => $languageName,
                            'language_code'    => $code,
                            'slug'             => $slug,
                            'browser_slug'     => $browserSlug,
                            'is_translatable'  => 1,
                            'is_default'       => 0,
                            'is_source'        => 0,
                            'language_weight'  => ( (int) $maxWeight  +1 ),
                            'created_at'       => current_time('mysql'),
                            'updated_at'       => current_time('mysql'),
                        ], ['%s','%s','%s','%s','%d','%d','%d','%d','%s','%s']);
                        if ($ok === false) {
                            (new YUZTRA_Logger())->log('error', '[WS_ADD] insert failed', ['code'=>$code, 'error'=>$wpdb->last_error]);
                            throw new \Exception( esc_html( "DB insert failed: ".$wpdb->last_error ) );                        }
                    } else {
                        // existe -> le rendre translatable
                        (new YUZTRA_Logger())->log('info', "[WS_ADD] enabling translatable for {$code}");
                        $ok = $wpdb->update(
                            $table,
                            ['is_translatable'=>1, 'updated_at'=>current_time('mysql')],
                            ['language_code'=>$code],
                            ['%d','%s'],
                            ['%s']
                        );
                        if ($ok === false) {
                            (new YUZTRA_Logger())->log('error', '[WS_ADD] update failed', ['code'=>$code, 'error'=>$wpdb->last_error]);
                            throw new \Exception( esc_html( "DB update failed: ".$wpdb->last_error ) );                        }
                    }

                    // ---- Option miroir : ajouter le code aux listes translatables (legacy  nouveau)
                    $options = $this->get_settings()->get_option('yuztra_general');
                    if (!is_array($options)) { $options = []; }
                    $options['yuztra_translatable_languages'] = $options['yuztra_translatable_languages'] ?? [];
                    if (!in_array($code, $options['yuztra_translatable_languages'], true)) {
                        $options['yuztra_translatable_languages'][] = $code;
                    }
                    $options['yuztra_translatable_languages'] = $options['yuztra_translatable_languages'] ?? [];
                    if (!in_array($code, $options['yuztra_translatable_languages'], true)) {
                        $options['yuztra_translatable_languages'][] = $code;
                    }
                    unset($options['yuztra_translatable_languages[]'], $options['yuztra_translatable_languages[]']);

                    // ---- Garantir default/source s'ils n'existent pas encore
                    $hasDefault = (int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE is_default = 1', $table));
                    $hasSource  = (int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE is_source = 1', $table));

                    $madeDefault = false;
                    $madeSource  = false;

                    if ($hasDefault === 0) {
                        $wpdb->query($wpdb->prepare('UPDATE %i SET is_default = 0', $table));
                        $wpdb->update($table, ['is_default'=>1, 'updated_at'=>current_time('mysql')], ['language_code'=>$code], ['%d','%s'], ['%s']);
                        $options['yuztra_default_language'] = $code;
                        $madeDefault = true;
                    }
                    if ($hasSource === 0) {
                        $wpdb->query($wpdb->prepare('UPDATE %i SET is_source = 0', $table));
                        $wpdb->update($table, ['is_source'=>1, 'updated_at'=>current_time('mysql')], ['language_code'=>$code], ['%d','%s'], ['%s']);
                        $options['yuztra_source_language'] = $code;
                        $madeSource = true;
                    }

                    $this->get_settings()->update_option('yuztra_general', $options);

                    // Ensure fresh lists after mutation
                    $this->invalidate_language_caches([$code]);
                    $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE language_code=%s', $table, $code), ARRAY_A);
                    $payload = $this->build_ws_payload();
                    $payload['debug_row'] = $row;
                    $payload['message']      = __('Language created/activated', 'yuz-tra');
                    $payload['made_default'] = $madeDefault;
                    $payload['made_source']  = $madeSource;
                    (new YUZTRA_Logger())->log('success', '[WS_ADD] done', ['code'=>$code, 'row'=>$row]);
                    return $payload;
                }
            );
        }

        public function yuztra_ws_cre_alllang() {
            // Vérif stricte via check_ajax_referer (clé front attendue: 'nonce')
            check_ajax_referer('yuztra_nonce', 'nonce');

            self::__handleRequest('yuztra_nonce',
                [],
                function ($data) {
                    if (!current_user_can('manage_options')) {
                        wp_send_json_error(['message' => 'unauthorized', 'code' => 'unauthorized'], 403);
                    }
                    global $wpdb;
                    $this->ensure_db_tables();

                    $table_name = $wpdb->prefix . 'yuz_tra_languages';
                    if (!$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table_name)))) {
                        (new YUZTRA_Logger())->log('critical', "Languages table $table_name missing after recreation attempt");
                        return [];
                    }

                    $res = $wpdb->query($wpdb->prepare('UPDATE %i SET is_translatable = 1, language_weight = IF(language_weight=0, 999, language_weight)', $table_name));
                    if ($res === false) {
                        throw new \Exception( esc_html( "Failed to update all languages: " . $wpdb->last_error ) );                    }

                    // options miroir (mettre à jour les deux clés et nettoyer les clés parasites)
                    $langs_col = $wpdb->get_col($wpdb->prepare('SELECT language_code FROM %i WHERE is_translatable = 1', $table_name));
                    $options   = $this->get_settings()->get_option('yuztra_general');
                    if (!is_array($options)) { $options = []; }
                    $options['yuztra_translatable_languages'] = $langs_col ?: [];
                    $options['yuztra_translatable_languages'] = $langs_col ?: [];
                    unset($options['yuztra_translatable_languages[]'], $options['yuztra_translatable_languages[]']);
                    // assurer default/source
                    $def = (int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE is_default=1', $table_name));
                    $src = (int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE is_source=1', $table_name));
                    if ($def===0 && !empty($langs_col)) {
                        $wpdb->update($table_name, ['is_default'=>1], ['language_code'=>$langs_col[0]], ['%d'], ['%s']);
                        $options['yuztra_default_language'] = $langs_col[0];
                    }
                    if ($src===0 && !empty($langs_col)) {
                        $wpdb->update($table_name, ['is_source'=>1], ['language_code'=>$langs_col[0]], ['%d'], ['%s']);
                        $options['yuztra_source_language'] = $langs_col[0];
                    }
                    $this->get_settings()->update_option('yuztra_general', $options);

                    $this->invalidate_language_caches();
                    return $this->build_ws_payload();
                }
            );
        }

        public function yuztra_ws_del_language() {
            self::__handleRequest('yuztra_nonce',
                ['language_code'],
                function ($data) {
                    if (!current_user_can('manage_options')) {
                        wp_send_json_error(['message' => 'unauthorized', 'code' => 'unauthorized'], 403);
                    }
                    global $wpdb;
                    $this->ensure_db_tables();

                    $table_name = $wpdb->prefix . 'yuz_tra_languages';
                    if (!$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table_name)))) {
                        (new YUZTRA_Logger())->log('critical', "Languages table $table_name missing after recreation attempt");
                        return [];
                    }

                    $code = sanitize_text_field($data['language_code']);
                    (new YUZTRA_Logger())->log('info', '[WS_DEL] disabling translatable', ['code'=>$code]);
                    $res  = $wpdb->update($table_name, ['is_translatable' => 0, 'updated_at' => current_time('mysql')], ['language_code' => $code], ['%d','%s'], ['%s']);
                    if ($res === false) {
                        (new YUZTRA_Logger())->log('error', '[WS_DEL] update failed', ['code'=>$code, 'error'=>$wpdb->last_error]);
                        throw new \Exception( esc_html( "Failed to update language {$code}: " . $wpdb->last_error ) );                    }

                    $options = (array) $this->get_settings()->get_option('yuztra_general');
                    $list    = (array) ($options['yuztra_translatable_languages'] ?? []);
                    $options['yuztra_translatable_languages'] = array_values(array_diff($list, [$code]));
                    if (isset($options['yuztra_translatable_languages']) && is_array($options['yuztra_translatable_languages'])) {
                        $options['yuztra_translatable_languages'] = array_values(array_diff((array) $options['yuztra_translatable_languages'], [$code]));
                    }
                    unset($options['yuztra_translatable_languages[]'], $options['yuztra_translatable_languages[]']);
                    $this->get_settings()->update_option('yuztra_general', $options); // bump version + caches

                    $this->invalidate_language_caches([$code]);
                    $payload = $this->build_ws_payload();
                    // Trace the resulting translatable list to help diagnose duplicates
                    try {
                        $langs = isset($payload['languages']) && is_array($payload['languages'])
                            ? array_map(function($L){ return is_array($L) ? ($L['language_code'] ?? '') : (is_object($L) ? ($L->language_code ?? '') : (string)$L); }, $payload['languages'])
                            : [];
                        (new YUZTRA_Logger())->log('info', '[WS_DEL] resulting languages', ['count' => count($langs), 'codes' => $langs]);
                    } catch (\Throwable $e) { /* no-op */ }
                    return $payload;
                }
            );
        }

        public function yuztra_ws_del_alllang() {
            self::__handleRequest('yuztra_nonce',
                [],
                function ($data) {
                    if (!current_user_can('manage_options')) {
                        wp_send_json_error(['message' => 'unauthorized', 'code' => 'unauthorized'], 403);
                    }
                    global $wpdb;
                    $this->ensure_db_tables();

                    $table_name = $wpdb->prefix . 'yuz_tra_languages';
                    if (!$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table_name)))) {
                        (new YUZTRA_Logger())->log('critical', "Languages table $table_name missing after recreation attempt");
                        return [];
                    }

                    (new YUZTRA_Logger())->log('info', '[WS_DEL_ALL] disabling all translatables');
                    $res = $wpdb->query($wpdb->prepare('UPDATE %i SET is_translatable = 0 WHERE is_translatable = 1', $table_name));
                    if ($res === false) {
                        (new YUZTRA_Logger())->log('error', '[WS_DEL_ALL] update failed', ['error'=>$wpdb->last_error]);
                        throw new \Exception( esc_html( "Failed to update all languages: " . $wpdb->last_error ) );                    }

                    $options = get_option('yuztra_general', []);
                    if (!is_array($options)) { $options = []; }
                    $options['yuztra_translatable_languages'] = [];
                    $options['yuztra_translatable_languages'] = [];
                    unset($options['yuztra_translatable_languages[]'], $options['yuztra_translatable_languages[]']);
                    update_option('yuztra_general', $options, false);

                    $this->invalidate_language_caches();
                    return $this->build_ws_payload();
                }
            );
        }

        public function yuztra_ws_upd_weights() {
            self::__handleRequest('yuztra_hvy_nonce',
                ['weights'],
                function ($data) {
                    if (!current_user_can('manage_options')) {
                        wp_send_json_error(['message' => 'unauthorized', 'code' => 'unauthorized'], 403);
                    }
                    global $wpdb;
                    $this->ensure_db_tables();

                    $table_name = $wpdb->prefix . 'yuz_tra_languages';
                    if (!$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table_name)))) {
                        (new YUZTRA_Logger())->log('critical', "Languages table $table_name missing after recreation attempt");
                        return [];
                    }

                    if (!is_array($data['weights']) || empty(array_filter($data['weights'], 'is_scalar'))) {
                        throw new \Exception( esc_html( 'Invalid weights data' ) );                    }

                    foreach (wp_unslash($data['weights']) as $code => $weight) {
                        $code_sanitized = sanitize_text_field($code);
                        $weight_int     = intval($weight);
                        $res = $wpdb->update($table_name, ['language_weight' => $weight_int, 'updated_at' => current_time('mysql')], ['language_code' => $code_sanitized], ['%d','%s'], ['%s']);
                        if ($res === false) {
                            (new YUZTRA_Logger())->log('critical', "Update failed for {$code_sanitized}: " . $wpdb->last_error);
                        }
                    }

                    $this->invalidate_language_caches(array_keys((array)($data['weights'] ?? [])));
                    return $this->build_ws_payload();
                }
            );
        }

        public function yuztra_ws_upd_swapsrc() {
            self::__handleRequest('yuztra_nonce',
                ['new_source_code'],
                function ($data) {
                    if (!current_user_can('manage_options')) {
                        wp_send_json_error(['message' => 'unauthorized', 'code' => 'unauthorized'], 403);
                    }
                    $new = sanitize_text_field($data['new_source_code']);
                    $ok  = $this->language_manager->swap_source_and_target($new);
                    if (!$ok) {
                        throw new \Exception( esc_html( "Source swap failed for {$new}" ) );                    }

                    $this->invalidate_language_caches([$new]);
                    $payload = $this->build_ws_payload();
                    $src   = $this->language_manager->get_source_language();
                    $payload['source_language'] = $this->normalize_lang_item($src);
                    return $payload;
                }
            );
        }

        public function yuztra_ws_upd_settings() {
    self::__handleRequest('yuztra_nonce',
        ['website_languages'],
        function ($data) {
            if (!current_user_can('yuztra_translate_content')) {
                wp_send_json_error(['message'=>'unauthorized','code'=>'unauthorized'], 403);
            }
            global $wpdb;
            $this->ensure_db_tables();

            $in    = (array) $data['website_languages'];
            $table = $wpdb->prefix . 'yuz_tra_languages';

            // -- 0) Parse & sanitize inputs -----------------------------------
            $hasTrans = array_key_exists('yuztra_translatable_languages', $in)
                        && is_array($in['yuztra_translatable_languages'])
                        && count($in['yuztra_translatable_languages']) > 0;

            $trans = $hasTrans
                ? array_values(array_unique(array_map('sanitize_text_field', $in['yuztra_translatable_languages'])))
                : [];

            // UI accepts both new and legacy keys; we store *manual* overrides only.
            $defRaw = $in['yuztra_default_language'] ?? $in['yuztra_default_language'] ?? '';
            $srcRaw = $in['yuztra_source_language']  ?? $in['yuztra_source_language']  ?? '';
            $defMan = !empty($defRaw) ? sanitize_text_field($defRaw) : '';
            $srcMan = !empty($srcRaw) ? sanitize_text_field($srcRaw) : '';

            $slugs = (isset($in['yuztra_slug']) && is_array($in['yuztra_slug']))
                ? array_map('sanitize_text_field', $in['yuztra_slug'])
                : [];

            $codes = (isset($in['yuztra_code']) && is_array($in['yuztra_code']))
                ? array_map('sanitize_text_field', $in['yuztra_code'])
                : [];

            // -- 1) Translatable list (differential, no global wipe if not provided)
            if ($hasTrans) {
                // Disable translatable for codes NOT in submitted list
                $placeholders = implode(',', array_fill(0, count($trans), '%s'));
                $sql = "UPDATE $table SET is_translatable = 0
                        WHERE is_translatable = 1
                          AND language_code NOT IN ($placeholders)";
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Placeholder list is generated from sanitized language codes.
                $wpdb->query($wpdb->prepare($sql, ...$trans));

                // Ensure each translatable exists, enable and set weight
                $weight = 1;
                foreach ($trans as $tCode) {
                    $id = $wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE language_code = %s', $table, $tCode));
                    if (!$id) {
                        $slug        = strtolower(str_replace('_','-',$tCode));
                        $browserSlug = strtolower(substr($tCode,0,2));
                        $wpdb->insert($table, [
                            'language_name'    => strtoupper($tCode),
                            'language_code'    => $tCode,
                            'slug'             => $slug,
                            'browser_slug'     => $browserSlug,
                            'is_translatable'  => 1,
                            'is_default'       => 0,
                            'is_source'        => 0,
                            'language_weight'  => $weight,
                            'created_at'       => current_time('mysql'),
                            'updated_at'       => current_time('mysql'),
                        ], ['%s','%s','%s','%s','%d','%d','%d','%d','%s','%s']);
                    } else {
                        $wpdb->update($table, [
                            'is_translatable' => 1,
                            'language_weight' => $weight,
                            'updated_at'      => current_time('mysql'),
                        ], ['language_code' => $tCode], ['%d','%d','%s'], ['%s']);
                    }
                    $weight++;
                }
            }

            // -- 2) NEVER touch is_default/is_source here (handled by enforce_language_rules)

            // -- 3) Update slugs/codes ----------------------------------------
            if (!empty($slugs)) {
                foreach ($slugs as $oldCode => $slugVal) {
                    $wpdb->update($table,
                        ['slug' => sanitize_text_field($slugVal), 'updated_at' => current_time('mysql')],
                        ['language_code' => sanitize_text_field($oldCode)],
                        ['%s','%s'],
                        ['%s']
                    );
                }
            }

            if (!empty($codes)) {
                foreach ($codes as $oldCode => $newCode) {
                    $oldCode = sanitize_text_field($oldCode);
                    $newCode = sanitize_text_field($newCode);
                    if ($newCode && $newCode !== $oldCode) {
                        $wpdb->update($table,
                            ['language_code' => $newCode, 'updated_at' => current_time('mysql')],
                            ['language_code' => $oldCode],
                            ['%s','%s'],
                            ['%s']
                        );
                    }
                }
            }

            // -- 4) Options: store ONLY manual overrides + slug/code mirrors ---
            $opt = $this->settings->get_option('yuztra_general', []);
            if (!is_array($opt)) { $opt = []; }

            // Manual overrides act as "switch": presence = manual, empty = back to auto
            if (array_key_exists('yuztra_default_language', $in) || array_key_exists('yuztra_default_language', $in)) {
                $opt['yuztra_default_manual'] = $defMan; // may be '' to revert to auto
            }
            if (array_key_exists('yuztra_source_language', $in) || array_key_exists('yuztra_source_language', $in)) {
                $opt['yuztra_source_manual'] = $srcMan;   // may be '' to revert to auto
            }

            if ($hasTrans) {
                $opt['yuztra_translatable_languages'] = $trans;
            }
            if (!empty($slugs)) { $opt['yuztra_slug'] = $slugs; }
            if (!empty($codes)) { $opt['yuztra_code'] = $codes; }

            // Clean legacy UI keys to avoid drift
            unset($opt['yuztra_translatable_languages'], $opt['yuztra_default_language'], $opt['yuztra_source_language'], $opt['yuztra_slug'], $opt['yuztra_code']);

            $this->settings->update_option('yuztra_general', $opt);

            // -- 5) Recompute effective default/source + align DB -------------
            if (property_exists($this, 'language_manager') && method_exists($this->language_manager, 'enforce_language_rules')) {
                $this->language_manager->enforce_language_rules();
            } else {
                // fallback if enforce is local to this class
                if (method_exists($this, 'enforce_language_rules')) {
                    $this->enforce_language_rules();
                }
            }

            // -- 6) Invalidate caches/transients ------------------------------
            $invalidate = array_filter([
                $opt['yuztra_default_manual'] ?? null,
                $opt['yuztra_source_manual']  ?? null,
            ]);
            if ($hasTrans) {
                $invalidate = array_unique(array_merge($invalidate, $trans));
            }

            $this->invalidate_language_caches($invalidate);

            (new YUZTRA_Logger())->log('success', 'Website languages options updated (manual overrides + sync)');
            return $this->build_ws_payload();
        },
        true
    );
}


        public function yuztra_ws_upd_deflang() {
            self::__handleRequest('yuztra_con_nonce',
                [],
                function ($data) {
                    if (!current_user_can('yuztra_translate_content')) {
                        wp_send_json_error(['message'=>'unauthorized','code'=>'unauthorized'],403);
                    }
                    global $wpdb;
                    $this->ensure_db_tables();

                    $table_name = $wpdb->prefix . 'yuz_tra_languages';
                    if (!$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table_name)))) {
                        (new YUZTRA_Logger())->log('critical', "Languages table $table_name missing after recreation attempt");
                        return [];
                    }

                    // Tolérance aux payloads historiques
                    $language_code = sanitize_text_field(
                        $data['language_code']
                            ?? $data['new_default_code']
                            ?? $data['new_default']
                            ?? ''
                    );
                    if ($language_code === '') {
                        wp_send_json_error(['message' => 'missing language_code', 'code' => 'bad_request'], 400);
                    }

                    $wpdb->update($table_name, ['is_default' => 0], ['is_default' => 1], ['%d'], ['%d']);
                    $res = $wpdb->update($table_name, ['is_default' => 1, 'updated_at' => current_time('mysql')], ['language_code' => $language_code], ['%d','%s'], ['%s']);
                    if ($res === false) {
                        throw new \Exception( esc_html( "Failed to set default language {$language_code}: " . $wpdb->last_error ) );                    }

                    $options = get_option('yuztra_general', []);
                    $options['yuztra_default_language'] = $language_code;
                    update_option('yuztra_general', $options, false);

                    $this->language_manager->enforce_language_rules(null, $language_code);

                    $this->invalidate_language_caches([$language_code]);
                    $payload = $this->build_ws_payload();
                    $payload['message'] = __('Default language set', 'yuz-tra');
                    return $payload;
                },
                true
            );
        }

        public function yuztra_ws_upd_srclang() {
            self::__handleRequest('yuztra_con_nonce',
                [],
                function ($data) {
                    if (!current_user_can('yuztra_translate_content')) {
                        wp_send_json_error(['message'=>'unauthorized','code'=>'unauthorized'],403);
                    }
                    global $wpdb;
                    $this->ensure_db_tables();

                    $table_name = $wpdb->prefix . 'yuz_tra_languages';
                    if (!$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table_name)))) {
                        (new YUZTRA_Logger())->log('critical', "Languages table $table_name missing after recreation attempt");
                        return [];
                    }

                    // Tolérance aux payloads historiques (source)
                    $language_code = sanitize_text_field(
                        $data['language_code']
                            ?? $data['new_source_code']
                            ?? $data['new_source']
                            ?? ''
                    );
                    if ($language_code === '') {
                        wp_send_json_error(['message' => 'missing language_code', 'code' => 'bad_request'], 400);
                    }

                    $wpdb->update($table_name, ['is_source' => 0], ['is_source' => 1], ['%d'], ['%d']);
                    $res = $wpdb->update($table_name, ['is_source' => 1, 'updated_at' => current_time('mysql')], ['language_code' => $language_code], ['%d','%s'], ['%s']);
                    if ($res === false) {
                        throw new \Exception( esc_html( "Failed to set source language {$language_code}: " . $wpdb->last_error ) );                    }

                    $options = get_option('yuztra_general', []);
                    $options['yuztra_source_language'] = $language_code;
                    update_option('yuztra_general', $options, false);

                    $this->language_manager->enforce_language_rules($language_code);

                    $this->invalidate_language_caches([$language_code]);
                    $payload = $this->build_ws_payload();
                    $payload['message'] = __('Source language set', 'yuz-tra');
                    return $payload;
                },
                true
            );
        }

        public function yuztra_ws_upd_translatable() {
            self::__handleRequest('yuztra_con_nonce',
                ['language_code', 'is_translatable'],
                function ($data) {
                    if (!current_user_can('yuztra_translate_content')) {
                        wp_send_json_error(['message'=>'unauthorized','code'=>'unauthorized'],403);
                    }
                    global $wpdb;
                    $this->ensure_db_tables();

                    $table_name = $wpdb->prefix . 'yuz_tra_languages';
                    if (!$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table_name)))) {
                        (new YUZTRA_Logger())->log('critical', "Languages table $table_name missing after recreation attempt");
                        return [];
                    }

                    $language_code   = sanitize_text_field($data['language_code']);
                    $is_translatable = intval($data['is_translatable']);

                    $max_weight = (int) $wpdb->get_var($wpdb->prepare('SELECT MAX(language_weight) FROM %i WHERE is_translatable = 1', $table_name));
                    $new_weight = $is_translatable ? ($max_weight  +1) : 0;

                    $res = $wpdb->update(
                        $table_name,
                        ['is_translatable' => $is_translatable, 'language_weight' => $new_weight, 'updated_at' => current_time('mysql')],
                        ['language_code' => $language_code],
                        ['%d','%d','%s'],
                        ['%s']
                    );
                    if ($res === false) {
                        throw new \Exception( esc_html( "Failed to update translatable status for {$language_code}: " . $wpdb->last_error ) );                    }

                    $fresh = $wpdb->get_col($wpdb->prepare('SELECT language_code FROM %i WHERE is_translatable = 1 ORDER BY language_weight ASC', $table_name));
                    if (!is_array($fresh)) {
                        $fresh = [];
                    }
                    $fresh = array_values(array_filter(array_map('strval', $fresh), 'strlen'));

                    $options = get_option('yuztra_general', []);
                    if (!is_array($options)) {
                        $options = [];
                    }
                    $options['yuztra_translatable_languages'] = $fresh;
                    // Legacy mirrors stay aligned to avoid stale UIs.
                    $options['yuztra_translatable_languages'] = $fresh;
                    unset($options['yuztra_translatable_languages[]'], $options['yuztra_translatable_languages[]']);
                    update_option('yuztra_general', $options, false);

                    $this->language_manager->enforce_language_rules(null, null, $language_code);

                    $this->invalidate_language_caches([$language_code]);
                    $payload = $this->build_ws_payload();
                    $payload['message'] = __('Translatable status updated', 'yuz-tra');
                    return $payload;
                },
                true
            );
        }

        /** -------------------- LANGUAGE SETTINGS -------------------- */
        public function yuztra_ls_get_settings() {
            $settings_provider = $this->get_settings();

            self::__handleRequest('yuztra_nonce',
                [],
                function ($data) use ($settings_provider) {
                    if (!current_user_can('yuztra_translate_content')) {
                        wp_send_json_error(['message'=>'unauthorized','code'=>'unauthorized'],403);
                    }
                    $settings = (array) $settings_provider->get_option('yuztra_ls_settings');

                    return [
                        'native_language_name' => !empty($settings['native_language_name'] ?? false),
                        'use_subdirectory'     => !empty($settings['use_subdirectory'] ?? false),
                        'force_lang_in_links'  => !empty($settings['force_lang_in_links'] ?? false),
                    ];
                },
                true
            );
        }

        public function yuztra_ls_upd_settings() {
            $logger = $this->logger;

            self::__handleRequest('yuztra_con_nonce',
                ['language_settings'],
                function ($data) use ($logger) {
                    if (!current_user_can('manage_options')) {
                        wp_send_json_error(['message' => 'unauthorized', 'code' => 'unauthorized'], 403);
                    }

                    $payload = isset($data['language_settings']) ? (array) $data['language_settings'] : [];

                    $all      = function_exists('yuztra_settings_get_all') ? yuztra_settings_get_all() : [];
                    $defaults = function_exists('yuztra_settings_section_default') ? yuztra_settings_section_default('yuztra_ls_settings') : [];
                    $existing = is_array($all) && isset($all['yuztra_ls_settings']) && is_array($all['yuztra_ls_settings'])
                        ? $all['yuztra_ls_settings']
                        : (is_array($defaults) ? $defaults : []);

                    if ($logger) {
                        $logger->log('info', '[AJAX][LS_UPD] before=' . wp_json_encode($existing));
                    }

                    $clean = function_exists('yuztra_settings_sanitize_section')
                        ? yuztra_settings_sanitize_section('yuztra_ls_settings', $payload)
                        : (is_array($payload) ? $payload : []);

                    $was_subdir = function_exists('yuztra_settings_truthy')
                        ? yuztra_settings_truthy($existing['use_subdirectory'] ?? '')
                        : !empty($existing['use_subdirectory']);
                    $mutated = $clean !== $existing;

                    $ok = function_exists('yuztra_settings_update_all')
                        ? yuztra_settings_update_all(['yuztra_ls_settings' => $clean])
                        : false;

                    $after_all = function_exists('yuztra_settings_get_all') ? yuztra_settings_get_all() : [];
                    $saved     = is_array($after_all) && isset($after_all['yuztra_ls_settings']) && is_array($after_all['yuztra_ls_settings'])
                        ? $after_all['yuztra_ls_settings']
                        : $clean;

                    if ($logger) {
                        $logger->log('info', '[AJAX][LS_UPD] after=' . wp_json_encode($saved));
                    }

                    $is_subdir = function_exists('yuztra_settings_truthy')
                        ? yuztra_settings_truthy($saved['use_subdirectory'] ?? '')
                        : !empty($saved['use_subdirectory']);

                    if ($is_subdir !== $was_subdir) {
                        if (function_exists('flush_rewrite_rules')) {
                            flush_rewrite_rules();
                        }
                        if (class_exists('YUZTRA_Logger')) {
                            (new YUZTRA_Logger())->log('info', 'Flushed rewrite rules due to use_subdirectory change');
                        }
                    }

                    $expected_sorted = $clean;
                    $saved_sorted    = $saved;
                    if (is_array($expected_sorted)) {
                        ksort($expected_sorted);
                    }
                    if (is_array($saved_sorted)) {
                        ksort($saved_sorted);
                    }

                    if ($saved_sorted === $expected_sorted) {
                        return [
                            'status'   => $mutated ? 'updated' : 'no_change',
                            'message'  => __('Language settings updated', 'yuz-tra'),
                            'settings' => $saved,
                            'ok'       => (bool) $ok,
                        ];
                    }

                    return [
                        'error'    => true,
                        'code'     => 'persist_mismatch',
                        'message'  => 'Option not persisted as expected.',
                        'expected' => $clean,
                        'got'      => $saved,
                        'ok'       => (bool) $ok,
                    ];
                },
                true
            );
        }

        /** -------------------- LANGUAGE SWITCHER -------------------- */
        public function yuztra_sw_get_settings() {
            self::__handleRequest('yuztra_nonce',
                [],
                function ($data) {
                    if (!current_user_can('yuztra_translate_content')) {
                        wp_send_json_error(['message'=>'unauthorized','code'=>'unauthorized'],403);
                    }
                    // Lire via le provider central (yuz_tra_all_settings) pour garder l'UI et le stockage alignés
                    $opts = $this->get_settings()->get_option('yuztra_sw_settings');
                    if (!is_array($opts)) { $opts = []; }

                    return [
                        'shortcode_enabled'  => !empty($opts['shortcode_enabled']),
                        'shortcode_format'   => $opts['shortcode_format']  ?? 'flags-full-names',
                        'menu_enabled'       => !empty($opts['menu_enabled']),
                        'menu_format'        => $opts['menu_format']       ?? 'flags-full-names',
                        'floating_enabled'   => !empty($opts['floating_enabled']),
                        'floating_format'    => $opts['floating_format']   ?? 'flags-full-names',
                        'floating_theme'     => $opts['floating_theme']    ?? 'light',
                        'floating_position'  => $opts['floating_position'] ?? 'bottom-right',
                        'show_poweredby'     => !empty($opts['show_poweredby']),
                    ];
                },
                true
            );
        }

        public function yuztra_sw_upd_settings() {
            $logger = $this->logger;

            self::__handleRequest('yuztra_con_nonce',
                ['switcher_settings'],
                function ($data) use ($logger) {
                    if ( ! current_user_can('manage_options') ) {
                        wp_send_json_error(['message'=>'unauthorized','code'=>'unauthorized'],403);
                    }

                    $payload = isset($data['switcher_settings']) ? (array) $data['switcher_settings'] : [];

                    $all      = function_exists('yuztra_settings_get_all') ? yuztra_settings_get_all() : [];
                    $defaults = function_exists('yuztra_settings_section_default') ? yuztra_settings_section_default('yuztra_sw_settings') : [];
                    $existing = is_array($all) && isset($all['yuztra_sw_settings']) && is_array($all['yuztra_sw_settings'])
                        ? $all['yuztra_sw_settings']
                        : (is_array($defaults) ? $defaults : []);

                    if ($logger) {
                        $logger->log('info', '[AJAX][SW_UPD] before=' . wp_json_encode($existing));
                    }

                    $clean = function_exists('yuztra_settings_sanitize_section')
                        ? yuztra_settings_sanitize_section('yuztra_sw_settings', $payload)
                        : (is_array($payload) ? $payload : []);

                    $mutated = $clean !== $existing;

                    $ok = function_exists('yuztra_settings_update_all')
                        ? yuztra_settings_update_all(['yuztra_sw_settings' => $clean])
                        : false;

                    $after_all = function_exists('yuztra_settings_get_all') ? yuztra_settings_get_all() : [];
                    $saved     = is_array($after_all) && isset($after_all['yuztra_sw_settings']) && is_array($after_all['yuztra_sw_settings'])
                        ? $after_all['yuztra_sw_settings']
                        : $clean;

                    if ($logger) {
                        $logger->log('info', '[AJAX][SW_UPD] after=' . wp_json_encode($saved));
                    }

                    $expected_sorted = $clean;
                    $saved_sorted    = $saved;
                    if (is_array($expected_sorted)) {
                        ksort($expected_sorted);
                    }
                    if (is_array($saved_sorted)) {
                        ksort($saved_sorted);
                    }

                    if ($saved_sorted === $expected_sorted) {
                        return [
                            'status'   => $mutated ? 'updated' : 'no_change',
                            'message'  => __('Language switcher settings updated', 'yuz-tra'),
                            'settings' => $saved,
                            'ok'       => (bool) $ok,
                        ];
                    }

                    return [
                        'error'    => true,
                        'code'     => 'persist_mismatch',
                        'message'  => 'Option not persisted as expected.',
                        'expected' => $clean,
                        'got'      => $saved,
                        'ok'       => (bool) $ok,
                    ];
                },
                true
            );
        }

        public function yuztra_sw_switch_language() {
            if (!current_user_can('manage_options') && !current_user_can('yuztra_translate_content')) {
                wp_send_json_error(['message'=>'forbidden','code'=>'forbidden'],403);
            }
            self::__handleRequest('yuztra_nonce',
                ['language_code'],
                function ($data) {
                    if (!current_user_can('manage_options') && !current_user_can('yuztra_translate_content')) {
                        wp_send_json_error(['message'=>'forbidden','code'=>'forbidden'],403);
                    }
                    global $wpdb;
                    $this->ensure_db_tables();

                    $lang_table = $wpdb->prefix . 'yuz_tra_languages';
                    if (!$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($lang_table)))) {
                        (new YUZTRA_Logger())->log('critical', "Languages table $lang_table missing after recreation attempt");
                        return [];
                    }

                    $language_code = $this->normalize_language_code(sanitize_text_field($data['language_code']));

                    $exists = (int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE language_code = %s AND is_translatable = 1', $lang_table, $language_code));
                    if (!$exists) {
                        (new YUZTRA_Logger())->log('error', "Invalid or non-translatable language code: {$language_code}");
                        throw new \Exception( esc_html( "Invalid or non-translatable language code: {$language_code}" ) );                    }

                    // Pass settings to the converter to avoid "not enough languages" and ensure correct URL rules
                    $settings = null;
                    if (class_exists('YUZTRA_Services') && method_exists('YUZTRA_Services', 'settings')) {
                        $settings = YUZTRA_Services::settings();
                    } elseif (class_exists('YUZTRA_Settings')) {
                        $settings = new YUZTRA_Settings(
                            new \YUZTRA\Fallbacks\NullLanguages(),
                            new \YUZTRA\Fallbacks\NullAjax(),
                            new \YUZTRA\Fallbacks\NullTranslationManager(),
                            new \YUZTRA\Fallbacks\NullLanguageManager(),
                            new \YUZTRA\Fallbacks\NullLogger()
                        );
                    }
                    $url_converter = new YUZTRA_Url_Converter($settings);
                    $current_url   = $url_converter->cur_page_url();
                    $new_url       = $url_converter->get_url_for_language($language_code, $current_url);

                    return [
                        'message' => __('Language switched successfully', 'yuz-tra'),
                        // IMPORTANT: key expected by front-end fallback is "url"
                        'url'     => esc_url($new_url)
                    ];
                }

            );
        }

        public function yuztra_sw_resolve_url() {
            self::__handleRequest('yuztra_nonce',
                ['language_code'],
                function ($data) {
                    $language_code = $this->normalize_language_code(sanitize_text_field($data['language_code'] ?? ''));
                    if ($language_code === '') {
                        throw new \Exception( esc_html( 'Missing language_code' ) );                    }

                    $current_url = isset($data['current_url'])
                        ? esc_url_raw($data['current_url'])
                        : '';

                    $settings = null;
                    if (class_exists('YUZTRA_Services') && method_exists('YUZTRA_Services', 'settings')) {
                        $settings = YUZTRA_Services::settings();
                    } elseif (class_exists('YUZTRA_Settings')) {
                        $settings = new YUZTRA_Settings(
                            new \YUZTRA\Fallbacks\NullLanguages(),
                            new \YUZTRA\Fallbacks\NullAjax(),
                            new \YUZTRA\Fallbacks\NullTranslationManager(),
                            new \YUZTRA\Fallbacks\NullLanguageManager(),
                            new \YUZTRA\Fallbacks\NullLogger()
                        );
                    }
                    $url_converter = new YUZTRA_Url_Converter($settings);

                    if ($current_url === '') {
                        $current_url = $url_converter->cur_page_url();
                    }

                    $resolved = $url_converter->get_url_for_language($language_code, $current_url);

                    return [
                        'url' => esc_url($resolved),
                    ];
                }
            );
        }

        /** -------------------- TRANSLATE SITE SETTINGS -------------------- */
        public function yuztra_ts_get_settings() {
            if (!current_user_can('manage_options') && !current_user_can('yuztra_translate_content')) {
                wp_send_json_error(['message'=>'forbidden','code'=>'forbidden'],403);
            }
            self::__handleRequest('yuztra_nonce',
                [],
                function ($data) {
                    if (!current_user_can('manage_options') && !current_user_can('yuztra_translate_content')) {
                        wp_send_json_error(['message'=>'forbidden','code'=>'forbidden'],403);
                    }
                    $settings = $this->get_settings()->get_option('yuztra_ts_settings');
                    if (!is_array($settings)) {
                        $settings = [];
                    }
                    if (function_exists('yuztra_settings_sanitize_section')) {
                        $settings = yuztra_settings_sanitize_section('yuztra_ts_settings', $settings);
                    }
                    if (empty($settings)) {
                        throw new \Exception( esc_html( 'Failed to retrieve site settings' ) );                    }
                    return $settings;
                }
            );
        }

        public function yuztra_ts_upd_settings() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message'=>'unauthorized','code'=>'unauthorized'],403);
    }
    self::__handleRequest('yuztra_con_nonce',
        // Accept both legacy and new payload keys to avoid persistence issues
        ['translate_site_settings','site_settings'],
        function ($data) {
            if ($this->logger) {
                $this->logger->log('info', '[AJAX][TS_UPD] incoming payload', [
                    'has_nonce'      => isset($_REQUEST['nonce']),
                    'can_translate'  => current_user_can('yuztra_translate_content'),
                    'can_manage'     => current_user_can('manage_options'),
                    'raw_keys'       => array_keys((array) ($data['translate_site_settings'] ?? $data['site_settings'] ?? [])),
                ]);
            }
            if (!current_user_can('manage_options')) {
                wp_send_json_error(['message'=>'unauthorized','code'=>'unauthorized'],403);
            }
            // Support both names: JS may send site_settings; older code used translate_site_settings
            $raw = (array) ($data['translate_site_settings'] ?? $data['site_settings'] ?? []);
            if (empty($raw)) {
                throw new \Exception( esc_html( 'Invalid site settings data' ) );            }
            $clean  = $this->get_settings()->sanitize_option('yuztra_ts_settings', $raw);
            $result = $this->get_settings()->update_option('yuztra_ts_settings', $clean);

            /* PATCH 4.4 — Sauvegarde des rôles autorisés  sync caps */
            if ( isset($clean['allowed_roles']) ) {
                $allowed = array_map('sanitize_key', (array) $clean['allowed_roles']);
                // nettoie les entrées vides et dédoublonne
                $allowed = array_values(array_unique(array_filter($allowed)));
                if ( empty($allowed) ) { $allowed = ['administrator']; }
                update_option('yuztra_allowed_roles', $allowed);
                if ( function_exists('yuztra_sync_caps_from_option') ) {
                    yuztra_sync_caps_from_option();
                }
            }

            if ($result === false && get_option('yuztra_ts_settings') !== $clean) {
                throw new \Exception( esc_html( 'Failed to update site settings' ) );            }

            delete_option('yuztra_site_settings');

            if ($this->logger) {
                $this->logger->log('info', '[AJAX][TS_UPD] success', ['settings' => $clean]);
            }

            return ['message' => __('Translate site settings updated', 'yuz-tra'), 'settings' => $clean];
        }
    );
}


       public function yuztra_ts_cre_fulltra() {
    self::__handleRequest('yuztra_hvy_nonce',
        [],
        function ($data) {
            if (!current_user_can('yuztra_translate_content')) {
                wp_send_json_error(['message'=>'unauthorized','code'=>'unauthorized'],403);
            }
            // run_full_site_translation() ne retourne rien → pas de test
            $this->translation_manager->run_full_site_translation();

            return [
                'message'   => __('Site translation triggered', 'yuz-tra'),
                'timestamp' => current_time('mysql'),
            ];
        },
        true
    );
}

public function yuztra_ts_start_translation() {
    self::__handleRequest('yuztra_hvy_nonce',
        [],
        function ($data) {
            if (!current_user_can('yuztra_translate_content')) {
                wp_send_json_error(['message'=>'unauthorized','code'=>'unauthorized'],403);
            }
            $this->translation_manager->run_full_site_translation();

            return [
                'message'   => __('Translation started successfully', 'yuz-tra'),
                'timestamp' => current_time('mysql'),
            ];
        },
        true
    );
}

        /** -------------------- TRANSLATION EDITOR -------------------- */
        public function yuztra_te_cre_tstart() {
            if (!current_user_can('manage_options') && !current_user_can('yuztra_translate_content')) wp_send_json_error(['message' => 'forbidden'], 403);
            self::__handleRequest('yuztra_nonce',
                ['page_url'],
                function ($data) {
                    $page_url   = esc_url_raw($data['page_url']);
                    $editor_url = admin_url('admin.php?page=yuz-translation-editor&url=' . urlencode($page_url));
                    return ['editor_url' => $editor_url];
                }
            );
        }


        private function normalize_newlines(string $s): string {
            // Force UTF-8 et normalise CRLF / CR → LF
            $s = mb_convert_encoding($s, 'UTF-8', 'UTF-8');
            $s = preg_replace("/\r\n?/", "\n", $s);   // ⚠️ bien \r\n? et pas \n?
            // Évite les \n répétés accidentels (garde les doubles pour paragraphes)
            $s = preg_replace("/\n{3,}/", "\n\n", $s);
            return $s;
        }

        private function normalize_language_code(string $code): string {
            $code = sanitize_text_field($code);
            if (function_exists('yuztra_normalize_language_code')) {
                return yuztra_normalize_language_code($code);
            }
            if ($code === '' || $code === 'auto') {
                return '';
            }
            $code = str_replace('-', '_', $code);
            $segments = array_filter(explode('_', $code), static function ($segment) {
                return $segment !== '';
            });
            if (empty($segments)) {
                return '';
            }
            $normalized = [];
            foreach (array_values($segments) as $index => $segment) {
                $normalized[] = $index === 0 ? strtolower($segment) : strtoupper($segment);
            }
            return implode('_', $normalized);
        }


        public function yuztra_te_cre_translation() {
            if (!current_user_can('manage_options') && !current_user_can('yuztra_translate_content')) wp_send_json_error(['message' => 'forbidden'], 403);
            // Trace diagnostic d'entrée
            try {
                $logger = $this->logger ?: (class_exists('YUZTRA_Logger') ? new \YUZTRA_Logger() : new \YUZTRA\Fallbacks\NullLogger());
                $trace  = isset($_REQUEST['yuztra_trace']) ? sanitize_text_field(wp_unslash((string) $_REQUEST['yuztra_trace'])) : '';
                $logger->log('info', '[TE][IN] create', [
                    'trace' => $trace,
                    'keys'  => array_map('sanitize_key', array_keys((array) $_REQUEST)),
                ]);
            } catch (\Throwable $e) {}
            if (!isset($_REQUEST['target_langs']) && isset($_REQUEST['target_lang'])) {
                $incoming = sanitize_text_field(wp_unslash((string) $_REQUEST['target_lang']));
                if (!is_array($incoming)) {
                    $incoming = [$incoming];
                }
                $normalized = [];
                foreach ($incoming as $lang) {
                    if (is_array($lang)) {
                        continue;
                    }
                    $normalized_value = $this->normalize_language_code((string) $lang);
                    if ($normalized_value !== '') {
                        $normalized[] = $normalized_value;
                    }
                }
                $_REQUEST['target_langs'] = $normalized;
            }
            if (!isset($_REQUEST['text']) && isset($_REQUEST['original_text'])) {
                $original = wp_kses_post(wp_unslash((string) $_REQUEST['original_text']));
                if (is_array($original)) {
                    $original = '';
                }
                $_REQUEST['text'] = wp_slash(wp_kses_post($original));
            }

            self::__handleRequest('yuztra_int_nonce',
                ['target_langs', 'text', 'page_url'],
                function ($data) {
                    global $wpdb;
                    $this->ensure_db_tables();

                    $logger = $this->logger ?: (class_exists('YUZTRA_Logger') ? new \YUZTRA_Logger() : new \YUZTRA\Fallbacks\NullLogger());

                    $lang_table  = $wpdb->prefix . 'yuz_tra_languages';
                    $trans_table = $wpdb->prefix . 'yuz_tra_translations';
                    if (!$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($lang_table))) || !$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($trans_table)))) {
                        $logger->log('critical', "Languages/Translations table missing after recreation attempt");
                        return [];
                    }

                    $requested_source_id = isset($data['source_lang_id']) ? (int) $data['source_lang_id'] : 0;
                    $source_lang_id      = $requested_source_id > 0 ? $requested_source_id : 0;

                    if (!$source_lang_id) {
                        $source_code = isset($data['source_lang']) ? $this->normalize_language_code((string) wp_unslash($data['source_lang'])) : '';
                        if ($source_code) {
                            $candidate = $wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE language_code = %s', $lang_table, $source_code));
                            if ($candidate) {
                                $source_lang_id = (int) $candidate;
                            }
                        }
                    }

                    if (!$source_lang_id) {
                        $fallback = $wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE is_source = 1', $lang_table));
                        if ($fallback) {
                            $source_lang_id = (int) $fallback;
                        }
                    }

                    if (!$source_lang_id) {
                        // journalise pour aider au diagnostic côté front
                        $logger->log('error', '[TE] missing source_lang_id', [
                                'trace' => isset($data['yuztra_trace']) ? (string)$data['yuztra_trace'] : '',
                                'src'   => isset($data['source_lang']) ? (string)$data['source_lang'] : '',
                            ]);
                        throw new \Exception( esc_html( 'Failed to retrieve source language ID: ' . $wpdb->last_error ) );                    }

                    $raw_target_langs = isset($data['target_langs']) ? $data['target_langs'] : [];
                    if (!is_array($raw_target_langs)) {
                        $raw_target_langs = [$raw_target_langs];
                    }

                    $target_langs = [];
                    foreach ($raw_target_langs as $lang) {
                        if (is_array($lang)) {
                            continue;
                        }
                        $normalized = $this->normalize_language_code((string) wp_unslash($lang));
                        if ($normalized === '') {
                            continue;
                        }
                        $target_langs[] = $normalized;
                    }
                    $target_langs = array_values(array_unique($target_langs));

                    if (empty($target_langs)) {
                        $logger->log('warning', '[TE] empty target_langs', [
                                'trace' => isset($data['yuztra_trace']) ? (string)$data['yuztra_trace'] : '',
                                'raw'   => $data['target_langs'] ?? null,
                            ]);
                        throw new \Exception( esc_html( 'Invalid target languages data' ) );                    }

                    $text = $data['text'] ?? '';
                    if (is_array($text)) {
                        $text = '';
                    }
                    $text = $this->normalize_newlines(wp_kses_post(wp_unslash($text)));
                    if ($text === '') {
                        $logger->log('warning', '[TE] empty text for creation', [
                                'trace' => isset($data['yuztra_trace']) ? (string)$data['yuztra_trace'] : '',
                            ]);
                    }

                    $page_url = isset($data['page_url']) ? esc_url_raw(wp_unslash($data['page_url'])) : '';
                    $context  = isset($data['context']) ? sanitize_text_field((string) wp_unslash($data['context'])) : 'content';
                    if ($context === '') {
                        $context = 'content';
                    }
                    $block_id = isset($data['block_id']) ? sanitize_text_field((string) wp_unslash($data['block_id'])) : '';
                    $post_id  = isset($data['post_id']) ? (int) $data['post_id'] : 0;
                    if ($post_id <= 0 && $page_url !== '') {
                        $post_id = (int) url_to_postid($page_url);
                    }

                    $origin = isset($data['origin']) ? sanitize_key((string) wp_unslash($data['origin'])) : 'manual';
                    if ($origin === 'auto') {
                        $origin = 'machine';
                    }
                    $allowed_origins = ['manual','machine','dock','gettext','dom'];
                    if (!in_array($origin, $allowed_origins, true)) {
                        $origin = 'manual';
                    }
                    $is_batch = $this->read_bool_flag($data, ['is_batch','isBatch','batch']);
                    $mode = isset($data['mode']) ? sanitize_key((string) wp_unslash($data['mode'])) : '';
                    if ($mode === 'semi') {
                        $mode = 'semi_auto';
                    }

                    if (function_exists('yuztra_status_sanitize')) {
                        if ($mode === 'manual') {
                            $default_status = YUZTRA_STATUS_DRAFT;
                        } elseif ($mode === 'semi_auto') {
                            $default_status = YUZTRA_STATUS_IN_REVIEW;
                        } elseif ($mode === 'auto') {
                            $default_status = YUZTRA_STATUS_PUBLISHED;
                        } else {
                            $needs_review = in_array($origin, ['machine', 'dom'], true) || $is_batch;
                            $default_status = $needs_review ? YUZTRA_STATUS_IN_REVIEW : YUZTRA_STATUS_PUBLISHED;
                        }
                        $status = yuztra_status_sanitize($data['status'] ?? null, $default_status);
                    } else {
                        if ($mode === 'manual') {
                            $default_status = 1;
                        } elseif ($mode === 'semi_auto') {
                            $default_status = 2;
                        } elseif ($mode === 'auto') {
                            $default_status = 4;
                        } else {
                            $default_status = ($origin === 'machine' || $origin === 'dom' || $is_batch) ? 2 : 1;
                        }
                        $status = isset($data['status']) ? (int) $data['status'] : $default_status;
                        if ($status < 1) {
                            $status = 1;
                        } elseif ($status > 5) {
                            $status = 5;
                        }
                    }
                    if (function_exists('yuztra_status_for_origin')) {
                        $status = yuztra_status_for_origin($origin, $status, $is_batch, $mode);
                    } elseif ($mode === 'auto') {
                        $status = defined('YUZTRA_STATUS_PUBLISHED') ? YUZTRA_STATUS_PUBLISHED : 4;
                    } elseif ($origin === 'machine' || $origin === 'dom' || $is_batch || $mode === 'semi_auto') {
                        $status = defined('YUZTRA_STATUS_REVIEW') ? YUZTRA_STATUS_REVIEW : 2;
                    }

                    $has_page_url = method_exists($this, 'translation_table_has_column')
                        ? $this->translation_table_has_column($trans_table, 'page_url')
                        : false;
                    $has_block_id = method_exists($this, 'translation_table_has_column')
                        ? $this->translation_table_has_column($trans_table, 'block_id')
                        : true;

                    $created_ids  = [];
                    $translations = [];
                    $touched_langs = [];

                    $html_translator = null;
                    if ($context === 'content' && method_exists('YUZTRA_HTML_Translator', '__construct')) {
                        $html_translator = new YUZTRA_HTML_Translator($this->translation_manager);
                    }

                    foreach ($target_langs as $code) {
                        $target_lang_id = $wpdb->get_var(
                            $wpdb->prepare('SELECT id FROM %i WHERE language_code = %s', $lang_table, $code)
                        );
                        if (!$target_lang_id) {
                            $logger->log('warning', "Invalid target language code: {$code}");
                            continue;
                        }

                        $whereParts = [];
                        $params     = [];

                        if ($post_id > 0) {
                            $whereParts[] = 'post_id = %d';
                            $params[]     = $post_id;
                        }
                        if ($has_page_url && $page_url !== '') {
                            $whereParts[] = 'page_url = %s';
                            $params[]     = $page_url;
                        }
                        $whereParts[] = 'context = %s';
                        $params[]     = $context;
                        $whereParts[] = 'block_id = %s';
                        $params[]     = $block_id;
                        $whereParts[] = 'source_lang_id = %d';
                        $params[]     = $source_lang_id;
                        $whereParts[] = 'target_lang_id = %d';
                        $params[]     = (int) $target_lang_id;
                        $whereParts[] = 'original_text = %s';
                        $params[]     = $text;

                        $existing_row = null;
                        if ($whereParts) {
                            $sql = 'SELECT id, translated_text FROM %i WHERE ' . implode(' AND ', $whereParts) . ' LIMIT 1';
                            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- WHERE fragments are fixed templates; values are prepared.
                            $existing_row = $wpdb->get_row($wpdb->prepare($sql, $trans_table, ...$params), ARRAY_A);
                        }

                        if ((!is_array($existing_row) || empty($existing_row['id']))) {
                            $keyWhere = [];
                            $keyParams = [];

                            $keyWhere[]  = 'post_id = %d';
                            $keyParams[] = $post_id;

                            $keyWhere[]  = 'context = %s';
                            $keyParams[] = $context;

                            if ($has_block_id) {
                                $keyWhere[]  = 'block_id = %s';
                                $keyParams[] = $block_id;
                            }

                            $keyWhere[]  = 'source_lang_id = %d';
                            $keyParams[] = $source_lang_id;

                            $keyWhere[]  = 'target_lang_id = %d';
                            $keyParams[] = (int) $target_lang_id;

                            $sqlFallback = 'SELECT id, translated_text FROM %i WHERE ' . implode(' AND ', $keyWhere) . ' LIMIT 1';
                            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- WHERE fragments are fixed templates; values are prepared.
                            $existing_row = $wpdb->get_row($wpdb->prepare($sqlFallback, $trans_table, ...$keyParams), ARRAY_A);
                            if (is_array($existing_row) && !empty($existing_row['id'])) {
                                $logger->log('info', '[TE] reuse existing translation (composite match)', [
                                    'id'         => (int) $existing_row['id'],
                                    'target'     => $code,
                                    'context'    => $context,
                                    'post_id'    => $post_id,
                                    'block_id'   => $block_id,
                                    'page_url_in'=> $page_url,
                                ]);
                            }
                        }

                        if (is_array($existing_row) && !empty($existing_row['id'])) {
                            $id = (int) $existing_row['id'];
                            $created_ids[$code]  = $id;
                            $translations[$code] = $existing_row['translated_text'] ?? '';
                            continue;
                        }

                        $translated_text = '';
                        try {
                            if ($html_translator && ($text !== '')) {
                                $translated_html = $html_translator->translate_html($text, $source_lang_id, (int) $target_lang_id);
                                if (is_string($translated_html) && $translated_html !== '') {
                                    $translated_text = $this->normalize_newlines($translated_html);
                                }
                            } elseif (method_exists($this->translation_manager, 'translate')) {
                                $candidate = $this->translation_manager->translate($text, $source_lang_id, (int) $target_lang_id);
                                if ($candidate !== null && $candidate !== '') {
                                    $translated_text = $this->normalize_newlines((string) $candidate);
                                }
                            }
                        } catch (\Throwable $e) {
                            $logger->log('warning', "Translation provider failed for {$code}: " . $e->getMessage());
                            $translated_text = '';
                        }

                        $now = current_time('mysql');
                        $insert_data = [
                            'post_id'         => $post_id,
                            'context'         => $context,
                            'block_id'        => $block_id,
                            'original_text'   => $text,
                            'translated_text' => $translated_text,
                            'source_lang_id'  => $source_lang_id,
                            'target_lang_id'  => (int) $target_lang_id,
                            'language_code'   => $code,
                            'status'          => $status,
                            'origin'          => $origin,
                            'created_at'      => $now,
                            'updated_at'      => $now,
                        ];
                        $insert_format = ['%d','%s','%s','%s','%s','%d','%d','%s','%d','%s','%s','%s'];

                        if ($has_page_url) {
                            $insert_data['page_url'] = $page_url;
                            $insert_format[] = '%s';
                        }

                        $result = $wpdb->insert($trans_table, $insert_data, $insert_format);
                        if ($result === false) {
                            $error_message = (string) $wpdb->last_error;
                            if (stripos($error_message, 'duplicate') !== false) {
                                $duplicate = $wpdb->get_row(
                                    $wpdb->prepare(
                                        'SELECT id, translated_text FROM %i WHERE post_id = %d AND context = %s AND block_id = %s AND source_lang_id = %d AND target_lang_id = %d LIMIT 1',
                                        $trans_table,
                                        $post_id,
                                        $context,
                                        $block_id,
                                        $source_lang_id,
                                        (int) $target_lang_id
                                    ),
                                    ARRAY_A
                                );
                                if ($duplicate) {
                                    $created_ids[$code]  = (int) $duplicate['id'];
                                    $translations[$code] = $duplicate['translated_text'] ?? '';
                                    $logger->log('info', '[TE] duplicate insert avoided (existing row reused)', [
                                        'id'       => (int) $duplicate['id'],
                                        'target'   => $code,
                                        'context'  => $context,
                                        'post_id'  => $post_id,
                                        'block_id' => $block_id,
                                    ]);
                                    continue;
                                }
                            }

                            $logger->log('critical', "Failed to insert translation for {$code}: " . $error_message);
                            continue;
                        }

                        $insert_id = (int) $wpdb->insert_id;
                        $created_ids[$code]  = $insert_id;
                        $translations[$code] = $translated_text;
                        $touched_langs[]     = $code;
                        $logger->log('success', "Translation inserted for {$code}");
                    }

                    if ($touched_langs) {
                        $this->invalidate_language_caches($touched_langs);
                    }

                    if (count($created_ids) === 1) {
                        $id = array_values($created_ids)[0];
                        return [
                            'id'            => $id,
                            'translations'  => $translations,
                        ];
                    }

                    return [
                        'ids'          => $created_ids,
                        'translations' => $translations,
                    ];
                }
            );
        }

        public function yuztra_te_upd_manual() {
            // This legacy endpoint saves a published translation, not only a draft.
            if (!current_user_can('manage_options') && !current_user_can('yuztra_publish_translations')) wp_send_json_error(['message' => 'forbidden'], 403);
            self::__handleRequest('yuztra_int_nonce',
                ['translation_id', 'translated_text', 'target_lang'],
                function ($data) {
                    global $wpdb;
                    $this->ensure_db_tables();

                    $table_name = $wpdb->prefix . 'yuz_tra_translations';
                    $lang_table = $wpdb->prefix . 'yuz_tra_languages';
                    if (!$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table_name))) || !$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($lang_table)))) {
                        (new YUZTRA_Logger())->log('critical', "Tables missing after recreation attempt");
                        return [];
                    }

                    $published_status = function_exists('yuztra_status_transition')
                        ? yuztra_status_transition('publish')
                        : 1;

                    $translation_id = intval($data['translation_id']);
                    $translated_text= wp_kses_post($data['translated_text']);
                    // YUZ: NORMALIZE NEWLINES (saisie manuelle)
                    $translated_text = $this->normalize_newlines($translated_text);
                    $context        = sanitize_text_field($data['context'] ?? '');
                    $target_lang    = sanitize_text_field($data['target_lang']);

                    $target_lang_id = $wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE language_code = %s', $lang_table, $target_lang));
                    if (!$target_lang_id) {
                        (new YUZTRA_Logger())->log('error', '[TE][MANUAL] invalid target lang', ['target_lang' => $target_lang, 'translation_id' => $translation_id]);
                        throw new \Exception( esc_html( "Invalid target language code: {$target_lang}" ) );                    }

                    $exists = (int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE id = %d', $table_name, $translation_id));

                    $result = $exists
                        ? $wpdb->update($table_name, ['translated_text' => $translated_text, 'context' => $context, 'status' => $published_status, 'origin' => 'manual', 'updated_at' => current_time('mysql')], ['id' => $translation_id], ['%s', '%s', '%d', '%s', '%s'], ['%d']) // YUZ: normalized
                        : $wpdb->insert($table_name, [
                            'post_id'         => 0,
                            'expression'      => sanitize_text_field($data['expression'] ?? ''),
                            'translated_text' => $translated_text,
                            'context'         => $context,
                            'target_lang_id'  => $target_lang_id,
                            'status'          => $published_status,
                            'origin'          => 'manual',
                            'created_at'      => current_time('mysql'),
                            'updated_at'      => current_time('mysql'),
                        ], ['%d', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s']);

                    if ($result === false) {
                        (new YUZTRA_Logger())->log('error', '[TE][MANUAL] update failed', [
                            'id'    => $translation_id,
                            'lang'  => $target_lang,
                            'error' => $wpdb->last_error,
                        ]);
                        throw new \Exception( esc_html( "Failed to update/insert translation ID {$translation_id}: " . $wpdb->last_error ) );                    }

                    try {
                        (new YUZTRA_Logger())->log('info', '[TE][MANUAL] saved', [
                            'id'      => $translation_id,
                            'lang'    => $target_lang,
                            'exists'  => $exists,
                            'user_id' => get_current_user_id(),
                        ]);
                    } catch (\Throwable $e) {}

                    return ['message' => __('Manual translation updated', 'yuz-tra')];
                }
            );
        }

        public function yuztra_te_upd_publish() {
            if (!current_user_can('manage_options') && !current_user_can('yuztra_publish_translations')) wp_send_json_error(['message' => 'forbidden'], 403);

            self::__handleRequest('yuztra_int_nonce',
                ['page_url', 'target_lang'],
                function ($data) {
                    global $wpdb;
                    $this->ensure_db_tables();

                    $trans_table = $wpdb->prefix . 'yuz_tra_translations';
                    $lang_table  = $wpdb->prefix . 'yuz_tra_languages';
                    if (!$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($trans_table))) || !$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($lang_table)))) {
                        (new YUZTRA_Logger())->log('critical', "Tables missing after recreation attempt");
                        return [];
                    }

                    $page_url    = esc_url_raw($data['page_url']);
                    $target_lang = sanitize_text_field($data['target_lang']);

                    $target_lang_id = (int) $wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE language_code = %s', $lang_table, $target_lang));
                    if (!$target_lang_id) {
                        throw new \Exception( esc_html( "Invalid target language code: {$target_lang}" ) );                    }

                    $post_id = url_to_postid($page_url);

                    if (!$post_id) {
                        $parsed = wp_parse_url($page_url);
                        if (!empty($parsed['query'])) {
                            parse_str($parsed['query'], $query_vars);
                            if (!empty($query_vars['page_id'])) {
                                $post_id = (int) $query_vars['page_id'];
                            } elseif (!empty($query_vars['pagename'])) {
                                $page = get_page_by_path(sanitize_title($query_vars['pagename']));
                                if ($page instanceof \WP_Post) {
                                    $post_id = (int) $page->ID;
                                }
                            }
                        }

                        if (!$post_id && !empty($parsed['path'])) {
                            $post_id = url_to_postid(home_url($parsed['path']));
                        }
                    }

                    if (!$post_id) {
                        $front_id = absint(get_option('page_on_front'));
                        if ($front_id) {
                            $post_id = $front_id;
                        }
                    }

                    if (!$post_id) {
                        (new YUZTRA_Logger())->log('warning', 'Unable to resolve post ID for publication', [
                            'page_url'    => $page_url,
                            'target_lang' => $target_lang,
                        ]);
                        throw new \Exception( esc_html( __('Unable to resolve page for translation publication.', 'yuz-tra') ) );                    }

                    $publish_status = function_exists('yuztra_status_transition')
                        ? yuztra_status_transition('publish')
                        : 2;

                    $origin = isset($data['origin']) ? sanitize_key((string) $data['origin']) : 'manual';
                    if ($origin === 'auto') {
                        $origin = 'machine';
                    }
                    $allowed_origin_values = ['manual','machine','dock','gettext','dom'];
                    if (!in_array($origin, $allowed_origin_values, true)) {
                        $origin = 'manual';
                    }
                    $entries = [];
                    if (!empty($data['entries'])) {
                        $raw_entries = is_array($data['entries'])
                            ? $data['entries']
                            : json_decode(wp_unslash((string) $data['entries']), true);
                        if (!is_array($raw_entries)) {
                            throw new \InvalidArgumentException('Invalid entries payload');
                        }
                        foreach ($raw_entries as $entry) {
                            if (!is_array($entry)) {
                                continue;
                            }
                            $entry_id = isset($entry['id']) ? (int) $entry['id'] : (int) ($entry['string_id'] ?? 0);
                            if ($entry_id <= 0) {
                                continue;
                            }
                            $entries[] = [
                                'id' => $entry_id,
                                'translated_text' => isset($entry['translated_text']) ? wp_kses_post($entry['translated_text']) : '',
                            ];
                        }
                    }

                    if ($entries) {
                        $updated_ids = [];
                        $matched_entries = 0;
                        $now = current_time('mysql');
                        foreach ($entries as $entry) {
                            $row = $wpdb->get_row(
                                $wpdb->prepare(
                                    'SELECT id, origin, post_id, target_lang_id FROM %i WHERE id = %d',
                                    $trans_table,
                                    $entry['id']
                                ),
                                ARRAY_A
                            );
                            if (!$row) {
                                continue;
                            }
                            if ((int) ($row['target_lang_id'] ?? 0) !== $target_lang_id) {
                                continue;
                            }
                            $row_post_id = (int) ($row['post_id'] ?? 0);
                            if ($row_post_id > 0 && $row_post_id !== $post_id) {
                                continue;
                            }
                            $matched_entries++;
                            $result = $wpdb->update(
                                $trans_table,
                                [
                                    'translated_text' => $entry['translated_text'],
                                    'status'          => $publish_status,
                                    'origin'          => 'manual',
                                    'updated_at'      => $now,
                                ],
                                ['id' => (int) $row['id']],
                                ['%s', '%d', '%s', '%s'],
                                ['%d']
                            );
                            if ($result === false) {
                                throw new \Exception( esc_html( 'Failed to publish translation #' . (int) $row['id'] . ': ' . $wpdb->last_error ) );                            }
                            if ($result > 0) {
                                $updated_ids[] = (int) $row['id'];
                            }
                        }

                        if ($matched_entries === 0) {
                            throw new \InvalidArgumentException('no_matching_translation_to_publish');
                        }
                        return [
                            'message' => __('Translation published', 'yuz-tra'),
                            'updated' => count($updated_ids),
                            'ids'     => $updated_ids,
                        ];
                    }

                    $origin_clause = implode(',', array_fill(0, 3, '%s'));
                    $review_statuses = array_filter([
                        defined('YUZTRA_STATUS_MACHINE') ? (int) YUZTRA_STATUS_MACHINE : 1,
                        defined('YUZTRA_STATUS_REVIEW') ? (int) YUZTRA_STATUS_REVIEW : 2,
                        defined('YUZTRA_STATUS_QUEUED') ? (int) YUZTRA_STATUS_QUEUED : 3,
                    ], static function ($value) {
                        return is_int($value) && $value >= 0;
                    });
                    if (!$review_statuses) {
                        $review_statuses = [1, 2, 3];
                    }
                    $review_clause = implode(',', array_map('intval', $review_statuses));
                    $origin_params = ['manual', 'dock', 'gettext'];
                    $select_sql = "SELECT id FROM %i WHERE post_id = %d AND target_lang_id = %d AND status IN ({$review_clause})";
                    $select_sql .= " AND (origin IN ({$origin_clause}) OR origin IS NULL OR origin = '')";
                    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Status and origin placeholder clauses are generated from fixed internal arrays.
                    $ids_to_publish = $wpdb->get_col($wpdb->prepare($select_sql, array_merge([$trans_table, $post_id, $target_lang_id], $origin_params)));
                    if ($ids_to_publish === null) {
                        throw new \Exception( esc_html( "Failed to fetch translations for {$target_lang}: " . $wpdb->last_error ) );                    }

                    $updated_rows = 0;
                    $timestamp    = current_time('mysql');
                    foreach (array_chunk($ids_to_publish, 200) as $chunk_ids) {
                        if (!$chunk_ids) {
                            continue;
                        }
                        $placeholders = implode(',', array_fill(0, count($chunk_ids), '%d'));
                        $sql = "UPDATE {$trans_table} SET status = %d, updated_at = %s WHERE id IN ({$placeholders})";
                        $params = array_merge([$publish_status, $timestamp], array_map('intval', $chunk_ids));
                        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Generated placeholder list is integer-only.
                        $prepared = $wpdb->prepare($sql, $params);
                        if ($prepared === false) {
                            throw new \Exception( esc_html( 'Failed to prepare publish batch statement.' ) );                        }
                        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Prepared above; id list placeholders are generated from integers.
                        $result = $wpdb->query($prepared);
                        if ($result === false) {
                            throw new \Exception( esc_html( 'Failed to publish translations: ' . $wpdb->last_error ) );                        }
                        $updated_rows += (int) $result;
                    }

                    if ($updated_rows === 0) {
                        (new YUZTRA_Logger())->log('info', 'No translations updated during publish', [
                            'post_id'   => $post_id,
                            'lang_id'   => $target_lang_id,
                            'page_url'  => $page_url,
                        ]);
                    } else {
                        (new YUZTRA_Logger())->log('success', 'Translations published', [
                            'post_id'   => $post_id,
                            'lang_id'   => $target_lang_id,
                            'rows'      => $updated_rows,
                        ]);
                    }

                    return [
                        'message' => __('Translation published', 'yuz-tra'),
                        'updated' => (int) $updated_rows,
                    ];
                }
            );
        }

        public function yuztra_get_pending_translations() {
            self::__handleRequest(
                'yuztra_int_nonce',
                ['ids'],
                function ($data) {
                    if (!is_user_logged_in()) {
                        wp_send_json_error(['message' => 'not_logged_in', 'code' => 'not_logged_in'], 403);
                    }
                    if (!current_user_can('manage_options') && !current_user_can('yuztra_publish_translations')) {
                        wp_send_json_error(['message' => 'forbidden'], 403);
                    }

                    global $wpdb;
                    $this->ensure_db_tables();
                    $trans_table = $wpdb->prefix . 'yuz_tra_translations';
                    if (!$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($trans_table)))) {
                        throw new \Exception( esc_html( 'Translations table missing' ) );                    }

                    $ids = $this->normalize_ajax_ids($data['ids'] ?? []);
                    if (!$ids) {
                        throw new \InvalidArgumentException('No translation IDs provided');
                    }

                    $rows = [];
                    foreach ($ids as $id) {
                        $row = $wpdb->get_row(
                            $wpdb->prepare(
                                'SELECT id, post_id, context, original_text, translated_text, status FROM %i WHERE id = %d',
                                $trans_table,
                                $id
                            ),
                            ARRAY_A
                        );
                        if (is_array($row)) {
                            $rows[] = $row;
                        }
                    }
                    if ($rows === null) {
                        throw new \Exception( esc_html( 'Failed to fetch translations: ' . $wpdb->last_error ) );                    }

                    $catalog = function_exists('yuztra_status_catalog') ? yuztra_status_catalog() : [];
                    $filtered = [];
                    foreach ($rows as $row) {
                        $row_id = (int) ($row['id'] ?? 0);
                        if ($row_id <= 0) {
                            continue;
                        }
                        $status = (int) ($row['status'] ?? 0);
                        if (function_exists('yuztra_status_requires_review') && !yuztra_status_requires_review($status)) {
                            continue;
                        }
                        $filtered[$row_id] = [
                            'id'              => $row_id,
                            'post_id'         => (int) ($row['post_id'] ?? 0),
                            'context'         => $row['context'] ?? '',
                            'original_text'   => $row['original_text'] ?? '',
                            'translated_text' => $row['translated_text'] ?? '',
                            'status'          => $status,
                            'status_label'    => $catalog[$status]['label'] ?? (string) $status,
                        ];
                    }

                    $ordered = [];
                    foreach ($ids as $id) {
                        if (isset($filtered[$id])) {
                            $ordered[] = $filtered[$id];
                        }
                    }

                    return $ordered;
                }
            );
        }

        public function yuztra_publish_translations() {
            if (!is_user_logged_in()) {
                wp_send_json_error(['message' => 'not_logged_in', 'code' => 'not_logged_in'], 403);
            }
            if (!current_user_can('manage_options') && !current_user_can('yuztra_publish_translations')) {
                wp_send_json_error(['message' => 'forbidden', 'code' => 'forbidden'], 403);
            }
            self::__handleRequest(
                'yuztra_con_nonce',
                ['ids'],
                function ($data) {
                    global $wpdb;
                    $this->ensure_db_tables();
                    $trans_table = $wpdb->prefix . 'yuz_tra_translations';
                    if (!$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($trans_table)))) {
                        throw new \Exception( esc_html( 'Translations table missing' ) );                    }

                    $ids = $this->normalize_ajax_ids($data['ids'] ?? []);
                    if (!$ids) {
                        throw new \InvalidArgumentException('No translation IDs provided');
                    }

                    $publish_status = function_exists('yuztra_status_transition')
                        ? yuztra_status_transition('publish')
                        : 2;
                    $allowed_statuses = [];
                    foreach (['YUZTRA_STATUS_MACHINE', 'YUZTRA_STATUS_REVIEW', 'YUZTRA_STATUS_QUEUED'] as $const) {
                        if (defined($const)) {
                            $allowed_statuses[] = (int) constant($const);
                        }
                    }
                    $allowed_clause = $allowed_statuses
                        ? implode(',', array_unique(array_map('intval', $allowed_statuses)))
                        : '';

                    $placeholders = implode(',', array_fill(0, count($ids), '%d'));
                    $params = array_merge([$publish_status, current_time('mysql')], $ids);
                    $sql = "UPDATE {$trans_table} SET status = %d, updated_at = %s WHERE id IN ({$placeholders})";
                    if ($allowed_clause !== '') {
                        $sql .= " AND status IN ({$allowed_clause})";
                    }

                    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Prepared with generated integer placeholders and fixed plugin table.
                    $result = $wpdb->query($wpdb->prepare($sql, $params));
                    if ($result === false) {
                        throw new \Exception( esc_html( 'Failed to publish translations: ' . $wpdb->last_error ) );                    }

                    try {
                        ($this->logger ?: new \YUZTRA\Fallbacks\NullLogger())->log('info', '[TE][PUBLISH] status bump', [
                            'ids'     => $ids,
                            'updated' => (int) $result,
                        ]);
                    } catch (\Throwable $ignored) {}

                    return [
                        'updated' => (int) $result,
                        'status'  => $publish_status,
                        'ids'     => $ids,
                    ];
                }
            );
        }

        /** -------------------- AUTOMATIC TRANSLATION -------------------- */
        public function yuztra_at_get_api_settings() {
            if (!current_user_can('manage_options')) {
                wp_send_json_error(['message'=>'forbidden','code'=>'forbidden'],403);
            }
            self::__handleRequest('yuztra_nonce',
                [],
                function ($data) {
                    if (!current_user_can('manage_options')) {
                        wp_send_json_error(['message'=>'forbidden','code'=>'forbidden'],403);
                    }
                    $settings = $this->get_settings()->get_option('yuztra_at_settings');
                    if (empty($settings)) {
                        $settings = $this->get_settings()->get_option('yuztra_api_settings');
                    }
                    if (empty($settings)) {
                        throw new \Exception( esc_html( 'Failed to retrieve API settings' ) );                    }
                    $strip_secrets = static function ($value) use (&$strip_secrets) {
                        if (!is_array($value)) return $value;
                        $safe=[];
                        foreach ($value as $key=>$item) {
                            if (preg_match('/(?:api[_-]?key|secret|token|password|authorization)/i',(string)$key)) continue;
                            $safe[$key]=$strip_secrets($item);
                        }
                        return $safe;
                    };
                    $safe=$strip_secrets($settings);
                    $safe['api_key_configured']=!empty($settings['api_key']);
                    return $safe;
                }
            );
        }

        public function yuztra_at_upd_api_settings() {
            self::__handleRequest('yuztra_con_nonce',
                ['automatic_translation_settings'],
                function ($data) {
                    if (!current_user_can('manage_options')) {
                        wp_send_json_error(['message'=>'unauthorized','code'=>'unauthorized'],403);
                    }
                    $settings  = wp_unslash($data['automatic_translation_settings']);
                    if (is_string($settings)) $settings=json_decode($settings,true);
                    if (!is_array($settings)) throw new InvalidArgumentException('invalid_settings');
                    $sanitized = $this->get_settings()->sanitize_option('yuztra_at_settings', $settings);
                    $result    = $this->get_settings()->update_option('yuztra_at_settings', $sanitized);

                    if ($result === false && get_option('yuztra_at_settings') !== $sanitized) {
                        throw new \Exception( esc_html( 'Failed to update API settings' ) );                    }

                    $this->translation_manager->set_api_settings($sanitized);
                    YUZTRA_Cron::reconcile_schedule();

                    $test_connection = !empty($data['test_connection']);
                    if ($test_connection) {
                        $test_settings = $sanitized;
                        $start_time     = microtime(true);
                        $test_result    = $this->translation_manager->test_api_conn($test_settings);
                        $end_time       = microtime(true);
                        $execution_time = round(($end_time - $start_time) * 1000, 2);
                    } else {
                        $test_result    = null;
                        $execution_time = 0;
                    }

                    return [
                        'message'            => __('Automatic Translation settings updated', 'yuz-tra'),
                        'settings'           => $sanitized,
                        'test_result'        => $test_result,
                        'execution_time_ms'  => $execution_time
                    ];
                },
                true
            );
        }

        // class-yuz-ajax.php

        public function yuztra_at_del_api_settings() {
            if (!current_user_can('manage_options')) {
                wp_send_json_error(['message'=>'unauthorized','code'=>'unauthorized'],403);
            }
            self::__handleRequest('yuztra_con_nonce',
                [],
                function ($data) {
                    if (!current_user_can('manage_options')) {
                        wp_send_json_error(['message'=>'unauthorized','code'=>'unauthorized'],403);
                    }
                    delete_option('yuztra_api_settings');
                    return ['message' => __('Automatic translate settings reset', 'yuz-tra')];
                },
                true
            );
        }

        public function yuztra_at_get_api_test() {  // --> en erreur ici
    if (!current_user_can('manage_options')) wp_send_json_error(['message'=>'forbidden'],403);
    // Vérif stricte via check_ajax_referer
    check_ajax_referer('yuztra_api_nonce', 'nonce');

    self::__handleRequest('yuztra_api_nonce',
        ['provider'], // endpoint/api_key validés conditionnellement ci-dessous
        function ($data) {
            $provider = sanitize_text_field($data['provider'] ?? '');
            $endpoint = esc_url_raw($data['endpoint'] ?? ($data['url'] ?? ''));
            $api_key  = sanitize_text_field($data['api_key'] ?? ($data['apiKey'] ?? ''));
            $extra_raw = $data['extra_settings'] ?? ($data['extraSettings'] ?? []);

            if (is_string($extra_raw)) {
                $decoded = json_decode(wp_unslash($extra_raw), true);
                $extra_in = is_array($decoded) ? $decoded : [];
            } else {
                $extra_in = (array) wp_unslash($extra_raw);
            }

            $extra = [];
            foreach ($extra_in as $key => $value) {
                if (!is_scalar($value)) {
                    continue;
                }
                $extra[sanitize_key((string) $key)] = sanitize_text_field((string) $value);
            }

            if (!current_user_can('manage_options')) wp_send_json_error(['message'=>'forbidden'],403);
            $valid_providers = ['libretranslate', 'deepl', 'google', 'custom', 'ollama'];
            if (!in_array($provider, $valid_providers, true)) {
                throw new \InvalidArgumentException('Invalid provider');
            }
            if (in_array($provider, ['deepl', 'google', 'custom'], true) && empty($api_key)) {
                throw new \InvalidArgumentException('Missing API key');
            }
            if (in_array($provider, ['libretranslate', 'custom'], true) && empty($endpoint)) {
                throw new \InvalidArgumentException('Missing endpoint');
            }

            $start_time     = microtime(true);
            $test_settings  = array_merge([
                'provider'       => $provider,
                'endpoint'       => $endpoint,
                'api_key'        => $api_key,
            ], $extra);
            if (!empty($extra)) {
                $test_settings['extra_settings'] = $extra;
            }

            $result         = $this->translation_manager->test_api_conn($test_settings);
            $end_time       = microtime(true);
            $execution_time = round(($end_time - $start_time) * 1000, 2);
            if (empty($result['success'])) wp_send_json_error(['message'=>$result['message'] ?? 'provider_connection_failed','execution_time_ms'=>$execution_time],503);

            return [
                'success'           => !empty($result['success']),
                'message'           => esc_html($result['message'] ?? ''),
                'translatedText'    => esc_html($result['translatedText'] ?? ''),
                'execution_time_ms' => $execution_time,
                'timestamp'         => current_time('mysql'),
            ];
        },
        true
    );
}


        /** Legacy create routes share the real provider and checked persistence. */
        public function yuztra_at_cre_tsilent() {
            self::__handleRequest('yuztra_int_nonce',['text'],function($data) {
                if (!current_user_can('manage_options') && !current_user_can('yuztra_translate_content')) self::send_json_error(['message'=>'forbidden'],403);
                global $wpdb;
                $targets=$wpdb->get_col($wpdb->prepare('SELECT language_code FROM %i WHERE is_translatable=1', $wpdb->prefix . 'yuz_tra_languages'));
                return $this->translate_and_store_legacy($data,$targets ?: []);
            });
        }
        public function yuztra_tm_cre_translation() {
            self::__handleRequest('yuztra_int_nonce',['text','target_langs'],function($data) {
                if (!current_user_can('manage_options') && !current_user_can('yuztra_translate_content')) self::send_json_error(['message'=>'forbidden'],403);
                $targets=self::validated_target_languages(wp_unslash($data['target_langs']));
                return $this->translate_and_store_legacy($data,$targets);
            });
        }
        private function translate_and_store_legacy(array $data,array $targets): array {
            global $wpdb;
            $targets=self::validated_target_languages($targets);
            $this->ensure_db_tables();
            $text=wp_kses_post(wp_unslash((string)$data['text']));
            if ($text==='' || strlen($text)>20000 || !$targets || count($targets)>10) throw new InvalidArgumentException('invalid_legacy_translation_request');
                $source=(int)$wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE is_source=1 LIMIT 1', $wpdb->prefix . 'yuz_tra_languages'));
            if (!$source) throw new InvalidArgumentException('invalid_source_language');
            $resolved=[];
            foreach ($targets as $code) {
                if (!is_string($code)) throw new InvalidArgumentException('invalid_target_language');
                $id=(int)$wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE language_code=%s', $wpdb->prefix . 'yuz_tra_languages', $code));
                if (!$id) throw new InvalidArgumentException('invalid_target_language');
                $resolved[$code]=$id;
            }
            $translations=[]; $errors=[]; $deadline=microtime(true)+80;
            foreach ($resolved as $code=>$target) {
                try {
                    if (microtime(true)>=$deadline) throw new RuntimeException('translation_time_budget');
                    $translated=strpos($text,'<')!==false
                        ? (new YUZTRA_HTML_Translator($this->translation_manager))->translate_html($text,$source,$target)
                        : $this->translation_manager->translate($text,$source,$target);
                    if (!is_string($translated) || trim($translated)==='') throw new RuntimeException('empty_provider_translation');
                    if (!$this->db->store_translation(['original_text'=>$text,'translated_text'=>$translated,
                        'source_lang_id'=>$source,'target_lang_id'=>$target,'language_code'=>$code,
                        'context'=>sanitize_text_field($data['context'] ?? 'content'),'post_id'=>(int)($data['post_id'] ?? 0),
                        'status'=>2,'origin'=>'machine'])) throw new RuntimeException('translation_persistence_failed');
                    $translations[$code]=$translated;
                } catch (Throwable $e) { $errors[$code]=$e->getMessage(); }
            }
            $result=['translations'=>$translations,'errors'=>$errors,'partial'=>!empty($errors),'stored'=>count($translations)];
            if (!$translations && $errors) self::send_json_error(array_merge($result,['message'=>'translation_failed']),503);
            return $result;
        }

        public function yuztra_tm_cre_page() {
            if (!current_user_can('manage_options') && !current_user_can('yuztra_translate_content')) {
                wp_send_json_error(['message'=>'forbidden','code'=>'forbidden'],403);
            }
            self::__handleRequest('yuztra_int_nonce',
                ['page_id', 'target_lang'],
                function ($data) {
                    if (!current_user_can('manage_options') && !current_user_can('yuztra_translate_content')) {
                        wp_send_json_error(['message'=>'forbidden','code'=>'forbidden'],403);
                    }
                    global $wpdb;
                    $this->ensure_db_tables();

                    $lang_table  = $wpdb->prefix . 'yuz_tra_languages';
                    $trans_table = $wpdb->prefix . 'yuz_tra_translations';
                    if (!$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($lang_table))) || !$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($trans_table)))) {
                        (new YUZTRA_Logger())->log('critical', "Tables missing after recreation attempt");
                        return [];
                    }

                    $page_id = intval($data['page_id']);
                    $post    = get_post($page_id);
                    if (!$post) {
                        throw new \Exception( esc_html( "Invalid post ID: {$page_id}" ) );                    }

                    $source_lang    = get_option('yuztra_general')['yuztra_source_language'] ?? 'en_US';
                    $source_lang_id = $wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE language_code = %s', $lang_table, $source_lang));
                    if (!$source_lang_id) {
                        throw new \Exception( esc_html( "Invalid source language: {$source_lang}" ) );                    }

                    $target_lang    = sanitize_text_field($data['target_lang']);
                    $target_lang_id = $wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE language_code = %s', $lang_table, $target_lang));
                    if (!$target_lang_id) {
                        throw new \Exception( esc_html( "Invalid target language: {$target_lang}" ) );                    }

                    $review_status = defined('YUZTRA_STATUS_REVIEW') ? YUZTRA_STATUS_REVIEW : 1;


                    // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Existing WordPress core content rendering hook, not a plugin-defined hook.
                    $content    = apply_filters('the_content', $post->post_content);
                    // YUZ: NORMALIZE NEWLINES (contenu WP)
                    $content    = $this->normalize_newlines($content);
                    $html_translator = new YUZTRA_HTML_Translator($this->translation_manager);
                    $translated = $html_translator->translate_html($content, $source_lang_id, $target_lang_id);
                    if (!is_string($translated) || $translated === '') {
                        throw new \Exception( esc_html( "Translation failed for page ID: {$page_id} to {$target_lang}" ) );                    }

                    // YUZ: NORMALIZE NEWLINES (résultat provider)
                    $translated = $this->normalize_newlines($translated);

                    // Draft machine output belongs in the status-aware store, not a public post-meta shadow.
                    if (!$this->db->store_translation([
                        'post_id'=>$page_id, 'context'=>'content', 'original_text'=>$content,
                        'translated_text'=>$translated, 'source_lang_id'=>(int)$source_lang_id,
                        'target_lang_id'=>(int)$target_lang_id, 'language_code'=>$target_lang,
                        'status'=>2, 'origin'=>'machine',
                    ])) throw new RuntimeException('translation_persistence_failed');

                    return ['translated_content' => $translated];
                }
            );
        }

        public function yuztra_tm_get_translations() {
        if (!current_user_can('manage_options') && !current_user_can('yuztra_translate_content')) {
            wp_send_json_error(['message'=>'forbidden','code'=>'forbidden'],403);
        }
        self::__handleRequest('yuztra_nonce',
            ['limit', 'offset'],
            function ($data) {
                if (!current_user_can('manage_options') && !current_user_can('yuztra_translate_content')) {
                    wp_send_json_error(['message'=>'forbidden','code'=>'forbidden'],403);
                }
                global $wpdb;
                $this->ensure_db_tables();

                $trans_table = $wpdb->prefix . 'yuz_tra_translations';
                $lang_table  = $wpdb->prefix . 'yuz_tra_languages';
                if (!$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($trans_table))) || !$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($lang_table)))) {
                    (new YUZTRA_Logger())->log('critical', "Tables missing after recreation attempt");
                    return [];
                }

                // Inputs (tolerate both page_url and url)
                $page_url      = esc_url_raw($data['page_url'] ?? ($data['url'] ?? ''));
                $target_lang   = $this->normalize_language_code(sanitize_text_field($data['target_lang'] ?? ''));
                $requested_source = $this->normalize_language_code(sanitize_text_field($data['source_lang'] ?? ''));
                $limit         = max(1, intval($data['limit']));
                $offset        = max(0, intval($data['offset']));
                $wantBootstrap = !empty($data['bootstrap']);

                // Diagnostics
                if (function_exists('yuztra_diag_log')) {
                    yuztra_diag_log('ajax:start', [
                        'action' => current_action(),
                        'src'    => isset($data['src']) ? (string)$data['src'] : '',
                        'dst'    => $target_lang,
                        'url'    => $page_url,
                        'trace'  => sanitize_text_field((string) ($data['yuztra_trace'] ?? '')),
                    ]);
                }

                // Page resolution
                $post_id = 0;
                if (!empty($data['post_id'])) { $post_id = intval($data['post_id']); }
                if (!$post_id && !empty($page_url)) { $post_id = url_to_postid($page_url); }
                if (!$post_id) { $post_id = get_queried_object_id(); }
                if (!$post_id) {
                    $front   = (int) get_option('page_on_front');
                    $posts   = (int) get_option('page_for_posts');
                    $post_id = $front ?: $posts;
                }

                // --------- PATCH: guards + noise filters ----------
                // Colonne présente ? (fallback si la méthode utilitaire n’existe pas)
                $colExists = function(string $table, string $col) use ($wpdb) {
                    if (method_exists($this, 'translation_table_has_column')) {
                        return (bool) $this->translation_table_has_column($table, $col);
                    }
                    // fallback INFORMATION_SCHEMA
                    return (bool) $wpdb->get_var($wpdb->prepare(
                        "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
                        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s LIMIT 1",
                        $table, $col
                    ));
                };
                $has_page_url = $colExists($trans_table, 'page_url');
                $has_selector = $colExists($trans_table, 'selector_path');
                $has_context  = $colExists($trans_table, 'context');
                // ---------------------------------------------------

                // Base query (historical payload)
                $query  = "SELECT t.*, s.language_code AS source_lang_code, tg.language_code AS target_lang_code
                        FROM $trans_table t
                        JOIN $lang_table s  ON t.source_lang_id = s.id
                        JOIN $lang_table tg ON t.target_lang_id = tg.id
                        WHERE 1=1";
                $params = [];

                if ($requested_source !== '') {
                    $query .= ' AND s.language_code = %s';
                    $params[] = $requested_source;
                }

                // filtre page_url uniquement si la colonne existe
                if ($has_page_url && !empty($page_url)) {
                    $query   .= " AND t.page_url = %s";
                    $params[] = $page_url;
                } elseif ($post_id > 0) {
                    $query .= ' AND t.post_id = %d';
                    $params[] = $post_id;
                }

                // filtre langue cible
                if (!empty($target_lang)) {
                    $variants = array_values(array_unique(array_filter([
                        $target_lang,
                        strlen($target_lang) === 5 ? substr($target_lang, 0, 2) : '',
                    ])));
                    $placeholders = implode(',', array_fill(0, count($variants), '%s'));
                    $query   .= " AND tg.language_code IN ($placeholders)";
                    foreach ($variants as $v) { $params[] = $v; }
                }

                // filtre q si présent
                $search_term = isset($data['q']) ? trim((string) wp_unslash($data['q'])) : '';
                if ($search_term !== '') {
                    $like     = '%' . $wpdb->esc_like($search_term) . '%';
                    $query   .= " AND (t.original_text LIKE %s OR t.translated_text LIKE %s)";
                    $params[] = $like;
                    $params[] = $like;
                }

                // **NOUVEAU** : ignorer le bruit de l’UI "search" via selector_path/context si dispos
                if ($has_selector) {
                    $query   .= " AND (t.selector_path IS NULL
                                OR (t.selector_path NOT LIKE %s
                                    AND t.selector_path NOT LIKE %s
                                    AND t.selector_path NOT LIKE %s))";
                    $params[] = '%wp-block-search%';
                    $params[] = '%[role="search"]%';
                    $params[] = '%search-form%';
                }
                if ($has_context) {
                    $query   .= " AND (t.context IS NULL OR t.context NOT LIKE 'yuz\\_%')";
                }

                // tri + pagination
                $query   .= " ORDER BY t.created_at DESC LIMIT %d OFFSET %d";
                $params[] = $limit;
                $params[] = $offset;

                // IMPORTANT: déplier l’array pour prepare()
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query fragments are fixed templates; values are prepared.
                $sql = $wpdb->prepare($query, ...$params);

                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Prepared above.
                $translations = $wpdb->get_results($sql, ARRAY_A);
                if ($translations === null) {
                    throw new \Exception( esc_html( "Failed to retrieve translations: " . $wpdb->last_error ) );                }

                // Payload de base
                $payload = [ 'translations' => $translations ];
                try {
                    (new YUZTRA_Logger())->log('info', '[TM_GET] result', [
                        'count'       => is_array($translations) ? count($translations) : -1,
                        'target_lang' => $target_lang,
                        'page_url'    => $page_url,
                        'post_id'     => $post_id,
                    ]);
                } catch (\Throwable $e) {}

                // Bootstrap de l’overlay
                if ($wantBootstrap) {
                    // Languages
                    $langs_rows = $wpdb->get_results(
                        $wpdb->prepare("SELECT language_code, language_name
                        FROM %i
                        WHERE is_translatable = 1
                        ORDER BY language_weight ASC, language_name ASC", $lang_table), ARRAY_A
                    );
                    $languages  = is_array($langs_rows) ? array_values(array_filter(array_map(function($r){
                        $code = $this->normalize_language_code((string)$r['language_code']);
                        if ($code === '') {
                            return null;
                        }
                        return [
                            'language_code' => $code,
                            'language_name' => (string)$r['language_name']
                        ];
                    }, $langs_rows))) : [];

                    // Source language
                    $opts          = get_option('yuztra_general');
                    $src_from_opt  = is_array($opts) ? ($opts['yuztra_source_language'] ?? $opts['yuztra_source_language'] ?? '') : '';
                    $src_from_db   = (string) $wpdb->get_var($wpdb->prepare('SELECT language_code FROM %i WHERE is_source = 1 LIMIT 1', $lang_table));
                    if ($src_from_db === '') {
                        $src_from_db = (string) $wpdb->get_var($wpdb->prepare('SELECT language_code FROM %i WHERE is_default = 1 LIMIT 1', $lang_table));
                    }
                    $source_lang   = $this->normalize_language_code($src_from_opt ?: ($src_from_db ?: get_locale()));

                    // Current (target) language
                    $codes = array_map(function($r){ return (string)$r['language_code']; }, $languages);
                    $current_lang = '';
                    if ($target_lang && in_array($target_lang, $codes, true)) {
                        $current_lang = $target_lang;
                    } else {
                        foreach ($codes as $code) { if ($code !== $source_lang) { $current_lang = $code; break; } }
                        if ($current_lang === '' && !empty($codes)) { $current_lang = $codes[0]; }
                    }

                    $payload['languages']        = $languages;
                    $payload['source_language']  = $requested_source ?: $source_lang;
                    $payload['current_language'] = $current_lang;
                    $payload['post_id']          = $post_id;

                    // Extraction des chaînes depuis le post
                    $strings = [];
                    if ($post_id && (!$requested_source || $requested_source === $source_lang)) {

                        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Existing WordPress core content rendering hook, not a plugin-defined hook.
                        $html    = (string) apply_filters('the_content', (string) get_post_field('post_content', $post_id));
                        $strings = $this->yuztra_te_extract_strings_from_html($html);
                    }

                    // **Pare-feu client** : filtrer le bruit Search UI côté bootstrap aussi
                    if (is_array($strings)) {
                        $strings = array_values(array_filter($strings, function($it) {
                            $sel = strtolower((string)($it['selector_path'] ?? $it['selector'] ?? ''));
                            $txt = (string)($it['original'] ?? $it['text'] ?? '');
                            if ($sel !== '') {
                                if (strpos($sel, 'wp-block-search') !== false) return false;
                                if (strpos($sel, 'search-form') !== false)    return false;
                                if (strpos($sel, '[role="search"]') !== false) return false;
                            }
                            if ($txt !== '' && preg_match('/^(search|rechercher|recherche)$/i', trim($txt))) {
                                return false;
                            }
                            return true;
                        }));
                    }

                    // Pagination locale
                    $payload['strings'] = array_slice(is_array($strings) ? $strings : [], $offset, $limit);
                    if ($requested_source && $requested_source !== $source_lang) {
                        // Never label page-source text as another language after a swap.
                        $payload['strings'] = $translations;
                    }

                    if (function_exists('yuztra_diag_log')) {
                        yuztra_diag_log('ajax:done', [
                            'action' => current_action(),
                            'count'  => is_array($strings) ? count($strings) : 0,
                            'trace'  => sanitize_text_field((string) ($data['yuztra_trace'] ?? '')),
                        ]);
                    }
                }

                return $payload;
            },
            true
        );
    }


       public function yuztra_tm_search() {
        // Sécurité
        check_ajax_referer('yuztra_nonce', 'nonce');
        if (!current_user_can('manage_options') && !current_user_can('yuztra_translate_content')) {
            wp_send_json_error(['message'=>'forbidden','code'=>'forbidden'],403);
        }

        // Inputs
        $q        = isset($_POST['q']) ? sanitize_text_field(wp_unslash($_POST['q'])) : '';
        $scope    = isset($_POST['scope']) ? sanitize_text_field(wp_unslash($_POST['scope'])) : 'page'; // 'page'|'instant'
        $target   = isset($_POST['target_lang']) ? sanitize_text_field(wp_unslash($_POST['target_lang'])) : '';
        $source   = isset($_POST['source_lang']) ? $this->normalize_language_code(sanitize_text_field(wp_unslash($_POST['source_lang']))) : '';
        $page_url = isset($_POST['page_url']) ? esc_url_raw(wp_unslash($_POST['page_url'])) : '';
        $limit    = isset($_POST['limit']) ? (int) $_POST['limit'] : 50;
        $index    = isset($_POST['index']) ? (int) $_POST['index'] : 0;

        $limit  = max(1, min(100, $limit));
        $index  = max(0, $index);
        $offset = $index * $limit;

        global $wpdb;
        $table = $wpdb->prefix . 'yuz_tra_translations';

        // --- helpers ------------------------------------------------------------
        static $has_col = [];
        $colExists = function(string $col) use ($wpdb, $table, &$has_col): bool {
            if (!array_key_exists($col, $has_col)) {
                $sql = $wpdb->prepare('SHOW COLUMNS FROM %i LIKE %s', $table, $col);
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Prepared above; table is a fixed plugin table.
                $has_col[$col] = (bool) $wpdb->get_var($sql);
            }
            return $has_col[$col];
        };

        static $has_fulltext = null;
        if ($has_fulltext === null) {
            // Index FULLTEXT "ft_text" recommandé : (original_text, translated_text)
            $has_fulltext = (bool) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s AND INDEX_TYPE = 'FULLTEXT'",
                $table, 'ft_text'
            ));
        }

        $normalize_url = function(string $u): string {
            $u = trim($u);
            if ($u === '') return '';
            $p = wp_parse_url($u);
            if (!$p || empty($p['host'])) return $u;

            $qs = [];
            if (!empty($p['query'])) parse_str($p['query'], $qs);
            // on enlève les bruits
            unset($qs['yuz-edit-translation'], $qs['sniffer'], $qs['_'], $qs['ver']);
            foreach (array_keys($qs) as $k) {
                if (stripos($k, 'utm_') === 0) unset($qs[$k]);
            }
            $scheme = isset($p['scheme']) ? $p['scheme'] : 'https';
            $host   = strtolower($p['host']);
            $path   = isset($p['path']) ? untrailingslashit($p['path']) : '';
            return "{$scheme}://{$host}{$path}";
        };
        $page_url_norm = $normalize_url($page_url);

        // --- SELECT sécurisé (colonne absente => NULL AS <col>)
        $wanted = ['id','string_id','original_text','translated_text','target_lang_id',
                'language_code','page_url','block_id','context','status','updated_at','score'];
        $select = [];
        foreach ($wanted as $c) {
            $select[] = $colExists($c) ? $c : "NULL AS {$c}";
        }
        // alias compat front
        if ($colExists('language_code')) $select[] = "language_code AS target_lang";
        else                             $select[] = "NULL AS target_lang";

        // --- WHERE --------------------------------------------------------------
        $where  = [];
        $params = [];

        if ($q !== '') {
            if ($has_fulltext && $colExists('original_text') && $colExists('translated_text')) {
                // FULLTEXT (boost meilleur tri). On ne met pas % ici.
                $where[]  = "MATCH (original_text, translated_text) AGAINST (%s IN BOOLEAN MODE)";
                $params[] = $q . '*';
            } else {
                $like     = '%' . $wpdb->esc_like($q) . '%';
                $where[]  = '(original_text LIKE %s OR translated_text LIKE %s)';
                $params[] = $like;
                $params[] = $like;
            }
        }

        if ($target !== '' && $colExists('language_code')) {
            $where[]  = 'language_code = %s';
            $params[] = $target;
        }

        if ($source !== '' && $colExists('source_lang_id')) {
            $where[] = "source_lang_id = (SELECT id FROM {$wpdb->prefix}yuz_tra_languages WHERE language_code = %s LIMIT 1)";
            $params[] = $source;
        }

        if ($scope === 'instant') {
            if ($colExists('context')) $where[] = "context = 'instant'";
            // pas de filtre page_url en instant
        } else {
            if ($colExists('context')) $where[] = "context <> 'instant'";
            if ($page_url_norm !== '' && $colExists('page_url')) {
                // égal strict OU préfixe (pour variantes querystring)
                $where[]  = '(page_url = %s OR page_url LIKE %s)';
                $params[] = $page_url_norm;
                $params[] = $page_url_norm . '%';
            }
        }

        if (!$where) $where[] = '1=1';
        $whereSql = implode(' AND ', $where);

        // --- COUNT --------------------------------------------------------------
        $countSql = "SELECT COUNT(*) FROM {$table} WHERE {$whereSql}";
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- WHERE fragments are fixed templates; values are prepared.
        $total    = (int) $wpdb->get_var($wpdb->prepare($countSql, $params));
        if ($wpdb->last_error) {
            wp_send_json_error(['message'=>'query_failed_total','error'=>$wpdb->last_error], 500);
        }

        // --- PAGE ---------------------------------------------------------------
        $orderBy = $colExists('updated_at') ? 'updated_at DESC' : 'id DESC';
        $sql = "SELECT " . implode(',', $select) . " FROM {$table}
                WHERE {$whereSql}
                ORDER BY {$orderBy}
                LIMIT %d OFFSET %d";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- SELECT/WHERE/order fragments are fixed templates; values are prepared.
        $rows = $wpdb->get_results($wpdb->prepare($sql, array_merge($params, [$limit, $offset])), ARRAY_A);
        if ($rows === null && $wpdb->last_error) {
            wp_send_json_error(['message'=>'query_failed_rows','error'=>$wpdb->last_error], 500);
        }

        $has_more = ($offset + $limit) < $total;

        wp_send_json_success([
            'scope'    => $scope,
            'index'    => $index,
            'limit'    => $limit,
            'total'    => $total,
            'has_more' => $has_more,
            'results'  => $rows ?: [],
            'debug'    => [
                'page_url_in'   => $page_url,
                'page_url_norm' => $page_url_norm,
                'fulltext'      => $has_fulltext,
                'applied_scope' => ($scope === 'instant' ? 'instant' : 'page_non_instant'),
            ],
        ]);
    }


        /** Batch translate via provider (LibreTranslate-compatible). */
        public function yuztra_tm_translate() {
             self::__handleRequest(
                'yuztra_hvy_nonce',
                ['payload'], // 'source'/'target' gérés souplement (from/to) dans la méthode
                function ($data) {
                    if (!current_user_can('manage_options') && !current_user_can('yuztra_translate_content')) {
                        self::send_json_error(['message'=>'forbidden'],403);
                    }
                    $batch_started = microtime(true);
                    $req_id = isset($data['req_id']) ? sanitize_text_field($data['req_id']) : '';
                    if ($req_id === '') {
                        $req_id = wp_generate_uuid4();
                    }

                    $source_raw = isset($data['from']) ? $data['from'] : ($data['source'] ?? 'auto');
                    $target_raw = isset($data['to']) ? $data['to'] : ($data['target'] ?? '');

                    $source_raw = sanitize_text_field((string) $source_raw);
                    $target_raw = sanitize_text_field((string) $target_raw);

                    $source_canon = yuztra_canon_lang($source_raw !== '' ? $source_raw : 'auto');
                    $target_canon = yuztra_canon_lang($target_raw);

                    if ($target_canon === '' || $target_canon === 'auto') {
                        self::send_json_error([
                            'message' => 'missing_target',
                            'req_id'  => $req_id,
                        ], 400);
                    }

                    $target_locale = yuztra_resolve_target_locale($target_raw);
                    if ($target_locale === '') {
                        self::send_json_error([
                            'message' => 'invalid_target',
                            'req_id'  => $req_id,
                        ], 200);
                    }

                    $source_locale = $source_raw === '' ? 'auto' : yuztra_norm_locale($source_raw);
                    if ($source_locale === '') {
                        $source_locale = 'auto';
                    }

                    if ($source_locale !== 'auto' && strcasecmp($source_locale, $target_locale) === 0) {
                        self::send_json_error([
                            'message' => 'invalid_target',
                            'req_id'  => $req_id,
                        ], 200);
                    }

                    $persist = !empty($data['persist']) ? 1 : 0;

                    $payload_json = isset($data['payload']) ? wp_unslash((string) $data['payload']) : '[]';
                    $payload      = json_decode($payload_json, true);
                    if (!is_array($payload)) {
                        $payload = [];
                    }
                    if (count($payload)>100 || strlen($payload_json)>200000) throw new InvalidArgumentException('batch_payload_too_large');

                    $items = [];
                    foreach ($payload as $rowIndex => $row) {
                        if (!is_array($row)) {
                            continue;
                        }
                        $index = isset($row['i']) ? (int) $row['i'] : $rowIndex;

                        $text_raw = isset($row['text']) ? $row['text'] : '';
                        if (!is_string($text_raw)) {
                            $text_raw = '';
                        }

                        $text = trim((string) wp_unslash($text_raw));
                        if ($text === '' && function_exists('yuztra_strip_css_js_noise')) {
                            $text = trim((string) yuztra_strip_css_js_noise($text_raw));
                        }
                        if ($text === '') {
                            continue;
                        }

                        $items[] = [
                            'i'        => $index,
                            'text'     => $text,
                            'post_id'  => isset($row['post_id']) ? (int) $row['post_id'] : 0,
                            'context'  => isset($row['context']) ? (string) $row['context'] : '',
                            'block_id' => isset($row['block_id']) ? (string) $row['block_id'] : '',
                        ];
                    }

                    $this->log_ajax_entry('yuztra_tm_translate', [
                        'req_id'        => $req_id,
                        'source'        => $source_canon,
                        'target'        => $target_canon,
                        'persist'       => (bool) $persist,
                        'payload_count' => is_array($payload) ? count($payload) : 0,
                        'items_count'   => count($items),
                    ]);

                    if (empty($items)) {
                        // trace empty payload
                        $this->trace_log('AUTO.BATCH.ERR', [
                            'req'    => $req_id,
                            'from'   => $source_canon,
                            'to'     => $target_canon,
                            'reason' => 'empty_payload'
                        ]);
                        self::send_json_error([
                            'message' => 'empty_payload',
                            'req_id'  => $req_id,
                        ], 200);
                    }

                    // trace batch IN
                    $this->trace_log('AUTO.BATCH.IN', [
                        'req'     => $req_id,
                        'from'    => $source_canon,
                        'to'      => $target_canon,
                        'count'   => count($items),
                        'persist' => (bool) $persist,
                    ]);

                    $target_code = strtoupper(str_replace('-', '_', $target_locale));
                    $source_code = $source_locale === 'auto'
                        ? ''
                        : strtoupper(str_replace('-', '_', $source_locale));

                    if (function_exists('yuztra_diag_log')) {
                        yuztra_diag_log('ajax:start', [
                            'action' => current_action(),
                            'src'    => $source_code ?: $source_canon,
                            'dst'    => $target_code,
                            'url'    => '',
                            'trace'  => sanitize_text_field((string) ($data['yuztra_trace'] ?? '')),
                        ]);
                    }

                    if (defined('WP_DEBUG') && WP_DEBUG) {
                        yuztra_debug_log('[YUZ][AJAX][TM] req=' . $req_id . ' src_raw=' . $source_raw . ' tgt_raw=' . $target_raw . ' src=' . $source_canon . ' tgt=' . $target_canon . ' items=' . count($items));
                    }

                    // Prépare les objets de langue (réutilisés pour fallback et persistance)
                    $srcObj = null;
                    $dstObj = null;
                    if ($source_code !== '' && method_exists($this->language_manager, 'get_by_code')) {
                        $srcObj = $this->language_manager->get_by_code($source_code);
                    }

                    if ((!$srcObj || !isset($srcObj->id)) && $source_locale !== 'auto' && method_exists($this->language_manager, 'get_all_languages')) {
                        $all = $this->language_manager->get_all_languages();
                        foreach ($all as $lang) {
                            $code = isset($lang->language_code) ? (string) $lang->language_code : '';
                            if ($code === '') {
                                continue;
                            }
                            $norm = yuztra_norm_locale($code);
                            if ($norm === $source_locale || yuztra_root($norm) === yuztra_root($source_locale)) {
                                $source_code = strtoupper(str_replace('-', '_', $code));
                                $srcObj = $this->language_manager->get_by_code($source_code);
                                if ($srcObj && isset($srcObj->id)) {
                                    break;
                                }
                            }
                        }
                    }

                    if (method_exists($this->language_manager, 'get_by_code')) {
                        $dstObj = $this->language_manager->get_by_code($target_code);
                    }

                    $source_lang_id = isset($srcObj->id) ? (int) $srcObj->id : 0;
                    $target_lang_id = isset($dstObj->id) ? (int) $dstObj->id : 0;

                    // All editors use the selected provider and the shared budget.
                    $trace = sanitize_text_field($data['yuztra_trace'] ?? '');
                    $adapter = new class($this->translation_manager) {
                        private $manager;
                        public function __construct($manager) { $this->manager = $manager; }
                        public function translate($text, $source, $target, $settings) {
                            return $this->manager->translate_text($text, $source, $target);
                        }
                    };
                    $out = [];
                    $provider_started = microtime(true);
                    $translated_list = [];
                    $used_batch = false;

                    foreach ($items as $k => $row) {
                        $idx  = (int) ($row['i'] ?? -1);
                        $text = (string) ($row['text'] ?? '');
                        if ($idx < 0 || $text === '') { continue; }
                        $last_error = '';
                        $translated = null;
                        if (microtime(true)-$batch_started>80) {
                            $out[$idx]=['error'=>'translation_time_budget','req_id'=>$req_id.':'.$idx];
                            continue;
                        }

                        if ($used_batch && array_key_exists($k, $translated_list)) {
                            $translated = $translated_list[$k];
                        }

                        if (!is_string($translated) || $translated === '') {
                            try {
                                $translated = $adapter->translate($text, $source_locale, $target_locale, []);
                            } catch (\Throwable $e) {
                                $translated = null;
                                $last_error = trim((string) $e->getMessage());
                                $this->log_ajax_exception('yuztra_tm_translate_item', $e, [
                                    'req_id' => $req_id,
                                    'index'  => $idx,
                                    'source' => $source_canon,
                                    'target' => $target_canon,
                                ]);
                            }
                        }

                        // A failed attempt is returned to the caller, never retried implicitly.
                        if (is_string($translated) && $translated !== '') {
                            $out[$idx] = [ 'translated_text' => $translated, 'req_id' => $req_id . ':' . $idx ];
                        } else {
                            $err = $last_error !== '' ? $last_error : 'empty_from_provider';
                            $out[$idx] = [ 'error' => $err, 'req_id' => $req_id . ':' . $idx ];
                        }
                    }
                    $provider_ms = (int) round((microtime(true) - $provider_started) * 1000);

                    $this->log_ajax_entry('yuztra_tm_translate_out', [
                        'req_id'      => $req_id,
                        'results'     => count($out),
                        'provider_ms' => $provider_ms,
                        'source'      => $source_canon,
                        'target'      => $target_canon,
                    ]);

                    $response = [
                        'data' => [
                            'results' => $out,
                            'source'  => $source_canon,
                            'target'  => $target_canon,
                        ],
                        'meta' => [ 'req_id' => $req_id, 'trace' => $trace ],
                    ];

                    if (is_wp_error($response)) {
                        self::send_json_error([
                            'message' => $response->get_error_message(),
                            'code'    => $response->get_error_code(),
                            'req_id'  => $req_id,
                        ], 200);
                    }

                    $data_block = [];
                    if (isset($response['data']) && is_array($response['data'])) {
                        $data_block = $response['data'];
                    }

                    $bag = $data_block['results']
                        ?? $data_block['translations']
                        ?? ($response['results'] ?? ($response['translations'] ?? []));

                    if (!is_array($bag)) {
                        $bag = (array) $bag;
                    }

                    $normalized = [];
                    $item_errors = [];
                    $translated_count = 0;

                    foreach ($items as $item) {
                        $idx = $item['i'];
                        $node = $bag[$idx] ?? ($bag[(string) $idx] ?? null);
                        if (is_object($node)) {
                            $node = (array) $node;
                        }

                        $txt = '';
                        if (is_array($node)) {
                            $txt = $node['translated_text']
                                ?? $node['translation']
                                ?? $node['translated']
                                ?? '';
                        } elseif (is_string($node)) {
                            $txt = $node;
                        }

                        if ($txt !== '') {
                            $txt = mb_convert_encoding($txt, 'UTF-8', 'UTF-8');
                            // YUZ: FIX REGEX (cause du “texte en colonne”)  normalisation
                            $txt = preg_replace("/\r\n?/", "\n", $txt); // ← remplacé l'ancien motif fautif
                            $txt = $this->normalize_newlines($txt);
                            $txt = sanitize_textarea_field(trim($txt));
                            $translated_count++;
                        }

                        if ($txt === '') {
                            $item_errors[] = ['i' => $idx, 'code' => 'translation_failed',
                                'message' => is_array($node) ? ($node['error'] ?? 'empty_from_provider') : 'empty_from_provider'];
                            continue;
                        }
                        $normalized[$idx] = [
                            'i'               => $idx,
                            'translated_text' => $txt,
                        ];
                    }

                    $single_translation = '';
                    if (count($items) === 1) {
                        $key = $items[0]['i'];
                        $single_translation = $normalized[$key]['translated_text'] ?? '';
                    }

                    if ($translated_count === 0) {
                        self::send_json_error([
                            'message' => 'translation_failed', 'req_id' => $req_id,
                            'results' => [], 'errors' => $item_errors,
                            'completed' => 0, 'total' => count($items),
                        ], 503);
                    }

                    // trace persist intent
                    $stored_count = 0;
                    if ($persist) {
                        try {
                            if ((!$srcObj || !isset($srcObj->id)) && $source_locale !== 'auto' && method_exists($this->language_manager, 'get_all_languages')) {
                                $all = $this->language_manager->get_all_languages();
                                foreach ($all as $lang) {
                                    $code = isset($lang->language_code) ? (string) $lang->language_code : '';
                                    if ($code === '') {
                                        continue;
                                    }
                                    $norm = yuztra_norm_locale($code);
                                    if ($norm === $source_locale || yuztra_root($norm) === yuztra_root($source_locale)) {
                                        $source_code = strtoupper(str_replace('-', '_', $code));
                                        $srcObj = $this->language_manager->get_by_code($source_code);
                                        if ($srcObj && isset($srcObj->id)) {
                                            break;
                                        }
                                    }
                                }
                            }

                            if ((!$dstObj || !isset($dstObj->id)) && method_exists($this->language_manager, 'get_by_code')) {
                                $dstObj = $this->language_manager->get_by_code($target_code);
                            }

                            if ($srcObj && isset($srcObj->id) && $dstObj && isset($dstObj->id) && $this->db && method_exists($this->db, 'store_translation')) {
                                foreach ($items as $row) {
                                    $i = (int) $row['i'];
                                    $translated_text = $normalized[$i]['translated_text'] ?? '';
                                    if ($translated_text === '') {
                                        continue;
                                    }

                                    // normalise avant persistance
                                    $orig_norm = $this->normalize_newlines((string)($row['text'] ?? ''));
                                    $tr_norm   = $this->normalize_newlines((string)$translated_text);
                                    $stored = $this->db->store_translation([
                                        'post_id'         => isset($row['post_id']) ? (int) $row['post_id'] : 0,
                                        'context'         => isset($row['context']) ? sanitize_text_field($row['context']) : 'content',
                                        'block_id'        => isset($row['block_id']) ? sanitize_text_field($row['block_id']) : '',
                                        'original_text'   => $orig_norm,
                                        'translated_text' => $tr_norm,
                                        'source_lang_id'  => (int) $srcObj->id,
                                        'target_lang_id'  => (int) $dstObj->id,
                                        'language_code'   => $target_code,
                                        'status'          => 2,
                                        'origin'          => 'machine',
                                    ]);
                                    if ($stored === false || is_wp_error($stored) || !$stored) {
                                        throw new RuntimeException('translation_persistence_failed');
                                    }
                                    $stored_count++;
                                }
                            }
                        } catch (\Throwable $e) {
                            self::send_json_error(['message'=>'translation_persistence_failed',
                                'results'=>$normalized, 'stored'=>$stored_count, 'req_id'=>$req_id], 500);
                        }
                        if ($stored_count !== $translated_count) {
                            self::send_json_error(['message'=>'translation_persistence_incomplete',
                                'results'=>$normalized, 'stored'=>$stored_count, 'req_id'=>$req_id], 500);
                        }
                    }

                    // trace OUT summary
                    $this->trace_log('AUTO.BATCH.OUT', [
                        'req'    => $req_id,
                        'from'   => $source_canon,
                        'to'     => $target_canon,
                        'ms'     => $provider_ms,
                        'ok'     => true,
                        'count'  => $translated_count,
                        'total'  => count($items),
                        'stored' => (int) $stored_count,
                    ]);

                    if (function_exists('yuztra_diag_log')) {
                        yuztra_diag_log('ajax:done', [
                            'action' => current_action(),
                            'count'  => count($normalized),
                            'trace'  => sanitize_text_field((string) ($data['yuztra_trace'] ?? '')),
                        ]);
                    }

                    if (defined('WP_DEBUG') && WP_DEBUG && $single_translation === '') {
                        yuztra_debug_log('[YUZ][AJAX][TM] req=' . $req_id . ' single_empty response_count=' . count($normalized));
                    }

                    return [
                        'results'         => $normalized,
                        'errors'          => $item_errors,
                        'partial'         => !empty($item_errors),
                        'completed'       => $translated_count,
                        'stored'          => $stored_count,
                        'total'           => count($items),
                        'translated_text' => $single_translation,
                        'req_id'          => $req_id,
                        'source'          => $source_canon,
                        'target'          => $target_canon,
                    ];
                },
                true
            );
        }
/** Single translation endpoint used by instant mode. */
        public function yuztra_translate() {
            self::__handleRequest(
                'yuztra_hvy_nonce',
                ['q','from','to'],
                function ($data) {
                    $trace_req_id = isset($data['req_id']) ? sanitize_text_field($data['req_id']) : '';
                    $nonce_raw = isset($_REQUEST['_ajax_nonce']) ? sanitize_text_field(wp_unslash((string) $_REQUEST['_ajax_nonce'])) : '';
                    $action_raw = isset($_REQUEST['action']) ? sanitize_key(wp_unslash((string) $_REQUEST['action'])) : '';
                    // Never log nonce values.
                    if (!current_user_can('yuztra_translate_content')) {
                        if (!headers_sent()) {
                            wp_send_json_error(['message' => 'forbidden', 'code' => 'forbidden'], 403);
                        }
                        return ['ok' => false, 'error' => 'forbidden'];
                    }

                    $req_id = isset($data['req_id']) ? sanitize_text_field($data['req_id']) : wp_generate_uuid4();
                    $started = microtime(true);
                    $textRaw = isset($data['q']) ? (string) $data['q'] : '';
                    $text = trim(wp_unslash($textRaw));
                    // YUZ: NORMALIZE NEWLINES (entrée instant)
                    $text = $this->normalize_newlines($text);
                    $from_raw = sanitize_text_field($data['from'] ?? $data['source'] ?? 'auto');
                    $target_raw = sanitize_text_field($data['to'] ?? $data['target'] ?? '');

                    if ($text === '' || $target_raw === '') {
                        $this->trace_log('AUTO.SINGLE.ERR', [ 'req' => $trace_req_id ?: $req_id, 'reason' => 'missing_text_or_target' ]);
                        return ['ok' => false, 'error' => 'missing_text_or_target', 'req_id' => $req_id];
                    }

                    $cleaned_text = yuztra_strip_css_js_noise($text);
                    if ($cleaned_text === '') {
                        $this->trace_log('AUTO.SINGLE.ERR', [ 'req' => $trace_req_id ?: $req_id, 'reason' => 'missing_text_or_target' ]);
                        return ['ok' => false, 'error' => 'missing_text_or_target', 'req_id' => $req_id];
                    }
                    $text = $cleaned_text;

                    $resolved = yuztra_resolve_target_locale($target_raw);
                    if ($resolved === '') {
                        $this->trace_log('AUTO.SINGLE.ERR', [ 'req' => $trace_req_id ?: $req_id, 'reason' => 'invalid_target' ]);
                        wp_send_json_error(['message' => 'invalid_target', 'req_id' => $req_id], 200);
                    }

                    $source_canon = yuztra_canon_lang($from_raw !== '' ? $from_raw : 'auto');
                    $target_canon = yuztra_canon_lang($resolved !== '' ? $resolved : $target_raw);

                    if ($target_canon === '' || $target_canon === 'auto') {
                        $this->trace_log('AUTO.SINGLE.ERR', [ 'req' => $trace_req_id ?: $req_id, 'reason' => 'missing_target' ]);
                        wp_send_json_error(['message' => 'missing_target', 'req_id' => $req_id], 400);
                    }

                    if ($from_raw !== 'auto' && strcasecmp(yuztra_norm_locale($from_raw), $resolved) === 0) {
                        $this->trace_log('AUTO.SINGLE.ERR', [ 'req' => $trace_req_id ?: $req_id, 'reason' => 'invalid_target_same_lang' ]);
                        return ['ok' => false, 'error' => 'invalid_target', 'req_id' => $req_id];
                    }

                    $from_canon = yuztra_norm_locale($from_raw) ?: 'auto';
                    $to_canon   = $resolved;

                    $target_code = strtoupper(str_replace('-', '_', $to_canon));
                    $sourceLang  = $from_canon === 'auto'
                        ? ''
                        : strtoupper(str_replace('-', '_', $from_canon));

                    // Trace IN
                    $this->trace_log('AUTO.SINGLE.IN', [
                        'req'   => $trace_req_id ?: $req_id,
                        'from'  => $source_canon,
                        'to'    => $target_canon,
                        'chars' => strlen($text),
                    ]);

                    $srcObj = null;
                    if ($sourceLang !== '' && method_exists($this->language_manager, 'get_by_code')) {
                        $srcObj = $this->language_manager->get_by_code($sourceLang);
                    }

                    if ((!$srcObj || !isset($srcObj->id)) && $from_canon !== 'auto' && method_exists($this->language_manager, 'get_all_languages')) {
                        $all = $this->language_manager->get_all_languages();
                        foreach ($all as $lang) {
                            $code = isset($lang->language_code) ? (string) $lang->language_code : '';
                            if ($code === '') {
                                continue;
                            }
                            $norm = yuztra_norm_locale($code);
                            if ($norm === $from_canon || yuztra_root($norm) === yuztra_root($from_canon)) {
                                $sourceLang = strtoupper(str_replace('-', '_', $code));
                                $srcObj = $this->language_manager->get_by_code($sourceLang);
                                if ($srcObj && isset($srcObj->id)) {
                                    break;
                                }
                            }
                        }
                    }

                    if (!$srcObj || !isset($srcObj->id)) {
                        $fallbackSrc = method_exists($this->language_manager, 'get_source_language')
                            ? $this->language_manager->get_source_language()
                            : null;
                        if ($fallbackSrc && isset($fallbackSrc->language_code)) {
                            $sourceLang = strtoupper(str_replace('-', '_', (string) $fallbackSrc->language_code));
                            $srcObj = method_exists($this->language_manager, 'get_by_code')
                                ? $this->language_manager->get_by_code($sourceLang)
                                : null;
                        }
                    }

                    $dstObj = method_exists($this->language_manager, 'get_by_code')
                        ? $this->language_manager->get_by_code($target_code)
                        : null;

                    if (!$dstObj || !isset($dstObj->id)) {
                        wp_send_json_error(['message' => 'invalid_target', 'req_id' => $req_id], 200);
                    }

                    if (!$srcObj || !isset($srcObj->id)) {
                        return ['ok' => false, 'error' => 'invalid_source', 'req_id' => $req_id];
                    }

                    $provider_source = $source_canon;
                    $provider_target = $target_canon;

                    yuztra_debug_log('[YUZ][AJAX] yuztra_translate req=' . $req_id . ' from=' . ($sourceLang ?: $provider_source) . ' to=' . $target_code . ' len=' . strlen($text));

                    $sourceId = (int) $srcObj->id;
                    $targetId = (int) $dstObj->id;
                    $providerSettings = get_option('yuztra_api_settings', []);
                    $providerName = isset($providerSettings['api_provider']) ? (string) $providerSettings['api_provider'] : 'unknown';

                    $translation = null;
                    try {
                        if (strpos($text, '<') !== false) {
                            $translation = (new YUZTRA_HTML_Translator($this->translation_manager))->translate_html($text, $sourceId, $targetId);
                        } else {
                            $translation = $this->translation_manager->translate_text($text, $from_canon, $to_canon);
                        }
                    } catch (\Throwable $e) {
                        if (class_exists('YUZTRA_Logger')) {
                            (new YUZTRA_Logger())->log('error', '[yuztra_translate] translate threw', [
                                'msg' => $e->getMessage(),
                                'from'=> $sourceLang,
                                'to'  => $target_code
                            ]);
                        }
                        $translation = null;
                    }

                    $durationMs = (int) round((microtime(true) - $started) * 1000);

                    // Preserve provider/budget errors; do not bypass the manager with another attempt.

                    if ($translation === null || $translation === '') {
                        $this->trace_log('AUTO.SINGLE.OUT', [
                            'req'  => $trace_req_id ?: $req_id,
                            'ok'   => false,
                            'ms'   => (int) round((microtime(true) - $started) * 1000),
                            'from' => $sourceLang ?: $provider_source,
                            'to'   => $target_code,
                        ]);
                        if (class_exists('YUZTRA_Logger')) {
                            (new YUZTRA_Logger())->log('warning', '[yuztra_translate] translate returned null', [
                                'from' => $sourceLang ?: $provider_source,
                                'to'   => $target_code,
                                'ms'   => $durationMs,
                                'chars'=> strlen($text),
                                'req'  => $req_id,
                            ]);
                        }
                        return ['ok' => false, 'error' => 'translate_failed', 'req_id' => $req_id];
                    }

                    if (function_exists('yuztra_diag_log')) {
                        yuztra_diag_log('ajax:instant', [
                            'action' => 'yuztra_translate',
                            'from'   => $sourceLang,
                            'to'     => $target_code,
                            'chars'  => strlen($text),
                            'ms'     => $durationMs,
                            'trace'  => sanitize_text_field((string) ($data['yuztra_trace'] ?? ''))
                        ]);
                    }
                    if (class_exists('YUZTRA_Logger')) {
                        (new YUZTRA_Logger())->log('info', sprintf(
                            'ts=%s MODE=instant PROVIDER=%s ACTION=translate from=%s to=%s chars=%d ms=%d ok',
                            gmdate('c'),
                            $providerName,
                            $sourceLang,
                            $target_code,
                            strlen($text),
                            $durationMs
                        ));
                    }

                    // Trace OUT
                    $this->trace_log('AUTO.SINGLE.OUT', [
                        'req'   => $trace_req_id ?: $req_id,
                        'ok'    => true,
                        'ms'    => (int) round((microtime(true) - $started) * 1000),
                        'from'  => $sourceLang,
                        'to'    => $target_code,
                        'chars' => strlen($text),
                    ]);

                    $translation = mb_convert_encoding((string) $translation, 'UTF-8', 'UTF-8');
                    $translation = preg_replace("/\r\n?/", "\n", $translation);
                    $translation = $this->normalize_newlines($translation); // YUZ: NORMALIZE NEWLINES (résultat instant)
                    $translation = sanitize_textarea_field(trim($translation));

                    return [
                        'ok'   => true,
                        'data' => [
                            'translation'   => $translation,
                            'from'          => $sourceLang,
                            'to'            => $target_code,
                            'ms'            => $durationMs,
                            'req_id'        => $req_id,
                            'source_canon'  => $source_canon,
                            'target_canon'  => $target_canon,
                        ]
                    ];
                },
                true
            );
        }

        /** Historical AI routes use the real provider, budget, job and glossary services. */
        public function yuztra_ai_request(): void {
            $action=sanitize_key(wp_unslash($_POST['action'] ?? ''));
            $nonces=['yuztra_ai_test_connection'=>'yuztra_api_nonce','yuztra_ai_save_settings'=>'yuztra_con_nonce',
                'yuztra_ai_translate_text'=>'yuztra_int_nonce','yuztra_ai_translate'=>'yuztra_api_nonce',
                'yuztra_ai_batch_translate'=>'yuztra_hvy_nonce','yuztra_ai_glossary_upload'=>'yuztra_con_nonce',
                'yuztra_ai_job_status'=>'yuztra_int_nonce','yuztra_ai_update_status'=>'yuztra_con_nonce'];
            if (!isset($nonces[$action])) wp_send_json_error(['message'=>'unknown_operation'],400);
            check_ajax_referer($nonces[$action],'nonce');
            $admin=in_array($action,['yuztra_ai_test_connection','yuztra_ai_save_settings','yuztra_ai_batch_translate','yuztra_ai_glossary_upload','yuztra_ai_update_status'],true);
            if (!current_user_can('manage_options') && ($admin || !current_user_can('yuztra_translate_strings'))) wp_send_json_error(['message'=>'forbidden'],403);
            try {
                if ($action==='yuztra_ai_test_connection') {
                    $result=YUZTRA_Services::tm()->test_api_conn([]);
                    if (empty($result['success'])) wp_send_json_error($result,503);
                    wp_send_json_success($result);
                }
                if ($action==='yuztra_ai_save_settings') {
                    $raw=map_deep(wp_unslash((array) ($_POST['settings'] ?? [])), 'sanitize_text_field');
                    if (is_string($raw)) $raw=json_decode($raw,true);
                    if (!is_array($raw)) throw new InvalidArgumentException('settings_array_required');
                    $next=YUZTRA_Translation_Budget::settings();
                    // Credentials are never returned to the browser.
                    foreach (['endpoint','model','model_revision','api_key'] as $field) {
                        if (isset($raw[$field])) $next[$field]=sanitize_text_field($raw[$field]);
                    }
                    if (!empty($raw['api_type'])) {
                        if (!in_array($raw['api_type'],['ollama','custom','libretranslate','deepl','google'],true)) throw new InvalidArgumentException('invalid_provider');
                        $next['api_type']=$raw['api_type']; $next['api_provider']=$raw['api_type'];
                    }
                    foreach (['provider_timeout'=>[5,120],'num_ctx'=>[1024,8192],'num_predict'=>[64,2048],
                        'num_thread'=>[1,8],'daily_token_limit'=>[1,10000000],'worker_batch_size'=>[1,10],
                        'worker_time_budget'=>[5,120]] as $field=>$bounds) {
                        if (isset($raw[$field])) $next[$field]=min($bounds[1],max($bounds[0],(int)$raw[$field]));
                    }
                    if (!empty($next['endpoint']) && !preg_match('#^https?://[^\s]+$#i',$next['endpoint'])) throw new InvalidArgumentException('invalid_endpoint');
                    update_option('yuztra_at_settings',$next,false);
                    if (get_option('yuztra_at_settings')!==$next) throw new RuntimeException('settings_write_failed');
                    wp_send_json_success(['saved'=>true]);
                }
                if ($action==='yuztra_ai_update_status') {
                    // There is no independent AI enable switch that bypasses the canonical provider.
                    throw new InvalidArgumentException('configure_provider_in_automatic_translation_settings');
                }
                if ($action==='yuztra_ai_glossary_upload') {
                    if (empty($_POST['approved']) || !rest_sanitize_boolean(wp_unslash($_POST['approved']))) throw new InvalidArgumentException('explicit_human_approval_required');
                    $csv=self::raw_post_payload('csv') ?? '';
                    // File metadata is not text input: validate its structure and every
                    // field before trusting the temporary path or reading its contents.
                    // Upload metadata is validated by type, size, error code and is_uploaded_file()
                    // below; the temporary path must remain untouched for that filesystem check.
                    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Uploaded-file metadata requires contextual validation, not text sanitization.
                    $file_input = isset($_FILES['file']) && is_array($_FILES['file']) ? $_FILES['file'] : [];
                    $upload = is_array($file_input) ? $file_input : [];
                    $tmp_name = isset($upload['tmp_name']) && is_string($upload['tmp_name']) ? $upload['tmp_name'] : '';
                    if ($tmp_name !== '') {
                        $upload_error = isset($upload['error']) ? (int) $upload['error'] : UPLOAD_ERR_NO_FILE;
                        $upload_size  = isset($upload['size']) ? (int) $upload['size'] : 0;
                        if ($upload_error !== UPLOAD_ERR_OK || $upload_size <= 0 || $upload_size > 200000 || !is_uploaded_file($tmp_name)) throw new InvalidArgumentException('invalid_glossary_upload');
                        $csv=file_get_contents($tmp_name);
                        if ($csv === false) throw new InvalidArgumentException('invalid_glossary_upload');
                    }
                    wp_send_json_success(['imported'=>YUZTRA_Translation_Memory::import_csv((string)$csv)]);
                }
                if ($action==='yuztra_ai_job_status') {
                    $job=YUZTRA_Translation_Jobs::status(sanitize_text_field(wp_unslash($_POST['job_id'] ?? '')));
                    if (!current_user_can('manage_options') && (int)$job['owner']!==get_current_user_id()) wp_send_json_error(['message'=>'forbidden'],403);
                    wp_send_json_success($job);
                }
                $source=sanitize_text_field(wp_unslash($_POST['source_lang'] ?? 'auto'));
                $target=sanitize_text_field(wp_unslash($_POST['target_lang'] ?? ''));
                if ($action==='yuztra_ai_batch_translate') {
                    $texts=array_map('sanitize_textarea_field', wp_unslash((array) ($_POST['texts'] ?? [])));
                    if (is_string($texts)) $texts=json_decode($texts,true);
                    if (!is_array($texts)) throw new InvalidArgumentException('texts_array_required');
                    wp_send_json_success(['job_id'=>YUZTRA_Translation_Jobs::create($texts,$source,$target),'status'=>'queued']);
                }
                $text=sanitize_textarea_field(wp_unslash((string) ($_POST['text'] ?? '')));
                wp_send_json_success(['translation'=>YUZTRA_Services::tm()->translate_text($text,$source,$target),'status'=>2,'cost_cents'=>null]);
            } catch (InvalidArgumentException $e) { wp_send_json_error(['message'=>$e->getMessage()],400); }
            catch (Throwable $e) { wp_send_json_error(['message'=>$e->getMessage()],503); }
        }

        /** Authenticated catalog API. No provider calls on page load. */
        public function yuztra_strings() {
            check_ajax_referer('yuztra_int_nonce', 'nonce');
            if (!current_user_can('manage_options') && !current_user_can('yuztra_translate_strings')) wp_send_json_error(['message'=>'forbidden'],403);
            try {
                if (!YUZTRA_DB::ensure_string_tables()) throw new RuntimeException('strings_storage_unavailable');
                $op = sanitize_key(wp_unslash($_POST['op'] ?? 'search'));
                $lang = YUZTRA_String_Catalog::locale(sanitize_text_field(wp_unslash($_POST['lang'] ?? get_user_locale())));
                if ($op === 'search') {
                    wp_send_json_success(YUZTRA_String_Catalog::search($lang,sanitize_text_field(wp_unslash((string) ($_POST['q'] ?? ''))),sanitize_text_field(wp_unslash((string) ($_POST['domain'] ?? ''))),(int) sanitize_text_field(wp_unslash((string) ($_POST['status'] ?? -1))),max(1,(int) sanitize_text_field(wp_unslash((string) ($_POST['page'] ?? 1))))));
                }
                if ($op === 'scan') {
                    if (!current_user_can('manage_options')) wp_send_json_error(['message'=>'Administrator permission required to scan installed code.'],403);
                    global $wpdb;
                    $lock='yuz-tra-scan-'.substr(hash('sha256',$wpdb->prefix),0,40);
                    if ((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$lock)) !== 1) throw new RuntimeException('scan_busy');
                    try { $result = !empty($_POST['start']) ? YUZTRA_String_Scanner::start() : YUZTRA_String_Scanner::step(); }
                    finally { $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock)); }
                    wp_send_json_success($result);
                }
                if ($op === 'save') {
                    if ((int) sanitize_text_field(wp_unslash((string) ($_POST['status'] ?? 1))) === 4 && !current_user_can('manage_options')) wp_send_json_error(['message'=>'administrator_publication_required'],403);
                    $forms_raw = isset($_POST['forms']) && is_string($_POST['forms']) ? sanitize_textarea_field(wp_unslash($_POST['forms'])) : '[]';
                    $forms=map_deep((array) json_decode($forms_raw,true), 'sanitize_text_field');
                    if (!is_array($forms) || count($forms)>6) throw new InvalidArgumentException('invalid_plural_forms');
                    YUZTRA_String_Catalog::save(absint(wp_unslash($_POST['id'] ?? 0)),$lang,array_values($forms),(int) sanitize_text_field(wp_unslash((string) ($_POST['status'] ?? 1))));
                    wp_send_json_success(['saved'=>1]);
                }
                if ($op === 'approve') {
                    $forms_raw = isset($_POST['forms']) && is_string($_POST['forms']) ? sanitize_textarea_field(wp_unslash($_POST['forms'])) : '[]';
                    $forms=map_deep((array) json_decode($forms_raw,true), 'sanitize_text_field');
                    if (!is_array($forms)) throw new InvalidArgumentException('invalid_forms');
                    YUZTRA_Translation_Memory::approve(absint(wp_unslash($_POST['id'] ?? 0)),$lang,$forms);
                    wp_send_json_success(['approved'=>1]);
                }
                if ($op === 'source_language') {
                    if (!current_user_can('manage_options')) wp_send_json_error(['message'=>'forbidden'],403);
                    $domain=sanitize_text_field(wp_unslash($_POST['domain'] ?? ''));
                    $source=sanitize_text_field(wp_unslash($_POST['source'] ?? ''));
                    if (!preg_match('/^[a-zA-Z0-9_.-]{1,191}$/D',$domain) || !preg_match('/^[a-zA-Z]{2,3}(?:[_-][a-zA-Z0-9]+)*$/D',$source)) throw new InvalidArgumentException('invalid_source_language');
                    $map=(array)get_option('yuztra_domain_source_languages',[]);
                    $map[$domain]=YUZTRA_String_Catalog::locale($source);
                    global $wpdb;
                    if ($wpdb->update($wpdb->prefix.'yuz_tra_string_sources',['source_lang'=>$map[$domain]],['domain'=>$domain])===false) throw new RuntimeException('source_language_write_failed');
                    update_option('yuztra_domain_source_languages',$map,false);
                    if (get_option('yuztra_domain_source_languages')!==$map) throw new RuntimeException('source_language_write_failed');
                    wp_send_json_success(['saved'=>1,'existing_translations_unchanged'=>true]);
                }
                if ($op === 'glossary') {
                    if (!current_user_can('manage_options')) wp_send_json_error(['message'=>'forbidden'],403);
                    if (empty($_POST['approved']) || !rest_sanitize_boolean(wp_unslash($_POST['approved']))) throw new InvalidArgumentException('explicit_human_approval_required');
                    wp_send_json_success(['imported'=>YUZTRA_Translation_Memory::import_csv(self::raw_post_payload('csv') ?? '')]);
                }
                if ($op === 'translate') {
                    $ids_raw = isset($_POST['ids']) && is_string($_POST['ids']) ? sanitize_textarea_field(wp_unslash($_POST['ids'])) : '[]';
                    $ids=array_map('absint', (array) json_decode($ids_raw,true));
                    if (!is_array($ids) || count($ids)>5 || !$ids) throw new InvalidArgumentException('select_one_to_five_strings');
                    if (!YUZTRA_String_Catalog::valid_language($lang)) throw new InvalidArgumentException('invalid_language');
                    global $wpdb;
                    $done=0; $errors=[]; $skipped=0;
                    $deadline=microtime(true)+80;
                    foreach (array_unique(array_map('intval',$ids)) as $id) {
                        $existing=$wpdb->get_var($wpdb->prepare('SELECT forms FROM %i WHERE source_id=%d AND lang=%s AND status>0', $wpdb->prefix . 'yuz_tra_string_targets', $id, $lang));
                        if ($existing && array_filter((array)json_decode($existing,true),'strlen')) { $skipped++; continue; }
                        try { YUZTRA_String_Catalog::translate($id,$lang,2,$deadline); $done++; }
                        catch (Throwable $e) { $errors[]=['id'=>$id,'message'=>$e->getMessage()]; break; }
                    }
                    $report=['translated'=>$done,'skipped'=>$skipped,'errors'=>$errors,'partial'=>!empty($errors),'usage'=>YUZTRA_Translation_Budget::usage()];
                    if (!$done && $errors) wp_send_json_error(array_merge($report,['message'=>$errors[0]['message']]),503);
                    wp_send_json_success($report);
                }
                wp_send_json_error(['message'=>'unknown_operation'],400);
            } catch (InvalidArgumentException $e) { wp_send_json_error(['message'=>$e->getMessage()],400); }
            catch (Throwable $e) { wp_send_json_error(['message'=>$e->getMessage()],503); }
        }

        public function yuztra_gt_search() {
            check_ajax_referer('yuztra_int_nonce', 'nonce');
            if (!current_user_can('edit_posts')) {
                wp_send_json_error(['message' => 'forbidden'], 403);
            }

            global $wpdb;
            $table = $wpdb->prefix . 'yuztra_gettext';
            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) !== $table) {
                wp_send_json_error([
                    'code' => 'strings_storage_missing',
                    'message' => 'Le stockage gettext de YUZ-TRA n’est pas installé. La collecte et la traduction de ces chaînes ne sont pas disponibles.',
                ], 503);
            }
            $lang  = sanitize_text_field(wp_unslash($_REQUEST['lang'] ?? ''));
            $domain= sanitize_text_field(wp_unslash($_REQUEST['domain'] ?? ''));
            $status= isset($_REQUEST['status']) ? (int) sanitize_text_field(wp_unslash((string) $_REQUEST['status'])) : -1;
            $query = sanitize_text_field(wp_unslash($_REQUEST['q'] ?? ''));
            $page  = max(1, absint(wp_unslash($_REQUEST['page'] ?? 1)));
            $per   = min(100, max(10, absint(wp_unslash($_REQUEST['per_page'] ?? 30))));
            $offset= ($page - 1) * $per;

            $like = '%' . $wpdb->esc_like($query) . '%';
            $status_filter = $status >= 0 ? 1 : 0;

            $sql = $wpdb->prepare(
                "SELECT SQL_CALC_FOUND_ROWS id, domain, context, original, lang, translated, status, origin, updated_at
                 FROM %i
                 WHERE (%s = '' OR lang = %s)
                   AND (%s = '' OR domain = %s)
                   AND (%d = 0 OR status = %d)
                   AND (%s = '%%' OR original LIKE %s OR translated LIKE %s)
                 ORDER BY updated_at DESC
                 LIMIT %d OFFSET %d",
                $table, $lang, $lang, $domain, $domain, $status_filter, $status, $like, $like, $like, $per, $offset
            );
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Prepared above; table and WHERE fragments are constrained internally.
            $rows = $wpdb->get_results($sql, ARRAY_A);
            $total = (int) $wpdb->get_var('SELECT FOUND_ROWS()');

            wp_send_json_success([
                'rows' => $rows,
                'total'=> $total,
                'page' => $page,
                'per_page' => $per,
            ]);
        }

        /** Read a bounded document; its decoded fields are validated by the caller. */
        private static function raw_post_payload(string $key): ?string {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Private document reader; authorized import callers verify nonce and capabilities before invocation.
            if (!isset($_POST[$key])) return null;
            // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Raw length/type guard only; unslash below, then validate decoded fields. Never use this raw value as output or SQL.
            if (!is_string($_POST[$key]) || strlen($_POST[$key]) > 4000000) wp_send_json_error(['message'=>'invalid_payload'],400);
            // WordPress slashes $_POST, whereas filter_input reads the original SAPI
            // bytes. Unslash exactly once, before JSON/CSV parsing, never sanitize a document.
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.NonceVerification.Missing -- Authorized callers validate decoded fields before use; document sanitization would corrupt source identities.
            return wp_unslash($_POST[$key]);
        }

        private static function validated_target_languages($raw): array {
            if (is_string($raw)) {
                if (strlen($raw) > 4096) throw new InvalidArgumentException('invalid_target_languages');
                $raw = trim($raw);
                if (str_starts_with($raw, '[')) {
                    $raw = json_decode($raw, true, 4);
                } else {
                    $raw = explode(',', $raw);
                }
            }
            if (!is_array($raw) || !array_is_list($raw) || count($raw) > 100) {
                throw new InvalidArgumentException('invalid_target_languages');
            }
            $result = [];
            foreach ($raw as $lang) {
                if (!is_string($lang)) throw new InvalidArgumentException('invalid_target_language');
                $lang = trim($lang);
                if ($lang === '') continue;
                if (strlen($lang) > 20 || !preg_match('/^[a-zA-Z]{2,3}(?:[_-][a-zA-Z0-9]+)*$/D', $lang)) {
                    throw new InvalidArgumentException('invalid_target_language');
                }
                $result[] = $lang;
            }
            return array_values(array_unique($result));
        }

        private static function legacy_catalog_items($raw, string $kind = 'gettext'): array {
            if (!is_string($raw) || strlen($raw) > 2000000 || !str_starts_with(ltrim($raw), '[')) wp_send_json_error(['message'=>'invalid_items'],400);
            $items = json_decode($raw, true, 8);
            if (!is_array($items) || !array_is_list($items) || count($items) > 100) wp_send_json_error(['message'=>'invalid_items'],400);
            $schemas = [
                'gettext' => ['domain','context','original','lang','translated','status','origin'],
                'slug' => ['object_id','object_type','post_type','lang','slug','status'],
                'email' => ['ekey','source','lang','translated','status'],
            ];
            $required = ['gettext'=>['original','lang'], 'slug'=>['object_id','slug','lang'], 'email'=>['ekey','lang']];
            if (!isset($schemas[$kind])) wp_send_json_error(['message'=>'invalid_items'],400);
            foreach ($items as &$row) {
                if (!is_array($row) || array_diff(array_keys($row), $schemas[$kind])) wp_send_json_error(['message'=>'invalid_item'],400);
                foreach ($required[$kind] as $field) {
                    if (!isset($row[$field]) || $row[$field] === '') wp_send_json_error(['message'=>'invalid_item_field'],400);
                }
                foreach ($row as $field => $value) {
                    if ($field === 'translated' && $value === null) continue;
                    if (in_array($field, ['object_id','status','origin'], true)) {
                        $min = $field === 'object_id' ? 1 : 0;
                        $max = $field === 'object_id' ? PHP_INT_MAX : ($field === 'status' ? 5 : 255);
                        if ((!is_int($value) && !is_string($value)) || !preg_match('/^(?:0|[1-9][0-9]*)$/D', (string)$value)
                            || filter_var($value, FILTER_VALIDATE_INT, ['options'=>['min_range'=>$min,'max_range'=>$max]]) === false) wp_send_json_error(['message'=>'invalid_item_field'],400);
                        $row[$field] = (int)$value;
                        continue;
                    }
                    if (!is_string($value) || strlen($value) > 200000 || preg_match('//u', $value) !== 1
                        || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value)) wp_send_json_error(['message'=>'invalid_item_field'],400);
                    $valid = true;
                    switch ($field) {
                        case 'lang': $valid = strlen($value) <= 20 && preg_match('/^[a-zA-Z]{2,3}(?:[_-][a-zA-Z0-9]+)*$/D', $value); break;
                        case 'domain': $valid = preg_match('/^[a-zA-Z0-9_.-]{0,191}$/D', $value); break;
                        case 'object_type': $valid = in_array($value, ['post','term'], true); break;
                        case 'post_type': $valid = preg_match('/^[a-z0-9_-]{0,20}$/D', $value); break;
                        case 'slug': $valid = strlen($value) <= 191 && $value !== '' && sanitize_title($value) === $value; break;
                        case 'ekey': $valid = strlen($value) <= 191 && sanitize_text_field($value) === $value; break;
                        case 'context': $valid = strlen($value) <= 1000 && wp_strip_all_tags($value, false) === trim($value); break;
                        default: $valid = YUZTRA_String_Catalog::safe_form($value); break;
                    }
                    // Identity fields must never be rewritten into a different lookup key.
                    if (!$valid) wp_send_json_error(['message'=>'invalid_item_field'],400);
                }
            }
            unset($row);
            return $items;
        }

        public function yuztra_gt_save() {
            check_ajax_referer('yuztra_int_nonce', 'nonce');
            if (!current_user_can('manage_options')) {
                wp_send_json_error(['message' => 'forbidden'], 403);
            }

            $items = self::legacy_catalog_items(self::raw_post_payload('items'));
            $saved = 0;
            foreach ($items as $item) {
                if ($item['original'] === '' || $item['lang'] === '') {
                    continue;
                }
                $data = [
                    'domain'     => $item['domain'] ?? '',
                    'context'    => $item['context'] ?? '',
                    'original'   => (string) ($item['original'] ?? ''),
                    'lang'       => sanitize_text_field($item['lang'] ?? ''),
                    'translated' => isset($item['translated']) ? wp_kses_post($item['translated']) : null,
                    'status'     => isset($item['status']) ? (int) $item['status'] : 0,
                    'origin'     => isset($item['origin']) ? (int) $item['origin'] : 0,
                ];
                if (!YUZTRA_String_Service::save_gettext($data)) {
                    wp_send_json_error(['message' => 'Échec de sauvegarde gettext.', 'saved' => $saved], 500);
                }
                $saved++;
            }

            wp_send_json_success(['saved' => $saved]);
        }

        public function yuztra_slugs_search() {
            check_ajax_referer('yuztra_int_nonce', 'nonce');
            if (!current_user_can('edit_posts')) {
                wp_send_json_error(['message' => 'forbidden'], 403);
            }

            global $wpdb;
            $table = $wpdb->prefix . 'yuz_tra_slugs';
            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) !== $table) {
                wp_send_json_error([
                    'code' => 'strings_storage_missing',
                    'message' => 'Le stockage slugs de YUZ-TRA n’est pas installé. La collecte et la traduction de ces chaînes ne sont pas disponibles.',
                ], 503);
            }
            $lang = sanitize_text_field(wp_unslash($_REQUEST['lang'] ?? ''));
            $post_type = sanitize_text_field(wp_unslash($_REQUEST['post_type'] ?? ''));
            $query = sanitize_text_field(wp_unslash($_REQUEST['q'] ?? ''));
            $page = max(1, absint(wp_unslash($_REQUEST['page'] ?? 1)));
            $per = min(100, max(10, absint(wp_unslash($_REQUEST['per_page'] ?? 30))));
            $offset = ($page - 1) * $per;

            $like = '%' . $wpdb->esc_like($query) . '%';

            $sql = $wpdb->prepare(
                "SELECT SQL_CALC_FOUND_ROWS object_id, object_type, post_type, lang, slug, status, updated_at
                 FROM %i
                 WHERE (%s = '' OR lang = %s)
                   AND (%s = '' OR post_type = %s)
                   AND (%s = '%%' OR slug LIKE %s)
                 ORDER BY updated_at DESC
                 LIMIT %d OFFSET %d",
                $table, $lang, $lang, $post_type, $post_type, $like, $like, $per, $offset
            );
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Prepared above; table and WHERE fragments are constrained internally.
            $rows = $wpdb->get_results($sql, ARRAY_A);
            $total = (int) $wpdb->get_var('SELECT FOUND_ROWS()');

            wp_send_json_success([
                'rows' => $rows,
                'total'=> $total,
                'page' => $page,
                'per_page' => $per,
            ]);
        }

        public function yuztra_slugs_save() {
    check_ajax_referer('yuztra_int_nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'forbidden'], 403);
    }

    $items = self::legacy_catalog_items(self::raw_post_payload('items'), 'slug');

    $updated = 0;
    foreach ($items as $item) {
        if (empty($item['lang']) || empty($item['slug']) || empty($item['object_id'])) {
            continue;
        }
        $data = [
            'object_id'   => (int) $item['object_id'],
            'object_type' => sanitize_key($item['object_type'] ?? 'post'),
            'post_type'   => sanitize_key($item['post_type'] ?? ''),
            'lang'        => sanitize_text_field($item['lang']),
            'slug'        => sanitize_title($item['slug']),
            'status'      => isset($item['status']) ? (int) $item['status'] : 0,
        ];
        if (!YUZTRA_String_Service::save_slug($data)) {
            wp_send_json_error(['message' => 'Échec de sauvegarde slugs.', 'saved' => $updated], 500);
        }
        // A target-language slug must not overwrite the source post/term permalink.
        $updated++;
    }

    wp_send_json_success(['saved' => $updated]);
}


        public function yuztra_eml_search() {

            check_ajax_referer('yuztra_int_nonce', 'nonce');
            if (!current_user_can('edit_posts')) {
                wp_send_json_error(['message' => 'forbidden'], 403);
            }

            global $wpdb;
            $table = $wpdb->prefix . 'yuz_tra_emails';
            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) !== $table) {
                wp_send_json_error([
                    'code' => 'strings_storage_missing',
                    'message' => 'Le stockage emails de YUZ-TRA n’est pas installé. La collecte et la traduction de ces chaînes ne sont pas disponibles.',
                ], 503);
            }
            $lang = sanitize_text_field(wp_unslash($_REQUEST['lang'] ?? ''));
            $query = sanitize_text_field(wp_unslash($_REQUEST['q'] ?? ''));
            $page = max(1, absint(wp_unslash($_REQUEST['page'] ?? 1)));
            $per = min(100, max(10, absint(wp_unslash($_REQUEST['per_page'] ?? 30))));
            $offset = ($page - 1) * $per;

            $like = '%' . $wpdb->esc_like($query) . '%';

            $sql = $wpdb->prepare(
                "SELECT SQL_CALC_FOUND_ROWS ekey, lang, translated, status, source, updated_at
                 FROM %i
                 WHERE (%s = '' OR lang = %s)
                   AND (%s = '%%' OR ekey LIKE %s OR translated LIKE %s)
                 ORDER BY updated_at DESC
                 LIMIT %d OFFSET %d",
                $table, $lang, $lang, $like, $like, $like, $per, $offset
            );
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Prepared above; table and WHERE fragments are constrained internally.
            $rows = $wpdb->get_results($sql, ARRAY_A);
            $total = (int) $wpdb->get_var('SELECT FOUND_ROWS()');

            wp_send_json_success([
                'rows' => $rows,
                'total'=> $total,
                'page' => $page,
                'per_page' => $per,
            ]);
        }

        public function yuztra_eml_save() {
            check_ajax_referer('yuztra_int_nonce', 'nonce');
            if (!current_user_can('manage_options')) {
                wp_send_json_error(['message' => 'forbidden'], 403);
            }

            $items = self::legacy_catalog_items(self::raw_post_payload('items'), 'email');

            $saved = 0;
            foreach ($items as $item) {
                if ($item['ekey'] === '' || $item['lang'] === '') {
                    continue;
                }
                $data = [
                    'ekey'       => sanitize_text_field($item['ekey']),
                    'source'     => isset($item['source']) ? wp_kses_post($item['source']) : '',
                    'lang'       => sanitize_text_field($item['lang']),
                    'translated' => isset($item['translated']) ? wp_kses_post($item['translated']) : null,
                    'status'     => isset($item['status']) ? (int) $item['status'] : 0,
                ];
                if (!YUZTRA_String_Service::save_email($data)) {
                    wp_send_json_error(['message' => 'Échec de sauvegarde emails.', 'saved' => $saved], 500);
                }
                $saved++;
            }

            wp_send_json_success(['saved' => $saved]);
        }

        private function synchronise_slug_entity(array $data): void {
            $object_id = (int) $data['object_id'];
            $slug      = $data['slug'];
            if (!$object_id || !$slug) {
                return;
            }
            if ($data['object_type'] === 'post') {
                $post = get_post($object_id);
                if ($post && $post->post_name !== $slug) {
                    wp_update_post([
                        'ID' => $object_id,
                        'post_name' => $slug,
                    ]);
                }
            } elseif ($data['object_type'] === 'term') {
                $term = get_term($object_id);
                if ($term && $term instanceof WP_Term && $term->slug !== $slug) {
                    wp_update_term($object_id, $term->taxonomy, ['slug' => $slug]);
                }
            }
        }

        /**
         * Extract visible-ish text nodes from HTML into editor-friendly items.
         * Returns: [ { id, original } ... ]
         */
        private function yuztra_te_extract_strings_from_html(string $html): array {
            $out = [];
            $uniq = [];
            if ($html === '') return $out;
            // Normalize HTML
            $doc = new \DOMDocument();
            libxml_use_internal_errors(true);
            $enc = '<?xml encoding="utf-8" ?>';
            $loaded = @$doc->loadHTML($enc . $html, LIBXML_NOWARNING|LIBXML_NOERROR);
            libxml_clear_errors();
            if (!$loaded) return $out;
            $xp = new \DOMXPath($doc);
            $nodes = $xp->query('//p|//h1|//h2|//h3|//h4|//h5|//h6|//li|//blockquote|//a|//span|//div');
            if (!$nodes) return $out;
            foreach ($nodes as $n) {
                $text = trim(preg_replace('/\s/', ' ', $n->textContent ?? ''));
                if ($text === '' || mb_strlen($text) < 2) continue;
                // Skip duplicate texts
                if (isset($uniq[$text])) continue;
                $uniq[$text] = true;
                $out[] = [ 'id' => substr(md5($text), 0, 12), 'original' => $text, 'translations' => new \stdClass() ];
                if (count($out) >= 500) break; // soft cap
            }
            return $out;
        }

        public function yuztra_tm_del_translation() {
            self::__handleRequest(
                'yuztra_del_nonce',
                ['translation_id'],
                function ($data) {
                    if (!current_user_can('manage_options')) {
                        wp_send_json_error(['message'=>'unauthorized','code'=>'unauthorized'],403);
                    }
                    global $wpdb;
                    $this->ensure_db_tables();

                    $trans_table = $wpdb->prefix . 'yuz_tra_translations';
                    if (!$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($trans_table)))) {
                        (new YUZTRA_Logger())->log('critical', "Translations table $trans_table missing after recreation attempt");
                        return [];
                    }

                    $translation_id = intval($data['translation_id']);

                    $result = $wpdb->delete($trans_table, ['id' => $translation_id], ['%d']);
                    if ($result === false) {
                        throw new \Exception( esc_html( "Failed to delete translation ID {$translation_id}: " . $wpdb->last_error ) );                    }

                    return ['message' => __('Translation deleted', 'yuz-tra')];
                },
                true
            );
        }

        public function yuztra_tm_test_api() {
    if (!current_user_can('manage_options')) wp_send_json_error(['message'=>'forbidden'],403);
    // Vérif stricte via check_ajax_referer
    check_ajax_referer('yuztra_api_nonce', 'nonce');

    self::__handleRequest(
        'yuztra_api_nonce',
        ['provider'], // endpoint/api_key validés conditionnellement ci-dessous
        function ($data) {
            $provider = sanitize_text_field($data['provider'] ?? '');
            $endpoint = esc_url_raw($data['endpoint'] ?? ($data['url'] ?? ''));
            $api_key  = sanitize_text_field($data['api_key'] ?? ($data['apiKey'] ?? ''));
            $extra_raw = $data['extra_settings'] ?? ($data['extraSettings'] ?? []);

            if (is_string($extra_raw)) {
                $decoded = json_decode(wp_unslash($extra_raw), true);
                $extra_in = is_array($decoded) ? $decoded : [];
            } else {
                $extra_in = (array) wp_unslash($extra_raw);
            }

            $extra = [];
            foreach ($extra_in as $key => $value) {
                if (!is_scalar($value)) {
                    continue;
                }
                $extra[sanitize_key((string) $key)] = sanitize_text_field((string) $value);
            }

            $valid_providers = ['libretranslate', 'deepl', 'google', 'custom'];
            if (!in_array($provider, $valid_providers, true)) {
                throw new \InvalidArgumentException('Invalid provider');
            }
            if (in_array($provider, ['deepl', 'google', 'custom'], true) && empty($api_key)) {
                throw new \InvalidArgumentException('Missing API key');
            }
            if (in_array($provider, ['libretranslate', 'custom'], true) && empty($endpoint)) {
                throw new \InvalidArgumentException('Missing endpoint');
            }

            $start_time     = microtime(true);
            $test_settings  = array_merge([
                'provider'       => $provider,
                'endpoint'       => $endpoint,
                'api_key'        => $api_key,
            ], $extra);
            if (!empty($extra)) {
                $test_settings['extra_settings'] = $extra;
            }

            $result         = $this->translation_manager->test_api_conn($test_settings);
            $end_time       = microtime(true);
            $execution_time = round(($end_time - $start_time) * 1000, 2);

            return [
                'success'           => !empty($result['success']),
                'message'           => esc_html($result['message'] ?? ''),
                'translatedText'    => esc_html($result['translatedText'] ?? ''),
                'execution_time_ms' => $execution_time,
                'timestamp'         => current_time('mysql'),
            ];
        },
        true
    );
}


        /** -------------------- JAVASCRIPT ACTIONS -------------------- */
        public function yuztra_js_upd_database() {
            self::__handleRequest('yuztra_hvy_nonce',
                [],
                function ($data) {
                    if (!current_user_can('manage_options')) {
                        wp_send_json_error(['message'=>'unauthorized','code'=>'unauthorized'],403);
                    }
                    global $wpdb;
                    $this->ensure_db_tables();

                    $trans_table = $wpdb->prefix . 'yuz_tra_translations';
                    if (!$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($trans_table)))) {
                        (new YUZTRA_Logger())->log('critical', "Translations table $trans_table missing after recreation attempt");
                        return [];
                    }

                    $publish_status = function_exists('yuztra_status_transition')
                        ? yuztra_status_transition('publish')
                        : 1;

                    $result = $wpdb->query($wpdb->prepare('UPDATE %i SET updated_at = %s WHERE status = %d', $trans_table, current_time('mysql'), $publish_status));
                    if ($result === false) {
                        throw new \Exception( esc_html( "Failed to update translations: " . $wpdb->last_error ) );                    }

                    return ['message' => __('Database updated', 'yuz-tra'), 'updated_rows' => $result];
                },
                true
            );
        }

        public function yuztra_js_upd_bulkedit() {
            self::__handleRequest('yuztra_hvy_nonce',
                ['data'],
                function ($data) {
                    if (!current_user_can('manage_options')) {
                        wp_send_json_error(['message'=>'unauthorized','code'=>'unauthorized'],403);
                    }
                    global $wpdb;
                    $this->ensure_db_tables();

                    $trans_table = $wpdb->prefix . 'yuz_tra_translations';
                    if (!$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($trans_table)))) {
                        (new YUZTRA_Logger())->log('critical', "Translations table $trans_table missing after recreation attempt");
                        return [];
                    }

                    $data = json_decode($data['data'], true);
                    if (!is_array($data) || empty($data)) {
                        throw new \Exception( esc_html( 'Invalid bulk edit data' ) );                    }

                    $publish_status = function_exists('yuztra_status_transition')
                        ? yuztra_status_transition('publish')
                        : 2;

                    $updated_rows = 0;
                    foreach ($data as $item) {
                        $id              = intval($item['id'] ?? 0);
                        $translated_text = wp_kses_post($item['translated_text'] ?? '');
                        if ($id <= 0 || $translated_text === '') continue;

                        $result = $wpdb->update($trans_table, ['translated_text' => $translated_text, 'status' => $publish_status, 'updated_at' => current_time('mysql')], ['id' => $id], ['%s','%d','%s'], ['%d']);
                        if ($result !== false) {
                            $updated_rows = (int) $result;
                        }
                    }

                    if ($updated_rows === 0) {
                        throw new \Exception( esc_html( 'No translations updated' ) );                    }

                    $dictionary = $wpdb->get_results($wpdb->prepare('SELECT * FROM %i WHERE status = %d', $trans_table, $publish_status), ARRAY_A);
                    if ($dictionary === null) {
                        throw new \Exception( esc_html( "Failed to retrieve dictionary: " . $wpdb->last_error ) );                    }

                    $total_items = $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE status = %d', $trans_table, $publish_status));
                    if ($total_items === null) {
                        throw new \Exception( esc_html( "Failed to retrieve total items: " . $wpdb->last_error ) );                    }

                    return ['dictionary' => $dictionary ?: [], 'totalItems' => (int)$total_items, 'message' => __('Bulk action applied', 'yuz-tra')];
                },
                true
            );
        }

        public function yuztra_js_get_gtxtscan() {
            self::__handleRequest('yuztra_log_nonce',
                [],
                function ($data) {
                    if (!current_user_can('manage_options')) {
                        wp_send_json_error(['message'=>'unauthorized','code'=>'unauthorized'],403);
                    }
                    $progress = get_option('yuztra_string_scan_last', []);
                    return ['completed'=>(bool)($progress['done'] ?? false),
                        'state'=>$progress ? (!empty($progress['done']) ? 'completed' : 'paused') : 'idle',
                        'progress_message'=>$progress ? sprintf('%d / %d files', $progress['scanned'], $progress['total']) : 'No scan started',
                        'report'=>$progress];
                },
                true
            );
        }

        public function yuztra_js_get_regular() {
            if (!current_user_can('manage_options') && !current_user_can('yuztra_translate_content')) wp_send_json_error(['message' => 'forbidden'], 403);
            self::__handleRequest('yuztra_int_nonce',
                ['nodes', 'target_lang'],
                function ($data) {
                    global $wpdb;
                    $this->ensure_db_tables();

                    $lang_table = $wpdb->prefix . 'yuz_tra_languages';
                    if (!$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($lang_table)))) {
                        (new YUZTRA_Logger())->log('critical', "Languages table $lang_table missing after recreation attempt");
                        return [];
                    }

                    $nodes       = wp_kses_post($data['nodes']);
                    // YUZ: NORMALIZE NEWLINES (entrée source)
                    $nodes       = $this->normalize_newlines($nodes);
                    $requested   = sanitize_text_field($data['target_lang']);

                    // Resolve best target among DB-declared translatable languages
                    $idx       = $this->get_translatable_index($wpdb);
                    $codes     = $idx['codes'];
                    $weights   = $idx['weights'];
                    $source    = $idx['source'];
                    $defTarget = $this->compute_default_target($codes, $weights, $source);
                    $target_lang = $this->best_match_locale($requested, $codes, $weights, $defTarget);

                    if ($this->logger) { try { $this->logger->log('debug','Lang resolver (js_get_regular)',['requested'=>$requested,'resolved'=>$target_lang,'codes'=>array_slice($codes,0,10)]); } catch(\Throwable $ignored){} }
                    $target_lang_id = (int) $wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE language_code = %s', $lang_table, $target_lang));
                    if ($target_lang_id <= 0) {
                        $target_lang = $defTarget;
                        $target_lang_id = (int) $wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE language_code = %s', $lang_table, $target_lang));
                    }
                    if ($target_lang_id <= 0) {
                        throw new \Exception( esc_html( "Invalid target language resolution: {$requested}" ) );                    }

                    $source_lang_id = $wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE is_source = 1', $lang_table));
                    if (!$source_lang_id) {
                        throw new \Exception( esc_html( 'Failed to retrieve source language ID: ' . $wpdb->last_error ) );                    }

                    $translated_text = $this->translation_manager->translate($nodes, $source_lang_id, $target_lang_id);
                    if ($translated_text === null) {
                        throw new \Exception( esc_html( "Translation failed for {$target_lang}" ) );                    }

                    // YUZ: NORMALIZE NEWLINES (résultat provider)
                    $translated_text = $this->normalize_newlines((string)$translated_text);
                    return ['translations' => [$target_lang => $translated_text]]; // YUZ: normalized
                }
            );
        }

        /** Public display only: no provider, table creation, draft reads or writes. */
        public function yuztra_public_lookup() {
            $nonce = is_string($_REQUEST['nonce'] ?? null) ? sanitize_text_field(wp_unslash($_REQUEST['nonce'])) : '';
            if ($nonce === '' || !wp_verify_nonce($nonce, 'yuztra_nonce')) {
                wp_send_json_error(['message' => 'invalid_nonce'], 403);
            }
            $post_id = isset($_REQUEST['post_id']) && (is_string($_REQUEST['post_id']) || is_int($_REQUEST['post_id']))
                ? sanitize_text_field(wp_unslash((string) $_REQUEST['post_id'])) : null;
            if ((!is_string($post_id) && !is_int($post_id)) || !ctype_digit((string)$post_id)
                || filter_var($post_id, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]) === false
                || !is_string($_REQUEST['language'] ?? null) || !is_string($_REQUEST['originals'] ?? null)) {
                wp_send_json_error(['message' => 'invalid_request'], 400);
            }
            $lang = sanitize_text_field(wp_unslash($_REQUEST['language']));
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Validate decoded exact-match keys below; sanitizing JSON corrupts markup and escapes.
            $raw = wp_unslash($_REQUEST['originals']);
            if (strlen($raw) > 131072) wp_send_json_error(['message'=>'invalid_request'],400);
            $post = get_post(absint($post_id));
            if (!$post || !is_post_publicly_viewable($post) || $post->post_password !== '') {
                wp_send_json_error(['message' => 'not_public'], 403);
            }
            if (!preg_match('/^[a-z]{2,3}(?:[_-][A-Za-z0-9]{2,8}){0,2}$/D', $lang)) {
                wp_send_json_error(['message' => 'invalid_language'], 400);
            }
            $originals = json_decode($raw, true, 8);
            if (!str_starts_with(ltrim($raw), '[') || !is_array($originals) || !array_is_list($originals) || count($originals) > 100) {
                wp_send_json_error(['message' => 'invalid_originals'], 400);
            }
            foreach ($originals as $original) {
                if (!is_string($original) || strlen($original) > 8192 || preg_match('//u',$original)!==1
                    || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/',$original)) {
                    wp_send_json_error(['message' => 'invalid_original'], 400);
                }
            }
            global $wpdb;
            $results = [];
            foreach ($originals as $original) {
                // Exact source equality is intentional: markup and placeholders are lookup keys.
                // Both the post and the translation must be published; client flags cannot relax this.
                $row = $wpdb->get_row($wpdb->prepare(
                    "SELECT t.id, t.block_id, t.translated_text FROM {$wpdb->prefix}yuz_tra_translations t
                     INNER JOIN {$wpdb->prefix}yuz_tra_languages l ON l.id = t.target_lang_id
                     WHERE t.post_id = %d AND t.context = %s AND t.original_text = %s
                       AND t.status = %d AND l.language_code = %s ORDER BY t.id DESC LIMIT 1",
                    $post->ID, 'content', $original, YUZTRA_STATUS_PUBLISHED, str_replace('-', '_', $lang)
                ), ARRAY_A);
                $translation = ['translated' => $row ? wp_kses_post($row['translated_text']) : '',
                    'status' => $row ? '4' : '0', 'translation_id' => $row ? (int) $row['id'] : 0];
                $results[] = ['original' => $original, 'block_id' => $row['block_id'] ?? '',
                    'translation_id' => $translation['translation_id'],
                    'translations' => [$lang => $translation], 'translationsArray' => [$lang => $translation]];
            }
            wp_send_json_success($results);
        }

        public function yuztra_get_regular() {
            if (!current_user_can('manage_options') && !current_user_can('yuztra_translate_content')) wp_send_json_error(['message' => 'forbidden'], 403);
            self::__handleRequest(
                'yuztra_nonce',
                ['originals', 'language'],
                function ($data) {
                    global $wpdb;
                    $t0 = microtime(true);
                    $cid = is_string($data['cid'] ?? null) ? sanitize_text_field(wp_unslash($data['cid'])) : null;
                    $logger = $this->logger ?? (class_exists('YUZTRA_Logger') ? new \YUZTRA_Logger() : null);
                    $lang_param = is_string($data['language'] ?? null) ? sanitize_text_field(wp_unslash($data['language'])) : '';
                    $this->log_ajax_entry('yuztra_get_regular', [
                        'cid'       => $cid,
                        'language'  => $lang_param,
                        'post_id'   => absint($data['post_id'] ?? 0),
                        // Selectors are unnecessary for diagnostic correlation; do not persist them.
                    ]);
                    if ($logger) {
                        try {
                            $logger->log('info', 'GET_REGULAR.IN', [
                                'cid'      => $cid,
                                'language' => $lang_param,
                            ]);
                        } catch (\Throwable $ignored) {}
                    }
                    $this->ensure_db_tables();

                    $trans_table = $wpdb->prefix . 'yuz_tra_translations';
                    $lang_table  = $wpdb->prefix . 'yuz_tra_languages';
                    if (!$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($trans_table))) || !$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($lang_table)))) {
                        if ($logger) { try { $logger->log('error', 'GET_REGULAR.NO_TABLES', ['cid'=>$cid,'trans_table'=>$trans_table,'lang_table'=>$lang_table]); } catch(\Throwable $ignored) {} }
                        return [];
                    }

                    $originals_raw = $data['originals'] ?? '[]';
                    if (is_string($originals_raw)) {
                        $originals_raw = wp_unslash($originals_raw);
                    }
                    $originals = json_decode($originals_raw, true);
                    if (!is_array($originals)) {
                        $originals = [];
                    }

                    $skip_raw = $data['skip_machine_translation'] ?? '[]';
                    if (is_string($skip_raw)) {
                        $skip_raw = wp_unslash($skip_raw);
                    }
                    $skip_items = json_decode($skip_raw, true);
                    if (!is_array($skip_items)) {
                        $skip_items = [];
                    }
                    $skip_flags = [];
                    $skip_values = [];
                    foreach (array_values($skip_items) as $skip_index => $skip_value) {
                        if (is_bool($skip_value)) {
                            $skip_flags[$skip_index] = $skip_value;
                            continue;
                        }
                        if (is_numeric($skip_value) && !is_string($skip_value)) {
                            $skip_flags[$skip_index] = ((int) $skip_value) === 1;
                            continue;
                        }
                        $skip_text = is_string($skip_value) ? trim($skip_value) : '';
                        if ($skip_text === '') {
                            continue;
                        }
                        $skip_lower = strtolower($skip_text);
                        if (in_array($skip_lower, ['1', 'true', 'yes', 'on'], true)) {
                            $skip_flags[$skip_index] = true;
                            continue;
                        }
                        if (in_array($skip_lower, ['0', 'false', 'no', 'off'], true)) {
                            $skip_flags[$skip_index] = false;
                            continue;
                        }
                        $skip_values[] = $skip_text;
                    }
                    $normalized_skip = array_map(function ($item) {
                        return $this->normalize_newlines((string) $item);
                    }, $skip_values);

                    $block_keys_raw = $data['block_keys'] ?? '[]';
                    if (is_string($block_keys_raw)) {
                        $block_keys_raw = wp_unslash($block_keys_raw);
                    }
                    $block_keys = json_decode($block_keys_raw, true);
                    if (!is_array($block_keys)) {
                        $block_keys = [];
                    }
                    $block_keys = array_values(array_map(static function($k){ return is_string($k) ? sanitize_text_field($k) : ''; }, $block_keys));

                    if ($logger) {
                        try {
                            $logger->log('debug', 'GET_REGULAR.DECODED', [
                                'cid'            => $cid,
                                'originals_len'  => count($originals),
                                'skip_len'       => count($skip_items),
                                'skip_flags_len' => count($skip_flags),
                                'block_keys_len' => count($block_keys),
                            ]);
                        } catch (\Throwable $ignored) {}
                    }
                    $this->log_ajax_entry('yuztra_get_regular_payload', [
                        'cid'            => $cid,
                        'language'       => $lang_param,
                        'originals_len'  => count($originals),
                        'skip_len'       => count($skip_items),
                        'skip_flags_len' => count($skip_flags),
                        'block_keys_len' => count($block_keys),
                    ]);

                    $normalize_plain = static function (string $text): string {
                        if (function_exists('wp_strip_all_tags')) {
                            $text = wp_strip_all_tags($text);
                        } else {
                            $text = wp_strip_all_tags($text);
                        }
                        $text = preg_replace('/\s+/u', ' ', $text);
                        return $text === null ? '' : trim($text);
                    };
                    $normalize_original_item = static function ($item): string {
                        if (is_string($item)) {
                            return $item;
                        }
                        if (is_scalar($item)) {
                            return (string) $item;
                        }
                        if (is_array($item)) {
                            foreach (['text', 'original', 'original_text', 'value', 'label'] as $key) {
                                if (!array_key_exists($key, $item)) {
                                    continue;
                                }
                                $candidate = $item[$key];
                                if (is_string($candidate)) {
                                    return $candidate;
                                }
                                if (is_scalar($candidate)) {
                                    return (string) $candidate;
                                }
                            }
                        }
                        return '';
                    };
                    $is_safe_placeholder_key = static function (string $block_key): bool {
                        return $block_key !== '' && (bool) preg_match('/^form\|[^|]+\|[^|]+:(?:placeholder|data-placeholder)$/', $block_key);
                    };
                    $lookup_placeholder_by_original = static function (string $original_text, int $target_id, int $source_id) use ($wpdb, $trans_table): ?array {
                        if ($original_text === '' || $target_id <= 0 || $source_id <= 0) {
                            return null;
                        }
                        $row = $wpdb->get_row(
                            $wpdb->prepare(
                                "SELECT id, translated_text, status
                                 FROM %i
                                 WHERE original_text = %s
                                   AND target_lang_id = %d
                                   AND source_lang_id = %d
                                   AND status >= 1
                                   AND translated_text IS NOT NULL
                                   AND translated_text <> ''
                                 ORDER BY updated_at DESC, id DESC
                                 LIMIT 1",
                                $trans_table,
                                $original_text,
                                $target_id,
                                $source_id
                            ),
                            ARRAY_A
                        );
                        return is_array($row) ? $row : null;
                    };

                    $primary_api   = get_option('yuztra_at_settings', []);
                    $secondary_api = get_option('yuztra_api_settings', []);
                    $legacy_api    = function_exists('get_option') ? get_option('yuztra_settings', []) : [];
                    $raw_api_settings = array_merge(
                        is_array($secondary_api) ? $secondary_api : [],
                        is_array($primary_api) ? $primary_api : [],
                        is_array($legacy_api) ? $legacy_api : []
                    );
                    $resolved_api_settings = null;
                    if (is_array($raw_api_settings) && !empty($raw_api_settings['api_provider'])) {
                        $provider = (string) $raw_api_settings['api_provider'];
                        $provider_slug = $provider;
                        if ($provider_slug === '') {
                            $provider_slug = (string) ($raw_api_settings['api_adapter'] ?? '');
                        }
                        $provider_short = $provider_slug;
                        if ($provider_short !== '') {
                            $provider_short = preg_replace('/translate$/i', '', $provider_short);
                            $provider_short = trim((string) $provider_short, "_- ");
                        }
                        $endpoint = '';
                        foreach (array_filter([
                            $provider_slug !== '' ? $provider_slug . '_url' : null,
                            $provider_short !== '' ? $provider_short . '_url' : null,
                            'url_to_load',
                            'custom_url',
                        ]) as $key) {
                            if (isset($raw_api_settings[$key]) && $raw_api_settings[$key] !== '') {
                                $endpoint = (string) $raw_api_settings[$key];
                                break;
                            }
                        }
                        $api_key = '';
                        foreach (array_filter([
                            $provider_slug !== '' ? $provider_slug . '_key' : null,
                            $provider_short !== '' ? $provider_short . '_key' : null,
                            'api_key',
                            'custom_key',
                        ]) as $key) {
                            if (isset($raw_api_settings[$key]) && $raw_api_settings[$key] !== '') {
                                $api_key = (string) $raw_api_settings[$key];
                                break;
                            }
                        }
                        $resolved_api_settings = [
                            'api_type'       => $provider,
                            'endpoint'       => $endpoint,
                            'api_key'        => $api_key,
                            'extra_settings' => [
                                'alternatives'   => $raw_api_settings['alternatives'] ?? null,
                                'deepl_free'     => $raw_api_settings['deepl_free'] ?? null,
                                'google_project' => $raw_api_settings['google_project'] ?? null,
                                'custom_auth'    => $raw_api_settings['custom_auth'] ?? null,
                                'custom_method'  => $raw_api_settings['custom_method'] ?? null,
                                'custom_format'  => $raw_api_settings['custom_format'] ?? null,
                            ],
                        ];
                    }

                    if ($logger) {
                        try {
                            $logger->log('debug', 'Auto translation API settings', [
                                'cid'               => $cid,
                                'provider'          => $provider ?? '',
                                'configured_fields' => array_keys(array_filter(
                                    $raw_api_settings,
                                    static fn($value): bool => $value !== '' && $value !== null
                                )),
                                'has_endpoint'      => !empty($resolved_api_settings['endpoint']),
                                'has_api_key'       => !empty($resolved_api_settings['api_key']),
                            ]);
                        } catch (\Throwable $ignored) {}
                    }

                    if ($resolved_api_settings && method_exists($this->translation_manager, 'set_api_settings')) {
                        try {
                            $this->translation_manager->set_api_settings($resolved_api_settings);
                        } catch (\Throwable $e) {
                            if ($this->logger) {
                                try {
                                    $this->logger->log('warning', 'Failed to apply API settings', ['message' => $e->getMessage()]);
                                } catch (\Throwable $ignored) {}
                            }
                        }
                    }

                    $can_auto_translate = false;
                    try {
                        $can_auto_translate = $this->translation_manager && $this->translation_manager->isConfigured();
                    } catch (\Throwable $e) {
                        $can_auto_translate = false;
                    }
                    $logger = $this->logger ?? (class_exists('YUZTRA_Logger') ? new \YUZTRA_Logger() : null);
                    if ($logger) {
                        try {
                            $logger->log('debug', 'Translation manager resolver', [
                                'cid' => $cid,
                                'class' => is_object($this->translation_manager) ? get_class($this->translation_manager) : gettype($this->translation_manager),
                                'can_auto' => $can_auto_translate,
                            ]);
                        } catch (\Throwable $ignored) {}
                    }

                    $page_url = isset($data['page_url']) ? esc_url_raw($data['page_url']) : '';
                    $post_id  = isset($data['post_id']) ? absint($data['post_id']) : 0;
                    if (!$post_id && $page_url) {
                        $post_id = $this->resolve_post_id_from_page_url($page_url);
                    }
                    $context = isset($data['context']) ? sanitize_text_field($data['context']) : 'content';
                    if ($post_id === 0) {
                        $this->trace_log('GET_REGULAR.POST_ID_ZERO', [
                            'url'     => $page_url,
                            'context' => $context,
                            'cid'     => $cid,
                        ]);
                    }

                    // Resolve target language code generically against DB-declared languages
                    $requested = sanitize_text_field($data['language'] ?? ($data['target_lang'] ?? ''));
                    if ($requested === '') {
                        // try page_url query ?lang=… or slug /xx/xx-YY/
                        $requested = $this->extract_locale_from_url($page_url ?: '');
                    }
                    if ($requested === '') {
                        $requested = get_locale();
                    }
                    $idx       = $this->get_translatable_index($wpdb);
                    $codes     = $idx['codes'];
                    $weights   = $idx['weights'];
                    $source    = $idx['source'];
                    $defTarget = $this->compute_default_target($codes, $weights, $source);
                    $target_code = $this->best_match_locale($requested, $codes, $weights, $defTarget);

                    if ($this->logger) { try { $this->logger->log('debug','Lang resolver (get_regular)',['requested'=>$requested,'resolved'=>$target_code,'codes'=>array_slice($codes,0,10)]); } catch(\Throwable $ignored){} }
                    $target_lang_id = (int) $wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE language_code = %s', $lang_table, $target_code));
                    if ($target_lang_id <= 0) {
                        // fallback to default target
                        $target_code = $defTarget;
                        $target_lang_id = (int) $wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE language_code = %s', $lang_table, $target_code));
                    }
                    if ($target_lang_id <= 0) {
                        return [];
                    }

                    $requested_source = sanitize_text_field($data['lang_from'] ?? ($data['original_language'] ?? ($data['source_lang'] ?? '')));
                    if ($requested_source === '') {
                        $requested_source = $source ?: get_locale();
                    }
                    $source_codes = $codes;
                    if ($source && !in_array($source, $source_codes, true)) {
                        $source_codes[] = $source;
                    }
                    if ($defTarget && !in_array($defTarget, $source_codes, true)) {
                        $source_codes[] = $defTarget;
                    }
                    $source_code = $this->best_match_locale($requested_source, $source_codes, $weights, $source ?: $defTarget);
                    $source_code = $source_code ?: get_locale();

                        $source_lang_id = (int) $wpdb->get_var($wpdb->prepare(
                        'SELECT id FROM %i WHERE language_code = %s',
                        $lang_table,
                        $source_code
                    ));

                    if ($source_lang_id <= 0) {
                        $source_lang_id = (int) $wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE is_source = 1 LIMIT 1', $lang_table));
                    }

                    // Probe switch (query/body/header)
                    $is_probe = !empty($_REQUEST['probe'])
                        || (isset($_GET['yuzprobe']) && $_GET['yuzprobe'] == '1')
                        || (isset($_GET['yuz-probe']) && $_GET['yuz-probe'] == '1')
                        || (!empty($_SERVER['HTTP_X_YUZ_PROBE']));

                    if ($is_probe && $this->logger) {
                        try {
                            $this->logger->log('info', 'YUZ-PROBE request (yuztra_get_regular)', [
                                'target'    => $target_code,
                                'source'    => $source_code,
                                'post_id'   => $post_id,
                                'context'   => $context,
                                'page_url'  => $page_url,
                                'count'     => count($originals),
                                'sample_count' => min(5, count($originals)),
                            ]);
                        } catch (\Throwable $ignored) {}
                    }

                    // Trace (dedicated log) — light summary
                    $this->trace_log('GET_REGULAR.IN', [
                        'target'   => $target_code,
                        'source'   => $source_code,
                        'post_id'  => $post_id,
                        'context'  => $context,
                        'url'      => $page_url,
                        'count'    => is_array($originals) ? count($originals) : 0,
                        'bk_count' => is_array($block_keys) ? count($block_keys) : 0,
                    ]);

                    $results = [];
                    $count_originals = count($originals);
                    $selector_pattern = '/(div#|span\.|option:nth-of-type|aria-label|wp-block|#yuz-|\.yuz-|data-yuz|>\s*#|>\s*\.[A-Za-z0-9_-]+)/i';
                    $MAX_ORIGINAL_LEN = 800; // guard against container dumps
                    $precomputed = [];
                    $pending_texts = [];
                    $pending_set = [];

                    for ($i = 0; $i < $count_originals; $i++) {
                        $original = $normalize_original_item($originals[$i] ?? '');
                        if ($original === '') {
                            continue;
                        }

                        $normalized_original = $this->normalize_newlines($original);
                        if ($normalized_original === '' || preg_match('/^\[object\s+object\]$/i', trim($normalized_original))) {
                            $this->trace_log('GET_REGULAR.SKIP_INVALID_ORIGINAL', [
                                'target'   => $target_code,
                                'source'   => $source_code,
                            ]);
                            continue;
                        }
                        $len = strlen($normalized_original);
                        $separator_score = substr_count($normalized_original, '•') + substr_count($normalized_original, '>');
                        if ($len > $MAX_ORIGINAL_LEN || $separator_score > 12 || preg_match($selector_pattern, $normalized_original)) {
                            $this->trace_log('GET_REGULAR.SKIP_LIKELY_SELECTOR', [
                                'len'      => $len,
                                'separators' => $separator_score,
                                'target'   => $target_code,
                            ]);
                            continue;
                        }
                        // Prefer a stable block_key from client when provided (forms/modals)
                        $bk = isset($block_keys[$i]) ? trim((string)$block_keys[$i]) : '';
                        if ($bk !== '') {
                            $block_id = substr(sha1($bk), 0, 40);
                        } else {
                            $block_id = function_exists('yuztra_generate_block_id')
                                ? yuztra_generate_block_id($post_id, $context, $normalized_original)
                                : substr(sha1($normalized_original), 0, 40);
                        }

                        $row = $wpdb->get_row(
                            $wpdb->prepare(
                                "SELECT id, translated_text, status FROM %i WHERE block_id = %s AND target_lang_id = %d AND source_lang_id = %d",
                                $trans_table,
                                $block_id,
                                $target_lang_id,
                                $source_lang_id
                            ),
                            ARRAY_A
                        );
                        // Back-compat: if nothing under block_key-based id, try legacy original-based id
                        if (!$row && $bk !== '') {
                            $legacy_id = function_exists('yuztra_generate_block_id')
                                ? yuztra_generate_block_id($post_id, $context, $normalized_original)
                                : substr(sha1($normalized_original), 0, 40);
                            $row = $wpdb->get_row(
                                $wpdb->prepare(
                                    "SELECT id, translated_text, status FROM %i WHERE block_id = %s AND target_lang_id = %d AND source_lang_id = %d",
                                    $trans_table,
                                    $legacy_id,
                                    $target_lang_id,
                                    $source_lang_id
                                ),
                                ARRAY_A
                            );
                            $this->trace_log('GET_REGULAR.LEGACY_FALLBACK', [
                                'bk'         => $bk,
                                'legacy_id'  => $legacy_id,
                                'resolved'   => (bool) $row,
                            ]);
                        }
                        if (!$row && $is_safe_placeholder_key($bk)) {
                            $row = $lookup_placeholder_by_original($normalized_original, $target_lang_id, $source_lang_id);
                            $this->trace_log('GET_REGULAR.ORIGINAL_FALLBACK', [
                                'bk'        => $bk,
                                'resolved'  => (bool) $row,
                            ]);
                        }

                        if (!$row) {
                            $this->trace_log('GET_REGULAR.EMPTY_ROW', [
                                'block_id'  => $block_id,
                                'block_key' => $bk,
                                'target'    => $target_code,
                            ]);
                        }

                        $skip_machine = array_key_exists($i, $skip_flags)
                            ? (bool) $skip_flags[$i]
                            : (
                                in_array($original, $skip_values, true)
                                || in_array($normalized_original, $skip_values, true)
                                || in_array($normalized_original, $normalized_skip, true)
                            );
                        $existing_text   = $row ? $this->normalize_newlines((string) ($row['translated_text'] ?? '')) : '';
                        $existing_status = $row ? (int) ($row['status'] ?? 0) : 0;
                        $should_attempt  = $can_auto_translate && !$skip_machine && $source_lang_id > 0;

                        $needs_translation = $should_attempt && (
                            !$row || ($existing_text === '' && $existing_status < 2) || ($existing_text !== '' && $normalize_plain($existing_text) === $normalize_plain($normalized_original))
                        );
                        if ($needs_translation && !isset($pending_set[$normalized_original])) {
                            $pending_set[$normalized_original] = true;
                            $pending_texts[] = $normalized_original;
                        }

                        $precomputed[] = [
                            'original'           => $original,
                            'normalized'         => $normalized_original,
                            'block_id'           => $block_id,
                            'block_key'          => $bk,
                            'row'                => $row,
                            'existing_text'      => $existing_text,
                            'existing_status'    => $existing_status,
                            'needs_translation'  => $needs_translation,
                        ];
                    }

                    // Batch call once for the wave
                    $translated_map = [];
                    if (!empty($pending_texts)) {
                        if ($logger) {
                            try { $logger->log('info', 'Auto translation batch', ['count' => count($pending_texts), 'target' => $target_code]); } catch (\Throwable $ignored) {}
                        }
                        $this->trace_log('AUTO_TRANSLATE.BATCH', [
                            'count'  => count($pending_texts),
                            'target' => $target_code,
                            'source' => $source_code,
                        ]);
                        // One attempt per text, no batch failure followed by another paid pass.
                        foreach ($pending_texts as $pt) {
                            $translated_map[$pt] = $this->translation_manager->translate($pt,$source_lang_id,$target_lang_id);
                            if (!is_string($translated_map[$pt]) || $translated_map[$pt]==='') throw new RuntimeException('empty_provider_translation');
                        }
                    }

                    $draft_status   = defined('YUZTRA_STATUS_DRAFT') ? YUZTRA_STATUS_DRAFT : 0;
                    $machine_status = defined('YUZTRA_STATUS_REVIEW') ? YUZTRA_STATUS_REVIEW : 1;

                    foreach ($precomputed as $entry) {
                        $original = $entry['original'];
                        $normalized_original = $entry['normalized'];
                        $block_id = $entry['block_id'];
                        $bk = $entry['block_key'];
                        $row = $entry['row'];
                        $existing_text = $entry['existing_text'];
                        $existing_status = $entry['existing_status'];
                        $needs_translation = $entry['needs_translation'];

                        $auto_translated = '';
                        if ($needs_translation) {
                            if ($logger) {
                                try {
                                    $logger->log('debug', 'Auto translation attempt', [
                                        'block_id' => $block_id,
                                        'target'   => $target_code,
                                        'has_row'  => (bool) $row,
                                    ]);
                                } catch (\Throwable $ignored) {}
                            }
                            $candidate = $translated_map[$normalized_original] ?? null;
                            if (is_string($candidate)) {
                                $candidate = $this->normalize_newlines($candidate);
                                $plain_candidate = $normalize_plain($candidate);
                                $plain_original  = $normalize_plain($normalized_original);
                                if ($logger) {
                                    try {
                                        $logger->log('debug', 'Auto translation candidate', [
                                            'block_id' => $block_id,
                                            'target'   => $target_code,
                                            'len'      => strlen($candidate),
                                        ]);
                                    } catch (\Throwable $ignored) {}
                                }
                                if ($candidate !== '' && $plain_candidate !== '' && $plain_candidate !== $plain_original) {
                                    $auto_translated = $candidate;
                                } else {
                                    $this->trace_log('AUTO_TRANSLATE.EMPTY_OR_SAME', [
                                        'block_id' => $block_id,
                                        'target'   => $target_code,
                                    ]);
                                }
                            }
                        }

                        if (!$row && $source_lang_id > 0) {
                            if ($auto_translated === '') {
                                $this->trace_log('AUTO_TRANSLATE.SKIP_EMPTY', [
                                    'block_id' => $block_id,
                                    'target'   => $target_code,
                                    'source'   => $source_code,
                                    'status'   => $draft_status,
                                ]);
                            } else {
                                $insert = [
                                    'post_id'         => $post_id,
                                    'context'         => $context,
                                    'block_id'        => $block_id,
                                    'original_text'   => $normalized_original,
                                    'translated_text' => $auto_translated,
                                    'translated_slug' => null,
                                    'source_lang_id'  => $source_lang_id,
                                    'target_lang_id'  => $target_lang_id,
                                    'language_code'   => $target_code,
                                    'status'          => $auto_translated === '' ? $draft_status : $machine_status,
                                    'origin'          => 'machine',
                                    'revision_of'     => null,
                                    'created_at'      => current_time('mysql'),
                                    'updated_at'      => current_time('mysql'),
                                ];
                                $formats = ['%d','%s','%s','%s','%s','%s','%d','%d','%s','%d','%s','%d','%s','%s'];
                                $inserted = $wpdb->insert($trans_table, $insert, $formats);
                                if ($inserted !== false) {
                                    $row = [
                                        'id' => (int) $wpdb->insert_id,
                                        'translated_text' => $auto_translated,
                                        'status' => $auto_translated === '' ? $draft_status : $machine_status,
                                    ];
                                    if ($auto_translated === '') {
                                        $this->trace_log('AUTO_TRANSLATE.STORED_EMPTY', [
                                            'block_id' => $block_id,
                                            'target'   => $target_code,
                                            'source'   => $source_code,
                                            'status'   => $row['status'],
                                        ]);
                                    }
                                } else {
                                    $row = $wpdb->get_row(
                                        $wpdb->prepare(
                                            "SELECT id, translated_text, status FROM %i WHERE block_id = %s AND target_lang_id = %d AND source_lang_id = %d",
                                            $trans_table,
                                            $block_id,
                                            $target_lang_id,
                                            $source_lang_id
                                        ),
                                        ARRAY_A
                                    );
                                }
                            }
                        } elseif ($row && $auto_translated !== '') {
                            $needs_update = false;
                            $current_plain = $normalize_plain($existing_text);
                            $original_plain = $normalize_plain($normalized_original);
                            if ($existing_text === '' || $existing_status < 2) {
                                $needs_update = ($current_plain === '' || $current_plain === $original_plain);
                            }
                            if ($needs_update) {
                                $updated = $wpdb->update(
                                    $trans_table,
                                    [
                                        'translated_text' => $auto_translated,
                                        'status'          => $machine_status,
                                        'origin'          => 'machine',
                                        'updated_at'      => current_time('mysql'),
                                    ],
                                    ['id' => (int) $row['id']],
                                    ['%s', '%d', '%s', '%s'],
                                    ['%d']
                                );
                                if ($updated !== false) {
                                    $row['translated_text'] = $auto_translated;
                                    $row['status'] = $machine_status;
                                    if ($logger) {
                                        try {
                                            $logger->log('success', 'Auto translation stored', [
                                                'block_id' => $block_id,
                                                'target'   => $target_code,
                                                'id'       => (int) $row['id'],
                                            ]);
                                        } catch (\Throwable $ignored) {}
                                    }
                                }
                            }
                        }

                        $translation_id = $row ? (int) ($row['id'] ?? 0) : 0;
                        $translated = $row ? (string) ($row['translated_text'] ?? '') : '';
                        $status     = $row ? (string) ($row['status'] ?? '0') : '0';
                        if ($translated === '') {
                            $this->trace_log('GET_REGULAR.EMPTY_TRANSLATED', [
                                'block_id'  => $block_id,
                                'block_key' => $bk,
                                'target'    => $target_code,
                                'source'    => $source_code,
                                'status'    => $status,
                                'id'        => $translation_id,
                            ]);
                        }
                        $this->trace_log('GET_REGULAR.ENTRY', [
                            'block_id'       => $block_id,
                            'block_key'      => $bk,
                            'target'         => $target_code,
                            'source'         => $source_code,
                            'translation_id' => $translation_id,
                            'status'         => $status,
                            'translated_len' => strlen($translated),
                        ]);
                        $results[] = [
                            'original' => $original,
                            'block_id' => $block_id,
                            'page_url' => $page_url,
                            'translation_id' => $translation_id,
                            'translations' => [
                                $target_code => [
                                    'translated' => $translated,
                                    'status'     => $status,
                                    'translation_id' => $translation_id,
                                ],
                            ],
                            'translationsArray' => [
                                $target_code => [
                                    'translated' => $translated,
                                    'status'     => $status,
                                    'translation_id' => $translation_id,
                                ],
                            ],
                        ];
                    }

                    if ($is_probe && $this->logger) {
                        try {
                            $translated = 0; $empty = 0;
                            foreach ($results as $r) {
                                $t = (string) ($r['translationsArray'][$target_code]['translated'] ?? '');
                                if ($t !== '') { $translated++; } else { $empty++; }
                            }
                            $this->logger->log('info', 'YUZ-PROBE summary (yuztra_get_regular)', [
                                'target'      => $target_code,
                                'total'       => count($results),
                                'translated'  => $translated,
                                'empty'       => $empty,
                            ]);
                        } catch (\Throwable $ignored) {}
                    }

                    if ($this->is_trace_request()) {
                        $invalid = 0;
                        $missing = 0;
                        foreach ($results as $entry) {
                            if (empty($entry['translationsArray']) || !is_array($entry['translationsArray'])) {
                                $invalid++;
                                continue;
                            }
                            if (empty($entry['translationsArray'][$target_code]) || ($entry['translationsArray'][$target_code]['translated'] ?? '') === '') {
                                $missing++;
                            }
                        }
                        $this->emit_trace_marker('YUZTRA_BACKEND_CHECK', [
                            'target'            => $target_code,
                            'rows'              => count($results),
                            'invalid_entries'   => $invalid,
                            'missing_translations' => $missing,
                        ]);
                    }

                    $rows = $results;
                    if (defined('YUZTRA_DEBUG_FRONT') && YUZTRA_DEBUG_FRONT) {
                        yuztra_debug_log('[YUZTRA_AJAX][yuztra_get_regular][OUT] ' . wp_json_encode([
                            'rows'        => is_array($rows ?? null) ? count($rows) : 0,
                            'success'     => !empty($rows),
                        ]));
                    }

                    $latency_ms = (microtime(true) - $t0) * 1000;
                    if ($logger) {
                        try {
                            $logger->log('info', 'GET_REGULAR.OUT', [
                                'cid'       => $cid,
                                'target'    => $target_code ?? null,
                                'source'    => $source_code ?? null,
                                'rows'      => is_array($rows) ? count($rows) : 0,
                                'latency_ms'=> round($latency_ms, 1),
                                'url'       => $page_url ?? '',
                                'count_req' => $count_originals ?? 0,
                            ]);
                        } catch (\Throwable $ignored) {}
                    }
                    $this->trace_log('GET_REGULAR.OUT', [
                        'cid'       => $cid,
                        'rows'      => is_array($rows) ? count($rows) : 0,
                        'latency_ms'=> round($latency_ms, 1),
                        'target'    => $target_code ?? null,
                        'source'    => $source_code ?? null,
                    ]);

                    return $results;
                }
            );
        }

        public function yuztra_js_upd_auto() {
            self::__handleRequest('yuztra_con_nonce',
                ['automatic_translation_settings'],
                function ($data) {
                    if (!current_user_can('manage_options')) {
                        wp_send_json_error(['message'=>'unauthorized','code'=>'unauthorized'],403);
                    }
                    $settings           = (array) $data['automatic_translation_settings'];
                    $sanitized_settings = $this->get_settings()->sanitize_option('yuztra_api_settings', $settings);
                    $result             = $this->get_settings()->update_option('yuztra_api_settings', $sanitized_settings);

                    if ($result === false && get_option('yuztra_api_settings') !== $sanitized_settings) {
                        throw new \Exception( esc_html( 'Failed to update API settings' ) );                    }

                    $this->translation_manager->set_api_settings([
                        'api_type'        => $sanitized_settings['api_provider'],
                        'endpoint'        => $sanitized_settings[$sanitized_settings['api_provider'] . '_url'] ?? $sanitized_settings['custom_url'],
                        'api_key'         => $sanitized_settings[$sanitized_settings['api_provider'] . '_key'] ?? $sanitized_settings['custom_key'],
                        'extra_settings'  => [
                            'alternatives'   => $sanitized_settings['alternatives'] ?? null,
                            'deepl_free'     => $sanitized_settings['deepl_free'] ?? null,
                            'google_project' => $sanitized_settings['google_project'] ?? null,
                            'custom_auth'    => $sanitized_settings['custom_auth']    ?? null,
                            'custom_method'  => $sanitized_settings['custom_method']  ?? null,
                            'custom_format'  => $sanitized_settings['custom_format']  ?? null,
                        ]
                    ]);

                    wp_clear_scheduled_hook('yuztra_batch_translate');
                    if (in_array($sanitized_settings['translation_mode'] ?? '', ['silent', 'all'], true)) {
                        wp_schedule_event(time(), $sanitized_settings['cron_interval'] ?? 'hourly', 'yuztra_batch_translate');
                        (new YUZTRA_Logger())->log('debug', 'Rescheduled WP-Cron event with interval: ' . ($sanitized_settings['cron_interval'] ?? 'hourly'));
                    }

                    return ['message' => __('Settings updated', 'yuz-tra'), 'settings' => $sanitized_settings];
                },
                true
            );
        }

        /** -------------------- GENERAL -------------------- */
        public function yuztra_delete_translation() {
            self::__handleRequest(
                'yuztra_del_nonce',
                ['translation_id'],
                function ($data) {
                    if (!current_user_can('manage_options')) {
                        wp_send_json_error(['message'=>'unauthorized','code'=>'unauthorized'],403);
                    }
                    global $wpdb;
                    $this->ensure_db_tables();

                    $trans_table = $wpdb->prefix . 'yuz_tra_translations';
                    if (!$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($trans_table)))) {
                        (new YUZTRA_Logger())->log('critical', "Translations table $trans_table missing after recreation attempt");
                        return [];
                    }

                    $translation_id = intval($data['translation_id']);

                    $result = $wpdb->delete($trans_table, ['id' => $translation_id], ['%d']);
                    if ($result === false) {
                        throw new \Exception( esc_html( "Failed to delete translation ID {$translation_id}: " . $wpdb->last_error ) );                    }

                    return ['message' => __('Translation deleted', 'yuz-tra')];
                },
                true
            );
        }

        public function yuztra_ws_remove_all() {
            self::__handleRequest('yuztra_nonce',
                [],
                function ($data) {
                    if (!current_user_can('manage_options')) {
                        wp_send_json_error(['message'=>'unauthorized','code'=>'unauthorized'],403);
                    }
                    global $wpdb;
                    $this->ensure_db_tables();

                    $table_name = $wpdb->prefix . 'yuz_tra_languages';
                    if (!$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table_name)))) {
                        (new YUZTRA_Logger())->log('critical', "Languages table $table_name missing after recreation attempt");
                        return [];
                    }

                    $res = $wpdb->query($wpdb->prepare(
                        'DELETE FROM %i WHERE is_translatable = 1 AND is_default = 0 AND is_source = 0',
                        $table_name
                    ));
                    if ($res === false) {
                        throw new \Exception( esc_html( "Failed to delete all languages: " . $wpdb->last_error ) );                    }

                    $options                               = $this->get_settings()->get_option('yuztra_general');
                    $options['yuztra_translatable_languages'] = [];
                    $this->get_settings()->update_option('yuztra_general', $options);

                    $this->invalidate_language_caches();
                    return $this->build_ws_payload();
                },
                true
            );
        }

        /** -------------------- ADVANCED (diagnostics / cache) -------------------- */
public function ajax_run_diagnostics(): void {
    $this->handleRequest(
        'yuztra_con_nonce',
        [], // pas de champs obligatoires
        function(array $data) {
            if (!current_user_can('manage_options')) {
                return ['success' => false, 'error' => ['code' => 403, 'message' => 'Insufficient permissions'], 'timestamp' => current_time('mysql')];
            }
            if (!class_exists('YUZTRA_Diagnostic')) {
                return ['success' => false, 'error' => ['code' => 'diag_unavailable', 'message' => __('Diagnostics module not available.', 'yuz-tra')], 'timestamp' => current_time('mysql')];
            }
            try {
                $diagnostic = new \YUZTRA_Diagnostic();
                $cache  = $diagnostic->run_diagnostic(plugin_dir_path(YUZTRA_PLUGIN_FILE));
                $report = $diagnostic->generate_report(
                    $cache['files'] ?? [],
                    $cache['db_tables'] ?? [],
                    $cache['settings_issues'] ?? [],
                    $cache['issues'] ?? [],
                    $cache['dependencies'] ?? []
                );
                $cache['report'] = $report;
                update_option('yuztra_diagnostic_cache', $cache);
                return ['success' => true, 'report' => $report, 'timestamp' => current_time('mysql')];
            } catch (\Throwable $e) {
                return ['success' => false, 'error' => ['code' => 'diag_failed', 'message' => __('Diagnostics failed.', 'yuz-tra')], 'timestamp' => current_time('mysql')];
            }
        },
        []
    );
}

public function ajax_clear_cache(): void {
    $this->handleRequest(
        'yuztra_con_nonce',
        [],
        function(array $data) {
            if (!current_user_can('manage_options')) {
                return ['success' => false, 'error' => ['code' => 403, 'message' => 'Insufficient permissions'], 'timestamp' => current_time('mysql')];
            }
            wp_cache_flush();
            global $wpdb;
            $wpdb->query($wpdb->prepare("DELETE FROM %i WHERE option_name LIKE %s", $wpdb->prefix . 'options', '_transient_%'));
            return ['success' => true, 'message' => __('Cache cleared successfully', 'yuz-tra'), 'timestamp' => current_time('mysql')];
        },
        []
    );
}


public function yuztra_diag_chain() {
    $received = [];
    foreach (['yuztra_nonce','nonce','security'] as $key) {
        if (isset($_POST[$key])) {
            $received[$key] = sanitize_text_field(wp_unslash($_POST[$key]));
        }
    }
    // Une seule action de nonce est acceptee : pas de condition alternative contournable.
    $verified = false;
    foreach (['yuztra_nonce', 'nonce', 'security'] as $nonce_field) {
        if (check_ajax_referer('yuztra_nonce', $nonce_field, false)) {
            $verified = true;
            break;
        }
    }
    if (!$verified) {
        wp_send_json_error(['message'=>'invalid_nonce','code'=>'invalid_nonce'],403);
    }

    if (!current_user_can('manage_options')) {
        wp_send_json_error([
            'message' => 'Insufficient permissions',
            'code'    => 403,
        ], 403);
    }

    global $wpdb;
    $table = $wpdb->prefix . 'yuz_tra_languages';
    $rows  = $wpdb->get_results($wpdb->prepare(
        'SELECT language_code, is_default, is_source, is_translatable FROM %i',
        $table
    ), ARRAY_A);
    $rows  = is_array($rows) ? $rows : [];

    $counts = [
        'total'        => count($rows),
        'translatable' => count(array_filter($rows, fn($row) => !empty($row['is_translatable']))),
        'default'      => count(array_filter($rows, fn($row) => !empty($row['is_default']))),
        'source'       => count(array_filter($rows, fn($row) => !empty($row['is_source']))),
    ];
    $sample = array_slice($rows, 0, 10);

    $opt_general = get_option('yuztra_general', []);
    $opt_mirror  = get_option('yuztra_languages', []);

    $localization_preview = [
        'general' => [
            'yuztra_default_language'       => $opt_general['yuztra_default_language'] ?? null,
            'yuztra_source_language'        => $opt_general['yuztra_source_language'] ?? null,
            'yuztra_translatable_languages' => $opt_general['yuztra_translatable_languages'] ?? [],
        ],
    ];

    $payload = [
        'server' => [
            'uri'     => sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'] ?? '')),
            'user'    => [
                'id'        => get_current_user_id(),
                'logged_in' => is_user_logged_in(),
            ],
        ],
        'received' => [
            'keys'      => array_keys($received),
            'nonce_sig' => $nonce ? substr(md5($nonce), 0, 8) : null,
        ],
        'verify' => [
            'nonce_action' => 'yuztra_nonce',
            'verified'     => $verified ? 1 : 0,
        ],
        'db' => [
            'counts' => $counts,
            'sample' => $sample,
        ],
        'options' => [
            'general'                 => $opt_general,
            'mirror_languages_option' => is_array($opt_mirror) ? count($opt_mirror) : 'absent',
        ],
        'localization_preview' => $localization_preview,
    ];

    wp_send_json_success($payload);
}






        /** -------------------- BATCH (real cron worker) -------------------- */
        public function handle_ajax_batch() {
            self::__handleRequest('yuztra_hvy_nonce',
                [],
                function ($data) {
                    if (!current_user_can('manage_options')) {
                        wp_send_json_error(['message'=>'unauthorized','code'=>'unauthorized'],403);
                    }
                    if (!class_exists('YUZTRA_Cron')) {
                        self::send_json_error(['message'=>'translation_worker_unavailable'], 503);
                    }
                    $report = YUZTRA_Cron::process(min(10, max(1, (int)($data['limit'] ?? 3))), 2);
                    if (!empty($report['failed']) || !empty($report['reason'])) {
                        self::send_json_error(['message'=>'batch_incomplete', 'report'=>$report], 503);
                    }
                    return ['ok'=>true, 'report'=>$report, 'state'=>empty($report['processed']) ? 'idle' : 'completed'];
                },
                true
            );
        }

        /** -------------------- PREFLIGHT -------------------- */
public static function ajax_preflight() {
    self::ensure_req_id();
    // Vérif nonce tolérante (nonce | _ajax_nonce | security), clé attendue 'yuz_hvy_nonce'
    $ok = false;
    foreach (['nonce', '_ajax_nonce', 'security'] as $nonce_field) {
        if (!is_string($_REQUEST[$nonce_field] ?? null)) continue;
        if (check_ajax_referer('yuztra_hvy_nonce', $nonce_field, false)) {
            $ok = true;
            break;
        }
    }
    if (!$ok) {
        wp_send_json_error(['message' => 'invalid_nonce', 'expected' => 'yuztra_hvy_nonce'], 403);
        return;
    }
    if (!current_user_can('manage_options') && !current_user_can('yuztra_translate_content')) {
        wp_send_json_error(['message'=>'forbidden','code'=>'forbidden'],403);
    }

    $mode = is_string($_POST['mode'] ?? null) ? sanitize_text_field(wp_unslash($_POST['mode'])) : null;
    $r    = (class_exists('YUZTRA_Services') && method_exists('YUZTRA_Services','preflight'))
        ? YUZTRA_Services::preflight($mode)
        : ['ok' => false, 'missing' => ['services']];

    self::send_json_success($r);
}

/**
 * YUZ: save translation
 */
public function yuztra_save_translation() {
    check_ajax_referer('yuztra_nonce', 'nonce');
    if (!current_user_can('manage_options') && !current_user_can('yuztra_translate_content')) {
        wp_send_json_error(['message'=>'Unauthorized','code'=>'forbidden'],403);
    }
    $actor_id = function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
    $remote_fingerprint = isset($_SERVER['REMOTE_ADDR']) ? md5(sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']))) : (string) wp_rand();
    $actor_suffix = $actor_id > 0 ? 'user_' . $actor_id : 'ip_' . substr($remote_fingerprint, 0, 12);
    $busy_key   = 'yuztra_te_busy_' . $actor_suffix;
    $max_slots  = defined('YUZTRA_SAVE_MAX_PARALLEL') ? max(1, (int) YUZTRA_SAVE_MAX_PARALLEL) : 4;
    // Entrée — trace rapide
    $trace_id = '';
    try {
        $logger = $this->logger ?: (class_exists('YUZTRA_Logger') ? new \YUZTRA_Logger() : new \YUZTRA\Fallbacks\NullLogger());
        $trace_id  = isset($_REQUEST['yuztra_trace']) ? sanitize_text_field(wp_unslash((string) $_REQUEST['yuztra_trace'])) : '';
        $logger->log('info', '[TE][IN] save', [
            'trace' => $trace_id,
            'keys'  => array_map('sanitize_key', array_keys((array) $_REQUEST)),
        ]);
    } catch (\Throwable $e) {}
    $active_slots = (int) get_transient($busy_key);
    if ($active_slots >= $max_slots) {
        self::send_json_error(['code' => 429, 'message' => 'Too many requests, try again in 30s'], 429);
    }
    set_transient($busy_key, $active_slots + 1, 30);

    $release = static function () use ($busy_key) {
        $current = get_transient($busy_key);
        if ($current === false) {
            return;
        }
        $remaining = (int) $current - 1;
        if ($remaining <= 0) {
            delete_transient($busy_key);
            return;
        }
        set_transient($busy_key, $remaining, 30);
    };

    $translation_id = 0;
    $target_langs   = [];
    $target_code    = '';
    $context        = '';
    $post_id        = 0;
    $page_url       = '';
    $block_id       = '';

    $logger = $this->logger ?: (class_exists('YUZTRA_Logger') ? new \YUZTRA_Logger() : new \YUZTRA\Fallbacks\NullLogger());
    $req_id = self::ensure_req_id();
    $raw_mode = isset($_POST['mode']) ? sanitize_key(wp_unslash((string) $_POST['mode'])) : '';
    $allowed_modes = ['auto', 'semi', 'manual'];
    $mode = in_array($raw_mode, $allowed_modes, true) ? $raw_mode : 'manual';
    $string_id_input = isset($_POST['string_id']) ? absint(wp_unslash($_POST['string_id'])) : 0;
    $translation_id = 0;
    foreach (['translation_id', 'translationId', 'id', 'ID'] as $maybe_id_key) {
        if (isset($_POST[$maybe_id_key])) {
            $translation_id = absint(wp_unslash($_POST[$maybe_id_key]));
            if ($translation_id > 0) {
                break;
            }
        }
    }
    if ($translation_id <= 0) {
        $translation_id = $string_id_input;
    }
    $this->log_ajax_entry('yuztra_save_translation', [
        'string_id'       => $string_id_input,
        'target_lang_raw' => isset($_POST['target_lang']) ? sanitize_text_field(wp_unslash((string) $_POST['target_lang'])) : (isset($_POST['language_code']) ? sanitize_text_field(wp_unslash((string) $_POST['language_code'])) : ''),
        'post_id'         => isset($_POST['post_id']) ? absint(wp_unslash($_POST['post_id'])) : 0,
        'context'         => isset($_POST['context']) ? sanitize_text_field(wp_unslash((string) $_POST['context'])) : '',
        'has_original'    => isset($_POST['original_text']) && $_POST['original_text'] !== '',
        'has_translated'  => isset($_POST['translated_text']) && $_POST['translated_text'] !== '',
        'mode'            => $mode,
        'req_id'          => $req_id,
        'trace_id'        => $trace_id,
    ]);

    try {
    global $wpdb;
    $table      = $wpdb->prefix . 'yuz_tra_translations';
    $lang_table = $wpdb->prefix . 'yuz_tra_languages';
    $like       = str_replace(['_', '%'], ['\\_', '\\%'], $table);
    // Vérif table
    if (!$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $like))) {
        $logger->log('critical', "Translations table {$table} does not exist");
        $release();
        self::send_json_error(['message' => 'Translations table missing'], 500);
    }
    $has_string_id_column = $this->translation_table_has_column($table, 'string_id');

    $collect_targets = static function ($value) use (&$collect_targets) {
        $collected = [];
        if ($value === null || $value === '') {
            return $collected;
        }
        if (is_array($value)) {
            foreach ($value as $entry) {
                $collected = array_merge($collected, $collect_targets($entry));
            }
            return $collected;
        }
        $sanitized = sanitize_text_field(wp_unslash((string) $value));
        if ($sanitized === '') {
            return $collected;
        }
        if (strpos($sanitized, ',') !== false) {
            $parts = array_filter(array_map('trim', explode(',', $sanitized)));
            foreach ($parts as $part) {
                $collected = array_merge($collected, $collect_targets($part));
            }
            return $collected;
        }
        $collected[] = $sanitized;
        return $collected;
    };

    $target_langs = [];
    if (isset($_POST['target_langs'])) {
        // JSON must be decoded before sanitization; sanitizing the JSON envelope
        // can corrupt Unicode and punctuation. collect_targets() validates and
        // sanitizes each resulting scalar locale below.
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON is decoded before contextual scalar sanitization in collect_targets().
        $target_langs = self::validated_target_languages(wp_unslash($_POST['target_langs']));
    }
    if (!$target_langs && isset($_POST['target_lang'])) {
        $target_langs = array_merge($target_langs, $collect_targets(sanitize_text_field(wp_unslash((string) $_POST['target_lang']))));
    }
    if (!$target_langs && isset($_POST['to'])) {
        $target_langs = array_merge($target_langs, $collect_targets(sanitize_text_field(wp_unslash((string) $_POST['to']))));
    }
    if (!$target_langs && isset($_POST['language_code'])) {
        $target_langs = array_merge($target_langs, $collect_targets(sanitize_text_field(wp_unslash((string) $_POST['language_code']))));
    }

    $normalize_locale = function (string $locale): string {
        $locale = trim($locale);
        if ($locale === '') {
            return '';
        }
        $locale = str_replace('-', '_', $locale);
        $segments = array_values(array_filter(explode('_', $locale)));
        if (!$segments) {
            return '';
        }
        $language = strtolower(array_shift($segments));
        if (!$segments) {
            return $language === 'auto' ? '' : $language;
        }
        $segments = array_map(static function ($segment) {
            return strtoupper($segment);
        }, $segments);
        $normalized = $language . '_' . implode('_', $segments);
        return strtolower($normalized) === 'auto' ? '' : $normalized;
    };

    if (function_exists('yuztra_normalize_locale')) {
        $target_langs = array_map('yuztra_normalize_locale', $target_langs);
    } else {
        $target_langs = array_map($normalize_locale, $target_langs);
    }
    $target_langs = array_values(array_unique(array_filter($target_langs, static function ($value) {
        return $value !== '' && strtolower($value) !== 'auto';
    })));

    if (!$target_langs) {
        $release();
        self::send_json_error(['message' => 'Missing parameter: target_langs', 'code' => 'missing_param', 'param' => 'target_langs'], 400);
    }

    $target_code = $target_langs[0];
    $_POST['target_langs'] = $target_langs;
    $_POST['target_lang'] = $target_code;
    $_POST['language_code'] = $target_code;
    $page_url       = esc_url_raw(wp_unslash($_POST['page_url'] ?? ''));
    $context        = sanitize_text_field(wp_unslash($_POST['context'] ?? '')) ?: 'content';
        $block_id       = sanitize_text_field(wp_unslash($_POST['block_id'] ?? ''));
    $post_id        = isset($_POST['post_id']) ? absint(wp_unslash($_POST['post_id'])) : 0;
    // Resolve post_id from page_url when missing, to avoid post_id=0 rows
    if ($post_id === 0 && $page_url !== '') {
        $resolved = $this->resolve_post_id_from_page_url($page_url);
        if ($resolved > 0) {
            $post_id = $resolved;
        }
    }
    $origin         = isset($_POST['origin']) ? sanitize_key(wp_unslash((string) $_POST['origin'])) : 'manual';
    if ($origin === 'auto') {
        $origin = 'machine';
    }
    $allowed_origins = ['manual','machine','dock','gettext','dom'];
    if (!in_array($origin, $allowed_origins, true)) {
        $origin = 'manual';
    }
    $is_batch = $this->read_bool_flag($_POST, ['is_batch','isBatch','batch','is_batch_operation']);

    $review_status    = defined('YUZTRA_STATUS_IN_REVIEW') ? (int) YUZTRA_STATUS_IN_REVIEW : (defined('YUZTRA_STATUS_REVIEW') ? (int) YUZTRA_STATUS_REVIEW : 2);
    $published_status = defined('YUZTRA_STATUS_PUBLISHED') ? (int) YUZTRA_STATUS_PUBLISHED : 4;
    $archived_status  = defined('YUZTRA_STATUS_ARCHIVED') ? (int) YUZTRA_STATUS_ARCHIVED : 5;
    $draft_status     = defined('YUZTRA_STATUS_DRAFT') ? (int) YUZTRA_STATUS_DRAFT : 1;
    $manual_publish_direct = defined('YUZTRA_MANUAL_MODE_PUBLISH_DIRECT') ? (bool) YUZTRA_MANUAL_MODE_PUBLISH_DIRECT : false;
    $mode_default_status = $draft_status;
    if ($mode === 'manual' && $manual_publish_direct) {
        $mode_default_status = $published_status;
    } elseif ($mode === 'semi' || $mode === 'semi_auto') {
        $mode_default_status = $review_status;
    } elseif ($mode === 'auto') {
        $mode_default_status = $published_status;
    }

    if (function_exists('yuztra_status_sanitize')) {
        $status = yuztra_status_sanitize(isset($_POST['status']) ? sanitize_text_field(wp_unslash((string) $_POST['status'])) : null, $mode_default_status);
    } else {
        $status_input   = isset($_POST['status']) ? (int) sanitize_text_field(wp_unslash((string) $_POST['status'])) : null;
    if ($status_input === null) {
        $status = $mode_default_status;
    } else {
        if ($status_input < $draft_status) {
            $status_input = $draft_status;
            } elseif ($status_input > $archived_status) {
                $status_input = $archived_status;
            }
            $status = $status_input;
        }
    }
    if (function_exists('yuztra_status_for_origin')) {
        $status = yuztra_status_for_origin($origin, $status, $is_batch, $mode);
    } elseif ($mode === 'auto') {
        $status = defined('YUZTRA_STATUS_PUBLISHED') ? YUZTRA_STATUS_PUBLISHED : 4;
    } elseif ($origin === 'machine' || $origin === 'dom' || $is_batch || $mode === 'semi' || $mode === 'semi_auto') {
        $status = defined('YUZTRA_STATUS_REVIEW') ? YUZTRA_STATUS_REVIEW : 2;
    }
    // Ensure machine saves tied to a resolved post are at least review (not draft)
    if ($origin === 'machine' && $post_id > 0 && $status < $review_status) {
        $status = $review_status;
    }
    try {
        $logger->log('info', '[TE][SAVE] status_resolved', [
            'mode'      => $mode,
            'origin'    => $origin,
            'is_batch'  => $is_batch,
            'status'    => $status,
            'post_id'   => $post_id,
            'lang'      => $target_code,
            'context'   => $context,
            'trace_id'  => $trace_id,
            'req_id'    => $req_id,
            'string_id' => $string_id_input,
        ]);
    } catch (\Throwable $ignored) {}

    // Trace données normalisées utiles au diagnostic
    try {
        $logger->log('debug', '[TE][SAVE] normalized', [
            'target' => $target_code,
            'post_id' => $post_id,
            'context' => $context,
            'block_id'=> $block_id,
            'page_url'=> $page_url,
            'has_original' => isset($_POST['original_text']) && $_POST['original_text'] !== '',
        ]);
    } catch (\Throwable $e) {}

    $original_text   = isset($_POST['original_text']) ? wp_kses_post(wp_unslash($_POST['original_text'])) : '';
    $translated_text = isset($_POST['translated_text']) ? wp_kses_post(wp_unslash($_POST['translated_text'])) : '';
    // Normalize non-string originals to avoid “[object Object]” garbage rows
    if (!is_string($original_text)) {
        $original_text = is_scalar($original_text) ? (string) $original_text : wp_json_encode($original_text, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    if (trim($original_text) === '[object Object]') {
        $original_text = '';
    }
    if ($post_id === 0) {
        try {
            $logger->log('warning', '[TE][SAVE] post_id_zero', [
                'page_url'  => $page_url,
                'context'   => $context,
                'block_id'  => $block_id,
                'target'    => $target_code,
                'origin'    => $origin,
                'mode'      => $mode,
                'trace_id'  => $trace_id,
                'req_id'    => $req_id,
                'has_url'   => $page_url !== '',
            ]);
        } catch (\Throwable $ignored) {}
        try {
            $this->trace_log('TE.POST_ID_ZERO', [
                'url'      => $page_url,
                'context'  => $context,
                'block_id' => $block_id,
                'target'   => $target_code,
                'origin'   => $origin,
                'mode'     => $mode,
                'trace_id' => $trace_id,
                'req_id'   => $req_id,
            ]);
        } catch (\Throwable $ignored) {}
    }

    // Slug handling: only persist/derive slug in explicit slug contexts
    $translated_slug = '';
    $is_slug_context = false;
    try {
        $flag = isset($_POST['is_slug']) ? filter_var(wp_unslash($_POST['is_slug']), FILTER_VALIDATE_BOOLEAN) : false;
        $is_slug_context = $flag || (stripos($context, 'slug') !== false) || (stripos($context, 'permalink') !== false) || (stripos($context, 'seo') !== false);
    } catch (\Throwable $e) {
        $is_slug_context = false;
    }

    $has_slug_column = $this->translation_table_has_column($table, 'translated_slug');
    if ($is_slug_context && $has_slug_column) {
        if (isset($_POST['translated_slug'])) {
            $translated_slug = sanitize_title(wp_unslash((string) $_POST['translated_slug']));
        }
        if ($translated_slug === '' && isset($_POST['slug'])) {
            $translated_slug = sanitize_title(wp_unslash((string) $_POST['slug']));
        }
        if ($translated_slug === '' && $translated_text !== '') {
            $translated_slug = sanitize_title($translated_text);
        }
        if ($translated_slug === '') {
            $translated_slug = 'translation-' . (function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : uniqid('yuztra_', true));
        }

        $base_slug      = $translated_slug;
        $candidate_slug = $base_slug;
        $suffix         = 2;
        $exclude_id     = $translation_id > 0 ? (int) $translation_id : 0;
        while ($this->translation_slug_exists($table, $target_code, $candidate_slug, $exclude_id)) {
            $candidate_slug = $base_slug . '-' . $suffix;
            $suffix++;
        }
        $translated_slug = $candidate_slug;
    } else {
        $translated_slug = '';
    }
    // YUZ: NORMALIZE NEWLINES (sauvegarde éditeur)
    $original_text   = $this->normalize_newlines($original_text);
    $translated_text = $this->normalize_newlines($translated_text);

    try {
        $logger->log('info', '[TE][SAVE] payload_summary', [
            'translation_id' => $translation_id,
            'string_id'      => $string_id_input,
            'target_langs'   => $target_langs,
            'target_code'    => $target_code,
            'post_id'        => $post_id,
            'context'        => $context,
            'origin'         => $origin,
            'mode'           => $mode,
            'original_len'   => strlen($original_text),
            'translated_len' => strlen($translated_text),
            'trace_id'       => $trace_id,
            'req_id'         => $req_id,
        ]);
    } catch (\Throwable $e) {}

    // Defensive guard: cap translated_text to avoid DB TEXT overflow (65k bytes).
    $max_bytes = defined('YUZTRA_MAX_TRANSLATED_BYTES') ? (int) YUZTRA_MAX_TRANSLATED_BYTES : 64000;
    $len_bytes = strlen($translated_text);
    if ($max_bytes > 0 && $len_bytes > $max_bytes) {
        $truncated = function_exists('mb_strcut')
            ? mb_strcut($translated_text, 0, $max_bytes, 'UTF-8')
            : substr($translated_text, 0, $max_bytes);
        try {
            $logger->log('warning', 'yuztra_save_translation: translated_text truncated', [
                'req_id'        => $req_id,
                'trace_id'      => $trace_id,
                'bytes_before'  => $len_bytes,
                'bytes_after'   => strlen($truncated),
                'limit_bytes'   => $max_bytes,
                'translation_id'=> $translation_id,
                'string_id'     => $string_id_input,
                'context'       => $context,
                'target'        => $target_code,
            ]);
        } catch (\Throwable $ignored) {}
        if (function_exists('yuztra_debug_probe_log')) {
            yuztra_debug_probe_log('editor_ajax_save_truncated', [
                'req_id'      => $req_id,
                'trace_id'    => $trace_id,
                'before'      => $len_bytes,
                'after'       => strlen($truncated),
                'limit_bytes' => $max_bytes,
            ]);
        }
        $translated_text = $truncated;
    }

    if (function_exists('yuztra_debug_probe_log')) {
        yuztra_debug_probe_log('editor_ajax_save_requested', [
            'string_id'   => $translation_id,
            'target_lang' => $target_code,
            'target_langs'=> $target_langs,
            'post_id'     => $post_id,
            'context'     => $context,
            'has_text'    => [
                'original'   => $original_text !== '',
                'translated' => $translated_text !== '',
            ],
        ]);
    }

    if ($target_code === '' || $translated_text === '') {
        $release();
        self::send_json_error(['message' => 'Missing required translation data', 'code' => 'invalid_payload'], 400);
    }

    $target_language = $this->language_manager->get_by_code($target_code);
    if (!$target_language || !method_exists($target_language, 'getId')) {
        $release();
        self::send_json_error(['message' => 'Unknown target language', 'code' => 'invalid_language'], 400);
    }

    $source_code = method_exists($this->language_manager, 'get_effective_source_code')
        ? $this->language_manager->get_effective_source_code()
        : $this->language_manager->get_source_language();

    $requested_source = $this->normalize_language_code(sanitize_text_field(wp_unslash($_POST['source_lang'] ?? $_POST['source_lang_code'] ?? '')));
    if ($requested_source !== '') {
        $source_code = $requested_source;
    }

    $source_language = $source_code ? $this->language_manager->get_by_code($source_code) : null;

    $existing = null;
    if ($translation_id > 0) {
        $existing = $wpdb->get_row(
            $wpdb->prepare('SELECT * FROM %i WHERE id = %d', $table, $translation_id),
            ARRAY_A
        );
        if (!$existing) {
            $logger->log('warning', "yuztra_save_translation: translation {$translation_id} not found, falling back to insert");
            $translation_id = 0;
        }
    }

    if (!$post_id && $existing && isset($existing['post_id'])) {
        $post_id = (int) $existing['post_id'];
    }
    if (!$post_id && $page_url) {
        $post_id = url_to_postid($page_url);
    }

    $target_lang_id = (int) $target_language->getId();
    $source_lang_id = ($source_language && method_exists($source_language, 'getId')) ? (int) $source_language->getId() : 0;

    if ($source_lang_id <= 0 && $source_code) {
        $source_lang_id = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM %i WHERE language_code = %s OR locale = %s",
                $lang_table,
                $source_code,
                $source_code
            )
        );
    }

    if ($source_lang_id <= 0) {
        $logger->log('warning', 'yuztra_save_translation: unable to resolve source language id', ['source_code' => $source_code]);
        $release();
        self::send_json_error(['message' => 'Missing source language reference', 'code' => 'invalid_language_source'], 400);
    }

    if ($block_id === '' && $original_text !== '') {
        $block_id = function_exists('yuztra_generate_block_id')
            ? yuztra_generate_block_id($post_id, $context, $original_text)
            : substr(sha1($original_text), 0, 40);
        $block_id = sanitize_text_field($block_id);
    }

    // ------------------------------------------------------------------
    // 1) Résolution de l'identifiant initial
    // ------------------------------------------------------------------
    // ------------------------------------------------------------------
    // 2) Normalisation du block_id
    // ------------------------------------------------------------------
    $normalized_original = trim((string) $original_text);
    if ($block_id === '' && $normalized_original !== '') {
        if (function_exists('yuztra_build_block_id')) {
            $block_id = (string) yuztra_build_block_id($normalized_original, $context, $post_id, $target_code);
        } else {
            $block_id = substr(
                sha1(
                    $post_id . '|' .
                    $context . '|' .
                    $target_code . '|' .
                    $normalized_original
                ),
                0,
                32
            );
        }
    }

    // ------------------------------------------------------------------
    // 3) Recherche par ID brut
    // ------------------------------------------------------------------
    $existing        = null;
    $existing_by_id  = null;
    $existing_by_key = null;
    if ($translation_id > 0) {
        $existing_by_id = $wpdb->get_row(
            $wpdb->prepare('SELECT * FROM %i WHERE id = %d', $table, $translation_id),
            ARRAY_A
        );
    }
    if ($existing_by_id) {
        $same_post    = (int) ($existing_by_id['post_id'] ?? 0) === (int) $post_id;
        $same_context = (string) ($existing_by_id['context'] ?? '') === (string) $context;
        $same_target  = (int) ($existing_by_id['target_lang_id'] ?? 0) === (int) $target_lang_id;
        $same_block   = (string) ($existing_by_id['block_id'] ?? '') === (string) $block_id;
        if ($same_post && $same_context && $same_target && $same_block) {
            $existing = $existing_by_id;
        } else {
            try {
                $logger->log('warning', '[TE][SAVE] string_id mismatch, falling back to logical key', [
                    'input_string_id'    => $translation_id,
                    'row_id'             => (int) ($existing_by_id['id'] ?? 0),
                    'row_post_id'        => (int) ($existing_by_id['post_id'] ?? 0),
                    'row_context'        => (string) ($existing_by_id['context'] ?? ''),
                    'row_target_lang_id' => (int) ($existing_by_id['target_lang_id'] ?? 0),
                    'row_block_id'       => (string) ($existing_by_id['block_id'] ?? ''),
                    'post_id'            => (int) $post_id,
                    'context'            => (string) $context,
                    'target_lang_id'     => (int) $target_lang_id,
                    'block_id'           => (string) $block_id,
                ]);
            } catch (\Throwable $ignored) {}
            $translation_id = 0;
            $existing_by_id = null;
        }
    }

    // ------------------------------------------------------------------
    // 4) Recherche par clé logique
    // ------------------------------------------------------------------
    if (!$existing && $has_string_id_column) {
        $existing_by_key = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM %i
                 WHERE post_id = %d
                   AND language_code = %s
                   AND context = %s
                   AND (
                        (%d > 0 AND string_id = %d)
                     OR (%d = 0 AND string_id = 0 AND original_text = %s)
                   )
                 ORDER BY id ASC
                 LIMIT 1",
                $table,
                $post_id,
                $target_code,
                $context,
                $string_id_input,
                $string_id_input,
                $string_id_input,
                $original_text
            ),
            ARRAY_A
        );
    }
    if (!$existing && !$has_string_id_column) {
        $existing_by_key = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM %i
                 WHERE post_id = %d
                   AND language_code = %s
                   AND context = %s
                   AND (
                        (%d > 0 AND id = %d)
                     OR (%d = 0 AND original_text = %s)
                   )
                 ORDER BY id ASC
                 LIMIT 1",
                $table,
                $post_id,
                $target_code,
                $context,
                $translation_id,
                $translation_id,
                $translation_id,
                $original_text
            ),
            ARRAY_A
        );
    }
    if (!$existing && !$existing_by_key && $block_id !== '') {
        $existing_by_key = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM %i
                 WHERE post_id = %d AND context = %s AND target_lang_id = %d AND block_id = %s
                 LIMIT 1",
                $table,
                $post_id,
                $context,
                $target_lang_id,
                $block_id
            ),
            ARRAY_A
        );
    }
    if ($existing_by_key) {
        $existing       = $existing_by_key;
        $translation_id = (int) $existing_by_key['id'];
    }

    // A target/block match alone must never overwrite a different source pair.
    if ($existing && (int) ($existing['source_lang_id'] ?? 0) !== $source_lang_id) {
        $release();
        self::send_json_error(['message' => 'Translation belongs to another source language.', 'code' => 'source_language_conflict'], 409);
    }

    // ------------------------------------------------------------------
    // 5) Trace diagnostic avant upsert
    // ------------------------------------------------------------------
    try {
        $logger->log('info', '[TE][SAVE] resolved translation target', [
            'origin'             => $origin,
            'status'             => $status,
            'post_id'            => (int) $post_id,
            'context'            => (string) $context,
            'target_code'        => (string) $target_code,
            'target_lang_id'     => (int) $target_lang_id,
            'block_id'           => (string) $block_id,
            'string_id_input'    => $string_id_input,
            'translation_id_used'=> (int) $translation_id,
            'existing_by_id'     => $existing_by_id ? (int) ($existing_by_id['id'] ?? 0) : null,
            'existing_by_key'    => $existing_by_key ? (int) ($existing_by_key['id'] ?? 0) : null,
            'has_original'       => $normalized_original !== '',
            'has_translated'     => $translated_text !== '',
            'mode'               => $mode,
        ]);
    } catch (\Throwable $ignored) {}

    if ($original_text === '' && $existing && isset($existing['original_text'])) {
        $original_text = $this->normalize_newlines((string) $existing['original_text']); // YUZ: normalized
    }

    $missing_fields = [];
    if ($target_code === '') {
        $missing_fields[] = 'language';
    }
    if ($original_text === '') {
        $missing_fields[] = 'original_text';
    }
    if ($post_id <= 0 && $translation_id <= 0) {
        $missing_fields[] = 'post_id';
    }
    if ($missing_fields) {
        $logger->log('warning', 'yuztra_save_translation: missing required fields', [
            'missing'   => $missing_fields,
            'post_id'   => $post_id,
            'lang'      => $target_code,
            'context'   => $context,
            'mode'      => $mode,
            'origin'    => $origin,
            'trace_id'  => $trace_id,
            'req_id'    => $req_id,
            'string_id' => $string_id_input,
            'translation_id' => $translation_id,
        ]);
        $release();
        self::send_json_error(['message' => 'Missing required fields', 'code' => 'missing_required_fields'], 400);
    }

    $now = current_time('mysql');

    $update_context = [
        'wpdb'            => $wpdb,
        'table'           => $table,
        'translated_text' => $translated_text,
        'original_text'   => $original_text,
        'target_lang_id'  => $target_lang_id,
        'target_code'     => $target_code,
        'status'          => $status,
        'source_lang_id'  => $source_lang_id,
        'post_id'         => $post_id,
        'context'         => $context,
        'block_id'        => $block_id,
        'page_url'        => $page_url,
        'now'             => $now,
        'release'         => $release,
        'logger'          => $logger,
        'translated_slug' => $translated_slug,
        'has_slug_column' => $has_slug_column,
        'origin'          => $origin,
        'mode'            => $mode,
        'trace_id'        => $trace_id,
        'req_id'          => $req_id,
        'string_id_input' => $string_id_input,
        'has_string_id_column' => $has_string_id_column,
    ];
    $updateExisting = function (array $row) use ($update_context) {
        $wpdb            = $update_context['wpdb'];
        $table           = $update_context['table'];
        $translated_text = $update_context['translated_text'];
        $original_text   = $update_context['original_text'];
        $target_lang_id  = $update_context['target_lang_id'];
        $target_code     = $update_context['target_code'];
        $status          = $update_context['status'];
        $source_lang_id  = $update_context['source_lang_id'];
        $post_id         = $update_context['post_id'];
        $context         = $update_context['context'];
        $block_id        = $update_context['block_id'];
        $page_url        = $update_context['page_url'];
        $now             = $update_context['now'];
        $release         = $update_context['release'];
        $logger          = $update_context['logger'];
        $translated_slug = $update_context['translated_slug'];
        $has_slug_column = $update_context['has_slug_column'];
        $origin          = $update_context['origin'];
        $mode            = $update_context['mode'];
        $trace_id        = $update_context['trace_id'];
        $req_id          = $update_context['req_id'];
        $string_id_input = $update_context['string_id_input'];
        $has_string_id_column = $update_context['has_string_id_column'];
        if (function_exists('yuztra_debug_probe_log')) {
            yuztra_debug_probe_log('editor_ajax_save_update', [
                'id'             => $row['id'],
                'post_id'        => $post_id,
                'target_lang_id' => $target_lang_id,
                'source_lang_id' => $source_lang_id,
            ]);
        }

        $update = [
            'translated_text' => $translated_text,
            'original_text'   => $original_text,
            'status'          => $status,
            'origin'          => $origin,
            'updated_at'      => $now,
        ];
        if ($has_string_id_column && $string_id_input > 0) {
            $update['string_id'] = $string_id_input;
        }

        if ($has_slug_column && $translated_slug !== '') {
            $update['translated_slug'] = $translated_slug;
        }

        if ($page_url !== '' && array_key_exists('page_url', $row) && $this->translation_table_has_column($table, 'page_url')) {
            $update['page_url'] = $page_url;
        }

        $formats = [];
        foreach ($update as $field => $_) {
            $formats[] = $field === 'status' ? '%d' : '%s';
        }

        $result = $wpdb->update($table, $update, ['id' => (int) $row['id']], $formats, ['%d']);

        if ($result === false) {
            $logger->log('error', 'Failed to update translation', ['error' => $wpdb->last_error, 'id' => (int) $row['id']]);
            $release();
            self::send_json_error(['message' => 'Failed to update translation', 'code' => 'db_update_failed'], 500);
        }

        if ($translated_slug !== '' && $post_id > 0) {
            $this->persist_translated_slug($post_id, $target_code, $translated_slug);
        }

        $status_before = isset($row['status']) ? (int) $row['status'] : null;
        $logger->log('info', 'yuztra_save_translation: updated existing translation', [
            'id'             => (int) $row['id'],
            'status_before'  => $status_before,
            'status_after'   => $status,
            'post_id'        => $post_id,
            'lang'           => $target_code,
            'context'        => $context,
            'origin'         => $origin,
            'mode'           => $mode,
            'trace_id'       => $trace_id,
            'req_id'         => $req_id,
            'string_id'      => $string_id_input,
        ]);
        $release();
        self::send_json_success(['message' => 'Translation updated', 'id' => (int) $row['id'], 'req_id' => $req_id]);
    };

    if ($translation_id > 0 && $existing) {
        $updateExisting($existing);
    }

    $insert = [
        'post_id'         => $post_id,
        'context'         => $context,
        'block_id'        => $block_id,
        'original_text'   => $original_text,   // YUZ: normalized
        'translated_text' => $translated_text, // YUZ: normalized
        'source_lang_id'  => $source_lang_id,
        'target_lang_id'  => $target_lang_id,
        'language_code'   => $target_code,
        'status'          => $status,
        'origin'          => $origin,
        'created_at'      => $now,
        'updated_at'      => $now,
    ];
    if ($has_string_id_column && $string_id_input !== null && $string_id_input > 0) {
        $insert['string_id'] = $string_id_input;
    }

    // Optional columns — include only if present to avoid SQL errors on legacy schemas
    if ($translated_slug !== '' && $has_slug_column) {
        $insert['translated_slug'] = $translated_slug;
    }
    if ($this->translation_table_has_column($table, 'revision_of')) {
        $insert['revision_of'] = null;
    }

    if ($page_url !== '' && $this->translation_table_has_column($table, 'page_url')) {
        $insert['page_url'] = $page_url;
    }

    // Build formats dynamically to match $insert keys
    $formats = [];
    foreach ($insert as $field => $_) {
        $formats[] = in_array($field, ['post_id','source_lang_id','target_lang_id','status','string_id'], true) ? '%d' : '%s';
    }

    if (function_exists('yuztra_debug_probe_log')) {
        yuztra_debug_probe_log('editor_ajax_save_insert', [
            'post_id'        => $post_id,
            'target_lang_id' => $target_lang_id,
            'source_lang_id' => $source_lang_id,
            'context'        => $context,
        ]);
    }
    $result = $wpdb->insert($table, $insert, $formats);
    if ($result === false) {
        $error_message = (string) $wpdb->last_error;
        $logger->log('error', 'Failed to insert translation', ['error' => $error_message]);
        if (function_exists('yuztra_debug_probe_log')) {
            yuztra_debug_probe_log('editor_ajax_save_fail', ['phase' => 'insert', 'error' => $error_message]);
        }

        if (stripos($error_message, 'duplicate') !== false) {
            $duplicate = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM %i WHERE post_id = %d AND context = %s AND source_lang_id = %d AND target_lang_id = %d AND block_id = %s",
                    $table,
                    $post_id,
                    $context,
                    $source_lang_id,
                    $target_lang_id,
                    $block_id
                ),
                ARRAY_A
            );
            if ($duplicate) {
                $updateExisting($duplicate);
                return;
            }
        }

        $release();
        self::send_json_error(['message' => 'Failed to save translation', 'code' => 'db_insert_failed'], 500);
    }

    $insert_id = (int) $wpdb->insert_id;
    $logger->log('info', 'yuztra_save_translation: created new translation', [
        'id'        => $insert_id,
        'post_id'   => $post_id,
        'lang'      => $target_code,
        'context'   => $context,
        'status'    => $status,
        'origin'    => $origin,
        'mode'      => $mode,
        'trace_id'  => $trace_id,
        'req_id'    => $req_id,
        'string_id' => $string_id_input,
    ]);
    if ($translated_slug !== '' && $post_id > 0) {
        $this->persist_translated_slug($post_id, $target_code, $translated_slug);
    }
    if (function_exists('yuztra_debug_probe_log')) {
        yuztra_debug_probe_log('editor_ajax_save_success', ['id' => $insert_id]);
    }
    $release();
    self::send_json_success(['message' => 'Translation saved', 'id' => $insert_id, 'req_id' => $req_id]);
    } catch (\Throwable $e) {
        $this->log_ajax_exception('yuztra_save_translation', $e, [
            'action'       => isset($_REQUEST['action']) ? sanitize_key(wp_unslash((string) $_REQUEST['action'])) : '',
            'string_id'    => $translation_id,
            'target_lang'  => $target_code,
            'target_langs' => $target_langs,
            'post_id'      => $post_id,
            'context'      => $context,
            'block_id'     => $block_id,
            'page_url'     => $page_url,
        ]);
        $release();
        self::send_json_error(['message' => 'Unexpected error while saving translation', 'code' => 'yuztra_save_exception'], 500);
    }
}

    private function persist_translated_slug(int $post_id, string $locale, string $slug): void
    {
        if ($post_id <= 0) {
            return;
        }
        $slug = sanitize_title($slug);
        $locale = sanitize_text_field($locale);
        if ($slug === '' || $locale === '') {
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'yuz_tra_translations';
        $like  = str_replace(['_', '%'], ['\\_', '\\%'], $table);
        if (!$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $like))) {
            return;
        }

        $locale_norm = strtoupper(str_replace('-', '_', $locale));
        $wpdb->update(
            $table,
            ['translated_slug' => $slug],
            ['post_id' => $post_id, 'language_code' => $locale_norm],
            ['%s'],
            ['%d', '%s']
        );
    }

    private function read_bool_flag($source, array $keys): bool
    {
        if (!is_array($source)) {
            return false;
        }
        foreach ($keys as $key) {
            if (!array_key_exists($key, $source)) {
                continue;
            }
            $value = $source[$key];
            if (is_bool($value)) {
                return $value;
            }
            if (is_numeric($value)) {
                return (int) $value === 1;
            }
            $normalized = strtolower(trim((string) $value));
            if ($normalized === '') {
                continue;
            }
            if (in_array($normalized, ['1', 'true', 'yes', 'on', 'batch'], true)) {
                return true;
            }
            if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
                return false;
            }
        }
        return false;
    }


    /**
     * Vérifie si une colonne existe dans la table des traductions.
     */
    private function translation_table_has_column(string $table, string $column): bool
    {
        global $wpdb;
        static $cache = [];
        $key = $table.'|'.$column;
        if (isset($cache[$key])) {
            return $cache[$key];
        }
        // Compatible MySQL/MariaDB ; évite INFORMATION_SCHEMA sur installs restreintes.
        $cols = $wpdb->get_results($wpdb->prepare('DESC %i', $table), ARRAY_A);
        if (!is_array($cols)) {
            $cache[$key] = false;
            return false;
        }
        foreach ($cols as $c) {
            if (!empty($c['Field']) && $c['Field'] === $column) {
                $cache[$key] = true;
                return true;
            }
        }
        $cache[$key] = false;
        return false;
    }

    /**
     * Vérifie l'unicité d'un slug traduit pour une langue.
     */
    private function translation_slug_exists(string $table, string $language_code, string $slug, int $exclude_id = 0): bool
    {
        if ($slug === '') {
            return false;
        }
        if (!$this->translation_table_has_column($table, 'translated_slug')) {
            return false;
        }
        global $wpdb;
        $language_code = strtoupper(str_replace('-', '_', $language_code));
        $sql    = "SELECT id FROM {$table} WHERE translated_slug = %s AND language_code = %s";
        $params = [$slug, $language_code];
        if ($exclude_id > 0) {
            $sql    .= " AND id <> %d";
            $params[] = $exclude_id;
        }
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query uses fixed plugin table and optional prepared integer exclusion.
        $prepared = $wpdb->prepare($sql, ...$params);
        if ($prepared === null) {
            return false;
        }
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Prepared above.
        $found = $wpdb->get_var($prepared);
        return !empty($found);
    }

    private static function sanitize_req_id($value): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }
        $value = preg_replace('/[^A-Za-z0-9_\-:]/', '', $value);
        return substr($value, 0, 64);
    }

    private static function ensure_req_id(): string
    {
        if (self::$current_req_id !== '') {
            return self::$current_req_id;
        }
        // Correlation metadata is intentionally available before authentication; it
        // confers no authority. Ignore malformed/oversized values without array casts.
        $incoming = is_string($_REQUEST['req_id'] ?? null) ? sanitize_text_field(wp_unslash($_REQUEST['req_id'])) : '';
        $incoming = strlen($incoming) <= 256 ? self::sanitize_req_id($incoming) : '';
        if ($incoming === '') {
            $incoming = function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : uniqid('yuztra_', true);
        }
        $_REQUEST['req_id'] = $incoming;
        self::$current_req_id = $incoming;
        return $incoming;
    }

    private static function current_action(): string
    {
        return is_string($_REQUEST['action'] ?? null) ? sanitize_key(wp_unslash($_REQUEST['action'])) : '';
    }

    private static function trace_meta(array $extra = []): array
    {
        $meta = [
            'req_id' => self::ensure_req_id(),
            'action' => self::current_action(),
            'ts'     => function_exists('current_time') ? current_time('mysql') : gmdate('Y-m-d H:i:s'),
        ];
        return array_merge($meta, $extra);
    }

    private static function with_trace($payload = [], array $extraTrace = []): array
    {
        if (!is_array($payload)) {
            $payload = ['value' => $payload];
        }
        $meta = self::trace_meta($extraTrace);
        $req_id = $meta['req_id'];
        unset($meta['req_id']);
        $payload['req_id'] = $req_id;
        $payload['trace']  = $meta;
        return $payload;
    }

    private static function preview_payload($payload): array
    {
        if (!is_array($payload)) {
            return ['type' => gettype($payload)];
        }
        $slice = array_slice($payload, 0, 5, true);
        foreach ($slice as $key => &$value) {
            if (is_array($value)) {
                $value = sprintf('array(%d)', count($value));
            } elseif (is_object($value)) {
                $value = 'object('.get_class($value).')';
            } else {
                $value = gettype($value);
            }
        }
        return $slice;
    }

    private static function log_ajax_exit(string $action, array $details = [], string $level = 'success'): void
    {
        try {
            if (!class_exists('YUZTRA_Logger')) {
                return;
            }
            $logger = new \YUZTRA_Logger();
            $context = array_merge(self::trace_meta(), $details);
            $logger->log($level, "[AJAX][{$action}][OUT]", $context);
        } catch (\Throwable $e) {
            yuztra_debug_log('[YUZTRA_AJAX][TRACE_EXIT_FAIL] '.$e->getMessage());
        }
    }

    private static function send_json_success($payload = [], int $status = 200): void
    {
        $action = self::current_action() ?: 'generic';
        $data = self::with_trace($payload);
        self::log_ajax_exit($action, [
            'status' => $status,
            'success' => true,
            'payload_preview' => self::preview_payload($data),
        ]);
        wp_send_json_success($data, $status);
    }

    private static function send_json_error($payload = [], int $status = 400): void
    {
        // Refusals must not persist request metadata, including through this wrapper.
        if ($status === 401 || $status === 403) {
            wp_send_json_error($payload, $status);
            return;
        }
        $action = self::current_action() ?: 'generic';
        $data = self::with_trace($payload);
        self::log_ajax_exit($action, [
            'status' => $status,
            'success' => false,
            'payload_preview' => self::preview_payload($data),
        ], 'error');
        wp_send_json_error($data, $status);
    }

    private function normalize_ajax_ids($raw): array
    {
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $raw = $decoded;
            } else {
                $raw = preg_split('/[,\s]+/', $raw);
            }
        }
        if (!is_array($raw)) {
            $raw = [$raw];
        }
        $ids = [];
        foreach ($raw as $value) {
            if (is_array($value)) {
                continue;
            }
            if (is_string($value) && $value !== '' && ctype_digit($value)) {
                $value = (int) $value;
            } elseif (is_numeric($value)) {
                $value = (int) $value;
            } else {
                continue;
            }
            if ($value > 0) {
                $ids[] = $value;
            }
        }
        return array_values(array_unique($ids));
    }

    /**
     * Log entrée AJAX avec contexte.
     */
    private function resolve_post_id_from_page_url(string $page_url): int
    {
        $page_url = trim($page_url);
        if ($page_url === '') {
            return 0;
        }

        // First, try the native resolver on the full URL.
        $direct = url_to_postid($page_url);
        if ($direct) {
            return (int) $direct;
        }

        $parts = wp_parse_url($page_url);
        if (!is_array($parts)) {
            return 0;
        }

        $path = isset($parts['path']) ? (string) $parts['path'] : '';
        if ($path === '') {
            return 0;
        }

        // Remove site base path if WP lives in a subdirectory.
        $home_parts = wp_parse_url(home_url('/'));
        $home_path  = isset($home_parts['path']) ? rtrim((string) $home_parts['path'], '/') : '';
        if ($home_path !== '' && stripos($path, $home_path) === 0) {
            $trimmed = substr($path, strlen($home_path));
            if ($trimmed !== false) {
                $path = $trimmed;
            }
        }

        $clean_path = trim($path, '/');
        $segments   = array_values(array_filter(explode('/', $clean_path), 'strlen'));

        $candidates = [];
        if ($clean_path !== '') {
            $candidates[] = $clean_path;
        }

        // Strip leading locale slug if it matches a known language slug.
        $slugs = $this->get_language_slugs();
        if (count($segments) > 1 && $slugs) {
            $first = strtolower($segments[0]);
            if (in_array($first, $slugs, true)) {
                $stripped = implode('/', array_slice($segments, 1));
                if ($stripped !== '') {
                    $candidates[] = $stripped;
                }
            }
        }

        foreach (array_values(array_unique($candidates)) as $candidate) {
            $found = get_page_by_path($candidate);
            if ($found && isset($found->ID)) {
                return (int) $found->ID;
            }

            $candidate_url = home_url('/' . ltrim($candidate, '/'));
            $pid = url_to_postid($candidate_url);
            if ($pid) {
                return (int) $pid;
            }
        }

        $front = absint(get_option('page_on_front'));
        return $front ?: 0;
    }

    /**
     * Known language slugs from DB or settings (lowercased, normalized).
     */
    private function get_language_slugs(): array
    {
        $slugs = [];

        try {
            global $wpdb;
            if ($wpdb instanceof \wpdb) {
                $table = $wpdb->prefix . 'yuz_tra_languages';
                if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table))) {
                    $rows = $wpdb->get_col($wpdb->prepare(
                        "SELECT slug FROM %i WHERE slug <> ''",
                        $table
                    ));
                    if (is_array($rows)) {
                        foreach ($rows as $slug) {
                            $norm = $this->normalize_slug_value((string) $slug);
                            if ($norm !== '') {
                                $slugs[] = strtolower($norm);
                            }
                        }
                    }
                }
            }
        } catch (\Throwable $ignored) {}

        try {
            $settings = (array) get_option('yuztra_language_settings', []);
            foreach (['slug_map', 'slugs', 'yuztra_slug'] as $key) {
                if (empty($settings[$key]) || !is_array($settings[$key])) {
                    continue;
                }
                foreach ($settings[$key] as $value) {
                    $norm = $this->normalize_slug_value(is_array($value) ? '' : (string) $value);
                    if ($norm !== '') {
                        $slugs[] = strtolower($norm);
                    }
                }
            }
        } catch (\Throwable $ignored) {}

        return array_values(array_unique(array_filter($slugs)));
    }

    /**
     * Log entrée AJAX avec contexte.
     */
    private function log_ajax_entry(string $action, array $details = []): void
    {
        $details = array_merge(self::trace_meta(), $details, [
            'user_id'    => function_exists('get_current_user_id') ? get_current_user_id() : 0,
            'doing_ajax' => defined('DOING_AJAX') && DOING_AJAX,
        ]);
        try {
            $logger = $this->logger ?: (class_exists('YUZTRA_Logger') ? new \YUZTRA_Logger() : new \YUZTRA\Fallbacks\NullLogger());
            $logger->log('info', "[AJAX][{$action}][IN]", $details);
        } catch (\Throwable $e) {
            yuztra_debug_log('[YUZTRA_AJAX]['.$action.'][ENTRY_LOG_FAIL] '.$e->getMessage());
        }
        $encoded = function_exists('wp_json_encode') ? wp_json_encode($details) : json_encode($details);
        yuztra_debug_log('[YUZTRA_AJAX]['.$action.'][IN] '.$encoded);
    }

    /**
     * Log exception AJAX (logger + php error_log).
     */
    private function log_ajax_exception(string $action, \Throwable $e, array $snapshot = []): void
    {
        $payload = self::trace_meta([
            'exception' => get_class($e),
            'message'   => $e->getMessage(),
            'code'      => (int) $e->getCode(),
            'file'      => $e->getFile(),
            'line'      => $e->getLine(),
            'snapshot'  => $snapshot,
        ]);
        try {
            $logger = $this->logger ?: (class_exists('YUZTRA_Logger') ? new \YUZTRA_Logger() : new \YUZTRA\Fallbacks\NullLogger());
            $logger->log('critical', "[AJAX][{$action}][EXCEPTION]", $payload);
        } catch (\Throwable $logError) {
            yuztra_debug_log('[YUZTRA_AJAX]['.$action.'][LOGGER_FAILURE] '.$logError->getMessage());
        }
        $encoded = function_exists('wp_json_encode') ? wp_json_encode($payload) : json_encode($payload);
        yuztra_debug_log('[YUZTRA_AJAX]['.$action.'][EXCEPTION] '.$encoded);
    }
} // fin class YUZ_Ajax
} // fin if (!class_exists('YUZ_Ajax'))

add_action('wp_ajax_yuztra_preflight', ['YUZTRA_Ajax','ajax_preflight']);
add_action('init', ['YUZTRA_Ajax', 'init']);

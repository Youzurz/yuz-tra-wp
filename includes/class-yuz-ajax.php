<?php
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
defined('ABSPATH') or exit;

require_once YUZ_TRA_INCLUDES . 'class-yuz-contracts.php';
require_once YUZ_TRA_INCLUDES . 'class-yuz-fallbacks.php';
if (!class_exists('YUZ_String_Service')) {
    require_once YUZ_TRA_INCLUDES . 'class-yuz-string-service.php';
}
require_once YUZ_TRA_INCLUDES . 'helpers/html-translator.php';

use YUZTRA\Interfaces\AjaxInterface;
use YUZTRA\Interfaces\TranslationManagerInterface;
use YUZTRA\Interfaces\LanguageManagerInterface;
use YUZTRA\Interfaces\DBInterface;
use YUZTRA\Fallbacks\NullTranslationManager;
use YUZTRA\Fallbacks\NullLanguageManager;
use YUZTRA\Fallbacks\NullAjax;

if (!class_exists('YUZ_Ajax')) {

    class YUZ_Ajax implements AjaxInterface {

        private const OFFICIAL_NONCES = [
            'yuz_tra_nonce',
            'yuz_con_nonce',
            'yuz_int_nonce',
            'yuz_del_nonce',
            'yuz_log_nonce',
            'yuz_hvy_nonce',
            'yuz_api_nonce',
        ];

        private static bool $bootstrapped = false;
        private static string $current_req_id = '';
        /** Endpoints soumis à un anti-rafale (limite courte glissante par IP+action). */
        private const RATE_LIMITED_ACTIONS = [
            'yuz_tra_tm_translate',
            'yuz_translate',
            'yuz_tra_tm_translate_item',
            'yuz_get_regular',
            'yuz_tra_js_get_regular',
        ];

        /** @var \YUZ_Logger|\YUZTRA\Fallbacks\NullLogger */
        private $logger;

        private TranslationManagerInterface $translation_manager;
        private LanguageManagerInterface $language_manager;
        private DBInterface $db;
        private ?\YUZ_Settings $settings = null;

        /**
         * Low-level trace logger to a dedicated uploads file (yuz-trace.log).
         * Lightweight, opt-in via constant YUZ_TRA_TRACE_AUTO=true or query header X-YUZ-TRACE.
         */
        private function trace_log(string $tag, array $ctx = []): void {
            try {
                $trace_on = (defined('YUZ_TRA_TRACE_AUTO') && YUZ_TRA_TRACE_AUTO) || !empty($_SERVER['HTTP_X_YUZ_TRACE']);
                if (!$trace_on) return;
                if (!function_exists('wp_upload_dir')) return;
                $uploads = wp_upload_dir();
                if (empty($uploads['basedir'])) return;
                $file = rtrim($uploads['basedir'], '/\\') . '/yuz-trace.log';
                $row  = sprintf('%s %s %s%s',
                    gmdate('Y-m-d H:i:s') . ' UTC',
                    $tag,
                    wp_json_encode($ctx, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
                    PHP_EOL
                );
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
            if (is_user_logged_in() && current_user_can('yuz_translate_content')) {
                return ['allow' => true, 'retry_after' => 0];
            }
            $window = defined('YUZ_TRA_RATE_LIMIT_WINDOW') ? (int) YUZ_TRA_RATE_LIMIT_WINDOW : 10; // seconds
            $limit  = defined('YUZ_TRA_RATE_LIMIT_LIMIT') ? (int) YUZ_TRA_RATE_LIMIT_LIMIT : 100; // requests per window
            if ($window <= 0) { $window = 10; }
            if ($limit <= 0)  { $limit  = 100; }
            $ip = isset($_SERVER['REMOTE_ADDR']) ? trim((string) $_SERVER['REMOTE_ADDR']) : 'unknown';
            if ($ip === '') $ip = 'unknown';

            $key = 'yuz_rate_' . md5($action . '|' . $ip);
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
                $key = 'yuz_tra_nonce';
            }
            return $key;
        }

        public function __construct(
    ?TranslationManagerInterface $translation_manager = null,
    ?LanguageManagerInterface $language_manager = null,
    ?DBInterface $db = null
) {
    // Logger / Health
    $logger = class_exists('YUZ_Logger') ? new \YUZ_Logger() : new \YUZTRA\Fallbacks\NullLogger();
    $this->logger = $logger;
    $health = class_exists('YUZ_Health_Check') ? new \YUZ_Health_Check($logger) : new \YUZTRA\Fallbacks\NullHealth();

    // DB
    if (!$db) {
        if (class_exists('YUZ_DB')) {
            $db = new \YUZ_DB($logger, $health);
        } else {
            // Fallback inerte si YUZ_DB indisponible
            $db = new \YUZTRA\Fallbacks\NullDB();
        }
    }

    // Language manager
    if (!$language_manager) {
        if (class_exists('YUZ_Languages')) {
            $language_manager = new \YUZ_Languages(new \YUZTRA\Fallbacks\NullSettings(), $db);
        } elseif (class_exists('YUZ_LanguageManager')) {
            $language_manager = new \YUZ_LanguageManager(new \YUZTRA\Fallbacks\NullSettings(), $db);
        } else {
            $language_manager = new \YUZTRA\Fallbacks\NullLanguageManager();
        }
    }

    // Translation manager
    if (!$translation_manager) {
        try {
            $translation_manager = (class_exists('YUZ_Services') && method_exists('YUZ_Services', 'tm'))
                ? \YUZ_Services::tm()
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
    add_action('wp_ajax_yuz_run_diagnostics', [$this, 'ajax_run_diagnostics']);
    add_action('wp_ajax_yuz_clear_cache',     [$this, 'ajax_clear_cache']);
    add_action('wp_ajax_yuz_tra_get_languages', [$this, 'ajax_get_languages']);
    add_action('wp_ajax_nopriv_yuz_tra_get_languages', [$this, 'ajax_get_languages']);
    add_action('wp_ajax_yuz_tra_diag_chain', [$this, 'yuz_tra_diag_chain']);
    add_action('wp_ajax_nopriv_yuz_tra_diag_chain', [$this, 'yuz_tra_diag_chain']);

    // Maintenance (admin‑only)
    add_action('wp_ajax_yuz_tra_maintenance', [$this, 'ajax_maintenance']);

    // Lightweight DOM debug logger (front and nopriv)
    add_action('wp_ajax_yuz_dom_log',       [$this, 'yuz_dom_log']);
    // Allow front probes to log without auth (debug-only endpoint)
    add_action('wp_ajax_nopriv_yuz_dom_log', [$this, 'yuz_dom_log']);

    // Front trace beacon -> uploads/yuz-trace.log
    add_action('wp_ajax_yuz_trace_beacon', [$this, 'yuz_trace_beacon']);
    add_action('wp_ajax_nopriv_yuz_trace_beacon', [$this, 'yuz_trace_beacon']);

    // One-shot diagnostic hook (requires nonce + trace flag)
    add_action('wp_ajax_yuz_diag', [$this, 'yuz_diag']);
}

        public function ajax_my_action(): void {
            try {
                // validation nonce (ne fait pas wp_die() en cas d'échec)
                if (! check_ajax_referer('yuz_tra_nonce', 'nonce', false) ) {
                    wp_send_json_error(['message' => 'bad_nonce'], 403);
                }

                // logique métier...
                // $translated doit être défini par votre traitement réel
                $translated = null; // <-- remplacer par votre code
                $result = [ 'translatedSingle' => $translated ];

                wp_send_json_success($result);
            } catch (\Throwable $e) {
                wp_send_json_error(['message' => 'internal_error'], 500);
            }
        }
        
private function is_trace_request(): bool {
            if (isset($_REQUEST['yuztrace']) && $_REQUEST['yuztrace'] === '1') {
                return true;
            }
            if (!empty($_SERVER['HTTP_X_YUZ_TRACE'])) {
                return true;
            }
            return false;
        }

        private function trace_upload_path(): ?string {
            if (!function_exists('wp_upload_dir')) {
                return null;
            }
            $upload_dir = wp_upload_dir();
            if (empty($upload_dir['basedir'])) {
                return null;
            }
            $base = $upload_dir['basedir'];
            if (!is_dir($base)) {
                wp_mkdir_p($base);
            }
            $target = trailingslashit($base) . 'yuz-trace.log';
            if (!file_exists($target)) {
                @touch($target);
                @chmod($target, 0664);
            }
            if (!is_writable($target)) {
                // fallback to wp-content root
                if (defined('WP_CONTENT_DIR')) {
                    $alt = trailingslashit(WP_CONTENT_DIR) . 'yuz-trace.log';
                    if (!file_exists($alt)) {
                        @touch($alt);
                        @chmod($alt, 0664);
                    }
                    if (is_writable($alt)) {
                        return $alt;
                    }
                }
            }
            return is_writable($target) ? $target : null;
        }

        private function emit_trace_marker(string $marker, array $context = []): void {
            $payload = wp_json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($payload === false) {
                $payload = '{}';
            }
            $line = sprintf('[%s] %s', $marker, $payload);
            $target = $this->trace_upload_path();
            if ($target) {
                $record = gmdate('c') . ' ' . $line . PHP_EOL;
                @file_put_contents($target, $record, FILE_APPEND | LOCK_EX);
            }
        }



        private function get_settings(): \YUZ_Settings {
            if ($this->settings instanceof \YUZ_Settings) {
                return $this->settings;
            }
            if (class_exists('YUZ_Services') && method_exists('YUZ_Services', 'settings')) {
                $service_settings = \YUZ_Services::settings();
                if ($service_settings instanceof \YUZ_Settings) {
                    return $this->settings = $service_settings;
                }
            }
            $logger = class_exists('YUZ_Logger') ? new \YUZ_Logger() : new \YUZTRA\Fallbacks\NullLogger();
            // Prefer real languages if available; fall back to NullLanguages
            $langs = class_exists('YUZ_Languages') ? new \YUZ_Languages(new \YUZTRA\Fallbacks\NullSettings(), $this->db) : new \YUZTRA\Fallbacks\NullLanguages();
            $this->settings = new \YUZ_Settings(
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
                \YUZ_Languages::TRANSIENT_TRANSLATABLE,
                \YUZ_Languages::TRANSIENT_ALL,
                \YUZ_Languages::TRANSIENT_DEFAULT_LANGUAGE,
                \YUZ_Languages::TRANSIENT_SOURCE_LANGUAGE,
            ];

            foreach ($suffixes as $suffix) {
                if (function_exists('yuz_settings_delete_transient')) {
                    yuz_settings_delete_transient($suffix);
                }
                if (function_exists('yuz_settings_cache_delete')) {
                    yuz_settings_cache_delete($suffix);
                }
            }

            // Legacy transients (pre-versioned) - defensive purge
            foreach (['yuz_tra_translatable_languages', 'yuz_tra_all_languages', 'yuz_tra_default_language', 'yuz_tra_source_language'] as $legacy) {
                delete_transient($legacy);
            }

            // Legacy object cache fragments
            if (function_exists('wp_cache_delete')) {
                foreach (['all_languages', 'translatable_languages', 'default_language', 'source_language'] as $legacyCache) {
                    wp_cache_delete($legacyCache, 'yuz-tra');
                }
            }

            if (function_exists('yuz_settings_cache_delete')) {
                foreach ($codes as $code) {
                    $suffix = \YUZ_Languages::CACHE_LANGUAGE_PREFIX . sanitize_key($code);
                    yuz_settings_cache_delete($suffix);
                }
            }

            if (class_exists('YUZ_Settings_Service')) {
                YUZ_Settings_Service::touch();
            } elseif (function_exists('yuz_settings_get_all') && function_exists('yuz_settings_update_all')) {
                $current = yuz_settings_get_all();
                if (is_array($current)) {
                    yuz_settings_update_all($current);
                }
            }
        }

        /** Resolve translations table name (first match) */
        private function resolve_translations_table(\wpdb $wpdb): string {
            $like = $wpdb->esc_like($wpdb->prefix . 'yuz_tra_translations');
            $rows = $wpdb->get_col("SHOW TABLES LIKE '{$like}%'");
            if (is_array($rows) && !empty($rows)) {
                return (string) $rows[0];
            }
            // Fallback to canonical
            return $wpdb->prefix . 'yuz_tra_translations';
        }

        /** Compute quick maintenance metrics */
        private function maintenance_metrics(\wpdb $wpdb, string $table): array {
            $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$table}`");
            $empties = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$table}` WHERE TRIM(COALESCE(translated_text,''))='' ");
            $dups = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM (\n" .
                " SELECT MIN(id) AS keep_id\n" .
                " FROM `{$table}`\n" .
                " GROUP BY post_id, COALESCE(block_id,''), language_code, COALESCE(context,''), COALESCE(original_text,'')\n" .
                " HAVING COUNT(*)>1\n" .
                ") x"
            );
            $locales = (array) $wpdb->get_results("SELECT language_code, COUNT(*) c FROM `{$table}` GROUP BY language_code ORDER BY c DESC", ARRAY_A);
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
            $wpdb->query("CREATE TABLE `{$backup}` LIKE `{$table}`");
            $wpdb->query("INSERT INTO `{$backup}` SELECT * FROM `{$table}`");
            return ['backup_table' => $backup];
        }

        /** Normalize locales safely: hyphen->underscore, lang lower, region upper */
        private function maintenance_normalize_locales(\wpdb $wpdb, string $table): int {
            $sql = "UPDATE `{$table}`\n"
                 . "SET language_code = CASE\n"
                 . "  WHEN language_code LIKE '%-%' OR language_code LIKE '%\\_%' THEN\n"
                 . "    CONCAT(LOWER(SUBSTRING_INDEX(REPLACE(language_code,'-','_'),'_',1)),'_',UPPER(SUBSTRING_INDEX(REPLACE(language_code,'-','_'),'_',-1)))\n"
                 . "  ELSE LOWER(language_code) END";
            return (int) $wpdb->query($sql);
        }

        /** Delete empty translated_text rows */
        private function maintenance_delete_empties(\wpdb $wpdb, string $table): int {
            return (int) $wpdb->query("DELETE FROM `{$table}` WHERE TRIM(COALESCE(translated_text,''))=''");
        }

        /** Deduplicate logical duplicates keeping highest id */
        private function maintenance_dedupe(\wpdb $wpdb, string $table): int {
            $sql = "DELETE t1 FROM `{$table}` t1\n"
                 . "JOIN `{$table}` t2\n"
                 . "  ON  t1.post_id = t2.post_id\n"
                 . "  AND COALESCE(t1.block_id,'') = COALESCE(t2.block_id,'')\n"
                 . "  AND t1.language_code = t2.language_code\n"
                 . "  AND COALESCE(t1.context,'') = COALESCE(t2.context,'')\n"
                 . "  AND COALESCE(t1.original_text,'') = COALESCE(t2.original_text,'')\n"
                 . "  AND t1.id < t2.id";
            return (int) $wpdb->query($sql);
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
                $qs = parse_url($url, PHP_URL_QUERY);
                if (is_string($qs)) {
                    parse_str($qs, $params);
                    if (!empty($params['lang'])) {
                        $candidate = (string) $params['lang'];
                    }
                }
            } catch (\Throwable $e) {}
            if ($candidate !== '') return $this->normalize_locale_code($candidate);
            try {
                $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');
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
                "SELECT language_code, language_weight, is_translatable, is_source, is_default FROM {$table}",
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
         * - Writes to wp-content/uploads/yuz-dom.log (fallback: wp-content/yuz-dom.log)
         * - NOPRIV allowed and nonce optional on purpose (debug only)
         */
        public function yuz_dom_log(): void {
            // Soft validation (no nonce to keep it usable in front probes)
            $event   = isset($_POST['event']) ? sanitize_text_field(wp_unslash((string) $_POST['event'])) : '';
            $rawCtx  = isset($_POST['context']) ? wp_unslash((string) $_POST['context']) : '';
            $context = null;
            if ($rawCtx !== '') {
                $decoded = json_decode($rawCtx, true);
                $context = is_array($decoded) ? $decoded : ['message' => substr($rawCtx, 0, 1000)];
            } else {
                $context = [];
            }

            // Enrich with basics
            $context['url']        = $context['url'] ?? (isset($_POST['page_url']) ? esc_url_raw((string) $_POST['page_url']) : '');
            $context['lang']       = $context['lang'] ?? (isset($_POST['lang']) ? sanitize_text_field((string) $_POST['lang']) : '');
            $context['user_agent'] = $context['user_agent'] ?? (isset($_SERVER['HTTP_USER_AGENT']) ? (string) $_SERVER['HTTP_USER_AGENT'] : '');

            $line = sprintf(
                "%s 🟪 DOMLOG %s %s\n",
                gmdate('Y-m-d H:i:s') . ' UTC',
                $event !== '' ? $event : 'event',
                wp_json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            );

            // Try uploads dir first
            $written = false;
            if (function_exists('wp_upload_dir')) {
                $up = wp_upload_dir();
                if (is_array($up) && !empty($up['basedir'])) {
                    $path = trailingslashit($up['basedir']) . 'yuz-dom.log';
                    // Silently attempt to create directory/file
                    if (!file_exists($path)) {
                        @touch($path);
                    }
                    $written = (@file_put_contents($path, $line, FILE_APPEND) !== false);
                }
            }
            // Fallback to wp-content
            if (!$written && defined('WP_CONTENT_DIR')) {
                $alt = trailingslashit(WP_CONTENT_DIR) . 'yuz-dom.log';
                if (!file_exists($alt)) { @touch($alt); }
                $written = (@file_put_contents($alt, $line, FILE_APPEND) !== false);
            }

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
                try { $this->logger->log('info', 'YUZ-DOM', ['event' => $event, 'context' => $context]); } catch (\Throwable $ignored) {}
            }

        if ($written) {
            wp_send_json_success(['ok' => true]);
        } else {
            wp_send_json_error(['ok' => false, 'message' => 'write_failed'], 500);
        }
    }

        /**
         * AJAX: Trace beacon endpoint (priv + nopriv) used by front-side sendBeacon/fetch fallbacks.
         * Accepts a JSON payload (marker + context) and mirrors it to uploads/yuz-trace.log.
         */
        public function yuz_trace_beacon(): void {
            check_ajax_referer('yuz_log_nonce', 'nonce');
            $payload_raw = isset($_POST['payload']) ? wp_unslash((string) $_POST['payload']) : '';
            if ($payload_raw === '') {
                wp_send_json_error(['ok' => false, 'error' => 'empty_payload'], 400);
            }

            $decoded = json_decode($payload_raw, true);
            if (!is_array($decoded)) {
                $decoded = [
                    'raw_payload' => substr($payload_raw, 0, 2000),
                ];
            }

            $marker = isset($decoded['marker']) ? sanitize_text_field((string) $decoded['marker']) : 'YUZ_TRACE_STATE';
            if ($marker === '') {
                $marker = 'YUZ_TRACE_STATE';
            }

            $decoded['remote_ip'] = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field((string) $_SERVER['REMOTE_ADDR']) : '';
            $decoded['user_agent'] = isset($_SERVER['HTTP_USER_AGENT']) ? substr(sanitize_text_field((string) $_SERVER['HTTP_USER_AGENT']), 0, 255) : '';

            try {
                $this->emit_trace_marker($marker, $decoded);
            } catch (\Throwable $e) {
                wp_send_json_error(['ok' => false, 'error' => 'emit_failed'], 500);
            }

            wp_send_json_success(['ok' => true]);
        }

        public function yuz_diag(): void {
            $nonce = isset($_POST['yuz_tra_nonce']) ? sanitize_text_field(wp_unslash((string) $_POST['yuz_tra_nonce'])) : '';
            if (!$nonce || !wp_verify_nonce($nonce, 'yuz_tra_nonce')) {
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

            if (!$wpdb->get_var("SHOW TABLES LIKE '$trans_table'")) {
                $summary['error'] = 'missing_table';
                $this->emit_trace_marker('YUZ_DIAG_SUMMARY', $summary);
                wp_send_json_error(['ok' => false, 'summary' => $summary], 500);
            }

            $rows = (array) $wpdb->get_results("SELECT original_text, translated_text, language_code FROM {$trans_table} ORDER BY updated_at DESC LIMIT 50", ARRAY_A);
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

            $this->emit_trace_marker('YUZ_DIAG_SUMMARY', [
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
            check_ajax_referer('yuz_hvy_nonce', 'nonce');
            global $wpdb;
            $table = $this->resolve_translations_table($wpdb);
            $task = isset($_REQUEST['task']) ? sanitize_key((string) $_REQUEST['task']) : 'metrics';

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
                "SELECT language_code, language_name, is_translatable
                 FROM `{$wpdb->prefix}yuz_tra_languages`",
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
                $g = get_option('yuz_tra_general', []);
                $list = $g['yuz_tra_translatable_languages'] ?? ($g['yuz_translatable_languages'] ?? []);
                foreach ((array)$list as $code) {
                    $c = (string)$code; if ($c==='') continue;
                    $map[$c] = ['label'=>$c, 'is_translatable'=>1];
                }
            }
            if (empty($map)) {
                $cfg = get_option('yuz_translation_config', []);
                foreach ((array)($cfg['target_languages'] ?? []) as $code) {
                    $c = (string)$code; if ($c==='') continue;
                    $map[$c] = ['label'=>$c, 'is_translatable'=>1];
                }
            }
            if (empty($map)) {
                foreach ((array)get_option('yuz_tra_enabled_languages', []) as $code) {
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
            // Public read-only endpoint: allow visitors, but keep a minimal log for monitoring.
            $is_authenticated = is_user_logged_in();
            $langs   = self::get_configured_languages();
            $targets = self::get_target_language_codes($langs);

            // debug: log incoming POST/nonce for diagnosis (no sensitive data returned anyway)
            try {
                $incoming = [
                    'remote_addr' => $_SERVER['REMOTE_ADDR'] ?? '',
                    'post_keys'   => array_keys($_POST),
                    'user'        => $is_authenticated ? get_current_user_id() : 0,
                ];
                \YUZ\YUZ_Assets::get()->log_debug('ajax-incoming-yuz_tra_ws_get_languages', $incoming);
            } catch (\Throwable $ignored) {}

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
                if (class_exists('YUZ_Logger')) {
                    (new YUZ_Logger())->log('notice', 'YUZ_Ajax::init skipped (already bootstrapped)');
                }
                return;
            }

            // 1) Charger proactivement la classe langues
            if (!class_exists('YUZ_Languages') && defined('YUZ_TRA_INCLUDES')) { // problème ic à cause de la ligne 344
                $path = trailingslashit(YUZ_TRA_INCLUDES) . 'class-yuz-languages.php';
                if (file_exists($path)) {
                    require_once $path;
                }
            }

            // 2) classes requises (log  die propre si manquantes)
            $required_classes = ['YUZ_Settings', 'YUZ_DB', 'YUZ_Health_Check', 'YUZ_Logger'];
            foreach ($required_classes as $class) {
                if (!class_exists($class)) {
                    wp_die(sprintf(__('Critical error: %s class missing.', 'yuz_translation'), $class));
                }
            }

            $logger       = new YUZ_Logger();
            $logger->log('debug', 'Initializing YUZ_Ajax class at ' . current_time('mysql'));
            $health_check = new YUZ_Health_Check($logger);
            $db           = new YUZ_DB($logger, $health_check);

            // Stubs
            $stub_settings         = new \YUZTRA\Fallbacks\NullSettings();
            $stub_languages        = new \YUZTRA\Fallbacks\NullLanguages();
            $stub_language_manager = new \YUZTRA\Fallbacks\NullLanguageManager();
            $stub_tm               = new \YUZTRA\Fallbacks\NullTranslationManager();

            // 3) Manager de langues : privilégier YUZ_Languages
            $languages = class_exists('YUZ_Languages')
                ? new YUZ_Languages($stub_settings, $db)
                : $stub_languages;

            $language_manager = ($languages instanceof \YUZTRA\Interfaces\LanguageManagerInterface)
                ? $languages
                : (class_exists('YUZ_LanguageManager') ? new YUZ_LanguageManager($stub_settings, $db) : $stub_language_manager);

            // 4) TM
            try {
                $translation_manager = (class_exists('YUZ_Services') && method_exists('YUZ_Services', 'tm'))
                    ? YUZ_Services::tm()
                    : $stub_tm;
            } catch (\Throwable $e) {
                $logger->log('warning', 'Falling back to NullTranslationManager in YUZ_Ajax::init(): ' . $e->getMessage());
                $translation_manager = $stub_tm;
            }

            // 5) Instance
            $instance = new self($translation_manager, $language_manager, $db);

            // 6) Enregistrement des actions
            $actions = [
                'website_languages' => [
                    'yuz_tra_ws_get_languages'   => 'yuz_tra_ws_get_languages',
                    'yuz_tra_languages'          => 'yuz_tra_ws_get_languages',
                    'yuz_tra_ws_cre_language'    => 'yuz_tra_ws_cre_language',
                    'yuz_tra_ws_cre_alllang'     => 'yuz_tra_ws_cre_alllang',
                    'yuz_tra_ws_del_language'    => 'yuz_tra_ws_del_language',
                    'yuz_tra_ws_del_alllang'     => 'yuz_tra_ws_del_alllang',
                    'yuz_tra_ws_upd_weights'     => 'yuz_tra_ws_upd_weights',
                    'yuz_tra_ws_upd_swapsrc'     => 'yuz_tra_ws_upd_swapsrc',
                    'yuz_tra_ws_upd_settings'    => 'yuz_tra_ws_upd_settings',
                    'yuz_tra_ws_upd_deflang'     => 'yuz_tra_ws_upd_deflang',
                    'yuz_tra_ws_upd_srclang'     => 'yuz_tra_ws_upd_srclang',
                    'yuz_tra_ws_upd_translatable'=> 'yuz_tra_ws_upd_translatable',
                    'yuz_tra_ws_remove_all'      => 'yuz_tra_ws_remove_all',
                ],
                'language_settings' => [
                    'yuz_tra_ls_get_settings' => 'yuz_tra_ls_get_settings',
                    'yuz_tra_ls_upd_settings' => 'yuz_tra_ls_upd_settings',
                ],
                'language_switcher' => [
                    'yuz_tra_sw_get_settings'    => 'yuz_tra_sw_get_settings',
                    'yuz_tra_sw_upd_settings'    => 'yuz_tra_sw_upd_settings',
                    'yuz_tra_sw_switch_language' => 'yuz_tra_sw_switch_language',
                    'yuz_tra_sw_resolve_url'     => 'yuz_tra_sw_resolve_url',
                ],
                'translate_site_settings' => [
                    'yuz_tra_ts_get_settings'     => 'yuz_tra_ts_get_settings',
                    'yuz_tra_ts_upd_settings'     => 'yuz_tra_ts_upd_settings',
                    'yuz_tra_ts_cre_fulltra'      => 'yuz_tra_ts_cre_fulltra',
                    'yuz_tra_ts_start_translation'=> 'yuz_tra_ts_start_translation',
                ],
                'translation_editor' => [
                    'yuz_tra_te_cre_tstart'      => 'yuz_tra_te_cre_tstart',
                    'yuz_tra_te_cre_translation' => 'yuz_tra_te_cre_translation',
                    'yuz_tra_te_upd_manual'      => 'yuz_tra_te_upd_manual',
                    'yuz_tra_te_upd_publish'     => 'yuz_tra_te_upd_publish',
                    'yuz_tra_get_publish_review' => 'yuz_get_pending_translations', // Backward compat for legacy JS
                    'yuz_get_pending_translations' => 'yuz_get_pending_translations',
                    'yuz_publish_translations'     => 'yuz_publish_translations',
                ],
                'automatic_translation' => [
                    'yuz_tra_at_get_api_settings' => 'yuz_tra_at_get_api_settings',
                    'yuz_tra_at_upd_api_settings' => 'yuz_tra_at_upd_api_settings',
                    'yuz_tra_at_del_api_settings' => 'yuz_tra_at_del_api_settings',
                    'yuz_tra_at_get_api_test'     => 'yuz_tra_at_get_api_test',
                    'yuz_tra_at_cre_tsilent'      => 'yuz_tra_at_cre_tsilent',
                ],
                'translation_manager' => [
                    'yuz_tra_tm_cre_translation' => 'yuz_tra_tm_cre_translation',
                    'yuz_tra_tm_cre_page'        => 'yuz_tra_tm_cre_page',
                    'yuz_tra_tm_get_translations'=> 'yuz_tra_tm_get_translations',
                    'yuz_tra_tm_search'          => 'yuz_tra_tm_search',
                    'yuz_tra_tm_del_translation' => 'yuz_tra_tm_del_translation',
                    'yuz_tra_tm_translate'       => 'yuz_tra_tm_translate',
                    'yuz_translate'              => 'yuz_translate',
                    'yuz_tra_tm_test_api'        => 'yuz_tra_tm_test_api',
                ],
                'strings' => [
                    'yuz_gt_search'      => 'yuz_gt_search',
                    'yuz_gt_save'        => 'yuz_gt_save',
                    'yuz_slugs_search'   => 'yuz_slugs_search',
                    'yuz_slugs_save'     => 'yuz_slugs_save',
                    'yuz_eml_search'     => 'yuz_eml_search',
                    'yuz_eml_save'       => 'yuz_eml_save',
                ],
                'javascript_actions' => [
                    'yuz_tra_js_upd_database' => 'yuz_tra_js_upd_database',
                    'yuz_tra_js_upd_bulkedit' => 'yuz_tra_js_upd_bulkedit',
                    'yuz_tra_js_get_gtxtscan' => 'yuz_tra_js_get_gtxtscan',
                    'yuz_tra_js_get_regular'  => 'yuz_tra_js_get_regular',
                    'yuz_get_regular'         => 'yuz_get_regular',
                    'yuz_tra_js_upd_auto'     => 'yuz_tra_js_upd_auto',
                ],
                'general_settings' => [
                    'yuz_tra_delete_translation' => 'yuz_tra_delete_translation',
                ],
                'batch' => [
                    'yuz_tra_batch' => 'handle_ajax_batch',
                ],
            ];

            foreach ($actions as $module => $module_actions) {
                $registered = 0;
                foreach ($module_actions as $action => $method) {
                    add_action("wp_ajax_{$action}",        [$instance, $method]);
                    add_action("wp_ajax_nopriv_{$action}", [$instance, $method]);
                    YUZ_Health_Check::ensure(
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
            add_action('wp_ajax_yuz_save_translation', [$instance, 'yuz_save_translation']);
            // (moved registration of yuz_save_translation after we instantiate $instance) /// ici

            $logger->log('success', 'YUZ_Ajax class initialized successfully');
            // Endpoints additionnels (Advanced)
            $instance->registerEndpoints();

            self::$bootstrapped = true;
        }

        /** -------------------- CORE AJAX WRAPPER -------------------- */
        public static function __handleRequest($nonce_key, array $required = [], callable $callback = null, bool $return_json = true) {
            if ($return_json && !headers_sent()) {
                header('Content-Type: application/json; charset=' . get_bloginfo('charset'));
            }

            // Tamponner toute sortie parasite
            ob_start();
            $obStarted = true;

            try {
                self::ensure_req_id();
                // Récup param nonce (multi noms acceptés)
                $nonce_param = $_REQUEST['nonce'] ?? ($_REQUEST['_ajax_nonce'] ?? ($_REQUEST['security'] ?? ''));
                $nonce_key   = self::normalize_nonce_key($nonce_key);

                // Correlation id (si fourni par le client)
                $cid    = isset($_REQUEST['cid']) ? sanitize_text_field((string) $_REQUEST['cid']) : null;
                $action = isset($_REQUEST['action']) ? sanitize_key((string) $_REQUEST['action']) : '';

                // Early probe logging BEFORE nonce verification, to help diagnose missing/non-matching nonces
                if (class_exists('YUZ_Logger')) {
                    try {
                        (new YUZ_Logger())->log('info', '[AJAX][PRE] request incoming', [
                            'remote_addr' => $_SERVER['REMOTE_ADDR'] ?? '',
                            'uri'         => $_SERVER['REQUEST_URI'] ?? '',
                            'action'      => $action,
                            'cid'         => $cid,
                            'post_keys'   => array_keys($_POST ?? []),
                            'has_nonce'   => (bool) $nonce_param,
                            'expected'    => $nonce_key,
                        ]);
                    } catch (\Throwable $ignored) {}
                }

                if (!$nonce_param || !wp_verify_nonce($nonce_param, $nonce_key)) {
                    if (class_exists('YUZ_Logger')) {
                        try {
                            (new YUZ_Logger())->log('error', '[AJAX][INVALID_NONCE]', [
                                'action'   => $action,
                                'cid'      => $cid,
                                'expected' => $nonce_key,
                                'hash'     => $nonce_param ? substr(md5((string) $nonce_param), 0, 8) : null,
                            ]);
                        } catch (\Throwable $ignored) {}
                    }
                    if ($obStarted) { ob_end_clean(); }
                    self::send_json_error([
                        'message'  => 'invalid_nonce',
                        'code'     => 'invalid_nonce',
                        'expected' => $nonce_key,
                    ], 403);
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

                // Correlation id  action
                if (class_exists('YUZ_Logger')) {
                    (new YUZ_Logger())->log('info', sprintf('[AJAX][IN] [%s][%s]', $cid ?: '-', $action));
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
                    $L = str_replace('-', '_', (string) $_REQUEST['language']);
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
                        if (class_exists('YUZ_Logger')) {
                            try { (new YUZ_Logger())->log('error', '[AJAX][REQUIRED_MISSING]', ['cid' => $cid, 'action' => $action, 'missing' => $key, 'keys' => array_keys($_REQUEST)]); } catch (\Throwable $ignored) {}
                        }
                        if ($obStarted) { ob_end_clean(); }
                        self::send_json_error($payload, 400);
                    }
                }

                // Exécute l’action
                $data = $callback ? call_user_func($callback, $_REQUEST) : [];

                // Vide et inspecte le tampon
                $noise = $obStarted ? ob_get_clean() : '';
                if (!empty($noise)) {
                    (new YUZ_Logger())->log('warning', 'Output captured before JSON', ['bytes' => strlen($noise)]);
                }

                self::send_json_success($data ?? []);
            } catch (\Throwable $e) {
                if ($obStarted) { @ob_end_clean(); }
                $cid    = isset($_REQUEST['cid']) ? sanitize_text_field((string) $_REQUEST['cid']) : null;
                $action = isset($_REQUEST['action']) ? sanitize_key((string) $_REQUEST['action']) : '';
                try {
                    (new YUZ_Logger())->log('error', 'AJAX handler threw', [
                        'cid'     => $cid,
                        'action'  => $action,
                        'type'    => get_class($e),
                        'message' => $e->getMessage(),
                        'file'    => $e->getFile(),
                        'line'    => $e->getLine(),
                        'keys'    => array_keys($_REQUEST ?? []),
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
            catch (\Throwable $e) { throw new \Exception('DB init failed: ' . $e->getMessage()); }
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

    // 1) Récup des langues (manager → DB → options)
    $trans = $this->language_manager->get_translatable_languages();
    $all   = $this->language_manager->get_all_languages();
    if (!is_array($trans) || empty($trans)) {
        $trans = $wpdb->get_results("SELECT * FROM $table WHERE is_translatable = 1 ORDER BY language_weight ASC");
        if (!is_array($trans) || empty($trans)) {
            $opt   = get_option('yuz_tra_general', []);
            $codes = array_values(array_unique(array_filter((array)($opt['yuz_tra_translatable_languages'] ?? []))));
            if (!empty($codes)) {
                $trans = array_map(fn($c)=>['language_code'=>$c,'is_translatable'=>1], $codes);
            }
        }
    }
    if (!is_array($all) || empty($all)) {
        $all = $wpdb->get_results("SELECT * FROM $table ORDER BY language_weight ASC");
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

    // 3) Non-translatables (planifiés)
    $non = array_values(array_filter($allN, fn($L)=> empty($L['is_translatable'])));

    // 4) Journaliser MAINTENANT (après calcul) - logger robuste (pas de méthode locale)
    if (class_exists('YUZ_Logger')) {
        (new YUZ_Logger())->log('debug', 'ws_payload', [
            'trans_count' => count($mergedTrans),
            'non_count'   => count($non),
            'codes'       => array_map(fn($r)=>$r['language_code'] ?? '', array_slice($mergedTrans, 0, 10)),
        ]);
    }

    return ['languages'=>$mergedTrans, 'non_translatable'=>$non];
}


        /* -------------------- WEBSITE LANGUAGES -------------------- */
public function yuz_tra_ws_get_languages() {
    // 1) Récup & normalisation du nonce (sans muter $_POST)
    $nonce_keys = ['yuz_tra_nonce', 'nonce', 'security'];
    $received   = [];
    foreach ($nonce_keys as $k) {
        if (isset($_POST[$k])) {
            $received[$k] = sanitize_text_field( wp_unslash($_POST[$k]) );
        }
    }
    $nonce = $received['yuz_tra_nonce'] ?? $received['nonce'] ?? $received['security'] ?? '';

    // 2) Logs d’entrée (pas de valeur brute du nonce)
    $this->log_debug('ajax-incoming-yuz_tra_ws_get_languages:begin', [
        'remote_addr' => $_SERVER['REMOTE_ADDR'] ?? '',
        'uri'         => $_SERVER['REQUEST_URI'] ?? '',
        'origin'      => $_SERVER['HTTP_ORIGIN'] ?? '',
        'referer'     => $_SERVER['HTTP_REFERER'] ?? '',
        'user'        => ['id'=>get_current_user_id(), 'logged_in'=>is_user_logged_in()],
        'post_keys'   => array_keys($_POST),
        'nonce_keys'  => array_keys($received),
        'nonce_sig'   => $nonce ? substr(md5($nonce), 0, 8) : null,
    ]);

    if ($nonce === '' || !wp_verify_nonce($nonce, 'yuz_tra_nonce')) {
        $this->log_debug('ajax-incoming-yuz_tra_ws_get_languages:verify', [
            'verify_yuz_tra_nonce' => false,
        ]);
        wp_send_json_error(['code'=>'bad_nonce','msg'=>'invalid_nonce'], 403);
    }

    $this->log_debug('ajax-incoming-yuz_tra_ws_get_languages:verify', [
        'verify_yuz_tra_nonce' => 1,
    ]);

    // 5) Construire le payload + métriques
    $payload = $this->build_ws_payload();
    $langs   = is_array($payload['languages'] ?? null) ? $payload['languages'] : [];
    $nontr   = is_array($payload['non_translatable'] ?? null) ? $payload['non_translatable'] : [];

    $this->log_debug('ajax-incoming-yuz_tra_ws_get_languages:payload', [
        'nb_total_langs'  => count($langs) + count($nontr),
        'nb_translatable' => count($langs),
        'nb_non_trans'    => count($nontr),
    ]);

    wp_send_json_success($payload);
}

/** Helper de log (ne divulgue pas de secrets) */
private function log_debug($tag, array $data) {
    if (class_exists('\YUZ\YUZ_Assets')) {
        \YUZ\YUZ_Assets::get()->log_debug($tag, $data);
    } elseif (class_exists('YUZ_Logger')) {
        (new YUZ_Logger())->log('debug', $tag, $data);
    } else {
    }
}


        public function yuz_tra_ws_cre_language() {
            // Vérif stricte via check_ajax_referer (clé front attendue: 'nonce')
            check_ajax_referer('yuz_tra_nonce', 'nonce');

            self::__handleRequest('yuz_tra_nonce',
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
                        "SELECT COUNT(*) FROM $table WHERE language_code = %s",
                        $code
                    ));

                    if ($exists === 0) {
                        (new YUZ_Logger())->log('info', "[WS_ADD] inserting new language {$code}");
                        // Métadonnées si dispo via référentiel
                        $langObj      = method_exists($this->language_manager, 'get_by_code') ? $this->language_manager->get_by_code($code) : null;
                        $languageName = $langObj->language_name ?? strtoupper($code);
                        $slug         = $langObj->slug ?? strtolower(str_replace('_','-',$code));
                        $browserSlug  = $langObj->browser_slug ?? strtolower(substr($code,0,2));

                        $maxWeight = (int)$wpdb->get_var("SELECT MAX(language_weight) FROM $table");
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
                            (new YUZ_Logger())->log('error', '[WS_ADD] insert failed', ['code'=>$code, 'error'=>$wpdb->last_error]);
                            throw new \Exception("DB insert failed: ".$wpdb->last_error);
                        }
                    } else {
                        // existe -> le rendre translatable
                        (new YUZ_Logger())->log('info', "[WS_ADD] enabling translatable for {$code}");
                        $ok = $wpdb->update(
                            $table,
                            ['is_translatable'=>1, 'updated_at'=>current_time('mysql')],
                            ['language_code'=>$code],
                            ['%d','%s'],
                            ['%s']
                        );
                        if ($ok === false) {
                            (new YUZ_Logger())->log('error', '[WS_ADD] update failed', ['code'=>$code, 'error'=>$wpdb->last_error]);
                            throw new \Exception("DB update failed: ".$wpdb->last_error);
                        }
                    }

                    // ---- Option miroir : ajouter le code aux listes translatables (legacy  nouveau)
                    $options = $this->get_settings()->get_option('yuz_tra_general');
                    if (!is_array($options)) { $options = []; }
                    $options['yuz_translatable_languages'] = $options['yuz_translatable_languages'] ?? [];
                    if (!in_array($code, $options['yuz_translatable_languages'], true)) {
                        $options['yuz_translatable_languages'][] = $code;
                    }
                    $options['yuz_tra_translatable_languages'] = $options['yuz_tra_translatable_languages'] ?? [];
                    if (!in_array($code, $options['yuz_tra_translatable_languages'], true)) {
                        $options['yuz_tra_translatable_languages'][] = $code;
                    }
                    unset($options['yuz_translatable_languages[]'], $options['yuz_tra_translatable_languages[]']);

                    // ---- Garantir default/source s'ils n'existent pas encore
                    $hasDefault = (int)$wpdb->get_var("SELECT COUNT(*) FROM $table WHERE is_default = 1");
                    $hasSource  = (int)$wpdb->get_var("SELECT COUNT(*) FROM $table WHERE is_source  = 1");

                    $madeDefault = false;
                    $madeSource  = false;

                    if ($hasDefault === 0) {
                        $wpdb->query("UPDATE $table SET is_default = 0");
                        $wpdb->update($table, ['is_default'=>1, 'updated_at'=>current_time('mysql')], ['language_code'=>$code], ['%d','%s'], ['%s']);
                        $options['yuz_default_language'] = $code;
                        $madeDefault = true;
                    }
                    if ($hasSource === 0) {
                        $wpdb->query("UPDATE $table SET is_source = 0");
                        $wpdb->update($table, ['is_source'=>1, 'updated_at'=>current_time('mysql')], ['language_code'=>$code], ['%d','%s'], ['%s']);
                        $options['yuz_source_language'] = $code;
                        $madeSource = true;
                    }

                    $this->get_settings()->update_option('yuz_tra_general', $options);

                    // Ensure fresh lists after mutation
                    $this->invalidate_language_caches([$code]);
                    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE language_code=%s", $code), ARRAY_A);
                    $payload = $this->build_ws_payload();
                    $payload['debug_row'] = $row;
                    $payload['message']      = __('Language created/activated', 'yuz_translation');
                    $payload['made_default'] = $madeDefault;
                    $payload['made_source']  = $madeSource;
                    (new YUZ_Logger())->log('success', '[WS_ADD] done', ['code'=>$code, 'row'=>$row]);
                    return $payload;
                }
            );
        }

        public function yuz_tra_ws_cre_alllang() {
            // Vérif stricte via check_ajax_referer (clé front attendue: 'nonce')
            check_ajax_referer('yuz_tra_nonce', 'nonce');

            self::__handleRequest('yuz_tra_nonce',
                [],
                function ($data) {
                    if (!current_user_can('manage_options')) {
                        wp_send_json_error(['message' => 'unauthorized', 'code' => 'unauthorized'], 403);
                    }
                    global $wpdb;
                    $this->ensure_db_tables();

                    $table_name = $wpdb->prefix . 'yuz_tra_languages';
                    if (!$wpdb->get_var("SHOW TABLES LIKE '$table_name'")) {
                        (new YUZ_Logger())->log('critical', "Languages table $table_name missing after recreation attempt");
                        return [];
                    }

                    $res = $wpdb->query("UPDATE $table_name SET is_translatable = 1, language_weight = IF(language_weight=0, 999, language_weight)");
                    if ($res === false) {
                        throw new \Exception("Failed to update all languages: " . $wpdb->last_error);
                    }

                    // options miroir (mettre à jour les deux clés et nettoyer les clés parasites)
                    $langs_col = $wpdb->get_col("SELECT language_code FROM $table_name WHERE is_translatable = 1");
                    $options   = $this->get_settings()->get_option('yuz_tra_general');
                    if (!is_array($options)) { $options = []; }
                    $options['yuz_translatable_languages'] = $langs_col ?: [];
                    $options['yuz_tra_translatable_languages'] = $langs_col ?: [];
                    unset($options['yuz_translatable_languages[]'], $options['yuz_tra_translatable_languages[]']);
                    // assurer default/source
                    $def = (int)$wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE is_default=1");
                    $src = (int)$wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE is_source=1");
                    if ($def===0 && !empty($langs_col)) {
                        $wpdb->update($table_name, ['is_default'=>1], ['language_code'=>$langs_col[0]], ['%d'], ['%s']);
                        $options['yuz_default_language'] = $langs_col[0];
                    }
                    if ($src===0 && !empty($langs_col)) {
                        $wpdb->update($table_name, ['is_source'=>1], ['language_code'=>$langs_col[0]], ['%d'], ['%s']);
                        $options['yuz_source_language'] = $langs_col[0];
                    }
                    $this->get_settings()->update_option('yuz_tra_general', $options);

                    $this->invalidate_language_caches();
                    return $this->build_ws_payload();
                }
            );
        }

        public function yuz_tra_ws_del_language() {
            self::__handleRequest('yuz_tra_nonce',
                ['language_code'],
                function ($data) {
                    if (!current_user_can('manage_options')) {
                        wp_send_json_error(['message' => 'unauthorized', 'code' => 'unauthorized'], 403);
                    }
                    global $wpdb;
                    $this->ensure_db_tables();

                    $table_name = $wpdb->prefix . 'yuz_tra_languages';
                    if (!$wpdb->get_var("SHOW TABLES LIKE '$table_name'")) {
                        (new YUZ_Logger())->log('critical', "Languages table $table_name missing after recreation attempt");
                        return [];
                    }

                    $code = sanitize_text_field($data['language_code']);
                    (new YUZ_Logger())->log('info', '[WS_DEL] disabling translatable', ['code'=>$code]);
                    $res  = $wpdb->update($table_name, ['is_translatable' => 0, 'updated_at' => current_time('mysql')], ['language_code' => $code], ['%d','%s'], ['%s']);
                    if ($res === false) {
                        (new YUZ_Logger())->log('error', '[WS_DEL] update failed', ['code'=>$code, 'error'=>$wpdb->last_error]);
                        throw new \Exception("Failed to update language {$code}: " . $wpdb->last_error);
                    }

                    $options = (array) $this->get_settings()->get_option('yuz_tra_general');
                    $list    = (array) ($options['yuz_tra_translatable_languages'] ?? []);
                    $options['yuz_tra_translatable_languages'] = array_values(array_diff($list, [$code]));
                    if (isset($options['yuz_translatable_languages']) && is_array($options['yuz_translatable_languages'])) {
                        $options['yuz_translatable_languages'] = array_values(array_diff((array) $options['yuz_translatable_languages'], [$code]));
                    }
                    unset($options['yuz_translatable_languages[]'], $options['yuz_tra_translatable_languages[]']);
                    $this->get_settings()->update_option('yuz_tra_general', $options); // bump version + caches

                    $this->invalidate_language_caches([$code]);
                    $payload = $this->build_ws_payload();
                    // Trace the resulting translatable list to help diagnose duplicates
                    try {
                        $langs = isset($payload['languages']) && is_array($payload['languages'])
                            ? array_map(function($L){ return is_array($L) ? ($L['language_code'] ?? '') : (is_object($L) ? ($L->language_code ?? '') : (string)$L); }, $payload['languages'])
                            : [];
                        (new YUZ_Logger())->log('info', '[WS_DEL] resulting languages', ['count' => count($langs), 'codes' => $langs]);
                    } catch (\Throwable $e) { /* no-op */ }
                    return $payload;
                }
            );
        }

        public function yuz_tra_ws_del_alllang() {
            self::__handleRequest('yuz_tra_nonce',
                [],
                function ($data) {
                    if (!current_user_can('manage_options')) {
                        wp_send_json_error(['message' => 'unauthorized', 'code' => 'unauthorized'], 403);
                    }
                    global $wpdb;
                    $this->ensure_db_tables();

                    $table_name = $wpdb->prefix . 'yuz_tra_languages';
                    if (!$wpdb->get_var("SHOW TABLES LIKE '$table_name'")) {
                        (new YUZ_Logger())->log('critical', "Languages table $table_name missing after recreation attempt");
                        return [];
                    }

                    (new YUZ_Logger())->log('info', '[WS_DEL_ALL] disabling all translatables');
                    $res = $wpdb->query("UPDATE $table_name SET is_translatable = 0 WHERE is_translatable = 1");
                    if ($res === false) {
                        (new YUZ_Logger())->log('error', '[WS_DEL_ALL] update failed', ['error'=>$wpdb->last_error]);
                        throw new \Exception("Failed to update all languages: " . $wpdb->last_error);
                    }

                    $options = get_option('yuz_tra_general', []);
                    if (!is_array($options)) { $options = []; }
                    $options['yuz_tra_translatable_languages'] = [];
                    $options['yuz_translatable_languages'] = [];
                    unset($options['yuz_translatable_languages[]'], $options['yuz_tra_translatable_languages[]']);
                    update_option('yuz_tra_general', $options, false);

                    $this->invalidate_language_caches();
                    return $this->build_ws_payload();
                }
            );
        }

        public function yuz_tra_ws_upd_weights() {
            self::__handleRequest('yuz_hvy_nonce',
                ['weights'],
                function ($data) {
                    if (!current_user_can('manage_options')) {
                        wp_send_json_error(['message' => 'unauthorized', 'code' => 'unauthorized'], 403);
                    }
                    global $wpdb;
                    $this->ensure_db_tables();

                    $table_name = $wpdb->prefix . 'yuz_tra_languages';
                    if (!$wpdb->get_var("SHOW TABLES LIKE '$table_name'")) {
                        (new YUZ_Logger())->log('critical', "Languages table $table_name missing after recreation attempt");
                        return [];
                    }

                    if (!is_array($data['weights']) || empty(array_filter($data['weights'], 'is_scalar'))) {
                        throw new \Exception('Invalid weights data');
                    }

                    foreach (wp_unslash($data['weights']) as $code => $weight) {
                        $code_sanitized = sanitize_text_field($code);
                        $weight_int     = intval($weight);
                        $res = $wpdb->update($table_name, ['language_weight' => $weight_int, 'updated_at' => current_time('mysql')], ['language_code' => $code_sanitized], ['%d','%s'], ['%s']);
                        if ($res === false) {
                            (new YUZ_Logger())->log('critical', "Update failed for {$code_sanitized}: " . $wpdb->last_error);
                        }
                    }

                    $this->invalidate_language_caches(array_keys((array)($data['weights'] ?? [])));
                    return $this->build_ws_payload();
                }
            );
        }

        public function yuz_tra_ws_upd_swapsrc() {
            self::__handleRequest('yuz_tra_nonce',
                ['new_source_code'],
                function ($data) {
                    if (!current_user_can('manage_options')) {
                        wp_send_json_error(['message' => 'unauthorized', 'code' => 'unauthorized'], 403);
                    }
                    $new = sanitize_text_field($data['new_source_code']);
                    $ok  = $this->language_manager->swap_source_and_target($new);
                    if (!$ok) {
                        throw new \Exception("Source swap failed for {$new}");
                    }

                    $this->invalidate_language_caches([$new]);
                    $payload = $this->build_ws_payload();
                    $src   = $this->language_manager->get_source_language();
                    $payload['source_language'] = $this->normalize_lang_item($src);
                    return $payload;
                }
            );
        }

        public function yuz_tra_ws_upd_settings() {
    self::__handleRequest('yuz_tra_nonce',
        ['website_languages'],
        function ($data) {
            if (!current_user_can('yuz_translate_content')) {
                wp_send_json_error(['message'=>'unauthorized','code'=>'unauthorized'], 403);
            }
            global $wpdb;
            $this->ensure_db_tables();

            $in    = (array) $data['website_languages'];
            $table = $wpdb->prefix . 'yuz_tra_languages';

            // -- 0) Parse & sanitize inputs -----------------------------------
            $hasTrans = array_key_exists('yuz_translatable_languages', $in)
                        && is_array($in['yuz_translatable_languages'])
                        && count($in['yuz_translatable_languages']) > 0;

            $trans = $hasTrans
                ? array_values(array_unique(array_map('sanitize_text_field', $in['yuz_translatable_languages'])))
                : [];

            // UI accepts both new and legacy keys; we store *manual* overrides only.
            $defRaw = $in['yuz_default_language'] ?? $in['yuz_tra_default_language'] ?? '';
            $srcRaw = $in['yuz_source_language']  ?? $in['yuz_tra_source_language']  ?? '';
            $defMan = !empty($defRaw) ? sanitize_text_field($defRaw) : '';
            $srcMan = !empty($srcRaw) ? sanitize_text_field($srcRaw) : '';

            $slugs = (isset($in['yuz_slug']) && is_array($in['yuz_slug']))
                ? array_map('sanitize_text_field', $in['yuz_slug'])
                : [];

            $codes = (isset($in['yuz_code']) && is_array($in['yuz_code']))
                ? array_map('sanitize_text_field', $in['yuz_code'])
                : [];

            // -- 1) Translatable list (differential, no global wipe if not provided)
            if ($hasTrans) {
                // Disable translatable for codes NOT in submitted list
                $placeholders = implode(',', array_fill(0, count($trans), '%s'));
                $sql = "UPDATE $table SET is_translatable = 0
                        WHERE is_translatable = 1
                          AND language_code NOT IN ($placeholders)";
                $wpdb->query($wpdb->prepare($sql, $trans));

                // Ensure each translatable exists, enable and set weight
                $weight = 1;
                foreach ($trans as $tCode) {
                    $id = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE language_code = %s", $tCode));
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
            $opt = $this->settings->get_option('yuz_tra_general', []);
            if (!is_array($opt)) { $opt = []; }

            // Manual overrides act as "switch": presence = manual, empty = back to auto
            if (array_key_exists('yuz_default_language', $in) || array_key_exists('yuz_tra_default_language', $in)) {
                $opt['yuz_tra_default_manual'] = $defMan; // may be '' to revert to auto
            }
            if (array_key_exists('yuz_source_language', $in) || array_key_exists('yuz_tra_source_language', $in)) {
                $opt['yuz_tra_source_manual'] = $srcMan;   // may be '' to revert to auto
            }

            if ($hasTrans) {
                $opt['yuz_tra_translatable_languages'] = $trans;
            }
            if (!empty($slugs)) { $opt['yuz_tra_slug'] = $slugs; }
            if (!empty($codes)) { $opt['yuz_tra_code'] = $codes; }

            // Clean legacy UI keys to avoid drift
            unset($opt['yuz_translatable_languages'], $opt['yuz_default_language'], $opt['yuz_source_language'], $opt['yuz_slug'], $opt['yuz_code']);

            $this->settings->update_option('yuz_tra_general', $opt);

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
                $opt['yuz_tra_default_manual'] ?? null,
                $opt['yuz_tra_source_manual']  ?? null,
            ]);
            if ($hasTrans) {
                $invalidate = array_unique(array_merge($invalidate, $trans));
            }

            $this->invalidate_language_caches($invalidate);

            (new YUZ_Logger())->log('success', 'Website languages options updated (manual overrides + sync)');
            return $this->build_ws_payload();
        },
        true
    );
}


        public function yuz_tra_ws_upd_deflang() {
            self::__handleRequest('yuz_con_nonce',
                [],
                function ($data) {
                    if (!current_user_can('yuz_translate_content')) {
                        wp_send_json_error(['message'=>'unauthorized','code'=>'unauthorized'],403);
                    }
                    global $wpdb;
                    $this->ensure_db_tables();

                    $table_name = $wpdb->prefix . 'yuz_tra_languages';
                    if (!$wpdb->get_var("SHOW TABLES LIKE '$table_name'")) {
                        (new YUZ_Logger())->log('critical', "Languages table $table_name missing after recreation attempt");
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
                        throw new \Exception("Failed to set default language {$language_code}: " . $wpdb->last_error);
                    }

                    $options = get_option('yuz_tra_general', []);
                    $options['yuz_tra_default_language'] = $language_code;
                    update_option('yuz_tra_general', $options, false);

                    $this->language_manager->enforce_language_rules(null, $language_code);

                    $this->invalidate_language_caches([$language_code]);
                    $payload = $this->build_ws_payload();
                    $payload['message'] = __('Default language set', 'yuz_translation');
                    return $payload;
                },
                true
            );
        }

        public function yuz_tra_ws_upd_srclang() {
            self::__handleRequest('yuz_con_nonce',
                [],
                function ($data) {
                    if (!current_user_can('yuz_translate_content')) {
                        wp_send_json_error(['message'=>'unauthorized','code'=>'unauthorized'],403);
                    }
                    global $wpdb;
                    $this->ensure_db_tables();

                    $table_name = $wpdb->prefix . 'yuz_tra_languages';
                    if (!$wpdb->get_var("SHOW TABLES LIKE '$table_name'")) {
                        (new YUZ_Logger())->log('critical', "Languages table $table_name missing after recreation attempt");
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
                        throw new \Exception("Failed to set source language {$language_code}: " . $wpdb->last_error);
                    }

                    $options = get_option('yuz_tra_general', []);
                    $options['yuz_tra_source_language'] = $language_code;
                    update_option('yuz_tra_general', $options, false);

                    $this->language_manager->enforce_language_rules($language_code);

                    $this->invalidate_language_caches([$language_code]);
                    $payload = $this->build_ws_payload();
                    $payload['message'] = __('Source language set', 'yuz_translation');
                    return $payload;
                },
                true
            );
        }

        public function yuz_tra_ws_upd_translatable() {
            self::__handleRequest('yuz_con_nonce',
                ['language_code', 'is_translatable'],
                function ($data) {
                    if (!current_user_can('yuz_translate_content')) {
                        wp_send_json_error(['message'=>'unauthorized','code'=>'unauthorized'],403);
                    }
                    global $wpdb;
                    $this->ensure_db_tables();

                    $table_name = $wpdb->prefix . 'yuz_tra_languages';
                    if (!$wpdb->get_var("SHOW TABLES LIKE '$table_name'")) {
                        (new YUZ_Logger())->log('critical', "Languages table $table_name missing after recreation attempt");
                        return [];
                    }

                    $language_code   = sanitize_text_field($data['language_code']);
                    $is_translatable = intval($data['is_translatable']);

                    $max_weight = (int) $wpdb->get_var("SELECT MAX(language_weight) FROM $table_name WHERE is_translatable = 1");
                    $new_weight = $is_translatable ? ($max_weight  +1) : 0; 

                    $res = $wpdb->update(
                        $table_name,
                        ['is_translatable' => $is_translatable, 'language_weight' => $new_weight, 'updated_at' => current_time('mysql')],
                        ['language_code' => $language_code],
                        ['%d','%d','%s'],
                        ['%s']
                    );
                    if ($res === false) {
                        throw new \Exception("Failed to update translatable status for {$language_code}: " . $wpdb->last_error);
                    }

                    $fresh = $wpdb->get_col("SELECT language_code FROM {$table_name} WHERE is_translatable = 1 ORDER BY language_weight ASC");
                    if (!is_array($fresh)) {
                        $fresh = [];
                    }
                    $fresh = array_values(array_filter(array_map('strval', $fresh), 'strlen'));

                    $options = get_option('yuz_tra_general', []);
                    if (!is_array($options)) {
                        $options = [];
                    }
                    $options['yuz_tra_translatable_languages'] = $fresh;
                    // Legacy mirrors stay aligned to avoid stale UIs.
                    $options['yuz_translatable_languages'] = $fresh;
                    unset($options['yuz_translatable_languages[]'], $options['yuz_tra_translatable_languages[]']);
                    update_option('yuz_tra_general', $options, false);

                    $this->language_manager->enforce_language_rules(null, null, $language_code);

                    $this->invalidate_language_caches([$language_code]);
                    $payload = $this->build_ws_payload();
                    $payload['message'] = __('Translatable status updated', 'yuz_translation');
                    return $payload;
                },
                true
            );
        }

        /** -------------------- LANGUAGE SETTINGS -------------------- */
        public function yuz_tra_ls_get_settings() {
            $settings_provider = $this->get_settings();

            self::__handleRequest('yuz_tra_nonce',
                [],
                function ($data) use ($settings_provider) {
                    if (!current_user_can('yuz_translate_content')) {
                        wp_send_json_error(['message'=>'unauthorized','code'=>'unauthorized'],403);
                    }
                    $settings = (array) $settings_provider->get_option('yuz_tra_ls_settings');

                    return [
                        'native_language_name' => !empty($settings['native_language_name'] ?? false),
                        'use_subdirectory'     => !empty($settings['use_subdirectory'] ?? false),
                        'force_lang_in_links'  => !empty($settings['force_lang_in_links'] ?? false),
                    ];
                },
                true
            );
        }

        public function yuz_tra_ls_upd_settings() {
            $logger = $this->logger;

            self::__handleRequest('yuz_con_nonce',
                ['language_settings'],
                function ($data) use ($logger) {
                    if (!current_user_can('manage_options')) {
                        wp_send_json_error(['message' => 'unauthorized', 'code' => 'unauthorized'], 403);
                    }

                    $payload = isset($data['language_settings']) ? (array) $data['language_settings'] : [];

                    $all      = function_exists('yuz_settings_get_all') ? yuz_settings_get_all() : [];
                    $defaults = function_exists('yuz_settings_section_default') ? yuz_settings_section_default('yuz_tra_ls_settings') : [];
                    $existing = is_array($all) && isset($all['yuz_tra_ls_settings']) && is_array($all['yuz_tra_ls_settings'])
                        ? $all['yuz_tra_ls_settings']
                        : (is_array($defaults) ? $defaults : []);

                    if ($logger) {
                        $logger->log('info', '[AJAX][LS_UPD] before=' . wp_json_encode($existing));
                    }

                    $clean = function_exists('yuz_settings_sanitize_section')
                        ? yuz_settings_sanitize_section('yuz_tra_ls_settings', $payload)
                        : (is_array($payload) ? $payload : []);

                    $was_subdir = function_exists('yuz_settings_truthy')
                        ? yuz_settings_truthy($existing['use_subdirectory'] ?? '')
                        : !empty($existing['use_subdirectory']);
                    $mutated = $clean !== $existing;

                    $ok = function_exists('yuz_settings_update_all')
                        ? yuz_settings_update_all(['yuz_tra_ls_settings' => $clean])
                        : false;

                    $after_all = function_exists('yuz_settings_get_all') ? yuz_settings_get_all() : [];
                    $saved     = is_array($after_all) && isset($after_all['yuz_tra_ls_settings']) && is_array($after_all['yuz_tra_ls_settings'])
                        ? $after_all['yuz_tra_ls_settings']
                        : $clean;

                    if ($logger) {
                        $logger->log('info', '[AJAX][LS_UPD] after=' . wp_json_encode($saved));
                    }

                    $is_subdir = function_exists('yuz_settings_truthy')
                        ? yuz_settings_truthy($saved['use_subdirectory'] ?? '')
                        : !empty($saved['use_subdirectory']);

                    if ($is_subdir !== $was_subdir) {
                        if (function_exists('flush_rewrite_rules')) {
                            flush_rewrite_rules();
                        }
                        if (class_exists('YUZ_Logger')) {
                            (new YUZ_Logger())->log('info', 'Flushed rewrite rules due to use_subdirectory change');
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
                            'message'  => __('Language settings updated', 'yuz_translation'),
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
        public function yuz_tra_sw_get_settings() {
            self::__handleRequest('yuz_tra_nonce',
                [],
                function ($data) {
                    if (!current_user_can('yuz_translate_content')) {
                        wp_send_json_error(['message'=>'unauthorized','code'=>'unauthorized'],403);
                    }
                    // Lire via le provider central (yuz_tra_all_settings) pour garder l'UI et le stockage alignés
                    $opts = $this->get_settings()->get_option('yuz_tra_sw_settings');
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

        public function yuz_tra_sw_upd_settings() {
            $logger = $this->logger;

            self::__handleRequest('yuz_con_nonce',
                ['switcher_settings'],
                function ($data) use ($logger) {
                    if ( ! current_user_can('manage_options') ) {
                        wp_send_json_error(['message'=>'unauthorized','code'=>'unauthorized'],403);
                    }

                    $payload = isset($data['switcher_settings']) ? (array) $data['switcher_settings'] : [];

                    $all      = function_exists('yuz_settings_get_all') ? yuz_settings_get_all() : [];
                    $defaults = function_exists('yuz_settings_section_default') ? yuz_settings_section_default('yuz_tra_sw_settings') : [];
                    $existing = is_array($all) && isset($all['yuz_tra_sw_settings']) && is_array($all['yuz_tra_sw_settings'])
                        ? $all['yuz_tra_sw_settings']
                        : (is_array($defaults) ? $defaults : []);

                    if ($logger) {
                        $logger->log('info', '[AJAX][SW_UPD] before=' . wp_json_encode($existing));
                    }

                    $clean = function_exists('yuz_settings_sanitize_section')
                        ? yuz_settings_sanitize_section('yuz_tra_sw_settings', $payload)
                        : (is_array($payload) ? $payload : []);

                    $mutated = $clean !== $existing;

                    $ok = function_exists('yuz_settings_update_all')
                        ? yuz_settings_update_all(['yuz_tra_sw_settings' => $clean])
                        : false;

                    $after_all = function_exists('yuz_settings_get_all') ? yuz_settings_get_all() : [];
                    $saved     = is_array($after_all) && isset($after_all['yuz_tra_sw_settings']) && is_array($after_all['yuz_tra_sw_settings'])
                        ? $after_all['yuz_tra_sw_settings']
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
                            'message'  => __('Language switcher settings updated', 'yuz_translation'),
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

        public function yuz_tra_sw_switch_language() {
            self::__handleRequest('yuz_tra_nonce',
                ['language_code'],
                function ($data) {
                    global $wpdb;
                    $this->ensure_db_tables();

                    $lang_table = $wpdb->prefix . 'yuz_tra_languages';
                    if (!$wpdb->get_var("SHOW TABLES LIKE '$lang_table'")) {
                        (new YUZ_Logger())->log('critical', "Languages table $lang_table missing after recreation attempt");
                        return [];
                    }

                    $language_code = $this->normalize_language_code(sanitize_text_field($data['language_code']));

                    $exists = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $lang_table WHERE language_code = %s AND is_translatable = 1", $language_code));
                    if (!$exists) {
                        (new YUZ_Logger())->log('error', "Invalid or non-translatable language code: {$language_code}");
                        throw new \Exception("Invalid or non-translatable language code: {$language_code}");
                    }

                    // Pass settings to the converter to avoid "not enough languages" and ensure correct URL rules
                    $settings = null;
                    if (class_exists('YUZ_Services') && method_exists('YUZ_Services', 'settings')) {
                        $settings = YUZ_Services::settings();
                    } elseif (class_exists('YUZ_Settings')) {
                        $settings = new YUZ_Settings(
                            new \YUZTRA\Fallbacks\NullLanguages(),
                            new \YUZTRA\Fallbacks\NullAjax(),
                            new \YUZTRA\Fallbacks\NullTranslationManager(),
                            new \YUZTRA\Fallbacks\NullLanguageManager(),
                            new \YUZTRA\Fallbacks\NullLogger()
                        );
                    }
                    $url_converter = new YUZ_Url_Converter($settings);
                    $current_url   = $url_converter->cur_page_url();
                    $new_url       = $url_converter->get_url_for_language($language_code, $current_url);

                    return [
                        'message' => __('Language switched successfully', 'yuz_translation'),
                        // IMPORTANT: key expected by front-end fallback is "url"
                        'url'     => esc_url($new_url)
                    ];
                }
           
            );
        }

        public function yuz_tra_sw_resolve_url() {
            self::__handleRequest('yuz_tra_nonce',
                ['language_code'],
                function ($data) {
                    $language_code = $this->normalize_language_code(sanitize_text_field($data['language_code'] ?? ''));
                    if ($language_code === '') {
                        throw new \Exception('Missing language_code');
                    }

                    $current_url = isset($data['current_url'])
                        ? esc_url_raw($data['current_url'])
                        : '';

                    $settings = null;
                    if (class_exists('YUZ_Services') && method_exists('YUZ_Services', 'settings')) {
                        $settings = YUZ_Services::settings();
                    } elseif (class_exists('YUZ_Settings')) {
                        $settings = new YUZ_Settings(
                            new \YUZTRA\Fallbacks\NullLanguages(),
                            new \YUZTRA\Fallbacks\NullAjax(),
                            new \YUZTRA\Fallbacks\NullTranslationManager(),
                            new \YUZTRA\Fallbacks\NullLanguageManager(),
                            new \YUZTRA\Fallbacks\NullLogger()
                        );
                    }
                    $url_converter = new YUZ_Url_Converter($settings);

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
        public function yuz_tra_ts_get_settings() {
            self::__handleRequest('yuz_tra_nonce',
                [],
                function ($data) {
                    $settings = $this->get_settings()->get_option('yuz_tra_ts_settings');
                    if (!is_array($settings)) {
                        $settings = [];
                    }
                    if (function_exists('yuz_settings_sanitize_section')) {
                        $settings = yuz_settings_sanitize_section('yuz_tra_ts_settings', $settings);
                    }
                    if (empty($settings)) {
                        throw new \Exception('Failed to retrieve site settings');
                    }
                    return $settings;
                }
            );
        }

        public function yuz_tra_ts_upd_settings() {
    self::__handleRequest('yuz_con_nonce',
        // Accept both legacy and new payload keys to avoid persistence issues
        ['translate_site_settings','site_settings'],
        function ($data) {
            if ($this->logger) {
                $this->logger->log('info', '[AJAX][TS_UPD] incoming payload', [
                    'has_nonce'      => isset($_REQUEST['nonce']),
                    'nonce_preview'  => isset($_REQUEST['nonce']) ? substr((string) $_REQUEST['nonce'], 0, 8) . '…' : null,
                    'can_translate'  => current_user_can('yuz_translate_content'),
                    'can_manage'     => current_user_can('manage_options'),
                    'raw_keys'       => array_keys((array) ($data['translate_site_settings'] ?? $data['site_settings'] ?? [])),
                ]);
            }
            if (!current_user_can('yuz_translate_content') && !current_user_can('manage_options')) {
                wp_send_json_error(['message'=>'unauthorized','code'=>'unauthorized'],403);
            }
            // Support both names: JS may send site_settings; older code used translate_site_settings
            $raw = (array) ($data['translate_site_settings'] ?? $data['site_settings'] ?? []);
            if (empty($raw)) {
                throw new \Exception('Invalid site settings data');
            }
            $clean  = $this->get_settings()->sanitize_option('yuz_tra_ts_settings', $raw);
            $result = $this->get_settings()->update_option('yuz_tra_ts_settings', $clean);

            /* PATCH 4.4 — Sauvegarde des rôles autorisés  sync caps */
            if ( isset($clean['allowed_roles']) ) {
                $allowed = array_map('sanitize_key', (array) $clean['allowed_roles']);
                // nettoie les entrées vides et dédoublonne
                $allowed = array_values(array_unique(array_filter($allowed)));
                if ( empty($allowed) ) { $allowed = ['administrator']; }
                update_option('yuz_tra_allowed_roles', $allowed);
                if ( function_exists('yuz_tra_sync_caps_from_option') ) {
                    yuz_tra_sync_caps_from_option();
                }
            }

            if ($result === false && get_option('yuz_tra_ts_settings') !== $clean) {
                throw new \Exception('Failed to update site settings');
            }

            delete_option('yuz_tra_site_settings');

            if ($this->logger) {
                $this->logger->log('info', '[AJAX][TS_UPD] success', ['settings' => $clean]);
            }

            return ['message' => __('Translate site settings updated', 'yuz_translation'), 'settings' => $clean];
        }
    );
}


       public function yuz_tra_ts_cre_fulltra() {
    self::__handleRequest('yuz_hvy_nonce',
        [],
        function ($data) {
            if (!current_user_can('yuz_translate_content')) {
                wp_send_json_error(['message'=>'unauthorized','code'=>'unauthorized'],403);
            }
            // run_full_site_translation() ne retourne rien → pas de test
            $this->translation_manager->run_full_site_translation();

            return [
                'message'   => __('Site translation triggered', 'yuz_translation'),
                'timestamp' => current_time('mysql'),
            ];
        },
        true
    );
}

public function yuz_tra_ts_start_translation() {
    self::__handleRequest('yuz_hvy_nonce',
        [],
        function ($data) {
            if (!current_user_can('yuz_translate_content')) {
                wp_send_json_error(['message'=>'unauthorized','code'=>'unauthorized'],403);
            }
            $this->translation_manager->run_full_site_translation();

            return [
                'message'   => __('Translation started successfully', 'yuz_translation'),
                'timestamp' => current_time('mysql'),
            ];
        },
        true
    );
}

        /** -------------------- TRANSLATION EDITOR -------------------- */
        public function yuz_tra_te_cre_tstart() {
            self::__handleRequest('yuz_tra_nonce',
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
            if (function_exists('yuz_normalize_language_code')) {
                return yuz_normalize_language_code($code);
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


        public function yuz_tra_te_cre_translation() {
            // Trace diagnostic d'entrée
            try {
                $logger = $this->logger ?: (class_exists('YUZ_Logger') ? new \YUZ_Logger() : new \YUZTRA\Fallbacks\NullLogger());
                $trace  = isset($_REQUEST['yuz_trace']) ? sanitize_text_field((string) $_REQUEST['yuz_trace']) : '';
                $logger->log('info', '[TE][IN] create', [
                    'trace' => $trace,
                    'keys'  => array_keys((array) $_REQUEST),
                ]);
            } catch (\Throwable $e) {}
            if (!isset($_REQUEST['target_langs']) && isset($_REQUEST['target_lang'])) {
                $incoming = wp_unslash($_REQUEST['target_lang']);
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
                $original = $_REQUEST['original_text'];
                if (is_array($original)) {
                    $original = '';
                }
                $_REQUEST['text'] = wp_kses_post(wp_unslash($original));
            }

            self::__handleRequest('yuz_int_nonce',
                ['target_langs', 'text', 'page_url'],
                function ($data) {
                    global $wpdb;
                    $this->ensure_db_tables();

                    $logger = $this->logger ?: (class_exists('YUZ_Logger') ? new \YUZ_Logger() : new \YUZTRA\Fallbacks\NullLogger());

                    $lang_table  = $wpdb->prefix . 'yuz_tra_languages';
                    $trans_table = $wpdb->prefix . 'yuz_tra_translations';
                    if (!$wpdb->get_var("SHOW TABLES LIKE '$lang_table'") || !$wpdb->get_var("SHOW TABLES LIKE '$trans_table'")) {
                        $logger->log('critical', "Languages/Translations table missing after recreation attempt");
                        return [];
                    }

                    $requested_source_id = isset($data['source_lang_id']) ? (int) $data['source_lang_id'] : 0;
                    $source_lang_id      = $requested_source_id > 0 ? $requested_source_id : 0;

                    if (!$source_lang_id) {
                        $source_code = isset($data['source_lang']) ? $this->normalize_language_code((string) wp_unslash($data['source_lang'])) : '';
                        if ($source_code) {
                            $candidate = $wpdb->get_var($wpdb->prepare("SELECT id FROM $lang_table WHERE language_code = %s", $source_code));
                            if ($candidate) {
                                $source_lang_id = (int) $candidate;
                            }
                        }
                    }

                    if (!$source_lang_id) {
                        $fallback = $wpdb->get_var("SELECT id FROM $lang_table WHERE is_source = 1");
                        if ($fallback) {
                            $source_lang_id = (int) $fallback;
                        }
                    }

                    if (!$source_lang_id) {
                        // journalise pour aider au diagnostic côté front
                        $logger->log('error', '[TE] missing source_lang_id', [
                                'trace' => isset($data['yuz_trace']) ? (string)$data['yuz_trace'] : '',
                                'src'   => isset($data['source_lang']) ? (string)$data['source_lang'] : '',
                            ]);
                        throw new \Exception('Failed to retrieve source language ID: ' . $wpdb->last_error);
                    }

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
                                'trace' => isset($data['yuz_trace']) ? (string)$data['yuz_trace'] : '',
                                'raw'   => $data['target_langs'] ?? null,
                            ]);
                        throw new \Exception('Invalid target languages data');
                    }

                    $text = $data['text'] ?? '';
                    if (is_array($text)) {
                        $text = '';
                    }
                    $text = $this->normalize_newlines(wp_kses_post(wp_unslash($text)));
                    if ($text === '') {
                        $logger->log('warning', '[TE] empty text for creation', [
                                'trace' => isset($data['yuz_trace']) ? (string)$data['yuz_trace'] : '',
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

                    if (function_exists('yuz_tra_status_sanitize')) {
                        if ($mode === 'manual') {
                            $default_status = YUZ_TRA_STATUS_DRAFT;
                        } elseif ($mode === 'semi_auto') {
                            $default_status = YUZ_TRA_STATUS_IN_REVIEW;
                        } elseif ($mode === 'auto') {
                            $default_status = YUZ_TRA_STATUS_PUBLISHED;
                        } else {
                            $needs_review = in_array($origin, ['machine', 'dom'], true) || $is_batch;
                            $default_status = $needs_review ? YUZ_TRA_STATUS_IN_REVIEW : YUZ_TRA_STATUS_PUBLISHED;
                        }
                        $status = yuz_tra_status_sanitize($data['status'] ?? null, $default_status);
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
                    if (function_exists('yuz_tra_status_for_origin')) {
                        $status = yuz_tra_status_for_origin($origin, $status, $is_batch, $mode);
                    } elseif ($mode === 'auto') {
                        $status = defined('YUZ_TRA_STATUS_PUBLISHED') ? YUZ_TRA_STATUS_PUBLISHED : 4;
                    } elseif ($origin === 'machine' || $origin === 'dom' || $is_batch || $mode === 'semi_auto') {
                        $status = defined('YUZ_TRA_STATUS_REVIEW') ? YUZ_TRA_STATUS_REVIEW : 2;
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
                    if ($context === 'content' && method_exists('YUZ_HTML_Translator', '__construct')) {
                        $html_translator = new YUZ_HTML_Translator($this->translation_manager);
                    }

                    foreach ($target_langs as $code) {
                        $target_lang_id = $wpdb->get_var(
                            $wpdb->prepare("SELECT id FROM $lang_table WHERE language_code = %s", $code)
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
                            $sql = "SELECT id, translated_text FROM $trans_table WHERE " . implode(' AND ', $whereParts) . " LIMIT 1";
                            $existing_row = $wpdb->get_row($wpdb->prepare($sql, $params), ARRAY_A);
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

                            $sqlFallback = "SELECT id, translated_text FROM $trans_table WHERE " . implode(' AND ', $keyWhere) . " LIMIT 1";
                            $existing_row = $wpdb->get_row($wpdb->prepare($sqlFallback, $keyParams), ARRAY_A);
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
                                        "SELECT id, translated_text FROM {$trans_table} WHERE post_id = %d AND context = %s AND block_id = %s AND source_lang_id = %d AND target_lang_id = %d LIMIT 1",
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

        public function yuz_tra_te_upd_manual() {
            self::__handleRequest('yuz_int_nonce',
                ['translation_id', 'translated_text', 'target_lang'],
                function ($data) {
                    global $wpdb;
                    $this->ensure_db_tables();

                    $table_name = $wpdb->prefix . 'yuz_tra_translations';
                    $lang_table = $wpdb->prefix . 'yuz_tra_languages';
                    if (!$wpdb->get_var("SHOW TABLES LIKE '$table_name'") || !$wpdb->get_var("SHOW TABLES LIKE '$lang_table'")) {
                        (new YUZ_Logger())->log('critical', "Tables missing after recreation attempt");
                        return [];
                    }

                    $published_status = function_exists('yuz_tra_status_transition')
                        ? yuz_tra_status_transition('publish')
                        : 1;

                    $translation_id = intval($data['translation_id']);
                    $translated_text= wp_kses_post($data['translated_text']);
                    // YUZ: NORMALIZE NEWLINES (saisie manuelle)
                    $translated_text = $this->normalize_newlines($translated_text);
                    $context        = sanitize_text_field($data['context'] ?? '');
                    $target_lang    = sanitize_text_field($data['target_lang']);

                    $target_lang_id = $wpdb->get_var($wpdb->prepare("SELECT id FROM $lang_table WHERE language_code = %s", $target_lang));
                    if (!$target_lang_id) {
                        (new YUZ_Logger())->log('error', '[TE][MANUAL] invalid target lang', ['target_lang' => $target_lang, 'translation_id' => $translation_id]);
                        throw new \Exception("Invalid target language code: {$target_lang}");
                    }

                    $exists = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table_name WHERE id = %d", $translation_id));

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
                        (new YUZ_Logger())->log('error', '[TE][MANUAL] update failed', [
                            'id'    => $translation_id,
                            'lang'  => $target_lang,
                            'error' => $wpdb->last_error,
                        ]);
                        throw new \Exception("Failed to update/insert translation ID {$translation_id}: " . $wpdb->last_error);
                    }

                    try {
                        (new YUZ_Logger())->log('info', '[TE][MANUAL] saved', [
                            'id'      => $translation_id,
                            'lang'    => $target_lang,
                            'exists'  => $exists,
                            'user_id' => get_current_user_id(),
                        ]);
                    } catch (\Throwable $e) {}

                    return ['message' => __('Manual translation updated', 'yuz_translation')];
                }
            );
        }

        public function yuz_tra_te_upd_publish() {
            // Trace diagnostic d'entrée
            try {
                $logger = $this->logger ?: (class_exists('YUZ_Logger') ? new \YUZ_Logger() : new \YUZTRA\Fallbacks\NullLogger());
                $trace  = isset($_REQUEST['yuz_trace']) ? sanitize_text_field((string) $_REQUEST['yuz_trace']) : '';
                $logger->log('info', '[TE][IN] publish', [
                    'trace' => $trace,
                    'keys'  => array_keys((array) $_REQUEST),
                ]);
            } catch (\Throwable $e) {}

            self::__handleRequest('yuz_int_nonce',
                ['page_url', 'target_lang'],
                function ($data) {
                    global $wpdb;
                    $this->ensure_db_tables();

                    $trans_table = $wpdb->prefix . 'yuz_tra_translations';
                    $lang_table  = $wpdb->prefix . 'yuz_tra_languages';
                    if (!$wpdb->get_var("SHOW TABLES LIKE '$trans_table'") || !$wpdb->get_var("SHOW TABLES LIKE '$lang_table'")) {
                        (new YUZ_Logger())->log('critical', "Tables missing after recreation attempt");
                        return [];
                    }

                    $page_url    = esc_url_raw($data['page_url']);
                    $target_lang = sanitize_text_field($data['target_lang']);

                    $target_lang_id = $wpdb->get_var($wpdb->prepare("SELECT id FROM $lang_table WHERE language_code = %s", $target_lang));
                    if (!$target_lang_id) {
                        throw new \Exception("Invalid target language code: {$target_lang}");
                    }

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
                        (new YUZ_Logger())->log('warning', 'Unable to resolve post ID for publication', [
                            'page_url'    => $page_url,
                            'target_lang' => $target_lang,
                        ]);
                        throw new \Exception(__('Unable to resolve page for translation publication.', 'yuz_translation'));
                    }

                    $publish_status = function_exists('yuz_tra_status_transition')
                        ? yuz_tra_status_transition('publish')
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
                        $now = current_time('mysql');
                        foreach ($entries as $entry) {
                            $row = $wpdb->get_row(
                                $wpdb->prepare(
                                    "SELECT id, origin, post_id, target_lang_id FROM {$trans_table} WHERE id = %d",
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
                                throw new \Exception('Failed to publish translation #' . (int) $row['id'] . ': ' . $wpdb->last_error);
                            }
                            if ($result > 0) {
                                $updated_ids[] = (int) $row['id'];
                            }
                        }

                        return [
                            'message' => __('Translation published', 'yuz_translation'),
                            'updated' => count($updated_ids),
                            'ids'     => $updated_ids,
                        ];
                    }

                    $origin_clause = implode(',', array_fill(0, 3, '%s'));
                    $review_statuses = array_filter([
                        defined('YUZ_TRA_STATUS_MACHINE') ? (int) YUZ_TRA_STATUS_MACHINE : 1,
                        defined('YUZ_TRA_STATUS_REVIEW') ? (int) YUZ_TRA_STATUS_REVIEW : 2,
                        defined('YUZ_TRA_STATUS_QUEUED') ? (int) YUZ_TRA_STATUS_QUEUED : 3,
                    ], static function ($value) {
                        return is_int($value) && $value >= 0;
                    });
                    if (!$review_statuses) {
                        $review_statuses = [1, 2, 3];
                    }
                    $review_clause = implode(',', array_map('intval', $review_statuses));
                    $origin_params = ['manual', 'dock', 'gettext'];
                    $select_sql = "SELECT id FROM {$trans_table} WHERE post_id = %d AND target_lang_id = %d AND status IN ({$review_clause})";
                    $select_sql .= " AND (origin IN ({$origin_clause}) OR origin IS NULL OR origin = '')";
                    $ids_to_publish = $wpdb->get_col($wpdb->prepare($select_sql, array_merge([$post_id, $target_lang_id], $origin_params)));
                    if ($ids_to_publish === null) {
                        throw new \Exception("Failed to fetch translations for {$target_lang}: " . $wpdb->last_error);
                    }

                    $updated_rows = 0;
                    $timestamp    = current_time('mysql');
                    foreach (array_chunk($ids_to_publish, 200) as $chunk_ids) {
                        if (!$chunk_ids) {
                            continue;
                        }
                        $placeholders = implode(',', array_fill(0, count($chunk_ids), '%d'));
                        $sql = "UPDATE {$trans_table} SET status = %d, updated_at = %s WHERE id IN ({$placeholders})";
                        $params = array_merge([$publish_status, $timestamp], array_map('intval', $chunk_ids));
                        $prepared = $wpdb->prepare($sql, $params);
                        if ($prepared === false) {
                            throw new \Exception('Failed to prepare publish batch statement.');
                        }
                        $result = $wpdb->query($prepared);
                        if ($result === false) {
                            throw new \Exception('Failed to publish translations: ' . $wpdb->last_error);
                        }
                        $updated_rows += (int) $result;
                    }

                    if ($updated_rows === 0) {
                        (new YUZ_Logger())->log('info', 'No translations updated during publish', [
                            'post_id'   => $post_id,
                            'lang_id'   => $target_lang_id,
                            'page_url'  => $page_url,
                        ]);
                    } else {
                        (new YUZ_Logger())->log('success', 'Translations published', [
                            'post_id'   => $post_id,
                            'lang_id'   => $target_lang_id,
                            'rows'      => $updated_rows,
                        ]);
                    }

                    return [
                        'message' => __('Translation published', 'yuz_translation'),
                        'updated' => (int) $updated_rows,
                    ];
                }
            );
        }

        public function yuz_get_pending_translations() {
            self::__handleRequest(
                'yuz_int_nonce',
                ['ids'],
                function ($data) {
                    if (!is_user_logged_in()) {
                        wp_send_json_error(['message' => 'not_logged_in', 'code' => 'not_logged_in'], 403);
                    }
                    if (!current_user_can('edit_posts') && !current_user_can('manage_options') && !current_user_can('yuz_translate_content')) {
                        wp_send_json_error(['message' => 'forbidden'], 403);
                    }

                    global $wpdb;
                    $this->ensure_db_tables();
                    $trans_table = $wpdb->prefix . 'yuz_tra_translations';
                    if (!$wpdb->get_var("SHOW TABLES LIKE '$trans_table'")) {
                        throw new \Exception('Translations table missing');
                    }

                    $ids = $this->normalize_ajax_ids($data['ids'] ?? []);
                    if (!$ids) {
                        throw new \InvalidArgumentException('No translation IDs provided');
                    }

                    $placeholders = implode(',', array_fill(0, count($ids), '%d'));
                    $rows = $wpdb->get_results(
                        $wpdb->prepare(
                            "SELECT id, post_id, context, original_text, translated_text, status FROM {$trans_table} WHERE id IN ({$placeholders})",
                            $ids
                        ),
                        ARRAY_A
                    );
                    if ($rows === null) {
                        throw new \Exception('Failed to fetch translations: ' . $wpdb->last_error);
                    }

                    $catalog = function_exists('yuz_tra_status_catalog') ? yuz_tra_status_catalog() : [];
                    $filtered = [];
                    foreach ($rows as $row) {
                        $row_id = (int) ($row['id'] ?? 0);
                        if ($row_id <= 0) {
                            continue;
                        }
                        $status = (int) ($row['status'] ?? 0);
                        if (function_exists('yuz_tra_status_requires_review') && !yuz_tra_status_requires_review($status)) {
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

        public function yuz_publish_translations() {
            self::__handleRequest(
                'yuz_con_nonce',
                ['ids'],
                function ($data) {
                    if (!is_user_logged_in()) {
                        wp_send_json_error(['message' => 'not_logged_in', 'code' => 'not_logged_in'], 403);
                    }
                    if (!current_user_can('edit_posts') && !current_user_can('manage_options') && !current_user_can('yuz_translate_content')) {
                        wp_send_json_error(['message' => 'forbidden'], 403);
                    }

                    global $wpdb;
                    $this->ensure_db_tables();
                    $trans_table = $wpdb->prefix . 'yuz_tra_translations';
                    if (!$wpdb->get_var("SHOW TABLES LIKE '$trans_table'")) {
                        throw new \Exception('Translations table missing');
                    }

                    $ids = $this->normalize_ajax_ids($data['ids'] ?? []);
                    if (!$ids) {
                        throw new \InvalidArgumentException('No translation IDs provided');
                    }

                    $publish_status = function_exists('yuz_tra_status_transition')
                        ? yuz_tra_status_transition('publish')
                        : 2;
                    $allowed_statuses = [];
                    foreach (['YUZ_TRA_STATUS_MACHINE', 'YUZ_TRA_STATUS_REVIEW', 'YUZ_TRA_STATUS_QUEUED'] as $const) {
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

                    $result = $wpdb->query($wpdb->prepare($sql, $params));
                    if ($result === false) {
                        throw new \Exception('Failed to publish translations: ' . $wpdb->last_error);
                    }

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
        public function yuz_tra_at_get_api_settings() {
            self::__handleRequest('yuz_tra_nonce',
                [],
                function ($data) {
                    $settings = $this->get_settings()->get_option('yuz_tra_api_settings');
                    if (empty($settings)) {
                        throw new \Exception('Failed to retrieve API settings');
                    }
                    return $settings;
                }
            );
        }

        public function yuz_tra_at_upd_api_settings() {
            self::__handleRequest('yuz_con_nonce',
                ['automatic_translation_settings'],
                function ($data) {
                    if (!current_user_can('yuz_translate_content')) {
                        wp_send_json_error(['message'=>'unauthorized','code'=>'unauthorized'],403);
                    }
                    $settings  = (array) $data['automatic_translation_settings'];
                    $sanitized = $this->get_settings()->sanitize_option('yuz_tra_api_settings', $settings);
                    $result    = $this->get_settings()->update_option('yuz_tra_api_settings', $sanitized);

                    if ($result === false && get_option('yuz_tra_api_settings') !== $sanitized) {
                        throw new \Exception('Failed to update API settings');
                    }

                    $this->translation_manager->set_api_settings([
                        'api_type'        => $sanitized['api_provider'],
                        'endpoint'        => $sanitized[$sanitized['api_provider'] . '_url'] ?? $sanitized['custom_url'],
                        'api_key'         => $sanitized[$sanitized['api_provider'] . '_key'] ?? $sanitized['custom_key'],
                        'extra_settings'  => [
                            'alternatives'   => $sanitized['alternatives'] ?? null,
                            'deepl_free'     => $sanitized['deepl_free'] ?? null,
                            'google_project' => $sanitized['google_project'] ?? null,
                            'custom_auth'    => $sanitized['custom_auth']    ?? null,
                            'custom_method'  => $sanitized['custom_method']  ?? null,
                            'custom_format'  => $sanitized['custom_format']  ?? null,
                        ]
                    ]);

                    wp_clear_scheduled_hook('yuz_tra_batch_translate');
                    if (in_array($sanitized['translation_mode'] ?? '', ['silent', 'all'], true)) {
                        wp_schedule_event(time(), $sanitized['cron_interval'] ?? 'hourly', 'yuz_tra_batch_translate');
                        (new YUZ_Logger())->log('debug', 'Rescheduled WP-Cron event with interval: ' . ($sanitized['cron_interval'] ?? 'hourly'));
                    }

                    $test_connection = !empty($data['test_connection']);
                    if ($test_connection) {
                        $test_settings = [
                            'provider'        => $sanitized['api_provider'],
                            'endpoint'        => $sanitized[$sanitized['api_provider'] . '_url'] ?? $sanitized['custom_url'],
                            'api_key'         => $sanitized[$sanitized['api_provider'] . '_key'] ?? $sanitized['custom_key'],
                            'extra_settings'  => [
                                'alternatives'   => $sanitized['alternatives'] ?? null,
                                'deepl_free'     => $sanitized['deepl_free']     ?? null,
                                'google_project' => $sanitized['google_project'] ?? null,
                                'custom_auth'    => $sanitized['custom_auth']    ?? null,
                                'custom_method'  => $sanitized['custom_method']  ?? null,
                                'custom_format'  => $sanitized['custom_format']  ?? null,
                            ]
                        ];
                        $start_time     = microtime(true);
                        $test_result    = $this->translation_manager->test_api_conn($test_settings);
                        $end_time       = microtime(true);
                        $execution_time = round(($end_time - $start_time) * 1000, 2);
                    } else {
                        $test_result    = null;
                        $execution_time = 0;
                    }

                    return [
                        'message'            => __('Automatic Translation settings updated', 'yuz_translation'),
                        'settings'           => $sanitized,
                        'test_result'        => $test_result,
                        'execution_time_ms'  => $execution_time
                    ];
                },
                true
            );
        }

        // class-yuz-ajax.php

        public function yuz_tra_at_del_api_settings() {
            self::__handleRequest('yuz_con_nonce',
                [],
                function ($data) {
                    if (!current_user_can('yuz_translate_content')) {
                        wp_send_json_error(['message'=>'unauthorized','code'=>'unauthorized'],403);
                    }
                    delete_option('yuz_tra_api_settings');
                    return ['message' => __('Automatic translate settings reset', 'yuz_translation')];
                },
                true
            );
        }

        public function yuz_tra_at_get_api_test() {  // --> en erreur ici 
    // Vérif stricte via check_ajax_referer
    check_ajax_referer('yuz_api_nonce', 'nonce');

    self::__handleRequest('yuz_api_nonce',
        ['provider'], // endpoint/api_key validés conditionnellement ci-dessous
        function ($data) {
            $provider = sanitize_text_field($data['provider'] ?? '');
            $endpoint = esc_url_raw($data['endpoint'] ?? ($data['url'] ?? ''));
            $api_key  = sanitize_text_field($data['api_key'] ?? ($data['apiKey'] ?? ''));
            $extra_in = isset($data['extra_settings'])
                ? (array) wp_unslash($data['extra_settings'])
                : ( isset($data['extraSettings']) ? (array) wp_unslash($data['extraSettings']) : [] );

            $extra = array_map(
                static function ($v) { return is_scalar($v) ? sanitize_text_field((string) $v) : ''; },
                $extra_in
            );

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
            $result         = $this->translation_manager->test_api_conn([
                'provider'       => $provider,
                'endpoint'       => $endpoint,
                'api_key'        => $api_key,
                'extra_settings' => $extra,
            ]);
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


        public function yuz_tra_at_cre_tsilent() {
            self::__handleRequest('yuz_int_nonce',
                ['text'],
                function ($data) {
                    global $wpdb;
                    $this->ensure_db_tables();

                    $lang_table  = $wpdb->prefix . 'yuz_tra_languages';
                    $trans_table = $wpdb->prefix . 'yuz_tra_translations';
                    if (!$wpdb->get_var("SHOW TABLES LIKE '$lang_table'") || !$wpdb->get_var("SHOW TABLES LIKE '$trans_table'")) {
                        (new YUZ_Logger())->log('critical', "Tables missing after recreation attempt");
                        return [];
                    }

                    $source_lang_id = $wpdb->get_var("SELECT id FROM $lang_table WHERE is_source = 1");
                    if (!$source_lang_id) {
                        throw new \Exception('Failed to retrieve source language ID: ' . $wpdb->last_error);
                    }

                    $target_langs = $this->language_manager->get_translatable_languages();
                    if (!is_array($target_langs)) {
                        throw new \Exception('Failed to retrieve translatable languages');
                    }

                    $machine_status = defined('YUZ_TRA_STATUS_REVIEW') ? YUZ_TRA_STATUS_REVIEW : 1;

                    $text         = wp_kses_post($data['text']);
                    // YUZ: NORMALIZE NEWLINES (entrée source)
                    $text = $this->normalize_newlines($text);
                    $translations = [];

                    $html_translator = null;
                    if (strpos($text, '<') !== false) {
                        $html_translator = new YUZ_HTML_Translator($this->translation_manager);
                    }

                    foreach ($target_langs as $lang_obj) {
                        $lang_code = $this->get_lang_code($lang_obj);
                        if (!$lang_code) {
                            (new YUZ_Logger())->log('warning', "Skipping invalid language entry");
                            continue;
                        }

                        $target_lang_id = $wpdb->get_var($wpdb->prepare("SELECT id FROM $lang_table WHERE language_code = %s", $lang_code));
                        if (!$target_lang_id) {
                            (new YUZ_Logger())->log('warning', "Invalid target language code: {$lang_code}");
                            $translations[$lang_code] = __('Invalid target language', 'yuz_translation');
                            continue;
                        }

                        $translated = null;
                        if ($html_translator) {
                            $translated = $html_translator->translate_html($text, $source_lang_id, $target_lang_id);
                        } elseif (method_exists($this->translation_manager, 'translate')) {
                            $translated = $this->translation_manager->translate($text, $source_lang_id, $target_lang_id);
                        }

                        if ($translated !== null) {
                            // YUZ: NORMALIZE NEWLINES (résultat provider)
                            $translated = $this->normalize_newlines((string)$translated);
                            $result = $wpdb->insert($trans_table, [
                                'original_text'   => $text,
                                'translated_text' => $translated,  // YUZ: normalized
                                'source_lang_id'  => $source_lang_id,
                                'target_lang_id'  => $target_lang_id,
                                'language_code'   => $lang_code,
                                'status'          => $machine_status,
                                'created_at'      => current_time('mysql'),
                                'updated_at'      => current_time('mysql'),
                            ], ['%s', '%s', '%d', '%d', '%s', '%d', '%s', '%s']);
                            if ($result === false) {
                                (new YUZ_Logger())->log('critical', "Failed to insert translation for {$lang_code}: " . $wpdb->last_error);
                            } else {
                                (new YUZ_Logger())->log('success', "Translation inserted for {$lang_code}");
                            }
                            $translations[$lang_code] = $translated;
                        } else {
                            (new YUZ_Logger())->log('warning', "Translation failed for {$lang_code}");
                            $translations[$lang_code] = __('Translation failed', 'yuz_translation');
                        }
                    }

                    return ['message' => __('Silent translation completed', 'yuz_translation'), 'translations' => $translations];
                }
            );
        }

        /** -------------------- TRANSLATION MANAGER -------------------- */
        public function yuz_tra_tm_cre_translation() {
            self::__handleRequest('yuz_int_nonce',
                ['text', 'target_langs'],
                function ($data) {
                    global $wpdb;
                    $this->ensure_db_tables();

                    $lang_table  = $wpdb->prefix . 'yuz_tra_languages';
                    $trans_table = $wpdb->prefix . 'yuz_tra_translations';
                    if (!$wpdb->get_var("SHOW TABLES LIKE '$lang_table'") || !$wpdb->get_var("SHOW TABLES LIKE '$trans_table'")) {
                        (new YUZ_Logger())->log('critical', "Tables missing after recreation attempt");
                        return [];
                    }

                    $source_lang_id = $wpdb->get_var("SELECT id FROM $lang_table WHERE is_source = 1");
                    if (!$source_lang_id) {
                        throw new \Exception('Failed to retrieve source language ID: ' . $wpdb->last_error);
                    }

                    $target_langs = wp_unslash($data['target_langs']);
                    if (!is_array($target_langs) || empty(array_filter($target_langs, 'is_scalar'))) {
                        throw new \Exception('Invalid target languages data');
                    }

                    $review_status = defined('YUZ_TRA_STATUS_REVIEW') ? YUZ_TRA_STATUS_REVIEW : 1;

                    $text         = wp_kses_post($data['text']);
                    $context      = sanitize_text_field($data['context'] ?? '');
                    $translations = [];

                    $html_translator = null;
                    if (strpos($text, '<') !== false) {
                        $html_translator = new YUZ_HTML_Translator($this->translation_manager);
                    }

                    foreach ($target_langs as $target_lang) {
                        $target_lang    = sanitize_text_field($target_lang);
                        $target_lang_id = $wpdb->get_var($wpdb->prepare("SELECT id FROM $lang_table WHERE language_code = %s", $target_lang));
                        if (!$target_lang_id) {
                            (new YUZ_Logger())->log('warning', "Invalid target language code: {$target_lang}");
                            $translations[$target_lang] = __('Invalid target language', 'yuz_translation');
                            continue;
                        }

                        $translated_text = null;
                        if ($html_translator && ($context === 'content' || strpos($text, '<') !== false)) {
                            $translated_text = $html_translator->translate_html($text, $source_lang_id, $target_lang_id);
                            $translated_text = $this->normalize_newlines((string) $translated_text);
                        } else {
                            $translated_text = $this->translation_manager->translate($text, $source_lang_id, $target_lang_id);
                            $translated_text = $this->normalize_newlines((string)$translated_text);
                        }

                        if ($translated_text !== null) {
                            $result = $wpdb->insert($trans_table, [
                                'original_text'   => $text,
                                'translated_text' => $translated_text, // YUZ: normalized
                                'source_lang_id'  => $source_lang_id,
                                'target_lang_id'  => $target_lang_id,
                                'language_code'   => $target_lang,
                                'context'         => $context,
                                'status'          => $review_status,
                                'created_at'      => current_time('mysql'),
                                'updated_at'      => current_time('mysql'),
                            ], ['%s', '%s', '%d', '%d', '%s', '%s', '%d', '%s', '%s']);
                            if ($result === false) {
                                (new YUZ_Logger())->log('critical', "Failed to insert translation for {$target_lang}: " . $wpdb->last_error);
                            } else {
                                (new YUZ_Logger())->log('success', "Translation inserted for {$target_lang}");
                            }
                            $translations[$target_lang] = $translated_text;
                        } else {
                            (new YUZ_Logger())->log('warning', "Translation failed for {$target_lang}");
                            $translations[$target_lang] = __('Translation failed', 'yuz_translation');
                        }
                    }

                    return ['translations' => $translations];
                },
                true
            );
        }

        public function yuz_tra_tm_cre_page() {
            self::__handleRequest('yuz_int_nonce',
                ['page_id', 'target_lang'],
                function ($data) {
                    global $wpdb;
                    $this->ensure_db_tables();

                    $lang_table  = $wpdb->prefix . 'yuz_tra_languages';
                    $trans_table = $wpdb->prefix . 'yuz_tra_translations';
                    if (!$wpdb->get_var("SHOW TABLES LIKE '$lang_table'") || !$wpdb->get_var("SHOW TABLES LIKE '$trans_table'")) {
                        (new YUZ_Logger())->log('critical', "Tables missing after recreation attempt");
                        return [];
                    }

                    $page_id = intval($data['page_id']);
                    $post    = get_post($page_id);
                    if (!$post) {
                        throw new \Exception("Invalid post ID: {$page_id}");
                    }

                    $source_lang    = get_option('yuz_tra_general')['yuz_source_language'] ?? 'en_US';
                    $source_lang_id = $wpdb->get_var($wpdb->prepare("SELECT id FROM $lang_table WHERE language_code = %s", $source_lang));
                    if (!$source_lang_id) {
                        throw new \Exception("Invalid source language: {$source_lang}");
                    }

                    $target_lang    = sanitize_text_field($data['target_lang']);
                    $target_lang_id = $wpdb->get_var($wpdb->prepare("SELECT id FROM $lang_table WHERE language_code = %s", $target_lang));
                    if (!$target_lang_id) {
                        throw new \Exception("Invalid target language: {$target_lang}");
                    }

                    $review_status = defined('YUZ_TRA_STATUS_REVIEW') ? YUZ_TRA_STATUS_REVIEW : 1;

                    $content    = apply_filters('the_content', $post->post_content);
                    // YUZ: NORMALIZE NEWLINES (contenu WP)
                    $content    = $this->normalize_newlines($content);
                    $html_translator = new YUZ_HTML_Translator($this->translation_manager);
                    $translated = $html_translator->translate_html($content, $source_lang_id, $target_lang_id);
                    if (!is_string($translated) || $translated === '') {
                        throw new \Exception("Translation failed for page ID: {$page_id} to {$target_lang}");
                    }

                    // YUZ: NORMALIZE NEWLINES (résultat provider)
                    $translated = $this->normalize_newlines($translated);

                    update_post_meta($page_id, 'yuz_translation_' . $target_lang, $translated);

                    $result = $wpdb->insert($trans_table, [
                        'page_url'        => get_permalink($page_id),
                        'original_text'   => $content,
                        'translated_text' => $translated, // YUZ: normalized
                        'source_lang_id'  => $source_lang_id,
                        'target_lang_id'  => $target_lang_id,
                        'language_code'   => $target_lang,
                        'status'          => $review_status,
                        'created_at'      => current_time('mysql'),
                        'updated_at'      => current_time('mysql'),
                    ], ['%s', '%s', '%s', '%d', '%d', '%s', '%d', '%s', '%s']);
                    if ($result === false) {
                        (new YUZ_Logger())->log('critical', "Failed to store translation for ID: {$page_id}: " . $wpdb->last_error);
                    }

                    return ['translated_content' => $translated];
                }
            );
        }

        public function yuz_tra_tm_get_translations() {
        self::__handleRequest('yuz_tra_nonce',
            ['limit', 'offset'],
            function ($data) {
                global $wpdb;
                $this->ensure_db_tables();

                $trans_table = $wpdb->prefix . 'yuz_tra_translations';
                $lang_table  = $wpdb->prefix . 'yuz_tra_languages';
                if (!$wpdb->get_var("SHOW TABLES LIKE '$trans_table'") || !$wpdb->get_var("SHOW TABLES LIKE '$lang_table'")) {
                    (new YUZ_Logger())->log('critical', "Tables missing after recreation attempt");
                    return [];
                }

                // Inputs (tolerate both page_url and url)
                $page_url      = esc_url_raw($data['page_url'] ?? ($data['url'] ?? ''));
                $target_lang   = $this->normalize_language_code(sanitize_text_field($data['target_lang'] ?? ''));
                $limit         = max(1, intval($data['limit']));
                $offset        = max(0, intval($data['offset']));
                $wantBootstrap = !empty($data['bootstrap']);

                // Diagnostics
                if (function_exists('yuz_diag_log')) {
                    yuz_diag_log('ajax:start', [
                        'action' => current_action(),
                        'src'    => isset($data['src']) ? (string)$data['src'] : '',
                        'dst'    => $target_lang,
                        'url'    => $page_url,
                        'trace'  => $_POST['yuz_trace'] ?? '',
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

                // filtre page_url uniquement si la colonne existe
                if ($has_page_url && !empty($page_url)) {
                    $query   .= " AND t.page_url = %s";
                    $params[] = $page_url;
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
                $sql = $wpdb->prepare($query, ...$params);

                $translations = $wpdb->get_results($sql, ARRAY_A);
                if ($translations === null) {
                    throw new \Exception("Failed to retrieve translations: " . $wpdb->last_error);
                }

                // Payload de base
                $payload = [ 'translations' => $translations ];
                try {
                    (new YUZ_Logger())->log('info', '[TM_GET] result', [
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
                        "SELECT language_code, language_name
                        FROM $lang_table
                        WHERE is_translatable = 1
                        ORDER BY language_weight ASC, language_name ASC", ARRAY_A
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
                    $opts          = get_option('yuz_tra_general');
                    $src_from_opt  = is_array($opts) ? ($opts['yuz_source_language'] ?? $opts['yuz_tra_source_language'] ?? '') : '';
                    $src_from_db   = (string) $wpdb->get_var("SELECT language_code FROM $lang_table WHERE is_source = 1 LIMIT 1");
                    if ($src_from_db === '') {
                        $src_from_db = (string) $wpdb->get_var("SELECT language_code FROM $lang_table WHERE is_default = 1 LIMIT 1");
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
                    $payload['source_language']  = $source_lang;
                    $payload['current_language'] = $current_lang;
                    $payload['post_id']          = $post_id;

                    // Extraction des chaînes depuis le post
                    $strings = [];
                    if ($post_id) {
                        $html    = (string) apply_filters('the_content', (string) get_post_field('post_content', $post_id));
                        $strings = $this->yuz_te_extract_strings_from_html($html);
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

                    if (function_exists('yuz_diag_log')) {
                        yuz_diag_log('ajax:done', [
                            'action' => current_action(),
                            'count'  => is_array($strings) ? count($strings) : 0,
                            'trace'  => $_POST['yuz_trace'] ?? '',
                        ]);
                    }
                }

                return $payload;
            },
            true
        );
    }


       public function yuz_tra_tm_search() {
        // Sécurité
        check_ajax_referer('yuz_tra_nonce', 'nonce');

        // Inputs
        $q        = isset($_POST['q']) ? sanitize_text_field(wp_unslash($_POST['q'])) : '';
        $scope    = isset($_POST['scope']) ? sanitize_text_field($_POST['scope']) : 'page'; // 'page'|'instant'
        $target   = isset($_POST['target_lang']) ? sanitize_text_field($_POST['target_lang']) : '';
        $page_url = isset($_POST['page_url']) ? esc_url_raw($_POST['page_url']) : '';
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
                $sql = $wpdb->prepare("SHOW COLUMNS FROM {$table} LIKE %s", $col);
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
        public function yuz_tra_tm_translate() {
             self::__handleRequest(
                'yuz_hvy_nonce',
                ['payload'], // 'source'/'target' gérés souplement (from/to) dans la méthode
                function ($data) {
                    $batch_started = microtime(true);
                    $req_id = isset($data['req_id']) ? sanitize_text_field($data['req_id']) : '';
                    if ($req_id === '') {
                        $req_id = wp_generate_uuid4();
                    }

                    $source_raw = isset($data['from']) ? $data['from'] : ($data['source'] ?? 'auto');
                    $target_raw = isset($data['to']) ? $data['to'] : ($data['target'] ?? '');

                    $source_raw = sanitize_text_field((string) $source_raw);
                    $target_raw = sanitize_text_field((string) $target_raw);

                    $source_canon = yuz_canon_lang($source_raw !== '' ? $source_raw : 'auto');
                    $target_canon = yuz_canon_lang($target_raw);

                    if ($target_canon === '' || $target_canon === 'auto') {
                        self::send_json_error([
                            'message' => 'missing_target',
                            'req_id'  => $req_id,
                        ], 400);
                    }

                    $target_locale = yuz_resolve_target_locale($target_raw);
                    if ($target_locale === '') {
                        self::send_json_error([
                            'message' => 'invalid_target',
                            'req_id'  => $req_id,
                        ], 200);
                    }

                    $source_locale = $source_raw === '' ? 'auto' : yuz_norm_locale($source_raw);
                    if ($source_locale === '') {
                        $source_locale = 'auto';
                    }

                    if ($source_canon !== 'auto' && $source_canon === $target_canon) {
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
                        if ($text === '' && function_exists('yuz_strip_css_js_noise')) {
                            $text = trim((string) yuz_strip_css_js_noise($text_raw));
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

                    $this->log_ajax_entry('yuz_tra_tm_translate', [
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

                    if (function_exists('yuz_diag_log')) {
                        yuz_diag_log('ajax:start', [
                            'action' => current_action(),
                            'src'    => $source_code ?: $source_canon,
                            'dst'    => $target_code,
                            'url'    => '',
                            'trace'  => $_POST['yuz_trace'] ?? '',
                        ]);
                    }

                    if (defined('WP_DEBUG') && WP_DEBUG) {
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
                            $norm = yuz_norm_locale($code);
                            if ($norm === $source_locale || yuz_root($norm) === yuz_root($source_locale)) {
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

                    // Use adapter directly (no provider include)
                    require_once YUZ_TRA_INCLUDES . 'class-yuz-libre-translate-adapter.php';
                    $trace = isset($data['yuz_trace']) ? sanitize_text_field($data['yuz_trace']) : '';

                    $api_settings = get_option('yuz_tra_at_settings', []);
                    if (!is_array($api_settings) || empty($api_settings)) {
                        $api_settings = get_option('yuz_tra_api_settings', []);
                    }
                    if (!is_array($api_settings)) { $api_settings = []; }
                    $base_url = isset($api_settings['libre_url']) ? rtrim((string)$api_settings['libre_url'], '/') : '';
                    $api_key  = isset($api_settings['libre_key']) ? (string)$api_settings['libre_key'] : '';

                    $adapter = new \YUZ_Libre_Translate_Adapter(null, [
                        'endpoint' => $base_url,
                        'api_key'  => $api_key,
                    ]);

                    $out = [];
                    $provider_started = microtime(true);
                    $translated_list = [];
                    $used_batch = false;

                    // Try batch when >1 item and map results by position (order-safe, handles duplicates)
                    if (count($items) > 1 && method_exists($adapter, 'translate_batch')) {
                        $batch_texts = array_map(static function ($row) {
                            return (string) ($row['text'] ?? '');
                        }, $items);
                        $is_list = static function (array $arr): bool {
                            return array_keys($arr) === range(0, count($arr) - 1);
                        };

                        try {
                            $raw_batch = $adapter->translate_batch($batch_texts, $source_canon, $target_canon, []);

                            if (is_array($raw_batch)) {
                                if (isset($raw_batch['translatedText']) && is_array($raw_batch['translatedText'])) {
                                    $translated_list = array_values($raw_batch['translatedText']);
                                } elseif ($is_list($raw_batch)) {
                                    $translated_list = array_values($raw_batch);
                                } else {
                                    foreach ($batch_texts as $k => $src) {
                                        $translated_list[$k] = $raw_batch[$src] ?? null;
                                    }
                                }
                            }

                            if (!empty($translated_list)) {
                                $used_batch = true;
                            }
                        } catch (\Throwable $e) {
                            $translated_list = [];
                        }
                    }

                    foreach ($items as $k => $row) {
                        $idx  = (int) ($row['i'] ?? -1);
                        $text = (string) ($row['text'] ?? '');
                        if ($idx < 0 || $text === '') { continue; }
                        $last_error = '';
                        $translated = null;

                        if ($used_batch && array_key_exists($k, $translated_list)) {
                            $translated = $translated_list[$k];
                        }

                        if (!is_string($translated) || $translated === '') {
                            try {
                                $translated = $adapter->translate($text, $source_canon, $target_canon, []);
                            } catch (\Throwable $e) {
                                $translated = null;
                                $last_error = trim((string) $e->getMessage());
                                $this->log_ajax_exception('yuz_tra_tm_translate_item', $e, [
                                    'req_id' => $req_id,
                                    'index'  => $idx,
                                    'source' => $source_canon,
                                    'target' => $target_canon,
                                ]);
                            }
                        }

                        if ((!is_string($translated) || $translated === '') && $source_lang_id > 0 && $target_lang_id > 0 && method_exists($this->translation_manager, 'translate')) {
                            try {
                                $fallback = $this->translation_manager->translate($text, $source_lang_id, $target_lang_id);
                                if (is_string($fallback) && $fallback !== '') {
                                    $translated = $fallback;
                                    if (defined('WP_DEBUG') && WP_DEBUG) {
                                    }
                                }
                            } catch (\Throwable $fallbackEx) {
                                $this->log_ajax_exception('yuz_tra_tm_translate_fallback', $fallbackEx, [
                                    'req_id' => $req_id,
                                    'index'  => $idx,
                                    'source' => $source_canon,
                                    'target' => $target_canon,
                                ]);
                            }
                        }
                        if (is_string($translated) && $translated !== '') {
                            $out[$idx] = [ 'translated_text' => $translated, 'req_id' => $req_id . ':' . $idx ];
                        } else {
                            $err = $last_error !== '' ? $last_error : 'empty_from_provider';
                            $out[$idx] = [ 'error' => $err, 'req_id' => $req_id . ':' . $idx ];
                        }
                    }
                    $provider_ms = (int) round((microtime(true) - $provider_started) * 1000);

                    $this->log_ajax_entry('yuz_tra_tm_translate_out', [
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
                            $txt = mb_substr($txt, 0, 10000);
                            $translated_count++;
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
                        $diag_first = '';
                        $first_err = '';
                        if (!empty($bag)) {
                            $first = reset($bag);
                            if (is_object($first)) {
                                $first = (array) $first;
                            }
                            if (is_array($first)) {
                                $diag_first = wp_json_encode(array_slice($first, 0, 4), JSON_UNESCAPED_UNICODE);
                                if (isset($first['error']) && is_string($first['error']) && $first['error'] !== '') {
                                    $first_err = substr($first['error'], 0, 160);
                                }
                            } elseif (is_string($first)) {
                                $diag_first = mb_substr($first, 0, 160, 'UTF-8');
                                $first_err = $diag_first;
                            }
                        }
                        $this->trace_log('AUTO.BATCH.OUT', [
                            'req'   => $req_id,
                            'from'  => $source_canon,
                            'to'    => $target_canon,
                            'ms'    => $provider_ms,
                            'ok'    => false,
                            'count' => 0,
                            'total' => count($items),
                        ]);
                        if (defined('WP_DEBUG') && WP_DEBUG) {
                            $raw = substr(wp_json_encode($response, JSON_UNESCAPED_UNICODE), 0, 1024);
                            $payload_raw = substr(wp_json_encode($payload, JSON_UNESCAPED_UNICODE), 0, 1024);
                        }
                        // SAFETY NET: if raw text exists, fallback to echo source as translation to avoid blocking UI
                        $fallback = [];
                        foreach ($payload as $idx => $item) {
                            $text = isset($item['text']) ? (string) $item['text'] : '';
                            if ($text !== '') {
                                $fallback[] = [
                                    'i' => $idx,
                                    'original_text' => $text,
                                    'translated_text' => $text,
                                    'status' => defined('YUZ_TRA_STATUS_IN_REVIEW') ? YUZ_TRA_STATUS_IN_REVIEW : 2,
                                ];
                            }
                        }

                        if (!empty($fallback)) {
                            $this->trace_log('AUTO.BATCH.OUT.FALLBACK', [
                                'req'   => $req_id,
                                'from'  => $source_canon,
                                'to'    => $target_canon,
                                'ms'    => $provider_ms,
                                'ok'    => true,
                                'count' => count($fallback),
                                'total' => count($items),
                                'reason'=> 'empty_translation_fallback',
                            ]);
                            self::send_json_success([
                                'results' => $fallback,
                                'req_id'  => $req_id,
                                'source'  => $source_canon,
                                'target'  => $target_canon,
                                'warning' => 'empty_translation_fallback',
                            ]);
                        } else {
                            self::send_json_error([
                                'message' => 'empty_translation',
                                'error'   => $first_err !== '' ? $first_err : null,
                                'req_id'  => $req_id,
                                'results' => $normalized,
                            ], 200);
                        }
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
                                    $norm = yuz_norm_locale($code);
                                    if ($norm === $source_locale || yuz_root($norm) === yuz_root($source_locale)) {
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
                                    $this->db->store_translation([
                                        'post_id'         => isset($row['post_id']) ? (int) $row['post_id'] : 0,
                                        'context'         => isset($row['context']) ? sanitize_text_field($row['context']) : 'content',
                                        'block_id'        => isset($row['block_id']) ? sanitize_text_field($row['block_id']) : '',
                                        'original_text'   => $orig_norm,
                                        'translated_text' => $tr_norm,
                                        'source_lang_id'  => (int) $srcObj->id,
                                        'target_lang_id'  => (int) $dstObj->id,
                                        'language_code'   => $target_code,
                                        'status'          => 0,
                                        'origin'          => 'machine',
                                    ]);
                                    $stored_count++;
                                }
                            }
                        } catch (\Throwable $e) {
                            // ignoring persistence errors on purpose
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

                    if (function_exists('yuz_diag_log')) {
                        yuz_diag_log('ajax:done', [
                            'action' => current_action(),
                            'count'  => count($normalized),
                            'trace'  => $_POST['yuz_trace'] ?? '',
                        ]);
                    }

                    if (defined('WP_DEBUG') && WP_DEBUG && $single_translation === '') {
                        $raw = substr(wp_json_encode($response, JSON_UNESCAPED_UNICODE), 0, 1024);
                    }

                    return [
                        'results'         => $normalized,
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
        public function yuz_translate() {
            self::__handleRequest(
                'yuz_hvy_nonce',
                ['q','from','to'],
                function ($data) {
                    $trace_req_id = isset($data['req_id']) ? sanitize_text_field($data['req_id']) : '';
                    $nonce_raw = isset($_REQUEST['_ajax_nonce']) ? (string) $_REQUEST['_ajax_nonce'] : '';
                    $action_raw = isset($_REQUEST['action']) ? (string) $_REQUEST['action'] : '';
                    if (!current_user_can('yuz_translate_content')) {
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

                    $cleaned_text = yuz_strip_css_js_noise($text);
                    if ($cleaned_text === '') {
                        $this->trace_log('AUTO.SINGLE.ERR', [ 'req' => $trace_req_id ?: $req_id, 'reason' => 'missing_text_or_target' ]);
                        return ['ok' => false, 'error' => 'missing_text_or_target', 'req_id' => $req_id];
                    }
                    $text = $cleaned_text;

                    $resolved = yuz_resolve_target_locale($target_raw);
                    if ($resolved === '') {
                        $this->trace_log('AUTO.SINGLE.ERR', [ 'req' => $trace_req_id ?: $req_id, 'reason' => 'invalid_target' ]);
                        wp_send_json_error(['message' => 'invalid_target', 'req_id' => $req_id], 200);
                    }

                    $source_canon = yuz_canon_lang($from_raw !== '' ? $from_raw : 'auto');
                    $target_canon = yuz_canon_lang($resolved !== '' ? $resolved : $target_raw);

                    if ($target_canon === '' || $target_canon === 'auto') {
                        $this->trace_log('AUTO.SINGLE.ERR', [ 'req' => $trace_req_id ?: $req_id, 'reason' => 'missing_target' ]);
                        wp_send_json_error(['message' => 'missing_target', 'req_id' => $req_id], 400);
                    }

                    if ($source_canon !== 'auto' && $source_canon === $target_canon) {
                        $this->trace_log('AUTO.SINGLE.ERR', [ 'req' => $trace_req_id ?: $req_id, 'reason' => 'invalid_target_same_lang' ]);
                        return ['ok' => false, 'error' => 'invalid_target', 'req_id' => $req_id];
                    }

                    $from_canon = yuz_norm_locale($from_raw) ?: 'auto';
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
                            $norm = yuz_norm_locale($code);
                            if ($norm === $from_canon || yuz_root($norm) === yuz_root($from_canon)) {
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

                    $sourceId = (int) $srcObj->id;
                    $targetId = (int) $dstObj->id;
                    $providerSettings = get_option('yuz_tra_api_settings', []);
                    $providerName = isset($providerSettings['api_provider']) ? (string) $providerSettings['api_provider'] : 'unknown';

                    $translation = null;
                    try {
                        if (strpos($text, '<') !== false) {
                            $translation = (new YUZ_HTML_Translator($this->translation_manager))->translate_html($text, $sourceId, $targetId);
                        } else {
                            $translation = $this->translation_manager->translate($text, $sourceId, $targetId);
                        }
                    } catch (\Throwable $e) {
                        if (class_exists('YUZ_Logger')) {
                            (new YUZ_Logger())->log('error', '[yuz_translate] translate threw', [
                                'msg' => $e->getMessage(),
                                'from'=> $sourceLang,
                                'to'  => $target_code
                            ]);
                        }
                        $translation = null;
                    }

                    $durationMs = (int) round((microtime(true) - $started) * 1000);

                    if ($translation === null || $translation === '') {
                        // Adapter-only fallback (no provider)
                        require_once YUZ_TRA_INCLUDES . 'class-yuz-libre-translate-adapter.php';
                        try {
                            $lt_trace = isset($_POST['yuz_trace']) ? sanitize_text_field($_POST['yuz_trace']) : '';
                            $api_settings = get_option('yuz_tra_at_settings', []);
                            if (!is_array($api_settings) || empty($api_settings)) {
                                $api_settings = get_option('yuz_tra_api_settings', []);
                            }
                            if (!is_array($api_settings)) { $api_settings = []; }
                            $base_url = isset($api_settings['libre_url']) ? rtrim((string)$api_settings['libre_url'], '/') : '';
                            $api_key  = isset($api_settings['libre_key']) ? (string)$api_settings['libre_key'] : '';
                            $adapter = new \YUZ_Libre_Translate_Adapter(null, [
                                'endpoint' => $base_url,
                                'api_key'  => $api_key,
                            ]);
                            $res = $adapter->translate($text, $provider_source, $provider_target, []);
                            $translation = is_string($res) ? $res : '';
                        } catch (\Throwable $fallbackException) {
                            if (class_exists('YUZ_Logger')) {
                                (new YUZ_Logger())->log('error', '[yuz_translate] adapter fallback failed', [
                                    'msg'  => $fallbackException->getMessage(),
                                    'from' => $provider_source,
                                    'to'   => $provider_target,
                                    'req'  => $req_id,
                                ]);
                            }
                            $translation = '';
                        }
                    }

                    if ($translation === null || $translation === '') {
                        $this->trace_log('AUTO.SINGLE.OUT', [
                            'req'  => $trace_req_id ?: $req_id,
                            'ok'   => false,
                            'ms'   => (int) round((microtime(true) - $started) * 1000),
                            'from' => $sourceLang ?: $provider_source,
                            'to'   => $target_code,
                        ]);
                        if (class_exists('YUZ_Logger')) {
                            (new YUZ_Logger())->log('warning', '[yuz_translate] translate returned null', [
                                'from' => $sourceLang ?: $provider_source,
                                'to'   => $target_code,
                                'ms'   => $durationMs,
                                'chars'=> strlen($text),
                                'req'  => $req_id,
                            ]);
                        }
                        return ['ok' => false, 'error' => 'translate_failed', 'req_id' => $req_id];
                    }

                    if (function_exists('yuz_diag_log')) {
                        yuz_diag_log('ajax:instant', [
                            'action' => 'yuz_translate',
                            'from'   => $sourceLang,
                            'to'     => $target_code,
                            'chars'  => strlen($text),
                            'ms'     => $durationMs,
                            'trace'  => $_POST['yuz_trace'] ?? ''
                        ]);
                    }
                    if (class_exists('YUZ_Logger')) {
                        (new YUZ_Logger())->log('info', sprintf(
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
                    $translation = mb_substr($translation, 0, 10000);

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

        public function yuz_gt_search() {
            check_ajax_referer('yuz_int_nonce', 'nonce');
            if (!current_user_can('edit_posts')) {
                wp_send_json_error(['message' => 'forbidden'], 403);
            }

            global $wpdb;
            $table = $wpdb->prefix . 'yuz_tra_gettext';
            $lang  = sanitize_text_field($_REQUEST['lang'] ?? '');
            $domain= sanitize_text_field($_REQUEST['domain'] ?? '');
            $status= isset($_REQUEST['status']) ? (int) $_REQUEST['status'] : -1;
            $query = sanitize_text_field($_REQUEST['q'] ?? '');
            $page  = max(1, (int) ($_REQUEST['page'] ?? 1));
            $per   = min(100, max(10, (int) ($_REQUEST['per_page'] ?? 30)));
            $offset= ($page - 1) * $per;

            $where = 'WHERE 1=1';
            $params = [];
            if ($lang) { $where .= ' AND lang = %s'; $params[] = $lang; }
            if ($domain) { $where .= ' AND domain = %s'; $params[] = $domain; }
            if ($status >= 0) { $where .= ' AND status = %d'; $params[] = $status; }
            if ($query) {
                $like = '%' . $wpdb->esc_like($query) . '%';
                $where .= ' AND (original LIKE %s OR translated LIKE %s)';
                $params[] = $like;
                $params[] = $like;
            }

            $sql = $wpdb->prepare(
                "SELECT SQL_CALC_FOUND_ROWS id, domain, context, original, lang, translated, status, origin, updated_at
                 FROM {$table} {$where}
                 ORDER BY updated_at DESC
                 LIMIT %d OFFSET %d",
                array_merge($params, [$per, $offset])
            );
            $rows = $wpdb->get_results($sql, ARRAY_A);
            $total = (int) $wpdb->get_var('SELECT FOUND_ROWS()');

            wp_send_json_success([
                'rows' => $rows,
                'total'=> $total,
                'page' => $page,
                'per_page' => $per,
            ]);
        }

        public function yuz_gt_save() {
            check_ajax_referer('yuz_int_nonce', 'nonce');
            if (!current_user_can('edit_posts')) {
                wp_send_json_error(['message' => 'forbidden'], 403);
            }

            $items = json_decode(stripslashes($_POST['items'] ?? '[]'), true);
            if (!is_array($items)) { $items = []; }
            $saved = 0;
            foreach ($items as $item) {
                if (empty($item['original']) || empty($item['lang'])) {
                    continue;
                }
                $data = [
                    'domain'     => sanitize_text_field($item['domain'] ?? ''),
                    'context'    => sanitize_text_field($item['context'] ?? ''),
                    'original'   => (string) ($item['original'] ?? ''),
                    'lang'       => sanitize_text_field($item['lang'] ?? ''),
                    'translated' => isset($item['translated']) ? wp_kses_post($item['translated']) : null,
                    'status'     => isset($item['status']) ? (int) $item['status'] : 0,
                    'origin'     => isset($item['origin']) ? (int) $item['origin'] : 0,
                ];
                YUZ_String_Service::save_gettext($data);
                $saved;
            }

            wp_send_json_success(['saved' => $saved]);
        }

        public function yuz_slugs_search() {
            check_ajax_referer('yuz_int_nonce', 'nonce');
            if (!current_user_can('edit_posts')) {
                wp_send_json_error(['message' => 'forbidden'], 403);
            }

            global $wpdb;
            $table = $wpdb->prefix . 'yuz_tra_slugs';
            $lang = sanitize_text_field($_REQUEST['lang'] ?? '');
            $post_type = sanitize_text_field($_REQUEST['post_type'] ?? '');
            $query = sanitize_text_field($_REQUEST['q'] ?? '');
            $page = max(1, (int) ($_REQUEST['page'] ?? 1));
            $per = min(100, max(10, (int) ($_REQUEST['per_page'] ?? 30)));
            $offset = ($page - 1) * $per;

            $where = 'WHERE 1=1';
            $params = [];
            if ($lang) { $where .= ' AND lang = %s'; $params[] = $lang; }
            if ($post_type) { $where .= ' AND post_type = %s'; $params[] = $post_type; }
            if ($query) {
                $like = '%' . $wpdb->esc_like($query) . '%';
                $where .= ' AND slug LIKE %s';
                $params[] = $like;
            }

            $sql = $wpdb->prepare(
                "SELECT SQL_CALC_FOUND_ROWS object_id, object_type, post_type, lang, slug, status, updated_at
                 FROM {$table} {$where}
                 ORDER BY updated_at DESC
                 LIMIT %d OFFSET %d",
                array_merge($params, [$per, $offset])
            );
            $rows = $wpdb->get_results($sql, ARRAY_A);
            $total = (int) $wpdb->get_var('SELECT FOUND_ROWS()');

            wp_send_json_success([
                'rows' => $rows,
                'total'=> $total,
                'page' => $page,
                'per_page' => $per,
            ]);
        }

        public function yuz_slugs_save() {
    check_ajax_referer('yuz_int_nonce', 'nonce');
    if (!current_user_can('edit_posts')) {
        wp_send_json_error(['message' => 'forbidden'], 403);
    }

    $items = json_decode(stripslashes($_POST['items'] ?? '[]'), true);
    if (!is_array($items)) { $items = []; }

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
        YUZ_String_Service::save_slug($data);
        $this->synchronise_slug_entity($data);
        $updated;
    }

    wp_send_json_success(['saved' => $updated]);
}


        public function yuz_eml_search() {

            check_ajax_referer('yuz_int_nonce', 'nonce');
            if (!current_user_can('edit_posts')) {
                wp_send_json_error(['message' => 'forbidden'], 403);
            }

            global $wpdb;
            $table = $wpdb->prefix . 'yuz_tra_emails';
            $lang = sanitize_text_field($_REQUEST['lang'] ?? '');
            $query = sanitize_text_field($_REQUEST['q'] ?? '');
            $page = max(1, (int) ($_REQUEST['page'] ?? 1));
            $per = min(100, max(10, (int) ($_REQUEST['per_page'] ?? 30)));
            $offset = ($page - 1) * $per;

            $where = 'WHERE 1=1';
            $params = [];
            if ($lang) { $where .= ' AND lang = %s'; $params[] = $lang; }
            if ($query) {
                $like = '%' . $wpdb->esc_like($query) . '%';
                $where .= ' AND (ekey LIKE %s OR translated LIKE %s)';
                $params[] = $like;
                $params[] = $like;
            }

            $sql = $wpdb->prepare(
                "SELECT SQL_CALC_FOUND_ROWS ekey, lang, translated, status, source, updated_at
                 FROM {$table} {$where}
                 ORDER BY updated_at DESC
                 LIMIT %d OFFSET %d",
                array_merge($params, [$per, $offset])
            );
            $rows = $wpdb->get_results($sql, ARRAY_A);
            $total = (int) $wpdb->get_var('SELECT FOUND_ROWS()');

            wp_send_json_success([
                'rows' => $rows,
                'total'=> $total,
                'page' => $page,
                'per_page' => $per,
            ]);
        }

        public function yuz_eml_save() {
            check_ajax_referer('yuz_int_nonce', 'nonce');
            if (!current_user_can('edit_posts')) {
                wp_send_json_error(['message' => 'forbidden'], 403);
            }

            $items = json_decode(stripslashes($_POST['items'] ?? '[]'), true);
            if (!is_array($items)) { $items = []; }

            $saved = 0;
            foreach ($items as $item) {
                if (empty($item['ekey']) || empty($item['lang'])) {
                    continue;
                }
                $data = [
                    'ekey'       => sanitize_text_field($item['ekey']),
                    'source'     => isset($item['source']) ? wp_kses_post($item['source']) : '',
                    'lang'       => sanitize_text_field($item['lang']),
                    'translated' => isset($item['translated']) ? wp_kses_post($item['translated']) : null,
                    'status'     => isset($item['status']) ? (int) $item['status'] : 0,
                ];
                YUZ_String_Service::save_email($data);
                $saved;
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
        private function yuz_te_extract_strings_from_html(string $html): array {
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

        public function yuz_tra_tm_del_translation() {
            self::__handleRequest(
                'yuz_del_nonce',
                ['translation_id'],
                function ($data) {
                    if (!current_user_can('manage_options')) {
                        wp_send_json_error(['message'=>'unauthorized','code'=>'unauthorized'],403);
                    }
                    global $wpdb;
                    $this->ensure_db_tables();

                    $trans_table = $wpdb->prefix . 'yuz_tra_translations';
                    if (!$wpdb->get_var("SHOW TABLES LIKE '$trans_table'")) {
                        (new YUZ_Logger())->log('critical', "Translations table $trans_table missing after recreation attempt");
                        return [];
                    }

                    $translation_id = intval($data['translation_id']);

                    $result = $wpdb->delete($trans_table, ['id' => $translation_id], ['%d']);
                    if ($result === false) {
                        throw new \Exception("Failed to delete translation ID {$translation_id}: " . $wpdb->last_error);
                    }

                    return ['message' => __('Translation deleted', 'yuz_translation')];
                },
                true
            );
        }

        public function yuz_tra_tm_test_api() {
    // Vérif stricte via check_ajax_referer
    check_ajax_referer('yuz_api_nonce', 'nonce');

    self::__handleRequest(
        'yuz_api_nonce',
        ['provider'], // endpoint/api_key validés conditionnellement ci-dessous
        function ($data) {
            $provider = sanitize_text_field($data['provider'] ?? '');
            $endpoint = esc_url_raw($data['endpoint'] ?? ($data['url'] ?? ''));
            $api_key  = sanitize_text_field($data['api_key'] ?? ($data['apiKey'] ?? ''));
            $extra_in = isset($data['extra_settings'])
                ? (array) wp_unslash($data['extra_settings'])
                : ( isset($data['extraSettings']) ? (array) wp_unslash($data['extraSettings']) : [] );

            $extra = array_map(
                static function ($v) { return is_scalar($v) ? sanitize_text_field((string) $v) : ''; },
                $extra_in
            );

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
            $result         = $this->translation_manager->test_api_conn([
                'provider'       => $provider,
                'endpoint'       => $endpoint,
                'api_key'        => $api_key,
                'extra_settings' => $extra,
            ]);
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
        public function yuz_tra_js_upd_database() {
            self::__handleRequest('yuz_hvy_nonce',
                [],
                function ($data) {
                    if (!current_user_can('manage_options')) {
                        wp_send_json_error(['message'=>'unauthorized','code'=>'unauthorized'],403);
                    }
                    global $wpdb;
                    $this->ensure_db_tables();

                    $trans_table = $wpdb->prefix . 'yuz_tra_translations';
                    if (!$wpdb->get_var("SHOW TABLES LIKE '$trans_table'")) {
                        (new YUZ_Logger())->log('critical', "Translations table $trans_table missing after recreation attempt");
                        return [];
                    }

                    $publish_status = function_exists('yuz_tra_status_transition')
                        ? yuz_tra_status_transition('publish')
                        : 1;

                    $result = $wpdb->query($wpdb->prepare("UPDATE $trans_table SET updated_at = %s WHERE status = %d", current_time('mysql'), $publish_status));
                    if ($result === false) {
                        throw new \Exception("Failed to update translations: " . $wpdb->last_error);
                    }

                    return ['message' => __('Database updated', 'yuz_translation'), 'updated_rows' => $result];
                },
                true
            );
        }

        public function yuz_tra_js_upd_bulkedit() {
            self::__handleRequest('yuz_hvy_nonce',
                ['data'],
                function ($data) {
                    if (!current_user_can('manage_options')) {
                        wp_send_json_error(['message'=>'unauthorized','code'=>'unauthorized'],403);
                    }
                    global $wpdb;
                    $this->ensure_db_tables();

                    $trans_table = $wpdb->prefix . 'yuz_tra_translations';
                    if (!$wpdb->get_var("SHOW TABLES LIKE '$trans_table'")) {
                        (new YUZ_Logger())->log('critical', "Translations table $trans_table missing after recreation attempt");
                        return [];
                    }

                    $data = json_decode($data['data'], true);
                    if (!is_array($data) || empty($data)) {
                        throw new \Exception('Invalid bulk edit data');
                    }

                    $publish_status = function_exists('yuz_tra_status_transition')
                        ? yuz_tra_status_transition('publish')
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
                        throw new \Exception('No translations updated');
                    }

                    $dictionary = $wpdb->get_results($wpdb->prepare("SELECT * FROM $trans_table WHERE status = %d", $publish_status), ARRAY_A);
                    if ($dictionary === null) {
                        throw new \Exception("Failed to retrieve dictionary: " . $wpdb->last_error);
                    }

                    $total_items = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $trans_table WHERE status = %d", $publish_status));
                    if ($total_items === null) {
                        throw new \Exception("Failed to retrieve total items: " . $wpdb->last_error);
                    }

                    return ['dictionary' => $dictionary ?: [], 'totalItems' => (int)$total_items, 'message' => __('Bulk action applied', 'yuz_translation')];
                },
                true
            );
        }

        public function yuz_tra_js_get_gtxtscan() {
            self::__handleRequest('yuz_log_nonce',
                [],
                function ($data) {
                    if (!current_user_can('manage_options')) {
                        wp_send_json_error(['message'=>'unauthorized','code'=>'unauthorized'],403);
                    }
                    $progress = get_option('yuz_gettext_scan_progress', ['completed' => false, 'progress_message' => '']);
                    if (empty($progress['completed'])) {
                        $progress['progress_message'] = __('Scanning...', 'yuz_translation');
                        update_option('yuz_gettext_scan_progress', $progress);
                        (new YUZ_Logger())->log('info', "Scan in progress: {$progress['progress_message']}");
                        return ['progress_message' => $progress['progress_message'], 'completed' => false];
                    }
                    return ['progress_message' => __('Gettext scan completed', 'yuz_translation'), 'completed' => true];
                },
                true
            );
        }

        public function yuz_tra_js_get_regular() {
            self::__handleRequest('yuz_int_nonce',
                ['nodes', 'target_lang'],
                function ($data) {
                    global $wpdb;
                    $this->ensure_db_tables();

                    $lang_table = $wpdb->prefix . 'yuz_tra_languages';
                    if (!$wpdb->get_var("SHOW TABLES LIKE '$lang_table'")) {
                        (new YUZ_Logger())->log('critical', "Languages table $lang_table missing after recreation attempt");
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
                    $target_lang_id = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM $lang_table WHERE language_code = %s", $target_lang));
                    if ($target_lang_id <= 0) {
                        $target_lang = $defTarget;
                        $target_lang_id = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM $lang_table WHERE language_code = %s", $target_lang));
                    }
                    if ($target_lang_id <= 0) {
                        throw new \Exception("Invalid target language resolution: {$requested}");
                    }

                    $source_lang_id = $wpdb->get_var("SELECT id FROM $lang_table WHERE is_source = 1");
                    if (!$source_lang_id) {
                        throw new \Exception('Failed to retrieve source language ID: ' . $wpdb->last_error);
                    }

                    $translated_text = $this->translation_manager->translate($nodes, $source_lang_id, $target_lang_id);
                    if ($translated_text === null) {
                        throw new \Exception("Translation failed for {$target_lang}");
                    }

                    // YUZ: NORMALIZE NEWLINES (résultat provider)
                    $translated_text = $this->normalize_newlines((string)$translated_text); 
                    return ['translations' => [$target_lang => $translated_text]]; // YUZ: normalized
                }
            );
        }

        public function yuz_get_regular() {
            if (defined('YUZ_TRA_DEBUG_FRONT') && YUZ_TRA_DEBUG_FRONT) {
                $orig = isset($_POST['originals']) ? json_decode(stripslashes((string) $_POST['originals']), true) : []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            }
            self::__handleRequest(
                'yuz_tra_nonce',
                ['originals', 'language'],
                function ($data) {
                    global $wpdb;
                    $t0 = microtime(true);
                    $cid = isset($_REQUEST['cid']) ? sanitize_text_field((string) $_REQUEST['cid']) : null;
                    $logger = $this->logger ?? (class_exists('YUZ_Logger') ? new \YUZ_Logger() : null);
                    $lang_param = isset($_REQUEST['language']) ? sanitize_text_field((string) $_REQUEST['language']) : '';
                    $this->log_ajax_entry('yuz_get_regular', [
                        'cid'       => $cid,
                        'language'  => $lang_param,
                        'post_id'   => isset($_REQUEST['post_id']) ? absint($_REQUEST['post_id']) : 0,
                        'selectors' => isset($_REQUEST['selectors']) ? $_REQUEST['selectors'] : '',
                    ]);
                    if ($logger) {
                        try {
                            $logger->log('info', 'GET_REGULAR.IN', [
                                'cid'      => $cid,
                                'keys'     => array_keys((array) $_REQUEST),
                                'language' => isset($_REQUEST['language']) ? (string) $_REQUEST['language'] : null,
                            ]);
                        } catch (\Throwable $ignored) {}
                    }
                    $this->ensure_db_tables();

                    $trans_table = $wpdb->prefix . 'yuz_tra_translations';
                    $lang_table  = $wpdb->prefix . 'yuz_tra_languages';
                    if (!$wpdb->get_var("SHOW TABLES LIKE '$trans_table'") || !$wpdb->get_var("SHOW TABLES LIKE '$lang_table'")) {
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
                    $skip_items = array_values(array_filter(array_map('strval', $skip_items), static function ($value) {
                        return $value !== '';
                    }));
                    $normalized_skip = array_map(function ($item) {
                        return $this->normalize_newlines((string) $item);
                    }, $skip_items);

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
                                'block_keys_len' => count($block_keys),
                            ]);
                        } catch (\Throwable $ignored) {}
                    }
                    $this->log_ajax_entry('yuz_get_regular_payload', [
                        'cid'            => $cid,
                        'language'       => $lang_param,
                        'originals_len'  => count($originals),
                        'skip_len'       => count($skip_items),
                        'block_keys_len' => count($block_keys),
                    ]);

                    $normalize_plain = static function (string $text): string {
                        if (function_exists('wp_strip_all_tags')) {
                            $text = wp_strip_all_tags($text);
                        } else {
                            $text = strip_tags($text);
                        }
                        $text = preg_replace('/\s+/u', ' ', $text);
                        return $text === null ? '' : trim($text);
                    };

                    $primary_api   = get_option('yuz_tra_at_settings', []);
                    $secondary_api = get_option('yuz_tra_api_settings', []);
                    $legacy_api    = function_exists('get_option') ? get_option('yuz_tra_settings', []) : [];
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
                                'cid'      => $cid,
                                'raw'      => wp_json_encode($raw_api_settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                                'resolved' => wp_json_encode($resolved_api_settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
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
                    $logger = $this->logger ?? (class_exists('YUZ_Logger') ? new \YUZ_Logger() : null);
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
                        $post_id = url_to_postid($page_url);
                    }
                    if ($post_id === 0) {
                        $this->trace_log('GET_REGULAR.POST_ID_ZERO', [
                            'url'     => $page_url,
                            'context' => $context,
                            'cid'     => $cid,
                        ]);
                    }

                    $context = isset($data['context']) ? sanitize_text_field($data['context']) : 'content';

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
                    $target_lang_id = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM $lang_table WHERE language_code = %s", $target_code));
                    if ($target_lang_id <= 0) {
                        // fallback to default target
                        $target_code = $defTarget;
                        $target_lang_id = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM $lang_table WHERE language_code = %s", $target_code));
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
                        "SELECT id FROM $lang_table WHERE language_code = %s",
                        $source_code
                    ));

                    if ($source_lang_id <= 0) {
                        $source_lang_id = (int) $wpdb->get_var("SELECT id FROM $lang_table WHERE is_source = 1 LIMIT 1");
                    }

                    // Probe switch (query/body/header)
                    $is_probe = !empty($_REQUEST['probe'])
                        || (isset($_GET['yuzprobe']) && $_GET['yuzprobe'] == '1')
                        || (isset($_GET['yuz-probe']) && $_GET['yuz-probe'] == '1')
                        || (!empty($_SERVER['HTTP_X_YUZ_PROBE']));

                    if ($is_probe && $this->logger) {
                        try {
                            $sample = array_slice(array_values(array_filter(array_map('strval', $originals))), 0, 5);
                            $this->logger->log('info', 'YUZ-PROBE request (yuz_get_regular)', [
                                'target'    => $target_code,
                                'source'    => $source_code,
                                'post_id'   => $post_id,
                                'context'   => $context,
                                'page_url'  => $page_url,
                                'count'     => count($originals),
                                'sample'    => wp_json_encode($sample, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
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
                        $original = $originals[$i];
                        if (!is_string($original) || $original === '') {
                            continue;
                        }

                        $normalized_original = $this->normalize_newlines($original);
                        $len = strlen($normalized_original);
                        $separator_score = substr_count($normalized_original, '•') + substr_count($normalized_original, '>');
                        if ($len > $MAX_ORIGINAL_LEN || $separator_score > 12 || preg_match($selector_pattern, $normalized_original)) {
                            $this->trace_log('GET_REGULAR.SKIP_LIKELY_SELECTOR', [
                                'len'      => $len,
                                'separators' => $separator_score,
                                'original_preview' => substr($normalized_original, 0, 160),
                                'target'   => $target_code,
                            ]);
                            continue;
                        }
                        // Prefer a stable block_key from client when provided (forms/modals)
                        $bk = isset($block_keys[$i]) ? trim((string)$block_keys[$i]) : '';
                        if ($bk !== '') {
                            $block_id = substr(sha1($bk), 0, 40);
                        } else {
                            $block_id = function_exists('yuz_generate_block_id')
                                ? yuz_generate_block_id($post_id, $context, $normalized_original)
                                : substr(sha1($normalized_original), 0, 40);
                        }

                        $row = $wpdb->get_row(
                            $wpdb->prepare(
                                "SELECT id, translated_text, status FROM $trans_table WHERE block_id = %s AND target_lang_id = %d AND source_lang_id = %d",
                                $block_id,
                                $target_lang_id,
                                $source_lang_id
                            ),
                            ARRAY_A
                        );
                        // Back-compat: if nothing under block_key-based id, try legacy original-based id
                        if (!$row && $bk !== '') {
                            $legacy_id = function_exists('yuz_generate_block_id')
                                ? yuz_generate_block_id($post_id, $context, $normalized_original)
                                : substr(sha1($normalized_original), 0, 40);
                            $row = $wpdb->get_row(
                                $wpdb->prepare(
                                    "SELECT id, translated_text, status FROM $trans_table WHERE block_id = %s AND target_lang_id = %d AND source_lang_id = %d",
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

                        if (!$row) {
                            $this->trace_log('GET_REGULAR.EMPTY_ROW', [
                                'block_id'  => $block_id,
                                'block_key' => $bk,
                                'original'  => substr($normalized_original, 0, 500),
                                'target'    => $target_code,
                            ]);
                        }

                        $skip_machine = in_array($original, $skip_items, true) || in_array($normalized_original, $skip_items, true) || in_array($normalized_original, $normalized_skip, true);
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
                        try {
                            if (method_exists($this->translation_manager, 'translate_batch')) {
                                $translated_map = (array) $this->translation_manager->translate_batch($pending_texts, $source_lang_id, $target_lang_id);
                            }
                        } catch (\Throwable $e) {
                            if ($logger) { try { $logger->log('warning', 'Auto translation batch failed', ['message' => $e->getMessage()]); } catch (\Throwable $ignored) {} }
                            $translated_map = [];
                        }
                        if (empty($translated_map)) {
                            foreach ($pending_texts as $pt) {
                                try { $translated_map[$pt] = $this->translation_manager->translate($pt, $source_lang_id, $target_lang_id); }
                                catch (\Throwable $e) { $translated_map[$pt] = null; }
                            }
                        }
                    }

                    $draft_status   = defined('YUZ_TRA_STATUS_DRAFT') ? YUZ_TRA_STATUS_DRAFT : 0;
                    $machine_status = defined('YUZ_TRA_STATUS_REVIEW') ? YUZ_TRA_STATUS_REVIEW : 1;

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
                                            'plain'    => $plain_candidate,
                                        ]);
                                    } catch (\Throwable $ignored) {}
                                }
                                if ($candidate !== '' && $plain_candidate !== '' && $plain_candidate !== $plain_original) {
                                    $auto_translated = $candidate;
                                } else {
                                    $this->trace_log('AUTO_TRANSLATE.EMPTY_OR_SAME', [
                                        'block_id' => $block_id,
                                        'target'   => $target_code,
                                        'plain_candidate' => $plain_candidate,
                                        'plain_original'  => $plain_original,
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
                                            "SELECT id, translated_text, status FROM $trans_table WHERE block_id = %s AND target_lang_id = %d AND source_lang_id = %d",
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
                                'original'  => substr($normalized_original, 0, 300),
                            ]);
                        }

                        $translation_id = $row ? (int) ($row['id'] ?? 0) : 0;
                        $this->trace_log('GET_REGULAR.ENTRY', [
                            'block_id'       => $block_id,
                            'block_key'      => $bk,
                            'target'         => $target_code,
                            'source'         => $source_code,
                            'translation_id' => $translation_id,
                            'status'         => $status,
                            'translated_len' => strlen($translated),
                            'translated'     => substr($translated, 0, 500),
                            'original'       => substr($normalized_original, 0, 500),
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
                            $this->logger->log('info', 'YUZ-PROBE summary (yuz_get_regular)', [
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
                        $this->emit_trace_marker('YUZ_BACKEND_CHECK', [
                            'target'            => $target_code,
                            'rows'              => count($results),
                            'invalid_entries'   => $invalid,
                            'missing_translations' => $missing,
                        ]);
                    }

                    $rows = $results;
                    if (defined('YUZ_TRA_DEBUG_FRONT') && YUZ_TRA_DEBUG_FRONT) {
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

        public function yuz_tra_js_upd_auto() {
            self::__handleRequest('yuz_con_nonce',
                ['automatic_translation_settings'],
                function ($data) {
                    if (!current_user_can('manage_options')) {
                        wp_send_json_error(['message'=>'unauthorized','code'=>'unauthorized'],403);
                    }
                    $settings           = (array) $data['automatic_translation_settings'];
                    $sanitized_settings = $this->get_settings()->sanitize_option('yuz_tra_api_settings', $settings);
                    $result             = $this->get_settings()->update_option('yuz_tra_api_settings', $sanitized_settings);

                    if ($result === false && get_option('yuz_tra_api_settings') !== $sanitized_settings) {
                        throw new \Exception('Failed to update API settings');
                    }

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

                    wp_clear_scheduled_hook('yuz_tra_batch_translate');
                    if (in_array($sanitized_settings['translation_mode'] ?? '', ['silent', 'all'], true)) {
                        wp_schedule_event(time(), $sanitized_settings['cron_interval'] ?? 'hourly', 'yuz_tra_batch_translate');
                        (new YUZ_Logger())->log('debug', 'Rescheduled WP-Cron event with interval: ' . ($sanitized_settings['cron_interval'] ?? 'hourly'));
                    }

                    return ['message' => __('Settings updated', 'yuz_translation'), 'settings' => $sanitized_settings];
                },
                true
            );
        }

        /** -------------------- GENERAL -------------------- */
        public function yuz_tra_delete_translation() {
            self::__handleRequest(
                'yuz_del_nonce',
                ['translation_id'],
                function ($data) {
                    if (!current_user_can('manage_options')) {
                        wp_send_json_error(['message'=>'unauthorized','code'=>'unauthorized'],403);
                    }
                    global $wpdb;
                    $this->ensure_db_tables();

                    $trans_table = $wpdb->prefix . 'yuz_tra_translations';
                    if (!$wpdb->get_var("SHOW TABLES LIKE '$trans_table'")) {
                        (new YUZ_Logger())->log('critical', "Translations table $trans_table missing after recreation attempt");
                        return [];
                    }

                    $translation_id = intval($data['translation_id']);

                    $result = $wpdb->delete($trans_table, ['id' => $translation_id], ['%d']);
                    if ($result === false) {
                        throw new \Exception("Failed to delete translation ID {$translation_id}: " . $wpdb->last_error);
                    }

                    return ['message' => __('Translation deleted', 'yuz_translation')];
                },
                true
            );
        }

        public function yuz_tra_ws_remove_all() {
            self::__handleRequest('yuz_tra_nonce',
                [],
                function ($data) {
                    if (!current_user_can('manage_options')) {
                        wp_send_json_error(['message'=>'unauthorized','code'=>'unauthorized'],403);
                    }
                    global $wpdb;
                    $this->ensure_db_tables();

                    $table_name = $wpdb->prefix . 'yuz_tra_languages';
                    if (!$wpdb->get_var("SHOW TABLES LIKE '$table_name'")) {
                        (new YUZ_Logger())->log('critical', "Languages table $table_name missing after recreation attempt");
                        return [];
                    }

                    $res = $wpdb->query("DELETE FROM $table_name WHERE is_translatable = 1 AND is_default = 0 AND is_source = 0");
                    if ($res === false) {
                        throw new \Exception("Failed to delete all languages: " . $wpdb->last_error);
                    }

                    $options                               = $this->get_settings()->get_option('yuz_tra_general');
                    $options['yuz_translatable_languages'] = [];
                    $this->get_settings()->update_option('yuz_tra_general', $options);

                    $this->invalidate_language_caches();
                    return $this->build_ws_payload();
                },
                true
            );
        }

        /** -------------------- ADVANCED (diagnostics / cache) -------------------- */
public function ajax_run_diagnostics(): void {
    $this->handleRequest(
        'yuz_con_nonce',
        [], // pas de champs obligatoires
        function(array $data) {
            if (!current_user_can('manage_options')) {
                return ['success' => false, 'error' => ['code' => 403, 'message' => 'Insufficient permissions'], 'timestamp' => current_time('mysql')];
            }
            if (!class_exists('YUZ_Diagnostic')) {
                return ['success' => false, 'error' => ['code' => 'diag_unavailable', 'message' => __('Diagnostics module not available.', 'yuz_translation')], 'timestamp' => current_time('mysql')];
            }
            try {
                $diagnostic = new \YUZ_Diagnostic();
                $cache  = $diagnostic->run_diagnostic(plugin_dir_path(YUZ_TRA_PLUGIN_FILE));
                $report = $diagnostic->generate_report(
                    $cache['files'] ?? [],
                    $cache['db_tables'] ?? [],
                    $cache['settings_issues'] ?? [],
                    $cache['issues'] ?? [],
                    $cache['dependencies'] ?? []
                );
                $cache['report'] = $report;
                update_option('yuz_diagnostic_cache', $cache);
                return ['success' => true, 'report' => $report, 'timestamp' => current_time('mysql')];
            } catch (\Throwable $e) {
                return ['success' => false, 'error' => ['code' => 'diag_failed', 'message' => __('Diagnostics failed.', 'yuz_translation')], 'timestamp' => current_time('mysql')];
            }
        },
        []
    );
}

public function ajax_clear_cache(): void {
    $this->handleRequest(
        'yuz_con_nonce',
        [],
        function(array $data) {
            if (!current_user_can('manage_options')) {
                return ['success' => false, 'error' => ['code' => 403, 'message' => 'Insufficient permissions'], 'timestamp' => current_time('mysql')];
            }
            wp_cache_flush();
            global $wpdb;
            $wpdb->query("DELETE FROM {$wpdb->prefix}options WHERE option_name LIKE '_transient_%'");
            return ['success' => true, 'message' => __('Cache cleared successfully', 'yuz_translation'), 'timestamp' => current_time('mysql')];
        },
        []
    );
}


public function yuz_tra_diag_chain() {
    $received = [];
    foreach (['yuz_tra_nonce','nonce','security'] as $key) {
        if (isset($_POST[$key])) {
            $received[$key] = sanitize_text_field(wp_unslash($_POST[$key]));
        }
    }
    $nonce = $received['yuz_tra_nonce'] ?? $received['nonce'] ?? $received['security'] ?? '';
    $verify_ws_get = $nonce ? wp_verify_nonce($nonce, 'yuz_tra_ws_get_languages') : false;
    $verify_main   = $nonce ? wp_verify_nonce($nonce, 'yuz_tra_nonce') : false;
    $check_ajax    = check_ajax_referer('yuz_tra_nonce', isset($received['nonce']) ? 'nonce' : false, false);

    if (!current_user_can('manage_options')) {
        wp_send_json_error([
            'message' => 'Insufficient permissions',
            'code'    => 403,
        ], 403);
    }

    global $wpdb;
    $table = $wpdb->prefix . 'yuz_tra_languages';
    $rows  = $wpdb->get_results("SELECT language_code, is_default, is_source, is_translatable FROM {$table}", ARRAY_A);
    $rows  = is_array($rows) ? $rows : [];

    $counts = [
        'total'        => count($rows),
        'translatable' => count(array_filter($rows, fn($row) => !empty($row['is_translatable']))),
        'default'      => count(array_filter($rows, fn($row) => !empty($row['is_default']))),
        'source'       => count(array_filter($rows, fn($row) => !empty($row['is_source']))),
    ];
    $sample = array_slice($rows, 0, 10);

    $opt_general = get_option('yuz_tra_general', []);
    $opt_mirror  = get_option('yuz_tra_languages', []);

    $localization_preview = [
        'general' => [
            'yuz_tra_default_language'       => $opt_general['yuz_tra_default_language'] ?? null,
            'yuz_tra_source_language'        => $opt_general['yuz_tra_source_language'] ?? null,
            'yuz_tra_translatable_languages' => $opt_general['yuz_tra_translatable_languages'] ?? [],
        ],
    ];

    $payload = [
        'server' => [
            'uri'     => $_SERVER['REQUEST_URI'] ?? '',
            'origin'  => $_SERVER['HTTP_ORIGIN'] ?? '',
            'referer' => $_SERVER['HTTP_REFERER'] ?? '',
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
            'wp_verify_ws_get_languages' => $verify_ws_get,
            'wp_verify_yuz_tra_nonce'    => $verify_main,
            'check_ajax_referer'         => $check_ajax ? 1 : 0,
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






        /** -------------------- BATCH (stub) -------------------- */
        public function handle_ajax_batch() {
            self::__handleRequest('yuz_hvy_nonce',
                [],
                function ($data) {
                    if (!current_user_can('manage_options')) {
                        wp_send_json_error(['message'=>'unauthorized','code'=>'unauthorized'],403);
                    }
                    /**
                     * Laisse la possibilité au core/service d’exécuter un batch réel.
                     * Si rien n’est branché, on renvoie juste ok  timestamp.
                     */
                    do_action('yuz_tra_run_batch', $data);
                    return ['ok'=>true,'timestamp'=>current_time('mysql')];
                },
                true
            );
        }

        /** -------------------- PREFLIGHT -------------------- */
        public static function ajax_preflight() {
    self::ensure_req_id();
    // Vérif nonce tolérante (nonce | _ajax_nonce | security), clé attendue 'yuz_hvy_nonce'
    $nonce_param = $_REQUEST['nonce'] ?? ($_REQUEST['_ajax_nonce'] ?? ($_REQUEST['security'] ?? ''));
    $ok          = $nonce_param && wp_verify_nonce($nonce_param, 'yuz_hvy_nonce');

    if (!$ok) {
        if (class_exists('YUZ_Logger')) {
            (new YUZ_Logger())->log('error', sprintf(
                'AJAX preflight nonce failed: expected=%s got=%s user=%d referer=%s',
                'yuz_hvy_nonce',
                $nonce_param ? ('len.'.strlen($nonce_param)) : 'EMPTY',
                get_current_user_id(),
                isset($_SERVER['HTTP_REFERER']) ? esc_url_raw($_SERVER['HTTP_REFERER']) : 'n/a'
            ));
        }
        self::send_json_error(['message' => 'invalid_nonce', 'expected' => 'yuz_hvy_nonce'], 403);
    }

    $mode = isset($_POST['mode']) ? sanitize_text_field($_POST['mode']) : null;
    $r    = (class_exists('YUZ_Services') && method_exists('YUZ_Services','preflight'))
        ? YUZ_Services::preflight($mode)
        : ['ok' => false, 'missing' => ['services']];

    self::send_json_success($r);
}

/**
 * YUZ: save translation
 */
public function yuz_save_translation() {
    $actor_id = function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
    $remote_fingerprint = isset($_SERVER['REMOTE_ADDR']) ? md5((string) $_SERVER['REMOTE_ADDR']) : (string) wp_rand();
    $actor_suffix = $actor_id > 0 ? 'user_' . $actor_id : 'ip_' . substr($remote_fingerprint, 0, 12);
    $busy_key   = 'yuz_te_busy_' . $actor_suffix;
    $max_slots  = defined('YUZ_TRA_SAVE_MAX_PARALLEL') ? max(1, (int) YUZ_TRA_SAVE_MAX_PARALLEL) : 4;
    // Entrée — trace rapide
    $trace_id = '';
    try {
        $logger = $this->logger ?: (class_exists('YUZ_Logger') ? new \YUZ_Logger() : new \YUZTRA\Fallbacks\NullLogger());
        $trace_id  = isset($_REQUEST['yuz_trace']) ? sanitize_text_field((string) $_REQUEST['yuz_trace']) : '';
        $logger->log('info', '[TE][IN] save', [
            'trace' => $trace_id,
            'keys'  => array_keys((array) $_REQUEST),
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

    check_ajax_referer('yuz_tra_nonce', 'nonce');

    $logger = $this->logger ?: (class_exists('YUZ_Logger') ? new \YUZ_Logger() : new \YUZTRA\Fallbacks\NullLogger());
    $req_id = self::ensure_req_id();
    $raw_mode = isset($_POST['mode']) ? sanitize_key(wp_unslash((string) $_POST['mode'])) : '';
    $allowed_modes = ['auto', 'semi', 'manual'];
    $mode = in_array($raw_mode, $allowed_modes, true) ? $raw_mode : 'manual';
    $string_id_input = isset($_POST['string_id']) ? absint($_POST['string_id']) : 0;
    $translation_id = 0;
    foreach (['translation_id', 'translationId', 'id', 'ID'] as $maybe_id_key) {
        if (isset($_POST[$maybe_id_key])) {
            $translation_id = absint($_POST[$maybe_id_key]);
            if ($translation_id > 0) {
                break;
            }
        }
    }
    if ($translation_id <= 0) {
        $translation_id = $string_id_input;
    }
    $this->log_ajax_entry('yuz_save_translation', [
        'string_id'       => $string_id_input,
        'target_lang_raw' => isset($_POST['target_lang']) ? sanitize_text_field(wp_unslash((string) $_POST['target_lang'])) : (isset($_POST['language_code']) ? sanitize_text_field(wp_unslash((string) $_POST['language_code'])) : ''),
        'post_id'         => isset($_POST['post_id']) ? absint($_POST['post_id']) : 0,
        'context'         => isset($_POST['context']) ? sanitize_text_field(wp_unslash((string) $_POST['context'])) : '',
        'has_original'    => isset($_POST['original_text']) && $_POST['original_text'] !== '',
        'has_translated'  => isset($_POST['translated_text']) && $_POST['translated_text'] !== '',
        'mode'            => $mode,
        'req_id'          => $req_id,
        'trace_id'        => $trace_id,
    ]);

    try {
    if (!current_user_can('yuz_translate_content')) {
        $logger->log('warning', 'yuz_save_translation denied: capability yuz_translate missing');
        $release();
        self::send_json_error(['message' => 'Unauthorized', 'code' => 'forbidden'], 403);
    }

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
        $decoded = $_POST['target_langs'];
        if (is_string($decoded)) {
            $json = json_decode($decoded, true);
            if (is_array($json)) {
                $decoded = $json;
            }
        }
        $target_langs = array_merge($target_langs, $collect_targets($decoded));
    }
    if (!$target_langs && isset($_POST['target_lang'])) {
        $target_langs = array_merge($target_langs, $collect_targets($_POST['target_lang']));
    }
    if (!$target_langs && isset($_POST['to'])) {
        $target_langs = array_merge($target_langs, $collect_targets($_POST['to']));
    }
    if (!$target_langs && isset($_POST['language_code'])) {
        $target_langs = array_merge($target_langs, $collect_targets($_POST['language_code']));
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

    if (function_exists('yuz_normalize_locale')) {
        $target_langs = array_map('yuz_normalize_locale', $target_langs);
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
    $page_url       = esc_url_raw($_POST['page_url'] ?? '');
    $context        = sanitize_text_field(wp_unslash($_POST['context'] ?? '')) ?: 'content';
        $block_id       = sanitize_text_field(wp_unslash($_POST['block_id'] ?? ''));
    $post_id        = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
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

    $review_status    = defined('YUZ_TRA_STATUS_IN_REVIEW') ? (int) YUZ_TRA_STATUS_IN_REVIEW : (defined('YUZ_TRA_STATUS_REVIEW') ? (int) YUZ_TRA_STATUS_REVIEW : 2);
    $published_status = defined('YUZ_TRA_STATUS_PUBLISHED') ? (int) YUZ_TRA_STATUS_PUBLISHED : 4;
    $archived_status  = defined('YUZ_TRA_STATUS_ARCHIVED') ? (int) YUZ_TRA_STATUS_ARCHIVED : 5;
    $draft_status     = defined('YUZ_TRA_STATUS_DRAFT') ? (int) YUZ_TRA_STATUS_DRAFT : 1;
    $manual_publish_direct = defined('YUZ_TRA_MANUAL_MODE_PUBLISH_DIRECT') ? (bool) YUZ_TRA_MANUAL_MODE_PUBLISH_DIRECT : false;
    $mode_default_status = $draft_status;
    if ($mode === 'manual' && $manual_publish_direct) {
        $mode_default_status = $published_status;
    } elseif ($mode === 'semi' || $mode === 'semi_auto') {
        $mode_default_status = $review_status;
    } elseif ($mode === 'auto') {
        $mode_default_status = $published_status;
    }

    if (function_exists('yuz_tra_status_sanitize')) {
        $status = yuz_tra_status_sanitize($_POST['status'] ?? null, $mode_default_status);
    } else {
        $status_input   = isset($_POST['status']) ? (int) $_POST['status'] : null;
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
    if (function_exists('yuz_tra_status_for_origin')) {
        $status = yuz_tra_status_for_origin($origin, $status, $is_batch, $mode);
    } elseif ($mode === 'auto') {
        $status = defined('YUZ_TRA_STATUS_PUBLISHED') ? YUZ_TRA_STATUS_PUBLISHED : 4;
    } elseif ($origin === 'machine' || $origin === 'dom' || $is_batch || $mode === 'semi' || $mode === 'semi_auto') {
        $status = defined('YUZ_TRA_STATUS_REVIEW') ? YUZ_TRA_STATUS_REVIEW : 2;
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
        $flag = isset($_POST['is_slug']) ? filter_var($_POST['is_slug'], FILTER_VALIDATE_BOOLEAN) : false;
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
            $translated_slug = 'translation-' . (function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : uniqid('yuz_', true));
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
    $max_bytes = defined('YUZ_TRA_MAX_TRANSLATED_BYTES') ? (int) YUZ_TRA_MAX_TRANSLATED_BYTES : 64000;
    $len_bytes = strlen($translated_text);
    if ($max_bytes > 0 && $len_bytes > $max_bytes) {
        $truncated = function_exists('mb_strcut')
            ? mb_strcut($translated_text, 0, $max_bytes, 'UTF-8')
            : substr($translated_text, 0, $max_bytes);
        try {
            $logger->log('warning', 'yuz_save_translation: translated_text truncated', [
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
        if (function_exists('yuz_debug_probe_log')) {
            yuz_debug_probe_log('editor_ajax_save_truncated', [
                'req_id'      => $req_id,
                'trace_id'    => $trace_id,
                'before'      => $len_bytes,
                'after'       => strlen($truncated),
                'limit_bytes' => $max_bytes,
            ]);
        }
        $translated_text = $truncated;
    }

    if (function_exists('yuz_debug_probe_log')) {
        yuz_debug_probe_log('editor_ajax_save_requested', [
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

    $source_language = $source_code ? $this->language_manager->get_by_code($source_code) : null;

    $existing = null;
    if ($translation_id > 0) {
        $existing = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $translation_id),
            ARRAY_A
        );
        if (!$existing) {
            $logger->log('warning', "yuz_save_translation: translation {$translation_id} not found, falling back to insert");
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
                "SELECT id FROM {$lang_table} WHERE language_code = %s OR wp_locale = %s",
                $source_code,
                $source_code
            )
        );
    }

    if ($source_lang_id <= 0) {
        $logger->log('warning', 'yuz_save_translation: unable to resolve source language id', ['source_code' => $source_code]);
        $release();
        self::send_json_error(['message' => 'Missing source language reference', 'code' => 'invalid_language_source'], 400);
    }

    if ($block_id === '' && $original_text !== '') {
        $block_id = function_exists('yuz_generate_block_id')
            ? yuz_generate_block_id($post_id, $context, $original_text)
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
        if (function_exists('yuz_tra_build_block_id')) {
            $block_id = (string) yuz_tra_build_block_id($normalized_original, $context, $post_id, $target_code);
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
            $wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $translation_id),
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
                "SELECT * FROM {$table}
                 WHERE post_id = %d
                   AND language_code = %s
                   AND context = %s
                   AND (
                        (%d > 0 AND string_id = %d)
                     OR (%d = 0 AND string_id = 0 AND original_text = %s)
                   )
                 ORDER BY id ASC
                 LIMIT 1",
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
                "SELECT * FROM {$table}
                 WHERE post_id = %d
                   AND language_code = %s
                   AND context = %s
                   AND (
                        (%d > 0 AND id = %d)
                     OR (%d = 0 AND original_text = %s)
                   )
                 ORDER BY id ASC
                 LIMIT 1",
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
                "SELECT * FROM {$table}
                 WHERE post_id = %d AND context = %s AND target_lang_id = %d AND block_id = %s
                 LIMIT 1",
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
        $logger->log('warning', 'yuz_save_translation: missing required fields', [
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
        if (function_exists('yuz_debug_probe_log')) {
            yuz_debug_probe_log('editor_ajax_save_update', [
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
        $logger->log('info', 'yuz_save_translation: updated existing translation', [
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

    if (function_exists('yuz_debug_probe_log')) {
        yuz_debug_probe_log('editor_ajax_save_insert', [
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
        if (function_exists('yuz_debug_probe_log')) {
            yuz_debug_probe_log('editor_ajax_save_fail', ['phase' => 'insert', 'error' => $error_message]);
        }

        if (stripos($error_message, 'duplicate') !== false) {
            $duplicate = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM {$table} WHERE post_id = %d AND context = %s AND source_lang_id = %d AND target_lang_id = %d AND block_id = %s",
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
    $logger->log('info', 'yuz_save_translation: created new translation', [
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
    if (function_exists('yuz_debug_probe_log')) {
        yuz_debug_probe_log('editor_ajax_save_success', ['id' => $insert_id]);
    }
    $release();
    self::send_json_success(['message' => 'Translation saved', 'id' => $insert_id, 'req_id' => $req_id]);
    } catch (\Throwable $e) {
        $this->log_ajax_exception('yuz_save_translation', $e, [
            'action'       => isset($_REQUEST['action']) ? sanitize_key((string) $_REQUEST['action']) : '',
            'string_id'    => $translation_id,
            'target_lang'  => $target_code,
            'target_langs' => $target_langs,
            'post_id'      => $post_id,
            'context'      => $context,
            'block_id'     => $block_id,
            'page_url'     => $page_url,
        ]);
        $release();
        self::send_json_error(['message' => 'Unexpected error while saving translation', 'code' => 'yuz_save_exception'], 500);
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
        $cols = $wpdb->get_results("DESC {$table}", ARRAY_A);
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
        $prepared = $wpdb->prepare($sql, ...$params);
        if ($prepared === null) {
            return false;
        }
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
        $incoming = isset($_REQUEST['req_id']) ? self::sanitize_req_id($_REQUEST['req_id']) : '';
        if ($incoming === '') {
            $incoming = function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : uniqid('yuz_', true);
        }
        $_REQUEST['req_id'] = $incoming;
        self::$current_req_id = $incoming;
        return $incoming;
    }

    private static function current_action(): string
    {
        return isset($_REQUEST['action']) ? sanitize_key((string) $_REQUEST['action']) : '';
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
            return ['value' => is_scalar($payload) ? $payload : gettype($payload)];
        }
        $slice = array_slice($payload, 0, 5, true);
        foreach ($slice as $key => &$value) {
            if (is_array($value)) {
                $value = sprintf('array(%d)', count($value));
            } elseif (is_object($value)) {
                $value = 'object('.get_class($value).')';
            } elseif (is_string($value) && strlen($value) > 120) {
                $value = mb_substr($value, 0, 120) . '…';
            }
        }
        return $slice;
    }

    private static function log_ajax_exit(string $action, array $details = [], string $level = 'success'): void
    {
        try {
            if (!class_exists('YUZ_Logger')) {
                return;
            }
            $logger = new \YUZ_Logger();
            $context = array_merge(self::trace_meta(), $details);
            $logger->log($level, "[AJAX][{$action}][OUT]", $context);
        } catch (\Throwable $e) {
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
                    $rows = $wpdb->get_col("SELECT slug FROM {$table} WHERE slug <> ''");
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
            $settings = (array) get_option('yuz_tra_language_settings', []);
            foreach (['slug_map', 'slugs', 'yuz_tra_slug'] as $key) {
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
            $logger = $this->logger ?: (class_exists('YUZ_Logger') ? new \YUZ_Logger() : new \YUZTRA\Fallbacks\NullLogger());
            $logger->log('info', "[AJAX][{$action}][IN]", $details);
        } catch (\Throwable $e) {
        }
        $encoded = function_exists('wp_json_encode') ? wp_json_encode($details) : json_encode($details);
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
            $logger = $this->logger ?: (class_exists('YUZ_Logger') ? new \YUZ_Logger() : new \YUZTRA\Fallbacks\NullLogger());
            $logger->log('critical', "[AJAX][{$action}][EXCEPTION]", $payload);
        } catch (\Throwable $logError) {
        }
        $encoded = function_exists('wp_json_encode') ? wp_json_encode($payload) : json_encode($payload);
    }
} // fin class YUZ_Ajax
} // fin if (!class_exists('YUZ_Ajax'))

add_action('wp_ajax_yuz_tra_preflight', ['YUZ_Ajax','ajax_preflight']);
add_action('init', ['YUZ_Ajax', 'init']);

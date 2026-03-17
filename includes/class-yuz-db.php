<?php
/**
 * Class YUZ_DB
 * Manages database setup and translation storage for the YUZ-TRA plugin.
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
require_once YUZ_TRA_INCLUDES . 'class-yuz-contracts.php';
use YUZTRA\Interfaces\DBInterface;
use YUZTRA\Interfaces\LoggerInterface;
use YUZTRA\Interfaces\HealthCheckInterface;
use YUZTRA\Fallbacks\NullLogger;
use YUZTRA\Fallbacks\NullHealthCheck;
if (!class_exists('YUZ_DB')) {
    class YUZ_DB implements DBInterface {
        const DB_VERSION = '1.0.4';
        const DB_VERSION_OPTION = 'yuz_tra_db_version';
        private $logger;
        private $health_check;
        public function __construct(LoggerInterface $logger = null, HealthCheckInterface $health_check = null) {
            $this->logger = $logger ?? new NullLogger();
            $this->health_check = $health_check ?? new NullHealthCheck();
            $this->logger->log('info', 'YUZ_DB instantiated with dependencies', ['class' => __CLASS__]);
        }
        public static function init(): void {
            $logger = class_exists('YUZ_Logger') ? new YUZ_Logger() : new NullLogger();
            $health_check = class_exists('YUZ_Health_Check') ? new YUZ_Health_Check($logger) : new NullHealthCheck();
            $instance = new self($logger, $health_check);
            $instance->logger->log('info', 'Initializing YUZ_DB class at ' . current_time('mysql'), ['class' => __CLASS__]);
            // Register hooks (retiré register_activation_hook, centralisé dans yuz-tra.php)
            add_action('plugins_loaded', [$instance, 'check_schema']);
            $instance->logger->log('success', 'YUZ_DB class initialized successfully', ['class' => __CLASS__]);
        }
        /**
         * Logs an action to the logs table.
         *
         * @param string $action The action name.
         * @param string $message The action message.
         * @param array $details Additional details.
         * @param int $success 1 for success, 0 for failure.
         * @return void
         */
        public function log_action(string $action, string $message, array $details = [], int $success = 1): void {
            global $wpdb;
            // MODIF: Gate sur tables_ok pour éviter writes si tables KO (Phase 4)
            if (!get_option('tables_ok', false)) {
                $this->logger->log('critical', 'Tables not OK, skipping log_action', ['class' => __CLASS__]);
                return;
            }
            $table = $wpdb->prefix . 'yuz_tra_logs';
            if (!$wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table))) { // Force prepare
                $this->logger->log('critical', "Logs table {$table} missing", ['class' => __CLASS__]);
                return;
            }
            static $logged_actions = [];
            $log_key = md5($action . $message . wp_json_encode($details));
            if (isset($logged_actions[$log_key])) {
                return;
            }
            $logged_actions[$log_key] = true;
            $details = is_array($details) ? $details : [];
            $details['request_uri'] = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field($_SERVER['REQUEST_URI']) : '';
            $translator = 'system';
            if (function_exists('is_user_logged_in') && is_user_logged_in()) {
                $user = wp_get_current_user();
                $translator = $user->user_login ?: 'anonymous';
            } elseif (function_exists('is_user_logged_in')) {
                $translator = 'anonymous';
            }
            $this->logger->log('info', "Preparing to log action: {$action}", ['class' => __CLASS__]);
            $attempts = 3;
            $result = null;
            while ($attempts > 0) {
                $result = $wpdb->insert(
                    $table,
                    [
                        'translator' => sanitize_text_field($translator),
                        'action' => sanitize_text_field($action),
                        'success' => (int) $success,
                        'message' => sanitize_text_field($message),
                        'details' => wp_json_encode($details),
                        'created_at' => current_time('mysql'),
                    ],
                    ['%s', '%s', '%d', '%s', '%s', '%s']
                );
                if ($result !== null) {
                    break;
                }
                $attempts--;
                $this->logger->log('warning', "Retry attempt for logging action {$action}, attempts left: {$attempts}", ['class' => __CLASS__]);
                usleep(100000);
            }
            if ($result === false) {
                $this->logger->log('error', "Failed to log action {$action}: " . $wpdb->last_error, ['class' => __CLASS__]);
                return;
            }
            $this->logger->log('success', "Action logged successfully: {$action}", ['class' => __CLASS__]);
        }
        /**
         * Checks if a foreign key constraint exists.
         *
         * @param string $table_name Name of the table.
         * @param string $constraint_name Name of the constraint.
         * @return bool True if the constraint exists, false otherwise.
         */
        private function constraint_exists($table_name, $constraint_name) {
            global $wpdb;
            // MODIF: Gate sur tables_ok (Phase 4)
            if (!get_option('tables_ok', false)) {
                $this->logger->log('critical', 'Tables not OK, skipping constraint_exists', ['class' => __CLASS__]);
                return false;
            }
            $query = $wpdb->prepare(
                "SELECT COUNT(*)
                 FROM information_schema.TABLE_CONSTRAINTS
                 WHERE CONSTRAINT_TYPE = 'FOREIGN KEY'
                 AND TABLE_SCHEMA = DATABASE()
                 AND TABLE_NAME = %s
                 AND CONSTRAINT_NAME = %s",
                $table_name,
                $constraint_name
            );
            $attempts = 3;
            $result = null;
            while ($attempts > 0) {
                $result = $wpdb->get_var($query);
                if ($result !== null) {
                    break;
                }
                $attempts--;
                $this->logger->log('warning', "Retry attempt for checking constraint {$constraint_name} on {$table_name}, attempts left: {$attempts}", ['class' => __CLASS__]);
                usleep(100000);
            }
            if ($result === null) {
                $this->logger->log('error', "Failed to check constraint {$constraint_name} on {$table_name}: " . $wpdb->last_error, ['class' => __CLASS__]);
                return false;
            }
            return $result > 0;
        }

        /**
         * Seed the global settings table with source/fallback language ids when empty.
         */
        private function seed_global_settings(): void {
            global $wpdb;

            $global_table = $wpdb->prefix . 'yuz_tra_global_settings';
            $lang_table   = $wpdb->prefix . 'yuz_tra_languages';

            $global_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $global_table));
            $lang_exists   = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $lang_table));
            if (!$global_exists || !$lang_exists) {
                $this->logger->log('warning', 'seed_global_settings skipped: tables missing', [
                    'global_exists' => (bool) $global_exists,
                    'lang_exists'   => (bool) $lang_exists,
                ]);
                return;
            }

            $rows = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$global_table}");
            if ($rows > 0) {
                $this->logger->log('info', 'seed_global_settings skipped: data present');
                return;
            }

            $general  = (array) get_option('yuz_tra_general', []);
            $settings = (array) get_option('yuz_tra_settings', []);

            $pickValues = static function (array $bags, array $keys): array {
                $found = [];
                foreach ($keys as $key) {
                    foreach ($bags as $bag) {
                        if (!is_array($bag) || empty($bag[$key])) {
                            continue;
                        }
                        $value = sanitize_text_field((string) $bag[$key]);
                        if ($value !== '') {
                            $found[] = $value;
                        }
                    }
                }
                return array_values(array_unique(array_filter($found)));
            };

            $source_candidates = $pickValues([$general, $settings], [
                'yuz_tra_source_language',
                'yuz_source_language',
                'source_language',
                'yuz_tra_default_language',
                'yuz_default_language',
                'default_language'
            ]);
            if (empty($source_candidates)) {
                $source_candidates[] = sanitize_text_field(get_locale());
            }

            $fallback_candidates = $pickValues([$general, $settings], [
                'yuz_tra_fallback_language',
                'yuz_fallback_language',
                'fallback_language',
                'yuz_tra_default_language',
                'yuz_default_language',
                'default_language'
            ]);

            $resolveId = static function (array $candidates) use ($wpdb, $lang_table): int {
                foreach ($candidates as $code) {
                    if ($code === '') {
                        continue;
                    }
                    $variants = array_unique([
                        $code,
                        strtoupper($code),
                        strtolower($code),
                        str_replace('-', '_', $code),
                        str_replace('_', '-', strtolower($code)),
                    ]);
                    foreach ($variants as $variant) {
                        $id = (int) $wpdb->get_var($wpdb->prepare(
                            "SELECT id FROM {$lang_table} WHERE language_code = %s OR slug = %s OR browser_slug = %s LIMIT 1",
                            $variant,
                            strtolower($variant),
                            strtolower($variant)
                        ));
                        if ($id > 0) {
                            return $id;
                        }
                    }
                }
                return 0;
            };

            $source_id   = $resolveId($source_candidates);
            $fallback_id = $resolveId($fallback_candidates);

            if ($source_id === 0) {
                $source_id = (int) $wpdb->get_var("SELECT id FROM {$lang_table} ORDER BY is_source DESC, is_default DESC, language_weight DESC, id ASC LIMIT 1");
            }
            if ($fallback_id === 0) {
                $fallback_id = (int) $wpdb->get_var("SELECT id FROM {$lang_table} ORDER BY is_default DESC, language_weight DESC, id ASC LIMIT 1");
            }

            if ($source_id > 0) {
                $wpdb->query($wpdb->prepare("UPDATE {$lang_table} SET is_source = CASE WHEN id = %d THEN 1 ELSE 0 END", $source_id));
            }
            if ($fallback_id > 0) {
                $wpdb->query($wpdb->prepare("UPDATE {$lang_table} SET is_default = CASE WHEN id = %d THEN 1 ELSE 0 END", $fallback_id));
            }

            $ids_to_enable = array_values(array_filter([$source_id, $fallback_id]));
            if (!empty($ids_to_enable)) {
                $placeholders = implode(', ', array_fill(0, count($ids_to_enable), '%d'));
                $prepared = $wpdb->prepare(
                    "UPDATE {$lang_table} SET is_translatable = 1, updated_at = %s WHERE id IN ({$placeholders})",
                    array_merge([current_time('mysql')], $ids_to_enable)
                );
                $wpdb->query($prepared);
            }

            if ($source_id === $fallback_id) {
                $fallback_id = 0;
            }

            $inserted = $wpdb->insert(
                $global_table,
                [
                    'id'              => 1,
                    'source_lang_id'  => $source_id ?: null,
                    'fallback_lang_id'=> $fallback_id ?: null,
                    'updated_at'      => current_time('mysql'),
                ],
                ['%d', '%d', '%d', '%s']
            );

            if ($inserted === false) {
                $this->logger->log('error', 'Failed to seed global settings table', ['error' => $wpdb->last_error]);
                $this->log_action('ensure_tables', 'Failed to seed global settings', ['error' => $wpdb->last_error], 0);
                return;
            }

            $this->logger->log('success', 'Global settings table seeded', [
                'source_lang_id'   => $source_id,
                'fallback_lang_id' => $fallback_id,
            ]);
            $this->log_action('ensure_tables', 'Global settings seeded', [
                'source_lang_id'   => $source_id,
                'fallback_lang_id' => $fallback_id,
            ], 1);
        }
        /**
         * Stores a translation in the database.
         *
         * @param array $translation_data Translation data including post_id, context, original_text, etc.
         * @return bool True if successful, false otherwise.
         */
        public function store_translation(array $translation_data): bool {
            global $wpdb;
            if (function_exists('yuz_debug_probe_log')) {
                yuz_debug_probe_log('db_store_translation_start', [
                    'payload' => $translation_data,
                ]);
            }
            // MODIF: Gate sur tables_ok (Phase 4)
            if (!get_option('tables_ok', false)) {
                $this->logger->log('critical', 'Tables not OK, skipping store_translation', ['class' => __CLASS__]);
                if (function_exists('yuz_debug_probe_log')) {
                    yuz_debug_probe_log('db_store_translation_abort', ['reason' => 'tables_flag_false']);
                }
                return false;
            }
            $table = $wpdb->prefix . 'yuz_tra_translations';
            if (!$wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table))) { // Force prepare
                $this->logger->log('critical', "Translations table $table missing", ['class' => __CLASS__]);
                if (function_exists('yuz_debug_probe_log')) {
                    yuz_debug_probe_log('db_store_translation_abort', ['reason' => 'table_missing']);
                }
                return false;
            }
            // Prepare data with fields matching run_full_site_translation
            $insert_data = [
                'post_id' => isset($translation_data['post_id']) ? (int)$translation_data['post_id'] : 0,
                'context' => isset($translation_data['context']) ? sanitize_text_field($translation_data['context']) : 'content',
                'block_id' => isset($translation_data['block_id']) ? sanitize_text_field($translation_data['block_id']) : '',
                'original_text' => isset($translation_data['original_text']) ? $translation_data['original_text'] : '',
                'translated_text' => isset($translation_data['translated_text']) ? $translation_data['translated_text'] : '',
                'translated_slug' => isset($translation_data['translated_slug']) ? sanitize_title($translation_data['translated_slug']) : null,
                'source_lang_id' => isset($translation_data['source_lang_id']) ? (int)$translation_data['source_lang_id'] : 0,
                'target_lang_id' => isset($translation_data['target_lang_id']) ? (int)$translation_data['target_lang_id'] : 0,
                'language_code' => isset($translation_data['language_code']) ? sanitize_text_field($translation_data['language_code']) : '',
                'status' => isset($translation_data['status']) ? (int)$translation_data['status'] : 1,
                'origin' => isset($translation_data['origin']) ? sanitize_text_field($translation_data['origin']) : 'machine',
                'revision_of' => isset($translation_data['revision_of']) ? (int)$translation_data['revision_of'] : null,
                'created_at' => current_time('mysql', true),
                'updated_at' => current_time('mysql', true),
            ];
            // Validate required fields with health check (si disponible)
            if (method_exists($this->health_check, 'ensure')) {
                if (!$this->health_check->ensure(
                    !empty($insert_data['original_text']) && !empty($insert_data['translated_text']) && !empty($insert_data['language_code']),
                    'Missing required translation data fields',
                    __METHOD__
                )) {
                    return false;
                }
            } else {
                $this->logger->log('warning', 'HealthCheck->ensure absent, skipping data validation', ['class'=>__CLASS__]);
            }

            if ($insert_data['translated_slug'] === null && !empty($translation_data['translated_text'])) {
                $insert_data['translated_slug'] = sanitize_title($translation_data['translated_text']);
            }

            if ($insert_data['block_id'] === '' && $insert_data['original_text'] !== '') {
                $insert_data['block_id'] = function_exists('yuz_generate_block_id')
                    ? yuz_generate_block_id($insert_data['post_id'], $insert_data['context'], $insert_data['original_text'])
                    : substr(sha1($insert_data['original_text']), 0, 40);
            }

            // Insert into database
            $attempts = 3;
            $result = null;
            while ($attempts > 0) {
                $result = $wpdb->insert($table, $insert_data, ['%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%d', '%s', '%s', '%s', '%s']);
                if (function_exists('yuz_debug_probe_log')) {
                    yuz_debug_probe_log('db_store_translation_attempt', [
                        'attempt'    => 4 - $attempts,
                        'result'     => $result,
                        'last_error' => $wpdb->last_error,
                        'insert_id'  => $wpdb->insert_id,
                    ]);
                }
                if ($result !== null) {
                    break;
                }
                $attempts--;
                $this->logger->log('warning', "Retry attempt for storing translation, attempts left: {$attempts}", ['class' => __CLASS__]);
                usleep(100000);
            }
            $ok = ($result !== false && empty($wpdb->last_error));
            // Vérifier l’insertion avec health_check si possible
            if (method_exists($this->health_check, 'ensure')) {
                if (!$this->health_check->ensure(
                    $ok,
                    "DB insert failed: {$wpdb->last_error}",
                    __METHOD__
                )) {
                    return false;
                }
            } elseif (!$ok) {
                $this->logger->log('error', "DB insert failed without HealthCheck: {$wpdb->last_error}", ['class'=>__CLASS__]);
                if (function_exists('yuz_debug_probe_log')) {
                    yuz_debug_probe_log('db_store_translation_fail', ['last_error' => $wpdb->last_error]);
                }
                return false;
            }
            $this->logger->log('success', "Translation stored successfully", ['class' => __CLASS__]);
            if (function_exists('yuz_debug_probe_log')) {
                yuz_debug_probe_log('db_store_translation_success', [
                    'insert_id' => $wpdb->insert_id,
                ]);
            }
            do_action('yuz_translation_stored', $translation_data);
            return true;
        }
        private static function create_languages_table(string $lang): bool {
            global $wpdb;
            $charset_collate = $wpdb->get_charset_collate();
            $sql = "CREATE TABLE {$lang} (
                    id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                    locale VARCHAR(12) NOT NULL,              -- NEW

                    language_code VARCHAR(10) NOT NULL,
                    language_name VARCHAR(100) NOT NULL,
                    native_name VARCHAR(100) DEFAULT NULL,
                    slug VARCHAR(50) DEFAULT NULL,
                    flag_svg TEXT DEFAULT NULL,
                    is_default TINYINT(1) DEFAULT 0,
                    is_source TINYINT(1) DEFAULT 0,
                    is_translatable TINYINT(1) DEFAULT 0,
                    browser_slug VARCHAR(10) DEFAULT NULL,
                    language_weight INT DEFAULT 0,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    UNIQUE KEY unique_lang_code (language_code),
                    UNIQUE KEY unique_slug (slug),
                    UNIQUE KEY uniq_locale (locale)           -- NEW
                ) $charset_collate;";
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            dbDelta($sql);
            return !$wpdb->last_error;
        }
        public function ensure_tables(): bool {
            global $wpdb;
            if (get_option('tables_ok', false)) {
                $lang = $wpdb->prefix . 'yuz_tra_languages';
                $trans = $wpdb->prefix . 'yuz_tra_translations';
                $lang_ok = (bool)$wpdb->get_var("SHOW TABLES LIKE '{$lang}'");
                $trans_ok = (bool)$wpdb->get_var("SHOW TABLES LIKE '{$trans}'");
                if ($lang_ok && $trans_ok) {
                    $this->logger->log('info', 'Tables already OK, skipping DDL', ['class' => __CLASS__]);
                    return true;
                }
                $this->logger->log('warning', 'tables_ok=true mais tables manquantes → reprise DDL', ['class' => __CLASS__]);
            }
            $this->logger->log('info', 'Starting table creation', ['class' => __CLASS__]);
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            $charset_collate = $wpdb->get_charset_collate();
            $prefix = $wpdb->prefix;
            $tables_definitions = [
                'yuz_tra_languages' => "CREATE TABLE {$prefix}yuz_tra_languages (
                    id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                    locale VARCHAR(12) NOT NULL,             -- NEW

                    language_code VARCHAR(10) NOT NULL,
                    language_name VARCHAR(100) NOT NULL,
                    native_name VARCHAR(100) DEFAULT NULL,
                    slug VARCHAR(50) DEFAULT NULL,
                    flag_svg TEXT DEFAULT NULL,
                    is_default TINYINT(1) DEFAULT 0,
                    is_source TINYINT(1) DEFAULT 0,
                    is_translatable TINYINT(1) DEFAULT 0,
                    browser_slug VARCHAR(10) DEFAULT NULL,
                    language_weight INT DEFAULT 0,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

                    PRIMARY KEY (id),
                    UNIQUE KEY unique_lang_code (language_code),
                    UNIQUE KEY unique_slug (slug),
                    UNIQUE KEY uniq_locale (locale)          -- NEW
                ) $charset_collate;",
                'yuz_tra_logs' => "CREATE TABLE {$prefix}yuz_tra_logs (
                    id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                    translator VARCHAR(255) NOT NULL,
                    action VARCHAR(50) NOT NULL,
                    success TINYINT(1) NOT NULL DEFAULT 0,
                    message TEXT NOT NULL,
                    details TEXT,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL,
                    PRIMARY KEY (id),
                    INDEX idx_action (action),
                    INDEX idx_created_at (created_at)
                ) $charset_collate;",
                'yuz_tra_user_preferences' => "CREATE TABLE {$prefix}yuz_tra_user_preferences (
                    user_id BIGINT(20) UNSIGNED NOT NULL,
                    preferred_lang_id BIGINT(20) UNSIGNED NOT NULL,
                    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (user_id)
                ) $charset_collate;",
                'yuz_tra_global_settings' => "CREATE TABLE {$prefix}yuz_tra_global_settings (
                    id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                    source_lang_id BIGINT(20) UNSIGNED DEFAULT NULL,
                    fallback_lang_id BIGINT(20) UNSIGNED DEFAULT NULL,
                    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (id)
                ) $charset_collate;",
                'yuz_tra_translations' => "CREATE TABLE {$prefix}yuz_tra_translations (
                    id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                    post_id BIGINT(20) NOT NULL COMMENT '0 = homepage, else wp_posts.ID',
                    context VARCHAR(255) NULL COMMENT 'title, content, menu, widget, block',
                    block_id VARCHAR(255) NULL DEFAULT '' COMMENT 'Unique ID for Gutenberg blocks',
                    original_text TEXT NOT NULL,
                    translated_text TEXT NULL,
                    translated_slug VARCHAR(255) NULL DEFAULT NULL COMMENT 'Translated slug for URL',
                    source_lang_id BIGINT(20) UNSIGNED NOT NULL,
                    target_lang_id BIGINT(20) UNSIGNED NOT NULL,
                    language_code VARCHAR(10) NOT NULL,
                    status TINYINT(1) NOT NULL DEFAULT 0 COMMENT '0=draft,1=machine,2=review,3=queued,4=published,5=archived',
                    origin ENUM('machine', 'manual') NOT NULL DEFAULT 'machine' COMMENT 'machine=auto-generated, manual=human-validated',
                    revision_of BIGINT(20) UNSIGNED NULL COMMENT 'If manual, refers to original auto-translation ID',
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    UNIQUE KEY idx_unique_translation (post_id, context, source_lang_id, target_lang_id, block_id),
                    UNIQUE KEY idx_unique_slug (post_id, target_lang_id, translated_slug),
                    INDEX idx_source_lang_id (source_lang_id),
                    INDEX idx_target_lang_id (target_lang_id),
                    INDEX idx_post_context (post_id, context),
                    INDEX idx_status (status)
                ) $charset_collate;",
                'yuz_tra_translation_meta' => "CREATE TABLE {$prefix}yuz_tra_translation_meta (
                    meta_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                    translation_id BIGINT(20) UNSIGNED NOT NULL,
                    meta_key VARCHAR(255) NOT NULL,
                    meta_value LONGTEXT,
                    PRIMARY KEY (meta_id),
                    INDEX idx_translation_id (translation_id),
                    INDEX idx_meta_key (meta_key)
                ) $charset_collate;",
                'yuz_tra_gettext_translations' => "CREATE TABLE {$prefix}yuz_tra_gettext_translations (
                    id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                    original_text VARCHAR(191) NOT NULL,
                    translated_text TEXT,
                    context VARCHAR(255),
                    target_lang_id BIGINT(20) UNSIGNED NOT NULL,
                    status INT DEFAULT 0 COMMENT '0: Draft, 1: Published',
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL,
                    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    INDEX idx_target_lang_id (target_lang_id),
                    INDEX idx_original_text (original_text),
                    INDEX idx_status (status)
                ) $charset_collate;",
                'yuz_tra_menu_translations' => "CREATE TABLE {$prefix}yuz_tra_menu_translations (
                    id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                    menu_item_id BIGINT(20) NOT NULL COMMENT 'Link to wp_posts (menu item)',
                    original_title TEXT NOT NULL,
                    translated_title TEXT,
                    original_url TEXT,
                    translated_url TEXT,
                    target_lang_id BIGINT(20) UNSIGNED NOT NULL,
                    status INT DEFAULT 0 COMMENT '0: Draft, 1: Published',
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL,
                    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    UNIQUE KEY idx_unique_menu_translation (menu_item_id, target_lang_id),
                    INDEX idx_target_lang_id (target_lang_id),
                    INDEX idx_status (status)
                ) $charset_collate;"
            ];
            $foreign_keys = [
                'yuz_tra_user_preferences' => "ALTER TABLE {$prefix}yuz_tra_user_preferences
                    ADD CONSTRAINT fk_preferred_lang
                    FOREIGN KEY (preferred_lang_id)
                    REFERENCES {$prefix}yuz_tra_languages(id)
                    ON DELETE CASCADE;",
                'yuz_tra_translations' => [
                    "ALTER TABLE {$prefix}yuz_tra_translations
                    ADD CONSTRAINT fk_source_lang
                    FOREIGN KEY (source_lang_id)
                    REFERENCES {$prefix}yuz_tra_languages(id)
                    ON DELETE CASCADE;",
                    "ALTER TABLE {$prefix}yuz_tra_translations
                    ADD CONSTRAINT fk_target_lang
                    FOREIGN KEY (target_lang_id)
                    REFERENCES {$prefix}yuz_tra_languages(id)
                    ON DELETE CASCADE;",
                    "ALTER TABLE {$prefix}yuz_tra_translations
                    ADD CONSTRAINT fk_revision_of
                    FOREIGN KEY (revision_of)
                    REFERENCES {$prefix}yuz_tra_translations(id)
                    ON DELETE SET NULL;"
                ],
                'yuz_tra_translation_meta' => "ALTER TABLE {$prefix}yuz_tra_translation_meta
                    ADD CONSTRAINT fk_translation_meta
                    FOREIGN KEY (translation_id)
                    REFERENCES {$prefix}yuz_tra_translations(id)
                    ON DELETE CASCADE;",
                'yuz_tra_gettext_translations' => "ALTER TABLE {$prefix}yuz_tra_gettext_translations
                    ADD CONSTRAINT fk_gettext_target_lang
                    FOREIGN KEY (target_lang_id)
                    REFERENCES {$prefix}yuz_tra_languages(id)
                    ON DELETE CASCADE;",
                'yuz_tra_menu_translations' => "ALTER TABLE {$prefix}yuz_tra_menu_translations
                    ADD CONSTRAINT fk_menu_target_lang
                    FOREIGN KEY (target_lang_id)
                    REFERENCES {$prefix}yuz_tra_languages(id)
                    ON DELETE CASCADE;"
            ];
            $this->logger->log('info', 'Starting table creation', ['class' => __CLASS__]);
            $failed_tables = [];
            // Create languages table first
            $languages_table = 'yuz_tra_languages';
            $full_table_name = $prefix . $languages_table;
            $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $full_table_name)); // Force prepare
            if (!$exists) {
                $this->logger->log('info', "Creating table $full_table_name (dependency root)", ['class' => __CLASS__]);
                $attempts = 3;
                while ($attempts > 0) {
                    dbDelta($tables_definitions[$languages_table]);
                    if (!$wpdb->last_error) {
                        break;
                    }
                    $attempts--;
                    $this->logger->log('warning', "Retry attempt for creating table $full_table_name, attempts left: {$attempts}", ['class' => __CLASS__]);
                    usleep(100000);
                }
                if ($wpdb->last_error) {
                    $failed_tables[] = ['table' => $full_table_name, 'error' => $wpdb->last_error];
                    $this->logger->log('error', "Failed to create table $full_table_name: " . $wpdb->last_error, ['class' => __CLASS__]);
                    $this->log_action('ensure_tables', "Failed to create table $full_table_name", ['error' => $wpdb->last_error], 0);
                } else {
                    $this->logger->log('success', "Table $full_table_name created successfully", ['class' => __CLASS__]);
                    $this->log_action('ensure_tables', "Table $full_table_name created", [], 1);
                }
            } else {
                $this->logger->log('info', "Table $full_table_name exists, verifying structure", ['class' => __CLASS__]);
                $this->log_action('ensure_tables', "Table $full_table_name exists, verifying structure", [], 1);
                $columns = (array)$wpdb->get_results("SHOW COLUMNS FROM `{$full_table_name}`");
                $actual_columns = array_column($columns, 'Field');

                if (!in_array('native_name', $actual_columns, true)) {
                    $this->logger->log('warning', "Column native_name missing in $full_table_name, adding it", ['class' => __CLASS__]);
                    $wpdb->query("ALTER TABLE `{$full_table_name}` ADD COLUMN `native_name` VARCHAR(100) NULL AFTER `language_name`");
                    if ($wpdb->last_error) {
                        $failed_tables[] = ['table' => $full_table_name, 'error' => $wpdb->last_error];
                        $this->logger->log('error', "Failed to add native_name: " . $wpdb->last_error, ['class' => __CLASS__]);
                        $this->log_action('ensure_tables', "Failed to add native_name column to $full_table_name", ['error' => $wpdb->last_error], 0);
                    } else {
                        $wpdb->query("UPDATE `{$full_table_name}` SET native_name = language_name WHERE native_name IS NULL OR native_name = ''");
                        $columns = (array)$wpdb->get_results("SHOW COLUMNS FROM `{$full_table_name}`");
                        $actual_columns = array_column($columns, 'Field');
                        $this->logger->log('success', "Column native_name added to $full_table_name", ['class' => __CLASS__]);
                        $this->log_action('ensure_tables', "Column native_name added to $full_table_name", [], 1);
                    }
                }

                // --- MIGRATION: ajouter 'locale' si manquante, backfill + index ---
                if (!in_array('locale', $actual_columns, true)) {
                    $this->logger->log('warning', "Column locale missing in $full_table_name, adding it", ['class' => __CLASS__]);

                    $wpdb->query("ALTER TABLE `{$full_table_name}` ADD COLUMN `locale` VARCHAR(12) NOT NULL AFTER `id`");
                    if ($wpdb->last_error) {
                        $failed_tables[] = ['table' => $full_table_name, 'error' => $wpdb->last_error];
                        $this->logger->log('error', "Failed to add locale: " . $wpdb->last_error, ['class' => __CLASS__]);
                        $this->log_action('ensure_tables', "Failed to add locale column to $full_table_name", ['error' => $wpdb->last_error], 0);
                    } else {
                        $wpdb->query("UPDATE `{$full_table_name}` SET `locale` = `language_code` WHERE `locale` IS NULL OR `locale` = ''");

                        $has_idx = (int)$wpdb->get_var(
                            $wpdb->prepare(
                                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
                                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = 'uniq_locale'",
                                $full_table_name
                            )
                        );
                        if (!$has_idx) {
                            $wpdb->query("ALTER TABLE `{$full_table_name}` ADD UNIQUE KEY `uniq_locale` (`locale`)");
                            if ($wpdb->last_error) {
                                $this->logger->log('error', "Failed to add uniq_locale index: " . $wpdb->last_error, ['class' => __CLASS__]);
                            }
                        }

                        $columns = (array)$wpdb->get_results("SHOW COLUMNS FROM `{$full_table_name}`");
                        $actual_columns = array_column($columns, 'Field');

                        $this->logger->log('success', "Column locale added and backfilled in $full_table_name", ['class' => __CLASS__]);
                        $this->log_action('ensure_tables', "Column locale added to $full_table_name", [], 1);
                    }
                }

                $expected_columns = ['id', 'locale', 'language_code', 'language_name', 'native_name', 'slug', 'flag_svg', 'is_default', 'is_source', 'is_translatable', 'browser_slug', 'language_weight', 'created_at', 'updated_at'];
                $missing_columns = array_diff($expected_columns, $actual_columns);
                if (!empty($missing_columns)) {
                    $this->logger->log('warning', "Table $full_table_name missing columns: " . implode(', ', $missing_columns) . " → running dbDelta", ['class' => __CLASS__]);
                    $attempts = 3;
                    while ($attempts > 0) {
                        dbDelta($tables_definitions[$languages_table]);
                        if (!$wpdb->last_error) {
                            break;
                        }
                        $attempts--;
                        $this->logger->log('warning', "Retry dbDelta for $full_table_name, attempts left: {$attempts}", ['class' => __CLASS__]);
                        usleep(100000);
                    }
                    if ($wpdb->last_error) {
                        $failed_tables[] = ['table' => $full_table_name, 'error' => $wpdb->last_error];
                        $this->logger->log('error', "dbDelta failed for $full_table_name: " . $wpdb->last_error, ['class' => __CLASS__]);
                    }
                }
            }
            // Create logs table next
            $logs_table = 'yuz_tra_logs';
            $full_logs_table = $prefix . $logs_table;
            $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $full_logs_table)); // Force prepare
            if (!$exists) {
                $this->logger->log('info', "Creating table $full_logs_table (logging dependency)", ['class' => __CLASS__]);
                $attempts = 3;
                while ($attempts > 0) {
                    dbDelta($tables_definitions[$logs_table]);
                    if (!$wpdb->last_error) {
                        break;
                    }
                    $attempts--;
                    $this->logger->log('warning', "Retry attempt for creating table $full_logs_table, attempts left: {$attempts}", ['class' => __CLASS__]);
                    usleep(100000);
                }
                if ($wpdb->last_error) {
                    $failed_tables[] = ['table' => $full_logs_table, 'error' => $wpdb->last_error];
                    $this->logger->log('error', "Failed to create table $full_logs_table: " . $wpdb->last_error, ['class' => __CLASS__]);
                    $this->log_action('ensure_tables', "Failed to create table $full_logs_table", ['error' => $wpdb->last_error], 0);
                } else {
                    $this->logger->log('success', "Table $full_logs_table created successfully", ['class' => __CLASS__]);
                    $this->log_action('ensure_tables', "Table $full_logs_table created", [], 1);
                }
            } else {
                $this->logger->log('info', "Table $full_logs_table exists, skipping creation", ['class' => __CLASS__]);
                $this->log_action('ensure_tables', "Table $full_logs_table exists, skipping creation", [], 1);
            }
            // Create remaining tables
            $wpdb->query('SET FOREIGN_KEY_CHECKS = 0;');
            foreach ($tables_definitions as $table_name => $definition) {
                if ($table_name === $languages_table || $table_name === $logs_table) {
                    continue;
                }
                $full_table_name = $prefix . $table_name;
                $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $full_table_name)); // Force prepare
                if (!$exists) {
                    if ($table_name === 'yuz_tra_translation_meta' && !$wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", "{$prefix}yuz_tra_translations"))) { // Force prepare
                        $this->logger->log('info', "Creating dependency table {$prefix}yuz_tra_translations for $table_name", ['class' => __CLASS__]);
                        $attempts = 3;
                        while ($attempts > 0) {
                            dbDelta($tables_definitions['yuz_tra_translations']);
                            if (!$wpdb->last_error) {
                                break;
                            }
                            $attempts--;
                            $this->logger->log('warning', "Retry attempt for creating table {$prefix}yuz_tra_translations, attempts left: {$attempts}", ['class' => __CLASS__]);
                            usleep(100000);
                        }
                        if ($wpdb->last_error) {
                            $failed_tables[] = ['table' => 'yuz_tra_translations', 'error' => $wpdb->last_error];
                            $this->logger->log('error', "Failed to create table yuz_tra_translations: " . $wpdb->last_error, ['class' => __CLASS__]);
                            $this->log_action('ensure_tables', "Failed to create table yuz_tra_translations", ['error' => $wpdb->last_error], 0);
                        } else {
                            $this->logger->log('success', "Table yuz_tra_translations created successfully", ['class' => __CLASS__]);
                            $this->log_action('ensure_tables', "Table yuz_tra_translations created", [], 1);
                        }
                    }
                    $this->logger->log('info', "Creating table $full_table_name", ['class' => __CLASS__]);
                    $attempts = 3;
                    while ($attempts > 0) {
                        dbDelta($definition);
                        if (!$wpdb->last_error) {
                            break;
                        }
                        $attempts--;
                        $this->logger->log('warning', "Retry attempt for creating table $full_table_name, attempts left: {$attempts}", ['class' => __CLASS__]);
                        usleep(100000);
                    }
                    if ($wpdb->last_error) {
                        $failed_tables[] = ['table' => $full_table_name, 'error' => $wpdb->last_error];
                        $this->logger->log('error', "Failed to create table $full_table_name: " . $wpdb->last_error, ['class' => __CLASS__]);
                        $this->log_action('ensure_tables', "Failed to create table $full_table_name", ['error' => $wpdb->last_error], 0);
                    } else {
                        $this->logger->log('success', "Table $full_table_name created successfully", ['class' => __CLASS__]);
                        $this->log_action('ensure_tables', "Table $full_table_name created", [], 1);
                    }
                } else {
                    $this->logger->log('info', "Table $full_table_name exists, skipping creation", ['class' => __CLASS__]);
                    $this->log_action('ensure_tables', "Table $full_table_name exists, skipping creation", [], 1);
                }
            }
            // Check and add revision_of column to yuz_tra_translations if missing
            $translations_table = $prefix . 'yuz_tra_translations';
            $columns = (array)$wpdb->get_results("SHOW COLUMNS FROM `{$translations_table}`");
            $actual_columns = array_column($columns, 'Field');
            if (!in_array('revision_of', $actual_columns)) {
                $this->logger->log('warning', "Column revision_of missing in $translations_table, adding it", ['class' => __CLASS__]);
                $add_column_sql = "ALTER TABLE `{$translations_table}`
                    ADD COLUMN revision_of BIGINT(20) UNSIGNED NULL DEFAULT NULL COMMENT 'If manual, refers to original auto-translation ID'";
                $attempts = 3;
                while ($attempts > 0) {
                    $wpdb->query($add_column_sql);
                    if (!$wpdb->last_error) {
                        break;
                    }
                    $attempts--;
                    $this->logger->log('warning', "Retry attempt for adding revision_of column to $translations_table, attempts left: {$attempts}", ['class' => __CLASS__]);
                    usleep(100000);
                }
                if ($wpdb->last_error) {
                    $failed_tables[] = ['table' => $translations_table, 'error' => "Failed to add revision_of column: " . $wpdb->last_error];
                    $this->logger->log('error', "Failed to add revision_of column to $translations_table: " . $wpdb->last_error, ['class' => __CLASS__]);
                    $this->log_action('ensure_tables', "Failed to add revision_of column to $translations_table", ['error' => $wpdb->last_error], 0);
                } else {
                    $this->logger->log('success', "Column revision_of added to $translations_table", ['class' => __CLASS__]);
                    $this->log_action('ensure_tables', "Column revision_of added to $translations_table", [], 1);
                }
            } else {
                $this->logger->log('info', "Column revision_of exists in $translations_table", ['class' => __CLASS__]);
            }
            // Apply foreign key constraints
            $wpdb->query('SET FOREIGN_KEY_CHECKS = 0;');
            foreach ($foreign_keys as $table_name => $fk_definitions) {
                $full_table_name = $prefix . $table_name;
                if (is_array($fk_definitions)) {
                    foreach ($fk_definitions as $fk_sql) {
                        preg_match('/ADD CONSTRAINT (\w+)/', $fk_sql, $matches); // Fixé regex
                        $constraint_name = !empty($matches[1]) ? $matches[1] : '';
                        if ($constraint_name && $this->constraint_exists($full_table_name, $constraint_name)) {
                            $this->logger->log('info', "Foreign key constraint $constraint_name already exists on $full_table_name, skipping", ['class' => __CLASS__]);
                            $this->log_action('ensure_tables', "Foreign key constraint $constraint_name already exists on $full_table_name, skipping", [], 1);
                            continue;
                        }
                        $this->logger->log('info', "Applying foreign key to $full_table_name", ['class' => __CLASS__]);
                        $attempts = 3;
                        while ($attempts > 0) {
                            $wpdb->query($fk_sql);
                            if (!$wpdb->last_error) {
                                break;
                            }
                            $attempts--;
                            $this->logger->log('warning', "Retry attempt for applying foreign key to $full_table_name, attempts left: {$attempts}", ['class' => __CLASS__]);
                            usleep(100000);
                        }
                        if ($wpdb->last_error) {
                            $failed_tables[] = ['table' => $full_table_name, 'error' => $wpdb->last_error];
                            $this->logger->log('error', "Failed to apply foreign key to $full_table_name: " . $wpdb->last_error, ['class' => __CLASS__]);
                            $this->log_action('ensure_tables', "Failed to apply foreign key to $full_table_name", ['error' => $wpdb->last_error], 0);
                        } else {
                            $this->logger->log('success', "Foreign key applied to $full_table_name", ['class' => __CLASS__]);
                            $this->log_action('ensure_tables', "Foreign key applied to $full_table_name", [], 1);
                        }
                    }
                } else {
                    preg_match('/ADD CONSTRAINT (\w+)/', $fk_definitions, $matches); // Fixé regex
                    $constraint_name = !empty($matches[1]) ? $matches[1] : '';
                    if ($constraint_name && $this->constraint_exists($full_table_name, $constraint_name)) {
                        $this->logger->log('info', "Foreign key constraint $constraint_name already exists on $full_table_name, skipping", ['class' => __CLASS__]);
                        $this->log_action('ensure_tables', "Foreign key constraint $constraint_name already exists on $full_table_name, skipping", [], 1);
                        continue;
                    }
                    $this->logger->log('info', "Applying foreign key to $full_table_name", ['class' => __CLASS__]);
                    $attempts = 3;
                    while ($attempts > 0) {
                        $wpdb->query($fk_definitions);
                        if (!$wpdb->last_error) {
                            break;
                        }
                        $attempts--;
                        $this->logger->log('warning', "Retry attempt for applying foreign key to $full_table_name, attempts left: {$attempts}", ['class' => __CLASS__]);
                        usleep(100000);
                    }
                    if ($wpdb->last_error) {
                        $failed_tables[] = ['table' => $full_table_name, 'error' => $wpdb->last_error];
                        $this->logger->log('error', "Failed to apply foreign key to $full_table_name: " . $wpdb->last_error, ['class' => __CLASS__]);
                        $this->log_action('ensure_tables', "Failed to apply foreign key to $full_table_name", ['error' => $wpdb->last_error], 0);
                    } else {
                        $this->logger->log('success', "Foreign key applied to $full_table_name", ['class' => __CLASS__]);
                        $this->log_action('ensure_tables', "Foreign key applied to $full_table_name", [], 1);
                    }
                }
            }
            $wpdb->query('SET FOREIGN_KEY_CHECKS = 1;');
            if (!empty($failed_tables)) {
                $this->logger->log('error', "Table creation completed with errors: " . print_r($failed_tables, true), ['class' => __CLASS__]);
                $this->log_action('ensure_tables', 'Table creation completed with errors', ['failed_tables' => $failed_tables], 0);
                return false;
            }
            // Import default languages
            $this->logger->log('info', 'Checking if languages table is empty', ['class' => __CLASS__]);
            $existing_languages = null;
            $attempts = 3;
            while ($attempts > 0) {
                $existing_languages = $wpdb->get_var("SELECT COUNT(*) FROM `{$prefix}yuz_tra_languages`");
                if ($existing_languages !== null) {
                    break;
                }
                $attempts--;
                $this->logger->log('warning', "Retry attempt for checking languages table count, attempts left: {$attempts}");
                usleep(100000);
            }
            if ($existing_languages === null) {
                $this->logger->log('error', "Failed to check languages table count: " . $wpdb->last_error, ['class' => __CLASS__]);
                $this->log_action('ensure_tables', "Failed to check languages table count", ['error' => $wpdb->last_error], 0);
                return false;
            }
            if ($existing_languages == 0) {
                $this->logger->log('info', 'Populating languages table with pre-filled languages (all flags set to 0)');
                $default_languages = [
                    ['language_name' => 'Arabic', 'native_name' => 'العربية', 'language_code' => 'ar_SA', 'slug' => 'ar', 'flag_svg' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 900 600"><path fill="#006c35" d="M0 0h900v600H0z"/><path fill="#fff" d="M0 200h900v200H0z"/></svg>', 'browser_slug' => 'ar', 'is_default' => 0, 'is_source' => 0, 'is_translatable' => 0, 'language_weight' => 0],
                    ['language_name' => 'Azerbaijani', 'native_name' => 'Azərbaycan dili', 'language_code' => 'az_AZ', 'slug' => 'az', 'flag_svg' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 640 480"><path fill="#00b9e4" d="M0 0h640v160H0z"/><path fill="#3f9c35" d="M0 160h640v160H0z"/><path fill="#ed2939" d="M0 320h640v160H0z"/><circle fill="#fff" cx="280" cy="240" r="72"/><circle fill="#ed2939" cx="296" cy="240" r="60"/><path fill="#fff" d="m376 216 8.1 24.9-104.1-75.7h129l-104.1 75.7z"/></svg>', 'browser_slug' => 'az', 'is_default' => 0, 'is_source' => 0, 'is_translatable' => 0, 'language_weight' => 0],
                    ['language_name' => 'Basque', 'native_name' => 'Euskara', 'language_code' => 'eu_ES', 'slug' => 'eu', 'flag_svg' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 750 500"><path fill="#d52b1e" d="M0 0h750v500H0z"/><path fill="#009b48" d="M0 0h300v200H0z"/><path fill="#fff" d="M300 0h150v500H300zM0 300h750v100H0z"/><path fill="#d52b1e" d="M450 0h300v200H450z"/></svg>', 'browser_slug' => 'eu', 'is_default' => 0, 'is_source' => 0, 'is_translatable' => 0, 'language_weight' => 0],
                    ['language_name' => 'Bengali', 'native_name' => 'বাংলা', 'language_code' => 'bn_BD', 'slug' => 'bn', 'flag_svg' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1000 600"><path fill="#006a4e" d="M0 0h1000v600H0z"/><circle fill="#f42a41" cx="400" cy="300" r="200"/></svg>', 'browser_slug' => 'bn', 'is_default' => 0, 'is_source' => 0, 'is_translatable' => 0, 'language_weight' => 0],
                    ['language_name' => 'Bulgarian', 'native_name' => 'Български', 'language_code' => 'bg_BG', 'slug' => 'bg', 'flag_svg' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1000 600"><path fill="#fff" d="M0 0h1000v200H0z"/><path fill="#00966e" d="M0 200h1000v200H0z"/><path fill="#d62612" d="M0 400h1000v200H0z"/></svg>', 'browser_slug' => 'bg', 'is_default' => 0, 'is_source' => 0, 'is_translatable' => 0, 'language_weight' => 0],
                    ['language_name' => 'Catalan', 'native_name' => 'Català', 'language_code' => 'ca_ES', 'slug' => 'ca', 'flag_svg' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 750 500"><path fill="#ffc107" d="M0 0h750v500H0z"/><path fill="#d81b60" d="M0 0h750v55.56H0zM0 111.11h750v55.56H0zM0 222.22h750v55.56H0zM0 333.33h750v55.56H0z"/></svg>', 'browser_slug' => 'ca', 'is_default' => 0, 'is_source' => 0, 'is_translatable' => 0, 'language_weight' => 0],
                    ['language_name' => 'Chinese (Simplified)', 'native_name' => '中文（简体）', 'language_code' => 'zh_CN', 'slug' => 'zh', 'flag_svg' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 900 600"><path fill="#de2910" d="M0 0h900v600H0z"/><path fill="#ffde00" d="m250 150 39.7 119-104.1-75.7h129l-104.1 75.7zm87.5 0 12.3 37-32.3-23.5h40l-32.3 23.5zm62.5 0 12.3 37-32.3-23.5h40l-32.3 23.5zm87.5 0 12.3 37-32.3-23.5h40l-32.3 23.5z"/></svg>', 'browser_slug' => 'zh-cn', 'is_default' => 0, 'is_source' => 0, 'is_translatable' => 0, 'language_weight' => 0],
                    ['language_name' => 'Chinese (Traditional)', 'native_name' => '中文（繁體）', 'language_code' => 'zh_TW', 'slug' => 'zh_tw', 'flag_svg' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 900 600"><path fill="#000095" d="M0 300h900v300H0z"/><path fill="#fff" d="M0 300h900v300H0z"/><path fill="#de2910" d="M0 0h450v300H0z"/><circle fill="#fff" cx="225" cy="150" r="90"/><circle fill="#000095" cx="225" cy="150" r="60"/><path fill="#fff" d="M200 150l15 45-39-28h48l-39 28z"/></svg>', 'browser_slug' => 'zh-tw', 'is_default' => 0, 'is_source' => 0, 'is_translatable' => 0, 'language_weight' => 0],
                    ['language_name' => 'Czech', 'native_name' => 'Čeština', 'language_code' => 'cs_CZ', 'slug' => 'cs', 'flag_svg' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 900 600"><path fill="#fff" d="M0 0h900v300H0z"/><path fill="#d7141a" d="M0 300h900v300H0z"/><path fill="#11457e" d="M0 0v600l300-300z"/></svg>', 'browser_slug' => 'cs', 'is_default' => 0, 'is_source' => 0, 'is_translatable' => 0, 'language_weight' => 0],
                    ['language_name' => 'Danish', 'native_name' => 'Dansk', 'language_code' => 'da_DK', 'slug' => 'da', 'flag_svg' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 700 500"><path fill="#c60c30" d="M0 0h700v500H0z"/><path fill="#fff" d="M200 0h100v500H200zM0 200h700v100H0z"/></svg>', 'browser_slug' => 'da', 'is_default' => 0, 'is_source' => 0, 'is_translatable' => 0, 'language_weight' => 0],
                    ['language_name' => 'Dutch', 'native_name' => 'Nederlands', 'language_code' => 'nl_NL', 'slug' => 'nl', 'flag_svg' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 900 600"><path fill="#c8102e" d="M0 0h900v200H0z"/><path fill="#fff" d="M0 200h900v200H0z"/><path fill="#003087" d="M0 400h900v200H0z"/></svg>', 'browser_slug' => 'nl', 'is_default' => 0, 'is_source' => 0, 'is_translatable' => 0, 'language_weight' => 0],
                    ['language_name' => 'English (UK)', 'native_name' => 'English', 'language_code' => 'en_GB', 'slug' => 'en', 'flag_svg' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1200 600"><path fill="#00247d" d="M0 0h1200v600H0z"/><path fill="#fff" d="m0 0 600 600m0-600-600 600" stroke-width="120"/><path fill="#cf142b" d="m0 0 600 600m0-600-600 600" stroke-width="80"/><path fill="#fff" d="M600 0v600M0 300h1200" stroke-width="120"/><path fill="#cf142b" d="M600 0v600M0 300h1200" stroke-width="80"/></svg>', 'browser_slug' => 'en-gb', 'is_default' => 0, 'is_source' => 0, 'is_translatable' => 0, 'language_weight' => 0],
                    ['language_name' => 'English (US)', 'native_name' => 'English', 'language_code' => 'en_US', 'slug' => 'en_us', 'flag_svg' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1800 900"><path fill="#b22234" d="M0 0h1800v900H0z"/><path fill="#fff" d="M0 0h1800v69.23H0zM0 138.46h1800v69.23H0zM0 276.92h1800v69.23H0zM0 415.38h1800v69.23H0zM0 553.85h1800v69.23H0zM0 692.31h1800v69.23H0zM0 830.77h1800v69.23H0z"/><path fill="#3c3b6e" d="M0 0h720v420H0z"/><path fill="#fff" d="m90 30 6 18-15-11h18l-15 11zm90 0 6 18-15-11h18l-15 11zm90 0 6 18-15-11h18l-15 11zm90 0 6 18-15-11h18l-15 11zm90 0 6 18-15-11h18l-15 11zm90 0 6 18-15-11h18l-15 11zm90 0 6 18-15-11h18l-15 11zm90 0 6 18-15-11h18l-15 11z"/></svg>', 'browser_slug' => 'en', 'is_default' => 0, 'is_source' => 0, 'is_translatable' => 0, 'language_weight' => 0],
                    ['language_name' => 'Estonian', 'native_name' => 'Eesti', 'language_code' => 'et_EE', 'slug' => 'et', 'flag_svg' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 990 630"><path fill="#0072ce" d="M0 0h990v210H0z"/><path fill="#000" d="M0 210h990v210H0z"/><path fill="#fff" d="M0 420h990v210H0z"/></svg>', 'browser_slug' => 'et', 'is_default' => 0, 'is_source' => 0, 'is_translatable' => 0, 'language_weight' => 0],
                    ['language_name' => 'Finnish', 'native_name' => 'Suomi', 'language_code' => 'fi_FI', 'slug' => 'fi', 'flag_svg' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1800 1100"><path fill="#fff" d="M0 0h1800v1100H0z"/><path fill="#003087" d="M500 0h300v1100H500zM0 400h1800v300H0z"/></svg>', 'browser_slug' => 'fi', 'is_default' => 0, 'is_source' => 0, 'is_translatable' => 0, 'language_weight' => 0],
                    ['language_name' => 'French', 'native_name' => 'Français', 'language_code' => 'fr_FR', 'slug' => 'fr', 'flag_svg' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 900 600"><path fill="#0055a4" d="M0 0h300v600H0z"/><path fill="#fff" d="M300 0h300v600H300z"/><path fill="#ef4135" d="M600 0h300v600H600z"/></svg>', 'browser_slug' => 'fr', 'is_default' => 0, 'is_source' => 0, 'is_translatable' => 0, 'language_weight' => 0],
                    ['language_name' => 'Galician', 'native_name' => 'Galego', 'language_code' => 'gl_ES', 'slug' => 'gl', 'flag_svg' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 750 500"><path fill="#fff" d="M0 0h750v500H0z"/><path fill="#00b7e9" d="M0 0h750v250H0z"/></svg>', 'browser_slug' => 'gl', 'is_default' => 0, 'is_source' => 0, 'is_translatable' => 0, 'language_weight' => 0],
                    ['language_name' => 'German', 'native_name' => 'Deutsch', 'language_code' => 'de_DE', 'slug' => 'de', 'flag_svg' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1000 600"><path fill="#000" d="M0 0h1000v200H0z"/><path fill="#d00" d="M0 200h1000v200H0z"/><path fill="#ffce00" d="M0 400h1000v200H0z"/></svg>', 'browser_slug' => 'de', 'is_default' => 0, 'is_source' => 0, 'is_translatable' => 0, 'language_weight' => 0],
                    ['language_name' => 'Greek', 'native_name' => 'Ελληνικά', 'language_code' => 'el_GR', 'slug' => 'el', 'flag_svg' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 900 600"><path fill="#005577" d="M0 0h900v600H0z"/><path fill="#fff" d="M0 0h900v66.67H0zM0 133.33h900v66.67H0zM0 266.67h900v66.67H0zM0 400h900v66.67H0zM0 0h300v300H0zM150 0h150v300H150z"/></svg>', 'browser_slug' => 'el', 'is_default' => 0, 'is_source' => 0, 'is_translatable' => 0, 'language_weight' => 0],
                    ['language_name' => 'Hebrew', 'native_name' => 'עברית', 'language_code' => 'he_IL', 'slug' => 'he', 'flag_svg' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 660 480"><path fill="#0038b8" d="M0 0h660v80H0zM0 400h660v80H0z"/><path fill="#fff" d="M0 80h660v320H0z"/><path fill="#0038b8" d="m220 120h220v40H220zM220 320h220v40H220z"/></svg>', 'browser_slug' => 'he', 'is_default' => 0, 'is_source' => 0, 'is_translatable' => 0, 'language_weight' => 0],
                    ['language_name' => 'Hindi', 'native_name' => 'हिन्दी', 'language_code' => 'hi_IN', 'slug' => 'hi', 'flag_svg' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 900 600"><path fill="#f93" d="M0 0h900v200H0z"/><path fill="#fff" d="M0 200h900v200H0z"/><path fill="#128807" d="M0 400h900v200H0z"/><circle fill="#000080" cx="450" cy="300" r="100"/><circle fill="#fff" cx="450" cy="300" r="80"/></svg>', 'browser_slug' => 'hi', 'is_default' => 0, 'is_source' => 0, 'is_translatable' => 0, 'language_weight' => 0],
                    ['language_name' => 'Hungarian', 'native_name' => 'Magyar', 'language_code' => 'hu_HU', 'slug' => 'hu', 'flag_svg' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1200 600"><path fill="#ce1126" d="M0 0h1200v200H0z"/><path fill="#fff" d="M0 200h1200v200H0z"/><path fill="#008d46" d="M0 400h1200v200H0z"/></svg>', 'browser_slug' => 'hu', 'is_default' => 0, 'is_source' => 0, 'is_translatable' => 0, 'language_weight' => 0],
                    ['language_name' => 'Indonesian', 'native_name' => 'Bahasa Indonesia', 'language_code' => 'id_ID', 'slug' => 'id', 'flag_svg' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 900 600"><path fill="#ce1126" d="M0 0h900v300H0z"/><path fill="#fff" d="M0 300h900v300H0z"/></svg>', 'browser_slug' => 'id', 'is_default' => 0, 'is_source' => 0, 'is_translatable' => 0, 'language_weight' => 0],
                    ['language_name' => 'Irish', 'native_name' => 'Gaeilge', 'language_code' => 'ga_IE', 'slug' => 'ga', 'flag_svg' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1200 600"><path fill="#169b62" d="M0 0h400v600H0z"/><path fill="#fff" d="M400 0h400v600H400z"/><path fill="#ff883e" d="M800 0h400v600H800z"/></svg>', 'browser_slug' => 'ga', 'is_default' => 0, 'is_source' => 0, 'is_translatable' => 0, 'language_weight' => 0],
                    ['language_name' => 'Italian', 'native_name' => 'Italiano', 'language_code' => 'it_IT', 'slug' => 'it', 'flag_svg' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1500 1000"><path fill="#009246" d="M0 0h500v1000H0z"/><path fill="#fff" d="M500 0h500v1000H500z"/><path fill="#ce2b37" d="M1000 0h500v1000H1000z"/></svg>', 'browser_slug' => 'it', 'is_default' => 0, 'is_source' => 0, 'is_translatable' => 0, 'language_weight' => 0],
                    ['language_name' => 'Japanese', 'native_name' => '日本語', 'language_code' => 'ja_JP', 'slug' => 'ja', 'flag_svg' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 900 600"><path fill="#fff" d="M0 0h900v600H0z"/><circle fill="#bc002d" cx="450" cy="300" r="180"/></svg>', 'browser_slug' => 'ja', 'is_default' => 0, 'is_source' => 0, 'is_translatable' => 0, 'language_weight' => 0],
                    ['language_name' => 'Latvian', 'native_name' => 'Latviešu', 'language_code' => 'lv_LV', 'slug' => 'lv', 'flag_svg' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1000 500"><path fill="#9e1b34" d="M0 0h1000v200H0zM0 300h1000v200H0z"/><path fill="#fff" d="M0 200h1000v100H0z"/></svg>', 'browser_slug' => 'lv', 'is_default' => 0, 'is_source' => 0, 'is_translatable' => 0, 'language_weight' => 0],
                    ['language_name' => 'Lithuanian', 'native_name' => 'Lietuvių', 'language_code' => 'lt_LT', 'slug' => 'lt', 'flag_svg' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1000 600"><path fill="#ffb915" d="M0 0h1000v200H0z"/><path fill="#006a44" d="M0 200h1000v200H0z"/><path fill="#c1272d" d="M0 400h1000v200H0z"/></svg>', 'browser_slug' => 'lt', 'is_default' => 0, 'is_source' => 0, 'is_translatable' => 0, 'language_weight' => 0],
                    ['language_name' => 'Malay', 'native_name' => 'Bahasa Melayu', 'language_code' => 'ms_MY', 'slug' => 'ms', 'flag_svg' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 2800 1400"><path fill="#010066" d="M0 0h2800v1400H0z"/><path fill="#fff" d="M0 0h2800v100H0zm0 200h2800v100H0zm0 400h2800v100H0zm0 600h2800v100H0z"/><path fill="#cc0000" d="M0 100h2800v100H0zm0 300h2800v100H0zm0 500h2800v100H0zm0 700h2800v100H0z"/><path fill="#fff" d="M0 0h1400v700H0z"/><path fill="#010066" d="M0 300h900v300H0z"/><circle fill="#ff0" cx="700" cy="350" r="200"/><path fill="#010066" d="m700 250 30 15-30-60-15 45 45 0z"/></svg>', 'browser_slug' => 'ms', 'is_default' => 0, 'is_source' => 0, 'is_translatable' => 0, 'language_weight' => 0],
                    ['language_name' => 'Norwegian', 'native_name' => 'Norsk', 'language_code' => 'no_NO', 'slug' => 'no', 'flag_svg' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 800 600"><path fill="#ef2b2d" d="M0 0h800v600H0z"/><path fill="#fff" d="M200 0h100v600H200zM0 200h800v100H0z"/><path fill="#002868" d="M240 0h40v600h-40zM0 240h800v40H0z"/></svg>', 'browser_slug' => 'no', 'is_default' => 0, 'is_source' => 0, 'is_translatable' => 0, 'language_weight' => 0],
                    ['language_name' => 'Persian', 'native_name' => 'فارسی', 'language_code' => 'fa_IR', 'slug' => 'fa', 'flag_svg' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 630 360"><path fill="#239bcd" d="M0 0h630v120H0z"/><path fill="#fff" d="M0 120h630v120H0z"/><path fill="#d81b60" d="M0 240h630v120H0z"/></svg>', 'browser_slug' => 'fa', 'is_default' => 0, 'is_source' => 0, 'is_translatable' => 0, 'language_weight' => 0],
                    ['language_name' => 'Polish', 'native_name' => 'Polski', 'language_code' => 'pl_PL', 'slug' => 'pl', 'flag_svg' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 800 500"><path fill="#fff" d="M0 0h800v250H0z"/><path fill="#dc143c" d="M0 250h800v250H0z"/></svg>', 'browser_slug' => 'pl', 'is_default' => 0, 'is_source' => 0, 'is_translatable' => 0, 'language_weight' => 0],
                    ['language_name' => 'Portuguese (Brazil)', 'native_name' => 'Português (Brasil)', 'language_code' => 'pt_BR', 'slug' => 'pt_br', 'flag_svg' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1000 700"><path fill="#009b3a" d="M0 0h1000v700H0z"/><path fill="#fedf00" d="M500 350l433-315-93-315z"/><circle fill="#002776" cx="500" cy="350" r="150"/><path fill="#fff" d="m500 300 30 90-78-57h96l-78 57z"/></svg>', 'browser_slug' => 'pt-br', 'is_default' => 0, 'is_source' => 0, 'is_translatable' => 0, 'language_weight' => 0],
                    ['language_name' => 'Portuguese (Portugal)', 'native_name' => 'Português (Portugal)', 'language_code' => 'pt_PT', 'slug' => 'pt', 'flag_svg' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 600 400"><path fill="#006600" d="M0 0h200v400H0z"/><path fill="#ff0000" d="M200 0h400v400H200z"/><circle fill="#ffc107" cx="200" cy="200" r="100"/><path fill="#003399" d="M200 150l15 45-39-28h48l-39 28z"/></svg>', 'browser_slug' => 'pt', 'is_default' => 0, 'is_source' => 0, 'is_translatable' => 0, 'language_weight' => 0],
                    ['language_name' => 'Romanian', 'native_name' => 'Română', 'language_code' => 'ro_RO', 'slug' => 'ro', 'flag_svg' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 900 600"><path fill="#002b7f" d="M0 0h300v600H0z"/><path fill="#fce300" d="M300 0h300v600H300z"/><path fill="#ce1126" d="M600 0h300v600H600z"/></svg>', 'browser_slug' => 'ro', 'is_default' => 0, 'is_source' => 0, 'is_translatable' => 0, 'language_weight' => 0],
                    ['language_name' => 'Russian', 'native_name' => 'Русский', 'language_code' => 'ru_RU', 'slug' => 'ru', 'flag_svg' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 900 600"><path fill="#fff" d="M0 0h900v200H0z"/><path fill="#0039a6" d="M0 200h900v200H0z"/><path fill="#d52b1e" d="M0 400h900v200H0z"/></svg>', 'browser_slug' => 'ru', 'is_default' => 0, 'is_source' => 0, 'is_translatable' => 0, 'language_weight' => 0],
                    ['language_name' => 'Slovak', 'native_name' => 'Slovenčina', 'language_code' => 'sk_SK', 'slug' => 'sk', 'flag_svg' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 900 600"><path fill="#fff" d="M0 0h900v300H0z"/><path fill="#0b4ea2" d="M0 300h900v300H0z"/><path fill="#ee1c25" d="M0 600h900v300H0z"/><path fill="#fff" d="M300 0h150v600H300z"/><path fill="#0b4ea2" d="M225 150l75 150-75 150z"/></svg>', 'browser_slug' => 'sk', 'is_default' => 0, 'is_source' => 0, 'is_translatable' => 0, 'language_weight' => 0],
                    ['language_name' => 'Slovenian', 'native_name' => 'Slovenščina', 'language_code' => 'sl_SI', 'slug' => 'sl', 'flag_svg' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1000 500"><path fill="#fff" d="M0 0h1000v166.67H0z"/><path fill="#003087" d="M0 166.67h1000v166.67H0z"/><path fill="#f31830" d="M0 333.33h1000v166.67H0z"/><path fill="#fff" d="M250 0h250v250H250z"/></svg>', 'browser_slug' => 'sl', 'is_default' => 0, 'is_source' => 0, 'is_translatable' => 0, 'language_weight' => 0],
                    ['language_name' => 'Spanish', 'native_name' => 'Español', 'language_code' => 'es_ES', 'slug' => 'es', 'flag_svg' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 750 500"><path fill="#c60c30" d="M0 0h750v125H0zM0 375h750v125H0z"/><path fill="#ffc107" d="M0 125h750v250H0z"/></svg>', 'browser_slug' => 'es', 'is_default' => 0, 'is_source' => 0, 'is_translatable' => 0, 'language_weight' => 0],
                    ['language_name' => 'Swedish', 'native_name' => 'Svenska', 'language_code' => 'sv_SE', 'slug' => 'sv', 'flag_svg' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1600 1000"><path fill="#006aa7" d="M0 0h1600v1000H0z"/><path fill="#fecc00" d="M400 0h200v1000H400zM0 400h1600v200H0z"/></svg>', 'browser_slug' => 'sv', 'is_default' => 0, 'is_source' => 0, 'is_translatable' => 0, 'language_weight' => 0],
                    ['language_name' => 'Tagalog', 'native_name' => 'Tagalog', 'language_code' => 'tl_PH', 'slug' => 'tl', 'flag_svg' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 900 450"><path fill="#0038a8" d="M0 0h900v225H0z"/><path fill="#ce1126" d="M0 225h900v225H0z"/><path fill="#fff" d="M0 0v450l450-225z"/><circle fill="#ffc107" cx="150" cy="225" r="60"/><path fill="#ffc107" d="m100 225 15 45-39-28h48l-39 28z"/></svg>', 'browser_slug' => 'tl', 'is_default' => 0, 'is_source' => 0, 'is_translatable' => 0, 'language_weight' => 0],
                    ['language_name' => 'Thai', 'native_name' => 'ไทย', 'language_code' => 'th_TH', 'slug' => 'th', 'flag_svg' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 900 600"><path fill="#a51931" d="M0 0h900v120H0zM0 480h900v120H0z"/><path fill="#fff" d="M0 120h900v120H0zM0 360h900v120H0z"/><path fill="#2a5f9e" d="M0 240h900v120H0z"/></svg>', 'browser_slug' => 'th', 'is_default' => 0, 'is_source' => 0, 'is_translatable' => 0, 'language_weight' => 0],
                    ['language_name' => 'Turkish', 'native_name' => 'Türkçe', 'language_code' => 'tr_TR', 'slug' => 'tr', 'flag_svg' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1200 800"><path fill="#e30a17" d="M0 0h1200v800H0z"/><circle fill="#fff" cx="480" cy="400" r="200"/><circle fill="#e30a17" cx="540" cy="400" r="160"/><path fill="#fff" d="m600 340 30 15-30-60-15 45 45 0z"/></svg>', 'browser_slug' => 'tr', 'is_default' => 0, 'is_source' => 0, 'is_translatable' => 0, 'language_weight' => 0],
                    ['language_name' => 'Ukrainian', 'native_name' => 'Українська', 'language_code' => 'uk_UA', 'slug' => 'uk', 'flag_svg' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1200 800"><path fill="#0057b7" d="M0 0h1200v400H0z"/><path fill="#ffd700" d="M0 400h1200v400H0z"/></svg>', 'browser_slug' => 'uk', 'is_default' => 0, 'is_source' => 0, 'is_translatable' => 0, 'language_weight' => 0],
                    ['language_name' => 'Urdu', 'native_name' => 'اردو', 'language_code' => 'ur_PK', 'slug' => 'ur', 'flag_svg' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 900 600"><path fill="#fff" d="M0 0h900v600H0z"/><path fill="#3b5a00" d="M0 0h225v600H0z"/><circle fill="#fff" cx="525" cy="300" r="100"/><path fill="#3b5a00" d="m600 200 30 15-30-60-15 45 45 0z"/></svg>', 'browser_slug' => 'ur', 'is_default' => 0, 'is_source' => 0, 'is_translatable' => 0, 'language_weight' => 0],
                    ['language_name' => 'Vietnamese', 'native_name' => 'Tiếng Việt', 'language_code' => 'vi_VN', 'slug' => 'vi', 'flag_svg' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 900 600"><path fill="#da251d" d="M0 0h900v600H0z"/><path fill="#ffff00" d="m450 150 30 150-120-90h180l-120 90z"/></svg>', 'browser_slug' => 'vi', 'is_default' => 0, 'is_source' => 0, 'is_translatable' => 0, 'language_weight' => 0],
                ];
                $wpdb->query('START TRANSACTION');
                $inserted_languages = [];
                $failed_languages = [];
                foreach ($default_languages as $lang) {
                    if (!isset($lang['language_code'], $lang['language_name'], $lang['slug'])) {
                        $this->logger->log('error', "Invalid language data: " . print_r($lang, true));
                        $failed_languages[] = ['language_code' => $lang['language_code'] ?? 'unknown', 'error' => 'Missing required fields'];
                        continue;
                    }
                    $exists = null;
                    $attempts = 3;
                    while ($attempts > 0) {
                        $exists = $wpdb->get_var($wpdb->prepare(
                            "SELECT COUNT(*) FROM `{$prefix}yuz_tra_languages` WHERE language_code = %s OR slug = %s",
                            $lang['language_code'],
                            $lang['slug']
                        ));
                        if ($exists !== null) {
                            break;
                        }
                        $attempts--;
                        $this->logger->log('warning', "Retry attempt for checking language {$lang['language_code']} existence, attempts left: {$attempts}");
                        usleep(100000);
                    }
                    if ($exists === null) {
                        $this->logger->log('error', "Failed to check language {$lang['language_code']} existence: " . $wpdb->last_error);
                        $failed_languages[] = ['language_code' => $lang['language_code'], 'error' => $wpdb->last_error];
                        continue;
                    }
                    if ($exists) {
                        $this->logger->log('info', "Language {$lang['language_code']} or slug {$lang['slug']} already exists, skipping");
                        $this->log_action('ensure_tables', "Language {$lang['language_code']} or slug {$lang['slug']} already exists, skipping", [], 1);
                        continue;
                    }
                    $result = null;
                    $attempts = 3;
                    while ($attempts > 0) {
                        $result = $wpdb->insert(
                            "{$prefix}yuz_tra_languages",
                            [
                                'locale'          => $lang['language_code'],        // NEW
                                'language_name'   => $lang['language_name'],
                                'native_name'     => $lang['native_name'] ?? $lang['language_name'],
                                'language_code'   => $lang['language_code'],
                                'slug'            => $lang['slug'],
                                'flag_svg'        => $lang['flag_svg'] ?? '',
                                'browser_slug'    => $lang['browser_slug'] ?? '',
                                'is_default'      => $lang['is_default'] ?? 0,
                                'is_source'       => $lang['is_source'] ?? 0,
                                'is_translatable' => $lang['is_translatable'] ?? 0,
                                'language_weight' => $lang['language_weight'] ?? 0,
                                'created_at'      => current_time('mysql'),
                                'updated_at'      => current_time('mysql'),
                            ],
                            ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%s', '%s']
                        );
                        if ($result !== null) {
                            break;
                        }
                        $attempts--;
                        $this->logger->log('warning', "Retry attempt for inserting language {$lang['language_code']}, attempts left: {$attempts}");
                        usleep(100000);
                    }
                    if ($result === false) {
                        $this->logger->log('error', "Failed to insert language {$lang['language_code']}: " . $wpdb->last_error);
                        $failed_languages[] = ['language_code' => $lang['language_code'], 'error' => $wpdb->last_error];
                    } else {
                        $inserted_languages[] = $lang['language_code'];
                        $this->logger->log('success', "Successfully inserted language {$lang['language_code']}");
                        $this->log_action('ensure_tables', "Successfully inserted language {$lang['language_code']}", [], 1);
                    }
                }
                if (!empty($failed_languages)) {
                    $this->logger->log('error', 'Failed to insert some languages: ' . print_r($failed_languages, true));
                    $this->log_action('ensure_tables', 'Failed to insert some languages', ['failed' => $failed_languages], 0);
                    $wpdb->query('ROLLBACK');
                    return false;
                }
                if (empty($inserted_languages)) {
                    $this->logger->log('error', 'No languages were inserted');
                    $this->log_action('ensure_tables', 'No languages were inserted', [], 0);
                    $wpdb->query('ROLLBACK');
                    return false;
                }
                $wpdb->query('COMMIT');
                $integrity_report[] = 'Languages table pre-filled with: ' . implode(', ', $inserted_languages);
                $this->logger->log('success', 'Languages table pre-filled with: ' . implode(', ', $inserted_languages));
                $this->log_action('ensure_tables', 'Languages table pre-filled', ['languages' => $inserted_languages], 1);
            } else {
                $this->logger->log('info', "Languages table contains $existing_languages entries, skipping pre-filling");
                $this->log_action('ensure_tables', "Languages table contains $existing_languages entries, skipping pre-filling", [], 1);
            }

            $this->seed_global_settings();

            // Verify table integrity
            $this->logger->log('info', 'Verifying table integrity');
            $integrity_report = [];
            $all_tables_exist = true;
            foreach (array_keys($tables_definitions) as $table_name) {
                $full_table_name = $prefix . $table_name;
                $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $full_table_name)); // Force prepare
                if (!$exists) {
                    $all_tables_exist = false;
                    $integrity_report[] = "Table $full_table_name is missing.";
                    $this->logger->log('error', "Integrity check failed - Table $full_table_name does not exist");
                } else {
                    $integrity_report[] = "Table $full_table_name exists.";
                    $this->logger->log('info', "Integrity check passed - Table $full_table_name exists");
                    $columns = (array)$wpdb->get_results("SHOW COLUMNS FROM `{$full_table_name}`");
                    $indexes = (array)$wpdb->get_results("SHOW INDEX FROM `{$full_table_name}`");
                    $column_count = count($columns);
                    $index_count = count($indexes);
                    $integrity_report[] = "Table $full_table_name has $column_count columns and $index_count indexes.";
                    $this->logger->log('info', "Table $full_table_name has $column_count columns and $index_count indexes");
                    if ($table_name === 'yuz_tra_languages') {
                        $flags = null;
                        $attempts = 3;
                        while ($attempts > 0) {
                            $flags = $wpdb->get_results("SELECT is_default, is_source, is_translatable FROM `{$full_table_name}` WHERE is_default != 0 OR is_source != 0 OR is_translatable != 0", ARRAY_A);
                            if ($flags !== null) {
                                break;
                            }
                            $attempts--;
                            $this->logger->log('warning', "Retry attempt for retrieving flags from $full_table_name, attempts left: {$attempts}");
                            usleep(100000);
                        }
                        if ($flags === null) {
                            $this->logger->log('error', "Failed to retrieve flags from $full_table_name: " . $wpdb->last_error);
                        } elseif (!empty($flags)) {
                            $integrity_report[] = "Table $full_table_name has non-zero flags: " . print_r($flags, true);
                            $this->logger->log('info', "Table $full_table_name has non-zero flags: " . print_r($flags, true));
                        } else {
                            $integrity_report[] = "Table $full_table_name has all flags set to 0.";
                            $this->logger->log('info', "Table $full_table_name has all flags set to 0");
                        }
                        $languages = null;
                        $attempts = 3;
                        while ($attempts > 0) {
                            $languages = $wpdb->get_results("SELECT language_code, language_name, slug, browser_slug FROM `{$full_table_name}`", ARRAY_A);
                            if ($languages !== null) {
                                break;
                            }
                            $attempts--;
                            $this->logger->log('warning', "Retry attempt for retrieving languages from $full_table_name, attempts left: {$attempts}");
                            usleep(100000);
                        }
                        if ($languages === null) {
                            $integrity_report[] = "Table $full_table_name has no languages.";
                            $this->logger->log('error', "Failed to retrieve languages from $full_table_name: " . $wpdb->last_error);
                        } else {
                            $integrity_report[] = "Table $full_table_name contains " . count($languages) . " languages: " . implode(', ', array_column($languages, 'language_code'));
                            $this->logger->log('info', "Table $full_table_name contains " . count($languages) . " languages: " . implode(', ', array_column($languages, 'language_code')));
                        }
                    }
                }
            }
            // Ensure coherence across already-populated rows
            // $wpdb->query("UPDATE {$prefix}yuz_tra_languages SET native_name = language_name WHERE native_name IS NULL OR native_name = ''");
            $wpdb->query("UPDATE {$prefix}yuz_tra_languages SET is_translatable = 1 WHERE is_source = 1 OR is_default = 1");

            // Log results and update DB version
            $final_report = implode("\n", $integrity_report);
            if (!$all_tables_exist) {
                $this->logger->log('error', "Table creation completed with errors:\n$final_report");
                $this->log_action('ensure_tables', 'Table creation completed with errors', ['report' => $integrity_report], 0);
                return false;
            }
            update_option(self::DB_VERSION_OPTION, self::DB_VERSION);
            update_option('tables_ok', true);
            $this->logger->log('success', "Table creation completed at " . current_time('mysql') . "\nDeployment Report:\n$final_report");
            $this->log_action('ensure_tables', 'Table creation completed', ['report' => $integrity_report], 1);
            return true;
        }
        /**
         * Checks and updates database schema if needed.
         *
         * @return void
         */
        public function check_schema(): void {
            $current_version = get_option(self::DB_VERSION_OPTION, '0.0.0');
            if (version_compare($current_version, self::DB_VERSION, '<')) {
                $message = sprintf(
                    'Database schema update required (current: %s → target: %s). Running ensure_tables().',
                    $current_version,
                    self::DB_VERSION
                );
                $this->logger->log('warning', $message);
                $ok = $this->ensure_tables();
                if ($ok) {
                    update_option(self::DB_VERSION_OPTION, self::DB_VERSION);
                } else {
                    $this->logger->log('critical', 'ensure_tables() failed during schema update.');
                }
            } else {
                $this->logger->log('info', "Database schema up to date. Version: $current_version");
                $this->log_action(
                    'check_schema',
                    'Database schema up to date',
                    ['version' => $current_version],
                    1
                );
            }
        }
        /**
         * Exécute une requête SELECT et renvoie un tableau associatif.
         *
         * @param string $sql La requête SQL, éventuellement avec des placeholders.
         * @param array $params Les paramètres à binder dans la requête.
         * @return array Résultats sous forme de tableau associatif.
         */
        public function query(string $sql, array $params = []): array {
            global $wpdb;
            // MODIF: Gate sur tables_ok pour reads aussi (Phase 4: cohérence globale)
            if (!get_option('tables_ok', false)) {
                $this->logger->log('critical', 'Tables not OK, skipping query', ['class' => __CLASS__]);
                return [];
            }
            if (!empty($params)) {
                $sql = $wpdb->prepare($sql, $params);
            }
            return $wpdb->get_results($sql, ARRAY_A) ?: [];
        }
        /**
         * Exécute une requête de modification (INSERT, UPDATE, DELETE).
         *
         * @param string $sql La requête SQL, éventuellement avec des placeholders.
         * @param array $params Les paramètres à binder dans la requête.
         * @return void
         */
        public function execute(string $sql, array $params = []): void {
            global $wpdb;
            // MODIF: Gate sur tables_ok (Phase 4)
            if (!get_option('tables_ok', false)) {
                $this->logger->log('critical', 'Tables not OK, skipping execute', ['class' => __CLASS__]);
                return;
            }
            if (!empty($params)) {
                $sql = $wpdb->prepare($sql, $params);
            }
            $result = $wpdb->query($sql);
            if ($result === false) {
                $this->logger->log('error', "Execute failed: {$wpdb->last_error}", ['sql' => $sql]);
            }
        }

        /**
         * Check if a DB table has a given column.
         */
        private function translation_table_has_column(string $table, string $column): bool {
            global $wpdb;
            // $table is already prefixed; rely on prepare for the LIKE pattern only
            $col = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$table} LIKE %s", $column));
            return !empty($col);
        }


        /**
         * Supprime une traduction de la base.
         *
         * @param int $translation_id L’ID de la traduction à supprimer.
         * @return bool True si supprimé, false en cas d’erreur.
         */
        public function delete_translation(int $translation_id): bool {
            global $wpdb;
            // MODIF: Gate sur tables_ok (Phase 4)
            if (!get_option('tables_ok', false)) {
                $this->logger->log('critical', 'Tables not OK, skipping delete_translation', ['class' => __CLASS__]);
                return false;
            }
            $table = $wpdb->prefix . 'yuz_tra_translations';
            $this->logger->log('info', "Tentative de suppression de la traduction #{$translation_id}", ['class'=>__CLASS__]);
            $attempts = 3;
            $deleted = null;
            while ($attempts > 0) {
                $deleted = $wpdb->delete(
                    $table,
                    ['id' => $translation_id],
                    ['%d']
                );
                if ($deleted !== null) {
                    break;
                }
                $attempts--;
                $this->logger->log(
                    'warning',
                    "Nouvelle tentative pour delete_translation({$translation_id}), restants: {$attempts}",
                    ['class'=>__CLASS__]
                );
                usleep(100000);
            }
            if ($deleted === false) {
                $this->logger->log(
                    'error',
                    "Échec de suppression de la traduction #{$translation_id} : {$wpdb->last_error}",
                    ['class'=>__CLASS__]
                );
                return false;
            }
            $this->logger->log(
                'success',
                "Traduction #{$translation_id} supprimée avec succès",
                ['class'=>__CLASS__]
            );
            return true;
        }
    }
    // Initialize the class
    YUZ_DB::init();
}

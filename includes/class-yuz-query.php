<?php
/**
 * Query helper for front-end translation lookups.
 *
 * Centralises access to yuz_tra_* tables and caches the most frequent reads.
 *
 * @package YUZ_Translation
 */

defined('ABSPATH') || exit;

if (!class_exists('YUZ_Query')) {
    class YUZ_Query {
        /** @var \wpdb */
        private $wpdb;

        /** @var YUZ_DB */
        private $db;

        /** @var array<string,array|null> */
        private array $language_cache = [];

        /**
         * Constructor.
         */
        public function __construct(?YUZ_DB $db = null) {
            global $wpdb;
            $this->wpdb = $wpdb;
            $this->db   = $db ?: (class_exists('YUZ_Services') ? YUZ_Services::db() : new YUZ_DB());
        }

        /**
         * Return a language row by code (cached).
         *
         * @param string $language_code e.g. en_US, en-us, en
         * @return array|null
         */
        public function get_language(string $language_code): ?array {
            $normalized = $this->normalize_language_code($language_code);
            if ($normalized === '') {
                return null;
            }

            if (isset($this->language_cache[$normalized])) {
                return $this->language_cache[$normalized];
            }

            $cache_key = 'yuz_lang_' . strtolower($normalized);
            $cached    = $this->cache_get($cache_key, 'yuz_tra_languages', 'language');
            if (is_array($cached)) {
                return $this->language_cache[$normalized] = $cached;
            }

            $table = $this->wpdb->prefix . 'yuz_tra_languages';
            $row   = $this->wpdb->get_row(
                $this->wpdb->prepare(
                    "SELECT * FROM {$table} WHERE language_code = %s LIMIT 1",
                    $normalized
                ),
                ARRAY_A
            );

            if (!$row && strlen($normalized) === 5) {
                // Try fallback without region (en -> en_US style)
                $fallback = substr($normalized, 0, 2);
                $row      = $this->wpdb->get_row(
                    $this->wpdb->prepare(
                        "SELECT * FROM {$table} WHERE language_code LIKE %s LIMIT 1",
                        $fallback . '\_%'
                    ),
                    ARRAY_A
                );
            }

            if ($row) {
                $row['id']             = isset($row['id']) ? (int) $row['id'] : 0;
                $row['language_code']  = $row['language_code'] ?? $normalized;
                $row['locale']         = $row['language_code'];
                $this->cache_set($cache_key, $row, 'yuz_tra_languages', HOUR_IN_SECONDS, 'language');
                return $this->language_cache[$normalized] = $row;
            }

            $this->language_cache[$normalized] = null;
            return null;
        }

        /**
         * Statuses allowed for front rendering / preview.
         *
         * @param bool $include_drafts When true, include draft-level entries (used for block maps).
         */
        private function allowed_statuses(bool $include_drafts = false): array
        {
            $base = $include_drafts
                ? [4, 3, 2, 1] // Published + review stack
                : [4];         // Public surface = Published only

            // Add canonical constants when available (new workflow + aliases for compat).
            foreach ([
                'YUZ_TRA_STATUS_PUBLISHED',
                'YUZ_TRA_STATUS_REVIEWED',
                'YUZ_TRA_STATUS_IN_REVIEW',
                'YUZ_TRA_STATUS_DRAFT',
                'YUZ_TRA_STATUS_REVIEW',   // legacy alias
                'YUZ_TRA_STATUS_MACHINE',  // legacy alias → in_review
                'YUZ_TRA_STATUS_QUEUED',   // legacy alias → reviewed
            ] as $const) {
                if (defined($const)) {
                    $base[] = (int) constant($const);
                }
            }

            $statuses = array_values(array_unique(array_filter(array_map('intval', $base), static function ($n) {
                return $n > 0;
            })));

            // Fallback to the legacy set if something went wrong.
            if (empty($statuses)) {
                $statuses = $include_drafts ? [4, 3, 2, 1] : [4];
            }

            sort($statuses);
            return $statuses;
        }

        /**
         * Fetch the latest translation for a post/context combo.
         *
         * @param int    $post_id
         * @param string $language_code
         * @param string $context
         * @return array|null
         */
        public function get_post_translation(int $post_id, string $language_code, string $context = 'content'): ?array {
            if ($post_id <= 0) {
                return null;
            }
            $lang_row = $this->get_language($language_code);
            if (!$lang_row || empty($lang_row['id'])) {
                return null;
            }
            $target_lang_id = (int) $lang_row['id'];
            // _v2 avoids stale cache entries built with the old status filter (status IN (1,2)).
            $cache_key = 'post_' . $post_id . '_' . strtolower($context) . '_' . $target_lang_id . '_v2';
            $cached    = $this->cache_get($cache_key, 'yuz_tra_front_translations', 'post_translation');
            if ($cached !== false) {
                return $cached;
            }

            $table = $this->wpdb->prefix . 'yuz_tra_translations';
            $statuses = $this->allowed_statuses(false);
            $placeholders = implode(',', array_fill(0, count($statuses), '%d'));

            $row   = $this->wpdb->get_row(
                $this->wpdb->prepare(
                    "
                        SELECT translated_text, translated_slug, status
                        FROM {$table}
                        WHERE post_id = %d
                          AND target_lang_id = %d
                          AND context = %s
                          AND status IN ({$placeholders})
                        ORDER BY updated_at DESC
                        LIMIT 1
                    ",
                    ...array_merge([$post_id, $target_lang_id, $context], $statuses)
                ),
                ARRAY_A
            );

            if ($row) {
                if (class_exists('YUZ_Logger')) {
                    try {
                        (new YUZ_Logger())->log('debug', 'YUZ_Query hit', [
                            'post_id'    => $post_id,
                            'context'    => $context,
                            'lang_id'    => $target_lang_id,
                            'lang_code'  => $language_code,
                            'status'     => $row['status'] ?? null,
                            'len'        => isset($row['translated_text']) ? strlen((string) $row['translated_text']) : 0,
                            'preview'    => isset($row['translated_text']) ? substr((string) $row['translated_text'], 0, 80) : '',
                        ]);
                    } catch (\Throwable $e) {
                        // ignore logging errors
                    }
                }
                $this->cache_set($cache_key, $row, 'yuz_tra_front_translations', MINUTE_IN_SECONDS * 10, 'post_translation');
                return $row;
            }

            if (class_exists('YUZ_Logger')) {
                try {
                    (new YUZ_Logger())->log('debug', 'YUZ_Query miss', [
                        'post_id'   => $post_id,
                        'context'   => $context,
                        'lang_id'   => $target_lang_id,
                        'lang_code' => $language_code,
                    ]);
                } catch (\Throwable $e) {
                    // ignore
                }
            }
            $this->cache_set($cache_key, null, 'yuz_tra_front_translations', MINUTE_IN_SECONDS * 5, 'post_translation');
            return null;
        }

        /**
         * Return all translations for a post/context keyed by block_id.
         *
         * @param int    $post_id
         * @param string $language_code
         * @param string $context
         * @return array<string,array>
         */
        public function get_post_translation_map(int $post_id, string $language_code, string $context = 'content'): array {
            $lang_row = $this->get_language($language_code);
            if (!$lang_row || empty($lang_row['id'])) {
                return [];
            }
            $target_lang_id = (int) $lang_row['id'];

            $table = $this->wpdb->prefix . 'yuz_tra_translations';
            $like  = str_replace(['_', '%'], ['\\_', '\\%'], $table);
            if (!$this->wpdb->get_var($this->wpdb->prepare('SHOW TABLES LIKE %s', $like))) {
                return [];
            }

            $statuses     = $this->allowed_statuses(true);
            $placeholders = implode(',', array_fill(0, count($statuses), '%d'));

            $rows = $this->wpdb->get_results(
                $this->wpdb->prepare(
                    "
                        SELECT id, block_id, original_text, translated_text, status
                        FROM {$table}
                        WHERE post_id = %d
                          AND target_lang_id = %d
                          AND context = %s
                          AND translated_text IS NOT NULL
                          AND translated_text <> ''
                          AND status IN ({$placeholders})
                    ",
                    ...array_merge([$post_id, $target_lang_id, $context], $statuses)
                ),
                ARRAY_A
            );

            if (!is_array($rows) || empty($rows)) {
                return [];
            }

            $map = [];
            foreach ($rows as $row) {
                if (!isset($row['translated_text']) || trim((string)$row['translated_text']) === '') {
                    continue; // ignore empty translations to avoid blank outputs
                }
                $block_id = isset($row['block_id']) ? (string) $row['block_id'] : '';
                if ($block_id === '') {
                    $block_id = sha1((string) ($row['original_text'] ?? '') . '|' . $row['id']);
                }
                $map[$block_id] = $row;
            }

            if (class_exists('YUZ_Logger')) {
                try {
                    (new YUZ_Logger())->log('debug', 'YUZ_Query map', [
                        'post_id'  => $post_id,
                        'context'  => $context,
                        'lang'     => $language_code,
                        'entries'  => count($map),
                        'keys'     => array_slice(array_keys($map), 0, 5),
                    ]);
                } catch (\Throwable $e) {
                    // swallow logging failure
                }
            }

            return $map;
        }

        /**
         * Return a translated slug if available for the given post.
         *
         * @param int    $post_id
         * @param string $language_code
         * @return string|null
         */
        public function get_post_slug(int $post_id, string $language_code): ?string {
            $translation = $this->get_post_translation($post_id, $language_code, 'slug');
            if ($translation && !empty($translation['translated_slug'])) {
                return $translation['translated_slug'];
            }
            return null;
        }

        /**
         * Normalize language codes to xx_YY format.
         */
        private function normalize_language_code(string $language_code): string {
            if (function_exists('yuz_normalize_language_code')) {
                return yuz_normalize_language_code($language_code);
            }

            $language_code = trim((string) $language_code);
            if ($language_code === '') {
                return '';
            }

            $language_code = str_replace('-', '_', $language_code);
            if (preg_match('/^[a-z]{2}$/i', $language_code)) {
                $language_code = strtolower($language_code) . '_' . strtoupper($language_code);
            }
            if (preg_match('/^[a-z]{2}_[a-z]{2}$/i', $language_code)) {
                $language_code = strtolower(substr($language_code, 0, 2)) . '_' . strtoupper(substr($language_code, 3, 2));
            }

            return $language_code;
        }

        private function cache_enabled(string $operation): bool {
            $enabled = true;
            if (function_exists('yuz_settings_cache_heavy_request') && yuz_settings_cache_heavy_request()) {
                $enabled = false;
            } elseif (defined('YUZ_TRA_DISABLE_CACHE') && YUZ_TRA_DISABLE_CACHE) {
                $enabled = false;
            } elseif (defined('YUZ_TRA_DISABLE_FRONT_CACHE') && YUZ_TRA_DISABLE_FRONT_CACHE && !is_admin()) {
                $enabled = false;
            }
            return (bool) apply_filters('yuz/query/enable_cache', $enabled, $operation);
        }

        private function cache_get(string $key, string $group, string $operation) {
            if (!$this->cache_enabled($operation)) {
                return false;
            }
            return wp_cache_get($key, $group);
        }

        private function cache_set(string $key, $value, string $group, int $ttl, string $operation): bool {
            if (!$this->cache_enabled($operation)) {
                return false;
            }
            $ttl = (int) apply_filters('yuz/query/cache_ttl', $ttl, $key, $group, $operation, $value);
            if ($ttl <= 0) {
                return false;
            }
            return wp_cache_set($key, $value, $group, $ttl);
        }
    }
}

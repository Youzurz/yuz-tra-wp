<?php
/**
 * WP-CLI helpers for YUZ-TRA.
 */

defined('ABSPATH') || exit;

if (defined('YUZ_TRA_INCLUDES') && !class_exists('YUZ_Context')) {
    $yuz_context_helper = trailingslashit(YUZ_TRA_INCLUDES) . 'class-yuz-context.php';
    if (is_readable($yuz_context_helper)) {
        require_once $yuz_context_helper;
    }
}

if (!class_exists('YUZ_CLI_Commands')) {
    class YUZ_CLI_Commands {
        public static function init(): void {
            if (!defined('WP_CLI') || !WP_CLI) {
                return;
            }

            \WP_CLI::add_command('yuz translations:sync-structure', [__CLASS__, 'sync_structure']);
            \WP_CLI::add_command('yuz translations:audit', [__CLASS__, 'audit_translations']);
            \WP_CLI::add_command('yuz translations:migrate-root-html', [__CLASS__, 'migrate_root_html']);
            \WP_CLI::add_command('yuz diag:lookup', [__CLASS__, 'diagnose_lookup']);
        }

        public static function sync_structure(array $args, array $assoc_args): void {
            global $wpdb;

            $table      = $wpdb->prefix . 'yuz_tra_translations';
            $lang_table = $wpdb->prefix . 'yuz_tra_languages';

            if (!$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', str_replace(['_', '%'], ['\\_', '\\%'], $table)))) {
                \WP_CLI::error('Translations table not found.');
                return;
            }

            $process_all = isset($assoc_args['all']) && $assoc_args['all'];
            $post_id     = isset($assoc_args['post']) ? (int) $assoc_args['post'] : 0;

            if (!$process_all && $post_id <= 0) {
                \WP_CLI::error('Provide --post=<id> or pass --all.');
                return;
            }

            $force = isset($assoc_args['force']) && $assoc_args['force'];

            $context_list = isset($assoc_args['contexts']) ? array_filter(array_map('trim', explode(',', $assoc_args['contexts']))) : [];
            if (!$context_list) {
                $context_list = ['content', 'title', 'excerpt', 'slug'];
            }

            $lang_filter = isset($assoc_args['lang']) ? array_filter(array_map('trim', explode(',', $assoc_args['lang']))) : [];
            $lang_filter = array_map(static function ($code) {
                return strtoupper(str_replace('-', '_', $code));
            }, $lang_filter);

            $languages_raw = $wpdb->get_results("SELECT id, language_code, is_source, is_translatable FROM {$lang_table}", ARRAY_A);
            if (!$languages_raw) {
                \WP_CLI::error('No languages registered.');
                return;
            }

            $source_lang    = null;
            $source_lang_id = 0;
            $languages      = [];

            foreach ($languages_raw as $row) {
                $code = strtoupper((string) $row['language_code']);
                if (!empty($row['is_source'])) {
                    $source_lang    = $code;
                    $source_lang_id = (int) $row['id'];
                }
                if (!empty($lang_filter) && !in_array($code, $lang_filter, true)) {
                    continue;
                }
                if (!empty($row['is_translatable'])) {
                    $languages[$code] = [
                        'id'   => (int) $row['id'],
                        'code' => $code,
                    ];
                }
            }

            if (!$source_lang) {
                $source_lang = 'fr_FR';
            }
            if (isset($languages[$source_lang])) {
                unset($languages[$source_lang]);
            }
            if (!$languages) {
                \WP_CLI::error('No target languages matched the filter.');
                return;
            }

            $post_ids = [];
            if ($process_all) {
                $post_ids = $wpdb->get_col("SELECT DISTINCT post_id FROM {$table} WHERE post_id > 0");
            } else {
                $post_ids = [$post_id];
            }

            $post_ids = array_unique(array_filter(array_map('intval', $post_ids)));
            if (!$post_ids) {
                \WP_CLI::error('No posts found.');
                return;
            }

            $total = 0;
            foreach ($post_ids as $pid) {
                $post = get_post($pid);
                if (!$post) {
                    \WP_CLI::warning("Post {$pid} not found, skipping.");
                    continue;
                }

                $payloads = [
                    'content' => $post->post_content,
                    'title'   => $post->post_title,
                    'excerpt' => $post->post_excerpt,
                    'slug'    => $post->post_name,
                ];

                foreach ($context_list as $context) {
                    if (!array_key_exists($context, $payloads)) {
                        \WP_CLI::warning("Context {$context} unsupported, skipping.");
                        continue;
                    }

                    $source_value = (string) $payloads[$context];
                    if ($source_value === '') {
                        continue;
                    }

                    foreach ($languages as $language) {
                        $lang_code = $language['code'];
                        $lang_id   = $language['id'];

                        $row = $wpdb->get_row(
                            $wpdb->prepare(
                                "SELECT id, translated_text FROM {$table} WHERE post_id = %d AND context = %s AND language_code = %s LIMIT 1",
                                $pid,
                                $context,
                                $lang_code
                            ),
                            ARRAY_A
                        );

                        if ($row && !$force && (string) $row['translated_text'] !== '') {
                            continue;
                        }

                        $now  = current_time('mysql');
                        $data = [
                            'post_id'         => $pid,
                            'context'         => $context,
                            'language_code'   => $lang_code,
                            'translated_text' => $source_value,
                            'original_text'   => $source_value,
                            'status'          => 0,
                            'updated_at'      => $now,
                        ];

                        if ($source_lang_id) {
                            $data['source_lang_id'] = $source_lang_id;
                        }
                        if ($lang_id) {
                            $data['target_lang_id'] = $lang_id;
                        }
                        if ($context === 'slug') {
                            $data['translated_slug'] = sanitize_title($source_value);
                        }

                        if ($row) {
                            $wpdb->update($table, $data, ['id' => (int) $row['id']]);
                            \WP_CLI::log(sprintf('Updated #%d %s (%s)', $pid, $lang_code, $context));
                        } else {
                            $data['created_at'] = $now;
                            $wpdb->insert($table, $data);
                            \WP_CLI::log(sprintf('Created #%d %s (%s)', $pid, $lang_code, $context));
                        }
                        $total++;
                    }
                }
            }

            \WP_CLI::success(sprintf('Structure sync completed (%d rows touched).', $total));
        }

        public static function audit_translations(array $args, array $assoc_args): void {
            global $wpdb;

            $table = $wpdb->prefix . 'yuz_tra_translations';
            if (!$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', str_replace(['_', '%'], ['\\_', '\\%'], $table)))) {
                \WP_CLI::error('Translations table not found.');
                return;
            }

            $limit       = isset($assoc_args['limit']) ? max(1, (int) $assoc_args['limit']) : 20;
            $post_filter = isset($assoc_args['post']) ? (int) $assoc_args['post'] : null;

            $sql = "SELECT post_id, language_code, context, translated_text FROM {$table} WHERE status IN (1,2) AND translated_text <> ''";
            if ($post_filter) {
                $sql .= $wpdb->prepare(" AND post_id = %d", $post_filter);
            }

            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Query uses a fixed plugin table; optional post filter is prepared above.
            $rows = $wpdb->get_results($sql, ARRAY_A);
            if (!$rows) {
                \WP_CLI::success('No published translations found.');
                return;
            }

            $issues = [];
            foreach ($rows as $row) {
                $text = (string) $row['translated_text'];
                $has_markup = strpos($text, '<') !== false && strpos($text, '>') !== false;
                $plain_len  = strlen(trim(wp_strip_all_tags($text)));
                if (!$has_markup || $plain_len === 0) {
                    $issues[] = [
                        'post_id'    => (int) $row['post_id'],
                        'language'   => $row['language_code'],
                        'context'    => $row['context'],
                        'has_markup' => $has_markup ? 'yes' : 'no',
                        'plain_len'  => $plain_len,
                    ];
                }
            }

            if (!$issues) {
                \WP_CLI::success('All translations contain markup.');
                return;
            }

            \WP_CLI::warning(sprintf('Found %d translations lacking markup.', count($issues)));
            foreach (array_slice($issues, 0, $limit) as $issue) {
                \WP_CLI::log(sprintf(
                    '#%d %s (%s) markup=%s plain_len=%d',
                    $issue['post_id'],
                    $issue['language'],
                    $issue['context'],
                    $issue['has_markup'],
                    $issue['plain_len']
                ));
            }
            if (count($issues) > $limit) {
                \WP_CLI::log(sprintf('... %d more not shown.', count($issues) - $limit));
            }
        }

        public static function migrate_root_html(array $args, array $assoc_args): void {
            global $wpdb;

            $table = $wpdb->prefix . 'yuz_tra_translations';
            $like  = str_replace(['_', '%'], ['\\_', '\\%'], $table);
            if (!$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $like))) {
                \WP_CLI::error('Translations table not found.');
                return;
            }

            $context = isset($assoc_args['context']) ? sanitize_text_field($assoc_args['context']) : 'content';
            if ($context === '') {
                $context = 'content';
            }

            $dry_run = !empty($assoc_args['dry-run']);

            $post_filter = isset($assoc_args['post']) ? (int) $assoc_args['post'] : 0;
            if ($post_filter > 0) {
                $post_ids = [$post_filter];
            } else {
                $post_ids = $wpdb->get_col($wpdb->prepare(
                    "SELECT DISTINCT post_id FROM {$table} WHERE post_id > 0 AND context = %s",
                    $context
                ));
            }

            $post_ids = array_values(array_unique(array_filter(array_map('intval', (array) $post_ids))));
            if (!$post_ids) {
                \WP_CLI::error('No posts matched the filter.');
                return;
            }

            $lang_filter = [];
            if (!empty($assoc_args['lang'])) {
                $lang_filter = array_map(static function ($code) {
                    return strtoupper(str_replace('-', '_', trim($code)));
                }, explode(',', $assoc_args['lang']));
                $lang_filter = array_values(array_filter($lang_filter));
            }

            $updated = 0;
            $skipped = 0;
            $missing_root = 0;
            $no_candidate = 0;

            foreach ($post_ids as $post_id) {
                $rows = $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT id, block_id, language_code, status, translated_text FROM {$table} WHERE post_id = %d AND context = %s",
                        $post_id,
                        $context
                    ),
                    ARRAY_A
                );

                if (!$rows) {
                    continue;
                }

                $grouped = [];
                foreach ($rows as $row) {
                    $code = isset($row['language_code']) ? strtoupper((string) $row['language_code']) : '';
                    if ($code === '') {
                        continue;
                    }
                    if ($lang_filter && !in_array($code, $lang_filter, true)) {
                        continue;
                    }
                    $grouped[$code][] = $row;
                }

                foreach ($grouped as $lang_code => $lang_rows) {
                    $root = null;
                    foreach ($lang_rows as $row) {
                        if ($row['block_id'] === null || $row['block_id'] === '') {
                            $root = $row;
                            break;
                        }
                    }

                    if (!$root) {
                        $missing_root++;
                        \WP_CLI::log(sprintf('Post %d %s: no root row found.', $post_id, $lang_code));
                        continue;
                    }

                    if (self::row_has_markup($root['translated_text'])) {
                        // Demote any other stale root rows even if we keep current one.
                        $demoted = $wpdb->query(
                            $wpdb->prepare(
                                "UPDATE {$table}
                                    SET status = 0, updated_at = updated_at
                                  WHERE post_id = %d
                                    AND context = %s
                                    AND language_code = %s
                                    AND (block_id IS NULL OR block_id = '')
                                    AND id <> %d",
                                $post_id,
                                $context,
                                $lang_code,
                                (int) $root['id']
                            )
                        );
                        if ($demoted) {
                            \WP_CLI::log(sprintf('Post %d %s: demoted %d stale root duplicate(s).', $post_id, $lang_code, $demoted));
                        }

                        $skipped++;
                        continue;
                    }

                    $candidate = null;
                    $candidate_len = 0;
                    foreach ($lang_rows as $row) {
                        if ($row['block_id'] === null || $row['block_id'] === '') {
                            continue;
                        }
                        if (!self::row_has_markup($row['translated_text'])) {
                            continue;
                        }

                        $len = strlen((string) $row['translated_text']);
                        if ($len > $candidate_len) {
                            $candidate = $row;
                            $candidate_len = $len;
                        }
                    }

                    if (!$candidate) {
                        $no_candidate++;
                        \WP_CLI::log(sprintf('Post %d %s: no HTML candidate found.', $post_id, $lang_code));
                        continue;
                    }

                    if ($dry_run) {
                        \WP_CLI::log(sprintf(
                            '[DRY-RUN] Post %d %s: would copy markup from block %s into root row #%d.',
                            $post_id,
                            $lang_code,
                            $candidate['block_id'],
                            $root['id']
                        ));
                        $updated++;
                        continue;
                    }

                    $new_text = self::normalize_newlines((string) $candidate['translated_text']);
                    $new_status = max((int) $root['status'], (int) $candidate['status'], 1);
                    $result = $wpdb->update(
                        $table,
                        [
                            'translated_text' => $new_text,
                            'status'          => $new_status,
                            'updated_at'      => current_time('mysql'),
                        ],
                        ['id' => (int) $root['id']],
                        ['%s', '%d', '%s'],
                        ['%d']
                    );

                    if ($result === false) {
                        \WP_CLI::warning(sprintf('Post %d %s: failed to update root row #%d.', $post_id, $lang_code, $root['id']));
                        continue;
                    }

                    \WP_CLI::log(sprintf(
                        'Post %d %s: root row #%d updated with markup from %s.',
                        $post_id,
                        $lang_code,
                        $root['id'],
                        $candidate['block_id']
                    ));
                    $updated++;

                    // Demote other root duplicates so audits stop flagging them and runtime ignores stale values.
                    $demoted = $wpdb->query(
                        $wpdb->prepare(
                            "UPDATE {$table}
                                SET status = 0, updated_at = updated_at
                              WHERE post_id = %d
                                AND context = %s
                                AND language_code = %s
                                AND (block_id IS NULL OR block_id = '')
                                AND id <> %d",
                            $post_id,
                            $context,
                            $lang_code,
                            (int) $root['id']
                        )
                    );

                    if ($demoted) {
                        \WP_CLI::log(sprintf('Post %d %s: demoted %d stale root duplicate(s).', $post_id, $lang_code, $demoted));
                    }
                }
            }

            $summary = sprintf(
                'Completed. Updated=%d, skipped=%d, missing_root=%d, no_candidate=%d%s',
                $updated,
                $skipped,
                $missing_root,
                $no_candidate,
                $dry_run ? ' (dry-run)' : ''
            );

            \WP_CLI::success($summary);
        }

        /**
         * Inspect translation rows for a specific post/context/lang trio.
         */
        public static function diagnose_lookup(array $args, array $assoc_args): void {
            global $wpdb;

            $post_id = isset($assoc_args['post']) ? (int) $assoc_args['post'] : 0;
            $lang    = isset($assoc_args['lang']) ? (string) $assoc_args['lang'] : '';
            $field_input = isset($assoc_args['field']) ? (string) $assoc_args['field'] : 'content';

            if ($post_id <= 0 || $lang === '') {
                \WP_CLI::error('Usage: wp yuz diag:lookup --post=<id> --lang=<locale> [--field=<key>]');
                return;
            }

            $lang_code = strtoupper(str_replace('-', '_', trim($lang)));
            $normalized_context = class_exists('YUZ_Context')
                ? YUZ_Context::normalize($field_input)
                : strtolower(trim($field_input));

            $query = (class_exists('YUZ_Services') && method_exists('YUZ_Services', 'translations'))
                ? YUZ_Services::translations()
                : new YUZ_Query();

            $language_row = $query->get_language($lang_code);
            if (!$language_row || empty($language_row['id'])) {
                \WP_CLI::error(sprintf('Language %s is not registered in yuz_tra_languages.', $lang_code));
                return;
            }

            $target_lang_id = (int) $language_row['id'];
            $translation    = $query->get_post_translation($post_id, $lang_code, $normalized_context);

            \WP_CLI::line(sprintf(
                'Inspecting post=%d | lang=%s (id=%d) | context=%s (input=%s)',
                $post_id,
                $lang_code,
                $target_lang_id,
                $normalized_context,
                $field_input
            ));

            if ($translation && isset($translation['translated_text'])) {
                $status  = isset($translation['status']) ? (int) $translation['status'] : null;
                $raw_text = wp_strip_all_tags((string) $translation['translated_text']);
                if (function_exists('mb_substr')) {
                    $preview = mb_substr($raw_text, 0, 140);
                    if (mb_strlen($raw_text) > 140) {
                        $preview .= '…';
                    }
                } else {
                    $preview = substr($raw_text, 0, 140);
                    if (strlen($raw_text) > 140) {
                        $preview .= '…';
                    }
                }

                \WP_CLI::success(sprintf(
                    'Published translation found (status=%s, length=%d)',
                    $status === null ? 'n/a' : $status,
                    strlen((string) $translation['translated_text'])
                ));
                if ($preview !== '') {
                    \WP_CLI::line('Preview: ' . $preview);
                }
                return;
            }

            \WP_CLI::warning('No published translation matched this lookup.');

            $table = $wpdb->prefix . 'yuz_tra_translations';
            $rows  = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT id, context, status, LENGTH(translated_text) AS len, updated_at
                     FROM {$table}
                     WHERE post_id = %d AND target_lang_id = %d
                     ORDER BY updated_at DESC
                     LIMIT 15",
                    $post_id,
                    $target_lang_id
                ),
                ARRAY_A
            );

            if (!$rows) {
                \WP_CLI::line('No rows found for this post/lang in yuz_tra_translations.');
                return;
            }

            $status_counts  = [];
            $context_matrix = [];

            foreach ($rows as $row) {
                $status = isset($row['status']) ? (int) $row['status'] : -1;
                $status_counts[$status] = ($status_counts[$status] ?? 0) + 1;
                $ctx = (string) $row['context'];
                $context_matrix[$ctx] = $context_matrix[$ctx] ?? [];
                $context_matrix[$ctx][] = sprintf(
                    '#%d(status=%d,len=%d,updated=%s)',
                    (int) $row['id'],
                    $status,
                    isset($row['len']) ? (int) $row['len'] : 0,
                    $row['updated_at'] ?? ''
                );
            }

            \WP_CLI::line('Status counts:');
            foreach ($status_counts as $code => $count) {
                \WP_CLI::line(sprintf('  status %d → %d row(s)', $code, $count));
            }

            \WP_CLI::line('Stored contexts:');
            foreach ($context_matrix as $ctx => $descriptors) {
                \WP_CLI::line(sprintf('  %s => %s', $ctx, implode(', ', array_slice($descriptors, 0, 5))));
            }
        }

        private static function row_has_markup(?string $text): bool {
            if ($text === null) {
                return false;
            }
            $text = trim($text);
            return $text !== '' && strpos($text, '<') !== false && strpos($text, '>') !== false;
        }

        private static function normalize_newlines(string $text): string {
            $normalized = preg_replace("/\r\n?/", "\n", $text);
            if ($normalized === null) {
                $normalized = '';
            }
            $normalized = preg_replace("/\n{3,}/", "\n\n", $normalized);
            return $normalized ?? '';
        }
    }
}

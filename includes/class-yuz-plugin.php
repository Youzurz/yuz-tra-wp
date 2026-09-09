<?php
/**
 * Primary bootstrap orchestrator for YUZ-TRA.
 *
 * @package YUZ_Translation
 */

defined('ABSPATH') || exit;

if (!class_exists('YUZ_Plugin')) {
    final class YUZ_Plugin {
        /** @var bool */
        private static $booted = false;

        /** @var string */
        private static $plugin_file = '';

        /**
         * Entry point from yuz-tra.php.
         */
        public static function boot(string $plugin_file): void {
            if (self::$booted) {
                return;
            }
            self::$booted     = true;
            self::$plugin_file = $plugin_file;

            self::define_constants($plugin_file);
            self::include_core_files();

            add_filter('redirect_canonical', '__return_false', 20);
            add_filter('wp_kses_allowed_html', [__CLASS__, 'extend_allowed_html'], 10, 2);

            add_action('plugins_loaded', [__CLASS__, 'load_hook_packs'], 5);
            add_action('plugins_loaded', [__CLASS__, 'init_modules'], 20);
            add_action('plugins_loaded', [__CLASS__, 'ensure_tables_if_needed'], 1);
            add_action('plugins_loaded', ['YUZ_String_Catalog', 'init'], 25);
            add_action('plugins_loaded', ['YUZ_Cron', 'init'], 26);

            add_action('init', [__CLASS__, 'guard_language_flags'], 5);
            add_action('init', ['YUZ_Core', 'init'], 30);
            add_action('init', [__CLASS__, 'sync_caps_from_option'], 20);

            if (defined('WP_CLI') && WP_CLI) {
                require_once YUZ_TRA_INCLUDES . 'cli/front-doctor.php';
                $cli_mirror = YUZ_TRA_INCLUDES . 'cli/class-yuz-cli-mirror.php';
                if (file_exists($cli_mirror)) {
                    require_once $cli_mirror;
                }

                // Inline CLI: `wp yuz verify` (rewrite/pivot/translations checks) via closure (no nested classes)
                \WP_CLI::add_command('yuz verify', function ($args, $assoc_args) {
                    global $wpdb;
                    $verbose = isset($assoc_args['verbose']);
                    \WP_CLI::log('=== YUZ Translation Diagnostic ===');
                    // 1) Rewrite rules
                    \WP_CLI::log("\n🌀 Vérification 1 : Rewrite / URL mapping");
                    $rules = get_option('rewrite_rules');
                    if (!is_array($rules)) { $rules = []; }
                    $lang_table = $wpdb->prefix . 'yuz_tra_languages';
                    $trans_table = $wpdb->prefix . 'yuz_tra_translations';
                    // Consider only translatable languages when checking rewrite coverage
                    $langs = (array) $wpdb->get_results("SELECT language_code, slug, is_default, is_source, is_translatable FROM {$lang_table} WHERE is_translatable = 1", ARRAY_A);
                    $slugs = array_values(array_unique(array_filter(array_map(function ($row) { return (string) ($row['slug'] ?? ''); }, $langs))));
                    $ok = 0; $total = count($slugs);
                    foreach ($slugs as $slug) {
                        $found = false;
                        foreach ($rules as $regex => $query) {
                            $re = '#^\^?' . preg_quote($slug, '#') . '(/|\$)#i';
                            if (preg_match($re, ltrim((string)$regex, '^'))) { $found = true; break; }
                        }
                        if ($found) { $ok++; }
                        if ($verbose) { \WP_CLI::log(sprintf(' - slug "%s": %s', $slug, $found ? 'OK' : 'absent')); }
                    }
                    if ($total === 0) { \WP_CLI::warning('Aucune langue trouvée (table vide ?).'); }
                    elseif ($ok === $total) { \WP_CLI::success(sprintf('Rewrite OK (%d/%d slugs détectés)', $ok, $total)); }
                    else { \WP_CLI::warning(sprintf('Rewrite partiel (%d/%d). Exécutez: wp rewrite flush --hard', $ok, $total)); }
                    // 2) Traductions
                    \WP_CLI::log("\n📘 Vérification 2 : Traductions en base");
                    $rows = (array) $wpdb->get_results("SELECT language_code, COUNT(*) total, SUM(CASE WHEN translated_text IS NULL OR translated_text='' THEN 1 ELSE 0 END) empty, SUM(CASE WHEN status IN (1,2) THEN 1 ELSE 0 END) published FROM {$trans_table} GROUP BY language_code", ARRAY_A);
                    if (empty($rows)) { \WP_CLI::warning('Aucune traduction stockée.'); }
                    else {
                        foreach ($rows as $r) {
                            $t = (int)($r['total'] ?? 0); $p = (int)($r['published'] ?? 0);
                            $ratio = $t ? round(($p/$t)*100,1) : 0.0;
                            \WP_CLI::log(sprintf(' - %s: %d/%d publiées (%s%%), vides: %d', (string)$r['language_code'], $p, $t, number_format_i18n($ratio,1), (int)($r['empty'] ?? 0)));
                        }
                    }
                    // 3) Pivot
                    \WP_CLI::log("\n🧭 Vérification 4 : Pivot linguistique");
                    $src = null; $def = null;
                    foreach ($langs as $l) { if (!empty($l['is_source'])) $src = $l['language_code']; if (!empty($l['is_default'])) $def = $l['language_code']; }
                    if (!$src || !$def) { \WP_CLI::warning('Langue source ou langue par défaut absente.'); }
                    else {
                        \WP_CLI::log(sprintf(' - source = %s | default = %s', $src, $def));
                        \WP_CLI::success($src === $def ? 'Pivot aligné (source=default).' : 'Pivot dissocié: OK (source≠default).');
                    }
                    if ($src) {
                        $src_id = (int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$lang_table} WHERE language_code = %s", $src));
                        $mismatch = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$trans_table} WHERE source_lang_id <> %d", $src_id));
                        if ($mismatch > 0) { \WP_CLI::warning(sprintf('Incohérences pivot (source_lang_id != %s): %d lignes', $src, $mismatch)); }
                        else { \WP_CLI::success('Pivot appliqué dans la table de traductions.'); }
                    }
                    if ($verbose) {
                        \WP_CLI::log("\nDétails langues:");
                        foreach ($langs as $l) { \WP_CLI::log(sprintf(' - %s (slug=%s) default=%d source=%d translatable=%d', (string)$l['language_code'], (string)$l['slug'], (int)$l['is_default'], (int)$l['is_source'], (int)$l['is_translatable'])); }
                    }
                    \WP_CLI::success("\n✅ Diagnostic terminé.");
                });

                \WP_CLI::add_command('yuz verify-structure', function ($args, $assoc_args) {
    global $wpdb;
                    $home = trailingslashit(home_url('/'));
                    $extract_text = function (string $html): string {
                        $html = preg_replace('#<script[\s\S]*?</script>#i', '', $html ?? '') ?? '';
                        $html = preg_replace('#<style[\s\S]*?</style>#i', '', $html) ?? '';
                        $text = wp_strip_all_tags($html, true);
                        $text = preg_replace('/\s+/u', ' ', $text ?? '') ?? '';
                        return trim($text);
                    };
                    $hash_text = function (string $html) use ($extract_text): string {
                        $t = $extract_text($html);
                        $t = mb_substr($t, 0, 20000);
                        return md5($t);
                    };
                    $hash_tags = function (string $html): string {
                        preg_match_all('#<([a-zA-Z0-9\-]+)(?:\s|>)#', $html ?? '', $m);
                        $tags = array_map('strtolower', $m[1] ?? []);
                        $whitelist = ['html','head','body','header','footer','nav','main','section','article','aside','h1','h2','h3','h4','h5','h6','p','ul','ol','li','figure','figcaption','div'];
                        $counts = [];
                        foreach ($tags as $tg) { if (in_array($tg, $whitelist, true)) { $counts[$tg] = ($counts[$tg] ?? 0) + 1; } }
                        ksort($counts);
                        return md5(json_encode($counts));
                    };

                    $base_res = wp_remote_get($home, ['timeout' => 10]);
                    $base_body = (string) wp_remote_retrieve_body($base_res);
                    $txt0 = $hash_text($base_body);
                    $tag0 = $hash_tags($base_body);

                    $table = $wpdb->prefix . 'yuz_tra_languages';
                    $rows = (array) $wpdb->get_results("SELECT language_code, slug, is_default FROM {$table} WHERE is_translatable = 1 ORDER BY language_weight, id", ARRAY_A);
                    if (empty($rows)) { \WP_CLI::warning('No languages found'); return; }

                    $converter = class_exists('YUZ_Url_Converter') ? new \YUZ_Url_Converter(null) : null;

                    \WP_CLI::log("Language | HTTP | Structure | Difference");
                    \WP_CLI::log(str_repeat('-', 48));
                    foreach ($rows as $r) {
                        $code = (string) ($r['language_code'] ?? '');
                        $slug = (string) ($r['slug'] ?? '');
                        $is_def = !empty($r['is_default']);
                        $target = $converter && method_exists($converter, 'get_url_for_language')
                            ? $converter->get_url_for_language($code, $home)
                            : ($slug ? trailingslashit($home . $slug) : $home);

                        $res  = wp_remote_get($target, ['timeout' => 10]);
                        $http = (int) wp_remote_retrieve_response_code($res);
                        $body = (string) wp_remote_retrieve_body($res);
                        $txt  = $hash_text($body);
                        $tag  = $hash_tags($body);

                        if ($is_def) {
                            \WP_CLI::log(sprintf('%-8s | %-4d | %-10s | %s', $code, $http, '—', 'Baseline'));
                            continue;
                        }

                        $diff = ($txt !== $txt0) || ($tag !== $tag0);
                        $structure = $diff ? '✅ Différent' : '❌ Identique';
                        $explain   = $diff ? 'Traduction appliquée' : 'Buffer inactif';
                        \WP_CLI::log(sprintf('%-8s | %-4d | %-10s | %s', $code, $http, $structure, $explain));
                    }
                });

                // Ensure YUZ rewrite rules get registered then flush and report coverage
                \WP_CLI::add_command('yuz translations:audit', function ($args, $assoc_args) {
                    global $wpdb;
                    $table = $wpdb->prefix . 'yuz_tra_translations';
                    $like  = str_replace(['_', '%'], ['\\_', '\\%'], $table);
                    $exists = (bool) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $like));
                    if (!$exists) {
                        \WP_CLI::error('Translations table not found.');
                    }

                    $limit = isset($assoc_args['limit']) ? max(1, (int) $assoc_args['limit']) : 50;
                    $context_filter = isset($assoc_args['context']) ? sanitize_text_field((string) $assoc_args['context']) : '';

                    \WP_CLI::log('=== YUZ Translation Audit ===');

                    $labels = [
                        0 => 'draft',
                        1 => 'published',
                        2 => 'pending',
                    ];

                    $status_rows = (array) $wpdb->get_results("SELECT status, COUNT(*) total FROM {$table} GROUP BY status ORDER BY status", ARRAY_A);
                    if (empty($status_rows)) {
                        \WP_CLI::warning('No rows in translations table.');
                    } else {
                        \WP_CLI::log('Status distribution:');
                        foreach ($status_rows as $row) {
                            $status = (int) ($row['status'] ?? 0);
                            $label  = $labels[$status] ?? ('status ' . $status);
                            \WP_CLI::log(sprintf(' - %s: %d', $label, (int) ($row['total'] ?? 0)));
                        }
                    }

                    $context_rows = (array) $wpdb->get_results("SELECT context, COUNT(*) total FROM {$table} GROUP BY context ORDER BY total DESC", ARRAY_A);
                    $known_contexts = ['content','title','excerpt','menu','slug','seo_title','seo_description','block','widget','json','meta','custom'];
                    $unexpected = [];
                    foreach ($context_rows as $row) {
                        $ctx = (string) ($row['context'] ?? '');
                        if ($ctx === '') {
                            continue;
                        }
                        if (!in_array($ctx, $known_contexts, true)) {
                            $unexpected[] = $row;
                        }
                    }
                    if ($unexpected) {
                        \WP_CLI::warning('Unexpected contexts detected:');
                        foreach (array_slice($unexpected, 0, 10) as $row) {
                            \WP_CLI::log(sprintf(' - %s (%d rows)', (string) $row['context'], (int) $row['total']));
                        }
                        if (count($unexpected) > 10) {
                            \WP_CLI::log(sprintf('   ... %d more', count($unexpected) - 10));
                        }
                    } elseif (!empty($context_rows)) {
                        \WP_CLI::success('All stored contexts match expected values.');
                    }

                    $limit_sql = max(1, $limit);
                    if ($context_filter !== '') {
                        $pending_rows = (array) $wpdb->get_results(
                            $wpdb->prepare(
                                "SELECT post_id, context, language_code, status, updated_at FROM {$table} WHERE status NOT IN (1,2) AND context = %s ORDER BY updated_at DESC LIMIT %d",
                                $context_filter,
                                $limit_sql
                            ),
                            ARRAY_A
                        );
                        $empty_rows = (array) $wpdb->get_results(
                            $wpdb->prepare(
                                "SELECT post_id, context, language_code, status, updated_at FROM {$table} WHERE (translated_text IS NULL OR translated_text = '') AND context = %s ORDER BY updated_at DESC LIMIT %d",
                                $context_filter,
                                $limit_sql
                            ),
                            ARRAY_A
                        );
                    } else {
                        $pending_rows = (array) $wpdb->get_results(
                            $wpdb->prepare(
                                "SELECT post_id, context, language_code, status, updated_at FROM {$table} WHERE status NOT IN (1,2) ORDER BY updated_at DESC LIMIT %d",
                                $limit_sql
                            ),
                            ARRAY_A
                        );
                        $empty_rows = (array) $wpdb->get_results(
                            $wpdb->prepare(
                                "SELECT post_id, context, language_code, status, updated_at FROM {$table} WHERE translated_text IS NULL OR translated_text = '' ORDER BY updated_at DESC LIMIT %d",
                                $limit_sql
                            ),
                            ARRAY_A
                        );
                    }

                    if (!empty($pending_rows)) {
                        \WP_CLI::warning(sprintf('Translations blocked in non-published status (showing max %d):', count($pending_rows)));
                        foreach ($pending_rows as $row) {
                            \WP_CLI::log(sprintf(
                                ' - post %d | %s | %s | status=%d | updated=%s',
                                (int) ($row['post_id'] ?? 0),
                                (string) ($row['context'] ?? ''),
                                (string) ($row['language_code'] ?? ''),
                                (int) ($row['status'] ?? 0),
                                (string) ($row['updated_at'] ?? '')
                            ));
                        }
                    } else {
                        \WP_CLI::success('No pending/draft translations detected for the requested scope.');
                    }

                    if (!empty($empty_rows)) {
                        \WP_CLI::warning(sprintf('Translations stored without text (showing max %d):', count($empty_rows)));
                        foreach ($empty_rows as $row) {
                            \WP_CLI::log(sprintf(
                                ' - post %d | %s | %s | status=%d | updated=%s',
                                (int) ($row['post_id'] ?? 0),
                                (string) ($row['context'] ?? ''),
                                (string) ($row['language_code'] ?? ''),
                                (int) ($row['status'] ?? 0),
                                (string) ($row['updated_at'] ?? '')
                            ));
                        }
                    } else {
                        \WP_CLI::success('No empty translations detected for the requested scope.');
                    }

                    \WP_CLI::success('Audit complete.');
                });

                \WP_CLI::add_command('yuz rewrite:refresh', function ($args, $assoc_args) {
                    $verbose = isset($assoc_args['verbose']);
                    if (class_exists('YUZ_Rewrite') && method_exists('YUZ_Rewrite','init')) {
                        YUZ_Rewrite::init();
                    }
                    do_action('init');
                    flush_rewrite_rules();
                    \WP_CLI::success('Rewrite rules flushed (after YUZ init).');

                    global $wpdb, $wp_rewrite;
                    $rules = $wp_rewrite->wp_rewrite_rules();
                    if (!is_array($rules)) { $rules = (array) get_option('rewrite_rules', []); }
                    $rule_count = is_array($rules) ? count($rules) : 0;
                    $yuz_rule_count = 0;
                    foreach ((array)$rules as $regex => $query) {
                        if (stripos((string)$query, 'yuz_path=') !== false || stripos((string)$query, 'index.php?lang=') === 0 || stripos((string)$query, '&lang=') !== false) {
                            $yuz_rule_count++;
                        }
                    }
                    if ($verbose) {
                        \WP_CLI::log(sprintf('Rules total=%d, YUZ-related=%d', $rule_count, $yuz_rule_count));
                    }

                    $lang_table = $wpdb->prefix . 'yuz_tra_languages';
                    $langs = (array) $wpdb->get_results("SELECT language_code, slug, browser_slug, is_translatable FROM {$lang_table} WHERE is_translatable = 1 ORDER BY language_weight, id", ARRAY_A);
                    if (empty($langs)) { \WP_CLI::warning('No translatable languages found.'); return; }

                    $log_lines = [];
                    $log_lines[] = sprintf("Rules total=%d, YUZ-related=%d", $rule_count, $yuz_rule_count);

                    $found = 0; $total = 0;
                    foreach ($langs as $row) {
                        $code = (string) ($row['language_code'] ?? '');
                        $slug = (string) ($row['slug'] ?? '');
                        $browser = (string) ($row['browser_slug'] ?? '');
                        if ($code === '') { continue; }
                        if ($slug === '') { $slug = strtolower(str_replace('_','-',$code)); }

                        // Build aliases like the rewrite registrar does
                        $short   = strtolower(substr(str_replace('_','-', $code), 0, 2));
                        $double  = $short !== '' ? ($short.'-'.$short) : '';
                        $doubleU = $short !== '' ? ($short.'_'.$short) : '';
                        $aliases = array_values(array_unique(array_filter([
                            $slug,
                            str_replace('-', '_', $slug),
                            $browser !== '' ? $browser : null,
                            $short   !== '' ? $short   : null,
                            $double  !== '' ? $double  : null,
                            $doubleU !== '' ? $doubleU : null,
                        ])));

                        $total++;
                        $ok = false;
                        $match_line = null;
                        foreach ($rules as $regex => $query) {
                            $hay = ltrim((string)$regex, '^');
                            foreach ($aliases as $a) {
                                $re = '#^\^?' . preg_quote($a, '#') . '(?:/|\$)#i';
                                if (preg_match($re, $hay)) { $ok = true; $match_line = [$regex, $query]; break 2; }
                            }
                        }
                        $found += $ok ? 1 : 0;
                        if ($verbose) {
                            if ($ok && $match_line) {
                                \WP_CLI::log(sprintf(' - %s (aliases=%s): OK -> %s => %s', $code, implode(',', $aliases), $match_line[0], $match_line[1]));
                            } else {
                                \WP_CLI::log(sprintf(' - %s (aliases=%s): absent', $code, implode(',', $aliases)));
                            }
                        }
                        $log_lines[] = $ok && $match_line
                            ? sprintf('%s OK: %s => %s', $code, $match_line[0], $match_line[1])
                            : sprintf('%s ABSENT (aliases=%s)', $code, implode(',', $aliases));
                    }

                    if ($total > 0) {
                        if ($found === $total) { \WP_CLI::success(sprintf('Rewrite coverage OK (%d/%d)', $found, $total)); }
                        else { \WP_CLI::warning(sprintf('Rewrite partial (%d/%d)', $found, $total)); }
                    }

                    if ($verbose) {
                        foreach ($log_lines as $log_line) { \WP_CLI::log($log_line); }
                    }
                });
            }

            register_activation_hook(self::$plugin_file, [__CLASS__, 'on_activation']);
            register_deactivation_hook(self::$plugin_file, static function () {
                wp_clear_scheduled_hook('yuz_tra_batch_translate');
                wp_clear_scheduled_hook('yuz_auto_translation_tick');
            });
        }

        /**
         * Defines core constants used throughout the plugin.
         */
        private static function define_constants(string $plugin_file): void {
            defined('YUZ_TRA_PLUGIN_FILE') || define('YUZ_TRA_PLUGIN_FILE', $plugin_file);
            defined('YUZ_TRA_DIR')         || define('YUZ_TRA_DIR', plugin_dir_path($plugin_file));
            defined('YUZ_TRA_URL')         || define('YUZ_TRA_URL', plugin_dir_url($plugin_file));
            defined('YUZ_TRA_INCLUDES')    || define('YUZ_TRA_INCLUDES', YUZ_TRA_DIR . 'includes/');
            defined('YUZ_TRA_ASSETS_DIR')  || define('YUZ_TRA_ASSETS_DIR', YUZ_TRA_DIR . 'assets/');
            defined('YUZ_TRA_ASSETS_URL')  || define('YUZ_TRA_ASSETS_URL', YUZ_TRA_URL . 'assets/');
            defined('YUZ_TRA_ASSETS')      || define('YUZ_TRA_ASSETS', YUZ_TRA_ASSETS_URL);
            // WordPress reads this same header; never maintain a second version literal.
            $version_header = get_file_data($plugin_file, ['version' => 'Version'], 'plugin');
            defined('YUZ_TRA_VERSION')     || define('YUZ_TRA_VERSION', $version_header['version']);
            defined('YUZ_LOG_ENABLED')     || define('YUZ_LOG_ENABLED', true);
            defined('YUZ_LOG_LEVEL')       || define('YUZ_LOG_LEVEL', 'warning');
            defined('YUZ_REWRITE_DEBUG')   || define('YUZ_REWRITE_DEBUG', false);
        }

        /**
         * Loads the core PHP files required on every request.
         */
        private static function include_core_files(): void {
            $includes = [
                'helpers/settings-helpers.php',
                'helpers/settings-helpers-debug.php',
                'helpers/lang-helpers.php',
                'helpers/status-helpers.php',
                'services/class-yuz-settings-service.php',
                'class-yuz-contracts.php',
                'class-yuz-core.php',
                'class-yuz-translation-budget.php',
                'class-yuz-product-catalog.php',
                'class-yuz-string-catalog.php',
                'class-yuz-string-scanner.php',
                'class-yuz-translation-jobs.php',
                'class-yuz-translation-memory.php',
                'class-yuz-cron.php',
                'class-yuz-options-bridge.php',
                'class-yuz-assets.php',
                'class-yuz-ajax.php',
                'class-yuz-footer.php',
                'class-yuz-query.php',
                'class-yuz-front-renderer.php',
                'class-yuz-front-buffer.php',
                'class-yuz-frontend.php',
                'class-yuz-debug-probe.php',
                'class-yuz-rest-monitoring.php',
            ];

            foreach ($includes as $relative) {
                $path = YUZ_TRA_INCLUDES . $relative;
                if (file_exists($path)) {
                    require_once $path;
                } else {
                    if ((defined('YUZ_TRA_DEBUG') && YUZ_TRA_DEBUG) || (defined('WP_DEBUG') && WP_DEBUG)) {
                        error_log('🟨 [WARNING] YUZ-TRA: missing include ' . $relative);
                    }
                }
            }
        }

        private static function can_run_runtime_maintenance(): bool {
            if (defined('WP_CLI') && WP_CLI) {
                return true;
            }

            if (defined('DOING_CRON') && DOING_CRON) {
                return true;
            }

            if (defined('DOING_AJAX') && DOING_AJAX) {
                return false;
            }

            return is_admin();
        }

        /**
         * Loads procedural hook packs (payloads, guards, traces).
         */
        public static function load_hook_packs(): void {
            $hooks_dir = YUZ_TRA_INCLUDES . 'hooks/';
            $files = [
                'yuz-payloads.php',
                'yuz-ajax-guard.php',
                'yuz-request-trace.php',
            ];

            foreach ($files as $file) {
                $path = $hooks_dir . $file;
                if (file_exists($path)) {
                    require_once $path;
                } else {
                    if ((defined('YUZ_TRA_DEBUG') && YUZ_TRA_DEBUG) || (defined('WP_DEBUG') && WP_DEBUG)) {
                        error_log('🟨 [WARNING] YUZ-TRA: missing hook file ' . $file);
                    }
                }
            }
        }

        /**
         * Initialises main modules after plugins_loaded.
         */
        public static function init_modules(): void {
            if (class_exists('YUZ_Assets')) {
                YUZ_Assets::init();
            }
            if (class_exists('YUZ_Ajax')) {
                YUZ_Ajax::init();
            }
            // Ensure rewrite rules and lang routing are registered
            if (class_exists('YUZ_Rewrite') && method_exists('YUZ_Rewrite','init')) {
                YUZ_Rewrite::init();
            }
            if (class_exists('YUZ_Rest_Monitoring')) {
                YUZ_Rest_Monitoring::init();
            }
            if (class_exists('YUZ_Footer')) {
                YUZ_Footer::init();
            }
            // Front rendering pipeline
            if (class_exists('YUZ_Front_Buffer') && method_exists('YUZ_Front_Buffer', 'init')) {
                YUZ_Front_Buffer::init();
            }
            if (class_exists('YUZ_Frontend') && method_exists('YUZ_Frontend', 'init')) {
                // Services are optional; the class resolves them lazily
                YUZ_Frontend::init();
            }
            // FSE/Gutenberg: translate core/post-content block output as a safety net
            add_filter('render_block_core/post-content', function ($content, $block) {
                try {
                    if (is_admin() || (defined('REST_REQUEST') && REST_REQUEST)) {
                        return $content;
                    }
                    if (!class_exists('YUZ_Front_Renderer')) {
                        return $content;
                    }
                    $active = YUZ_Front_Renderer::get_active_language();
                    $source = YUZ_Front_Renderer::get_source_language();
                    if (strcasecmp((string)$active, (string)$source) === 0) {
                        return $content;
                    }
                    $post_id = 0;
                    if (function_exists('get_the_ID')) {
                        $pid = get_the_ID();
                        if ($pid) { $post_id = (int) $pid; }
                    }
                    if ($post_id <= 0 && function_exists('get_queried_object_id')) {
                        $pid = get_queried_object_id();
                        if ($pid) { $post_id = (int) $pid; }
                    }
                    if ($post_id <= 0) {
                        return $content;
                    }
                    YUZ_Front_Renderer::register_post_id($post_id);
                    return YUZ_Front_Renderer::translate_post_field((string)$content, $post_id, 'content');
                } catch (\Throwable $e) {
                    if (defined('WP_DEBUG') && WP_DEBUG) {
                        error_log('[YUZ-TRA] render_block_core/post-content filter failed: ' . $e->getMessage());
                    }
                    return $content;
                }
            }, 60, 2);
        }

        /**
         * Ensures DB tables exist at runtime when needed.
         */
        public static function ensure_tables_if_needed(): void {
            if (!self::can_run_runtime_maintenance()) {
                return;
            }

            global $wpdb;
            $lang_table = $wpdb->prefix . 'yuz_tra_languages';

            $like   = str_replace(['_', '%'], ['\\_', '\\%'], $lang_table);
            $exists = (bool) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $like));

            $need = !get_option('yuz_tra_tables_ok') || !$exists;
            if ($need) {
                self::ensure_tables_once();
            }
        }

        /**
         * Keep language flags consistent (source/default must remain translatable).
         */
        public static function guard_language_flags(): void {
            if (!self::can_run_runtime_maintenance()) {
                return;
            }

            $transient_key = 'yuz_lang_guard_run';
            if (get_transient($transient_key)) {
                return;
            }

            if (class_exists('YUZ_Languages') && method_exists('YUZ_Languages', 'enforce_invariants')) {
                try {
                    YUZ_Languages::enforce_invariants();
                } catch (\Throwable $e) {
                    if (defined('WP_DEBUG') && WP_DEBUG) {
                        error_log('[YUZ-TRA] enforce_invariants failed: ' . $e->getMessage());
                    }
                }
            }

            if (class_exists('YUZ_Services')) {
                try {
                    $languages = YUZ_Services::languages();
                    if ($languages && method_exists($languages, 'enforce_language_rules')) {
                        $general      = get_option('yuz_tra_general', []);
                        $translatable = isset($general['yuz_tra_translatable_languages']) && is_array($general['yuz_tra_translatable_languages'])
                            ? $general['yuz_tra_translatable_languages']
                            : [];
                        $languages->enforce_language_rules(null, null, $translatable);
                    }
                } catch (\Throwable $e) {
                    if (defined('WP_DEBUG') && WP_DEBUG) {
                        error_log('[YUZ-TRA] guard_language_flags failed: ' . $e->getMessage());
                    }
                }
            }

            if (class_exists('YUZ_Languages') && method_exists('YUZ_Languages', 'repair_bias')) {
                YUZ_Languages::repair_bias();
            }

            set_transient($transient_key, 1, 10 * MINUTE_IN_SECONDS);
        }

        /**
         * Handles plugin activation (single site or network).
         */
        public static function on_activation($network_wide): void {
            if (is_multisite() && $network_wide) {
                $site_ids = get_sites(['fields' => 'ids']);
                foreach ($site_ids as $site_id) {
                    switch_to_blog($site_id);
                    self::ensure_tables_once();
                    self::initialise_role_settings();
                    self::sync_caps_from_option();
                    restore_current_blog();
                }
            } else {
                self::ensure_tables_once();
                self::initialise_role_settings();
                self::sync_caps_from_option();
            }
        }

        /**
         * Ensures DB tables are present (single run).
         */
        public static function ensure_tables_once(): bool {
            if (!class_exists('YUZ_DB')) {
                $db_file = YUZ_TRA_INCLUDES . 'class-yuz-db.php';
                if (file_exists($db_file)) {
                    require_once $db_file;
                }
            }

            $logger = class_exists('YUZ_Logger') ? new YUZ_Logger() : null;
            $hc     = class_exists('YUZ_Health_Check') ? new YUZ_Health_Check($logger) : null;

            if (!class_exists('YUZ_DB')) {
                error_log('🟨 [WARNING] YUZ-TRA: YUZ_DB class not available.');
                return false;
            }

            try {
                $db = new YUZ_DB($logger, $hc);
                $ok = $db->ensure_tables();

                if ($ok) {
                    if (defined('YUZ_DB::DB_VERSION_OPTION')) {
                        update_option(YUZ_DB::DB_VERSION_OPTION, YUZ_DB::DB_VERSION);
                    }
                    update_option('yuz_tra_tables_ok', true);
                    return true;
                }

                delete_option('yuz_tra_tables_ok');
            } catch (\Throwable $e) {
                error_log('🟥 [CRITICAL] YUZ-TRA DB ensure error: ' . $e->getMessage());
                delete_option('yuz_tra_tables_ok');
            }

            return false;
        }

        /**
         * Returns the list of roles that must keep translator capabilities.
         *
         * @return string[]
         */
        public static function default_allowed_roles(): array {
            $defaults = [];
            foreach (['administrator', 'editor', 'translator'] as $slug) {
                if (get_role($slug)) {
                    $defaults[] = $slug;
                }
            }

            if (!in_array('administrator', $defaults, true)) {
                $defaults[] = 'administrator';
            }

            return array_values(array_unique($defaults));
        }

        /**
         * Syncs translator capabilities from stored options.
         */
        public static function sync_caps_from_option(): void {
            if (!self::can_run_runtime_maintenance()) {
                return;
            }

            $allowed = (array) get_option('yuz_tra_allowed_roles', []);
            $allowed = array_filter(array_map('sanitize_key', $allowed));

            $required = self::default_allowed_roles();
            $changed  = false;
            foreach ($required as $role_slug) {
                if (!in_array($role_slug, $allowed, true)) {
                    $allowed[] = $role_slug;
                    $changed   = true;
                }
            }
            if ($changed) {
                update_option('yuz_tra_allowed_roles', $allowed);
            }

            foreach (wp_roles()->roles as $role_slug => $def) {
                if ($role = get_role($role_slug)) {
                    if (in_array($role_slug, $allowed, true)) {
                        $role->add_cap('yuz_translate_content');
                    } else {
                        $role->remove_cap('yuz_translate_content');
                    }
                    // Clean up old phantom caps as we go.
                    $role->remove_cap('yuz_translate');
                }
            }

            if (class_exists('YUZ_Capabilities') && method_exists('YUZ_Capabilities', 'sync_role_matrix')) {
                \YUZ_Capabilities::sync_role_matrix($allowed);
            }

            if ($admin = get_role('administrator')) {
                $admin->add_cap('yuz_translate_content');
            }

            $site_settings = get_option('yuz_tra_site_settings', []);
            if (!is_array($site_settings)) {
                $site_settings = [];
            }
            $current_allowed = isset($site_settings['allowed_roles'])
                ? array_map('sanitize_key', (array) $site_settings['allowed_roles'])
                : [];

            sort($current_allowed);
            $allowed_copy = $allowed;
            sort($allowed_copy);

            if ($current_allowed !== $allowed_copy) {
                $site_settings['allowed_roles'] = $allowed;
                update_option('yuz_tra_site_settings', $site_settings);
            }
        }

        /**
         * Seeds the allowed roles option on activation.
         */
        private static function initialise_role_settings(): void {
            if ($admin = get_role('administrator')) {
                $admin->add_cap('yuz_translate_content');
            }

            if (get_option('yuz_tra_allowed_roles', null) === null) {
                update_option('yuz_tra_allowed_roles', self::default_allowed_roles());
            }
        }

        /**
         * Allow richer markup (data/aria attributes) inside translated HTML.
         *
         * @param array  $allowed
         * @param string $context
         * @return array
         */
        public static function extend_allowed_html(array $allowed, string $context): array {
            if ($context !== 'post') {
                return $allowed;
            }

            $extra_attrs = [
                'class'            => true,
                'id'               => true,
                'style'            => true,
                'data-id'          => true,
                'data-type'        => true,
                'data-name'        => true,
                'data-label'       => true,
                'data-block'       => true,
                'data-align'       => true,
                'data-placeholder' => true,
                'data-url'         => true,
                'data-target'      => true,
                'aria-label'       => true,
                'aria-hidden'      => true,
                'aria-expanded'    => true,
            ];

            foreach ($allowed as $tag => $attributes) {
                if (!is_array($attributes)) {
                    $attributes = [];
                }
                $allowed[$tag] = array_merge($attributes, $extra_attrs);
            }

            return $allowed;
        }
    }

    // ------------------------------------------------------------------
    // Backwards compatibility helpers for legacy procedural calls.
    // ------------------------------------------------------------------

    if (!function_exists('yuz_tra_ensure_tables_once')) {
        function yuz_tra_ensure_tables_once() {
            return YUZ_Plugin::ensure_tables_once();
        }
    }

    if (!function_exists('yuz_tra_default_allowed_roles')) {
        function yuz_tra_default_allowed_roles(): array {
            return YUZ_Plugin::default_allowed_roles();
        }
    }

    if (!function_exists('yuz_tra_sync_caps_from_option')) {
        function yuz_tra_sync_caps_from_option() {
            YUZ_Plugin::sync_caps_from_option();
        }
    }
}

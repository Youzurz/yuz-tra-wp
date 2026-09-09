<?php
/**
 * Auto-resolution and synchronization helpers for YUZ language settings.
 *
 * @package YUZ_Translation
 */

defined('ABSPATH') || exit;

if (!class_exists('YUZ_Lang_Auto')) {
    final class YUZ_Lang_Auto {
        /**
         * Try to infer the default language from WordPress configuration.
         */
        public static function resolve_default_from_wp(): ?string {
            $wp = function_exists('get_locale') ? get_locale() : '';
            if (!$wp) {
                $wp = (string) get_option('WPLANG', '');
            }
            if ($wp === '') {
                return null;
            }
            return self::normalize($wp);
        }

        /**
         * Resolve the source language by delegating to the detector (if available).
         *
         * @param array $ids
         * @param float $tau
         */
        public static function resolve_source_from_api(array $ids, float $tau = 0.80): ?string {
            $sample = self::collect($ids);
            if ($sample === '') {
                return null;
            }

            if (!class_exists('YUZ_Language_Detector')) {
                return null;
            }

            try {
                $detected = \YUZ_Language_Detector::detect($sample);
            } catch (\Throwable $e) {
                return null;
            }

            if (!is_array($detected)) {
                return null;
            }

            $lang = $detected['language'] ?? '';
            $confidence = isset($detected['confidence']) ? (float) $detected['confidence'] : 0.0;

            if (!$lang || $confidence < $tau) {
                return null;
            }

            return self::normalize($lang);
        }

        /**
         * Collect textual samples from posts/pages to feed the detector.
         */
        private static function collect(array $ids): string {
            if (!function_exists('get_post')) {
                return '';
            }

            $chunks = [];
            foreach ($ids as $id) {
                $post_id = (int) $id;
                if ($post_id <= 0) {
                    continue;
                }

                $post = get_post($post_id);
                if (!$post) {
                    continue;
                }

                if (isset($post->post_title)) {
                    $chunks[] = $post->post_title;
                }
                if (isset($post->post_excerpt) && $post->post_excerpt !== '') {
                    $chunks[] = wp_strip_all_tags($post->post_excerpt);
                }
                if (isset($post->post_content) && $post->post_content !== '') {
                    $chunks[] = wp_strip_all_tags($post->post_content);
                }

                if (strlen(implode("\n", $chunks)) >= 4000) {
                    break;
                }
            }

            if (!$chunks) {
                return '';
            }

            $text = implode("\n", array_filter($chunks));
            $text = preg_replace('/\s+/u', ' ', $text ?? '');
            $text = (string) $text;
            $slice = function_exists('mb_substr') ? mb_substr($text, 0, 4000) : substr($text, 0, 4000);
            return trim($slice);
        }

        /**
         * Normalize a language code to the xx_YY convention.
         */
        public static function normalize(string $code): string {
            $code = trim($code);
            if ($code === '') {
                return '';
            }

            $code = str_replace('-', '_', $code);
            $code = preg_replace('/[^a-zA-Z_]/', '', $code);
            $code = (string) $code;

            $map = [
                'en' => 'en_US',
                'fr' => 'fr_FR',
                'es' => 'es_ES',
                'de' => 'de_DE',
                'it' => 'it_IT',
                'pt' => 'pt_PT',
                'pt_br' => 'pt_BR',
                'nl' => 'nl_NL',
                'pl' => 'pl_PL',
                'ru' => 'ru_RU',
                'ja' => 'ja_JP',
                'zh' => 'zh_CN',
                'zh_cn' => 'zh_CN',
                'zh_tw' => 'zh_TW',
            ];

            $key = strtolower($code);
            if (isset($map[$key])) {
                $normalized = $map[$key];
            } elseif (strpos($code, '_') === false) {
                $normalized = strtolower($code) . '_' . strtoupper($code);
            } else {
                [$lang, $region] = array_pad(explode('_', $code, 2), 2, '');
                $normalized = strtolower($lang);
                if ($region !== '') {
                    $normalized .= '_' . strtoupper($region);
                }
            }

            /**
             * Allow external overrides of the normalization.
             *
             * @param string $normalized
             * @param string $code
             */
            $normalized = apply_filters('yuz/lang/normalize', $normalized, $code);
            return (string) $normalized;
        }
    }
}

if (!class_exists('YUZ_Lang_Resolve')) {
    final class YUZ_Lang_Resolve {
        private static bool $running = false;

        public static function apply(): void {
            if (self::$running) {
                return;
            }
            self::$running = true;
            try {
                $general = (array) get_option('yuz_tra_general', []);
                $original = $general;

                if (!empty($general['yuz_auto_default_language'])) {
                    $auto_default = YUZ_Lang_Auto::resolve_default_from_wp();
                    if ($auto_default) {
                        $general['yuz_tra_default_language'] = $auto_default;
                    }
                }

                if (!empty($general['yuz_auto_source_language'])) {
                    $sample_ids = apply_filters('yuz/source_detection_sample', [get_option('page_on_front')]);
                    $auto_source = YUZ_Lang_Auto::resolve_source_from_api((array) $sample_ids, 0.80);
                    if ($auto_source) {
                        $general['yuz_tra_source_language'] = $auto_source;
                    }
                }

                if ($general !== $original) {
                    update_option('yuz_tra_general', $general, false);
                }
            } finally {
                self::$running = false;
            }
        }
    }
}

if (!class_exists('YUZ_Lang_Sync')) {
    final class YUZ_Lang_Sync {
        private static bool $running = false;

        public static function run(): void {
            if (self::$running) {
                return;
            }
            self::$running = true;
            try {
                if (!get_option('yuz_tra_tables_ok')) {
                    return;
                }

                global $wpdb;
                $table = $wpdb->prefix . 'yuz_tra_languages';
                $like = str_replace(['_', '%'], ['\\_', '\\%'], $table);
                $exists = (bool) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $like));
                if (!$exists) {
                    return;
                }

                $general = (array) get_option('yuz_tra_general', []);
                $default = YUZ_Lang_Auto::normalize((string) ($general['yuz_tra_default_language'] ?? ''));
                $source  = YUZ_Lang_Auto::normalize((string) ($general['yuz_tra_source_language'] ?? ''));

                if ($default === '') {
                    $default = 'en_US';
                }
                if ($source === '') {
                    $source = $default;
                }

                $enabled = array_map(
                    static fn($code) => YUZ_Lang_Auto::normalize((string) $code),
                    (array) ($general['yuz_tra_translatable_languages'] ?? [])
                );
                $enabled = array_values(array_filter(array_unique($enabled)));

                $codes_to_ensure = array_values(array_filter(array_unique(array_merge([$default, $source], $enabled))));
                foreach ($codes_to_ensure as $code) {
                    self::ensure_language_row($table, $code);
                }

                // Reset flags.
                $wpdb->query("UPDATE {$table} SET is_default = 0, is_source = 0");
                $wpdb->query("UPDATE {$table} SET is_translatable = 0");

                $wpdb->update($table, ['is_default' => 1], ['language_code' => $default], ['%d'], ['%s']);
                $wpdb->update($table, ['is_source' => 1], ['language_code' => $source], ['%d'], ['%s']);

                $translatable_codes = array_values(array_unique(array_merge([$default, $source], $enabled)));
                if ($translatable_codes) {
                    $placeholders = implode(',', array_fill(0, count($translatable_codes), '%s'));
                    $wpdb->query(
                        $wpdb->prepare(
                            "UPDATE {$table} SET is_translatable = 1 WHERE language_code IN ({$placeholders})",
                            ...$translatable_codes
                        )
                    );
                }

                $source_id = (int) $wpdb->get_var(
                    $wpdb->prepare("SELECT id FROM {$table} WHERE language_code = %s LIMIT 1", $source)
                );

                if ($source_id > 0) {
                    $settings = (array) get_option('yuz_tra_settings', []);
                    $current_id = isset($settings['source_language_id']) ? (int) $settings['source_language_id'] : 0;
                    if ($current_id !== $source_id) {
                        $settings['source_language_id'] = $source_id;
                        update_option('yuz_tra_settings', $settings, false);
                    }
                }

                delete_transient('yuz_lang_index');
                delete_transient('yuz_lang_choices');
            } finally {
                self::$running = false;
            }
        }

        private static function ensure_language_row(string $table, string $code): void {
            global $wpdb;
            if ($code === '') {
                return;
            }

            $existing = $wpdb->get_var(
                $wpdb->prepare("SELECT id FROM {$table} WHERE language_code = %s LIMIT 1", $code)
            );

            if ($existing) {
                return;
            }

            if (!function_exists('yuz_lang_label')) {
                $label = strtoupper(str_replace('_', '-', $code));
            } else {
                $label = yuz_lang_label($code);
            }

            $browser = substr($code, 0, 2);
            $slug = sanitize_title($code);

            $wpdb->insert(
                $table,
                [
                    'language_code'   => $code,
                    'locale'          => $code,
                    'language_name'   => $label,
                    'native_name'     => $label,
                    'slug'            => $slug,
                    'browser_slug'    => strtolower($browser),
                    'is_default'      => 0,
                    'is_source'       => 0,
                    'is_translatable' => 0,
                ],
                ['%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d']
            );
        }
    }
}

add_action('plugins_loaded', static function (): void {
    if (!class_exists('YUZ_Lang_Resolve') || !class_exists('YUZ_Lang_Sync')) {
        return;
    }
    YUZ_Lang_Resolve::apply();
    YUZ_Lang_Sync::run();
}, 20);

add_action('update_option_yuz_tra_general', static function (): void {
    if (!class_exists('YUZ_Lang_Resolve') || !class_exists('YUZ_Lang_Sync')) {
        return;
    }
    YUZ_Lang_Resolve::apply();
    YUZ_Lang_Sync::run();
}, 10, 0);

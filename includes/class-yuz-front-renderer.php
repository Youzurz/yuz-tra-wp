<?php
/**
 * Front-end rendering utilities (strings, HTML, REST payloads).
 *
 * @package YUZ_Translation
 */

defined('ABSPATH') || exit;

require_once YUZ_TRA_INCLUDES . 'helpers/html-applier.php';

if (!class_exists('YUZ_Front_Renderer')) {
    final class YUZ_Front_Renderer {
        private static ?YUZ_Query $query = null;
        private static ?YUZ_Url_Converter $url_converter = null;
        private static ?YUZ_Languages $languages = null;
        /** @var int[] */
        private static array $registered_post_ids = [];

        /**
         * Remember post IDs encountered during the request so we can fetch their strings later.
         */
        public static function register_post_id(int $post_id): void {
            if ($post_id <= 0) {
                return;
            }
            if (!in_array($post_id, self::$registered_post_ids, true)) {
                self::$registered_post_ids[] = $post_id;
            }
        }

        /**
         * Translate full page output (HTML/JSON) before it is echoed.
         *
         * @param string $output
         * @return string
         */
        public static function translate_page(string $output): string {
            $active  = self::get_active_language();
            $default = self::get_default_language();
            $source  = self::get_source_language();
            $logger  = class_exists('YUZ_Logger') ? new YUZ_Logger() : null;

            $is_source        = strcasecmp($active, $source) === 0;
            $is_default       = strcasecmp($active, $default) === 0;
            $should_translate = !($output === '' || $is_source);

            if ($logger) {
                $logger->log('info', 'Front buffer translate_page invoked', [
                    'active_lang'     => $active,
                    'default_lang'    => $default,
                    'source_lang'     => $source,
                    'same_as_default' => $is_default,
                    'same_as_source'  => $is_source,
                    'output_length'   => strlen((string) $output),
                    'has_markup'      => (strpos($output, '<') !== false && strpos($output, '>') !== false),
                    'has_blocks'      => (strpos($output, '<!-- wp:') !== false),
                    'preview'         => substr((string) $output, 0, 160),
                    'will_translate'  => $should_translate,
                ]);
            }

            if (!$should_translate) {
                return $output;
            }

            $json = self::decode_json($output);
            if (is_array($json)) {
                if ($logger) {
                    $logger->log('info', 'Front buffer translating JSON payload', [
                        'top_keys'   => array_slice(array_keys($json), 0, 5),
                        'json_depth' => self::json_depth($json),
                    ]);
                }
                $translated = self::translate_json_tree($json);
                $encoded = wp_json_encode($translated);
                if ($encoded === '' && $output !== '') {
                    return $output; // safety: never blank out
                }
                return $encoded;
            }

            if ($logger) {
                $logger->log('info', 'Front buffer translating HTML payload', [
                    'length'      => strlen($output),
                    'tag_count'   => substr_count($output, '<'),
                    'has_blocks'  => (strpos($output, '<!-- wp:') !== false),
                    'preview_src' => substr($output, 0, 160),
                ]);
            }
            $html = self::translate_html_document($output);
            if ($html === '' && $output !== '') {
                return $output; // safety: never blank out
            }
            return $html;
        }

        /**
         * Translate a post field (content/title/excerpt).
         */
        public static function translate_post_field(string $text, int $post_id, string $context): string {
            $active  = self::get_active_language();
            $source  = self::get_source_language();
            if ($text === '' || $post_id <= 0 || strcasecmp($active, $source) === 0) {
                return $text;
            }

            $logger = class_exists('YUZ_Logger') ? new YUZ_Logger() : null;
            if ($context === 'content') {
                $map = self::query()->get_post_translation_map($post_id, $active, $context);
                if (!empty($map)) {
                    $applier = new YUZ_HTML_Apply($map, $post_id, $context);
                    $translated_html = $applier->apply($text);
                    if ($translated_html !== $text) {
                        if ($logger) {
                            try {
                                $logger->log('debug', 'translate_post_field applied map', [
                                    'post_id'    => $post_id,
                                    'lang'       => $active,
                                    'entries'    => count($map),
                                    'len_src'    => strlen($text),
                                    'len_dst'    => strlen($translated_html),
                                ]);
                            } catch (\Throwable $ignored) {}
                        }
                        return $translated_html;
                    }
                }
            } else {
                $translation = self::query()->get_post_translation($post_id, $active, $context);
                if ($translation && isset($translation['translated_text']) && $translation['translated_text'] !== '') {
                    if ($logger) {
                        try {
                            $logger->log('debug', 'translate_post_field success', [
                                'post_id'     => $post_id,
                                'context'     => $context,
                                'lang'        => $active,
                                'len'         => strlen((string) $translation['translated_text']),
                                'status'      => $translation['status'] ?? null,
                                'has_markup'  => (strpos((string) $translation['translated_text'], '<') !== false),
                                'has_blocks'  => (strpos((string) $translation['translated_text'], '<!-- wp:') !== false),
                                'preview'     => substr((string) $translation['translated_text'], 0, 120),
                            ]);
                        } catch (\Throwable $e) {
                            // ignore logging failures
                        }
                    }
                    return (string) $translation['translated_text'];
                }
            }

            if ($logger) {
                try {
                    $logger->log('debug', 'translate_post_field fallback', [
                        'post_id'      => $post_id,
                        'context'      => $context,
                        'lang'         => $active,
                        'text_length'  => strlen($text),
                        'has_markup'   => (strpos($text, '<') !== false),
                        'has_blocks'   => (strpos($text, '<!-- wp:') !== false),
                        'preview_src'  => substr($text, 0, 120),
                    ]);
                } catch (\Throwable $e) {
                    // Swallow logging errors; we do not interrupt rendering.
                }
            }

            return $text;
        }

        /**
         * Translate a REST API post response.
         *
         * @param WP_REST_Response $response
         * @param WP_Post          $post
         * @return WP_REST_Response
         */
        public static function translate_rest_post(\WP_REST_Response $response, \WP_Post $post): \WP_REST_Response {
            $data = $response->get_data();
            if (!is_array($data)) {
                return $response;
            }

            $active  = self::get_active_language();
            $source  = self::get_source_language();
            if (strcasecmp($active, $source) === 0) {
                return $response;
            }

            $post_id = (int) $post->ID;
            if ($post_id > 0) {
                if (isset($data['title']['rendered'])) {
                    $data['title']['rendered'] = self::translate_post_field((string) $data['title']['rendered'], $post_id, 'title');
                }
                if (isset($data['excerpt']['rendered'])) {
                    $data['excerpt']['rendered'] = self::translate_post_field((string) $data['excerpt']['rendered'], $post_id, 'excerpt');
                }
                if (isset($data['content']['rendered'])) {
                    $data['content']['rendered'] = self::translate_post_field((string) $data['content']['rendered'], $post_id, 'content');
                }
            }

            $response->set_data($data);
            return $response;
        }

        /**
         * Translate wp_nav_menu items payload.
         *
         * @param array $items
         * @return array
         */
        public static function translate_menu_items(array $items): array {
            $active  = self::get_active_language();
            $source  = self::get_source_language();
            if (strcasecmp($active, $source) === 0 || empty($items)) {
                return $items;
            }

            foreach ($items as $item) {
                if (!empty($item->ID)) {
                    $translated = self::query()->get_post_translation((int) $item->ID, $active, 'menu');
                    if ($translated && !empty($translated['translated_text'])) {
                        $item->title = $translated['translated_text'];
                    }
                }
                if (!empty($item->url)) {
                    $item->url = self::convert_url((string) $item->url);
                }
            }

            return $items;
        }

        /**
         * Convert any URL to the current language if possible.
         */
        public static function convert_url(string $url): string {
            if ($url === '') {
                return $url;
            }
            $active  = self::get_active_language();
            $source  = self::get_source_language();
            if ($active === '' || strcasecmp($active, $source) === 0) {
                return $url;
            }

            $logger    = class_exists('YUZ_Logger') ? new YUZ_Logger() : null;
            $converter = self::url_converter();
            try {
                $explicitLocale = $converter->detect_locale_from_url($url);
                if ($explicitLocale && strcasecmp($explicitLocale, $active) !== 0) {
                    if ($logger) {
                        $logger->log('debug', 'convert_url preserved explicit locale link', [
                            'url'      => $url,
                            'active'   => $active,
                            'detected' => $explicitLocale,
                        ]);
                    }
                    return $url;
                }

                $converted = $converter->get_url_for_language($active, $url);
                if ($logger) {
                    if ($converted && $converted !== $url) {
                        $logger->log('info', 'URL converted for active language', [
                            'from' => $url,
                            'to'   => $converted,
                            'lang' => $active,
                        ]);
                    } else {
                        $logger->log('debug', 'URL conversion noop', [
                            'url'  => $url,
                            'lang' => $active,
                        ]);
                    }
                }
                return $converted ?: $url;
            } catch (\Throwable $e) {
                if ($logger) {
                    $logger->log('warning', 'convert_url failure', [
                        'error' => $e->getMessage(),
                        'url'   => $url,
                        'lang'  => $active,
                    ]);
                }
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    yuz_tra_debug_log('[YUZ-TRA] convert_url failure: ' . $e->getMessage());
                }
                return $url;
            }
        }

        /**
         * Return active locale (xx_XX).
         */
        public static function get_active_language(): string {
            static $active = null;
            if ($active !== null) {
                return $active;
            }

            try {
                $locale = self::url_converter()->get_active_locale();
                if ($locale) {
                    $active = str_replace('-', '_', $locale);
                    return $active;
                }
            } catch (\Throwable $e) {
                // swallow
            }

            $active = self::get_default_language();
            return $active;
        }

        /**
         * Return default language from settings.
         */
        public static function get_default_language(): string {
            static $default = null;
            if ($default !== null) {
                return $default;
            }
            if (class_exists('YUZ_Services')) {
                try {
                    $default = (string) YUZ_Services::languages()->get_default_language();
                } catch (\Throwable $e) {
                    $default = get_locale();
                }
            } else {
                $default = get_locale();
            }
            return $default;
        }

        /**
         * Return source language from settings.
         */
        public static function get_source_language(): string {
            static $source = null;
            if ($source !== null) {
                return $source;
            }
            if (class_exists('YUZ_Services')) {
                try {
                    $source = (string) YUZ_Services::languages()->get_source_language();
                    if ($source !== '') {
                        return $source;
                    }
                } catch (\Throwable $e) {
                    // fall back below
                }
            }
            $source = self::get_default_language();
            return $source;
        }

        /**
         * Lazy accessor.
         */
        private static function query(): YUZ_Query {
            if (self::$query === null) {
                self::$query = class_exists('YUZ_Services')
                    ? YUZ_Services::translations()
                    : new YUZ_Query();
            }
            return self::$query;
        }

        /**
         * Lazy URL converter.
         */
        private static function url_converter(): YUZ_Url_Converter {
            if (self::$url_converter === null) {
                if (!class_exists('YUZ_Settings')) {
                    require_once YUZ_TRA_INCLUDES . 'class-yuz-settings.php';
                }
                $settings = class_exists('YUZ_Services') ? YUZ_Services::settings() : new YUZ_Settings(
                    new \YUZTRA\Fallbacks\NullLanguages(),
                    new \YUZTRA\Fallbacks\NullAjax(),
                    new \YUZTRA\Fallbacks\NullTranslationManager(),
                    new \YUZTRA\Fallbacks\NullLanguageManager(),
                    new \YUZTRA\Fallbacks\NullLogger()
                );
                self::$url_converter = new YUZ_Url_Converter($settings);
            }
            return self::$url_converter;
        }

        /**
         * Decode JSON safely.
         */
        private static function decode_json(string $payload) {
            if ($payload === '' || stripos(ltrim($payload), '{') !== 0 && stripos(ltrim($payload), '[') !== 0) {
                return null;
            }
            $decoded = json_decode($payload, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
            return null;
        }

        /**
         * Translate recursive JSON nodes where values look like HTML.
         *
         * @param mixed $node
         * @return mixed
         */
        private static function translate_json_tree($node) {
            if (is_array($node)) {
                foreach ($node as $key => $value) {
                    $node[$key] = self::translate_json_tree($value);
                }
                return $node;
            }
            if (is_string($node)) {
                // Attempt to translate HTML snippets
                if (wp_strip_all_tags($node) !== $node) {
                    return self::translate_html_document($node);
                }
            }
            return $node;
        }

        /**
         * Update HTML links and attributes for the active language.
         */
        private static function translate_html_document(string $html): string {
            if ($html === '') {
                return $html;
            }

            $active = self::get_active_language();
            if ($active === '') {
                return $html;
            }

            $converted = preg_replace_callback(
                '/\shref=(["\'])([^"\']+)\1/i',
                static function ($matches) {
                    $url       = $matches[2];
                    $converted = self::convert_url($url);
                    if ($converted === $url) {
                        return $matches[0];
                    }
                    return ' href=' . $matches[1] . esc_url($converted) . $matches[1];
                },
                $html
            );

            $converted_before_actions = $converted;

            if (is_string($converted)) {
                $converted = preg_replace_callback(
                    '/\saction=(["\'])([^"\']+)\1/i',
                    static function ($matches) {
                        $url       = $matches[2];
                        $converted = self::convert_url($url);
                        if ($converted === $url) {
                            return $matches[0];
                        }
                        return ' action=' . $matches[1] . esc_url($converted) . $matches[1];
                    },
                    $converted
                );
            }

            $final = is_string($converted) ? $converted : $html;

            if ($final !== $html && class_exists('YUZ_Logger')) {
                try {
                    (new YUZ_Logger())->log('debug', 'translate_html_document mutated', [
                        'len_src'      => strlen($html),
                        'len_dst'      => strlen($final),
                        'tags_src'     => substr_count($html, '<'),
                        'tags_dst'     => substr_count($final, '<'),
                        'href_changes' => (is_string($converted_before_actions ?? '') ? substr_count($converted_before_actions, ' href=') : 0),
                        'preview_dst'  => substr($final, 0, 160),
                    ]);
                } catch (\Throwable $ignored) {}
            }

            return $final;
        }

        private static function json_depth($node, int $depth = 0): int {
            if (!is_array($node)) {
                return $depth;
            }
            $depth++;
            $max = $depth;
            foreach ($node as $value) {
                $max = max($max, self::json_depth($value, $depth));
            }
            return $max;
        }
    }
}

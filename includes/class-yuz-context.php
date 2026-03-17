<?php
/**
 * Shared helpers for translation context normalization.
 *
 * @package YUZ_Translation
 */

defined('ABSPATH') || exit;

if (!class_exists('YUZ_Context')) {
    final class YUZ_Context {
        /**
         * Canonicalise a context key so editor/runtime share the same lookup.
         */
        public static function normalize(string $context): string {
            $context = strtolower(trim($context));
            if ($context === '' || $context === 'auto') {
                return 'content';
            }

            static $aliases = [
                'post_content'      => 'content',
                'core/post-content' => 'content',
                'content/main'      => 'content',
                'content/body'      => 'content',
                'body'              => 'content',
                'main'              => 'content',
                'content:main'      => 'content',
                'content-main'      => 'content',
                'title/main'        => 'title',
                'post_title'        => 'title',
                'heading'           => 'title',
                'headline'          => 'title',
                'excerpt/main'      => 'excerpt',
                'post_excerpt'      => 'excerpt',
                'summary'           => 'excerpt',
                'menu_item'         => 'menu',
                'nav_menu'          => 'menu',
                'menu/title'        => 'menu',
                'menu:text'         => 'menu',
                'slug'              => 'slug',
                'permalink'         => 'slug',
                'seo/title'         => 'seo_title',
                'seo/description'   => 'seo_description',
            ];

            if (isset($aliases[$context])) {
                return $aliases[$context];
            }

            if (strpos($context, 'content/') === 0 || strpos($context, 'content:') === 0) {
                return 'content';
            }
            if (strpos($context, 'title/') === 0 || strpos($context, 'title:') === 0) {
                return 'title';
            }
            if (strpos($context, 'excerpt/') === 0 || strpos($context, 'excerpt:') === 0) {
                return 'excerpt';
            }
            if (preg_match('/^menu[\W_]/', $context)) {
                return 'menu';
            }

            $normalized = preg_replace('/[^a-z0-9_-]/', '_', $context);
            $normalized = $normalized !== null ? trim($normalized, '_') : '';
            if ($normalized === '') {
                return 'content';
            }

            return $normalized;
        }
    }
}


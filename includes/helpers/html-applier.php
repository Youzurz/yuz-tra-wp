<?php
/**
 * Apply stored translations to an HTML document while keeping markup intact.
 *
 * @package YUZ_Translation
 */

defined('ABSPATH') || exit;

if (!class_exists('YUZ_HTML_Apply')) {
    class YUZ_HTML_Apply {
        /** @var array<string,array> */
        private array $translations;
        /** @var int */
        private int $post_id;
        /** @var string */
        private string $context;

        public function __construct(array $translations, int $post_id, string $context) {
            $this->translations = $translations;
            $this->post_id      = $post_id;
            $this->context      = $context;
        }

        public function apply(string $html): string {
            if ($html === '' || empty($this->translations)) {
                return $html;
            }

            $document = new \DOMDocument('1.0', 'UTF-8');
            $options  = LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOERROR | LIBXML_NOWARNING;

            $wrapper = 'yuz-applier-' . wp_generate_password(6, false);
            $payload = '<?xml encoding="utf-8" ?><div id="' . $wrapper . '">' . $html . '</div>';
            libxml_use_internal_errors(true);
            $loaded = $document->loadHTML($payload, $options);
            libxml_clear_errors();
            if (!$loaded) {
                return $html;
            }

            $xpath = new \DOMXPath($document);
            $nodes = $xpath->query(sprintf('//*[@id="%s"]//text()', $wrapper));
            if (!$nodes instanceof \DOMNodeList) {
                return $html;
            }

            foreach ($nodes as $node) {
                if (!$node instanceof \DOMText) {
                    continue;
                }
                $original = (string) $node->nodeValue;
                $normalized = self::normalize($original);
                if ($normalized === '') {
                    continue;
                }
                $block_id = function_exists('yuz_generate_block_id')
                    ? yuz_generate_block_id($this->post_id, $this->context, $normalized)
                    : sha1($this->post_id . '|' . $this->context . '|' . $normalized);

                if (!isset($this->translations[$block_id])) {
                    continue;
                }
                $replacement = (string) ($this->translations[$block_id]['translated_text'] ?? '');
                if ($replacement === '') {
                    continue;
                }
                $node->nodeValue = $replacement;
            }

            $root = $document->getElementById($wrapper);
            if (!$root) {
                return $html;
            }

            $output = '';
            foreach ($root->childNodes as $child) {
                $output .= $document->saveHTML($child);
            }

            return $output !== '' ? $output : $html;
        }

        public static function normalize(string $value): string {
            $value = str_replace(["\r\n", "\r"], "\n", $value);
            $value = preg_replace("/\n{3,}/", "\n\n", $value);
            return $value ?? '';
        }
    }
}

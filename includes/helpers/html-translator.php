<?php
/**
 * HTML translation helper – walks the DOM and translates text nodes while preserving markup.
 *
 * @package YUZ_Translation
 */

defined('ABSPATH') || exit;

if (!class_exists('YUZ_HTML_Translator')) {
    class YUZ_HTML_Translator {
        /** @var \YUZ_Translation_Manager|\YUZTRA\Interfaces\TranslationManagerInterface|null */
        private $translation_manager;

        public function __construct($translation_manager) {
            $this->translation_manager = $translation_manager;
        }

        /**
         * Translate HTML content keeping the structure intact.
         *
         * @param string $html
         * @param int    $source_lang_id
         * @param int    $target_lang_id
         * @return string
         */
        public function translate_html(string $html, int $source_lang_id, int $target_lang_id): string {
            if ($html === '') return '';
            if (!$this->translation_manager) throw new RuntimeException('translation_manager_unavailable');

            $wrapper_id = 'yuz-html-root-' . wp_generate_password(8, false);
            $document   = new \DOMDocument('1.0', 'UTF-8');

            $options = LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOERROR | LIBXML_NOWARNING;
            $encoded = '<?xml encoding="utf-8" ?><div id="' . $wrapper_id . '">' . $html . '</div>';

            $previous_errors = libxml_use_internal_errors(true);
            $loaded = $document->loadHTML($encoded, $options);
            libxml_clear_errors();
            libxml_use_internal_errors($previous_errors);

            if (!$loaded) {
                throw new RuntimeException('html_parse_failed');
            }

            $xpath = new \DOMXPath($document);
            $nodes = $xpath->query(sprintf('//*[@id="%s"]//text()[not(ancestor::script or ancestor::style or ancestor::code or ancestor::*[@translate="no"] or ancestor::*[contains(concat(" ", normalize-space(@class), " "), " notranslate ")])]', $wrapper_id));
            if ($nodes instanceof \DOMNodeList) {
                /** @var \DOMText $node */
                foreach ($nodes as $node) {
                    $original = $node->nodeValue;
                    if (!is_string($original)) {
                        continue;
                    }

                    $normalized = trim(preg_replace('/\s+/u', ' ', $original));
                    if ($normalized === '') {
                        continue;
                    }

                    try {
                        $translated = $this->translation_manager->translate($original, $source_lang_id, $target_lang_id);
                        if (is_string($translated) && $translated !== '') {
                            $node->nodeValue = $translated;
                        } else {
                            throw new RuntimeException('empty_from_provider');
                        }
                    } catch (\Throwable) {
                        throw new RuntimeException('html_translation_failed');
                    }
                }
            }

            $wrapper = $document->getElementById($wrapper_id);
            if (!$wrapper) {
                throw new RuntimeException('html_wrapper_missing');
            }

            $output = '';
            foreach ($wrapper->childNodes as $child) {
                $output .= $document->saveHTML($child);
            }

            if ($output === '') throw new RuntimeException('html_serialization_failed');
            return $output;
        }
    }
}

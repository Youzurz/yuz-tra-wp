<?php
defined('ABSPATH') || exit;

/* =========================
 *  (1) NORMALISATION INTERNE
 * ========================= */
if (!function_exists('yuz_norm_locale')) {
    function yuz_norm_locale(string $code): string {
        $s = strtolower(trim($code));
        return strtr($s, ['_' => '-', ' ' => '']);
    }
}

if (!function_exists('yuz_normalize_language_code')) {
    /**
     * Normalize arbitrary inputs into the canonical xx_XX format.
     * - Accepts xx, xx-yy, xx_yy, and trims/cleans unknown separators.
     * - Returns an empty string for invalid/auto values.
     */
    function yuz_normalize_language_code($code): string {
        $raw = is_string($code) ? trim($code) : '';
        if ($raw === '' || strtolower($raw) === 'auto') {
            return '';
        }

        $raw = str_replace('-', '_', $raw);
        $parts = array_values(array_filter(explode('_', $raw), 'strlen'));
        if (empty($parts)) {
            return '';
        }

        $normalized = [];
        foreach ($parts as $index => $part) {
            $normalized[] = $index === 0 ? strtolower($part) : strtoupper($part);
        }

        if (count($normalized) === 1) {
            // Duplicate the root to build a region (en -> en_US, fr -> fr_FR)
            $root = $normalized[0];
            $normalized[] = ($root === 'en') ? 'US' : strtoupper($root);
        }

        // Canonical form keeps the first 2 segments.
        return implode('_', array_slice($normalized, 0, 2));
    }
}

if (!function_exists('yuz_root')) {
    function yuz_root(string $code): string {
        $s = yuz_norm_locale($code);
        if ($s === '' || $s === 'auto') {
            return $s;
        }
        if ($s === 'iw' || str_starts_with($s, 'iw-')) {
            return 'he';
        }
        if (str_starts_with($s, 'zh')) {
            return 'zh';
        }
        if (str_starts_with($s, 'pt')) {
            return 'pt';
        }
        return substr($s, 0, 2);
    }
}

if (!function_exists('yuz_canon_lang')) {
    /**
     * Canonical provider code mapper: xx_XX / xx / auto → xx
     * Centralises aliases so every layer shares the same mapping.
     */
    function yuz_canon_lang($code): string {
        if ($code === null) {
            return 'auto';
        }

        $s  = str_replace('-', '_', trim((string) $code));
        if ($s === '') {
            return 'auto';
        }

        $sl = strtolower($s);
        if ($sl === 'auto') {
            return 'auto';
        }

        static $ALIASES = [
            // English
            'en_us' => 'en', 'en_gb' => 'en', 'en_ca' => 'en', 'en_au' => 'en',
            // French / Spanish / Portuguese
            'fr_fr' => 'fr', 'fr_ca' => 'fr', 'es_es' => 'es', 'es_mx' => 'es',
            'pt_br' => 'pt', 'pt_pt' => 'pt',
            // Chinese variants collapse to zh
            'zh_cn' => 'zh', 'zh_tw' => 'zh', 'zh_hans' => 'zh', 'zh_hant' => 'zh',
            // Additional common locales → root
            'de_de' => 'de', 'it_it' => 'it', 'nl_nl' => 'nl',
            'ja_jp' => 'ja', 'ko_kr' => 'ko', 'ru_ru' => 'ru',
        ];

        if (isset($ALIASES[$sl])) {
            return $ALIASES[$sl];
        }

        if (preg_match('/^([a-z]{2,3})_/', $sl, $m)) {
            return $m[1];
        }

        if (preg_match('/^[a-z]{2,3}$/', $sl)) {
            return $sl;
        }

        return substr($sl, 0, 2);
    }
}

if (!function_exists('yuz_allowed_locales')) {
    function yuz_allowed_locales(): array {
        $cfg = get_option('yuz_tra_general', []);
        $list = (array) ($cfg['yuz_tra_translatable_languages'] ?? []);
        $out  = [];
        foreach ($list as $item) {
            $n = yuz_norm_locale($item);
            if ($n !== '') {
                $out[] = $n;
            }
        }
        return array_values(array_unique($out));
    }
}

/* ====================================
 *  (2) MAPPING WORDPRESS -> LibreTranslate
 * ==================================== */
if (!function_exists('yuz_map_to_lt')) {
    function yuz_map_to_lt(string $code, string $fallback = 'auto'): string {
        $canon = yuz_canon_lang($code);
        if ($canon === 'auto') {
            if ($fallback === 'auto') {
                return 'auto';
            }

            return yuz_canon_lang($fallback);
        }

        return $canon;
    }
}

/* ====================================
 *  (3) MAPPING LibreTranslate -> WordPress
 * ==================================== */
if (!function_exists('yuz_default_locales')) {
    function yuz_default_locales(): array {
        return [
            'ar' => 'ar_SA', 'az' => 'az_AZ', 'eu' => 'eu_ES', 'bn' => 'bn_BD', 'bg' => 'bg_BG',
            'ca' => 'ca_ES', 'zh' => 'zh_CN', 'zh_tw' => 'zh_TW', 'cs' => 'cs_CZ', 'da' => 'da_DK',
            'nl' => 'nl_NL', 'en' => 'en_US', 'et' => 'et_EE', 'fi' => 'fi_FI', 'fr' => 'fr_FR',
            'gl' => 'gl_ES', 'de' => 'de_DE', 'el' => 'el_GR', 'he' => 'he_IL', 'hi' => 'hi_IN',
            'hu' => 'hu_HU', 'id' => 'id_ID', 'ga' => 'ga_IE', 'it' => 'it_IT', 'ja' => 'ja_JP',
            'lv' => 'lv_LV', 'lt' => 'lt_LT', 'ms' => 'ms_MY', 'no' => 'no_NO', 'fa' => 'fa_IR',
            'pl' => 'pl_PL', 'pt' => 'pt_PT', 'pt_br' => 'pt_BR', 'ro' => 'ro_RO', 'ru' => 'ru_RU',
            'sk' => 'sk_SK', 'sl' => 'sl_SI', 'es' => 'es_ES', 'sv' => 'sv_SE', 'tl' => 'tl_PH',
            'th' => 'th_TH', 'tr' => 'tr_TR', 'uk' => 'uk_UA', 'ur' => 'ur_PK', 'vi' => 'vi_VN',
        ];
    }
}

if (!function_exists('yuz_map_from_lt')) {
    function yuz_map_from_lt(string $lt, array $allowed = []): string {
        $l = strtolower(trim($lt));
        if ($l === '' || $l === 'auto') {
            return '';
        }

        if ($l === 'iw') {
            $l = 'he';
        } elseif ($l === 'zh-hant') {
            $l = 'zh_tw';
        } elseif ($l === 'zh-hans') {
            $l = 'zh';
        }

        $root = ($l === 'pt_br' || $l === 'zh_tw') ? $l : substr($l, 0, 2);

        if ($allowed) {
            $matchRoot = ($root === 'pt_br' || $root === 'zh_tw') ? substr($root, 0, 2) : $root;
            foreach ($allowed as $loc) {
                if (yuz_root($loc) === $matchRoot) {
                    $parts = explode('-', yuz_norm_locale($loc));
                    return strtolower($parts[0]) . '_' . strtoupper($parts[1] ?? $parts[0]);
                }
            }
        }

        $defaults = yuz_default_locales();
        if (isset($defaults[$l])) {
            return $defaults[$l];
        }
        if (isset($defaults[$root])) {
            return $defaults[$root];
        }

        $r = substr($l, 0, 2);
        return $r ? strtolower($r) . '_' . strtoupper($r) : '';
    }
}

/* ====================================
 *  (Bonus) Résolution côté WP (utilise allowed)
 * ==================================== */
if (!function_exists('yuz_resolve_target_locale')) {
    function yuz_resolve_target_locale(string $target): string {
        $t = yuz_norm_locale($target);
        if ($t === '' || $t === 'auto') {
            return '';
        }

        $allowed = yuz_allowed_locales();

        if ($allowed && in_array($t, $allowed, true)) {
            return $t;
        }

        if ($allowed) {
            $r = yuz_root($t);
            foreach ($allowed as $loc) {
                if (yuz_root($loc) === $r) {
                    return $loc;
                }
            }
        }

        $lt = yuz_map_to_lt($t);
        $wp = yuz_map_from_lt($lt, $allowed);
        return $wp ? yuz_norm_locale($wp) : '';
    }
}

if (!function_exists('yuz_strip_css_js_noise')) {
    function yuz_strip_css_js_noise(string $text): string {
        if ($text === '') {
            return '';
        }

        $clean = $text;

        $patterns = [
            '/<script\b[^>]*>[\s\S]*?<\/script>/iu',
            '/<style\b[^>]*>[\s\S]*?<\/style>/iu',
            '/\/\*[\s\S]*?\*\//u',
            '/(^|[^\w:])\/\/[^\r\n]*/u',
            '/[#.\w-][^{]{0,160}\{[^}]*\}/u',
            '/[(){}@]+/u',
        ];

        foreach ($patterns as $pattern) {
            $replacement = $pattern === '/(^|[^\w:])\/\/[^\r\n]*/u' ? '$1 ' : ' ';
            $replaced = preg_replace($pattern, $replacement, $clean);
            if ($replaced !== null) {
                $clean = $replaced;
            }
        }

        $clean = wp_strip_all_tags($clean, false);
        $clean = html_entity_decode($clean, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $markers = [
            'vos préférences de confidentialité',
            "j'accepte de recevoir des e-mails",
            'j’accepte de recevoir des e-mails',
            'facebook twitter linkedin discord',
            'addEventListener',
            'localStorage',
            'document.',
            'window.',
            'cookies et collectons',
            'cookies et collecte'
        ];

        foreach ($markers as $marker) {
            $pos = mb_stripos($clean, $marker, 0, 'UTF-8');
            if ($pos !== false) {
                $clean = mb_substr($clean, 0, $pos, 'UTF-8');
            }
        }

        $clean = preg_replace('/\s{2,}/u', ' ', $clean);
        if ($clean === null) {
            $clean = '';
        }

        $clean = preg_replace('/[|\/\\·•]+\s*$/u', '', $clean);
        if ($clean === null) {
            $clean = '';
        }

        $clean = trim($clean);

        if ($clean === '' || !preg_match('/[A-Za-zÀ-ÿ]{2}/u', $clean)) {
            $fallback = trim(html_entity_decode(wp_strip_all_tags($text, false), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($fallback !== '' && preg_match('/[A-Za-zÀ-ÿ]{2}/u', $fallback)) {
                return $fallback;
            }
            return '';
        }

        return $clean;
    }
}

if (!function_exists('yuz_human_label_from_locale')) {
    function yuz_human_label_from_locale(string $locale): string {
        $norm = str_replace('-', '_', trim($locale));
        if ($norm === '') {
            return '';
        }

        $parts = explode('_', $norm);
        $lang = strtolower($parts[0] ?? '');
        $region = strtoupper($parts[1] ?? '');

        static $LANG_MAP = [
            'ar'=>'العربية','az'=>'Azərbaycan dili','bg'=>'Български','bn'=>'বাংলা','ca'=>'Català',
            'cs'=>'Čeština','da'=>'Dansk','de'=>'Deutsch','el'=>'Ελληνικά','en'=>'English',
            'es'=>'Español','et'=>'Eesti','fi'=>'Suomi','fr'=>'Français','gl'=>'Galego',
            'he'=>'עברית','hi'=>'हिन्दी','hu'=>'Magyar','id'=>'Indonesia','it'=>'Italiano',
            'ja'=>'日本語','ko'=>'한국어','lt'=>'Lietuvių','lv'=>'Latviešu','ms'=>'Bahasa Melayu',
            'nb'=>'Norsk Bokmål','nl'=>'Nederlands','pl'=>'Polski','pt'=>'Português','ro'=>'Română',
            'ru'=>'Русский','sk'=>'Slovenčina','sl'=>'Slovenščina','sv'=>'Svenska','th'=>'ไทย',
            'tr'=>'Türkçe','uk'=>'Українська','vi'=>'Tiếng Việt','zh'=>'中文'
        ];

        static $REGION_MAP = [
            'AE'=>'Émirats Arabes Unis','AR'=>'Argentina','AU'=>'Australia','BD'=>'Bangladesh','BE'=>'Belgique',
            'BR'=>'Brasil','CA'=>'Canada','CH'=>'Suisse','CN'=>'Chine','CZ'=>'Česko','DE'=>'Deutschland',
            'DK'=>'Danmark','EE'=>'Eesti','ES'=>'España','FI'=>'Suomi','FR'=>'France','GB'=>'United Kingdom',
            'GR'=>'Ελλάδα','HK'=>'Hong Kong','IE'=>'Ireland','IL'=>'Israël','IN'=>'India','IT'=>'Italia',
            'JP'=>'日本','KR'=>'대한민국','MX'=>'México','MY'=>'Malaysia','NL'=>'Nederland','NO'=>'Norge',
            'NZ'=>'New Zealand','PH'=>'Philippines','PL'=>'Polska','PT'=>'Portugal','RO'=>'România',
            'RU'=>'Россия','SE'=>'Sverige','SG'=>'Singapore','TH'=>'ไทย','TR'=>'Türkiye','TW'=>'台灣',
            'UA'=>'Україна','US'=>'United States','VN'=>'Việt Nam'
        ];

        $language = $LANG_MAP[$lang] ?? strtoupper($lang);
        if ($language === '') {
            $language = strtoupper($lang);
        }

        if ($region === '') {
            return $language;
        }

        $country = $REGION_MAP[$region] ?? strtoupper($region);
        return sprintf('%s (%s)', $language, $country);
    }
}

if (!function_exists('yuz_generate_block_id')) {
    function yuz_generate_block_id(int $post_id, string $context, string $original): string {
        $context_norm = strtolower(trim($context));
        $text = preg_replace('/\s+/u', ' ', trim($original));
        $seed = $post_id . '|' . $context_norm . '|' . $text;
        return 'auto:' . substr(sha1($seed), 0, 40);
    }
}

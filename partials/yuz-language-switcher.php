<?php
/**
 * YUZ Language Switcher Partial
 * Renders the language switcher UI for shortcode, menu, or floating modes.
 *
 * @package YUZ_Translation
 */
defined('ABSPATH') or exit;

if (!function_exists('yuz_locale_to_flag_code')) {
    function yuz_locale_to_flag_code(string $locale): string {
        $normalized = strtolower(str_replace('_', '-', $locale));
        if (strpos($normalized, '-') !== false) {
            [, $country] = explode('-', $normalized, 2);
            return $country;
        }
        static $lang_to_flag = [
            'en' => 'gb',
            'fr' => 'fr',
            'es' => 'es',
            'de' => 'de',
            'it' => 'it',
            'pt' => 'pt',
            'ar' => 'sa',
            'nl' => 'nl',
            'pl' => 'pl',
            'ru' => 'ru',
            'ja' => 'jp',
            'zh' => 'cn',
        ];
        return $lang_to_flag[$normalized] ?? $normalized;
    }
}

if (!function_exists('yuz_flag_url')) {
    function yuz_flag_url(string $locale_or_code): string {
        $code = strtolower(trim($locale_or_code));
        if ($code === '') {
            $code = 'xx';
        }

        if (strpos($code, '-') !== false || strpos($code, '_') !== false) {
            $code = yuz_locale_to_flag_code($code);
        }

        $flags_url = defined('YUZ_TRA_FLAGS_URL')
            ? trailingslashit(YUZ_TRA_FLAGS_URL)
            : plugins_url('assets/flags/', YUZ_TRA_PLUGIN_FILE);

        $flags_dir = defined('YUZ_TRA_ASSETS_DIR')
            ? trailingslashit(rtrim(YUZ_TRA_ASSETS_DIR, '/')) . 'flags/'
            : plugin_dir_path(YUZ_TRA_PLUGIN_FILE) . 'assets/flags/';

        $code = preg_replace('/[^a-z]/', '', $code);
        if ($code === '') {
            $code = 'xx';
        }

        $candidates = [$code . '.svg', $code . '.png'];
        foreach ($candidates as $file) {
            if (file_exists($flags_dir . $file)) {
                return $flags_url . $file;
            }
        }

        return $flags_url . 'xx.svg';
    }
}

// Ensure variables are set with defaults
$settings = isset($settings) ? $settings : get_option('yuz_tra_switcher', []); // Defaults from option
$format = isset($format) ? $format : (
    isset($settings['shortcode_format']) ? esc_attr($settings['shortcode_format']) :
    (isset($settings['yuz_shortcode_format']) ? esc_attr($settings['yuz_shortcode_format']) : 'flags-full-names')
);
$theme = isset($theme) ? esc_attr($theme) : (
    isset($settings['floating_theme']) ? esc_attr($settings['floating_theme']) :
    (isset($settings['yuz_floating_theme']) ? esc_attr($settings['yuz_floating_theme']) : 'dark')
);
$position = isset($position) ? esc_attr($position) : '';
$show_poweredby = isset($show_poweredby) ? (bool)$show_poweredby : (!empty($settings['show_poweredby']) || !empty($settings['yuz_show_poweredby']));
$current_lang = isset($current_lang) ? esc_attr($current_lang) : get_locale();
$mode = isset($mode) ? esc_attr($mode) : 'shortcode';
$use_native_name = isset($use_native_name) ? (bool)$use_native_name : !empty(get_option('yuz_tra_settings', [])['native_language_name']);
$languages = isset($languages) ? $languages : [];
$translated_strings = isset($translated_strings) ? $translated_strings : [
    'current_lang_label' => esc_html__('Current language: %s, click to change', 'yuz_translation'),
    'switch_to_label'    => esc_html__('Switch to %s', 'yuz_translation'),
    'powered_by'         => esc_html__('Powered by', 'yuz_translation'),
    'powered_by_yuzurz'  => esc_html__('YoUZurz', 'yuz_translation'),
];
$current_url_for_switcher = isset($current_url_for_switcher) ? (string) $current_url_for_switcher : '';
if ($current_url_for_switcher === '' && isset($this) && isset($this->url_converter) && method_exists($this->url_converter, 'cur_page_url')) {
    $current_url_for_switcher = (string) $this->url_converter->cur_page_url();
}

if ($current_url_for_switcher !== '') {
    $current_url_for_switcher = remove_query_arg(
        ['yuz-edit-translation', 'yuz-edit-translation-url'],
        $current_url_for_switcher
    );
}

// Soft fallback: build a minimal language list from options if DI didn't inject languages
if (empty($languages)) {
    // Parse helper: normalize option values into an array of strings
    $to_array = function($val) {
        if (is_array($val)) {
            return array_values(array_filter($val, function($v){ return is_string($v) && $v !== ''; }));
        }
        if (is_string($val)) {
            $trim = trim($val);
            if ($trim === '' || $trim === '[]') { return []; }
            if ($trim[0] === '[') {
                $decoded = json_decode($trim, true);
                return is_array($decoded) ? array_values(array_filter($decoded)) : [];
            }
            if (strpos($trim, ',') !== false) {
                return array_values(array_filter(array_map('trim', explode(',', $trim))));
            }
            return [$trim];
        }
        return [];
    };

    $general = get_option('yuz_tra_general', []);
    $source  = isset($general['yuz_tra_source_language']) ? (string)$general['yuz_tra_source_language'] : '';
    $default = isset($general['yuz_tra_default_language']) ? (string)$general['yuz_tra_default_language'] : '';
    // Handle multiple historical keys that may contain the list
    $trans   = [];
    foreach ([
        'yuz_tra_translatable_languages',
        'yuz_translatable_languages',
        'yuz_translatable_languages[]',
        'yuz_tra_translatable_languages[]'
    ] as $k) {
        if (isset($general[$k])) {
            $trans = $to_array($general[$k]);
            if (!empty($trans)) { break; }
        }
    }

    // Fallback to current site locale if nothing configured
    $site_locale = function_exists('get_locale') ? (string) get_locale() : '';
    if ($source === '') { $source = $default ?: $site_locale; }
    if ($default === '') { $default = $source ?: $site_locale; }

    // Compose a compact ordered list: put current first, ensure uniqueness
    $ordered_codes = array_values(array_unique(array_filter(array_merge([
        $current_lang,
        $default,
        $source,
    ], $trans), function($c){ return is_string($c) && $c !== ''; })));

    // Minimal language value object for rendering in this partial only
    $mk_lang = function($code) use ($use_native_name) {
        $label = function_exists('yuz_lang_label') ? yuz_lang_label($code) : $code; // friendly from DB if available
        $native = $label;
        return new class($code, $label, $native) {
            private $code; private $name; private $native;
            public function __construct($code, $name, $native){ $this->code=$code; $this->name=$name; $this->native=$native; }
            public function get_code(){ return $this->code; }
            public function get_name(){ return $this->name; }
            public function get_native_name(){ return $this->native; }
        };
    };

    $languages = array_map($mk_lang, $ordered_codes);

    // If still empty, give up quietly (keeps previous behavior in worst case)
    if (empty($languages)) {
        return;
    }
}

// Find current language object with fallback
$current_lang_obj = array_reduce($languages, function($carry, $lang) use ($current_lang) {
    return $lang->get_code() === $current_lang ? $lang : $carry;
}, $languages[0] ?? null);

if (!$current_lang_obj) {
    return; // Exit if no valid current language object
}

$current_lang_normalized = strtolower(str_replace('-', '_', (string) $current_lang));
$resolve_display_label = static function ($lang, bool $force_native, string $active_code): string {
    if (!is_object($lang)) {
        return '';
    }

    $code = method_exists($lang, 'get_code') ? (string) $lang->get_code() : '';
    $name = method_exists($lang, 'get_name') ? trim((string) $lang->get_name()) : '';
    $native = method_exists($lang, 'get_native_name') ? trim((string) $lang->get_native_name()) : '';

    if ($force_native && $native !== '') {
        return $native;
    }

    $target_code = strtolower(str_replace('-', '_', $code));
    if ($native !== '' && $active_code !== '' && $target_code === $active_code) {
        return $native;
    }

    if ($name !== '') {
        return $name;
    }

    if ($native !== '') {
        return $native;
    }

    return $code;
};
?>
<div
  class="yuz-language-switcher yuz-theme-<?php echo esc_attr($theme); ?> <?php echo ($position && ($mode === 'floating' || $mode === 'shortcode')) ? 'yuz-position-' . esc_attr($position) : ''; ?>"
  id="<?php echo $mode === 'floating' ? 'yuz-floating-switcher' : ($mode === 'shortcode' ? 'yuz-language-switcher-shortcode' : ''); ?>"
  data-key="yuz-language-switcher-<?php echo esc_attr($mode); ?>"
  data-switcher-mode="<?php echo esc_attr($mode); ?>"
  data-auto-flip="1"
  data-align="auto"
  data-ssr="1"
  aria-label="<?php esc_attr_e('Language switcher', 'yuz_translation'); ?>"
>
  <button
    class="yuz-current-lang"
    aria-expanded="false"
    aria-label="<?php echo esc_attr(sprintf($translated_strings['current_lang_label'], $resolve_display_label($current_lang_obj, $use_native_name, $current_lang_normalized))); ?>"
  >
    <?php
      // OPTION A (PHP/SSR) : helpers centralisés (Assets)
      $code_current          = $current_lang_obj->get_code();
      $flag_url_current      = esc_url( yuz_flag_url( $code_current ) );
      $fallback_text_current = strtoupper( yuz_locale_to_flag_code( $code_current ) );
      $current_label         = $resolve_display_label($current_lang_obj, $use_native_name, $current_lang_normalized);

      // Formats supportés:
      //  - 'only-flags'            → drapeau seul
      //  - 'flags-full-names'      → drapeau + nom complet
      //  - 'flags-short-names'     → drapeau + code court
      //  - 'full-names'            → nom complet uniquement
      //  - 'short-names'           → code court uniquement
      $has_flags = strpos($format, 'flags') !== false || $format === 'only-flags';
      $want_full = strpos($format, 'full') !== false;
      $want_short= strpos($format, 'short') !== false;

      if ($has_flags && $format === 'only-flags') {
        $display = '<img class="yuz-flag" src="' . $flag_url_current . '" alt="' . esc_attr($current_label) . '" title="' . esc_attr($current_label) . '" width="18" height="12" data-lang-code="' . esc_attr($code_current) . '" data-fallback-text="' . esc_attr($fallback_text_current) . '" />';
      } elseif ($has_flags && ($want_full || $want_short)) {
        $right = $want_short ? esc_html($current_lang_obj->get_code()) : esc_html($current_label);
        $display = '<span class="yuz-flagline"><img class="yuz-flag" src="' . $flag_url_current . '" alt="' . esc_attr($current_label) . '" title="' . esc_attr($current_label) . '" width="18" height="12" data-lang-code="' . esc_attr($code_current) . '" data-fallback-text="' . esc_attr($fallback_text_current) . '" /><span class="yuz-flagline-text">' . $right . '</span></span>';
      } else {
        $display = $want_short ? esc_html($current_lang_obj->get_code()) : esc_html($current_label);
      }
      echo $display;
    ?>
    <span class="yuz-arrow">▼</span>
  </button>

  <ul class="yuz-language-dropdown" data-dropdown>
    <?php foreach ($languages as $lang): ?>
      <?php
        // OPTION A : helpers centralisés
        $code_item           = $lang->get_code();
        $flag_url_item       = esc_url( yuz_flag_url( $code_item ) );
        $fallback_text_item  = strtoupper( yuz_locale_to_flag_code( $code_item ) );
        $label_item          = $resolve_display_label($lang, $use_native_name, $current_lang_normalized);
      ?>
      <li>
        <?php
            $target_lang_code = $lang->get_code();
            $url_context = [
                'source'        => 'switcher',
                'mode'          => $mode,
                'current_lang'  => $current_lang,
                'target_lang'   => $target_lang_code,
                'position'      => $position,
                'format'        => $format,
            ];
            $target_url_raw = $this->url_converter->get_url_for_language($target_lang_code, $current_url_for_switcher, $url_context);
            $target_url     = remove_query_arg(['yuz-edit-translation', 'yuz-edit-translation-url'], $target_url_raw);
            $this->log('debug', 'Switcher link generated', [
                'mode'          => $mode,
                'current_lang'  => $current_lang,
                'target_lang'   => $target_lang_code,
                'current_url'   => $current_url_for_switcher,
                'target_url_raw'=> $target_url_raw,
                'target_url'    => $target_url,
            ]);
            if (!headers_sent()) {
                header('x-yuz-switch-target: ' . $target_lang_code);
                header('x-yuz-switch-url: ' . $target_url);
            }
        ?>
        <a
          href="<?php echo esc_url($target_url); ?>"
          data-lang-code="<?php echo esc_attr($lang->get_code()); ?>"
          aria-label="<?php echo esc_attr(sprintf($translated_strings['switch_to_label'], $label_item)); ?>"
        >
          <?php
            if ($format === 'only-flags') {
              $display_item = '<img class="yuz-flag" src="' . $flag_url_item . '" alt="' . esc_attr($label_item) . '" title="' . esc_attr($label_item) . '" width="18" height="12" data-lang-code="' . esc_attr($code_item) . '" data-fallback-text="' . esc_attr($fallback_text_item) . '" />';
            } elseif (strpos($format, 'flags') !== false && (strpos($format,'full')!==false || strpos($format,'short')!==false)) {
              $right = (strpos($format,'short')!==false) ? esc_html($lang->get_code()) : esc_html($label_item);
              $display_item = '<span class="yuz-flagline"><img class="yuz-flag" src="' . $flag_url_item . '" alt="' . esc_attr($label_item) . '" title="' . esc_attr($label_item) . '" width="18" height="12" data-lang-code="' . esc_attr($code_item) . '" data-fallback-text="' . esc_attr($fallback_text_item) . '" /><span class="yuz-flagline-text">' . $right . '</span></span>';
            } else {
              $display_item = (strpos($format, 'short') !== false ? esc_html($lang->get_code()) : esc_html($label_item));
            }
            echo $display_item;
          ?>
        </a>
      </li>
    <?php endforeach; ?>

    <?php if ($show_poweredby): ?>
      <li class="yuz-powered-by">
        <?php echo esc_html($translated_strings['powered_by']); ?>
        <a href="https://yuzurz.com" target="_blank" rel="nofollow noopener noreferrer"><?php echo esc_html($translated_strings['powered_by_yuzurz']); ?></a>
      </li>
    <?php endif; ?>
  </ul>
</div>

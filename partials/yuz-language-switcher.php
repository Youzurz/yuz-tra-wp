<?php
/**
 * YUZ Language Switcher Partial
 * Renders the language switcher UI for shortcode, menu, or floating modes.
 *
 * @package YUZ_Translation
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

if (!function_exists('yuztra_locale_to_flag_code')) {
    function yuztra_locale_to_flag_code(string $yuztra_locale): string {
        $yuztra_normalized = strtolower(str_replace('_', '-', $yuztra_locale));
        if (strpos($yuztra_normalized, '-') !== false) {
            [, $yuztra_country] = explode('-', $yuztra_normalized, 2);
            return $yuztra_country;
        }
        static $yuztra_lang_to_flag = [
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
        return $yuztra_lang_to_flag[$yuztra_normalized] ?? $yuztra_normalized;
    }
}

if (!function_exists('yuztra_flag_url')) {
    function yuztra_flag_url(string $yuztra_locale_or_code): string {
        $yuztra_code = strtolower(trim($yuztra_locale_or_code));
        if ($yuztra_code === '') {
            $yuztra_code = 'xx';
        }

        if (strpos($yuztra_code, '-') !== false || strpos($yuztra_code, '_') !== false) {
            $yuztra_code = yuztra_locale_to_flag_code($yuztra_code);
        }

        $yuztra_flags_url = defined('YUZTRA_FLAGS_URL')
            ? trailingslashit(YUZTRA_FLAGS_URL)
            : plugins_url('assets/flags/', YUZTRA_PLUGIN_FILE);

        $yuztra_flags_dir = defined('YUZTRA_ASSETS_DIR')
            ? trailingslashit(rtrim(YUZTRA_ASSETS_DIR, '/')) . 'flags/'
            : plugin_dir_path(YUZTRA_PLUGIN_FILE) . 'assets/flags/';

        $yuztra_code = preg_replace('/[^a-z]/', '', $yuztra_code);
        if ($yuztra_code === '') {
            $yuztra_code = 'xx';
        }

        $yuztra_candidates = [$yuztra_code . '.svg', $yuztra_code . '.png'];
        foreach ($yuztra_candidates as $yuztra_file) {
            if (file_exists($yuztra_flags_dir . $yuztra_file)) {
                return $yuztra_flags_url . $yuztra_file;
            }
        }

        return $yuztra_flags_url . 'xx.svg';
    }
}

// Preserve the method-scope values injected by the renderer while keeping every
// variable declared by this partial distinctively prefixed.
$yuztra_scope = get_defined_vars();

// Ensure variables are set with defaults
$yuztra_settings = $yuztra_scope['settings'] ?? get_option('yuztra_switcher', []); // Defaults from option
$yuztra_format = $yuztra_scope['format'] ?? (
    isset($yuztra_settings['shortcode_format']) ? esc_attr($yuztra_settings['shortcode_format']) :
    (isset($yuztra_settings['yuztra_shortcode_format']) ? esc_attr($yuztra_settings['yuztra_shortcode_format']) : 'flags-full-names')
);
$yuztra_format = esc_attr($yuztra_format);
$yuztra_theme = $yuztra_scope['theme'] ?? (
    isset($yuztra_settings['floating_theme']) ? esc_attr($yuztra_settings['floating_theme']) :
    (isset($yuztra_settings['yuztra_floating_theme']) ? esc_attr($yuztra_settings['yuztra_floating_theme']) : 'dark')
);
$yuztra_theme = esc_attr($yuztra_theme);
$yuztra_position = isset($yuztra_scope['position']) ? esc_attr($yuztra_scope['position']) : '';
$yuztra_show_poweredby = isset($yuztra_scope['show_poweredby']) ? (bool) $yuztra_scope['show_poweredby'] : (!empty($yuztra_settings['show_poweredby']) || !empty($yuztra_settings['yuztra_show_poweredby']));
$yuztra_show_poweredby = $yuztra_show_poweredby && !empty(get_option('yuztra_sw_settings', [])['show_poweredby']);
$yuztra_current_lang = isset($yuztra_scope['current_lang']) ? esc_attr($yuztra_scope['current_lang']) : get_locale();
$yuztra_mode = isset($yuztra_scope['mode']) ? esc_attr($yuztra_scope['mode']) : 'shortcode';
$yuztra_use_native_name = isset($yuztra_scope['use_native_name']) ? (bool) $yuztra_scope['use_native_name'] : !empty(get_option('yuztra_settings', [])['native_language_name']);
$yuztra_languages = $yuztra_scope['languages'] ?? [];
$yuztra_translated_strings = $yuztra_scope['translated_strings'] ?? [
    /* translators: %s: current language name or code. */
    'current_lang_label' => esc_html__('Current language: %s, click to change', 'yuz-tra'),
    /* translators: %s: target language name or code. */
    'switch_to_label'    => esc_html__('Switch to %s', 'yuz-tra'),
    'powered_by'         => esc_html__('Powered by', 'yuz-tra'),
    'powered_by_yuzurz'  => esc_html__('YoUZurz', 'yuz-tra'),
];
$yuztra_current_url_for_switcher = isset($yuztra_scope['current_url_for_switcher']) ? (string) $yuztra_scope['current_url_for_switcher'] : '';
$yuztra_switcher_url_converter = $yuztra_scope['switcher_url_converter'] ?? (
    isset($this) && isset($this->url_converter)
        ? $this->url_converter
        : new YUZTRA_Url_Converter(YUZTRA_Services::settings())
);
if ($yuztra_current_url_for_switcher === '' && method_exists($yuztra_switcher_url_converter, 'cur_page_url')) {
    $yuztra_current_url_for_switcher = (string) $yuztra_switcher_url_converter->cur_page_url();
}

if ($yuztra_current_url_for_switcher !== '') {
    $yuztra_current_url_for_switcher = remove_query_arg(
        ['yuz-edit-translation', 'yuz-edit-translation-url'],
        $yuztra_current_url_for_switcher
    );
}

// Soft fallback: build a minimal language list from options if DI didn't inject languages
if (empty($yuztra_languages)) {
    // Parse helper: normalize option values into an array of strings
    $yuztra_to_array = function($yuztra_val) {
        if (is_array($yuztra_val)) {
            return array_values(array_filter($yuztra_val, function($yuztra_v){ return is_string($yuztra_v) && $yuztra_v !== ''; }));
        }
        if (is_string($yuztra_val)) {
            $yuztra_trim = trim($yuztra_val);
            if ($yuztra_trim === '' || $yuztra_trim === '[]') { return []; }
            if ($yuztra_trim[0] === '[') {
                $yuztra_decoded = json_decode($yuztra_trim, true);
                return is_array($yuztra_decoded) ? array_values(array_filter($yuztra_decoded)) : [];
            }
            if (strpos($yuztra_trim, ',') !== false) {
                return array_values(array_filter(array_map('trim', explode(',', $yuztra_trim))));
            }
            return [$yuztra_trim];
        }
        return [];
    };

    $yuztra_general = get_option('yuztra_general', []);
    $yuztra_source  = isset($yuztra_general['yuztra_source_language']) ? (string)$yuztra_general['yuztra_source_language'] : '';
    $yuztra_default = isset($yuztra_general['yuztra_default_language']) ? (string)$yuztra_general['yuztra_default_language'] : '';
    // Handle multiple historical keys that may contain the list
    $yuztra_trans   = [];
    foreach ([
        'yuztra_translatable_languages',
        'yuztra_translatable_languages',
        'yuztra_translatable_languages[]',
        'yuztra_translatable_languages[]'
    ] as $yuztra_k) {
        if (isset($yuztra_general[$yuztra_k])) {
            $yuztra_trans = $yuztra_to_array($yuztra_general[$yuztra_k]);
            if (!empty($yuztra_trans)) { break; }
        }
    }

    // Fallback to current site locale if nothing configured
    $yuztra_site_locale = function_exists('get_locale') ? (string) get_locale() : '';
    if ($yuztra_source === '') { $yuztra_source = $yuztra_default ?: $yuztra_site_locale; }
    if ($yuztra_default === '') { $yuztra_default = $yuztra_source ?: $yuztra_site_locale; }

    // Compose a compact ordered list: put current first, ensure uniqueness
    $yuztra_ordered_codes = array_values(array_unique(array_filter(array_merge([
        $yuztra_current_lang,
        $yuztra_default,
        $yuztra_source,
    ], $yuztra_trans), function($yuztra_c){ return is_string($yuztra_c) && $yuztra_c !== ''; })));

    // Minimal language value object for rendering in this partial only
    $yuztra_mk_lang = function($yuztra_code) use ($yuztra_use_native_name) {
        $yuztra_label = function_exists('yuztra_lang_label') ? yuztra_lang_label($yuztra_code) : $yuztra_code; // friendly from DB if available
        $yuztra_native = $yuztra_label;
        return new class($yuztra_code, $yuztra_label, $yuztra_native) {
            private $code; private $name; private $native;
            public function __construct($yuztra_code, $yuztra_name, $yuztra_native){ $this->code=$yuztra_code; $this->name=$yuztra_name; $this->native=$yuztra_native; }
            public function get_code(){ return $this->code; }
            public function get_name(){ return $this->name; }
            public function get_native_name(){ return $this->native; }
        };
    };

    $yuztra_languages = array_map($yuztra_mk_lang, $yuztra_ordered_codes);

    // If still empty, give up quietly (keeps previous behavior in worst case)
    if (empty($yuztra_languages)) {
        return;
    }
}

// Find current language object with fallback
$yuztra_current_lang_obj = array_reduce($yuztra_languages, function($yuztra_carry, $yuztra_lang) use ($yuztra_current_lang) {
    return $yuztra_lang->get_code() === $yuztra_current_lang ? $yuztra_lang : $yuztra_carry;
}, $yuztra_languages[0] ?? null);

if (!$yuztra_current_lang_obj) {
    return; // Exit if no valid current language object
}

$yuztra_current_lang_normalized = strtolower(str_replace('-', '_', (string) $yuztra_current_lang));
$yuztra_resolve_display_label = static function ($yuztra_lang, bool $yuztra_force_native, string $yuztra_active_code): string {
    if (!is_object($yuztra_lang)) {
        return '';
    }

    $yuztra_code = method_exists($yuztra_lang, 'get_code') ? (string) $yuztra_lang->get_code() : '';
    $yuztra_name = method_exists($yuztra_lang, 'get_name') ? trim((string) $yuztra_lang->get_name()) : '';
    $yuztra_native = method_exists($yuztra_lang, 'get_native_name') ? trim((string) $yuztra_lang->get_native_name()) : '';

    if ($yuztra_force_native && $yuztra_native !== '') {
        return $yuztra_native;
    }

    $yuztra_target_code = strtolower(str_replace('-', '_', $yuztra_code));
    if ($yuztra_native !== '' && $yuztra_active_code !== '' && $yuztra_target_code === $yuztra_active_code) {
        return $yuztra_native;
    }

    if ($yuztra_name !== '') {
        return $yuztra_name;
    }

    if ($yuztra_native !== '') {
        return $yuztra_native;
    }

    return $yuztra_code;
};
?>
<div
  class="yuz-language-switcher yuz-theme-<?php echo esc_attr($yuztra_theme); ?> <?php echo ($yuztra_position && ($yuztra_mode === 'floating' || $yuztra_mode === 'shortcode')) ? 'yuz-position-' . esc_attr($yuztra_position) : ''; ?>"
  id="<?php echo $yuztra_mode === 'floating' ? 'yuz-floating-switcher' : ($yuztra_mode === 'shortcode' ? 'yuz-language-switcher-shortcode' : ''); ?>"
  data-key="yuz-language-switcher-<?php echo esc_attr($yuztra_mode); ?>"
  data-switcher-mode="<?php echo esc_attr($yuztra_mode); ?>"
  data-auto-flip="1"
  data-align="auto"
  data-ssr="1"
  aria-label="<?php esc_attr_e('Language switcher', 'yuz-tra'); ?>"
>
  <button
    class="yuz-current-lang"
    aria-expanded="false"
    aria-label="<?php echo esc_attr(sprintf($yuztra_translated_strings['current_lang_label'], $yuztra_resolve_display_label($yuztra_current_lang_obj, $yuztra_use_native_name, $yuztra_current_lang_normalized))); ?>"
  >
    <?php
      // OPTION A (PHP/SSR) : helpers centralisés (Assets)
      $yuztra_code_current          = $yuztra_current_lang_obj->get_code();
      $yuztra_flag_url_current      = esc_url( yuztra_flag_url( $yuztra_code_current ) );
      $yuztra_fallback_text_current = strtoupper( yuztra_locale_to_flag_code( $yuztra_code_current ) );
      $yuztra_current_label         = $yuztra_resolve_display_label($yuztra_current_lang_obj, $yuztra_use_native_name, $yuztra_current_lang_normalized);

      // Formats supportés:
      //  - 'only-flags'            → drapeau seul
      //  - 'flags-full-names'      → drapeau + nom complet
      //  - 'flags-short-names'     → drapeau + code court
      //  - 'full-names'            → nom complet uniquement
      //  - 'short-names'           → code court uniquement
      $yuztra_has_flags = strpos($yuztra_format, 'flags') !== false || $yuztra_format === 'only-flags';
      $yuztra_want_full = strpos($yuztra_format, 'full') !== false;
      $yuztra_want_short= strpos($yuztra_format, 'short') !== false;

      if ($yuztra_has_flags && $yuztra_format === 'only-flags') {
        $yuztra_display = '<img class="yuz-flag" src="' . $yuztra_flag_url_current . '" alt="' . esc_attr($yuztra_current_label) . '" title="' . esc_attr($yuztra_current_label) . '" width="18" height="12" data-lang-code="' . esc_attr($yuztra_code_current) . '" data-fallback-text="' . esc_attr($yuztra_fallback_text_current) . '" />';
      } elseif ($yuztra_has_flags && ($yuztra_want_full || $yuztra_want_short)) {
        $yuztra_right = $yuztra_want_short ? esc_html($yuztra_current_lang_obj->get_code()) : esc_html($yuztra_current_label);
        $yuztra_display = '<span class="yuz-flagline"><img class="yuz-flag" src="' . $yuztra_flag_url_current . '" alt="' . esc_attr($yuztra_current_label) . '" title="' . esc_attr($yuztra_current_label) . '" width="18" height="12" data-lang-code="' . esc_attr($yuztra_code_current) . '" data-fallback-text="' . esc_attr($yuztra_fallback_text_current) . '" /><span class="yuz-flagline-text">' . $yuztra_right . '</span></span>';
      } else {
        $yuztra_display = $yuztra_want_short ? esc_html($yuztra_current_lang_obj->get_code()) : esc_html($yuztra_current_label);
      }
      echo wp_kses_post( $yuztra_display );
    ?>
    <span class="yuz-arrow">▼</span>
  </button>

  <ul class="yuz-language-dropdown" data-dropdown>
    <?php foreach ($yuztra_languages as $yuztra_lang): ?>
      <?php
        // OPTION A : helpers centralisés
        $yuztra_code_item           = $yuztra_lang->get_code();
        $yuztra_flag_url_item       = esc_url( yuztra_flag_url( $yuztra_code_item ) );
        $yuztra_fallback_text_item  = strtoupper( yuztra_locale_to_flag_code( $yuztra_code_item ) );
        $yuztra_label_item          = $yuztra_resolve_display_label($yuztra_lang, $yuztra_use_native_name, $yuztra_current_lang_normalized);
      ?>
      <li>
        <?php
            $yuztra_target_lang_code = $yuztra_lang->get_code();
            $yuztra_url_context = [
                'source'        => 'switcher',
                'mode'          => $yuztra_mode,
                'current_lang'  => $yuztra_current_lang,
                'target_lang'   => $yuztra_target_lang_code,
                'position'      => $yuztra_position,
                'format'        => $yuztra_format,
            ];
            $yuztra_target_url_raw = $yuztra_switcher_url_converter->get_url_for_language($yuztra_target_lang_code, $yuztra_current_url_for_switcher, $yuztra_url_context);
            $yuztra_target_url     = remove_query_arg(['yuz-edit-translation', 'yuz-edit-translation-url'], $yuztra_target_url_raw);
            if (isset($this)) $this->log('debug', 'Switcher link generated', [
                'mode'          => $yuztra_mode,
                'current_lang'  => $yuztra_current_lang,
                'target_lang'   => $yuztra_target_lang_code,
                'current_url'   => $yuztra_current_url_for_switcher,
                'target_url_raw'=> $yuztra_target_url_raw,
                'target_url'    => $yuztra_target_url,
            ]);
            if (!headers_sent()) {
                header('x-yuz-switch-target: ' . $yuztra_target_lang_code);
                header('x-yuz-switch-url: ' . $yuztra_target_url);
            }
        ?>
        <a
          href="<?php echo esc_url($yuztra_target_url); ?>"
          data-lang-code="<?php echo esc_attr($yuztra_lang->get_code()); ?>"
          aria-label="<?php echo esc_attr(sprintf($yuztra_translated_strings['switch_to_label'], $yuztra_label_item)); ?>"
        >
          <?php
            if ($yuztra_format === 'only-flags') {
              $yuztra_display_item = '<img class="yuz-flag" src="' . $yuztra_flag_url_item . '" alt="' . esc_attr($yuztra_label_item) . '" title="' . esc_attr($yuztra_label_item) . '" width="18" height="12" data-lang-code="' . esc_attr($yuztra_code_item) . '" data-fallback-text="' . esc_attr($yuztra_fallback_text_item) . '" />';
            } elseif (strpos($yuztra_format, 'flags') !== false && (strpos($yuztra_format,'full')!==false || strpos($yuztra_format,'short')!==false)) {
              $yuztra_right = (strpos($yuztra_format,'short')!==false) ? esc_html($yuztra_lang->get_code()) : esc_html($yuztra_label_item);
              $yuztra_display_item = '<span class="yuz-flagline"><img class="yuz-flag" src="' . $yuztra_flag_url_item . '" alt="' . esc_attr($yuztra_label_item) . '" title="' . esc_attr($yuztra_label_item) . '" width="18" height="12" data-lang-code="' . esc_attr($yuztra_code_item) . '" data-fallback-text="' . esc_attr($yuztra_fallback_text_item) . '" /><span class="yuz-flagline-text">' . $yuztra_right . '</span></span>';
            } else {
              $yuztra_display_item = (strpos($yuztra_format, 'short') !== false ? esc_html($yuztra_lang->get_code()) : esc_html($yuztra_label_item));
            }
            echo wp_kses_post( $yuztra_display_item );
          ?>
        </a>
      </li>
    <?php endforeach; ?>

    <?php if ($yuztra_show_poweredby): ?>
      <li class="yuz-powered-by">
        <?php echo esc_html($yuztra_translated_strings['powered_by']); ?>
        <a href="https://youzurz.com" target="_blank" rel="nofollow noopener noreferrer"><?php echo esc_html($yuztra_translated_strings['powered_by_yuzurz']); ?></a>
      </li>
    <?php endif; ?>
  </ul>
</div>

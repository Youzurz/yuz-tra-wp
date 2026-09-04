<?php
defined('ABSPATH') || exit;

/**
 * Util: noms de langue simples (à raffiner si besoin)
 */
function yuz_lang_names(array $codes): array {
    $out = [];
    $index = function_exists('yuz_get_languages_index') ? (array) yuz_get_languages_index() : [];

    foreach ($codes as $c) {
        $code = (string) $c;
        if ($code === '') {
            continue;
        }

        // Reuse the canonical language index so the switcher shows the stored native label
        // (for example `Français`) instead of recomputing an English fallback.
        $row = isset($index[$code]) && is_array($index[$code]) ? $index[$code] : [];
        $label = '';
        if (!empty($row['native'])) {
            $label = (string) $row['native'];
        } elseif (!empty($row['name'])) {
            $label = (string) $row['name'];
        }

        if ($label !== '') {
            $out[$code] = $label;
        } elseif (function_exists('yuz_lang_label')) {
            $out[$code] = yuz_lang_label($code);
        } elseif (function_exists('yuz_human_label_from_locale')) {
            $fallback = yuz_human_label_from_locale($code);
            $out[$code] = $fallback !== '' ? $fallback : $code;
        } else {
            $out[$code] = $code;
        }
    }
    return $out;
}

/**
 * SWITCHER: fournit window.yuzSW (ajax_url, langues, flags…)
 * Le JS du switcher attend au moins: ajax_url, languages, flags_path, flags_file_name
 */
add_filter('yuz/assets/payload/switcher', function($payload){
    $cfg      = get_option('yuz_tra_settings', []);
    $langs    = is_array($cfg) && !empty($cfg['translation-languages']) ? (array)$cfg['translation-languages'] : ['fr_FR','en_US'];
    $flags_dir = plugin_dir_path(defined('YUZ_TRA_PLUGIN_FILE') ? YUZ_TRA_PLUGIN_FILE : __FILE__) . 'assets/flags/';
    $flags_url = plugins_url('assets/flags/', defined('YUZ_TRA_PLUGIN_FILE') ? YUZ_TRA_PLUGIN_FILE : __FILE__);

    $flags_map = [];
    foreach ($langs as $code) {
        $cc = '';
        if (preg_match('~^[a-z]{2}[-_][a-z]{2}$~i', $code)) {
            $parts = preg_split('~[-_]~', $code);
            $cc = strtolower($parts[1] ?? '');
        } else {
            $cc = strtolower(substr((string)$code, -2));
        }
        if ($cc) {
            foreach (['svg','png','jpg','jpeg'] as $ext) {
                $fn = $cc . '.' . $ext;
                if (file_exists($flags_dir . $fn)) { $flags_map[$code] = $fn; break; }
            }
        }
        if (empty($flags_map[$code]) && file_exists($flags_dir . 'flag.svg')) {
            $flags_map[$code] = 'flag.svg';
        }
    }

    return [
        'ajax_url' => admin_url('admin-ajax.php', 'relative'),

        // Nonces normalisés (CIA)
        'nonces' => [
            'yuz_tra_sw_get_settings'    => wp_create_nonce('yuz_tra_nonce'),
            'yuz_tra_sw_upd_settings'    => wp_create_nonce('yuz_con_nonce'),
            'yuz_tra_sw_switch_language' => wp_create_nonce('yuz_tra_nonce'),
            'yuz_tra_sw_resolve_url'     => wp_create_nonce('yuz_tra_nonce'),
        ],

        // Données fonctionnelles
        'languages'         => $langs,
        'translation_langs' => $langs,                 // alias compat
        'langNames'         => yuz_lang_names($langs),
        'language_names'    => yuz_lang_names($langs), // alias compat

        'endpoints' => [
            'resolve_url' => 'yuz_tra_sw_resolve_url',
        ],

        'flags_path'        => trailingslashit($flags_url),
        'flags_file_name'   => $flags_map,
        'default_language'  => get_locale(),

        // UI par défaut (surcharge possible ailleurs si besoin)
        'switcher' => [
            'mode'     => 'floating',
            'position' => 'right-bottom',
            'style'    => 'flags+code',
        ],
    ];
});

/**
 * ÉDITEUR: fournit window.yuzTE (ajax_url, nonces, site_settings…)
 * Le JS attend: ajax_url, nonces{actions…}, site_settings{url_to_load,use_subdirectory,force_lang_in_links,slugs}
 */
add_filter('yuz/assets/payload/translation-editor', function($payload){
    // Slugs par défaut (à reprendre depuis vos réglages)
    $slugs = [
        'fr_FR' => 'fr',
        'en_US' => 'en',
    ];

    // Nonces par groupe d’usage (CIA): tra/int/hvy/del/api
    $nonces = [
        // Lecture / fetch / search
        'yuz_tra_tm_get_translations' => wp_create_nonce('yuz_tra_nonce'),
        // Écriture / modifications
        'yuz_tra_te_upd_manual'       => wp_create_nonce('yuz_int_nonce'),
        'yuz_tra_te_upd_publish'      => wp_create_nonce('yuz_int_nonce'),
        'yuz_tra_te_cre_translation'  => wp_create_nonce('yuz_int_nonce'),
        'yuz_save_translation'        => wp_create_nonce('yuz_tra_nonce'),
        'yuz_tra_tm_cre_page'         => wp_create_nonce('yuz_int_nonce'),
        // Suppression explicite
        'yuz_tra_tm_del_translation'  => wp_create_nonce('yuz_del_nonce'),
        'yuz_tra_delete_translation'  => wp_create_nonce('yuz_del_nonce'),
        // Tests/connexions externes
        'yuz_tra_tm_test_api'         => wp_create_nonce('yuz_api_nonce'),
        // Opérations lourdes (batch)
        'yuz_ai_batch_translate'      => wp_create_nonce('yuz_hvy_nonce'),
    ];

    return [
        'ajax_url' => admin_url('admin-ajax.php', 'relative'),
        'nonces'   => $nonces,
        'defaultLang'   => get_locale(),
        'site_settings' => [
            'url_to_load'        => home_url('/'),
            'use_subdirectory'   => true,
            'force_lang_in_links'=> true,
            'slugs'              => $slugs,
        ],
    ];
});

/**
 * STRING EDITOR (optionnel) : fournit window.yuzSE uniquement si activé.
 * Par défaut -> [] donc PAS d'objet global (voir YUZ_Assets::maybe_inject_editor_se_payload()).
 *
 * Activez via :
 *   - define('YUZ_SE_PAYLOAD', true);            // ex. dans wp-config.php (DEV only)
 *   - ou update_option('yuz_se_payload_enable', 1);
 */
add_filter('yuz/assets/editor_se_payload', function(array $payload){
    $enabled = (defined('YUZ_SE_PAYLOAD') && YUZ_SE_PAYLOAD)
            || (bool) get_option('yuz_se_payload_enable', 0);

    if (!$enabled) {
        return []; // => aucun yuzSE injecté (comportement par défaut)
    }

    // >>> ICI: données strictement spécifiques à la page "String Translation Editor"
    return [
        'pageBootstrap' => [
            'allowBulk' => false,
            'readonly'  => true,
        ],
        // ... ajoute tes clés propres à l'éditeur si besoin
    ];
}, 10, 1);

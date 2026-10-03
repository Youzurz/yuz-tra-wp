<?php
defined('ABSPATH') || exit;

/** Reviewed storage identifiers, not a recursive replacement of customer data. */
function yuztra_prefix_manifest(array $job_names = []): array {
    $names = [
        'yuz_tra_ws_settings','yuz_tra_ls_settings','yuz_tra_sw_settings','yuz_tra_ts_settings',
        'yuz_tra_at_settings','yuz_tra_av_settings','yuz_tra_ad_settings','yuz_tra_li_settings','yuz_tra_ai_settings',
        'yuz_tra_all_settings','yuz_tra_general','yuz_tra_general_settings','yuz_tra_settings',
        'yuz_tra_switcher','yuz_tra_switcher_settings','yuz_tra_language_settings','yuz_tra_site_settings',
        'yuz_tra_api_settings','yuz_tra_advanced','yuz_tra_addons','yuz_tra_licenses','yuz_tra_ai','yuz_tra_allowed_roles',
        'yuz_settings_meta','yuz_translation_site_settings','yuz_translation_config',
        'yuz_tra_default_lang','yuz_tra_source_lang','yuz_tra_enabled_languages','yuz_tra_languages',
        'yuz_tra_domain_source_languages','yuz_tra_target_language','yuz_tra_license_key','yuz_tra_license_status',
        'yuz_tra_qos','yuz_tra_log_level','yuz_tra_log_max_bytes','yuz_tra_log_max_files',
        'yuz_tra_log_rate_window','yuz_tra_log_rate_max','yuz_tra_log_ctx_maxlen','yuz_tra_log_ctx_maxkeys',
        'yuz_tra_private_log','yuz_tra_debug_probe_records','yuz_tra_legacy_diagnostics',
        'yuz_tra_string_scan','yuz_tra_string_scan_last','yuz_tra_db_version','yuz_tra_strings_schema',
        'yuz_tra_tables_ok','yuz_tra_worker_language','yuz_tra_worker_last',
        'yuz_addons_last_save','yuz_debug_mode','yuz_diagnostic_cache','yuz_disable_dynamic',
        'yuz_fix_dynamic','yuz_force_fallback_on_integrity_fail','yuz_probes_enabled',
        'yuz_rewrite_flush_version','yuz_rewrite_settings_hash','yuz_se_payload_enable',
    ];
    foreach ($job_names as $name) {
        if (!is_string($name) || !preg_match('/^yuz_tra_job_[a-f0-9-]{36}$/D', $name)) {
            throw new InvalidArgumentException('Invalid historical job option name');
        }
        $names[] = $name;
    }
    $canonical = static fn(string $name): string => preg_replace('/^yuz_(?:tra_)?/', 'yuztra_', $name);
    $mapping = static function (array $keys) use ($canonical): array {
        $result = [];
        foreach ($keys as $key) $result[$key] = $canonical($key);
        return $result;
    };
    $rules = static function (array $keys, array $parent = []) use ($canonical): array {
        return array_map(static fn($key) => ['from'=>array_merge($parent, [$key]),
            'to'=>array_merge($parent, [$canonical($key)])], $keys);
    };
    $ws = ['yuz_tra_default_language','yuz_tra_source_language','yuz_tra_translatable_languages','yuz_tra_slug','yuz_tra_code'];
    $ws_legacy = ['yuz_default_language','yuz_source_language','yuz_translatable_languages','yuz_slug','yuz_code'];
    $switch = ['yuz_shortcode_enabled','yuz_shortcode_format','yuz_menu_enabled','yuz_menu_format',
        'yuz_floating_enabled','yuz_floating_format','yuz_floating_theme','yuz_floating_position','yuz_show_poweredby'];
    $stages = [];
    foreach (['yuztra_ws_settings','yuztra_general','yuztra_general_settings'] as $option) {
        $stages[$option] = [$rules($ws), $rules($ws_legacy)];
    }
    $stages['yuztra_settings'] = [$rules($switch)];
    $sections = ['yuz_tra_general','yuz_tra_settings','yuz_tra_switcher','yuz_tra_site_settings',
        'yuz_tra_api_settings','yuz_tra_advanced','yuz_tra_addons','yuz_tra_licenses','yuz_tra_ai',
        'yuz_tra_ws_settings','yuz_tra_ls_settings','yuz_tra_sw_settings','yuz_tra_ts_settings',
        'yuz_tra_at_settings','yuz_tra_av_settings','yuz_tra_ad_settings','yuz_tra_li_settings','yuz_tra_ai_settings'];
    $stages['yuztra_all_settings'] = [$rules($sections)];
    foreach (['yuztra_general','yuztra_ws_settings'] as $section) {
        $stages['yuztra_all_settings'][] = $rules($ws, [$section]);
        $stages['yuztra_all_settings'][] = $rules($ws_legacy, [$section]);
    }
    $stages['yuztra_all_settings'][] = $rules($switch, ['yuztra_settings']);
    return [
        'options'=>$mapping(array_values(array_unique($names))), 'usermeta'=>[], 'tables'=>[],
        'option_paths'=>[], 'option_path_stages'=>$stages,
        'capabilities'=>$mapping(['yuz_translate_content','yuz_translate_strings','yuz_manage_slugs',
            'yuz_publish_translations','yuz_manage_settings','yuz_translate','yuz_translate_posts',
            'yuz_translate_publish','yuz_translate_settings','yuz_translate_core']),
        'cron_hooks'=>$mapping(['yuz_tra_batch_translate','yuz_tra_run_job','yuz_auto_translation_tick','yuz_continue_translation']),
    ];
}

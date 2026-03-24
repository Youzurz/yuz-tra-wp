<?php
/**
 * Lightweight helpers around the canonical YUZ settings API.
 *
 * These wrappers provide cache/transient utilities and expose meta data
 * while delegating storage to the canonical option buckets defined in
 * class-yuz-options-bridge.php.
 */



if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('yuz_settings_sanitize_section')) {
    function yuz_settings_sanitize_section($name, $raw = []) {
        if (defined('YUZ_DEBUG_SANITIZE') && YUZ_DEBUG_SANITIZE) {
            $logfile = rtrim(WP_CONTENT_DIR, '/\\') . '/debug-yuz-sanitize.log';
            $snapshot = [
                'canonical' => $name,
                'raw'       => $raw,
                '_POST'     => $_POST,
                'timestamp' => date('Y-m-d H:i:s'),
            ];
            file_put_contents($logfile, print_r($snapshot, true) . "\n\n", FILE_APPEND);
        }

        $canonical = yuz_settings__resolve_canonical($name);
        $value = is_array($raw) ? $raw : [];
        $defs  = yuz_settings_section_default($canonical);

        if (!function_exists('yuz_settings_registry')) {
            return array_merge($defs, $value);
        }
        $reg  = yuz_settings_registry();
        $conf = $reg[$canonical] ?? null;
        if (!$conf) {
            return array_merge($defs, $value);
        }

        if (($conf['type'] ?? '') === 'assoc' && !empty($conf['fields'])) {
            $clean = [];
            foreach ($conf['fields'] as $key => $meta) {
                $t = $meta['type'] ?? 'string';
                $v = $value[$key] ?? $defs[$key] ?? null;

                switch ($t) {
                    case 'int':
                        $v = (int) $v;
                        break;

                    case 'bool':
                        $v = !empty($v) ? 1 : 0;
                        break;

                    case 'url':
                        $v = is_string($v) ? trim($v) : '';
                        $v = preg_replace('#/translate/?$#', '', $v);
                        $v = esc_url_raw($v);
                        break;

                    case 'array_string':
                    case 'array_role':
                        $arr = is_array($v) ? $v : [];
                        $v = array_values(array_map(static function ($x) {
                            return is_scalar($x) ? sanitize_text_field((string) $x) : '';
                        }, $arr));
                        break;

                    case 'string_toggle':
                        $v = (!empty($v) && $v !== '0' && $v !== 'off') ? '1' : '';
                        break;

                    default:
                        $v = is_scalar($v) ? sanitize_text_field((string) $v) : '';
                        break;
                }

                $clean[$key] = $v;
            }

            if ($canonical === 'yuz_tra_at_settings') {
                if (empty($clean['api_provider']) && !empty($clean['api_adapter'])) {
                    $clean['api_provider'] = $clean['api_adapter'];
                }
                if (empty($clean['url_to_load']) && !empty($clean['libre_url'])) {
                    $clean['url_to_load'] = $clean['libre_url'];
                }
                foreach (['url_to_load', 'libre_url'] as $k) {
                    if (!empty($clean[$k])) {
                        $clean[$k] = preg_replace('#/translate/?$#', '', $clean[$k]);
                    }
                }
            }

            return array_merge($defs, $clean);
        }

        if (($conf['type'] ?? '') === 'list') {
            $arr = is_array($value) ? $value : [];
            return array_values($arr);
        }

        return array_merge($defs, $value);
    }
}

// ===== SAFETY SHIMS (si les helpers ne sont pas chargés) =====================
if (!function_exists('yuz_settings_registry_lookup')) {
    function yuz_settings_registry_lookup(): array { return []; }
}

if (!function_exists('yuz_settings__resolve_canonical')) {
    function yuz_settings__resolve_canonical(string $name): string {
        $lookup = yuz_settings_registry_lookup();
        if (isset($lookup[$name]['canonical'])) {
            return (string) $lookup[$name]['canonical'];
        }
        // Si le lookup n'existe pas encore, on suppose que $name est déjà canonique
        return $name;
    }
}

if (!function_exists('yuz_settings_section_default')) {
    function yuz_settings_section_default(string $name): array {
        $canonical = yuz_settings__resolve_canonical($name);
        if (!function_exists('yuz_settings_registry')) {
            return []; // pas de registre → défaut vide
        }
        $reg = yuz_settings_registry();
        if (!isset($reg[$canonical])) {
            return [];
        }
        $conf = $reg[$canonical];

        // Assoc avec fields
        if (($conf['type'] ?? '') === 'assoc' && !empty($conf['fields']) && is_array($conf['fields'])) {
            $out = [];
            foreach ($conf['fields'] as $key => $meta) {
                $out[$key] = $meta['default'] ?? null;
                // Normaliser quelques types
                $t = $meta['type'] ?? '';
                if ($t === 'bool') { $out[$key] = (int) !empty($out[$key]); }
                if ($t === 'int')  { $out[$key] = (int) ($out[$key] ?? 0); }
                if ($t === 'array_string' || $t === 'array_role') {
                    $out[$key] = is_array($out[$key]) ? array_values($out[$key]) : [];
                }
                if ($t === 'string_toggle' && $out[$key] === null) {
                    $out[$key] = ''; // legacy ''/ '1'
                }
            }
            return $out;
        }

        // List (ex: addons/licences)
        if (($conf['type'] ?? '') === 'list') {
            return $conf['default'] ?? [];
        }

        return [];
    }
}

if (!function_exists('yuz_settings_replace_section')) {
    function yuz_settings_replace_section(string $name, array $value): bool {
        $canonical = yuz_settings__resolve_canonical($name);
        update_option($canonical, $value, false);
        if (function_exists('yuz_settings_runtime_flush')) {
            yuz_settings_runtime_flush();
        }
        return true;
    }
}
// ============================================================================
// ===== FIN DES SHIMS =========================================================



if (!function_exists('yuz_settings_get_all')) {
    // Ensure the bridge providing canonical helpers is loaded.
    $bridge = defined('YUZ_TRA_INCLUDES') ? YUZ_TRA_INCLUDES . 'class-yuz-options-bridge.php' : '';
    if ($bridge && file_exists($bridge)) {
        require_once $bridge;
    }
}

if (!function_exists('yuz_settings_get_meta')) {
    function yuz_settings_get_meta(): array {
        $all = yuz_settings_get_all();
        $meta = is_array($all['__meta'] ?? null) ? $all['__meta'] : [];
        $meta['version'] = max(1, (int) ($meta['version'] ?? 1));
        $meta['last_changed'] = (int) ($meta['last_changed'] ?? time());
        if ($meta['last_changed'] <= 0) {
            $meta['last_changed'] = time();
        }
        return $meta;
    }
}

if (!function_exists('yuz_settings_cache_namespace')) {
    function yuz_settings_cache_namespace(): string {
        $meta = yuz_settings_get_meta();
        return sprintf('yuztra:v%d', max(1, (int) ($meta['version'] ?? 1)));
    }
}

if (!function_exists('yuz_settings_cache_key')) {
    function yuz_settings_cache_key(string $suffix): string {
        $suffix = ltrim($suffix, ':');
        return yuz_settings_cache_namespace() . ':' . $suffix;
    }
}

if (!function_exists('yuz_settings_cache_heavy_request')) {
    function yuz_settings_cache_heavy_request(): bool {
        if (defined('YUZ_TRA_DISABLE_CACHE') && YUZ_TRA_DISABLE_CACHE) {
            return true;
        }
        if (defined('YUZ_TRA_DISABLE_FRONT_CACHE') && YUZ_TRA_DISABLE_FRONT_CACHE && !is_admin()) {
            return true;
        }
        foreach (['yuzprobe','yuzscan','yuzdom','yuzcacheflush'] as $flag) {
            if (!empty($_GET[$flag])) {
                return true;
            }
        }
        if (defined('DOING_AJAX') && DOING_AJAX && !empty($_POST['action'])) {
            $action = (string) $_POST['action'];
            if (strpos($action, 'yuz_') === 0) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('yuz_settings_transient_key')) {
    function yuz_settings_transient_key(string $suffix): string {
        $raw = yuz_settings_cache_key($suffix);
        $normalized = str_replace([':', '|'], '_', $raw);
        if (strlen($normalized) > 170) {
            $normalized = substr(sha1($normalized), 0, 32);
        }
        return $normalized;
    }
}

if (!function_exists('yuz_settings_cache_enabled')) {
    function yuz_settings_cache_enabled(string $operation = 'settings'): bool {
        $enabled = !yuz_settings_cache_heavy_request();
        return (bool) apply_filters('yuz/settings/enable_cache', $enabled, $operation);
    }
}

if (!function_exists('yuz_settings_cache_get')) {
    function yuz_settings_cache_get(string $suffix, string $group = 'yuz-tra') {
        if (!yuz_settings_cache_enabled('get')) {
            return false;
        }
        return wp_cache_get(yuz_settings_cache_key($suffix), $group);
    }
}

if (!function_exists('yuz_settings_cache_set')) {
    function yuz_settings_cache_set(string $suffix, $value, string $group = 'yuz-tra', int $expire = 0): bool {
        if (!yuz_settings_cache_enabled('set')) {
            return false;
        }
        $expire = (int) apply_filters('yuz/settings/cache_ttl', $expire, $suffix, $group, $value);
        if ($expire < 0) {
            $expire = 0;
        }
        return wp_cache_set(yuz_settings_cache_key($suffix), $value, $group, $expire);
    }
}

if (!function_exists('yuz_settings_cache_delete')) {
    function yuz_settings_cache_delete(string $suffix, string $group = 'yuz-tra'): bool {
        return wp_cache_delete(yuz_settings_cache_key($suffix), $group);
    }
}

if (!function_exists('yuz_settings_get_transient')) {
    function yuz_settings_get_transient(string $suffix) {
        return get_transient(yuz_settings_transient_key($suffix));
    }
}

if (!function_exists('yuz_settings_set_transient')) {
    function yuz_settings_set_transient(string $suffix, $value, int $expiration): bool {
        return set_transient(yuz_settings_transient_key($suffix), $value, $expiration);
    }
}

if (!function_exists('yuz_settings_delete_transient')) {
    function yuz_settings_delete_transient(string $suffix): bool {
        return delete_transient(yuz_settings_transient_key($suffix));
    }
}

if (!function_exists('yuz_settings_replace_section')) {
    function yuz_settings_replace_section(string $section, $value): bool {
        if (!function_exists('yuz_settings_update')) {
            return false;
        }

        if (function_exists('yuz_settings_registry_lookup')) {
            $lookup = yuz_settings_registry_lookup();
            if (isset($lookup[$section]['canonical'])) {
                $section = $lookup[$section]['canonical'];
            }
        }

        if (!function_exists('yuz_settings_sanitize_section')) {
            return false;
        }

        $sanitized = yuz_settings_sanitize_section($section, $value);

        return yuz_settings_update(function (array $current) use ($section, $sanitized) {
            $current[$section] = $sanitized;
            return $current;
        });
    }
}

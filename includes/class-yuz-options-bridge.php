<?php
/**
 * Class YUZ_Options_Bridge (Option A)
 * Variante minimaliste : gardes de contexte + durcissement AT sans migration auto.
 */
/**
 * YUZ CORE RULES — DO NOT VIOLATE
 *
 * Objectif :
 *   Verrouiller par VERBES les actions autorisées par fichier central.
 *   Tout verbe non listé ci-dessous est INTERDIT dans ce fichier.
 *
 * Exclusivités (fichiers centraux) — autorité unique :
 *
 * - class-yuz-assets.php  (Rôle: Assets)
 *     VERBES AUTORISÉS UNIQUEMENT ICI :
 *       - wp_register_script, wp_register_style
 *       - wp_enqueue_script, wp_enqueue_style
 *       - wp_localize_script
 *       - wp_add_inline_script, wp_add_inline_style
 *       - wp_set_script_translations
 *       - add_action('admin_enqueue_scripts' | 'wp_enqueue_scripts' | 'enqueue_block_editor_assets')
 *       - add_filter('script_loader_tag' | 'style_loader_tag' | 'clar_inline_script_hashes')
 *     INTERDIT ailleurs : tout enregistrement/enfilage/localisation/altération <script>/<link>.
 *
 * - class-yuz-ajax.php  (Rôle: AJAX)
 *     VERBES AUTORISÉS UNIQUEMENT ICI :
 *       - add_action('wp_ajax_*' | 'wp_ajax_nopriv_*')
 *       - check_ajax_referer
 *       - current_user_can
 *       - sanitize_* (toutes variantes), esc_* (toutes variantes)
 *       - wp_send_json, wp_send_json_success, wp_send_json_error
 *       - wp_die (uniquement fin d’endpoint)
 *     INTERDIT ailleurs : tout câblage d'actions AJAX, émission JSON des endpoints, contrôle caps pour AJAX.
 *
 * - class-yuz-renderer.php  (Rôle: Rendu Admin)
 *     VERBES AUTORISÉS UNIQUEMENT ICI :
 *       - add_menu_page, add_submenu_page
 *       - add_settings_section, add_settings_field (déclaration UI)
 *       - render_* (fonctions de sortie/templates), require template admin
 *       - wp_nonce_field (pour les formulaires d’admin)
 *     INTERDIT ailleurs : ajout de pages/menus d’admin ou de sections/champs Settings API.
 *
 * - class-yuz-contracts.php  (Rôle: Contrats)
 *     VERBES AUTORISÉS :
 *       - interface, trait (déclarations uniquement)
 *     INTERDIT : logique, hooks, sorties, accès WP_*.
 *
 * - class-yuz-fallbacks.php  (Rôle: Nulls/Fallbacks)
 *     VERBES AUTORISÉS : 
 *       - class Null Fallback* (implémentations minimales des contrats) 
 *     INTERDIT : hooks, I/O, enqueues, endpoints.
 *
 * Règle d’or (globale) :
 *   Aucun autre fichier ne doit enregistrer/enfiler/localiser des assets,
 *   ni câbler des hooks AJAX/menus d’admin,
 *   ni altérer les balises <script>/<link>,
 *   ni émettre des réponses JSON d’endpoint.
 *
 * Conseils :
 *   — Toute logique transverse doit passer par services/contrats, jamais par un hook non autorisé.
 *   — Les chemins d’assets ne doivent JAMAIS être câblés en dur hors class-yuz-assets.php.
 */

defined('ABSPATH') || exit;

/**
 * Providers which may be selected explicitly by an administrator.
 * No provider is contacted merely by loading or saving this option schema.
 */
if (!function_exists('yuztra_allowed_remote_providers')) {
    function yuztra_allowed_remote_providers(): array {
        return ['libretranslate', 'google', 'deepl', 'custom', 'ollama', 'openai'];
    }
}

if (!function_exists('yuztra_diag_enabled')) {
    function yuztra_diag_enabled(): bool {
        if (defined('YUZTRA_DIAG_FORCE')) {
            return (bool) YUZTRA_DIAG_FORCE;
        }
        if (defined('YUZTRA_DEBUG') && YUZTRA_DEBUG) {
            return true;
        }
        return defined('WP_DEBUG') && WP_DEBUG;
    }
}
if (!function_exists('yuztra_settings_diag_log')) {
    function yuztra_settings_diag_log(string $message): void {
        if (!yuztra_diag_enabled()) {
            return;
        }
        yuztra_debug_log($message);
    }
}

if (!function_exists('yuztra_diag_shape')) {
    /**
     * Return structural diagnostics without ever serializing setting values.
     * API credentials and customer configuration must not reach debug logs.
     */
    function yuztra_diag_shape($value): array {
        if (!is_array($value)) {
            return ['type' => gettype($value)];
        }

        return [
            'type'  => 'array',
            'count' => count($value),
            'keys'  => array_map('sanitize_key', array_keys($value)),
        ];
    }
}

if (!function_exists('yuztra_request_text')) {
    /**
     * Read a scalar request field as normalized text.
     */
    function yuztra_request_text(string $key): string {
        // Routing/nonce metadata only; callers authorize before consuming payloads.
        // WordPress slashes $_POST, not the SAPI input read by filter_input().
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Used to obtain the nonce itself.
        return isset($_POST[$key]) && is_string($_POST[$key]) ? sanitize_text_field(wp_unslash($_POST[$key])) : '';
    }
}

if (!function_exists('yuztra_request_array')) {
    /** Read an array payload only after its request boundary has been authorized. */
    function yuztra_request_array(string $key): array {
        if (!current_user_can('manage_options')) {
            return [];
        }
        if (yuztra_request_text('action') === 'update') {
            if (!yuztra_authorize_settings_request(yuztra_request_text('option_page'))) {
                return [];
            }
        } else {
            $nonce = yuztra_request_text('nonce');
            if ($nonce === '' || (!wp_verify_nonce($nonce, 'yuztra_nonce') && !wp_verify_nonce($nonce, 'yuztra_con_nonce'))) {
                return [];
            }
        }
        if (!isset($_POST[$key]) || !is_array($_POST[$key])) {
            return [];
        }
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Authorized above; typed per-section validation or map_deep follows before return.
        $value = wp_unslash($_POST[$key]);
        if (in_array($key, ['yuztra_ls_settings', 'yuztra_sw_settings'], true)) {
            unset($value['_sentinel']);
            try {
                return yuztra_sanitize_general_patch($key, $value);
            } catch (InvalidArgumentException $e) {
                return [];
            }
        }
        return map_deep($value, 'sanitize_text_field');
    }
}

if (!function_exists('yuztra_sanitize_general_patch')) {
    /** Validate only submitted fields; absent fields remain untouched when merged. */
    function yuztra_sanitize_general_patch(string $section, array $value): array {
        $language = $section === 'yuztra_ls_settings';
        $toggles = $language
            ? ['native_language_name', 'use_subdirectory', 'force_lang_in_links']
            : ['shortcode_enabled', 'menu_enabled', 'floating_enabled', 'show_poweredby'];
        $formats = ['full-names', 'short-names', 'flags-full-names', 'flags-short-names', 'only-flags'];
        $choices = $language ? [] : [
            'shortcode_format' => $formats, 'menu_format' => $formats, 'floating_format' => $formats,
            'floating_theme' => ['light', 'dark'],
            'floating_position' => ['bottom-left', 'bottom-right', 'top-left', 'top-right'],
        ];
        $clean = [];
        foreach ($value as $key => $field) {
            if (in_array($key, $toggles, true)) {
                if (!in_array($field, [true, false, 1, 0, '1', '0', '', 'on', 'off', 'true', 'false'], true)) {
                    throw new InvalidArgumentException('Invalid settings toggle');
                }
                $enabled = in_array($field, [true, 1, '1', 'on', 'true'], true);
                $clean[$key] = $language ? ($enabled ? '1' : '') : (int) $enabled;
            } elseif (isset($choices[$key]) && is_string($field) && in_array($field, $choices[$key], true)) {
                $clean[$key] = sanitize_key($field);
            } else {
                throw new InvalidArgumentException('Invalid settings field');
            }
        }
        return $clean;
    }
}

if (!function_exists('yuztra_authorize_settings_request')) {
    /** Require the Settings API capability and group nonce before any option write. */
    function yuztra_authorize_settings_request(string $group): bool {
        if (!current_user_can('manage_options')) {
            return false;
        }
        $nonce = yuztra_request_text('_wpnonce');
        return $group !== '' && $nonce !== '' && wp_verify_nonce($nonce, $group . '-options');
    }
}

// Autoriser l'action 'update' (submit Settings API via options.php)
add_filter('yuztra/canonical_write/allowed_actions', function(array $actions){
    if (!in_array('update', $actions, true)) {
        $actions[] = 'update';
    }
    return $actions;
}, 1);

/* ========================================================================== *
 * SETTINGS SCHEMA HELPERS — CANONICAL STORAGE MAP
 * ========================================================================== */

// Mappe un groupe Settings API -> options canoniques autorisées
if (!function_exists('yuztra_allowed_group_map')) {
    function yuztra_allowed_group_map(): array {
        return [
            'yuztra_at_settings' => ['yuztra_at_settings'],
            'yuztra_general_settings_group'    => [
                'yuztra_ws_settings',
                'yuztra_ls_settings',
                'yuztra_sw_settings',
            ],
            'yuztra_ws_settings_group' => ['yuztra_ws_settings'],
            'yuztra_ls_settings_group' => ['yuztra_ls_settings'],
            'yuztra_sw_settings_group' => ['yuztra_sw_settings'],
            'yuztra_ts_settings_group' => ['yuztra_ts_settings'],
        ];
    }
}

add_filter('allowed_options', function (array $allowed) {
    foreach (yuztra_allowed_group_map() as $group => $opts) {
        $allowed[$group] = array_unique(array_merge($allowed[$group] ?? [], $opts));
    }
    static $logged = false;
    if (!$logged && function_exists('error_log')) {
        yuztra_settings_diag_log('[YUZ-DIAG][ALLOWED_OPTIONS] merged canonical groups: ' . wp_json_encode(array_keys(yuztra_allowed_group_map())));
        $logged = true;
    }
    return $allowed;
}, 1);

add_filter('whitelist_options', function (array $allowed) {
    foreach (yuztra_allowed_group_map() as $group => $opts) {
        $allowed[$group] = array_unique(array_merge($allowed[$group] ?? [], $opts));
    }
    static $logged = false;
    if (!$logged && function_exists('error_log')) {
        yuztra_settings_diag_log('[YUZ-DIAG][WHITELIST_OPTIONS] merged canonical groups');
        $logged = true;
    }
    return $allowed;
}, 1);

add_filter('allowed_options', function (array $allowed) {
    $allowed['yuztra_general_settings_group'] = [
        'yuztra_ws_settings',
        'yuztra_ls_settings',
        'yuztra_sw_settings',
    ];
    static $logged = false;
    if (!$logged && function_exists('error_log')) {
        yuztra_settings_diag_log('[YUZ-DIAG][ALLOWED_OPTIONS] forced General group allowance');
        $logged = true;
    }
    return $allowed;
}, PHP_INT_MAX);

add_filter('whitelist_options', function (array $allowed) {
    $allowed['yuztra_general_settings_group'] = [
        'yuztra_ws_settings',
        'yuztra_ls_settings',
        'yuztra_sw_settings',
    ];
    static $logged = false;
    if (!$logged && function_exists('error_log')) {
        yuztra_settings_diag_log('[YUZ-DIAG][WHITELIST_OPTIONS] forced General group allowance');
        $logged = true;
    }
    return $allowed;
}, PHP_INT_MAX);

foreach (['yuztra_ws_settings', 'yuztra_ls_settings', 'yuztra_sw_settings'] as $yuztra_opt) {
    add_filter("pre_update_option_{$yuztra_opt}", function ($new, $old) use ($yuztra_opt) {
        $action = yuztra_request_text('action');
        $group  = yuztra_request_text('option_page');

        if ($action === 'update' && $group === 'yuztra_general_settings_group') {
            if (!yuztra_authorize_settings_request($group)) {
                return $old;
            }
            $raw = yuztra_request_array($yuztra_opt);
            if (empty($raw)) {
                if (function_exists('error_log')) {
                    yuztra_settings_diag_log('[YUZ-DIAG][PRE_UPDATE][' . $yuztra_opt . '] general submit but payload empty -> keeping previous');
                }
                return $old;
            }

            unset($raw['_sentinel']);

            $base = is_array($old) ? $old : [];
            $merged = array_merge($base, $raw);

            if ($yuztra_opt === 'yuztra_ls_settings') {
                foreach (['native_language_name', 'use_subdirectory', 'force_lang_in_links'] as $key) {
                    $merged[$key] = !empty($merged[$key]) ? '1' : '';
                }
            } elseif ($yuztra_opt === 'yuztra_sw_settings') {
                foreach (['shortcode_enabled', 'menu_enabled', 'floating_enabled', 'show_poweredby'] as $key) {
                    $merged[$key] = !empty($merged[$key]) ? '1' : '';
                }
            } elseif ($yuztra_opt === 'yuztra_ws_settings') {
                if (method_exists('YUZTRA_General', 'sanitize_ws_settings')) {
                    $merged = YUZTRA_General::sanitize_ws_settings($merged);
                }
            }

            if (function_exists('error_log')) {
                yuztra_settings_diag_log('[YUZ-DIAG][PRE_UPDATE][' . $yuztra_opt . '] overriding with POST payload keys=' . implode(',', array_keys($merged)));
            }

            $merged = array_replace($base, yuztra_settings_sanitize_section($yuztra_opt, $merged));

            // Synchronize legacy containers used by canonical migration logic.
            if ($yuztra_opt === 'yuztra_ws_settings') {
                update_option('yuztra_general', $merged, false);
                update_option('yuztra_general_settings', $merged, false);
            } elseif ($yuztra_opt === 'yuztra_ls_settings') {
                $legacy = [
                    'native_language_name' => !empty($merged['native_language_name']) ? '1' : '',
                    'use_subdirectory'     => !empty($merged['use_subdirectory']) ? '1' : '',
                    'force_lang_in_links'  => !empty($merged['force_lang_in_links']) ? '1' : '',
                ];
                update_option('yuztra_settings', $legacy, false);
            } elseif ($yuztra_opt === 'yuztra_sw_settings') {
                update_option('yuztra_switcher', $merged, false);
                update_option('yuztra_switcher_settings', $merged, false);
            }

            if (function_exists('yuztra_settings_runtime_flush')) {
                yuztra_settings_runtime_flush();
            }
            return $merged;
        }

        if (!is_array($new)) {
            $decoded = is_string($new) ? json_decode($new, true) : null;
            if (is_array($decoded)) {
                if (function_exists('error_log')) {
                    yuztra_settings_diag_log('[YUZ-DIAG][PRE_UPDATE][' . $yuztra_opt . '] decoded JSON payload');
                }
                return $decoded;
            }
            if (function_exists('error_log')) {
                yuztra_settings_diag_log('[YUZ-DIAG][PRE_UPDATE][' . $yuztra_opt . '] rejected non-array payload outside submit');
            }
            return $old;
        }

        if (function_exists('error_log')) {
            yuztra_settings_diag_log('[YUZ-DIAG][PRE_UPDATE][' . $yuztra_opt . '] passive accept keys=' . implode(',', array_keys($new)));
        }
        return $new;
    }, 0, 2);
}

add_filter('option_yuztra_ls_settings', function ($yuztra_opt) {
    $yuztra_opt = is_array($yuztra_opt) ? $yuztra_opt : [];
    foreach (['native_language_name', 'use_subdirectory', 'force_lang_in_links'] as $k) {
        $yuztra_opt[$k] = !empty($yuztra_opt[$k]) ? '1' : '';
    }
    if (function_exists('error_log')) {
        yuztra_settings_diag_log('[YUZ-DIAG][OPTION_READ][LS] normalized toggles=' . wp_json_encode($yuztra_opt));
    }
    return $yuztra_opt;
}, 9);

add_filter('option_yuztra_sw_settings', function ($yuztra_opt) {
    $yuztra_opt = is_array($yuztra_opt) ? $yuztra_opt : [];
    foreach (['shortcode_enabled', 'menu_enabled', 'floating_enabled', 'show_poweredby'] as $k) {
        $yuztra_opt[$k] = !empty($yuztra_opt[$k]) ? '1' : '';
    }
    if (function_exists('error_log')) {
        yuztra_settings_diag_log('[YUZ-DIAG][OPTION_READ][SW] normalized toggles=' . wp_json_encode($yuztra_opt));
    }
    return $yuztra_opt;
}, 9);


add_action('admin_init', function () {
    static $logged = false;
    if ($logged) {
        return;
    }
    $request_method = isset($_SERVER['REQUEST_METHOD'])
        ? sanitize_key(wp_unslash((string) $_SERVER['REQUEST_METHOD']))
        : '';
    if ($request_method !== 'post') {
        return;
    }
    $action = yuztra_request_text('action');
    $group  = yuztra_request_text('option_page');
    if ($action !== 'update' || $group !== 'yuztra_general_settings_group') {
        return;
    }
    if (!yuztra_authorize_settings_request($group)) {
        return;
    }

    // Verification explicite du nonce dans la portee meme de la lecture.
    $wpnonce = yuztra_request_text('_wpnonce');
    if ($wpnonce === '' || !wp_verify_nonce($wpnonce, $group . '-options')) {
        return;
    }
    $posted = is_array($_POST) ? map_deep(wp_unslash($_POST), 'sanitize_text_field') : [];

    $snapshot = [
        'action' => $action,
        'option_page' => $group,
        'root_keys' => array_map('sanitize_key', array_keys($posted)),
        'payload' => [],
    ];

    foreach (['yuztra_ws_settings', 'yuztra_ls_settings', 'yuztra_sw_settings'] as $yuztra_opt) {
        $entry = [
            'isset' => array_key_exists($yuztra_opt, $posted),
            'type'  => array_key_exists($yuztra_opt, $posted) ? gettype($posted[$yuztra_opt]) : 'missing',
        ];
        if (isset($posted[$yuztra_opt])) {
            if (is_array($posted[$yuztra_opt])) {
                $entry['keys'] = array_map('sanitize_key', array_keys($posted[$yuztra_opt]));
            } else {
                $entry['length'] = is_string($posted[$yuztra_opt]) ? strlen($posted[$yuztra_opt]) : null;
            }
        }
        $snapshot['payload'][$yuztra_opt] = $entry;
    }

    if (function_exists('error_log')) {
        yuztra_settings_diag_log('[YUZ-DIAG][POST_SNAPSHOT] ' . wp_json_encode($snapshot));
    }
    $logged = true;
}, 5);

foreach (['yuztra_ws_settings', 'yuztra_ls_settings', 'yuztra_sw_settings'] as $yuztra_opt) {
    remove_all_filters("sanitize_option_{$yuztra_opt}");
    add_filter("sanitize_option_{$yuztra_opt}", function ($value, $option, $original) use ($yuztra_opt) {
        if (function_exists('error_log')) {
            $log = [
                'value_type'    => gettype($value),
                'original_type' => gettype($original),
                'filters'       => [],
                'stack'         => [],
            ];
            $log['value_shape']    = yuztra_diag_shape($value);
            $log['original_shape'] = yuztra_diag_shape($original);
            $filters = $GLOBALS['wp_filter']["sanitize_option_{$yuztra_opt}"] ?? null;
            if ($filters instanceof WP_Hook && !empty($filters->callbacks)) {
                foreach ($filters->callbacks as $priority => $callbacks) {
                    foreach ($callbacks as $id => $cb) {
                        $details = [
                            'priority' => $priority,
                            'id'       => $id,
                            'type'     => is_array($cb['function']) ? 'array' : (is_object($cb['function']) ? 'object' : gettype($cb['function'])),
                        ];
                        if (is_array($cb['function'])) {
                            $details['callable'] = [
                                'class'  => is_object($cb['function'][0]) ? get_class($cb['function'][0]) : (string) $cb['function'][0],
                                'method' => (string) $cb['function'][1],
                            ];
                            try {
                                $ref = new ReflectionMethod($cb['function'][0], $cb['function'][1]);
                                $details['callable_file'] = basename((string) $ref->getFileName());
                                $details['callable_line'] = $ref->getStartLine();
                            } catch (ReflectionException $e) {
                                $details['callable_file'] = 'n/a';
                            }
                        } elseif ($cb['function'] instanceof Closure) {
                            $details['callable'] = 'Closure';
                            $ref = new ReflectionFunction($cb['function']);
                            $details['callable_file'] = basename((string) $ref->getFileName());
                            $details['callable_line'] = $ref->getStartLine();
                        } elseif (is_object($cb['function'])) {
                            $details['callable'] = get_class($cb['function']);
                            try {
                                $ref = new ReflectionMethod($cb['function'], '__invoke');
                                $details['callable_file'] = basename((string) $ref->getFileName());
                                $details['callable_line'] = $ref->getStartLine();
                            } catch (ReflectionException $e) {
                                $details['callable_file'] = 'n/a';
                            }
                        } else {
                            $details['callable'] = (string) $cb['function'];
                            if (is_string($cb['function']) && function_exists($cb['function'])) {
                                $ref = new ReflectionFunction($cb['function']);
                                $details['callable_file'] = basename((string) $ref->getFileName());
                                $details['callable_line'] = $ref->getStartLine();
                            }
                        }
                        $log['filters'][] = $details;
                    }
                }
            }
            if ($value === null || $original === null) {
                $trace = (new \RuntimeException())->getTrace();
                $trace = array_slice($trace, 0, 12);
                foreach ($trace as $step) {
                    $frame = [];
                    $frame['function'] = $step['function'] ?? '';
                    if (isset($step['class'])) {
                        $frame['class'] = $step['class'];
                    }
                    if (isset($step['file'], $step['line'])) {
                        $frame['file'] = basename($step['file']) . ':' . $step['line'];
                    }
                    $log['stack'][] = $frame;
                }
            }
            $encoded_log = wp_json_encode($log, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            yuztra_settings_diag_log('[YUZ-DIAG][SANITIZE][' . $yuztra_opt . '] ' . ($encoded_log !== false ? $encoded_log : '[unencodable]'));
        }

        $is_general_post = yuztra_request_text('option_page') === 'yuztra_general_settings_group';
        if (!$is_general_post || !yuztra_authorize_settings_request('yuztra_general_settings_group')) {
            $existing = get_option($yuztra_opt, []);
            if (function_exists('error_log')) {
                yuztra_settings_diag_log('[YUZ-DIAG][SANITIZE][' . $yuztra_opt . '] bypassed (no general POST) keeping existing payload');
            }
            return is_array($existing) ? $existing : [];
        }

        if (!is_array($value)) {
            $posted_value = yuztra_request_array($yuztra_opt);
            if (!empty($posted_value)) {
                $value = $posted_value;
            } elseif (is_string($value)) {
                $decoded = json_decode($value, true);
                $value = is_array($decoded) ? $decoded : [];
            } else {
                $value = [];
            }
        }

        if (in_array($yuztra_opt, ['yuztra_ls_settings', 'yuztra_sw_settings'], true)) {
            $existing = get_option($yuztra_opt, []);
            $existing = is_array($existing) ? $existing : [];
            unset($value['_sentinel']);
            try {
                $value = array_merge($existing, yuztra_sanitize_general_patch($yuztra_opt, $value));
            } catch (InvalidArgumentException $e) {
                return $existing;
            }
        }

        if ($yuztra_opt === 'yuztra_ls_settings') {
            foreach (['native_language_name', 'use_subdirectory', 'force_lang_in_links'] as $key) {
                $value[$key] = !empty($value[$key]) ? '1' : '';
            }
        } elseif ($yuztra_opt === 'yuztra_sw_settings') {
            foreach (['shortcode_enabled', 'menu_enabled', 'floating_enabled', 'show_poweredby'] as $key) {
                $value[$key] = !empty($value[$key]) ? '1' : '';
            }
        }
        unset($value['_sentinel']);

        return $value;
    }, 5, 3);
}


if (!function_exists('yuztra_settings_registry')) {
    function yuztra_settings_registry(): array {
        static $registry = null;
        if ($registry !== null) {
            return $registry;
        }

        $registry = [
            'yuztra_ws_settings' => [
                'alias'  => 'yuztra_general',
                'type'   => 'assoc',
                'fields' => [
                    'yuztra_default_language'       => ['type' => 'string',       'default' => 'en_US'],
                    'yuztra_source_language'        => ['type' => 'string',       'default' => 'fr_FR'],
                    'yuztra_translatable_languages' => ['type' => 'array_string', 'default' => []],
                    'yuztra_slug'                   => ['type' => 'array_string', 'default' => []],
                    'yuztra_code'                   => ['type' => 'array_string', 'default' => []],
                ],
            ],
            'yuztra_ls_settings' => [
                'alias'  => 'yuztra_settings',
                'type'   => 'assoc',
                'fields' => [
                    'native_language_name' => ['type' => 'string_toggle', 'default' => ''],
                    'use_subdirectory'     => ['type' => 'string_toggle', 'default' => ''],
                    'force_lang_in_links'  => ['type' => 'string_toggle', 'default' => ''],
                ],
            ],
            'yuztra_sw_settings' => [
                'alias'  => 'yuztra_switcher',
                'type'   => 'assoc',
                'fields' => [
                    'shortcode_enabled' => ['type' => 'bool',   'default' => false],
                    'shortcode_format'  => ['type' => 'string', 'default' => 'flags-full-names'],
                    'menu_enabled'      => ['type' => 'bool',   'default' => false],
                    'menu_format'       => ['type' => 'string', 'default' => 'short-names'],
                    'floating_enabled'  => ['type' => 'bool',   'default' => false],
                    'floating_format'   => ['type' => 'string', 'default' => 'short-names'],
                    'floating_theme'    => ['type' => 'string', 'default' => 'dark'],
                    'floating_position' => ['type' => 'string', 'default' => 'bottom-right'],
                    'show_poweredby'    => ['type' => 'bool',   'default' => false],
                ],
            ],
            'yuztra_ts_settings' => [
                'alias'  => 'yuztra_site_settings',
                'type'   => 'assoc',
                'fields' => [
                    'enable_extra_languages'  => ['type' => 'bool',       'default' => false],
                    'translate_seo'           => ['type' => 'bool',       'default' => false],
                    'require_complete'        => ['type' => 'bool',       'default' => false],
                    'menu_per_lang'           => ['type' => 'bool',       'default' => false],
                    'browser_language_detect' => ['type' => 'bool',       'default' => false],
                    'block_browser_translation' => ['type' => 'bool',     'default' => true],
                    'enable_ai'               => ['type' => 'bool',       'default' => false],
                    'enable_youzuruz'         => ['type' => 'bool',       'default' => false],
                    'user_role_emulation'     => ['type' => 'string',     'default' => 'administrator'],
                    'allowed_roles'           => ['type' => 'array_role', 'default' => ['administrator', 'editor', 'translator']],
                ],
            ],
            'yuztra_at_settings' => [
                'alias'  => 'yuztra_api_settings',
                'type'   => 'assoc',
                'fields' => [
                    'source_language_id'    => ['type' => 'int',    'default' => 0],
                    'api_adapter'           => ['type' => 'string', 'default' => 'libretranslate'],
                    'url_to_load'           => ['type' => 'url',    'default' => ''],
                    'translation_mode'      => ['type' => 'string', 'default' => 'manual'],
                    'cron_interval'         => ['type' => 'string', 'default' => 'hourly'],
                    'enable_auto_translate' => ['type' => 'bool',   'default' => false],
                    'api_provider'          => ['type' => 'string', 'default' => 'libretranslate'],
                    'ollama_url'            => ['type' => 'url', 'default' => ''],
                    // phpcs:ignore PluginCheck.CodeAnalysis.AIProvider.DirectIntegration -- Explicit administrator-selected provider endpoint; no request occurs in the schema.
                    'openai_url'            => ['type' => 'url', 'default' => 'https://api.openai.com/v1/chat/completions'],
                    'openai_key'            => ['type' => 'string', 'default' => ''],
                    'openai_model'          => ['type' => 'string', 'default' => ''],
                    'model'                 => ['type' => 'string', 'default' => ''],
                    'model_revision'        => ['type' => 'string', 'default' => ''],
                    'provider_timeout'      => ['type' => 'int', 'default' => 45],
                    'num_ctx'               => ['type' => 'int', 'default' => 2048],
                    'num_predict'           => ['type' => 'int', 'default' => 512],
                    'num_thread'            => ['type' => 'int', 'default' => 2],
                    'daily_token_limit'     => ['type' => 'int', 'default' => 100000],
                    'worker_batch_size'     => ['type' => 'int', 'default' => 3],
                    'worker_time_budget'    => ['type' => 'int', 'default' => 35],
                    'libre_url'             => ['type' => 'url',    'default' => ''],
                    'libre_key'             => ['type' => 'string', 'default' => ''],
                    'google_key'            => ['type' => 'string', 'default' => ''],
                    'google_project'        => ['type' => 'string', 'default' => ''],
                    'deepl_key'             => ['type' => 'string', 'default' => ''],
                    'deepl_free'            => ['type' => 'bool',   'default' => false],
                    'custom_url'            => ['type' => 'url',    'default' => ''],
                    'custom_key'            => ['type' => 'string', 'default' => ''],
                    'custom_auth'           => ['type' => 'string', 'default' => 'none'],
                    'custom_method'         => ['type' => 'string', 'default' => 'POST'],
                    'custom_format'         => ['type' => 'string', 'default' => 'JSON'],
                    'alternatives'          => ['type' => 'int',    'default' => 3],
                    'char_limit'            => ['type' => 'int',    'default' => 50000],
                    'requests_limit'        => ['type' => 'int',    'default' => 100],
                    'available_balance_usd'  => ['type' => 'string', 'default' => ''],
                    'minimum_balance_usd'    => ['type' => 'string', 'default' => ''],
                    'input_cost_usd_per_million' => ['type' => 'string', 'default' => ''],
                    'output_cost_usd_per_million' => ['type' => 'string', 'default' => ''],
                    'sale_price_usd_per_million' => ['type' => 'string', 'default' => ''],
                    'fixed_monthly_cost_usd' => ['type' => 'string', 'default' => ''],
                    'pricing_source'         => ['type' => 'string', 'default' => 'administrator estimate'],
                    'block_crawlers'        => ['type' => 'bool',   'default' => false],
                    'log_queries'           => ['type' => 'bool',   'default' => false],
                ],
            ],
            'yuztra_av_settings' => [
                'alias'  => 'yuztra_advanced',
                'type'   => 'assoc',
                'fields' => [
                    'fix_dynamic_content'         => ['type' => 'bool', 'default' => false],
                    'disable_dynamic_translation' => ['type' => 'bool', 'default' => false],
                ],
            ],
            'yuztra_ad_settings' => [
                'alias'     => 'yuztra_addons',
                'type'      => 'list',
                'item_type' => 'string',
                'default'   => [],
            ],
            'yuztra_li_settings' => [
                'alias'     => 'yuztra_licenses',
                'type'      => 'list',
                'item_type' => 'string',
                'default   ' => [],
            ],
            'yuztra_ai_settings' => [
                'alias'  => 'yuztra_ai',
                'type'   => 'assoc',
                'fields' => [
                    'enabled' => ['type' => 'bool', 'default' => false],
                ],
            ],
        ];

        return $registry;
    }
}

if (!function_exists('yuztra_settings_registry_lookup')) {
    function yuztra_settings_registry_lookup(): array {
        static $lookup = null;
        if ($lookup !== null) {
            return $lookup;
        }

        $lookup = [];
        foreach (yuztra_settings_registry() as $canonical => $config) {
            $alias = $config['alias'];
            $lookup[$canonical] = ['canonical' => $canonical, 'alias' => $alias, 'config' => $config];
            $lookup[$alias]     = ['canonical' => $canonical, 'alias' => $alias, 'config' => $config];
        }
        return $lookup;
    }
}

if (!function_exists('yuztra_settings_meta_key')) {
    function yuztra_settings_meta_key(): string {
        return 'yuztra_settings_meta';
    }
}

if (!function_exists('yuztra_is_cli_ctx')) {
    function yuztra_is_cli_ctx(): bool {
        return (defined('WP_CLI') && WP_CLI) || PHP_SAPI === 'cli';
    }
}

if (!function_exists('yuztra_is_at_save_ctx')) {
    function yuztra_is_at_save_ctx(): bool {
        $act  = sanitize_key(yuztra_request_text('action'));
        $page = sanitize_key(yuztra_request_text('option_page'));

        if (!empty(yuztra_request_array('yuztra_at_settings'))) {
            return true;
        }
        if (in_array($act, ['yuztra_at_upd_settings', 'yuztra_at_upd_api_settings', 'update'], true)) {
            return true;
        }
        if ($page === 'yuztra_at_settings') {
            return true;
        }
        return false;
    }
}

if (!function_exists('yuztra_is_publish_flow_action')) {
    function yuztra_is_publish_flow_action(string $action): bool {
        return in_array($action, ['yuztra_save_translation', 'yuztra_publish_translations', 'yuztra_mass_publish'], true);
    }
}

// --- Helper : détection d'un submit Settings API de YUZ ---
if (!function_exists('yuztra_is_settings_api_submit')) {
    function yuztra_is_settings_api_submit(): bool {
        $action = sanitize_key(yuztra_request_text('action'));
        if ($action === '') return false;
        if ($action !== 'update') return false;

        $option_page = sanitize_key(yuztra_request_text('option_page'));
        if ($option_page === '') return false;

        // Groupes connus (au cas où tu en as plusieurs)
        $allowed_groups = [
            'yuztra_general_settings_group',      // General (Website Languages / Switcher / etc.)
            'yuztra_at_settings',   // Automatic Translation
            'yuztra_ts_settings_group',   // Translate Site (si séparé)
            'yuztra_ws_settings_group',
            'yuztra_ls_settings_group',
            'yuztra_sw_settings_group',
        ];
        if (in_array($option_page, $allowed_groups, true)) {
            if (function_exists('error_log')) {
                yuztra_settings_diag_log('[YUZ-DIAG][SETTINGS_API_SUBMIT] recognized allowed group ' . $option_page);
            }
            return true;
        }

        // Filet de sécurité : tout groupe au format yuz_tra_*_settings(_group)
        $match = (bool) preg_match('/^yuztra_[a-z0-9_]+_settings(_group)?$/i', $option_page);
        if ($match && function_exists('error_log')) {
            yuztra_settings_diag_log('[YUZ-DIAG][SETTINGS_API_SUBMIT] matched fallback pattern for group ' . $option_page);
        }
        return $match;
    }
}


if (!function_exists('yuztra_bridge_allowed_actions')) {
    function yuztra_bridge_allowed_actions(): array {
        return apply_filters(
            'yuztra/canonical_write/allowed_actions',
            [
                'update', // << essentiel pour la Settings API (options.php)
                'yuztra_ws_upd_settings',
                'yuztra_ws_upd_deflang',
                'yuztra_ws_upd_srclang',
                'yuztra_ws_upd_translatable',
                'yuztra_ls_upd_settings',
                'yuztra_sw_upd_settings',
                'yuztra_ts_upd_settings',
                'yuztra_at_upd_api_settings',
                'yuztra_te_upd_manual',
                'yuztra_te_upd_publish',
                'yuztra_save_translation',
                'yuztra_publish_translations',
                'yuztra_mass_publish',
            ]
        );
    }
}

// --- Garde principale : quand autoriser l'écriture canonique ---
if (!function_exists('yuztra_should_allow_canonical_write')) {
    function yuztra_should_allow_canonical_write(string $canonical): bool {
        if (function_exists('error_log')) {
            yuztra_settings_diag_log('[YUZ-DIAG][GUARD] evaluating canonical=' . $canonical . ' action=' . yuztra_request_text('action'));
        }
        if (defined('WP_CLI') && WP_CLI) {
            if (function_exists('error_log')) {
                yuztra_settings_diag_log('[YUZ-GUARD] Blocked canonical write in CLI for ' . $canonical);
            }
            return false;
        }

        $action = sanitize_key(yuztra_request_text('action'));
        $group  = sanitize_key(yuztra_request_text('option_page'));

        if ($action === 'update' && $group === 'yuztra_general_settings_group') {
            if (!yuztra_authorize_settings_request($group)) {
                return false;
            }
            if (function_exists('error_log')) {
                yuztra_settings_diag_log('[YUZ-DIAG][GUARD] General submit detected, canonical=' . $canonical . ' group=' . $group);
            }
            return in_array($canonical, [
                'yuztra_ws_settings',
                'yuztra_ls_settings',
                'yuztra_sw_settings',
            ], true);
        }

        if (defined('DOING_AJAX') && DOING_AJAX && $action === 'heartbeat') {
            if (function_exists('error_log')) {
                yuztra_settings_diag_log('[YUZ-GUARD] Blocked canonical write during heartbeat for ' . $canonical);
            }
            return false;
        }

        if ($action === 'update' && $group !== '') {
            if (!yuztra_authorize_settings_request($group)) {
                return false;
            }
            $map = yuztra_allowed_group_map();
            if (isset($map[$group]) && in_array($canonical, $map[$group], true)) {
                if (function_exists('error_log')) {
                    yuztra_settings_diag_log('[YUZ-GUARD] Allow canonical write via options.php for ' . $canonical . ' (group=' . $group . ')');
                }
                return true;
            }
        }

        $allowed    = yuztra_bridge_allowed_actions();
        $doing_ajax = defined('DOING_AJAX') && DOING_AJAX;
        $is_admin   = function_exists('is_admin') && is_admin();
        $is_rest    = defined('REST_REQUEST') && REST_REQUEST;
        if (($doing_ajax || $is_admin || $is_rest) && $action && !in_array($action, $allowed, true)) {
            if (function_exists('error_log')) {
                yuztra_settings_diag_log('[YUZ-GUARD] Blocked canonical write (action=' . $action . ') for ' . $canonical);
            }
            return false;
        }

        if ($canonical === 'yuztra_at_settings' && !yuztra_is_at_save_ctx()) {
            if ($action && yuztra_is_publish_flow_action($action)) {
                if (function_exists('error_log')) {
                    yuztra_settings_diag_log('[YUZ-GUARD] Allow AT canonical write during publish action ' . $action);
                }
            } else {
                if (function_exists('error_log')) {
                    yuztra_settings_diag_log('[YUZ-GUARD] Skipped canonical write (not AT submit) for ' . $canonical);
                }
                return false;
            }
        }

        if (function_exists('error_log')) {
            yuztra_settings_diag_log('[YUZ-DIAG][GUARD] default allow for canonical=' . $canonical);
        }
        return true;
    }
}

add_action('admin_init', function () {
    foreach (yuztra_allowed_group_map() as $group => $opts) {
        foreach ($opts as $yuztra_opt) {
            register_setting(
                $group,
                $yuztra_opt,
                [
                    'type'              => 'array',
                    'sanitize_callback' => static function ($value) use ($yuztra_opt) {
                        return yuztra_settings_sanitize_section($yuztra_opt, $value);
                    },
                    'default'           => [],
                ]
            );
            if (function_exists('error_log')) {
                yuztra_settings_diag_log('[YUZ-DIAG][REGISTER_SETTING] bound ' . $yuztra_opt . ' to group ' . $group);
            }
        }
    }
}, 0);

add_action('admin_init', function () {
    foreach (['yuztra_ws_settings', 'yuztra_ls_settings', 'yuztra_sw_settings'] as $yuztra_opt) {
        $sanitize = static function ($value) use ($yuztra_opt) {
            return yuztra_settings_sanitize_section($yuztra_opt, $value);
        };
        if ($yuztra_opt === 'yuztra_ws_settings' && method_exists('YUZTRA_General', 'sanitize_ws_settings')) {
            // YUZ_General::sanitize_ws_settings ne fait que normaliser : on assainit ensuite.
            $sanitize = static function ($value) use ($yuztra_opt) {
                return yuztra_settings_sanitize_section(
                    $yuztra_opt,
                    YUZTRA_General::sanitize_ws_settings($value)
                );
            };
        }
        register_setting('yuztra_general_settings_group', $yuztra_opt, [
            'type'              => 'array',
            'sanitize_callback' => $sanitize,
            'default'           => [],
        ]);
        if (function_exists('error_log')) {
            yuztra_settings_diag_log('[YUZ-DIAG][REGISTER_SETTING] enforced General group registration for ' . $yuztra_opt);
        }
    }
}, 0);

remove_all_filters('sanitize_option_yuztra_at_settings');

add_filter('sanitize_option_yuztra_at_settings', function ($value, $option = null, $original = null) {
    if (is_array($original) && !empty($original)) {
        if (function_exists('error_log')) {
            yuztra_settings_diag_log('[YUZ-DIAG][SANITIZE_AT] using original payload from options.php');
        }
        $value = $original;
    }

    $posted_at = yuztra_request_array('yuztra_at_settings');
    if ((!is_array($value) || empty($value)) && !empty($posted_at)) {
        if (function_exists('error_log')) {
            yuztra_settings_diag_log('[YUZ-DIAG][SANITIZE_AT] fallback to POST data');
        }
        $value = $posted_at;
    }

    $value = is_array($value) ? $value : [];

    foreach (['url_to_load', 'libre_url', 'custom_url'] as $k) {
        if (array_key_exists($k, $value)) {
            $raw = is_array($value[$k]) ? '' : (string) wp_unslash($value[$k]);
            $raw = preg_replace('#/translate/?$#', '', trim($raw));
            $value[$k] = esc_url_raw($raw);
        }
    }
    if (empty($value['url_to_load']) && !empty($value['libre_url'])) {
        $value['url_to_load'] = $value['libre_url'];
    }
    if (empty($value['libre_url']) && !empty($value['url_to_load'])) {
        $value['libre_url'] = $value['url_to_load'];
    }

    $old = get_option('yuztra_at_settings', []);
    foreach (['libre_key', 'google_key', 'deepl_key', 'custom_key', 'openai_key'] as $k) {
        if (array_key_exists($k, $value)) {
            $v = trim((string) $value[$k]);
            if ($v === '' || $v === '***' || $v === '********') {
                $value[$k] = is_array($old) && array_key_exists($k, $old) ? $old[$k] : '';
            }
        }
    }
    foreach (['available_balance_usd','minimum_balance_usd','input_cost_usd_per_million','output_cost_usd_per_million','sale_price_usd_per_million','fixed_monthly_cost_usd'] as $k) {
        if (array_key_exists($k, $value)) {
            $raw = trim((string) $value[$k]);
            $value[$k] = $raw === '' ? '' : (is_numeric($raw) && (float) $raw >= 0 ? (string) (float) $raw : '');
        }
    }
    if (array_key_exists('pricing_source', $value)) $value['pricing_source'] = sanitize_text_field((string) $value['pricing_source']);
    foreach (['enable_auto_translate', 'deepl_free', 'block_crawlers', 'log_queries'] as $k) {
        $value[$k] = !empty($value[$k]) ? 1 : 0;
    }
    if (empty($value['api_provider']) && !empty($value['api_adapter'])) {
        $value['api_provider'] = $value['api_adapter'];
    }

    $defaults = [
        'source_language_id'    => 0,
        'api_adapter'           => 'libretranslate',
        'url_to_load'           => '',
        'translation_mode'      => 'all',
        'cron_interval'         => 'daily',
        'enable_auto_translate' => 0,
        'api_provider'          => 'libretranslate',
        'libre_url'             => '',
        // phpcs:ignore PluginCheck.CodeAnalysis.AIProvider.DirectIntegration -- Explicit administrator-selected provider endpoint; no request occurs while normalizing options.
        'openai_url'            => 'https://api.openai.com/v1/chat/completions',
        'openai_key'            => '',
        'openai_model'          => '',
        'libre_key'             => '',
        'google_key'            => '',
        'google_project'        => '',
        'deepl_key'             => '',
        'deepl_free'            => 0,
        'custom_url'            => '',
        'custom_key'            => '',
        'custom_auth'           => 'none',
        'custom_method'         => 'POST',
        'custom_format'         => 'JSON',
        'alternatives'          => 3,
        'char_limit'            => 50000,
        'requests_limit'        => 100,
        'available_balance_usd'  => '',
        'minimum_balance_usd'    => '',
        'input_cost_usd_per_million' => '',
        'output_cost_usd_per_million' => '',
        'sale_price_usd_per_million' => '',
        'fixed_monthly_cost_usd' => '',
        'pricing_source'         => 'administrator estimate',
        'block_crawlers'        => 0,
        'log_queries'           => 0,
    ];
    $base  = is_array($old) ? $old : [];
    $value = array_merge($defaults, $base, $value);

    if (function_exists('error_log')) {
        yuztra_settings_diag_log('[YUZ-DIAG][SANITIZE_AT] final_shape=' . wp_json_encode(yuztra_diag_shape($value)));
    }
    return $value;
}, 9999, 3);

add_filter('pre_update_option_yuztra_at_settings', function ($new_value, $old_value, $option) {
    if (!is_array($new_value) || empty($new_value)) {
        $src = null;
        $posted_at = yuztra_request_array('yuztra_at_settings');
        $src = !empty($posted_at) ? $posted_at : null;
        if (is_array($src) && !empty($src)) {
            if (function_exists('error_log')) {
                yuztra_settings_diag_log('[YUZ-DIAG][UPDATED_AT] recovering from empty value via pre_update');
            }

            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core sanitize_option_{option} hook for this plugin's canonical option.
            return apply_filters('sanitize_option_yuztra_at_settings', $src, 'yuztra_at_settings', $src);
        }
        if (function_exists('error_log')) {
            yuztra_settings_diag_log('[YUZ-DIAG][UPDATED_AT] empty payload, keeping previous option');
        }
        return $old_value;
    }
    if (function_exists('error_log')) {
        yuztra_settings_diag_log('[YUZ-DIAG][UPDATED_AT] accepting non-empty payload keys=' . implode(',', array_keys($new_value)));
    }
    return $new_value;
}, 9999, 3);

if (!function_exists('yuztra_settings_get_meta')) {
    function yuztra_settings_get_meta(): array {
        $raw  = get_option(yuztra_settings_meta_key(), []);
        $meta = is_array($raw) ? $raw : [];
        $version = (int) ($meta['version'] ?? 1);
        if ($version < 1) {
            $version = 1;
        }
        $last = (int) ($meta['last_changed'] ?? 0);
        if ($last <= 0) {
            $last = time();
        }
        if ($meta !== ['version' => $version, 'last_changed' => $last]) {
            update_option(yuztra_settings_meta_key(), ['version' => $version, 'last_changed' => $last], false);
        }
        return ['version' => $version, 'last_changed' => $last];
    }
}

if (!function_exists('yuztra_settings_bump_meta')) {
    function yuztra_settings_bump_meta(): array {
        $meta = yuztra_settings_get_meta();
        $meta['version'] = max(1, (int) $meta['version']) + 1;
        $meta['last_changed'] = time();
        update_option(yuztra_settings_meta_key(), $meta, false);
        return $meta;
    }
}

/* ========================================================================== *
 * SSOT HELPERS — LECTURE/ÉCRITURE CONSOLIDÉE AVEC VERROUS
 * ========================================================================== */

if (!function_exists('yuztra_settings_runtime_flush')) {
    function yuztra_settings_runtime_flush(): void {
        unset($GLOBALS['yuztra_settings_runtime_cache']);
    }
}

if (!function_exists('yuztra_settings_get_all')) {

    if (!function_exists('yuztra_settings_should_skip_canonical_write')) {
        function yuztra_settings_should_skip_canonical_write(string $canonical = ''): bool {
            if (defined('WP_CLI') && WP_CLI) {
                return false;
            }

            $action  = sanitize_key(yuztra_request_text('action'));
            $allowed = apply_filters('yuztra/canonical_write/allowed_actions', [
                'yuztra_ws_upd_settings',
                'yuztra_ws_upd_deflang',
                'yuztra_ws_upd_srclang',
                'yuztra_ws_upd_translatable',
                'yuztra_ls_upd_settings',
                'yuztra_sw_upd_settings',
                'yuztra_ts_upd_settings',
                'yuztra_at_upd_api_settings',
                'yuztra_te_upd_manual',
                'yuztra_te_upd_publish',
                'yuztra_save_translation',
                'yuztra_publish_translations',
                'yuztra_mass_publish',
            ]);

            $doing_ajax = defined('DOING_AJAX') && DOING_AJAX;
            $is_rest    = defined('REST_REQUEST') && REST_REQUEST;
            $is_admin   = function_exists('is_admin') && is_admin();

            if ($doing_ajax || $is_rest || $is_admin) {
                if ($action === '' || !in_array($action, $allowed, true)) {
                    return true;
                }
            }

            $legacy_sensitive = apply_filters(
                'yuztra/canonical_write/sensitive_actions',
                [
                    'yuztra_ls_upd_settings',
                    'yuztra_sw_upd_settings',
                ],
                $canonical
            );

            if (is_array($legacy_sensitive) && $action !== '' && in_array($action, $legacy_sensitive, true)) {
                return true;
            }

            return false;
        }
    }

    /**
     * Lecture consolidée FRONT-SAFE du SSOT 'yuz_tra_all_settings'
     * - Verrou de profondeur pour casser toute ré-entrance
     * - Normalisation SANS autres get_option()
     */
    function yuztra_settings_get_all(bool $force_refresh = false): array {
        static $depth = 0;
        if ($depth > 0) {
            // Déjà en cours : renvoyer cache ou squelette minimal
            return is_array($GLOBALS['yuztra_settings_runtime_cache'] ?? null)
                ? $GLOBALS['yuztra_settings_runtime_cache']
                : ['__meta' => ['version' => 1, 'last_changed' => time()]];
        }
        $depth++;

        try {
            if (!$force_refresh && is_array($GLOBALS['yuztra_settings_runtime_cache'] ?? null)) {
                return $GLOBALS['yuztra_settings_runtime_cache'];
            }

            $registry = yuztra_settings_registry();
            $cache    = [];
            foreach ($registry as $canonical => $config) {
                $raw        = get_option($canonical, null);
                $sanitized  = yuztra_settings_sanitize_section($canonical, $raw);
                $needs_sync = !is_array($raw) || $sanitized !== $raw;

                if (!is_array($raw)) {
                    $defaults = yuztra_settings_section_default($canonical);
                    if ($sanitized !== $defaults) {
                        $needs_sync = true;
                    }
                }

                if ($needs_sync) {
                    $skip = false;
                    if (
                        $canonical === 'yuztra_all_settings'
                        && function_exists('yuztra_settings_should_skip_canonical_write')
                        && yuztra_settings_should_skip_canonical_write($canonical)
                    ) {
                        $skip = true;
                    }
                    if ($skip) {
                        if (function_exists('clar_log')) {
                            clar_log('CANONICAL WRITE', __FUNCTION__ . '_skip', $canonical, ['context' => 'ajax_sensitive']);
                        }
                        $cache[$canonical] = $sanitized;
                        $alias = $config['alias'];
                        unset($cache[$alias]);
                        $cache[$alias] =& $cache[$canonical];
                        continue;
                    }
                    if (!yuztra_should_allow_canonical_write($canonical)) {
                        $cache[$canonical] = $sanitized;
                        $alias = $config['alias'];
                        unset($cache[$alias]);
                        $cache[$alias] =& $cache[$canonical];
                        continue;
                    }
                    if (function_exists('clar_log')) {
                        clar_log('CANONICAL WRITE', __FUNCTION__, $canonical, array_keys(is_array($sanitized) ? $sanitized : []));
                    }

                    if ($canonical === 'yuztra_at_settings') {
                        if (function_exists('error_log')) {
                            yuztra_settings_diag_log('[YUZ-DIAG][AT] POST_SHAPE=' . wp_json_encode(yuztra_diag_shape(yuztra_request_array('yuztra_at_settings'))));
                            yuztra_settings_diag_log('[YUZ-DIAG][AT] ACTION=' . yuztra_request_text('action'));
                        }
                    }

                    update_option($canonical, $sanitized, false);
                    if (function_exists('clar_log')) {
                        clar_log('CANONICAL WRITE', __FUNCTION__ . '_done', $canonical, array_keys(is_array($sanitized) ? $sanitized : []));
                    }
                }

                $cache[$canonical] = $sanitized;
                $alias = $config['alias'];
                unset($cache[$alias]);
                $cache[$alias] =& $cache[$canonical];
            }

            $cache['__meta'] = yuztra_settings_get_meta();
            $GLOBALS['yuztra_settings_runtime_cache'] = $cache;
            return $cache;
        } finally {
            $depth--;
        }
    }
}

if (!function_exists('yuztra_settings_update_all')) {
    /**
     * Écriture atomique du SSOT + mise à jour du cache runtime.
     * Verrou de profondeur pour éviter les cycles via hooks.
     */
    function yuztra_settings_update_all(array $desired): bool {
        static $depth = 0;
        if ($depth > 0) {
            // Refus de la ré-entrance écriture
            return false;
        }
        $depth++;

        try {
            $registry = yuztra_settings_registry();
            $current  = yuztra_settings_get_all();
            $writes   = [];

            foreach ($registry as $canonical => $config) {
                $alias = $config['alias'];
                if (array_key_exists($canonical, $desired)) {
                    $candidate = $desired[$canonical];
                } elseif (array_key_exists($alias, $desired)) {
                    $candidate = $desired[$alias];
                } else {
                    $candidate = $current[$canonical] ?? yuztra_settings_section_default($canonical);
                }
                $writes[$canonical] = yuztra_settings_sanitize_section($canonical, $candidate);
            }

            $dirty = false;
            foreach ($writes as $canonical => $value) {
                $existing = $current[$canonical] ?? null;
                if ($existing !== $value) {
                    $skip = false;
                    if (
                        $canonical === 'yuztra_all_settings'
                        && function_exists('yuztra_settings_should_skip_canonical_write')
                        && yuztra_settings_should_skip_canonical_write($canonical)
                    ) {
                        $skip = true;
                    }
                    if ($skip) {
                        if (function_exists('clar_log')) {
                            clar_log('CANONICAL WRITE', __FUNCTION__ . '_skip', $canonical, ['context' => 'ajax_sensitive']);
                        }
                        continue;
                    }
                    if ($canonical === 'yuztra_at_settings') {
                        if (function_exists('error_log')) {
                            $posted = yuztra_request_array('yuztra_at_settings');
                            yuztra_settings_diag_log('[YUZ-DIAG][AT] POST_SHAPE=' . wp_json_encode(yuztra_diag_shape($posted)));
                        }
                        $old_at = get_option('yuztra_at_settings', []);
                        if (function_exists('error_log')) {
                            yuztra_settings_diag_log('[YUZ-DIAG][AT] OLD_SHAPE=' . wp_json_encode(yuztra_diag_shape($old_at)));
                            if (function_exists('has_filter')) {
                                yuztra_settings_diag_log('[YUZ-DIAG][AT] HAS_SANITIZE=' . (int) has_filter('sanitize_option_yuztra_at_settings'));
                            }
                        }
                        if (function_exists('sanitize_option')) {
                            $value = sanitize_option('yuztra_at_settings', $value);
                            if (function_exists('error_log')) {
                                yuztra_settings_diag_log('[YUZ-DIAG][AT] AFTER_SAN_SHAPE=' . wp_json_encode(yuztra_diag_shape($value)));
                            }
                        }
                        if (empty($value['api_provider']) && !empty($value['api_adapter'])) {
                            $value['api_provider'] = $value['api_adapter'];
                        }
                        if (empty($value['url_to_load']) && !empty($value['libre_url'])) {
                            $value['url_to_load'] = $value['libre_url'];
                        }
                        foreach (['url_to_load','libre_url'] as $k) {
                            if (!empty($value[$k])) {
                                $value[$k] = preg_replace('#/translate/?$#','', trim($value[$k]));
                            }
                        }
                        if (function_exists('error_log')) {
                            $changed = [];
                            foreach ((array) $value as $k => $v) {
                                $ov = $old_at[$k] ?? null;
                                if ($ov !== $v) { $changed[] = $k; }
                            }
                            yuztra_settings_diag_log('[YUZ-DIAG][AT] CHANGED_KEYS=' . json_encode($changed));
                        }
                    }
                    if (!yuztra_should_allow_canonical_write($canonical)) {
                        continue;
                    }
                    if (function_exists('clar_log')) {
                        clar_log('CANONICAL WRITE', __FUNCTION__, $canonical, array_keys(is_array($value) ? $value : []));
                    }
                    update_option($canonical, $value, false);
                    if (function_exists('clar_log')) {
                        clar_log('CANONICAL WRITE', __FUNCTION__ . '_done', $canonical, array_keys(is_array($value) ? $value : []));
                    }
                    $dirty = true;
                }
            }

            $meta = $dirty ? yuztra_settings_bump_meta() : yuztra_settings_get_meta();
            $cache = [];
            foreach ($writes as $canonical => $value) {
                $alias = $registry[$canonical]['alias'];
                $cache[$canonical] = $value;
                unset($cache[$alias]);
                $cache[$alias] =& $cache[$canonical];
            }
            $cache['__meta'] = $meta;
            $GLOBALS['yuztra_settings_runtime_cache'] = $cache;

            if ($dirty && function_exists('do_action')) {
                do_action('yuztra_settings_sections_updated', array_keys($writes));
            }
            return true;
        } finally {
            $depth--;
        }
    }
}

if (!function_exists('yuztra_settings_update')) {
    /**
     * Mutateur fonctionnel : lit -> mutateur($current) -> écrit
     */
    function yuztra_settings_update(callable $mutator): bool {
        $current = yuztra_settings_get_all();
        $next    = $mutator($current);
        if (!is_array($next)) {
            return false;
        }
        return yuztra_settings_update_all($next);
    }
}

add_filter('sanitize_option_yuztra_at_settings', function ($value) {
    $value = is_array($value) ? $value : [];
    $old   = get_option('yuztra_at_settings', []);
    $context = yuztra_request_text('action');
    if (function_exists('error_log')) {
        yuztra_settings_diag_log('[YUZ-DIAG][SANITIZE_AT] context=' . ($context !== '' ? $context : 'none') . ' payload_shape=' . wp_json_encode(yuztra_diag_shape($value)));
    }

    foreach (['url_to_load', 'libre_url', 'custom_url'] as $key) {
        if (array_key_exists($key, $value)) {
            $raw = is_array($value[$key]) ? '' : (string) wp_unslash($value[$key]);
            $raw = preg_replace('#/translate/?$#', '', trim($raw));
            $value[$key] = esc_url_raw($raw);
        }
    }

    if (empty($value['url_to_load']) && !empty($value['libre_url'])) {
        $value['url_to_load'] = $value['libre_url'];
    }
    if (empty($value['libre_url']) && !empty($value['url_to_load'])) {
        $value['libre_url'] = $value['url_to_load'];
    }

    foreach (['url_to_load','libre_url','custom_url'] as $k) {
        if ((isset($value[$k]) && $value[$k] === '') && !empty($old[$k])) {
            $value[$k] = $old[$k];
        }
    }

    if (empty($value['api_provider']) && !empty($value['api_adapter'])) {
        $value['api_provider'] = $value['api_adapter'];
    }

    foreach (['libre_key', 'google_key', 'deepl_key', 'custom_key'] as $key) {
        if (!array_key_exists($key, $value)) {
            continue;
        }
        $val = trim((string) $value[$key]);
        if ($val === '' || $val === '***' || $val === '********') {
            $value[$key] = is_array($old) && array_key_exists($key, $old) ? $old[$key] : '';
        }
    }

    foreach (['enable_auto_translate', 'deepl_free', 'block_crawlers', 'log_queries'] as $key) {
        $value[$key] = !empty($value[$key]) ? 1 : 0;
    }

    $defaults = yuztra_settings_section_default('yuztra_at_settings');
    $base     = is_array($old) ? $old : [];

    return array_merge($defaults, $base, $value);
}, 5);

add_filter('option_yuztra_at_settings', function ($yuztra_opt) {
    if (function_exists('error_log')) {
        yuztra_settings_diag_log('[YUZ-DIAG][READ] ' . wp_json_encode(yuztra_diag_shape($yuztra_opt)));
    }
    $defaults = [
        'source_language_id'    => 0,
        'api_adapter'           => 'libretranslate',
        'url_to_load'           => '',
        'translation_mode'      => 'all',
        'cron_interval'         => 'daily',
        'enable_auto_translate' => 0,
        'api_provider'          => 'libretranslate',
        'libre_url'             => '',
        // phpcs:ignore PluginCheck.CodeAnalysis.AIProvider.DirectIntegration -- Explicit administrator-selected provider endpoint; no request occurs while reading options.
        'openai_url'            => 'https://api.openai.com/v1/chat/completions',
        'openai_key'            => '',
        'openai_model'          => '',
        'libre_key'             => '',
        'google_key'            => '',
        'google_project'        => '',
        'deepl_key'             => '',
        'deepl_free'            => 0,
        'custom_url'            => '',
        'custom_key'            => '',
        'custom_auth'           => 'none',
        'custom_method'         => 'POST',
        'custom_format'         => 'JSON',
        'alternatives'          => 3,
        'char_limit'            => 50000,
        'requests_limit'        => 100,
        'available_balance_usd'  => '',
        'minimum_balance_usd'    => '',
        'input_cost_usd_per_million' => '',
        'output_cost_usd_per_million' => '',
        'sale_price_usd_per_million' => '',
        'fixed_monthly_cost_usd' => '',
        'pricing_source'         => 'administrator estimate',
        'block_crawlers'        => 0,
        'log_queries'           => 0,
    ];
    return wp_parse_args(is_array($yuztra_opt) ? $yuztra_opt : [], $defaults);
}, 9);

/* ========================================================================== *
 * BRIDGE — FAÇADE D’OPTIONS HISTORIQUES
 * ========================================================================== */

if (!class_exists('YUZTRA_Options_Bridge')) {
    class YUZTRA_Options_Bridge {

        public static function init(): void {

    // 🔒 Empêche double initialisation ou interférence pendant migration
    static $initialized = false;
    if ($initialized) {
        if (function_exists('clar_log')) clar_log('⚠️ Bridge init() skipped — already initialized');
        return;
    }

    // ⏸ Si migration active, ne pas enregistrer les filtres d’écriture
    if (defined('YUZTRA_MIGRATION_MODE') && YUZTRA_MIGRATION_MODE === true) {
        if (function_exists('clar_log')) clar_log('⏸ Bridge hooks suspended (migration mode active)');
        $initialized = true;
        return;
    }

    $aliases = self::alias_map();

    foreach ($aliases as $alias => $canonical) {

        // 🔹 Lecture / fallback vers l’option canonique
        add_filter("option_{$alias}", function ($value) use ($alias, $canonical) {
            return YUZTRA_Options_Bridge::filter_alias($alias, $canonical);
        }, 10, 1);

        add_filter("default_option_{$alias}", function ($value) use ($alias, $canonical) {
            return YUZTRA_Options_Bridge::filter_alias($alias, $canonical);
        }, 10, 1);

        // 🔹 Blocage d’écriture — mais seulement si migration inactive
        add_filter("pre_update_option_{$alias}", function ($value, $old_value, $option) use ($alias) {
            if (defined('YUZTRA_MIGRATION_MODE') && YUZTRA_MIGRATION_MODE === true) {
                if (function_exists('clar_log')) clar_log("⏸ Bridge write filter bypassed for {$alias}");
                return $value; // Autoriser écriture directe
            }
            return YUZTRA_Options_Bridge::prevent_alias_write($alias, $value, $old_value, $option);
        }, 10, 3);
    }

    // 🔹 Gestion spéciale du conteneur global
    add_filter('option_yuztra_all_settings', [__CLASS__, 'filter_all_settings']);
    add_filter('default_option_yuztra_all_settings', [__CLASS__, 'filter_all_settings']);
    add_filter('pre_update_option_yuztra_all_settings', function ($value, $old_value, $option) {
        if (defined('YUZTRA_MIGRATION_MODE') && YUZTRA_MIGRATION_MODE === true) {
            if (function_exists('clar_log')) clar_log('⏸ Legacy all_settings write bypassed (migration mode)');
            return $value;
        }
        return YUZTRA_Options_Bridge::prevent_all_settings_write($value, $old_value, $option);
    }, 10, 3);

    if (function_exists('clar_log')) clar_log('✅ YUZTRA_Options_Bridge initialized successfully');
    $initialized = true;
}


        public static function get_defaults(string $group): array {
            $canonical = self::canonical_from($group);
            if (!$canonical) {
                return [];
            }
            return yuztra_settings_section_default($canonical);
        }

         private static function alias_map(): array {
    // 🔒 Mapping statique conforme à la table de référence UI ↔ Options
    return [
        'yuztra_general'        => 'yuztra_ws_settings',  // Website Languages
        'yuztra_settings'       => 'yuztra_ls_settings',  // Language Settings
        'yuztra_switcher'       => 'yuztra_sw_settings',  // Language Switcher
        'yuztra_site_settings'  => 'yuztra_ts_settings',  // Translate Site
        'yuztra_api_settings'   => 'yuztra_at_settings',  // Automatic Translation
        'yuztra_advanced'       => 'yuztra_av_settings',  // Advanced
        'yuztra_addons'         => 'yuztra_ad_settings',  // Add-ons
        'yuztra_licenses'       => 'yuztra_li_settings',  // Licenses
        'yuztra_ai'             => 'yuztra_ai_settings',  // AI Translation
        // 🔁 Fallback global (ancien conteneur monolithique)
        'yuztra_all_settings'   => 'yuztra_ws_settings',
        // 🧩 Compat noms hérités divers
        'yuztra_translation_site_settings' => 'yuztra_ts_settings',
        'yuztra_language_settings'    => 'yuztra_ls_settings',
        'yuztra_switcher_settings'    => 'yuztra_sw_settings',
    ];
}


        private static function canonical_from(string $name): ?string {
            $registry = yuztra_settings_registry();
            if (isset($registry[$name])) {
                return $name;
            }
            $aliases = self::alias_map();
            return $aliases[$name] ?? null;
        }

        private static function filter_alias(string $alias, string $canonical) {
            if (function_exists('clar_log') && in_array($alias, ['yuztra_translation_site_settings', 'yuztra_site_settings'], true)) {
                clar_log('LEGACY ACCESS', $alias);
            }
            return self::build_alias_payload($alias, $canonical);
        }

        private static function prevent_alias_write(string $alias, $new_value, $old_value, string $option) {
            if (defined('YUZTRA_MIGRATION_MODE') && YUZTRA_MIGRATION_MODE === true) {
                if (function_exists('clar_log')) { clar_log('⏸ Bridge update interception disabled', $alias); }
                return $new_value;
            }
            $canonical = self::canonical_from($alias);
            if ($canonical) {
                $sanitized = yuztra_settings_sanitize_section($canonical, is_array($new_value) ? $new_value : (array) $new_value);
                if (function_exists('clar_log')) {
                    clar_log('CANONICAL WRITE', __FUNCTION__, $canonical, array_keys(is_array($sanitized) ? $sanitized : []));
                }
                update_option($canonical, $sanitized, false);
                if (function_exists('clar_log')) {
                    clar_log('CANONICAL WRITE', __FUNCTION__ . '_done', $canonical, array_keys(is_array($sanitized) ? $sanitized : []));
                }
                if (function_exists('yuztra_settings_runtime_flush')) {
                    yuztra_settings_runtime_flush();
                }
            }
            self::log_blocked($alias);
            return $old_value;
        }

        public static function filter_all_settings($value) {
            return self::build_all_settings_payload();
        }

        public static function prevent_all_settings_write($new_value, $old_value, string $option) {
            if (defined('YUZTRA_MIGRATION_MODE') && YUZTRA_MIGRATION_MODE === true) {
                if (function_exists('clar_log')) { clar_log('⏸ Bridge update interception disabled', 'yuztra_all_settings'); }
                return $new_value;
            }
            if (is_array($new_value)) {
                $did_update = false;
                foreach (self::alias_map() as $alias => $canonical) {
                    if (!array_key_exists($alias, $new_value)) {
                        continue;
                    }
                    $sanitized = yuztra_settings_sanitize_section($canonical, $new_value[$alias]);
                    if (function_exists('clar_log')) {
                        clar_log('CANONICAL WRITE', __FUNCTION__, $canonical, array_keys(is_array($sanitized) ? $sanitized : []));
                    }
                    update_option($canonical, $sanitized, false);
                    if (function_exists('clar_log')) {
                        clar_log('CANONICAL WRITE', __FUNCTION__ . '_done', $canonical, array_keys(is_array($sanitized) ? $sanitized : []));
                    }
                    $did_update = true;
                }
                if ($did_update && function_exists('yuztra_settings_runtime_flush')) {
                    yuztra_settings_runtime_flush();
                }
            }
            self::log_blocked('yuztra_all_settings');
            return is_array($old_value) ? $old_value : self::build_all_settings_payload();
        }

        private static function build_all_settings_payload(): array {
            $payload = [];
            foreach (self::alias_map() as $alias => $canonical) {
                $payload[$alias] = self::build_alias_payload($alias, $canonical);
            }
            $payload['__meta'] = yuztra_settings_get_meta();
            return $payload;
        }

        private static function build_alias_payload(string $alias, string $canonical): array {
            $section = self::get_section($canonical);

            switch ($alias) {
                case 'yuztra_settings': {
                    $legacy = $section;
                    $api    = self::get_section('yuztra_at_settings');
                    $legacy['url_to_load']        = $api['url_to_load'] ?? '';
                    $legacy['source_language_id'] = $api['source_language_id'] ?? 0;
                    $legacy['api_adapter']        = $api['api_adapter'] ?? 'libretranslate';
                    $legacy = array_merge($legacy, self::legacy_switcher_patch(self::get_section('yuztra_sw_settings')));
                    return $legacy;
                }

                case 'yuztra_language_settings':
                    return [
                        'native_language_name' => self::bool_to_legacy_toggle(!empty($section['native_language_name'])),
                        'use_subdirectory'     => self::bool_to_legacy_toggle(!empty($section['use_subdirectory'])),
                        'force_lang_in_links'  => self::bool_to_legacy_toggle(!empty($section['force_lang_in_links'])),
                    ];

                case 'yuztra_switcher_settings':
                    return self::cast_switcher_for_legacy(self::get_section('yuztra_sw_settings'));

                case 'yuztra_switcher':
                    return self::get_section('yuztra_sw_settings');

                case 'yuztra_translation_site_settings':
                    return self::get_section('yuztra_ts_settings');

                default:
                    return $section;
            }
        }

        private static function get_section(string $canonical): array {
            $all = yuztra_settings_get_all();
            if (isset($all[$canonical]) && is_array($all[$canonical])) {
                return $all[$canonical];
            }
            return yuztra_settings_section_default($canonical);
        }

        private static function legacy_switcher_patch(array $switcher): array {
            $map = [
                'shortcode_enabled' => 'yuztra_shortcode_enabled',
                'shortcode_format'  => 'yuztra_shortcode_format',
                'menu_enabled'      => 'yuztra_menu_enabled',
                'menu_format'       => 'yuztra_menu_format',
                'floating_enabled'  => 'yuztra_floating_enabled',
                'floating_format'   => 'yuztra_floating_format',
                'floating_theme'    => 'yuztra_floating_theme',
                'floating_position' => 'yuztra_floating_position',
                'show_poweredby'    => 'yuztra_show_poweredby',
            ];
            $out = [];
            foreach ($map as $from => $to) {
                if (!array_key_exists($from, $switcher)) {
                    continue;
                }
                $value = $switcher[$from];
                if (in_array($from, ['shortcode_enabled','menu_enabled','floating_enabled','show_poweredby'], true)) {
                    $out[$to] = (bool) $value;
                } else {
                    $out[$to] = is_string($value) ? $value : strval($value);
                }
            }
            return $out;
        }

        private static function bool_to_legacy_toggle(bool $value): int {
            return $value ? 1 : 0;
        }

        private static function log_blocked(string $option): void {
            if (class_exists('\YUZTRA_Logger')) {
                (new \YUZTRA_Logger())->log('notice', 'Legacy option write prevented', ['option' => $option]);
            }
        }

        private static function cast_switcher_for_legacy(array $in): array {
            $out = [];
            $boolKeys = ['shortcode_enabled', 'menu_enabled', 'floating_enabled', 'show_poweredby'];
            foreach ($boolKeys as $k) {
                if (array_key_exists($k, $in)) { $out[$k] = !empty($in[$k]) ? 1 : 0; }
            }
            $strKeys = ['shortcode_format','menu_format','floating_format','floating_theme','floating_position'];
            foreach ($strKeys as $k) {
                if (array_key_exists($k, $in)) { $out[$k] = is_string($in[$k]) ? $in[$k] : strval($in[$k]); }
            }
            return $out;
        }
    }
}

/* ======================= DÉFECTIF SUPPLÉMENTAIRE (v2) ======================= */

add_action('updated_option', function($option, $old_value, $value){
  if ($option === 'yuztra_at_settings') {
    if (function_exists('error_log')) yuztra_settings_diag_log('UPDATED_AT=1');
  }
}, 10, 3);

// Éviter double traitement si des filtres bridge-level avaient été branchés ailleurs
remove_filter('pre_update_option_yuztra_at_settings', ['YUZTRA_Options_Bridge', 'filter_pre_update_at_settings'], 10);
remove_filter('pre_update_option_yuztra_ls_settings', ['YUZTRA_Options_Bridge', 'filter_pre_update_ls_settings'], 10);
remove_filter('pre_update_option_yuztra_sw_settings', ['YUZTRA_Options_Bridge', 'filter_pre_update_sw_settings'], 10);

// Préservation des clés masquées lors d'un update direct (défensif)
add_filter('pre_update_option_yuztra_at_settings', function($new, $old){
  $new = is_array($new) ? $new : [];
  $old = is_array($old) ? $old : [];

  foreach (['libre_key','google_key','deepl_key','custom_key'] as $k){
    if (array_key_exists($k, $new)) {
      $val = trim((string) $new[$k]);
      if ($val === '' || $val === '***' || $val === '********') {
        $new[$k] = $old[$k] ?? '';
      }
    }
  }

  return $new;
}, 9, 2);

/**
 * Minimal, safe, one-shot migration for AT settings.
 * If canonical AT settings are empty, try to hydrate from legacy sources
 * (yuz_tra_api_settings, yuz_tra_settings, yuz_tra_all_settings).
 */
add_action('init', function(){
    static $done = false; if ($done) return; $done = true;
    // Do not run during AJAX save or if canonical already populated
    $at = get_option('yuztra_at_settings', []);
    if (is_array($at) && array_filter($at)) { return; }

    $candidates = [];
    $legacy_api = get_option('yuztra_api_settings', []);
    if (is_array($legacy_api) && array_filter($legacy_api)) { $candidates[] = $legacy_api; }

    $legacy_ws = get_option('yuztra_settings', []);
    if (is_array($legacy_ws) && array_filter($legacy_ws)) {
        $subset = [];
        foreach (['url_to_load','source_language_id','api_adapter','libre_url','libre_key','google_key','google_project','deepl_key','deepl_free','custom_url','custom_key','custom_auth','custom_method','custom_format'] as $k) {
            if (isset($legacy_ws[$k])) { $subset[$k] = $legacy_ws[$k]; }
        }
        if ($subset) $candidates[] = $subset;
    }

    $all = get_option('yuztra_all_settings', []);
    if (is_array($all) && isset($all['yuztra_api_settings']) && is_array($all['yuztra_api_settings'])) {
        $candidates[] = $all['yuztra_api_settings'];
    }

    if (!$candidates) { return; }

    $merged = [];
    foreach ($candidates as $src) { $merged = array_merge($merged, (array) $src); }
    // Sanitize against the canonical schema
    if (!function_exists('yuztra_settings_sanitize_section')) { return; }
    $clean = yuztra_settings_sanitize_section('yuztra_at_settings', $merged);
    if (is_array($clean) && array_filter($clean)) {
        update_option('yuztra_at_settings', $clean, false);
        if (function_exists('error_log')) {
            yuztra_settings_diag_log('[YUZ-DIAG][AT-MIGRATE] canonical hydrated from legacy sources');
        }
    }
}, 20);

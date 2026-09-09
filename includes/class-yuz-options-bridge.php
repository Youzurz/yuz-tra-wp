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

if (!function_exists('yuz_tra_diag_enabled')) {
    function yuz_tra_diag_enabled(): bool {
        if (defined('YUZ_TRA_DIAG_FORCE')) {
            return (bool) YUZ_TRA_DIAG_FORCE;
        }
        if (defined('YUZ_TRA_DEBUG') && YUZ_TRA_DEBUG) {
            return true;
        }
        return defined('WP_DEBUG') && WP_DEBUG;
    }
}
if (!function_exists('yuz_tra_diag_log')) {
    function yuz_tra_diag_log(string $message): void {
        if (!yuz_tra_diag_enabled()) {
            return;
        }
        error_log($message);
    }
}

if (!function_exists('yuz_tra_diag_shape')) {
    /**
     * Return structural diagnostics without ever serializing setting values.
     * API credentials and customer configuration must not reach debug logs.
     */
    function yuz_tra_diag_shape($value): array {
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

if (!function_exists('yuz_tra_request_text')) {
    /**
     * Read a scalar request field as normalized text.
     */
    function yuz_tra_request_text(string $key): string {
        // Request data is only used for routing here; authorization is performed
        // by options.php or by the registered authenticated action handler.
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $value = $_POST[$key] ?? '';
        return is_string($value) ? sanitize_text_field(wp_unslash($value)) : '';
    }
}

// Autoriser l'action 'update' (submit Settings API via options.php)
add_filter('yuz/canonical_write/allowed_actions', function(array $actions){
    if (!in_array('update', $actions, true)) {
        $actions[] = 'update';
    }
    return $actions;
}, 1);

/* ========================================================================== *
 * SETTINGS SCHEMA HELPERS — CANONICAL STORAGE MAP
 * ========================================================================== */

// Mappe un groupe Settings API -> options canoniques autorisées
if (!function_exists('yuz_tra_allowed_group_map')) {
    function yuz_tra_allowed_group_map(): array {
        return [
            'yuz_tra_at_settings' => ['yuz_tra_at_settings'],
            'yuz_tra_general_settings_group'    => [
                'yuz_tra_ws_settings',
                'yuz_tra_ls_settings',
                'yuz_tra_sw_settings',
            ],
            'yuz_tra_ws_settings_group' => ['yuz_tra_ws_settings'],
            'yuz_tra_ls_settings_group' => ['yuz_tra_ls_settings'],
            'yuz_tra_sw_settings_group' => ['yuz_tra_sw_settings'],
            'yuz_tra_ts_settings_group' => ['yuz_tra_ts_settings'],
        ];
    }
}

add_filter('allowed_options', function (array $allowed) {
    foreach (yuz_tra_allowed_group_map() as $group => $opts) {
        $allowed[$group] = array_unique(array_merge($allowed[$group] ?? [], $opts));
    }
    static $logged = false;
    if (!$logged && function_exists('error_log')) {
        yuz_tra_diag_log('[YUZ-DIAG][ALLOWED_OPTIONS] merged canonical groups: ' . wp_json_encode(array_keys(yuz_tra_allowed_group_map())));
        $logged = true;
    }
    return $allowed;
}, 1);

add_filter('whitelist_options', function (array $allowed) {
    foreach (yuz_tra_allowed_group_map() as $group => $opts) {
        $allowed[$group] = array_unique(array_merge($allowed[$group] ?? [], $opts));
    }
    static $logged = false;
    if (!$logged && function_exists('error_log')) {
        yuz_tra_diag_log('[YUZ-DIAG][WHITELIST_OPTIONS] merged canonical groups');
        $logged = true;
    }
    return $allowed;
}, 1);

add_filter('allowed_options', function (array $allowed) {
    $allowed['yuz_tra_general_settings_group'] = [
        'yuz_tra_ws_settings',
        'yuz_tra_ls_settings',
        'yuz_tra_sw_settings',
    ];
    static $logged = false;
    if (!$logged && function_exists('error_log')) {
        yuz_tra_diag_log('[YUZ-DIAG][ALLOWED_OPTIONS] forced General group allowance');
        $logged = true;
    }
    return $allowed;
}, PHP_INT_MAX);

add_filter('whitelist_options', function (array $allowed) {
    $allowed['yuz_tra_general_settings_group'] = [
        'yuz_tra_ws_settings',
        'yuz_tra_ls_settings',
        'yuz_tra_sw_settings',
    ];
    static $logged = false;
    if (!$logged && function_exists('error_log')) {
        yuz_tra_diag_log('[YUZ-DIAG][WHITELIST_OPTIONS] forced General group allowance');
        $logged = true;
    }
    return $allowed;
}, PHP_INT_MAX);

foreach (['yuz_tra_ws_settings', 'yuz_tra_ls_settings', 'yuz_tra_sw_settings'] as $opt) {
    add_filter("pre_update_option_{$opt}", function ($new, $old) use ($opt) {
        $action = yuz_tra_request_text('action');
        $group  = yuz_tra_request_text('option_page');

        if ($action === 'update' && $group === 'yuz_tra_general_settings_group') {
            $nonce = isset($_POST['_wpnonce']) && is_string($_POST['_wpnonce']) ? sanitize_text_field(wp_unslash($_POST['_wpnonce'])) : '';
            if (!current_user_can('manage_options') || !wp_verify_nonce($nonce, 'yuz_tra_general_settings_group-options')) {
                return $old;
            }
            $raw = isset($_POST[$opt]) && is_array($_POST[$opt]) ? wp_unslash($_POST[$opt]) : [];
            if (empty($raw)) {
                if (function_exists('error_log')) {
                    yuz_tra_diag_log('[YUZ-DIAG][PRE_UPDATE][' . $opt . '] general submit but payload empty -> keeping previous');
                }
                return $old;
            }

            unset($raw['_sentinel']);

            $base = is_array($old) ? $old : [];
            $merged = array_merge($base, $raw);

            if ($opt === 'yuz_tra_ls_settings') {
                foreach (['native_language_name', 'use_subdirectory', 'force_lang_in_links'] as $key) {
                    $merged[$key] = !empty($merged[$key]) ? '1' : '';
                }
            } elseif ($opt === 'yuz_tra_sw_settings') {
                foreach (['shortcode_enabled', 'menu_enabled', 'floating_enabled', 'show_poweredby'] as $key) {
                    $merged[$key] = !empty($merged[$key]) ? '1' : '';
                }
            } elseif ($opt === 'yuz_tra_ws_settings') {
                if (method_exists('YUZ_General', 'sanitize_ws_settings')) {
                    $merged = YUZ_General::sanitize_ws_settings($merged);
                }
            }

            if (function_exists('error_log')) {
                yuz_tra_diag_log('[YUZ-DIAG][PRE_UPDATE][' . $opt . '] overriding with POST payload keys=' . implode(',', array_keys($merged)));
            }

            $merged = yuz_settings_sanitize_section($opt, $merged);

            // Synchronize legacy containers used by canonical migration logic.
            if ($opt === 'yuz_tra_ws_settings') {
                update_option('yuz_tra_general', $merged, false);
                update_option('yuz_tra_general_settings', $merged, false);
            } elseif ($opt === 'yuz_tra_ls_settings') {
                $legacy = [
                    'native_language_name' => !empty($merged['native_language_name']) ? '1' : '',
                    'use_subdirectory'     => !empty($merged['use_subdirectory']) ? '1' : '',
                    'force_lang_in_links'  => !empty($merged['force_lang_in_links']) ? '1' : '',
                ];
                update_option('yuz_tra_settings', $legacy, false);
            } elseif ($opt === 'yuz_tra_sw_settings') {
                update_option('yuz_tra_switcher', $merged, false);
                update_option('yuz_tra_switcher_settings', $merged, false);
            }

            if (function_exists('yuz_settings_runtime_flush')) {
                yuz_settings_runtime_flush();
            }
            return $merged;
        }

        if (!is_array($new)) {
            $decoded = is_string($new) ? json_decode($new, true) : null;
            if (is_array($decoded)) {
                if (function_exists('error_log')) {
                    yuz_tra_diag_log('[YUZ-DIAG][PRE_UPDATE][' . $opt . '] decoded JSON payload');
                }
                return $decoded;
            }
            if (function_exists('error_log')) {
                yuz_tra_diag_log('[YUZ-DIAG][PRE_UPDATE][' . $opt . '] rejected non-array payload outside submit');
            }
            return $old;
        }

        if (function_exists('error_log')) {
            yuz_tra_diag_log('[YUZ-DIAG][PRE_UPDATE][' . $opt . '] passive accept keys=' . implode(',', array_keys($new)));
        }
        return $new;
    }, 0, 2);
}

add_filter('option_yuz_tra_ls_settings', function ($opt) {
    $opt = is_array($opt) ? $opt : [];
    foreach (['native_language_name', 'use_subdirectory', 'force_lang_in_links'] as $k) {
        $opt[$k] = !empty($opt[$k]) ? '1' : '';
    }
    if (function_exists('error_log')) {
        yuz_tra_diag_log('[YUZ-DIAG][OPTION_READ][LS] normalized toggles=' . wp_json_encode($opt));
    }
    return $opt;
}, 9);

add_filter('option_yuz_tra_sw_settings', function ($opt) {
    $opt = is_array($opt) ? $opt : [];
    foreach (['shortcode_enabled', 'menu_enabled', 'floating_enabled', 'show_poweredby'] as $k) {
        $opt[$k] = !empty($opt[$k]) ? '1' : '';
    }
    if (function_exists('error_log')) {
        yuz_tra_diag_log('[YUZ-DIAG][OPTION_READ][SW] normalized toggles=' . wp_json_encode($opt));
    }
    return $opt;
}, 9);


add_action('admin_init', function () {
    static $logged = false;
    if ($logged) {
        return;
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        return;
    }
    $action = yuz_tra_request_text('action');
    $group  = yuz_tra_request_text('option_page');
    if ($action !== 'update' || $group !== 'yuz_tra_general_settings_group') {
        return;
    }

    $snapshot = [
        'action' => $action,
        'option_page' => $group,
        'root_keys' => array_map('sanitize_key', array_keys($_POST)),
        'payload' => [],
    ];

    foreach (['yuz_tra_ws_settings', 'yuz_tra_ls_settings', 'yuz_tra_sw_settings'] as $opt) {
        $entry = [
            'isset' => array_key_exists($opt, $_POST),
            'type'  => array_key_exists($opt, $_POST) ? gettype($_POST[$opt]) : 'missing',
        ];
        if (isset($_POST[$opt])) {
            if (is_array($_POST[$opt])) {
                $entry['keys'] = array_map('sanitize_key', array_keys($_POST[$opt]));
            } else {
                $entry['length'] = is_string($_POST[$opt]) ? strlen($_POST[$opt]) : null;
            }
        }
        $snapshot['payload'][$opt] = $entry;
    }

    if (function_exists('error_log')) {
        yuz_tra_diag_log('[YUZ-DIAG][POST_SNAPSHOT] ' . wp_json_encode($snapshot));
    }
    $logged = true;
}, 5);

foreach (['yuz_tra_ws_settings', 'yuz_tra_ls_settings', 'yuz_tra_sw_settings'] as $opt) {
    remove_all_filters("sanitize_option_{$opt}");
    add_filter("sanitize_option_{$opt}", function ($value, $option, $original) use ($opt) {
        if (function_exists('error_log')) {
            $log = [
                'value_type'    => gettype($value),
                'original_type' => gettype($original),
                'filters'       => [],
                'stack'         => [],
            ];
            $log['value_shape']    = yuz_tra_diag_shape($value);
            $log['original_shape'] = yuz_tra_diag_shape($original);
            $filters = $GLOBALS['wp_filter']["sanitize_option_{$opt}"] ?? null;
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
                $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 12);
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
            yuz_tra_diag_log('[YUZ-DIAG][SANITIZE][' . $opt . "]\n" . print_r($log, true));
        }

        $is_general_post = isset($_POST['option_page']) && $_POST['option_page'] === 'yuz_tra_general_settings_group';
        if (!$is_general_post) {
            $existing = get_option($opt, []);
            if (function_exists('error_log')) {
                yuz_tra_diag_log('[YUZ-DIAG][SANITIZE][' . $opt . '] bypassed (no general POST) keeping existing payload');
            }
            return is_array($existing) ? $existing : [];
        }

        if (!is_array($value)) {
            if (isset($_POST[$opt]) && is_array($_POST[$opt])) {
                $value = wp_unslash($_POST[$opt]);
            } elseif (is_string($value)) {
                $decoded = json_decode($value, true);
                $value = is_array($decoded) ? $decoded : [];
            } else {
                $value = [];
            }
        }

        if ($opt === 'yuz_tra_ls_settings') {
            foreach (['native_language_name', 'use_subdirectory', 'force_lang_in_links'] as $key) {
                $value[$key] = !empty($value[$key]) ? '1' : '';
            }
        } elseif ($opt === 'yuz_tra_sw_settings') {
            foreach (['shortcode_enabled', 'menu_enabled', 'floating_enabled', 'show_poweredby'] as $key) {
                $value[$key] = !empty($value[$key]) ? '1' : '';
            }
        }
        unset($value['_sentinel']);

        return $value;
    }, 5, 3);
}


if (!function_exists('yuz_settings_registry')) {
    function yuz_settings_registry(): array {
        static $registry = null;
        if ($registry !== null) {
            return $registry;
        }

        $registry = [
            'yuz_tra_ws_settings' => [
                'alias'  => 'yuz_tra_general',
                'type'   => 'assoc',
                'fields' => [
                    'yuz_tra_default_language'       => ['type' => 'string',       'default' => 'en_US'],
                    'yuz_tra_source_language'        => ['type' => 'string',       'default' => 'fr_FR'],
                    'yuz_tra_translatable_languages' => ['type' => 'array_string', 'default' => []],
                    'yuz_tra_slug'                   => ['type' => 'array_string', 'default' => []],
                    'yuz_tra_code'                   => ['type' => 'array_string', 'default' => []],
                ],
            ],
            'yuz_tra_ls_settings' => [
                'alias'  => 'yuz_tra_settings',
                'type'   => 'assoc',
                'fields' => [
                    'native_language_name' => ['type' => 'string_toggle', 'default' => ''],
                    'use_subdirectory'     => ['type' => 'string_toggle', 'default' => ''],
                    'force_lang_in_links'  => ['type' => 'string_toggle', 'default' => ''],
                ],
            ],
            'yuz_tra_sw_settings' => [
                'alias'  => 'yuz_tra_switcher',
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
            'yuz_tra_ts_settings' => [
                'alias'  => 'yuz_tra_site_settings',
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
            'yuz_tra_at_settings' => [
                'alias'  => 'yuz_tra_api_settings',
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
            'yuz_tra_av_settings' => [
                'alias'  => 'yuz_tra_advanced',
                'type'   => 'assoc',
                'fields' => [
                    'fix_dynamic_content'         => ['type' => 'bool', 'default' => false],
                    'disable_dynamic_translation' => ['type' => 'bool', 'default' => false],
                ],
            ],
            'yuz_tra_ad_settings' => [
                'alias'     => 'yuz_tra_addons',
                'type'      => 'list',
                'item_type' => 'string',
                'default'   => [],
            ],
            'yuz_tra_li_settings' => [
                'alias'     => 'yuz_tra_licenses',
                'type'      => 'list',
                'item_type' => 'string',
                'default   ' => [],
            ],
            'yuz_tra_ai_settings' => [
                'alias'  => 'yuz_tra_ai',
                'type'   => 'assoc',
                'fields' => [
                    'enabled' => ['type' => 'bool', 'default' => false],
                ],
            ],
        ];

        return $registry;
    }
}

if (!function_exists('yuz_settings_registry_lookup')) {
    function yuz_settings_registry_lookup(): array {
        static $lookup = null;
        if ($lookup !== null) {
            return $lookup;
        }

        $lookup = [];
        foreach (yuz_settings_registry() as $canonical => $config) {
            $alias = $config['alias'];
            $lookup[$canonical] = ['canonical' => $canonical, 'alias' => $alias, 'config' => $config];
            $lookup[$alias]     = ['canonical' => $canonical, 'alias' => $alias, 'config' => $config];
        }
        return $lookup;
    }
}

if (!function_exists('yuz_settings_meta_key')) {
    function yuz_settings_meta_key(): string {
        return 'yuz_settings_meta';
    }
}

if (!function_exists('yuz_is_cli_ctx')) {
    function yuz_is_cli_ctx(): bool {
        return (defined('WP_CLI') && WP_CLI) || PHP_SAPI === 'cli';
    }
}

if (!function_exists('yuz_is_at_save_ctx')) {
    function yuz_is_at_save_ctx(): bool {
        $p    = $_POST ?? [];
        $act  = isset($p['action']) ? (string) $p['action'] : '';
        $page = isset($p['option_page']) ? (string) $p['option_page'] : '';

        if (!empty($p['yuz_tra_at_settings']) && is_array($p['yuz_tra_at_settings'])) {
            return true;
        }
        if (in_array($act, ['yuz_tra_at_upd_settings', 'yuz_tra_at_upd_api_settings', 'update'], true)) {
            return true;
        }
        if ($page === 'yuz_tra_at_settings') {
            return true;
        }
        return false;
    }
}

if (!function_exists('yuz_is_publish_flow_action')) {
    function yuz_is_publish_flow_action(string $action): bool {
        return in_array($action, ['yuz_save_translation', 'yuz_publish_translations', 'yuz_mass_publish'], true);
    }
}

// --- Helper : détection d'un submit Settings API de YUZ ---
if (!function_exists('yuz_is_settings_api_submit')) {
    function yuz_is_settings_api_submit(): bool {
        if (empty($_POST)) return false;
        $action = isset($_POST['action']) ? (string) $_POST['action'] : '';
        if ($action !== 'update') return false;

        $option_page = isset($_POST['option_page']) ? sanitize_key(wp_unslash($_POST['option_page'])) : '';
        if ($option_page === '') return false;

        // Groupes connus (au cas où tu en as plusieurs)
        $allowed_groups = [
            'yuz_tra_general_settings_group',      // General (Website Languages / Switcher / etc.)
            'yuz_tra_at_settings',   // Automatic Translation
            'yuz_tra_ts_settings_group',   // Translate Site (si séparé)
            'yuz_tra_ws_settings_group',
            'yuz_tra_ls_settings_group',
            'yuz_tra_sw_settings_group',
        ];
        if (in_array($option_page, $allowed_groups, true)) {
            if (function_exists('error_log')) {
                yuz_tra_diag_log('[YUZ-DIAG][SETTINGS_API_SUBMIT] recognized allowed group ' . $option_page);
            }
            return true;
        }

        // Filet de sécurité : tout groupe au format yuz_tra_*_settings(_group)
        $match = (bool) preg_match('/^yuz_tra_[a-z0-9_]+_settings(_group)?$/i', $option_page);
        if ($match && function_exists('error_log')) {
            yuz_tra_diag_log('[YUZ-DIAG][SETTINGS_API_SUBMIT] matched fallback pattern for group ' . $option_page);
        }
        return $match;
    }
}


if (!function_exists('yuz_bridge_allowed_actions')) {
    function yuz_bridge_allowed_actions(): array {
        return apply_filters(
            'yuz/canonical_write/allowed_actions',
            [
                'update', // << essentiel pour la Settings API (options.php)
                'yuz_tra_ws_upd_settings',
                'yuz_tra_ws_upd_deflang',
                'yuz_tra_ws_upd_srclang',
                'yuz_tra_ws_upd_translatable',
                'yuz_tra_ls_upd_settings',
                'yuz_tra_sw_upd_settings',
                'yuz_tra_ts_upd_settings',
                'yuz_tra_at_upd_api_settings',
                'yuz_tra_te_upd_manual',
                'yuz_tra_te_upd_publish',
                'yuz_save_translation',
                'yuz_publish_translations',
                'yuz_mass_publish',
            ]
        );
    }
}

// --- Garde principale : quand autoriser l'écriture canonique ---
if (!function_exists('yuz_should_allow_canonical_write')) {
    function yuz_should_allow_canonical_write(string $canonical): bool {
        if (function_exists('error_log')) {
            yuz_tra_diag_log('[YUZ-DIAG][GUARD] evaluating canonical=' . $canonical . ' action=' . ($_POST['action'] ?? ''));
        }
        if (defined('WP_CLI') && WP_CLI) {
            if (function_exists('error_log')) {
                yuz_tra_diag_log('[YUZ-GUARD] Blocked canonical write in CLI for ' . $canonical);
            }
            return false;
        }

        $action = isset($_POST['action']) ? (string) $_POST['action'] : '';
        $group  = isset($_POST['option_page']) ? sanitize_key(wp_unslash($_POST['option_page'])) : '';

        if ($action === 'update' && $group === 'yuz_tra_general_settings_group') {
            if (function_exists('error_log')) {
                yuz_tra_diag_log('[YUZ-DIAG][GUARD] General submit detected, canonical=' . $canonical . ' group=' . $group);
            }
            return in_array($canonical, [
                'yuz_tra_ws_settings',
                'yuz_tra_ls_settings',
                'yuz_tra_sw_settings',
            ], true);
        }

        if (defined('DOING_AJAX') && DOING_AJAX && $action === 'heartbeat') {
            if (function_exists('error_log')) {
                yuz_tra_diag_log('[YUZ-GUARD] Blocked canonical write during heartbeat for ' . $canonical);
            }
            return false;
        }

        if ($action === 'update' && $group !== '') {
            $map = yuz_tra_allowed_group_map();
            if (isset($map[$group]) && in_array($canonical, $map[$group], true)) {
                if (function_exists('error_log')) {
                    yuz_tra_diag_log('[YUZ-GUARD] Allow canonical write via options.php for ' . $canonical . ' (group=' . $group . ')');
                }
                return true;
            }
        }

        $allowed    = yuz_bridge_allowed_actions();
        $doing_ajax = defined('DOING_AJAX') && DOING_AJAX;
        $is_admin   = function_exists('is_admin') && is_admin();
        $is_rest    = defined('REST_REQUEST') && REST_REQUEST;
        if (($doing_ajax || $is_admin || $is_rest) && $action && !in_array($action, $allowed, true)) {
            if (function_exists('error_log')) {
                yuz_tra_diag_log('[YUZ-GUARD] Blocked canonical write (action=' . $action . ') for ' . $canonical);
            }
            return false;
        }

        if ($canonical === 'yuz_tra_at_settings' && !yuz_is_at_save_ctx()) {
            if ($action && yuz_is_publish_flow_action($action)) {
                if (function_exists('error_log')) {
                    yuz_tra_diag_log('[YUZ-GUARD] Allow AT canonical write during publish action ' . $action);
                }
            } else {
                if (function_exists('error_log')) {
                    yuz_tra_diag_log('[YUZ-GUARD] Skipped canonical write (not AT submit) for ' . $canonical);
                }
                return false;
            }
        }

        if (function_exists('error_log')) {
            yuz_tra_diag_log('[YUZ-DIAG][GUARD] default allow for canonical=' . $canonical);
        }
        return true;
    }
}

add_action('admin_init', function () {
    foreach (yuz_tra_allowed_group_map() as $group => $opts) {
        foreach ($opts as $opt) {
            register_setting(
                $group,
                $opt,
                [
                    'type'              => 'array',
                    'sanitize_callback' => static function ($value) use ($opt) {
                        return yuz_settings_sanitize_section($opt, $value);
                    },
                    'default'           => [],
                ]
            );
            if (function_exists('error_log')) {
                yuz_tra_diag_log('[YUZ-DIAG][REGISTER_SETTING] bound ' . $opt . ' to group ' . $group);
            }
        }
    }
}, 0);

add_action('admin_init', function () {
    foreach (['yuz_tra_ws_settings', 'yuz_tra_ls_settings', 'yuz_tra_sw_settings'] as $opt) {
        $sanitize = static function ($value) use ($opt) {
            return yuz_settings_sanitize_section($opt, $value);
        };
        if ($opt === 'yuz_tra_ws_settings' && method_exists('YUZ_General', 'sanitize_ws_settings')) {
            $sanitize = ['YUZ_General', 'sanitize_ws_settings'];
        }
        register_setting('yuz_tra_general_settings_group', $opt, [
            'type'              => 'array',
            'sanitize_callback' => $sanitize,
            'default'           => [],
        ]);
        if (function_exists('error_log')) {
            yuz_tra_diag_log('[YUZ-DIAG][REGISTER_SETTING] enforced General group registration for ' . $opt);
        }
    }
}, 0);

remove_all_filters('sanitize_option_yuz_tra_at_settings');

add_filter('sanitize_option_yuz_tra_at_settings', function ($value, $option = null, $original = null) {
    if (is_array($original) && !empty($original)) {
        if (function_exists('error_log')) {
            yuz_tra_diag_log('[YUZ-DIAG][SANITIZE_AT] using original payload from options.php');
        }
        $value = $original;
    }

    if ((!is_array($value) || empty($value)) && isset($_POST['yuz_tra_at_settings']) && is_array($_POST['yuz_tra_at_settings'])) {
        if (function_exists('error_log')) {
            yuz_tra_diag_log('[YUZ-DIAG][SANITIZE_AT] fallback to POST data');
        }
        $value = wp_unslash($_POST['yuz_tra_at_settings']);
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

    $old = get_option('yuz_tra_at_settings', []);
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
        yuz_tra_diag_log('[YUZ-DIAG][SANITIZE_AT] final_shape=' . wp_json_encode(yuz_tra_diag_shape($value)));
    }
    return $value;
}, 9999, 3);

add_filter('pre_update_option_yuz_tra_at_settings', function ($new_value, $old_value, $option) {
    if (!is_array($new_value) || empty($new_value)) {
        $src = null;
        if (isset($_POST['yuz_tra_at_settings']) && is_array($_POST['yuz_tra_at_settings'])) {
            $src = wp_unslash($_POST['yuz_tra_at_settings']);
        }
        if (is_array($src) && !empty($src)) {
            if (function_exists('error_log')) {
                yuz_tra_diag_log('[YUZ-DIAG][UPDATED_AT] recovering from empty value via pre_update');
            }
            return apply_filters('sanitize_option_yuz_tra_at_settings', $src, 'yuz_tra_at_settings', $src);
        }
        if (function_exists('error_log')) {
            yuz_tra_diag_log('[YUZ-DIAG][UPDATED_AT] empty payload, keeping previous option');
        }
        return $old_value;
    }
    if (function_exists('error_log')) {
        yuz_tra_diag_log('[YUZ-DIAG][UPDATED_AT] accepting non-empty payload keys=' . implode(',', array_keys($new_value)));
    }
    return $new_value;
}, 9999, 3);

if (!function_exists('yuz_settings_get_meta')) {
    function yuz_settings_get_meta(): array {
        $raw  = get_option(yuz_settings_meta_key(), []);
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
            update_option(yuz_settings_meta_key(), ['version' => $version, 'last_changed' => $last], false);
        }
        return ['version' => $version, 'last_changed' => $last];
    }
}

if (!function_exists('yuz_settings_bump_meta')) {
    function yuz_settings_bump_meta(): array {
        $meta = yuz_settings_get_meta();
        $meta['version'] = max(1, (int) $meta['version']) + 1;
        $meta['last_changed'] = time();
        update_option(yuz_settings_meta_key(), $meta, false);
        return $meta;
    }
}

/* ========================================================================== *
 * SSOT HELPERS — LECTURE/ÉCRITURE CONSOLIDÉE AVEC VERROUS
 * ========================================================================== */

if (!function_exists('yuz_settings_runtime_flush')) {
    function yuz_settings_runtime_flush(): void {
        unset($GLOBALS['yuz_settings_runtime_cache']);
    }
}

if (!function_exists('yuz_settings_get_all')) {

    if (!function_exists('yuz_settings_should_skip_canonical_write')) {
        function yuz_settings_should_skip_canonical_write(string $canonical = ''): bool {
            if (defined('WP_CLI') && WP_CLI) {
                return false;
            }

            $action  = is_string($_POST['action'] ?? null) ? (string) $_POST['action'] : '';
            $allowed = apply_filters('yuz/canonical_write/allowed_actions', [
                'yuz_tra_ws_upd_settings',
                'yuz_tra_ws_upd_deflang',
                'yuz_tra_ws_upd_srclang',
                'yuz_tra_ws_upd_translatable',
                'yuz_tra_ls_upd_settings',
                'yuz_tra_sw_upd_settings',
                'yuz_tra_ts_upd_settings',
                'yuz_tra_at_upd_api_settings',
                'yuz_tra_te_upd_manual',
                'yuz_tra_te_upd_publish',
                'yuz_save_translation',
                'yuz_publish_translations',
                'yuz_mass_publish',
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
                'yuz/canonical_write/sensitive_actions',
                [
                    'yuz_tra_ls_upd_settings',
                    'yuz_tra_sw_upd_settings',
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
    function yuz_settings_get_all(bool $force_refresh = false): array {
        static $depth = 0;
        if ($depth > 0) {
            // Déjà en cours : renvoyer cache ou squelette minimal
            return is_array($GLOBALS['yuz_settings_runtime_cache'] ?? null)
                ? $GLOBALS['yuz_settings_runtime_cache']
                : ['__meta' => ['version' => 1, 'last_changed' => time()]];
        }
        $depth++;

        try {
            if (!$force_refresh && is_array($GLOBALS['yuz_settings_runtime_cache'] ?? null)) {
                return $GLOBALS['yuz_settings_runtime_cache'];
            }

            $registry = yuz_settings_registry();
            $cache    = [];
            foreach ($registry as $canonical => $config) {
                $raw        = get_option($canonical, null);
                $sanitized  = yuz_settings_sanitize_section($canonical, $raw);
                $needs_sync = !is_array($raw) || $sanitized !== $raw;

                if (!is_array($raw)) {
                    $defaults = yuz_settings_section_default($canonical);
                    if ($sanitized !== $defaults) {
                        $needs_sync = true;
                    }
                }

                if ($needs_sync) {
                    $skip = false;
                    if (
                        $canonical === 'yuz_tra_all_settings'
                        && function_exists('yuz_settings_should_skip_canonical_write')
                        && yuz_settings_should_skip_canonical_write($canonical)
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
                    if (!yuz_should_allow_canonical_write($canonical)) {
                        $cache[$canonical] = $sanitized;
                        $alias = $config['alias'];
                        unset($cache[$alias]);
                        $cache[$alias] =& $cache[$canonical];
                        continue;
                    }
                    if (function_exists('clar_log')) {
                        clar_log('CANONICAL WRITE', __FUNCTION__, $canonical, array_keys(is_array($sanitized) ? $sanitized : []));
                    }

                    if ($canonical === 'yuz_tra_at_settings') {
                        if (function_exists('error_log')) {
                            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Structural diagnostics only; no values are read or logged.
                            yuz_tra_diag_log('[YUZ-DIAG][AT] POST_SHAPE=' . wp_json_encode(yuz_tra_diag_shape($_POST['yuz_tra_at_settings'] ?? [])));
                            yuz_tra_diag_log('[YUZ-DIAG][AT] ACTION=' . yuz_tra_request_text('action'));
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

            $cache['__meta'] = yuz_settings_get_meta();
            $GLOBALS['yuz_settings_runtime_cache'] = $cache;
            return $cache;
        } finally {
            $depth--;
        }
    }
}

if (!function_exists('yuz_settings_update_all')) {
    /**
     * Écriture atomique du SSOT + mise à jour du cache runtime.
     * Verrou de profondeur pour éviter les cycles via hooks.
     */
    function yuz_settings_update_all(array $desired): bool {
        static $depth = 0;
        if ($depth > 0) {
            // Refus de la ré-entrance écriture
            return false;
        }
        $depth++;

        try {
            $registry = yuz_settings_registry();
            $current  = yuz_settings_get_all();
            $writes   = [];

            foreach ($registry as $canonical => $config) {
                $alias = $config['alias'];
                if (array_key_exists($canonical, $desired)) {
                    $candidate = $desired[$canonical];
                } elseif (array_key_exists($alias, $desired)) {
                    $candidate = $desired[$alias];
                } else {
                    $candidate = $current[$canonical] ?? yuz_settings_section_default($canonical);
                }
                $writes[$canonical] = yuz_settings_sanitize_section($canonical, $candidate);
            }

            $dirty = false;
            foreach ($writes as $canonical => $value) {
                $existing = $current[$canonical] ?? null;
                if ($existing !== $value) {
                    $skip = false;
                    if (
                        $canonical === 'yuz_tra_all_settings'
                        && function_exists('yuz_settings_should_skip_canonical_write')
                        && yuz_settings_should_skip_canonical_write($canonical)
                    ) {
                        $skip = true;
                    }
                    if ($skip) {
                        if (function_exists('clar_log')) {
                            clar_log('CANONICAL WRITE', __FUNCTION__ . '_skip', $canonical, ['context' => 'ajax_sensitive']);
                        }
                        continue;
                    }
                    if ($canonical === 'yuz_tra_at_settings') {
                        if (function_exists('error_log')) {
                            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Structural diagnostics only; no values are logged.
                            $posted = $_POST['yuz_tra_at_settings'] ?? [];
                            yuz_tra_diag_log('[YUZ-DIAG][AT] POST_SHAPE=' . wp_json_encode(yuz_tra_diag_shape($posted)));
                        }
                        $old_at = get_option('yuz_tra_at_settings', []);
                        if (function_exists('error_log')) {
                            yuz_tra_diag_log('[YUZ-DIAG][AT] OLD_SHAPE=' . wp_json_encode(yuz_tra_diag_shape($old_at)));
                            if (function_exists('has_filter')) {
                                yuz_tra_diag_log('[YUZ-DIAG][AT] HAS_SANITIZE=' . (int) has_filter('sanitize_option_yuz_tra_at_settings'));
                            }
                        }
                        if (function_exists('sanitize_option')) {
                            $value = sanitize_option('yuz_tra_at_settings', $value);
                            if (function_exists('error_log')) {
                                yuz_tra_diag_log('[YUZ-DIAG][AT] AFTER_SAN_SHAPE=' . wp_json_encode(yuz_tra_diag_shape($value)));
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
                            yuz_tra_diag_log('[YUZ-DIAG][AT] CHANGED_KEYS=' . json_encode($changed));
                        }
                    }
                    if (!yuz_should_allow_canonical_write($canonical)) {
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

            $meta = $dirty ? yuz_settings_bump_meta() : yuz_settings_get_meta();
            $cache = [];
            foreach ($writes as $canonical => $value) {
                $alias = $registry[$canonical]['alias'];
                $cache[$canonical] = $value;
                unset($cache[$alias]);
                $cache[$alias] =& $cache[$canonical];
            }
            $cache['__meta'] = $meta;
            $GLOBALS['yuz_settings_runtime_cache'] = $cache;

            if ($dirty && function_exists('do_action')) {
                do_action('yuz_settings_sections_updated', array_keys($writes));
            }
            return true;
        } finally {
            $depth--;
        }
    }
}

if (!function_exists('yuz_settings_update')) {
    /**
     * Mutateur fonctionnel : lit -> mutateur($current) -> écrit
     */
    function yuz_settings_update(callable $mutator): bool {
        $current = yuz_settings_get_all();
        $next    = $mutator($current);
        if (!is_array($next)) {
            return false;
        }
        return yuz_settings_update_all($next);
    }
}

add_filter('sanitize_option_yuz_tra_at_settings', function ($value) {
    $value = is_array($value) ? $value : [];
    $old   = get_option('yuz_tra_at_settings', []);
    $context = yuz_tra_request_text('action');
    if (function_exists('error_log')) {
        yuz_tra_diag_log('[YUZ-DIAG][SANITIZE_AT] context=' . ($context !== '' ? $context : 'none') . ' payload_shape=' . wp_json_encode(yuz_tra_diag_shape($value)));
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

    $defaults = yuz_settings_section_default('yuz_tra_at_settings');
    $base     = is_array($old) ? $old : [];

    return array_merge($defaults, $base, $value);
}, 5);

add_filter('option_yuz_tra_at_settings', function ($opt) {
    if (function_exists('error_log')) {
        yuz_tra_diag_log('[YUZ-DIAG][READ] ' . wp_json_encode(yuz_tra_diag_shape($opt)));
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
    return wp_parse_args(is_array($opt) ? $opt : [], $defaults);
}, 9);

/* ========================================================================== *
 * BRIDGE — FAÇADE D’OPTIONS HISTORIQUES
 * ========================================================================== */

if (!class_exists('YUZ_Options_Bridge')) {
    class YUZ_Options_Bridge {

        public static function init(): void {

    // 🔒 Empêche double initialisation ou interférence pendant migration
    static $initialized = false;
    if ($initialized) {
        if (function_exists('clar_log')) clar_log('⚠️ Bridge init() skipped — already initialized');
        return;
    }

    // ⏸ Si migration active, ne pas enregistrer les filtres d’écriture
    if (defined('YUZ_MIGRATION_MODE') && YUZ_MIGRATION_MODE === true) {
        if (function_exists('clar_log')) clar_log('⏸ Bridge hooks suspended (migration mode active)');
        $initialized = true;
        return;
    }

    $aliases = self::alias_map();

    foreach ($aliases as $alias => $canonical) {

        // 🔹 Lecture / fallback vers l’option canonique
        add_filter("option_{$alias}", function ($value) use ($alias, $canonical) {
            return YUZ_Options_Bridge::filter_alias($alias, $canonical);
        }, 10, 1);

        add_filter("default_option_{$alias}", function ($value) use ($alias, $canonical) {
            return YUZ_Options_Bridge::filter_alias($alias, $canonical);
        }, 10, 1);

        // 🔹 Blocage d’écriture — mais seulement si migration inactive
        add_filter("pre_update_option_{$alias}", function ($value, $old_value, $option) use ($alias) {
            if (defined('YUZ_MIGRATION_MODE') && YUZ_MIGRATION_MODE === true) {
                if (function_exists('clar_log')) clar_log("⏸ Bridge write filter bypassed for {$alias}");
                return $value; // Autoriser écriture directe
            }
            return YUZ_Options_Bridge::prevent_alias_write($alias, $value, $old_value, $option);
        }, 10, 3);
    }

    // 🔹 Gestion spéciale du conteneur global
    add_filter('option_yuz_tra_all_settings', [__CLASS__, 'filter_all_settings']);
    add_filter('default_option_yuz_tra_all_settings', [__CLASS__, 'filter_all_settings']);
    add_filter('pre_update_option_yuz_tra_all_settings', function ($value, $old_value, $option) {
        if (defined('YUZ_MIGRATION_MODE') && YUZ_MIGRATION_MODE === true) {
            if (function_exists('clar_log')) clar_log('⏸ Legacy all_settings write bypassed (migration mode)');
            return $value;
        }
        return YUZ_Options_Bridge::prevent_all_settings_write($value, $old_value, $option);
    }, 10, 3);

    if (function_exists('clar_log')) clar_log('✅ YUZ_Options_Bridge initialized successfully');
    $initialized = true;
}


        public static function get_defaults(string $group): array {
            $canonical = self::canonical_from($group);
            if (!$canonical) {
                return [];
            }
            return yuz_settings_section_default($canonical);
        }

         private static function alias_map(): array {
    // 🔒 Mapping statique conforme à la table de référence UI ↔ Options
    return [
        'yuz_tra_general'        => 'yuz_tra_ws_settings',  // Website Languages
        'yuz_tra_settings'       => 'yuz_tra_ls_settings',  // Language Settings
        'yuz_tra_switcher'       => 'yuz_tra_sw_settings',  // Language Switcher
        'yuz_tra_site_settings'  => 'yuz_tra_ts_settings',  // Translate Site
        'yuz_tra_api_settings'   => 'yuz_tra_at_settings',  // Automatic Translation
        'yuz_tra_advanced'       => 'yuz_tra_av_settings',  // Advanced
        'yuz_tra_addons'         => 'yuz_tra_ad_settings',  // Add-ons
        'yuz_tra_licenses'       => 'yuz_tra_li_settings',  // Licenses
        'yuz_tra_ai'             => 'yuz_tra_ai_settings',  // AI Translation
        // 🔁 Fallback global (ancien conteneur monolithique)
        'yuz_tra_all_settings'   => 'yuz_tra_ws_settings',
        // 🧩 Compat noms hérités divers
        'yuz_translation_site_settings' => 'yuz_tra_ts_settings',
        'yuz_tra_language_settings'    => 'yuz_tra_ls_settings',
        'yuz_tra_switcher_settings'    => 'yuz_tra_sw_settings',
    ];
}


        private static function canonical_from(string $name): ?string {
            $registry = yuz_settings_registry();
            if (isset($registry[$name])) {
                return $name;
            }
            $aliases = self::alias_map();
            return $aliases[$name] ?? null;
        }

        private static function filter_alias(string $alias, string $canonical) {
            if (function_exists('clar_log') && in_array($alias, ['yuz_translation_site_settings', 'yuz_tra_site_settings'], true)) {
                clar_log('LEGACY ACCESS', $alias, debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2));
            }
            return self::build_alias_payload($alias, $canonical);
        }

        private static function prevent_alias_write(string $alias, $new_value, $old_value, string $option) {
            if (defined('YUZ_MIGRATION_MODE') && YUZ_MIGRATION_MODE === true) {
                if (function_exists('clar_log')) { clar_log('⏸ Bridge update interception disabled', $alias); }
                return $new_value;
            }
            $canonical = self::canonical_from($alias);
            if ($canonical) {
                $sanitized = yuz_settings_sanitize_section($canonical, is_array($new_value) ? $new_value : (array) $new_value);
                if (function_exists('clar_log')) {
                    clar_log('CANONICAL WRITE', __FUNCTION__, $canonical, array_keys(is_array($sanitized) ? $sanitized : []));
                }
                update_option($canonical, $sanitized, false);
                if (function_exists('clar_log')) {
                    clar_log('CANONICAL WRITE', __FUNCTION__ . '_done', $canonical, array_keys(is_array($sanitized) ? $sanitized : []));
                }
                if (function_exists('yuz_settings_runtime_flush')) {
                    yuz_settings_runtime_flush();
                }
            }
            self::log_blocked($alias);
            return $old_value;
        }

        public static function filter_all_settings($value) {
            return self::build_all_settings_payload();
        }

        public static function prevent_all_settings_write($new_value, $old_value, string $option) {
            if (defined('YUZ_MIGRATION_MODE') && YUZ_MIGRATION_MODE === true) {
                if (function_exists('clar_log')) { clar_log('⏸ Bridge update interception disabled', 'yuz_tra_all_settings'); }
                return $new_value;
            }
            if (is_array($new_value)) {
                $did_update = false;
                foreach (self::alias_map() as $alias => $canonical) {
                    if (!array_key_exists($alias, $new_value)) {
                        continue;
                    }
                    $sanitized = yuz_settings_sanitize_section($canonical, $new_value[$alias]);
                    if (function_exists('clar_log')) {
                        clar_log('CANONICAL WRITE', __FUNCTION__, $canonical, array_keys(is_array($sanitized) ? $sanitized : []));
                    }
                    update_option($canonical, $sanitized, false);
                    if (function_exists('clar_log')) {
                        clar_log('CANONICAL WRITE', __FUNCTION__ . '_done', $canonical, array_keys(is_array($sanitized) ? $sanitized : []));
                    }
                    $did_update = true;
                }
                if ($did_update && function_exists('yuz_settings_runtime_flush')) {
                    yuz_settings_runtime_flush();
                }
            }
            self::log_blocked('yuz_tra_all_settings');
            return is_array($old_value) ? $old_value : self::build_all_settings_payload();
        }

        private static function build_all_settings_payload(): array {
            $payload = [];
            foreach (self::alias_map() as $alias => $canonical) {
                $payload[$alias] = self::build_alias_payload($alias, $canonical);
            }
            $payload['__meta'] = yuz_settings_get_meta();
            return $payload;
        }

        private static function build_alias_payload(string $alias, string $canonical): array {
            $section = self::get_section($canonical);

            switch ($alias) {
                case 'yuz_tra_settings': {
                    $legacy = $section;
                    $api    = self::get_section('yuz_tra_at_settings');
                    $legacy['url_to_load']        = $api['url_to_load'] ?? '';
                    $legacy['source_language_id'] = $api['source_language_id'] ?? 0;
                    $legacy['api_adapter']        = $api['api_adapter'] ?? 'libretranslate';
                    $legacy = array_merge($legacy, self::legacy_switcher_patch(self::get_section('yuz_tra_sw_settings')));
                    return $legacy;
                }

                case 'yuz_tra_language_settings':
                    return [
                        'native_language_name' => self::bool_to_legacy_toggle(!empty($section['native_language_name'])),
                        'use_subdirectory'     => self::bool_to_legacy_toggle(!empty($section['use_subdirectory'])),
                        'force_lang_in_links'  => self::bool_to_legacy_toggle(!empty($section['force_lang_in_links'])),
                    ];

                case 'yuz_tra_switcher_settings':
                    return self::cast_switcher_for_legacy(self::get_section('yuz_tra_sw_settings'));

                case 'yuz_tra_switcher':
                    return self::get_section('yuz_tra_sw_settings');

                case 'yuz_translation_site_settings':
                    return self::get_section('yuz_tra_ts_settings');

                default:
                    return $section;
            }
        }

        private static function get_section(string $canonical): array {
            $all = yuz_settings_get_all();
            if (isset($all[$canonical]) && is_array($all[$canonical])) {
                return $all[$canonical];
            }
            return yuz_settings_section_default($canonical);
        }

        private static function legacy_switcher_patch(array $switcher): array {
            $map = [
                'shortcode_enabled' => 'yuz_shortcode_enabled',
                'shortcode_format'  => 'yuz_shortcode_format',
                'menu_enabled'      => 'yuz_menu_enabled',
                'menu_format'       => 'yuz_menu_format',
                'floating_enabled'  => 'yuz_floating_enabled',
                'floating_format'   => 'yuz_floating_format',
                'floating_theme'    => 'yuz_floating_theme',
                'floating_position' => 'yuz_floating_position',
                'show_poweredby'    => 'yuz_show_poweredby',
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
            if (class_exists('\YUZ_Logger')) {
                (new \YUZ_Logger())->log('notice', 'Legacy option write prevented', ['option' => $option]);
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
  if ($option === 'yuz_tra_at_settings') {
    if (function_exists('error_log')) yuz_tra_diag_log('UPDATED_AT=1');
  }
}, 10, 3);

// Éviter double traitement si des filtres bridge-level avaient été branchés ailleurs
remove_filter('pre_update_option_yuz_tra_at_settings', ['YUZ_Options_Bridge', 'filter_pre_update_at_settings'], 10);
remove_filter('pre_update_option_yuz_tra_ls_settings', ['YUZ_Options_Bridge', 'filter_pre_update_ls_settings'], 10);
remove_filter('pre_update_option_yuz_tra_sw_settings', ['YUZ_Options_Bridge', 'filter_pre_update_sw_settings'], 10);

// Préservation des clés masquées lors d'un update direct (défensif)
add_filter('pre_update_option_yuz_tra_at_settings', function($new, $old){
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
    $at = get_option('yuz_tra_at_settings', []);
    if (is_array($at) && array_filter($at)) { return; }

    $candidates = [];
    $legacy_api = get_option('yuz_tra_api_settings', []);
    if (is_array($legacy_api) && array_filter($legacy_api)) { $candidates[] = $legacy_api; }

    $legacy_ws = get_option('yuz_tra_settings', []);
    if (is_array($legacy_ws) && array_filter($legacy_ws)) {
        $subset = [];
        foreach (['url_to_load','source_language_id','api_adapter','libre_url','libre_key','google_key','google_project','deepl_key','deepl_free','custom_url','custom_key','custom_auth','custom_method','custom_format'] as $k) {
            if (isset($legacy_ws[$k])) { $subset[$k] = $legacy_ws[$k]; }
        }
        if ($subset) $candidates[] = $subset;
    }

    $all = get_option('yuz_tra_all_settings', []);
    if (is_array($all) && isset($all['yuz_tra_api_settings']) && is_array($all['yuz_tra_api_settings'])) {
        $candidates[] = $all['yuz_tra_api_settings'];
    }

    if (!$candidates) { return; }

    $merged = [];
    foreach ($candidates as $src) { $merged = array_merge($merged, (array) $src); }
    // Sanitize against the canonical schema
    if (!function_exists('yuz_settings_sanitize_section')) { return; }
    $clean = yuz_settings_sanitize_section('yuz_tra_at_settings', $merged);
    if (is_array($clean) && array_filter($clean)) {
        update_option('yuz_tra_at_settings', $clean, false);
        if (function_exists('error_log')) {
            yuz_tra_diag_log('[YUZ-DIAG][AT-MIGRATE] canonical hydrated from legacy sources');
        }
    }
}, 20);

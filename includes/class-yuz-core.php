<?php
/**
 * Class YUZ_Core
 * Point d’entrée / bootstrap du plugin YUZ-TRA.
 *
 * Orchestration PDCA :
 * - PLAN : poser le contexte (DB, Settings, Languages, Environment, QoS Services)
 * - DO : dispatcher selon contexte (ADMIN/AJAX/CLI/FRONT)
 * - CHECK : analyser les écarts (guards/health, logs)
 * - ACT : actions d’amélioration (PDCA / diagnostics)
 *
 * @package YUZ_Translation
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

if ( ! defined( 'ABSPATH' ) ) { exit; }
// MODIF: Fondations minimales (interfaces secours logs/guards) — Phase 1: stubs Null* pour casser circularités
require_once YUZ_TRA_INCLUDES . 'class-yuz-contracts.php';
require_once YUZ_TRA_INCLUDES . 'class-yuz-fallbacks.php';
require_once YUZ_TRA_INCLUDES . 'class-yuz-logger.php';
require_once YUZ_TRA_INCLUDES . 'class-yuz-health-check.php';
// MODIF: Dépendances “PLAN” (sans exécuter de traduction) — Phase 1: aucun I/O lourde
require_once YUZ_TRA_INCLUDES . 'class-yuz-db.php';
require_once YUZ_TRA_INCLUDES . 'class-yuz-settings.php';
require_once YUZ_TRA_INCLUDES . 'class-yuz-languages.php';
require_once YUZ_TRA_INCLUDES . 'class-yuz-environment.php';
require_once YUZ_TRA_INCLUDES . 'class-yuz-ajax.php';
require_once YUZ_TRA_INCLUDES . 'class-yuz-services.php'; // QoS worker singletons services
require_once YUZ_TRA_INCLUDES . 'class-yuz-capabilities.php';
class YUZ_Core {
const PLUGIN_VERSION = '1.0.0';
private static $initialized = false;
/** Memo du PLAN pour CHECK/ACT */
private static array $plan_state = [
'issues' => [],
'viable' => true,
'default_lang' => null,
'source_lang' => null,
'flags' => [],
    ];

private static function can_run_runtime_maintenance(): bool {
if (defined('WP_CLI') && WP_CLI) {
return true;
        }
if (defined('DOING_CRON') && DOING_CRON) {
return true;
        }
if (defined('DOING_AJAX') && DOING_AJAX) {
return false;
        }
return is_admin();
    }
/* ========================================================================================
     * INIT === PLAN → DO → CHECK → ACT
     * ====================================================================================== */
public static function init(): void {
if (self::$initialized) {
return;
        }
self::$initialized = true;
// MODIF: Memory check early (Phase 1: constants/logs memory check)
if (function_exists('memory_get_usage') && memory_get_usage(true) > 512 * 1024 * 1024) {
self::flag_issue('Mémoire > 512MB détectée au démarrage. ADMIN-only.');
        }
/* =======================
         * PLAN — préparer le terrain
         * ======================= */
self::ensure_default_settings(); // Préconditions légères (non disruptives) : on LOG on dégrade si besoin
if (!defined('YUZ_TRA_INCLUDES') || !defined('YUZ_TRA_PLUGIN_FILE')) {
self::flag_issue('Constantes critiques manquantes (YUZ_TRA_INCLUDES / YUZ_TRA_PLUGIN_FILE). ADMIN-only.');
        }
// DB: s’assurer des tables sans bloquer
try {
$logger = class_exists('YUZ_Logger') ? new YUZ_Logger() : null;
$health = class_exists('YUZ_Health_Check') ? new YUZ_Health_Check($logger) : null;
if (class_exists('YUZ_DB')) {
$db = new YUZ_DB($logger, $health);
if (method_exists($db, 'ensure_tables')) {
// MODIF: Gate sur tables_ok pour éviter DDL runtime (Phase 4)
if (!get_option('tables_ok', false) && self::can_run_runtime_maintenance()) {
$db->ensure_tables();
update_option('tables_ok', true); // Flag après succès
                    }
                }
            }
        } catch (\Throwable $e) {
self::flag_issue('DB ensure_tables: '.$e->getMessage());
        }
// QoS / Worker (enregistre filtres/worker, pas de traduction ici)
try {
if (class_exists('YUZ_Services') && method_exists('YUZ_Services','init')) {
YUZ_Services::init();
            }
        } catch (\Throwable $e) {
self::flag_issue('YUZ_Services::init: '.$e->getMessage());
        }
// Environment (avec catalogue langues cohérent via Services si dispo)
try {
if (class_exists('YUZ_Environment') && method_exists('YUZ_Environment','init')) {
if (class_exists('YUZ_Services') && method_exists('YUZ_Services','languages')) {
YUZ_Environment::init( YUZ_Services::languages() );
                } else {
YUZ_Environment::init();
                }
            }
        } catch (\Throwable $e) {
self::flag_issue('YUZ_Environment::init: '.$e->getMessage());
        }
// Verrouiller contexte langues (source/default) à partir du manager DB si dispo
$default_lang = null;
$source_lang  = null;
try {
    if (class_exists('YUZ_Services') && method_exists('YUZ_Services','languages')) {
        $L = YUZ_Services::languages();
        if (is_object($L)) {
            if (method_exists($L, 'get_default_language')) { $default_lang = (string) $L->get_default_language(); }
            if (method_exists($L, 'get_source_language'))  { $source_lang  = (string) $L->get_source_language(); }
        }
    }
} catch (\Throwable $e) {
    self::log_colored('warning', 'Languages manager unavailable: '.$e->getMessage());
}
// Fallback propre depuis l’option consolidée (yuz_tra_all_settings -> general)
if (empty($default_lang) || empty($source_lang)) {
    $all = yuz_settings_get_all();
    $gen = is_array($all) && isset($all['yuz_tra_general']) ? (array)$all['yuz_tra_general'] : [];
    $detectedDef = (class_exists('YUZ_Environment') && method_exists('YUZ_Environment','get_detected_default')) ? YUZ_Environment::get_detected_default() : null;
    $default_lang = $default_lang ?: ($detectedDef ?: ($gen['yuz_tra_default_language'] ?? get_locale()));
    $source_lang  = $source_lang  ?: ($gen['yuz_tra_source_language']  ?? $default_lang);
}
$flags = ['switcher_enabled' => true];
if (class_exists('YUZ_Settings') && method_exists('YUZ_Settings','runtime_flags')) {
try {
$flags = array_merge($flags, (array) YUZ_Settings::runtime_flags());
            } catch (\Throwable $e) {
self::flag_issue('runtime_flags: '.$e->getMessage());
            }
        }
self::$plan_state['default_lang'] = $default_lang;
self::$plan_state['source_lang']  = $source_lang;
self::$plan_state['flags'] = $flags;
// Critère minimal de viabilité du PLAN
if (empty($default_lang) || empty($source_lang)) {
self::flag_issue('Langues invalides (default/source) après PLAN. ADMIN-only.');
        }
/* =======================
         * DO — dérouler selon contexte (XOR)
         * ======================= */
if (!self::$plan_state['viable']) {
// Dégradation douce : brancher uniquement l’ADMIN pour que l’utilisateur corrige
add_action('admin_notices', function () {
$msg = 'YUZ-TRA a démarré en mode dégradé (ADMIN-only) :<br>'.esc_html(implode(' | ', self::$plan_state['issues']));
echo '<div class="notice notice-error"><p><strong>YUZ-TRA:</strong> ' . wp_kses_post( $msg ) . '</p></div>';
            });
self::register_admin_hooks(self::$plan_state['flags']);
        } else {
self::dispatch_context(self::$plan_state['flags']);
        }
/* =======================
         * CHECK — analyser & tracer
         * ======================= */
try {
if (class_exists('YUZ_Health_Check') && method_exists('YUZ_Health_Check','ensure')) {
// Exemple : valider encore une fois la cohérence des langues (trace, pas de blocage)
YUZ_Health_Check::ensure(!empty(self::$plan_state['default_lang']) && !empty(self::$plan_state['source_lang']), 'CHECK: défaut/source manquants', __METHOD__);
            }
self::log_colored('info', 'CHECK — plan_state', self::$plan_state);
        } catch (\Throwable $e) {
self::log_colored('warning', 'CHECK exception: '.$e->getMessage());
        }
/* =======================
         * ACT — amélioration continue
         * ======================= */
do_action('yuz_core_boot_complete', [
'viable' => self::$plan_state['viable'],
'issues' => self::$plan_state['issues'],
        ]);
// (Le PDCA manager/diagnostic est déjà chargé dans la branche ADMIN via register_admin_hooks)
self::log_colored('success', 'YUZ_Core — bootstrap terminé (PDCA).');
    }
/* ========================================================================================
     * DISPATCHER (XOR)
     * ====================================================================================== */
private static function dispatch_context(array $flags): void {
if (defined('DOING_AJAX') && DOING_AJAX) {
self::handle_ajax_branch($flags);
return;
        }
if (is_admin()) {
self::register_admin_hooks($flags);
return;
        }
if (defined('WP_CLI') && WP_CLI) {
self::register_cli_commands($flags);
return;
        }
self::register_frontend_hooks($flags);
    }
/* ========================================================================================
     * FRONTEND (DO)
     * ====================================================================================== */
private static function register_frontend_hooks(array $flags): void {
if (empty($flags['switcher_enabled'])) {
self::log_colored('info', 'Frontend désactivé par flags → skip');
return;
        }
        if (class_exists('YUZ_Front_Buffer')) {
            try {
                YUZ_Front_Buffer::init();
            } catch (\Throwable $e) {
                self::log_colored('warning', 'YUZ_Front_Buffer::init: ' . $e->getMessage());
            }
        }
if (class_exists('YUZ_Ajax') && method_exists('YUZ_Ajax','init')) {
try {
YUZ_Ajax::init();
            } catch (\Throwable $e) {
self::log_colored('warning','YUZ_Ajax::init(front): '.$e->getMessage());
            }
        }
        $frontend_classes = [
            'YUZ_Assets',
            'YUZ_Ajax',
            'YUZ_Frontend',
            'YUZ_Switcher',
            'YUZ_Blocks',
            'YUZ_Translation_Manager',
            'YUZ_Rewrite',
            'YUZ_Editor',
            'YUZ_Admin_Bar',
        ];
self::load_and_init_classes($frontend_classes, 'frontend');
    }
/* ========================================================================================
     * ADMIN (PLAN UI)
     * ====================================================================================== */
private static function register_admin_hooks(array $flags): void {
if (!is_admin()) {
return;
        }
        $admin_classes = [
            'YUZ_Settings',
            'YUZ_Assets',
            'YUZ_Renderer',
            'YUZ_General',
            'YUZ_Languages',
            'YUZ_Translate_Site',
            'YUZ_Automatic_Translation',
            'YUZ_Translation_AI',
            'YUZ_Advanced',
            'YUZ_Licenses',
            'YUZ_Addons',
            'YUZ_Blocks',
            'YUZ_String_Admin',
            'YUZ_Ajax',
            // 'YUZ_PDCA_Manager', // optional module not present; suppress warnings
        ];
        // Gate IA: prefer consolidated storage, fallback to standalone legacy
        $all_settings  = yuz_settings_get_all();
        $site_settings = is_array($all_settings) && isset($all_settings['yuz_tra_site_settings'])
            ? (array)$all_settings['yuz_tra_site_settings']
            : (array) get_option('yuz_tra_site_settings', ['enable_ai' => '0']);
        $ai_enabled = !empty($site_settings['enable_ai']) || !empty($site_settings['enable_youzuruz']);
        if ( $ai_enabled && interface_exists('\\YUZTRA\\Interfaces\\AIInterface') ) {
            $admin_classes[] = 'YUZ_Translation_AI';
        } else {
            self::log_colored('info', 'AI module disabled (flag or interface missing)');
        }
self::load_and_init_classes($admin_classes, 'admin');
// Chargement conditionnel du sidecar Diagnostic (à la racine, pas dans includes/)
$diag_file = plugin_dir_path(YUZ_TRA_PLUGIN_FILE) . 'yuz-tra-diagnostic.php';
if (file_exists($diag_file)) {
require_once $diag_file; // sidecar
if (class_exists('YUZ_Diagnostic') && method_exists('YUZ_Diagnostic','init')) {
try { YUZ_Diagnostic::init(); } catch (\Throwable $e) {
self::log_colored('warning',"YUZ_Diagnostic::init a échoué: ".$e->getMessage());
                }
            } else {
self::log_colored('info','Sidecar présent mais classe/init absents → no-op');
            }
        } else {
self::log_colored('info','Sidecar diagnostic non présent (optionnel) → no-op');
        }
    }
/* ========================================================================================
     * AJAX (DO ponctuel)
     * ====================================================================================== */
private static function handle_ajax_branch(array $flags): void {
if (class_exists('YUZ_Ajax') && method_exists('YUZ_Ajax','init')) {
try {
YUZ_Ajax::init();
            } catch (\Throwable $e) {
self::log_colored('warning','YUZ_Ajax::init(ajax): '.$e->getMessage());
            }
        }
        $manager_file = YUZ_TRA_INCLUDES . 'class-yuz-api-manager.php';
        if (file_exists($manager_file)) {
            require_once $manager_file;
            if (class_exists('YUZ_API_Manager') && method_exists('YUZ_API_Manager','init')) {
                try {
                    YUZ_API_Manager::init();
                } catch (\Throwable $e) {
                    self::log_colored('critical','API TM::init(ajax): '.$e->getMessage());
                }
            } else {
                self::log_colored('critical','YUZ_API_Manager absent ou sans init() → no-op');
            }
        } else {
            self::log_colored('critical','Fichier manquant: class-yuz-api-manager.php');
        }
    }
/* ========================================================================================
     * CLI (DO batch)
     * ====================================================================================== */
private static function register_cli_commands(array $flags): void {
    if (!defined('WP_CLI') || !WP_CLI) {
        return;
    }

    // 🔹 Warm include pour que le CLI voie les classes (utilisé par wp yuz check)
    // On garde le chargement **dans** le plugin → auto-suffisant.
    $inc = defined('YUZ_TRA_INCLUDES') ? YUZ_TRA_INCLUDES : plugin_dir_path(YUZ_TRA_PLUGIN_FILE) . 'includes/';
    foreach (['class-yuz-url-converter.php', 'class-yuz-rewrite.php'] as $file) {
        $path = $inc . $file;
        if (file_exists($path)) {
            require_once $path;
        }
    }

    // Optionnel mais safe en CLI: initialiser la réécriture (n’envoie pas d’en-têtes en CLI)
    if (class_exists('YUZ_Rewrite') && method_exists('YUZ_Rewrite', 'init')) {
        try { YUZ_Rewrite::init(); } catch (\Throwable $e) {
            self::log_colored('warning', 'YUZ_Rewrite::init (CLI) a échoué: ' . $e->getMessage());
        }
    }

    // Charge aussi les éventuelles commandes CLI du plugin (si présentes)
    $cli_classes = ['YUZ_CLI_Commands']; // garde ta liste si tu en as
    self::load_and_init_classes($cli_classes, 'cli');
}

/* ========================================================================================
     * LOADER générique (inclut init) intégrité
     * ====================================================================================== */
private static function load_and_init_classes(array $classes, string $context): void {
require_once YUZ_TRA_INCLUDES . 'class-yuz-contracts.php';
$integrity_report = [];
        $alt_files = [
            // Allow singular filename for a plural class name (backward-compat)
            // Keep alias for safety but primary file is class-yuz-licenses.php
            'YUZ_Licenses' => 'class-yuz-licenses.php',
            // Frontend loader loads the active runtime implementation.
            'YUZ_Frontend' => 'class-yuz-frontend.php',
        ];
        foreach ($classes as $class) {
            $file = 'class-' . strtolower(str_replace('_', '-', $class)) . '.php';
            $path = YUZ_TRA_INCLUDES . $file;
            if (!file_exists($path)) {
                // Try explicit alias mapping first
                if (isset($alt_files[$class])) {
                    $alt = YUZ_TRA_INCLUDES . $alt_files[$class];
                    if (file_exists($alt)) {
                        $path = $alt;
                        $file = basename($alt);
                    }
                }
                // Generic singularization fallback (licenses -> license)
                if (!file_exists($path)) {
                    $alt2 = YUZ_TRA_INCLUDES . preg_replace('/licenses(\.php)$/', 'license$1', $file);
                    if ($alt2 !== YUZ_TRA_INCLUDES . $file && file_exists($alt2)) {
                        $path = $alt2;
                        $file = basename($alt2);
                    }
                }
            }
            if (file_exists($path)) {
                require_once $path;
                self::log_colored('success', "Included {$file} for {$context}.");
            } else {
                self::log_colored('warning', "File {$file} missing for {$context}.");
                continue;
            }
self::log_colored('debug', "Memory after including {$file} ({$context}): " . round(memory_get_usage(true)/1048576,2) . ' MB');
if (class_exists($class) && method_exists($class, 'init')) {
try {
call_user_func([$class, 'init']);
                } catch (\Throwable $e) {
self::log_colored('warning', "{$class}::init {$context}: ".$e->getMessage());
                }
$integrity_report[] = "{$class}";
self::log_colored('success', "Initialized {$class} for {$context}.");
            } else {
self::log_colored('warning', "{$class} sans méthode init() ({$context}).");
            }
self::log_colored('debug', "Memory after init {$class} ({$context}): " . round(memory_get_usage(true)/1048576,2) . ' MB');
        }
self::log_colored('info', "Classes loaded for {$context}: " . (empty($integrity_report) ? 'none' : implode(', ', $integrity_report)));
    }
/* ========================================================================================
     * Utils
     * ====================================================================================== */
private static function flag_issue(string $msg): void {
self::$plan_state['issues'][] = $msg;
self::$plan_state['viable'] = false;
self::log_colored('critical', $msg);
    }
    public static function log_colored($level, $message, $context = []): void {
        // Level gating to avoid noisy logs in production
        static $LEVELS = [ 'debug' => 5, 'info' => 10, 'success' => 15, 'warning' => 20, 'error' => 30, 'critical' => 40 ];
        $requested = strtolower((string)$level);
        if (!isset($LEVELS[$requested])) { $requested = 'info'; }

        // Determine threshold: constant > option > WP_DEBUG > default('warning')
        $threshold = 'warning';
        if (defined('YUZ_TRA_LOG_LEVEL') && is_string(YUZ_TRA_LOG_LEVEL) && isset($LEVELS[strtolower(YUZ_TRA_LOG_LEVEL)])) {
            $threshold = strtolower(YUZ_TRA_LOG_LEVEL);
        } elseif (function_exists('get_option')) {
            $opt = (string) get_option('yuz_tra_log_level', '');
            $opt = strtolower($opt);
            if (isset($LEVELS[$opt])) { $threshold = $opt; }
            elseif (defined('WP_DEBUG') && WP_DEBUG) { $threshold = 'info'; }
        } elseif (defined('WP_DEBUG') && WP_DEBUG) {
            $threshold = 'info';
        }

        if ($LEVELS[$requested] < $LEVELS[$threshold]) {
            return; // below threshold: skip
        }

        $prefixes = [
            'critical' => '🟥 [CRITICAL]',
            'warning'  => '🟨 [WARNING]',
            'success'  => '🟩 [SUCCESS]',
            'info'     => '🟦 [INFO]',
            'debug'    => '🟪 [DEBUG]',
        ];
        $prefix = $prefixes[$requested] ?? '🟦 [INFO]';
        $log_message = "{$prefix} {$message}";
        if (!empty($context)) {
            $log_message .= ' | Context: ' . (is_string($context) ? $context : print_r($context, true));
        }
        error_log($log_message);
    }
/** N’écrase pas les valeurs déjà présentes */
    public static function ensure_default_settings(): void {
        $defaults = [
            // Do NOT enable front switcher features from here (admin core).
            // Keep legacy keys present but disabled to avoid overriding yuz_tra_switcher.
            'yuz_floating_enabled' => false,
            'yuz_floating_format' => 'short-names',
            'yuz_floating_theme' => 'dark',
            'yuz_floating_position' => 'bottom-right',
            'yuz_menu_enabled' => false,
            'yuz_menu_format' => 'short-names',
            'yuz_shortcode_enabled' => false,
            'source_language_id' => 0,
            'api_adapter' => 'libretranslate',
        ];
        $all_settings = function_exists('yuz_settings_get_all') ? yuz_settings_get_all() : [];
        $current = is_array($all_settings['yuz_tra_settings'] ?? null) ? $all_settings['yuz_tra_settings'] : [];

        if (empty($current)) {
            yuz_settings_replace_section('yuz_tra_settings', $defaults);
            self::log_colored('info', 'Default settings added (new option created).');
        } else {
            $updated = array_merge($defaults, $current);
            if ($updated !== $current) {
                yuz_settings_replace_section('yuz_tra_settings', $updated); //
                $diffKeys = array_keys(array_diff_key($updated, $current));
                self::log_colored('info', 'Defaults applied, new keys=' . (empty($diffKeys) ? 'none' : implode(', ', $diffKeys)));
            }
        }

        // One-shot migration: move legacy switcher flags from yuz_tra_settings to yuz_tra_switcher
        $migrate_flag = 'yuz_tra_switcher_migrated';
        if (!get_option($migrate_flag)) {
            $legacy = get_option('yuz_tra_settings', []);
            if (is_array($legacy)) {
                $sw_all = yuz_settings_get_all();
                $sw = isset($sw_all['yuz_tra_switcher']) && is_array($sw_all['yuz_tra_switcher']) ? $sw_all['yuz_tra_switcher'] : [];

                $changed_sw = false;
                $changed_legacy = false;

                // Enabled flags
                $mapEnabled = [
                    'yuz_shortcode_enabled' => 'shortcode_enabled',
                    'yuz_menu_enabled'      => 'menu_enabled',
                    'yuz_floating_enabled'  => 'floating_enabled',
                ];
                foreach ($mapEnabled as $old => $new) {
                    if (array_key_exists($old, $legacy)) {
                        $val = !empty($legacy[$old]) ? 1 : 0;
                        if (!array_key_exists($new, $sw) || (int)$sw[$new] !== $val) {
                            $sw[$new] = $val;
                            $changed_sw = true;
                        }
                        unset($legacy[$old]);
                        $changed_legacy = true;
                    }
                }

                // Formats / theme / position
                $mapOther = [
                    'yuz_shortcode_format'   => 'shortcode_format',
                    'yuz_menu_format'        => 'menu_format',
                    'yuz_floating_format'    => 'floating_format',
                    'yuz_floating_theme'     => 'floating_theme',
                    'yuz_floating_position'  => 'floating_position',
                ];
                foreach ($mapOther as $old => $new) {
                    if (array_key_exists($old, $legacy)) {
                        $val = sanitize_text_field($legacy[$old]);
                        if (!array_key_exists($new, $sw) || $sw[$new] !== $val) {
                            $sw[$new] = $val;
                            $changed_sw = true;
                        }
                        unset($legacy[$old]);
                        $changed_legacy = true;
                    }
                }

                if ($changed_sw || $changed_legacy) {
                    yuz_settings_update(function (array $currentAll) use ($sw, $legacy, $changed_sw, $changed_legacy) {
                        if ($changed_sw) {
                            $currentAll['yuz_tra_switcher'] = $sw;
                        }
                        if ($changed_legacy) {
                            $current = isset($currentAll['yuz_tra_settings']) && is_array($currentAll['yuz_tra_settings'])
                                ? $currentAll['yuz_tra_settings']
                                : [];
                            $currentAll['yuz_tra_settings'] = array_merge($current, $legacy);
                        }
                        return $currentAll;
                    });
                    if ($changed_sw) {
                        self::log_colored('success', 'Migrated legacy switcher flags to yuz_tra_switcher');
                    }
                    if ($changed_legacy) {
                        self::log_colored('info', 'Cleaned legacy yuz_* switcher keys from yuz_tra_settings');
                    }
                }
                update_option($migrate_flag, 1, false);
                // --- 🔧 HOTFIX 2025-10-20 : Empêche la disparition de yuz_tra_settings ---
                $all_settings_block = get_option('yuz_tra_all_settings');
                if (isset($all_settings_block['yuz_tra_settings']) && is_array($all_settings_block['yuz_tra_settings'])) {
                    if (!get_option('yuz_tra_settings')) {
                        update_option('yuz_tra_settings', $all_settings_block['yuz_tra_settings'], true);
                        if (function_exists('error_log')) {
                            self::log_colored('debug', '🧩 HOTFIX YUZ: yuz_tra_settings restauré avant cleanup.');
                        }
                    }
                }
                // -------------------------------------------------------------------------
                delete_option('yuz_tra_switcher');
                delete_option('yuz_tra_settings');
                delete_option('yuz_tra_switcher_settings');
            }
        }
    }
/** Config légère pour d’autres modules */
public static function get_config(): array {
$all = function_exists('yuz_settings_get_all') ? yuz_settings_get_all() : [];
$options = is_array($all['yuz_tra_settings'] ?? null) ? $all['yuz_tra_settings'] : [];
$default_language = $options['yuz_tra_default_language'] ?? get_locale();
$config = [
'default_language' => $default_language,
'plugin_version' => self::PLUGIN_VERSION,
        ];
self::log_colored('success', 'Configuration options retrieved.', $config);
return $config;
    }
/** Pass-through de traduction (lazy-load TM, jamais en PLAN) */
public static function translate($text, $source_lang, $target_lang) {
if (empty($text)) {
return '';
        }
        $manager_file = YUZ_TRA_INCLUDES . 'class-yuz-api-manager.php';
        if (!class_exists('YUZ_API_Manager') && file_exists($manager_file)) {
            require_once $manager_file;
            if (class_exists('YUZ_API_Manager') && method_exists('YUZ_API_Manager','init')) {
                try {
                    YUZ_API_Manager::init();
                } catch (\Throwable $e) {
                    self::log_colored('critical','API TM::init(translate): '.$e->getMessage());
                    return null;
                }
            }
        }
        if (!class_exists('YUZ_API_Manager') || !method_exists('YUZ_API_Manager','translate')) {
            self::log_colored('critical','YUZ_API_Manager::translate indisponible.');
            return null;
        }
        $result = YUZ_API_Manager::translate($text, $source_lang, $target_lang);
if ($result === null) {
self::log_colored('warning','Translation failed, no result from manager.');
return null;
        }
do_action('yuz_translation_complete', $result);
return $result;
    }
}
/* ============================================================================================
 * HOOKS WP
 * ========================================================================================== */
// MODIF: Démarre le Core après le chargement du textdomain (voir yuz-tra.php)
add_action('init', ['YUZ_Core', 'init'], 30);

<?php
/**
 * YUZ CORE RULES — DO NOT VIOLATE
 *
 * Objectif :
 *   Verrouiller par VERBES les actions autorisées par fichier central.
 *   Tout verbe non listé ci-dessous est INTERDIT dans ce fichier.
 *
 * centralise des politiques: class-yuz-contracts.php (les interfaces), class-yuz-fallbacks.php (les fallbacks), class-yuz-ajax.php (la communication), class-yuz-assets.php (les assets css, js, ...), class-yuz-renderer.php (les rendus UI), yuz-core.php (le bootstrap).
 * 
 * Exclusivités (fichiers centraux) — autorité unique :
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

require_once YUZ_TRA_INCLUDES . 'class-yuz-contracts.php';
require_once YUZ_TRA_INCLUDES . 'class-yuz-fallbacks.php';

use YUZTRA\Interfaces\AdvancedInterface;
use YUZTRA\Interfaces\RendererInterface;
use YUZTRA\Interfaces\LoggerInterface;
use YUZTRA\Interfaces\HealthCheckInterface;
use YUZTRA\Interfaces\AjaxInterface;

use YUZTRA\Fallbacks\NullSettings;
use YUZTRA\Fallbacks\NullAjax;
use YUZTRA\Fallbacks\NullTranslationManager;
use YUZTRA\Fallbacks\NullLanguageManager;
use YUZTRA\Fallbacks\NullLogger;
use YUZTRA\Fallbacks\NullHealthCheck;
use YUZTRA\Fallbacks\NullRenderer;
use YUZTRA\Fallbacks\NullLanguages;

if ( ! class_exists('YUZ_Advanced') ) {

class YUZ_Advanced implements AdvancedInterface {

    private RendererInterface $renderer;
    private LoggerInterface $logger;
    private HealthCheckInterface $health_check;
    private AjaxInterface $ajax;

    public function __construct(
        RendererInterface $renderer,
        LoggerInterface $logger,
        HealthCheckInterface $health_check,
        AjaxInterface $ajax
    ) {
        $this->renderer      = $renderer;
        $this->logger        = $logger;
        $this->health_check  = $health_check;
        $this->ajax          = $ajax;
    }

    private static bool $booted = false;

    /** Bootstrap */
    public static function init(): void
    {
        if (self::$booted) {
            return;
        }
        self::$booted = true;

        if ( ! defined('YUZ_TRA_INCLUDES') || ! defined('YUZ_TRA_PLUGIN_FILE') ) {
            error_log('🟥 [CRITICAL] YUZ-TRA: constants missing in YUZ_Advanced::init');
            return;
        }

        $logger       = class_exists('YUZ_Logger')       ? new \YUZ_Logger()                       : new NullLogger();
        $health_check = class_exists('YUZ_Health_Check') ? new \YUZ_Health_Check($logger)           : new NullHealthCheck();
        $db           = class_exists('YUZ_DB')           ? new \YUZ_DB($logger, $health_check)      : null;
        $ajax         = (class_exists('YUZ_Ajax') && $db instanceof \YUZ_DB)
                            ? new \YUZ_Ajax(new NullTranslationManager(), new NullLanguageManager(), $db)
                            : new NullAjax();

        $languages    = class_exists('YUZ_Languages') ? new \YUZ_Languages(new NullSettings(), $db) : new NullLanguages();
        $settings     = class_exists('YUZ_Settings')  ? new \YUZ_Settings($languages, $ajax, new NullTranslationManager(), new NullLanguageManager(), $logger) : new NullSettings();
        $renderer     = class_exists('YUZ_Renderer')  ? new \YUZ_Renderer($ajax, $settings, $logger, $languages) : new NullRenderer();

        $instance = new self($renderer, $logger, $health_check, $ajax);

        // Rendu via routeur central
        add_action('yuz-tra_page_yuz-translation-advanced', [$instance, 'render_tab']);

        // NOTE: endpoints AJAX déplacés dans YUZ_Ajax (conforme aux CORE RULES).
    }

    /** Tabs par défaut + filtre d’extension */
    private function get_tabs(): array
    {
        $has_diag = class_exists('YUZ_Diagnostic'); // sidecar optionnel
        $tabs = [
            'troubleshooting' => __('Troubleshooting', 'yuz-tra'),
            'debug'           => __('Debug', 'yuz-tra'),
            'tools'           => __('Tools', 'yuz-tra'),
        ];
        if ($has_diag) {
            $tabs['diagnostics'] = __('Diagnostics', 'yuz-tra');
        }
        // Hook 1 : permettre d’ajouter/enlever des sous-onglets
        $tabs = apply_filters('yuz_advanced_tabs', $tabs);

        // sécurité : garder l’ordre stable, filtrer les clés/labels vides
        $clean = [];
        foreach ($tabs as $k => $v) {
            if (!empty($k) && !empty($v)) {
                $clean[$k] = $v;
            }
        }
        return $clean;
    }

    private function get_active_tab(string $fallback): string
    {
        $active = isset($_GET['subtab']) ? sanitize_key($_GET['subtab']) : $fallback;
        $tabs   = $this->get_tabs();
        return array_key_exists($active, $tabs) ? $active : $fallback;
    }

    /** Rendu principal (nav locale + dispatch par sous-onglet) */
    public function render_tab(): void
    {
        if ( ! current_user_can('manage_options') ) {
            echo '<div class="notice notice-error"><p>'.esc_html__('Unauthorized', 'yuz-tra').'</p></div>';
            return;
        }

        // Enqueue des assets (fabrique centrale)
        if (method_exists('YUZ_Assets','require')) {
            YUZ_Assets::require('advanced-admin');
        }

        $tabs = $this->get_tabs();
        if (empty($tabs)) {
            echo '<div class="wrap"><h1>'.esc_html__('Advanced', 'yuz-tra').'</h1><p>'.esc_html__('No sections available.', 'yuz-tra').'</p></div>';
            return;
        }

        $active   = $this->get_active_tab(array_key_first($tabs));
        $base_url = add_query_arg(
            ['page' => 'yuz-translation-settings', 'tab' => 'advanced'],
            admin_url('admin.php')
        );
        ?>
        <div class="wrap" id="yuz-advanced">
            <h1><?php esc_html_e('Advanced', 'yuz-tra'); ?></h1>

            <h2 class="nav-tab-wrapper">
                <?php foreach ($tabs as $slug => $label): ?>
                    <?php
                    $url   = esc_url(add_query_arg(['subtab' => $slug], $base_url));
                    $class = 'nav-tab' . ($slug === $active ? ' nav-tab-active' : '');
                    ?>
                    <a class="<?php echo esc_attr($class); ?>" href="<?php echo esc_url($url); ?>">
                        <?php echo esc_html($label); ?>
                    </a>
                <?php endforeach; ?>
            </h2>

            <div class="yuz-advanced-content">
                <?php $this->render_subtab($active); ?>
            </div>
        </div>
        <?php
    }

    /** Dispatch vers sous-onglets ou hook externe */
    private function render_subtab(string $active): void
    {
        switch ($active) {
            case 'troubleshooting':
                $this->render_troubleshooting();
                break;
            case 'debug':
                $this->render_debug();
                break;
            case 'tools':
                $this->render_tools();
                break;
            case 'diagnostics':
                $this->render_diagnostics();
                break;
            default:
                /**
                 * Hook 2 : sous-onglet fourni par un module externe (ex: sidecar)
                 * Le module doit écho son HTML.
                 */
                do_action('yuz_advanced_render_tab_'.$active, $this);

                if ( ! has_action('yuz_advanced_render_tab_'.$active) ) {
                    echo '<div class="notice notice-info"><p>'
                         . esc_html__('This section is planned but not implemented yet.', 'yuz-tra')
                         . '</p></div>';
                }
        }
    }

    /* ---------- SOUS-ONGLETS INTÉGRÉS ------------- */

    private function render_troubleshooting(): void
    {
        // POST d’abord pour refléter l’état
        if ( 'POST' === ($_SERVER['REQUEST_METHOD'] ?? '') && isset($_POST['nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])),'yuz_con_nonce') ) {
            $fix  = isset($_POST['yuz_fix_dynamic']) ? 1 : 0;
            $stop = isset($_POST['yuz_disable_dynamic']) ? 1 : 0;

            $payload = [
                'fix_dynamic_content'        => (bool)$fix,
                'disable_dynamic_translation'=> (bool)$stop,
            ];

            if (class_exists('YUZ_Settings')) {
                try {
                    $languages = class_exists('YUZ_Languages') ? new \YUZ_Languages(new NullSettings(), (class_exists('YUZ_DB') ? new \YUZ_DB(new NullLogger(), new NullHealthCheck()) : null)) : new NullLanguages();
                    $settings  = new \YUZ_Settings($languages, $this->ajax, new NullTranslationManager(), new NullLanguageManager(), $this->logger ?: new NullLogger());
                    $settings->update_option('yuz_tra_av_settings', $payload);
                } catch (\Throwable $e) {
                    // ignore, fallback legacy juste après
                }
            }

            update_option('yuz_fix_dynamic',    $fix);
            update_option('yuz_disable_dynamic',$stop);

            echo '<div class="updated notice"><p>'.esc_html__('Settings saved.', 'yuz-tra').'</p></div>';
        }

        // Lecture depuis settings consolidés avec fallback legacy
        $adv = class_exists('YUZ_Settings')
              ? (new \YUZ_Settings(new NullLanguages(), new NullAjax(), new NullTranslationManager(), new NullLanguageManager(), new NullLogger()))->get_option('yuz_tra_av_settings')
              : [];
        if (!is_array($adv)) { $adv = []; }

        $fix_dynamic     = !empty($adv['fix_dynamic_content']) ? 1 : (int) get_option('yuz_fix_dynamic', 0);
        $disable_dynamic = !empty($adv['disable_dynamic_translation']) ? 1 : (int) get_option('yuz_disable_dynamic', 0);
        ?>
        <form method="post" action="">
            <?php wp_nonce_field('yuz_con_nonce', 'nonce'); ?>
            <table class="form-table">
                <tr>
                    <th><?php esc_html_e('Fix missing dynamic content', 'yuz-tra'); ?></th>
                    <td>
                        <label class="switch">
                            <input type="checkbox" name="yuz_fix_dynamic" value="1" <?php checked($fix_dynamic); ?> />
                            <span class="slider round"></span>
                        </label>
                        <label style="margin-left:8px;">
                            <?php esc_html_e('May help content inserted via JS appear in translations.', 'yuz-tra'); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e('Disable dynamic translation', 'yuz-tra'); ?></th>
                    <td>
                        <label class="switch">
                            <input type="checkbox" name="yuz_disable_dynamic" value="1" <?php checked($disable_dynamic); ?> />
                            <span class="slider round"></span>
                        </label>
                        <label style="margin-left:8px;">
                            <?php esc_html_e('Skip detecting strings added dynamically via JavaScript.', 'yuz-tra'); ?>
                        </label>
                    </td>
                </tr>
            </table>
            <?php submit_button( __( 'Save Changes', 'yuz-tra' ) ); ?>
        </form>
        <?php
    }

    private function render_debug(): void
    {
        // POST d’abord
        if ( 'POST' === ($_SERVER['REQUEST_METHOD'] ?? '') && isset($_POST['nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])),'yuz_con_nonce') ) {
            update_option('yuz_debug_mode', isset($_POST['debug_mode']) ? 1 : 0);
            // Log level select
            $level = isset($_POST['yuz_tra_log_level']) ? sanitize_text_field(wp_unslash($_POST['yuz_tra_log_level'])) : '';
            $allowed = ['debug','info','success','warning','error','critical'];
            if (in_array($level, $allowed, true)) {
                update_option('yuz_tra_log_level', $level);
            }
            echo '<div class="updated notice"><p>'.esc_html__('Settings saved.', 'yuz-tra').'</p></div>';
        }

        $debug_mode = (int) get_option('yuz_debug_mode', 0);
        $current_level = (string) get_option('yuz_tra_log_level', 'warning');
        ?>
        <form method="post" action="">
            <?php wp_nonce_field('yuz_con_nonce', 'nonce'); ?>
            <table class="form-table">
                <tr>
                    <th><label for="debug_mode"><?php esc_html_e('Enable Debug Mode', 'yuz-tra'); ?></label></th>
                    <td>
                        <label class="switch">
                            <input type="checkbox" id="debug_mode" name="debug_mode" value="1" <?php checked($debug_mode); ?> />
                            <span class="slider round"></span>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th><label for="yuz_tra_log_level"><?php esc_html_e('Log Level', 'yuz-tra'); ?></label></th>
                    <td>
                        <select id="yuz_tra_log_level" name="yuz_tra_log_level">
                            <?php
                            $levels = ['debug','info','success','warning','error','critical'];
                            foreach ($levels as $lv) {
                                printf('<option value="%s" %s>%s</option>', esc_attr($lv), selected($current_level, $lv, false), esc_html(ucfirst($lv)));
                            }
                            ?>
                        </select>
                        <p class="description"><?php esc_html_e('Controls plugin verbosity without editing wp-config.php. Default: warning.', 'yuz-tra'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e('Logs path', 'yuz-tra'); ?></th>
                    <td><code><?php echo esc_html( WP_CONTENT_DIR . '/debug.log' ); ?></code></td>
                </tr>
            </table>
            <?php submit_button( __( 'Save Changes', 'yuz-tra' ) ); ?>
        </form>
        <?php
    }

    private function render_tools(): void
    {
        ?>
        <div class="yuz-section">
            <h2 class="yuz-section-title"><?php esc_html_e('Maintenance Tools', 'yuz-tra'); ?></h2>
            <p class="description"><?php esc_html_e('Clear caches and reset temporary state.', 'yuz-tra'); ?></p>
            <button type="button" id="yuz-clear-cache" class="button">
                <?php esc_html_e('Clear All Cache', 'yuz-tra'); ?>
            </button>
            <span id="yuz-clear-cache-result" style="margin-left:8px;"></span>
        </div>

        <div class="yuz-section" style="margin-top:24px">
            <h2 class="yuz-section-title"><?php esc_html_e('Translations Table Maintenance', 'yuz-tra'); ?></h2>
            <p class="description"><?php esc_html_e('Backup and clean the translations table: remove empty translations, normalize locales, deduplicate.', 'yuz-tra'); ?></p>

            <input type="hidden" id="yuz_tra_maintenance_nonce" value="<?php echo esc_attr( wp_create_nonce('yuz_hvy_nonce') ); ?>">
            <div class="yuz-tools-actions">
                <button type="button" id="yuz-maint-metrics" class="button button-secondary" style="margin-right:12px"><?php esc_html_e('Preview Metrics', 'yuz-tra'); ?></button>
                <button type="button" id="yuz-maint-backup" class="button" style="margin-right:12px"><?php esc_html_e('Backup Table', 'yuz-tra'); ?></button>
                <button type="button" id="yuz-maint-cleanup" class="button button-primary" style="margin-right:12px"><?php esc_html_e('Run Full Cleanup', 'yuz-tra'); ?></button>
            </div>

            <details style="margin-top:12px">
                <summary><?php esc_html_e('Advanced (single operations)', 'yuz-tra'); ?></summary>
                <div style="margin-top:8px">
                    <button type="button" id="yuz-maint-delete-empties" class="button button-secondary" style="margin-right:8px"><?php esc_html_e('Delete Empty Translations', 'yuz-tra'); ?></button>
                    <button type="button" id="yuz-maint-normalize-locales" class="button button-secondary" style="margin-right:8px"><?php esc_html_e('Normalize Locales', 'yuz-tra'); ?></button>
                    <button type="button" id="yuz-maint-dedupe" class="button button-secondary" style="margin-right:8px"><?php esc_html_e('Deduplicate', 'yuz-tra'); ?></button>
                </div>
            </details>
            <pre id="yuz-maintenance-result" style="margin-top:12px; padding:12px; background:#fff; border:1px solid #e5e7eb; border-radius:6px; max-height:380px; overflow:auto;"></pre>
        </div>
        <?php
    }

    private function render_diagnostics(): void
    {
        $has_diag   = class_exists('YUZ_Diagnostic');
        $diag_cache = get_option('yuz_diagnostic_cache', []);
        $diag_report= $diag_cache['report'] ?? __('No diagnostics run yet.', 'yuz-tra');

        if ( ! $has_diag ) {
            echo '<div class="notice notice-info"><p>'
               . esc_html__('Diagnostics module is not installed. Add yuz-tra-diagnostic.php to enable this section.', 'yuz-tra')
               . '</p></div>';
            return;
        }
        ?>
        <div class="yuz-section">
            <p class="description"><?php esc_html_e('Collect environment, files, DB and settings checks.', 'yuz-tra'); ?></p>
            <button type="button" id="yuz-run-diagnostics" class="button button-primary">
                <?php esc_html_e('Run Diagnostics', 'yuz-tra'); ?>
            </button>
            <pre id="yuz-diagnostic-report" style="margin-top:12px;"><?php echo esc_html($diag_report); ?></pre>
        </div>
        <?php
    }

    /* ---------- Assets (contrat) ----------- */
    /**
     * Respecte AdvancedInterface tout en déléguant à la fabrique d'assets.
     * Aucun enqueue direct ici (voir CORE RULES).
     */
    public function enqueue_scripts(string $hook): void {
        if (class_exists('YUZ_Assets') && method_exists('YUZ_Assets','require')) {
            // pack "advanced-admin" : js + css + localisation (nonce, ajaxurl, i18n)
            YUZ_Assets::require('advanced-admin');
        }
    }

    /* ---------- Interface contract ---------- */
    public function render_advanced_tab(): void
    {
        $this->render_tab();
    }
}
}

// Hook d’initialisation (admin)
if ( class_exists('YUZ_Advanced') ) {
    add_action('admin_init', ['YUZ_Advanced', 'init']);
}

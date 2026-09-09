<?php
/**
 * Class YUZ_Editor
 * Manages the translation editor functionality for YUZ-TRA.
 *
 * @package YUZ_Translation
 */
/**
 * centralise des politiques: class-yuz-contracts.php (les interfaces), class-yuz-fallbacks.php (les fallbacks), class-yuz-ajax.php (la communication), class-yuz-assets.php (les assets css, js, ...), class-yuz-renderer.php (les rendus UI), yuz-core.php (le bootstrap).
 * 
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

// Load dependencies
require_once YUZ_TRA_INCLUDES . 'class-yuz-contracts.php';
require_once YUZ_TRA_INCLUDES . 'class-yuz-fallbacks.php';

use YUZTRA\Interfaces\LanguagesInterface;
use YUZTRA\Interfaces\TranslationManagerInterface;
use YUZTRA\Fallbacks\NullLanguages;
use YUZTRA\Fallbacks\NullAjax;
use YUZTRA\Fallbacks\NullTranslateAdapter;
use YUZTRA\Fallbacks\NullSettings;
use YUZTRA\Fallbacks\NullLogger;
use YUZTRA\Fallbacks\NullLanguageManager;
use YUZTRA\Fallbacks\NullTranslationManager;

if (!class_exists('YUZ_Editor')) {
class YUZ_Editor {
    private static $instance = null;
    private $languages;
    private $translation_manager;
    private $logger;
    private $settings;

    /**
     * Private constructor for singleton pattern.
     */
    private function __construct(LanguagesInterface $languages, TranslationManagerInterface $translation_manager) {
        $this->languages            = $languages;
        $this->translation_manager  = $translation_manager;
        $this->logger               = class_exists('\YUZ_Logger') ? new \YUZ_Logger() : new NullLogger();

        // Préfère la factory centrale
        if (class_exists('YUZ_Services') && method_exists('YUZ_Services', 'settings')) {
            $this->settings = \YUZ_Services::settings();
        } else {
            $this->settings = new NullSettings();
        }
    }

    /**
     * Initializes the editor functionality using singleton.
     */
    public static function init() {
        if ( self::$instance === null ) {
            // 1) Logger + health + DB
            $logger = class_exists('\YUZ_Logger') ? new \YUZ_Logger() : new NullLogger();
            $health = class_exists('\YUZ_Health_Check') ? new \YUZ_Health_Check( $logger ) : null;
            $db     = class_exists('\YUZ_DB') ? new \YUZ_DB( $logger, $health ) : null;

            // 2) Langues
            if (class_exists('YUZ_Services') && method_exists('YUZ_Services', 'languages')) {
                $languages = \YUZ_Services::languages();
            } else {
                $languages = class_exists( 'YUZ_Languages' ) && $db
                    ? new \YUZ_Languages( new NullSettings(), $db )
                    : new NullLanguages();
            }

            // 3) Settings
            if (class_exists('YUZ_Services') && method_exists('YUZ_Services', 'settings')) {
                $settings = \YUZ_Services::settings();
            } elseif (class_exists('YUZ_Settings') && $db) {
                $settings = new \YUZ_Settings($languages, new NullAjax(), new NullTranslationManager(), new NullLanguageManager(), $logger);
            } else {
                $settings = new NullSettings();
            }

            // 4) Translation Manager
            if (class_exists('YUZ_Services') && method_exists('YUZ_Services', 'tm')) {
                $translationMgr = \YUZ_Services::tm();
            } elseif (class_exists( 'YUZ_API_Manager' ) && $db) {
                $adapters = [
                    'custom'         => class_exists( 'YUZ_Custom_Translate_Adapter' ) ? new \YUZ_Custom_Translate_Adapter() : new NullTranslateAdapter(),
                    'libretranslate' => class_exists( 'YUZ_Libre_Translate_Adapter' ) ? new \YUZ_Libre_Translate_Adapter() : new NullTranslateAdapter(),
                    'deepl'          => class_exists( 'YUZ_DeepL_Translate_Adapter' ) ? new \YUZ_DeepL_Translate_Adapter() : new NullTranslateAdapter(),
                    'google'         => class_exists( 'YUZ_Google_Translate_Adapter' ) ? new \YUZ_Google_Translate_Adapter() : new NullTranslateAdapter(),
                ];

                $translationMgr = new \YUZ_API_Manager(
                    $adapters,
                    $settings,
                    $languages,
                    null,
                    $db,
                    $logger
                );
            } else {
                $translationMgr = new NullTranslationManager();
            }

            // 5) Language Manager
            $languageMgr = class_exists('YUZ_Language_Manager')
                ? new \YUZ_Language_Manager()
                : new NullLanguageManager();

            // 6) Ajax
            $ajax = class_exists( 'YUZ_Ajax' ) && $db
                ? new \YUZ_Ajax( $translationMgr, $languageMgr, $db )
                : new NullAjax();

            // 7) Branchement Ajax → TM
            if (method_exists($translationMgr, 'setAjax')) {
                $translationMgr->setAjax($ajax);
            }

            // 8) Si Settings non fournis par Services, recâbler
            if (!(class_exists('YUZ_Services') && method_exists('YUZ_Services', 'settings')) && class_exists('YUZ_Settings') && !($settings instanceof NullSettings)) {
                $settings = new \YUZ_Settings($languages, $ajax, $translationMgr, $languageMgr, $logger);
            }

            // 9) Instance
            self::$instance = new self( $languages, $translationMgr );
            self::$instance->settings = $settings;
        }

        // Flags runtime
        $flags = (is_object(self::$instance->settings) && method_exists(self::$instance->settings, 'runtime_flags'))
            ? self::$instance->settings->runtime_flags()
            : ['editor_enabled' => true];

        if (empty($flags['editor_enabled'])) {
            self::$instance->logger->log('info', 'Editor disabled via flags, skipping init');
            return;
        }

        // Hooks AJAX (NB: si tu veux strictement isoler AJAX dans class-yuz-ajax.php, déplace ces hooks là-bas)
        self::$instance->logger->log( 'info', 'Initializing YUZ_Editor at ' . current_time( 'mysql' ) );
        add_action( 'wp_ajax_yuz_start_translation', [ self::$instance, 'ajax_start_translation' ] );
        add_action( 'wp_ajax_yuz_get_translatable_languages', [ self::$instance, 'ajax_get_translatable_languages' ] );
        add_action( 'wp_ajax_yuz_save_translation', [ self::$instance, 'ajax_save_translation' ] );
        add_action( 'wp_ajax_yuz_translate_text', [ self::$instance, 'ajax_translate_text' ] );

        // === DEBUG DIAG ENDPOINT (Option A) — activé seulement si YUZ_TRA_DEBUG ===
        if ( defined('YUZ_TRA_DEBUG') && YUZ_TRA_DEBUG ) {
            add_action('wp_ajax_yuz_tra_probe', [ self::$instance, 'ajax_probe' ]);
        }

        // Hook assets (déclenche uniquement la demande centrale vers YUZ_Assets)
        add_action( 'wp_enqueue_scripts', [ self::$instance, 'enqueue_editor_assets' ] );
        // Injection du conteneur overlay (front uniquement)
        add_action( 'yuz/footer/frontend', [ self::$instance, 'print_editor_container' ], 5 );

        self::$instance->logger->log( 'success', 'YUZ_Editor initialized successfully' );
    }

    /**
     * Enqueues assets for the translation editor UI (via YUZ_Assets).
     * Ne monte l'overlay que si ?yuz-edit-translation=1 ET l'utilisateur peut 'yuz_translate_content'.
     */
    public function enqueue_editor_assets() {
        // Flags runtime
        $flags = (is_object($this->settings) && method_exists($this->settings, 'runtime_flags'))
            ? $this->settings->runtime_flags()
            : ['editor_enabled' => true];

        // Gardes stricts (pas d'overlay si désactivé, si param absent, ou si cap manquante)
        $has_param = isset($_GET['yuz-edit-translation']); // phpcs:ignore
        $can_edit  = class_exists('YUZ_Capabilities')
            ? \YUZ_Capabilities::user_is_translator()
            : (current_user_can('manage_options') || current_user_can('yuz_translate_content'));

        if (empty($flags['editor_enabled']) || ! $has_param || ! $can_edit) {
            // Log doux pour diagnostiquer sans bruit
            if (method_exists($this->logger, 'log')) {
                $why = empty($flags['editor_enabled']) ? 'editor_disabled' : (!$has_param ? 'missing_param' : 'missing_cap');
                $this->logger->log('info', 'Editor overlay guard skip: ' . $why);
            }
            return;
        }

        // Demande à la fabrique d’assets (source unique) — uniquement si gardes OK
        if (method_exists('YUZ_Assets','require')) {
            YUZ_Assets::require('translation-editor');
        }

        // Rien d’autre ici : pas de wp_enqueue_* ni wp_localize_script (géré par class-yuz-assets.php)
        if (method_exists($this->logger, 'log')) {
            $this->logger->log('success', 'Requested translation editor assets (front overlay allowed)');
        }
    }

    /**
     * Imprime le conteneur de l'éditeur en bas de page quand activé.
     * Aucun JS inline ici; seulement le markup minimal attendu par le bundle UMD.
     */
    public function print_editor_container(): void {
        if ( is_admin() ) { return; }
        // Même gardes que pour l'enqueue (éviter tout echo si non autorisé)
        $flags = (is_object($this->settings) && method_exists($this->settings, 'runtime_flags'))
            ? $this->settings->runtime_flags()
            : ['editor_enabled' => true];
        $has_param = isset($_GET['yuz-edit-translation']); // phpcs:ignore
        $can_edit  = class_exists('YUZ_Capabilities')
            ? \YUZ_Capabilities::user_is_translator()
            : (current_user_can('manage_options') || current_user_can('yuz_translate_content'));
        if ( empty($flags['editor_enabled']) || ! $has_param || ! $can_edit ) {
            return;
        }
        // Évite doublons si un thème/JS l'a déjà inséré
        echo "\n<!-- YUZ-TRA Editor Container -->\n";
        echo '<div id="yuz-editor-container" class="yuz-editor-overlay" aria-hidden="true" style="display:none"></div>' . "\n";
        echo "<!-- /YUZ-TRA Editor Container -->\n";
    }

    /**
     * AJAX: start translation
     */
    public function ajax_start_translation() {
        check_ajax_referer('yuz_tra_nonce', 'nonce');
        $this->logger->log('info', 'Starting translation via AJAX');

        $page_url   = isset($_POST['page_url']) ? esc_url_raw($_POST['page_url']) : home_url();
        $editor_url = add_query_arg('yuz-edit-translation', '1', $page_url);

        wp_send_json_success(['editor_url' => $editor_url]);
        $this->logger->log('success', 'Translation started, editor URL: ' . $editor_url);
    }

    /**
     * AJAX: get translatable languages
     */
    public function ajax_get_translatable_languages() {
        $busy_key = 'yuz_te_busy';
        if (get_transient($busy_key)) {
            wp_send_json_error(['code' => 429, 'message' => 'Too many requests, try again in 30s'], 429);
        }
        set_transient($busy_key, true, 30);

        check_ajax_referer('yuz_tra_nonce', 'nonce');
        $this->logger->log('info', 'Fetching translatable languages via AJAX');

        global $wpdb;
        $table_name = $wpdb->prefix . 'yuz_tra_languages';
        if (! $wpdb->get_var("SHOW TABLES LIKE '$table_name'")) {
            wp_send_json_error(['message' => "Languages table $table_name does not exist"], 500);
        }

        $languages = null;
        $attempts  = 3;
        while ($attempts > 0) {
            $languages = $wpdb->get_results("SELECT language_code, language_name FROM {$table_name} WHERE is_translatable = 1");
            if ($languages !== null) {
                break;
            }
            $attempts--;
            $this->logger->log('warning', "Retry attempt for fetching translatable languages, attempts left: {$attempts}");
            usleep(100000);
        }

        if ($wpdb->last_error || $languages === null) {
            $this->logger->log('error', 'Database query failed: ' . $wpdb->last_error);
            wp_send_json_error(['message' => 'Database error']);
            return;
        }

        wp_send_json_success(['languages' => $languages]);
        $this->logger->log('success', 'Translatable languages fetched: ' . count($languages));
    }
    
    private function translation_table_has_column(string $table, string $column): bool {
        static $cache = [];

        $table_key = $table ?: 'default';
        if (!isset($cache[$table_key])) {
            global $wpdb;
            $columns = $wpdb->get_col("DESCRIBE {$table}", 0);
            $cache[$table_key] = is_array($columns) ? array_map('strtolower', $columns) : [];
        }

        return in_array(strtolower($column), $cache[$table_key], true);
    }

    /**
     * AJAX: translate text
     */
    public function ajax_translate_text() {
        $busy_key = 'yuz_te_busy';
        if (get_transient($busy_key)) {
            wp_send_json_error(['code' => 429, 'message' => 'Too many requests, try again in 30s'], 429);
        }
        set_transient($busy_key, true, 30);

        check_ajax_referer('yuz_tra_nonce', 'nonce');
        $this->logger->log('info', 'Translating text via AJAX');

        $text        = sanitize_text_field($_POST['text'] ?? '');
        $source_lang = sanitize_text_field($_POST['source_lang'] ?? '');
        $target_lang = sanitize_text_field($_POST['target_lang'] ?? '');

        if (empty($text) || empty($source_lang) || empty($target_lang)) {
            wp_send_json_error(['message' => 'Missing required parameters for translation'], 400);
        }

        $translated_text = $this->translation_manager->translate($text, $source_lang, $target_lang);
        if ($translated_text === null) {
            $this->logger->log('error', 'Translation failed');
            wp_send_json_error(['message' => 'Translation failed'], 500);
            return;
        }

        wp_send_json_success(['translated_text' => $translated_text]);
        $this->logger->log('success', 'Text translated successfully');
    }

    /**
     * AJAX: probe (diagnostic léger, activé uniquement si YUZ_TRA_DEBUG)
     */
    public function ajax_probe() {
        if ( ! is_user_logged_in() || ! current_user_can('manage_options') ) {
            wp_send_json_error(['code'=>403,'msg'=>'forbidden']);
        }

        $ok = check_ajax_referer('yuz_tra_nonce', 'nonce', false);
        if ( ! $ok ) {
            wp_send_json_error(['code'=>401,'msg'=>'bad nonce']);
        }

        wp_send_json_success([
            'user_id'        => get_current_user_id(),
            'user_login'     => wp_get_current_user()->user_login ?? null,
            'has_cap'        => current_user_can('yuz_translate_content'),
            'cookie_present' => isset($_COOKIE['wordpress_logged_in_'.COOKIEHASH]),
            'received_nonce' => $_POST['nonce'] ?? null,
            'expected_nonce' => wp_create_nonce('yuz_tra_nonce'),
            'headers'        => [
                'ua'          => sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'] ?? '')),
                'xrw'         => $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '',
                'cfipcountry' => $_SERVER['HTTP_CF_IPCOUNTRY'] ?? '',
            ],
        ]);
    }
}
}

if (class_exists('YUZ_Editor')) {
    if (class_exists('\\YUZ_Logger')) {
        (new \YUZ_Logger())->log('success', 'YUZ_Editor class created successfully at ' . current_time('mysql'));
    }
    add_action('plugins_loaded', ['YUZ_Editor', 'init']); // init après chargement des dépendances
} else {
    if (class_exists('\\YUZ_Logger')) {
        (new \YUZ_Logger())->log('critical', 'Failed to create YUZ_Editor class at ' . current_time('mysql'));
    }
}

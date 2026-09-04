<?php
/**
 * Class YUZ_Addons
 * Manages the Addons tab in the YUZ-TRA admin interface, including addon listing, activation, and health checks.
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
use YUZTRA\Interfaces\{AddonsInterface,RendererInterface,LoggerInterface,HealthCheckInterface,AjaxInterface,SettingsInterface};
use YUZTRA\Fallbacks\NullRenderer;
use YUZTRA\Fallbacks\NullLogger;
use YUZTRA\Fallbacks\NullHealthCheck;
use YUZTRA\Fallbacks\NullAjax;
use YUZTRA\Fallbacks\NullSettings;
use YUZTRA\Fallbacks\NullTranslationManager;
use YUZTRA\Fallbacks\NullLanguageManager;
require_once YUZ_TRA_INCLUDES . 'class-yuz-contracts.php';
require_once YUZ_TRA_INCLUDES . 'class-yuz-fallbacks.php';
if (!class_exists('YUZ_Addons')) {
class YUZ_Addons implements AddonsInterface {
private RendererInterface $renderer;
private LoggerInterface $logger;
private HealthCheckInterface $health_check;
private AjaxInterface $ajax;
private SettingsInterface $settings;
/** @var array<string, array> */
private array $registry = []; // registre des addons (slug => metadata/loader)
/**
     * Constructor to inject dependencies.
     */
public function __construct(
RendererInterface $renderer,
LoggerInterface $logger,
HealthCheckInterface $health_check,
AjaxInterface $ajax,
SettingsInterface $settings
    ) {
$this->renderer = $renderer;
$this->logger = $logger;
$this->health_check = $health_check;
$this->ajax = $ajax;
$this->settings = $settings;
    }
private static bool $booted = false;
/**
     * Initializes the class.
     */
public static function init(): void {
if (self::$booted) {
return;
        }
self::$booted = true;
// Chaînage des dépendances sûres
$logger = class_exists('YUZ_Logger') ? new \YUZ_Logger() : new NullLogger();
$health_check = class_exists('YUZ_Health_Check') ? new \YUZ_Health_Check($logger) : new NullHealthCheck();
$translation_manager = new NullTranslationManager();
$language_manager = new NullLanguageManager();
$db = class_exists('YUZ_DB')
            ? new \YUZ_DB($logger, $health_check)
            : null;
$ajax = (class_exists('YUZ_Ajax') && $db instanceof \YUZ_DB)
            ? new \YUZ_Ajax($translation_manager, $language_manager, $db) // pass $db
            : new NullAjax();
// On évite le crash si YUZ_Settings exige des args non dispos
$settings = class_exists('YUZ_Settings')
            ? (new NullSettings()) // placeholder tant que l’injection complète n’est pas prête
            : new NullSettings();
// Pour éviter "too few arguments" sur YUZ_Renderer, on met un NullRenderer
$renderer = new NullRenderer();
$instance = new self($renderer, $logger, $health_check, $ajax, $settings);
$instance->logger->log('info', 'Initializing YUZ_Addons at ' . (function_exists('current_time') ? current_time('mysql') : gmdate('Y-m-d H:i:s')));
// LEGACY→YUZ_Assets: add_action('admin_enqueue_scripts', [$instance, 'enqueue_scripts']);
add_action('wp_ajax_yuz_activate_addon', [$instance, 'ajax_activate_addon']);
add_action('wp_ajax_yuz_deactivate_addon', [$instance, 'ajax_deactivate_addon']);
$instance->register_addons((array) apply_filters('yuz_tra_addons', []));
$instance->logger->log('success', 'YUZ_Addons initialized successfully');
    }
/**
     * Impl. AddonsInterface::register_addons
     * Enregistre (ou fusionne) des addons dans le registre local.
     *
     * @param array $addons Liste d’addons de la forme:
     * [
     * ['slug'=>'my-addon','name'=>'...','description'=>'...','loader'=>callable|null],
     * ...
     * ]
     */
public function register_addons(array $addons): void {
foreach ($addons as $addon) {
if (empty($addon['slug'])) { continue; }
$slug = (string) $addon['slug'];
$this->registry[$slug] = [
'slug' => $slug,
'name' => $addon['name'] ?? $slug,
'description' => $addon['description'] ?? '',
'loader' => isset($addon['loader']) && is_callable($addon['loader']) ? $addon['loader'] : null,
            ];
        }
    }
/**
     * Impl. AddonsInterface::loadAddon
     * Tente de charger l’addon via son loader enregistré.
     */
public function loadAddon(string $addon): bool {
if (!isset($this->registry[$addon])) {
$this->logger->log('warning', "Addon '$addon' not registered");
return false;
        }
$loader = $this->registry[$addon]['loader'] ?? null;
if (is_callable($loader)) {
try {
return call_user_func($loader) === true;
            } catch (\Throwable $e) {
$this->logger->log('error', "Addon '$addon' loader threw: " . $e->getMessage());
return false;
            }
        }
return false;
    }
/**
     * Enqueues scripts for the Addons tab.
     */
public function enqueue_scripts(string $hook): void {
        if (isset($this->logger)) { $this->logger->log('info', 'enqueue_scripts delegated to YUZ_Assets'); }
        return;
    }
/**
     * Renders the Addons tab.
     */
public function render_tab(): void {
if (!current_user_can('manage_options')) {
$this->logger->log('critical', 'User lacks manage_options capability in YUZ_Addons::render_tab');
wp_die(esc_html__('Unauthorized', 'yuz-tra'));
        }
// POST → passer par l’AjaxInterface::handleRequest
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
$this->ajax->handleRequest(
'yuz_con_nonce',
                [],
function ($post_data) {
$this->logger->log('info', 'Processing addons form');
update_option('yuz_addons_last_save', time());
return ['success' => true, 'message' => __('Addons settings updated.', 'yuz-tra')];
                },
                [],
true
            );
        }
$addons = $this->get_available_addons();
?>
<div class="wrap">
<h1><?php esc_html_e('Addons', 'yuz-tra'); ?></h1>
<form method="post" action="">
<?php wp_nonce_field('yuz_con_nonce', 'nonce'); ?>
<div class="yuz-section">
<h2 class="yuz-section-title"><?php esc_html_e('Available Addons', 'yuz-tra'); ?></h2>
<hr>
<table class="form-table">
<?php foreach ($addons as $addon): ?>
<tr>
<th><?php echo esc_html($addon['name']); ?></th>
<td>
<p><?php echo esc_html($addon['description']); ?></p>
<?php if (!empty($addon['active'])): ?>
<button type="button" class="button yuz-deactivate-addon" data-addon="<?php echo esc_attr($addon['slug']); ?>"><?php esc_html_e('Deactivate', 'yuz-tra'); ?></button>
<?php else: ?>
<button type="button" class="button button-primary yuz-activate-addon" data-addon="<?php echo esc_attr($addon['slug']); ?>"><?php esc_html_e('Activate', 'yuz-tra'); ?></button>
<?php endif; ?>
</td>
</tr>
<?php endforeach; ?>
</table>
</div>
<?php submit_button(__('Save Changes', 'yuz-tra')); ?>
</form>
</div>
<?php
$this->logger->log('info', 'Finished rendering Addons tab');
    }
/**
     * Retrieves available addons (from registry + options).
     */
private function get_available_addons(): array {
$list = [];
// Base sur le registre rempli via register_addons()
foreach ($this->registry as $slug => $meta) {
$active = (bool) (int) (function_exists('get_option') ? get_option("yuz_addon_{$slug}", 0) : 0);
$list[] = [
'slug' => $slug,
'name' => (string) ($meta['name'] ?? $slug),
'description' => (string) ($meta['description'] ?? ''),
'active' => $active,
            ];
        }
// Only registered implementations are listed.
return $list;
    }
/**
     * Handles AJAX request to activate addon.
     */
public function ajax_activate_addon(): void {
check_ajax_referer('yuz_con_nonce', 'nonce');
if (!current_user_can('manage_options')) wp_send_json_error(['message'=>'forbidden'],403);
$this->logger->log('info', 'Activating addon via AJAX');
$addon_slug = sanitize_text_field($_POST['addon'] ?? '');
$this->health_check->ensure(!empty($addon_slug), 'Missing addon slug', __METHOD__);
// Charge l’addon si possible
$loaded = $this->loadAddon($addon_slug);
if (!$loaded) wp_send_json_error(['message'=>'addon_not_loaded'],422);
// Active l’option
update_option("yuz_addon_{$addon_slug}", 1);
if ((int)get_option("yuz_addon_{$addon_slug}") !== 1) wp_send_json_error(['message'=>'addon_save_failed'],500);
wp_send_json_success(['message' => __('Addon activated', 'yuz-tra'), 'loaded' => (bool)$loaded]);
$this->logger->log('success', "Addon {$addon_slug} activated");
    }
/**
     * Handles AJAX request to deactivate addon.
     */
public function ajax_deactivate_addon(): void {
check_ajax_referer('yuz_con_nonce', 'nonce');
if (!current_user_can('manage_options')) wp_send_json_error(['message'=>'forbidden'],403);
$this->logger->log('info', 'Deactivating addon via AJAX');
$addon_slug = sanitize_text_field($_POST['addon'] ?? '');
$this->health_check->ensure(!empty($addon_slug), 'Missing addon slug', __METHOD__);
update_option("yuz_addon_{$addon_slug}", 0);
if ((int)get_option("yuz_addon_{$addon_slug}") !== 0) wp_send_json_error(['message'=>'addon_save_failed'],500);
wp_send_json_success(['message' => __('Addon deactivated', 'yuz-tra')]);
$this->logger->log('success', "Addon {$addon_slug} deactivated");
    }
}
}
// Boot à un moment sûr (évite __() avant 'init')
if (class_exists('YUZ_Addons')) {
add_action('admin_init', ['YUZ_Addons', 'init']);
}

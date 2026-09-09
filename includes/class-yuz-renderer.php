<?php
/**
 * Class YUZ_Renderer
 * Handles rendering for the YUZ-TRA plugin with lazy tab loading.
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
// Include the contracts file
require_once YUZ_TRA_INCLUDES . 'class-yuz-contracts.php';
// Load required dependencies
require_once YUZ_TRA_INCLUDES . 'class-yuz-fallbacks.php';
require_once YUZ_TRA_INCLUDES . 'class-yuz-logger.php';
require_once YUZ_TRA_INCLUDES . 'class-yuz-languages.php';
//require_once YUZ_TRA_INCLUDES . 'class-yuz-health-check.php'; // Added for health checks
use YUZTRA\Interfaces\RendererInterface;
use YUZTRA\Interfaces\AjaxInterface;
use YUZTRA\Interfaces\SettingsInterface;
use YUZTRA\Interfaces\LoggerInterface;
use YUZTRA\Interfaces\LanguagesInterface;
use YUZTRA\Fallbacks\NullSettings;
use YUZTRA\Fallbacks\NullAjax;
use YUZTRA\Fallbacks\NullLanguages;
class YUZ_Renderer implements RendererInterface { // here (24)
    private $ajax;
    private $settings;
    private $logger;
    private $languages;
    /**
     * Constructor to inject dependencies.
     *
     * @param AjaxInterface $ajax
     * @param SettingsInterface $settings
     * @param LoggerInterface $logger
     */
    public function __construct( // here (36)
        AjaxInterface $ajax,
        SettingsInterface $settings,
        LoggerInterface $logger,
        LanguagesInterface $languages
    ) { // here (29)
        $this->ajax = $ajax;
        $this->settings = $settings;
        $this->logger = $logger;
        $this->languages = $languages;
    }

    // … dans class YUZ_Renderer …

/**
 * Tab "Licences" exigé par RendererInterface (orthographe UK).
 * On délègue l’affichage au wrapper existant.
 */
public function render_licences_tab(array $settings = []): void {
    // Tu peux passer un petit contexte si tu veux tracer l’origine
    $ctx = is_array($settings) ? $settings : [];
    $ctx['source'] = 'renderer-licences-tab';
    $this->render_licenses_content($ctx);
}

/**
 * (Optionnel) Alias US si du code appelle "licenses" au lieu de "licences".
 */
public function render_licenses_tab(array $settings = []): void {
    $this->render_licences_tab($settings);
}

    /**
     * Initializes renderer (hooks).
     */
    public static function init(): void {
        // Stub deps for init
        $logger = new YUZ_Logger();
        $instance = new self(new NullAjax(), new NullSettings(), $logger, new NullLanguages());
        // No direct admin_menu; handled in Settings/Core
        // Log init
        $logger->log('success', 'YUZ_Renderer initialized');
    }
    /**
     * Renders the tabs wrapper.
     */
    public function render_tab(): void {
    // Désactivé : l'affichage se fait via les hooks de page (YUZ_Settings).
    if (class_exists('YUZ_Logger')) {
        (new \YUZ_Logger())->log('info', 'Renderer::render_tab() désactivé (routeur par hooks).');
    }
    return;
}
    private function normalize_general(array $s): array {
        $s += []; // ensure array
        if (!isset($s['yuz_tra_default_language']) && isset($s['yuz_default_language'])) {
            $s['yuz_tra_default_language'] = $s['yuz_default_language'];
        }
        if (!isset($s['yuz_tra_source_language']) && isset($s['yuz_source_language'])) {
            $s['yuz_tra_source_language'] = $s['yuz_source_language'];
        }
        if (!isset($s['yuz_tra_translatable_languages']) && isset($s['yuz_translatable_languages'])) {
            $s['yuz_tra_translatable_languages'] = $s['yuz_translatable_languages'];
        }
        if (!isset($s['yuz_tra_slug']) && isset($s['yuz_slug'])) { $s['yuz_tra_slug'] = $s['yuz_slug']; }
        if (!isset($s['yuz_tra_code']) && isset($s['yuz_code'])) { $s['yuz_tra_code'] = $s['yuz_code']; }
        return $s;
    }
    // --- General Settings Fields ---
    /**
     * Renders the default language field.
     *
     * @param array $settings General settings (optional).
     */
    public function render_default_language_field(array $settings = []): void {
        $this->logger->log('info', 'Rendering default language field at ' . current_time('mysql'));
        $this->logger->log('info', 'Rendering default language field');
        $options_general = $this->normalize_general($settings ?: $this->settings->get_option('yuz_tra_ws_settings'));
        $default_lang = (string) ($options_general['yuz_tra_default_language'] ?? '');
        $all_languages = $this->languages->get_all_languages(); // here (117)
        if (empty($all_languages)) {
            $this->logger->log('warning', 'No languages available for default language field');
        }
        ?>
        <tr>
            <th scope="row">
                <label for="yuz_tra_ws_settings[yuz_tra_default_language]"><?php esc_html_e('Default Language', 'yuz-tra'); ?></label>
            </th>
            <td>
                <select id="yuz_tra_ws_settings[yuz_tra_default_language]" name="yuz_tra_ws_settings[yuz_tra_default_language]" style="border: 1px solid #ddd;">
                    <?php foreach ($all_languages as $lang) : ?>
                        <option value="<?php echo esc_attr($lang->get_code()); ?>" <?php selected($default_lang, $lang->get_code()); ?>>
                            <?php echo esc_html($lang->get_name() . ' (' . $lang->get_code() . ')'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="yuz-tra-description"><?php esc_html_e('Select the default language for your site.', 'yuz-tra'); ?></p>
            </td>
        </tr>
        <?php
        $this->logger->log('info', 'Default language field rendered successfully');
        $this->logger->log('success', 'Default language field rendered successfully');
    }
    /**
     * Renders the source language field.
     *
     * @param array $settings General settings (optional).
     */
    public function render_source_language_field(array $settings = []): void {
        $this->logger->log('info', 'Rendering source language field at ' . current_time('mysql'));
        $this->logger->log('info', 'Rendering source language field');
        $options_general = $this->normalize_general($settings ?: $this->settings->get_option('yuz_tra_ws_settings'));
        $source_lang = (string) ($options_general['yuz_tra_source_language'] ?? '');
        $all_languages = $this->languages->get_all_languages();
        if (empty($all_languages)) {
            $this->logger->log('warning', 'No languages available for source language field');
        }
        ?>
        <tr>
            <th scope="row">
                <label for="yuz_tra_ws_settings[yuz_tra_source_language]"><?php esc_html_e('Source Language', 'yuz-tra'); ?></label>
            </th>
            <td>
                <select id="yuz_tra_ws_settings[yuz_tra_source_language]" name="yuz_tra_ws_settings[yuz_tra_source_language]" style="border: 1px solid #ddd;">
                    <?php foreach ($all_languages as $lang) : ?>
                        <option value="<?php echo esc_attr($lang->get_code()); ?>" <?php selected($source_lang, $lang->get_code()); ?>>
                            <?php echo esc_html($lang->get_name() . ' (' . $lang->get_code() . ')'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="yuz-tra-description"><?php esc_html_e('Select the source language for translations.', 'yuz-tra'); ?></p>
            </td>
        </tr>
        <?php
        $this->logger->log('info', 'Source language field rendered successfully');
        $this->logger->log('success', 'Source language field rendered successfully');
    }
    /**
     * Renders the translatable languages field.
     *
     * @param array $settings General settings (optional).
     */
    public function render_translatable_languages_field(array $settings = []): void {
        $this->logger->log('info', 'Rendering translatable languages field at ' . current_time('mysql'));
        $this->logger->log('info', 'Rendering translatable languages field');
        global $wpdb;
        $table_name = $wpdb->prefix . 'yuz_tra_languages';
        // Health check for table existence
        if (class_exists('YUZ_Health_Check')) {
            YUZ_Health_Check::ensure(
                $wpdb->get_var("SHOW TABLES LIKE '$table_name'") !== null,
                'Languages table missing',
                __METHOD__
            );
        }
        $options_general = $this->normalize_general($settings ?: $this->settings->get_option('yuz_tra_ws_settings'));
        $translatable_languages = $this->languages->get_translatable_languages();
        $translatable = array_map(function($lang_obj) {
            return $lang_obj->get_code();
        }, $translatable_languages);
        $all_languages = $this->languages->get_all_languages();
        $available = array_filter($all_languages, function($lang_obj) use ($translatable) {
            return !in_array($lang_obj->get_code(), $translatable, true)
                && !$lang_obj->is_default
                && !$lang_obj->is_source;
        });
        if (empty($all_languages)) {
            $this->logger->log('warning', 'No languages available for translatable languages field');
        }
        ?>
        <tr>
            <th scope="row">
                <?php esc_html_e('Translatable Language(s)', 'yuz-tra'); ?>
            </th>
            <td>
                <p class="yuz-tra-description">
                    <?php esc_html_e('Drag and drop to reorder languages by priority. Lower weight means higher priority.', 'yuz-tra'); ?>
                </p>
                <div style="display: flex; font-weight: bold; margin-bottom: 8px; gap: 16px;">
                    <span style="flex: 1;"><?php esc_html_e('Language', 'yuz-tra'); ?></span>
                    <span style="width: 100px;"><?php esc_html_e('Slug', 'yuz-tra'); ?></span>
                    <span style="width: 100px;"><?php esc_html_e('Code', 'yuz-tra'); ?></span>
                    <span style="width: 32px;"></span>
                </div>
                <ul id="yuz_tra_translatable_list" class="yuz-tra-translatable-list" style="list-style: none; margin: 0; padding: 0;">
                    <?php if (empty($translatable)) : ?>
                        <li style="color: #6c757d;"><?php esc_html_e('No translatable languages set. Add languages to start translating.', 'yuz-tra'); ?></li>
                        <?php $this->logger->log('warning', 'No translatable languages set'); ?>
                    <?php else : ?>
                        <?php foreach ($translatable as $code) : ?>
                            <?php foreach ($all_languages as $lang) : ?>
                                <?php if ($lang->get_code() === $code) : ?>
                                    <li class="yuz-tra-language-row" data-language-code="<?php echo esc_attr($lang->get_code()); ?>" style="background: #f8f9fa; padding: 10px; margin-bottom: 8px; display: flex; align-items: center; gap: 16px;">
                                        <span class="yuz-tra-drag-handle dashicons dashicons-menu" style="cursor: move;"></span>
                                        <span style="flex: 1;">
                                            <?php echo esc_html($lang->get_name() . ' (' . $lang->get_code() . ')'); ?>
                                        </span>
                                        <input type="text" class="yuz-tra-regular-text" name="yuz_tra_ws_settings[yuz_tra_slug][<?php echo esc_attr($lang->get_code()); ?>]" value="<?php echo esc_attr(isset($options_general['yuz_tra_slug'][$lang->get_code()]) ? $options_general['yuz_tra_slug'][$lang->get_code()] : strtolower(str_replace('-', '_', $lang->get_code()))); ?>" style="width: 100px;">
                                        <input type="text" class="yuz-tra-regular-text" name="yuz_tra_ws_settings[yuz_tra_code][<?php echo esc_attr($lang->get_code()); ?>]" value="<?php echo esc_attr(isset($options_general['yuz_tra_code'][$lang->get_code()]) ? $options_general['yuz_tra_code'][$lang->get_code()] : $lang->get_code()); ?>" style="width: 100px;">
                                        <button type="button" id="yuz_tra_yuz_remove_language_<?php echo esc_attr($lang->get_code()); ?>" class="yuz-tra-remove-language yuz-btn-remove" data-language-code="<?php echo esc_attr($lang->get_code()); ?>" title="<?php esc_attr_e('Remove', 'yuz-tra'); ?>">
                                            <span class="yuz-tra-icon" aria-hidden="true">
                                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                    <line x1="18" y1="6" x2="6" y2="18"/>
                                                    <line x1="6" y1="6" x2="18" y2="18"/>
                                                </svg>
                                            </span>
                                        </button>
                                        <input type="hidden" name="yuz_tra_ws_settings[yuz_tra_translatable_languages][]" value="<?php echo esc_attr($lang->get_code()); ?>">
                                    </li>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </ul>
                <div class="yuz-tra-add-language-section" style="margin-top: 16px;">
                    <select id="yuz_tra_yuz_add_language_select" style="border: 1px solid #ddd;">
                        <option value=""><?php echo esc_html__('Select a language to add', 'yuz-tra'); ?></option>
                        <?php foreach ($available as $lang) : ?>
                            <option value="<?php echo esc_attr($lang->get_code()); ?>">
                                <?php echo esc_html($lang->get_name() . ' (' . $lang->get_code() . ')'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <button type="button" id="yuz_tra_yuz_add_language_btn" class="yuz-tra-button yuz-add-language"><?php esc_html_e('Add Language', 'yuz-tra'); ?></button>
                    <button type="button" id="yuz_tra_yuz_add_all_languages_btn" class="yuz-tra-button yuz-add-all-languages"><?php esc_html_e('Add All Languages', 'yuz-tra'); ?></button>
                    <button type="button" id="yuz_tra_yuz_remove_all_languages_btn" class="yuz-tra-button yuz-remove-all-languages"><?php esc_html_e('Remove All Languages', 'yuz-tra'); ?></button>
                </div>
            </td>
        </tr>
        <?php
        $this->logger->log('info', 'Translatable languages field rendered successfully');
        $this->logger->log('success', 'Translatable languages field rendered successfully', ['translatable_count' => count($translatable)]);
    }
    /**
     * Renders the native language name field.
     *
     * @param array $settings Translation settings (optional).
     */
    public function render_native_language_name_field(array $settings = []): void { // here (220)
        $this->logger->log('info', 'Rendering native language name field at ' . current_time('mysql'));
        $this->logger->log('info', 'Rendering native language name field');
        $options_settings = $settings ?: $this->settings->get_option('yuz_tra_ls_settings');
        $value = $options_settings['native_language_name'] ?? '';
        ?>
        <div class="yuz-tra-field">
            <input type="hidden" name="yuz_tra_ls_settings[native_language_name]" value="0">
            <label class="switch">
                <input type="checkbox" id="yuz_tra_ls_settings[native_language_name]" name="yuz_tra_ls_settings[native_language_name]" value="1" <?php checked(!empty($value)); ?>>
                <span class="slider round"></span>
            </label>
            <label for="yuz_tra_ls_settings[native_language_name]"><?php esc_html_e('Use Native Language Names', 'yuz-tra'); ?></label>
            <p class="yuz-tra-description"><?php esc_html_e('Display languages in their native names (e.g., Español instead of Spanish).', 'yuz-tra'); ?></p>
        </div>

        
        <?php
        $this->logger->log('info', 'Native language name field rendered successfully');
        $this->logger->log('success', 'Native language name field rendered successfully');
    }
    /**
     * Renders the use subdirectory field.
     *
     * @param array $settings Translation settings (optional).
     */
    public function render_use_subdirectory_field(array $settings = []): void {
        $this->logger->log('info', 'Rendering use subdirectory field at ' . current_time('mysql'));
        $this->logger->log('info', 'Rendering use subdirectory field');
        $options_settings = $settings ?: $this->settings->get_option('yuz_tra_ls_settings');
        $value = $options_settings['use_subdirectory'] ?? '';
        ?>
        <div class="yuz-tra-field">
            <input type="hidden" name="yuz_tra_ls_settings[use_subdirectory]" value="0">
            <label class="switch">
                <input type="checkbox" id="yuz_tra_ls_settings[use_subdirectory]" name="yuz_tra_ls_settings[use_subdirectory]" value="1" <?php checked(!empty($value)); ?>>
                <span class="slider round"></span>
            </label>
            <label for="yuz_tra_ls_settings[use_subdirectory]"><?php esc_html_e('Use Subdirectory for Languages', 'yuz-tra'); ?></label>
            <p class="yuz-tra-description"><?php esc_html_e('Enable to use subdirectories for languages (e.g., example.com/fr/).', 'yuz-tra'); ?></p>
        </div>
        <?php
        $this->logger->log('info', 'Use subdirectory field rendered successfully');
        $this->logger->log('success', 'Use subdirectory field rendered successfully');
    }
    /**
     * Renders the force language in links field.
     *
     * @param array $settings Translation settings (optional).
     */
    public function render_force_lang_in_links_field(array $settings = []): void {
        $this->logger->log('info', 'Rendering force lang in links field at ' . current_time('mysql'));
        $this->logger->log('info', 'Rendering force lang in links field');
        $options_settings = $settings ?: $this->settings->get_option('yuz_tra_ls_settings');
        $value = $options_settings['force_lang_in_links'] ?? '';
        ?>
        <div class="yuz-tra-field">
            <input type="hidden" name="yuz_tra_ls_settings[force_lang_in_links]" value="0">
            <label class="switch">
                <input type="checkbox" id="yuz_tra_ls_settings[force_lang_in_links]" name="yuz_tra_ls_settings[force_lang_in_links]" value="1" <?php checked(!empty($value)); ?>>
                <span class="slider round"></span>
            </label>
            <label for="yuz_tra_ls_settings[force_lang_in_links]"><?php esc_html_e('Force Language in Links', 'yuz-tra'); ?></label>
            <p class="yuz-tra-description"><?php esc_html_e('Force the language code in all internal links.', 'yuz-tra'); ?></p>
        </div>
        <?php
        $this->logger->log('info', 'Force lang in links field rendered successfully');
        $this->logger->log('success', 'Force lang in links field rendered successfully');
    }
    /**
     * Renders the shortcode block.
     *
     * @param array $settings Translation settings (optional).
     */
    public function render_shortcode_block(array $settings = []): void {
        $this->logger->log('info', 'Rendering shortcode block at ' . current_time('mysql'));
        $this->logger->log('info', 'Rendering shortcode block');
        $options_settings = $settings ?: $this->settings->get_option('yuz_tra_sw_settings');
        $value = $options_settings['shortcode_enabled'] ?? '';
        $shortcode_format = (string) ($options_settings['shortcode_format'] ?? 'flags-full-names');
        ?>
        <div class="yuz-tra-field yuz-field yuz-settings-checkbox">
            <input type="hidden" name="yuz_tra_sw_settings[shortcode_enabled]" value="0">
            <label class="switch" for="yuz_tra_sw_settings[shortcode_enabled]">
                <input type="checkbox" id="yuz_tra_sw_settings[shortcode_enabled]" name="yuz_tra_sw_settings[shortcode_enabled]" value="1" <?php checked(!empty($value)); ?>>
                <span class="slider round"></span>
            </label>
            <div class="yuz-checkbox-content">
                <label for="yuz_tra_sw_settings[shortcode_enabled]" class="yuz-primary-text-bold yuz-tra-primary-text-bold">
                    <?php esc_html_e('Shortcode [language-switcher]', 'yuz-tra'); ?>
                </label>
                <select id="yuz_tra_sw_settings[shortcode_format]" name="yuz_tra_sw_settings[shortcode_format]" class="yuz-select yuz-tra-select yuz-ls-select-option">
                    <?php $this->render_shortcode_format_options($shortcode_format); ?>
                </select>
                <p class="yuz-description-text yuz-tra-description">
                    <?php esc_html_e('Use the shortcode on any page or widget. You can also add the Language Switcher Block in the WP Gutenberg Editor.', 'yuz-tra'); ?>
                    <?php esc_html_e('It inherits the floating switcher theme and position unless you override them with shortcode attributes.', 'yuz-tra'); ?>
                </p>
            </div>
        </div>
        <?php
        $this->logger->log('info', 'Shortcode block rendered successfully');
        $this->logger->log('success', 'Shortcode block rendered successfully');
    }
    /**
     * Renders the menu item block.
     *
     * @param array $settings Translation settings (optional).
     */
    public function render_menu_item_block(array $settings = []): void {
        $this->logger->log('info', 'Rendering menu item block at ' . current_time('mysql'));
        $this->logger->log('info', 'Rendering menu item block');
        $options_settings = $settings ?: $this->settings->get_option('yuz_tra_sw_settings');
        $value = $options_settings['menu_enabled'] ?? '';
        $menu_format = (string) ($options_settings['menu_format'] ?? 'flags-full-names');
        ?>
        <div class="yuz-tra-field yuz-field yuz-settings-checkbox">
            <input type="hidden" name="yuz_tra_sw_settings[menu_enabled]" value="0">
            <label class="switch" for="yuz_tra_sw_settings[menu_enabled]">
                <input type="checkbox" id="yuz_tra_sw_settings[menu_enabled]" name="yuz_tra_sw_settings[menu_enabled]" value="1" <?php checked(!empty($value)); ?>>
                <span class="slider round"></span>
            </label>
            <div class="yuz-checkbox-content">
                <label for="yuz_tra_sw_settings[menu_enabled]" class="yuz-primary-text-bold yuz-tra-primary-text-bold">
                    <?php esc_html_e('Menu item', 'yuz-tra'); ?>
                </label>
                <select id="yuz_tra_sw_settings[menu_format]" name="yuz_tra_sw_settings[menu_format]" class="yuz-select yuz-tra-select yuz-ls-select-option">
                    <?php $this->render_menu_format_options($menu_format, true); ?>
                </select>
                <p class="yuz-description-text yuz-tra-description">
                    <?php esc_html_e('Go to Appearance → Menus to add languages to the Language Switcher in any menu.', 'yuz-tra'); ?>
                    <a href="https://github.com/Youzurz/yuz-tra-wp#readme" target="_blank" rel="noopener"><?php esc_html_e('Learn more in our documentation.', 'yuz-tra'); ?></a>
                </p>
            </div>
        </div>
        <?php
        $this->logger->log('info', 'Menu item block rendered successfully');
        $this->logger->log('success', 'Menu item block rendered successfully');
    }
    /**
     * Renders the floating language selection block.
     *
     * @param array $settings Translation settings (optional).
     */
    public function render_floating_language_selection_block(array $settings = []): void {
        $this->logger->log('info', 'Rendering floating language selection block at ' . current_time('mysql'));
        $this->logger->log('info', 'Rendering floating language selection block');
        $options_settings = $settings ?: $this->settings->get_option('yuz_tra_sw_settings');
        $value = $options_settings['floating_enabled'] ?? '';
        $floating_format = (string) ($options_settings['floating_format'] ?? 'flags-full-names');
        $floating_theme = (string) ($options_settings['floating_theme'] ?? 'dark');
        $floating_pos = (string) ($options_settings['floating_position'] ?? 'bottom-right');
        ?>
        <div class="yuz-tra-field yuz-field yuz-settings-checkbox">
            <input type="hidden" name="yuz_tra_sw_settings[floating_enabled]" value="0">
            <label class="switch" for="yuz_tra_sw_settings[floating_enabled]">
                <input type="checkbox" id="yuz_tra_sw_settings[floating_enabled]" name="yuz_tra_sw_settings[floating_enabled]" value="1" <?php checked(!empty($value)); ?>>
                <span class="slider round"></span>
            </label>
            <div class="yuz-checkbox-content">
                <label for="yuz_tra_sw_settings[floating_enabled]" class="yuz-primary-text-bold yuz-tra-primary-text-bold">
                    <?php esc_html_e('Floating language selection', 'yuz-tra'); ?>
                </label>
                <div class="yuz-select-group">
                    <select id="yuz_tra_sw_settings[floating_format]" name="yuz_tra_sw_settings[floating_format]" class="yuz-select yuz-tra-select yuz-ls-select-option">
                        <?php $this->render_floater_format_options($floating_format); ?>
                    </select>
                    <select id="yuz_tra_sw_settings[floating_theme]" name="yuz_tra_sw_settings[floating_theme]" class="yuz-select yuz-tra-select yuz-ls-select-option">
                        <option value="dark" <?php selected($floating_theme, 'dark'); ?>><?php esc_html_e('Dark', 'yuz-tra'); ?></option>
                        <option value="light" <?php selected($floating_theme, 'light'); ?>><?php esc_html_e('Light', 'yuz-tra'); ?></option>
                    </select>
                    <select id="yuz_tra_sw_settings[floating_position]" name="yuz_tra_sw_settings[floating_position]" class="yuz-select yuz-tra-select yuz-ls-select-option">
                        <?php $this->render_positions($floating_pos); ?>
                    </select>
                </div>
                <p class="yuz-description-text yuz-tra-description"><?php esc_html_e('Add a floating dropdown that follows the user on every page.', 'yuz-tra'); ?></p>
            </div>
        </div>
        <?php
        $this->logger->log('info', 'Floating language selection block rendered successfully');
        $this->logger->log('success', 'Floating language selection block rendered successfully');
    }
    /**
     * Renders the powered by block.
     *
     * @param array $settings Translation settings (optional).
     */
    public function render_powered_by_block(array $settings = []): void {
        $this->logger->log('info', 'Rendering powered by block at ' . current_time('mysql'));
        $this->logger->log('info', 'Rendering powered by block');
        $options_settings = $settings ?: $this->settings->get_option('yuz_tra_sw_settings');
        $value = $options_settings['show_poweredby'] ?? '';
        ?>
        <div class="yuz-tra-field yuz-field yuz-settings-checkbox">
            <input type="hidden" name="yuz_tra_sw_settings[show_poweredby]" value="0">
            <label class="switch" for="yuz_tra_sw_settings[show_poweredby]">
                <input type="checkbox" id="yuz_tra_sw_settings[show_poweredby]" name="yuz_tra_sw_settings[show_poweredby]" value="1" <?php checked(!empty($value)); ?>>
                <span class="slider round"></span>
            </label>
            <div class="yuz-checkbox-content">
                <label for="yuz_tra_sw_settings[show_poweredby]" class="yuz-primary-text-bold yuz-tra-primary-text-bold">
                    <?php esc_html_e('Show "Powered by YoUZurz"', 'yuz-tra'); ?>
                </label>
                <p class="yuz-description-text yuz-tra-description"><?php esc_html_e('Show the small "Powered by YoUZurz" label in the floating language switcher.', 'yuz-tra'); ?></p>
            </div>
        </div>
        <?php
        $this->logger->log('info', 'Powered by block rendered successfully');
        $this->logger->log('success', 'Powered by block rendered successfully');
    }
    // --- Translate Site Fields ---
    /**
     * Renders the translate site button.
     *
     * @param array $settings Translation site settings (optional).
     */
    public function render_translate_site_button(array $settings = []): void {
    $this->logger->log('info', 'Rendering translate site button (CSP-safe data attrs)');

    // Bouton piloté par assets/js/yuz-translation-admin.js
    // ACTION logique -> start_translation  (→ AJAX: yuz_tra_ts_start_translation)
    ?>
    <div class="yuz-tra-section">
        <?php
        // Lien direct vers l'éditeur front (toujours cliquable)
        $href = add_query_arg('yuz-edit-translation', '1', home_url('/'));
        ?>
        <a
            id="yuz_tra_translate_now_link"
            class="button button-primary"
            href="<?php echo esc_url($href); ?>"
            data-yuz-ts-action="start_translation"
            data-scope="current_page"
        >
            <?php esc_html_e('Translate Now', 'yuz-tra'); ?>
        </a>
        <noscript>
            <p>
                <a class="button" href="<?php echo esc_url($href); ?>">
                    <?php esc_html_e('Open Translation Editor', 'yuz-tra'); ?>
                </a>
            </p>
        </noscript>
    </div>
    <?php

    $this->logger->log('success', 'Translate site button rendered (delegated handlers)');
}
    /**
     * Renders the support extra languages field.
     *
     * @param array $settings Translation site settings (optional).
     */
    public function render_support_extra_languages_field(array $settings = []): void {
    $options_settings = $settings ?: $this->settings->get_option('yuz_tra_ts_settings');
    $value = $options_settings['enable_extra_languages'] ?? '0';
    ?>
    <tr>
        <th scope="row"><?php esc_html_e('Support Extra Languages', 'yuz-tra'); ?></th>
        <td>
            <label class="switch">
                <input type="hidden" name="yuz_tra_ts_settings[enable_extra_languages]" value="0">
                <input
                    type="checkbox"
                    id="yuz_tra_site_settings_enable_extra_languages"
                    name="yuz_tra_ts_settings[enable_extra_languages]"
                    value="1" <?php checked($value, '1'); ?>
                    data-yuz-ts-action="update_settings"
                    data-yuz-ts-on="change"
                >
                <span class="slider round"></span>
            </label>
            <label for="yuz_tra_site_settings_enable_extra_languages">
                <?php esc_html_e('Enable support for over 130 additional languages.', 'yuz-tra'); ?>
            </label>
        </td>
    </tr>
    <?php
}

    /**
     * Renders the YoUZuruz AI field.
     *
     * @param array $settings Translation site settings (optional).
     */
    public function render_youzuruz_ai_field(array $settings = []): void {
    $this->logger->log('info', 'Rendering YoUZuruz AI field (delegated change)');
    $options_settings = $settings ?: $this->settings->get_option('yuz_tra_ts_settings');
    $ai_flag   = $options_settings['enable_ai'] ?? ($options_settings['enable_youzuruz'] ?? false);
    $enable_ai = (string) ((bool) $ai_flag ? '1' : '0');
    ?>
    <tr>
        <th scope="row"><?php esc_html_e('Access YoUZuruz AI', 'yuz-tra'); ?></th>
        <td>
            <label class="switch">
                <input
                    type="checkbox"
                    id="yuz_tra_enable_ai"
                    name="yuz_tra_ts_settings[enable_ai]"
                    value="1" <?php checked($enable_ai, '1'); ?>
                    data-yuz-ts-action="update_settings"
                    data-yuz-ts-on="change"
                >
                <span class="slider round"></span>
            </label>
            <label for="yuz_tra_enable_ai"><?php esc_html_e('Enable AI-powered translation features.', 'yuz-tra'); ?></label>
        </td>
    </tr>
    <?php
}

    // --- Advanced, Addons, Licences ---
    /**
     * Renders the advanced tab content with AI options.
     *
     * @param array $settings Advanced settings (optional).
     */
    public function render_advanced_tab_content(array $settings = []): void {
        $this->logger->log('info', 'Rendering advanced tab content at ' . current_time('mysql'));
        $this->logger->log('info', 'Rendering advanced tab content');
        $options_settings = $settings ?: $this->settings->get_option('yuz_tra_av_settings');
        ?>
        <div class="yuz-tra-section">
            <h2 class="yuz-tra-section-title"><?php esc_html_e('Advanced Options', 'yuz-tra'); ?></h2>
            <hr>
            <table class="form-table">
                <?php
                // AI Configuration Section
                $this->logger->log('info', 'Rendering AI configuration options');
                ?>
                <tr>
                    <th scope="row"><?php esc_html_e('AI Model Selection', 'yuz-tra'); ?></th>
                    <td>
                        <select id="yuz_tra_ai_model" name="yuz_tra_av_settings[ai_model]">
                            <option value="default" <?php selected($options_settings['ai_model'] ?? 'default', 'default'); ?>><?php esc_html_e('Default Model', 'yuz-tra'); ?></option>
                            <option value="custom" <?php selected($options_settings['ai_model'] ?? '', 'custom'); ?>><?php esc_html_e('Custom Model', 'yuz-tra'); ?></option>
                        </select>
                        <p class="yuz-tra-description"><?php esc_html_e('Choose the AI model for translations.', 'yuz-tra'); ?></p>
                    </td>
                </tr>
                <tr id="yuz_tra_custom_ai_endpoint_row" style="<?php echo ($options_settings['ai_model'] ?? 'default') === 'custom' ? '' : 'display:none;'; ?>">
                    <th scope="row"><?php esc_html_e('Custom AI Endpoint', 'yuz-tra'); ?></th>
                    <td>
                        <input type="text" id="yuz_tra_custom_ai_endpoint" name="yuz_tra_av_settings[custom_ai_endpoint]" value="<?php echo esc_attr($options_settings['custom_ai_endpoint'] ?? ''); ?>" placeholder="<?php esc_attr_e('Enter custom AI endpoint', 'yuz-tra'); ?>" style="width: 100%; max-width: 400px;">
                        <p class="yuz-tra-description"><?php esc_html_e('URL for a custom AI translation service.', 'yuz-tra'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('AI Translation Priority', 'yuz-tra'); ?></th>
                    <td>
                        <input type="number" id="yuz_tra_ai_priority" name="yuz_tra_av_settings[ai_priority]" value="<?php echo esc_attr($options_settings['ai_priority'] ?? '50'); ?>" min="0" max="100" style="width: 100px;">
                        <p class="yuz-tra-description"><?php esc_html_e('Priority level for AI translations (0-100, higher prioritizes AI).', 'yuz-tra'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Enable Advanced Editor UI', 'yuz-tra'); ?></th>
                    <td>
                        <?php $adv_ui = !empty($options_settings['editor_advanced_ui']) ? '1' : '0'; ?>
                        <label>
                            <input type="checkbox" name="yuz_tra_av_settings[editor_advanced_ui]" value="1" <?php checked($adv_ui, '1'); ?>>
                            <?php esc_html_e('Show advanced actions (Publish, Preview, Auto-translate, Reset, Refresh) in the compact editor.', 'yuz-tra'); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Show "Strings" in Admin Bar', 'yuz-tra'); ?></th>
                    <td>
                        <?php $ta_enabled = !empty($options_settings['translate_admin_enabled']) ? '1' : '0'; ?>
                        <label>
                            <input type="checkbox" name="yuz_tra_av_settings[translate_admin_enabled]" value="1" <?php checked($ta_enabled, '1'); ?>>
                            <?php esc_html_e('Display the Strings entry in the admin bar.', 'yuz-tra'); ?>
                        </label>
                    </td>
                </tr>
            </table>
            <?php
wp_register_script('yuz-tra-renderer-fields', false, ['jquery'], YUZ_TRA_VERSION, true);
wp_enqueue_script('yuz-tra-renderer-fields');
wp_add_inline_script('yuz-tra-renderer-fields', <<<'YUZTRA_JS'
jQuery(function ($) {
(function($) {
                    function toggleCustomEndpoint() {
                        var model = $('#yuz_tra_ai_model').val();
                        $('#yuz_tra_custom_ai_endpoint_row').toggle(model === 'custom');
                    }
                    $('#yuz_tra_ai_model').on('change', toggleCustomEndpoint);
                    toggleCustomEndpoint();
                })(jQuery);
});
YUZTRA_JS
, 'after');
?>
        </div>
        <?php
        // Maintenance tools (Advanced → Tools‑like section)
        ?>
        
        <?php
        $this->logger->log('info', 'Advanced tab content rendered successfully');
        $this->logger->log('success', 'Advanced tab content rendered successfully');
    }
    /**
     * Renders the translate SEO field.
     *
     * @param array $settings Translation site settings (optional).
     */
    public function render_translate_seo_field(array $settings = []): void {
    $options_settings = $settings ?: $this->settings->get_option('yuz_tra_ts_settings');
    $translate_seo = (string) ($options_settings['translate_seo'] ?? '0');
    ?>
    <tr>
        <th scope="row"><?php esc_html_e('Translate SEO Metadata', 'yuz-tra'); ?></th>
        <td>
            <label class="switch">
                <input
                    type="checkbox"
                    id="yuz_tra_translate_seo"
                    name="yuz_tra_ts_settings[translate_seo]"
                    value="1" <?php checked($translate_seo, '1'); ?>
                    data-yuz-ts-action="update_settings"
                    data-yuz-ts-on="change"
                >
                <span class="slider round"></span>
            </label>
            <label for="yuz_tra_translate_seo">
                <?php esc_html_e('Translate SEO metadata for better search engine visibility.', 'yuz-tra'); ?>
            </label>
        </td>
    </tr>
    <?php
}

    /**
     * Renders the publish only complete field.
     *
     * @param array $settings Translation site settings (optional).
     */
    public function render_publish_only_complete_field(array $settings = []): void {
    $options_settings = $settings ?: $this->settings->get_option('yuz_tra_ts_settings');
    $require_comp = (string) ($options_settings['require_complete'] ?? '0');
    ?>
    <tr>
        <th scope="row"><?php esc_html_e('Publish Only Complete Translations', 'yuz-tra'); ?></th>
        <td>
            <label class="switch">
                <input
                    type="checkbox"
                    id="yuz_tra_require_complete"
                    name="yuz_tra_ts_settings[require_complete]"
                    value="1" <?php checked($require_comp, '1'); ?>
                    data-yuz-ts-action="update_settings"
                    data-yuz-ts-on="change"
                >
                <span class="slider round"></span>
            </label>
            <label for="yuz_tra_require_complete">
                <?php esc_html_e('Only publish fully completed translations.', 'yuz-tra'); ?>
            </label>
        </td>
    </tr>
    <?php
}

    /**
     * Renders the translate by role field.
     *
     * @param array $settings Translation site settings (optional).
     */
    public function render_translate_by_role_field(array $settings = []): void {
    $options_settings = $settings ?: $this->settings->get_option('yuz_tra_ts_settings');
    $user_role = (string) ($options_settings['user_role_emulation'] ?? '');
    $roles = wp_roles()->get_names();
    ?>
    <tr>
        <th scope="row"><?php esc_html_e('Translate by User Role', 'yuz-tra'); ?></th>
        <td>
            <select
                id="yuz_tra_user_role_emulation"
                name="yuz_tra_ts_settings[user_role_emulation]"
                style="margin-left: 10px; border: 1px solid #ddd;"
                data-yuz-ts-action="update_settings"
                data-yuz-ts-on="change"
            >
                <option value=""><?php esc_html_e('Select a role', 'yuz-tra'); ?></option>
                <?php foreach ($roles as $role_value => $role_name) : ?>
                    <option value="<?php echo esc_attr($role_value); ?>" <?php selected($user_role, $role_value); ?>>
                        <?php echo esc_html($role_name); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <p class="yuz-tra-description">
                <?php esc_html_e('Simulate navigation as a specific user role to test translated views.', 'yuz-tra'); ?>
            </p>
        </td>
    </tr>
    <?php
}

    /**
     * Renders the menu per language field.
     *
     * @param array $settings Translation site settings (optional).
     */
    public function render_menu_per_language_field(array $settings = []): void {
    $options_settings = $settings ?: $this->settings->get_option('yuz_tra_ts_settings');
    $menu_per = (string) ($options_settings['menu_per_lang'] ?? '0');
    ?>
    <tr>
        <th scope="row"><?php esc_html_e('Different Menu per Language', 'yuz-tra'); ?></th>
        <td>
            <label class="switch">
                <input
                    type="checkbox"
                    id="yuz_tra_menu_per_lang"
                    name="yuz_tra_ts_settings[menu_per_lang]"
                    value="1" <?php checked($menu_per, '1'); ?>
                    data-yuz-ts-action="update_settings"
                    data-yuz-ts-on="change"
                >
                <span class="slider round"></span>
            </label>
            <label for="yuz_tra_menu_per_lang">
                <?php esc_html_e('Enable different menus for each language.', 'yuz-tra'); ?>
            </label>
            <p class="yuz-tra-description">
                <?php esc_html_e('Allows you to assign unique menus for each language.', 'yuz-tra'); ?>
            </p>
        </td>
    </tr>
    <?php
}

    /**
     * Renders the browser language detect field.
     *
     * @param array $settings Translation site settings (optional).
     */
    public function render_browser_language_detect_field(array $settings = []): void {
    $options_settings = $settings ?: $this->settings->get_option('yuz_tra_ts_settings');
    $browser_det = (string) ($options_settings['browser_language_detect'] ?? '0');
    ?>
    <tr>
        <th scope="row"><?php esc_html_e('Automatic User Language Detection', 'yuz-tra'); ?></th>
        <td>
            <label class="switch">
                <input
                    type="checkbox"
                    id="yuz_tra_browser_language_detect"
                    name="yuz_tra_ts_settings[browser_language_detect]"
                    value="1" <?php checked($browser_det, '1'); ?>
                    data-yuz-ts-action="update_settings"
                    data-yuz-ts-on="change"
                >
                <span class="slider round"></span>
            </label>
            <label for="yuz_tra_browser_language_detect">
                <?php esc_html_e('Detect user language from browser settings.', 'yuz-tra'); ?>
            </label>
            <p class="yuz-tra-description">
                <?php esc_html_e('Automatically switches to the user’s preferred language based on their browser settings.', 'yuz-tra'); ?>
            </p>
        </td>
    </tr>
    <?php
}

    /**
     * Renders the block browser native translation field.
     *
     * @param array $settings Translation site settings (optional).
     */
    public function render_block_browser_translation_field(array $settings = []): void {
    $options_settings = $settings ?: $this->settings->get_option('yuz_tra_ts_settings');
    $block_native = (string) ($options_settings['block_browser_translation'] ?? '0');
    ?>
    <tr>
        <th scope="row"><?php esc_html_e('Block Native Browser Translation', 'yuz-tra'); ?></th>
        <td>
            <label class="switch">
                <input
                    type="checkbox"
                    id="yuz_tra_block_browser_translation"
                    name="yuz_tra_ts_settings[block_browser_translation]"
                    value="1" <?php checked($block_native, '1'); ?>
                    data-yuz-ts-action="update_settings"
                    data-yuz-ts-on="change"
                >
                <span class="slider round"></span>
            </label>
            <label for="yuz_tra_block_browser_translation">
                <?php esc_html_e('Ask browsers to disable their built-in translators on your site.', 'yuz-tra'); ?>
            </label>
            <p class="yuz-tra-description">
                <?php esc_html_e('Adds notranslate metadata for Chrome/Edge and a translate="no" hint for other browsers; YoUZurz translations still work.', 'yuz-tra'); ?>
            </p>
        </td>
    </tr>
    <?php
}

    // --- Automatic Translation Fields ---
    /**
     * Renders the enable auto translation field.
     *
     * @param array $settings API settings (optional).
     */
    public function render_enable_auto_translation_field(array $settings = []): void {
        $this->logger->log('info', 'Rendering enable auto translation field at ' . current_time('mysql'));
        $this->logger->log('info', 'Rendering enable auto translation field');
        $options_settings = $settings ?: $this->settings->get_option('yuz_tra_at_settings');
        $enable_auto = (string) ($options_settings['enable_auto_translate'] ?? '0');
        $mode = (string) ($options_settings['translation_mode'] ?? 'manual');
        $disabled = ($mode === 'manual');
        ?>
        <div class="yuz-tra-section yuz-auto-translation">
            <h2 class="yuz-tra-section-title"><?php esc_html_e('Enable Automatic Translation', 'yuz-tra'); ?></h2>
            <hr>
            <label class="switch">
                <input type="checkbox" id="yuz_tra_enable_auto_translate" name="yuz_tra_at_settings[enable_auto_translate]" value="1" <?php checked($enable_auto, '1'); ?> <?php disabled($disabled, true); ?>>
                <span class="slider round"></span>
            </label>
            <label for="yuz_tra_enable_auto_translate"><?php esc_html_e('Automatically translate content using an API.', 'yuz-tra'); ?></label>
            <p class="yuz-tra-description">
                <?php esc_html_e('This option is disabled in Manual mode, as translations are performed via the editor.', 'yuz-tra'); ?>
            </p>
        </div>
        <?php
wp_register_script('yuz-tra-renderer-fields', false, ['jquery'], YUZ_TRA_VERSION, true);
wp_enqueue_script('yuz-tra-renderer-fields');
wp_add_inline_script('yuz-tra-renderer-fields', <<<'YUZTRA_JS'
jQuery(function ($) {
(function($) {
                function toggleAutoTranslationField() {
                    var mode = $('#yuz_tra_translation_mode').val();
                    if (mode === 'manual') {
                        $('#yuz_tra_enable_auto_translate').prop('disabled', true);
                        $('.yuz-auto-translation').css('opacity', '0.6');
                    } else {
                        $('#yuz_tra_enable_auto_translate').prop('disabled', false);
                        $('.yuz-auto-translation').css('opacity', '1');
                    }
                }
                $('#yuz_tra_translation_mode').on('change', toggleAutoTranslationField);
                toggleAutoTranslationField();
            })(jQuery);
});
YUZTRA_JS
, 'after');
?>
        <?php
        $this->logger->log('info', 'Enable auto translation field rendered successfully');
        $this->logger->log('success', 'Enable auto translation field rendered successfully');
    }

    /**
     * Renders the allowed roles field for granting yuz_translate capability.
     *
     * @param array $settings Translation site settings (optional).
     */
    public function render_allowed_roles_field(array $settings = []): void {
        $current = (array) get_option('yuz_tra_allowed_roles', ['administrator']);
        if ( ! function_exists('get_editable_roles') ) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
        }
        $roles = get_editable_roles();
        ?>
        <tr>
            <th scope="row"><?php esc_html_e('Allowed roles for translation', 'yuz-tra'); ?></th>
            <td>
                <select
                    name="yuz_tra_ts_settings[allowed_roles][]"
                    id="yuz_tra_allowed_roles"
                    style="min-width: 260px; border: 1px solid #ddd;"
                    multiple="multiple"
                    size="6"
                    data-yuz-ts-action="update_settings"
                    data-yuz-ts-on="change"
                >
                    <?php foreach ($roles as $slug => $def) :
                        $label = $def['name'] ?? $slug; ?>
                        <option value="<?php echo esc_attr($slug); ?>" <?php selected(in_array($slug, $current, true), true); ?>>
                            <?php echo esc_html($label); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="description"><?php esc_html_e('Select one or more roles allowed to translate (Ctrl/Cmd + click for multi-selection). Administrators and editors remain translators even if not selected.', 'yuz-tra'); ?></p>
            </td>
        </tr>
        <?php
    }
    /**
     * Renders the translation mode field.
     *
     * @param array $settings API settings (optional).
     */
    public function render_translation_mode_field(array $settings = []): void {
        $this->logger->log('info', 'Rendering translation mode field at ' . current_time('mysql'));
        $this->logger->log('info', 'Rendering translation mode field');
        $options_settings = $settings ?: $this->settings->get_option('yuz_tra_at_settings');
        $mode = (string) ($options_settings['translation_mode'] ?? 'manual');
        $options = [
            'manual' => __('Manual (Editor)', 'yuz-tra'),
            'semi_auto' => __('Semi-Automatic (Page-by-Page)', 'yuz-tra'),
            'silent' => __('Silent (Background)', 'yuz-tra'),
            'all' => __('All Modes', 'yuz-tra')
        ];
        ?>
        <div class="yuz-tra-section">
            <h2 class="yuz-tra-section-title"><?php esc_html_e('Translation Mode', 'yuz-tra'); ?></h2>
            <hr>
            <select id="yuz_tra_translation_mode" name="yuz_tra_at_settings[translation_mode]">
                <?php foreach ($options as $value => $label) : ?>
                    <option value="<?php echo esc_attr($value); ?>" <?php selected($mode, $value); ?>>
                        <?php echo esc_html($label); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <p class="yuz-tra-description"><?php esc_html_e('Choose how translations are performed: manually via an editor, page-by-page, silently in the background, or all modes combined.', 'yuz-tra'); ?></p>
        </div>
        <?php
wp_register_script('yuz-tra-renderer-fields', false, ['jquery'], YUZ_TRA_VERSION, true);
wp_enqueue_script('yuz-tra-renderer-fields');
wp_add_inline_script('yuz-tra-renderer-fields', <<<'YUZTRA_JS'
jQuery(function ($) {
(function($) {
                function toggleCronField() {
                    var mode = $('#yuz_tra_translation_mode').val();
                    if (mode === 'silent' || mode === 'all') {
                        $('.yuz-cron-interval').show();
                    } else {
                        $('.yuz-cron-interval').hide();
                    }
                }
                $('#yuz_tra_translation_mode').on('change', toggleCronField);
                toggleCronField();
            })(jQuery);
});
YUZTRA_JS
, 'after');
?>
        <?php
        $this->logger->log('info', 'Translation mode field rendered successfully');
        $this->logger->log('success', 'Translation mode field rendered successfully');
    }
    /**
     * Renders the cron interval field.
     *
     * @param array $settings API settings (optional).
     */
    public function render_cron_interval_field(array $settings = []): void {
        $this->logger->log('info', 'Rendering cron interval field at ' . current_time('mysql'));
        $this->logger->log('info', 'Rendering cron interval field');
        $options_settings = $settings ?: $this->settings->get_option('yuz_tra_at_settings');
        $interval = (string) ($options_settings['cron_interval'] ?? 'hourly');
        $options = [
            'hourly' => __('Hourly', 'yuz-tra'),
            'twicedaily' => __('Twice Daily', 'yuz-tra'),
            'daily' => __('Daily', 'yuz-tra')
        ];
        ?>
        <div class="yuz-tra-section yuz-cron-interval" style="display: none;">
            <h2 class="yuz-tra-section-title"><?php esc_html_e('Cron Interval', 'yuz-tra'); ?></h2>
            <hr>
            <select id="yuz_tra_cron_interval" name="yuz_tra_at_settings[cron_interval]">
                <?php foreach ($options as $value => $label) : ?>
                    <option value="<?php echo esc_attr($value); ?>" <?php selected($interval, $value); ?>>
                        <?php echo esc_html($label); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <p class="yuz-tra-description"><?php esc_html_e('Select how often background translations should run.', 'yuz-tra'); ?></p>
        </div>
        <?php
        $this->logger->log('info', 'Cron interval field rendered successfully');
        $this->logger->log('success', 'Cron interval field rendered successfully');
    }
    /**
     * Renders the API provider field.
     *
     * @param array $settings API settings (optional).
     */
    public function render_api_provider_field(array $settings = []): void {
        $this->logger->log('info', 'Rendering API provider field at ' . current_time('mysql'));
        $this->logger->log('info', 'Rendering API provider field');
        $options_settings = $settings ?: $this->settings->get_option('yuz_tra_at_settings');
        $provider = (string) ($options_settings['api_provider'] ?? 'libretranslate');
        ?>
        <tr>
            <th scope="row"><label for="api_provider"><?php esc_html_e('Translation Provider', 'yuz-tra'); ?></label></th>
            <td>
                <select id="yuz_tra_api_provider" name="yuz_tra_at_settings[api_provider]" style="border: 1px solid #ddd;">
                    <option value="libretranslate" <?php selected($provider, 'libretranslate'); ?>><?php esc_html_e('LibreTranslate', 'yuz-tra'); ?></option>
                    <option value="google" <?php selected($provider, 'google'); ?>><?php esc_html_e('Google Translate', 'yuz-tra'); ?></option>
                    <option value="deepl" <?php selected($provider, 'deepl'); ?>><?php esc_html_e('DeepL', 'yuz-tra'); ?></option>
                    <option value="custom" <?php selected($provider, 'custom'); ?>><?php esc_html_e('Custom', 'yuz-tra'); ?></option>
                    <option value="ollama" <?php selected($provider, 'ollama'); ?>>Ollama — relecture humaine obligatoire</option>
                    <option value="openai" <?php selected($provider, 'openai'); ?>>OpenAI — coût externe, relecture humaine</option>
                </select>
                <p class="yuz-tra-description"><?php esc_html_e('Select the API provider for automatic translations.', 'yuz-tra'); ?></p>
            </td>
        </tr>
        <?php foreach (['ollama_url'=>'URL de base Ollama (réseau privé)','model'=>'Modèle installé (nom exact)',
            'model_revision'=>'Révision du modèle (invalidation du cache)','provider_timeout'=>'Délai HTTP maximal (secondes)',
            'num_ctx'=>'Contexte maximal (tokens)','num_predict'=>'Sortie maximale (tokens)','num_thread'=>'Threads CPU',
            'daily_token_limit'=>'Limite quotidienne de tokens'] as $key=>$label): ?>
        <tr class="yuz-tra-api-provider-field yuz-ollama" style="<?php echo $provider==='ollama' ? '' : 'display:none;'; ?>">
            <th><label for="yuz_tra_<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></label></th>
            <td><input id="yuz_tra_<?php echo esc_attr($key); ?>" name="yuz_tra_at_settings[<?php echo esc_attr($key); ?>]" value="<?php echo esc_attr($options_settings[$key] ?? ['provider_timeout'=>45,'num_ctx'=>2048,'num_predict'=>512,'num_thread'=>2,'daily_token_limit'=>100000][$key] ?? ''); ?>"></td>
        </tr>
        <?php endforeach; ?>
        <?php foreach (['openai_url'=>'URL OpenAI (endpoint compatible)','openai_model'=>'Modèle OpenAI exact'] as $key=>$label): ?>
        <tr class="yuz-tra-api-provider-field yuz-openai" style="<?php echo $provider==='openai' ? '' : 'display:none;'; ?>"><th><label for="yuz_tra_<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></label></th><td><input id="yuz_tra_<?php echo esc_attr($key); ?>" name="yuz_tra_at_settings[<?php echo esc_attr($key); ?>]" value="<?php echo esc_attr($options_settings[$key] ?? ($key==='openai_url' ? 'https://api.openai.com/v1/chat/completions' : '')); ?>"></td></tr>
        <?php endforeach; ?>
        <tr class="yuz-tra-api-provider-field yuz-openai" style="<?php echo $provider==='openai' ? '' : 'display:none;'; ?>"><th><label for="yuz_tra_openai_key">Clé API OpenAI</label></th><td><input type="password" id="yuz_tra_openai_key" name="yuz_tra_at_settings[openai_key]" value="" autocomplete="new-password" placeholder="Conservée si laissée vide"></td></tr>
        <tr class="yuz-cost-accounting"><th colspan="2"><h3>Comptabilité de rentabilité (USD, estimation administrateur)</h3><p class="description">Ces champs ne lisent pas la facturation OpenAI. Saisissez les tarifs réellement applicables ; aucun appel ne sera lancé par cette saisie.</p></th></tr>
        <?php foreach (['available_balance_usd'=>'Solde fournisseur disponible','minimum_balance_usd'=>'Réserve minimale à protéger','input_cost_usd_per_million'=>'Coût entrant / million de tokens','output_cost_usd_per_million'=>'Coût sortant / million de tokens','sale_price_usd_per_million'=>'Prix de vente / million de tokens','fixed_monthly_cost_usd'=>'Coûts fixes mensuels','pricing_source'=>'Source/version des tarifs'] as $key=>$label): ?>
        <tr class="yuz-cost-accounting"><th><label for="yuz_tra_<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></label></th><td><input id="yuz_tra_<?php echo esc_attr($key); ?>" name="yuz_tra_at_settings[<?php echo esc_attr($key); ?>]" value="<?php echo esc_attr($options_settings[$key] ?? ''); ?>" inputmode="decimal"></td></tr>
        <?php endforeach; ?>
        <?php
wp_register_script('yuz-tra-renderer-fields', false, ['jquery'], YUZ_TRA_VERSION, true);
wp_enqueue_script('yuz-tra-renderer-fields');
wp_add_inline_script('yuz-tra-renderer-fields', <<<'YUZTRA_JS'
jQuery(function ($) {
(function($) {
                function toggleProviderFields() {
                    var provider = $('#yuz_tra_api_provider').val();
                    $('.yuz-tra-api-provider-field').hide();
                    $('.yuz-' + provider).show();
                }
                $('#yuz_tra_api_provider').on('change', toggleProviderFields);
                toggleProviderFields();
            })(jQuery);
});
YUZTRA_JS
, 'after');
?>
        <?php
        $this->logger->log('info', 'API provider field rendered successfully');
        $this->logger->log('success', 'API provider field rendered successfully');
    }
    /**
     * Renders the LibreTranslate fields.
     *
     * @param array $settings API settings (optional).
     */
    public function render_libretranslate_fields(array $settings = []): void {
        $this->logger->log('info', 'Rendering LibreTranslate fields at ' . current_time('mysql'));
        $this->logger->log('info', 'Rendering LibreTranslate fields');
        $options_settings = $settings ?: $this->settings->get_option('yuz_tra_at_settings');
        $libre_url = (string) ($options_settings['libre_url'] ?? '');
        $provider = (string) ($options_settings['api_provider'] ?? 'libretranslate');
        ?>
        <tr class="yuz-tra-api-provider-field yuz-libretranslate" style="<?php echo ($provider === 'libretranslate') ? '' : 'display:none;'; ?>">
            <th scope="row"><label for="libre_url"><?php esc_html_e('LibreTranslate API Endpoint', 'yuz-tra'); ?></label></th>
            <td>
                <input type="text" id="yuz_tra_libre_url" name="yuz_tra_at_settings[libre_url]" value="<?php echo esc_attr($libre_url); ?>" placeholder="<?php esc_attr_e('Enter API endpoint', 'yuz-tra'); ?>" style="width: 100%; max-width: 400px;">
                <p class="yuz-tra-description"><?php esc_html_e('The endpoint for LibreTranslate API.', 'yuz-tra'); ?></p>
            </td>
        </tr>
        <tr class="yuz-tra-api-provider-field yuz-libretranslate" style="<?php echo ($provider === 'libretranslate') ? '' : 'display:none;'; ?>">
            <th scope="row"><label for="libre_key"><?php esc_html_e('LibreTranslate API Key', 'yuz-tra'); ?></label></th>
            <td>
                <input type="password" id="yuz_tra_libre_key" name="yuz_tra_at_settings[libre_key]" value="" autocomplete="new-password" placeholder="<?php esc_attr_e('Preserved when left empty', 'yuz-tra'); ?>" style="width: 100%; max-width: 400px;">
                <p class="yuz-tra-description"><?php esc_html_e('Optional API key for LibreTranslate. The saved value is never displayed.', 'yuz-tra'); ?></p>
            </td>
        </tr>
        <?php
        $this->logger->log('info', 'LibreTranslate fields rendered successfully');
        $this->logger->log('success', 'LibreTranslate fields rendered successfully');
    }
    /**
     * Renders the alternatives field for LibreTranslate.
     *
     * @param array $settings API settings (optional).
     */
    public function render_alternatives_field(array $settings = []): void {
        $this->logger->log('info', 'Rendering alternatives field at ' . current_time('mysql'));
        $this->logger->log('info', 'Rendering alternatives field');
        $options_settings = $settings ?: $this->settings->get_option('yuz_tra_at_settings');
        $alternatives = (string) ($options_settings['alternatives'] ?? '3');
        $provider = (string) ($options_settings['api_provider'] ?? 'libretranslate');
        ?>
        <tr class="yuz-tra-api-provider-field yuz-libretranslate" style="<?php echo ($provider === 'libretranslate') ? '' : 'display:none;'; ?>">
            <th scope="row"><label for="alternatives"><?php esc_html_e('Number of Alternatives (LibreTranslate)', 'yuz-tra'); ?></label></th>
            <td>
                <input type="number" id="yuz_tra_alternatives" name="yuz_tra_at_settings[alternatives]" value="<?php echo esc_attr($alternatives); ?>" min="0" max="5" style="width: 100%; max-width: 100px;">
                <p class="yuz-tra-description"><?php esc_html_e('Set the number of alternative translations to request from LibreTranslate (0 to 5).', 'yuz-tra'); ?></p>
            </td>
        </tr>
        <?php
        $this->logger->log('info', 'Alternatives field rendered successfully');
        $this->logger->log('success', 'Alternatives field rendered successfully');
    }
    /**
     * Renders the Google Translate fields.
     *
     * @param array $settings API settings (optional).
     */
    public function render_google_fields(array $settings = []): void {
        $this->logger->log('info', 'Rendering Google fields at ' . current_time('mysql'));
        $this->logger->log('info', 'Rendering Google fields');
        $options_settings = $settings ?: $this->settings->get_option('yuz_tra_at_settings');
        $google_proj = (string) ($options_settings['google_project'] ?? '');
        $provider = (string) ($options_settings['api_provider'] ?? 'libretranslate');
        ?>
        <tr class="yuz-tra-api-provider-field yuz-google" style="<?php echo ($provider === 'google') ? '' : 'display:none;'; ?>">
            <th scope="row"><label for="google_key"><?php esc_html_e('Google API Key', 'yuz-tra'); ?></label></th>
            <td>
                <input type="password" id="yuz_tra_google_key" name="yuz_tra_at_settings[google_key]" value="" autocomplete="new-password" placeholder="<?php esc_attr_e('Preserved when left empty', 'yuz-tra'); ?>" style="width: 100%; max-width: 400px;">
                <p class="yuz-tra-description"><?php esc_html_e('API key for Google Translate. The saved value is never displayed.', 'yuz-tra'); ?></p>
            </td>
        </tr>
        <tr class="yuz-tra-api-provider-field yuz-google" style="<?php echo ($provider === 'google') ? '' : 'display:none;'; ?>">
            <th scope="row"><label for="google_project"><?php esc_html_e('Google Project ID', 'yuz-tra'); ?></label></th>
            <td>
                <input type="text" id="yuz_tra_google_project" name="yuz_tra_at_settings[google_project]" value="<?php echo esc_attr($google_proj); ?>" placeholder="<?php esc_attr_e('Enter project ID (optional)', 'yuz-tra'); ?>" style="width: 100%; max-width: 400px;">
                <p class="yuz-tra-description"><?php esc_html_e('Optional project ID for Google Translate.', 'yuz-tra'); ?></p>
            </td>
        </tr>
        <?php
        $this->logger->log('info', 'Google fields rendered successfully');
        $this->logger->log('success', 'Google fields rendered successfully');
    }
    /**
     * Renders the DeepL fields.
     *
     * @param array $settings API settings (optional).
     */
    public function render_deepl_fields(array $settings = []): void {
        $this->logger->log('info', 'Rendering DeepL fields at ' . current_time('mysql'));
        $this->logger->log('info', 'Rendering DeepL fields');
        $options_settings = $settings ?: $this->settings->get_option('yuz_tra_at_settings');
        $deepl_free = (string) ($options_settings['deepl_free'] ?? '0');
        $provider = (string) ($options_settings['api_provider'] ?? 'libretranslate');
        ?>
        <tr class="yuz-tra-api-provider-field yuz-deepl" style="<?php echo ($provider === 'deepl') ? '' : 'display:none;'; ?>">
            <th scope="row"><label for="deepl_key"><?php esc_html_e('DeepL API Key', 'yuz-tra'); ?></label></th>
            <td>
                <input type="password" id="yuz_tra_deepl_key" name="yuz_tra_at_settings[deepl_key]" value="" autocomplete="new-password" placeholder="<?php esc_attr_e('Preserved when left empty', 'yuz-tra'); ?>" style="width: 100%; max-width: 400px;">
                <p class="yuz-tra-description"><?php esc_html_e('API key for DeepL. The saved value is never displayed.', 'yuz-tra'); ?></p>
            </td>
        </tr>
        <tr class="yuz-tra-api-provider-field yuz-deepl" style="<?php echo ($provider === 'deepl') ? '' : 'display:none;'; ?>">
            <th scope="row"><label for="deepl_free"><?php esc_html_e('Use DeepL Free Tier', 'yuz-tra'); ?></label></th>
            <td>
                <label class="switch">
                    <input type="checkbox" id="yuz_tra_deepl_free" name="yuz_tra_at_settings[deepl_free]" value="1" <?php checked($deepl_free, '1'); ?>>
                    <span class="slider round"></span>
                </label>
                <label for="yuz_tra_deepl_free"><?php esc_html_e('Enable DeepL’s free tier.', 'yuz-tra'); ?></label>
                <p class="yuz-tra-description"><?php esc_html_e('Enable DeepL’s free tier.', 'yuz-tra'); ?></p>
            </td>
        </tr>
        <?php
        $this->logger->log('info', 'DeepL fields rendered successfully');
        $this->logger->log('success', 'DeepL fields rendered successfully');
    }
    /**
     * Renders the custom API fields.
     *
     * @param array $settings API settings (optional).
     */
    public function render_custom_fields(array $settings = []): void {
        $this->logger->log('info', 'Rendering custom fields at ' . current_time('mysql'));
        $this->logger->log('info', 'Rendering custom fields');
        $options_settings = $settings ?: $this->settings->get_option('yuz_tra_at_settings');
        $custom_url = (string) ($options_settings['custom_url'] ?? '');
        $custom_auth = (string) ($options_settings['custom_auth'] ?? 'none');
        $custom_method = (string) ($options_settings['custom_method'] ?? 'POST');
        $custom_fmt = (string) ($options_settings['custom_format'] ?? 'JSON');
        $provider = (string) ($options_settings['api_provider'] ?? 'libretranslate');
        ?>
        <tr class="yuz-tra-api-provider-field yuz-custom" style="<?php echo ($provider === 'custom') ? '' : 'display:none;'; ?>">
            <th scope="row"><label for="custom_url"><?php esc_html_e('Custom API Endpoint', 'yuz-tra'); ?></label></th>
            <td>
                <input type="text" id="yuz_tra_custom_url" name="yuz_tra_at_settings[custom_url]" value="<?php echo esc_attr($custom_url); ?>" placeholder="<?php esc_attr_e('Enter custom API endpoint', 'yuz-tra'); ?>" style="width: 100%; max-width: 400px;">
                <p class="yuz-tra-description"><?php esc_html_e('The endpoint for the custom API.', 'yuz-tra'); ?></p>
            </td>
        </tr>
        <tr class="yuz-tra-api-provider-field yuz-custom" style="<?php echo ($provider === 'custom') ? '' : 'display:none;'; ?>">
            <th scope="row"><label for="custom_key"><?php esc_html_e('Custom API Key', 'yuz-tra'); ?></label></th>
            <td>
                <input type="password" id="yuz_tra_custom_key" name="yuz_tra_at_settings[custom_key]" value="" autocomplete="new-password" placeholder="<?php esc_attr_e('Preserved when left empty', 'yuz-tra'); ?>" style="width: 100%; max-width: 400px;">
                <p class="yuz-tra-description"><?php esc_html_e('API key for the custom API. The saved value is never displayed.', 'yuz-tra'); ?></p>
            </td>
        </tr>
        <tr class="yuz-tra-api-provider-field yuz-custom" style="<?php echo ($provider === 'custom') ? '' : 'display:none;'; ?>">
            <th scope="row"><label for="custom_auth"><?php esc_html_e('Authentication Method', 'yuz-tra'); ?></label></th>
            <td>
                <select id="yuz_tra_custom_auth" name="yuz_tra_at_settings[custom_auth]">
                    <option value="none" <?php selected($custom_auth, 'none'); ?>><?php esc_html_e('None', 'yuz-tra'); ?></option>
                    <option value="bearer" <?php selected($custom_auth, 'bearer'); ?>><?php esc_html_e('Bearer', 'yuz-tra'); ?></option>
                    <option value="basic" <?php selected($custom_auth, 'basic'); ?>><?php esc_html_e('Basic Auth', 'yuz-tra'); ?></option>
                </select>
                <p class="yuz-tra-description"><?php esc_html_e('Select the authentication method for the custom API.', 'yuz-tra'); ?></p>
            </td>
        </tr>
        <tr class="yuz-tra-api-provider-field yuz-custom" style="<?php echo ($provider === 'custom') ? '' : 'display:none;'; ?>">
            <th scope="row"><label for="custom_method"><?php esc_html_e('HTTP Method', 'yuz-tra'); ?></label></th>
            <td>
                <select id="yuz_tra_custom_method" name="yuz_tra_at_settings[custom_method]">
                    <option value="POST" <?php selected($custom_method, 'POST'); ?>><?php esc_html_e('POST', 'yuz-tra'); ?></option>
                    <option value="GET" <?php selected($custom_method, 'GET'); ?>><?php esc_html_e('GET', 'yuz-tra'); ?></option>
                </select>
                <p class="yuz-tra-description"><?php esc_html_e('Select the HTTP method for the custom API.', 'yuz-tra'); ?></p>
            </td>
        </tr>
        <tr class="yuz-tra-api-provider-field yuz-custom" style="<?php echo ($provider === 'custom') ? '' : 'display:none;'; ?>">
            <th scope="row"><label for="custom_format"><?php esc_html_e('Response Format', 'yuz-tra'); ?></label></th>
            <td>
                <select id="yuz_tra_custom_format" name="yuz_tra_at_settings[custom_format]">
                    <option value="JSON" <?php selected($custom_fmt, 'JSON'); ?>><?php esc_html_e('JSON', 'yuz-tra'); ?></option>
                    <option value="Text" <?php selected($custom_fmt, 'Text'); ?>><?php esc_html_e('Text', 'yuz-tra'); ?></option>
                </select>
                <p class="yuz-tra-description"><?php esc_html_e('Select the response format for the custom API.', 'yuz-tra'); ?></p>
            </td>
        </tr>
        <?php
        $this->logger->log('info', 'Custom fields rendered successfully');
        $this->logger->log('success', 'Custom fields rendered successfully');
    }
    /**
     * Renders the character limit field.
     *
     * @param array $settings API settings (optional).
     */
    public function render_char_limit_field(array $settings = []): void {
        $this->logger->log('info', 'Rendering char limit field at ' . current_time('mysql'));
        $this->logger->log('info', 'Rendering char limit field');
        $options_settings = $settings ?: $this->settings->get_option('yuz_tra_at_settings');
        $char_limit = (string) ($options_settings['char_limit'] ?? '50000');
        ?>
        <tr>
            <th scope="row"><label for="yuz_tra_char_limit"><?php esc_html_e('Character Limit per Day', 'yuz-tra'); ?></label></th>
            <td>
                <input type="number" id="yuz_tra_char_limit" name="yuz_tra_at_settings[char_limit]" value="<?php echo esc_attr($char_limit); ?>" min="1" style="width: 100%; max-width: 100px;">
                <p class="yuz-tra-description"><?php esc_html_e('Maximum number of characters translated per day.', 'yuz-tra'); ?></p>
            </td>
        </tr>
        <?php
        $this->logger->log('info', 'Char limit field rendered successfully');
        $this->logger->log('success', 'Char limit field rendered successfully');
    }
    /**
     * Renders the requests limit field.
     *
     * @param array $settings API settings (optional).
     */
    public function render_requests_limit_field(array $settings = []): void {
        $this->logger->log('info', 'Rendering requests limit field at ' . current_time('mysql'));
        $this->logger->log('info', 'Rendering requests limit field');
        $options_settings = $settings ?: $this->settings->get_option('yuz_tra_at_settings');
        $req_limit = (string) ($options_settings['requests_limit'] ?? '100');
        ?>
        <tr>
            <th scope="row"><label for="yuz_tra_requests_limit"><?php esc_html_e('Requests Limit per Minute', 'yuz-tra'); ?></label></th>
            <td>
                <input type="number" id="yuz_tra_requests_limit" name="yuz_tra_at_settings[requests_limit]" value="<?php echo esc_attr($req_limit); ?>" min="1" style="width: 100%; max-width: 100px;">
                <p class="yuz-tra-description"><?php esc_html_e('Maximum number of translation requests per minute.', 'yuz-tra'); ?></p>
            </td>
        </tr>
        <?php
        $this->logger->log('info', 'Requests limit field rendered successfully');
        $this->logger->log('success', 'Requests limit field rendered successfully');
    }
    /**
     * Renders the block crawlers field.
     *
     * @param array $settings API settings (optional).
     */
    public function render_block_crawlers_field(array $settings = []): void {
        $this->logger->log('info', 'Rendering block crawlers field at ' . current_time('mysql'));
        $this->logger->log('info', 'Rendering block crawlers field');
        $options_settings = $settings ?: $this->settings->get_option('yuz_tra_at_settings');
        $block_crawlers = (string) ($options_settings['block_crawlers'] ?? '0');
        ?>
        <tr>
            <th scope="row"><label for="yuz_tra_block_crawlers"><?php esc_html_e('Block Crawlers', 'yuz-tra'); ?></label></th>
            <td>
                <label class="switch">
                    <input type="checkbox" id="yuz_tra_block_crawlers" name="yuz_tra_at_settings[block_crawlers]" value="1" <?php checked($block_crawlers, '1'); ?>>
                    <span class="slider round"></span>
                </label>
                <label for="yuz_tra_block_crawlers"><?php esc_html_e('Prevent crawlers from triggering translations.', 'yuz-tra'); ?></label>
                <p class="yuz-tra-description"><?php esc_html_e('Prevent crawlers from triggering translations.', 'yuz-tra'); ?></p>
            </td>
        </tr>
        <?php
        $this->logger->log('info', 'Block crawlers field rendered successfully');
        $this->logger->log('success', 'Block crawlers field rendered successfully');
    }
    /**
     * Renders the log queries field.
     *
     * @param array $settings API settings (optional).
     */
    public function render_log_queries_field(array $settings = []): void {
        $this->logger->log('info', 'Rendering log queries field at ' . current_time('mysql'));
        $this->logger->log('info', 'Rendering log queries field');
        $options_settings = $settings ?: $this->settings->get_option('yuz_tra_at_settings');
        $log_queries = (string) ($options_settings['log_queries'] ?? '0');
        ?>
        <tr>
            <th scope="row"><label for="yuz_tra_log_queries"><?php esc_html_e('Log Translation Queries', 'yuz-tra'); ?></label></th>
            <td>
                <label class="switch">
                    <input type="checkbox" id="yuz_tra_log_queries" name="yuz_tra_at_settings[log_queries]" value="1" <?php checked($log_queries, '1'); ?>>
                    <span class="slider round"></span>
                </label>
                <label for="yuz_tra_log_queries"><?php esc_html_e('Log all translation API queries for debugging.', 'yuz-tra'); ?></label>
                <p class="yuz-tra-description"><?php esc_html_e('Log all translation API queries for debugging.', 'yuz-tra'); ?></p>
            </td>
        </tr>
        <?php
        $this->logger->log('info', 'Log queries field rendered successfully');
        $this->logger->log('success', 'Log queries field rendered successfully');
    }
    /**
     * Renders the test API connection button.
     *
     * @param array $settings API settings (optional).
     */
    public function render_test_api_connection(array $settings = []): void {
        $this->logger->log('info', 'Rendering test API connection at ' . current_time('mysql'));
        $this->logger->log('info', 'Rendering test API connection');
        ?>
        <div class="yuz-tra-section">
            <h2 class="yuz-tra-section-title"><?php esc_html_e('Test API Connection', 'yuz-tra'); ?></h2>
            <hr>
            <button type="button" id="yuz_tra_test_btn" class="yuz-tra-button"><?php esc_html_e('Test Connection', 'yuz-tra'); ?></button>
            <span id="yuz_tra_test_status"></span>
            <p class="yuz-tra-description"><?php esc_html_e('Test the current API configuration.', 'yuz-tra'); ?></p>
        </div>
        <?php
        $this->logger->log('info', 'Test API connection rendered successfully');
        $this->logger->log('success', 'Test API connection rendered successfully');
    }
    /**
     * Renders the monitoring dashboard.
     *
     * @param array $settings API settings (optional).
     */
    public function render_monitoring_dashboard(array $settings = []): void {
        if (!YUZ_DB::ensure_string_tables()) {
            echo '<p>Le stockage du suivi est indisponible.</p>';
            return;
        }
        $usage=YUZ_Translation_Budget::usage();
        $finance=YUZ_Translation_Budget::financial_snapshot();
        echo '<section class="yuz-tra-section"><h2>Consommation réelle (UTC)</h2>';
        echo '<p>'.esc_html(sprintf('%d / %d caractères aujourd’hui ; %d / %d requêtes cette minute.',
            $usage['characters'],$usage['char_limit'],$usage['minute_requests'],$usage['requests_limit'])).'</p>';
        echo '<p>'.esc_html(sprintf('%d tentatives ; %d réussies ; %d échouées ; %d réponses en cache.',
            $usage['attempts'],$usage['successes'],$usage['failures'],$usage['cache_hits'])).'</p>';
        echo '<h3>Rentabilité estimée — '.esc_html($finance['currency']).'</h3>';
        echo '<p>'.esc_html(sprintf('Coût variable : %.6f · valeur facturable estimée : %.6f · marge estimée : %.6f.', $finance['variable_cost_usd'], $finance['estimated_revenue_usd'], $finance['estimated_margin_usd'])).'</p>';
        echo '<p>'.esc_html($finance['is_profitable'] ? 'Seuil de rentabilité atteint selon les hypothèses saisies.' : 'Seuil de rentabilité non atteint selon les hypothèses saisies.').'</p>';
        echo '<p class="description">Source des prix : '.esc_html($finance['prices_source']).'. Cette vue est un registre interne estimatif, pas une facture OpenAI.</p>';
        $last=get_option('yuz_tra_worker_last',[]);
        if ($last) echo '<pre>'.esc_html(wp_json_encode($last,JSON_PRETTY_PRINT)).'</pre>';
        echo '</section>';
    }

    public function render_advanced_tab(array $settings = []): void {
        $this->render_advanced_tab_content($settings);
    }
    /**
     * Renders the addons tab content.
     *
     * @param array $settings Addons settings (optional).
     */
    public function render_addons_tab_content(array $settings = []): void {
        $this->logger->log('info', 'Rendering addons tab content at ' . current_time('mysql'));
        $this->logger->log('info', 'Rendering addons tab content');
        ?>
        <div class="yuz-tra-section">
            <h2 class="yuz-tra-section-title"><?php esc_html_e('Manage Addons', 'yuz-tra'); ?></h2>
            <hr>
            <p><?php esc_html_e('No addons available at this time.', 'yuz-tra'); ?></p>
        </div>
        <?php
        $this->logger->log('info', 'Addons tab content rendered successfully');
        $this->logger->log('success', 'Addons tab content rendered successfully');
    }
    /**
     * Renders the addons tab.
     *
     * @param array $settings Addons settings (optional).
     */
    public function render_addons_tab(array $settings = []): void {
        $this->render_addons_tab_content($settings);
    }




    /**
     * Renders the licences tab content.
     *
     * @param array $settings Licences settings (optional).
     */
    /* ============================================================
    * LICENSES: cartes modulaires (1 méthode par encart) + wrapper
    * ============================================================
    */

/** 1) Compte client / Pro */
public function render_license_account_card(array $args = []): void {
    $args = wp_parse_args($args, [
        'title'         => __('YUZ-TRA — GPLv2 or later', 'yuz-tra'),
        'account_url'   => 'https://github.com/Youzurz/yuz-tra-wp',
        'account_label' => __('Source code and downloads', 'yuz-tra'),
        'desc'          => __('This distribution does not require a paid activation key. External translation services may charge separately.', 'yuz-tra'),
    ]);

    if (isset($this->logger)) {
        $this->logger->log('info', 'Render license account card', $args);
    }

    echo '<div class="card" style="padding:16px;margin:0 0 16px;background:#fff;border:1px solid #e5e5e5;">';
    echo '<h2>'.esc_html($args['title']).'</h2>';
    echo '<p><a class="button" target="_blank" rel="noopener" href="'.esc_url($args['account_url']).'">'.esc_html($args['account_label']).'</a></p>';
    echo '<p>'.esc_html($args['desc']).'</p>';
    echo '</div>';
}

/** 2) Encadré AI (bénéfices) */
public function render_license_ai_card(array $args = []): void {
    $args = wp_parse_args($args, [
        'title'   => __('Automatic translation and human review', 'yuz-tra'),
        'bullets' => [
            __('Translate selected strings using your configured provider.', 'yuz-tra'),
            __('Review meaning, context and placeholders before publication.', 'yuz-tra'),
            __('Reuse explicitly approved translations and glossary terms.', 'yuz-tra'),
            __('Provider limits protect resource usage; they are not a paid feature unlock.', 'yuz-tra'),
        ],
    ]);

    if (isset($this->logger)) {
        $this->logger->log('info', 'Render license AI card', ['count' => count((array)$args['bullets'])]);
    }

    echo '<div class="card" style="padding:16px;margin:0 0 16px;background:#fff;border:1px solid #e5e5e5;">';
    echo '<h2>'.esc_html($args['title']).'</h2>';
    echo '<ul class="ul-disc" style="list-style:disc;padding-left:20px;">';
    foreach ((array) $args['bullets'] as $b) {
        echo '<li>'.esc_html($b).'</li>';
    }
    echo '</ul>';
    echo '</div>';
}

/** 3) CTA Plans */
public function render_license_plans_card(array $args = []): void {
    $args = wp_parse_args($args, [
        'title'       => __('Costs and support', 'yuz-tra'),
        'pricing_url' => 'https://github.com/Youzurz/yuz-tra-wp/blob/main/PRICING.md',
        'cta_label'   => __('Read the cost policy', 'yuz-tra'),
    ]);

    if (isset($this->logger)) {
        $this->logger->log('info', 'Render license plans card', $args);
    }

    $plans = YUZ_Product_Catalog::current_offers();
    echo '<div class="card" style="padding:16px;margin:0 0 16px;background:#fff;border:1px solid #e5e5e5;">';
    echo '<h2>'.esc_html($args['title']).'</h2>';
    echo '<p>Choisissez une offre, consultez ses limites, puis ouvrez le paiement sécurisé. Le plugin reste utilisable sans abonnement.</p>';
    echo '<p style="color:#50575e;font-size:13px;">Espace réservé aux services YUZ-TRA : aucune promotion ne s’affiche sur votre site public.</p>';
    echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:12px;margin:16px 0;">';
    foreach ($plans as $plan) {
        echo '<section style="border:1px solid #dcdcde;border-radius:8px;padding:14px;">';
        echo '<h3 style="margin-top:0;">'.esc_html($plan['name']).'</h3>';
        echo '<strong>'.esc_html($plan['price']).'</strong>';
        echo '<p>'.esc_html($plan['text']).'</p>';
        $plan_url = add_query_arg(['product' => 'yuz-tra', 'plan' => sanitize_key($plan['slug'] ?? $plan['name'])], 'https://youzurz.com/fr/yuz-tra');
        echo '<a class="button button-primary" target="_blank" rel="noopener" href="'.esc_url($plan_url . '#plans'). '">Voir et souscrire</a>';
        echo '</section>';
    }
    echo '</div>';
    echo '<p><a class="button" target="_blank" rel="noopener" href="'.esc_url($args['pricing_url']).'">'.esc_html($args['cta_label']).'</a> ';
    echo '<a class="button" target="_blank" rel="noopener" href="'.esc_url('https://youzurz.com/fr/support'). '">Support et accompagnement</a></p>';
    echo '</div>';
}

/** Central YOUZURZ catalog, kept quiet and visible only in the admin service area. */
public function render_product_catalog_card(): void {
    echo '<div class="card" style="padding:16px;margin:0 0 16px;background:#fff;border:1px solid #e5e5e5;">';
    echo '<h2>Catalogue des services YOUZURZ</h2>';
    echo '<p>Retrouvez les produits installés et les services compatibles depuis un seul espace. Rien n’est affiché sur votre site public.</p>';
    echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;">';
    foreach (YUZ_Product_Catalog::all() as $product) {
        echo '<section style="border:1px solid #dcdcde;border-radius:8px;padding:14px;">';
        echo '<h3 style="margin-top:0;">'.esc_html($product['name']).'</h3>';
        echo '<p>'.esc_html($product['summary']).'</p>';
        echo '<p><span style="font-weight:600;">'.($product['installed'] ? 'Installé' : 'Découvrir').'</span></p>';
        echo '<a class="button" target="_blank" rel="noopener" href="'.esc_url($product['url']).'">Fiche produit</a>';
        echo '</section>';
    }
    echo '</div></div>';
}

/**
 * Wrapper: assemble les cartes (ordre/présence modulables via filtres)
 *  - 'yuz/licenses/sections' → ex: ['account','plans']
 *  - 'yuz/licenses/card_args' → ex: ['plans' => ['title' => 'Pro Plans']]
 */
public function render_licenses_content(array $context = []): void {
    $sections   = apply_filters('yuz/licenses/sections', ['account','ai','plans'], $context);
    $cards_args = (array) apply_filters('yuz/licenses/card_args', [], $context);

    if (isset($this->logger)) {
        $this->logger->log('info', 'Render licenses content (wrapper)', ['sections' => $sections]);
    }

    echo '<div class="yuz-license-wrap" style="max-width:980px;">';
    $this->render_product_catalog_card();
    foreach ($sections as $key) {
        switch ($key) {
            case 'account':
                $this->render_license_account_card($cards_args['account'] ?? []);
                break;
            case 'ai':
                $this->render_license_ai_card($cards_args['ai'] ?? []);
                break;
            case 'plans':
                $this->render_license_plans_card($cards_args['plans'] ?? []);
                break;
        }
    }
    echo '</div>';
}

/* ============================================================
 * SUPPORT TOOLBAR (bandeau) — modular & reusable
 * ============================================================
 */

/** Bouton Support */
public function render_support_link(array $args = []): void {
    $args = wp_parse_args($args, [
        'label' => __('Support', 'yuz-tra'),
        'url'   => 'https://github.com/Youzurz/yuz-tra-wp/issues/new/choose',
        'class' => 'button button-secondary yuz-admin-toolbar__button yuz-admin-toolbar__button--secondary',
    ]);
    echo '<a class="'.esc_attr($args['class']).'" target="_blank" rel="noopener" href="'.esc_url($args['url']).'">'.esc_html($args['label']).'</a>';
}

/** Bouton Documentation */
public function render_docs_link(array $args = []): void {
    $args = wp_parse_args($args, [
        'label' => __('Documentation', 'yuz-tra'),
        'url'   => 'https://github.com/Youzurz/yuz-tra-wp#readme',
        'class' => 'button button-secondary yuz-admin-toolbar__button yuz-admin-toolbar__button--secondary',
    ]);
    echo '<a class="'.esc_attr($args['class']).'" target="_blank" rel="noopener" href="'.esc_url($args['url']).'">'.esc_html($args['label']).'</a>';
}

/** Bouton Upgrade */
public function render_upgrade_link(array $args = []): void {
    $args = wp_parse_args($args, [
        'label' => __('Versions', 'yuz-tra'),
        'url'   => 'https://github.com/Youzurz/yuz-tra-wp/blob/main/CHANGELOG.md',
        'class' => 'button button-primary yuz-admin-toolbar__button yuz-admin-toolbar__button--primary',
    ]);
    echo '<a class="'.esc_attr($args['class']).'" target="_blank" rel="noopener" href="'.esc_url($args['url']).'">'.esc_html($args['label']).'</a>';
}

/**
 * Wrapper bandeau (ordre/présence modulables via filtres)
 *  - 'yuz/support/toolbar_sections' → ex: ['support','upgrade'] pour masquer Docs
 *  - 'yuz/support/toolbar_args'     → ex: ['upgrade' => ['label' => 'Go Pro']]
 */
public function render_support_toolbar(array $context = []): void {
    $sections = apply_filters('yuz/support/toolbar_sections', ['support','docs','upgrade'], $context);
    $args     = (array) apply_filters('yuz/support/toolbar_args', [], $context);

    if (isset($this->logger)) {
        $this->logger->log('info', 'Render support toolbar', ['sections' => $sections, 'context' => $context]);
    }

    echo '<div class="yuz-admin-toolbar" data-yuz-toolbar>';
    foreach ($sections as $key) {
        switch ($key) {
            case 'support':
                $this->render_support_link($args['support'] ?? []);
                break;
            case 'docs':
                $this->render_docs_link($args['docs'] ?? []);
                break;
            case 'upgrade':
                $this->render_upgrade_link($args['upgrade'] ?? []);
                break;
        }
    }
    echo '</div>';
}



    // --- Helper Methods ---
    /**
     * Renders the shortcode format options.
     *
     * @param string $selected The selected option value.
     */
    private function render_shortcode_format_options($selected) {
        $this->logger->log('info', 'Rendering shortcode format options at ' . current_time('mysql'));
        $this->logger->log('info', 'Rendering shortcode format options');
        $options = [
            'full-names' => __('Full Language Names', 'yuz-tra'),
            'short-names' => __('Short Language Names', 'yuz-tra'),
            'flags-full-names' => __('Flags with Full Language Names', 'yuz-tra'),
            'flags-short-names' => __('Flags with Short Language Names', 'yuz-tra'),
            'only-flags' => __('Only Flags', 'yuz-tra'),
        ];
        foreach ($options as $value => $label) {
            printf(
                '<option value="%s"%s>%s</option>',
                esc_attr($value),
                selected($selected, $value, false),
                esc_html($label)
            );
        }
        $this->logger->log('info', 'Shortcode format options rendered successfully');
        $this->logger->log('success', 'Shortcode format options rendered successfully');
    }
    /**
     * Renders the menu format options.
     *
     * @param string $selected The selected option value.
     * @param bool $menu Whether to include menu-specific options.
     */
    private function render_menu_format_options($selected, $menu = false) {
        $this->logger->log('info', 'Rendering menu format options at ' . current_time('mysql'));
        $this->logger->log('info', 'Rendering menu format options');
        $options = [
            'full-names' => __('Full Language Names', 'yuz-tra'),
            'short-names' => __('Short Language Names', 'yuz-tra'),
            'flags-full-names' => __('Flags with Full Language Names', 'yuz-tra'),
            'flags-short-names' => __('Flags with Short Language Names', 'yuz-tra'),
            'only-flags' => __('Only Flags', 'yuz-tra'),
        ];
        if ($menu) {
            $options['full-names-no-html'] = __('Full Names (No HTML)', 'yuz-tra');
        }
        foreach ($options as $value => $label) {
            printf(
                '<option value="%s"%s>%s</option>',
                esc_attr($value),
                selected($selected, $value, false),
                esc_html($label)
            );
        }
        $this->logger->log('info', 'Menu format options rendered successfully');
        $this->logger->log('success', 'Menu format options rendered successfully');
    }
    /**
     * Renders the floater format options.
     *
     * @param string $selected The selected option value.
     */
    private function render_floater_format_options($selected) {
        $this->logger->log('info', 'Rendering floater format options at ' . current_time('mysql'));
        $this->logger->log('info', 'Rendering floater format options');
        $options = [
            'full-names' => __('Full Language Names', 'yuz-tra'),
            'short-names' => __('Short Language Names', 'yuz-tra'),
            'flags-full-names' => __('Flags with Full Language Names', 'yuz-tra'),
            'flags-short-names' => __('Flags with Short Language Names', 'yuz-tra'),
            'only-flags' => __('Only Flags', 'yuz-tra'),
        ];
        foreach ($options as $value => $label) {
            printf(
                '<option value="%s"%s>%s</option>',
                esc_attr($value),
                selected($selected, $value, false),
                esc_html($label)
            );
        }
        $this->logger->log('info', 'Floater format options rendered successfully');
        $this->logger->log('success', 'Floater format options rendered successfully');
    }
    // Helper for positions (if needed in tabs)
    private function render_positions($selected) {
        $positions = [
            'bottom-left' => __('Bottom Left', 'yuz-tra'),
            'bottom-right' => __('Bottom Right', 'yuz-tra'),
            'top-left' => __('Top Left', 'yuz-tra'),
            'top-right' => __('Top Right', 'yuz-tra'),
        ];
        foreach ($positions as $p => $label) {
            printf(
                '<option value="%1$s"%2$s>%3$s</option>',
                esc_attr($p),
                selected($selected, $p, false),
                esc_html($label)
            );
        }
    }
}

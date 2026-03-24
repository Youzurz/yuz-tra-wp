<?php
/**
 * Class YUZ_Environment
 * Détecte la langue du navigateur et la géolocalisation par IP via AJAX,
 * puis choisit la locale WP la plus proche parmi celles déclarées en base.
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

defined('ABSPATH') or exit;
require_once YUZ_TRA_INCLUDES . 'class-yuz-contracts.php';
require_once YUZ_TRA_INCLUDES . 'class-yuz-fallbacks.php';
use YUZTRA\Interfaces\LanguagesInterface;
use YUZTRA\Interfaces\EnvironmentInterface;
if (!class_exists('YUZ_Environment')) {
class YUZ_Environment implements EnvironmentInterface {
/** @var LanguagesInterface|null */
protected static $lang_manager;
/**
         * Initialise le hook AJAX
         *
         * @param LanguagesInterface|null $lang_manager
         * @return void
         */
public static function init(LanguagesInterface $lang_manager = null): void {
$flags = YUZ_Settings::runtime_flags();
if (!$flags['switcher_enabled']) {
return;
            }
self::$lang_manager = $lang_manager;
add_action('wp_ajax_yuz_detect_user_environment', [__CLASS__, 'detect_user_environment']);
add_action('wp_ajax_nopriv_yuz_detect_user_environment', [__CLASS__, 'detect_user_environment']);
        }
/**
         * Répond via AJAX avec la langue détectée
         *
         * @return void
         */
public static function detect_user_environment(): void {
if (!check_ajax_referer('yuz_tra_nonce', 'nonce', false)) {
wp_send_json_error(['message' => __('Invalid nonce', 'yuz-translation')]);
            }
// Préférences du navigateur
$accept = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '';
$browser_pref = [];
foreach (explode(',', $accept) as $segment) {
if (preg_match('/^([a-zA-Z\-]+)/', $segment, $m)) {
$browser_pref[] = str_replace('-', '_', $m[1]);
                }
            }
// Géolocalisation
$ip = $_SERVER['REMOTE_ADDR'] ?? '';
$country = '';
if (!(defined('YUZ_TRA_WP_ORG_BUILD') && YUZ_TRA_WP_ORG_BUILD) && filter_var($ip, FILTER_VALIDATE_IP)) {
    $geo = apply_filters('yuz_tra_environment_geoip', [], $ip);
    if (is_array($geo)) {
        $country = (string) ($geo['countryCode'] ?? '');
    }
            }
// Liste des langues activées
$all = self::$lang_manager ? self::$lang_manager->get_translatable_languages() : [];
$matches = [];
// Pays exact (XX)
if ($country) {
foreach ($all as $lang) {
$code = is_object($lang) ? $lang->language_code : $lang;
if (substr($code, -2) === $country) {
$matches[] = $code;
                    }
                }
            }
// Navigation
if (empty($matches)) {
foreach ($browser_pref as $pref) {
foreach ($all as $lang) {
$code = is_object($lang) ? $lang->language_code : $lang;
if (strtolower(substr($code, 0, 2)) === strtolower(substr($pref, 0, 2))) {
$matches[] = $code;
                        }
                    }
if ($matches) {
break;
                    }
                }
            }
// Fallback
$detected = !empty($matches)
                ? $matches[0]
                : apply_filters('yuz_tra_default_language', get_locale());
wp_send_json_success([
'language' => $detected,
'browser_lang' => $browser_pref,
'country' => $country,
'ip' => $ip,
            ]);
        }
/**
         * Récupère une valeur d'environnement
         *
         * @param string $key
         * @return mixed
         */
public function getEnv(string $key): mixed {
return null;
        }
/**
         * Vérifie si l'environnement est en production
         *
         * @return bool
         */
public function isProduction(): bool {
return false;
        }
    }
}
// Fallback si l'interface manque
if (!class_exists('NullEnvironment')) {
class NullEnvironment implements EnvironmentInterface {
public static function init(LanguagesInterface $lang_manager = null): void {}
public static function detect_user_environment(): void {
wp_send_json_success([
'language' => get_locale(),
'browser_lang' => [],
'country' => '',
'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
            ]);
        }
public function getEnv(string $key): mixed { return null; }
public function isProduction(): bool { return false; }
    }
}

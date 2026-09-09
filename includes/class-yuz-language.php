<?php
/**
 * Class YUZ_Language
 * Represents a language entity in the YUZ-TRA plugin.
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
use YUZTRA\Interfaces\Language as LanguageInterface;
if (!class_exists('YUZ_Language')) {
class YUZ_Language implements LanguageInterface {
/** @var array Données brutes de la langue */
private $data;
/** @var bool Activer/désactiver les logs */
private static $debug = true;
/** Préfixes colorés pour les logs */
private static $prefixes = [
'critical' => '🟥 [CRITICAL]',
'warning' => '🟨 [WARNING]',
'success' => '🟩 [SUCCESS]',
'info' => '🟦 [INFO]',
        ];
/**
         * @param array $data Données initiales (id, language_name, slug, code, formality, native_name, locale…)
         */
public function __construct(array $data) {
$this->data = $data;
$this->log('info', 'Création de YUZ_Language', $data);
        }
public function getId(): int {
return (int) ($this->data['id'] ?? 0);
        }
public function getName(): string {
return (string) ($this->data['language_name'] ?? '');
        }
public function getSlug(): string {
return (string) ($this->data['slug'] ?? '');
        }
public function getCode(): string {
return (string) ($this->data['language_code'] ?? '');
        }
public function getFormality(): string {
return (string) ($this->data['formality'] ?? 'formal');
        }
/**
         * Gets the language code (alias for getCode).
         *
         * @return string Language code.
         */
public function get_code(): string {
return $this->getCode();
        }
/**
         * Gets the language name (alias for getName).
         *
         * @return string Language name.
         */
public function get_name(): string {
return $this->getName();
        }
/**
         * Gets the native language name.
         *
         * @return ?string Native language name or null.
         */
public function get_native_name(): ?string {
return isset($this->data['native_name']) ? (string) $this->data['native_name'] : null;
        }
/**
         * Gets the language locale.
         *
         * @return string Language locale.
         */
public function getLocale(): string {
return (string) ($this->data['locale'] ?? '');
        }
public function toArray(): array {
return [
'id' => $this->getId(),
'language_name' => $this->getName(),
'slug' => $this->getSlug(),
'language_code' => $this->getCode(),
'formality' => $this->getFormality(),
'native_name' => $this->get_native_name(),
'locale' => $this->getLocale(),
            ];
        }
// Alias "snake_case" attendus par le code existant
public function get_language_code(): string { return $this->getCode(); }
public function get_language_name(): string { return $this->getName(); }
public function to_array(): array { return $this->toArray(); }
// Accesseurs magiques pour compatibilité templates/renderer
public function __get(string $name) {
switch ($name) {
case 'language_code': return $this->getCode();
case 'language_name': return $this->getName();
case 'native_name': return $this->get_native_name();
case 'slug': return $this->getSlug();
case 'locale': return $this->getLocale();
case 'formality': return $this->getFormality();
default: return $this->data[$name] ?? null;
    }
}
public function __set(string $name, $value): void {
// Autoriser l’écriture contrôlée sur les clés connues
$allowed = ['language_code','language_name','native_name','slug','locale','formality','id'];
if (in_array($name, $allowed, true)) {
$this->data[$name] = $value;
return;
    }
// sinon on ignore ou on logge si besoin
}
public function __isset(string $name): bool {
if (in_array($name, ['language_code','language_name','native_name','slug','locale','formality'], true)) {
return true; // lisibles via __get
    }
return isset($this->data[$name]);
}
public static function setDebug(bool $flag): void {
self::$debug = $flag;
        }
/**
         * Journalisation simple.
         */
private function log(string $level, string $message, $context = []): void {
if (!self::$debug) {
return;
            }
$prefix = self::$prefixes[$level] ?? self::$prefixes['info'];
yuz_tra_debug_log(sprintf('%s %s: %s', $prefix, $message, print_r($context, true)));
        }
    }
}

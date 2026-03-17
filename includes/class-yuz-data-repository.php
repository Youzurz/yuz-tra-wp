<?php
/**
 * Class YUZ_Data_Repository
 * Accès & gestion des données (DB abstraction).
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
require_once YUZ_TRA_INCLUDES . 'class-yuz-services.php';
require_once YUZ_TRA_INCLUDES . 'class-yuz-logger.php';
require_once YUZ_TRA_INCLUDES . 'class-yuz-health-check.php';
require_once YUZ_TRA_INCLUDES . 'class-yuz-db.php';

use YUZTRA\Interfaces\DBInterface;

class YUZ_Data_Repository {
    public static function store_translation($translation_data) {
        if (function_exists('yuz_debug_probe_log')) {
            yuz_debug_probe_log('repository_store_translation_start', [
                'payload' => $translation_data,
            ]);
        }

        $logger = class_exists('YUZ_Logger') ? new \YUZ_Logger() : new \YUZTRA\Fallbacks\NullLogger();
        $health = class_exists('YUZ_Health_Check') ? new \YUZ_Health_Check($logger) : null;
        $db     = class_exists('YUZ_DB') ? new \YUZ_DB($logger, $health) : null;

        if (!$db || !method_exists($db, 'store_translation')) {
            $logger->log('error', 'store_translation unavailable: YUZ_DB missing');
            return false;
        }

        if (method_exists($db, 'ensure_tables')) {
            $db->ensure_tables();
        }

        $languages = (class_exists('YUZ_Services') && method_exists('YUZ_Services', 'languages'))
            ? \YUZ_Services::languages()
            : new \YUZTRA\Fallbacks\NullLanguages();

        $target_code = sanitize_text_field($translation_data['target_lang'] ?? $translation_data['language_code'] ?? '');
        if ($target_code === '') {
            $logger->log('warning', 'store_translation aborted: missing target code');
            if (function_exists('yuz_debug_probe_log')) {
                yuz_debug_probe_log('repository_store_translation_abort', ['reason' => 'missing_target_code']);
            }
            return false;
        }

        $target_lang = $languages->get_by_code($target_code);
        if (!$target_lang || !method_exists($target_lang, 'getId')) {
            $logger->log('warning', 'store_translation aborted: unknown target language', ['code' => $target_code]);
            if (function_exists('yuz_debug_probe_log')) {
                yuz_debug_probe_log('repository_store_translation_abort', ['reason' => 'unknown_target_language', 'code' => $target_code]);
            }
            return false;
        }

        $source_code = method_exists($languages, 'get_effective_source_code')
            ? $languages->get_effective_source_code()
            : $languages->get_source_language();
        $source_lang = $source_code ? $languages->get_by_code($source_code) : null;

        $origin = isset($translation_data['origin']) ? sanitize_key((string) $translation_data['origin']) : 'manual';
        if ($origin === 'auto') {
            $origin = 'machine';
        }
        $allowed_origins = ['manual','machine','dock','gettext','dom'];
        if (!in_array($origin, $allowed_origins, true)) {
            $origin = 'manual';
        }
        $is_batch = false;
        if (isset($translation_data['is_batch'])) {
            $batch_flag = $translation_data['is_batch'];
            if (is_bool($batch_flag)) {
                $is_batch = $batch_flag;
            } elseif (is_numeric($batch_flag)) {
                $is_batch = (int) $batch_flag === 1;
            } else {
                $normalized = strtolower(trim((string) $batch_flag));
                if ($normalized !== '') {
                    $is_batch = in_array($normalized, ['1','true','yes','on','batch'], true);
                }
            }
        }

        $mode = isset($translation_data['mode']) ? sanitize_key((string) $translation_data['mode']) : '';
        if ($mode === 'semi') {
            $mode = 'semi_auto';
        }

        if (function_exists('yuz_tra_status_sanitize')) {
            if ($mode === 'manual') {
                $default_status = YUZ_TRA_STATUS_DRAFT;
            } elseif ($mode === 'semi_auto') {
                $default_status = YUZ_TRA_STATUS_IN_REVIEW;
            } elseif ($mode === 'auto') {
                $default_status = YUZ_TRA_STATUS_PUBLISHED;
            } else {
                $needs_review = in_array($origin, ['machine', 'dom'], true) || $is_batch;
                $default_status = $needs_review ? YUZ_TRA_STATUS_IN_REVIEW : YUZ_TRA_STATUS_PUBLISHED;
            }
            $status = yuz_tra_status_sanitize($translation_data['status'] ?? null, $default_status);
        } else {
            if ($mode === 'manual') {
                $default_status = 1;
            } elseif ($mode === 'semi_auto') {
                $default_status = 2;
            } elseif ($mode === 'auto') {
                $default_status = 4;
            } else {
                $default_status = ($origin === 'machine' || $origin === 'dom' || $is_batch) ? 2 : 1;
            }
            $status = isset($translation_data['status']) ? (int) $translation_data['status'] : $default_status;
            if ($status < 1) {
                $status = 1;
            } elseif ($status > 5) {
                $status = 5;
            }
        }
        if (function_exists('yuz_tra_status_for_origin')) {
            $status = yuz_tra_status_for_origin($origin, $status, $is_batch, $mode);
        } elseif ($mode === 'auto') {
            $status = defined('YUZ_TRA_STATUS_PUBLISHED') ? YUZ_TRA_STATUS_PUBLISHED : 4;
        } elseif ($origin === 'machine' || $origin === 'dom' || $is_batch || $mode === 'semi_auto') {
            $status = defined('YUZ_TRA_STATUS_REVIEW') ? YUZ_TRA_STATUS_REVIEW : 2;
        }

        $payload = [
            'post_id'         => isset($translation_data['post_id']) ? (int) $translation_data['post_id'] : 0,
            'context'         => isset($translation_data['context']) ? sanitize_text_field($translation_data['context']) : 'content',
            'block_id'        => isset($translation_data['block_id']) ? sanitize_text_field($translation_data['block_id']) : '',
            'original_text'   => isset($translation_data['original_text']) ? (string) $translation_data['original_text'] : '',
            'translated_text' => isset($translation_data['translated_text']) ? (string) $translation_data['translated_text'] : '',
            'translated_slug' => $translation_data['translated_slug'] ?? null,
            'source_lang_id'  => ($source_lang && method_exists($source_lang, 'getId')) ? (int) $source_lang->getId() : 0,
            'target_lang_id'  => (int) $target_lang->getId(),
            'language_code'   => $target_code,
            'status'          => $status,
            'origin'          => $origin,
            'revision_of'     => isset($translation_data['revision_of']) ? (int) $translation_data['revision_of'] : null,
        ];

        if (!empty($translation_data['page_url'])) {
            $payload['page_url'] = esc_url_raw($translation_data['page_url']);
        }

        if ($payload['original_text'] === '' || $payload['translated_text'] === '') {
            $logger->log('warning', 'store_translation aborted: empty text payload');
            if (function_exists('yuz_debug_probe_log')) {
                yuz_debug_probe_log('repository_store_translation_abort', ['reason' => 'empty_text']);
            }
            return false;
        }

        $result = $db->store_translation($payload);
        if (function_exists('yuz_debug_probe_log')) {
            yuz_debug_probe_log('repository_store_translation_result', [
                'payload' => $payload,
                'result'  => $result,
            ]);
        }
        if ($result) {
            do_action('yuz_translation_stored', $translation_data);
        }
        return $result;
    }

    public function set_option( $name, $value ) {
    $ok = update_option($name, $value);
    YUZ_Health_Check::ensure(
        $ok,
        "update_option('$name') a retourné false",
        __METHOD__
    );
    return $ok;
}
}

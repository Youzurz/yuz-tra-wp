<?php
/**
 * Class YUZ_Services
 * Façade QoS  Orchestrateur de traduction (routing, découpage, queue, worker).
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

defined('ABSPATH') || exit;
if (!class_exists('YUZ_Services')):
class YUZ_Services {
/* =========================
         * FAIL-SAFE (hard defaults)
         * ========================= */
// NB: ces valeurs ne sont plus la source de vérité ; elles ne servent
// que de dernier filet de sécurité si Settings/Adapter/Filters ne disent rien.
const MAX_DIRECT_CHARS_DEFAULT = 1200;
const MAX_AJAX_PAYLOAD_DEFAULT = 20000;
const MAX_CHUNK_CHARS_DEFAULT = 900;
const MAX_CHUNKS_PER_RUN_DEFAULT = 10;
const MAX_RETRIES_PER_CHUNK_DEFAULT= 2;
/* =========================
         * SINGLETONS LÉGERS
         * ========================= */
public static function settings(): YUZ_Settings { static $i; return $i ??= new YUZ_Settings(
new \YUZTRA\Fallbacks\NullLanguages(),
new \YUZTRA\Fallbacks\NullAjax(),
new \YUZTRA\Fallbacks\NullTranslationManager(),
new \YUZTRA\Fallbacks\NullLanguageManager(),
new \YUZTRA\Fallbacks\NullLogger()
        ); }
public static function db(): YUZ_DB { static $i; return $i ??= new YUZ_DB(new YUZ_Logger(), new YUZ_Health_Check(new YUZ_Logger())); }
public static function languages(): YUZ_Languages { static $i; return $i ??= new YUZ_Languages(self::settings(), self::db()); }
public static function translations(): YUZ_Query { static $i; return $i ??= new YUZ_Query(self::db()); }
/* =========================
         * LAZY FACTORY TM (Phase 6: factory lazy tm(), remove early, inject LanguageManager)
         * ========================= */
/* =========================
 * LAZY FACTORY TM
 * ========================= */
private static ?YUZ_API_Manager $tm = null;

public static function tm(): YUZ_API_Manager {
    if (self::$tm === null) {
        // S'assurer que la classe TM est chargée avant instanciation
        $tm_file = YUZ_TRA_INCLUDES . 'class-yuz-api-manager.php';
        if (!class_exists('YUZ_API_Manager') && file_exists($tm_file)) {
            require_once $tm_file;
        }

        // Deps partagées (réelles)
        $logger    = new YUZ_Logger();
        $health    = new YUZ_Health_Check($logger);
        $db        = self::db();               // YUZ_DB réel
        $languages = self::languages();        // YUZ_Languages réel (implémente LanguageManagerInterface)
        $settings  = self::settings();         // ✅ Settings réel (SettingsInterface)

        // Adapters (garde tes require_once si besoin)
         $adapterFiles = [
             YUZ_TRA_INCLUDES . 'class-yuz-custom-translate-adapter.php',
             YUZ_TRA_INCLUDES . 'class-yuz-libre-translate-adapter.php',
             YUZ_TRA_INCLUDES . 'class-yuz-deepl-translate-adapter.php',
             YUZ_TRA_INCLUDES . 'class-yuz-google-translate-adapter.php',
         ];
        foreach ($adapterFiles as $f) { if (file_exists($f)) { require_once $f; } }

        $adapters = [
            'custom'         => class_exists('YUZ_Custom_Translate_Adapter')   ? new YUZ_Custom_Translate_Adapter()   : new \YUZTRA\Fallbacks\NullTranslateAdapter(),
            'libretranslate' => class_exists('YUZ_Libre_Translate_Adapter')    ? new YUZ_Libre_Translate_Adapter()    : new \YUZTRA\Fallbacks\NullTranslateAdapter(),
            'deepl'          => class_exists('YUZ_DeepL_Translate_Adapter')    ? new YUZ_DeepL_Translate_Adapter()    : new \YUZTRA\Fallbacks\NullTranslateAdapter(),
            'google'         => class_exists('YUZ_Google_Translate_Adapter')   ? new YUZ_Google_Translate_Adapter()   : new \YUZTRA\Fallbacks\NullTranslateAdapter(),
        ];

        // 1) Translation Manager RÉEL — signature officielle:
        //    (array $adapters, SettingsInterface $settings, LanguagesInterface $languages, AjaxInterface $ajax, DBInterface $db, LoggerInterface $logger)
        $tm = new YUZ_API_Manager(
            $adapters,
            $settings,   // ✅ 2) Settings
            $languages,  // ✅ 3) Languages
            null,        // ✅ 4) Ajax branché après
            $db,         // ✅ 5) DB
            $logger      // ✅ 6) Logger
        );

        // 2) Ajax avec les 3 ARGUMENTS REQUIS (signature confirmée dans class-yuz-ajax.php)
        //    __construct(TranslationManagerInterface, LanguageManagerInterface, DBInterface)
        $ajax = new YUZ_Ajax($tm, $languages, $db);

        // 3) Setter Ajax (camelCase)
        if (method_exists($tm, 'setAjax')) {
            $tm->setAjax($ajax);
        }

        self::$tm = $tm;
        $logger->log('success', 'YUZ_API_Manager lazy instantiated via YUZ_Services::tm()');
    }
    return self::$tm;
}

/* =========================
         * INIT
         * ========================= */
public static function init(): void {
add_action('yuz_tra_run_job', [__CLASS__, 'run_job'], 10, 1);
// Valeurs par défaut exposées aux filtres (permet aux devs d’ajuster sans toucher DB)
add_filter('yuz_tra_qos_max_ajax_payload', fn($v)=> $v ?: self::MAX_AJAX_PAYLOAD_DEFAULT);
add_filter('yuz_tra_qos_max_direct_chars', fn($v)=> $v ?: self::MAX_DIRECT_CHARS_DEFAULT);
add_filter('yuz_tra_qos_max_chunk_chars', fn($v)=> $v ?: self::MAX_CHUNK_CHARS_DEFAULT);
add_filter('yuz_tra_qos_max_chunks_per_run', fn($v)=> $v ?: self::MAX_CHUNKS_PER_RUN_DEFAULT);
add_filter('yuz_tra_qos_max_retries', fn($v)=> $v ?: self::MAX_RETRIES_PER_CHUNK_DEFAULT);
        }
/* =========================
         * CHECK: pré-vol traduction
         * ========================= */
// NEW: rapport de préparation par mode: null|manual|semi|auto
public static function preflight(?string $mode = null): array {
$missing = [];
$warnings = [];
// A) DB & tables minimales
global $wpdb;
$lang_table = $wpdb->prefix . 'yuz_tra_languages';
$has_lang_table = $wpdb->get_var( $wpdb->prepare("SHOW TABLES LIKE %s", $lang_table) );
if (!$has_lang_table) {
$missing[] = 'db_tables';
            }
// B) Langues (General)
try {
$L = self::languages();
$src = $L->get_source_language();
$targets = $L->get_translatable_languages();
$target_codes = array_map(fn($o)=> is_object($o)? $o->language_code : (string)$o, $targets);
if (empty($src)) { $missing[] = 'source_language'; }
if (empty($target_codes)) { $missing[] = 'target_languages'; }
// au moins une cible ≠ source
if ($src && $target_codes && !array_diff($target_codes, [$src])) {
$missing[] = 'distinct_targets';
                }
            } catch (\Throwable $e) {
$missing[] = 'languages_runtime';
            }
// C) Provider/API requis si semi/auto
$api = get_option('yuz_tra_api_settings', []);
$provider = isset($api['api_provider']) ? sanitize_text_field($api['api_provider']) : null;
if ($mode === 'semi' || $mode === 'auto' || $mode === null) {
if (!$provider) {
$missing[] = 'api_provider';
                } else {
// exigences minimales génériques: endpoint  clé si pertinent
$ep = $api[$provider]['endpoint'] ?? $api['endpoint'] ?? '';
$key = $api[$provider]['api_key'] ?? $api['api_key'] ?? '';
if (in_array($provider, ['libretranslate','custom','google','deepl'], true)) {
if (!$ep) { $missing[] = 'api_endpoint'; }
// certains providers peuvent tourner sans clé, mais on la réclame par défaut
if (!$key && $provider !== 'custom') { $warnings[] = 'api_key_missing'; }
                    }
                }
            }
// D) QoS limites (toujours dispo via qos())
$q = self::qos();
if ($q['max_direct_chars'] < 100 || $q['max_chunk_chars'] < 100) {
$warnings[] = 'qos_limits_too_low';
            }
// E) Cron / Action Scheduler pour auto (jobs)
if ($mode === 'auto' || $mode === null) {
$has_as = function_exists('as_enqueue_async_action');
$wp_cron_disabled = defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;
if (!$has_as && $wp_cron_disabled) {
$warnings[] = 'background_disabled';
                }
            }
// F) Mode manuel: OK même sans provider (écrit DB)
if ($mode === 'manual') {
// rien d’autre: la DB  langues suffisent
            }
$ok = empty($missing);

// Logging ajouté: Log si preflight échoue ou a des warnings
$logger = new YUZ_Logger();
if (!$ok || !empty($warnings)) {
  $logger->log('warning', 'Preflight check completed with issues', [
    'mode' => $mode,
    'missing' => $missing,
    'warnings' => $warnings,
    'ok' => $ok
  ]);
} else {
  $logger->log('success', 'Preflight check successful', ['mode' => $mode]);
}

return [
'ok' => $ok,
'missing' => array_values(array_unique($missing)),
'warnings' => array_values(array_unique($warnings)),
'adapter' => $provider ?: $q['adapter'],
'qos' => $q,
            ];
        }
// NEW: bool helper
public static function is_ready(?string $mode = null): bool {
$r = self::preflight($mode);
return $r['ok'] === true;
        }
/* =========================
         * QoS core
         * ========================= */
/**
         * Retourne les limites QoS “effectives” en combinant:
         * defaults → Settings globaux → Settings par adapter → filtres → clamp & types
         * La structure retournée:
         * [
         * 'max_ajax_payload' => int,
         * 'max_direct_chars' => int,
         * 'max_chunk_chars' => int,
         * 'max_chunks_per_run' => int,
         * 'max_retries' => int,
         * 'adapter' => 'libretranslate'|'deepl'|'google'|'custom'
         * ]
         */
public static function qos(): array {
// 1) defaults
$qos = [
'max_ajax_payload' => self::MAX_AJAX_PAYLOAD_DEFAULT,
'max_direct_chars' => self::MAX_DIRECT_CHARS_DEFAULT,
'max_chunk_chars' => self::MAX_CHUNK_CHARS_DEFAULT,
'max_chunks_per_run' => self::MAX_CHUNKS_PER_RUN_DEFAULT,
'max_retries' => self::MAX_RETRIES_PER_CHUNK_DEFAULT,
'adapter' => 'libretranslate',
            ];
// 2) Settings globaux (admin)
// On lit deux options:
// - yuz_tra_api_settings → provider sélectionné (déjà présent dans l’UI)
// - yuz_tra_qos → NOUVELLE option (objet simple de seuils globaux  par adapter)
$api = get_option('yuz_tra_api_settings', []);
if (!empty($api['api_provider'])) {
$qos['adapter'] = sanitize_text_field($api['api_provider']);
            }
$stored = get_option('yuz_tra_qos', []);
if (is_array($stored)) {
foreach (['max_ajax_payload','max_direct_chars','max_chunk_chars','max_chunks_per_run','max_retries'] as $k) {
if (isset($stored[$k]) && is_numeric($stored[$k])) {
$qos[$k] = (int)$stored[$k];
                    }
                }
            }
// 3) Overrides par adapter (dans la même option, namespace “adapters”)
$adapter = $qos['adapter'];
if (!empty($stored['adapters'][$adapter]) && is_array($stored['adapters'][$adapter])) {
foreach (['max_ajax_payload','max_direct_chars','max_chunk_chars','max_chunks_per_run','max_retries'] as $k) {
if (isset($stored['adapters'][$adapter][$k]) && is_numeric($stored['adapters'][$adapter][$k])) {
$qos[$k] = (int)$stored[$k];
                    }
                }
            }
// 4) Filtres (per-mettre aux adapters PHP d’imposer des caps dynamiques)
$qos['max_ajax_payload'] = (int) apply_filters('yuz_tra_qos_max_ajax_payload', $qos['max_ajax_payload'], $adapter, $stored);
$qos['max_direct_chars'] = (int) apply_filters('yuz_tra_qos_max_direct_chars', $qos['max_direct_chars'], $adapter, $stored);
$qos['max_chunk_chars'] = (int) apply_filters('yuz_tra_qos_max_chunk_chars', $qos['max_chunk_chars'], $adapter, $stored);
$qos['max_chunks_per_run'] = (int) apply_filters('yuz_tra_qos_max_chunks_per_run', $qos['max_chunks_per_run'], $adapter, $stored);
$qos['max_retries'] = (int) apply_filters('yuz_tra_qos_max_retries', $qos['max_retries'], $adapter, $stored);
// 5) Clamp & types (sécurité)
$qos['max_ajax_payload'] = max(1000, min(1000000, $qos['max_ajax_payload']));
$qos['max_direct_chars'] = max(100, min(50000, $qos['max_direct_chars']));
$qos['max_chunk_chars'] = max(100, min(5000, $qos['max_chunk_chars']));
$qos['max_chunks_per_run'] = max(1, min(100, $qos['max_chunks_per_run']));
$qos['max_retries'] = max(0, min(10, $qos['max_retries']));

// Logging ajouté: Log les QoS effectives pour debug
$logger = new YUZ_Logger();
$logger->log('debug', 'QoS limits computed', ['qos' => $qos]);

return $qos;
        }
/* =========================
         * ENTRÉES PUBLIQUES
         * ========================= */
/**
         * Entrée unique pour toute demande de traduction (texte court/long).
         * Retourne:
         * - succès direct: ['ok'=>true, 'translated'=>"..."]
         * - job asynchrone: ['ok'=>true, 'queued'=>true, 'job_id'=>"job_xxx"]
         *
         * @param array{ text?:string, source?:string, target?:string, mode?:string, meta?:array } $payload
         */
public static function translate_entrypoint(array $payload): array {
$payload = self::validate_payload($payload);
$text = $payload['text'];
$source = $payload['source'];
$target = $payload['target'];
$mode = $payload['mode'];
$meta = $payload['meta'];
// NEW: gate préflight strict (empêche toute trad sans contexte)
$pf = self::preflight($mode === '' ? null : $mode);
if (!$pf['ok']) {
return [
'ok' => false,
'error' => [
'code' => 'not_ready',
'message' => __('Translation preflight failed','yuz_translation'),
'missing' => $pf['missing'],
'warnings' => $pf['warnings'],
                    ]
                ];
            }
$q = self::qos();
// barrière dure côté AJAX (QoS)
if (($meta['origin'] ?? '') === 'ajax' && mb_strlen($text) > $q['max_ajax_payload']) {
throw new \RuntimeException(sprintf(
'Payload too large for AJAX (%d > %d chars)',
mb_strlen($text), $q['max_ajax_payload']
                ));
            }
// validation langues (déjà majoritairement couverte par preflight)
$L = self::languages();
$src = $source ?: $L->get_source_language();
if ($L->get_by_code($src) === null) throw new \RuntimeException("Invalid source language: $src");
if ($L->get_by_code($target) === null) throw new \RuntimeException("Invalid target language: $target");
if (!$L->is_enabled($target) && $target !== $src) throw new \RuntimeException("Target not enabled: $target");
if ($text === '' || $src === $target) {
return ['ok'=>true, 'translated'=>$text];
            }
// Direct vs Queue (principe appliqué)
if (mb_strlen($text) <= $q['max_direct_chars']) {
$translated = self::translate_unit($text, $src, $target, $mode);
self::db()->upsert_translation($text, $src, $target, $translated);

// Logging ajouté: Log traduction directe réussie
$logger = new YUZ_Logger();
$logger->log('success', 'Direct translation completed', [
  'source' => $src,
  'target' => $target,
  'text_length' => mb_strlen($text)
]);

return ['ok'=>true, 'translated'=>$translated];
            }
// Gros texte → job
$jobId = self::enqueue_job($text, $src, $tgt, $mode, $meta, $q);
return ['ok'=>true, 'queued'=>true, 'job_id'=>$jobId];
        }
/** Unitaire/small path */
private static function translate_unit(string $text, string $src, string $tgt, string $mode): string {
return YUZ_Translation_Manager::translate(
$text, $src, $tgt,
                ['mode'=>$mode, 'settings'=> self::settings()]
            );
        }
/** Découpage  enqueue (cron/Action Scheduler) */
public static function enqueue_job(string $text, string $src, string $tgt, string $mode, array $meta=[], ?array $qos=null): string {
$q = $qos ?: self::qos();
$chunks = self::smart_split($text, $q['max_chunk_chars']);
$jobId = self::db()->create_translation_job($src, $tgt, $mode, count($chunks), array_merge($meta, ['qos'=>$q]));
foreach ($chunks as $i => $chunk) {
self::db()->enqueue_chunk($jobId, $i, $chunk);
            }
// Planifie un run asynchrone
if (function_exists('as_enqueue_async_action')) {
as_enqueue_async_action('yuz_tra_run_job', ['job_id'=>$jobId], 'yuz-tra');
            } else {
if (!wp_next_scheduled('yuz_tra_run_job', [$jobId])) {
wp_schedule_single_event(time() + (5 * 60), 'yuz_tra_run_job', [$jobId]); // syntax error, unexpected integer "5", expecting ")" // yntax error, unexpected integer "5", expecting ")"
                }
            }

// Logging ajouté: Log job enqueued
$logger = new YUZ_Logger();
$logger->log('info', 'Translation job enqueued', [
  'job_id' => $jobId,
  'source' => $src,
  'target' => $tgt,
  'mode' => $mode,
  'chunk_count' => count($chunks),
  'text_length' => mb_strlen($text)
]);

return $jobId;
        }
/** Worker: traite N chunks par passe. Ré-appointe l’exécution jusqu’à completion. */
public static function run_job(string $jobId): void {
$job = self::db()->get_job($jobId);
if (!$job || $job['status'] === 'done' || $job['status'] === 'error') return;
$q = isset($job['meta']['qos']) && is_array($job['meta']['qos']) ? $job['meta']['qos'] : self::qos();
$batchSize = (int) $q['max_chunks_per_run'];
$pending = self::db()->claim_pending_chunks($jobId, $batchSize);

// Logging ajouté: Log début du run job
$logger = new YUZ_Logger();
$logger->log('info', 'Running translation job', [
  'job_id' => $jobId,
  'batch_size' => $batchSize,
  'pending_chunks' => count($pending)
]);

foreach ($pending as $c) {
$attempts = 0;
$maxRetries = (int) $q['max_retries'];
while (true) {
try {
$tr = self::translate_unit($c['payload'], $job['src'], $job['tgt'], $job['mode']);
self::db()->store_chunk_result($jobId, (int)$c['seq'], $tr);

// Logging ajouté: Log chunk réussi
$logger->log('success', 'Chunk translated successfully', [
  'job_id' => $jobId,
  'seq' => $c['seq'],
  'attempts' => $attempts
]);

break;
                    } catch (\Throwable $e) {
$attempts;
YUZ_Logger::log('warning','YUZ job chunk retry', ['job'=>$jobId,'seq'=>$c['seq'],'attempt'=>$attempts,'e'=>$e->getMessage()]);
if ($attempts > $maxRetries) {
YUZ_Logger::log('error','YUZ job chunk failed', ['job'=>$jobId,'seq'=>$c['seq'],'e'=>$e->getMessage()]);
self::db()->mark_chunk_error($jobId, (int)$c['seq'], $e->getMessage());

// Logging ajouté: Log chunk échoué après retries
$logger->log('error', 'Chunk translation failed after max retries', [
  'job_id' => $jobId,
  'seq' => $c['seq'],
  'attempts' => $attempts,
  'error' => $e->getMessage()
]);

break;
                        }
// petit backoff
usleep(150000);
                    }
                }
            }
// finalise si terminé, sinon replanifie un tour
if (self::db()->maybe_finalize_job($jobId)) {

// Logging ajouté: Log job complété
$logger->log('success', 'Translation job completed', ['job_id' => $jobId]);

return;
            }
if (function_exists('as_enqueue_async_action')) {
as_enqueue_async_action('yuz_tra_run_job', ['job_id'=>$jobId], 'yuz-tra');
            } else {
wp_schedule_single_event(time() + 3, 'yuz_tra_run_job', [$jobId]);
            }

// Logging ajouté: Log replanification du job
$logger->log('info', 'Job run incomplete, rescheduling next batch', ['job_id' => $jobId]);
        }
/** Split “safe” par phrases / taille (respecte max_chunk_chars) */
private static function smart_split(string $text, int $limit): array {
$text = trim($text);
$out = [];
while (mb_strlen($text) > $limit) {
$slice = mb_substr($text, 0, $limit);
// essaie de couper en fin de phrase
$pos = false;
foreach (['.', '。', '!', '?', ';', "\n"] as $sep) {
$p = mb_strrpos($slice, $sep);
if ($p !== false && $p > $pos) $pos = $p + 1;
                }
if ($pos === false || $pos < (int)($limit * 0.5)) $pos = $limit;
$out[] = trim(mb_substr($text, 0, $pos));
$text = trim(mb_substr($text, $pos));
            }
if ($text !== '') $out[] = $text;

// Logging ajouté: Log split en chunks (si text long)
$logger = new YUZ_Logger();
if (count($out) > 1) {
  $logger->log('debug', 'Text split into chunks', [
    'original_length' => mb_strlen($text),
    'chunk_count' => count($out),
    'limit' => $limit
  ]);
}

return $out;
        }
/** Suivi de job pour l’UI (barre de progression) */
public static function get_job_status(string $jobId): array {
$status = self::db()->get_job_status($jobId) ?: ['status'=>'unknown'];

// Logging ajouté: Log requête de status
$logger = new YUZ_Logger();
$logger->log('debug', 'Job status requested', [
  'job_id' => $jobId,
  'status' => $status['status']
]);

return $status;
        }
/* =========================
         * Validation utilitaire
         * ========================= */
private static function validate_payload(array $payload): array {
$text = trim((string)($payload['text'] ?? ''));
$source = (string)($payload['source'] ?? '');
$target = (string)($payload['target'] ?? '');
$mode = (string)($payload['mode'] ?? 'semi');
$meta = (array) ($payload['meta'] ?? []);

// Logging ajouté: Log payload validé (si invalide, warning)
$logger = new YUZ_Logger();
if (empty($text) || empty($target)) {
  $logger->log('warning', 'Invalid translation payload', [
    'text_length' => mb_strlen($text),
    'source' => $source,
    'target' => $target,
    'mode' => $mode
  ]);
} else {
  $logger->log('debug', 'Payload validated', [
    'text_length' => mb_strlen($text),
    'source' => $source,
    'target' => $target,
    'mode' => $mode
  ]);
}

return [
'text' => $text,
'source' => $source,
'target' => $target,
'mode' => $mode,
'meta' => $meta,
            ];
        }
    }
endif;
// Hook du worker (cron / Action Scheduler fallback)
add_action('yuz_tra_run_job', ['YUZ_Services','run_job'], 10, 1);

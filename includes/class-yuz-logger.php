<?php
/**
 * Class YUZ_Logger
 * Robust logger with level threshold, coalescing/rate-limit, sampling,
 * rotation (size + count), and context capping.
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
use YUZTRA\Interfaces\LoggerInterface;
if (!class_exists('YUZ_Logger')) {
    class YUZ_Logger implements LoggerInterface {
        // ---------- Defaults (peuvent être surchargés par options WP ou constantes) ----------
        private const DEFAULT_LEVEL = 'warning'; // debug|info|success|warning|error|critical
        private const DEFAULT_MAX_BYTES = 2_097_152; // 2 MB
        private const DEFAULT_MAX_FILES = 5; // .1 .. .5
        private const DEFAULT_RATE_WINDOW = 10; // secondes
        private const DEFAULT_RATE_MAX = 5; // max messages similaires/clé/fenêtre
        private const DEFAULT_CTX_MAXLEN = 500; // longueur max de chaque valeur string
        private const DEFAULT_CTX_MAXKEYS = 30; // nombre max d'entrées de contexte sérialisées
        // Map niveau → ordre
        private const LEVELS = [
            'debug' => 5,
            'info' => 10,
            'success' => 15,
            'warning' => 20,
            'error' => 30,
            'critical' => 40,
        ];
        // État global de throttling par "clé de bruit"
        private static array $buckets = []; // key => [start:int,count:int,suppressed:int]
        private static bool $shutdownHooked = false;
        // Config courante
        private string $level;
        private string $file;
        private int $maxBytes;
        private int $maxFiles;
        private int $rateWindow;
        private int $rateMax;
        private int $ctxMaxLen;
        private int $ctxMaxKeys;
        // ---------- Bootstrap ----------
        public static function init(): void {
            // Rien d'agressif ici: on laisse le ctor créer le fichier à la volée.
        }
        public function __construct(?string $file = null) {
            // 1) Fichier de log (constante > option > défaut uploads/)
            $uploads = function_exists('wp_upload_dir') ? wp_upload_dir() : ['basedir' => WP_CONTENT_DIR . '/uploads'];
            $defaultPath = rtrim($uploads['basedir'] ?? (WP_CONTENT_DIR . '/uploads'), '/').'/yuz-log.log';
            $this->file = defined('YUZ_TRA_LOG_FILE') && is_string(YUZ_TRA_LOG_FILE) && YUZ_TRA_LOG_FILE
                ? YUZ_TRA_LOG_FILE
                : ($file ?: $defaultPath);
            // 2) Niveau (constante > option > défaut)
            $optLevel = function_exists('get_option') ? (string) get_option('yuz_tra_log_level', self::DEFAULT_LEVEL) : self::DEFAULT_LEVEL;
            $this->setLevel(defined('YUZ_TRA_LOG_LEVEL') ? (string) YUZ_TRA_LOG_LEVEL : $optLevel);
            // 3) Limites/ratelimit/context caps
            $this->maxBytes = (int) (defined('YUZ_TRA_LOG_MAX_BYTES') ? YUZ_TRA_LOG_MAX_BYTES : (function_exists('get_option') ? (int) get_option('yuz_tra_log_max_bytes', self::DEFAULT_MAX_BYTES) : self::DEFAULT_MAX_BYTES));
            $this->maxFiles = (int) (defined('YUZ_TRA_LOG_MAX_FILES') ? YUZ_TRA_LOG_MAX_FILES : (function_exists('get_option') ? (int) get_option('yuz_tra_log_max_files', self::DEFAULT_MAX_FILES) : self::DEFAULT_MAX_FILES));
            $this->rateWindow = (int) (defined('YUZ_TRA_LOG_RATE_WINDOW') ? YUZ_TRA_LOG_RATE_WINDOW : (function_exists('get_option') ? (int) get_option('yuz_tra_log_rate_window', self::DEFAULT_RATE_WINDOW) : self::DEFAULT_RATE_WINDOW));
            $this->rateMax = (int) (defined('YUZ_TRA_LOG_RATE_MAX') ? YUZ_TRA_LOG_RATE_MAX : (function_exists('get_option') ? (int) get_option('yuz_tra_log_rate_max', self::DEFAULT_RATE_MAX) : self::DEFAULT_RATE_MAX));
            $this->ctxMaxLen = (int) (defined('YUZ_TRA_LOG_CTX_MAXLEN') ? YUZ_TRA_LOG_CTX_MAXLEN : (function_exists('get_option') ? (int) get_option('yuz_tra_log_ctx_maxlen', self::DEFAULT_CTX_MAXLEN) : self::DEFAULT_CTX_MAXLEN));
            $this->ctxMaxKeys = (int) (defined('YUZ_TRA_LOG_CTX_MAXKEYS') ? YUZ_TRA_LOG_CTX_MAXKEYS : (function_exists('get_option') ? (int) get_option('yuz_tra_log_ctx_maxkeys', self::DEFAULT_CTX_MAXKEYS) : self::DEFAULT_CTX_MAXKEYS));
            // 4) Assurer présence du dossier + fichier
            $dir = dirname($this->file);
            if (!is_dir($dir)) {
                if (function_exists('wp_mkdir_p')) @wp_mkdir_p($dir); else @mkdir($dir, 0755, true);
            }
            if (!file_exists($this->file)) {
                @touch($this->file);
                @chmod($this->file, 0644);
            }
            // 5) Flush de fin si besoin (aujourd’hui inutile: on flush par fenêtre)
            if (!self::$shutdownHooked && function_exists('add_action')) {
                self::$shutdownHooked = true;
                add_action('shutdown', [__CLASS__, 'flushSuppressed']);
            }
        }
        // ---------- API LoggerInterface ----------
        public function setLevel(string $level): void {
            $l = strtolower(trim($level));
            $this->level = array_key_exists($l, self::LEVELS) ? $l : self::DEFAULT_LEVEL;
        }
        public function log(string $level, string $message, array $context = []): void {
            // Coupe-circuit global
            if (defined('YUZ_TRA_LOG_DISABLED') && YUZ_TRA_LOG_DISABLED) return;
            // Seuil
            $lvlNum = self::LEVELS[strtolower($level)] ?? self::LEVELS[self::DEFAULT_LEVEL];
            if ($lvlNum < self::LEVELS[$this->level]) return;
            // Ratelimit/coalescing — on protège *tous* les modes, mais on laisse passer
            // warning|error|critical sans suppression (seulement coalescing message de synthèse).
            $key = $this->noiseKey($level, $message, $context);
            $now = time();
            $bucket = self::$buckets[$key] ?? ['start' => $now, 'count' => 0, 'suppressed' => 0];
            // nouvelle fenêtre ?
            if ($now - $bucket['start'] >= $this->rateWindow) {
                if ($bucket['suppressed'] > 0) {
                    $this->write($level, "[coalesce] suppressed {$bucket['suppressed']} similar messages", $context);
                }
                $bucket = ['start' => $now, 'count' => 0, 'suppressed' => 0];
            }
            $bucket['count']++;
            $shouldSuppress = (
                $bucket['count'] > $this->rateMax
                && $lvlNum <= self::LEVELS['info'] // on rate-limit surtout debug/info/success
            );
            if ($shouldSuppress) {
                $bucket['suppressed']++;
                self::$buckets[$key] = $bucket;
                return;
            }
            self::$buckets[$key] = $bucket;
            // Sampling optionnel: ['sample' => 50] => 1 message / 50
            if (isset($context['sample']) && (int)$context['sample'] > 1) {
                if (($bucket['count'] % (int)$context['sample']) !== 1) return;
            }
            $this->write($level, $message, $context);
        }
        // ---------- Internals ----------
        private function noiseKey(string $level, string $message, array $context): string {
            // On neutralise les nombres/IDs pour regrouper les messages identiques
            $normalized = preg_replace('/\b\d+\b/', '{n}', (string)$message);
            $cat = (string) ($context['category'] ?? ($context['class'] ?? ''));
            return $cat.'|'.strtolower($level).'|'.$normalized;
        }
        private function write(string $level, string $message, array $context): void {
            // Rotation (hard cap taille)
            $this->rotateIfNeeded();
            // Cap du contexte (taille + nombre de clés, pour éviter 1 ligne avec 5 Mo)
            if (!empty($context)) {
                if (count($context) > $this->ctxMaxKeys) {
                    $context = array_slice($context, 0, $this->ctxMaxKeys, true) + ['__truncated__' => true];
                }
                array_walk($context, function (&$v) {
                    if (is_string($v) && strlen($v) > $this->ctxMaxLen) {
                        $v = mb_substr($v, 0, $this->ctxMaxLen) . '…';
                    } elseif (is_array($v) || is_object($v)) {
                        $j = json_encode($v, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
                        if ($j !== false && strlen($j) > $this->ctxMaxLen) {
                            $v = mb_substr($j, 0, $this->ctxMaxLen) . '…';
                        }
                    }
                });
            }
            // Timestamp UTC pour corrélation (pas de locale)
            $ts = gmdate('Y-m-d H:i:s').' UTC';
            $line = sprintf(
                "%s %s %s: %s%s\n",
                $ts,
                $this->emoji($level),
                strtoupper($level),
                $message,
                empty($context) ? '' : (' ' . json_encode($context, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES))
            );
            // Écriture avec verrou pour éviter la corruption
            $fh = @fopen($this->file, 'ab');
            if ($fh === false) {
                // fallback
                return;
            }
            @flock($fh, LOCK_EX);
            @fwrite($fh, $line);
            @flock($fh, LOCK_UN);
            @fclose($fh);
        }
        public static function flushSuppressed(): void {
            // On ne garde pas d’état global à flush ici: chaque bascule de fenêtre écrit déjà un résumé.
            // Si besoin, on pourrait itérer sur self::$buckets pour émettre un dernier "suppressed".
        }
        private function emoji(string $level): string {
            $l = strtolower($level);
            return match ($l) {
                'critical','error' => '🟥',
                'warning' => '🟨',
                'success' => '🟩',
                default => '🟦',
            };
        }
        private function rotateIfNeeded(): void {
            clearstatcache(true, $this->file);
            $size = @filesize($this->file);
            if ($size === false || $size < $this->maxBytes) return;
            // Renommer .(n-1) -> .n
            for ($i = $this->maxFiles - 1; $i >= 1; $i--) {
                $src = $this->file . '.' . $i;
                $dst = $this->file . '.' . ($i + 1);
                if (file_exists($src)) @rename($src, $dst);
            }
            // Pivot -> .1
            @rename($this->file, $this->file . '.1');
            // Nouveau fichier vide
            @file_put_contents($this->file, "");
            @chmod($this->file, 0644);
            // Supprimer au-delà du max
            $overflow = $this->file . '.' . ($this->maxFiles + 1);
            if (file_exists($overflow)) @unlink($overflow);
        }
    }
}


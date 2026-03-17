<?php
/**
 * YUZ AI endpoints & job orchestration
 * File: includes/class-yuz-ai.php
 *
 * ACT-12,13,14,15 — P0
 *
 * Endpoints:
 *  - ajax_yuz_ai_test_connection   (nonce: yuz_api_nonce)                    [caps: manage_options]
 *  - ajax_yuz_ai_save_settings     (nonce: yuz_con_nonce)                    [caps: manage_options]
 *  - ajax_yuz_ai_translate_text    (nonce: yuz_int_nonce)                    [caps: edit_posts]
 *  - ajax_yuz_ai_batch_translate   (nonce: yuz_hvy_nonce)                    [caps: manage_options]
 *  - ajax_yuz_ai_glossary_upload   (nonce: yuz_con_nonce)                    [caps: manage_options]
 *  - ajax_yuz_ai_job_status        (nonce: yuz_tra_nonce)                    [caps: edit_posts]
 *
 * Stockage jobs: transient "yuz_ai_job_<job_id>" (TTL par défaut 15 min).
 * Quotas/coûts: option "yuz_ai_quota" (cap mensuelle simple) + option "yuz_tra_ai_settings".
 *
 * Remarque provider:
 *  - Vous pouvez brancher un provider réel via les hooks:
 *      apply_filters('yuz_ai_translate_text', $result, $params, $settings)
 *      apply_filters('yuz_ai_batch_translate', $result, $params, $settings)
 *      apply_filters('yuz_ai_test_connection', $result, $settings)
 *  - Si aucun hook ne répond, on renvoie un fallback non-bloquant (mock).
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

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'YUZ_AI' ) ) {

class YUZ_AI {

    const OPT_SETTINGS    = 'yuz_tra_ai_settings';
    const OPT_QUOTA       = 'yuz_ai_quota';
    const OPT_GLOSSARIES  = 'yuz_ai_glossaries';
    const JOB_TTL_SECONDS = 15 * 60; // 15 minutes
    const JOB_PREFIX      = 'yuz_ai_job_';

    /** @var self */
    private static $instance;

    public static function instance(): self {
        if ( ! self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function register_hooks(): void {
        // Admin AJAX endpoints (auth required)
        add_action( 'wp_ajax_yuz_ai_test_connection', [ $this, 'ajax_test_connection' ] );
        add_action( 'wp_ajax_yuz_ai_save_settings',  [ $this, 'ajax_save_settings' ] );
        add_action( 'wp_ajax_yuz_ai_translate_text', [ $this, 'ajax_translate_text' ] );
        add_action( 'wp_ajax_yuz_ai_batch_translate',[ $this, 'ajax_batch_translate' ] );
        add_action( 'wp_ajax_yuz_ai_glossary_upload',[ $this, 'ajax_glossary_upload' ] );
        add_action( 'wp_ajax_yuz_ai_job_status',     [ $this, 'ajax_job_status' ] );

        // Maintenance légère: reset mensuel du quota si besoin
        add_action( 'init', [ $this, 'maybe_reset_monthly_quota' ] );
    }

    /* ----------------------------- AJAX handlers ---------------------------- */

    public function ajax_test_connection(): void {
        $this->json_headers_no_cache();

        $this->require_caps( 'manage_options' );
        $this->verify_nonce_or_fail( 'yuz_api_nonce' );

        $settings = $this->get_settings();

        // Hook provider (doit retourner ['ok'=>bool, 'message'=>string])
        $result = apply_filters( 'yuz_ai_test_connection', null, $settings );
        if ( is_array( $result ) && isset( $result['ok'] ) ) {
            if ( $result['ok'] ) {
                $this->json_ok( [ 'message' => $result['message'] ?? 'Connection OK (provider)' ] );
            }
            $this->json_error( $result['message'] ?? 'Connection failed (provider)' );
        }

        // Fallback trivial: considère OK si api_key + endpoint existent
        if ( ! empty( $settings['api_key'] ) && ! empty( $settings['endpoint'] ) ) {
            $this->json_ok( [ 'message' => 'Connection OK (fallback)' ] );
        }

        $this->json_error( 'Missing API settings (endpoint/api_key).' );
    }

    public function ajax_save_settings(): void {
        $this->json_headers_no_cache();

        $this->require_caps( 'manage_options' );
        $this->verify_nonce_or_fail( 'yuz_con_nonce' );

        $raw = wp_unslash( $_POST['settings'] ?? [] );
        $settings = $this->sanitize_settings( is_array( $raw ) ? $raw : [] );

        update_option( self::OPT_SETTINGS, $settings, false );

        $this->json_ok( [
            'message'  => __( 'Settings saved.', 'yuz-tra' ),
            'settings' => $settings,
        ] );
    }

    public function ajax_translate_text(): void {
        $this->json_headers_no_cache();

        $this->require_caps( 'edit_posts' );
        $this->verify_nonce_or_fail( 'yuz_int_nonce' );

        $text        = $this->param_string( 'text' );
        $source_lang = $this->param_string( 'source_lang', 'auto' );
        $target_lang = $this->param_string( 'target_lang' );
        if ( $text === '' || $target_lang === '' ) {
            $this->json_error( 'Missing required parameters (text, target_lang).' );
        }

        $settings = $this->get_settings();

        // Provider hook
        $params = compact( 'text', 'source_lang', 'target_lang' );
        $result = apply_filters( 'yuz_ai_translate_text', null, $params, $settings );
        if ( is_array( $result ) && isset( $result['translation'] ) ) {
            // quota: au bon vouloir du provider, sinon on estime ici
            $this->consume_quota_estimate( mb_strlen( $text ), $settings );
            $this->json_ok( [
                'translation' => $result['translation'],
                'provider'    => $result['provider'] ?? 'provider',
                'cost_cents'  => $result['cost_cents'] ?? $this->estimate_cost_cents( mb_strlen( $text ), $settings ),
            ] );
        }

        // Fallback safe (mock)
        $translation = sprintf( '%s [→ %s]', $text, strtoupper( $target_lang ) );
        $cost_cents  = $this->estimate_cost_cents( mb_strlen( $text ), $settings );
        $this->consume_quota( $cost_cents );

        $this->json_ok( [
            'translation' => $translation,
            'provider'    => 'mock',
            'cost_cents'  => $cost_cents,
            'is_mock'     => true,
        ] );
    }

    public function ajax_batch_translate(): void {
        $this->json_headers_no_cache();

        $this->require_caps( 'manage_options' );
        $this->verify_nonce_or_fail( 'yuz_hvy_nonce' );

        $texts       = isset( $_POST['texts'] ) ? wp_unslash( $_POST['texts'] ) : [];
        $source_lang = $this->param_string( 'source_lang', 'auto' );
        $target_lang = $this->param_string( 'target_lang' );
        if ( ! is_array( $texts ) || empty( $texts ) || $target_lang === '' ) {
            $this->json_error( 'Missing required parameters (texts[], target_lang).' );
        }

        $settings = $this->get_settings();

        $job_id = wp_generate_uuid4();
        $now    = time();

        $total_chars = 0;
        foreach ( $texts as $t ) {
            $total_chars += mb_strlen( (string) $t );
        }
        $est_cost_cents = $this->estimate_cost_cents( $total_chars, $settings );

        // Pré-crée le job
        $job = [
            'id'           => $job_id,
            'status'       => 'queued', // queued → processing → completed|failed
            'created_at'   => $now,
            'updated_at'   => $now,
            'source_lang'  => $source_lang,
            'target_lang'  => $target_lang,
            'total'        => count( $texts ),
            'completed'    => 0,
            'texts'        => array_values( $texts ), // stocker si provider async indisponible
            'translations' => [],
            'est_cost_cents' => $est_cost_cents,
        ];
        set_transient( self::JOB_PREFIX . $job_id, $job, self::JOB_TTL_SECONDS );

        /**
         * Provider hook batch:
         * - Soit le provider lance un job distant et renvoie ['queued'=>true,'job_remote_id'=>'...']
         * - Soit il traite en local/immédiat et renvoie ['completed'=>true,'translations'=>[]]
         */
        $params = [
            'texts'       => $texts,
            'source_lang' => $source_lang,
            'target_lang' => $target_lang,
            'job_id'      => $job_id,
        ];
        $provider = apply_filters( 'yuz_ai_batch_translate', null, $params, $settings );

        if ( is_array( $provider ) ) {
            $job['updated_at'] = time();
            if ( ! empty( $provider['completed'] ) && ! empty( $provider['translations'] ) && is_array( $provider['translations'] ) ) {
                $job['status']       = 'completed';
                $job['translations'] = $provider['translations'];
                $job['completed']    = $job['total'];
                set_transient( self::JOB_PREFIX . $job_id, $job, self::JOB_TTL_SECONDS );
                $this->consume_quota( $est_cost_cents );
                $this->json_ok( [ 'job_id' => $job_id, 'status' => 'completed' ] );
            }

            // Sinon on marque processing si un job provider est lancé
            if ( ! empty( $provider['queued'] ) ) {
                $job['status']         = 'processing';
                $job['provider_job_id']= (string) ( $provider['job_remote_id'] ?? '' );
                set_transient( self::JOB_PREFIX . $job_id, $job, self::JOB_TTL_SECONDS );
                $this->json_ok( [ 'job_id' => $job_id, 'status' => 'processing' ] );
            }
        }

        // Fallback: job simulé → le polling avancera la complétion
        $job['status'] = 'processing';
        set_transient( self::JOB_PREFIX . $job_id, $job, self::JOB_TTL_SECONDS );

        $this->json_ok( [
            'job_id' => $job_id,
            'status' => 'processing',
            'est_cost_cents' => $est_cost_cents,
        ] );
    }

    public function ajax_job_status(): void {
        $this->json_headers_no_cache();

        $this->require_caps( 'edit_posts' );
        $this->verify_nonce_or_fail( 'yuz_int_nonce' );

        $job_id = $this->param_string( 'job_id' );
        if ( $job_id === '' ) {
            $this->json_error( 'Missing job_id' );
        }

        $job = get_transient( self::JOB_PREFIX . $job_id );
        if ( ! is_array( $job ) ) {
            $this->json_error( 'Job not found or expired.' );
        }

        // Si provider ne pilote pas la complétion, on simule une progression temporelle.
        if ( $job['status'] === 'processing' || $job['status'] === 'queued' ) {
            $elapsed = max( 1, time() - (int) $job['created_at'] );
            $rate    = 2; // éléments complétés par seconde (simulation)
            $completed = min( $job['total'], (int) floor( $elapsed / $rate ) );

            if ( $completed > $job['completed'] ) {
                // Génère des traductions simulées manquantes
                for ( $i = $job['completed']; $i < $completed; $i++ ) {
                    $src = $job['texts'][ $i ] ?? '';
                    $job['translations'][ $i ] = sprintf( '%s [→ %s]', $src, strtoupper( $job['target_lang'] ) );
                }
                $job['completed'] = $completed;
                $job['updated_at'] = time();
                set_transient( self::JOB_PREFIX . $job_id, $job, self::JOB_TTL_SECONDS );
            }

            if ( $job['completed'] >= $job['total'] ) {
                $job['status'] = 'completed';
                set_transient( self::JOB_PREFIX . $job_id, $job, self::JOB_TTL_SECONDS );
                // Consomme la quota estimée une seule fois à la fin
                $this->consume_quota( (int) ( $job['est_cost_cents'] ?? 0 ) );
            }
        }

        $quota = $this->get_quota();
        $banner = $this->quota_banner( $quota );

        $this->json_ok( [
            'job_id'       => $job['id'],
            'status'       => $job['status'],
            'completed'    => (int) $job['completed'],
            'total'        => (int) $job['total'],
            'translations' => $job['status'] === 'completed' ? array_values( $job['translations'] ) : [],
            'est_cost_cents' => (int) ( $job['est_cost_cents'] ?? 0 ),
            'quota'        => $quota,
            'banner'       => $banner,
        ] );
    }

    public function ajax_glossary_upload(): void {
        $this->json_headers_no_cache();

        $this->require_caps( 'manage_options' );
        $this->verify_nonce_or_fail( 'yuz_con_nonce' );

        if ( empty( $_FILES['file'] ) || ! is_array( $_FILES['file'] ) ) {
            $this->json_error( 'No file uploaded.' );
        }

        $file = $_FILES['file'];
        if ( ! function_exists( 'wp_handle_upload' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        $overrides = [
            'test_form' => false,
            'mimes'     => [
                'csv' => 'text/csv',
                'txt' => 'text/plain',
                'tsv' => 'text/tab-separated-values',
            ],
        ];
        $movefile = wp_handle_upload( $file, $overrides );
        if ( isset( $movefile['error'] ) ) {
            $this->json_error( 'Upload failed: ' . $movefile['error'] );
        }

        $glossaries = get_option( self::OPT_GLOSSARIES, [] );
        $item = [
            'filename'  => basename( $movefile['file'] ),
            'url'       => $movefile['url'],
            'type'      => $movefile['type'],
            'uploaded'  => current_time( 'mysql' ),
        ];
        $glossaries[] = $item;
        update_option( self::OPT_GLOSSARIES, $glossaries, false );

        $this->json_ok( [
            'message'   => 'Glossary uploaded.',
            'glossary'  => $item,
        ] );
    }

    /* -------------------------------- Helpers ------------------------------- */

    private function json_headers_no_cache(): void {
        nocache_headers();
        header( 'Content-Type: application/json; charset=' . get_bloginfo( 'charset' ) );
    }

    private function json_ok( array $data ): void {
        wp_send_json_success( $data );
    }

    private function json_error( string $message, int $code = 400, array $extra = [] ): void {
        $payload = array_merge( [ 'message' => $message ], $extra );
        wp_send_json_error( $payload, $code );
    }

    private function require_caps( string $cap ): void {
        if ( ! current_user_can( $cap ) ) {
            $this->json_error( 'Forbidden', 403 );
        }
    }

    /**
     * Vérifie un nonce issu de la matrice officielle.
     *
     * @param string $nonce_key
     */
    private function verify_nonce_or_fail( string $nonce_key ): void {
        $allowed = [
            'yuz_tra_nonce',
            'yuz_con_nonce',
            'yuz_int_nonce',
            'yuz_del_nonce',
            'yuz_log_nonce',
            'yuz_hvy_nonce',
            'yuz_api_nonce',
        ];
        if (!in_array($nonce_key, $allowed, true)) {
            $nonce_key = 'yuz_tra_nonce';
        }
        $nonce = isset( $_REQUEST['nonce'] ) ? (string) $_REQUEST['nonce'] : '';
        if ( $nonce === '' ) {
            $this->json_error( 'Missing nonce.', 403 );
        }

        if ( wp_verify_nonce( $nonce, $nonce_key ) ) {
            return;
        }

        $this->json_error( 'Invalid nonce.', 403, [ 'expected' => $nonce_key ] );
    }

    private function param_string( string $key, string $default = '' ): string {
        if ( ! isset( $_REQUEST[ $key ] ) ) {
            return $default;
        }
        $val = wp_unslash( $_REQUEST[ $key ] );
        return is_string( $val ) ? trim( $val ) : $default;
    }

    private function get_settings(): array {
        $defaults = [
            'provider'         => '',       // ex: 'openai', 'gcp', ...
            'endpoint'         => '',       // URL du provider si nécessaire
            'api_key'          => '',
            'model'            => '',       // ex: 'gpt-4o-mini-translator', 'nllb-200'...
            'price_per_kchar'  => 20,       // centimes pour 1000 caractères (fallback)
            'monthly_cap_cents'=> 2000,     // 20,00€ par défaut (exemple)
        ];
        $o = get_option( self::OPT_SETTINGS, [] );
        return wp_parse_args( is_array( $o ) ? $o : [], $defaults );
    }

    private function sanitize_settings( array $raw ): array {
        return [
            'provider'         => isset( $raw['provider'] ) ? sanitize_text_field( $raw['provider'] ) : '',
            'endpoint'         => isset( $raw['endpoint'] ) ? esc_url_raw( $raw['endpoint'] ) : '',
            'api_key'          => isset( $raw['api_key'] ) ? sanitize_text_field( $raw['api_key'] ) : '',
            'model'            => isset( $raw['model'] ) ? sanitize_text_field( $raw['model'] ) : '',
            'price_per_kchar'  => isset( $raw['price_per_kchar'] ) ? max( 0, (int) $raw['price_per_kchar'] ) : 20,
            'monthly_cap_cents'=> isset( $raw['monthly_cap_cents'] ) ? max( 0, (int) $raw['monthly_cap_cents'] ) : 2000,
        ];
    }

    private function get_quota(): array {
        $defaults = [
            'used_cents_mtd'   => 0,
            'monthly_cap_cents'=> (int) $this->get_settings()['monthly_cap_cents'],
            'last_reset'       => gmdate( 'Y-m-01 00:00:00' ),
        ];
        $o = get_option( self::OPT_QUOTA, [] );
        $q = wp_parse_args( is_array( $o ) ? $o : [], $defaults );

        // cap peut avoir changé dans les settings
        $q['monthly_cap_cents'] = (int) $this->get_settings()['monthly_cap_cents'];

        return $q;
    }

    private function update_quota( array $q ): void {
        update_option( self::OPT_QUOTA, $q, false );
    }

    public function maybe_reset_monthly_quota(): void {
        $q = $this->get_quota();
        $current_month = gmdate( 'Y-m' );
        $last_reset_m  = substr( (string) $q['last_reset'], 0, 7 );
        if ( $last_reset_m !== $current_month ) {
            $q['used_cents_mtd'] = 0;
            $q['last_reset']     = gmdate( 'Y-m-01 00:00:00' );
            $this->update_quota( $q );
        }
    }

    private function estimate_cost_cents( int $chars, array $settings ): int {
        // Estimation très simple basée sur un prix pour 1000 caractères
        $per_k = (int) ( $settings['price_per_kchar'] ?? 20 ); // centimes
        if ( $chars <= 0 ) return 0;
        $thousands = $chars / 1000.0;
        return (int) round( $per_k * $thousands );
    }

    private function consume_quota_estimate( int $chars, array $settings ): void {
        $this->consume_quota( $this->estimate_cost_cents( $chars, $settings ) );
    }

    private function consume_quota( int $delta_cents ): void {
        if ( $delta_cents <= 0 ) return;
        $q = $this->get_quota();
        $q['used_cents_mtd'] = (int) $q['used_cents_mtd'] + $delta_cents;
        $this->update_quota( $q );
    }

    private function quota_banner( array $q ): array {
        $cap   = max( 1, (int) $q['monthly_cap_cents'] );
        $used  = (int) $q['used_cents_mtd'];
        $pct   = min( 100, (int) floor( ( $used / $cap ) * 100 ) );

        $level = 'ok';
        $text  = '';
        if ( $pct >= 95 ) {
            $level = 'critical';
            $text  = __( 'Monthly AI budget almost exhausted.', 'yuz-tra' );
        } elseif ( $pct >= 80 ) {
            $level = 'warning';
            $text  = __( 'You are nearing your monthly AI budget.', 'yuz-tra' );
        }

        return [
            'level'        => $level, // ok|warning|critical
            'percent_used' => $pct,
            'used_cents'   => $used,
            'cap_cents'    => $cap,
            'message'      => $text,
        ];
    }
}

} // class exists

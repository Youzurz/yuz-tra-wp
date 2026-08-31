<?php
/**
 * Class YUZ_CSV_Importer
 * Import languages from a CSV file into the YUZ-TRA plugin.
 *
 * Expected CSV format (with header) :
 *   language_code,language_name
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

// Assume class-yuz-contracts.php contains CsvImporterInterface and LanguageManagerInterface
require_once YUZ_TRA_INCLUDES . 'class-yuz-contracts.php';

use YUZTRA\Interfaces\CsvImporterInterface;
use YUZTRA\Interfaces\LanguageManagerInterface;

if ( ! class_exists( 'YUZ_CSV_Importer' ) ) {

    class YUZ_CSV_Importer implements CsvImporterInterface {
        /** @var LanguageManagerInterface */
        private $language_manager;
        /** @var YUZ_Logger */
        private $logger;

        /**
         * @param LanguageManagerInterface $language_manager
         * @param YUZ_Logger               $logger
         */
        public function __construct( LanguageManagerInterface $language_manager, YUZ_Logger $logger ) {
            $this->language_manager = $language_manager;
            $this->logger           = $logger;
        }

        /**
         * Adds a submenu under “YUZ-TRA → Import CSV”.
         */
        public function add_import_page(): void {
            add_submenu_page(
                'yuz-translation',                     // parent slug defined by your plugin
                __( 'Import Languages', 'yuz-translation' ),
                __( 'Import CSV', 'yuz-translation' ),
                'manage_options',
                'yuz-import-csv',
                [ $this, 'render_import_form' ]
            );
        }

        /**
         * Renders the CSV upload form.
         */
        public function render_import_form(): void {
            if ( ! current_user_can( 'manage_options' ) ) {
                wp_die( esc_html__( 'Unauthorized', 'yuz-translation' ) );
            }

            // Check for transient messages
            $success_message = get_transient( 'yuz_import_success' );
            $error_message = get_transient( 'yuz_import_error' );
            if ( $success_message ) {
                delete_transient( 'yuz_import_success' );
            }
            if ( $error_message ) {
                delete_transient( 'yuz_import_error' );
            }
            ?>
            <div class="wrap">
                <h1><?php esc_html_e( 'Import Languages from CSV', 'yuz-translation' ); ?></h1>
                <?php if ( $success_message ) : ?>
                    <div class="notice notice-success">
                        <p><?php echo esc_html( $success_message ); ?></p>
                    </div>
                <?php endif; ?>
                <?php if ( $error_message ) : ?>
                    <div class="notice notice-error">
                        <p><?php echo esc_html( $error_message ); ?></p>
                    </div>
                <?php endif; ?>

                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
                    <?php wp_nonce_field( 'yuz_hvy_nonce', 'nonce' ); ?>
                    <input type="hidden" name="action" value="yuz_import_csv">
                    <table class="form-table">
                        <tr>
                            <th scope="row"><label for="csv_file"><?php esc_html_e( 'CSV File', 'yuz-translation' ); ?></label></th>
                            <td><input type="file" name="csv_file" id="csv_file" accept=".csv" required></td>
                        </tr>
                    </table>
                    <?php submit_button( __( 'Import', 'yuz-translation' ) ); ?>
                </form>
            </div>
            <?php
        }

        /**
         * Handles the import POST request.
         */
        public function handle_import(): void {
            if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'yuz_hvy_nonce', 'nonce' ) ) {
                wp_die( esc_html__( 'Unauthorized', 'yuz-translation' ) );
            }

            if ( empty( $_FILES['csv_file'] ) || empty( $_FILES['csv_file']['tmp_name'] ) ) {
                set_transient( 'yuz_import_error', __( 'No file uploaded.', 'yuz-translation' ), 30 );
                wp_redirect( admin_url( 'admin.php?page=yuz-import-csv' ) );
                exit;
            }

            // Validate file type
            $file_info = wp_check_filetype( basename( $_FILES['csv_file']['name'] ) );
            if ( ! $file_info || 'csv' !== $file_info['ext'] ) {
                set_transient( 'yuz_import_error', __( 'Invalid file type. Please upload a CSV file.', 'yuz-translation' ), 30 );
                wp_redirect( admin_url( 'admin.php?page=yuz-import-csv' ) );
                exit;
            }

            // Check file size against max upload size
            $max_size = wp_max_upload_size();
            if ( $_FILES['csv_file']['size'] > $max_size ) {
                /* translators: %s: maximum upload size. */
                set_transient( 'yuz_import_error', sprintf( __( 'File too large. Maximum size is %s.', 'yuz-translation' ), size_format( $max_size ) ), 30 );
                wp_redirect( admin_url( 'admin.php?page=yuz-import-csv' ) );
                exit;
            }

            $result = $this->import_csv( $_FILES['csv_file']['tmp_name'] );

            if ( $result['success'] ) {
                /* translators: %d: number of imported languages. */
                set_transient( 'yuz_import_success', sprintf( __( 'Import completed. %d languages imported.', 'yuz-translation' ), $result['count'] ), 30 );
            } else {
                /* translators: %s: import error messages. */
                set_transient( 'yuz_import_error', sprintf( __( 'Import failed. Errors: %s', 'yuz-translation' ), implode( '; ', $result['errors'] ) ), 30 );
            }

            wp_redirect( admin_url( 'admin.php?page=yuz-import-csv' ) );
            exit;
        }

        /**
         * Reads the CSV and creates/activates languages.
         *
         * @param string $file_path
         * @return array ['success' => bool, 'count' => int, 'errors' => array]
         */
        public function import_csv( string $file_path ): array {
            $result = [
                'success' => true,
                'count' => 0,
                'errors' => [],
            ];

            try {
                $csv = new SplFileObject( $file_path, 'r' );
                $csv->setFlags( SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY | SplFileObject::DROP_NEW_LINE );
                $csv->setCsvControl( ',' );
            } catch ( RuntimeException $e ) {
                $this->logger->log( 'error', 'Unable to open CSV: ' . $file_path );
                $result['errors'][] = __( 'Unable to open file.', 'yuz-translation' );
                $result['success'] = false;
                return $result;
            }

            $row = 0;
            foreach ( $csv as $data ) {
                if ( ! is_array( $data ) || $data === [ null ] ) {
                    continue;
                }
                $row++;
                // Skip header if it matches
                if ( $row === 1 && preg_match( '/language_code/i', implode( ',', $data ) ) ) {
                    continue;
                }

                // Expect: [0]=code, [1]=name
                $code = sanitize_text_field( $data[0] ?? '' );
                $name = sanitize_text_field( $data[1] ?? '' );

                if ( ! $code || ! $name ) {
                    /* translators: %d: CSV line number. */
                    $error = sprintf( __( 'Line %d ignored: invalid data.', 'yuz-translation' ), $row );
                    $this->logger->log( 'warning', $error );
                    $result['errors'][] = $error;
                    continue;
                }

                try {
                    // Generic method to add/activate a language
                    $this->language_manager->add_language( $code, $name );
                    $result['count']++;
                } catch ( Exception $e ) {
                    /* translators: %1$s: language code, %2$d: CSV line number, %3$s: error message. */
                    $error = sprintf( __( 'Failed to import %1$s on line %2$d: %3$s', 'yuz-translation' ), $code, $row, $e->getMessage() );
                    $this->logger->log( 'error', $error );
                    $result['errors'][] = $error;
                }
            }

            if ( ! empty( $result['errors'] ) ) {
                $result['success'] = false;
            }

            $this->logger->log( 'info', sprintf( 'CSV import completed, %d languages created/activated.', $result['count'] ) );

            return $result;
        }
    }
}

if ( ! class_exists( 'NullCsvImporter' ) ) {
    class NullCsvImporter implements CsvImporterInterface {
        public function import_csv( string $file_path ): bool {
            ( new YUZ_Logger() )->log( 'warning', 'CSV importer unavailable' );
            return false;
        }
    }
}

// Note: Remove the add_action('plugins_loaded', ...) here. Instead, in YUZ_Core::init(), after instantiating $settings, $db, $language_manager, $logger:
// $csv_importer = class_exists('YUZ_CSV_Importer') ? new YUZ_CSV_Importer($language_manager, $logger) : new NullCsvImporter();
// add_action('admin_menu', [$csv_importer, 'add_import_page']);
// add_action('admin_post_yuz_import_csv', [$csv_importer, 'handle_import']);

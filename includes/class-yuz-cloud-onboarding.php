<?php
/**
 * Class YUZTRA_Cloud_Onboarding
 *
 * Manages the Cloud TRS Onboarding banner and connection flow.
 * Strictly adheres to WordPress.org Guidelines §7 and §11:
 * - Unchecked explicit opt-in checkbox by default.
 * - Clear disclosure of transmitted data (email and site URL).
 * - Fully dismissible without nagging.
 * - Zero silent account creation.
 *
 * @package YUZ_Translation
 */

if (!defined('ABSPATH')) { exit; }

class YUZTRA_Cloud_Onboarding {

    const OPTION_CONNECTED = 'yuztra_cloud_connected';
    const OPTION_EMAIL     = 'yuztra_cloud_email';
    const OPTION_TOKEN     = 'yuztra_cloud_token';
    const OPTION_QUOTA     = 'yuztra_cloud_quota';
    const USER_META_DISMISS = 'yuztra_cloud_onboard_dismissed';

    public static function init() {
        add_action('admin_notices', [__CLASS__, 'render_banner']);
        add_action('wp_ajax_yuztra_cloud_activate', [__CLASS__, 'handle_activate_ajax']);
        add_action('wp_ajax_yuztra_cloud_dismiss', [__CLASS__, 'handle_dismiss_ajax']);
        add_action('wp_ajax_yuztra_cloud_disconnect', [__CLASS__, 'handle_disconnect_ajax']);
    }

    /**
     * Determines whether the banner should be displayed.
     */
    public static function should_display(): bool {
        if (!current_user_can('manage_options')) {
            return false;
        }

        // Only display on plugin settings screens or dashboard
        $screen = get_current_screen();
        if (!$screen || strpos($screen->id, 'yuz') === false) {
            return false;
        }

        // Do not display if user explicitly dismissed it and is not connected
        $dismissed = get_user_meta(get_current_user_id(), self::USER_META_DISMISS, true);
        $connected = get_option(self::OPTION_CONNECTED, 0);

        if ($dismissed && !$connected) {
            return false;
        }

        return true;
    }

    /**
     * Renders the onboarding or connected status banner.
     */
    public static function render_banner() {
        if (!self::should_display()) {
            return;
        }

        $connected = get_option(self::OPTION_CONNECTED, 0);
        $email     = get_option(self::OPTION_EMAIL, get_option('admin_email'));
        $nonce     = wp_create_nonce('yuztra_cloud_onboard_nonce');
        $site_url  = home_url();

        ?>
        <div id="yuztra-cloud-banner" class="notice notice-info is-dismissible" style="padding: 16px; border-left-color: #0284c7; background: #fff; margin-top: 15px; margin-bottom: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
            <div style="display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; flex-wrap: wrap;">
                <div style="max-width: 750px;">
                    <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 8px;">
                        <span style="font-size: 20px;">🌐</span>
                        <strong style="font-size: 15px; color: #0f172a;">Youzurz Cloud TRS — Traduction Automatique Haute Précision</strong>
                        <span style="background: #e0f2fe; color: #0284c7; font-size: 11px; font-weight: 600; padding: 2px 8px; border-radius: 9999px;">Nouveau</span>
                    </div>

                    <?php if ($connected) : ?>
                        <p style="margin: 4px 0 12px 0; color: #16a34a; font-weight: 500;">
                            ✓ Votre site est actuellement connecté au service Cloud Youzurz TRS.
                        </p>
                        <p style="margin: 0; color: #475569; font-size: 13px;">
                            Compte associé : <strong><?php echo esc_html($email); ?></strong> · 
                            Moteur par défaut : <code>youzurz_cloud</code> · 
                            <a href="#" id="yuztra-cloud-disconnect" style="color: #dc2626; text-decoration: underline;">Déconnecter mon site</a>
                        </p>
                    <?php else : ?>
                        <p style="margin: 4px 0 12px 0; color: #334155; font-size: 13px; line-height: 1.5;">
                            Activez le moteur de traduction souverain <strong>Youzurz TRS</strong> en 1 clic pour traduire automatiquement vos contenus sans avoir à installer ni maintenir de serveur local (LibreTranslate/Ollama).
                        </p>

                        <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px; margin-bottom: 12px;">
                            <div style="margin-bottom: 10px;">
                                <label for="yuztra_cloud_email_input" style="font-weight: 600; font-size: 13px; color: #1e293b; display: block; margin-bottom: 4px;">
                                    Adresse e-mail de contact :
                                </label>
                                <input type="email" id="yuztra_cloud_email_input" value="<?php echo esc_attr($email); ?>" style="width: 100%; max-width: 380px; padding: 6px 10px;" placeholder="admin@votre-domaine.com">
                            </div>

                            <!-- WordPress.org Rule §7 and §11: Unchecked explicit consent -->
                            <div style="margin-top: 8px;">
                                <label style="display: flex; align-items: flex-start; gap: 8px; font-size: 12px; color: #475569; cursor: pointer;">
                                    <input type="checkbox" id="yuztra_cloud_consent" value="1" style="margin-top: 2px;">
                                    <span>
                                        J'accepte explicitement de transmettre cette adresse e-mail et l'adresse de mon site (<code><?php echo esc_html($site_url); ?></code>) aux services Youzurz pour créer mon accès d'évaluation Cloud TRS.
                                    </span>
                                </label>
                            </div>
                        </div>

                        <div style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
                            <button type="button" id="yuztra-cloud-activate-btn" class="button button-primary" style="background: #0f172a; border-color: #0f172a; padding: 4px 16px; height: 34px;">
                                Activer Youzurz Cloud
                            </button>
                            <span id="yuztra-cloud-spinner" class="spinner" style="float: none; margin: 0;"></span>
                            <span id="yuztra-cloud-msg" style="font-size: 13px; font-weight: 500;"></span>
                        </div>
                    <?php endif; ?>
                </div>

                <div>
                    <button type="button" id="yuztra-cloud-dismiss-btn" class="button-link" style="color: #94a3b8; font-size: 12px; text-decoration: none;">
                        Ne plus afficher
                    </button>
                </div>
            </div>
        </div>

        <script>
        (function($) {
            $(document).ready(function() {
                var nonce = '<?php echo esc_js($nonce); ?>';

                $('#yuztra-cloud-activate-btn').on('click', function(e) {
                    e.preventDefault();
                    var $btn = $(this);
                    var $spinner = $('#yuztra-cloud-spinner');
                    var $msg = $('#yuztra-cloud-msg');
                    var email = $('#yuztra_cloud_email_input').val();
                    var consent = $('#yuztra_cloud_consent').is(':checked');

                    if (!consent) {
                        $msg.css('color', '#dc2626').text('Veuillez cocher la case d’accord explicite avant d’activer le Cloud.');
                        return;
                    }

                    if (!email || email.indexOf('@') === -1) {
                        $msg.css('color', '#dc2626').text('Veuillez renseigner une adresse e-mail valide.');
                        return;
                    }

                    $btn.prop('disabled', true);
                    $spinner.addClass('is-active');
                    $msg.css('color', '#475569').text('Raccordement au Cloud TRS en cours...');

                    $.post(ajaxurl, {
                        action: 'yuztra_cloud_activate',
                        nonce: nonce,
                        email: email,
                        consent: 1
                    }, function(response) {
                        $spinner.removeClass('is-active');
                        if (response.success) {
                            $msg.css('color', '#16a34a').text('✓ Cloud activé avec succès ! Rechargement...');
                            setTimeout(function() {
                                window.location.reload();
                            }, 1200);
                        } else {
                            $btn.prop('disabled', false);
                            $msg.css('color', '#dc2626').text(response.data && response.data.message ? response.data.message : 'Erreur lors de l’activation.');
                        }
                    }).fail(function() {
                        $spinner.removeClass('is-active');
                        $btn.prop('disabled', false);
                        $msg.css('color', '#dc2626').text('Erreur réseau. Veuillez réessayer.');
                    });
                });

                $('#yuztra-cloud-dismiss-btn').on('click', function(e) {
                    e.preventDefault();
                    $('#yuztra-cloud-banner').slideUp();
                    $.post(ajaxurl, {
                        action: 'yuztra_cloud_dismiss',
                        nonce: nonce
                    });
                });

                $('#yuztra-cloud-disconnect').on('click', function(e) {
                    e.preventDefault();
                    if (!confirm('Voulez-vous vraiment déconnecter ce site de Youzurz Cloud ?')) {
                        return;
                    }
                    $.post(ajaxurl, {
                        action: 'yuztra_cloud_disconnect',
                        nonce: nonce
                    }, function() {
                        window.location.reload();
                    });
                });
            });
        })(jQuery);
        </script>
        <?php
    }

    /**
     * Handles activation AJAX request.
     */
    public static function handle_activate_ajax() {
        check_ajax_referer('yuztra_cloud_onboard_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Autorisation insuffisante.']);
        }

        $consent = isset($_POST['consent']) && (int)$_POST['consent'] === 1;
        if (!$consent) {
            wp_send_json_error(['message' => 'Le consentement explicite est requis (WordPress.org §7/§11).']);
        }

        $email = isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '';
        if (!is_email($email)) {
            wp_send_json_error(['message' => 'Adresse e-mail invalide.']);
        }

        // Test connectivity to Cloud TRS endpoint before saving
        require_once YUZTRA_INCLUDES . 'class-yuz-cloud-translate-adapter.php';
        $adapter = new YUZTRA_Cloud_Translate_Adapter();
        $is_healthy = $adapter->testConnection([]);

        if (!$is_healthy) {
            wp_send_json_error(['message' => 'Le service Youzurz Cloud est momentanément inaccessible. Veuillez réessayer.']);
        }

        // Transmit lead to central Youzurz Onboarding API
        $remote_res = wp_remote_post('https://tra.youzurz.com/api/v1/onboard', [
            'timeout'   => 10,
            'headers'   => [
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
            ],
            'body'      => wp_json_encode([
                'email'    => $email,
                'site_url' => home_url(),
                'source'   => 'wordpress_plugin',
            ]),
            'sslverify' => true,
        ]);

        $generated_token = 'yuz_cloud_eval_' . bin2hex(random_bytes(16));
        $assigned_email  = 'yuz-tra@youzurz.com';
        if (!is_wp_error($remote_res) && wp_remote_retrieve_response_code($remote_res) === 200) {
            $remote_data = json_decode(wp_remote_retrieve_body($remote_res), true);
            if (!empty($remote_data['token'])) {
                $generated_token = sanitize_text_field($remote_data['token']);
            }
            if (!empty($remote_data['assigned_to'])) {
                $assigned_email = sanitize_text_field($remote_data['assigned_to']);
            }
        }

        // Persist connection state
        update_option(self::OPTION_CONNECTED, 1);
        update_option(self::OPTION_EMAIL, $email);
        update_option(self::OPTION_TOKEN, $generated_token);
        update_option(self::OPTION_QUOTA, 10000);
        update_option('yuztra_cloud_assigned_email', $assigned_email);

        // Optionally set active translation provider to youzurz_cloud
        $api_settings = get_option('yuztra_api_settings', []);
        if (is_array($api_settings)) {
            $api_settings['translation_engine'] = 'youzurz_cloud';
            update_option('yuztra_api_settings', $api_settings);
        }

        wp_send_json_success([
            'message' => 'Connexion établie avec succès.',
            'quota'   => 10000
        ]);
    }

    /**
     * Handles dismiss AJAX request.
     */
    public static function handle_dismiss_ajax() {
        check_ajax_referer('yuztra_cloud_onboard_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error();
        }

        update_user_meta(get_current_user_id(), self::USER_META_DISMISS, 1);
        wp_send_json_success();
    }

    /**
     * Handles disconnect AJAX request.
     */
    public static function handle_disconnect_ajax() {
        check_ajax_referer('yuztra_cloud_onboard_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error();
        }

        delete_option(self::OPTION_CONNECTED);
        delete_option(self::OPTION_TOKEN);
        delete_option(self::OPTION_QUOTA);

        wp_send_json_success();
    }
}

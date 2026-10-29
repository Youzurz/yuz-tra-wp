<?php
/** Explicit administrator diagnostics; no customer content or request metadata. */
defined('ABSPATH') || exit;

class YUZTRA_Rest_Monitoring {
    public static function init(): void {
        if (!(defined('YUZTRA_RUM') && YUZTRA_RUM === true)) return;
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue']);
    }

    public static function enqueue(): void {
        if (!current_user_can('manage_options')) return;
        wp_enqueue_script('yuztra-rum', plugins_url('assets/js/yuz-rum.js', dirname(__DIR__) . '/yuz-tra.php'), [], YUZTRA_VERSION, true);
        $config = ['enabled'=>true, 'endpoint'=>rest_url('yuztra/v1/jslog'), 'nonce'=>wp_create_nonce('wp_rest')];
        wp_add_inline_script('yuztra-rum', 'window.yuztraRum = ' . wp_json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';', 'before');
    }

    public static function permission(WP_REST_Request $request): bool {
        $nonce = $request->get_header('X-WP-Nonce');
        return current_user_can('manage_options') && is_string($nonce) && (bool) wp_verify_nonce($nonce, 'wp_rest');
    }

    public static function register_routes(): void {
        register_rest_route('yuztra/v1', '/jslog', [
            'methods'=>WP_REST_Server::CREATABLE,
            'callback'=>[__CLASS__, 'handle_log'],
            'permission_callback'=>[__CLASS__, 'permission'],
        ]);
    }

    public static function handle_log(WP_REST_Request $request) {
        if (!self::permission($request)) return new WP_REST_Response(['ok'=>false], 403);
        $payload = $request->get_json_params();
        if (!is_array($payload) || count($payload) !== 1 || !is_string($payload['t'] ?? null)
            || !in_array($payload['t'], ['error','promise','csp','switcher'], true)) {
            return new WP_REST_Response(['ok'=>false], 400);
        }
        $key = 'yuztra_rum_' . get_current_user_id();
        $state = get_transient($key);
        $state = is_array($state) && isset($state['count'], $state['reset']) && is_int($state['count']) && is_int($state['reset'])
            ? $state : ['count'=>0, 'reset'=>time()+300];
        if ($state['count'] >= 30) return new WP_REST_Response(['ok'=>false], 429);
        ++$state['count'];
        set_transient($key, $state, max(1, $state['reset']-time()));
        // Bounded category only; IP, UA, referrer and error text are excluded.
        yuztra_debug_log('[YUZ-RUM] ' . $payload['t']);
        return new WP_REST_Response(['ok'=>true], 200);
    }
}

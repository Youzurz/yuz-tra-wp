<?php
defined('ABSPATH') || exit;

/**
 * 30 requêtes / 60s par (user + IP + action)
 */
if (!function_exists('yuztra_rate_limit_or_die')) {
    function yuztra_rate_limit_or_die(string $action) : void {
        $uid = get_current_user_id() ?: 0;
        $ip  = sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'));
        $k   = 'yuztra_rl_' . md5($action.'|'.$uid.'|'.$ip);
        $n   = (int) get_transient($k);
        if ($n >= 30) {
            header('Retry-After: 60', true, 429);
            wp_send_json_error(['message' => 'Too many requests'], 429);
        }
        set_transient($k, $n + 1, 60);
    }
}

/**
 * Exemple d’utilisation dans un handler AJAX :
 *
 * add_action('wp_ajax_yuztra_tm_test_api', function(){
 *   check_ajax_referer('yuz_api_nonce', 'nonce');
 *   if (!current_user_can('yuz_translate_content')) wp_send_json_error(['message'=>'Forbidden'],403);
 *   yuztra_rate_limit_or_die('yuz_tra_tm_test_api');
 *   // ... suite handler ...
 * });
 */

/**
 * Backward-compatible alias for integrations using the historical helper.
 */

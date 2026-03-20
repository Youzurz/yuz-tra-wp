<?php
/**
 * Plugin Name: YUZ Translation
 * Plugin URI: https://youzurz.com/
 * Description: Translation management plugin with language switching and publishing review tools.
 * Version: 1.2.1
 * Author: YUZ
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: yuz_translation
 * Domain Path: /languages
 */

defined('ABSPATH') || exit;

defined('YUZ_TRA_WP_ORG_BUILD') || define('YUZ_TRA_WP_ORG_BUILD', true);
defined('YUZ_TRA_DEBUG') || define('YUZ_TRA_DEBUG', false);
defined('YUZ_TRA_DEBUG_FRONT') || define('YUZ_TRA_DEBUG_FRONT', false);
defined('YUZ_TRA_LOG_DISABLED') || define('YUZ_TRA_LOG_DISABLED', true);
defined('YUZ_TRA_LOG_LEVEL') || define('YUZ_TRA_LOG_LEVEL', 'error');
defined('YUZ_DEBUG') || define('YUZ_DEBUG', false);


require_once __DIR__ . '/includes/class-yuz-plugin.php';

YUZ_Plugin::boot(__FILE__);

if (!defined('YUZ_TRA_DISABLE_FRONT_BUFFER')) {
    define('YUZ_TRA_DISABLE_FRONT_BUFFER', false);
}

if (!function_exists('yuz_tra_override_clar_assets')) {
    /**
     * Force CLAR child theme scripts to fall back to the yuz-cov plugin copies when the theme files are missing.
     *
     * We cannot write to the child theme directory in this environment, so the theme enqueues point to 404 URLs.
     * This filter rewrites the script source at print-time if the theme asset does not exist locally.
     *
     * @param string $src
     * @param string $handle
     * @return string
     */
    function yuz_tra_override_clar_assets(string $src, string $handle): string {
        static $handles = [
            'clar-global'    => 'clar-global.js',
            'clar-skip-link' => 'skip-link.js',
            'clar-page-id'   => 'page-id.js',
        ];

        if (!isset($handles[$handle])) {
            return $src;
        }

        $relative      = $handles[$handle];
        $theme_path    = trailingslashit(get_stylesheet_directory()) . 'assets/js/' . $relative;
        if (file_exists($theme_path)) {
            return $src;
        }

        $plugin_path = trailingslashit(plugin_dir_path(__FILE__)) . 'assets/js/' . $relative;
        if (!file_exists($plugin_path)) {
            return $src;
        }

        $plugin_url = plugins_url('assets/js/' . $relative, __FILE__);
        $version    = @filemtime($plugin_path);
        if ($version) {
            $plugin_url = remove_query_arg('ver', $plugin_url);
            $plugin_url = add_query_arg('ver', $version, $plugin_url);
        }

        return $plugin_url;
    }

    add_filter('script_loader_src', 'yuz_tra_override_clar_assets', 10, 2);
}

if (!function_exists('yuz_tra_disable_front_buffer')) {
    /**
     * Temporary safeguard: disable the front output buffer (translate_page) if requested.
     *
     * This allows us to diagnose layout corruption that might occur during the buffered post-processing.
     * Set the constant YUZ_TRA_DISABLE_FRONT_BUFFER (or filter) to true to deactivate the buffer.
     */
    add_filter(
        'yuz_tra_enable_front_buffer',
        static function ($enabled) {
            if (defined('YUZ_TRA_DISABLE_FRONT_BUFFER') && YUZ_TRA_DISABLE_FRONT_BUFFER) {
                return false;
            }
            if (apply_filters('yuz_tra_force_disable_front_buffer', false)) {
                return false;
            }
            return $enabled;
        },
        0
    );
}

if (!function_exists('yuz_tra_bridge_log')) {
    function yuz_tra_bridge_log(string $message): void {
        if (defined('YUZ_TRA_WP_ORG_BUILD') && YUZ_TRA_WP_ORG_BUILD) {
            return;
        }
        if ((defined('YUZ_TRA_DEBUG') && YUZ_TRA_DEBUG) || (defined('WP_DEBUG') && WP_DEBUG)) {
        }
    }
}

if (!function_exists('yuz_tra_bootstrap_yuz_rem')) {
    /**
     * Ensure YUZ-REM backend is available even if the plugin is not loaded
     * through the normal active-plugins chain.
     */
    function yuz_tra_bootstrap_yuz_rem(string $context = 'plugins_loaded'): void {
        if (!defined('ABSPATH')) {
            return;
        }

        if (!function_exists('yuz_rem_bootstrap')) {
            $rem_file = WP_PLUGIN_DIR . '/yuz-rem/yuz-rem.php';
            if (is_readable($rem_file)) {
                require_once $rem_file;
                yuz_tra_bridge_log('[YUZ-REM-BRIDGE] ' . wp_json_encode([
                    'event' => 'included_from_yuz_tra',
                    'context' => $context,
                    'file' => $rem_file,
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            } else {
                yuz_tra_bridge_log('[YUZ-REM-BRIDGE] ' . wp_json_encode([
                    'event' => 'missing_file',
                    'context' => $context,
                    'file' => $rem_file,
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                return;
            }
        }

        if (function_exists('yuz_rem_bootstrap')) {
            yuz_rem_bootstrap('yuz_tra_bridge_' . $context);
        }
    }

    add_action('plugins_loaded', static function (): void {
        yuz_tra_bootstrap_yuz_rem('plugins_loaded');
    }, 2);

    add_action('init', static function (): void {
        $action = sanitize_key((string)($_REQUEST['action'] ?? ''));
        if ($action !== '' && strpos($action, 'yuz_ra_') === 0) {
            yuz_tra_bootstrap_yuz_rem('init_' . $action);
        }
    }, 0);

    add_action('rest_api_init', static function (): void {
        yuz_tra_bootstrap_yuz_rem('rest_api_init');
    }, 0);
}

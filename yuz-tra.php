<?php
/**
 * Plugin Name: YUZ Translation
 * Plugin URI: https://youzurz.com/
 * Description: Translation management plugin with language switching and publishing review tools.
 * Version: 1.2.1
 * Author: YUZ
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: yuz_tra
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

<?php
/**
 * Plugin Name: YUZ-TRA
 * Plugin URI: https://github.com/Youzurz/yuz-tra-wp
 * Description: Traduction visuelle et catalogue Gettext WordPress, plugins et thèmes, avec relecture, publication et quotas.
 * Version: 1.5.4
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * Author: YOUZURZ (YUZ CLA GPT)
 * Author URI: https://youzurz.com/
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: yuz-tra
 */
defined('ABSPATH') || exit;
require_once __DIR__ . '/includes/class-yuz-plugin.php';
YUZ_Plugin::boot(__FILE__);

if (!defined('YUZ_TRA_DISABLE_FRONT_BUFFER')) define('YUZ_TRA_DISABLE_FRONT_BUFFER', false);
add_filter('yuz_tra_enable_front_buffer', static function ($enabled) {
    if (YUZ_TRA_DISABLE_FRONT_BUFFER || apply_filters('yuz_tra_force_disable_front_buffer', false)) return false;
    return $enabled;
}, 0);

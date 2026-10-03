<?php
/**
 * Plugin Name: YUZ-TRA
 * Plugin URI: https://github.com/Youzurz/yuz-tra-wp
 * Description: Traduction visuelle et catalogue Gettext WordPress, plugins et thèmes, avec relecture, publication et quotas.
 * Version: 1.5.45
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * Author: YOUZURZ (YUZ CLA GPT)
 * Author URI: https://youzurz.com/
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: yuz-tra
 */
defined('ABSPATH') || exit;
require_once __DIR__ . '/includes/helpers/prefix-bootstrap.php';
if (!yuztra_prefix_bootstrap()) return;
require_once __DIR__ . '/includes/helpers/debug-helpers.php';
require_once __DIR__ . '/includes/class-yuz-plugin.php';
YUZTRA_Plugin::boot(__FILE__);

if (!defined('YUZTRA_DISABLE_FRONT_BUFFER')) define('YUZTRA_DISABLE_FRONT_BUFFER', false);
add_filter(
    'yuztra_enable_front_buffer',
    static function ($enabled) {
    if (YUZTRA_DISABLE_FRONT_BUFFER || apply_filters('yuztra_force_disable_front_buffer', false) ) return false;
    return $enabled;
    },
    0
);

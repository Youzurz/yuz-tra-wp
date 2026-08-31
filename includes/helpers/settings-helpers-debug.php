<?php
/**
 * Optional debug loader for settings sanitize helpers.
 *
 * Load this file (e.g. via mu-plugin or wp-config) to enable detailed sanitize
 * traces without altering the production helper.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('YUZ_DEBUG_SANITIZE')) {
    define('YUZ_DEBUG_SANITIZE', true);
}

require_once __DIR__ . '/settings-helpers.php';

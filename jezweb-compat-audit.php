<?php
/**
 * Plugin Name: Jezweb Compatibility Audit
 * Description: Scan themes, child themes and plugins for PHP 8.x compatibility. Jezweb branded with GitHub auto-update.
 * Version: 0.4.0
 * Author: Jezweb
 * License: GPL-2.0-or-later
 */

if (!defined('ABSPATH')) exit;

define('JW_COMPAT_AUDIT_VER', '0.4.0');

// Auto-update checker
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}
if (class_exists('\YahnisElsts\PluginUpdateChecker\v5\PucFactory')) {
    $updateChecker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
        'https://github.com/mmhfarooque/jezweb-compatibility-audit/',
        __FILE__,
        'jezweb-compat-audit'
    );
    $updateChecker->setBranch('main');
}

// Load classes
require_once __DIR__ . '/includes/Auditor.php';
require_once __DIR__ . '/includes/Admin.php';
require_once __DIR__ . '/includes/Api.php';
if (defined('WP_CLI') && WP_CLI) {
    require_once __DIR__ . '/includes/CLI.php';
}

add_action('plugins_loaded', function() {
    \Jezweb\CompatAudit\Admin::init();
});

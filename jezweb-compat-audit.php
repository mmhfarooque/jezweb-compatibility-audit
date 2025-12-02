<?php
/**
 * Plugin Name: Jezweb Compatibility Audit
 * Description: Jezweb-branded PHP 8.x compatibility auditor (themes, child theme, plugins) with per-component results, AJAX batch scanning, WP-CLI commands, REST API, and GitHub auto-updates.
 * Version: 0.5.6
 * Author: Jezweb
 * License: GPL-2.0-or-later
 * Requires PHP: 7.4
 * Requires at least: 5.6
 */

if (!defined('ABSPATH')) {
    exit;
}

define('JW_COMPAT_AUDIT_VER', '0.5.6');
define('JW_COMPAT_AUDIT_DIR', plugin_dir_path(__FILE__));
define('JW_COMPAT_AUDIT_URL', plugin_dir_url(__FILE__));
define('JW_COMPAT_AUDIT_REPORT', WP_CONTENT_DIR . '/compat-report.json');

/**
 * ------------------------------------------------------------
 * Composer autoloader
 * ------------------------------------------------------------
 * Load Composer dependencies including:
 * - Plugin Update Checker (GitHub auto-updates)
 * - PHP_CodeSniffer
 * - PHPCompatibilityWP
 */
$__jw_autoload = JW_COMPAT_AUDIT_DIR . 'vendor/autoload.php';
if (file_exists($__jw_autoload)) {
    require_once $__jw_autoload;
}

/**
 * ------------------------------------------------------------
 * GitHub auto-updates
 * ------------------------------------------------------------
 */
if (class_exists(\YahnisElsts\PluginUpdateChecker\v5\PucFactory::class)) {
    $updateChecker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
        'https://github.com/mmhfarooque/jezweb-compatibility-audit/',
        __FILE__,
        'jezweb-compat-audit'
    );

    // Use GitHub releases for updates (looks for tags like v0.5.0 or 0.5.0)
    $updateChecker->setBranch('main');

    // If you attach a ZIP to the release, it will use that instead of the auto-generated one
    $updateChecker->getVcsApi()->enableReleaseAssets();
}

/**
 * Core plugin includes
 */
require_once JW_COMPAT_AUDIT_DIR . 'includes/Auditor.php';
require_once JW_COMPAT_AUDIT_DIR . 'includes/Admin.php';
require_once JW_COMPAT_AUDIT_DIR . 'includes/Ajax.php';
require_once JW_COMPAT_AUDIT_DIR . 'includes/Api.php';
require_once JW_COMPAT_AUDIT_DIR . 'includes/CLI.php';

/**
 * Bootstrap the plugin
 */
add_action('plugins_loaded', function () {
    // Admin UI
    \Jezweb\CompatAudit\Admin::init();

    // AJAX handlers
    \Jezweb\CompatAudit\Ajax::init();

    // REST API
    \Jezweb\CompatAudit\Api::init();

    // WP-CLI commands
    \Jezweb\CompatAudit\CLI::init();
});

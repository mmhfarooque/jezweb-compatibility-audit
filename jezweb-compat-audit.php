<?php
/**
 * Plugin Name: Jezweb Compatibility Audit
 * Description: Jezweb-branded PHP 8.x compatibility auditor (themes, child theme, plugins) with per-component results and GitHub auto-updates.
 * Version: 0.4.0
 * Author: Jezweb
 * License: GPL-2.0-or-later
 */

if (!defined('ABSPATH')) { exit; }

define('JW_COMPAT_AUDIT_VER', '0.4.0');
define('JW_COMPAT_AUDIT_DIR', plugin_dir_path(__FILE__));
define('JW_COMPAT_AUDIT_URL', plugin_dir_url(__FILE__));
define('JW_COMPAT_AUDIT_REPORT', WP_CONTENT_DIR . '/compat-report.json');

/**
 * ------------------------------------------------------------
 * GitHub auto-updates (Composer OR vendored fallback)
 * ------------------------------------------------------------
 * Priority:
 *  1) Use Composer autoload if present (vendor/autoload.php)
 *  2) Otherwise, load the library directly if it's vendored at:
 *     vendor/plugin-update-checker/plugin-update-checker.php
 */
$__jw_autoload = JW_COMPAT_AUDIT_DIR . 'vendor/autoload.php';
if (file_exists($__jw_autoload)) {
    require_once $__jw_autoload;
} elseif (file_exists(JW_COMPAT_AUDIT_DIR . 'vendor/plugin-update-checker/plugin-update-checker.php')) {
    // Fallback when Plugin Update Checker is included without Composer.
    require_once JW_COMPAT_AUDIT_DIR . 'vendor/plugin-update-checker/plugin-update-checker.php';
}

// Initialize the update checker if available.
if (class_exists(\YahnisElsts\PluginUpdateChecker\v5\PucFactory::class)) {
    $updateChecker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
        'https://github.com/mmhfarooque/jezweb-compatibility-audit/',
        __FILE__,
        'jezweb-compat-audit'
    );
    // Track the default branch for updates.
    $updateChecker->setBranch('main');
}

/**
 * Core plugin includes
 */
require_once JW_COMPAT_AUDIT_DIR . 'includes/Auditor.php';
require_once JW_COMPAT_AUDIT_DIR . 'includes/Admin.php';
require_once JW_COMPAT_AUDIT_DIR . 'includes/Api.php';

// WP-CLI command (optional)
if (defined('WP_CLI') && WP_CLI) {
    require_once JW_COMPAT_AUDIT_DIR . 'includes/CLI.php';
}

/**
 * Bootstrap the admin UI
 */
add_action('plugins_loaded', function () {
    \Jezweb\CompatAudit\Admin::init();
});

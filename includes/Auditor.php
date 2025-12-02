<?php
namespace Jezweb\CompatAudit;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Core auditor class for PHP compatibility scanning
 */
class Auditor {

    /**
     * Run a full audit of all plugins and themes
     *
     * @param string $php_version Target PHP version (e.g., '8.0-', '8.3')
     * @return array Full audit report
     */
    public static function run_audit($php_version = '8.0-') {
        $inventory = self::get_inventory();
        $report = [
            'inventory' => $inventory,
            'phpcs' => ['errors' => 0, 'warnings' => 0],
            'component_summaries' => ['plugins' => [], 'themes' => []],
            'remote_meta' => ['plugins' => [], 'themes' => []],
            'details' => ['plugins' => [], 'themes' => []],
        ];

        // Scan plugins
        foreach ($inventory['plugins'] as $file => $plugin) {
            $slug = dirname($file);
            if ($slug === '.') {
                $slug = basename($file, '.php');
            }

            $path = WP_PLUGIN_DIR . '/' . $file;
            if (is_dir(WP_PLUGIN_DIR . '/' . $slug)) {
                $path = WP_PLUGIN_DIR . '/' . $slug;
            }

            $scan_result = self::scan_component($path, $php_version);
            $summary = self::summarize_scan($scan_result);

            $report['component_summaries']['plugins'][$slug] = $summary;
            $report['details']['plugins'][$slug] = $scan_result;
            $report['phpcs']['errors'] += $summary['errors'];
            $report['phpcs']['warnings'] += $summary['warnings'];

            // Get remote meta from WordPress.org
            $report['remote_meta']['plugins'][$slug] = self::get_remote_meta('plugin', $slug);
        }

        // Scan themes
        foreach (['active', 'parent'] as $theme_key) {
            if (empty($inventory['themes'][$theme_key])) {
                continue;
            }

            $theme = $inventory['themes'][$theme_key];
            $slug = $theme['stylesheet'] ?? $theme['template'] ?? '';
            $path = $theme['stylesheet_dir'] ?? $theme['template_dir'] ?? '';

            if (empty($path) || !is_dir($path)) {
                continue;
            }

            $scan_result = self::scan_component($path, $php_version);
            $summary = self::summarize_scan($scan_result);

            $report['component_summaries']['themes'][$slug] = $summary;
            $report['details']['themes'][$slug] = $scan_result;
            $report['phpcs']['errors'] += $summary['errors'];
            $report['phpcs']['warnings'] += $summary['warnings'];

            // Get remote meta from WordPress.org
            $report['remote_meta']['themes'][$slug] = self::get_remote_meta('theme', $slug);
        }

        // Save the report
        self::save_report($report);

        return $report;
    }

    /**
     * Get inventory of all plugins and themes
     *
     * @return array Inventory data
     */
    public static function get_inventory() {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $plugins = get_plugins();
        $active_plugins = get_option('active_plugins', []);

        $plugins_data = [];
        foreach ($plugins as $file => $plugin) {
            $plugins_data[$file] = [
                'name' => $plugin['Name'],
                'version' => $plugin['Version'],
                'author' => $plugin['Author'],
                'is_active' => in_array($file, $active_plugins, true),
                'requires_php' => $plugin['RequiresPHP'] ?? '',
                'requires_wp' => $plugin['RequiresWP'] ?? '',
            ];
        }

        // Get theme info
        $active_theme = wp_get_theme();
        $themes_data = [
            'active' => [
                'name' => $active_theme->get('Name'),
                'version' => $active_theme->get('Version'),
                'stylesheet' => $active_theme->get_stylesheet(),
                'template' => $active_theme->get_template(),
                'stylesheet_dir' => $active_theme->get_stylesheet_directory(),
                'template_dir' => $active_theme->get_template_directory(),
                'requires_php' => $active_theme->get('RequiresPHP') ?? '',
            ],
        ];

        // Check for parent theme
        if ($active_theme->parent()) {
            $parent = $active_theme->parent();
            $themes_data['parent'] = [
                'name' => $parent->get('Name'),
                'version' => $parent->get('Version'),
                'stylesheet' => $parent->get_stylesheet(),
                'template' => $parent->get_template(),
                'stylesheet_dir' => $parent->get_stylesheet_directory(),
                'template_dir' => $parent->get_template_directory(),
                'requires_php' => $parent->get('RequiresPHP') ?? '',
            ];
        }

        return [
            'generated_at' => current_time('mysql'),
            'site' => [
                'home_url' => home_url(),
                'wp_version' => get_bloginfo('version'),
                'php_version' => PHP_VERSION,
            ],
            'plugins' => $plugins_data,
            'themes' => $themes_data,
        ];
    }

    /**
     * Scan a single component (plugin or theme directory) for PHP compatibility
     *
     * @param string $path Path to scan
     * @param string $php_version Target PHP version
     * @return array|null PHPCS JSON result or null on error
     */
    public static function scan_component($path, $php_version = '8.0-') {
        if (!is_dir($path) && !is_file($path)) {
            return null;
        }

        $phpcs_bin = JW_COMPAT_AUDIT_DIR . 'vendor/bin/phpcs';
        $ruleset = JW_COMPAT_AUDIT_DIR . 'phpcs-compat.xml';

        // Build command
        $cmd = sprintf(
            '%s --standard=%s --runtime-set testVersion %s --report=json --extensions=php %s 2>&1',
            escapeshellcmd($phpcs_bin),
            escapeshellarg($ruleset),
            escapeshellarg($php_version),
            escapeshellarg($path)
        );

        $output = [];
        $exit_code = 0;
        exec($cmd, $output, $exit_code);

        $json_output = implode("\n", $output);
        $result = json_decode($json_output, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return [
                'error' => 'Failed to parse PHPCS output',
                'raw_output' => $json_output,
                'exit_code' => $exit_code,
            ];
        }

        return $result;
    }

    /**
     * Summarize scan results into error/warning counts
     *
     * @param array|null $scan_result PHPCS result
     * @return array Summary with errors and warnings count
     */
    public static function summarize_scan($scan_result) {
        if (empty($scan_result) || isset($scan_result['error'])) {
            return ['errors' => 0, 'warnings' => 0, 'files' => 0];
        }

        return [
            'errors' => $scan_result['totals']['errors'] ?? 0,
            'warnings' => $scan_result['totals']['warnings'] ?? 0,
            'files' => count($scan_result['files'] ?? []),
        ];
    }

    /**
     * Get remote metadata from WordPress.org API
     *
     * @param string $type 'plugin' or 'theme'
     * @param string $slug Component slug
     * @return array Remote metadata
     */
    public static function get_remote_meta($type, $slug) {
        $meta = [
            'version' => '',
            'requires_php' => '',
            'tested' => '',
            'homepage' => '',
            'changelog' => '',
        ];

        if ($type === 'plugin') {
            $url = 'https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&request[slug]=' . urlencode($slug);
        } else {
            $url = 'https://api.wordpress.org/themes/info/1.2/?action=theme_information&request[slug]=' . urlencode($slug);
        }

        $response = wp_remote_get($url, ['timeout' => 10]);

        if (is_wp_error($response)) {
            return $meta;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (empty($data) || isset($data['error'])) {
            return $meta;
        }

        $meta['version'] = $data['version'] ?? '';
        $meta['requires_php'] = $data['requires_php'] ?? '';
        $meta['tested'] = $data['tested'] ?? '';
        $meta['homepage'] = $data['homepage'] ?? '';

        if ($type === 'plugin') {
            $meta['changelog'] = !empty($data['slug'])
                ? 'https://wordpress.org/plugins/' . $data['slug'] . '/#developers'
                : '';
        } else {
            $meta['changelog'] = !empty($data['slug'])
                ? 'https://wordpress.org/themes/' . $data['slug'] . '/'
                : '';
        }

        return $meta;
    }

    /**
     * Save report to JSON file
     *
     * @param array $report Full report data
     * @return bool Success status
     */
    public static function save_report($report) {
        $json = wp_json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        return file_put_contents(JW_COMPAT_AUDIT_REPORT, $json) !== false;
    }

    /**
     * Load existing report from file
     *
     * @return array|null Report data or null if not found
     */
    public static function load_report() {
        if (!file_exists(JW_COMPAT_AUDIT_REPORT)) {
            return null;
        }

        $json = file_get_contents(JW_COMPAT_AUDIT_REPORT);
        return json_decode($json, true);
    }

    /**
     * Get list of components for batch scanning
     *
     * @return array List of components with type, slug, name, and path
     */
    public static function get_scan_queue() {
        $inventory = self::get_inventory();
        $queue = [];

        // Add plugins to queue
        foreach ($inventory['plugins'] as $file => $plugin) {
            $slug = dirname($file);
            if ($slug === '.') {
                $slug = basename($file, '.php');
            }

            $path = WP_PLUGIN_DIR . '/' . $file;
            if (is_dir(WP_PLUGIN_DIR . '/' . $slug)) {
                $path = WP_PLUGIN_DIR . '/' . $slug;
            }

            $queue[] = [
                'type' => 'plugin',
                'slug' => $slug,
                'name' => $plugin['name'],
                'path' => $path,
            ];
        }

        // Add themes to queue
        foreach (['active', 'parent'] as $theme_key) {
            if (empty($inventory['themes'][$theme_key])) {
                continue;
            }

            $theme = $inventory['themes'][$theme_key];
            $slug = $theme['stylesheet'] ?? $theme['template'] ?? '';
            $path = $theme['stylesheet_dir'] ?? $theme['template_dir'] ?? '';

            if (empty($path) || !is_dir($path)) {
                continue;
            }

            $queue[] = [
                'type' => 'theme',
                'slug' => $slug,
                'name' => $theme['name'] . ' (' . ucfirst($theme_key) . ')',
                'path' => $path,
            ];
        }

        return $queue;
    }
}

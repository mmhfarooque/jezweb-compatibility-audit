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
     * Check if exec() function is available
     *
     * @return bool
     */
    public static function is_exec_available() {
        if (!function_exists('exec')) {
            return false;
        }

        $disabled = ini_get('disable_functions');
        if (!empty($disabled)) {
            $disabled_functions = array_map('trim', explode(',', $disabled));
            if (in_array('exec', $disabled_functions, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get diagnostic information for troubleshooting
     *
     * @return array Diagnostic info
     */
    public static function get_diagnostics() {
        // Use the actual PHPCS script path (not composer bin wrapper)
        $phpcs_bin = JW_COMPAT_AUDIT_DIR . 'vendor/squizlabs/php_codesniffer/bin/phpcs';
        $phpcs_bin_fallback = JW_COMPAT_AUDIT_DIR . 'vendor/bin/phpcs';
        $ruleset = JW_COMPAT_AUDIT_DIR . 'phpcs-compat.xml';
        $php_bin = self::find_php_binary();

        // Use direct path if available, otherwise fallback
        if (!file_exists($phpcs_bin)) {
            $phpcs_bin = $phpcs_bin_fallback;
        }

        // Check which PHP paths exist - comprehensive search for LiteSpeed and other hosts
        $php_paths_checked = [];
        $check_paths = [
            // LiteSpeed paths
            '/usr/local/lsws/lsphp74/bin/php',
            '/usr/local/lsws/lsphp80/bin/php',
            '/usr/local/lsws/lsphp81/bin/php',
            '/usr/local/lsws/lsphp82/bin/php',
            '/usr/local/lsws/lsphp83/bin/php',
            // Standard CLI
            '/usr/bin/php-cli',
            '/usr/bin/php',
            '/usr/local/bin/php-cli',
            '/usr/local/bin/php',
            // CloudLinux
            '/opt/alt/php74/usr/bin/php',
            '/opt/alt/php80/usr/bin/php',
            '/opt/alt/php81/usr/bin/php',
            '/opt/alt/php82/usr/bin/php',
            '/opt/alt/php83/usr/bin/php',
        ];
        foreach ($check_paths as $p) {
            if (file_exists($p)) {
                // Test if it's CLI
                $test_out = [];
                exec(escapeshellcmd($p) . ' -v 2>&1', $test_out);
                $test_str = implode(' ', $test_out);
                if (strpos($test_str, '(cli)') !== false) {
                    $php_paths_checked[$p] = 'CLI';
                } elseif (strpos($test_str, '(cgi') !== false) {
                    $php_paths_checked[$p] = 'CGI (not usable)';
                } elseif (strpos($test_str, 'litespeed') !== false) {
                    $php_paths_checked[$p] = 'LiteSpeed (not usable)';
                } else {
                    $php_paths_checked[$p] = 'exists (unknown type)';
                }
            }
        }

        // Also try to find php binaries using locate or find
        $find_output = [];
        exec('find /usr/local/lsws -name "php" -type f 2>/dev/null | head -10', $find_output);
        if (!empty($find_output)) {
            $php_paths_checked['lsws_found'] = $find_output;
        }

        $diagnostics = [
            'exec_available' => self::is_exec_available(),
            'php_binary' => $php_bin ?: 'NOT FOUND - no working PHP CLI found',
            'php_binary_constant' => defined('PHP_BINARY') ? PHP_BINARY : 'NOT DEFINED',
            'php_paths_checked' => $php_paths_checked,
            'phpcs_exists' => file_exists($phpcs_bin),
            'phpcs_path' => $phpcs_bin,
            'ruleset_exists' => file_exists($ruleset),
            'ruleset_path' => $ruleset,
            'plugin_dir' => JW_COMPAT_AUDIT_DIR,
        ];

        // Try a test exec if available
        if (self::is_exec_available() && $php_bin) {
            $test_output = [];
            $test_code = 0;
            exec(escapeshellcmd($php_bin) . ' -v 2>&1', $test_output, $test_code);
            $diagnostics['php_version_output'] = implode("\n", array_slice($test_output, 0, 2));
            $diagnostics['php_exec_code'] = $test_code;

            // Test PHPCS itself
            if (file_exists($phpcs_bin)) {
                $phpcs_output = [];
                $phpcs_code = 0;
                exec(escapeshellcmd($php_bin) . ' ' . escapeshellarg($phpcs_bin) . ' --version 2>&1', $phpcs_output, $phpcs_code);
                $diagnostics['phpcs_version_output'] = implode("\n", $phpcs_output);
                $diagnostics['phpcs_exec_code'] = $phpcs_code;
            }

            // Test actual scan on the plugin's own main file
            if (file_exists($phpcs_bin) && file_exists($ruleset)) {
                $test_file = JW_COMPAT_AUDIT_DIR . 'jezweb-compat-audit.php';
                $scan_cmd = sprintf(
                    '%s -d memory_limit=256M %s --standard=%s --runtime-set testVersion %s --report=json --extensions=php %s 2>&1',
                    escapeshellcmd($php_bin),
                    escapeshellarg($phpcs_bin),
                    escapeshellarg($ruleset),
                    escapeshellarg('8.3'),
                    escapeshellarg($test_file)
                );
                $scan_output = [];
                $scan_code = 0;
                exec($scan_cmd, $scan_output, $scan_code);
                $scan_json = implode("\n", $scan_output);

                $diagnostics['test_scan_command'] = $scan_cmd;
                $diagnostics['test_scan_exit_code'] = $scan_code;
                $diagnostics['test_scan_output_length'] = strlen($scan_json);
                $diagnostics['test_scan_output_preview'] = substr($scan_json, 0, 300);

                // Try to parse it
                $json_start = strpos($scan_json, '{');
                if ($json_start !== false) {
                    $diagnostics['test_scan_json_found'] = true;
                    $diagnostics['test_scan_json_start_pos'] = $json_start;
                } else {
                    $diagnostics['test_scan_json_found'] = false;
                }
            }
        }

        return $diagnostics;
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
            return ['error' => 'Path not found or not accessible.'];
        }

        // Check if exec is available
        if (!self::is_exec_available()) {
            return [
                'error' => 'The exec() PHP function is disabled. PHPCS scanning requires exec() to be enabled.',
                'totals' => ['errors' => 0, 'warnings' => 0],
                'files' => [],
            ];
        }

        // Use the actual PHPCS script, not the Composer bin wrapper
        // The bin wrapper causes issues with LiteSpeed PHP (lsphp)
        $phpcs_bin = JW_COMPAT_AUDIT_DIR . 'vendor/squizlabs/php_codesniffer/bin/phpcs';
        $ruleset = JW_COMPAT_AUDIT_DIR . 'phpcs-compat.xml';

        // Validate PHPCS binary exists
        if (!file_exists($phpcs_bin)) {
            // Fallback to composer bin if direct path doesn't exist
            $phpcs_bin = JW_COMPAT_AUDIT_DIR . 'vendor/bin/phpcs';
            if (!file_exists($phpcs_bin)) {
                return [
                    'error' => 'PHPCS binary not found. Please run composer install.',
                    'totals' => ['errors' => 0, 'warnings' => 0],
                    'files' => [],
                ];
            }
        }

        // Validate ruleset exists
        if (!file_exists($ruleset)) {
            return [
                'error' => 'PHPCS ruleset not found.',
                'totals' => ['errors' => 0, 'warnings' => 0],
                'files' => [],
            ];
        }

        // Find PHP binary - try multiple common locations
        $php_bin = self::find_php_binary();
        if (!$php_bin) {
            return [
                'error' => 'PHP binary not found. Cannot execute PHPCS.',
                'totals' => ['errors' => 0, 'warnings' => 0],
                'files' => [],
            ];
        }

        // Build command - run phpcs via PHP explicitly for better compatibility
        // This avoids issues with shebang lines on shared hosting
        // Add memory limit and ignore warnings to ensure clean JSON output
        $cmd = sprintf(
            '%s -d memory_limit=256M %s --standard=%s --runtime-set testVersion %s --report=json --extensions=php %s 2>&1',
            escapeshellcmd($php_bin),
            escapeshellarg($phpcs_bin),
            escapeshellarg($ruleset),
            escapeshellarg($php_version),
            escapeshellarg($path)
        );

        $output = [];
        $exit_code = 0;
        exec($cmd, $output, $exit_code);

        $json_output = implode("\n", $output);

        // Check for empty output
        if (empty(trim($json_output))) {
            return [
                'error' => 'PHPCS returned empty output (possible timeout or no PHP files found)',
                'exit_code' => $exit_code,
                'totals' => ['errors' => 0, 'warnings' => 0],
                'files' => [],
            ];
        }

        // PHPCS may output warnings/errors before JSON - try to extract just the JSON part
        $json_start = strpos($json_output, '{');
        if ($json_start === false) {
            // No JSON found at all - return the raw output as error
            return [
                'error' => 'PHPCS did not return JSON output',
                'raw_output' => substr($json_output, 0, 500),
                'exit_code' => $exit_code,
                'totals' => ['errors' => 0, 'warnings' => 0],
                'files' => [],
            ];
        }

        if ($json_start > 0) {
            $json_output = substr($json_output, $json_start);
        }

        $result = json_decode($json_output, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return [
                'error' => 'Failed to parse PHPCS output: ' . json_last_error_msg(),
                'raw_output' => substr($json_output, 0, 500),
                'exit_code' => $exit_code,
            ];
        }

        return $result;
    }

    /**
     * Find the PHP CLI binary path
     *
     * @return string|false Path to PHP CLI binary or false if not found
     */
    public static function find_php_binary() {
        // Common PHP CLI binary locations
        // Note: We need ACTUAL CLI php, not cgi-fcgi or lsphp
        $possible_paths = [
            // cPanel EA-PHP CLI binaries (most reliable on cPanel)
            '/opt/cpanel/ea-php83/root/usr/bin/php-cli',
            '/opt/cpanel/ea-php82/root/usr/bin/php-cli',
            '/opt/cpanel/ea-php81/root/usr/bin/php-cli',
            '/opt/cpanel/ea-php80/root/usr/bin/php-cli',
            '/opt/cpanel/ea-php74/root/usr/bin/php-cli',
            // CloudLinux alt-php CLI
            '/opt/alt/php83/usr/bin/php-cli',
            '/opt/alt/php82/usr/bin/php-cli',
            '/opt/alt/php81/usr/bin/php-cli',
            '/opt/alt/php80/usr/bin/php-cli',
            '/opt/alt/php74/usr/bin/php-cli',
            // Standard CLI locations
            '/usr/bin/php-cli',
            '/usr/local/bin/php-cli',
            // LiteSpeed CLI binaries
            '/usr/local/lsws/lsphp83/bin/php-cli',
            '/usr/local/lsws/lsphp82/bin/php-cli',
            '/usr/local/lsws/lsphp81/bin/php-cli',
            '/usr/local/lsws/lsphp80/bin/php-cli',
            '/usr/local/lsws/lsphp74/bin/php-cli',
            '/usr/local/lsws/lsphp83/bin/php',
            '/usr/local/lsws/lsphp82/bin/php',
            '/usr/local/lsws/lsphp81/bin/php',
            '/usr/local/lsws/lsphp80/bin/php',
            '/usr/local/lsws/lsphp74/bin/php',
            // Plesk
            '/opt/plesk/php/8.3/bin/php',
            '/opt/plesk/php/8.2/bin/php',
            '/opt/plesk/php/8.1/bin/php',
            '/opt/plesk/php/8.0/bin/php',
            '/opt/plesk/php/7.4/bin/php',
            // Standard locations (check last as they might be CGI)
            '/usr/bin/php',
            '/usr/local/bin/php',
            // Versioned binaries
            '/usr/bin/php8.3',
            '/usr/bin/php8.2',
            '/usr/bin/php8.1',
            '/usr/bin/php8.0',
            '/usr/bin/php7.4',
        ];

        foreach ($possible_paths as $path) {
            if (file_exists($path) && is_executable($path)) {
                // Verify it's actually a CLI PHP (not CGI/FastCGI/lsphp)
                $test_output = [];
                $test_code = 0;
                exec(escapeshellcmd($path) . ' -v 2>&1', $test_output, $test_code);
                $output_str = implode("\n", $test_output);

                // Valid PHP CLI will output version info starting with "PHP"
                // and should contain "(cli)" in the version string
                // CGI/FastCGI outputs "Content-type:" header first
                if ($test_code === 0 &&
                    strpos($output_str, 'PHP') === 0 &&
                    strpos($output_str, 'Content-type:') === false &&
                    (strpos($output_str, '(cli)') !== false || strpos($output_str, 'cli') !== false)) {
                    return $path;
                }
            }
        }

        // Try 'which php-cli' first
        $which_output = [];
        exec('which php-cli 2>/dev/null', $which_output);
        if (!empty($which_output[0]) && is_executable($which_output[0])) {
            return $which_output[0];
        }

        // Try 'which php' but verify it's CLI
        $which_output = [];
        exec('which php 2>/dev/null', $which_output);
        if (!empty($which_output[0]) && is_executable($which_output[0])) {
            $test_output = [];
            $test_code = 0;
            exec(escapeshellcmd($which_output[0]) . ' -v 2>&1', $test_output, $test_code);
            $output_str = implode("\n", $test_output);
            if ($test_code === 0 &&
                strpos($output_str, 'PHP') === 0 &&
                strpos($output_str, 'Content-type:') === false &&
                strpos($output_str, '(cli)') !== false) {
                return $which_output[0];
            }
        }

        return false;
    }

    /**
     * Summarize scan results into error/warning counts
     *
     * @param array|null $scan_result PHPCS result
     * @return array Summary with errors, warnings count, and scan status
     */
    public static function summarize_scan($scan_result) {
        // If scan failed or returned an error, mark it as FAILED - not PASS!
        if (empty($scan_result)) {
            return [
                'errors' => 0,
                'warnings' => 0,
                'files' => 0,
                'scan_status' => 'failed',
                'scan_error' => 'Scan returned empty result',
            ];
        }

        if (isset($scan_result['error'])) {
            $error_msg = $scan_result['error'];
            // Append raw_output if available for more context
            if (!empty($scan_result['raw_output'])) {
                $error_msg .= ' | Output: ' . substr($scan_result['raw_output'], 0, 200);
            }
            return [
                'errors' => 0,
                'warnings' => 0,
                'files' => 0,
                'scan_status' => 'failed',
                'scan_error' => $error_msg,
            ];
        }

        return [
            'errors' => $scan_result['totals']['errors'] ?? 0,
            'warnings' => $scan_result['totals']['warnings'] ?? 0,
            'files' => count($scan_result['files'] ?? []),
            'scan_status' => 'success',
            'scan_error' => null,
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

        // Initialize WP_Filesystem
        global $wp_filesystem;
        if (empty($wp_filesystem)) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            WP_Filesystem();
        }

        if ($wp_filesystem) {
            return $wp_filesystem->put_contents(JW_COMPAT_AUDIT_REPORT, $json, FS_CHMOD_FILE);
        }

        // Fallback with proper permissions
        return file_put_contents(JW_COMPAT_AUDIT_REPORT, $json, LOCK_EX) !== false;
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

        // Initialize WP_Filesystem
        global $wp_filesystem;
        if (empty($wp_filesystem)) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            WP_Filesystem();
        }

        if ($wp_filesystem && $wp_filesystem->exists(JW_COMPAT_AUDIT_REPORT)) {
            $json = $wp_filesystem->get_contents(JW_COMPAT_AUDIT_REPORT);
        } else {
            // Fallback
            $json = file_get_contents(JW_COMPAT_AUDIT_REPORT);
        }

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

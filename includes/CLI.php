<?php
namespace Jezweb\CompatAudit;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * WP-CLI commands for Jezweb Compatibility Audit
 */
class CLI {

    /**
     * Register CLI commands
     */
    public static function init() {
        if (defined('WP_CLI') && \WP_CLI) {
            \WP_CLI::add_command('jw-compat', __CLASS__);
        }
    }

    /**
     * Run a full PHP compatibility audit
     *
     * ## OPTIONS
     *
     * [--php=<version>]
     * : PHP version to check compatibility against.
     * ---
     * default: 8.0-
     * options:
     *   - 8.0-
     *   - 8.1-
     *   - 8.2-
     *   - 8.3-
     *   - 8.3
     *   - 8.4-
     * ---
     *
     * [--format=<format>]
     * : Output format.
     * ---
     * default: table
     * options:
     *   - table
     *   - json
     *   - csv
     *   - yaml
     * ---
     *
     * [--skip-remote]
     * : Skip fetching remote metadata from WordPress.org
     *
     * ## EXAMPLES
     *
     *     # Run audit for PHP 8.3+
     *     $ wp jw-compat audit --php=8.3-
     *
     *     # Run audit and output as JSON
     *     $ wp jw-compat audit --format=json
     *
     *     # Quick audit without remote API calls
     *     $ wp jw-compat audit --skip-remote
     *
     * @when after_wp_load
     */
    public function audit($args, $assoc_args) {
        $php_version = $assoc_args['php'] ?? '8.0-';
        $format = $assoc_args['format'] ?? 'table';
        $skip_remote = isset($assoc_args['skip-remote']);

        \WP_CLI::log("Starting PHP {$php_version} compatibility audit...");
        \WP_CLI::log('');

        // Get inventory
        $inventory = Auditor::get_inventory();
        $queue = Auditor::get_scan_queue();

        if (empty($queue)) {
            \WP_CLI::warning('No components found to scan.');
            return;
        }

        // Create progress bar
        $progress = \WP_CLI\Utils\make_progress_bar('Scanning components', count($queue));

        $results = [];
        $total_errors = 0;
        $total_warnings = 0;

        foreach ($queue as $component) {
            $scan_result = Auditor::scan_component($component['path'], $php_version);
            $summary = Auditor::summarize_scan($scan_result);

            $status = 'PASS';
            if (($summary['errors'] ?? 0) > 0) {
                $status = 'FAIL';
            } elseif (($summary['warnings'] ?? 0) > 0) {
                $status = 'WARN';
            }

            $remote_meta = [];
            if (!$skip_remote) {
                $remote_meta = Auditor::get_remote_meta($component['type'], $component['slug']);
            }

            $results[] = [
                'type' => ucfirst($component['type']),
                'name' => $component['name'],
                'slug' => $component['slug'],
                'status' => $status,
                'errors' => $summary['errors'] ?? 0,
                'warnings' => $summary['warnings'] ?? 0,
                'requires_php' => $remote_meta['requires_php'] ?? '—',
                'tested' => $remote_meta['tested'] ?? '—',
            ];

            $total_errors += $summary['errors'] ?? 0;
            $total_warnings += $summary['warnings'] ?? 0;

            $progress->tick();
        }

        $progress->finish();
        \WP_CLI::log('');

        // Output results
        if ($format === 'json') {
            \WP_CLI::log(json_encode([
                'summary' => [
                    'total_components' => count($results),
                    'total_errors' => $total_errors,
                    'total_warnings' => $total_warnings,
                    'php_version' => $php_version,
                ],
                'results' => $results,
            ], JSON_PRETTY_PRINT));
        } else {
            $fields = ['type', 'name', 'status', 'errors', 'warnings', 'requires_php', 'tested'];
            \WP_CLI\Utils\format_items($format, $results, $fields);

            \WP_CLI::log('');
            \WP_CLI::log("Summary: {$total_errors} errors, {$total_warnings} warnings across " . count($results) . " components");
        }

        // Final status
        if ($total_errors > 0) {
            \WP_CLI::error("Audit completed with {$total_errors} errors.", false);
        } elseif ($total_warnings > 0) {
            \WP_CLI::warning("Audit completed with {$total_warnings} warnings.");
        } else {
            \WP_CLI::success('All components passed PHP compatibility check!');
        }
    }

    /**
     * Scan a specific path for PHP compatibility
     *
     * ## OPTIONS
     *
     * <path>
     * : Path to scan (plugin or theme directory)
     *
     * [--php=<version>]
     * : PHP version to check compatibility against.
     * ---
     * default: 8.0-
     * ---
     *
     * [--format=<format>]
     * : Output format.
     * ---
     * default: table
     * options:
     *   - table
     *   - json
     * ---
     *
     * ## EXAMPLES
     *
     *     # Scan a specific plugin
     *     $ wp jw-compat scan /var/www/html/wp-content/plugins/my-plugin
     *
     *     # Scan with JSON output
     *     $ wp jw-compat scan /path/to/plugin --format=json
     *
     * @when after_wp_load
     */
    public function scan($args, $assoc_args) {
        $path = $args[0];
        $php_version = $assoc_args['php'] ?? '8.0-';
        $format = $assoc_args['format'] ?? 'table';

        if (!file_exists($path)) {
            \WP_CLI::error("Path not found: {$path}");
        }

        \WP_CLI::log("Scanning {$path} for PHP {$php_version} compatibility...");
        \WP_CLI::log('');

        $result = Auditor::scan_component($path, $php_version);

        if (isset($result['error'])) {
            \WP_CLI::error($result['error']);
        }

        $summary = Auditor::summarize_scan($result);

        if ($format === 'json') {
            \WP_CLI::log(json_encode($result, JSON_PRETTY_PRINT));
        } else {
            // Display summary
            \WP_CLI::log("Errors: {$summary['errors']}");
            \WP_CLI::log("Warnings: {$summary['warnings']}");
            \WP_CLI::log("Files scanned: {$summary['files']}");
            \WP_CLI::log('');

            // Display issues
            $files = $result['files'] ?? [];
            if (!empty($files)) {
                $issues = [];
                foreach ($files as $file_path => $file_data) {
                    foreach ($file_data['messages'] ?? [] as $msg) {
                        $short_path = implode('/', array_slice(explode('/', $file_path), -2));
                        $issues[] = [
                            'file' => $short_path,
                            'line' => $msg['line'],
                            'type' => $msg['type'],
                            'message' => substr($msg['message'], 0, 80),
                        ];
                    }
                }

                if (!empty($issues)) {
                    \WP_CLI\Utils\format_items('table', $issues, ['file', 'line', 'type', 'message']);
                }
            }
        }

        if ($summary['errors'] > 0) {
            \WP_CLI::error("Found {$summary['errors']} errors.", false);
        } elseif ($summary['warnings'] > 0) {
            \WP_CLI::warning("Found {$summary['warnings']} warnings.");
        } else {
            \WP_CLI::success('No compatibility issues found!');
        }
    }

    /**
     * Display the last audit report
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : Output format.
     * ---
     * default: table
     * options:
     *   - table
     *   - json
     * ---
     *
     * ## EXAMPLES
     *
     *     # Show last report
     *     $ wp jw-compat report
     *
     *     # Show last report as JSON
     *     $ wp jw-compat report --format=json
     *
     * @when after_wp_load
     */
    public function report($args, $assoc_args) {
        $format = $assoc_args['format'] ?? 'table';

        $report = Auditor::load_report();

        if (empty($report)) {
            \WP_CLI::error('No report found. Run `wp jw-compat audit` first.');
        }

        if ($format === 'json') {
            \WP_CLI::log(json_encode($report, JSON_PRETTY_PRINT));
            return;
        }

        // Display summary
        \WP_CLI::log('=== Last Audit Report ===');
        \WP_CLI::log('');
        \WP_CLI::log("Generated: " . ($report['inventory']['generated_at'] ?? 'Unknown'));
        \WP_CLI::log("Site: " . ($report['inventory']['site']['home_url'] ?? 'Unknown'));
        \WP_CLI::log("WordPress: " . ($report['inventory']['site']['wp_version'] ?? 'Unknown'));
        \WP_CLI::log("PHP: " . ($report['inventory']['site']['php_version'] ?? 'Unknown'));
        \WP_CLI::log("Tested for: " . ($report['php_version_tested'] ?? 'PHP 8.0+'));
        \WP_CLI::log('');
        \WP_CLI::log("Total Errors: " . ($report['phpcs']['errors'] ?? 0));
        \WP_CLI::log("Total Warnings: " . ($report['phpcs']['warnings'] ?? 0));
        \WP_CLI::log('');

        // Build results table
        $results = [];

        foreach (($report['inventory']['plugins'] ?? []) as $file => $plugin) {
            $slug = dirname($file);
            if ($slug === '.') {
                $slug = basename($file, '.php');
            }
            $summary = $report['component_summaries']['plugins'][$slug] ?? ['errors' => 0, 'warnings' => 0];
            $status = ($summary['errors'] ?? 0) > 0 ? 'FAIL' : (($summary['warnings'] ?? 0) > 0 ? 'WARN' : 'PASS');

            $results[] = [
                'type' => 'Plugin',
                'name' => $plugin['name'],
                'status' => $status,
                'errors' => $summary['errors'] ?? 0,
                'warnings' => $summary['warnings'] ?? 0,
            ];
        }

        foreach (['active', 'parent'] as $key) {
            if (empty($report['inventory']['themes'][$key])) {
                continue;
            }
            $theme = $report['inventory']['themes'][$key];
            $slug = $theme['stylesheet'] ?? '';
            $summary = $report['component_summaries']['themes'][$slug] ?? ['errors' => 0, 'warnings' => 0];
            $status = ($summary['errors'] ?? 0) > 0 ? 'FAIL' : (($summary['warnings'] ?? 0) > 0 ? 'WARN' : 'PASS');

            $results[] = [
                'type' => 'Theme',
                'name' => $theme['name'] . ' (' . ucfirst($key) . ')',
                'status' => $status,
                'errors' => $summary['errors'] ?? 0,
                'warnings' => $summary['warnings'] ?? 0,
            ];
        }

        \WP_CLI\Utils\format_items('table', $results, ['type', 'name', 'status', 'errors', 'warnings']);
    }

    /**
     * Get the inventory of plugins and themes
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : Output format.
     * ---
     * default: table
     * options:
     *   - table
     *   - json
     *   - csv
     * ---
     *
     * ## EXAMPLES
     *
     *     $ wp jw-compat inventory
     *     $ wp jw-compat inventory --format=json
     *
     * @when after_wp_load
     */
    public function inventory($args, $assoc_args) {
        $format = $assoc_args['format'] ?? 'table';

        $inventory = Auditor::get_inventory();

        if ($format === 'json') {
            \WP_CLI::log(json_encode($inventory, JSON_PRETTY_PRINT));
            return;
        }

        // Build items list
        $items = [];

        foreach ($inventory['plugins'] as $file => $plugin) {
            $items[] = [
                'type' => 'Plugin',
                'name' => $plugin['name'],
                'version' => $plugin['version'],
                'active' => $plugin['is_active'] ? 'Yes' : 'No',
                'requires_php' => $plugin['requires_php'] ?: '—',
            ];
        }

        foreach (['active', 'parent'] as $key) {
            if (empty($inventory['themes'][$key])) {
                continue;
            }
            $theme = $inventory['themes'][$key];
            $items[] = [
                'type' => 'Theme',
                'name' => $theme['name'] . ' (' . ucfirst($key) . ')',
                'version' => $theme['version'],
                'active' => $key === 'active' ? 'Yes' : 'No',
                'requires_php' => $theme['requires_php'] ?: '—',
            ];
        }

        \WP_CLI\Utils\format_items($format, $items, ['type', 'name', 'version', 'active', 'requires_php']);
    }
}

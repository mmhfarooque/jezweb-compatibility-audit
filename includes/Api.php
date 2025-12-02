<?php
namespace Jezweb\CompatAudit;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * REST API endpoints for Jezweb Compatibility Audit
 */
class Api {

    /**
     * API namespace
     */
    const NAMESPACE = 'jezweb/v1';

    /**
     * Initialize REST API
     */
    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
    }

    /**
     * Register REST routes
     */
    public static function register_routes() {
        // Get/Run audit
        register_rest_route(self::NAMESPACE, '/audit', [
            [
                'methods' => \WP_REST_Server::READABLE,
                'callback' => [__CLASS__, 'get_report'],
                'permission_callback' => [__CLASS__, 'check_admin_permission'],
            ],
            [
                'methods' => \WP_REST_Server::CREATABLE,
                'callback' => [__CLASS__, 'run_audit'],
                'permission_callback' => [__CLASS__, 'check_admin_permission'],
                'args' => [
                    'php_version' => [
                        'type' => 'string',
                        'default' => '8.0-',
                        'sanitize_callback' => 'sanitize_text_field',
                        'validate_callback' => function($value) {
                            return in_array($value, ['8.0-', '8.1-', '8.2-', '8.3-', '8.3', '8.4-'], true);
                        },
                    ],
                ],
            ],
        ]);

        // Get inventory
        register_rest_route(self::NAMESPACE, '/inventory', [
            'methods' => \WP_REST_Server::READABLE,
            'callback' => [__CLASS__, 'get_inventory'],
            'permission_callback' => [__CLASS__, 'check_admin_permission'],
        ]);

        // Scan specific component
        register_rest_route(self::NAMESPACE, '/scan', [
            'methods' => \WP_REST_Server::CREATABLE,
            'callback' => [__CLASS__, 'scan_component'],
            'permission_callback' => [__CLASS__, 'check_admin_permission'],
            'args' => [
                'path' => [
                    'type' => 'string',
                    'required' => true,
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'php_version' => [
                    'type' => 'string',
                    'default' => '8.0-',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);

        // Get scan queue
        register_rest_route(self::NAMESPACE, '/queue', [
            'methods' => \WP_REST_Server::READABLE,
            'callback' => [__CLASS__, 'get_queue'],
            'permission_callback' => [__CLASS__, 'check_admin_permission'],
        ]);
    }

    /**
     * Check if user has admin permission
     */
    public static function check_admin_permission() {
        return current_user_can('manage_options');
    }

    /**
     * Get the latest audit report
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public static function get_report(\WP_REST_Request $request) {
        $report = Auditor::load_report();

        if (empty($report)) {
            return new \WP_Error(
                'jw_compat_no_report',
                'No audit report found. Run an audit first.',
                ['status' => 404]
            );
        }

        return new \WP_REST_Response([
            'success' => true,
            'data' => $report,
        ], 200);
    }

    /**
     * Run a full audit
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response
     */
    public static function run_audit(\WP_REST_Request $request) {
        $php_version = $request->get_param('php_version') ?? '8.0-';

        // Run the audit
        $report = Auditor::run_audit($php_version);

        return new \WP_REST_Response([
            'success' => true,
            'message' => 'Audit completed successfully.',
            'summary' => [
                'total_errors' => $report['phpcs']['errors'] ?? 0,
                'total_warnings' => $report['phpcs']['warnings'] ?? 0,
                'php_version' => $php_version,
                'generated_at' => $report['inventory']['generated_at'] ?? '',
            ],
            'data' => $report,
        ], 200);
    }

    /**
     * Get inventory of plugins and themes
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response
     */
    public static function get_inventory(\WP_REST_Request $request) {
        $inventory = Auditor::get_inventory();

        return new \WP_REST_Response([
            'success' => true,
            'data' => $inventory,
        ], 200);
    }

    /**
     * Scan a specific component
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public static function scan_component(\WP_REST_Request $request) {
        $path = $request->get_param('path');
        $php_version = $request->get_param('php_version') ?? '8.0-';

        // Check for directory traversal attempts
        if (preg_match('/\.\./', $path)) {
            return new \WP_Error(
                'jw_compat_invalid_path',
                'Invalid path format.',
                ['status' => 400]
            );
        }

        // Validate path is within WordPress
        $real_path = realpath($path);
        $wp_content = realpath(WP_CONTENT_DIR);

        if ($real_path === false) {
            return new \WP_Error(
                'jw_compat_invalid_path',
                'Path not found.',
                ['status' => 400]
            );
        }

        if ($wp_content === false || strpos($real_path, $wp_content) !== 0) {
            return new \WP_Error(
                'jw_compat_path_not_allowed',
                'Path must be within wp-content directory.',
                ['status' => 403]
            );
        }

        $result = Auditor::scan_component($path, $php_version);
        $summary = Auditor::summarize_scan($result);

        $status = 'PASS';
        if (($summary['errors'] ?? 0) > 0) {
            $status = 'FAIL';
        } elseif (($summary['warnings'] ?? 0) > 0) {
            $status = 'WARN';
        }

        return new \WP_REST_Response([
            'success' => true,
            'path' => $path,
            'php_version' => $php_version,
            'status' => $status,
            'summary' => $summary,
            'details' => $result,
        ], 200);
    }

    /**
     * Get scan queue (list of components)
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response
     */
    public static function get_queue(\WP_REST_Request $request) {
        $queue = Auditor::get_scan_queue();

        return new \WP_REST_Response([
            'success' => true,
            'total' => count($queue),
            'queue' => $queue,
        ], 200);
    }
}

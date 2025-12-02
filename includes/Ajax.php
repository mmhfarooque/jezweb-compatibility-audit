<?php
namespace Jezweb\CompatAudit;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * AJAX handlers for batch processing compatibility audits
 */
class Ajax {

    /**
     * Initialize AJAX handlers
     */
    public static function init() {
        add_action('wp_ajax_jw_compat_start_audit', [__CLASS__, 'start_audit']);
        add_action('wp_ajax_jw_compat_scan_component', [__CLASS__, 'scan_component']);
        add_action('wp_ajax_jw_compat_finalize_audit', [__CLASS__, 'finalize_audit']);
    }

    /**
     * Start audit - returns list of components to scan
     */
    public static function start_audit() {
        check_ajax_referer('jw_compat_audit', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied.']);
        }

        $php_version = isset($_POST['php_version']) ? sanitize_text_field($_POST['php_version']) : '8.0-';

        // Validate PHP version
        if (!in_array($php_version, ['8.0-', '8.1-', '8.2-', '8.3-', '8.3', '8.4-'], true)) {
            $php_version = '8.0-';
        }

        // Get inventory and queue
        $inventory = Auditor::get_inventory();
        $queue = Auditor::get_scan_queue();

        // Store initial state in transient
        $audit_id = 'jw_audit_' . time();
        $audit_state = [
            'id' => $audit_id,
            'php_version' => $php_version,
            'inventory' => $inventory,
            'started_at' => current_time('mysql'),
            'results' => [
                'plugins' => [],
                'themes' => [],
            ],
            'remote_meta' => [
                'plugins' => [],
                'themes' => [],
            ],
        ];

        set_transient($audit_id, $audit_state, HOUR_IN_SECONDS);

        wp_send_json_success([
            'audit_id' => $audit_id,
            'queue' => $queue,
            'total' => count($queue),
            'php_version' => $php_version,
        ]);
    }

    /**
     * Scan a single component
     */
    public static function scan_component() {
        check_ajax_referer('jw_compat_audit', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied.']);
        }

        $audit_id = isset($_POST['audit_id']) ? sanitize_text_field($_POST['audit_id']) : '';
        $component_type = isset($_POST['type']) ? sanitize_key($_POST['type']) : '';
        $component_slug = isset($_POST['slug']) ? sanitize_file_name($_POST['slug']) : '';
        $component_path = isset($_POST['path']) ? sanitize_text_field($_POST['path']) : '';
        $php_version = isset($_POST['php_version']) ? sanitize_text_field($_POST['php_version']) : '8.0-';

        // Validate audit_id format (must start with jw_audit_)
        if (empty($audit_id) || strpos($audit_id, 'jw_audit_') !== 0) {
            wp_send_json_error(['message' => 'Invalid audit ID.']);
        }

        // Validate component type
        if (!in_array($component_type, ['plugin', 'theme'], true)) {
            wp_send_json_error(['message' => 'Invalid component type.']);
        }

        // Validate PHP version
        if (!in_array($php_version, ['8.0-', '8.1-', '8.2-', '8.3-', '8.3', '8.4-'], true)) {
            $php_version = '8.0-';
        }

        if (empty($component_slug) || empty($component_path)) {
            wp_send_json_error(['message' => 'Missing required parameters.']);
        }

        // Validate path is within WordPress directories (strict validation)
        $real_wp_content = realpath(WP_CONTENT_DIR);
        $real_path = realpath($component_path);

        // Path must exist and be within wp-content
        if ($real_path === false) {
            wp_send_json_error(['message' => 'Path not found.']);
        }

        if ($real_wp_content === false || strpos($real_path, $real_wp_content) !== 0) {
            wp_send_json_error(['message' => 'Access denied: path outside allowed directory.']);
        }

        // Ensure path doesn't contain directory traversal attempts
        if (preg_match('/\.\./', $component_path)) {
            wp_send_json_error(['message' => 'Invalid path format.']);
        }

        // Run scan
        $scan_result = Auditor::scan_component($component_path, $php_version);
        $summary = Auditor::summarize_scan($scan_result);

        // Get remote meta
        $remote_meta = Auditor::get_remote_meta($component_type, $component_slug);

        // Update audit state
        $audit_state = get_transient($audit_id);
        if ($audit_state) {
            $type_key = $component_type === 'plugin' ? 'plugins' : 'themes';
            $audit_state['results'][$type_key][$component_slug] = [
                'summary' => $summary,
                'details' => $scan_result,
            ];
            $audit_state['remote_meta'][$type_key][$component_slug] = $remote_meta;
            set_transient($audit_id, $audit_state, HOUR_IN_SECONDS);
        }

        // Determine status
        $status = 'PASS';
        if (($summary['errors'] ?? 0) > 0) {
            $status = 'FAIL';
        } elseif (($summary['warnings'] ?? 0) > 0) {
            $status = 'WARN';
        }

        wp_send_json_success([
            'type' => $component_type,
            'slug' => $component_slug,
            'summary' => $summary,
            'status' => $status,
            'remote_meta' => $remote_meta,
            'details' => $scan_result,
        ]);
    }

    /**
     * Finalize audit and save report
     */
    public static function finalize_audit() {
        check_ajax_referer('jw_compat_audit', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied.']);
        }

        $audit_id = isset($_POST['audit_id']) ? sanitize_text_field($_POST['audit_id']) : '';

        // Validate audit_id format
        if (empty($audit_id) || strpos($audit_id, 'jw_audit_') !== 0) {
            wp_send_json_error(['message' => 'Invalid audit ID.']);
        }

        // Sanitize audit_id to only allow alphanumeric and underscore
        if (!preg_match('/^jw_audit_[0-9]+$/', $audit_id)) {
            wp_send_json_error(['message' => 'Invalid audit ID format.']);
        }

        $audit_state = get_transient($audit_id);
        if (!$audit_state) {
            wp_send_json_error(['message' => 'Audit session expired.']);
        }

        // Build final report
        $total_errors = 0;
        $total_warnings = 0;
        $component_summaries = ['plugins' => [], 'themes' => []];
        $details = ['plugins' => [], 'themes' => []];

        foreach (['plugins', 'themes'] as $type) {
            foreach ($audit_state['results'][$type] as $slug => $data) {
                $component_summaries[$type][$slug] = $data['summary'];
                $details[$type][$slug] = $data['details'];
                $total_errors += $data['summary']['errors'] ?? 0;
                $total_warnings += $data['summary']['warnings'] ?? 0;
            }
        }

        $report = [
            'inventory' => $audit_state['inventory'],
            'phpcs' => [
                'errors' => $total_errors,
                'warnings' => $total_warnings,
            ],
            'component_summaries' => $component_summaries,
            'remote_meta' => $audit_state['remote_meta'],
            'details' => $details,
            'php_version_tested' => $audit_state['php_version'],
        ];

        // Save report
        Auditor::save_report($report);

        // Clean up transient
        delete_transient($audit_id);

        wp_send_json_success([
            'message' => 'Audit completed successfully.',
            'total_errors' => $total_errors,
            'total_warnings' => $total_warnings,
        ]);
    }
}

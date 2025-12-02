<?php
namespace Jezweb\CompatAudit;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Admin UI for Jezweb Compatibility Audit
 */
class Admin {

    /**
     * Initialize admin hooks
     */
    public static function init() {
        add_action('admin_menu', [__CLASS__, 'register_menu']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
        add_action('admin_post_jw_compat_download', [__CLASS__, 'handle_download']);
    }

    /**
     * Register admin menu
     */
    public static function register_menu() {
        add_management_page(
            'Jezweb Compatibility Audit',
            'Jezweb Compat Audit',
            'manage_options',
            'jezweb-compat-audit',
            [__CLASS__, 'render_page']
        );
    }

    /**
     * Enqueue admin assets
     */
    public static function enqueue_assets($hook) {
        if ($hook !== 'tools_page_jezweb-compat-audit') {
            return;
        }

        wp_enqueue_style(
            'jw-compat-audit-admin',
            JW_COMPAT_AUDIT_URL . 'assets/css/admin.css',
            [],
            JW_COMPAT_AUDIT_VER
        );

        wp_enqueue_script(
            'jw-compat-audit-admin',
            JW_COMPAT_AUDIT_URL . 'assets/js/admin.js',
            ['jquery'],
            JW_COMPAT_AUDIT_VER,
            true
        );

        wp_localize_script('jw-compat-audit-admin', 'jwCompatAudit', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('jw_compat_audit'),
        ]);
    }

    /**
     * Handle report download
     */
    public static function handle_download() {
        if (!current_user_can('manage_options')) {
            wp_die('Permission denied.');
        }
        check_admin_referer('jw_compat_download');

        if (!file_exists(JW_COMPAT_AUDIT_REPORT)) {
            wp_safe_redirect(add_query_arg(['page' => 'jezweb-compat-audit', 'err' => 'missing'], admin_url('tools.php')));
            exit;
        }

        $filename = 'compat-report-' . date('Ymd-His', @filemtime(JW_COMPAT_AUDIT_REPORT) ?: time()) . '.json';

        nocache_headers();
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize(JW_COMPAT_AUDIT_REPORT));
        readfile(JW_COMPAT_AUDIT_REPORT);
        exit;
    }

    /**
     * Render admin page
     */
    public static function render_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $report = Auditor::load_report();
        ?>
        <div class="wrap">
            <h1>Jezweb Compatibility Audit</h1>

            <?php if (isset($_GET['err']) && sanitize_key($_GET['err']) === 'missing'): ?>
                <div class="notice notice-error"><p>No report found. Please run an audit first.</p></div>
            <?php endif; ?>

            <?php if (!Auditor::is_exec_available()): ?>
                <div class="jw-exec-warning">
                    <strong>Warning: exec() function is disabled</strong>
                    <p>The PHP <code>exec()</code> function is required for accurate scanning but is currently disabled on your server.
                    Scans will be marked as "SKIPPED" and results may not be reliable. Contact your hosting provider to enable it,
                    or use WP-CLI on a server where exec() is available.</p>
                </div>
            <?php endif; ?>

            <!-- Diagnostics Panel (collapsible) -->
            <details class="jw-diagnostics-panel" style="margin: 15px 0; padding: 10px; background: #f9f9f9; border: 1px solid #ddd;">
                <summary style="cursor: pointer; font-weight: bold;">System Diagnostics (click to expand)</summary>
                <div id="jw-diagnostics-content" style="margin-top: 10px;">
                    <button type="button" id="jw-run-diagnostics" class="button">Run Diagnostics</button>
                    <pre id="jw-diagnostics-output" style="margin-top: 10px; padding: 10px; background: #fff; border: 1px solid #ccc; overflow-x: auto; display: none;"></pre>
                </div>
            </details>

            <p>Scan your WordPress site (themes, child themes, plugins) for PHP compatibility issues.</p>

            <!-- Audit Controls -->
            <div class="jw-audit-controls">
                <label for="jw-php-version">Target PHP Version:</label>
                <select id="jw-php-version" name="php_version">
                    <option value="8.0-">PHP 8.0+</option>
                    <option value="8.1-">PHP 8.1+</option>
                    <option value="8.2-">PHP 8.2+</option>
                    <option value="8.3-" selected>PHP 8.3+</option>
                    <option value="8.3">PHP 8.3 only</option>
                    <option value="8.4-">PHP 8.4+</option>
                </select>

                <button type="button" id="jw-run-audit-btn" class="button button-primary">Run Audit Now</button>

                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;">
                    <?php wp_nonce_field('jw_compat_download'); ?>
                    <input type="hidden" name="action" value="jw_compat_download">
                    <?php
                    $disabled = file_exists(JW_COMPAT_AUDIT_REPORT) ? '' : 'disabled';
                    submit_button('Download Report (JSON)', 'secondary', '', false, [$disabled => $disabled]);
                    ?>
                </form>
            </div>

            <!-- Progress Container -->
            <div id="jw-progress-container" style="display: none;">
                <div class="jw-progress-bar">
                    <div id="jw-progress-fill" style="width: 0%;"></div>
                    <span id="jw-progress-percent">0%</span>
                </div>
                <p class="jw-progress-text">Scanning: <span id="jw-current-component">Initializing...</span></p>
                <p class="jw-progress-count"><span id="jw-scanned">0</span> / <span id="jw-total">0</span> components</p>
            </div>

            <!-- Results Container -->
            <div id="jw-audit-results" style="<?php echo empty($report) ? 'display:none;' : ''; ?>">
                <?php if (!empty($report)): ?>
                    <?php self::render_results($report); ?>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /**
     * Render audit results
     */
    public static function render_results($report) {
        $total_errors = $report['phpcs']['errors'] ?? 0;
        $total_warnings = $report['phpcs']['warnings'] ?? 0;

        // Calculate stats
        $pass_count = 0;
        $warn_count = 0;
        $fail_count = 0;
        $skip_count = 0;

        foreach (['plugins', 'themes'] as $type) {
            foreach (($report['component_summaries'][$type] ?? []) as $slug => $summary) {
                // Check for failed scans first
                if (isset($summary['scan_status']) && $summary['scan_status'] === 'failed') {
                    $skip_count++;
                } elseif (($summary['errors'] ?? 0) > 0) {
                    $fail_count++;
                } elseif (($summary['warnings'] ?? 0) > 0) {
                    $warn_count++;
                } else {
                    $pass_count++;
                }
            }
        }

        $total = $pass_count + $warn_count + $fail_count + $skip_count;

        // If any scans were skipped, health score is unreliable
        if ($skip_count > 0) {
            $health_score = '?';
            $health_class = 'moderate';
        } else {
            $health_score = $total > 0 ? round(($pass_count / $total) * 100) : 100;
            $health_class = $health_score >= 80 ? 'good' : ($health_score >= 50 ? 'moderate' : 'poor');
        }
        ?>

        <!-- Health Score Card -->
        <div class="jw-health-card">
            <div class="jw-health-score-container">
                <div class="jw-health-score-label">Health Score</div>
                <div id="jw-health-score" class="<?php echo esc_attr($health_class); ?>"><?php echo esc_html($health_score); ?>%</div>
            </div>

            <div class="jw-health-stats">
                <div class="jw-stat-item">
                    <div class="jw-stat-value" id="jw-total-pass"><?php echo esc_html($pass_count); ?></div>
                    <div class="jw-stat-label">Pass</div>
                </div>
                <div class="jw-stat-item">
                    <div class="jw-stat-value" id="jw-total-warn"><?php echo esc_html($warn_count); ?></div>
                    <div class="jw-stat-label">Warnings</div>
                </div>
                <div class="jw-stat-item">
                    <div class="jw-stat-value" id="jw-total-fail"><?php echo esc_html($fail_count); ?></div>
                    <div class="jw-stat-label">Fail</div>
                </div>
                <?php if ($skip_count > 0): ?>
                <div class="jw-stat-item">
                    <div class="jw-stat-value" id="jw-total-skip" style="color: #826eb4;"><?php echo esc_html($skip_count); ?></div>
                    <div class="jw-stat-label">Skipped</div>
                </div>
                <?php endif; ?>
                <div class="jw-stat-item">
                    <div class="jw-stat-value" id="jw-total-errors"><?php echo esc_html($total_errors); ?></div>
                    <div class="jw-stat-label">Total Errors</div>
                </div>
                <div class="jw-stat-item">
                    <div class="jw-stat-value" id="jw-total-warnings"><?php echo esc_html($total_warnings); ?></div>
                    <div class="jw-stat-label">Total Warnings</div>
                </div>
            </div>
        </div>

        <!-- Summary -->
        <h2>Site Information</h2>
        <table class="widefat striped">
            <tbody>
                <tr><th>Generated</th><td><?php echo esc_html($report['inventory']['generated_at'] ?? ''); ?></td></tr>
                <tr><th>Site</th><td><?php echo esc_html($report['inventory']['site']['home_url'] ?? ''); ?></td></tr>
                <tr><th>WordPress</th><td><?php echo esc_html($report['inventory']['site']['wp_version'] ?? ''); ?></td></tr>
                <tr><th>PHP</th><td><?php echo esc_html($report['inventory']['site']['php_version'] ?? ''); ?></td></tr>
                <tr><th>Tested For</th><td><?php echo esc_html($report['php_version_tested'] ?? 'PHP 8.0+'); ?></td></tr>
            </tbody>
        </table>

        <!-- Plugins -->
        <h2>Plugins</h2>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th>Plugin</th>
                    <th>Version</th>
                    <th>Status</th>
                    <th>Requires PHP</th>
                    <th>Tested Up To</th>
                    <th>Update</th>
                    <th>Changelog</th>
                    <th>Details</th>
                </tr>
            </thead>
            <tbody id="jw-plugins-results">
                <?php foreach (($report['inventory']['plugins'] ?? []) as $file => $plugin):
                    $slug = dirname($file);
                    if ($slug === '.') {
                        $slug = basename($file, '.php');
                    }

                    $meta = $report['remote_meta']['plugins'][$slug] ?? [];
                    $summary = $report['component_summaries']['plugins'][$slug] ?? ['errors' => 0, 'warnings' => 0];
                    $details = $report['details']['plugins'][$slug] ?? [];

                    // Check for failed scans first
                    if (isset($summary['scan_status']) && $summary['scan_status'] === 'failed') {
                        $status = 'SKIPPED';
                    } elseif (($summary['errors'] ?? 0) > 0) {
                        $status = 'FAIL';
                    } elseif (($summary['warnings'] ?? 0) > 0) {
                        $status = 'WARN';
                    } else {
                        $status = 'PASS';
                    }
                    $status_class = 'jw-status-' . strtolower($status);

                    $update = (!empty($meta['version']) && !empty($plugin['version']) && version_compare($meta['version'], $plugin['version'], '>'))
                        ? 'Update to ' . esc_html($meta['version'])
                        : '';

                    $changelog = !empty($meta['changelog']) ? $meta['changelog'] : (!empty($meta['homepage']) ? $meta['homepage'] : '');
                    $has_issues = ($summary['errors'] ?? 0) > 0 || ($summary['warnings'] ?? 0) > 0;
                    $scan_error = $summary['scan_error'] ?? null;
                ?>
                    <tr data-slug="<?php echo esc_attr($slug); ?>">
                        <td><?php echo esc_html($plugin['name'] ?? $slug); ?></td>
                        <td><?php echo esc_html($plugin['version'] ?? ''); ?></td>
                        <td>
                            <span class="jw-status-badge <?php echo esc_attr($status_class); ?>"><?php echo esc_html($status); ?></span>
                            <?php if ($has_issues): ?>
                                <small>(<?php echo intval($summary['errors']); ?>e/<?php echo intval($summary['warnings']); ?>w)</small>
                            <?php elseif ($scan_error): ?>
                                <small title="<?php echo esc_attr($scan_error); ?>">(scan failed)</small>
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html($meta['requires_php'] ?? '—'); ?></td>
                        <td><?php echo esc_html($meta['tested'] ?? '—'); ?></td>
                        <td><?php echo $update ? '<span class="jw-update-available">' . esc_html($update) . '</span>' : '—'; ?></td>
                        <td><?php echo $changelog ? '<a href="' . esc_url($changelog) . '" target="_blank" rel="noopener">View</a>' : '—'; ?></td>
                        <td>
                            <?php if ($has_issues): ?>
                                <button type="button" class="button button-small jw-toggle-details" data-slug="<?php echo esc_attr($slug); ?>">Details</button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php if ($has_issues): ?>
                        <tr class="jw-detail-row" data-detail-slug="<?php echo esc_attr($slug); ?>" style="display: none;">
                            <td colspan="8">
                                <?php self::render_issues_table($details); ?>
                            </td>
                        </tr>
                    <?php endif; ?>
                <?php endforeach; ?>
            </tbody>
        </table>

        <!-- Themes -->
        <h2>Themes</h2>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th>Theme</th>
                    <th>Version</th>
                    <th>Status</th>
                    <th>Requires PHP</th>
                    <th>Tested Up To</th>
                    <th>Update</th>
                    <th>Changelog</th>
                    <th>Details</th>
                </tr>
            </thead>
            <tbody id="jw-themes-results">
                <?php foreach (['active', 'parent'] as $key):
                    if (empty($report['inventory']['themes'][$key])) {
                        continue;
                    }

                    $theme = $report['inventory']['themes'][$key];
                    $slug = $theme['stylesheet'] ?? ($theme['template'] ?? '');
                    $meta = $report['remote_meta']['themes'][$slug] ?? [];
                    $summary = $report['component_summaries']['themes'][$slug] ?? ['errors' => 0, 'warnings' => 0];
                    $details = $report['details']['themes'][$slug] ?? [];

                    // Check for failed scans first
                    if (isset($summary['scan_status']) && $summary['scan_status'] === 'failed') {
                        $status = 'SKIPPED';
                    } elseif (($summary['errors'] ?? 0) > 0) {
                        $status = 'FAIL';
                    } elseif (($summary['warnings'] ?? 0) > 0) {
                        $status = 'WARN';
                    } else {
                        $status = 'PASS';
                    }
                    $status_class = 'jw-status-' . strtolower($status);

                    $update = (!empty($meta['version']) && !empty($theme['version']) && version_compare($meta['version'], $theme['version'], '>'))
                        ? 'Update to ' . esc_html($meta['version'])
                        : '';

                    $changelog = !empty($meta['changelog']) ? $meta['changelog'] : (!empty($meta['homepage']) ? $meta['homepage'] : '');
                    $has_issues = ($summary['errors'] ?? 0) > 0 || ($summary['warnings'] ?? 0) > 0;
                    $scan_error = $summary['scan_error'] ?? null;
                    $label = $key === 'parent' ? '(Parent)' : '(Active)';
                ?>
                    <tr data-slug="<?php echo esc_attr($slug); ?>">
                        <td><?php echo esc_html(($theme['name'] ?? $slug) . ' ' . $label); ?></td>
                        <td><?php echo esc_html($theme['version'] ?? ''); ?></td>
                        <td>
                            <span class="jw-status-badge <?php echo esc_attr($status_class); ?>"><?php echo esc_html($status); ?></span>
                            <?php if ($has_issues): ?>
                                <small>(<?php echo intval($summary['errors']); ?>e/<?php echo intval($summary['warnings']); ?>w)</small>
                            <?php elseif ($scan_error): ?>
                                <small title="<?php echo esc_attr($scan_error); ?>">(scan failed)</small>
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html($meta['requires_php'] ?? '—'); ?></td>
                        <td><?php echo esc_html($meta['tested'] ?? '—'); ?></td>
                        <td><?php echo $update ? '<span class="jw-update-available">' . esc_html($update) . '</span>' : '—'; ?></td>
                        <td><?php echo $changelog ? '<a href="' . esc_url($changelog) . '" target="_blank" rel="noopener">View</a>' : '—'; ?></td>
                        <td>
                            <?php if ($has_issues): ?>
                                <button type="button" class="button button-small jw-toggle-details" data-slug="<?php echo esc_attr($slug); ?>">Details</button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php if ($has_issues): ?>
                        <tr class="jw-detail-row" data-detail-slug="<?php echo esc_attr($slug); ?>" style="display: none;">
                            <td colspan="8">
                                <?php self::render_issues_table($details); ?>
                            </td>
                        </tr>
                    <?php endif; ?>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    /**
     * Render issues table for a component
     */
    private static function render_issues_table($details) {
        $files = $details['files'] ?? [];

        if (empty($files)) {
            echo '<p>No detailed issues available.</p>';
            return;
        }
        ?>
        <table class="jw-issues-table">
            <thead>
                <tr>
                    <th>File</th>
                    <th>Line</th>
                    <th>Type</th>
                    <th>Message</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($files as $file_path => $file_data):
                    $messages = $file_data['messages'] ?? [];
                    $short_path = implode('/', array_slice(explode('/', $file_path), -2));

                    foreach ($messages as $msg):
                        $type_class = $msg['type'] === 'ERROR' ? 'jw-issue-error' : 'jw-issue-warning';
                ?>
                    <tr class="<?php echo esc_attr($type_class); ?>">
                        <td title="<?php echo esc_attr($file_path); ?>"><?php echo esc_html($short_path); ?></td>
                        <td><?php echo esc_html($msg['line'] ?? ''); ?></td>
                        <td><?php echo esc_html($msg['type'] ?? ''); ?></td>
                        <td><?php echo esc_html($msg['message'] ?? ''); ?></td>
                    </tr>
                <?php
                    endforeach;
                endforeach;
                ?>
            </tbody>
        </table>
        <?php
    }
}

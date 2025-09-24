<?php
namespace Jezweb\CompatAudit;

if (!defined('ABSPATH')) { exit; }

class Admin {

    public static function init() {
        add_action('admin_menu', [__CLASS__, 'register_menu']);
        add_action('admin_post_jw_compat_run', [__CLASS__, 'handle_run']);
        add_action('admin_post_jw_compat_download', [__CLASS__, 'handle_download']);
    }

    public static function register_menu() {
        add_management_page(
            'Jezweb Compatibility Audit',
            'Jezweb Compatibility Audit',
            'manage_options',
            'jezweb-compat-audit',
            [__CLASS__, 'render_page']
        );
    }

    /** Handle "Run Audit Now" */
    public static function handle_run() {
        if (!current_user_can('manage_options')) wp_die('Permission denied.');
        check_admin_referer('jw_compat_run');

        Auditor::run_audit();

        wp_safe_redirect(add_query_arg(['page'=>'jezweb-compat-audit','ran'=>'1'], admin_url('tools.php')));
        exit;
    }

    /** Handle "Download Report" */
    public static function handle_download() {
        if (!current_user_can('manage_options')) wp_die('Permission denied.');
        check_admin_referer('jw_compat_download');

        if (!file_exists(JW_COMPAT_AUDIT_REPORT)) {
            // No report yet → bounce back with error
            wp_safe_redirect(add_query_arg(['page'=>'jezweb-compat-audit','err'=>'missing'], admin_url('tools.php')));
            exit;
        }

        $filename = 'compat-report-' . date('Ymd-His', @filemtime(JW_COMPAT_AUDIT_REPORT) ?: time()) . '.json';

        // Push download
        nocache_headers();
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize(JW_COMPAT_AUDIT_REPORT));
        readfile(JW_COMPAT_AUDIT_REPORT);
        exit;
    }

    public static function render_page() {
        if (!current_user_can('manage_options')) return;

        $report = [];
        if (file_exists(JW_COMPAT_AUDIT_REPORT)) {
            $json = file_get_contents(JW_COMPAT_AUDIT_REPORT);
            $report = json_decode($json, true) ?: [];
        }
        ?>
        <div class="wrap">
            <h1>Jezweb Compatibility Audit</h1>

            <?php if (isset($_GET['ran'])): ?>
                <div class="notice notice-success"><p>Audit completed.</p></div>
            <?php endif; ?>
            <?php if (isset($_GET['err']) && $_GET['err'] === 'missing'): ?>
                <div class="notice notice-error"><p>No report found yet. Please run an audit first.</p></div>
            <?php endif; ?>

            <p>Click the button below to scan your site (themes, child themes, plugins) for PHP 8.3 compatibility.</p>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;margin-right:8px">
                <?php wp_nonce_field('jw_compat_run'); ?>
                <input type="hidden" name="action" value="jw_compat_run">
                <?php submit_button('Run Audit Now', 'primary', '', false); ?>
            </form>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block">
                <?php wp_nonce_field('jw_compat_download'); ?>
                <input type="hidden" name="action" value="jw_compat_download">
                <?php
                $disabled = file_exists(JW_COMPAT_AUDIT_REPORT) ? '' : 'disabled';
                submit_button('Download Report (JSON)', 'secondary', '', false, [ 'style'=>'margin-left:6px', $disabled => $disabled ]);
                ?>
            </form>

            <?php if (!empty($report)): ?>
                <h2>Summary</h2>
                <table class="widefat striped">
                    <tbody>
                        <tr><th>Generated</th><td><?php echo esc_html($report['inventory']['generated_at'] ?? ''); ?></td></tr>
                        <tr><th>Site</th><td><?php echo esc_html($report['inventory']['site']['home_url'] ?? ''); ?></td></tr>
                        <tr><th>WordPress</th><td><?php echo esc_html($report['inventory']['site']['wp_version'] ?? ''); ?></td></tr>
                        <tr><th>PHP</th><td><?php echo esc_html($report['inventory']['site']['php_version'] ?? ''); ?></td></tr>
                        <tr><th>PHPCS</th><td><?php echo intval($report['phpcs']['errors'] ?? 0); ?> errors, <?php echo intval($report['phpcs']['warnings'] ?? 0); ?> warnings</td></tr>
                    </tbody>
                </table>

                <h2>Plugins</h2>
                <table class="widefat striped">
                    <thead><tr><th>Plugin</th><th>Version</th><th>Status</th><th>Active</th><th>Requires&nbsp;PHP</th><th>Tested</th><th>Update?</th><th>Changelog</th></tr></thead>
                    <tbody>
                    <?php foreach (($report['inventory']['plugins'] ?? []) as $file => $p):
                        $slug = dirname($file);
                        $meta = $report['remote_meta']['plugins'][$slug] ?? [];
                        $sum  = $report['component_summaries']['plugins'][$slug] ?? ['errors'=>0,'warnings'=>0];
                        $status = ($sum['errors'] ?? 0) > 0 ? 'FAIL' : ( ($sum['warnings'] ?? 0) > 0 ? 'WARN' : 'PASS' );
                        $update = (!empty($meta['version']) && !empty($p['version']) && version_compare($meta['version'], $p['version'], '>')) ? ('Update to ' . esc_html($meta['version'])) : '';
                        $changelog = !empty($meta['changelog']) ? $meta['changelog'] : (!empty($meta['homepage']) ? $meta['homepage'] : '');
                    ?>
                        <tr>
                            <td><?php echo esc_html($p['name'] ?? $slug); ?></td>
                            <td><?php echo esc_html($p['version'] ?? ''); ?></td>
                            <td><?php echo esc_html($status); ?><?php if(!empty($sum['errors']) || !empty($sum['warnings'])): ?> (<?php echo intval($sum['errors']); ?>e/<?php echo intval($sum['warnings']); ?>w)<?php endif; ?></td>
                            <td><?php echo !empty($p['is_active']) ? 'Yes' : 'No'; ?></td>
                            <td><?php echo esc_html($meta['requires_php'] ?? '—'); ?></td>
                            <td><?php echo esc_html($meta['tested'] ?? '—'); ?></td>
                            <td><?php echo $update ? esc_html($update) : '—'; ?></td>
                            <td><?php echo $changelog ? '<a href="'.esc_url($changelog).'" target="_blank" rel="noreferrer">View</a>' : '—'; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>

                <h2>Themes</h2>
                <table class="widefat striped">
                    <thead><tr><th>Theme</th><th>Version</th><th>Status</th><th>Path</th><th>Requires&nbsp;PHP</th><th>Tested</th><th>Update?</th><th>Changelog</th></tr></thead>
                    <tbody>
                    <?php foreach (['active','parent'] as $key):
                        if (empty($report['inventory']['themes'][$key])) continue;
                        $t = $report['inventory']['themes'][$key];
                        $slug = $t['stylesheet'] ?? ($t['template'] ?? '');
                        $meta = $report['remote_meta']['themes'][$slug] ?? [];
                        $path = $t['stylesheet_dir'] ?? ($t['template_dir'] ?? '');
                        $sum  = $report['component_summaries']['themes'][$slug] ?? ['errors'=>0,'warnings'=>0];
                        $status = ($sum['errors'] ?? 0) > 0 ? 'FAIL' : ( ($sum['warnings'] ?? 0) > 0 ? 'WARN' : 'PASS' );
                        $update = (!empty($meta['version']) && !empty($t['version']) && version_compare($meta['version'], $t['version'], '>')) ? ('Update to ' . esc_html($meta['version'])) : '';
                        $changelog = !empty($meta['changelog']) ? $meta['changelog'] : (!empty($meta['homepage']) ? $meta['homepage'] : '');
                    ?>
                        <tr>
                            <td><?php echo esc_html($t['name'] ?? $slug); ?> <?php echo $key === 'parent' ? '(Parent)' : '(Active)'; ?></td>
                            <td><?php echo esc_html($t['version'] ?? ''); ?></td>
                            <td><?php echo esc_html($status); ?><?php if(!empty($sum['errors']) || !empty($sum['warnings'])): ?> (<?php echo intval($sum['errors']); ?>e/<?php echo intval($sum['warnings']); ?>w)<?php endif; ?></td>
                            <td><?php echo esc_html($path); ?></td>
                            <td><?php echo esc_html($meta['requires_php'] ?? '—'); ?></td>
                            <td><?php echo esc_html($meta['tested'] ?? '—'); ?></td>
                            <td><?php echo $update ? esc_html($update) : '—'; ?></td>
                            <td><?php echo $changelog ? '<a href="'.esc_url($changelog).'" target="_blank" rel="noreferrer">View</a>' : '—'; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }
}

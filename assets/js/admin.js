(function($) {
    'use strict';

    var JWCompatAudit = {
        auditId: null,
        queue: [],
        currentIndex: 0,
        results: [],
        phpVersion: '8.0-',

        init: function() {
            this.bindEvents();
        },

        bindEvents: function() {
            $('#jw-run-audit-btn').on('click', this.startAudit.bind(this));
            $(document).on('click', '.jw-toggle-details', this.toggleDetails.bind(this));
        },

        startAudit: function(e) {
            e.preventDefault();

            var self = this;
            this.phpVersion = $('#jw-php-version').val() || '8.0-';
            this.results = [];
            this.currentIndex = 0;

            // Show progress, hide results
            $('#jw-progress-container').show();
            $('#jw-audit-results').hide();
            $('#jw-run-audit-btn').prop('disabled', true);
            $('.jw-audit-notice').remove();

            // Reset progress
            this.updateProgress(0, 0, 'Initializing...');

            // Start the audit
            $.ajax({
                url: jwCompatAudit.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'jw_compat_start_audit',
                    nonce: jwCompatAudit.nonce,
                    php_version: this.phpVersion
                },
                success: function(response) {
                    if (response.success) {
                        self.auditId = response.data.audit_id;
                        self.queue = response.data.queue;
                        self.updateProgress(0, self.queue.length, 'Starting scan...');
                        self.scanNext();
                    } else {
                        self.showError(response.data.message || 'Failed to start audit.');
                    }
                },
                error: function() {
                    self.showError('Network error. Please try again.');
                }
            });
        },

        scanNext: function() {
            var self = this;

            if (this.currentIndex >= this.queue.length) {
                this.finalizeAudit();
                return;
            }

            var component = this.queue[this.currentIndex];
            this.updateProgress(
                this.currentIndex,
                this.queue.length,
                'Scanning: ' + component.name
            );

            $.ajax({
                url: jwCompatAudit.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'jw_compat_scan_component',
                    nonce: jwCompatAudit.nonce,
                    audit_id: this.auditId,
                    type: component.type,
                    slug: component.slug,
                    path: component.path,
                    php_version: this.phpVersion
                },
                success: function(response) {
                    if (response.success) {
                        self.results.push({
                            component: component,
                            data: response.data
                        });
                    } else {
                        self.results.push({
                            component: component,
                            data: {
                                status: 'ERROR',
                                summary: { errors: 0, warnings: 0 },
                                error: response.data.message
                            }
                        });
                    }

                    self.currentIndex++;
                    self.scanNext();
                },
                error: function() {
                    self.results.push({
                        component: component,
                        data: {
                            status: 'ERROR',
                            summary: { errors: 0, warnings: 0 },
                            error: 'Network error'
                        }
                    });

                    self.currentIndex++;
                    self.scanNext();
                }
            });
        },

        finalizeAudit: function() {
            var self = this;

            this.updateProgress(this.queue.length, this.queue.length, 'Finalizing and saving report...');

            $.ajax({
                url: jwCompatAudit.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'jw_compat_finalize_audit',
                    nonce: jwCompatAudit.nonce,
                    audit_id: this.auditId
                },
                success: function(response) {
                    if (response.success) {
                        // Show completion message
                        self.updateProgress(
                            self.queue.length,
                            self.queue.length,
                            'Audit complete! Found ' + response.data.total_errors + ' errors and ' + response.data.total_warnings + ' warnings.'
                        );
                        $('#jw-progress-percent').text('Done!');

                        // Show loading overlay and reload page
                        self.showLoadingOverlay('Loading results...');

                        // Brief delay then reload to show server-rendered results
                        setTimeout(function() {
                            window.location.reload();
                        }, 800);
                    } else {
                        $('#jw-progress-container').hide();
                        $('#jw-run-audit-btn').prop('disabled', false);
                        self.showError(response.data.message || 'Failed to finalize audit.');
                    }
                },
                error: function() {
                    $('#jw-progress-container').hide();
                    $('#jw-run-audit-btn').prop('disabled', false);
                    self.showError('Network error during finalization.');
                }
            });
        },

        updateProgress: function(current, total, message) {
            var percent = total > 0 ? Math.round((current / total) * 100) : 0;

            $('#jw-progress-fill').css('width', percent + '%');
            $('#jw-progress-percent').text(percent + '%');
            $('#jw-current-component').text(message);
            $('#jw-scanned').text(current);
            $('#jw-total').text(total);
        },

        displayResults: function() {
            var self = this;
            var $container = $('#jw-audit-results');
            var $pluginsBody = $('#jw-plugins-results');
            var $themesBody = $('#jw-themes-results');

            $pluginsBody.empty();
            $themesBody.empty();

            var totalErrors = 0;
            var totalWarnings = 0;
            var passCount = 0;
            var warnCount = 0;
            var failCount = 0;

            this.results.forEach(function(item) {
                var component = item.component;
                var data = item.data;
                var summary = data.summary || { errors: 0, warnings: 0 };
                var status = data.status || 'PASS';
                var remoteMeta = data.remote_meta || {};

                totalErrors += summary.errors || 0;
                totalWarnings += summary.warnings || 0;

                if (status === 'PASS') passCount++;
                else if (status === 'WARN') warnCount++;
                else if (status === 'FAIL') failCount++;

                var $row = self.createResultRow(component, data, remoteMeta);
                var $detailRow = self.createDetailRow(component, data);

                if (component.type === 'plugin') {
                    $pluginsBody.append($row);
                    $pluginsBody.append($detailRow);
                } else {
                    $themesBody.append($row);
                    $themesBody.append($detailRow);
                }
            });

            // Update health score
            var total = this.results.length;
            var healthScore = total > 0 ? Math.round((passCount / total) * 100) : 100;
            var healthClass = healthScore >= 80 ? 'good' : (healthScore >= 50 ? 'moderate' : 'poor');

            $('#jw-health-score').text(healthScore + '%').removeClass('good moderate poor').addClass(healthClass);
            $('#jw-total-pass').text(passCount);
            $('#jw-total-warn').text(warnCount);
            $('#jw-total-fail').text(failCount);
            $('#jw-total-errors').text(totalErrors);
            $('#jw-total-warnings').text(totalWarnings);

            $container.show();
        },

        createResultRow: function(component, data, remoteMeta) {
            var summary = data.summary || { errors: 0, warnings: 0 };
            var status = data.status || 'PASS';
            var statusClass = 'jw-status-' + status.toLowerCase();

            var hasIssues = (summary.errors > 0 || summary.warnings > 0);
            var scanFailed = (summary.scan_status === 'failed');
            var toggleBtn = hasIssues ? '<button type="button" class="button button-small jw-toggle-details" data-slug="' + this.escapeHtml(component.slug) + '">Details</button>' : '';

            var updateAvailable = '';
            if (remoteMeta.version && component.version) {
                if (this.compareVersions(remoteMeta.version, component.version) > 0) {
                    updateAvailable = '<span class="jw-update-available">Update to ' + this.escapeHtml(remoteMeta.version) + '</span>';
                }
            }

            var changelogLink = remoteMeta.changelog ? '<a href="' + this.escapeHtml(remoteMeta.changelog) + '" target="_blank" rel="noopener">View</a>' : '&mdash;';

            return $(
                '<tr data-slug="' + this.escapeHtml(component.slug) + '">' +
                    '<td>' + this.escapeHtml(component.name) + '</td>' +
                    '<td>' + this.escapeHtml(component.version || '') + '</td>' +
                    '<td><span class="jw-status-badge ' + statusClass + '">' + this.escapeHtml(status) + '</span>' +
                        (hasIssues ? ' <small>(' + parseInt(summary.errors, 10) + 'e/' + parseInt(summary.warnings, 10) + 'w)</small>' : '') +
                        (scanFailed && !hasIssues ? ' <small title="' + this.escapeHtml(summary.scan_error || 'Scan failed') + '">(scan failed)</small>' : '') +
                    '</td>' +
                    '<td>' + this.escapeHtml(remoteMeta.requires_php || '') || '&mdash;' + '</td>' +
                    '<td>' + this.escapeHtml(remoteMeta.tested || '') || '&mdash;' + '</td>' +
                    '<td>' + (updateAvailable || '&mdash;') + '</td>' +
                    '<td>' + changelogLink + '</td>' +
                    '<td>' + toggleBtn + '</td>' +
                '</tr>'
            );
        },

        createDetailRow: function(component, data) {
            var self = this;
            var details = data.details || {};
            var files = details.files || {};
            var hasIssues = Object.keys(files).length > 0;

            if (!hasIssues) {
                return $('<tr class="jw-detail-row" data-detail-slug="' + self.escapeHtml(component.slug) + '" style="display:none;"><td colspan="8"><p>No issues found.</p></td></tr>');
            }

            var issuesHtml = '<table class="jw-issues-table"><thead><tr><th>File</th><th>Line</th><th>Type</th><th>Message</th></tr></thead><tbody>';

            for (var filePath in files) {
                var file = files[filePath];
                var messages = file.messages || [];

                messages.forEach(function(msg) {
                    var typeClass = msg.type === 'ERROR' ? 'jw-issue-error' : 'jw-issue-warning';
                    var shortPath = filePath.split('/').slice(-2).join('/');

                    issuesHtml += '<tr class="' + typeClass + '">' +
                        '<td title="' + self.escapeHtml(filePath) + '">' + self.escapeHtml(shortPath) + '</td>' +
                        '<td>' + self.escapeHtml(String(msg.line || '')) + '</td>' +
                        '<td>' + self.escapeHtml(msg.type || '') + '</td>' +
                        '<td>' + self.escapeHtml(msg.message || '') + '</td>' +
                    '</tr>';
                });
            }

            issuesHtml += '</tbody></table>';

            return $('<tr class="jw-detail-row" data-detail-slug="' + self.escapeHtml(component.slug) + '" style="display:none;"><td colspan="8">' + issuesHtml + '</td></tr>');
        },

        toggleDetails: function(e) {
            e.preventDefault();
            var $btn = $(e.currentTarget);
            var slug = $btn.data('slug');
            var $detailRow = $('tr[data-detail-slug="' + slug + '"]');

            if ($detailRow.is(':visible')) {
                $detailRow.hide();
                $btn.text('Details');
            } else {
                $detailRow.show();
                $btn.text('Hide');
            }
        },

        compareVersions: function(v1, v2) {
            var parts1 = (v1 || '0').split('.').map(Number);
            var parts2 = (v2 || '0').split('.').map(Number);
            var len = Math.max(parts1.length, parts2.length);

            for (var i = 0; i < len; i++) {
                var p1 = parts1[i] || 0;
                var p2 = parts2[i] || 0;
                if (p1 > p2) return 1;
                if (p1 < p2) return -1;
            }
            return 0;
        },

        escapeHtml: function(text) {
            var div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        },

        showSuccess: function(message) {
            this.showNotice(message, 'success');
        },

        showError: function(message) {
            this.showNotice(message, 'error');
            $('#jw-progress-container').hide();
            $('#jw-run-audit-btn').prop('disabled', false);
        },

        showNotice: function(message, type) {
            var $notice = $('<div class="notice notice-' + type + ' is-dismissible jw-audit-notice"><p>' + message + '</p></div>');
            $('.wrap h1').after($notice);

            // Auto-dismiss after 5 seconds
            setTimeout(function() {
                $notice.fadeOut(function() {
                    $(this).remove();
                });
            }, 5000);
        },

        showLoadingOverlay: function(message) {
            var $overlay = $(
                '<div class="jw-loading-overlay">' +
                    '<div class="jw-loading-spinner"></div>' +
                    '<div class="jw-loading-text">' + this.escapeHtml(message) + '</div>' +
                '</div>'
            );
            $('body').append($overlay);
        }
    };

    $(document).ready(function() {
        JWCompatAudit.init();
    });

})(jQuery);

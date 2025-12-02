# Jezweb Compatibility Audit

[![Version](https://img.shields.io/badge/version-0.5.3-blue.svg)](https://github.com/mmhfarooque/jezweb-compatibility-audit/releases)
[![WordPress](https://img.shields.io/badge/WordPress-5.6%2B-blue.svg)](https://wordpress.org/)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-purple.svg)](https://php.net/)
[![License](https://img.shields.io/badge/license-GPL--2.0--or--later-green.svg)](LICENSE)

A WordPress plugin that scans your site (themes, child themes, plugins) for **PHP 8.x compatibility** issues before upgrading your server.

## Description

Jezweb Compatibility Audit helps WordPress site administrators identify PHP compatibility issues in their themes and plugins before upgrading to newer PHP versions. It uses the industry-standard PHPCompatibilityWP ruleset with PHP_CodeSniffer to perform static analysis and detect deprecated functions, removed features, and breaking changes.

### Why Use This Plugin?

- **Prevent Downtime** - Identify incompatible code before upgrading PHP
- **Detailed Reports** - See exactly which files and lines have issues
- **Actionable Results** - Know which plugins/themes need updates or replacements
- **Multiple Interfaces** - Use via Admin UI, WP-CLI, or REST API

## Features

### Core Scanning
- Full PHP compatibility scanning powered by PHPCompatibilityWP/PHPCS
- Scans all installed plugins (active and inactive)
- Scans active theme and parent theme
- Configurable target PHP versions (8.0+, 8.1+, 8.2+, 8.3+, 8.4+)

### Admin Interface
- Modern, responsive dashboard under **Tools → Jezweb Compatibility Audit**
- Real-time AJAX batch processing with progress bar
- Health score dashboard showing overall site compatibility
- Color-coded status badges (PASS / WARN / FAIL)
- Expandable error details with file paths and line numbers
- One-click JSON report download

### WordPress.org Integration
- Fetches plugin/theme metadata from WordPress.org API
- Shows "Requires PHP" version for each component
- Shows "Tested up to" WordPress version
- Identifies available updates

### WP-CLI Support
- `wp jw-compat audit` - Run full audit with progress bar
- `wp jw-compat scan <path>` - Scan specific directory
- `wp jw-compat report` - View last audit report
- `wp jw-compat inventory` - List all plugins/themes
- Multiple output formats: table, JSON, CSV, YAML

### REST API
- Full programmatic access for automation
- Endpoints for audit, inventory, queue, and scan
- Requires `manage_options` capability

### Auto-Updates
- Automatic update notifications via GitHub Releases
- One-click updates from WordPress Dashboard

## Screenshots

*Coming soon*

## Installation

### From GitHub Release (Recommended)

1. Download the latest ZIP from [Releases](https://github.com/mmhfarooque/jezweb-compatibility-audit/releases)
2. Go to **WordPress Admin → Plugins → Add New → Upload Plugin**
3. Upload the ZIP file and click **Install Now**
4. Activate the plugin

### From Source

```bash
git clone https://github.com/mmhfarooque/jezweb-compatibility-audit.git
cd jezweb-compatibility-audit
composer install --no-dev
```

Then upload the folder to `wp-content/plugins/`.

## Usage

### Admin Interface

1. Navigate to **Tools → Jezweb Compatibility Audit**
2. Select your target PHP version from the dropdown (e.g., "PHP 8.3+")
3. Click **Run Audit Now**
4. Watch the progress bar as each plugin/theme is scanned
5. Review the results:
   - **Green (PASS)** - No compatibility issues found
   - **Yellow (WARN)** - Warnings found (may work but has deprecated code)
   - **Red (FAIL)** - Errors found (likely to break on target PHP version)
6. Click **Details** on any component to see specific issues
7. Download the full JSON report for records or sharing

### WP-CLI Commands

```bash
# Run a full compatibility audit
wp jw-compat audit

# Audit for a specific PHP version
wp jw-compat audit --php=8.3-

# Output results as JSON
wp jw-compat audit --format=json

# Quick audit (skip WordPress.org API lookups)
wp jw-compat audit --skip-remote

# Scan a specific plugin or theme directory
wp jw-compat scan /var/www/html/wp-content/plugins/my-plugin

# View the last audit report
wp jw-compat report

# List all installed plugins and themes
wp jw-compat inventory
wp jw-compat inventory --format=json
```

### REST API Endpoints

All endpoints require authentication with `manage_options` capability.

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/wp-json/jezweb/v1/audit` | Get the latest audit report |
| `POST` | `/wp-json/jezweb/v1/audit` | Run a new audit |
| `GET` | `/wp-json/jezweb/v1/inventory` | Get list of plugins/themes |
| `GET` | `/wp-json/jezweb/v1/queue` | Get scan queue |
| `POST` | `/wp-json/jezweb/v1/scan` | Scan a specific path |

#### Example: Run Audit via REST API

```bash
curl -X POST "https://yoursite.com/wp-json/jezweb/v1/audit" \
  -H "Authorization: Basic BASE64_ENCODED_CREDENTIALS" \
  -H "Content-Type: application/json" \
  -d '{"php_version": "8.3-"}'
```

## Requirements

| Requirement | Version |
|-------------|---------|
| WordPress | 5.6 or higher |
| PHP | 7.4 or higher |
| `exec()` function | Must be enabled |

**Note:** The `exec()` PHP function must be available for PHPCS scanning to work. Some shared hosting providers disable this function.

## Frequently Asked Questions

### What PHP versions can I test against?

You can test compatibility with:
- PHP 8.0+
- PHP 8.1+
- PHP 8.2+
- PHP 8.3+
- PHP 8.3 (exact version)
- PHP 8.4+

### Does this plugin fix compatibility issues?

No, this plugin only identifies issues. You'll need to update the affected plugins/themes or contact their developers for fixes.

### Why do I see "exec() disabled" error?

The plugin uses PHP_CodeSniffer which requires the `exec()` function. Contact your hosting provider to enable it, or use the WP-CLI commands on a server where it's available.

### Can I scan only specific plugins?

Yes, use the WP-CLI command: `wp jw-compat scan /path/to/plugin`

Or use the REST API scan endpoint.

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for a full list of changes.

### 0.5.3 (2025-12-02)
- **CRITICAL FIX**: False positive bug - failed scans no longer report as "PASS"
- Added "SKIPPED" status for components that couldn't be scanned
- Added warning banner when exec() is disabled
- Health score shows "?" when results are unreliable

### 0.5.2 (2025-12-02)
- Fixed: Auto-refresh page after scan completes to display results immediately
- Added loading overlay with spinner during page reload
- Progress bar visual feedback when scan completes

### 0.5.1 (2025-12-02)
- Security hardening: Enhanced input validation and sanitization
- Security hardening: Strict path traversal protection
- Security hardening: XSS prevention improvements
- Added exec() availability check with proper error messaging
- Implemented WP_Filesystem for secure file operations

### 0.5.0 (2025-12-02)
- Added full PHPCS scanning with bundled PHPCompatibilityWP
- Added AJAX batch processing with real-time progress bar
- Added configurable PHP version targeting
- Added WP-CLI commands: audit, scan, report, inventory
- Added REST API endpoints
- Enhanced admin UI with health score and expandable details

### 0.4.6 (2025-09-25)
- Initial public release

## Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

1. Fork the repository
2. Create your feature branch (`git checkout -b feature/amazing-feature`)
3. Commit your changes (`git commit -m 'Add amazing feature'`)
4. Push to the branch (`git push origin feature/amazing-feature`)
5. Open a Pull Request

## Support

- **Issues:** [GitHub Issues](https://github.com/mmhfarooque/jezweb-compatibility-audit/issues)
- **Website:** [Jezweb](https://jezweb.com.au)

## Credits

- [PHP_CodeSniffer](https://github.com/squizlabs/PHP_CodeSniffer)
- [PHPCompatibility](https://github.com/PHPCompatibility/PHPCompatibility)
- [PHPCompatibilityWP](https://github.com/PHPCompatibility/PHPCompatibilityWP)
- [Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker)

## License

This project is licensed under the GPL-2.0-or-later License - see the [LICENSE](LICENSE) file for details.

---

**Made with care by [Jezweb](https://jezweb.com.au)**

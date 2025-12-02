# Jezweb Compatibility Audit

A WordPress plugin that scans your site (themes, child themes, plugins) for **PHP 8.x compatibility**.

## Features

- **PHP Compatibility Scanning** - Static analysis with PHPCompatibilityWP/PHPCS
- **Configurable PHP Versions** - Target PHP 8.0+, 8.1+, 8.2+, 8.3+, or 8.4+
- **AJAX Batch Processing** - Real-time progress bar during scans
- **Health Score Dashboard** - Visual summary with PASS/WARN/FAIL status
- **Expandable Error Details** - View specific issues with file/line references
- **WordPress.org Integration** - Check for updates and PHP requirements
- **WP-CLI Commands** - Full CLI support for automation
- **REST API** - Programmatic access to audit functionality
- **Auto-updates** - Updates via GitHub Releases

## Installation

1. Download the latest release from [Releases](../../releases)
2. Upload in WP Admin → Plugins → Add New → Upload Plugin
3. Activate and run under **Tools → Jezweb Compatibility Audit**

## Usage

### Admin Interface

1. Go to **Tools → Jezweb Compatibility Audit**
2. Select your target PHP version from the dropdown
3. Click **Run Audit Now**
4. Watch the progress bar as each component is scanned
5. Review results with expandable details for any issues

### WP-CLI Commands

```bash
# Run a full audit
wp jw-compat audit

# Audit for specific PHP version
wp jw-compat audit --php=8.3-

# Output as JSON
wp jw-compat audit --format=json

# Quick audit (skip WordPress.org API calls)
wp jw-compat audit --skip-remote

# Scan a specific path
wp jw-compat scan /path/to/plugin

# View the last report
wp jw-compat report

# List installed plugins/themes
wp jw-compat inventory
```

### REST API Endpoints

All endpoints require admin authentication (`manage_options` capability).

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/wp-json/jezweb/v1/audit` | Get latest audit report |
| POST | `/wp-json/jezweb/v1/audit` | Run new audit |
| GET | `/wp-json/jezweb/v1/inventory` | Get plugins/themes list |
| GET | `/wp-json/jezweb/v1/queue` | Get scan queue |
| POST | `/wp-json/jezweb/v1/scan` | Scan specific path |

## Requirements

- WordPress 5.6+
- PHP 7.4+ (to run the plugin)
- `exec()` function enabled (for PHPCS scanning)

## License

GPL-2.0-or-later

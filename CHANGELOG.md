# Changelog

All notable changes to **Jezweb Compatibility Audit** will be documented here.
This project follows [Semantic Versioning](https://semver.org/).

---

## [0.5.3] - 2025-12-02
### Fixed
- **CRITICAL: False positive bug** - Scans that failed (e.g., exec() disabled) were reporting as "PASS" instead of indicating failure
- Added "SKIPPED" status for components that couldn't be scanned
- Health score now shows "?" when scans were skipped (unreliable results)

### Added
- Warning banner when exec() function is disabled on server
- Scan error messages shown in UI with hover tooltip
- "Skipped" count in health stats when scans fail

### Changed
- `summarize_scan()` now returns `scan_status` and `scan_error` fields
- Status determination checks for failed scans before reporting PASS

---

## [0.5.2] - 2025-12-02
### Fixed
- Auto-refresh page after scan completes to display results immediately
- Added loading overlay with spinner during page reload
- Progress bar turns green with animation when scan completes
- Shows error/warning count in completion message

---

## [0.5.1] - 2025-12-02
### Security
- Enhanced input validation and sanitization across all AJAX handlers
- Added strict path traversal protection for scan operations
- Improved XSS prevention in JavaScript with proper HTML escaping
- Added validation for audit IDs and PHP version parameters
- Implemented WP_Filesystem for secure file operations
- Added exec() availability check with proper error messaging

### Changed
- Strengthened REST API path validation
- Improved error handling in Auditor class

---

## [0.5.0] - 2025-12-02
### Added
- **AJAX Batch Processing**: Real-time progress bar with component-by-component scanning
- **Configurable PHP Version**: Select target PHP version (8.0+, 8.1+, 8.2+, 8.3+, 8.3, 8.4+)
- **Enhanced UI**: Health score dashboard, color-coded status badges, expandable error details
- **Full WP-CLI Support**: Commands for `audit`, `scan`, `report`, and `inventory`
- **REST API**: Endpoints at `/wp-json/jezweb/v1/` for audit, inventory, scan, and queue
- **Bundled PHPCS**: PHP_CodeSniffer and PHPCompatibilityWP included via Composer

### Changed
- Complete rewrite of Auditor class with proper PHPCS integration
- Improved Admin UI with modern styling and responsive design
- Better error handling and validation throughout

### Fixed
- Scanning now properly uses PHPCompatibilityWP ruleset
- Remote metadata fetching from WordPress.org API

---

## [0.4.0] - 2025-09-24
### Added
- First public release on GitHub
- Jezweb-branded admin page under **Tools → Jezweb Compatibility Audit**
- Per-plugin and per-theme PHP 8.3+ compatibility check
- PASS / WARN / FAIL status indicators with error/warning counts
- Auto-update support via GitHub Releases
- WP-CLI command: `wp jezweb compat audit`
- GitHub Actions workflow to build release ZIP automatically

---

## [0.3.0] - Internal
- PASS/FAIL per component, changelog links, update flags

## [0.2.0] - Internal
- Jezweb branding (menus, headers, CLI)

## [0.1.0] - Internal
- First working prototype (inventory + PHPCS scan)

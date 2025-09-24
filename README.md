# Jezweb Compatibility Audit

A WordPress plugin that scans your themes, child themes, and plugins for **PHP 8.x compatibility**.  
It uses [PHP_CodeSniffer](https://github.com/squizlabs/PHP_CodeSniffer) with [PHPCompatibilityWP](https://github.com/PHPCompatibility/PHPCompatibilityWP) to detect issues and fetches plugin/theme metadata from WordPress.org.

## Features
- Inventory of all plugins, themes (parent + child).
- Static scan for PHP 8.3+ compatibility issues.
- PASS / WARN / FAIL status per component.
- Shows recommended updates and changelog links.
- WP-CLI command:  
  ```bash
  wp jezweb compat audit
# jezweb-compatibility-audit

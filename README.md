# WC Template Updater

**Automatically keep your WooCommerce template overrides up to date.**

When WooCommerce ships a new version, your customized template overrides fall behind. This plugin detects which templates are outdated, merges the core changes into your customizations using a 3-way git merge, and flags any conflicts for review.

## Features

- **Automatic detection** — Scans your child theme for outdated WooCommerce template overrides
- **3-way merge** — Uses git merge-file to cleanly apply upstream changes while preserving your customizations
- **Code diff viewer** — See exactly what changed between your version and WooCommerce core
- **Conflict flagging** — Unresolvable conflicts are clearly marked for manual review
- **Scheduled runs** — Set it and forget it with automatic daily/weekly runs
- **Email notifications** — Get notified when templates are updated or conflicts need attention
- **Safe deletes** — Easily remove uncustomized template overrides so WooCommerce uses its own copy

## Pro Version

The [Pro version](https://mindfulplugins.io/wc-template-updater/) adds **AI-powered conflict resolution** via Claude. When a merge conflict cannot be resolved automatically, Claude analyzes both versions and produces a merged result — no manual editing required.

Proven on version jumps like `cart/cart.php` 3.4.0 → 10.1.0 and `single-product/product-image.php` 2.6.3 → 10.5.0.

## Requirements

- WordPress 5.8+
- WooCommerce 7.0+
- PHP 7.4+
- `git` available on the server (for merge-file)

## Installation

1. Upload the `template-updater-for-woocommerce` folder to `/wp-content/plugins/`
2. Activate through the Plugins menu
3. Go to **WooCommerce > Template Updater**
4. Click **Run Now** to scan and update your templates

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).

## Author

Built by [Mindful Plugins](https://mindfulplugins.io/) / [Joseph Toscano](https://josephtoscano.io/)

=== Template Updater for WooCommerce ===
Contributors: josephtoscano
Tags: woocommerce, templates, updates, theme, overrides
Requires at least: 5.8
Tested up to: 6.9
Requires PHP: 7.4
WC requires at least: 7.0
WC tested up to: 9.4
Stable tag: 1.0.4
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Automatically keep your WooCommerce template overrides up to date using 3-way git merge.

== Description ==

When WooCommerce ships a new version, your customized template overrides fall behind. Template Updater for WooCommerce detects which templates are outdated and merges the core changes into your customizations automatically — the same way git handles code merges.

**How it works:**

1. Scans your child theme for WooCommerce template overrides
2. Compares your version against the current WooCommerce version
3. Runs a 3-way git merge: base (old WC) + yours + new WC
4. If the merge is clean, your file is updated automatically
5. If there’s a conflict, it’s flagged for your review

**Features:**

* Automatic detection of outdated template overrides
* 3-way merge using git merge-file
* Code diff viewer — see exactly what changed
* Conflict flagging with clear diagnosis
* Scheduled automatic runs (daily or weekly)
* Email notifications
* Safe delete for uncustomized overrides

**Pro Version**

The Pro version adds AI-powered conflict resolution. When a merge conflict cannot be resolved automatically, Claude (Anthropic’s AI) analyzes both versions and produces a clean merged result — no manual editing required.

[Learn more at mindfulplugins.io](https://mindfulplugins.io/wc-template-updater/)

== Installation ==

1. Upload the `template-updater-for-woocommerce` folder to `/wp-content/plugins/`
2. Activate the plugin through the Plugins menu in WordPress
3. Navigate to **WooCommerce > Template Updater**
4. Click **Run Now** to perform your first scan and update

== Frequently Asked Questions ==

= Does this work with any theme? =

Yes, as long as your theme overrides WooCommerce templates in the standard location: `yourtheme/woocommerce/`.

= What happens if a merge conflict occurs? =

The plugin flags the conflict and saves a `.conflict` file alongside your template so you can review both versions. The original file is not modified.

= Is git required? =

Yes. The `git` binary must be available on your server (most managed WordPress hosts include it). The plugin uses `git merge-file` to perform 3-way merges.

= Will this work with page builders? =

The plugin only touches PHP template files in your theme’s WooCommerce folder. It does not interact with page builders or the block editor.

== Changelog ==

= 1.0.4 =
* Fixed Plugin URI link
* Removed upgrade notice banner

= 1.0.3 =
* Added uninstall cleanup — removes all plugin options on uninstall

= 1.0.2 =
* Fixed Freemius SDK integration (set_basename wrapper, menu config)
* Gated AI settings behind Pro license
* Fixed public key and gatekeeper values

= 1.0.1 =
* First Freemius deploy

= 1.0.0 =
* Initial release

== Upgrade Notice ==

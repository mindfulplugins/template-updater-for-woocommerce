<?php
/**
 * Plugin Name:       Template Updater for WooCommerce
 * Plugin URI:        https://mindfulplugins.io/wc-template-updater/
 * Description:       Automatically keeps your WooCommerce template overrides up to date. Detects outdated templates, merges core updates into your customizations via 3-way merge, and flags unresolvable conflicts for review.
 * Version:           1.0.2
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Mindful Plugins
 * Author URI:        https://mindfulplugins.io/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       template-updater-for-woocommerce
 * Domain Path:       /languages
 * WC requires at least: 7.0
 * WC tested up to:   9.4
 */

defined( 'ABSPATH' ) || exit;

// =============================================================================
// Freemius SDK Init
// =============================================================================
if ( function_exists( 'wtu_fs' ) ) {
	wtu_fs()->set_basename( true, __FILE__ );
} else {
	if ( ! function_exists( 'wtu_fs' ) ) {
		function wtu_fs() { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Freemius convention
			global $wtu_fs; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Freemius convention

			if ( ! isset( $wtu_fs ) ) {
				// Include Freemius SDK.
				require_once dirname( __FILE__ ) . '/vendor/freemius/start.php';

				$wtu_fs = fs_dynamic_init( array( // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Freemius convention
					'id'                  => '25855',
					'slug'                => 'template-updater-for-woocommerce',
					'premium_slug'        => 'template-updater-for-woocommerce-pro',
					'type'                => 'plugin',
					'public_key'          => 'pk_177e0395877b2c5a0607414e429af',
					'is_premium'          => true,
					'premium_suffix'      => 'Pro',
					'has_premium_version' => true,
					'has_addons'          => false,
					'has_paid_plans'      => true,
					'is_org_compliant'    => true,
					'wp_org_gatekeeper'   => 'OA7#BoRiBNqdf52FvzEf!!074aRLPs8fspif$7K1#4u4Csys1fQlCecVcUTOs2mcpeVHi#C2j9d09fOTvbC0HloPT7fFee5WdS3G',
					'menu'                => array(
						'slug'       => 'template-updater-for-woocommerce',
						'first-path' => 'admin.php?page=template-updater-for-woocommerce',
						'parent'     => array(
							'slug' => 'woocommerce',
						),
						'support'    => false,
					),
				) );
			}

			return $wtu_fs;
		}

		// Init Freemius.
		wtu_fs();
		// Signal that SDK was initiated.
		do_action( 'wtu_fs_loaded' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Freemius convention
	}
}

// =============================================================================
// Constants
// =============================================================================
define( 'WC_TU_VERSION', '1.0.2' );
define( 'WC_TU_FILE',    __FILE__ );
define( 'WC_TU_DIR',     plugin_dir_path( __FILE__ ) );
define( 'WC_TU_URL',     plugin_dir_url( __FILE__ ) );

add_action( 'plugins_loaded', function () {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', function () {
			echo '<div class="notice notice-error"><p><strong>Template Updater for WooCommerce</strong> requires WooCommerce to be active.</p></div>';
		} );
		return;
	}

	require_once WC_TU_DIR . 'includes/class-wc-tu-scanner.php';
	require_once WC_TU_DIR . 'includes/class-wc-tu-fetcher.php';
	require_once WC_TU_DIR . 'includes/class-wc-tu-merger.php';
	if ( wtu_fs()->can_use_premium_code() ) {
		require_once WC_TU_DIR . 'includes/class-wc-tu-ai-resolver__premium_only.php';
	}
	require_once WC_TU_DIR . 'includes/class-wc-tu-runner.php';
	require_once WC_TU_DIR . 'includes/class-wc-tu-cleaner.php';
	require_once WC_TU_DIR . 'includes/class-wc-tu-cron.php';
	require_once WC_TU_DIR . 'includes/class-wc-tu-notifier.php';

	new WC_TU_Cron();
	new WC_TU_Cleaner();

	if ( is_admin() ) {
		require_once WC_TU_DIR . 'includes/class-wc-tu-admin.php';
		new WC_TU_Admin();
	}
} );

register_activation_hook( __FILE__, function () {
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-wc-tu-cron.php';
	WC_TU_Cron::activate();
} );

register_deactivation_hook( __FILE__, function () {
	WC_TU_Cron::deactivate();
} );

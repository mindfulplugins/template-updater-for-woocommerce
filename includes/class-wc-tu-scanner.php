<?php
defined( 'ABSPATH' ) || exit;

/**
 * Scans the active theme's /woocommerce/ directory for template overrides.
 * Also checks the parent theme when a child theme is active (WooCommerce itself
 * searches both directories via locate_template()).
 *
 * get_outdated_overrides() — only files behind the installed WC core version.
 * get_all_overrides()      — every .php file that maps to a WC core template,
 *                            regardless of version. Used by WC_TU_Cleaner.
 */
class WC_TU_Scanner {

	/**
	 * Returns overrides whose @version is behind the current WooCommerce core template.
	 */
	public function get_outdated_overrides(): array {
		return array_values( array_filter(
			$this->scan_overrides(),
			fn( $o ) => version_compare( $o['base_version'], $o['core_version'], '<' )
		) );
	}

	/**
	 * Returns ALL overrides that map to a WC core template, including up-to-date ones.
	 * Used by WC_TU_Cleaner to check for uncustomized copies.
	 */
	public function get_all_overrides(): array {
		return $this->scan_overrides();
	}

	// -------------------------------------------------------------------------
	// Private
	// -------------------------------------------------------------------------

	private function scan_overrides(): array {
		$core_tpl_dir = trailingslashit( WC()->plugin_path() ) . 'templates/';

		// Scan child theme first, then parent. WooCommerce's locate_template() does
		// the same — child overrides parent for the same relative path.
		$theme_dirs = array_unique( [
			trailingslashit( get_stylesheet_directory() ) . 'woocommerce/',
			trailingslashit( get_template_directory() )   . 'woocommerce/',
		] );

		$overrides  = [];
		$seen_paths = []; // relative path → true; child takes precedence over parent.

		foreach ( $theme_dirs as $theme_wc_dir ) {
			if ( ! is_dir( $theme_wc_dir ) ) {
				continue;
			}

			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $theme_wc_dir, RecursiveDirectoryIterator::SKIP_DOTS )
			);

			foreach ( $iterator as $file ) {
				if ( $file->getExtension() !== 'php' ) {
					continue;
				}

				$absolute_path = $file->getPathname();

				// Skip backup and conflict files written by this plugin.
				if ( preg_match( '/\.(bak\.|conflict)/', $absolute_path ) ) {
					continue;
				}

				// Normalize path separator and strip theme dir prefix.
				$relative_path = str_replace( '\\', '/', $absolute_path );
				$relative_path = str_replace( str_replace( '\\', '/', $theme_wc_dir ), '', $relative_path );
				$relative_path = ltrim( $relative_path, '/' );

				// Child theme takes precedence — skip if already found in child.
				if ( isset( $seen_paths[ $relative_path ] ) ) {
					continue;
				}

				// Only process files that actually exist as WC core templates.
				$core_path = $core_tpl_dir . $relative_path;
				if ( ! file_exists( $core_path ) ) {
					continue;
				}

				$theme_content = file_get_contents( $absolute_path );
				$base_version  = $this->extract_version( $theme_content );

				if ( ! $base_version ) {
					continue;
				}

				// Compare against the CORE template's own @version, not WC_VERSION.
				// WC template versions only bump when that specific template changes.
				$core_content = file_get_contents( $core_path );
				$core_version = $this->extract_version( $core_content );

				if ( ! $core_version ) {
					continue;
				}

				$seen_paths[ $relative_path ] = true;

				$overrides[] = [
					'path'          => $relative_path,
					'absolute_path' => $absolute_path,
					'core_path'     => $core_path,
					'base_version'  => $base_version,
					'core_version'  => $core_version,
				];
			}
		}

		return $overrides;
	}

	/**
	 * Extracts the @version string from a WooCommerce template file header.
	 */
	private function extract_version( string $content ): ?string {
		if ( preg_match( '/@version\s+([\d.]+)/', $content, $matches ) ) {
			return $matches[1];
		}
		return null;
	}
}

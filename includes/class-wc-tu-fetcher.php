<?php
defined( 'ABSPATH' ) || exit;

/**
 * Fetches WooCommerce template files from the official GitHub repository
 * and caches them locally to avoid redundant HTTP requests.
 *
 * WooCommerce migrated to a monorepo around version 6.6, changing the
 * template path inside the repository. This class handles both layouts.
 */
class WC_TU_Fetcher {

	/**
	 * Version at which WC moved templates into plugins/woocommerce/templates/.
	 */
	const MONOREPO_CUTOVER = '6.6.0';

	/** @var string Absolute path to the local cache directory. */
	private string $cache_dir;

	public function __construct() {
		$upload_dir      = wp_upload_dir();
		$this->cache_dir = trailingslashit( $upload_dir['basedir'] ) . 'template-updater-for-woocommerce/';
		wp_mkdir_p( $this->cache_dir );

		// Prevent direct web access to cached files.
		$htaccess = $this->cache_dir . '.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			file_put_contents( $htaccess, 'deny from all' );
		}
	}

	/**
	 * Returns the content of a WooCommerce template at a specific version.
	 * Uses local cache when available.
	 *
	 * @param string $version       WooCommerce version string, e.g. "9.4.0".
	 * @param string $template_path Relative template path, e.g. "cart/cart.php".
	 * @return string|null File content, or null on failure.
	 */
	public function fetch( string $version, string $template_path ): ?string {
		$cached = $this->get_cached( $version, $template_path );
		if ( $cached !== null ) {
			return $cached;
		}

		$content = $this->fetch_from_github( $version, $template_path );
		if ( $content !== null ) {
			$this->store_cache( $version, $template_path, $content );
		}

		return $content;
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	private function fetch_from_github( string $version, string $template_path ): ?string {
		foreach ( $this->candidate_urls( $version, $template_path ) as $url ) {
			$response = wp_remote_get( $url, [ 'timeout' => 20 ] );

			if ( is_wp_error( $response ) ) {
				continue;
			}

			if ( wp_remote_retrieve_response_code( $response ) === 200 ) {
				return wp_remote_retrieve_body( $response );
			}
		}

		return null;
	}

	/**
	 * Returns candidate raw-GitHub URLs to try, accounting for the monorepo
	 * restructure. Both paths are tried to handle edge cases near the cutover.
	 */
	private function candidate_urls( string $version, string $template_path ): array {
		$tag  = $version; // WC tags use bare version numbers, e.g. "9.4.0".
		$path = ltrim( $template_path, '/' );

		if ( version_compare( $version, self::MONOREPO_CUTOVER, '>=' ) ) {
			return [
				"https://raw.githubusercontent.com/woocommerce/woocommerce/{$tag}/plugins/woocommerce/templates/{$path}",
				"https://raw.githubusercontent.com/woocommerce/woocommerce/{$tag}/templates/{$path}",
			];
		}

		return [
			"https://raw.githubusercontent.com/woocommerce/woocommerce/{$tag}/templates/{$path}",
			"https://raw.githubusercontent.com/woocommerce/woocommerce/{$tag}/plugins/woocommerce/templates/{$path}",
		];
	}

	private function get_cached( string $version, string $template_path ): ?string {
		$path = $this->cache_path( $version, $template_path );
		return file_exists( $path ) ? file_get_contents( $path ) : null;
	}

	private function store_cache( string $version, string $template_path, string $content ): void {
		$path = $this->cache_path( $version, $template_path );
		wp_mkdir_p( dirname( $path ) );
		file_put_contents( $path, $content );
	}

	private function cache_path( string $version, string $template_path ): string {
		return $this->cache_dir . $version . '/' . ltrim( $template_path, '/' );
	}
}

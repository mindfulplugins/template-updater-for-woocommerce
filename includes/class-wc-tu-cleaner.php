<?php
defined( 'ABSPATH' ) || exit;

/**
 * Detects and removes WooCommerce template overrides that were never customized
 * (i.e. code-identical to the original WC template — ignoring comments and whitespace).
 *
 * Workflow:
 *   1. run_scan()   — async (WP-Cron). Compares every override against its base
 *                     version fetched from GitHub. Stores results in wc_tu_clean_results.
 *   2. remove()     — synchronous. Called directly from the admin action handler
 *                     after the user confirms. Deletes files and prunes empty dirs.
 *
 * Progress is written to wc_tu_clean_progress during the scan so the admin UI
 * can show a live progress bar. Deleted on scan completion.
 */
class WC_TU_Cleaner {

	const SCAN_HOOK = 'wc_tu_clean_scan_async';

	public function __construct() {
		add_action( self::SCAN_HOOK, [ $this, 'run_scan' ] );
	}

	// -------------------------------------------------------------------------
	// Async scan (WP-Cron)
	// -------------------------------------------------------------------------

	/**
	 * Compare every theme override against its WC reference to detect files
	 * that were never customized and can safely be deleted.
	 *
	 * Two comparison strategies, chosen per file:
	 *
	 *   a) Up-to-date (base_version == core_version):
	 *      Compare against the INSTALLED WC core template on disk.
	 *      No network request needed. Immune to stale GitHub tags or
	 *      WC patches that bump template content without bumping @version.
	 *
	 *   b) Outdated (base_version < core_version):
	 *      Fetch the original WC template at the version the file claims
	 *      to be based on (from GitHub). Detects vanilla copies that were
	 *      never touched even though WC has since moved on.
	 *
	 * Files are compared using code-aware normalization (see normalize()) so
	 * comment/whitespace-only differences are ignored.
	 */
	public function run_scan(): void {
		$scanner = new WC_TU_Scanner();
		$fetcher = new WC_TU_Fetcher();

		$all   = $scanner->get_all_overrides();
		$total = count( $all );

		$uncustomized    = [];
		$customized      = []; // has real code changes vs original
		$skipped_fetch   = []; // Strategy B: GitHub fetch returned null
		$skipped_unread  = []; // file_get_contents failed (read error)

		foreach ( $all as $i => $override ) {
			// Write live progress so the admin UI can show per-file status.
			update_option( 'wc_tu_clean_progress', [
				'total'   => $total,
				'current' => $i + 1,
				'path'    => $override['path'],
			] );

			$ours = file_get_contents( $override['absolute_path'] );
			if ( $ours === false ) {
				$skipped_unread[] = $override['path'];
				continue;
			}

			$is_current = version_compare( $override['base_version'], $override['core_version'], '>=' );

			if ( $is_current ) {
				// ---------------------------------------------------------------
				// Strategy A: file is at (or beyond) core version.
				// Primary: compare against the installed WC template on disk.
				// ---------------------------------------------------------------
				$reference = file_get_contents( $override['core_path'] );
				if ( $reference === false ) {
					$skipped_unread[] = $override['path'];
					continue;
				}

				if ( $this->normalize( $ours ) === $this->normalize( $reference ) ) {
					$uncustomized[] = $override;
					continue;
				}

				// ---------------------------------------------------------------
				// Strategy A fallback: the file differs from the installed WC
				// template. But WC sometimes patches files without bumping @version
				// (e.g. review.php — schema.org microdata removed silently).
				// Cross-check GitHub at the same tag: if our file matches what WC
				// shipped at that version, the user never edited it.
				// ---------------------------------------------------------------
				$github_ref = $fetcher->fetch( $override['base_version'], $override['path'] );
				if ( $github_ref === null ) {
					// Can't verify — skip rather than falsely flag as customized.
					$skipped_fetch[] = [
						'path'         => $override['path'],
						'base_version' => $override['base_version'],
					];
				} elseif ( $this->normalize( $ours ) === $this->normalize( $github_ref ) ) {
					// Matches GitHub → WC silently patched this file. Uncustomized.
					$uncustomized[] = $override;
				} else {
					// Differs from both installed WC and GitHub → real edits present.
					// Store full override (not just path) so the diff viewer can use it.
					$customized[] = $override;
				}
				continue;
			}

			// ---------------------------------------------------------------
			// Strategy B: file is outdated — fetch from GitHub at the version
			// the file claims to be based on and compare against that.
			// ---------------------------------------------------------------
			$reference = $fetcher->fetch( $override['base_version'], $override['path'] );
			if ( $reference === null ) {
				// GitHub fetch failed (timeout, rate-limit, bad tag, etc.).
				$skipped_fetch[] = [
					'path'         => $override['path'],
					'base_version' => $override['base_version'],
				];
				continue;
			}

			if ( $this->normalize( $ours ) === $this->normalize( $reference ) ) {
				$uncustomized[] = $override;
			} else {
				// Store full override (not just path) so the diff viewer can use it.
				$customized[] = $override;
			}
		}

		// Clear progress and store final results.
		delete_option( 'wc_tu_clean_progress' );
		update_option( 'wc_tu_clean_results', [
			'time'          => time(),
			'uncustomized'  => $uncustomized,
			'scan_total'    => $total,
			'customized'    => $customized,    // paths with real code changes
			'skipped_fetch' => $skipped_fetch, // [{path, base_version}] — GitHub failed
			'skipped_unread'=> $skipped_unread,// paths we couldn't read at all
		] );
	}

	// -------------------------------------------------------------------------
	// Synchronous removal (called directly from admin action)
	// -------------------------------------------------------------------------

	/**
	 * Delete all uncustomized files recorded in wc_tu_clean_results and remove
	 * any directories that become empty as a result.
	 *
	 * Also removes any .bak.* backup files and .conflict files left behind by
	 * previous runner passes that incorrectly treated these files as customized
	 * (e.g. when only the byte-for-byte comparison was used).
	 *
	 * @return array { removed: string[], failed: string[], dirs_removed: int }
	 */
	public function remove(): array {
		$results = get_option( 'wc_tu_clean_results', null );

		if ( ! $results || empty( $results['uncustomized'] ) ) {
			return [ 'removed' => [], 'failed' => [], 'dirs_removed' => 0 ];
		}

		$removed = [];
		$failed  = []; // paths that could not be deleted (likely a permissions issue)
		$dirs    = [];

		// Support both child and parent theme paths for the directory-pruning
		// stop boundary — files may live in either location.
		$stylesheet_stop = rtrim( str_replace( '\\', '/', trailingslashit( get_stylesheet_directory() ) . 'woocommerce' ), '/' );
		$template_stop   = rtrim( str_replace( '\\', '/', trailingslashit( get_template_directory() )   . 'woocommerce' ), '/' );

		foreach ( $results['uncustomized'] as $override ) {
			$abs = $override['absolute_path'];

			if ( ! file_exists( $abs ) ) {
				// Already gone (deleted by the runner or manually) — treat as removed.
				$removed[] = $override['path'];
			} else {
				wp_delete_file( $abs );
				if ( ! file_exists( $abs ) ) {
					$removed[] = $override['path'];
					$dirs[]    = str_replace( '\\', '/', dirname( $abs ) );
				} else {
					// wp_delete_file() failed — almost always a file-permissions issue
					// (web server lacks write access to the theme directory).
					$failed[] = $override['path'];
				}
			}

			// Always clean up sidecar files regardless of whether the main file
			// was present — these are orphaned leftovers from previous runs.
			foreach ( glob( $abs . '.bak.*' ) ?: [] as $bak ) {
				wp_delete_file( $bak );
			}
			if ( file_exists( $abs . '.conflict' ) ) {
				wp_delete_file( $abs . '.conflict' );
			}
		}

		// Prune empty directories bottom-up, never going above woocommerce/.
		$dirs_removed = 0;
		$dirs         = array_unique( $dirs );

		// Sort deepest paths first so nested empties are removed before parents.
		usort( $dirs, fn( $a, $b ) => strlen( $b ) - strlen( $a ) );

		foreach ( $dirs as $dir ) {
			// Choose the correct stop boundary for this dir (child vs parent theme).
			$dir_norm = str_replace( '\\', '/', $dir );
			if ( strpos( $dir_norm, $stylesheet_stop ) === 0 ) {
				$stop = $stylesheet_stop;
			} elseif ( strpos( $dir_norm, $template_stop ) === 0 ) {
				$stop = $template_stop;
			} else {
				continue; // Safety: never prune outside known theme directories.
			}

			while (
				strlen( $dir ) > strlen( $stop ) &&
				strpos( $dir, $stop ) === 0 &&
				is_dir( $dir ) &&
				count( scandir( $dir ) ) === 2 // only . and ..
			) {
				rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir,WordPressVIPMinimum.Functions.RestrictedFunctions.directory_rmdir
				$dirs_removed++;
				$dir = str_replace( '\\', '/', dirname( $dir ) );
			}
		}

		// Clear scan results — they're stale now.
		delete_option( 'wc_tu_clean_results' );

		return [
			'removed'      => $removed,
			'failed'       => $failed,
			'dirs_removed' => $dirs_removed,
		];
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Code-aware normalization: strips all PHP comments (single-line //, #,
	 * block /* *\/, and docblocks /** *\/) and collapses all whitespace to a
	 * single space before comparing file contents.
	 *
	 * This means two files that differ ONLY in comments, the @version tag,
	 * indentation, or blank lines will compare as equal — so a template the
	 * user never touched but that WC reformatted between versions is still
	 * correctly identified as uncustomized.
	 *
	 * Falls back to basic line-ending normalization if the tokenizer fails.
	 */
	private function normalize( string $s ): string {
		$tokens = @token_get_all( $s ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( empty( $tokens ) ) {
			return trim( str_replace( [ "\r\n", "\r" ], "\n", $s ) );
		}

		$out = '';
		foreach ( $tokens as $token ) {
			if ( ! is_array( $token ) ) {
				$out .= $token;
				continue;
			}
			[ $type, $text ] = $token;
			// Drop all comment tokens (covers //, #, /* */, and /** */).
			if ( $type === T_COMMENT || $type === T_DOC_COMMENT ) {
				continue;
			}
			// Collapse all whitespace (spaces, tabs, newlines) to a single space.
			if ( $type === T_WHITESPACE ) {
				$out .= ' ';
				continue;
			}
			$out .= $text;
		}

		// Collapse any runs of spaces produced by stripping comments/whitespace.
		return trim( preg_replace( '/  +/', ' ', $out ) );
	}
}

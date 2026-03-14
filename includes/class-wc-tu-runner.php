<?php
defined( 'ABSPATH' ) || exit;

/**
 * Orchestrates a full update pass:
 *   scan → fetch base + new → uncustomized check → 3-way merge → apply/delete/conflict
 *
 * Results are stored in the wc_tu_last_run option.
 * Live progress is written to wc_tu_run_progress during execution.
 * Set wc_tu_stop_requested = true to halt after the current template.
 */
class WC_TU_Runner {

	private WC_TU_Scanner  $scanner;
	private WC_TU_Fetcher  $fetcher;
	private WC_TU_Merger   $merger;
	private WC_TU_Notifier $notifier;

	private int   $progress_total     = 0;
	private int   $progress_current   = 0;
	private array $progress_completed = [];

	public function __construct() {
		$this->scanner  = new WC_TU_Scanner();
		$this->fetcher  = new WC_TU_Fetcher();
		$this->merger   = new WC_TU_Merger();
		$this->notifier = new WC_TU_Notifier();
	}

	public function run(): array {
		$results = [
			'updated'   => [],
			'deleted'   => [],
			'conflicts' => [],
			'errors'    => [],
		];

		$overrides = $this->scanner->get_outdated_overrides();

		$this->progress_total     = count( $overrides );
		$this->progress_current   = 0;
		$this->progress_completed = [];

		$this->set_progress( '', 'Scanning for outdated overrides...' );

		foreach ( $overrides as $override ) {
			// Honor a stop request set via the admin "Stop" button.
			if ( get_option( 'wc_tu_stop_requested', false ) ) {
				delete_option( 'wc_tu_stop_requested' );
				break;
			}

			$this->progress_current++;
			$item = $this->process( $override );
			$key  = $item['status']; // updated | deleted | conflicts | errors
			$results[ $key ][] = $item;

			// Accumulate completed items (updated or deleted) for the live table.
			if ( $key === 'updated' || $key === 'deleted' ) {
				$this->progress_completed[] = [
					'status'       => $key,
					'path'         => $item['path'],
					'from_version' => $item['from_version'],
					'to_version'   => $item['to_version'],
					'ai_resolved'  => $item['ai_resolved'] ?? false,
				];
				$label = $key === 'deleted' ? 'Deleted (uncustomized) ✓' : 'Updated ✓';
				$this->set_progress( $item['path'], $label );
			}
		}

		delete_option( 'wc_tu_run_progress' );

		update_option( 'wc_tu_last_run', [
			'time'    => time(),
			'results' => $results,
		] );

		if ( ! empty( $results['updated'] ) || ! empty( $results['deleted'] ) || ! empty( $results['conflicts'] ) || ! empty( $results['errors'] ) ) {
			$this->notifier->send( $results );
		}

		return $results;
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	private function process( array $override ): array {
		$path         = $override['path'];
		$base_version = $override['base_version'];
		$core_version = $override['core_version'];
		$abs_path     = $override['absolute_path'];
		$core_path    = $override['core_path'];

		// Fetch base from GitHub — the WC template at the version our file claims to be.
		$this->set_progress( $path, "Fetching base template v{$base_version} from GitHub..." );
		$base = $this->fetcher->fetch( $base_version, $path );
		if ( $base === null ) {
			return $this->error( $path, "Could not fetch WC base template v{$base_version} from GitHub." );
		}

		// Read current theme override.
		$ours = file_get_contents( $abs_path );
		if ( $ours === false ) {
			return $this->error( $path, "Could not read theme file: {$abs_path}" );
		}

		// -----------------------------------------------------------------------
		// Uncustomized check: if the theme file's actual PHP code is identical
		// to the original WC template (ignoring comments & whitespace), it was
		// never edited. Delete it — WooCommerce will fall back to its own copy
		// automatically. This saves API credits and leaves only genuinely
		// customized files.
		// -----------------------------------------------------------------------
		if ( self::normalize( $ours ) === self::normalize( $base ) ) {
			$this->set_progress( $path, 'Uncustomized copy — deleting...' );
			return $this->delete_uncustomized( $path, $abs_path, $base_version, $core_version );
		}

		// Read the current installed WC template ("theirs").
		$theirs = file_get_contents( $core_path );
		if ( $theirs === false ) {
			return $this->error( $path, "Could not read core WC template: {$core_path}" );
		}

		// 3-way merge.
		$this->set_progress( $path, 'Running 3-way merge...' );
		$merge = $this->merger->merge( $ours, $base, $theirs );

		if ( $merge['method'] === 'unavailable' ) {
			return $this->error( $path, $merge['error'] );
		}

		if ( $merge['has_conflicts'] ) {
			$resolver = new WC_TU_AI_Resolver( get_option( 'wc_tu_anthropic_api_key', '' ) );
			if ( $resolver->is_available() ) {
				$this->set_progress( $path, 'Conflict detected — resolving with Claude AI...' );
				$ai_result = $resolver->resolve( $ours, $base, $theirs, $merge['content'], $path );
				if ( $ai_result['text'] !== null ) {
					return $this->apply( $path, $abs_path, $base_version, $core_version, $ai_result['text'], true );
				}
				$ai_error = $ai_result['error'];
			} else {
				$ai_error = 'No API key — configure one in Settings';
			}
			return $this->stage_conflict( $path, $abs_path, $base_version, $core_version, $merge['content'], $ai_error );
		}

		return $this->apply( $path, $abs_path, $base_version, $core_version, $merge['content'] );
	}

	/**
	 * Delete a theme override that is identical to its original WC base template.
	 * Also removes any .bak.* and .conflict sidecar files left by earlier runs,
	 * then prunes empty parent directories up to (but not including) woocommerce/.
	 */
	private function delete_uncustomized( string $path, string $abs_path, string $base_version, string $core_version ): array {
		wp_delete_file( $abs_path );
		if ( file_exists( $abs_path ) ) {
			return $this->error( $path, "File is uncustomized but could not be deleted: {$abs_path}. Check file permissions." );
		}

		// Remove any backup or conflict files left over from previous runner passes.
		foreach ( glob( $abs_path . '.bak.*' ) ?: [] as $bak ) {
			wp_delete_file( $bak );
		}
		if ( file_exists( $abs_path . '.conflict' ) ) {
			wp_delete_file( $abs_path . '.conflict' );
		}

		// Prune empty parent directories.
		$stop_dir = rtrim( str_replace( '\\', '/', trailingslashit( get_stylesheet_directory() ) . 'woocommerce' ), '/' );
		$dir      = str_replace( '\\', '/', dirname( $abs_path ) );

		while (
			strlen( $dir ) > strlen( $stop_dir ) &&
			strpos( $dir, $stop_dir ) === 0 &&
			is_dir( $dir ) &&
			count( scandir( $dir ) ) === 2
		) {
			rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir,WordPressVIPMinimum.Functions.RestrictedFunctions.directory_rmdir
			$dir = str_replace( '\\', '/', dirname( $dir ) );
		}

		return [
			'status'       => 'deleted',
			'path'         => $path,
			'from_version' => $base_version,
			'to_version'   => $core_version,
			'ai_resolved'  => false,
		];
	}

	/**
	 * Auto-applies a clean merge result to the theme file.
	 * Keeps exactly one backup (.bak.{from_version}), removing any previous ones.
	 */
	private function apply( string $path, string $abs_path, string $from, string $to, string $content, bool $ai_resolved = false ): array {
		// Remove any previous backups for this file so we only ever keep one.
		foreach ( glob( $abs_path . '.bak.*' ) ?: [] as $old_backup ) {
			wp_delete_file( $old_backup );
		}

		$backup = $abs_path . '.bak.' . $from;
		copy( $abs_path, $backup );

		// Bump @version tag to the new WC version.
		$content = preg_replace( '/(@version\s+)[\d.]+/', '${1}' . $to, $content );

		if ( file_put_contents( $abs_path, $content ) === false ) {
			return $this->error( $path, "Merge succeeded but could not write to {$abs_path}. Check file permissions." );
		}

		return [
			'status'       => 'updated',
			'path'         => $path,
			'from_version' => $from,
			'to_version'   => $to,
			'backup'       => $backup,
			'ai_resolved'  => $ai_resolved,
		];
	}

	/**
	 * Writes conflict-marked content to a .conflict file for admin review.
	 */
	private function stage_conflict( string $path, string $abs_path, string $from, string $to, string $content, ?string $ai_error = null ): array {
		$conflict_file = $abs_path . '.conflict';
		file_put_contents( $conflict_file, $content );

		return [
			'status'        => 'conflicts',
			'path'          => $path,
			'from_version'  => $from,
			'to_version'    => $to,
			'conflict_file' => $conflict_file,
			'ai_error'      => $ai_error,
		];
	}

	private function error( string $path, string $message ): array {
		return [
			'status'  => 'errors',
			'path'    => $path,
			'message' => $message,
		];
	}

	private function set_progress( string $path, string $step ): void {
		update_option( 'wc_tu_run_progress', [
			'total'     => $this->progress_total,
			'current'   => $this->progress_current,
			'path'      => $path,
			'step'      => $step,
			'completed' => $this->progress_completed,
		] );
	}

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
	private static function normalize( string $s ): string {
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

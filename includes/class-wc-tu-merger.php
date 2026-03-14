<?php
defined( 'ABSPATH' ) || exit;

/**
 * Performs a 3-way merge of WooCommerce template content using git merge-file.
 *
 * Uses proc_open() for reliable stdout/stderr capture. Falls back to exec()
 * if proc_open is unavailable. Returns detailed error info when git cannot run
 * so the admin UI can surface a diagnosis rather than silent failures.
 *
 * 3-way merge contract:
 *   base   = original WC template at the version the theme file was based on
 *   ours   = the customized theme override (our work)
 *   theirs = the new WC template we want to upgrade to
 */
class WC_TU_Merger {

	/** @var string|null Resolved path to git binary, or null if unavailable. */
	private ?string $git_bin = null;

	/** @var bool Whether we've already checked for git. */
	private bool $git_checked = false;

	// -------------------------------------------------------------------------
	// Public API
	// -------------------------------------------------------------------------

	/**
	 * @return array {
	 *   content      string  Merged file content (may include conflict markers).
	 *   has_conflicts bool   True when conflict markers are present.
	 *   method       string  'git', 'exec', or 'unavailable'.
	 *   error        string  Human-readable error when method === 'unavailable'.
	 *   stderr       string  Raw stderr from git (empty on success).
	 * }
	 */
	public function merge( string $ours, string $base, string $theirs ): array {
		$git = $this->find_git();

		if ( $git === null ) {
			return $this->unavailable( 'git binary not found. Install git or add it to the server PATH.' );
		}

		// Prefer proc_open — captures stderr and is less likely to be blocked.
		if ( $this->proc_open_available() ) {
			return $this->merge_via_proc_open( $git, $ours, $base, $theirs );
		}

		if ( $this->exec_available() ) {
			return $this->merge_via_exec( $git, $ours, $base, $theirs );
		}

		return $this->unavailable( 'Both proc_open() and exec() are disabled in PHP config. Enable at least one to allow git merge-file.' );
	}

	/**
	 * Returns true if git can be found AND at least one execution method is available.
	 */
	public function git_available(): bool {
		return $this->find_git() !== null && ( $this->proc_open_available() || $this->exec_available() );
	}

	/**
	 * Runs `git --version` and returns the output, or an error string.
	 * Used by the admin pre-flight to confirm git actually executes.
	 */
	public function git_version(): string {
		$git = $this->find_git();
		if ( $git === null ) {
			return 'git not found';
		}

		if ( $this->proc_open_available() ) {
			$descriptors = [ 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ];
			$pipes       = [];
			$process     = proc_open( escapeshellarg( $git ) . ' --version', $descriptors, $pipes ); // phpcs:ignore Generic.PHP.ForbiddenFunctions.Found
			if ( ! is_resource( $process ) ) {
				return 'proc_open failed';
			}
			$out = trim( stream_get_contents( $pipes[1] ) );
			fclose( $pipes[1] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			fclose( $pipes[2] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			proc_close( $process );
			return $out ?: 'no output';
		}

		if ( $this->exec_available() ) {
			$out = [];
			exec( escapeshellarg( $git ) . ' --version 2>/dev/null', $out );
			return $out[0] ?? 'no output';
		}

		return 'no execution method available';
	}

	// -------------------------------------------------------------------------
	// Private — merge implementations
	// -------------------------------------------------------------------------

	private function merge_via_proc_open( string $git, string $ours, string $base, string $theirs ): array {
		[ $tmp, $ours_file, $base_file, $theirs_file ] = $this->write_tmp_files( $ours, $base, $theirs );

		$cmd = sprintf(
			'%s merge-file -p %s %s %s',
			escapeshellarg( $git ),
			escapeshellarg( $ours_file ),
			escapeshellarg( $base_file ),
			escapeshellarg( $theirs_file )
		);

		$descriptors = [
			0 => [ 'pipe', 'r' ],
			1 => [ 'pipe', 'w' ],
			2 => [ 'pipe', 'w' ],
		];
		$pipes   = [];
		$process = proc_open( $cmd, $descriptors, $pipes ); // phpcs:ignore Generic.PHP.ForbiddenFunctions.Found

		if ( ! is_resource( $process ) ) {
			$this->cleanup_tmp( $tmp, $ours_file, $base_file, $theirs_file );
			return $this->unavailable( 'proc_open() failed to launch git. Check server permissions.' );
		}

		fclose( $pipes[0] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		$content = stream_get_contents( $pipes[1] );
		$stderr  = trim( stream_get_contents( $pipes[2] ) );
		fclose( $pipes[1] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $pipes[2] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		$exit_code = proc_close( $process );

		$this->cleanup_tmp( $tmp, $ours_file, $base_file, $theirs_file );

		// Empty output + non-zero exit usually means git failed to run at all.
		if ( $content === '' && $exit_code !== 0 ) {
			return $this->unavailable(
				'git produced no output (exit ' . $exit_code . ').' .
				( $stderr ? ' stderr: ' . $stderr : '' )
			);
		}

		return $this->build_result( $content, $exit_code, $stderr, 'proc_open' );
	}

	private function merge_via_exec( string $git, string $ours, string $base, string $theirs ): array {
		[ $tmp, $ours_file, $base_file, $theirs_file ] = $this->write_tmp_files( $ours, $base, $theirs );

		$cmd = sprintf(
			'%s merge-file -p %s %s %s 2>/dev/null',
			escapeshellarg( $git ),
			escapeshellarg( $ours_file ),
			escapeshellarg( $base_file ),
			escapeshellarg( $theirs_file )
		);

		$output_lines = [];
		exec( $cmd, $output_lines, $exit_code );
		$content = implode( "\n", $output_lines );

		$this->cleanup_tmp( $tmp, $ours_file, $base_file, $theirs_file );

		if ( $content === '' && $exit_code !== 0 ) {
			return $this->unavailable( 'git produced no output via exec (exit ' . $exit_code . '). Try enabling proc_open().' );
		}

		return $this->build_result( $content, $exit_code, '', 'exec' );
	}

	// -------------------------------------------------------------------------
	// Private — helpers
	// -------------------------------------------------------------------------

	private function build_result( string $content, int $exit_code, string $stderr, string $method ): array {
		$has_conflicts = ( $exit_code !== 0 ) || ( strpos( $content, '<<<<<<<' ) !== false );

		return [
			'content'       => $content,
			'has_conflicts' => $has_conflicts,
			'method'        => $method,
			'stderr'        => $stderr,
		];
	}

	private function unavailable( string $error ): array {
		return [
			'content'       => '',
			'has_conflicts' => true,
			'method'        => 'unavailable',
			'error'         => $error,
			'stderr'        => '',
		];
	}

	private function write_tmp_files( string $ours, string $base, string $theirs ): array {
		$tmp = sys_get_temp_dir() . '/wc-tu-' . uniqid( '', true ) . '/';
		mkdir( $tmp, 0700, true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir

		$ours_file   = $tmp . 'ours.php';
		$base_file   = $tmp . 'base.php';
		$theirs_file = $tmp . 'theirs.php';

		file_put_contents( $ours_file,   $ours );
		file_put_contents( $base_file,   $base );
		file_put_contents( $theirs_file, $theirs );

		return [ $tmp, $ours_file, $base_file, $theirs_file ];
	}

	private function cleanup_tmp( string $tmp, string ...$files ): void {
		foreach ( $files as $f ) {
			wp_delete_file( $f );
		}
		@rmdir( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions.file_system_operations_rmdir,WordPressVIPMinimum.Functions.RestrictedFunctions.directory_rmdir
	}

	private function proc_open_available(): bool {
		if ( ! function_exists( 'proc_open' ) ) {
			return false;
		}
		$disabled = array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) );
		return ! in_array( 'proc_open', $disabled, true );
	}

	private function exec_available(): bool {
		if ( ! function_exists( 'exec' ) ) {
			return false;
		}
		$disabled = array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) );
		return ! in_array( 'exec', $disabled, true );
	}

	private function find_git(): ?string {
		if ( $this->git_checked ) {
			return $this->git_bin;
		}

		$this->git_checked = true;

		$candidates = [ '/usr/bin/git', '/usr/local/bin/git', '/bin/git' ];

		foreach ( $candidates as $path ) {
			if ( is_executable( $path ) ) {
				$this->git_bin = $path;
				return $path;
			}
		}

		// Try exec/proc_open to resolve via PATH.
		if ( $this->exec_available() ) {
			$output = [];
			exec( 'which git 2>/dev/null', $output, $code );
			if ( $code === 0 && ! empty( $output[0] ) ) {
				$path = trim( $output[0] );
				if ( is_executable( $path ) ) {
					$this->git_bin = $path;
					return $path;
				}
			}
		}

		return null;
	}
}

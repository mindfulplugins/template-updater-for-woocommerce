<?php
defined( 'ABSPATH' ) || exit;

/**
 * Registers the WooCommerce > Template Updater admin page.
 *
 * Shows:
 *   - Pre-flight checks (git availability, AI resolver status)
 *   - AI / API key settings
 *   - Run Now button (async via WP-Cron)
 *   - Last run summary (updated / conflicts / errors)
 *   - Housekeeping: scan + remove uncustomized template copies
 *   - Currently outdated overrides
 */
class WC_TU_Admin {

	public function __construct() {
		add_action( 'admin_menu',                           [ $this, 'register_menu' ], 999 );
		add_action( 'admin_post_wc_tu_run_now',             [ $this, 'handle_run_now' ] );
		add_action( 'admin_post_wc_tu_stop_run',            [ $this, 'handle_stop_run' ] );
		add_action( 'admin_post_wc_tu_force_reset',         [ $this, 'handle_force_reset' ] );
		add_action( 'admin_post_wc_tu_save_settings',       [ $this, 'handle_save_settings' ] );
		add_action( 'admin_post_wc_tu_clear_conflicts',     [ $this, 'handle_clear_conflicts' ] );
		add_action( 'admin_post_wc_tu_scan_uncustomized',   [ $this, 'handle_scan_uncustomized' ] );
		add_action( 'admin_post_wc_tu_remove_uncustomized', [ $this, 'handle_remove_uncustomized' ] );
		add_action( 'admin_post_wc_tu_clean_bak_files',    [ $this, 'handle_clean_bak_files' ] );
		add_action( 'admin_post_wc_tu_delete_selected',    [ $this, 'handle_delete_selected' ] );
		add_action( 'admin_enqueue_scripts',                [ $this, 'enqueue_styles' ] );
		add_action( 'admin_notices',                        [ $this, 'render_conflict_banner' ] );
	}

	/**
	 * Shows a red banner across ALL WP admin pages when unresolved conflicts exist.
	 * Disappears automatically once the conflict files are gone.
	 */
	public function render_conflict_banner(): void {
		$last_run = get_option( 'wc_tu_last_run', null );
		if ( ! $last_run || empty( $last_run['results']['conflicts'] ) ) {
			return;
		}

		$pending = array_filter( $last_run['results']['conflicts'], function ( $r ) {
			return file_exists( $r['conflict_file'] );
		} );

		if ( empty( $pending ) ) {
			return;
		}

		$count = count( $pending );
		$url   = admin_url( 'admin.php?page=template-updater-for-woocommerce' );
		$label = $count === 1 ? '1 WooCommerce template conflict' : "{$count} WooCommerce template conflicts";

		printf(
			'<div class="notice notice-error"><p><strong>WC Template Updater:</strong> %s need%s manual review. <a href="%s">View details &rarr;</a></p></div>',
			esc_html( $label ),
			$count === 1 ? 's' : '',
			esc_url( $url )
		);
	}

	public function register_menu(): void {
		add_submenu_page(
			'woocommerce',
			'Template Updater',
			'Template Updater',
			'manage_options',
			'template-updater-for-woocommerce',
			[ $this, 'render_page' ],
			99 // Position: push to bottom of WooCommerce submenu.
		);
	}

	public function enqueue_styles( string $hook ): void {
		if ( $hook !== 'woocommerce_page_template-updater-for-woocommerce' ) {
			return;
		}
		wp_add_inline_style( 'wp-admin', $this->inline_css() );
	}

	// -------------------------------------------------------------------------
	// Page render
	// -------------------------------------------------------------------------

	public function render_page(): void {
		// Route to the diff viewer when a file path is supplied.
		if ( isset( $_GET['wc_tu_diff'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$this->render_diff_page( sanitize_text_field( wp_unslash( $_GET['wc_tu_diff'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$current_tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'updater'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$updater_url  = admin_url( 'admin.php?page=template-updater-for-woocommerce&tab=updater' );
		$settings_url = admin_url( 'admin.php?page=template-updater-for-woocommerce&tab=settings' );

		?>
		<div class="wrap wc-tu-wrap">
			<h1>WC Template Updater</h1>

			<?php $this->render_notices(); ?>

			<nav class="nav-tab-wrapper" style="margin-bottom:24px;">
				<a href="<?php echo esc_url( $updater_url ); ?>"
					class="nav-tab <?php echo $current_tab === 'updater' ? 'nav-tab-active' : ''; ?>">
					Updater
				</a>
				<a href="<?php echo esc_url( $settings_url ); ?>"
					class="nav-tab <?php echo $current_tab === 'settings' ? 'nav-tab-active' : ''; ?>">
					Settings
				</a>
			</nav>

			<?php if ( $current_tab === 'settings' ): ?>
				<?php $this->render_preflight( new WC_TU_Merger() ); ?>
				<?php $this->render_settings(); ?>
			<?php else: ?>
				<?php
				$last_run = get_option( 'wc_tu_last_run', null );
				?>
				<?php $this->render_run_button(); ?>
				<?php $this->render_last_run( $last_run ); ?>
				<?php $this->render_combined_overrides(); ?>
			<?php endif; ?>

		</div>
		<?php
	}

	// -------------------------------------------------------------------------
	// Sections
	// -------------------------------------------------------------------------

	private function render_notices(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- post-redirect display flags
		// Settings saved confirmation.
		if ( isset( $_GET['wc_tu_saved'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>Settings saved.</p></div>';
		}

		// Force reset completed.
		if ( isset( $_GET['wc_tu_reset'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>&#10003; Plugin state reset. All in-progress flags cleared.</p></div>';
		}

		// Conflict files cleared.
		if ( isset( $_GET['wc_tu_conflicts_cleared'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>&#10003; All conflict files deleted and list cleared.</p></div>';
		}

		// Run was stopped early.
		if ( isset( $_GET['wc_tu_stopped'] ) ) {
			echo '<div class="notice notice-warning is-dismissible"><p>&#9632; Run stopped. Templates processed so far are shown in Last Run below.</p></div>';
		}

		// Orphaned backup files deleted.
		if ( isset( $_GET['wc_tu_bak_cleaned'] ) ) {
			$deleted = isset( $_GET['deleted'] ) ? absint( wp_unslash( $_GET['deleted'] ) ) : 0; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			echo "<div class='notice notice-success is-dismissible'><p>&#10003; Removed <strong>{$deleted}</strong> orphaned backup file(s).</p></div>"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		// Housekeeping: manual per-file deletion from the customized list.
		if ( isset( $_GET['wc_tu_deleted'] ) ) {
			$deleted = isset( $_GET['deleted'] ) ? absint( wp_unslash( $_GET['deleted'] ) ) : 0; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			$failed  = isset( $_GET['failed'] )  ? absint( wp_unslash( $_GET['failed'] ) )  : 0;
			if ( $failed > 0 && $deleted === 0 ) {
				echo "<div class='notice notice-error is-dismissible'><p><strong>&#10007; Could not delete any files</strong> &mdash; check file permissions.</p></div>";
			} elseif ( $failed > 0 ) {
				echo "<div class='notice notice-warning is-dismissible'><p>&#10003; Deleted <strong>{$deleted}</strong> file(s) &mdash; <strong>{$failed}</strong> could not be removed (check permissions).</p></div>"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			} else {
				echo "<div class='notice notice-success is-dismissible'><p>&#10003; Deleted <strong>{$deleted}</strong> file(s). WooCommerce will use its own copy automatically.</p></div>"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
		}

		// Housekeeping: removal completed.
		if ( isset( $_GET['wc_tu_clean_done'] ) ) {
			$removed = isset( $_GET['removed'] ) ? absint( wp_unslash( $_GET['removed'] ) ) : 0;
			$dirs    = isset( $_GET['dirs'] )    ? absint( wp_unslash( $_GET['dirs'] ) )    : 0;
			$failed  = isset( $_GET['failed'] )  ? absint( wp_unslash( $_GET['failed'] ) )  : 0;

			$dirs_note = $dirs > 0 ? ", and cleaned up <strong>{$dirs}</strong> empty folder(s)" : '';

			// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
			if ( $failed > 0 && $removed === 0 ) {
				// Every single unlink() failed — almost certainly a permissions issue.
				echo "<div class='notice notice-error is-dismissible'>" .
					"<p><strong>&#10007; Could not delete any files.</strong> " .
					"<strong>{$failed}</strong> file(s) could not be removed &mdash; the web server " .
					"probably lacks write permission to your theme directory. " .
					"Try deleting the files via FTP, SSH, or your hosting file manager, " .
					"or ask your host to fix the directory permissions.</p>" .
					"</div>";
			} elseif ( $failed > 0 ) {
				// Partial success.
				echo "<div class='notice notice-warning is-dismissible'>" .
					"<p>&#10003; Removed <strong>{$removed}</strong> uncustomized template file(s){$dirs_note}. " .
					"&mdash; <strong>{$failed}</strong> file(s) could not be deleted (check file permissions).</p>" .
					"</div>";
			} else {
				echo "<div class='notice notice-success is-dismissible'>" .
					"<p>&#10003; Removed <strong>{$removed}</strong> uncustomized template file(s){$dirs_note}.</p>" .
					"</div>";
			}
			// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		// Async run queued — poll until wc_tu_last_run is updated.
		if ( isset( $_GET['wc_tu_queued'] ) ) {
			$started   = (int) get_option( 'wc_tu_run_started', 0 );
			$last_run  = get_option( 'wc_tu_last_run', null );
			$last_time = $last_run ? (int) $last_run['time'] : 0;

			if ( $last_time < $started ) {
				// Still running — read live progress and auto-refresh every 5 seconds.
				$progress = get_option( 'wc_tu_run_progress', null );

				if ( $progress && ! empty( $progress['total'] ) ) {
					$current   = (int) $progress['current'];
					$total     = (int) $progress['total'];
					$path      = esc_html( $progress['path'] ?? '' );
					$step      = esc_html( $progress['step']  ?? '' );
					$pct       = $total > 0 ? round( ( $current / $total ) * 100 ) : 0;
					$completed = $progress['completed'] ?? [];

					$detail = $path
						? "<br><small style='font-family:monospace;opacity:.85;'>{$path}</small>"
						: '';

					// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
					echo "<div class='notice notice-info'>" .
						"<p><strong>WC Template Updater:</strong> Processing template {$current} of {$total} &mdash; {$step}{$detail}</p>" .
						"<div style='background:#ddd;border-radius:3px;height:6px;max-width:400px;margin:6px 0 4px;'>" .
						"<div style='background:#007cba;border-radius:3px;height:6px;width:{$pct}%;transition:width .3s;'></div>" .
						"</div>" .
						"</div>";
					// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped

					// Rolling processed-so-far table — grows with each completed template.
					if ( ! empty( $completed ) ) {
						$done_count = count( $completed );
						echo '<h3 style="color:#46b450;margin-top:16px;">&#10003; Processed so far (' . (int) $done_count . ')</h3>';
						echo "<table class='wp-list-table widefat striped' style='max-width:800px;margin-bottom:20px;'>";
						echo "<thead><tr><th>Template</th><th>Your version</th><th>WC core version</th><th>Result</th></tr></thead>";
						echo "<tbody>";
						foreach ( $completed as $r ) {
							if ( ( $r['status'] ?? 'updated' ) === 'deleted' ) {
								$result = "<span style='color:#888;'>Deleted &mdash; uncustomized</span>";
							} elseif ( ! empty( $r['ai_resolved'] ) ) {
								$result = "<span style='color:#7c5cbf;font-weight:600;'>AI-resolved</span>";
							} else {
								$result = "<span style='color:#46b450;'>Clean merge</span>";
							}
							printf(
								'<tr><td><code>%s</code></td><td>%s</td><td>%s</td><td>%s</td></tr>',
								esc_html( $r['path'] ),
								esc_html( $r['from_version'] ),
								esc_html( $r['to_version'] ),
								wp_kses_post( $result )
							);
						}
						echo "</tbody></table>";
					}
				} else {
					// Progress not written yet — cron job is still warming up.
					echo '<div class="notice notice-info"><p><strong>WC Template Updater:</strong> Run queued &mdash; waiting for cron worker to start&hellip;</p></div>';
				}

				echo '<script>setTimeout(function(){ window.location.reload(); }, 5000);</script>';
			} else {
				// Run completed — show results summary.
				$results   = $last_run['results'] ?? [];
				$updated   = count( $results['updated']   ?? [] );
				$deleted   = count( $results['deleted']   ?? [] );
				$conflicts = count( $results['conflicts'] ?? [] );
				$errors    = count( $results['errors']    ?? [] );

				$parts = [];
				if ( $deleted )   $parts[] = "<strong>{$deleted}</strong> uncustomized file(s) deleted";
				if ( $updated )   $parts[] = "<strong>{$updated}</strong> file(s) auto-updated";
				if ( $conflicts ) $parts[] = "<strong>{$conflicts}</strong> conflict(s) need manual review";
				if ( $errors )    $parts[] = "<strong>{$errors}</strong> error(s)";

				$type = $conflicts || $errors ? 'notice-warning' : 'notice-success';
				$msg  = $parts ? implode( ', ', $parts ) . '.' : 'Nothing to update &mdash; all overrides are current.';

				echo '<div class="notice ' . esc_attr( $type ) . ' is-dismissible"><p>' . wp_kses_post( $msg ) . '</p></div>';
			}
			return;
		}

		// Housekeeping scan queued — poll until wc_tu_clean_results is updated.
		if ( isset( $_GET['wc_tu_scanning'] ) ) {
			$scan_started  = (int) get_option( 'wc_tu_clean_scan_started', 0 );
			$clean_results = get_option( 'wc_tu_clean_results', null );
			$results_time  = $clean_results ? (int) $clean_results['time'] : 0;

			if ( $results_time < $scan_started ) {
				// Still scanning.
				$progress = get_option( 'wc_tu_clean_progress', null );

				if ( $progress && ! empty( $progress['total'] ) ) {
					$current = (int) $progress['current'];
					$total   = (int) $progress['total'];
					$path    = esc_html( $progress['path'] ?? '' );
					$pct     = $total > 0 ? round( ( $current / $total ) * 100 ) : 0;
					$detail  = $path ? "<br><small style='font-family:monospace;opacity:.85;'>{$path}</small>" : '';

					// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
					echo "<div class='notice notice-info'>" .
						"<p><strong>WC Template Updater:</strong> Scanning template {$current} of {$total} &mdash; checking for customisations&hellip;{$detail}</p>" .
						"<div style='background:#ddd;border-radius:3px;height:6px;max-width:400px;margin:6px 0 4px;'>" .
						"<div style='background:#f0a500;border-radius:3px;height:6px;width:{$pct}%;transition:width .3s;'></div>" .
						"</div>" .
						"</div>";
					// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
				} else {
					echo '<div class="notice notice-info"><p><strong>WC Template Updater:</strong> Scan queued &mdash; waiting for cron worker to start&hellip;</p></div>';
				}

				echo '<script>setTimeout(function(){ window.location.reload(); }, 5000);</script>';
			} else {
				// Scan done — redirect to clean URL so the cleaner section shows results.
				wp_safe_redirect( admin_url( 'admin.php?page=template-updater-for-woocommerce#wc-tu-overrides' ) );
				exit;
			}
			return;
		}

		// Legacy synchronous run result (kept for safety).
		if ( isset( $_GET['wc_tu_ran'] ) ) {
			$updated   = isset( $_GET['updated'] )   ? absint( wp_unslash( $_GET['updated'] ) )   : 0;
			$conflicts = isset( $_GET['conflicts'] ) ? absint( wp_unslash( $_GET['conflicts'] ) ) : 0;
			$errors    = isset( $_GET['errors'] )    ? absint( wp_unslash( $_GET['errors'] ) )    : 0;

			$parts = [];
			if ( $updated )   $parts[] = "<strong>{$updated}</strong> file(s) auto-updated";
			if ( $conflicts ) $parts[] = "<strong>{$conflicts}</strong> conflict(s) need manual review";
			if ( $errors )    $parts[] = "<strong>{$errors}</strong> error(s)";

			$type = $conflicts || $errors ? 'notice-warning' : 'notice-success';
			$msg  = $parts ? implode( ', ', $parts ) . '.' : 'Nothing to update &mdash; all overrides are current.';

			echo '<div class="notice ' . esc_attr( $type ) . ' is-dismissible"><p>' . wp_kses_post( $msg ) . '</p></div>';
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	private function render_preflight( WC_TU_Merger $merger ): void {
		$git_version = $merger->git_version();
		$git_ok      = $merger->git_available();
		$has_api_key = ! empty( get_option( 'wc_tu_anthropic_api_key', '' ) );
		?>
		<table class="wc-tu-preflight widefat" style="max-width:600px;margin-bottom:20px;">
			<thead><tr><th colspan="2">Pre-flight</th></tr></thead>
			<tbody>
				<tr>
					<td>WooCommerce version</td>
					<td><code><?php echo esc_html( WC_VERSION ); ?></code></td>
				</tr>
				<tr>
					<td>git (actual execution test)</td>
					<td>
						<?php if ( $git_ok ): ?>
							<span style="color:#46b450">&#10003; <?php echo esc_html( $git_version ); ?></span>
						<?php else: ?>
							<span style="color:#dc3232">&#10007; <?php echo esc_html( $git_version ); ?> — merging will not work.</span>
						<?php endif; ?>
					</td>
				</tr>
				<?php if ( function_exists( 'wtu_fs' ) && wtu_fs()->can_use_premium_code() ) : ?>
				<tr>
					<td>AI conflict resolver</td>
					<td>
						<?php if ( $has_api_key ): ?>
							<span style="color:#46b450">&#10003; Enabled (API key configured)</span>
						<?php else: ?>
							<span style="color:#888">&#8212; Not configured &mdash; conflicts will require manual review</span>
						<?php endif; ?>
					</td>
				</tr>
				<?php endif; ?>
				<tr>
					<td>Next scheduled run</td>
					<td>
						<?php
						$next = wp_next_scheduled( WC_TU_Cron::HOOK );
						echo $next ? esc_html( wp_date( 'Y-m-d H:i:s', $next ) ) : '<em>Not scheduled</em>';
						?>
					</td>
				</tr>
			</tbody>
		</table>
		<?php
	}

	private function render_settings(): void {
		if ( ! function_exists( 'wtu_fs' ) || ! wtu_fs()->can_use_premium_code() ) {
			?>
			<h2 style="margin-top:0;">AI Settings</h2>
			<p style="color:#888;">AI conflict resolution is a <strong>Pro</strong> feature. <a href="https://mindfulplugins.io/plugins/template-updater-for-woocommerce/">Upgrade to Pro</a> to unlock it.</p>
			<?php
			return;
		}
		$api_key     = get_option( 'wc_tu_anthropic_api_key', '' );
		$has_api_key = ! empty( $api_key );
		?>
		<h2 style="margin-top:0;">AI Settings</h2>
		<p>When an Anthropic API key is provided, conflicts that git cannot auto-merge are sent to <strong>Claude</strong> for intelligent resolution. Only templates with actual merge conflicts are sent &mdash; clean merges never call the API.</p>
		<?php if ( $has_api_key ): ?>
		<table class="form-table" style="max-width:600px;margin-bottom:16px;">
			<tr>
				<th scope="row"><label>Anthropic API Key</label></th>
				<td>
					<input
						type="password"
						class="regular-text"
						autocomplete="off"
						placeholder="&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;"
						value=""
						disabled
					>
					<p class="description">An API key is saved.</p>
				</td>
			</tr>
		</table>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'wc_tu_save_settings' ); ?>
			<input type="hidden" name="action" value="wc_tu_save_settings">
			<input type="hidden" name="wc_tu_clear_api_key" value="1">
			<?php submit_button( 'Clear API Key', 'delete', 'submit', false ); ?>
		</form>
		<?php else: ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom:24px;">
			<?php wp_nonce_field( 'wc_tu_save_settings' ); ?>
			<input type="hidden" name="action" value="wc_tu_save_settings">
			<table class="form-table" style="max-width:600px;">
				<tr>
					<th scope="row"><label for="wc_tu_anthropic_api_key">Anthropic API Key</label></th>
					<td>
						<input
							type="password"
							id="wc_tu_anthropic_api_key"
							name="wc_tu_anthropic_api_key"
							class="regular-text"
							autocomplete="off"
							placeholder="sk-ant-..."
							value=""
						>
						<p class="description">Your API key from <a href="https://console.anthropic.com/settings/keys" target="_blank" rel="noopener">console.anthropic.com</a>. Stored in the WordPress options table.</p>
					</td>
				</tr>
			</table>
			<?php submit_button( 'Save Settings', 'secondary', 'submit', false ); ?>
		</form>
		<?php endif; ?>
		<?php
	}

	private function render_run_button(): void {
		$is_running   = $this->is_run_in_progress();
		$is_scanning  = $this->is_scan_in_progress();
		$stop_pending = (bool) get_option( 'wc_tu_stop_requested', false );
		$is_stuck     = $is_running || $is_scanning || $stop_pending;
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;margin-bottom:24px;">
			<?php wp_nonce_field( 'wc_tu_run_now' ); ?>
			<input type="hidden" name="action" value="wc_tu_run_now">
			<?php submit_button( $is_running ? 'Running&hellip;' : 'Run Now', 'primary', 'submit', false, $is_running ? [ 'disabled' => 'disabled' ] : [] ); ?>
		</form>

		<?php if ( $is_running && ! $stop_pending ): ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;margin-left:8px;">
				<?php wp_nonce_field( 'wc_tu_stop_run' ); ?>
				<input type="hidden" name="action" value="wc_tu_stop_run">
				<button type="submit" class="button" style="color:#dc3232;border-color:#dc3232;">&#9632; Stop After Current Template</button>
			</form>
			<span style="margin-left:8px;color:#888;font-style:italic;">Processing templates in the background&hellip;</span>
		<?php elseif ( $is_running && $stop_pending ): ?>
			<span style="margin-left:8px;color:#b45309;font-style:italic;">&#9201; Stop requested &mdash; will halt after current template&hellip;</span>
		<?php endif; ?>

		<?php if ( $is_stuck ): ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;margin-left:12px;">
				<?php wp_nonce_field( 'wc_tu_force_reset' ); ?>
				<input type="hidden" name="action" value="wc_tu_force_reset">
				<button type="submit" class="button button-small" style="color:#888;border-color:#ccc;"
					title="Clears all in-progress flags if the plugin appears stuck">
					&#8635; Force Reset
				</button>
			</form>
		<?php endif; ?>

		<div style="margin-bottom:24px;"></div>
		<?php
	}

	private function render_last_run( ?array $last_run ): void {
		if ( ! $last_run ) {
			return;
		}

		$results   = $last_run['results'];
		$time      = wp_date( 'Y-m-d H:i:s', $last_run['time'] );
		$updated   = $results['updated']   ?? [];
		$deleted   = $results['deleted']   ?? [];
		$conflicts = $results['conflicts'] ?? [];
		$errors    = $results['errors']    ?? [];
		?>
		<h2>Last Run &mdash; <?php echo esc_html( $time ); ?></h2>

		<?php if ( ! empty( $deleted ) ): ?>
			<h3 style="color:#888">&#128465; Deleted &mdash; uncustomized copies (<?php echo count( $deleted ); ?>)</h3>
			<p style="color:#888;font-size:13px;">These were identical to the original WooCommerce template they were based on. They have been removed &mdash; WooCommerce will use its own copy automatically.</p>
			<table class="wp-list-table widefat striped" style="max-width:800px;margin-bottom:16px;">
				<thead><tr><th>Template</th><th>Was at version</th><th>Current WC version</th></tr></thead>
				<tbody>
					<?php foreach ( $deleted as $r ): ?>
					<tr style="opacity:.7;">
						<td><code><?php echo esc_html( $r['path'] ); ?></code></td>
						<td><?php echo esc_html( $r['from_version'] ); ?></td>
						<td><?php echo esc_html( $r['to_version'] ); ?></td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<?php if ( ! empty( $updated ) ): ?>
			<h3 style="color:#46b450">&#10003; Auto-updated (<?php echo count( $updated ); ?>)</h3>
			<table class="wp-list-table widefat striped" style="max-width:800px;margin-bottom:16px;">
				<thead><tr><th>Template</th><th>From</th><th>To</th><th>Method</th></tr></thead>
				<tbody>
					<?php foreach ( $updated as $r ): ?>
					<tr>
						<td><code><?php echo esc_html( $r['path'] ); ?></code></td>
						<td><?php echo esc_html( $r['from_version'] ); ?></td>
						<td><?php echo esc_html( $r['to_version'] ); ?></td>
						<td>
							<?php if ( ! empty( $r['ai_resolved'] ) ): ?>
								<span style="color:#7c5cbf;font-weight:600;">AI-resolved</span>
							<?php else: ?>
								<span style="color:#46b450;">Clean merge</span>
							<?php endif; ?>
						</td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<?php if ( ! empty( $conflicts ) ): ?>
			<h3 style="color:#dc3232">&#9888; Conflicts &mdash; manual review required (<?php echo count( $conflicts ); ?>)</h3>
			<p>A <code>.conflict</code> file has been written next to each template. Resolve the <code>&lt;&lt;&lt;&lt;&lt;&lt;&lt;</code> markers, then replace the original file with the resolved content and delete the <code>.conflict</code> file. The banner will clear automatically.</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom:12px;"
				onsubmit="return confirm('Delete all .conflict files and clear this list?');">
				<?php wp_nonce_field( 'wc_tu_clear_conflicts' ); ?>
				<input type="hidden" name="action" value="wc_tu_clear_conflicts">
				<button type="submit" class="button" style="color:#dc3232;border-color:#dc3232;">&#128465; Clear All Conflict Files</button>
			</form>
			<table class="wp-list-table widefat striped" style="max-width:1000px;margin-bottom:16px;">
				<thead><tr><th>Template</th><th>Your version</th><th>Core version</th><th>Diagnosis</th><th>AI Status</th><th>Conflict file</th></tr></thead>
				<tbody>
					<?php foreach ( $conflicts as $r ): ?>
					<?php
						$cf_content      = file_exists( $r['conflict_file'] ) ? file_get_contents( $r['conflict_file'] ) : null;
						$is_empty        = $cf_content === '' || $cf_content === null;
						$has_markers     = $cf_content && strpos( $cf_content, '<<<<<<<' ) !== false;
						$diagnosis_color = $is_empty ? '#dc3232' : ( $has_markers ? '#b45309' : '#46b450' );
						$diagnosis_label = $is_empty ? '&#10007; Empty &mdash; git may not have run' : ( $has_markers ? '&#9888; Real conflict &mdash; needs manual edit' : '&#10003; No markers &mdash; safe to apply' );
					?>
					<tr>
						<td><code><?php echo esc_html( $r['path'] ); ?></code></td>
						<td><?php echo esc_html( $r['from_version'] ); ?></td>
						<td><?php echo esc_html( $r['to_version'] ); ?></td>
						<td><span style="color:<?php echo esc_attr( $diagnosis_color ); ?>"><?php echo wp_kses_post( $diagnosis_label ); ?></span></td>
						<td>
							<?php if ( ! empty( $r['ai_error'] ) ): ?>
								<span style="color:#b45309;">&#9888; <?php echo esc_html( $r['ai_error'] ); ?></span>
							<?php elseif ( array_key_exists( 'ai_error', $r ) ): ?>
								<span style="color:#888;">&mdash; not attempted</span>
							<?php else: ?>
								<span style="color:#aaa;">&mdash;</span>
							<?php endif; ?>
						</td>
						<td><code style="font-size:11px;"><?php echo esc_html( $r['conflict_file'] ); ?></code></td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<?php if ( ! empty( $errors ) ): ?>
			<h3 style="color:#dc3232">&#10007; Errors (<?php echo count( $errors ); ?>)</h3>
			<table class="wp-list-table widefat striped" style="max-width:800px;margin-bottom:16px;">
				<thead><tr><th>Template</th><th>Message</th></tr></thead>
				<tbody>
					<?php foreach ( $errors as $r ): ?>
					<tr>
						<td><code><?php echo esc_html( $r['path'] ); ?></code></td>
						<td><?php echo esc_html( $r['message'] ); ?></td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
		<?php
	}

	/**
	 * Housekeeping section — find and remove uncustomized template copies.
	 *
	 * States:
	 *   1. Default      — scan button, description, previous results if any.
	 *   2. Scan results — table of uncustomized files + "Remove All" confirm button.
	 */
	private function render_cleaner(): void {
		$clean_results  = get_option( 'wc_tu_clean_results', null );
		$uncustomized   = $clean_results['uncustomized']   ?? [];
		$customized     = $clean_results['customized']     ?? null; // null = old scan
		$skipped_fetch  = $clean_results['skipped_fetch']  ?? null; // null = old scan
		$skipped_unread = $clean_results['skipped_unread'] ?? [];
		$scan_total     = $clean_results['scan_total']     ?? 0;
		$scan_time      = $clean_results ? wp_date( 'Y-m-d H:i:s', $clean_results['time'] ) : null;
		$is_scanning    = $this->is_scan_in_progress();

		$uncust_count  = count( $uncustomized );
		$custom_count  = is_array( $customized )     ? count( $customized )     : null;
		$fetch_count   = is_array( $skipped_fetch )  ? count( $skipped_fetch )  : null;
		$unread_count  = count( $skipped_unread );
		?>
		<h2 id="wc-tu-housekeeping">Housekeeping &mdash; Remove Uncustomized Templates</h2>
		<p>
			If you accidentally copied WooCommerce's entire template folder into your theme, most files are probably vanilla copies that were never edited.
			This scan compares each override against its original WC version (via GitHub) and flags any file whose <strong>code</strong> is identical &mdash; comments and whitespace differences are ignored, so only actual PHP logic changes count as a customization.
		</p>

		<?php if ( $scan_time ): ?>
			<p style="color:#888;font-size:13px;margin-bottom:4px;">
				Last scan: <?php echo esc_html( $scan_time ); ?> &mdash; <?php echo (int) $scan_total; ?> total overrides checked.
			</p>

			<?php if ( $custom_count !== null || $fetch_count !== null ): ?>
			<table style="border-collapse:collapse;font-size:13px;margin-bottom:12px;">
				<tr>
					<td style="padding:2px 16px 2px 0;color:#46b450;">&#10003; Uncustomized (safe to delete)</td>
					<td style="font-weight:600;"><?php echo (int) $uncust_count; ?></td>
				</tr>
				<?php if ( $custom_count !== null ): ?>
				<tr>
					<td style="padding:2px 16px 2px 0;color:#007cba;">&#9998; Has code changes &mdash; customized</td>
					<td style="font-weight:600;"><?php echo (int) $custom_count; ?></td>
				</tr>
				<?php endif; ?>
				<?php if ( $fetch_count !== null && $fetch_count > 0 ): ?>
				<tr>
					<td style="padding:2px 16px 2px 0;color:#b45309;">&#9888; Skipped &mdash; GitHub fetch failed</td>
					<td style="font-weight:600;"><?php echo (int) $fetch_count; ?></td>
				</tr>
				<?php endif; ?>
				<?php if ( $unread_count > 0 ): ?>
				<tr>
					<td style="padding:2px 16px 2px 0;color:#dc3232;">&#10007; Skipped &mdash; could not read file</td>
					<td style="font-weight:600;"><?php echo (int) $unread_count; ?></td>
				</tr>
				<?php endif; ?>
			</table>
			<?php endif; ?>

			<?php if ( $fetch_count !== null && $fetch_count > 0 ): ?>
			<div style="border:1px solid #f0a500;border-radius:3px;padding:10px 14px;background:#fffbf0;margin-bottom:14px;max-width:860px;">
				<strong style="color:#b45309;">&#9888; <?php echo (int) $fetch_count; ?> file(s) skipped &mdash; GitHub fetch failed</strong><br>
				<span style="font-size:13px;">
					These outdated files couldn&rsquo;t be compared because their original WC template couldn&rsquo;t be fetched from GitHub (timeout, rate-limit, or network hiccup).
					<strong>Re-scanning</strong> will retry them &mdash; successfully fetched files are cached locally so retries are fast.
				</span>
				<?php if ( ! empty( $skipped_fetch ) ): ?>
				<details style="margin-top:8px;">
					<summary style="cursor:pointer;font-size:12px;color:#888;">Show skipped files</summary>
					<ul style="font-family:monospace;font-size:11px;color:#888;margin:6px 0 0 16px;">
						<?php foreach ( $skipped_fetch as $sf ): ?>
						<li><code><?php echo esc_html( $sf['path'] ); ?></code> (v<?php echo esc_html( $sf['base_version'] ); ?>)</li>
						<?php endforeach; ?>
					</ul>
				</details>
				<?php endif; ?>
			</div>
			<?php endif; ?>
		<?php endif; ?>

		<?php if ( ! empty( $uncustomized ) ): ?>
			<div style="border:1px solid #f0a500;border-radius:3px;padding:12px 16px;background:#fffbf0;margin-bottom:16px;max-width:860px;">
				<strong style="color:#b45309;">&#9888; Found <?php echo (int) $uncust_count; ?> uncustomized template(s)</strong>
				&mdash; these are exact copies of the original WC template and can be safely deleted.
				WooCommerce will fall back to its own copy automatically.
			</div>

			<table class="wp-list-table widefat striped" style="max-width:860px;margin-bottom:16px;">
				<thead>
					<tr>
						<th>Template</th>
						<th>Version in your theme</th>
						<th>Current WC core version</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $uncustomized as $r ): ?>
					<tr>
						<td><code><?php echo esc_html( $r['path'] ); ?></code></td>
						<td><?php echo esc_html( $r['base_version'] ); ?></td>
						<td><?php echo esc_html( $r['core_version'] ); ?></td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom:8px;"
				onsubmit="return confirm('Delete <?php echo (int) $uncust_count; ?> uncustomized template file(s) and remove any empty folders? This cannot be undone.');">
				<?php wp_nonce_field( 'wc_tu_remove_uncustomized' ); ?>
				<input type="hidden" name="action" value="wc_tu_remove_uncustomized">
				<?php submit_button( 'Delete ' . $uncust_count . ' Uncustomized File(s)', 'delete', 'submit', false ); ?>
			</form>

		<?php elseif ( $scan_time && ! $is_scanning ): ?>
			<?php if ( $fetch_count === null || $fetch_count === 0 ): ?>
			<p style="color:#46b450;">&#10003; All <?php echo (int) $scan_total; ?> overrides appear to be customised &mdash; nothing to remove.</p>
			<?php endif; ?>
		<?php endif; ?>

		<?php
		// -------------------------------------------------------------------
		// Customized files table — listed after uncustomized so the user can
		// inspect diffs and confirm which files are genuinely theirs.
		// -------------------------------------------------------------------
		if ( ! empty( $customized ) && $custom_count > 0 ):
		?>
		<h3 style="color:#007cba;margin-top:20px;">&#9998; Files with code changes (<?php echo (int) $custom_count; ?>)</h3>
		<p style="color:#888;font-size:13px;max-width:860px;">
			These files have actual PHP code differences from their original WC version.
			Click <strong>View diff</strong> to inspect &mdash; it may be a WC silent patch or a real customization.
			Check any files you want to remove and hit <strong>Delete Selected</strong>.
		</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
			onsubmit="return confirm('Delete the selected file(s)? This cannot be undone.');">
			<?php wp_nonce_field( 'wc_tu_delete_selected' ); ?>
			<input type="hidden" name="action" value="wc_tu_delete_selected">
			<table class="wp-list-table widefat striped" style="max-width:940px;margin-bottom:10px;">
				<thead>
					<tr>
						<th style="width:32px;padding-left:10px;">
							<input type="checkbox" id="wc-tu-check-all" title="Select / deselect all">
						</th>
						<th>Template</th>
						<th style="width:110px;">Your version</th>
						<th style="width:120px;">WC version</th>
						<th style="width:100px;"></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $customized as $item ):
						$cpath    = is_array( $item ) ? ( $item['path']         ?? '' ) : $item;
						$cbv      = is_array( $item ) ? ( $item['base_version'] ?? '?' ) : '?';
						$ccv      = is_array( $item ) ? ( $item['core_version'] ?? '?' ) : '?';
						$has_abs  = is_array( $item ) && ! empty( $item['absolute_path'] );
						$diff_url = add_query_arg( [ 'wc_tu_diff' => $cpath ], admin_url( 'admin.php?page=template-updater-for-woocommerce' ) );
					?>
					<tr>
						<td style="padding-left:10px;">
							<?php if ( $has_abs ): ?>
							<input type="checkbox" name="wc_tu_paths[]" value="<?php echo esc_attr( $cpath ); ?>" class="wc-tu-file-check">
							<?php else: ?>
							<input type="checkbox" disabled title="Re-scan to enable deletion">
							<?php endif; ?>
						</td>
						<td><code><?php echo esc_html( $cpath ); ?></code></td>
						<td><?php echo esc_html( $cbv ); ?></td>
						<td><?php echo esc_html( $ccv ); ?></td>
						<td>
							<?php if ( $has_abs ): ?>
							<a href="<?php echo esc_url( $diff_url ); ?>" target="_blank" rel="noopener">View diff &rarr;</a>
							<?php else: ?>
							<span style="color:#aaa;font-size:11px;">Re-scan to enable</span>
							<?php endif; ?>
						</td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p style="margin-top:6px;">
				<button type="submit" class="button" style="color:#dc3232;border-color:#dc3232;">
					&#128465; Delete Selected
				</button>
				<span style="margin-left:10px;color:#888;font-size:12px;">WooCommerce will use its own copy for any deleted file.</span>
			</p>
		</form>
		<script>
		(function(){
			var all = document.getElementById('wc-tu-check-all');
			if ( all ) {
				all.addEventListener('change', function(){
					document.querySelectorAll('.wc-tu-file-check').forEach(function(cb){ cb.checked = all.checked; });
				});
			}
		})();
		</script>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom:24px;">
			<?php wp_nonce_field( 'wc_tu_scan_uncustomized' ); ?>
			<input type="hidden" name="action" value="wc_tu_scan_uncustomized">
			<?php
			$label = $is_scanning ? 'Scanning&hellip;' : ( $scan_time ? 'Re-scan' : 'Scan for Uncustomized Templates' );
			submit_button( $label, 'secondary', 'submit', false, $is_scanning ? [ 'disabled' => 'disabled' ] : [] );
			?>
			<?php if ( $is_scanning ): ?>
				<span style="margin-left:8px;color:#888;font-style:italic;">Comparing templates against GitHub&hellip; this may take a minute.</span>
				<?php
				// Auto-refresh every 5 s so the button resets as soon as the scan finishes,
				// even when the user is viewing this page without the ?wc_tu_scanning param
				// (e.g. they navigated here directly while the cron job was already running).
				if ( ! isset( $_GET['wc_tu_scanning'] ) ): // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flag
				?>
					<script>setTimeout(function(){ window.location.reload(); }, 5000);</script>
				<?php endif; ?>
			<?php else: ?>
				<span style="margin-left:8px;color:#888;font-size:13px;">Fetches each template from GitHub to compare &mdash; requires internet access.</span>
			<?php endif; ?>
		</form>

		<?php
		// -----------------------------------------------------------------------
		// Orphaned backup files — .bak.* files whose matching .php was deleted.
		// Detected synchronously on every page load (fast local filesystem scan).
		// -----------------------------------------------------------------------
		$orphaned_baks = $this->find_orphaned_bak_files();
		if ( ! empty( $orphaned_baks ) ):
			$bak_count = count( $orphaned_baks );
		?>
		<h3 style="color:#b45309;">&#128465; Orphaned Backup Files (<?php echo (int) $bak_count; ?>)</h3>
		<p>
			These <code>.bak.*</code> files have no matching <code>.php</code> template &mdash; their originals were
			deleted (by the runner or housekeeping) and the backups are now orphaned. Safe to remove.
		</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom:8px;">
			<?php wp_nonce_field( 'wc_tu_clean_bak_files' ); ?>
			<input type="hidden" name="action" value="wc_tu_clean_bak_files">
			<?php submit_button( "Delete {$bak_count} Orphaned Backup File(s)", 'delete', 'submit', false ); ?>
		</form>
		<ul style="font-family:monospace;font-size:12px;color:#888;margin-top:4px;">
			<?php foreach ( $orphaned_baks as $bak ):
				// Show path relative to the theme root for readability.
				$display = str_replace( '\\', '/', $bak );
				$display = str_replace( str_replace( '\\', '/', trailingslashit( get_stylesheet_directory() ) ), '', $display );
				$display = str_replace( str_replace( '\\', '/', trailingslashit( get_template_directory() ) ),   '', $display );
			?>
				<li><code><?php echo esc_html( $display ); ?></code></li>
			<?php endforeach; ?>
		</ul>
		<?php endif; ?>
		<?php
	}

	/**
	 * Unified “Template Overrides” section — replaces the old separate
	 * Housekeeping and Template Overrides sections with a single table.
	 *
	 * Columns: ☐ | Template | Your version | WC version | Update | Code | Actions
	 */
	private function render_combined_overrides(): void {
		// -----------------------------------------------------------------
		// Data sources.
		// -----------------------------------------------------------------
		$all_overrides  = ( new WC_TU_Scanner() )->get_all_overrides();
		$clean_results  = get_option( 'wc_tu_clean_results', null );
		$is_scanning    = $this->is_scan_in_progress();
		$scan_time      = $clean_results ? wp_date( 'Y-m-d H:i:s', $clean_results['time'] ) : null;
		$scan_total     = $clean_results ? (int) ( $clean_results['scan_total'] ?? 0 ) : 0;
		$skipped_fetch  = $clean_results['skipped_fetch']  ?? [];
		$skipped_unread = $clean_results['skipped_unread'] ?? [];

		// Build quick-lookup maps keyed by rel_path.
		$uncust_map       = [];
		$custom_map       = [];
		$skipped_ftch_map = [];

		if ( $clean_results ) {
			foreach ( $clean_results['uncustomized'] ?? [] as $item ) {
				if ( is_array( $item ) && isset( $item['path'] ) ) {
					$uncust_map[ $item['path'] ] = $item;
				}
			}
			foreach ( $clean_results['customized'] ?? [] as $item ) {
				if ( is_array( $item ) && isset( $item['path'] ) ) {
					$custom_map[ $item['path'] ] = $item;
				}
			}
			foreach ( $skipped_fetch as $item ) {
				if ( is_array( $item ) && isset( $item['path'] ) ) {
					$skipped_ftch_map[ $item['path'] ] = $item;
				}
			}
		}

		// -----------------------------------------------------------------
		// Sort: outdated first, then alphabetically within each group.
		// -----------------------------------------------------------------
		$total          = count( $all_overrides );
		$outdated_list  = array_values( array_filter( $all_overrides, fn( $o ) => version_compare( $o['base_version'], $o['core_version'], '<' ) ) );
		$current_list   = array_values( array_filter( $all_overrides, fn( $o ) => version_compare( $o['base_version'], $o['core_version'], '>=' ) ) );
		usort( $outdated_list, fn( $a, $b ) => strcmp( $a['path'], $b['path'] ) );
		usort( $current_list,  fn( $a, $b ) => strcmp( $a['path'], $b['path'] ) );
		$sorted         = array_merge( $outdated_list, $current_list );
		$outdated_count = count( $outdated_list );

		// Count checkboxable “safe to delete” rows for the JS quick-select helper.
		$safe_count = 0;
		foreach ( $sorted as $o ) {
			if ( isset( $uncust_map[ $o['path'] ] ) && ! empty( $uncust_map[ $o['path'] ]['absolute_path'] ) ) {
				$safe_count++;
			}
		}
		?>
		<h2 id="wc-tu-overrides">
			Template Overrides (<?php echo (int) $total; ?>)
			<?php if ( $outdated_count > 0 ): ?>
				<span style="font-size:14px;font-weight:normal;color:#dc3232;margin-left:8px;">
					&mdash; <?php echo (int) $outdated_count; ?> outdated
				</span>
			<?php endif; ?>
		</h2>

		<?php if ( $total === 0 ): ?>
			<p>No WooCommerce template overrides found in your theme.</p>
		<?php elseif ( $outdated_count > 0 ): ?>
			<p style="color:#b45309;">
				&#9888; <strong><?php echo (int) $outdated_count; ?></strong> override(s) are behind the installed WooCommerce version &mdash;
				hit <strong>Run Now</strong> above to auto-merge them.
			</p>
		<?php else: ?>
			<p style="color:#46b450;">&#10003; All template overrides are up to date.</p>
		<?php endif; ?>

		<?php if ( $scan_time ): ?>
			<p style="color:#888;font-size:13px;margin-bottom:4px;">
				Last code scan: <?php echo esc_html( $scan_time ); ?> &mdash; <?php echo (int) $scan_total; ?> total overrides checked.
				<?php if ( count( $skipped_fetch ) > 0 ): ?>
					&mdash; <span style="color:#b45309;">&#9888; <?php echo count( $skipped_fetch ); ?> fetch failure(s) &mdash; re-scan to retry</span>
				<?php endif; ?>
			</p>
		<?php else: ?>
			<p style="color:#888;font-size:13px;margin-bottom:4px;">
				No code scan run yet &mdash; click <strong>Scan</strong> below to check which overrides have actual code changes.
			</p>
		<?php endif; ?>

		<?php if ( $total > 0 ): ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
			onsubmit="return confirm('Delete the selected file(s) from your theme? This cannot be undone.')">
			<?php wp_nonce_field( 'wc_tu_delete_selected' ); ?>
			<input type="hidden" name="action" value="wc_tu_delete_selected">
			<table class="wp-list-table widefat striped" style="max-width:1040px;margin-bottom:10px;">
				<thead>
					<tr>
						<th style="width:32px;padding-left:10px;">
							<input type="checkbox" id="wc-tu-check-all" title="Select / deselect all">
						</th>
						<th>Template</th>
						<th style="width:100px;">Your version</th>
						<th style="width:110px;">WC version</th>
						<th style="width:100px;">Update</th>
						<th style="width:145px;">Code</th>
						<th style="width:90px;"></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $sorted as $o ):
						$path        = $o['path'];
						$base_ver    = $o['base_version'];
						$core_ver    = $o['core_version'];
						$is_outdated = version_compare( $base_ver, $core_ver, '<' );
						if ( isset( $uncust_map[ $path ] ) ) {
							$scan_item   = $uncust_map[ $path ];
							$code_status = 'uncustomized';
						} elseif ( isset( $custom_map[ $path ] ) ) {
							$scan_item   = $custom_map[ $path ];
							$code_status = 'customized';
						} elseif ( isset( $skipped_ftch_map[ $path ] ) ) {
							$scan_item   = $skipped_ftch_map[ $path ];
							$code_status = 'fetch_failed';
						} else {
							$scan_item   = null;
							$code_status = 'not_scanned';
						}
						$has_abs  = $scan_item && ! empty( $scan_item['absolute_path'] );
						$diff_url = add_query_arg( [ 'wc_tu_diff' => $path ], admin_url( 'admin.php?page=template-updater-for-woocommerce' ) );
					?>
					<tr>
						<td style="padding-left:10px;">
							<?php if ( $has_abs ): ?>
								<input type="checkbox" name="wc_tu_paths[]"
									value="<?php echo esc_attr( $path ); ?>"
									class="wc-tu-file-check<?php echo $code_status === 'uncustomized' ? ' wc-tu-safe-check' : ''; ?>">
							<?php else: ?>
								<input type="checkbox" disabled
									title="<?php echo esc_attr( $code_status === 'not_scanned' ? 'Run a scan to enable deletion' : 'Re-scan to enable deletion' ); ?>">
							<?php endif; ?>
						</td>
						<td><code><?php echo esc_html( $path ); ?></code></td>
						<td><?php echo esc_html( $base_ver ); ?></td>
						<td><?php echo esc_html( $core_ver ); ?></td>
						<td>
							<?php if ( $is_outdated ): ?>
								<span style="color:#dc3232;font-weight:600;">&#9888; Outdated</span>
							<?php else: ?>
								<span style="color:#46b450;">&#10003; Up to date</span>
							<?php endif; ?>
						</td>
						<td>
							<?php if ( $code_status === 'uncustomized' ): ?>
								<span style="color:#46b450;">&#10003; Safe to delete</span>
							<?php elseif ( $code_status === 'customized' ): ?>
								<span style="color:#007cba;">&#9998; Has changes</span>
							<?php elseif ( $code_status === 'fetch_failed' ): ?>
								<span style="color:#b45309;">&#9888; Fetch failed</span>
							<?php else: ?>
								<span style="color:#aaa;">&#8212; Not scanned</span>
							<?php endif; ?>
						</td>
						<td>
							<?php if ( $code_status === 'customized' && $has_abs ): ?>
								<a href="<?php echo esc_url( $diff_url ); ?>" target="_blank" rel="noopener">View diff &rarr;</a>
							<?php endif; ?>
						</td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p style="margin-top:6px;">
				<button type="submit" class="button" style="color:#dc3232;border-color:#dc3232;">
					&#128465; Delete Selected
				</button>
				<?php if ( $safe_count > 0 ): ?>
					<a href="#" id="wc-tu-select-safe" style="margin-left:12px;font-size:13px;">
						Select <?php echo (int) $safe_count; ?> safe to delete
					</a>
				<?php endif; ?>
				<span style="margin-left:10px;color:#888;font-size:12px;">WooCommerce will use its own copy for any deleted file.</span>
			</p>
		</form>
		<script>
		(function(){
			var all  = document.getElementById('wc-tu-check-all');
			var safe = document.getElementById('wc-tu-select-safe');
			if ( all ) {
				all.addEventListener('change', function(){
					document.querySelectorAll('.wc-tu-file-check').forEach(function(cb){ cb.checked = all.checked; });
				});
			}
			if ( safe ) {
				safe.addEventListener('click', function(e){
					e.preventDefault();
					document.querySelectorAll('.wc-tu-file-check').forEach(function(cb){ cb.checked = false; });
					document.querySelectorAll('.wc-tu-safe-check').forEach(function(cb){ cb.checked = true; });
				});
			}
		})();
		</script>
		<?php endif; ?>

		<?php
		$fetch_count = count( $skipped_fetch );
		if ( $fetch_count > 0 ):
		?>
		<div style="border:1px solid #f0a500;border-radius:3px;padding:10px 14px;background:#fffbf0;margin-bottom:14px;max-width:860px;">
			<strong style="color:#b45309;">&#9888; <?php echo (int) $fetch_count; ?> file(s) skipped &mdash; GitHub fetch failed</strong><br>
			<span style="font-size:13px;">
				These couldn&rsquo;t be compared because the original WC template couldn&rsquo;t be fetched from GitHub.
				<strong>Re-scanning</strong> will retry them &mdash; successfully fetched files are cached locally so retries are fast.
			</span>
			<details style="margin-top:8px;">
				<summary style="cursor:pointer;font-size:12px;color:#888;">Show skipped files</summary>
				<ul style="font-family:monospace;font-size:11px;color:#888;margin:6px 0 0 16px;">
					<?php foreach ( $skipped_fetch as $sf ): ?>
					<li><code><?php echo esc_html( $sf['path'] ); ?></code> (v<?php echo esc_html( $sf['base_version'] ); ?>)</li>
					<?php endforeach; ?>
				</ul>
			</details>
		</div>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom:24px;">
			<?php wp_nonce_field( 'wc_tu_scan_uncustomized' ); ?>
			<input type="hidden" name="action" value="wc_tu_scan_uncustomized">
			<?php
			$label = $is_scanning ? 'Scanning&hellip;' : ( $scan_time ? 'Re-scan for Code Changes' : 'Scan for Code Changes' );
			submit_button( $label, 'secondary', 'submit', false, $is_scanning ? [ 'disabled' => 'disabled' ] : [] );
			?>
			<?php if ( $is_scanning ): ?>
				<span style="margin-left:8px;color:#888;font-style:italic;">Comparing templates against GitHub&hellip; this may take a minute.</span>
				<?php if ( ! isset( $_GET['wc_tu_scanning'] ) ): // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
					<script>setTimeout(function(){ window.location.reload(); }, 5000);</script>
				<?php endif; ?>
			<?php else: ?>
				<span style="margin-left:8px;color:#888;font-size:13px;">Fetches each template from GitHub to compare &mdash; requires internet access.</span>
			<?php endif; ?>
		</form>

		<?php
		// -----------------------------------------------------------------
		// Orphaned backup files.
		// -----------------------------------------------------------------
		$orphaned_baks = $this->find_orphaned_bak_files();
		if ( ! empty( $orphaned_baks ) ):
			$bak_count = count( $orphaned_baks );
		?>
		<h3 style="color:#b45309;">&#128465; Orphaned Backup Files (<?php echo (int) $bak_count; ?>)</h3>
		<p>
			These <code>.bak.*</code> files have no matching <code>.php</code> template &mdash; their originals were
			deleted (by the runner or housekeeping) and the backups are now orphaned. Safe to remove.
		</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom:8px;">
			<?php wp_nonce_field( 'wc_tu_clean_bak_files' ); ?>
			<input type="hidden" name="action" value="wc_tu_clean_bak_files">
			<?php submit_button( "Delete {$bak_count} Orphaned Backup File(s)", 'delete', 'submit', false ); ?>
		</form>
		<ul style="font-family:monospace;font-size:12px;color:#888;margin-top:4px;">
			<?php foreach ( $orphaned_baks as $bak ):
				$display = str_replace( '\\', '/', $bak );
				$display = str_replace( str_replace( '\\', '/', trailingslashit( get_stylesheet_directory() ) ), '', $display );
				$display = str_replace( str_replace( '\\', '/', trailingslashit( get_template_directory() )   ), '', $display );
			?>
				<li><code><?php echo esc_html( $display ); ?></code></li>
			<?php endforeach; ?>
		</ul>
		<?php endif; ?>
		<?php
	}

	private function render_overrides_table( array $all ): void {
		$total    = count( $all );
		$outdated = array_values( array_filter( $all, fn( $o ) => version_compare( $o['base_version'], $o['core_version'], '<' ) ) );
		$current  = array_values( array_filter( $all, fn( $o ) => version_compare( $o['base_version'], $o['core_version'], '>=' ) ) );

		// Sort each group alphabetically by path, then merge outdated first.
		usort( $outdated, fn( $a, $b ) => strcmp( $a['path'], $b['path'] ) );
		usort( $current,  fn( $a, $b ) => strcmp( $a['path'], $b['path'] ) );
		$sorted         = array_merge( $outdated, $current );
		$outdated_count = count( $outdated );
		?>
		<h2>
			Template Overrides (<?php echo (int) $total; ?>)
			<?php if ( $outdated_count > 0 ): ?>
				<span style="font-size:14px;font-weight:normal;color:#dc3232;margin-left:8px;">
					&mdash; <?php echo (int) $outdated_count; ?> outdated
				</span>
			<?php endif; ?>
		</h2>

		<?php if ( empty( $all ) ): ?>
			<p>No WooCommerce template overrides found in your theme.</p>
		<?php else: ?>
			<?php if ( $outdated_count > 0 ): ?>
				<p style="color:#b45309;">
					&#9888; <strong><?php echo (int) $outdated_count; ?></strong> override(s) are behind the installed WooCommerce version &mdash;
					hit <strong>Run Now</strong> above to auto-merge them.
				</p>
			<?php else: ?>
				<p style="color:#46b450;">&#10003; All template overrides are up to date.</p>
			<?php endif; ?>

			<table class="wp-list-table widefat striped" style="max-width:900px;">
				<thead>
					<tr>
						<th>Template</th>
						<th style="width:110px;">Your version</th>
						<th style="width:130px;">Core version</th>
						<th style="width:110px;">Status</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $sorted as $o ):
						$is_outdated = version_compare( $o['base_version'], $o['core_version'], '<' );
					?>
					<tr>
						<td><code><?php echo esc_html( $o['path'] ); ?></code></td>
						<td><?php echo esc_html( $o['base_version'] ); ?></td>
						<td><?php echo esc_html( $o['core_version'] ); ?></td>
						<td>
							<?php if ( $is_outdated ): ?>
								<span style="color:#dc3232;font-weight:600;">&#9888; Outdated</span>
							<?php else: ?>
								<span style="color:#46b450;">&#10003; Up to date</span>
							<?php endif; ?>
						</td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
		<?php
	}

	// -------------------------------------------------------------------------
	// Actions
	// -------------------------------------------------------------------------

	public function handle_run_now(): void {
		check_admin_referer( 'wc_tu_run_now' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Insufficient permissions.' );
		}

		// Stamp when we kicked this off so the UI can poll for completion.
		update_option( 'wc_tu_run_started', time() );

		// Clear any pending async hook to avoid duplicates.
		wp_clear_scheduled_hook( WC_TU_Cron::ASYNC_HOOK );

		// Schedule the run for immediate execution.
		wp_schedule_single_event( time() - 1, WC_TU_Cron::ASYNC_HOOK );

		// Spawn WP-Cron non-blocking so it fires right away without the browser waiting.
		wp_remote_get(
			add_query_arg( 'doing_wp_cron', '', site_url( 'wp-cron.php' ) ),
			[
				'timeout'   => 0.01,
				'blocking'  => false,
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WP core filter
			]
		);

		wp_safe_redirect( admin_url( 'admin.php?page=template-updater-for-woocommerce&wc_tu_queued=1' ) );
		exit;
	}

	public function handle_stop_run(): void {
		check_admin_referer( 'wc_tu_stop_run' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Insufficient permissions.' );
		}

		update_option( 'wc_tu_stop_requested', true );

		wp_safe_redirect( admin_url( 'admin.php?page=template-updater-for-woocommerce&wc_tu_queued=1' ) );
		exit;
	}

	public function handle_force_reset(): void {
		check_admin_referer( 'wc_tu_force_reset' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Insufficient permissions.' );
		}

		// Clear all run state.
		delete_option( 'wc_tu_stop_requested' );
		delete_option( 'wc_tu_run_started' );
		delete_option( 'wc_tu_run_progress' );

		// Clear all scan state.
		delete_option( 'wc_tu_clean_scan_started' );
		delete_option( 'wc_tu_clean_progress' );

		// Cancel any queued cron events.
		wp_clear_scheduled_hook( WC_TU_Cron::ASYNC_HOOK );
		wp_clear_scheduled_hook( WC_TU_Cleaner::SCAN_HOOK );

		wp_safe_redirect( admin_url( 'admin.php?page=template-updater-for-woocommerce&wc_tu_reset=1' ) );
		exit;
	}

	public function handle_clear_conflicts(): void {
		check_admin_referer( 'wc_tu_clear_conflicts' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Insufficient permissions.' );
		}

		$last_run = get_option( 'wc_tu_last_run', null );

		if ( $last_run && ! empty( $last_run['results']['conflicts'] ) ) {
			foreach ( $last_run['results']['conflicts'] as $r ) {
				if ( ! empty( $r['conflict_file'] ) && file_exists( $r['conflict_file'] ) ) {
					wp_delete_file( $r['conflict_file'] );
				}
			}
			// Clear the conflicts list from last run so the table disappears.
			$last_run['results']['conflicts'] = [];
			update_option( 'wc_tu_last_run', $last_run );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=template-updater-for-woocommerce&wc_tu_conflicts_cleared=1' ) );
		exit;
	}

	public function handle_save_settings(): void {
		check_admin_referer( 'wc_tu_save_settings' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Insufficient permissions.' );
		}

		if ( ! empty( $_POST['wc_tu_clear_api_key'] ) ) {
			delete_option( 'wc_tu_anthropic_api_key' );
		} elseif ( ! empty( $_POST['wc_tu_anthropic_api_key'] ) ) {
			update_option( 'wc_tu_anthropic_api_key', sanitize_text_field( wp_unslash( $_POST['wc_tu_anthropic_api_key'] ) ) );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=template-updater-for-woocommerce&tab=settings&wc_tu_saved=1' ) );
		exit;
	}

	public function handle_scan_uncustomized(): void {
		check_admin_referer( 'wc_tu_scan_uncustomized' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Insufficient permissions.' );
		}

		// Clear any previous results and stamp start time.
		delete_option( 'wc_tu_clean_results' );
		update_option( 'wc_tu_clean_scan_started', time() );

		// Schedule the async scan.
		wp_clear_scheduled_hook( WC_TU_Cleaner::SCAN_HOOK );
		wp_schedule_single_event( time() - 1, WC_TU_Cleaner::SCAN_HOOK );

		// Spawn WP-Cron non-blocking.
		wp_remote_get(
			add_query_arg( 'doing_wp_cron', '', site_url( 'wp-cron.php' ) ),
			[
				'timeout'   => 0.01,
				'blocking'  => false,
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WP core filter
			]
		);

		wp_safe_redirect( admin_url( 'admin.php?page=template-updater-for-woocommerce&wc_tu_scanning=1' ) );
		exit;
	}

	public function handle_remove_uncustomized(): void {
		check_admin_referer( 'wc_tu_remove_uncustomized' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Insufficient permissions.' );
		}

		$cleaner = new WC_TU_Cleaner();
		$outcome = $cleaner->remove();

		$removed = count( $outcome['removed'] );
		$failed  = count( $outcome['failed'] ?? [] );
		$dirs    = (int) $outcome['dirs_removed'];

		// Clear the scan-started flag so the button resets to "Scan" instead of
		// staying stuck in the "Scanning…" disabled state (remove() deletes the
		// results option, which would otherwise make is_scan_in_progress() return true).
		delete_option( 'wc_tu_clean_scan_started' );

		wp_safe_redirect( admin_url( "admin.php?page=template-updater-for-woocommerce&wc_tu_clean_done=1&removed={$removed}&dirs={$dirs}&failed={$failed}" ) );
		exit;
	}

	public function handle_clean_bak_files(): void {
		check_admin_referer( 'wc_tu_clean_bak_files' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Insufficient permissions.' );
		}

		$orphaned = $this->find_orphaned_bak_files();
		$deleted  = 0;
		foreach ( $orphaned as $bak ) {
			wp_delete_file( $bak );
			if ( ! file_exists( $bak ) ) {
				$deleted++;
			}
		}

		wp_safe_redirect( admin_url( "admin.php?page=template-updater-for-woocommerce&wc_tu_bak_cleaned=1&deleted={$deleted}" ) );
		exit;
	}

	public function handle_delete_selected(): void {
		check_admin_referer( 'wc_tu_delete_selected' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Insufficient permissions.' );
		}

		$raw_paths = isset( $_POST['wc_tu_paths'] ) && is_array( $_POST['wc_tu_paths'] )
			? array_map( 'sanitize_text_field', wp_unslash( $_POST['wc_tu_paths'] ) )
			: [];

		if ( empty( $raw_paths ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=template-updater-for-woocommerce&wc_tu_deleted=1&deleted=0&failed=0' ) );
			exit;
		}

		// Build a map of rel_path -> absolute_path from the stored scan results.
		// Includes both customized and uncustomized entries so either can be deleted.
		$results     = get_option( 'wc_tu_clean_results', null );
		$abs_map     = [];
		$all_scanned = array_merge(
			$results['customized']   ?? [],
			$results['uncustomized'] ?? []
		);
		foreach ( $all_scanned as $item ) {
			if ( is_array( $item ) && ! empty( $item['absolute_path'] ) ) {
				$abs_map[ $item['path'] ] = $item['absolute_path'];
			}
		}

		$deleted = 0;
		$failed  = 0;
		$deleted_paths = [];

		foreach ( $raw_paths as $rel ) {
			$abs = $abs_map[ $rel ] ?? '';
			if ( ! $abs ) {
				$failed++;
				continue;
			}
			if ( ! file_exists( $abs ) ) {
				// Already gone — count as deleted and clean up from results.
				$deleted++;
				$deleted_paths[] = $rel;
				continue;
			}
			wp_delete_file( $abs );
			if ( ! file_exists( $abs ) ) {
				$deleted++;
				$deleted_paths[] = $rel;
			} else {
				$failed++;
			}
		}

		// Remove deleted files from stored scan results so the table
		// shrinks immediately on the next page load.
		if ( $results && ! empty( $deleted_paths ) ) {
			foreach ( [ 'customized', 'uncustomized' ] as $key ) {
				$results[ $key ] = array_values( array_filter(
					$results[ $key ] ?? [],
					function ( $item ) use ( $deleted_paths ) {
						$p = is_array( $item ) ? ( $item['path'] ?? '' ) : $item;
						return ! in_array( $p, $deleted_paths, true );
					}
				) );
			}
			update_option( 'wc_tu_clean_results', $results );
		}

		// Also prune empty woocommerce/ sub-dirs (best-effort).
		$stylesheet_wc = rtrim( str_replace( '\\', '/', trailingslashit( get_stylesheet_directory() ) . 'woocommerce' ), '/' );
		$template_wc   = rtrim( str_replace( '\\', '/', trailingslashit( get_template_directory() )   . 'woocommerce' ), '/' );

		foreach ( $deleted_paths as $rel ) {
			foreach ( [ $stylesheet_wc, $template_wc ] as $stop ) {
				$dir = str_replace( '\\', '/', dirname( $stop . '/' . ltrim( $rel, '/' ) ) );
				while ( strlen( $dir ) > strlen( $stop ) ) {
					if ( is_dir( $dir ) && count( scandir( $dir ) ) === 2 ) {
						@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions.file_system_operations_rmdir,WordPressVIPMinimum.Functions.RestrictedFunctions.directory_rmdir
					}
					$dir = dirname( $dir );
				}
			}
		}

		wp_safe_redirect( admin_url( "admin.php?page=template-updater-for-woocommerce&wc_tu_deleted=1&deleted={$deleted}&failed={$failed}" ) );
		exit;
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Returns absolute paths of .bak.* files in the theme's woocommerce/ directory
	 * that have no corresponding .php template (i.e. the original was deleted).
	 * Checks both child and parent theme directories.
	 */
	private function find_orphaned_bak_files(): array {
		$dirs = array_unique( [
			trailingslashit( get_stylesheet_directory() ) . 'woocommerce/',
			trailingslashit( get_template_directory() )   . 'woocommerce/',
		] );

		$orphaned = [];

		foreach ( $dirs as $dir ) {
			if ( ! is_dir( $dir ) ) {
				continue;
			}

			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS )
			);

			foreach ( $iterator as $file ) {
				$abs = str_replace( '\\', '/', $file->getPathname() );
				// Match .bak.{version} suffix (e.g. cart.php.bak.3.7.0).
				if ( ! preg_match( '/\.bak\.[^\/]+$/', $abs ) ) {
					continue;
				}
				// Derive the expected .php path by stripping the .bak.{version} suffix.
				$php = preg_replace( '/\.bak\.[^\/]+$/', '', $abs );
				if ( ! file_exists( $php ) ) {
					$orphaned[] = $abs;
				}
			}
		}

		return $orphaned;
	}

	/**
	 * Returns true while an async run has been queued but not yet completed.
	 */
	private function is_run_in_progress(): bool {
		$started   = (int) get_option( 'wc_tu_run_started', 0 );
		$last_run  = get_option( 'wc_tu_last_run', null );
		$last_time = $last_run ? (int) $last_run['time'] : 0;
		return $started > 0 && $last_time < $started;
	}

	/**
	 * Returns true while an async uncustomized scan is in progress.
	 */
	private function is_scan_in_progress(): bool {
		$started      = (int) get_option( 'wc_tu_clean_scan_started', 0 );
		$clean_results = get_option( 'wc_tu_clean_results', null );
		$results_time  = $clean_results ? (int) $clean_results['time'] : 0;
		return $started > 0 && $results_time < $started;
	}

	// -------------------------------------------------------------------------
	// Styles
	// -------------------------------------------------------------------------

	private function inline_css(): string {
		return '
			.wc-tu-wrap h2 { margin-top: 28px; }
			.wc-tu-wrap h3 { margin-top: 16px; }
			.wc-tu-preflight th { background: #f0f0f1; }
			.wc-tu-preflight td, .wc-tu-preflight th { padding: 8px 12px; }
		';
	}

	// -------------------------------------------------------------------------
	// Diff viewer
	// -------------------------------------------------------------------------

	/**
	 * Renders a full-page inline diff between the theme override and the
	 * original WC template it was based on.
	 *
	 * Reference priority:
	 *   1. GitHub at base_version  — shows exactly what changed from the original copy.
	 *   2. Installed WC core file  — fallback when GitHub is unavailable.
	 */
	private function render_diff_page( string $rel_path ): void {
		// Look up stored override metadata from the most recent scan.
		$results  = get_option( 'wc_tu_clean_results', null );
		$override = null;
		if ( $results && ! empty( $results['customized'] ) ) {
			foreach ( $results['customized'] as $item ) {
				if ( is_array( $item ) && ( $item['path'] ?? '' ) === $rel_path ) {
					$override = $item;
					break;
				}
			}
		}

		$back_url = admin_url( 'admin.php?page=template-updater-for-woocommerce' );

		echo '<div class="wrap wc-tu-wrap">';
		echo '<h1 style="font-size:1.3em;">Template Diff</h1>';
		echo '<p><a href="' . esc_url( $back_url ) . '">&larr; Back to Housekeeping</a></p>';

		if ( ! $override ) {
			echo '<div class="notice notice-error inline"><p>File not found in scan results. Please re-scan first.</p></div>';
			echo '</div>';
			return;
		}

		$abs_path = $override['absolute_path'] ?? '';
		if ( ! $abs_path || ! file_exists( $abs_path ) ) {
			echo '<div class="notice notice-error inline"><p>Theme file not found on disk: <code>' . esc_html( $rel_path ) . '</code></p></div>';
			echo '</div>';
			return;
		}

		$theme_content = file_get_contents( $abs_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( $theme_content === false ) {
			echo '<div class="notice notice-error inline"><p>Could not read theme file.</p></div>';
			echo '</div>';
			return;
		}

		// Load reference: GitHub at base_version first, installed core as fallback.
		$fetcher        = new WC_TU_Fetcher();
		$ref_content    = null;
		$ref_label      = '';
		$github_content = $fetcher->fetch( $override['base_version'], $rel_path );

		if ( $github_content !== null ) {
			$ref_content = $github_content;
			$ref_label   = 'WC&nbsp;' . esc_html( $override['base_version'] ) . ' via GitHub &mdash; the original you copied';
		} elseif ( ! empty( $override['core_path'] ) && file_exists( $override['core_path'] ) ) {
			$ref_content = file_get_contents( $override['core_path'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$ref_label   = 'WC&nbsp;' . esc_html( $override['core_version'] ) . ' installed on disk';
		}

		if ( $ref_content === null || $ref_content === false ) {
			echo '<div class="notice notice-warning inline"><p>Could not load a reference version for this file (GitHub unavailable and installed core not found). Try again later.</p></div>';
			echo '</div>';
			return;
		}

		// File metadata table.
		echo '<table class="widefat" style="max-width:660px;margin-bottom:20px;border-collapse:collapse;">';
		echo '<tbody>';
		echo '<tr><th style="width:190px;padding:8px 12px;background:#f0f0f1;">File</th><td style="padding:8px 12px;"><code>' . esc_html( $rel_path ) . '</code></td></tr>';
		echo '<tr><th style="padding:8px 12px;background:#f0f0f1;">Your @version tag</th><td style="padding:8px 12px;">' . esc_html( $override['base_version'] ) . '</td></tr>';
		echo '<tr><th style="padding:8px 12px;background:#f0f0f1;">Current WC version</th><td style="padding:8px 12px;">' . esc_html( $override['core_version'] ) . '</td></tr>';
		echo '<tr><th style="padding:8px 12px;background:#f0f0f1;">Comparing against</th><td style="padding:8px 12px;">' . wp_kses_post( $ref_label ) . '</td></tr>';
		echo '</tbody></table>';

		// Delete button — lets the user nuke this file right from the diff page.
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-bottom:20px;" '
			. 'onsubmit="return confirm(\'Delete this file from your theme? This cannot be undone.\')">';
		wp_nonce_field( 'wc_tu_delete_selected' );
		echo '<input type="hidden" name="action" value="wc_tu_delete_selected">';
		echo '<input type="hidden" name="wc_tu_paths[]" value="' . esc_attr( $rel_path ) . '">';
		echo '<button type="submit" class="button" style="color:#dc3232;border-color:#dc3232;">&#128465; Delete This File</button>';
		echo '<span style="margin-left:10px;color:#888;font-size:13px;">Removes this override — WC will use its own copy automatically.</span>';
		echo '</form>';

		// Compute line diff.
		$old_lines = explode( "\n", str_replace( [ "\r\n", "\r" ], "\n", $ref_content ) );
		$new_lines = explode( "\n", str_replace( [ "\r\n", "\r" ], "\n", $theme_content ) );
		$diff      = $this->diff_lines( $old_lines, $new_lines );

		// Quick change summary.
		$adds = 0;
		$dels = 0;
		foreach ( $diff as $d ) {
			if ( $d['t'] === '+' ) {
				$adds++;
			}
			if ( $d['t'] === '-' ) {
				$dels++;
			}
		}

		if ( $adds === 0 && $dels === 0 ) {
			echo '<div class="notice notice-success inline"><p>No code differences found &mdash; files are identical after normalisation.</p></div>';
		} else {
			echo '<p style="font-size:13px;color:#555;margin-bottom:8px;">';
			echo '<span style="color:#116329;font-weight:600;">&#43;' . (int) $adds . ' added</span>&nbsp;&nbsp;';
			echo '<span style="color:#b91c1c;font-weight:600;">&minus;' . (int) $dels . ' removed</span>';
			echo '</p>';
			echo '<div style="max-width:100%;overflow-x:auto;">';
			echo wp_kses_post( $this->render_diff_html( $diff ) );
			echo '</div>';
		}

		echo '</div>';
	}

	/**
	 * Computes a line-level LCS diff between two arrays of lines.
	 *
	 * Returns an array of [ 't' => '='|'+'|'-', 'l' => line_text ] entries:
	 *   '=' — unchanged line present in both old and new
	 *   '+' — line added in new (present only in new / your theme file)
	 *   '-' — line removed from old (present only in the original / WC file)
	 *
	 * Uses full dynamic-programming LCS. Capped at 1500 lines per side to
	 * avoid excessive memory use; files larger than that fall back to a simple
	 * all-removed / all-added listing.
	 */
	private function diff_lines( array $old, array $new ): array {
		$m = count( $old );
		$n = count( $new );

		// Safety cap: LCS table is O(m*n). 1500*1500 ≈ 2.25 M entries — fine.
		if ( $m > 1500 || $n > 1500 ) {
			$result = [];
			foreach ( $old as $l ) {
				$result[] = [ 't' => '-', 'l' => $l ];
			}
			foreach ( $new as $l ) {
				$result[] = [ 't' => '+', 'l' => $l ];
			}
			return $result;
		}

		// Build the LCS table (standard DP).
		$dp = [];
		for ( $i = 0; $i <= $m; $i++ ) {
			$dp[ $i ] = array_fill( 0, $n + 1, 0 );
		}
		for ( $i = 1; $i <= $m; $i++ ) {
			$prev = $dp[ $i - 1 ];
			$curr = &$dp[ $i ];
			for ( $j = 1; $j <= $n; $j++ ) {
				if ( $old[ $i - 1 ] === $new[ $j - 1 ] ) {
					$curr[ $j ] = $prev[ $j - 1 ] + 1;
				} else {
					$curr[ $j ] = $curr[ $j - 1 ] > $prev[ $j ] ? $curr[ $j - 1 ] : $prev[ $j ];
				}
			}
		}
		unset( $curr );

		// Trace back from dp[m][n], collecting entries in reverse then flipping.
		$trace = [];
		$i     = $m;
		$j     = $n;
		while ( $i > 0 || $j > 0 ) {
			if ( $i > 0 && $j > 0 && $old[ $i - 1 ] === $new[ $j - 1 ] ) {
				$trace[] = [ 't' => '=', 'l' => $old[ $i - 1 ] ];
				$i--;
				$j--;
			} elseif ( $j > 0 && ( $i === 0 || $dp[ $i ][ $j - 1 ] >= $dp[ $i - 1 ][ $j ] ) ) {
				$trace[] = [ 't' => '+', 'l' => $new[ $j - 1 ] ];
				$j--;
			} else {
				$trace[] = [ 't' => '-', 'l' => $old[ $i - 1 ] ];
				$i--;
			}
		}

		return array_reverse( $trace );
	}

	/**
	 * Renders a colored unified-diff view from the output of diff_lines().
	 *
	 * Long runs of unchanged '=' lines are collapsed with a summary line,
	 * showing $context lines on each side of every changed block.
	 *
	 * @param  array $diff    Output of diff_lines().
	 * @param  int   $context Unchanged context lines to show each side of changes.
	 * @return string         HTML <pre> block (already escaped, safe to echo).
	 */
	private function render_diff_html( array $diff, int $context = 4 ): string {
		$html  = '<div style="font-size:12px;margin-bottom:8px;color:#555;">';
		$html .= '<span style="background:#e6ffed;border:1px solid #94d3a2;padding:1px 7px;margin-right:10px;border-radius:2px;">&#43;&nbsp;Your additions</span>';
		$html .= '<span style="background:#ffeef0;border:1px solid #f9c0c0;padding:1px 7px;border-radius:2px;">&minus;&nbsp;Removed from original</span>';
		$html .= '</div>';

		$html .= '<pre style="background:#f6f8fa;border:1px solid #d1d5da;border-radius:4px;padding:0;margin:0;overflow-x:auto;font-size:12px;line-height:1.6;">';

		$total = count( $diff );
		$i     = 0;

		while ( $i < $total ) {
			// ---- Changed line -----------------------------------------------
			if ( $diff[ $i ]['t'] !== '=' ) {
				$entry = $diff[ $i ];
				if ( $entry['t'] === '+' ) {
					$html .= '<span style="display:block;padding:0 12px;background:#e6ffed;color:#116329;">&#43; ' . esc_html( $entry['l'] ) . '</span>';
				} else {
					$html .= '<span style="display:block;padding:0 12px;background:#ffeef0;color:#b91c1c;">&minus; ' . esc_html( $entry['l'] ) . '</span>';
				}
				$i++;
				continue;
			}

			// ---- Run of unchanged lines -------------------------------------
			$run_start = $i;
			while ( $i < $total && $diff[ $i ]['t'] === '=' ) {
				$i++;
			}
			$run_end = $i; // exclusive
			$run_len = $run_end - $run_start;
			$at_start = ( $run_start === 0 );
			$at_end   = ( $run_end === $total );

			// Helper closure to output one context line.
			$ctx_line = static function ( string $line ) use ( &$html ): void {
				$html .= '<span style="display:block;padding:0 12px;color:#555;">&nbsp;&nbsp;' . esc_html( $line ) . '</span>';
			};
			$skip_line = static function ( int $n ) use ( &$html ): void {
				$html .= '<span style="display:block;padding:2px 12px;background:#f0f0f0;color:#888;font-style:italic;">&hellip;&nbsp;' . $n . '&nbsp;unchanged lines&nbsp;&hellip;</span>';
			};

			if ( $at_start && $at_end ) {
				// Entire diff is unchanged — shouldn't happen after add/del check, but just in case.
				$skip_line( $run_len );
				continue;
			}

			if ( $at_start ) {
				// Leading context: show only the last $context lines.
				$show_from = max( $run_start, $run_end - $context );
				if ( $show_from > $run_start ) {
					$skip_line( $show_from - $run_start );
				}
				for ( $k = $show_from; $k < $run_end; $k++ ) {
					$ctx_line( $diff[ $k ]['l'] );
				}
				continue;
			}

			if ( $at_end ) {
				// Trailing context: show only the first $context lines.
				$show_to = min( $run_end, $run_start + $context );
				for ( $k = $run_start; $k < $show_to; $k++ ) {
					$ctx_line( $diff[ $k ]['l'] );
				}
				if ( $show_to < $run_end ) {
					$skip_line( $run_end - $show_to );
				}
				continue;
			}

			// Middle context: show $context at each end, skip the middle.
			if ( $run_len <= $context * 2 ) {
				for ( $k = $run_start; $k < $run_end; $k++ ) {
					$ctx_line( $diff[ $k ]['l'] );
				}
			} else {
				for ( $k = $run_start; $k < $run_start + $context; $k++ ) {
					$ctx_line( $diff[ $k ]['l'] );
				}
				$skip_line( $run_len - $context * 2 );
				for ( $k = $run_end - $context; $k < $run_end; $k++ ) {
					$ctx_line( $diff[ $k ]['l'] );
				}
			}
		}

		$html .= '</pre>';
		return $html;
	}
}

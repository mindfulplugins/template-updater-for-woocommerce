<?php
defined( 'ABSPATH' ) || exit;

/**
 * Registers and manages the WP-Cron schedule that triggers automatic
 * template update passes.
 *
 * Default schedule: daily. Can be changed via the wc_tu_cron_schedule filter.
 *
 * Two hooks exist:
 *   WC_TU_Cron::HOOK       — recurring daily run (scheduled on activation).
 *   WC_TU_Cron::ASYNC_HOOK — one-shot run spawned by the "Run Now" button.
 */
class WC_TU_Cron {

	const HOOK       = 'wc_tu_run';
	const ASYNC_HOOK = 'wc_tu_run_async';

	public function __construct() {
		add_action( self::HOOK,       [ $this, 'run' ] );
		add_action( self::ASYNC_HOOK, [ $this, 'run' ] );
	}

	public static function activate(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			$schedule = apply_filters( 'wc_tu_cron_schedule', 'daily' );
			// Start 24 hours from now so the first run is always manual.
			// The user should review settings and trigger Run Now themselves first.
			wp_schedule_event( time() + DAY_IN_SECONDS, $schedule, self::HOOK );
		}
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( self::HOOK );
		wp_clear_scheduled_hook( self::ASYNC_HOOK );
	}

	public function run(): void {
		$runner = new WC_TU_Runner();
		$runner->run();
	}
}

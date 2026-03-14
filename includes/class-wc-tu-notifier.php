<?php
defined( 'ABSPATH' ) || exit;

/**
 * Sends an admin email summary after each update pass.
 *
 * The recipient defaults to the site admin email but can be overridden
 * via the wc_tu_notification_email filter.
 */
class WC_TU_Notifier {

	/**
	 * @param array $results Results array from WC_TU_Runner::run().
	 */
	public function send( array $results ): void {
		$to      = apply_filters( 'wc_tu_notification_email', get_option( 'admin_email' ) );
		$subject = $this->build_subject( $results );
		$body    = $this->build_body( $results );

		wp_mail( $to, $subject, $body );
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	private function build_subject( array $results ): string {
		$updated   = count( $results['updated'] );
		$conflicts = count( $results['conflicts'] );
		$errors    = count( $results['errors'] );

		$parts = [];
		if ( $updated )   $parts[] = "{$updated} updated";
		if ( $conflicts ) $parts[] = "{$conflicts} conflict" . ( $conflicts > 1 ? 's' : '' );
		if ( $errors )    $parts[] = "{$errors} error" . ( $errors > 1 ? 's' : '' );

		$summary = $parts ? implode( ', ', $parts ) : 'nothing to do';

		return sprintf( '[%s] WC Template Updater: %s', get_bloginfo( 'name' ), $summary );
	}

	private function build_body( array $results ): string {
		$site = get_bloginfo( 'name' );
		$time = wp_date( 'Y-m-d H:i:s' );
		$url  = admin_url( 'admin.php?page=template-updater-for-woocommerce' );

		$lines = [
			"WC Template Updater — {$site}",
			"Run completed: {$time}",
			str_repeat( '-', 60 ),
			'',
		];

		// Updated.
		if ( ! empty( $results['updated'] ) ) {
			$lines[] = 'AUTO-UPDATED (' . count( $results['updated'] ) . '):';
			foreach ( $results['updated'] as $r ) {
				$lines[] = "  ✓ {$r['path']}  ({$r['from_version']} → {$r['to_version']})";
			}
			$lines[] = '';
		}

		// Conflicts.
		if ( ! empty( $results['conflicts'] ) ) {
			$lines[] = 'CONFLICTS — MANUAL REVIEW NEEDED (' . count( $results['conflicts'] ) . '):';
			foreach ( $results['conflicts'] as $r ) {
				$lines[] = "  ✗ {$r['path']}  ({$r['from_version']} → {$r['to_version']})";
				$lines[] = "    Conflict file: {$r['conflict_file']}";
			}
			$lines[] = '';
			$lines[] = "Review conflicts in the WP admin: {$url}";
			$lines[] = '';
		}

		// Errors.
		if ( ! empty( $results['errors'] ) ) {
			$lines[] = 'ERRORS (' . count( $results['errors'] ) . '):';
			foreach ( $results['errors'] as $r ) {
				$lines[] = "  ! {$r['path']}: {$r['message']}";
			}
			$lines[] = '';
		}

		return implode( "\n", $lines );
	}
}

<?php
defined( 'ABSPATH' ) || exit;

/**
 * Resolves WooCommerce template merge conflicts via the Claude API.
 *
 * Used as a fallback when git merge-file reports conflicts. Sends all three
 * versions of the template plus the conflict-marked output to Claude and asks
 * it to produce a clean, fully-resolved PHP file.
 *
 * resolve() returns a structured array { text: ?string, error: ?string }.
 * On success, 'text' holds the resolved content and 'error' is null.
 * On any failure, 'text' is null and 'error' contains a human-readable reason
 * that is stored on the conflict result and surfaced in the admin UI.
 */
class WC_TU_AI_Resolver {

	/** @var string Anthropic API key. */
	private string $api_key;

	public function __construct( string $api_key ) {
		$this->api_key = $api_key;
	}

	/**
	 * Returns true when an API key is configured.
	 */
	public function is_available(): bool {
		return ! empty( $this->api_key );
	}

	/**
	 * Attempt to resolve merge conflicts using Claude.
	 *
	 * @param string $ours     Customised theme override (what we want to keep).
	 * @param string $base     Original WC template the theme was based on.
	 * @param string $theirs   New WC core template to upgrade to.
	 * @param string $conflict Content produced by git merge-file (may have markers).
	 * @param string $path     Relative template path, e.g. templates/cart/cart.php.
	 * @return array { text: ?string, error: ?string }
	 *               'text'  is the resolved file content on success, null on failure.
	 *               'error' is a human-readable failure reason, null on success.
	 */
	public function resolve( string $ours, string $base, string $theirs, string $conflict, string $path ): array {
		$request_body = wp_json_encode( [
			'model'      => 'claude-opus-4-6',
			'max_tokens' => 16000,
			'thinking'   => [ 'type' => 'adaptive' ],
			'messages'   => [
				[
					'role'    => 'user',
					'content' => $this->build_prompt( $ours, $base, $theirs, $conflict, $path ),
				],
			],
		] );

		$response = wp_remote_post( 'https://api.anthropic.com/v1/messages', [
			'timeout' => 120,
			'headers' => [
				'Content-Type'      => 'application/json',
				'x-api-key'         => $this->api_key,
				'anthropic-version' => '2023-06-01',
			],
			'body'    => $request_body,
		] );

		if ( is_wp_error( $response ) ) {
			return [ 'text' => null, 'error' => 'Network error: ' . $response->get_error_message() ];
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( 200 !== $code ) {
			$body    = wp_remote_retrieve_body( $response );
			$data    = json_decode( $body, true );
			$api_msg = isset( $data['error']['message'] ) ? (string) $data['error']['message'] : '';

			if ( 401 === $code ) {
				$label = 'API key invalid';
			} elseif ( 403 === $code ) {
				$label = 'API key unauthorized';
			} elseif ( 429 === $code ) {
				$label = 'Rate limited — try again later';
			} elseif ( 500 === $code ) {
				$label = 'Anthropic server error';
			} elseif ( 529 === $code ) {
				$label = 'Anthropic overloaded — try again later';
			} else {
				$label = 'API error';
			}

			$detail = $api_msg ? ': ' . $api_msg : '';
			return [ 'text' => null, 'error' => $label . ' (HTTP ' . $code . ')' . $detail ];
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		$text = null;

		foreach ( $data['content'] ?? [] as $block ) {
			if ( 'text' === ( $block['type'] ?? '' ) ) {
				$text = $block['text'];
				break;
			}
		}

		if ( ! $text ) {
			return [ 'text' => null, 'error' => 'API returned an empty response' ];
		}

		return $this->clean_and_validate( $text );
	}

	/**
	 * Strips any markdown wrapping Claude may have added and validates the output.
	 *
	 * @return array { text: ?string, error: ?string }
	 */
	private function clean_and_validate( string $text ): array {
		$text = trim( $text );

		// Strip ```php ... ``` or ``` ... ``` fences.
		$text = preg_replace( '/^```php\s*/i', '', $text );
		$text = preg_replace( '/^```\s*/i',    '', $text );
		$text = preg_replace( '/\s*```\s*$/i', '', $text );
		$text = trim( $text );

		if ( empty( $text ) ) {
			return [ 'text' => null, 'error' => 'API returned an empty response' ];
		}

		// Conflict markers still present — AI didn't fully resolve it.
		if ( strpos( $text, '<<<<<<<' ) !== false ) {
			return [ 'text' => null, 'error' => 'AI could not fully resolve all conflicts' ];
		}

		// Must look like a PHP file.
		if ( strpos( $text, '<?php' ) === false ) {
			return [ 'text' => null, 'error' => 'AI response was not valid PHP' ];
		}

		return [ 'text' => $text, 'error' => null ];
	}

	private function build_prompt( string $ours, string $base, string $theirs, string $conflict, string $path ): string {
		return
			"You are an expert PHP and WooCommerce developer performing a 3-way template merge.\n\n" .
			"Template: {$path}\n\n" .
			"## BASE — original WC template our theme was customised from:\n{$base}\n\n" .
			"## OURS — our customised theme override:\n{$ours}\n\n" .
			"## THEIRS — new WC core template to upgrade to:\n{$theirs}\n\n" .
			"## Git merge result (contains conflict markers):\n{$conflict}\n\n" .
			"Produce the fully resolved merged PHP file. Preserve all theme customisations while incorporating the relevant WC core updates. " .
			"Output ONLY the raw PHP file content — no markdown, no code fences, no explanation. " .
			"The output must begin with <?php.";
	}
}

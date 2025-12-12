<?php
declare( strict_types = 1 );
namespace Lib;

use JsonException;

/**
 * Represents an HTTP response with optional HATEOAS metadata
 *
 * This class handles both HATEOAS responses (with _links and _embedded)
 * and plain JSON responses. The has_hateoas flag indicates which type.
 */
readonly class Http_Response {
	/**
	 * @param array<string, mixed>  $data        The main response payload
	 * @param int                   $status_code HTTP status code
	 * @param bool                  $has_hateoas Whether response contains HATEOAS metadata
	 * @param array<string, array>  $links       Optional HATEOAS links (rel => link data)
	 * @param array<string, array>  $embedded    Optional embedded resources
	 * @param array<string, string> $headers     Response headers
	 */
	public function __construct(
		public array $data,
		public int $status_code,
		public bool $has_hateoas = false,
		public array $links      = [],
		public array $embedded   = [],
		public array $headers    = [],
	) {}

	/**
	 * Check if a HATEOAS link relation exists
	 *
	 * @param string $rel
	 *
	 * @return bool
	 */
	public function has_link( string $rel ): bool {
		return isset( $this->links[ $rel ] );
	}

	/**
	 * Get HATEOAS link by relation name
	 * Returns null if link doesn't exist or response isn't HATEOAS
	 *
	 * @param string $rel
	 *
	 * @return array|null
	 */
	public function get_link( string $rel ): ?array {
		return $this->links[ $rel ] ?? null;
	}

	/**
	 * Get the URL for a HATEOAS link relation
	 * Convenience method that extracts just the href
	 *
	 * @param string $rel
	 *
	 * @return string|null
	 */
	public function get_link_url( string $rel ): ?string {
		$link = $this->get_link( $rel );

		return $link[ 'href' ] ?? null;
	}

	/**
	 * Get embedded resource by name
	 * Returns null if resource doesn't exist or response isn't HATEOAS
	 *
	 * @param string $name
	 *
	 * @return array|null
	 */
	public function get_embedded( string $name ): ?array {
		return $this->embedded[ $name ] ?? null;
	}

	/**
	 * Parse JSON response into HttpResponse
	 *
	 * Automatically detects HATEOAS structure and extracts links/embedded
	 * resources when present. Falls back to plain data structure otherwise.
	 *
	 * This is the key method that makes the client flexible—it doesn't
	 * require HATEOAS fields, but extracts them when they exist.
	 *
	 * @param string $json
	 * @param int    $status_code
	 * @param array  $headers
	 *
	 * @return Http_Response
	 * @throws JsonException
	 */
	public static function from_json(
		string $json,
		int $status_code,
		array $headers = []
	): self {
		$decoded = json_decode( $json, true, 512, JSON_THROW_ON_ERROR );

		// Check if this looks like a HATEOAS response
		// We only consider it HATEOAS if _links exists and contains data
		$has_hateoas = isset( $decoded[ '_links' ] ) && is_array( $decoded[ '_links' ] );

		$links    = [];
		$embedded = [];

		if ( $has_hateoas ) {
			// Extract HATEOAS metadata
			$links = $decoded[ '_links' ];
			unset( $decoded[ '_links' ] );

			// Embedded resources are optional even in HATEOAS
			if ( isset( $decoded[ '_embedded' ] ) && is_array( $decoded[ '_embedded' ] ) ) {
				$embedded = $decoded[ '_embedded' ];
				unset( $decoded[ '_embedded' ] );
			}
		}

		return new self(
			data: $decoded,
			status_code: $status_code,
			has_hateoas: $has_hateoas,
			links: $links,
			embedded: $embedded,
			headers: $headers,
		);
	}

	/**
	 * Get a specific header value (case-insensitive)
	 *
	 * @param string $name
	 *
	 * @return string|null
	 */
	public function get_header( string $name ): ?string {
		$name = strtolower( $name );

		foreach ( $this->headers as $key => $value ) {
			if ( strtolower( $key ) === $name ) {
				return $value;
			}
		}

		return null;
	}

	/**
	 * @return string
	 */
	public function __toString(): string {
		return json_encode( $this );
	}
}

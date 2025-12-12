<?php
declare( strict_types = 1 );
namespace Src;

use JsonException;
use Lib\Enum\Http_Status_Category;
use Lib\HTTP_Result;
use Lib\Http_Response;

use Lib\Circuit_Breaker;

/**
 * Resilient HTTP client with circuit breaker protection
 *
 * This client is format-agnostic: it should work with HATEOAS APIs, plain REST APIs,
 * and everything in between. The response parsing automatically detects and
 * extracts HATEOAS metadata when present.
 */
final class Circuit_Breaker_Client
{
	private const int DEFAULT_TIMEOUT       = 10;
	private const string DEFAULT_USER_AGENT = 'Circuit_Breaker_Client/1.0';

	public function __construct(
		private readonly Circuit_Breaker $circuit_breaker,
		private readonly int $timeout = self::DEFAULT_TIMEOUT,
	) {}

	/**
	 * Perform GET request with circuit breaker protection
	 */
	public function get( string $url, array $headers = [] ): HTTP_Result
	{
		return $this->request( 'GET', $url, $headers );
	}

	/**
	 * Perform POST request with circuit breaker protection
	 */
	public function post( string $url, array $data, array $headers = [] ): HTTP_Result
	{
		return $this->request( 'POST', $url, $headers, $data );
	}

	/**
	 * Perform PUT request with circuit breaker protection
	 */
	public function put( string $url, array $data, array $headers = [] ): HTTP_Result {
		return $this->request( 'PUT', $url, $headers, $data );
	}

	/**
	 * Perform PATCH request with circuit breaker protection
	 */
	public function patch( string $url, array $data, array $headers = [] ): HTTP_Result {
		return $this->request( 'PATCH', $url, $headers, $data );
	}

	/**
	 * Perform DELETE request with circuit breaker protection
	 */
	public function delete( string $url, array $headers = [] ): HTTP_Result {
		return $this->request( 'DELETE', $url, $headers );
	}

	/**
	 * Follow a HATEOAS link from a previous response
	 *
	 * This is a convenience method for HATEOAS APIs. It safely handles
	 * responses that don't have links by returning a descriptive error.
	 *
	 * @param  Http_Response $response
	 * @param  string         $rel
	 * @param  array          $headers
	 *
	 * @return HTTP_Result
	 */
	public function follow_link(
		Http_Response $response,
		string $rel,
		array $headers = []
	): HTTP_Result {
		// Check if response contains HATEOAS metadata
		if ( ! $response->has_hateoas ) {
			return HTTP_Result::failure(
				"Response does not contain HATEOAS links (no _links field found)"
			);
		}

		$link = $response->get_link( $rel );

		if ( null === $link ) {
			return HTTP_Result::failure(
				"Link relation '$rel' not found in response"
			);
		}

		// Extract link details with sensible defaults
		$url    = $link[ 'href' ] ?? null;
		$method = $link[ 'method' ] ?? 'GET';
		$type   = $link[ 'type' ] ?? null;

		if ( null === $url ) {
			return HTTP_Result::failure(
				"Link relation '$rel' is missing href attribute"
			);
		}

		// Add Accept header based on link type if provided
		if ( null !== $type && ! isset( $headers[ 'Accept' ] ) ) {
			$headers[ 'Accept' ] = $type;
		}

		return $this->request( $method, $url, $headers );
	}

	/**
	 * Execute HTTP request with full circuit breaker handling
	 *
	 * This method handles:
	 * - Circuit breaker checks (fail fast if open)
	 * - HTTP request execution via cURL
	 * - Status code classification (success/client error/server error)
	 * - Circuit breaker state updates (success/failure recording)
	 * - Response parsing (both HATEOAS and plain JSON)
	 *
	 * @param string $method   The HTTP method
	 * @param string $url      The request URL.
	 * @param array  $headers  The request headers.
	 * @param ?array $data     Optional PUT|PATCH|POST data.
	 *
	 * @return HTTP_Result
	 */
	private function request(
		string $method,
		string $url,
		array $headers = [],
		?array $data   = null,
	): HTTP_Result {
		// Check circuit before attempting request
		if ( ! $this->circuit_breaker->is_available() ) {
			return HTTP_Result::failure(
				'Circuit breaker is open - service temporarily unavailable',
				503
			);
		}

		// Prepare cURL request
		$ch = curl_init();

		$defaultHeaders = [
			'Accept: application/json',
			'Content-Type: application/json',
			'User-Agent: ' . self::DEFAULT_USER_AGENT,
		];

		foreach ( $headers as $name => $value ) {
			$defaultHeaders[] = "$name: $value";
		}

		curl_setopt_array( $ch, [
			CURLOPT_URL             => $url,
			CURLOPT_RETURNTRANSFER  => true,
			CURLOPT_TIMEOUT         => $this->timeout,
			CURLOPT_CONNECTTIMEOUT  => $this->timeout,
			CURLOPT_HTTPHEADER      => $defaultHeaders,
			CURLOPT_FOLLOWLOCATION  => true,
			CURLOPT_MAXREDIRS       => 5,
			CURLOPT_FORBID_REUSE    => true,
			CURLOPT_HEADER          => true, // Include headers in output
		] );

		// Set method and body for non-GET requests
		if ( 'GET' !== $method ) {
			curl_setopt( $ch, CURLOPT_CUSTOMREQUEST, $method );

			if ( null !== $data ) {
				curl_setopt( $ch, CURLOPT_POSTFIELDS, json_encode( $data ) );
			}
		}

		// Execute request
		$response    = curl_exec( $ch );
		$status_code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		$header_size = curl_getinfo( $ch, CURLINFO_HEADER_SIZE );
		$error       = curl_error( $ch );
		$errno       = curl_errno( $ch );

		// Handle connection errors (timeout, DNS failure, etc.)
		if ( 0 !== $errno ) {
			$this->circuit_breaker->record_failure();

			return HTTP_Result::failure(
				$this->map_curl_error( $errno, $error )
			);
		}

		// Split headers and body
		$header_string    = substr( $response, 0, $header_size );
		$response_body    = substr( $response, $header_size );
		$response_headers = $this->parse_headers( $header_string );

		// Classify HTTP status code
		$category = Http_Status_Category::from_status_code( $status_code );

		// Record success or failure based on status category
		if ( $category->should_record_failure() ) {
			$this->circuit_breaker->record_failure();
		} elseif ( Http_Status_Category::SUCCESS === $category ) {
			$this->circuit_breaker->record_success();
		}
		// Note: 4xx errors don't affect circuit - they're client errors

		// Handle non-success responses
		if ( Http_Status_Category::SUCCESS !== $category ) {
			return HTTP_Result::failure(
				$this->get_error_message( $status_code, $response_body ),
				$status_code
			);
		}

		// Parse successful response
		// The fromJson method automatically detects HATEOAS or plain JSON
		try {
			$http_response = Http_Response::from_json(
				$response_body,
				$status_code,
				$response_headers
			);

			return HTTP_Result::success( $http_response );
		} catch ( JsonException $e ) {
			// JSON parse error doesn't trigger circuit - response was received
			return HTTP_Result::failure(
				'Failed to parse response: ' . $e->getMessage(),
				$status_code
			);
		}
	}

	/**
	 * Parse HTTP headers from response string
	 */
	private function parse_headers( string $header_string ): array
	{
		$headers = [];
		$lines = explode( "\r\n", $header_string );

		foreach ( $lines as $line ) {
			$parts = explode( ':', $line, 2 );
			if ( 2 === count( $parts ) ) {
				$headers[ trim( $parts[ 0 ] ) ] = trim( $parts[ 1 ] );
			}
		}

		return $headers;
	}

	/**
	 * Map cURL error codes to human-readable messages
	 */
	private function map_curl_error( int $errno, string $error ): string
	{
		return match ( $errno ) {
			CURLE_OPERATION_TIMEDOUT   => 'Request timed out',
			CURLE_COULDNT_CONNECT      => 'Could not connect to server',
			CURLE_COULDNT_RESOLVE_HOST => 'Could not resolve hostname',
			CURLE_SSL_CONNECT_ERROR    => 'SSL connection failed',
			default                    => "Connection error: $error",
		};
	}

	/**
	 * Extract error message from response body if available
	 */
	private function get_error_message( int $status_code, string $body ): string
	{
		// Try to parse as JSON first
		$decoded = json_decode( $body, true );

		// Try common error message fields
		$message = $decoded[ 'message' ]
				   ?? $decoded[ 'error' ]
				   ?? $decoded[ 'detail' ]
				   ?? $decoded[ 'error_description' ]
				   ?? null;

		if ( null !== $message ) {
			return "HTTP $status_code: $message";
		}

		// Fall back to generic messages
		return match ( $status_code ) {
			400     => 'Bad Request - Invalid request syntax',
			401     => 'Unauthorized - Authentication required',
			403     => 'Forbidden - Access denied',
			404     => 'Not Found - Resource does not exist',
			429     => 'Too Many Requests - Rate limit exceeded',
			500     => 'Internal Server Error',
			502     => 'Bad Gateway - Invalid upstream response',
			503     => 'Service Unavailable',
			504     => 'Gateway Timeout',
			default => "HTTP error $status_code",
		};
	}
}

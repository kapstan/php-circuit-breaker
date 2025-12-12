<?php
declare( strict_types = 1 );
namespace Lib;

use RuntimeException;

/**
 * HTTP client result type for explicit error handling
 *
 * Using a Result type instead of exceptions for recoverable errors
 * makes error handling more explicit and type-safe.
 */
readonly class HTTP_Result {
	/**
	 * @param bool               $success
	 * @param Http_Response|null $response
	 * @param string|null        $error
	 * @param int|null           $status_code
	 */
	private function __construct(
		public bool $success,
		public ?Http_Response $response,
		public ?string $error,
		public ?int $status_code,
	) {}

	/**
	 * @param Http_Response $response
	 *
	 * @return self
	 */
	public static function success( Http_Response $response ): self {
		return new self( true, $response, null, $response->status_code );
	}

	/**
	 * @param string   $error
	 * @param int|null $status_code
	 *
	 * @return self
	 */
	public static function failure( string $error, ?int $status_code = null ): self {
		return new self( false, null, $error, $status_code );
	}

	/**
	 * Get response data or throw if failed
	 * Convenience method for cases where you want exception-based error handling
	 *
	 * @return Http_Response
	 */
	public function unwrap(): Http_Response
	{
		if ( ! $this->success ) {
			throw new RuntimeException(
				$this->error ?? 'HTTP request failed',
				$this->status_code ?? 0
			);
		}
		return $this->response;
	}

	/**
	 * Get response data or return a default value
	 * Useful for non-critical API calls where you want graceful degradation
	 */
	public function unwrap_or( Http_Response $default ): Http_Response {
		return $this->success ? $this->response : $default;
	}
}

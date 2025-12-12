<?php
declare( strict_types = 1 );
namespace Lib\Enum;

/**
 * HTTP status code classification for circuit breaker decisions
 */
enum Http_Status_Category {
	case SUCCESS;          // 2xx - Record success
	case CLIENT_ERROR;     // 4xx - Don't count against circuit (client's fault)
	case SERVER_ERROR;     // 5xx - Record failure (server's fault)
	case RATE_LIMITED;     // 429 - Special handling, may count as failure

	public static function from_status_code( int $code ): self
	{
		return match (true) {
			$code >= 200 && $code < 300 => self::SUCCESS,
			$code === 429               => self::RATE_LIMITED,
			$code >= 400 && $code < 500 => self::CLIENT_ERROR,
			$code >= 500                => self::SERVER_ERROR,

			default                     => self::CLIENT_ERROR,
		};
	}

	/**
	 * Determine if this status should count as circuit breaker failure
	 */
	public function should_record_failure(): bool
	{
		return match ( $this ) {
			self::SERVER_ERROR, self::RATE_LIMITED => true,
			self::SUCCESS, self::CLIENT_ERROR      => false,
		};
	}
}

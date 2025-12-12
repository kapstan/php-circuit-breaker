<?php
declare( strict_types = 1 );
namespace Lib\Enum;

use JsonException;

/**
 * Circuit Breaker State Enumeration
 *
 * Represents the three possible states of a circuit breaker using PHP 8.1+ backed enum.
 * The string backing allows for clean serialization to storage backends.
 */
enum Circuit_State: string {
	/**
	 * Normal operation - requests flow through, failures are tracked
	 */
	case CLOSED = 'closed';

	/**
	 * Circuit tripped - all requests immediately rejected, service given time to recover
	 */
	case OPEN = 'open';

	/**
	 * Testing recovery - limited requests allowed through to test service health
	 */
	case HALF_OPEN = 'half_open';

	/**
	 * Determine if requests can be attempted in this state
	 *
	 * CLOSED and HALF_OPEN states allow requests through.
	 * OPEN state blocks all requests immediately.
	 *
	 * @return bool True if requests should be attempted, false if blocked
	 */
	public function can_attempt_request(): bool {
		return match ( $this ) {
			self::CLOSED, self::HALF_OPEN => true,
			self::OPEN                    => false,
		};
	}

	/**
	 * Get human-readable description of the state
	 *
	 * Useful for logging, debugging, and monitoring dashboards.
	 *
	 * @return string Description of what this state means
	 */
	public function description(): string {
		return match ( $this ) {
			self::CLOSED    => 'Circuit closed - normal operation',
			self::OPEN      => 'Circuit open - requests blocked',
			self::HALF_OPEN => 'Circuit half-open - testing recovery',
		};
	}

	/**
	 * Check if a string value is valid for this enum
	 *
	 * Custom helper method to validate values before attempting conversion.
	 * This is NOT provided by PHP automatically.
	 *
	 * @param string $value The string value to validate
	 * @return bool True if the value corresponds to a valid case
	 */
	public static function is_valid( string $value ): bool {
		return self::tryFrom( $value ) !== null;
	}

	/**
	 * Get all possible string values for this enum
	 *
	 * Returns the backing values (not the case names).
	 * Custom helper method NOT provided by PHP automatically.
	 *
	 * @return array<string> Array of all valid string values
	 */
	public static function values(): array {
		return array_column( self::cases(), 'value' );
	}

	/**
	 * Get the next logical state after a success
	 *
	 * Custom method to determine state transitions on success.
	 *
	 * @return self The state to transition to on success
	 */
	public function get_success_transition(): self
	{
		return match ( $this ) {
			self::CLOSED    => self::CLOSED, // Stay closed on success
			self::HALF_OPEN => self::CLOSED, // Close on half-open success
			self::OPEN      => self::OPEN,   // Should not receive success in OPEN
		};
	}

	/**
	 * Get the next logical state after a failure
	 *
	 * Custom method to determine state transitions on failure.
	 * Note: Actual transition depends on failure threshold.
	 *
	 * @param bool $threshold_exceeded Whether the failure threshold has been exceeded
	 * @return self                    The state to transition to on failure
	 */
	public function get_failure_transition( bool $threshold_exceeded ): self
	{
		return match ( $this ) {
			self::CLOSED     => $threshold_exceeded ? self::OPEN : self::CLOSED,
			self::HALF_OPEN  => self::OPEN,     // Any failure in half-open reopens
			self::OPEN       => self::OPEN,     // Stay open
		};
	}

	/**
	 * Check if this state should track failures
	 *
	 * OPEN state doesn't need to track failures since no requests are attempted.
	 *
	 * @return bool True if failures should be tracked in this state
	 */
	public function should_track_failures(): bool {
		return match ( $this ) {
			self::CLOSED, self::HALF_OPEN => true,
			self::OPEN                    => false,
		};
	}

	/**
	 * Get the severity level of this state for monitoring/alerting
	 *
	 * Useful for determining alert levels in monitoring systems.
	 *
	 * @return string Severity level: 'info', 'warning', or 'critical'
	 */
	public function get_severity(): string {
		return match ( $this ) {
			self::CLOSED => 'info',
			self::HALF_OPEN => 'warning',
			self::OPEN => 'critical',
		};
	}

	/**
	 * Convert to array representation for JSON serialization
	 *
	 * Useful for API responses and logging.
	 *
	 * @return array{value: string, name: string, description: string, can_attempt: bool}
	 */
	public function to_array(): array {
		return [
			'value' => $this->value,
			'name' => $this->name,
			'description' => $this->description(),
			'can_attempt' => $this->can_attempt_request(),
			'severity' => $this->get_severity(),
		];
	}

	/**
	 * Convert to JSON string
	 *
	 * @return string JSON representation
	 * @throws JsonException
	 */
	public function to_json(): string {
		return json_encode( $this->to_array(), JSON_THROW_ON_ERROR );
	}
}

<?php
declare( strict_types = 1 );
namespace Lib;

use Lib\Enum\Circuit_State;
use Lib\Exception\Circuit_Breaker_Exception;
use Lib\Interface\Circuit_Breaker_Storage_Interface;
use Throwable;

/**
 * Circuit breaker implementation for PHP 8.4
 *
 * Wraps external service calls and tracks failures to prevent cascade failures.
 * When failures exceed threshold, circuit opens and rejects requests immediately.
 */
final class Circuit_Breaker {
	private Circuit_State $current_state = Circuit_State::CLOSED;

	public function __construct(
		private readonly string $service_name,
		private readonly Circuit_Breaker_Storage_Interface $storage,
		private readonly Circuit_Breaker_Config $config = new Circuit_Breaker_Config(),
	) {
		// Load persisted state on instantiation
		$this->current_state = $this->storage->get_state( $this->service_name );
	}

	/**
	 * Check if the circuit allows a request to proceed
	 */
	public function is_available(): bool {
		$this->update_state();
		return $this->current_state->can_attempt_request();
	}

	/**
	 * Get current circuit state
	 */
	public function get_state(): Circuit_State {
		$this->update_state();
		return $this->current_state;
	}

	/**
	 * Record a successful call - resets failure count and closes circuit
	 */
	public function record_success(): void {
		if ( $this->current_state === Circuit_State::HALF_OPEN ) {
			$this->storage->increment_success_count( $this->service_name );

			// Check if enough successes to close circuit
			$successes = $this->storage->get_success_count( $this->service_name );

			if ( $successes >= $this->config->half_open_max_calls ) {
				$this->transition_to( Circuit_State::CLOSED );
				$this->storage->reset_counts( $this->service_name );
			}
		} elseif ( $this->current_state === Circuit_State::CLOSED ) {
			// Reset failure count on success during normal operation
			$this->storage->reset_counts( $this->service_name );
		}
	}

	/**
	 * Record a failed call - increments failure count, may open circuit
	 */
	public function record_failure(): void {
		if ( Circuit_State::HALF_OPEN === $this->current_state ) {
			// Any failure in half-open immediately reopens circuit
			$this->transition_to( Circuit_State::OPEN );
			$this->storage->set_opened_at(
				$this->service_name,
				time(),
				$this->config->recovery_timeout_secs + 60
			);

			return;
		}

		$this->storage->increment_failure_count(
			$this->service_name,
			$this->config->time_window_secs
		);

		$failures = $this->storage->get_failure_count( $this->service_name );

		if ( $failures >= $this->config->failure_threshold ) {
			$this->transition_to( Circuit_State::OPEN );
			$this->storage->set_opened_at(
				$this->service_name,
				time(),
				$this->config->recovery_timeout_secs + 60
			);
		}
	}

	/**
	 * Execute a callable with circuit breaker protection
	 *
	 * @param callable  $operation() The operation to execute
	 * @param ?callable $fallback () Optional fallback when circuit is open
	 *
	 * @return mixed
	 * @throws Throwable When circuit is open and no fallback provided
	 */
	public function execute( callable $operation, ?callable $fallback = null ): mixed {
		if ( ! $this->is_available() ) {
			if ( null !== $fallback ) {
				return $fallback();
			}

			throw new Circuit_Breaker_Exception(
				"Circuit breaker is open for service: $this->service_name"
			);
		}

		try {
			$result = $operation();
			$this->record_success();
			return $result;
		} catch ( Throwable $e ) {
			$this->record_failure();

			if ( null !== $fallback ) {
				return $fallback();
			}

			throw $e;
		}
	}

	/**
	 * Update state based on timeout expiration
	 */
	private function update_state(): void {
		$persisted_state = $this->storage->get_state( $this->service_name );

		if ( Circuit_State::OPEN === $persisted_state ) {
			$opened_at = $this->storage->get_opened_at( $this->service_name );

			if ( null !== $opened_at ) {
				$elapsed = time() - $opened_at;

				if ( $this->config->recovery_timeout_secs <= $elapsed ) {
					// Timeout elapsed, transition to half-open
					$this->transition_to( Circuit_State::HALF_OPEN );
					$this->storage->reset_counts( $this->service_name );
					return;
				}
			}
		}

		$this->current_state = $persisted_state;
	}

	/**
	 * Transition to a new state
	 */
	private function transition_to( Circuit_State $new_state ): void {
		$old_state = $this->current_state;
		$this->current_state = $new_state;

		// Persist with appropriate TTL
		$ttl = match ( $new_state ) {
			Circuit_State::OPEN      => $this->config->recovery_timeout_secs + 60,
			Circuit_State::HALF_OPEN => 300,    // 5 minutes max in half-open
			Circuit_State::CLOSED    => 3600,   // Clean up after 1 hour of success
		};

		$this->storage->set_state( $this->service_name, $new_state, $ttl );

		// Log state transitions for monitoring
		error_log( sprintf(
			'[Circuit_Breaker] %s: %s -> %s',
			$this->service_name,
			$old_state->value,
			$new_state->value
		) );
	}
}

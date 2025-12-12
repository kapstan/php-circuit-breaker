<?php
declare( strict_types = 1 );
namespace Lib;

use Memcached;
use Lib\Enum\Circuit_State;
use Lib\Interface\Circuit_Breaker_Storage_Interface;
use RuntimeException;

class Memcached_Storage implements Circuit_Breaker_Storage_Interface {
	/**
	 * Key prefix to namespace circuit breaker data and prevent collisions
	 * with other application data stored in Memcached.
	 *
	 * @const string
	 */
	private const string PREFIX = 'circuit_breaker';

	/**
	 * Memcached connection instance
	 *
	 * This is maintained as a class property so we can reuse connections
	 * across multiple storage operations. Creating a new connection for
	 * every operation would be wasteful and slow.
	 *
	 * @var Memcached
	 */
	private Memcached $memcached;

	/**
	 * Whether we successfully connected to Memcached
	 *
	 * This flag lets us fail gracefully if Memcached is unavailable.
	 * The circuit breaker will still work, but it won't be able to
	 * share state across servers.
	 *
	 * @var bool
	 */
	private bool $connected = false;

	/**
	 * Constructor - Establishes connection to Memcached cluster
	 *
	 * The constructor sets up the Memcached connection with sensible defaults
	 * for circuit breaker usage. We use persistent connections to avoid the
	 * overhead of establishing new TCP connections on every request.
	 *
	 * @param string $host Memcached server hostname or IP address
	 * @param int $port Memcached server port (standard is 11211)
	 * @param string $persistent_id Optional persistent connection ID for connection pooling
	 *
	 * @throws RuntimeException if Memcached extension is not installed
	 */
	public function __construct(
		private readonly string $host          = '127.0.0.1',
		private readonly int $port             = 11211,
		private readonly string $persistent_id = 'circuit_breaker_pool'
	) {
		// Verify that the Memcached extension is installed
		if ( ! extension_loaded('memcached' ) ) {
			throw new RuntimeException( 'Memcached extension is not installed. Install it with: pecl install memcached' );
		}

		$this->initialize_connection();
	}

	/**
	 * Initialize the Memcached connection with optimized settings
	 *
	 * This method configures the Memcached client with settings appropriate
	 * for circuit breaker usage. We prioritize consistency and quick failure
	 * detection over absolute performance.
	 *
	 * @return void
	 */
	private function initialize_connection(): void {
		// Use persistent connections to reuse TCP connections across requests.
		// The persistent_id groups connections into pools - all instances with
		// the same ID share connections. This dramatically reduces overhead.
		$this->memcached = new Memcached( $this->persistent_id );

		// Check if we've already added servers to this persistent connection.
		// Memcached maintains a server list internally, and adding servers
		// multiple times causes warnings and connection issues.
		$servers = $this->memcached->getServerList();

		if ( empty( $servers ) ) {
			// First time using this persistent connection - add our server
			$success = $this->memcached->addServer( $this->host, $this->port );

			if ( ! $success ) {
				error_log(
					"[Circuit_Breaker] Failed to add Memcached server $this->host:$this->port"
				);

				return;
			}

			// Configure Memcached options for circuit breaker usage
			$this->configure_memcached_options();
		}

		// Verify we can actually connect by attempting a simple operation
		// We use a short timeout to fail fast if Memcached is down
		$this->memcached->set( '_circuit_breaker_health_check', 1, 10 );
		$result = $this->memcached->get( '_circuit_breaker_health_check' );

		if ( $result === false && Memcached::RES_SUCCESS !== $this->memcached->getResultCode() ) {
			$this->connected = false;

			error_log(
				"[Circuit Breaker] Cannot connect to Memcached at $this->host:$this->port: " .
				$this->memcached->getResultMessage()
			);
			return;
		}

		$this->connected = true;
	}

	/**
	 * Configure Memcached client options for circuit breaker usage
	 *
	 * These settings balance reliability with performance. Circuit breakers
	 * require consistent state, so we prioritize data accuracy over raw speed.
	 *
	 * @return void
	 */
	private function configure_memcached_options(): void {
		$this->memcached->setOptions( [
			// Binary protocol is more efficient and supports additional features
			// like CAS (Compare-And-Swap/Check-and-Set) operations for atomic updates
			Memcached::OPT_BINARY_PROTOCOL      => true,

			// Compression isn't needed for circuit breaker data since our values
			// are small (mostly integers and timestamps). Disabling it reduces CPU overhead.
			Memcached::OPT_COMPRESSION          => false,

			// Use consistent hashing for server distribution. If we later add more
			// Memcached servers to the cluster, this minimizes key redistribution.
			Memcached::OPT_DISTRIBUTION         => Memcached::DISTRIBUTION_CONSISTENT,
			Memcached::OPT_LIBKETAMA_COMPATIBLE => true,

			// Set aggressive timeouts - we'd rather fail fast than block the application.
			// Circuit breakers need to be lightweight; spending seconds waiting for
			// Memcached defeats the purpose of having a circuit breaker at all.
			Memcached::OPT_CONNECT_TIMEOUT      => 100,  // 100ms connection timeout
			Memcached::OPT_SEND_TIMEOUT         => 100,  // 100ms send timeout
			Memcached::OPT_RECV_TIMEOUT         => 100,  // 100ms receive timeout
			Memcached::OPT_POLL_TIMEOUT         => 100,  // 100ms poll timeout

			// Retry failed operations once. If Memcached is temporarily overwhelmed,
			// a single retry often succeeds. More retries risk cascading failures.
			Memcached::OPT_RETRY_TIMEOUT        => 1,

			// Enable TCP_NODELAY (disable Nagle's algorithm) for lower latency.
			// Circuit breaker operations are small and need to complete quickly,
			// so we want data sent immediately rather than buffered.
			Memcached::OPT_TCP_NODELAY          => true,
		] );
	}

	/**
	 * Generate a Memcached-compatible key with prefix
	 *
	 * Memcached has strict key requirements:
	 * - Maximum 250 characters
	 * - No whitespace or control characters (ASCII < 32 or > 126)
	 * - Case-sensitive
	 *
	 * This method ensures keys meet these requirements while remaining readable.
	 *
	 * @param string $service_name The service identifier
	 * @param string $suffix       The key type (state, failures, etc.)
	 * @return string              A valid Memcached key
	 */
	private function get_key( string $service_name, string $suffix ): string {
		// Sanitize service name to remove invalid characters
		// We replace whitespace and special chars with underscores
		$sanitized = preg_replace( '/[^a-zA-Z0-9._-]/', '_', $service_name );

		// Truncate if needed to stay under 250 char limit (leaving room for prefix and suffix)
		$maxServiceNameLength = 250 - strlen( self::PREFIX ) - strlen( $suffix ) - 2;
		if ( strlen( $sanitized ) > $maxServiceNameLength ) {
			$sanitized = substr( $sanitized, 0, $maxServiceNameLength );
		}

		return self::PREFIX . $sanitized . ':' . $suffix;
	}

	/**
	 * Get the current circuit state
	 *
	 * @param string $service_name  The service identifier.
	 *
	 * @return Circuit_State        The current state, defaults to CLOSED if not set
	 */
	public function get_state( string $service_name ): Circuit_State {
		if ( ! $this->connected ) {
			// If Memcached is unavailable, default to CLOSED state
			// This ensures the circuit breaker doesn't block all traffic
			// when the cache itself is down (graceful degradation)
			return Circuit_State::CLOSED;
		}

		$key = $this->get_key( $service_name, 'state' );
		$value = $this->memcached->get( $key );

		// Memcached returns false on cache miss or error
		if ( $value === false ) {
			// Check if this was an actual error or just a cache miss
			$resultCode = $this->memcached->getResultCode();

			if ( $resultCode !== Memcached::RES_NOTFOUND && $resultCode !== Memcached::RES_SUCCESS ) {
				error_log(
					"[Circuit_Breaker] Memcached error getting state for $service_name: " .
					$this->memcached->getResultMessage()
				);
			}

			return Circuit_State::CLOSED;
		}

		// Validate that the stored value is a valid state
		if ( ! is_string( $value ) ) {
			return Circuit_State::CLOSED;
		}

		return Circuit_State::tryFrom( $value ) ?? Circuit_State::CLOSED;
	}

	/**
	 * Set the circuit state
	 *
	 * @param string        $service_name
	 * @param Circuit_State $state The new state to set
	 * @param int           $ttl_seconds
	 *
	 * @return void
	 */
	public function set_state( string $service_name, Circuit_State $state, int $ttl_seconds ): void {
		if ( ! $this->connected ) {
			return;
		}

		$key = $this->get_key( $service_name, 'state' );

		// Store the state's string value for easy serialization
		$success = $this->memcached->set( $key, $state->value, $ttl_seconds );

		if (!$success) {
			error_log(
				"[Circuit_Breaker] Failed to set state for $service_name: " .
				$this->memcached->getResultMessage()
			);
		}
	}

	/**
	 * Get the current failure count
	 *
	 * @param string $service_name The service identifier
	 * @return int                 Number of failures, 0 if not set
	 */
	public function get_failure_count( string $service_name ): int {
		if ( ! $this->connected ) {
			return 0;
		}

		$key = $this->get_key( $service_name, 'failures' );
		$value = $this->memcached->get( $key );

		if ( false === $value ) {
			return 0;
		}

		return (int) $value;
	}

	/**
	 * Get the current failure count
	 *
	 * Failure counts are used during the open state to determine if
	 * we can transition to half-closed, or a closed state.
	 *
	 * @param string $service_name
	 * @param int    $window_seconds
	 *
	 * @return void
	 */
	public function increment_failure_count( string $service_name, int $window_seconds ): void {
		if ( ! $this->connected ) {
			return;
		}

		$key = $this->get_key( $service_name, 'failures' );

		// Attempt to increment the counter atomically
		// If the key doesn't exist, this returns false
		$result = $this->memcached->increment( $key );

		if ( false === $result ) {
			$added = $this->memcached->add( $key, 1, $window_seconds );

			if ( ! $added ) {
				$result_code = $this->memcached->getResultCode();

				if ( Memcached::RES_NOTSTORED === $result_code ) {
					// Another server already initialized it - try incrementing again
					$this->memcached->increment( $key );
				} else {
					error_log(
						"[Circuit_Breaker] Failed to initialize failure count for $service_name: " .
						$this->memcached->getResultMessage()
					);
				}
			}
		}
	}

	/**
	 * Get the current success count
	 *
	 * Success counts are used during the half-open state to determine
	 * when the circuit can close again.
	 *
	 * @param string $service_name The service identifier
	 *
	 * @return int                 Number of successes, 0 if not set
	 */
	public function get_success_count( string $service_name ): int {
		if ( ! $this->connected ) {
			return 0;
		}

		$key = $this->get_key( $service_name, 'successes' );
		$value = $this->memcached->get( $key );

		if ( false === $value ) {
			return 0;
		}

		return (int) $value;
	}

	/**
	 * Increment the success count atomically
	 *
	 * @param string $service_name The service identifier
	 * @return void
	 */
	public function increment_success_count( string $service_name ): void {
		if ( ! $this->connected ) {
			return;
		}

		$key = $this->get_key( $service_name, 'successes' );

		// Try to increment, initialize if it doesn't exist
		$result = $this->memcached->increment( $key );

		if ( false === $result ) {
			// Initialize the counter with a 5-minute TTL
			// Success counts are temporary and only relevant during recovery
			$added = $this->memcached->add( $key, 1, 300 );

			if ( ! $added && Memcached::RES_NOTSTORED === $this->memcached->getResultCode() ) {
				// Race condition - another server initialized it
				$this->memcached->increment( $key );
			}
		}
	}

	/**
	 * Reset all counters to zero
	 *
	 * This is called when the circuit closes or when we want to clear
	 * accumulated state.
	 *
	 * @param string $service_name The service identifier
	 * @return void
	 */
	public function reset_counts( string $service_name ): void {
		if ( ! $this->connected ) {
			return;
		}

		$failureKey = $this->get_key( $service_name, 'failures' );
		$successKey = $this->get_key( $service_name, 'successes' );

		// Delete both counters
		// We don't check return values because delete() returns false both
		// for errors and for "key doesn't exist", and either case is fine
		$this->memcached->delete( $failureKey );
		$this->memcached->delete( $successKey );
	}

	/**
	 * Get the timestamp when the circuit was opened
	 *
	 * This is used to determine when enough time has passed to transition
	 * from OPEN to HALF_OPEN state.
	 *
	 * @param string $service_name The service identifier
	 * @return int|null            Unix timestamp, or null if not set
	 */
	public function get_opened_at( string $service_name ): ?int {
		if ( ! $this->connected ) {
			return null;
		}

		$key = $this->get_key( $service_name, 'opened_at' );
		$value = $this->memcached->get( $key );

		if ( false === $value ) {
			return null;
		}

		return (int) $value;
	}

	/**
	 * Set the timestamp when the circuit was opened
	 *
	 * @param string $service_name The service identifier
	 * @param int    $timestamp
	 * @param int    $ttl_seconds  How long to store this value
	 *
	 * @return void
	 */
	public function set_opened_at( string $service_name, int $timestamp, int $ttl_seconds ): void {
		if ( ! $this->connected ) {
			return;
		}

		$key = $this->get_key( $service_name, 'opened_at' );

		$success = $this->memcached->set( $key, $timestamp, $ttl_seconds );

		if (!$success) {
			error_log(
				"[Circuit_Breaker] Failed to set opened_at for $service_name: " .
				$this->memcached->getResultMessage()
			);
		}
	}

	/**
	 * Get connection status
	 *
	 * Useful for health checks and monitoring to verify Memcached is accessible.
	 *
	 * @return bool True if connected to Memcached
	 */
	public function is_connected(): bool {
		return $this->connected;
	}

	/**
	 * Get Memcached server statistics
	 *
	 * Returns statistics from the Memcached server, useful for monitoring
	 * and debugging cache performance issues.
	 *
	 * @return array Server statistics, or empty array if not connected
	 */
	public function get_stats(): array {
		if ( ! $this->connected ) {
			return [];
		}

		$stats = $this->memcached->getStats();
		return $stats ?: [];
	}

	/**
	 * Close the Memcached connection
	 *
	 * This is called automatically when the object is destroyed, but you can
	 * call it explicitly if you want to close connections earlier for resource
	 * management.
	 *
	 * Note that with persistent connections, this doesn't actually close the
	 * TCP connection - it just releases our reference to it. The connection
	 * pool maintains the actual socket.
	 *
	 * @return void
	 */
	public function close(): void {
		if ( $this->connected ) {
			// Quit is a graceful shutdown that flushes pending operations
			$this->memcached->quit();
			$this->connected = false;
		}
	}
}

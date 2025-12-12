<?php
declare( strict_types = 1 );
namespace Lib;

readonly class Circuit_Breaker_Config {
	public function __construct(
		public int $failure_threshold     = 5,  // Failures before opening
		public int $recovery_timeout_secs = 30, // Cooldown in OPEN state
		public int $half_open_max_calls   = 3,  // Test calls in HALF_OPEN state
		public int $time_window_secs      = 60, // Sliding window for failures
	) {}
}

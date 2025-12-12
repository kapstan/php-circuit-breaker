<?php
declare( strict_types = 1 );
namespace Lib\Interface;

use Lib\Enum\Circuit_State;

interface Circuit_Breaker_Storage_Interface {
	public function get_state( string $serviceName ): Circuit_State;
	public function set_state( string $serviceName, Circuit_State $state, int $ttlSeconds ): void;
	public function get_failure_count( string $serviceName ): int;
	public function increment_failure_count( string $serviceName, int $windowSeconds ): void;
	public function get_success_count( string $serviceName ): int;
	public function increment_success_count( string $serviceName ): void;
	public function reset_counts( string $serviceName ): void;
	public function get_opened_at( string $serviceName ): ?int;
	public function set_opened_at( string $serviceName, int $timestamp, int $ttlSeconds ): void;
}

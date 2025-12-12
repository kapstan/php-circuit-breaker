<?php
declare( strict_types = 1 );
namespace Lib\Exception;

use RuntimeException;

/**
 * Exception thrown when circuit is open and no fallback is available
 */
class Circuit_Breaker_Exception extends RuntimeException {}

<?php
declare( strict_types = 1 );

/**
 * Autoloader for Circuit Breaker Classes
 */
spl_autoload_register( function ( string $class ): void {
	// Define search directories
	$prefixes = [
		'Lib\\' => __DIR__ . '/Lib/',
		'Src\\' => __DIR__ . '/Src/',
	];

	foreach( $prefixes as $prefix => $base_dir ) {
		$len = strlen( $prefix );

		if ( 0 !== strncmp( $class, $prefix, $len ) ) {
			continue;
		}

		$relative_class = substr( $class, $len );
		$file           = $base_dir . str_replace( '\\', '/', $relative_class ) . '.php';

		if ( file_exists( $file ) ) {
			echo sprintf( 'Loaded file "%s"', $file ) . PHP_EOL;
			require $file;
		} else {
			echo sprintf( 'Failed to find file named: %s', $file ) . PHP_EOL;
		}
	}
} );

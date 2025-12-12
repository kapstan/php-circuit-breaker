<?php
declare( strict_types = 1 );

require_once __DIR__ . '/autoload.php';

use Lib\Circuit_Breaker_Config;
use Lib\Memcached_Storage;
use Lib\Circuit_Breaker;
use Src\Circuit_Breaker_Client;

// Most APIs return plain JSON without HATEOAS
$storage = new Memcached_Storage();
$config  = new Circuit_Breaker_Config();
$circuit = new Circuit_Breaker( 'github-api', $storage, $config );
$client  = new Circuit_Breaker_Client( $circuit );

// Fetch user data from GitHub API (plain JSON response)
$result = $client->get(
	'https://api.github.com/users/torvalds',
	[ 'Accept' => 'application/vnd.github.v3+json' ]
);

if ( $result->success ) {
	$response = $result->response;

	// Plain JSON access - no HATEOAS metadata
	echo "User: {$response->data[ 'login' ]}\n";
	echo "Name: {$response->data[ 'name' ]}\n";
	echo "Repos: {$response->data[ 'public_repos' ]}\n";

	// has_hateoas will be false
	echo "Is HATEOAS: " . ( $response->has_hateoas ? 'yes' : 'no' ) . "\n";
	// Output: Is HATEOAS: no

} else {
	echo "Error: $result->error\n";
}

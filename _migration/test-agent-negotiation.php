<?php
/** Read-only Accept negotiation unit harness. Run through wp eval-file. */

if ( ! defined( 'ABSPATH' ) ) {
	exit( "Run via wp eval-file\n" );
}

$cases = [
	''                                           => 'html',
	'*/*'                                        => 'html',
	'text/html'                                  => 'html',
	'text/plain'                                 => 'html',
	'text/markdown'                              => 'markdown',
	'text/markdown, text/html;q=0.8'             => 'markdown',
	'text/html, text/markdown;q=0.5'             => 'html',
	'text/markdown;q=0, */*;q=1'                 => 'html',
	'text/markdown;q=0.7, text/html;q=0.7'       => 'markdown',
	'application/json'                           => null,
];

foreach ( $cases as $accept => $expected ) {
	$actual = GLC_AI::preferred_representation( $accept );
	if ( $actual !== $expected ) {
		throw new RuntimeException( sprintf( 'Accept %s: expected %s, got %s', $accept ?: '(empty)', var_export( $expected, true ), var_export( $actual, true ) ) );
	}
	WP_CLI::log( sprintf( '  ✓ %s → %s', $accept ?: '(empty)', var_export( $actual, true ) ) );
}

WP_CLI::success( 'Accept negotiation cases passed.' );

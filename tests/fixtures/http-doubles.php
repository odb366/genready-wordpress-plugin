<?php
namespace GenReady\Tests\Unit;

use Brain\Monkey;

/**
 * A scripted GenReady server for the real Client: each wp_remote_request() call
 * takes the next answer from the list and is logged with its URL and arguments.
 */
trait ScriptedHttp {
	/** @var array[] array( url, args ) of each request, in order. */
	private $http_log = array();

	/**
	 * Answer the next requests in order.
	 *
	 * @param array[] $answers array( status, body array ) for each request.
	 */
	private function scripted_http( array $answers ): void {
		$this->http_log = array();
		$log            = &$this->http_log;
		Monkey\Functions\when( 'is_wp_error' )->alias( function ( $v ) { return $v instanceof \WP_Error; } );
		Monkey\Functions\when( 'wp_remote_retrieve_response_code' )->alias( function ( $r ) { return $r['response']['code']; } );
		Monkey\Functions\when( 'wp_remote_retrieve_body' )->alias( function ( $r ) { return $r['body']; } );
		Monkey\Functions\when( 'wp_remote_retrieve_header' )->justReturn( '' );
		Monkey\Functions\when( 'get_bloginfo' )->justReturn( '6.8' );
		Monkey\Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Monkey\Functions\when( 'wp_remote_request' )->alias(
			function ( $url, $args ) use ( &$answers, &$log ) {
				$log[]  = array( $url, $args );
				$answer = array_shift( $answers );
				if ( null === $answer ) {
					throw new \RuntimeException( 'No scripted answer for ' . $url );
				}
				return array(
					'response' => array( 'code' => $answer[0] ),
					'body'     => json_encode( $answer[1] ),
					'headers'  => array(),
				);
			}
		);
	}

	/** The Authorization header of a logged request. */
	private function bearer( int $index ): string {
		return (string) ( $this->http_log[ $index ][1]['headers']['Authorization'] ?? '' );
	}
}

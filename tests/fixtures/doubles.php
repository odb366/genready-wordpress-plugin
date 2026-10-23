<?php
namespace GenReady\Tests\Unit;

use GenReady\Api\Client;
use GenReady\Connection\TokenStore;

/** Token store whose refresh lock is scripted. */
class LockableStore extends TokenStore {
	/** @var bool */
	private $grant;
	/** @var bool */
	public $released = false;

	public function __construct( bool $grant ) {
		$this->grant = $grant;
	}

	public function acquire_lock( int $wait_seconds = 5 ): bool {
		return $this->grant;
	}

	public function release_lock(): void {
		$this->released = true;
	}
}

/** Client with scripted answers; records every call. */
class FakeClient extends Client {
	/** @var mixed */
	public $form_result = null;
	/** @var mixed */
	public $request_result = array();
	/** @var array */
	public $forms = array();
	/** @var array */
	public $requests = array();
	/** @var callable|null Runs inside request(), before the answer, to simulate a racing request. */
	public $on_request = null;
	/** @var mixed Answer of call_public(): an array, an ApiError, or a Closure( $method, $path ). */
	public $public_result = array();
	/** @var array array( method, path, body, bearer, timeout ) of each call_public(). */
	public $publics = array();
	/** @var callable|null Runs inside call_public(), before the answer. */
	public $on_public = null;

	public function __construct() {
	}

	public function post_form( string $path, array $form, int $timeout = 15 ) {
		$this->forms[] = array( $path, $form );
		return $this->form_result;
	}

	public function request( string $method, string $path, ?array $body = null, int $timeout = 15 ) {
		$this->requests[] = array( $method, $path, $body );
		if ( null !== $this->on_request ) {
			call_user_func( $this->on_request );
		}
		return $this->request_result;
	}

	public function call_public( string $method, string $path, ?array $body = null, ?string $bearer = null, int $timeout = 15 ) {
		$this->publics[] = array( $method, $path, $body, $bearer, $timeout );
		if ( null !== $this->on_public ) {
			call_user_func( $this->on_public );
		}
		return $this->public_result instanceof \Closure ? call_user_func( $this->public_result, $method, $path ) : $this->public_result;
	}
}

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

	public function __construct() {
	}

	public function post_form( string $path, array $form, int $timeout = 15 ) {
		$this->forms[] = array( $path, $form );
		return $this->form_result;
	}

	public function request( string $method, string $path, ?array $body = null, int $timeout = 15 ) {
		$this->requests[] = array( $method, $path, $body );
		return $this->request_result;
	}
}

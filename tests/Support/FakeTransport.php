<?php

namespace WpPcloudBackup\Tests\Support;

use WpPcloudBackup\Storage\HttpTransportInterface;

/**
 * Scripted HTTP transport for tests.
 */
final class FakeTransport implements HttpTransportInterface {

	/**
	 * Recorded requests: method, url, options.
	 *
	 * @var array<int, array{method:string,url:string,options:array}>
	 */
	public $requests = array();

	/**
	 * Queued responses (arrays or exceptions).
	 *
	 * @var array
	 */
	private $responses = array();

	/**
	 * Queue a raw response: ['status' => int, 'body' => string].
	 */
	public function queue( array $response ): void {
		$this->responses[] = $response;
	}

	/**
	 * Queue a JSON-encoded response body.
	 */
	public function queueJson( array $data, int $status = 200 ): void {
		$this->queue(
			array(
				'status' => $status,
				'body'   => json_encode( $data ),
			)
		);
	}

	/**
	 * Queue an exception to be thrown by the next request.
	 */
	public function queueException( \Throwable $exception ): void {
		$this->responses[] = $exception;
	}

	public function request( string $method, string $url, array $options = array() ): array {
		$this->requests[] = array(
			'method'  => $method,
			'url'     => $url,
			'options' => $options,
		);

		if ( array() === $this->responses ) {
			throw new \LogicException( 'FakeTransport queue is empty.' );
		}

		$response = array_shift( $this->responses );
		if ( $response instanceof \Throwable ) {
			throw $response;
		}

		return $response;
	}
}

<?php

namespace WpPcloudBackup\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WpPcloudBackup\Storage\PcloudClient;
use WpPcloudBackup\Storage\StorageException;
use WpPcloudBackup\Tests\Support\FakeTransport;
use WpPcloudBackup\Tests\Support\TempDir;

final class PcloudClientTest extends TestCase {

	/**
	 * @var FakeTransport
	 */
	private $http;

	/**
	 * @var PcloudClient
	 */
	private $client;

	protected function setUp(): void {
		parent::setUp();
		$this->http   = new FakeTransport();
		$this->client = new PcloudClient( $this->http, 'secret-token' );
	}

	public function test_bearer_header_and_host(): void {
		$this->http->queueJson(
			array(
				'result'    => 0,
				'email'     => 'me@example.com',
				'quota'     => 10737418240,
				'usedquota' => 1024,
			)
		);

		$info = $this->client->userInfo();

		$this->assertSame(
			array(
				'email'     => 'me@example.com',
				'quota'     => 10737418240,
				'usedquota' => 1024,
			),
			$info
		);
		$request = $this->http->requests[0];
		$this->assertSame( 'GET', $request['method'] );
		$this->assertSame( 'https://api.pcloud.com/userinfo', $request['url'] );
		$this->assertSame( 'Bearer secret-token', $request['options']['headers']['Authorization'] );
	}

	public function test_custom_host_is_used(): void {
		$client = new PcloudClient( $this->http, 'tok', 'eapi.pcloud.com' );
		$this->http->queueJson( array( 'result' => 0, 'email' => 'a', 'quota' => 1, 'usedquota' => 0 ) );

		$client->userInfo();

		$this->assertSame( 'https://eapi.pcloud.com/userinfo', $this->http->requests[0]['url'] );
	}

	public function test_ensure_folder_creates_each_level(): void {
		$this->http->queueJson( array( 'result' => 0, 'metadata' => array( 'folderid' => 11 ) ) );
		$this->http->queueJson( array( 'result' => 0, 'metadata' => array( 'folderid' => 22 ) ) );

		$id = $this->client->ensureFolder( '/WPBackup/example.com' );

		$this->assertSame( 22, $id );
		$this->assertCount( 2, $this->http->requests );
		$this->assertSame( 'https://api.pcloud.com/createfolderifnotexists', $this->http->requests[0]['url'] );
		$this->assertSame(
			array(
				'folderid' => 0,
				'name'     => 'WPBackup',
			),
			$this->http->requests[0]['options']['query']
		);
		$this->assertSame(
			array(
				'folderid' => 11,
				'name'     => 'example.com',
			),
			$this->http->requests[1]['options']['query']
		);
	}

	public function test_ensure_folder_root_makes_no_request(): void {
		$this->assertSame( 0, $this->client->ensureFolder( '/' ) );
		$this->assertSame( array(), $this->http->requests );
	}

	public function test_list_folder_maps_contents(): void {
		$this->http->queueJson(
			array(
				'result'   => 0,
				'metadata' => array(
					'contents' => array(
						array(
							'name'     => '2026-10-24',
							'isfolder' => true,
							'folderid' => 5,
							'modified' => 'Thu, 24 Oct 2026 03:00:00 +0000',
						),
						array(
							'name'     => 'a.zip',
							'isfolder' => false,
							'fileid'   => 99,
							'size'     => 1234,
							'modified' => 'Thu, 24 Oct 2026 03:00:00 +0000',
						),
					),
				),
			)
		);

		$list = $this->client->listFolder( 7 );

		$this->assertSame( 'https://api.pcloud.com/listfolder', $this->http->requests[0]['url'] );
		$this->assertSame( array( 'folderid' => 7 ), $this->http->requests[0]['options']['query'] );
		$ts = strtotime( 'Thu, 24 Oct 2026 03:00:00 +0000' );
		$this->assertSame(
			array(
				array(
					'id'       => 5,
					'name'     => '2026-10-24',
					'isfolder' => true,
					'size'     => 0,
					'modified' => $ts,
				),
				array(
					'id'       => 99,
					'name'     => 'a.zip',
					'isfolder' => false,
					'size'     => 1234,
					'modified' => $ts,
				),
			),
			$list
		);
	}

	public function test_upload_sends_multipart_and_verifies_checksum(): void {
		$dir  = TempDir::create();
		$path = $dir . '/part.zip';
		file_put_contents( $path, 'zipdata' );

		$this->http->queueJson( array( 'result' => 0, 'metadata' => array( array( 'fileid' => 77, 'size' => 7 ) ) ) );
		$this->http->queueJson( array( 'result' => 0, 'sha1' => 'abc123', 'md5' => 'def', 'metadata' => array( 'size' => 7 ) ) );

		try {
			$result = $this->client->uploadFile( 5, $path, 'part-001.zip' );
		} finally {
			TempDir::remove( $dir );
		}

		$this->assertSame(
			array(
				'id'   => 77,
				'size' => 7,
				'sha1' => 'abc123',
			),
			$result
		);
		$upload = $this->http->requests[0];
		$this->assertSame( 'POST', $upload['method'] );
		$this->assertSame( 'https://api.pcloud.com/uploadfile', $upload['url'] );
		$this->assertSame(
			array(
				'folderid' => 5,
				'filename' => 'part-001.zip',
				'nopartial' => 1,
			),
			$upload['options']['form']
		);
		$this->assertSame(
			array(
				'field' => 'file',
				'path'  => $path,
				'name'  => 'part-001.zip',
			),
			$upload['options']['upload']
		);
		$this->assertSame( 'Bearer secret-token', $upload['options']['headers']['Authorization'] );

		$checksum = $this->http->requests[1];
		$this->assertSame( 'GET', $checksum['method'] );
		$this->assertSame( 'https://api.pcloud.com/checksumfile', $checksum['url'] );
		$this->assertSame( array( 'fileid' => 77 ), $checksum['options']['query'] );
	}

	public function test_upload_missing_file_throws_without_request(): void {
		try {
			$this->client->uploadFile( 5, '/nonexistent/file.zip', 'x.zip' );
			$this->fail( 'Expected StorageException' );
		} catch ( StorageException $e ) {
			$this->assertFalse( $e->isRetryable() );
			$this->assertSame( 'other', $e->category() );
		}
		$this->assertSame( array(), $this->http->requests );
	}

	public function test_checksum_returns_hashes_and_size(): void {
		$this->http->queueJson( array( 'result' => 0, 'sha1' => 's', 'md5' => 'm', 'metadata' => array( 'size' => 42 ) ) );

		$this->assertSame(
			array(
				'sha1' => 's',
				'md5'  => 'm',
				'size' => 42,
			),
			$this->client->checksum( 3 )
		);
	}

	public function test_rename_and_delete_calls(): void {
		$this->http->queueJson( array( 'result' => 0 ) );
		$this->http->queueJson( array( 'result' => 0 ) );
		$this->http->queueJson( array( 'result' => 0 ) );

		$this->client->renameFolder( 4, 'new-name' );
		$this->client->deleteFolder( 4 );
		$this->client->deleteFile( 8 );

		$this->assertSame( 'https://api.pcloud.com/renamefolder', $this->http->requests[0]['url'] );
		$this->assertSame( array( 'folderid' => 4, 'toname' => 'new-name' ), $this->http->requests[0]['options']['query'] );
		$this->assertSame( 'https://api.pcloud.com/deletefolderrecursive', $this->http->requests[1]['url'] );
		$this->assertSame( array( 'folderid' => 4 ), $this->http->requests[1]['options']['query'] );
		$this->assertSame( 'https://api.pcloud.com/deletefile', $this->http->requests[2]['url'] );
		$this->assertSame( array( 'fileid' => 8 ), $this->http->requests[2]['options']['query'] );
	}

	public function test_result_2008_is_quota_not_retryable(): void {
		$this->http->queueJson( array( 'result' => 2008, 'error' => 'User is over quota.' ) );

		try {
			$this->client->userInfo();
			$this->fail( 'Expected StorageException' );
		} catch ( StorageException $e ) {
			$this->assertSame( 'quota', $e->category() );
			$this->assertFalse( $e->isRetryable() );
			$this->assertSame( 2008, $e->getCode() );
			$this->assertSame( 'User is over quota.', $e->getMessage() );
		}
	}

	/**
	 * @dataProvider authCodes
	 */
	public function test_auth_results_are_not_retryable( int $code ): void {
		$this->http->queueJson( array( 'result' => $code, 'error' => 'Invalid token' ) );

		try {
			$this->client->userInfo();
			$this->fail( 'Expected StorageException' );
		} catch ( StorageException $e ) {
			$this->assertSame( 'auth', $e->category() );
			$this->assertFalse( $e->isRetryable() );
			$this->assertStringNotContainsString( 'secret-token', $e->getMessage() );
		}
	}

	public function authCodes(): array {
		return array(
			'1000' => array( 1000 ),
			'2000' => array( 2000 ),
			'2094' => array( 2094 ),
			'2095' => array( 2095 ),
		);
	}

	public function test_result_2000_is_auth_not_retryable(): void {
		$this->http->queueJson( array( 'result' => 2000, 'error' => 'Log in required.' ) );

		try {
			$this->client->userInfo();
			$this->fail( 'Expected StorageException' );
		} catch ( StorageException $e ) {
			$this->assertSame( 'auth', $e->category() );
			$this->assertFalse( $e->isRetryable() );
		}
	}

	/**
	 * @dataProvider serverCodes
	 */
	public function test_server_results_are_retryable( int $code ): void {
		$this->http->queueJson( array( 'result' => $code, 'error' => 'Internal error' ) );

		try {
			$this->client->userInfo();
			$this->fail( 'Expected StorageException' );
		} catch ( StorageException $e ) {
			$this->assertSame( 'server', $e->category() );
			$this->assertTrue( $e->isRetryable() );
		}
	}

	public function serverCodes(): array {
		return array(
			'4000' => array( 4000 ),
			'5000' => array( 5000 ),
			'5001' => array( 5001 ),
		);
	}

	public function test_other_result_is_not_retryable(): void {
		$this->http->queueJson( array( 'result' => 2005, 'error' => 'Directory does not exist.' ) );

		try {
			$this->client->listFolder( 1 );
			$this->fail( 'Expected StorageException' );
		} catch ( StorageException $e ) {
			$this->assertSame( 'other', $e->category() );
			$this->assertFalse( $e->isRetryable() );
			$this->assertSame( 2005, $e->getCode() );
		}
	}

	public function test_http_500_and_network_are_retryable(): void {
		$this->http->queue( array( 'status' => 502, 'body' => 'Bad Gateway' ) );
		try {
			$this->client->userInfo();
			$this->fail( 'Expected StorageException' );
		} catch ( StorageException $e ) {
			$this->assertSame( 'server', $e->category() );
			$this->assertTrue( $e->isRetryable() );
		}

		$this->http->queueException( StorageException::network( 'Connection timed out' ) );
		try {
			$this->client->userInfo();
			$this->fail( 'Expected StorageException' );
		} catch ( StorageException $e ) {
			$this->assertSame( 'network', $e->category() );
			$this->assertTrue( $e->isRetryable() );
		}
	}

	public function test_invalid_json_is_retryable_server_error(): void {
		$this->http->queue( array( 'status' => 200, 'body' => '<html>oops' ) );

		try {
			$this->client->userInfo();
			$this->fail( 'Expected StorageException' );
		} catch ( StorageException $e ) {
			$this->assertSame( 'server', $e->category() );
			$this->assertTrue( $e->isRetryable() );
		}
	}

	public function test_exchange_code_returns_token(): void {
		$this->http->queueJson( array( 'result' => 0, 'access_token' => 'new-token', 'token_type' => 'bearer' ) );

		$token = PcloudClient::exchangeCode( $this->http, 'cid', 'csecret', 'thecode', 'eapi.pcloud.com' );

		$this->assertSame( 'new-token', $token );
		$request = $this->http->requests[0];
		$this->assertSame( 'GET', $request['method'] );
		$this->assertSame( 'https://eapi.pcloud.com/oauth2_token', $request['url'] );
		$this->assertSame(
			array(
				'client_id'     => 'cid',
				'client_secret' => 'csecret',
				'code'          => 'thecode',
			),
			$request['options']['query']
		);
		$this->assertArrayNotHasKey( 'Authorization', $request['options']['headers'] ?? array() );
	}

	public function test_exchange_code_error_does_not_leak_secret(): void {
		$this->http->queueJson( array( 'result' => 2012, 'error' => 'Invalid code.' ) );

		try {
			PcloudClient::exchangeCode( $this->http, 'cid', 'csecret', 'thecode' );
			$this->fail( 'Expected StorageException' );
		} catch ( StorageException $e ) {
			$this->assertStringNotContainsString( 'csecret', $e->getMessage() );
			$this->assertFalse( $e->isRetryable() );
		}
	}
	public function test_ensure_folder_without_a_valid_folderid_is_retryable(): void {
		foreach ( array( array( 'result' => 0, 'metadata' => array() ), array( 'result' => 0 ), array( 'result' => 0, 'metadata' => array( 'folderid' => 0 ) ) ) as $response ) {
			$http   = new FakeTransport();
			$client = new PcloudClient( $http, 'tok' );
			$http->queueJson( $response );

			try {
				$client->ensureFolder( '/WPBackup' );
				$this->fail( 'Expected StorageException for ' . json_encode( $response ) );
			} catch ( StorageException $e ) {
				$this->assertTrue( $e->isRetryable() );
				$this->assertSame( 'server', $e->category() );
				$this->assertStringContainsString( 'folderid', $e->getMessage() );
			}
		}
	}

	public function test_upload_without_a_valid_fileid_is_retryable_and_skips_the_checksum(): void {
		$dir  = TempDir::create();
		$path = $dir . '/part.zip';
		file_put_contents( $path, 'zipdata' );
		$this->http->queueJson( array( 'result' => 0, 'metadata' => array( array( 'size' => 7 ) ) ) );

		try {
			$this->client->uploadFile( 5, $path, 'files.zip.001' );
			$this->fail( 'Expected StorageException' );
		} catch ( StorageException $e ) {
			$this->assertTrue( $e->isRetryable() );
			$this->assertSame( 'server', $e->category() );
			$this->assertStringContainsString( 'fileid', $e->getMessage() );
		} finally {
			TempDir::remove( $dir );
		}

		$this->assertCount( 1, $this->http->requests, 'no checksumfile(0)' );
	}

	public function test_folder_operations_refuse_the_root_and_invalid_ids(): void {
		$calls = array(
			'deleteFolder' => static function ( PcloudClient $client, int $id ): void {
				$client->deleteFolder( $id );
			},
			'listFolder'   => static function ( PcloudClient $client, int $id ): void {
				$client->listFolder( $id );
			},
			'renameFolder' => static function ( PcloudClient $client, int $id ): void {
				$client->renameFolder( $id, 'x' );
			},
		);

		foreach ( $calls as $name => $call ) {
			foreach ( array( 0, -1 ) as $id ) {
				try {
					$call( $this->client, $id );
					$this->fail( $name . '(' . $id . ') did not throw' );
				} catch ( StorageException $e ) {
					$this->assertFalse( $e->isRetryable(), $name );
					$this->assertSame( 'other', $e->category(), $name );
				}
			}
		}

		$this->assertSame( array(), $this->http->requests, 'nothing is sent' );
	}

	public function test_exchange_code_without_a_token_throws(): void {
		$this->http->queueJson( array( 'result' => 0, 'token_type' => 'bearer' ) );

		try {
			PcloudClient::exchangeCode( $this->http, 'cid', 'csecret', 'thecode' );
			$this->fail( 'Expected StorageException' );
		} catch ( StorageException $e ) {
			$this->assertFalse( $e->isRetryable() );
			$this->assertSame( 'auth', $e->category() );
		}
	}
}

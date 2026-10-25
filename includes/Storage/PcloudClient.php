<?php

namespace WpPcloudBackup\Storage;

/**
 * pCloud HTTP JSON API client.
 */
class PcloudClient implements StorageInterface {

	private const AUTH_CODES   = array( 1000, 2000, 2094, 2095 );
	private const QUOTA_CODES  = array( 2008 );
	private const SERVER_CODES = array( 4000, 5000, 5001 );

	/**
	 * @var HttpTransportInterface
	 */
	private $http;

	/**
	 * OAuth access token (never put into messages).
	 *
	 * @var string
	 */
	private $token;

	/**
	 * @var string
	 */
	private $host;

	public function __construct( HttpTransportInterface $http, string $token, string $host = 'api.pcloud.com' ) {
		$this->http  = $http;
		$this->token = $token;
		$this->host  = $host;
	}

	/**
	 * Exchange an OAuth authorization code for an access token.
	 */
	public static function exchangeCode( HttpTransportInterface $http, string $clientId, string $clientSecret, string $code, string $host = 'api.pcloud.com' ): string {
		$response = $http->request(
			'GET',
			'https://' . $host . '/oauth2_token',
			array(
				'query' => array(
					'client_id'     => $clientId,
					'client_secret' => $clientSecret,
					'code'          => $code,
				),
			)
		);
		$data     = self::parse( $response );
		$token    = (string) ( $data['access_token'] ?? '' );

		if ( '' === $token ) {
			throw new StorageException( 'pCloud returned no access token', 0, false, 'auth' );
		}

		return $token;
	}

	/**
	 * @return array{email:string,quota:int,usedquota:int}
	 */
	public function userInfo(): array {
		$data = $this->call( 'userinfo' );

		return array(
			'email'     => (string) ( $data['email'] ?? '' ),
			'quota'     => (int) ( $data['quota'] ?? 0 ),
			'usedquota' => (int) ( $data['usedquota'] ?? 0 ),
		);
	}

	public function ensureFolder( string $path ): int {
		$folderId = 0;
		foreach ( explode( '/', $path ) as $segment ) {
			if ( '' === $segment ) {
				continue;
			}
			$data     = $this->call(
				'createfolderifnotexists',
				array(
					'folderid' => $folderId,
					'name'     => $segment,
				)
			);
			$folderId = self::positiveId( $data['metadata']['folderid'] ?? null, 'folderid', 'createfolderifnotexists' );
		}

		return $folderId;
	}

	public function listFolder( int $folderId ): array {
		self::assertFolderId( $folderId, 'list' );
		$data  = $this->call( 'listfolder', array( 'folderid' => $folderId ) );
		$items = array();
		foreach ( $data['metadata']['contents'] ?? array() as $entry ) {
			$isFolder = ! empty( $entry['isfolder'] );
			$modified = isset( $entry['modified'] ) ? strtotime( (string) $entry['modified'] ) : false;
			$items[]  = array(
				'id'       => (int) ( $isFolder ? $entry['folderid'] : $entry['fileid'] ),
				'name'     => (string) $entry['name'],
				'isfolder' => $isFolder,
				'size'     => $isFolder ? 0 : (int) ( $entry['size'] ?? 0 ),
				'modified' => false === $modified ? 0 : $modified,
			);
		}

		return $items;
	}

	public function uploadFile( int $folderId, string $localPath, string $name ): array {
		if ( ! is_file( $localPath ) ) {
			throw new StorageException( 'File not found', 0, false, 'other' );
		}

		$data   = $this->call(
			'uploadfile',
			array(
				'folderid'  => $folderId,
				'filename'  => $name,
				'nopartial' => 1,
			),
			array(
				'field' => 'file',
				'path'  => $localPath,
				'name'  => $name,
			)
		);
		$meta   = $data['metadata'][0] ?? array();
		$fileId = self::positiveId( $meta['fileid'] ?? null, 'fileid', 'uploadfile' );
		$hashes = $this->checksum( $fileId );

		return array(
			'id'   => $fileId,
			'size' => (int) ( $meta['size'] ?? 0 ),
			'sha1' => $hashes['sha1'],
		);
	}

	public function renameFolder( int $folderId, string $newName ): void {
		self::assertFolderId( $folderId, 'rename' );
		$this->call(
			'renamefolder',
			array(
				'folderid' => $folderId,
				'toname'   => $newName,
			)
		);
	}

	public function deleteFolder( int $folderId ): void {
		self::assertFolderId( $folderId, 'delete' );
		$this->call( 'deletefolderrecursive', array( 'folderid' => $folderId ) );
	}

	public function deleteFile( int $fileId ): void {
		$this->call( 'deletefile', array( 'fileid' => $fileId ) );
	}

	public function checksum( int $fileId ): array {
		$data = $this->call( 'checksumfile', array( 'fileid' => $fileId ) );

		return array(
			'sha1' => (string) ( $data['sha1'] ?? '' ),
			'md5'  => (string) ( $data['md5'] ?? '' ),
			'size' => (int) ( $data['metadata']['size'] ?? 0 ),
		);
	}

	/**
	 * An id from a successful response; a missing or non-positive one is a broken response
	 * (retryable), never the pCloud root (folder id 0).
	 *
	 * @param mixed $value Raw value.
	 * @throws StorageException When the id is missing or not positive.
	 */
	private static function positiveId( $value, string $field, string $method ): int {
		$id = is_numeric( $value ) ? (int) $value : 0;
		if ( $id <= 0 ) {
			throw new StorageException( sprintf( 'pCloud %s response has no valid %s', $method, $field ), 0, true, 'server' );
		}

		return $id;
	}

	/**
	 * Refuse to touch the pCloud root (id 0) or an invalid id: a bug upstream, never retried.
	 *
	 * @throws StorageException When the id is not positive.
	 */
	private static function assertFolderId( int $folderId, string $operation ): void {
		if ( $folderId <= 0 ) {
			throw new StorageException( sprintf( 'Refusing to %s folder id %d', $operation, $folderId ), 0, false, 'other' );
		}
	}

	/**
	 * Call an API method; uploads use POST multipart, everything else GET.
	 *
	 * @param array<string,mixed>|null $upload
	 */
	private function call( string $method, array $params = array(), ?array $upload = null ): array {
		$options = array( 'headers' => array( 'Authorization' => 'Bearer ' . $this->token ) );
		if ( null !== $upload ) {
			$options['form']   = $params;
			$options['upload'] = $upload;
			$verb              = 'POST';
		} else {
			$options['query'] = $params;
			$verb             = 'GET';
		}

		return self::parse( $this->http->request( $verb, 'https://' . $this->host . '/' . $method, $options ) );
	}

	/**
	 * Decode a response and classify failures.
	 *
	 * @param array{status:int,body:string} $response
	 */
	private static function parse( array $response ): array {
		if ( $response['status'] >= 500 ) {
			throw new StorageException( 'pCloud server error (HTTP ' . $response['status'] . ')', $response['status'], true, 'server' );
		}

		$data = json_decode( $response['body'], true );
		if ( ! is_array( $data ) || ! isset( $data['result'] ) ) {
			throw new StorageException( 'Invalid response from pCloud', 0, true, 'server' );
		}

		$result = (int) $data['result'];
		if ( 0 === $result ) {
			return $data;
		}

		$message = isset( $data['error'] ) ? (string) $data['error'] : 'pCloud error ' . $result;
		if ( in_array( $result, self::AUTH_CODES, true ) ) {
			throw new StorageException( $message, $result, false, 'auth' );
		}
		if ( in_array( $result, self::QUOTA_CODES, true ) ) {
			throw new StorageException( $message, $result, false, 'quota' );
		}
		if ( in_array( $result, self::SERVER_CODES, true ) ) {
			throw new StorageException( $message, $result, true, 'server' );
		}

		throw new StorageException( $message, $result, false, 'other' );
	}
}

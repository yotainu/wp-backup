<?php

namespace WpPcloudBackup\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WpPcloudBackup\Crypto;

final class CryptoTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		if ( 'test_without_salts_encrypt_throws_and_decrypt_returns_null' === $this->getName() ) {
			return;
		}
		if ( ! defined( 'AUTH_KEY' ) ) {
			define( 'AUTH_KEY', 'test-auth-key' );
		}
		if ( ! defined( 'SECURE_AUTH_KEY' ) ) {
			define( 'SECURE_AUTH_KEY', 'test-secure-auth-key' );
		}
	}

	public function test_is_available(): void {
		$this->assertTrue( Crypto::isAvailable() );
	}

	public function test_roundtrip(): void {
		$first  = Crypto::encrypt( 'secret-token' );
		$second = Crypto::encrypt( 'secret-token' );

		$this->assertSame( 'secret-token', Crypto::decrypt( $first ) );
		$this->assertSame( 'secret-token', Crypto::decrypt( $second ) );
		$this->assertNotSame( $first, $second, 'IV must be random per call.' );
	}

	public function test_blob_layout_is_iv_tag_ciphertext(): void {
		$raw = base64_decode( Crypto::encrypt( 'abc' ), true );

		$this->assertSame( 12 + 16 + 3, strlen( $raw ) );
	}

	public function test_roundtrip_of_empty_string(): void {
		$this->assertSame( '', Crypto::decrypt( Crypto::encrypt( '' ) ) );
	}

	public function test_tampered_blob_returns_null(): void {
		$raw = base64_decode( Crypto::encrypt( 'secret-token' ), true );

		foreach ( array( 0, 12, 28 ) as $offset ) { // IV, tag and ciphertext.
			$bad            = $raw;
			$bad[ $offset ] = chr( ord( $bad[ $offset ] ) ^ 1 );
			$this->assertNull( Crypto::decrypt( base64_encode( $bad ) ), "offset $offset" );
		}
	}

	public function test_malformed_blob_returns_null(): void {
		$this->assertNull( Crypto::decrypt( '' ) );
		$this->assertNull( Crypto::decrypt( '!!!not base64!!!' ) );
		$this->assertNull( Crypto::decrypt( base64_encode( str_repeat( 'x', 27 ) ) ) );
	}

	public function test_decrypt_with_other_key_returns_null(): void {
		$blob = Crypto::encrypt( 'secret-token' );
		$other = Crypto::keyFromSalts( 'other-a', 'other-b' );

		$this->assertNull( Crypto::decrypt( $blob, $other ) );
		$this->assertSame( 'secret-token', Crypto::decrypt( Crypto::encrypt( 'secret-token', $other ), $other ) );
	}

	public function test_key_is_sha256_of_concatenated_salts(): void {
		$this->assertSame( hash( 'sha256', 'ab', true ), Crypto::keyFromSalts( 'a', 'b' ) );
		$this->assertSame( hash( 'sha256', AUTH_KEY . SECURE_AUTH_KEY, true ), Crypto::keyFromSalts( AUTH_KEY, SECURE_AUTH_KEY ) );
		$this->assertSame( 'secret', Crypto::decrypt( Crypto::encrypt( 'secret' ), Crypto::keyFromSalts( AUTH_KEY, SECURE_AUTH_KEY ) ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_without_salts_encrypt_throws_and_decrypt_returns_null(): void {
		$this->assertFalse( defined( 'AUTH_KEY' ) );
		$this->assertNull( Crypto::decrypt( base64_encode( str_repeat( 'x', 40 ) ) ) );

		$this->expectException( \RuntimeException::class );
		Crypto::encrypt( 'secret-token' );
	}
}

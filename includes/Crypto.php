<?php

namespace WpPcloudBackup;

/**
 * Authenticated encryption (AES-256-GCM) for secrets stored in the database.
 *
 * Blob format: base64( iv (12 bytes) . tag (16 bytes) . ciphertext ).
 * The key is derived from the WordPress AUTH_KEY / SECURE_AUTH_KEY salts.
 */
final class Crypto {

	private const CIPHER = 'aes-256-gcm';

	private const IV_LENGTH = 12;

	private const TAG_LENGTH = 16;

	/**
	 * Whether the server can run AES-256-GCM.
	 */
	public static function isAvailable(): bool {
		return function_exists( 'openssl_encrypt' ) && in_array( self::CIPHER, openssl_get_cipher_methods(), true );
	}

	/**
	 * Derive the 32 byte binary key from two salts.
	 */
	public static function keyFromSalts( string $first, string $second ): string {
		return hash( 'sha256', $first . $second, true );
	}

	/**
	 * @param string      $plain Plain text.
	 * @param string|null $key   Binary key. Null derives it from the wp-config salts.
	 * @throws \RuntimeException When no key is available or encryption fails.
	 */
	public static function encrypt( string $plain, ?string $key = null ): string {
		$key = $key ?? self::siteKey();
		if ( null === $key ) {
			throw new \RuntimeException( 'AUTH_KEY and SECURE_AUTH_KEY must be defined to encrypt.' );
		}

		$iv  = random_bytes( self::IV_LENGTH );
		$tag = '';
		$enc = openssl_encrypt( $plain, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag, '', self::TAG_LENGTH );
		if ( false === $enc ) {
			throw new \RuntimeException( 'Encryption failed.' );
		}

		return base64_encode( $iv . $tag . $enc );
	}

	/**
	 * @param string      $blob Value produced by encrypt().
	 * @param string|null $key  Binary key. Null derives it from the wp-config salts.
	 * @return string|null Plain text, or null when the blob is malformed, tampered with or the key is wrong.
	 */
	public static function decrypt( string $blob, ?string $key = null ): ?string {
		$key = $key ?? self::siteKey();
		if ( null === $key ) {
			return null;
		}

		$raw = base64_decode( $blob, true );
		if ( false === $raw || strlen( $raw ) < self::IV_LENGTH + self::TAG_LENGTH ) {
			return null;
		}

		$iv  = substr( $raw, 0, self::IV_LENGTH );
		$tag = substr( $raw, self::IV_LENGTH, self::TAG_LENGTH );
		$enc = (string) substr( $raw, self::IV_LENGTH + self::TAG_LENGTH );

		$plain = openssl_decrypt( $enc, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag );

		return false === $plain ? null : $plain;
	}

	private static function siteKey(): ?string {
		if ( ! defined( 'AUTH_KEY' ) || ! defined( 'SECURE_AUTH_KEY' ) ) {
			return null;
		}

		return self::keyFromSalts( (string) AUTH_KEY, (string) SECURE_AUTH_KEY );
	}
}

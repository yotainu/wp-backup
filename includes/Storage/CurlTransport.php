<?php

namespace WpPcloudBackup\Storage;

/**
 * cURL-backed HTTP transport. Uploads are streamed from disk by cURL.
 */
final class CurlTransport implements HttpTransportInterface {

	public function request( string $method, string $url, array $options = array() ): array {
		if ( ! empty( $options['query'] ) ) {
			$url .= ( false === strpos( $url, '?' ) ? '?' : '&' ) . http_build_query( $options['query'] );
		}

		$headers = array();
		foreach ( $options['headers'] ?? array() as $name => $value ) {
			$headers[] = $name . ': ' . $value;
		}

		$upload    = $options['upload'] ?? null;
		$timeout   = $options['timeout'] ?? ( null !== $upload ? 600 : 60 );
		$curl_opts = array(
			CURLOPT_URL               => $url,
			CURLOPT_CUSTOMREQUEST     => $method,
			CURLOPT_RETURNTRANSFER    => true,
			CURLOPT_HTTPHEADER        => $headers,
			CURLOPT_TIMEOUT           => $timeout,
			CURLOPT_CONNECTTIMEOUT    => 20,
			CURLOPT_LOW_SPEED_LIMIT   => $options['low_speed_limit'] ?? 1024,
			CURLOPT_LOW_SPEED_TIME    => $options['low_speed_time'] ?? 30,
			CURLOPT_FOLLOWLOCATION    => false,
			CURLOPT_SSL_VERIFYPEER    => true,
		);

		if ( null !== $upload || 'GET' !== $method ) {
			$fields = $options['form'] ?? array();
			if ( null !== $upload ) {
				$fields[ $upload['field'] ] = new \CURLFile( $upload['path'], 'application/octet-stream', $upload['name'] );
			}
			$curl_opts[ CURLOPT_POST ]       = true;
			$curl_opts[ CURLOPT_POSTFIELDS ] = $fields;
		}

		$handle = curl_init();
		curl_setopt_array( $handle, $curl_opts );
		$body = curl_exec( $handle );

		if ( false === $body ) {
			$message = curl_error( $handle );
			curl_close( $handle );
			throw StorageException::network( '' !== $message ? $message : 'HTTP request failed' );
		}

		$status = (int) curl_getinfo( $handle, CURLINFO_RESPONSE_CODE );
		curl_close( $handle );

		return array(
			'status' => $status,
			'body'   => (string) $body,
		);
	}
}

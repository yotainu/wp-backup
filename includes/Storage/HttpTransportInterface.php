<?php

namespace WpPcloudBackup\Storage;

/**
 * Minimal HTTP client abstraction (swapped for a fake in tests).
 */
interface HttpTransportInterface {

	/**
	 * Perform a request. Connection and timeout failures throw
	 * StorageException::network(); the response body is never interpreted.
	 *
	 * @param array{headers?:array<string,string>,query?:array,form?:array,upload?:array{field:string,path:string,name:string},timeout?:int,low_speed_limit?:int,low_speed_time?:int} $options
	 * @return array{status:int,body:string}
	 */
	public function request( string $method, string $url, array $options = array() ): array;
}

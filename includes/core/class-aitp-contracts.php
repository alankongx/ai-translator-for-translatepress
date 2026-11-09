<?php
/**
 * Interfaces between the translation core and its storage / network, plus in-memory
 * implementations used by tests and as safe fallbacks.
 *
 * @package AI_Translator_For_TranslatePress
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface AITP_Transport {
	/**
	 * Send POST requests, up to $concurrency at a time.
	 *
	 * @param array<string|int,array{url:string,headers:array,body:string,timeout:float}> $requests Requests.
	 * @param int                                                                         $concurrency Parallel requests.
	 * @return array<string|int,array{status:int,headers:array,body:string,error:?string,error_type:?string,duration_ms:int}>
	 */
	public function send( array $requests, $concurrency );
}

interface AITP_Cache_Store {
	/** @return string|null */
	public function get( $key );
	public function set( $key, $target_locale, $text );
	public function delete( $key );
}

interface AITP_Quota {
	/** Atomically reserve bytes against the daily limit. @return bool */
	public function reserve( $bytes );
	/** The reserved request was sent and may be billed. */
	public function commit( $bytes );
	/** The reserved request was not sent / not billable: give the bytes back. */
	public function release( $bytes );
}

interface AITP_State_Store {
	/** @return array */
	public function get();
	public function set( array $state );
}

interface AITP_Failure_Store {
	/** @return bool True when the item is in backoff and must not be sent now. */
	public function held( $fkey );
	public function failed( $fkey, array $info );
	public function clear( $fkey );
}

interface AITP_Review_Store {
	public function add( array $row );
}

interface AITP_Log {
	public function log( $level, $event, array $data );
}

interface AITP_Caps_Store {
	/** @return array|null */
	public function get( $fingerprint );
	public function set( $fingerprint, array $caps );
}

/* ---------------------------------------------------------------------------------------
 * In-memory implementations.
 * ------------------------------------------------------------------------------------- */

class AITP_Memory_Cache implements AITP_Cache_Store {
	public $rows = array();
	public function get( $key ) {
		return isset( $this->rows[ $key ] ) ? $this->rows[ $key ] : null;
	}
	public function set( $key, $target_locale, $text ) {
		$this->rows[ $key ] = $text;
	}
	public function delete( $key ) {
		unset( $this->rows[ $key ] );
	}
}

class AITP_Memory_Quota implements AITP_Quota {
	public $limit;
	public $used     = 0;
	public $reserved = 0;
	public function __construct( $limit = PHP_INT_MAX ) {
		$this->limit = $limit;
	}
	public function reserve( $bytes ) {
		if ( $this->used + $this->reserved + $bytes > $this->limit ) {
			return false;
		}
		$this->reserved += $bytes;
		return true;
	}
	public function commit( $bytes ) {
		$this->reserved -= $bytes;
		$this->used     += $bytes;
	}
	public function release( $bytes ) {
		$this->reserved -= $bytes;
	}
}

class AITP_Memory_State implements AITP_State_Store {
	public $state = array();
	public function get() {
		return $this->state;
	}
	public function set( array $state ) {
		$this->state = $state;
	}
}

class AITP_Memory_Failures implements AITP_Failure_Store {
	public $rows = array();
	public $now;
	public function __construct( $now = null ) {
		$this->now = $now;
	}
	public function held( $fkey ) {
		$now = $this->now !== null ? $this->now : time();
		return isset( $this->rows[ $fkey ] ) && $this->rows[ $fkey ]['next_retry_at'] > $now;
	}
	public function failed( $fkey, array $info ) {
		$now      = $this->now !== null ? $this->now : time();
		$attempts = isset( $this->rows[ $fkey ] ) ? $this->rows[ $fkey ]['attempts'] + 1 : 1;
		$this->rows[ $fkey ] = array_merge(
			$info,
			array(
				'attempts'      => $attempts,
				'next_retry_at' => $now + AITP_Translator::backoff_seconds( $attempts ),
			)
		);
	}
	public function clear( $fkey ) {
		unset( $this->rows[ $fkey ] );
	}
}

class AITP_Memory_Review implements AITP_Review_Store {
	public $rows = array();
	public function add( array $row ) {
		$this->rows[] = $row;
	}
}

class AITP_Memory_Log implements AITP_Log {
	public $rows = array();
	public function log( $level, $event, array $data ) {
		$this->rows[] = array( 'level' => $level, 'event' => $event, 'data' => $data );
	}
}

class AITP_Memory_Caps implements AITP_Caps_Store {
	public $rows = array();
	public function get( $fingerprint ) {
		return isset( $this->rows[ $fingerprint ] ) ? $this->rows[ $fingerprint ] : null;
	}
	public function set( $fingerprint, array $caps ) {
		$this->rows[ $fingerprint ] = $caps;
	}
}

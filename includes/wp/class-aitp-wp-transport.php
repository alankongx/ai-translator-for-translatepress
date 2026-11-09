<?php
/**
 * HTTP transport on top of WordPress' bundled Requests library.
 *
 * - Parallel requests (curl multi) in waves of $concurrency.
 * - TLS verification on, redirects off (an endpoint cannot redirect the API key elsewhere).
 * - WordPress proxy settings respected.
 * - A request whose wave would start after its "not_after" time is not sent at all.
 *
 * @package AI_Translator_For_TranslatePress
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AITP_WP_Transport implements AITP_Transport {

	/**
	 * @param array $requests    Requests (url, headers, body, timeout, not_after?, deadline?).
	 * @param int   $concurrency Parallel requests.
	 * @return array
	 */
	public function send( array $requests, $concurrency ) {
		$out = array();
		foreach ( array_chunk( $requests, max( 1, (int) $concurrency ), true ) as $wave ) {
			$now  = microtime( true );
			$send = array();
			foreach ( $wave as $id => $r ) {
				if ( isset( $r['not_after'] ) && $now > (float) $r['not_after'] ) {
					$out[ $id ] = self::failure( 'Not sent: time budget used up', 'skipped', 0 );
					continue;
				}
				$timeout = (float) $r['timeout'];
				if ( isset( $r['deadline'] ) ) {
					$timeout = max( 5.0, min( $timeout, (float) $r['deadline'] - $now ) );
				}
				$r['timeout'] = $timeout;
				$send[ $id ]  = $r;
			}
			if ( ! $send ) {
				continue;
			}
			$class = self::requests_class();
			if ( count( $send ) === 1 || $class === '' ) {
				foreach ( $send as $id => $r ) {
					$out[ $id ] = $this->single( $r );
				}
			} else {
				$out += $this->multi( $send, $class );
			}
		}
		return $out;
	}

	/** @return string */
	private static function requests_class() {
		if ( class_exists( '\WpOrg\Requests\Requests' ) ) {
			return '\WpOrg\Requests\Requests';
		}
		if ( class_exists( 'Requests' ) ) {
			return 'Requests';
		}
		return '';
	}

	/**
	 * @param array $r Request.
	 * @return array
	 */
	private function single( array $r ) {
		$start = microtime( true );
		$resp  = wp_remote_post(
			$r['url'],
			array(
				'headers'     => $r['headers'],
				'body'        => $r['body'],
				'timeout'     => $r['timeout'],
				'redirection' => 0,
				'sslverify'   => true,
				'user-agent'  => self::user_agent(),
				'data_format' => 'body',
			)
		);
		$ms = (int) round( ( microtime( true ) - $start ) * 1000 );
		if ( is_wp_error( $resp ) ) {
			return self::failure( $resp->get_error_message(), self::error_type( $resp->get_error_message() ), $ms );
		}
		$headers = array();
		foreach ( (array) wp_remote_retrieve_headers( $resp ) as $k => $v ) {
			$headers[ strtolower( (string) $k ) ] = $v;
		}
		$raw = wp_remote_retrieve_headers( $resp );
		if ( is_object( $raw ) && method_exists( $raw, 'getAll' ) ) {
			$headers = array();
			foreach ( $raw->getAll() as $k => $v ) {
				$headers[ strtolower( (string) $k ) ] = is_array( $v ) ? implode( ', ', $v ) : $v;
			}
		}
		return array(
			'status'      => (int) wp_remote_retrieve_response_code( $resp ),
			'headers'     => $headers,
			'body'        => (string) wp_remote_retrieve_body( $resp ),
			'error'       => null,
			'error_type'  => null,
			'duration_ms' => $ms,
		);
	}

	/**
	 * @param array  $send  Requests.
	 * @param string $class Requests class name.
	 * @return array
	 */
	private function multi( array $send, $class ) {
		$reqs = array();
		foreach ( $send as $id => $r ) {
			$options = array(
				'timeout'          => $r['timeout'],
				'connect_timeout'  => min( 10, $r['timeout'] ),
				'follow_redirects' => false,
				'redirects'        => 0,
				'useragent'        => self::user_agent(),
				'verify'           => ABSPATH . WPINC . '/certificates/ca-bundle.crt',
				'verifyname'       => true,
			);
			$proxy = self::proxy( $r['url'] );
			if ( $proxy !== null ) {
				$options['proxy'] = $proxy;
			}
			$reqs[ $id ] = array(
				'url'     => $r['url'],
				'headers' => $r['headers'],
				'data'    => $r['body'],
				'type'    => 'POST',
				'options' => $options,
			);
		}
		$start = microtime( true );
		try {
			$responses = call_user_func( array( $class, 'request_multiple' ), $reqs, array() );
		} catch ( Exception $e ) {
			// Fall back to sequential requests.
			$out = array();
			foreach ( $send as $id => $r ) {
				$out[ $id ] = $this->single( $r );
			}
			return $out;
		}
		$ms  = (int) round( ( microtime( true ) - $start ) * 1000 );
		$out = array();
		foreach ( $send as $id => $r ) {
			$resp = isset( $responses[ $id ] ) ? $responses[ $id ] : null;
			if ( ! is_object( $resp ) || $resp instanceof Exception ) {
				$msg        = $resp instanceof Exception ? $resp->getMessage() : 'No response';
				$out[ $id ] = self::failure( $msg, self::error_type( $msg ), $ms );
				continue;
			}
			$headers = array();
			if ( isset( $resp->headers ) && is_object( $resp->headers ) && method_exists( $resp->headers, 'getAll' ) ) {
				foreach ( $resp->headers->getAll() as $k => $v ) {
					$headers[ strtolower( (string) $k ) ] = is_array( $v ) ? implode( ', ', $v ) : $v;
				}
			}
			$out[ $id ] = array(
				'status'      => (int) $resp->status_code,
				'headers'     => $headers,
				'body'        => (string) $resp->body,
				'error'       => null,
				'error_type'  => null,
				'duration_ms' => $ms,
			);
		}
		return $out;
	}

	/**
	 * WordPress proxy settings for a URL, in the form Requests expects.
	 *
	 * @param string $url URL.
	 * @return mixed|null
	 */
	private static function proxy( $url ) {
		if ( ! class_exists( 'WP_HTTP_Proxy' ) ) {
			return null;
		}
		$p = new WP_HTTP_Proxy();
		if ( ! $p->is_enabled() || ! $p->send_through_proxy( $url ) ) {
			return null;
		}
		$args = array( $p->host() . ':' . $p->port() );
		if ( $p->use_authentication() ) {
			$args[] = $p->username();
			$args[] = $p->password();
		}
		if ( class_exists( '\WpOrg\Requests\Proxy\Http' ) ) {
			return new \WpOrg\Requests\Proxy\Http( $args );
		}
		if ( class_exists( 'Requests_Proxy_HTTP' ) ) {
			return new Requests_Proxy_HTTP( $args );
		}
		return null;
	}

	/** @return string */
	private static function user_agent() {
		return 'AI-Translator-for-TranslatePress/' . AITP_VERSION . '; WordPress/' . get_bloginfo( 'version' );
	}

	/**
	 * @param string $message Error message.
	 * @return string
	 */
	private static function error_type( $message ) {
		return preg_match( '~timed out|timeout|cURL error 28~i', (string) $message ) ? 'timeout' : 'network';
	}

	/**
	 * @param string $message Message.
	 * @param string $type    Type.
	 * @param int    $ms      Duration.
	 * @return array
	 */
	private static function failure( $message, $type, $ms ) {
		return array(
			'status'      => 0,
			'headers'     => array(),
			'body'        => '',
			'error'       => (string) $message,
			'error_type'  => $type,
			'duration_ms' => (int) $ms,
		);
	}
}

<?php
/**
 * Stops sending requests when the provider is failing, so page views cannot cause an
 * endless stream of failing (and possibly billed) requests.
 *
 * States:
 *  - closed  normal operation
 *  - open    temporary stop (rate limit, daily quota, repeated server errors/timeouts)
 *  - paused  needs attention (invalid key, billing, unknown model, repeated bad requests);
 *            a single probe request is allowed every 30 minutes so service resumes by
 *            itself after the account is topped up or the key is fixed.
 *
 * @package AI_Translator_For_TranslatePress
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AITP_Circuit_Breaker {

	const PROBE_INTERVAL = 1800;

	/** @var AITP_State_Store */
	private $store;

	/** @var callable */
	private $clock;

	/**
	 * @param AITP_State_Store $store State storage.
	 * @param callable|null    $clock Returns current unix time.
	 */
	public function __construct( AITP_State_Store $store, $clock = null ) {
		$this->store = $store;
		$this->clock = $clock ? $clock : 'time';
	}

	/** @return int */
	private function now() {
		return (int) call_user_func( $this->clock );
	}

	/** @return array */
	public function state() {
		return array_merge(
			array(
				'status'    => 'closed',
				'reason'    => '',
				'until'     => 0,
				'fails'     => 0,
				'rate_hits' => 0,
				'bad'       => 0,
				'since'     => 0,
				'message'   => '',
			),
			$this->store->get()
		);
	}

	/**
	 * Whether a request may be sent now.
	 *
	 * @return bool
	 */
	public function allow() {
		$s = $this->state();
		if ( $s['status'] === 'closed' ) {
			return true;
		}
		if ( $this->now() < (int) $s['until'] ) {
			return false;
		}
		if ( $s['status'] === 'paused' ) {
			// Allow one probe, then push the next probe out so concurrent requests do not stampede.
			$s['until'] = $this->now() + self::PROBE_INTERVAL;
			$this->store->set( $s );
		}
		return true;
	}

	/** Record a successful response. */
	public function success() {
		$s = $this->state();
		if ( $s['status'] !== 'closed' || $s['fails'] || $s['rate_hits'] || $s['bad'] ) {
			$this->store->set( array( 'status' => 'closed' ) );
		}
	}

	/**
	 * @param string   $code        Error category.
	 * @param int|null $retry_after Seconds suggested by the provider.
	 * @param string   $message     Redacted message.
	 */
	public function failure( $code, $retry_after = null, $message = '' ) {
		$s   = $this->state();
		$now = $this->now();
		$s['message'] = (string) $message;
		$s['reason']  = (string) $code;
		if ( $s['status'] === 'closed' ) {
			$s['since'] = $now;
		}
		switch ( $code ) {
			case 'auth':
			case 'billing':
			case 'not_found':
				$s['status'] = 'paused';
				$s['until']  = $now + self::PROBE_INTERVAL;
				break;
			case 'bad_request':
				$s['bad']++;
				if ( $s['bad'] >= 2 ) {
					$s['status'] = 'paused';
					$s['until']  = $now + self::PROBE_INTERVAL;
				} else {
					$s['status'] = 'open';
					$s['until']  = $now + 300;
				}
				break;
			case 'quota_daily':
				$s['status'] = 'open';
				$s['until']  = $now + max( 3600, (int) $retry_after );
				break;
			case 'rate_limit':
				$s['rate_hits']++;
				$s['status'] = 'open';
				$s['until']  = $now + max( (int) $retry_after, (int) min( 900, 15 * pow( 2, $s['rate_hits'] - 1 ) ) );
				break;
			default: // timeout, network, server, invalid_response.
				$s['fails']++;
				if ( $s['fails'] >= 3 ) {
					$s['status'] = 'open';
					$s['until']  = $now + (int) min( 600, 30 * pow( 2, $s['fails'] - 3 ) );
				}
		}
		$this->store->set( $s );
	}

	/** Manual reset from the admin screen or after settings change. */
	public function reset() {
		$this->store->set( array( 'status' => 'closed' ) );
	}
}

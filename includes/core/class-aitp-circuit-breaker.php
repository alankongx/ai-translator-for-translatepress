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

	/** Languages held at once before bad answers count as a provider-wide problem. */
	const LOCALES_FOR_GLOBAL = 3;

	/**
	 * Whether a request may be sent now.
	 *
	 * @param string $locale Target language; '' checks the provider-wide state only.
	 * @return bool
	 */
	public function allow( $locale = '' ) {
		$s = $this->state();
		if ( $locale !== '' && ! empty( $s['locales'][ $locale ]['until'] ) && $this->now() < (int) $s['locales'][ $locale ]['until'] ) {
			return false; // Checked first: a held language must not use up the paused probe.
		}
		if ( $s['status'] !== 'closed' ) {
			if ( $this->now() < (int) $s['until'] ) {
				return false;
			}
			if ( $s['status'] === 'paused' ) {
				// Allow one probe, then push the next probe out so concurrent requests do not stampede.
				$s['until'] = $this->now() + self::PROBE_INTERVAL;
				$this->store->set( $s );
			}
		}
		return true;
	}

	/**
	 * Languages currently held because of repeated unusable answers.
	 *
	 * @return array<string,int> locale => until.
	 */
	public function held_locales() {
		$s   = $this->state();
		$out = array();
		foreach ( isset( $s['locales'] ) && is_array( $s['locales'] ) ? $s['locales'] : array() as $loc => $l ) {
			if ( ! empty( $l['until'] ) && (int) $l['until'] > $this->now() ) {
				$out[ $loc ] = (int) $l['until'];
			}
		}
		return $out;
	}

	/**
	 * Record a successful response. Clears provider-wide counters and the given language's;
	 * other languages stay held (their problem is with that language, not the provider).
	 *
	 * @param string $locale Target language.
	 */
	public function success( $locale = '' ) {
		$s       = $this->state();
		$locales = isset( $s['locales'] ) && is_array( $s['locales'] ) ? $s['locales'] : array();
		$had     = isset( $locales[ $locale ] );
		unset( $locales[ $locale ] );
		if ( $s['status'] !== 'closed' || $s['fails'] || $s['rate_hits'] || $s['bad'] || $had ) {
			$this->store->set( $locales ? array( 'status' => 'closed', 'locales' => $locales ) : array( 'status' => 'closed' ) );
		}
	}

	/**
	 * @param string   $code        Error category.
	 * @param int|null $retry_after Seconds suggested by the provider.
	 * @param string   $message     Redacted message.
	 * @param string   $locale      Target language, for unusable answers (bad_output).
	 */
	public function failure( $code, $retry_after = null, $message = '', $locale = '' ) {
		$s   = $this->state();
		$now = $this->now();
		if ( $code === 'bad_output' && $locale !== '' ) {
			$this->locale_failure( $s, $now, (string) $locale, (string) $message );
			return;
		}
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

	/**
	 * Unusable answers (misaligned, broken JSON) for one language hold that language only:
	 * a validation rule that misjudges one language must not stop all the others. When
	 * several languages are held at once the provider itself is failing: hold everything.
	 *
	 * @param array  $s       State.
	 * @param int    $now     Now.
	 * @param string $locale  Target language.
	 * @param string $message Message.
	 */
	private function locale_failure( array $s, $now, $locale, $message ) {
		$locales = isset( $s['locales'] ) && is_array( $s['locales'] ) ? $s['locales'] : array();
		$l       = isset( $locales[ $locale ] ) ? $locales[ $locale ] : array( 'n' => 0, 'until' => 0 );
		$l['n']  = (int) $l['n'] + 1;
		$l['message'] = $message;
		if ( $l['n'] >= 3 ) {
			$l['until'] = $now + (int) min( 600, 30 * pow( 2, $l['n'] - 3 ) );
		}
		$locales[ $locale ] = $l;
		$s['locales']       = $locales;
		$held               = 0;
		foreach ( $locales as $x ) {
			if ( ! empty( $x['until'] ) && (int) $x['until'] > $now ) {
				$held++;
			}
		}
		if ( $held >= self::LOCALES_FOR_GLOBAL && $s['status'] === 'closed' ) {
			$s['status']  = 'open';
			$s['reason']  = 'bad_output';
			$s['message'] = $message;
			$s['since']   = $now;
			$s['until']   = $now + 300;
		}
		$this->store->set( $s );
	}

	/** Manual reset from the admin screen or after settings change. */
	public function reset() {
		$this->store->set( array( 'status' => 'closed' ) );
	}
}

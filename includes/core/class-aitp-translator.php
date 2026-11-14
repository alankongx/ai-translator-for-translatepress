<?php
/**
 * Translation orchestrator. Independent of TranslatePress so it can be tested in isolation.
 *
 * Guarantees:
 *  - Translations are mapped back by ID, never by position.
 *  - Only translations that passed validation are returned. Everything else is reported as
 *    failed with a reason and is NOT returned, so the caller never saves source text,
 *    empty strings, error messages or truncated output as a translation.
 *  - Retries are bounded (rounds, attempts per item, time budget, circuit breaker,
 *    per-item backoff across page views).
 *
 * @package AI_Translator_For_TranslatePress
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AITP_Translator {

	/** Failure reasons that say nothing about the item itself (no per-item backoff). */
	const SYSTEMIC = array( 'structured_data', 'deferred', 'breaker', 'quota', 'rate_limit', 'quota_daily', 'auth', 'billing', 'not_found', 'bad_request', 'network', 'timeout', 'server', 'unsupported', 'held', 'invalid_response', 'split', 'repeated_output', 'refused' );

	/**
	 * Hard codes that describe the language of the answer rather than where it sits. Many of
	 * them in one batch mean a rule misjudges this language (or the model writes the wrong
	 * one), not that answers moved onto the wrong ids: each entry is judged on its own.
	 */
	const LANGUAGE_CODES = array( 'foreign_script', 'source_residue', 'wrong_script', 'english_residue', 'untranslated' );

	/** Extra passes allowed only to narrow down a batch that failed as a whole. */
	const MAX_SPLIT_PASSES = 8;

	/** @var AITP_Provider */
	private $provider;
	/** @var AITP_Rules */
	private $rules;
	/** @var AITP_Validator */
	private $validator;
	/** @var AITP_Transport */
	private $transport;
	/** @var AITP_Cache_Store */
	private $cache;
	/** @var AITP_Quota */
	private $quota;
	/** @var AITP_Circuit_Breaker */
	private $breaker;
	/** @var AITP_Failure_Store */
	private $failures;
	/** @var AITP_Review_Store */
	private $review;
	/** @var AITP_Log */
	private $log;
	/** @var AITP_Caps_Store */
	private $caps_store;
	/** @var AITP_Usage_Store */
	private $usage;
	/** @var callable */
	private $clock;
	/** @var array */
	private $opt;

	/** @var array */
	private $caps;

	/**
	 * @param AITP_Provider $provider Provider.
	 * @param AITP_Rules    $rules    Rules.
	 * @param array         $deps     transport, cache, quota, breaker, failures, review, log, caps, clock.
	 * @param array         $options  See defaults below.
	 */
	public function __construct( AITP_Provider $provider, AITP_Rules $rules, array $deps, array $options = array() ) {
		$this->provider   = $provider;
		$this->rules      = $rules;
		$this->validator  = new AITP_Validator( $rules );
		$this->transport  = $deps['transport'];
		$this->cache      = isset( $deps['cache'] ) ? $deps['cache'] : new AITP_Memory_Cache();
		$this->quota      = isset( $deps['quota'] ) ? $deps['quota'] : new AITP_Memory_Quota();
		$this->breaker    = isset( $deps['breaker'] ) ? $deps['breaker'] : new AITP_Circuit_Breaker( new AITP_Memory_State() );
		$this->failures   = isset( $deps['failures'] ) ? $deps['failures'] : new AITP_Memory_Failures();
		$this->review     = isset( $deps['review'] ) ? $deps['review'] : new AITP_Memory_Review();
		$this->log        = isset( $deps['log'] ) ? $deps['log'] : new AITP_Memory_Log();
		$this->caps_store = isset( $deps['caps'] ) ? $deps['caps'] : new AITP_Memory_Caps();
		$this->usage      = isset( $deps['usage'] ) ? $deps['usage'] : new AITP_Memory_Usage();
		$this->clock      = isset( $deps['clock'] ) ? $deps['clock'] : 'microtime';
		$this->opt        = array_merge(
			array(
				'batch_tokens'        => 2500,
				'batch_items'         => 40,
				'max_rounds'          => 3,
				'max_attempts'        => 3,
				'concurrency'         => 3,
				'http_timeout'        => 30,
				'min_request_seconds' => 6,
				'split_bytes'         => 6000,
				'soft_policy'         => 'save_review', // or 'strict'.
				'soft_retry'          => true,
				'max_output_cap'      => 32768,
				'context_items'       => 2,
				'context_chars'       => 300,
			),
			$options
		);

		$stored     = $this->caps_store->get( $provider->fingerprint() );
		$this->caps = is_array( $stored ) ? array_merge( $provider->default_caps(), $stored ) : $provider->default_caps();
	}

	/**
	 * Hold time after the n-th consecutive failure of the same item.
	 *
	 * @param int $attempts Consecutive failures.
	 * @return int Seconds.
	 */
	public static function backoff_seconds( $attempts ) {
		$table = array( 1 => 0, 2 => 300, 3 => 1800, 4 => 7200 );
		return isset( $table[ $attempts ] ) ? $table[ $attempts ] : 43200;
	}

	/** @return float */
	private function now() {
		$v = call_user_func( $this->clock, true );
		return (float) $v;
	}

	/**
	 * Cache key for an item.
	 *
	 * @param string $text      Source.
	 * @param string $src       Source locale.
	 * @param string $tgt       Target locale.
	 * @param string $ctx_sig   Context signature.
	 * @param string $prompt_v  Prompt version.
	 * @return string
	 */
	private function cache_key( $text, $src, $tgt, $ctx_sig, $prompt_v ) {
		return hash( 'sha256', (string) json_encode( array( AITP_Protocol::VERSION, $this->provider->fingerprint(), $src, $tgt, $this->rules->version(), $prompt_v, $ctx_sig, $text ) ) );
	}

	/**
	 * @param string $text Source.
	 * @param string $src  Source locale.
	 * @param string $tgt  Target locale.
	 * @return string
	 */
	public static function failure_key( $text, $src, $tgt ) {
		return hash( 'sha256', $src . '|' . $tgt . '|' . $text );
	}

	/**
	 * Translate a set of strings.
	 *
	 * @param array<string|int,string> $items    key => text (keys are preserved, any order, non-contiguous).
	 * @param string                   $src      Source locale (e.g. en_US).
	 * @param string                   $tgt      Target locale (e.g. es_MX).
	 * @param float                    $deadline Absolute time (microtime) after which no new request starts.
	 * @param array                    $names    Optional English names: ['source' => ..., 'target' => ...].
	 * @return array{translations:array,failed:array,flagged:array,stats:array}
	 */
	public function translate( array $items, $src, $tgt, $deadline, array $names = array() ) {
		$started = $this->now();
		$si      = AITP_Languages::info( $src, isset( $names['source'] ) ? $names['source'] : null );
		$ti      = AITP_Languages::info( $tgt, isset( $names['target'] ) ? $names['target'] : null );
		$system  = AITP_Protocol::system_prompt( $si, $ti );
		$prompt_v = substr( md5( $system ), 0, 12 );

		$out = array(
			'translations' => array(),
			'failed'       => array(),
			'flagged'      => array(),
			'stats'        => array(
				'items'       => count( $items ),
				'cached'      => 0,
				'held'        => 0,
				'fixed'       => 0,
				'requests'    => 0,
				'rounds'      => 0,
				'sent_bytes'  => 0,
				'stop_reason' => '',
			),
		);

		$order   = array_keys( $items );
		$pos     = array_flip( array_map( 'strval', $order ) );
		$texts   = array();
		foreach ( $items as $k => $v ) {
			$texts[ (string) $k ] = (string) $v;
		}

		// 1. Backoff and cache.
		$pending = array();
		$ckeys   = array();
		foreach ( $texts as $key => $text ) {
			if ( trim( $text ) === '' ) {
				$out['failed'][ $key ] = 'empty_source';
				continue;
			}
			if ( AITP_Text::is_structured_data( $text ) ) {
				$out['failed'][ $key ] = 'structured_data';
				continue;
			}
			// Code-like interface strings (date formats, text direction): a model would turn
			// "F j, Y" into words that break date(); they are answered here and never sent.
			$fixed = self::fixed_translation( $text, $ti );
			if ( $fixed !== null ) {
				$out['translations'][ $key ] = $fixed;
				$out['stats']['fixed']++;
				continue;
			}
			$fkey = self::failure_key( $text, $src, $tgt );
			if ( $this->failures->held( $fkey ) ) {
				$out['failed'][ $key ] = 'held';
				$out['stats']['held']++;
				continue;
			}
			$ckey           = $this->cache_key( $text, $src, $tgt, $this->context_signature( $key, $order, $texts ), $prompt_v );
			$ckeys[ $key ]  = $ckey;
			$cached         = $this->cache->get( $ckey );
			if ( is_string( $cached ) ) {
				$v = $this->validator->check( $text, $cached, $si, $ti );
				$v_soft = array_diff( $v['soft'], array( AITP_Validator::CONFIRM_IDENTICAL ) );
				if ( ! $v['hard'] && ! ( $v_soft && $this->opt['soft_policy'] === 'strict' ) ) {
					$out['translations'][ $key ] = $cached;
					$out['stats']['cached']++;
					continue;
				}
				$this->cache->delete( $ckey );
			}
			$pending[ $key ] = $text;
		}

		// 2. Split long items into units.
		$units      = array();
		$item_units = array();
		$splits     = array();
		$n          = 0;
		foreach ( $pending as $key => $text ) {
			$split          = AITP_Segmenter::split( $text, (int) $this->opt['split_bytes'] );
			$splits[ $key ] = $split;
			foreach ( $split['parts'] as $i => $part ) {
				$uid                  = 'u' . ( ++$n );
				$units[ $uid ]        = array(
					'key'            => $key,
					'part'           => $i,
					'parts'          => count( $split['parts'] ),
					'text'           => $part['text'],
					'attempts'       => 0,
					'note'           => '',
					'reason'         => '',
					'done'           => false,
					'dead'           => false,
					'result'         => null,
					'flags'          => array(),
					'soft_retried'   => false,
					'soft_candidate' => null,
					'soft_flags'     => array(),
					'confirm_asked'  => false,
					'solo'           => false,
				);
				$item_units[ $key ][] = $uid;
			}
		}

		// 3. Rounds.
		//
		// A request that fails as a whole (moderation, safety block, truncation, broken JSON,
		// a 400 that only this batch gets, a timeout) is split in half and the halves are sent
		// in the next pass, without counting an attempt against the entries: one bad text must
		// not keep its neighbours untranslated. Only a single entry that still fails is charged.
		// Splitting may run past max_rounds (MAX_SPLIT_PASSES), sending only the halves.
		$queue       = array_keys( $units );
		$forced      = array(); // Groups of uids that must be sent together in the next pass.
		$stop_reason = '';
		$round       = 0;
		$passes      = 0;
		$downgrades  = 0;
		$usage       = array( 'input' => 0, 'output' => 0, 'thinking' => 0, 'requests' => 0 );
		while ( $queue && $passes < (int) $this->opt['max_rounds'] + self::MAX_SPLIT_PASSES ) {
			$extra = $round >= (int) $this->opt['max_rounds'];
			if ( $extra && ! $forced ) {
				break;
			}
			$round++;
			$passes++;
			if ( $this->now() >= $deadline - (float) $this->opt['min_request_seconds'] ) {
				$stop_reason = 'deferred';
				break;
			}
			if ( ! $this->breaker->allow( $tgt ) ) {
				$stop_reason = 'breaker';
				break;
			}
			$out['stats']['rounds'] = $round;

			$in_queue = array_flip( $queue );
			$batches  = array();
			$grouped  = array();
			$split_bi = array(); // Batches that are halves of an earlier batch.
			foreach ( $forced as $g ) {
				$g = array_values(
					array_filter(
						$g,
						function ( $uid ) use ( $in_queue ) {
							return isset( $in_queue[ $uid ] );
						}
					)
				);
				if ( $g ) {
					$split_bi[ count( $batches ) ] = true;
					$batches[] = $g;
					foreach ( $g as $uid ) {
						$grouped[ $uid ] = true;
					}
				}
			}
			$forced = array();
			if ( ! $extra ) {
				$max_items = max( 1, (int) floor( $this->opt['batch_items'] / pow( 2, min( $round, (int) $this->opt['max_rounds'] ) - 1 ) ) );
				$queue_txt = array();
				foreach ( $queue as $uid ) {
					if ( isset( $grouped[ $uid ] ) ) {
						continue;
					}
					if ( $units[ $uid ]['solo'] ) {
						$batches[] = array( $uid );
						continue;
					}
					$queue_txt[ $uid ] = $units[ $uid ]['text'];
				}
				foreach ( AITP_Batcher::build( $queue_txt, (int) $this->opt['batch_tokens'], $max_items ) as $b ) {
					$batches[] = $b;
				}
			}

			$cap      = (int) $this->opt['max_output_cap'];
			if ( ! empty( $this->caps['max_out'] ) ) {
				$cap = min( $cap, (int) $this->caps['max_out'] );
			}
			$reserve  = $this->provider->thinking_reserve( $this->caps );
			if ( ! empty( $this->caps['thinks'] ) ) {
				$reserve = max( $reserve, 8192 ); // Learnt: the model thinks even when asked not to.
			}
			$requests = array();
			$meta     = array();
			foreach ( $batches as $bi => $uids ) {
				$ids     = array();
				$payload = array();
				$notes   = array();
				$bytes   = 0;
				foreach ( $uids as $j => $uid ) {
					$id             = 't' . ( $j + 1 );
					$ids[ $id ]     = $uid;
					$payload[ $id ] = $units[ $uid ]['text'];
					$bytes         += strlen( $units[ $uid ]['text'] );
					if ( $units[ $uid ]['note'] !== '' ) {
						$notes[ $id ] = $units[ $uid ]['note'];
					}
				}
				if ( ! $this->quota->reserve( $bytes ) ) {
					$stop_reason = 'quota';
					break;
				}
				$visible   = AITP_Batcher::max_output_tokens( $payload, $ti, 0, $cap );
				$max_out   = AITP_Batcher::max_output_tokens( $payload, $ti, $reserve, $cap );
				$remaining = $deadline - $this->now();
				// Long answers take longer: 5 s more per 1000 expected output tokens (at most double).
				$timeout   = max( 5.0, min( (float) $this->opt['http_timeout'] + min( (float) $this->opt['http_timeout'], 5.0 * floor( $visible / 1000 ) ), $remaining ) );
				$user      = AITP_Protocol::user_payload( $payload, $si, $ti, $this->payload_extra( $uids, $units, $order, $pos, $texts, $ti, $notes ) );
				$req       = $this->provider->build_request( $system, $user, $max_out, $this->caps );
				$req['timeout']   = $timeout;
				$req['not_after'] = $deadline - (float) $this->opt['min_request_seconds'];
				$req['deadline']  = $deadline;
				$requests[ $bi ] = $req;
				$meta[ $bi ]     = array( 'ids' => $ids, 'bytes' => $bytes, 'caps' => $this->caps, 'max_out' => $max_out );
			}

			if ( ! $requests ) {
				break;
			}

			$results = $this->transport->send( $requests, (int) $this->opt['concurrency'] );
			$out['stats']['requests'] += count( $requests );

			$requeue           = array();
			$fatal             = '';
			$round_unsupported = 0;
			$round_split       = 0;
			$round_isolated    = 0;
			$skipped           = 0;
			$round_suspect     = 0;
			$round_clean       = 0;
			$bad_requests      = array();
			$refused           = array(); // bi => ids of batches the provider refused for their content.
			foreach ( $requests as $bi => $req ) {
				$ids   = $meta[ $bi ]['ids'];
				$bytes = $meta[ $bi ]['bytes'];
				$n     = count( $ids );
				$res   = isset( $results[ $bi ] ) ? $results[ $bi ] : array( 'status' => 0, 'headers' => array(), 'body' => '', 'error' => 'no result', 'error_type' => 'network', 'duration_ms' => 0 );
				$logd  = array(
					'round'       => $round,
					'target'      => $tgt,
					'items'       => $n,
					'bytes'       => $bytes,
					'status'      => (int) $res['status'],
					'duration_ms' => (int) $res['duration_ms'],
				);

				if ( ! empty( $res['error'] ) && $res['error_type'] === 'skipped' ) {
					// Not sent (the transport ran out of time before this request's turn).
					$this->quota->release( $bytes );
					$this->mark( $units, $ids, 'deferred', false, $requeue );
					$skipped++;
					continue;
				}
				$usage['requests']++;
				if ( ! empty( $res['error'] ) ) {
					$code = $res['error_type'] === 'timeout' ? 'timeout' : 'network';
					if ( $code === 'timeout' ) {
						$this->quota->commit( $bytes ); // May have been processed and billed.
						$out['stats']['sent_bytes'] += $bytes;
					} else {
						$this->quota->release( $bytes );
					}
					$this->breaker->failure( $code, null, $this->provider->redact( (string) $res['error'] ) );
					$this->log->log( 'warning', 'request', $logd + array( 'error' => $code, 'message' => $this->provider->redact( (string) $res['error'] ) ) );
					if ( $code === 'timeout' && $n > 1 ) {
						$this->split( $units, $ids, $requeue, $forced );
						$round_split++;
					} else {
						// A single entry that times out on its own is too slow for this provider:
						// it gets a per-item record and backs off instead of being resent every view.
						$this->mark( $units, $ids, $code === 'timeout' ? 'timeout_item' : $code, true, $requeue );
						$this->solo( $units, $ids );
					}
					continue;
				}

				$pr = $this->provider->parse_response( (int) $res['status'], (array) $res['headers'], (string) $res['body'], $meta[ $bi ]['caps'] );
				$logd['request_id'] = $pr['request_id'];
				$logd['usage']      = $pr['usage'];
				foreach ( array( 'input', 'output', 'thinking' ) as $uk ) {
					$usage[ $uk ] += isset( $pr['usage'][ $uk ] ) ? (int) $pr['usage'][ $uk ] : 0;
				}

				if ( ! $pr['ok'] ) {
					if ( $pr['unsupported'] !== null ) {
						if ( $meta[ $bi ]['caps'] !== $this->caps ) {
							// Another request of this round already changed the capabilities:
							// resend with the new ones instead of downgrading a second time.
							$this->quota->release( $bytes );
							$this->mark( $units, $ids, 'unsupported', false, $requeue );
							$round_unsupported++;
							continue;
						}
						$new = null;
						if ( $pr['unsupported'] === 'max_out' ) {
							if ( ! empty( $pr['max_out'] ) && ( empty( $this->caps['max_out'] ) || (int) $pr['max_out'] < (int) $this->caps['max_out'] ) ) {
								$new            = $this->caps;
								$new['max_out'] = (int) $pr['max_out'];
							}
						} else {
							$new = $this->provider->downgrade( $this->caps, $pr['unsupported'] );
						}
						if ( $new !== null ) {
							$this->caps = $new;
							$this->caps_store->set( $this->provider->fingerprint(), $new );
							$this->quota->release( $bytes );
							$this->log->log( 'notice', 'capability', $logd + array( 'feature' => $pr['unsupported'], 'message' => $pr['message'] ) );
							$this->mark( $units, $ids, 'unsupported', false, $requeue );
							$round_unsupported++;
							$downgrades++;
							continue;
						}
					}
					$this->quota->release( $bytes );
					if ( $pr['error'] === 'content_rejected' ) {
						// The provider refuses this text (moderation). Narrow it down; the
						// provider is fine and the other entries are translated.
						$this->log->log( 'warning', 'request', $logd + array( 'error' => $pr['error'], 'message' => $pr['message'] ) );
						$refused[ $bi ] = $ids;
						if ( $n > 1 ) {
							$this->split( $units, $ids, $requeue, $forced );
							$round_split++;
						} else {
							$this->give_up( $units, $ids, 'content_rejected' );
						}
						continue;
					}
					if ( $pr['error'] === 'bad_request' ) {
						// Judged after the round: one batch alone is narrowed down, two at once
						// mean the request itself is wrong.
						$bad_requests[ $bi ] = $pr;
						continue;
					}
					if ( $pr['error'] === 'too_large' && $n > 1 ) {
						// Too much in one request: halves are the cure, the provider is fine.
						$this->log->log( 'warning', 'request', $logd + array( 'error' => $pr['error'], 'message' => $pr['message'] ) );
						$this->split( $units, $ids, $requeue, $forced );
						$round_split++;
						continue;
					}
					$this->breaker->failure( $pr['error'], $pr['retry_after'], $pr['message'] );
					$this->log->log( 'error', 'request', $logd + array( 'error' => $pr['error'], 'message' => $pr['message'] ) );
					if ( in_array( $pr['error'], array( 'auth', 'billing', 'not_found', 'quota_daily', 'rate_limit' ), true ) ) {
						$fatal = $pr['error'];
						$this->mark( $units, $ids, $pr['error'], false, $requeue );
					} else {
						$this->mark( $units, $ids, (string) $pr['error'], true, $requeue );
					}
					continue;
				}

				$this->quota->commit( $bytes );
				$out['stats']['sent_bytes'] += $bytes;

				if ( $pr['finish'] !== 'stop' ) {
					$reason = $pr['finish'] === 'length' ? 'truncated' : ( $pr['finish'] === 'safety' ? 'blocked' : 'finish_other' );
					if ( $reason === 'truncated' ) {
						$this->learn_thinking( $pr['usage'], (int) $meta[ $bi ]['max_out'] );
					}
					if ( $reason === 'blocked' ) {
						$refused[ $bi ] = $ids; // Safety filter: about the text, like content_rejected.
					} else {
						// Cut off or stopped: billed, and slow (the whole budget was generated).
						// Counted for this language so a batch that keeps being cut off on every
						// page view is held instead of paid for again and again.
						$this->breaker->failure( 'bad_output', null, $reason, $tgt );
					}
					$this->log->log( 'warning', 'request', $logd + array( 'error' => $reason, 'message' => $pr['message'] ) );
					if ( $n > 1 ) {
						$this->split( $units, $ids, $requeue, $forced );
						$round_split++;
					} else {
						$this->mark( $units, $ids, $reason, true, $requeue );
						$this->solo( $units, $ids );
					}
					continue;
				}

				$parsed = AITP_Protocol::parse( $pr['text'], array_keys( $ids ) );
				if ( $parsed['status'] !== 'ok' ) {
					$this->breaker->failure( 'bad_output', null, $parsed['status'], $tgt );
					$this->log->log(
						'warning',
						'request',
						$logd + array(
							'error'     => $parsed['status'],
							'unknown'   => count( $parsed['unknown'] ),
							'duplicate' => count( $parsed['duplicate'] ),
						)
					);
					if ( $n > 1 ) {
						$this->split( $units, $ids, $requeue, $forced );
						$round_split++;
					} else {
						$this->mark( $units, $ids, $parsed['status'], true, $requeue );
						$this->solo( $units, $ids );
					}
					continue;
				}

				// Judge the response as a whole before accepting any entry from it: when entries look
				// shifted onto the wrong ids, even the ones that pass validation cannot be trusted.
				$checks = array();
				foreach ( $ids as $id => $uid ) {
					if ( ! in_array( (string) $id, $parsed['missing'], true ) ) {
						$checks[ $id ] = $this->validator->check( $units[ $uid ]['text'], $parsed['translations'][ $id ], $si, $ti );
					}
				}
				$suspect  = self::suspect_response( $ids, $units, $parsed, $checks );
				$repeated = $suspect === 'repeated_output' ? self::repeated_ids( $ids, $units, $parsed ) : array();
				if ( $repeated && count( $repeated ) <= max( 2, (int) ceil( $n / 4 ) ) ) {
					// Two different sources, one translation ("Product"/"Products" → 製品,
					// "Search"/"Search:" → "Buscar"): often simply correct. Each of them is asked
					// again on its own, where no misalignment is possible; the rest of the batch
					// is asked again too. Nothing is charged, the provider is not blamed.
					$rest = $ids;
					foreach ( $repeated as $id ) {
						$units[ $ids[ $id ] ]['solo'] = true;
						$forced[]                     = array( $ids[ $id ] );
						unset( $rest[ $id ] );
					}
					if ( $rest ) {
						$forced[] = array_values( $rest );
					}
					$this->log->log( 'notice', 'request', $logd + array( 'error' => 'repeated_output', 'repeated' => count( $repeated ) ) );
					$this->mark( $units, $ids, 'repeated_output', false, $requeue );
					$round_isolated++;
					continue;
				}
				if ( $suspect !== '' ) {
					$this->breaker->failure( 'bad_output', null, 'misaligned:' . $suspect, $tgt );
					$this->log->log(
						'warning',
						'request',
						$logd + array(
							'error'    => 'misaligned',
							'why'      => $suspect,
							'missing'  => count( $parsed['missing'] ),
							'rejected' => $n,
						)
					);
					foreach ( $ids as $uid ) {
						$units[ $uid ]['note'] = ''; // Hints derived from a misaligned answer would be wrong.
					}
					$this->mark( $units, $ids, 'misaligned', true, $requeue );
					$round_suspect++;
					continue;
				}
				// Half the batch or more handed back unchanged, at least two of them rejected for it,
				// and not entries already rejected for that earlier: the provider is not translating.
				// Nothing from such an answer is trusted, not even "confirmed" names.
				$echo = self::echo_counts( $ids, $units, $parsed, $checks );
				if ( $n >= 4 && $echo['hard_new'] >= 2 && $echo['new'] * 2 >= $n ) {
					$this->breaker->failure( 'bad_output', null, 'misaligned:echoed', $tgt );
					$this->log->log(
						'warning',
						'request',
						$logd + array(
							'error'    => 'misaligned',
							'why'      => 'echoed',
							'missing'  => 0,
							'rejected' => $n,
						)
					);
					foreach ( $ids as $uid ) {
						$units[ $uid ]['note'] = '';
					}
					$this->mark( $units, $ids, 'misaligned', true, $requeue );
					$round_suspect++;
					continue;
				}
				// Every entry of a sizeable batch rejected for its language: judged entry by
				// entry, but this language's answers count as unusable for the breaker.
				$lang_only = 0;
				foreach ( $checks as $v ) {
					if ( $v['hard'] && ! array_diff( $v['hard'], self::LANGUAGE_CODES ) ) {
						$lang_only++;
					}
				}
				if ( $n >= 4 && $lang_only === $n ) {
					$this->breaker->failure( 'bad_output', null, 'wrong_language', $tgt );
				} elseif ( $echo['again'] * 2 < $n ) {
					// A retry batch made mostly of entries echoed before (text that may well be in
					// the target language already) says nothing about the provider either way.
					$this->breaker->success( $tgt );
				}
				$round_clean++;

				$rejected = 0;
				foreach ( $ids as $id => $uid ) {
					if ( ! isset( $checks[ $id ] ) ) {
						$this->mark( $units, array( $id => $uid ), 'missing_id', true, $requeue );
						$rejected++;
						continue;
					}
					if ( ! $this->accept_or_retry( $units[ $uid ], $parsed['translations'][ $id ], $checks[ $id ], $ti ) ) {
						$requeue[ $uid ] = true;
						$rejected++;
					}
				}
				$this->log->log(
					'info',
					'request',
					$logd + array(
						'missing'  => count( $parsed['missing'] ),
						'rejected' => $rejected,
					)
				);
			}

			// HTTP 400 for one batch only: something in it (a text the provider refuses without
			// saying so) is the cause. Two separate batches failing at once: the request itself
			// is wrong (parameter, model), which needs the site owner.
			// Halves of one split batch are not independent evidence: only batches that were not
			// produced by splitting count towards "the request itself is wrong".
			$independent = 0;
			foreach ( $bad_requests as $bi => $pr ) {
				if ( ! isset( $split_bi[ $bi ] ) ) {
					$independent++;
				}
			}
			if ( $independent >= 2 ) {
				$first = reset( $bad_requests );
				$this->breaker->failure( 'bad_request', $first['retry_after'], $first['message'] ); // Once per round.
				foreach ( $bad_requests as $bi => $pr ) {
					$this->log->log( 'error', 'request', array( 'round' => $round, 'target' => $tgt, 'items' => count( $meta[ $bi ]['ids'] ), 'error' => 'bad_request', 'message' => $pr['message'], 'request_id' => $pr['request_id'] ) );
					$this->mark( $units, $meta[ $bi ]['ids'], 'bad_request', false, $requeue );
				}
				$fatal = 'bad_request';
			} else {
				foreach ( $bad_requests as $bi => $pr ) {
					$ids = $meta[ $bi ]['ids'];
					$this->log->log( 'warning', 'request', array( 'round' => $round, 'target' => $tgt, 'items' => count( $ids ), 'error' => 'bad_request', 'message' => $pr['message'], 'request_id' => $pr['request_id'] ) );
					$refused[ $bi ] = $ids;
					if ( count( $ids ) > 1 ) {
						$this->split( $units, $ids, $requeue, $forced );
						$round_split++;
					} else {
						$this->give_up( $units, $ids, 'provider_rejected' );
					}
				}
			}

			// The provider refused every request of this round (at least four) for its content:
			// it refuses this whole language or everything, not a single text. Narrowing down
			// further would cost about two requests per entry; give the entries up (per-item
			// backoff), hold this language and stop.
			if ( count( $refused ) >= 4 && count( $refused ) === count( $requests ) - $skipped ) {
				foreach ( $refused as $ids ) {
					$this->give_up( $units, $ids, 'content_rejected' );
				}
				$forced = array();
				$this->breaker->failure( 'bad_output', null, 'refused', $tgt );
				$stop_reason = 'refused';
				break;
			}

			if ( $fatal !== '' ) {
				$stop_reason = $fatal;
				break;
			}
			if ( $stop_reason === 'quota' ) {
				break;
			}
			if ( $skipped ) {
				$out['stats']['requests'] -= $skipped;
				$stop_reason = 'deferred';
				break;
			}
			// Several misaligned answers and not one usable answer: the model is not following
			// the protocol right now. Stop here instead of paying for more rounds.
			if ( $round_suspect >= 2 && $round_clean === 0 ) {
				$stop_reason = 'misaligned';
				break;
			}
			// A round spent only on capabilities, splitting or isolating does not count.
			if ( ! $extra && $round_unsupported + $round_split + $round_isolated === count( $requests ) && $downgrades <= 6 ) {
				$round--;
			}

			$queue = array();
			foreach ( array_keys( $requeue ) as $uid ) {
				if ( $units[ $uid ]['done'] || $units[ $uid ]['dead'] ) {
					continue;
				}
				if ( $units[ $uid ]['attempts'] >= (int) $this->opt['max_attempts'] ) {
					$units[ $uid ]['dead'] = true;
					continue;
				}
				$queue[] = $uid;
			}
			// Keep original order for context continuity.
			$order_index = array_flip( array_keys( $units ) );
			usort(
				$queue,
				function ( $a, $b ) use ( $order_index ) {
					return $order_index[ $a ] - $order_index[ $b ];
				}
			);
		}
		if ( $usage['requests'] ) {
			$this->usage->add( $tgt, $usage );
		}

		// Units that never finished: fall back to a soft-flagged candidate if one exists.
		foreach ( $units as $uid => &$u ) {
			if ( $u['done'] ) {
				continue;
			}
			if ( $u['soft_candidate'] !== null ) {
				$u['done']   = true;
				$u['result'] = $u['soft_candidate'];
				$u['flags']  = $u['soft_flags'];
				continue;
			}
			if ( $u['reason'] === '' || ( ! $u['dead'] && $stop_reason !== '' ) ) {
				$u['reason'] = $stop_reason !== '' ? $stop_reason : ( $u['reason'] !== '' ? $u['reason'] : 'deferred' );
			}
		}
		unset( $u );

		// 4. Assemble items.
		foreach ( $pending as $key => $text ) {
			$uids   = $item_units[ $key ];
			$parts  = array();
			$flags  = array();
			$reason = '';
			foreach ( $uids as $uid ) {
				if ( ! $units[ $uid ]['done'] ) {
					$reason = $units[ $uid ]['reason'];
					break;
				}
				$parts[] = $units[ $uid ]['result'];
				$flags   = array_merge( $flags, $units[ $uid ]['flags'] );
			}

			$fkey = self::failure_key( $text, $src, $tgt );
			if ( $reason === '' ) {
				$joined = count( $parts ) === 1 ? $splits[ $key ]['lead'] . $parts[0] . $splits[ $key ]['parts'][0]['sep'] : AITP_Segmenter::join( $parts, $splits[ $key ], $ti['script'] );
				if ( count( $parts ) > 1 ) {
					$v = $this->validator->check( $text, $joined, $si, $ti );
					if ( $v['hard'] ) {
						$reason = 'assembled_' . $v['hard'][0];
					} else {
						$flags = array_merge( $flags, array_diff( $v['soft'], array( AITP_Validator::CONFIRM_IDENTICAL ) ) );
					}
				}
			}

			if ( $reason !== '' ) {
				$out['failed'][ $key ] = $reason;
				if ( ! in_array( $reason, self::SYSTEMIC, true ) ) {
					$this->failures->failed(
						$fkey,
						array(
							'source_locale' => $src,
							'target_locale' => $tgt,
							'reason'        => $reason,
							'excerpt'       => self::excerpt( $text ),
						)
					);
				}
				continue;
			}

			$flags = array_values( array_unique( $flags ) );
			if ( $flags ) {
				$this->review->add(
					array(
						'source_locale' => $src,
						'target_locale' => $tgt,
						'original'      => $text,
						'translation'   => $joined,
						'flags'         => $flags,
						'saved'         => $this->opt['soft_policy'] !== 'strict',
					)
				);
				if ( $this->opt['soft_policy'] === 'strict' ) {
					$out['failed'][ $key ] = 'review_' . $flags[0];
					continue;
				}
				$out['flagged'][ $key ] = $flags;
			}

			$out['translations'][ $key ] = $joined;
			$this->failures->clear( $fkey );
			if ( isset( $ckeys[ $key ] ) ) {
				$this->cache->set( $ckeys[ $key ], $tgt, $joined );
			}
		}

		$out['stats']['stop_reason'] = $stop_reason;
		$out['stats']['duration_ms'] = (int) round( ( $this->now() - $started ) * 1000 );
		$this->log->log(
			$out['failed'] ? 'notice' : 'info',
			'call',
			array(
				'target'      => $tgt,
				'items'       => count( $items ),
				'translated'  => count( $out['translations'] ),
				'failed'      => count( $out['failed'] ),
				'flagged'     => count( $out['flagged'] ),
				'cached'      => $out['stats']['cached'],
				'held'        => $out['stats']['held'],
				'requests'    => $out['stats']['requests'],
				'rounds'      => $out['stats']['rounds'],
				'sent_bytes'  => $out['stats']['sent_bytes'],
				'stop_reason' => $stop_reason,
				'duration_ms' => $out['stats']['duration_ms'],
				'reasons'     => array_count_values( array_values( $out['failed'] ) ),
			)
		);

		return $out;
	}

	/**
	 * Validate a candidate; accept it, or prepare a retry with a note.
	 *
	 * @param array  $u    Unit (by reference).
	 * @param string $cand Candidate translation.
	 * @param array  $v    Validator result for the candidate.
	 * @param array  $ti   Target info.
	 * @return bool True when the unit is done.
	 */
	private function accept_or_retry( array &$u, $cand, array $v, array $ti ) {
		$soft = $v['soft'];

		if ( $v['hard'] ) {
			$u['attempts']++;
			$u['reason'] = $v['hard'][0];
			if ( self::is_echo( $u['text'], $cand ) ) {
				$u['echoed'] = true;
			}
			$notes       = array();
			foreach ( $v['hard'] as $code ) {
				$notes[] = AITP_Validator::hint( $code, $v['details'], $ti );
			}
			$u['note'] = implode( ' ', array_unique( $notes ) );
			return false;
		}

		if ( in_array( AITP_Validator::CONFIRM_IDENTICAL, $soft, true ) && ( $u['confirm_asked'] || ! $this->opt['soft_retry'] ) ) {
			$soft = array_values( array_diff( $soft, array( AITP_Validator::CONFIRM_IDENTICAL ) ) );
		}

		if ( $soft && $this->opt['soft_retry'] && ! $u['soft_retried'] ) {
			$u['soft_retried']   = true;
			$u['attempts']++;
			$u['reason']         = $soft[0];
			$review_flags        = array_values( array_diff( $soft, array( AITP_Validator::CONFIRM_IDENTICAL ) ) );
			// Text left identical to the source is only kept once the model confirms it on retry;
			// it is never the fallback when that retry does not happen.
			$u['soft_candidate'] = in_array( AITP_Validator::CONFIRM_IDENTICAL, $soft, true ) ? null : $cand;
			$u['soft_flags']     = $review_flags;
			if ( in_array( AITP_Validator::CONFIRM_IDENTICAL, $soft, true ) ) {
				$u['confirm_asked'] = true;
			}
			$notes = array();
			foreach ( $soft as $code ) {
				$notes[] = AITP_Validator::hint( $code, $v['details'], $ti );
			}
			$u['note'] = implode( ' ', array_unique( $notes ) );
			return false;
		}

		$u['done']   = true;
		$u['result'] = $cand;
		$u['flags']  = array_values( array_diff( $soft, array( AITP_Validator::CONFIRM_IDENTICAL ) ) );
		$u['note']   = '';
		return true;
	}

	/**
	 * Whether a structurally valid response looks misaligned (entries attached to the wrong
	 * ids). Returns the reason, or '' when the response can be judged entry by entry.
	 *
	 *  - missing_id       an entry is missing: the others may have moved up one place
	 *  - repeated_output  two different sources received the same text
	 *  - many_rejected    many entries fail validation at once
	 *
	 * @param array $ids    id => uid.
	 * @param array $units  Units.
	 * @param array $parsed Parsed response.
	 * @param array $checks id => validator result (entries present in the response).
	 * @return string
	 */
	private static function suspect_response( array $ids, array $units, array $parsed, array $checks ) {
		$n = count( $ids );
		if ( $n < 2 ) {
			return '';
		}
		if ( $parsed['missing'] ) {
			return 'missing_id';
		}
		$hard = 0;
		foreach ( $checks as $id => $v ) {
			$cand = $parsed['translations'][ $id ];
			$src  = $units[ $ids[ $id ] ]['text'];
			// An entry returned unchanged sits on its own id, so it is no sign of misalignment:
			// it fails on its own (e.g. text that was already in the target language) and
			// must not take the rest of the batch or the whole engine down with it. Neither is
			// a rejection only for the language of the answer (see LANGUAGE_CODES).
			if ( $v['hard'] && ! self::is_echo( $src, $cand ) && array_diff( $v['hard'], self::LANGUAGE_CODES ) ) {
				$hard++;
			}
		}
		if ( $hard >= max( 2, (int) ceil( 0.2 * $n ) ) ) {
			return 'many_rejected';
		}
		if ( self::repeated_ids( $ids, $units, $parsed ) ) {
			return 'repeated_output';
		}
		return '';
	}

	/**
	 * Ids of entries whose translation equals the translation of an entry with a different
	 * source.
	 *
	 * @param array $ids    id => uid.
	 * @param array $units  Units.
	 * @param array $parsed Parsed response.
	 * @return string[]
	 */
	private static function repeated_ids( array $ids, array $units, array $parsed ) {
		$by = array();
		foreach ( $parsed['translations'] as $id => $cand ) {
			if ( ! isset( $ids[ $id ] ) ) {
				continue;
			}
			$t = self::fold( $cand );
			if ( $t === '' ) {
				continue;
			}
			$by[ $t ][ $id ] = self::fold( $units[ $ids[ $id ] ]['text'] );
		}
		$out = array();
		foreach ( $by as $group ) {
			if ( count( array_unique( $group ) ) > 1 ) {
				foreach ( array_keys( $group ) as $id ) {
					$out[] = (string) $id;
				}
			}
		}
		return $out;
	}

	/**
	 * Requeue a batch that failed as a whole as two halves for the next pass, without
	 * charging an attempt to its entries.
	 *
	 * @param array $units   Units (by reference).
	 * @param array $ids     id => uid.
	 * @param array $requeue uid => true (by reference).
	 * @param array $forced  Groups for the next pass (by reference).
	 */
	private function split( array &$units, array $ids, array &$requeue, array &$forced ) {
		$this->mark( $units, $ids, 'split', false, $requeue );
		$uids     = array_values( $ids );
		$half     = (int) ceil( count( $uids ) / 2 );
		$forced[] = array_slice( $uids, 0, $half );
		$forced[] = array_slice( $uids, $half );
	}

	/**
	 * An entry that failed on its own is sent on its own next time, so it cannot make a
	 * batch of other entries fail again.
	 *
	 * @param array $units Units (by reference).
	 * @param array $ids   id => uid.
	 */
	private function solo( array &$units, array $ids ) {
		foreach ( $ids as $uid ) {
			$units[ $uid ]['solo'] = true;
		}
	}

	/**
	 * Stop trying an entry in this call (the provider refuses this very text); it gets a
	 * per-item record and backs off.
	 *
	 * @param array  $units  Units (by reference).
	 * @param array  $ids    id => uid.
	 * @param string $reason Reason.
	 */
	private function give_up( array &$units, array $ids, $reason ) {
		foreach ( $ids as $uid ) {
			if ( ! $units[ $uid ]['done'] ) {
				$units[ $uid ]['reason'] = $reason;
				$units[ $uid ]['dead']   = true;
			}
		}
	}

	/**
	 * A cut-off answer where reasoning took a large share of the output budget: the model
	 * thinks even when asked not to. Remember it so later requests reserve room for it.
	 *
	 * @param array $usage   Usage of the response.
	 * @param int   $max_out Output budget of the request.
	 */
	private function learn_thinking( array $usage, $max_out ) {
		$thinking = isset( $usage['thinking'] ) ? (int) $usage['thinking'] : 0;
		if ( ! empty( $this->caps['thinks'] ) || $thinking <= 0 || $thinking < 0.3 * max( 1, $max_out ) ) {
			return;
		}
		$this->caps['thinks'] = true;
		$this->caps_store->set( $this->provider->fingerprint(), $this->caps );
		$this->log->log( 'notice', 'capability', array( 'feature' => 'thinks', 'thinking' => $thinking, 'max_out' => $max_out ) );
	}

	/**
	 * Translation of code-like interface strings that must not go to a model, or null.
	 *
	 * @param string $text Source.
	 * @param array  $ti   Target info.
	 * @return string|null
	 */
	public static function fixed_translation( $text, array $ti ) {
		$t = trim( (string) $text );
		if ( $t === 'ltr' || $t === 'rtl' ) {
			return in_array( $ti['script'], array( 'Arab', 'Hebr', 'Thaa', 'Syrc' ), true ) ? 'rtl' : 'ltr';
		}
		if ( AITP_Text::is_date_format( $t ) ) {
			return (string) $text;
		}
		return null;
	}

	/**
	 * Entries returned unchanged: rejected for it, or waiting for the model to confirm them.
	 * Text recognised as already in the target language is not counted. Entries rejected
	 * for an unchanged answer before ("again") are counted apart: echoing those again is a
	 * property of the text, not of the provider.
	 *
	 * @param array $ids    id => uid.
	 * @param array $units  Units.
	 * @param array $parsed Parsed response.
	 * @param array $checks id => validator result.
	 * @return array{new:int,hard_new:int,again:int}
	 */
	private static function echo_counts( array $ids, array $units, array $parsed, array $checks ) {
		$out = array( 'new' => 0, 'hard_new' => 0, 'again' => 0 );
		foreach ( $checks as $id => $v ) {
			$u = $units[ $ids[ $id ] ];
			if ( in_array( AITP_Validator::ALREADY_TARGET, $v['soft'], true ) || ! self::is_echo( $u['text'], $parsed['translations'][ $id ] ) ) {
				continue;
			}
			if ( ! empty( $u['echoed'] ) ) {
				$out['again']++;
			} elseif ( $v['hard'] ) {
				$out['new']++;
				$out['hard_new']++;
			} elseif ( in_array( AITP_Validator::CONFIRM_IDENTICAL, $v['soft'], true ) ) {
				$out['new']++;
			}
		}
		return $out;
	}

	/**
	 * Whether a candidate is the source returned unchanged.
	 *
	 * @param string $src  Source.
	 * @param mixed  $cand Candidate.
	 * @return bool
	 */
	private static function is_echo( $src, $cand ) {
		return is_string( $cand ) && trim( $cand ) !== '' && self::fold( $src ) === self::fold( $cand );
	}

	/**
	 * Case-, spacing- and entity-insensitive form used to compare texts
	 * ("TR90 &amp; TPEE" and "TR90 & TPEE" are the same text).
	 *
	 * @param string $s Text.
	 * @return string
	 */
	private static function fold( $s ) {
		return AITP_Text::fold( $s );
	}

	/**
	 * Requeue units of a batch with a reason.
	 *
	 * @param array  $units      Units (by reference).
	 * @param array  $ids        id => uid.
	 * @param string $reason     Reason.
	 * @param bool   $count      Whether this counts as an attempt.
	 * @param array  $requeue    uid => true (by reference).
	 */
	private function mark( array &$units, array $ids, $reason, $count, array &$requeue ) {
		foreach ( $ids as $uid ) {
			if ( $units[ $uid ]['done'] ) {
				continue;
			}
			if ( $count ) {
				$units[ $uid ]['attempts']++;
			}
			$units[ $uid ]['reason'] = $reason;
			$requeue[ $uid ]         = true;
		}
	}

	/**
	 * Context, protected terms and glossary for a batch.
	 *
	 * @return array
	 */
	private function payload_extra( array $uids, array $units, array $order, array $pos, array $texts, array $ti, array $notes ) {
		$in_batch = array();
		$joined   = '';
		foreach ( $uids as $uid ) {
			$in_batch[ $units[ $uid ]['key'] ] = true;
			$joined                           .= "\n" . $units[ $uid ]['text'];
		}
		$first = $units[ $uids[0] ];
		$last  = $units[ $uids[ count( $uids ) - 1 ] ];
		$n     = (int) $this->opt['context_items'];

		$before = array();
		if ( $first['part'] > 0 ) {
			$before[] = self::tail( $this->unit_text_of( $units, $first['key'], $first['part'] - 1 ), (int) $this->opt['context_chars'] );
		}
		$p = $pos[ (string) $first['key'] ];
		for ( $i = max( 0, $p - $n ); $i < $p; $i++ ) {
			$k = (string) $order[ $i ];
			if ( ! isset( $in_batch[ $k ] ) ) {
				$before[] = self::excerpt( $texts[ $k ], (int) $this->opt['context_chars'] );
			}
		}
		$after = array();
		$q     = $pos[ (string) $last['key'] ];
		for ( $i = $q + 1, $max = min( count( $order ), $q + 1 + $n ); $i < $max; $i++ ) {
			$k = (string) $order[ $i ];
			if ( ! isset( $in_batch[ $k ] ) ) {
				$after[] = self::excerpt( $texts[ $k ], (int) $this->opt['context_chars'] );
			}
		}

		$terms = array();
		foreach ( $this->rules->protected_terms as $t ) {
			if ( trim( $t ) !== '' && AITP_Text::count_term( $joined, $t ) > 0 ) {
				$terms[] = $t;
			}
		}
		$glossary = array();
		foreach ( $this->rules->glossary_for( $joined, $ti ) as $rule ) {
			$glossary[ $rule['term'] ] = array(
				'source' => $rule['term'],
				'use'    => $rule['require'],
				'avoid'  => $rule['forbid'],
			);
		}

		return array(
			'site_context'    => self::excerpt( $this->rules->site_context, 1500 ),
			'protected_terms' => $terms,
			'glossary'        => array_values( $glossary ),
			'context_before'  => $before,
			'context_after'   => $after,
			'notes'           => $notes,
		);
	}

	/**
	 * @return string
	 */
	private function unit_text_of( array $units, $key, $part ) {
		foreach ( $units as $u ) {
			if ( $u['key'] === $key && $u['part'] === $part ) {
				return $u['text'];
			}
		}
		return '';
	}

	/**
	 * Signature of the immediate neighbours of an item (part of the cache key).
	 *
	 * @return string
	 */
	private function context_signature( $key, array $order, array $texts ) {
		$pos  = array_search( $key, array_map( 'strval', $order ), true );
		$prev = ( $pos !== false && $pos > 0 ) ? $texts[ (string) $order[ $pos - 1 ] ] : '';
		$next = ( $pos !== false && $pos < count( $order ) - 1 ) ? $texts[ (string) $order[ $pos + 1 ] ] : '';
		return md5( $prev . "\x1f" . $next );
	}

	/**
	 * @param string $s   Text.
	 * @param int    $max Characters.
	 * @return string
	 */
	public static function excerpt( $s, $max = 200 ) {
		$s = trim( (string) $s );
		return AITP_Text::len( $s ) > $max ? ( function_exists( 'mb_substr' ) ? mb_substr( $s, 0, $max, 'UTF-8' ) : substr( $s, 0, $max ) ) . '…' : $s;
	}

	/**
	 * @param string $s   Text.
	 * @param int    $max Characters.
	 * @return string
	 */
	private static function tail( $s, $max ) {
		$s = trim( (string) $s );
		return AITP_Text::len( $s ) > $max ? '…' . ( function_exists( 'mb_substr' ) ? mb_substr( $s, -$max, null, 'UTF-8' ) : substr( $s, -$max ) ) : $s;
	}

	/** @return array */
	public function caps() {
		return $this->caps;
	}
}

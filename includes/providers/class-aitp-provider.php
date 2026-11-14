<?php
/**
 * Base class for model providers. A provider only builds HTTP requests and interprets
 * HTTP responses; it never touches TranslatePress or the database.
 *
 * @package AI_Translator_For_TranslatePress
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class AITP_Provider {

	/** @var array */
	protected $config;

	/**
	 * @param array $config base_url, api_key, model, thinking (auto|low|off), structured (auto|schema|json|off),
	 *                      temperature (null|float), max_output_cap (int), site_url (string).
	 */
	public function __construct( array $config ) {
		$this->config = array_merge(
			array(
				'base_url'       => '',
				'api_key'        => '',
				'model'          => '',
				'thinking'       => 'auto',
				'structured'     => 'auto',
				'temperature'    => null,
				'max_output_cap' => 32768,
				'site_url'       => '',
			),
			$config
		);
	}

	/** @return string */
	abstract public function id();

	/** @return string */
	abstract public function default_base_url();

	/**
	 * Default capabilities before any downgrade has been learned.
	 *
	 * @return array<string,bool>
	 */
	abstract public function default_caps();

	/**
	 * @param string $system System instruction.
	 * @param string $user   User payload (JSON).
	 * @param int    $max_out Output token limit.
	 * @param array  $caps   Capabilities.
	 * @return array{url:string,headers:array<string,string>,body:string}
	 */
	abstract public function build_request( $system, $user, $max_out, array $caps );

	/**
	 * @param int    $status  HTTP status.
	 * @param array  $headers Lower-cased response headers.
	 * @param string $body    Response body.
	 * @param array  $caps    Capabilities used for the request.
	 * @return array{ok:bool,text:string,finish:string,error:?string,unsupported:?string,retry_after:?int,request_id:string,usage:array,message:string}
	 */
	abstract public function parse_response( $status, array $headers, $body, array $caps = array() );

	/**
	 * Switch off (or toggle) a capability after the endpoint rejected it.
	 *
	 * @param array  $caps    Caps.
	 * @param string $feature Feature reported by detect_unsupported().
	 * @return array|null New caps, or null when nothing can be downgraded.
	 */
	abstract public function downgrade( array $caps, $feature );

	/**
	 * Extra output tokens to reserve for reasoning ("thinking") with the given caps.
	 *
	 * @param array $caps Capabilities.
	 * @return int
	 */
	public function thinking_reserve( array $caps ) {
		return 0;
	}

	/** @return string */
	public function base_url() {
		$url = trim( (string) $this->config['base_url'] );
		return rtrim( $url !== '' ? $url : $this->default_base_url(), '/' );
	}

	/** @return string */
	public function model() {
		return trim( (string) $this->config['model'] );
	}

	/**
	 * Identifies provider + endpoint + model for capability memory and cache keys.
	 *
	 * @return string
	 */
	public function fingerprint() {
		$host = parse_url( $this->base_url(), PHP_URL_HOST ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
		return $this->id() . '|' . strtolower( (string) $host ) . '|' . $this->model();
	}

	/**
	 * Remove the API key (and anything that looks like a credential) from a string.
	 *
	 * @param string $s Input.
	 * @return string
	 */
	public function redact( $s ) {
		$s   = (string) $s;
		$key = (string) $this->config['api_key'];
		if ( strlen( $key ) >= 6 ) {
			$s = str_replace( $key, '[redacted]', $s );
		}
		$s = preg_replace( '~([?&](?:key|api_key|apikey|access_token|token)=)[^&\s"\']+~i', '$1[redacted]', $s );
		$s = preg_replace( '~(Bearer\s+)[A-Za-z0-9._\-]+~i', '$1[redacted]', $s );
		$s = preg_replace( '~\b(sk-|AIza)[A-Za-z0-9_\-]{8,}~', '[redacted]', $s );
		return (string) $s;
	}

	/**
	 * Short, redacted message for logs.
	 *
	 * @param string $s Input.
	 * @return string
	 */
	protected function short( $s ) {
		$s = $this->redact( trim( preg_replace( '~\s+~', ' ', (string) $s ) ) );
		return strlen( $s ) > 300 ? substr( $s, 0, 300 ) . '…' : $s;
	}

	/** Text of a real billing problem (no money or credit left), as opposed to a rate limit. */
	const BILLING_HARD = '~insufficient[_ ]quota|insufficient[_ ]balance|insufficient[_ ]funds|insufficient[_ ]credit|credit balance is too low|billing hard limit|account (?:is )?(?:not active|deactivated|suspended)|arrear|欠费|余额不足|账户余额|limit:\s*0\b~u';

	/** Content moderation: the provider refuses this text. Only this item is affected. */
	const MODERATION = '~content exists risk|content[_ ]filter|content[_ ]management[_ ]policy|data[_ ]inspection[_ ]failed|inappropriate content|unsafe content|sensitive content|\b1301\b|敏感|不安全|违规|high risk~u';

	/** The model name is wrong or unavailable (also the wording relays and DeepSeek use). */
	const MODEL_MISSING = '~model[^.\n]{0,40}(?:not exist|does not exist|not found|not available|is invalid)|invalid model|unknown model|model_not_found|no available channel|无可用渠道|模型不存在~u';

	/**
	 * Map an HTTP failure to an error category.
	 *
	 * auth / billing / not_found pause the engine until fixed; quota_daily and rate_limit open
	 * the circuit for a while; server is transient. content_rejected concerns one text only.
	 * bad_request is judged by the translator: one batch alone is narrowed down item by item,
	 * two separate batches failing at once means the request itself is wrong.
	 *
	 * Many providers word an ordinary rate limit like a billing problem (Gemini: "You exceeded
	 * your current quota, please check your plan and billing details"; free tiers point to a
	 * payment page). A 429 is therefore a rate limit unless it says plainly that no money or
	 * credit is left.
	 *
	 * @param int    $status HTTP status.
	 * @param string $body   Body or error message.
	 * @return string
	 */
	public static function classify_error( $status, $body ) {
		$b       = function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $body, 'UTF-8' ) : strtolower( (string) $body );
		$billing = '~insufficient[_ ]quota|insufficient[_ ]balance|insufficient[_ ]funds|billing|credit|balance|payment|arrear|欠费|余额不足~u';
		if ( $status === 402 ) {
			return 'billing';
		}
		if ( $status === 401 ) {
			return 'auth';
		}
		if ( $status === 403 ) {
			return preg_match( $billing, $b ) ? 'billing' : 'auth';
		}
		if ( $status === 404 ) {
			return 'not_found';
		}
		if ( $status === 413 ) {
			return 'too_large';
		}
		if ( $status === 429 ) {
			if ( preg_match( self::BILLING_HARD, $b ) ) {
				return 'billing';
			}
			if ( preg_match( '~perday|per day|per_day|daily|每日|每天|当日~u', $b ) ) {
				return 'quota_daily';
			}
			return 'rate_limit';
		}
		if ( $status === 400 || $status === 422 || $status === 451 ) {
			if ( preg_match( self::MODERATION, $b ) ) {
				return 'content_rejected';
			}
			if ( preg_match( '~api[_ ]?key[_ ]?(?:invalid|not valid|expired)|invalid[_ ]api[_ ]?key|incorrect api key|unauthori[sz]ed|permission[_ ]denied~', $b ) ) {
				return 'auth';
			}
			if ( preg_match( self::MODEL_MISSING, $b ) ) {
				return 'not_found';
			}
			if ( preg_match( $billing, $b ) ) {
				return 'billing';
			}
			if ( preg_match( '~context length|context_length|too long|maximum context|token limit|too many tokens|request too large~', $b ) && self::output_limit( $b ) === null ) {
				return 'too_large';
			}
			return 'bad_request';
		}
		if ( $status === 0 ) {
			return 'network';
		}
		// On 5xx relays also say "no available channel" for short outages: that stays a server
		// error (retried soon); only a model that does not exist pauses the engine.
		if ( ( $status === 503 || $status === 500 ) && preg_match( self::MODEL_MISSING, $b ) && ! preg_match( '~no available channel|无可用渠道~u', $b ) ) {
			return 'not_found';
		}
		return 'server';
	}

	/**
	 * Output token limit stated in an error ("supports at most 4096 completion tokens",
	 * "range of max_tokens is [1, 8192]").
	 *
	 * @param string $body Error text.
	 * @return int|null
	 */
	public static function output_limit( $body ) {
		$b = strtolower( (string) $body );
		if ( ! preg_match( '~max_?(?:completion_|output_|new_)?tokens|completion tokens|output tokens|maxoutputtokens~', $b ) ) {
			return null;
		}
		$patterns = array(
			'~at most (\d{3,7})~',
			'~range of [a-z_ ]*is \[\s*\d+\s*,\s*(\d{3,7})\s*\]~',
			'~\[\s*1\s*,\s*(\d{3,7})\s*\]~',
			'~(?:less than or equal to|<=|not exceed|maximum (?:value )?(?:is|of)|max(?:imum)? allowed(?: value)? (?:is|of)?)\s*(\d{3,7})~',
		);
		foreach ( $patterns as $p ) {
			if ( preg_match( $p, $b, $m ) ) {
				return (int) $m[1];
			}
		}
		return null;
	}

	/**
	 * Detect a 400 caused by an optional request feature the endpoint does not support.
	 *
	 * @param string $body Body.
	 * @param array  $caps Current caps.
	 * @return string|null Capability name to switch off / toggle.
	 */
	public static function detect_unsupported( $body, array $caps ) {
		$b = strtolower( (string) $body );
		if ( ! empty( $caps['schema'] ) && preg_match( '~response_?schema|json_schema|response_format|responsejsonschema|structured output~', $b ) ) {
			return 'schema';
		}
		if ( ! empty( $caps['json'] ) && preg_match( '~response_?mime_?type|json_object|response_format~', $b ) ) {
			return 'json';
		}
		if ( ! empty( $caps['thinking'] ) && preg_match( '~thinking|reasoning~', $b ) ) {
			return 'thinking';
		}
		// A value above the model's output limit is not an unknown parameter name.
		if ( self::output_limit( $b ) !== null ) {
			return 'max_out';
		}
		if ( preg_match( '~max_completion_tokens|max_tokens~', $b ) ) {
			return 'max_completion_tokens';
		}
		if ( ! empty( $caps['temperature'] ) && preg_match( '~temperature~', $b ) ) {
			return 'temperature';
		}
		return null;
	}

	/**
	 * Parse a Retry-After header or a "30s" style delay.
	 *
	 * @param mixed $v Value.
	 * @return int|null
	 */
	protected static function seconds( $v ) {
		if ( is_array( $v ) ) {
			$v = reset( $v );
		}
		if ( is_numeric( $v ) ) {
			return min( 86400, max( 0, (int) ceil( (float) $v ) ) );
		}
		if ( is_string( $v ) && preg_match( '~^(\d+(?:\.\d+)?)s$~', trim( $v ), $m ) ) {
			return min( 86400, (int) ceil( (float) $m[1] ) );
		}
		if ( is_string( $v ) && trim( $v ) !== '' && preg_match( '~[A-Za-z]{3},? \d~', $v ) ) {
			$t = strtotime( $v ); // HTTP date.
			if ( $t ) {
				return min( 86400, max( 0, $t - time() ) ); // A date far ahead must not stop the engine for weeks.
			}
		}
		return null;
	}

	/**
	 * @param array  $headers Lower-cased headers.
	 * @param string $name    Header.
	 * @return string
	 */
	protected static function header( array $headers, $name ) {
		if ( ! isset( $headers[ $name ] ) ) {
			return '';
		}
		$v = $headers[ $name ];
		return is_array( $v ) ? (string) reset( $v ) : (string) $v;
	}

	/**
	 * @return array
	 */
	protected function base_result() {
		return array(
			'ok'          => false,
			'text'        => '',
			'finish'      => 'other',
			'error'       => null,
			'unsupported' => null,
			'retry_after' => null,
			'request_id'  => '',
			'usage'       => array(),
			'message'     => '',
			'max_out'     => null,
		);
	}

	/**
	 * Build an error result for a non-200 response.
	 *
	 * @param int    $status  Status.
	 * @param array  $headers Headers.
	 * @param string $body    Body.
	 * @param string $message Extracted error message.
	 * @param array  $caps    Caps used.
	 * @return array
	 */
	protected function error_result( $status, array $headers, $body, $message, array $caps = array() ) {
		$r                = $this->base_result();
		$r['error']       = self::classify_error( (int) $status, $message . ' ' . $body );
		$r['retry_after'] = self::seconds( self::header( $headers, 'retry-after' ) );
		$r['request_id']  = self::header( $headers, 'x-request-id' );
		$r['message']     = $this->short( 'HTTP ' . $status . ': ' . ( $message !== '' ? $message : $body ) );
		if ( in_array( (int) $status, array( 400, 422 ), true ) && $r['error'] === 'bad_request' ) {
			$r['unsupported'] = self::detect_unsupported( $message . ' ' . $body, $caps );
			if ( $r['unsupported'] === 'max_out' ) {
				$r['max_out'] = self::output_limit( $message . ' ' . $body );
			}
		}
		return $r;
	}
}

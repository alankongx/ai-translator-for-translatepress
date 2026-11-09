<?php
/**
 * Plugin settings (option "aitp_settings"), API key storage and builders for the core objects.
 *
 * @package AI_Translator_For_TranslatePress
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AITP_Settings {

	const OPTION = 'aitp_settings';

	/** @var array|null */
	private static $cache = null;

	/** @return array */
	public static function defaults() {
		return array(
			'provider'           => 'gemini',
			'preset'             => 'openai',
			'base_url'           => '',
			'model'              => 'gemini-3.5-flash',
			'api_key_enc'        => '',
			'thinking'           => 'off',
			'structured'         => 'auto',
			'temperature'        => '',
			'protected_terms'    => '',
			'protected_patterns' => '',
			'glossary'           => '',
			'site_context'       => '',
			'code_guard'         => 1,
			'size_guard'         => 1,
			'claim_checks'       => 1,
			'soft_policy'        => 'save_review',
			'time_budget'        => 25,
			'chunk_size'         => 150,
			'batch_items'        => 40,
			'batch_tokens'       => 2500,
			'concurrency'        => 3,
			'http_timeout'       => 30,
			'log_days'           => 14,
		);
	}

	/** @return array */
	public static function get() {
		if ( self::$cache === null ) {
			$v           = get_option( self::OPTION, array() );
			self::$cache = array_merge( self::defaults(), is_array( $v ) ? $v : array() );
		}
		return self::$cache;
	}

	/** Forget the in-memory copy (after saving). */
	public static function flush() {
		self::$cache = null;
	}

	/* -------------------------------------------------------------------------------------
	 * API key: wp-config constant, or stored encrypted with the site's secret keys.
	 * ----------------------------------------------------------------------------------- */

	/** @return bool */
	public static function key_from_constant() {
		return defined( 'AITP_API_KEY' ) && is_string( AITP_API_KEY ) && AITP_API_KEY !== '';
	}

	/** @return string */
	public static function api_key() {
		if ( self::key_from_constant() ) {
			return (string) AITP_API_KEY;
		}
		$s = self::get();
		return self::decrypt( (string) $s['api_key_enc'] );
	}

	/** @return bool True when a key is stored but cannot be decrypted (secret keys changed). */
	public static function key_unreadable() {
		$s = self::get();
		return ! self::key_from_constant() && $s['api_key_enc'] !== '' && self::api_key() === '';
	}

	/** @return string Last 4 characters for display. */
	public static function key_hint() {
		$k = self::api_key();
		return strlen( $k ) >= 8 ? substr( $k, -4 ) : '';
	}

	/** @return string */
	private static function secret() {
		$material = 'aitp|';
		foreach ( array( 'AUTH_KEY', 'SECURE_AUTH_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT' ) as $c ) {
			$material .= defined( $c ) ? constant( $c ) : '';
		}
		return hash( 'sha256', $material, true );
	}

	/**
	 * @param string $plain Key.
	 * @return string
	 */
	public static function encrypt( $plain ) {
		if ( $plain === '' ) {
			return '';
		}
		if ( function_exists( 'sodium_crypto_secretbox' ) ) {
			$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			return 'sb1:' . base64_encode( $nonce . sodium_crypto_secretbox( $plain, $nonce, self::secret() ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		}
		if ( function_exists( 'openssl_encrypt' ) ) {
			$iv  = random_bytes( 12 );
			$tag = '';
			$c   = openssl_encrypt( $plain, 'aes-256-gcm', self::secret(), OPENSSL_RAW_DATA, $iv, $tag );
			return 'gcm1:' . base64_encode( $iv . $tag . $c ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		}
		return 'raw:' . $plain;
	}

	/**
	 * @param string $stored Stored value.
	 * @return string
	 */
	public static function decrypt( $stored ) {
		if ( $stored === '' ) {
			return '';
		}
		if ( strpos( $stored, 'sb1:' ) === 0 && function_exists( 'sodium_crypto_secretbox_open' ) ) {
			$bin = base64_decode( substr( $stored, 4 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
			if ( $bin === false || strlen( $bin ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
				return '';
			}
			$p = sodium_crypto_secretbox_open( substr( $bin, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), substr( $bin, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), self::secret() );
			return is_string( $p ) ? $p : '';
		}
		if ( strpos( $stored, 'gcm1:' ) === 0 && function_exists( 'openssl_decrypt' ) ) {
			$bin = base64_decode( substr( $stored, 5 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
			if ( $bin === false || strlen( $bin ) <= 28 ) {
				return '';
			}
			$p = openssl_decrypt( substr( $bin, 28 ), 'aes-256-gcm', self::secret(), OPENSSL_RAW_DATA, substr( $bin, 0, 12 ), substr( $bin, 12, 16 ) );
			return is_string( $p ) ? $p : '';
		}
		if ( strpos( $stored, 'raw:' ) === 0 ) {
			return substr( $stored, 4 );
		}
		return '';
	}

	/* -------------------------------------------------------------------------------------
	 * Endpoint validation.
	 * ----------------------------------------------------------------------------------- */

	/**
	 * Validate a base URL. HTTPS only (unless AITP_ALLOW_INSECURE_ENDPOINT is true, for local
	 * model servers), no credentials, query string or fragment.
	 *
	 * @param string $url URL.
	 * @return string|WP_Error Normalized URL.
	 */
	public static function validate_base_url( $url ) {
		$url = trim( (string) $url );
		if ( $url === '' ) {
			return '';
		}
		$p = wp_parse_url( $url );
		if ( ! is_array( $p ) || empty( $p['scheme'] ) || empty( $p['host'] ) ) {
			return new WP_Error( 'aitp_url', __( 'The API address is not a valid URL.', 'ai-translator-for-translatepress' ) );
		}
		$insecure = defined( 'AITP_ALLOW_INSECURE_ENDPOINT' ) && AITP_ALLOW_INSECURE_ENDPOINT;
		if ( strtolower( $p['scheme'] ) !== 'https' && ! ( $insecure && strtolower( $p['scheme'] ) === 'http' ) ) {
			return new WP_Error( 'aitp_url', __( 'The API address must start with https://.', 'ai-translator-for-translatepress' ) );
		}
		if ( isset( $p['user'] ) || isset( $p['pass'] ) || isset( $p['query'] ) || isset( $p['fragment'] ) ) {
			return new WP_Error( 'aitp_url', __( 'The API address must not contain a user name, password, query string or fragment. Enter the API key in its own field.', 'ai-translator-for-translatepress' ) );
		}
		return esc_url_raw( rtrim( $url, '/' ), array( 'https', 'http' ) );
	}

	/* -------------------------------------------------------------------------------------
	 * Builders.
	 * ----------------------------------------------------------------------------------- */

	/** @return bool */
	public static function is_configured() {
		$s = self::get();
		if ( self::api_key() === '' || trim( (string) $s['model'] ) === '' ) {
			return false;
		}
		return $s['provider'] === 'gemini' || trim( (string) self::effective_base_url() ) !== '';
	}

	/** @return string */
	public static function effective_base_url() {
		$s = self::get();
		if ( trim( (string) $s['base_url'] ) !== '' ) {
			return (string) $s['base_url'];
		}
		if ( $s['provider'] === 'openai' ) {
			$presets = AITP_Provider_OpenAI::presets();
			return isset( $presets[ $s['preset'] ] ) ? $presets[ $s['preset'] ]['base_url'] : '';
		}
		return '';
	}

	/** @return AITP_Provider|null */
	public static function provider() {
		if ( ! self::is_configured() ) {
			return null;
		}
		$s   = self::get();
		$cfg = array(
			'api_key'     => self::api_key(),
			'model'       => trim( (string) $s['model'] ),
			'base_url'    => self::effective_base_url(),
			'thinking'    => $s['thinking'],
			'structured'  => $s['structured'],
			'temperature' => $s['temperature'] === '' ? null : (float) $s['temperature'],
			'site_url'    => home_url( '/' ),
		);
		return $s['provider'] === 'openai' ? new AITP_Provider_OpenAI( $cfg ) : new AITP_Provider_Gemini( $cfg );
	}

	/** @return AITP_Rules */
	public static function rules() {
		$s     = self::get();
		$rules = new AITP_Rules();
		$rules->protected_terms = self::lines( $s['protected_terms'] );
		$rules->patterns        = array_merge( AITP_Rules::default_patterns(), self::user_patterns( $s['protected_patterns'] )['patterns'] );
		$g                      = AITP_Rules::parse_glossary( (string) $s['glossary'] );
		$rules->glossary        = $g['rules'];
		$rules->site_context    = (string) $s['site_context'];
		$rules->code_guard      = (bool) $s['code_guard'];
		$rules->size_guard      = (bool) $s['size_guard'];
		$rules->claim_checks    = (bool) $s['claim_checks'];
		return $rules;
	}

	/** @return array Translator options. */
	public static function translator_options() {
		$s = self::get();
		return array(
			'batch_items'  => (int) $s['batch_items'],
			'batch_tokens' => (int) $s['batch_tokens'],
			'concurrency'  => (int) $s['concurrency'],
			'http_timeout' => (int) $s['http_timeout'],
			'soft_policy'  => $s['soft_policy'] === 'strict' ? 'strict' : 'save_review',
		);
	}

	/**
	 * @param string $text Textarea value.
	 * @return string[]
	 */
	public static function lines( $text ) {
		$out = array();
		foreach ( preg_split( '~\R~u', (string) $text ) as $l ) {
			$l = trim( $l );
			if ( $l !== '' && strpos( $l, '#' ) !== 0 ) {
				$out[] = $l;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Protected patterns entered one per line as regular expressions without delimiters.
	 *
	 * @param string $text Textarea value.
	 * @return array{patterns:string[],errors:string[]}
	 */
	public static function user_patterns( $text ) {
		$patterns = array();
		$errors   = array();
		foreach ( self::lines( $text ) as $l ) {
			$p = '~' . str_replace( '~', '\~', $l ) . '~u';
			if ( AITP_Rules::is_valid_pattern( $p ) && ! preg_match( $p, '' ) ) {
				$patterns[] = $p;
			} else {
				$errors[] = $l;
			}
		}
		return array( 'patterns' => $patterns, 'errors' => $errors );
	}

	/**
	 * @param mixed $v   Value.
	 * @param int   $max Maximum bytes.
	 * @return string
	 */
	private static function clean_text( $v, $max ) {
		$v = wp_check_invalid_utf8( str_replace( array( "\0", "\r\n", "\r" ), array( '', "\n", "\n" ), (string) $v ) );
		if ( strlen( $v ) > $max ) {
			$v = function_exists( 'mb_strcut' ) ? mb_strcut( $v, 0, $max, 'UTF-8' ) : substr( $v, 0, $max );
		}
		return $v;
	}

	/* -------------------------------------------------------------------------------------
	 * Sanitizing (register_setting callback).
	 * ----------------------------------------------------------------------------------- */

	/**
	 * @param mixed $in Submitted values.
	 * @return array
	 */
	public static function sanitize( $in ) {
		$d = self::defaults();
		if ( is_array( $in ) && ! isset( $in['_form'] ) ) {
			// Already-sanitized value (WordPress runs the callback again when the option is first added).
			return array_intersect_key( array_merge( $d, $in ), $d );
		}
		$old = self::get();
		$in  = is_array( $in ) ? wp_unslash( $in ) : array();
		$out = $old;

		$out['provider'] = isset( $in['provider'] ) && in_array( $in['provider'], array( 'gemini', 'openai' ), true ) ? $in['provider'] : $old['provider'];
		$out['preset']   = isset( $in['preset'] ) && array_key_exists( $in['preset'], AITP_Provider_OpenAI::presets() ) ? $in['preset'] : $old['preset'];
		$out['model']    = isset( $in['model'] ) ? substr( preg_replace( '~[^A-Za-z0-9._:/@+\-]~', '', (string) $in['model'] ), 0, 120 ) : $old['model'];

		if ( isset( $in['base_url'] ) ) {
			$url = self::validate_base_url( $in['base_url'] );
			if ( is_wp_error( $url ) ) {
				add_settings_error( self::OPTION, 'aitp_url', $url->get_error_message() );
			} else {
				$out['base_url'] = $url;
			}
		}

		if ( ! empty( $in['api_key_clear'] ) ) {
			$out['api_key_enc'] = '';
		} elseif ( isset( $in['api_key_new'] ) && trim( (string) $in['api_key_new'] ) !== '' ) {
			$key = trim( preg_replace( '~\s+~', '', (string) $in['api_key_new'] ) );
			if ( strlen( $key ) > 512 ) {
				add_settings_error( self::OPTION, 'aitp_key', __( 'The API key is too long.', 'ai-translator-for-translatepress' ) );
			} else {
				$out['api_key_enc'] = self::encrypt( $key );
			}
		}

		foreach ( array( 'thinking' => array( 'off', 'low', 'auto' ), 'structured' => array( 'auto', 'schema', 'json', 'off' ), 'soft_policy' => array( 'save_review', 'strict' ) ) as $k => $allowed ) {
			if ( isset( $in[ $k ] ) && in_array( $in[ $k ], $allowed, true ) ) {
				$out[ $k ] = $in[ $k ];
			}
		}

		$out['temperature'] = isset( $in['temperature'] ) && trim( (string) $in['temperature'] ) !== '' && is_numeric( $in['temperature'] ) ? (string) max( 0, min( 2, (float) $in['temperature'] ) ) : '';

		// Stored as plain text and always escaped on output; sanitize_textarea_field() is not used
		// because it would remove "%de"-like sequences and "<" from terms, patterns and glossaries.
		foreach ( array( 'protected_terms' => 20000, 'protected_patterns' => 5000, 'glossary' => 20000, 'site_context' => 4000 ) as $k => $max ) {
			if ( isset( $in[ $k ] ) ) {
				$out[ $k ] = self::clean_text( $in[ $k ], $max );
			}
		}
		if ( isset( $in['protected_patterns'] ) ) {
			$check = self::user_patterns( $out['protected_patterns'] );
			if ( $check['errors'] ) {
				/* translators: %s: list of patterns */
				add_settings_error( self::OPTION, 'aitp_patterns', sprintf( __( 'These protected patterns are not valid regular expressions (or match empty text) and are ignored: %s', 'ai-translator-for-translatepress' ), implode( ' | ', array_map( 'esc_html', $check['errors'] ) ) ) );
			}
		}
		if ( isset( $in['glossary'] ) ) {
			$g = AITP_Rules::parse_glossary( $out['glossary'] );
			if ( $g['errors'] ) {
				/* translators: %s: list of lines */
				add_settings_error( self::OPTION, 'aitp_glossary', sprintf( __( 'These glossary lines could not be read and are ignored: %s', 'ai-translator-for-translatepress' ), implode( ' | ', array_map( 'esc_html', $g['errors'] ) ) ) );
			}
		}

		if ( isset( $in['_checkboxes'] ) ) {
			foreach ( array( 'code_guard', 'size_guard', 'claim_checks' ) as $k ) {
				$out[ $k ] = empty( $in[ $k ] ) ? 0 : 1;
			}
		}

		$ranges = array(
			'time_budget'  => array( 10, 40 ),
			'chunk_size'   => array( 20, 300 ),
			'batch_items'  => array( 5, 100 ),
			'batch_tokens' => array( 500, 8000 ),
			'concurrency'  => array( 1, 6 ),
			'http_timeout' => array( 10, 60 ),
			'log_days'     => array( 1, 90 ),
		);
		foreach ( $ranges as $k => $r ) {
			if ( isset( $in[ $k ] ) && is_numeric( $in[ $k ] ) ) {
				$out[ $k ] = max( $r[0], min( $r[1], (int) $in[ $k ] ) );
			} elseif ( ! isset( $out[ $k ] ) ) {
				$out[ $k ] = $d[ $k ];
			}
		}

		// Connection changed: forget the paused state so the new settings are tried right away.
		foreach ( array( 'provider', 'preset', 'base_url', 'model', 'api_key_enc' ) as $k ) {
			if ( $out[ $k ] !== $old[ $k ] ) {
				delete_option( 'aitp_breaker' );
				break;
			}
		}

		self::flush();
		return array_intersect_key( $out, $d );
	}
}

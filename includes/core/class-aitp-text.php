<?php
/**
 * Text helpers shared by the validator, segmenter and batcher. Pure PHP.
 *
 * @package AI_Translator_For_TranslatePress
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AITP_Text {

	// Stops at quotes of any kind and at an unbalanced ")" (markdown links), never ends on punctuation.
	const URL_REGEX   = '~\b(?:https?://|www\.)(?:[^\s<>"\'“”‘’«»「」()\[\]]|\([^\s<>"\'()]*\))*(?:[^\s<>"\'“”‘’«»「」()\[\].,;:!?}]|\([^\s<>"\'()]*\))~iu';
	const EMAIL_REGEX = '~[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}~u';

	/**
	 * Placeholder / token patterns that must survive translation untouched.
	 *
	 * @return string[]
	 */
	public static function placeholder_patterns() {
		return array(
			'~%(?:\d+\$)?(?:\.\d+)?[sdfu]~',                // printf: %s %d %1$s %.2f
			'~%%~',                                          // escaped percent
			'~\{\{[^{}]+\}\}~',                              // {{var}}
			'~(?<!\{)\{[A-Za-z0-9_.\-]+\}(?!\})~',           // {name} {0}
			'~\[/?[a-z_][a-z0-9_\-]*(?:\s+[^\]]*=[^\]]*)?/?\]~', // [shortcode attr="x"] [/shortcode] (not "[Silicone loop]")
			'~%%[A-Za-z_]+%%~',                              // %%title%% (SEO plugins)
			'~\b\d*TP\d+T\b~',                               // TranslatePress tokens (1TP0T ...)
			'~#!trpst#[^#]*#!trpen#~',                       // TranslatePress gettext wrappers
		);
	}

	/**
	 * Whether a string is structured data (JSON / JSON-LD) rather than prose. Such strings
	 * are not sent to the model: a translation would break the structure.
	 *
	 * @param string $s Input.
	 * @return bool
	 */
	public static function is_structured_data( $s ) {
		$t = trim( self::decode_entities( $s ) );
		$t = strtr( $t, array( '“' => '"', '”' => '"' ) );
		if ( $t === '' || ! in_array( $t[0], array( '{', '[' ), true ) ) {
			return false;
		}
		if ( preg_match( '~"@(?:context|type)"\s*:~', $t ) ) {
			return true;
		}
		$last = substr( $t, -1 );
		return ( $last === '}' || $last === ']' ) && is_array( json_decode( $t, true ) ) && strlen( $t ) > 2;
	}

	/**
	 * @param string $s Input.
	 * @return string
	 */
	public static function decode_entities( $s ) {
		return html_entity_decode( (string) $s, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}

	/**
	 * Convert digits of other numeral systems to ASCII so numbers can be compared.
	 *
	 * @param string $s Input.
	 * @return string
	 */
	public static function normalize_digits( $s ) {
		static $map = null;
		if ( $map === null ) {
			$map    = array();
			$blocks = array( 0x0660, 0x06F0, 0x07C0, 0x0966, 0x09E6, 0x0A66, 0x0AE6, 0x0B66, 0x0BE6, 0x0C66, 0x0CE6, 0x0D66, 0x0E50, 0x0ED0, 0x0F20, 0x1040, 0x17E0, 0x1810, 0xFF10 );
			foreach ( $blocks as $start ) {
				for ( $d = 0; $d < 10; $d++ ) {
					$map[ self::chr( $start + $d ) ] = (string) $d;
				}
			}
			// Arabic decimal and thousands separators.
			$map[ self::chr( 0x066B ) ] = '.';
			$map[ self::chr( 0x066C ) ] = ',';
		}
		return strtr( (string) $s, $map );
	}

	/**
	 * Normalise dashes and special spaces so codes like "EN ISO 12312-1" compare reliably.
	 *
	 * @param string $s Input.
	 * @return string
	 */
	public static function normalize_punct( $s ) {
		$s = preg_replace( '~[\x{2010}\x{2011}\x{2012}\x{2013}\x{2014}\x{2015}\x{2212}\x{FE63}\x{FF0D}]~u', '-', (string) $s );
		$s = preg_replace( '~[\x{00A0}\x{2007}\x{2009}\x{200A}\x{202F}\x{205F}\x{3000}]~u', ' ', $s );
		return (string) $s;
	}

	/**
	 * @param int $cp Code point.
	 * @return string
	 */
	public static function chr( $cp ) {
		if ( function_exists( 'mb_chr' ) ) {
			return mb_chr( $cp, 'UTF-8' );
		}
		return html_entity_decode( '&#' . (int) $cp . ';', ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * Every match of the given patterns (deduplicated list of strings).
	 *
	 * @param string   $s        Input.
	 * @param string[] $patterns Regexes.
	 * @return string[]
	 */
	public static function matches( $s, array $patterns ) {
		$out = array();
		foreach ( $patterns as $p ) {
			if ( @preg_match_all( $p, $s, $m ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors -- user-supplied patterns are validated on save; stay defensive.
				foreach ( $m[0] as $hit ) {
					$out[] = $hit;
				}
			}
		}
		return $out;
	}

	/**
	 * @param string   $s        Input.
	 * @param string[] $patterns Regexes.
	 * @return string
	 */
	public static function remove( $s, array $patterns ) {
		foreach ( $patterns as $p ) {
			$r = @preg_replace( $p, ' ', $s ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( is_string( $r ) ) {
				$s = $r;
			}
		}
		return $s;
	}

	/**
	 * Remove a literal term (word-boundary aware, case-sensitive).
	 *
	 * @param string $s    Input.
	 * @param string $term Term.
	 * @return string
	 */
	public static function remove_term( $s, $term ) {
		return (string) preg_replace( self::term_regex( $term ), ' ', self::normalize_punct( $s ) );
	}

	/**
	 * Count occurrences of a literal term (word-boundary aware, case-sensitive).
	 *
	 * @param string $s    Input.
	 * @param string $term Term.
	 * @return int
	 */
	public static function count_term( $s, $term ) {
		return (int) preg_match_all( self::term_regex( $term ), self::normalize_punct( $s ) );
	}

	/**
	 * @param string $term Term.
	 * @return string
	 */
	public static function term_regex( $term ) {
		$term  = trim( self::normalize_punct( $term ) );
		$parts = preg_split( '~\s+~u', $term );
		$q     = implode(
			'\s+',
			array_map(
				function ( $p ) {
					return preg_quote( $p, '~' );
				},
				$parts
			)
		);
		$first = preg_match( '~^.~us', $term, $m ) ? $m[0] : '';
		$last  = preg_match( '~.$~us', $term, $m ) ? $m[0] : '';
		$b     = self::edge_class( $first );
		$a     = self::edge_class( $last );
		return '~' . ( $b !== '' ? '(?<!' . $b . ')' : '' ) . $q . ( $a !== '' ? '(?!' . $a . ')' : '' ) . '~u';
	}

	/**
	 * Characters that must not touch a term at an edge starting/ending with $ch.
	 *
	 * Only characters of the same writing system count, so "OKAY EYEWEARは" (Japanese
	 * particle directly after a Latin brand) matches while "OKAYS" does not. Scripts written
	 * without spaces (Han, Kana, Thai …) have no word boundaries at all.
	 *
	 * @param string $ch Edge character.
	 * @return string Regex character class or ''.
	 */
	private static function edge_class( $ch ) {
		if ( $ch === '' ) {
			return '';
		}
		if ( preg_match( '~\p{N}~u', $ch ) ) {
			return '[\p{N}\p{Latin}]';
		}
		if ( ! preg_match( '~\p{L}~u', $ch ) ) {
			return '';
		}
		foreach ( array( 'Han', 'Hiragana', 'Katakana', 'Thai', 'Lao', 'Khmer', 'Myanmar' ) as $sc ) {
			if ( preg_match( '~\p{' . $sc . '}~u', $ch ) ) {
				return '';
			}
		}
		foreach ( AITP_Languages::all_scripts() as $sc ) {
			if ( preg_match( '~\p{' . $sc . '}~u', $ch ) ) {
				return '[\p{' . $sc . '}\p{N}\p{M}]';
			}
		}
		return '[\p{L}\p{N}\p{M}]';
	}

	/**
	 * Count letters/digits by Unicode script.
	 *
	 * Only letters and digits are counted: newer PCRE versions match \p{Script} by script
	 * extension, so shared punctuation such as the ideographic comma "、" would otherwise be
	 * counted as Hangul in Japanese text.
	 *
	 * @param string $s Input (already stripped of markup).
	 * @return array<string,int>
	 */
	public static function script_counts( $s ) {
		$out = array();
		foreach ( AITP_Languages::all_scripts() as $script ) {
			$n = preg_match_all( '~(?=[\p{L}\p{N}])\p{' . $script . '}~u', $s );
			if ( $n ) {
				$out[ $script ] = (int) $n;
			}
		}
		return $out;
	}

	/**
	 * Number of letters and digits.
	 *
	 * @param string $s Input.
	 * @return int
	 */
	public static function letter_count( $s ) {
		return (int) preg_match_all( '~[\p{L}\p{N}]~u', $s );
	}

	/**
	 * Latin words of the given minimum length.
	 *
	 * @param string $s       Input.
	 * @param int    $min_len Minimum letters.
	 * @return string[]
	 */
	public static function latin_words( $s, $min_len = 1 ) {
		if ( ! preg_match_all( '~\p{Latin}+(?:[\'’]\p{Latin}+)*~u', $s, $m ) ) {
			return array();
		}
		$out = array();
		foreach ( $m[0] as $w ) {
			if ( self::len( $w ) >= $min_len ) {
				$out[] = $w;
			}
		}
		return $out;
	}

	/**
	 * Canonical numbers (digit sequences without separators) found in a string.
	 *
	 * "10,000" and "10 000" and "10.000" all become "10000"; "3.5" and "3,5" become "35".
	 * This keeps locale formatting differences from looking like changed numbers.
	 *
	 * @param string $s Input.
	 * @return string[]
	 */
	public static function numbers( $s ) {
		$s = self::normalize_punct( self::normalize_digits( $s ) );
		if ( ! preg_match_all( '~(?<![\p{L}\d])\d{1,3}(?:[ .,\'](?=\d{3}(?!\d))\d{3})+(?:[.,]\d+)?|\d+(?:[.,]\d+)?~u', $s, $m ) ) {
			return array();
		}
		$out = array();
		foreach ( $m[0] as $n ) {
			$out[] = preg_replace( '~\D~', '', $n );
		}
		return $out;
	}

	/**
	 * Sentence terminators.
	 *
	 * @param string $s Input.
	 * @return int
	 */
	public static function sentence_count( $s ) {
		return (int) preg_match_all( '~[.!?](?=\s|$)|[。！？؟।။։።]~u', $s );
	}

	/**
	 * @param string $s Input.
	 * @return int
	 */
	public static function len( $s ) {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $s, 'UTF-8' ) : strlen( utf8_decode( $s ) );
	}

	/**
	 * @param string $s Input.
	 * @return string
	 */
	public static function lower( $s ) {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $s, 'UTF-8' ) : strtolower( $s );
	}

	/**
	 * Collapse whitespace.
	 *
	 * @param string $s Input.
	 * @return string
	 */
	public static function squash( $s ) {
		return trim( (string) preg_replace( '~\s+~u', ' ', self::normalize_punct( $s ) ) );
	}

	/**
	 * Rough token estimate. Deliberately conservative: bytes / 3 overestimates English
	 * (≈4 chars per token) and roughly matches CJK (3 bytes ≈ 1 token).
	 *
	 * @param string $s Input.
	 * @return int
	 */
	public static function estimate_tokens( $s ) {
		return (int) ceil( strlen( (string) $s ) / 3 );
	}

	/**
	 * Multiset equality for string lists.
	 *
	 * @param string[] $a List.
	 * @param string[] $b List.
	 * @return bool
	 */
	public static function same_multiset( array $a, array $b ) {
		sort( $a, SORT_STRING );
		sort( $b, SORT_STRING );
		return $a === $b;
	}

	/**
	 * Items of $needles that are missing from $haystack, honouring multiplicity.
	 *
	 * @param string[] $needles  Required items.
	 * @param string[] $haystack Available items.
	 * @return string[]
	 */
	public static function missing_items( array $needles, array $haystack ) {
		$count = array_count_values( $haystack );
		$miss  = array();
		foreach ( $needles as $n ) {
			if ( ! empty( $count[ $n ] ) ) {
				$count[ $n ]--;
			} else {
				$miss[] = $n;
			}
		}
		return $miss;
	}
}

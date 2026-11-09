<?php
/**
 * Site-specific validation rules: protected terms, protected patterns, glossary.
 *
 * Pure PHP. Built from plugin settings by AITP_Settings::rules().
 *
 * @package AI_Translator_For_TranslatePress
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AITP_Rules {

	/** @var string[] Exact strings (brands, product names) that must be copied unchanged. */
	public $protected_terms = array();

	/** @var string[] Regexes; every match in the source must reappear verbatim in the translation. */
	public $patterns = array();

	/** @var bool Treat model/material codes (TR90, UV400, OK-A1001, B2B) as protected. */
	public $code_guard = true;

	/** @var bool Strings made only of size codes (S, M, L, XL …) must stay identical. */
	public $size_guard = true;

	/** @var bool Built-in "added claim" checks (guarantee, certified, in stock …). */
	public $claim_checks = true;

	/** @var array<int,array{term:string,lang:string,require:string[],forbid:string[]}> */
	public $glossary = array();

	/** @var string Free-text description of the site, sent with every request. */
	public $site_context = '';

	/**
	 * Default protected patterns: technical standards and certification references.
	 *
	 * @return string[]
	 */
	public static function default_patterns() {
		return array(
			// EN ISO 12312-1, ISO 9001:2015, ANSI Z80.3, ASTM F963-17, EN 71-3, IEC 62471, UL 94, GB/T 14214, AS/NZS 1067.1.
			'~(?<![\p{L}\p{N}])(?:(?:EN|BS|DIN|NF|UNE|CSA)\s+)?(?:ISO(?:/IEC)?|IEC|EN|ANSI(?:/[A-Z]+)?|ASTM|DIN|BS|JIS|GB(?:/T)?|AS/NZS|CSA|UL)\s?[A-Z]{0,2}\d[\w.:/-]*(?<![.:/-])~u',
		);
	}

	/**
	 * Model numbers, material codes and similar tokens found in the text.
	 * A token qualifies when it has at least one digit, at least one capital letter,
	 * is 3+ chars long and has 2+ capitals or 2+ digits (so "Q4" or "A4" are left to the
	 * plain number check, while TR90, UV400, A1001, B2B, CO2 are protected).
	 *
	 * @param string $s Input.
	 * @return string[]
	 */
	public static function extract_codes( $s ) {
		if ( ! preg_match_all( '~(?<![\p{L}\p{N}])[A-Z0-9]+(?:[-/.][A-Z0-9]+)*(?![\p{L}\p{N}])~u', AITP_Text::normalize_punct( $s ), $m ) ) {
			return array();
		}
		$out = array();
		foreach ( $m[0] as $tok ) {
			$digits = preg_match_all( '~\d~', $tok );
			$caps   = preg_match_all( '~[A-Z]~', $tok );
			if ( strlen( $tok ) >= 3 && $digits >= 1 && $caps >= 1 && ( $caps >= 2 || $digits >= 2 ) ) {
				$out[] = $tok;
			}
		}
		return $out;
	}

	/**
	 * Whether a string is only size codes, e.g. "XL" or "S / M / L".
	 *
	 * @param string $s Input.
	 * @return bool
	 */
	public static function is_size_string( $s ) {
		$size = '(?:XXS|XS|S|M|L|XL|XXL|XXXL|[2-6]XL)';
		return (bool) preg_match( '~^\s*' . $size . '(?:\s*[/,|-]\s*' . $size . ')*\s*$~', $s );
	}

	/**
	 * Parse glossary lines.
	 *
	 * Format (one rule per line, "#" starts a comment):
	 *   source term => lang: preferred | alternative ; !forbidden | !forbidden2
	 * lang is a locale (es_MX), a base language (es) or * for all languages.
	 * Forbidden forms written in ALL CAPS are matched case-sensitively, others case-insensitively.
	 *
	 * @param string $text Raw textarea value.
	 * @return array{rules:array,errors:string[]}
	 */
	public static function parse_glossary( $text ) {
		$rules  = array();
		$errors = array();
		$lines  = preg_split( '~\R~u', (string) $text );
		foreach ( $lines as $no => $line ) {
			$line = trim( $line );
			if ( $line === '' || strpos( $line, '#' ) === 0 ) {
				continue;
			}
			$pos = strpos( $line, '=>' );
			if ( $pos === false ) {
				$errors[] = sprintf( 'Line %d: missing "=>"', $no + 1 );
				continue;
			}
			$term  = trim( substr( $line, 0, $pos ) );
			$right = trim( substr( $line, $pos + 2 ) );
			$lang  = '*';
			if ( preg_match( '~^(\*|[A-Za-z]{2,3}(?:[_-][A-Za-z0-9]+)*)\s*:\s*(.*)$~u', $right, $m ) ) {
				$lang  = $m[1];
				$right = $m[2];
			}
			$require = array();
			$forbid  = array();
			foreach ( preg_split( '~[;|]~u', $right ) as $tok ) {
				$tok = trim( $tok );
				if ( $tok === '' ) {
					continue;
				}
				if ( strpos( $tok, '!' ) === 0 ) {
					$tok = trim( substr( $tok, 1 ) );
					if ( $tok !== '' ) {
						$forbid[] = $tok;
					}
				} else {
					$require[] = $tok;
				}
			}
			if ( $term === '' || ( empty( $require ) && empty( $forbid ) ) ) {
				$errors[] = sprintf( 'Line %d: needs a source term and at least one target form', $no + 1 );
				continue;
			}
			$rules[] = array(
				'term'    => $term,
				'lang'    => str_replace( '-', '_', $lang ),
				'require' => $require,
				'forbid'  => $forbid,
			);
		}
		return array( 'rules' => $rules, 'errors' => $errors );
	}

	/**
	 * Glossary rules relevant to a target locale and present in the source text.
	 *
	 * @param string $source Source text.
	 * @param array  $tinfo  Target language info (AITP_Languages::info()).
	 * @return array
	 */
	public function glossary_for( $source, array $tinfo ) {
		$out = array();
		$low = AITP_Text::lower( AITP_Text::normalize_punct( $source ) );
		foreach ( $this->glossary as $rule ) {
			if ( ! self::lang_matches( $rule['lang'], $tinfo ) ) {
				continue;
			}
			if ( ! preg_match( AITP_Text::term_regex( AITP_Text::lower( $rule['term'] ) ), $low ) ) {
				continue;
			}
			$out[] = $rule;
		}
		return $out;
	}

	/**
	 * @param string $lang  Rule language.
	 * @param array  $tinfo Target info.
	 * @return bool
	 */
	public static function lang_matches( $lang, array $tinfo ) {
		if ( $lang === '*' ) {
			return true;
		}
		$lang = strtolower( $lang );
		return $lang === strtolower( $tinfo['locale'] ) || $lang === strtolower( str_replace( '-', '_', $tinfo['tag'] ) ) || $lang === $tinfo['base'];
	}

	/**
	 * Whether a target text contains a glossary form.
	 *
	 * @param string $text Target text.
	 * @param string $form Form.
	 * @return bool
	 */
	public static function contains_form( $text, $form ) {
		$text = AITP_Text::normalize_punct( $text );
		$form = AITP_Text::normalize_punct( $form );
		$caps = (bool) preg_match( '~\p{Lu}~u', $form ) && ! preg_match( '~\p{Ll}~u', $form );
		if ( $caps ) {
			return AITP_Text::count_term( $text, $form ) > 0;
		}
		return strpos( AITP_Text::lower( $text ), AITP_Text::lower( $form ) ) !== false;
	}

	/**
	 * Validate a user-supplied regex.
	 *
	 * @param string $pattern Pattern.
	 * @return bool
	 */
	public static function is_valid_pattern( $pattern ) {
		if ( ! is_string( $pattern ) || $pattern === '' || strlen( $pattern ) > 500 ) {
			return false;
		}
		return @preg_match( $pattern, '' ) !== false; // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}

	/**
	 * Fingerprint, used in cache keys so rule changes never reuse old results.
	 *
	 * @return string
	 */
	public function version() {
		return substr(
			hash(
				'sha256',
				(string) json_encode(
					array(
						$this->protected_terms,
						$this->patterns,
						$this->code_guard,
						$this->size_guard,
						$this->claim_checks,
						$this->glossary,
						$this->site_context,
					)
				)
			),
			0,
			16
		);
	}
}

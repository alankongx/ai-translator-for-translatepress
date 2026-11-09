<?php
/**
 * Light-weight HTML tag tokenizer used for structure checks and safe splitting.
 *
 * It does not build a DOM. TranslatePress strings are fragments (a paragraph with inline
 * tags, an attribute value, a gettext string), so a tokenizer is enough and never "repairs"
 * markup behind our back.
 *
 * @package AI_Translator_For_TranslatePress
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AITP_Markup {

	const TAG_REGEX = '~<!--.*?-->|</?[A-Za-z][A-Za-z0-9:._-]*(?:\s+(?:[^<>"\']|"[^"]*"|\'[^\']*\')*)?/?>~s';

	/** @var string[] */
	private static $void = array( 'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr' );

	/**
	 * Attributes whose values are human-readable and may legitimately be translated.
	 *
	 * @var string[]
	 */
	private static $translatable_attributes = array( 'title', 'alt', 'aria-label', 'aria-description', 'aria-placeholder', 'placeholder', 'label', 'summary', 'data-title', 'data-tooltip' );

	/**
	 * Split a string into text and tag tokens.
	 *
	 * @param string $s Input.
	 * @return array<int,array{type:string,value:string,name?:string,closing?:bool,void?:bool,attrs?:array<string,string|null>}>
	 */
	public static function tokenize( $s ) {
		$tokens = array();
		$offset = 0;
		if ( ! preg_match_all( self::TAG_REGEX, $s, $m, PREG_OFFSET_CAPTURE ) ) {
			return $s === '' ? array() : array( array( 'type' => 'text', 'value' => $s ) );
		}
		foreach ( $m[0] as $match ) {
			list( $tag, $pos ) = $match;
			if ( $pos > $offset ) {
				$tokens[] = array( 'type' => 'text', 'value' => substr( $s, $offset, $pos - $offset ) );
			}
			$tokens[] = self::describe_tag( $tag );
			$offset   = $pos + strlen( $tag );
		}
		if ( $offset < strlen( $s ) ) {
			$tokens[] = array( 'type' => 'text', 'value' => substr( $s, $offset ) );
		}
		return $tokens;
	}

	/**
	 * @param string $tag Raw tag.
	 * @return array
	 */
	private static function describe_tag( $tag ) {
		if ( strpos( $tag, '<!--' ) === 0 ) {
			return array( 'type' => 'comment', 'value' => $tag );
		}
		preg_match( '~^<(/?)([A-Za-z][A-Za-z0-9:._-]*)~', $tag, $m );
		$closing = $m[1] === '/';
		$name    = strtolower( $m[2] );
		$attrs   = array();
		$rest    = substr( $tag, strlen( $m[0] ) );
		$rest    = preg_replace( '~/?>$~', '', $rest );
		if ( preg_match_all( '~([^\s=/>"\']+)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>]+)))?~', $rest, $am, PREG_SET_ORDER ) ) {
			foreach ( $am as $a ) {
				$value = null;
				if ( isset( $a[2] ) && $a[2] !== '' ) {
					$value = $a[2];
				} elseif ( isset( $a[3] ) && $a[3] !== '' ) {
					$value = $a[3];
				} elseif ( isset( $a[4] ) && $a[4] !== '' ) {
					$value = $a[4];
				} elseif ( strpos( $a[0], '=' ) !== false ) {
					$value = '';
				}
				$attrs[ strtolower( $a[1] ) ] = $value;
			}
		}
		$self_closing = (bool) preg_match( '~/>$~', $tag );
		return array(
			'type'    => 'tag',
			'value'   => $tag,
			'name'    => $name,
			'closing' => $closing,
			'void'    => $self_closing || in_array( $name, self::$void, true ),
			'attrs'   => $attrs,
		);
	}

	/**
	 * Order-independent signatures of every tag/comment. Translatable attribute values are masked.
	 *
	 * @param string $s Input.
	 * @return string[] Sorted list (a multiset).
	 */
	public static function signatures( $s ) {
		$out = array();
		foreach ( self::tokenize( $s ) as $t ) {
			if ( $t['type'] === 'comment' ) {
				$out[] = 'comment:' . $t['value'];
			} elseif ( $t['type'] === 'tag' ) {
				$attrs = $t['attrs'];
				ksort( $attrs );
				$parts = array();
				foreach ( $attrs as $k => $v ) {
					if ( in_array( $k, self::$translatable_attributes, true ) ) {
						$parts[] = $k . '=*';
					} else {
						$parts[] = $k . ( $v === null ? '' : '=' . $v );
					}
				}
				$out[] = ( $t['closing'] ? '/' : '' ) . $t['name'] . ( $parts ? ' ' . implode( ' ', $parts ) : '' );
			}
		}
		sort( $out, SORT_STRING );
		return $out;
	}

	/**
	 * Whether non-void tags open and close in a properly nested order.
	 *
	 * @param string $s Input.
	 * @return bool
	 */
	public static function is_balanced( $s ) {
		$stack = array();
		foreach ( self::tokenize( $s ) as $t ) {
			if ( $t['type'] !== 'tag' || $t['void'] ) {
				continue;
			}
			if ( ! $t['closing'] ) {
				$stack[] = $t['name'];
			} else {
				if ( empty( $stack ) || end( $stack ) !== $t['name'] ) {
					return false;
				}
				array_pop( $stack );
			}
		}
		return empty( $stack );
	}

	/**
	 * Replace every tag/comment with a space, leaving only human-readable text.
	 *
	 * @param string $s Input.
	 * @return string
	 */
	public static function text_only( $s ) {
		return (string) preg_replace( self::TAG_REGEX, ' ', $s );
	}

	/**
	 * Inner text of elements explicitly marked as not-to-be-translated
	 * (translate="no", class="notranslate", data-no-translation, TranslatePress exclusion spans).
	 * Nested markup inside such elements is returned as-is.
	 *
	 * @param string $s Input.
	 * @return string[]
	 */
	public static function no_translate_texts( $s ) {
		$out    = array();
		$tokens = self::tokenize( $s );
		$n      = count( $tokens );
		for ( $i = 0; $i < $n; $i++ ) {
			$t = $tokens[ $i ];
			if ( $t['type'] !== 'tag' || $t['closing'] || $t['void'] || ! self::is_no_translate( $t['attrs'] ) ) {
				continue;
			}
			$depth = 1;
			$inner = '';
			for ( $j = $i + 1; $j < $n; $j++ ) {
				$u = $tokens[ $j ];
				if ( $u['type'] === 'tag' && ! $u['void'] && $u['name'] === $t['name'] ) {
					$depth += $u['closing'] ? -1 : 1;
					if ( $depth === 0 ) {
						break;
					}
				}
				$inner .= $u['value'];
			}
			if ( trim( $inner ) !== '' ) {
				$out[] = $inner;
			}
		}
		return $out;
	}

	/**
	 * @param array<string,string|null> $attrs Attributes.
	 * @return bool
	 */
	private static function is_no_translate( $attrs ) {
		if ( isset( $attrs['translate'] ) && strtolower( (string) $attrs['translate'] ) === 'no' ) {
			return true;
		}
		if ( array_key_exists( 'data-no-translation', $attrs ) || array_key_exists( 'data-trp-no-translation', $attrs ) ) {
			return true;
		}
		if ( isset( $attrs['class'] ) && preg_match( '~(^|\s)(notranslate|trp-no-translate|translation-block-no)(\s|$)~', (string) $attrs['class'] ) ) {
			return true;
		}
		return false;
	}

	/**
	 * @return string[]
	 */
	public static function translatable_attributes() {
		return self::$translatable_attributes;
	}
}

<?php
/**
 * Splits very long strings into ordered sub-segments at safe points, and joins the
 * translated sub-segments back. A split item is only saved when every sub-segment was
 * translated and validated, and the joined result passes validation again.
 *
 * Safe points are sentence ends and block-level closing tags at nesting depth 0, so a
 * sub-segment never contains half of an inline element.
 *
 * @package AI_Translator_For_TranslatePress
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AITP_Segmenter {

	/** @var string[] */
	private static $block = array( 'p', 'div', 'li', 'ul', 'ol', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'blockquote', 'section', 'article', 'table', 'tr', 'td', 'th', 'figure', 'figcaption', 'pre', 'dl', 'dt', 'dd', 'header', 'footer', 'aside', 'main', 'nav' );

	/**
	 * @param string $s         Text.
	 * @param int    $max_bytes Maximum bytes per segment (a single unsplittable piece may exceed it).
	 * @return array{lead:string,parts:array<int,array{text:string,sep:string}>}
	 */
	public static function split( $s, $max_bytes ) {
		$s = (string) $s;
		preg_match( '~^\s*~u', $s, $m );
		$lead = $m[0];
		$body = substr( $s, strlen( $lead ) );

		if ( strlen( $s ) <= $max_bytes ) {
			return array( 'lead' => $lead, 'parts' => self::with_seps( array( $body ) ) );
		}

		$pieces = array();
		$cur    = '';
		$depth  = 0;
		foreach ( AITP_Markup::tokenize( $body ) as $t ) {
			if ( $t['type'] === 'tag' ) {
				$cur .= $t['value'];
				if ( ! $t['void'] ) {
					$depth = max( 0, $depth + ( $t['closing'] ? -1 : 1 ) );
				}
				if ( $depth === 0 && ( ( $t['closing'] && in_array( $t['name'], self::$block, true ) ) || $t['name'] === 'br' ) ) {
					$pieces[] = $cur;
					$cur      = '';
				}
				continue;
			}
			if ( $t['type'] === 'comment' || $depth > 0 ) {
				$cur .= $t['value'];
				continue;
			}
			$text = $t['value'];
			if ( preg_match_all( '~(?:[.!?](?:["\'”’)\]]*)\s+|[。！？؟।\x{06D4}\x{17D4}\x{0F0D}]["」』）]*\s*)~u', $text, $bm, PREG_OFFSET_CAPTURE ) ) {
				$start = 0;
				foreach ( $bm[0] as $b ) {
					$end      = $b[1] + strlen( $b[0] );
					$cur     .= substr( $text, $start, $end - $start );
					$pieces[] = $cur;
					$cur      = '';
					$start    = $end;
				}
				$cur .= substr( $text, $start );
			} else {
				$cur .= $text;
			}
		}
		if ( $cur !== '' ) {
			$pieces[] = $cur;
		}

		$parts = array();
		$buf   = '';
		foreach ( $pieces as $p ) {
			if ( $buf !== '' && strlen( $buf ) + strlen( $p ) > $max_bytes ) {
				$parts[] = $buf;
				$buf     = $p;
			} else {
				$buf .= $p;
			}
		}
		if ( $buf !== '' ) {
			$parts[] = $buf;
		}

		return array( 'lead' => $lead, 'parts' => self::with_seps( $parts ) );
	}

	/**
	 * @param string[] $parts Raw parts.
	 * @return array<int,array{text:string,sep:string}>
	 */
	private static function with_seps( array $parts ) {
		$out = array();
		foreach ( $parts as $p ) {
			preg_match( '~\s*$~u', $p, $m );
			$sep  = $m[0];
			$text = substr( $p, 0, strlen( $p ) - strlen( $sep ) );
			if ( $text === '' && $out ) {
				$out[ count( $out ) - 1 ]['sep'] .= $sep;
				continue;
			}
			$out[] = array( 'text' => $text, 'sep' => $sep );
		}
		return $out;
	}

	/**
	 * @param string[] $translated Translated texts, same order as $split['parts'].
	 * @param array    $split      Result of split().
	 * @param string   $script     Target script code.
	 * @return string
	 */
	public static function join( array $translated, array $split, $script ) {
		$out   = $split['lead'];
		$last  = count( $split['parts'] ) - 1;
		$tight = AITP_Languages::joins_without_spaces( $script );
		foreach ( $split['parts'] as $i => $part ) {
			$out .= $translated[ $i ];
			$sep  = $part['sep'];
			if ( $i < $last && $tight && $sep !== '' && strpos( $sep, "\n" ) === false ) {
				$sep = '';
			}
			$out .= $sep;
		}
		return $out;
	}
}

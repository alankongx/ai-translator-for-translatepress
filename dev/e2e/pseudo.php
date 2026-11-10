<?php
/** Deterministic pseudo-translation of the mock LLM (PHP port), shared by the e2e scripts. */
function aitp_e2e_pseudo( $text, $locale, array $terms ) {
	$alpha = null;
	if ( $locale === 'ja' ) {
		$alpha = 'アイウエオカキクケコサシスセソタチツテトナニヌネノハヒフヘホマミムメモヤユヨラリルレロワヲン';
	} elseif ( $locale === 'ar' ) {
		$alpha = 'ابتثجحخدذرزسشصضطظعغفقكلمنهوي';
	} elseif ( strpos( $locale, 'zh' ) === 0 ) {
		$alpha = '的一是不了人我在有他这为之大来以个中上们到说国和地也子时道出而要于就下得可你年生';
	}
	$alpha = $alpha ? preg_split( '~~u', $alpha, -1, PREG_SPLIT_NO_EMPTY ) : null;
	usort( $terms, function ( $a, $b ) { return strlen( $b ) - strlen( $a ); } );
	$out = '';
	foreach ( preg_split( '~(<[^>]*>)~', $text, -1, PREG_SPLIT_DELIM_CAPTURE ) as $part ) {
		if ( $part !== '' && $part[0] === '<' && substr( $part, -1 ) === '>' ) {
			$out .= $part;
			continue;
		}
		$keep = array();
		$part = preg_replace_callback( '~(?:https?://|www\.)[^\s<>"\']*[^\s<>"\'.,;:!?)]|[\w.+-]+@[\w-]+(?:\.[\w-]+)+~u', function ( $m ) use ( &$keep ) {
			$keep[] = $m[0];
			return "\x02" . ( count( $keep ) - 1 ) . "\x02";
		}, $part );
		foreach ( $terms as $i => $t ) {
			$part = str_replace( $t, "\x01" . $i . "\x01", $part );
		}
		$part = preg_replace_callback( '~\p{L}+~u', function ( $m ) use ( $alpha ) {
			$w     = $m[0];
			$chars = preg_split( '~~u', $w, -1, PREG_SPLIT_NO_EMPTY );
			if ( count( $chars ) < 3 || preg_match( '~^\p{Lu}+$~u', $w ) ) {
				return $w;
			}
			if ( $alpha ) {
				$o = '';
				foreach ( $chars as $c ) {
					$o .= $alpha[ mb_ord( $c, 'UTF-8' ) % count( $alpha ) ];
				}
				return $o;
			}
			$r = implode( '', array_reverse( $chars ) );
			return $r === $w ? $w . 'x' : $r;
		}, $part );
		$part = preg_replace_callback( "~\x01(\d+)\x01~", function ( $m ) use ( $terms ) { return $terms[ (int) $m[1] ]; }, $part );
		$part = preg_replace_callback( "~\x02(\d+)\x02~", function ( $m ) use ( $keep ) { return $keep[ (int) $m[1] ]; }, $part );
		$out .= $part;
	}
	return $out;
}

function aitp_e2e_norm( $s, $tight = false ) {
	$s = html_entity_decode( (string) $s, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	$s = strtr( $s, array( '’' => "'", '‘' => "'", '“' => '"', '”' => '"', "\xC2\xA0" => ' ' ) );
	// Japanese/Chinese: long texts are rejoined without spaces between sentences.
	return $tight ? preg_replace( '~\s+~u', '', $s ) : trim( preg_replace( '~\s+~u', ' ', $s ) );
}


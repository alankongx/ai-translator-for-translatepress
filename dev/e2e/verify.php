<?php
/**
 * wp eval-file /e2e/verify.php [lang,lang…]
 * Checks every machine-translated row (status 1) in the TranslatePress dictionaries against
 * the mock's deterministic translation of its own original. Any difference means a string was
 * saved with somebody else's translation (misalignment) or with damaged text.
 */
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

global $wpdb;
$langs  = isset( $args[0] ) ? explode( ',', $args[0] ) : array( 'fr_FR', 'es_MX', 'ja', 'ar', 'de_DE', 'pt_BR' );
$terms  = AITP_Settings::rules()->protected_terms;
$report = array();
foreach ( $langs as $l ) {
	$table = $wpdb->prefix . 'trp_dictionary_en_us_' . strtolower( $l );
	$rows  = $wpdb->get_results( "SELECT id, original, translated, status FROM $table", ARRAY_A ); // phpcs:ignore
	$r     = array( 'rows' => count( $rows ), 'status0' => 0, 'status1' => 0, 'status2' => 0, 'mismatch' => 0, 'english_saved' => 0, 'samples' => array() );
	foreach ( $rows as $row ) {
		$st = (int) $row['status'];
		$r[ 'status' . $st ] = ( isset( $r[ 'status' . $st ] ) ? $r[ 'status' . $st ] : 0 ) + 1;
		if ( $st !== 1 ) {
			continue;
		}
		$orig     = html_entity_decode( $row['original'], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$expected = aitp_e2e_pseudo( $orig, $l, $terms );
		$tight = $l === 'ja' || strpos( $l, 'zh' ) === 0;
		if ( aitp_e2e_norm( $expected, $tight ) !== aitp_e2e_norm( $row['translated'], $tight ) ) {
			$r['mismatch']++;
			if ( count( $r['samples'] ) < 5 ) {
				$r['samples'][] = array( 'original' => mb_substr( $orig, 0, 120 ), 'saved' => mb_substr( $row['translated'], 0, 120 ), 'expected' => mb_substr( $expected, 0, 120 ) );
			}
		}
		if ( aitp_e2e_norm( $orig ) === aitp_e2e_norm( $row['translated'] ) && preg_match_all( '~\b\p{Ll}{3,}\b~u', $orig ) >= 2 ) {
			$r['english_saved']++;
		}
	}
	$report[ $l ] = $r;
}
echo wp_json_encode( $report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) . "\n";

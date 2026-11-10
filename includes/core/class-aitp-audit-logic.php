<?php
/**
 * Pure rules of the audit (no WordPress): judging translations already stored by
 * TranslatePress, spotting one translation shared by unrelated originals, deciding
 * whether a row may be reset or restored, reading import lists and AI verdicts.
 *
 * @package AI_Translator_For_TranslatePress
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AITP_Audit_Logic {

	/** Severity: the translation is wrong in a way the checks can prove. */
	const ERROR = 'error';

	/** Severity: probably attached to the wrong original; needs a person or the AI check. */
	const SUSPECT = 'suspect';

	/** Severity: possible meaning change (added claim, glossary); a person decides. */
	const REVIEW = 'review';

	/** Most rows one repair or AI-check action may touch. */
	const MAX_ACTION_ROWS = 500;

	/**
	 * Judge one stored machine translation.
	 *
	 * @param AITP_Validator $v          Validator with the site's rules.
	 * @param string         $original   Original text (TranslatePress `original`).
	 * @param mixed          $translated Stored translation.
	 * @param array          $si         Source language info.
	 * @param array          $ti         Target language info.
	 * @return array{severity:string,codes:string[],details:array}|null Null when nothing was found.
	 */
	public static function check_row( AITP_Validator $v, $original, $translated, array $si, array $ti ) {
		$original = (string) $original;
		if ( ! is_string( $translated ) || trim( $translated ) === '' ) {
			return array( 'severity' => self::ERROR, 'codes' => array( 'empty' ), 'details' => array() );
		}
		if ( AITP_Text::is_structured_data( $original ) && self::json( $original ) !== null ) {
			$why = self::structured_problem( $original, $translated );
			return $why === '' ? null : array( 'severity' => self::ERROR, 'codes' => array( 'structured_broken' ), 'details' => array( 'why' => $why ) );
		}
		$res  = $v->check( $original, $translated, $si, $ti );
		$soft = array_values( array_diff( $res['soft'], array( AITP_Validator::CONFIRM_IDENTICAL ) ) );
		if ( $res['hard'] ) {
			return array( 'severity' => self::ERROR, 'codes' => array_values( array_unique( array_merge( $res['hard'], $soft ) ) ), 'details' => self::small( $res['details'] ) );
		}
		if ( $soft ) {
			return array( 'severity' => self::REVIEW, 'codes' => $soft, 'details' => self::small( $res['details'] ) );
		}
		// Left exactly as the original. Fine for a name; for a phrase of several words it may
		// be untranslated text the rules cannot prove (titles full of codes and brands).
		if ( in_array( AITP_Validator::CONFIRM_IDENTICAL, $res['soft'], true ) && preg_match_all( '~\p{L}{2,}~u', AITP_Markup::text_only( $original ) ) >= 3 ) {
			return array( 'severity' => self::SUSPECT, 'codes' => array( 'identical' ), 'details' => array() );
		}
		return null;
	}

	/**
	 * Structured data (JSON-LD and similar) must stay valid JSON with the same shape:
	 * same keys, same value types, and identifiers, types and links unchanged.
	 *
	 * @param string $original   Original JSON.
	 * @param string $translated Stored translation.
	 * @return string '' when fine, otherwise a short reason.
	 */
	public static function structured_problem( $original, $translated ) {
		$a = self::json( $original );
		if ( $a === null ) {
			return ''; // Not JSON after all; nothing to compare.
		}
		$b = self::json( $translated );
		if ( $b === null ) {
			return 'invalid_json';
		}
		return self::same_shape( $a, $b, '' );
	}

	/**
	 * Decode JSON that may be wrapped in a script tag, HTML-encoded or have its quotes
	 * turned into typographic quotes by WordPress.
	 *
	 * @param string $s Text.
	 * @return mixed|null
	 */
	private static function json( $s ) {
		$s = trim( (string) $s );
		if ( preg_match( '~^<script[^>]*>(.*)</script>$~is', $s, $m ) ) {
			$s = trim( $m[1] );
		}
		$d = json_decode( $s, true );
		if ( $d === null ) {
			$d = json_decode( strtr( AITP_Text::decode_entities( $s ), array( '“' => '"', '”' => '"', '″' => '"' ) ), true );
		}
		return is_array( $d ) ? $d : null;
	}

	/**
	 * @param mixed  $a    Original value.
	 * @param mixed  $b    Translated value.
	 * @param string $path Key path.
	 * @return string
	 */
	private static function same_shape( $a, $b, $path ) {
		if ( gettype( $a ) !== gettype( $b ) ) {
			return 'type:' . $path;
		}
		if ( is_array( $a ) ) {
			if ( array_keys( $a ) !== array_keys( $b ) ) {
				return 'keys:' . $path;
			}
			foreach ( $a as $k => $va ) {
				$why = self::same_shape( $va, $b[ $k ], $path . '/' . $k );
				if ( $why !== '' ) {
					return $why;
				}
			}
			return '';
		}
		$key = (string) substr( strrchr( '/' . $path, '/' ), 1 );
		if ( is_string( $a ) && ( strpos( $key, '@' ) === 0 || in_array( strtolower( $key ), array( 'url', 'image', 'logo', 'sameas', 'contenturl', 'sku', 'gtin', 'mpn', 'pricecurrency', 'price', 'availability', 'telephone', 'email' ), true ) || preg_match( '~^(https?:)?//~', $a ) ) ) {
			return $a === $b ? '' : 'value:' . $path;
		}
		if ( ! is_string( $a ) && $a !== $b ) {
			return 'value:' . $path;
		}
		return '';
	}

	/**
	 * Rows that share one translation although their originals are unrelated.
	 * Short translations are skipped (different short labels often share one).
	 *
	 * @param string                $translation Shared translation.
	 * @param array<int|string,string> $originals Row id => original.
	 * @return array Row ids to flag (all of the group), or empty.
	 */
	public static function shared_suspects( $translation, array $originals ) {
		if ( count( $originals ) < 2 ) {
			return array();
		}
		$plain = trim( AITP_Text::decode_entities( AITP_Markup::text_only( (string) $translation ) ) );
		if ( AITP_Text::len( $plain ) < 20 && count( preg_split( '~\s+~u', $plain ) ) < 4 ) {
			return array();
		}
		$folded = array();
		foreach ( $originals as $id => $o ) {
			$folded[ $id ] = self::fold( $o );
		}
		$ids = array_keys( $folded );
		$n   = count( $ids );
		for ( $i = 0; $i < $n; $i++ ) {
			for ( $j = $i + 1; $j < $n; $j++ ) {
				if ( ! self::similar( $folded[ $ids[ $i ] ], $folded[ $ids[ $j ] ] ) ) {
					return $ids;
				}
			}
		}
		return array();
	}

	/**
	 * @param string $a Folded text.
	 * @param string $b Folded text.
	 * @return bool
	 */
	public static function similar( $a, $b ) {
		if ( $a === $b ) {
			return true;
		}
		$la = strlen( $a );
		$lb = strlen( $b );
		if ( $la === 0 || $lb === 0 ) {
			return false;
		}
		if ( ( strpos( $a, $b ) !== false || strpos( $b, $a ) !== false ) && max( $la, $lb ) <= 1.5 * min( $la, $lb ) ) {
			return true;
		}
		if ( $la > 2000 || $lb > 2000 ) {
			$a = substr( $a, 0, 2000 );
			$b = substr( $b, 0, 2000 );
		}
		similar_text( $a, $b, $pct );
		return $pct >= 75;
	}

	/** Problem codes typical of a translation saved on the wrong string. */
	const SHIFT_CODES = array( 'too_short', 'too_long', 'sentences_missing', 'number_mismatch', 'untranslated', 'english_residue', 'protected_term_missing', 'protected_pattern_missing', 'markup_mismatch', 'link_changed', 'placeholder_mismatch', 'size_changed' );

	/**
	 * Runs of misplaced-looking rows. TranslatePress stores the strings of one page view
	 * with neighbouring ids, so when several nearby rows look misplaced, the rows between
	 * and around them were probably shifted too (short ones just pass the checks).
	 *
	 * @param int[] $ids Row ids with misplacement-type errors.
	 * @param int   $gap Largest id distance inside one run.
	 * @param int   $min Rows needed for a run.
	 * @param int   $pad Rows added before and after a run.
	 * @return array<int,array{0:int,1:int}> Id ranges [from, to] to look at.
	 */
	public static function neighbor_ranges( array $ids, $gap = 5, $min = 2, $pad = 2 ) {
		$ids = array_values( array_unique( array_map( 'intval', $ids ) ) );
		sort( $ids );
		$out = array();
		$run = array();
		foreach ( $ids as $id ) {
			if ( $run && $id - end( $run ) > $gap ) {
				if ( count( $run ) >= $min ) {
					$out[] = array( max( 1, $run[0] - $pad ), end( $run ) + $pad );
				}
				$run = array();
			}
			$run[] = $id;
		}
		if ( count( $run ) >= $min ) {
			$out[] = array( max( 1, $run[0] - $pad ), end( $run ) + $pad );
		}
		return $out;
	}

	/**
	 * @param string[] $codes Finding codes.
	 * @return bool Whether they look like a misplaced translation.
	 */
	public static function shift_like( array $codes ) {
		return (bool) array_intersect( $codes, self::SHIFT_CODES );
	}

	/**
	 * Lower case, markup removed, punctuation and spaces collapsed.
	 *
	 * @param string $s Text.
	 * @return string
	 */
	public static function fold( $s ) {
		$s = AITP_Text::lower( AITP_Text::decode_entities( AITP_Markup::text_only( (string) $s ) ) );
		$s = preg_replace( '~[^\p{L}\p{N}]+~u', ' ', $s );
		return trim( (string) $s );
	}

	/**
	 * Pages a repair may visit for one row. Pages where the page map saw the row come first:
	 * when there are any, pages only linked by TranslatePress (post_parent_id) are not used, so
	 * a repair of rows taken from mapped pages stays on those pages. With a list of allowed
	 * pages, only pages on that list are used.
	 *
	 * @param array<string,array>       $known   key => page (url, source_url, lang, from: map|meta).
	 * @param array<string,bool>|null   $allowed Keys from page_keys(), or null for no list.
	 * @return array<string,array> key => page
	 */
	public static function visit_pages( array $known, $allowed ) {
		$mapped = array_filter(
			$known,
			function ( $p ) {
				return isset( $p['from'] ) && $p['from'] === 'map';
			}
		);
		$use = $mapped ? $mapped : $known;
		if ( $allowed === null ) {
			return $use;
		}
		return array_filter(
			$use,
			function ( $p ) use ( $allowed ) {
				return isset( $allowed[ self::page_key( (string) $p['url'] ) ] ) || isset( $allowed[ self::page_key( isset( $p['source_url'] ) ? (string) $p['source_url'] : '' ) ] );
			}
		);
	}

	/**
	 * @param string[] $urls Page addresses (default-language or translated).
	 * @return array<string,bool> page key => true
	 */
	public static function page_keys( array $urls ) {
		$out = array();
		foreach ( $urls as $u ) {
			$k = self::page_key( (string) $u );
			if ( $k !== '' ) {
				$out[ $k ] = true;
			}
		}
		return $out;
	}

	/**
	 * Comparable form of a page address: path and query in the canonical spelling, without
	 * scheme, host, fragment and trailing slash.
	 *
	 * @param string $url Address.
	 * @return string
	 */
	public static function page_key( $url ) {
		$url = trim( (string) $url );
		if ( $url === '' ) {
			return '';
		}
		$url  = preg_replace( '~#.*$~s', '', $url );
		$path = preg_match( '~^[a-z][a-z0-9+.-]*://[^/?]*(.*)$~i', $url, $m ) ? $m[1] : $url;
		$q    = '';
		if ( strpos( $path, '?' ) !== false ) {
			list( $path, $q ) = explode( '?', $path, 2 );
		}
		$path = '/' . trim( $path, '/' );
		return AITP_Queue_Logic::canonical_path( $path . ( $q !== '' ? '?' . $q : '' ) );
	}

	/**
	 * A row stored unchanged whose original is not in the source language (TranslatePress
	 * keeps, for example, the language switcher labels of each language as originals of that
	 * language only). Only "untranslated" was found, the translation equals the original, and
	 * no other language has this original: a person should look, nothing needs retranslating.
	 *
	 * @param array|null $f            Finding from check_row().
	 * @param string     $original     Original.
	 * @param mixed      $translated   Stored translation.
	 * @param bool       $in_other     Whether another language's dictionary has this original.
	 * @return array|null The finding to record.
	 */
	public static function native_identical( $f, $original, $translated, $in_other ) {
		if ( ! $f || $in_other || $f['codes'] !== array( 'untranslated' ) || ! is_string( $translated ) || trim( $translated ) !== trim( (string) $original ) ) {
			return $f;
		}
		return array( 'severity' => self::REVIEW, 'codes' => array( 'identical_native' ), 'details' => array() );
	}

	/**
	 * Whether a row may be reset: still machine translated and unchanged since the scan.
	 *
	 * @param array $finding Finding: original_hash, translated_hash.
	 * @param array|null $row Current TranslatePress row: original, translated, status.
	 * @param array      $accepted Accepted-list entries of this row ([original_hash, translated_hash] pairs).
	 * @return string '' when allowed, otherwise the reason (missing, manual, untranslated, changed, accepted).
	 */
	public static function reset_block( array $finding, $row, array $accepted = array() ) {
		if ( ! is_array( $row ) ) {
			return 'missing';
		}
		if ( (int) $row['status'] === 2 ) {
			return 'manual';
		}
		if ( (int) $row['status'] !== 1 ) {
			return 'untranslated';
		}
		if ( sha1( (string) $row['original'] ) !== $finding['original_hash'] || sha1( (string) $row['translated'] ) !== $finding['translated_hash'] ) {
			return 'changed';
		}
		if ( self::is_accepted( $row, $accepted ) ) {
			return 'accepted';
		}
		return '';
	}

	/**
	 * Whether the row's current text is on the accepted list (reviewed by people).
	 *
	 * @param array $row      Current row: original, translated.
	 * @param array $accepted Accepted entries for this row: list of [original_hash, translated_hash].
	 * @return bool
	 */
	public static function is_accepted( array $row, array $accepted ) {
		$oh = sha1( (string) $row['original'] );
		$th = sha1( (string) $row['translated'] );
		foreach ( $accepted as $a ) {
			if ( $a[0] === $oh && $a[1] === $th ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Fewest pages that together show every row (greedy set cover). A reset row is
	 * translated again on any page that shows it, so one such page per row is enough.
	 * On a tie the page listed first wins (the post the string belongs to comes first).
	 *
	 * @param array<string,array<string,array>> $pages_of row key => page key => page.
	 * @return array<string,array> page key => page
	 */
	public static function cover_pages( array $pages_of ) {
		$left    = array();
		$all     = array();
		$rows_of = array();
		foreach ( $pages_of as $rk => $list ) {
			if ( ! $list ) {
				continue;
			}
			$left[ $rk ] = true;
			foreach ( $list as $pk => $p ) {
				$all[ $pk ]            = $p;
				$rows_of[ $pk ][ $rk ] = true;
			}
		}
		$out = array();
		while ( $left ) {
			$best = '';
			$n    = 0;
			foreach ( $rows_of as $pk => $rs ) {
				$c = count( array_intersect_key( $rs, $left ) );
				if ( $c > $n ) {
					$n    = $c;
					$best = (string) $pk;
				}
			}
			if ( $best === '' ) {
				break;
			}
			$out[ $best ] = $all[ $best ];
			$left         = array_diff_key( $left, $rows_of[ $best ] );
			unset( $rows_of[ $best ] );
		}
		return $out;
	}

	/**
	 * Whether a backed-up row may be restored.
	 * Restored: rows still reset by us, or translated again by the machine.
	 * Never restored: manual translations, rows whose original changed, rows that are gone.
	 *
	 * @param array      $backup Backup: original, translated, status.
	 * @param array|null $row    Current row.
	 * @return string '' when allowed, otherwise the reason.
	 */
	public static function restore_block( array $backup, $row ) {
		if ( ! is_array( $row ) ) {
			return 'missing';
		}
		if ( (string) $row['original'] !== (string) $backup['original'] ) {
			return 'changed';
		}
		$status = (int) $row['status'];
		if ( $status === 2 ) {
			return 'manual';
		}
		if ( $status === 0 || $status === 1 ) {
			return '';
		}
		return 'changed';
	}

	/**
	 * Read an AI verdict.
	 *
	 * @param mixed $text Model answer for one pair.
	 * @return string match, mismatch or unsure.
	 */
	public static function verdict( $text ) {
		$t = strtolower( trim( (string) $text, " \t\n\r\0\x0B.!\"'" ) );
		if ( in_array( $t, array( 'match', 'mismatch' ), true ) ) {
			return $t;
		}
		return 'unsure';
	}

	/**
	 * Parse an imported candidate list (JSON array or CSV with a header row).
	 * Recognised columns: language|lang|locale, id|row_id, original|source, old|translated|translation, table (dictionary|gettext).
	 *
	 * @param string $content File content.
	 * @return array{rows:array<int,array{lang:string,id:int,original:string,old:string,table:string}>,bad:int}
	 */
	public static function parse_import( $content, array $text_keys = array( 'old', 'translated', 'translation' ) ) {
		$content = preg_replace( '~^\xEF\xBB\xBF~', '', (string) $content );
		$records = array();
		$data    = json_decode( $content, true );
		if ( is_array( $data ) ) {
			foreach ( array( 'items', 'rows' ) as $wrap ) {
				if ( isset( $data[ $wrap ] ) && is_array( $data[ $wrap ] ) ) {
					$data = $data[ $wrap ];
					break;
				}
			}
			$records = array_values( array_filter( $data, 'is_array' ) );
		} else {
			$lines = self::csv_rows( $content );
			$head  = array_map( 'strtolower', array_map( 'trim', (array) array_shift( $lines ) ) );
			foreach ( $lines as $cells ) {
				if ( count( $cells ) === 1 && trim( (string) $cells[0] ) === '' ) {
					continue;
				}
				$rec = array();
				foreach ( $head as $i => $h ) {
					$rec[ $h ] = isset( $cells[ $i ] ) ? $cells[ $i ] : '';
				}
				$records[] = $rec;
			}
		}
		$pick = function ( array $r, array $names ) {
			foreach ( $names as $n ) {
				if ( isset( $r[ $n ] ) && ( is_string( $r[ $n ] ) || is_numeric( $r[ $n ] ) ) ) {
					return (string) $r[ $n ];
				}
			}
			return null;
		};
		$rows = array();
		$bad  = 0;
		foreach ( $records as $r ) {
			$lang = $pick( $r, array( 'language', 'lang', 'locale', 'target_locale' ) );
			$id   = $pick( $r, array( 'id', 'row_id' ) );
			$orig = $pick( $r, array( 'original', 'source' ) );
			$old  = $pick( $r, $text_keys );
			$tbl  = $pick( $r, array( 'table' ) );
			if ( $lang === null || $id === null || ! ctype_digit( trim( $id ) ) || $orig === null || $old === null || ! preg_match( '~^[A-Za-z]{2,3}(?:[_-][A-Za-z0-9]{2,8})*$~', trim( $lang ) ) ) {
				$bad++;
				continue;
			}
			$rows[] = array(
				'lang'     => trim( $lang ),
				'id'       => (int) $id,
				'original' => $orig,
				'old'      => $old,
				'table'    => $tbl !== null && strtolower( trim( $tbl ) ) === 'gettext' ? 'gettext' : 'dictionary',
			);
		}
		return array( 'rows' => $rows, 'bad' => $bad );
	}

	/**
	 * @param string $content CSV text.
	 * @return array<int,array<int,string>>
	 */
	private static function csv_rows( $content ) {
		$fh = fopen( 'php://temp', 'r+' );
		fwrite( $fh, $content );
		rewind( $fh );
		$out = array();
		while ( ( $cells = fgetcsv( $fh, 0, ',', '"', '' ) ) !== false ) {
			$out[] = $cells;
		}
		fclose( $fh );
		return $out;
	}

	/**
	 * Keep finding details short.
	 *
	 * @param array $d Details.
	 * @return array
	 */
	private static function small( array $d ) {
		$json = (string) json_encode( $d, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR );
		return strlen( $json ) > 1500 ? array( 'truncated' => true ) : $d;
	}
}


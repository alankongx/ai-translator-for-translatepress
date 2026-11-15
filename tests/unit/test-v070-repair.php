<?php
/**
 * 0.7.0 batch 3: repairing old translations (one test per review finding).
 */

/* C10: three tiers. */

test( 'C10: certainly wrong / for a person / for reference', function () {
	eq( 'a', AITP_Audit_Logic::tier( 'error', array( 'too_short' ) ) );
	eq( 'a', AITP_Audit_Logic::tier( 'error', array( 'size_changed', 'untranslated' ) ), 'one proof is enough' );
	eq( 'b', AITP_Audit_Logic::tier( 'error', array( 'size_changed' ) ), 'sizes, codes and numbers: a person looks' );
	eq( 'b', AITP_Audit_Logic::tier( 'error', array( 'number_mismatch', 'protected_pattern_missing', 'brand_case' ) ) );
	eq( 'b', AITP_Audit_Logic::tier( 'error', array( 'structured_broken' ) ), 'a page visit cannot repair structured data' );
	eq( 'b', AITP_Audit_Logic::tier( 'error', array( 'too_short', 'structured_broken' ) ) );
	eq( 'b', AITP_Audit_Logic::tier( 'error', array( 'some_new_code' ) ), 'unknown codes are left for a person' );
	eq( 'b', AITP_Audit_Logic::tier( 'suspect', array( 'shared_translation' ) ) );
	eq( 'b', AITP_Audit_Logic::tier( 'review', array( 'identical_native' ) ) );
	eq( 'ref', AITP_Audit_Logic::tier( 'suspect', array( 'near_misaligned' ) ) );
	eq( 'b', AITP_Audit_Logic::tier( 'suspect', array( 'near_misaligned', 'shared_translation' ) ) );
	eq( 'a', AITP_Audit_Logic::tier( 'error', array( 'near_misaligned', 'ai_mismatch' ) ) );
} );

test( 'C10: the SQL used for stored findings names every code of the rule', function () {
	$sql = AITP_Audit_Logic::tier_sql();
	foreach ( array_merge( AITP_Audit_Logic::TIER_A_CODES, AITP_Audit_Logic::TIER_B_FORCE ) as $c ) {
		ok( strpos( $sql, "FIND_IN_SET('$c', codes)" ) !== false, $c );
	}
	ok( strpos( $sql, "codes IN ('near_misaligned')" ) !== false );
} );

test( 'C10: tiers on reviewed real-site data (skipped without the private fixtures)', function () {
	$dir = __DIR__ . '/../fixtures/private/';
	if ( ! is_file( $dir . 'okay-qa.json' ) || ! is_file( $dir . 'okay-accepted-hhg-repair.json' ) || ! is_file( $dir . 'okay-candidates-remaining.json' ) ) {
		ok( true );
		return;
	}
	$rules                  = new AITP_Rules();
	$rules->protected_terms = array( 'OKAY EYEWEAR', 'Okay Eyewear', 'OKAY Eyewear', 'OK-A1001', 'TR90', 'UV400' );
	$rules->patterns        = AITP_Rules::default_patterns();
	$v   = new AITP_Validator( $rules );
	$si  = AITP_Languages::info( 'en_US' );
	$map = array( 'fr' => 'fr_FR', 'es' => 'es_MX', 'de' => 'de_DE', 'pt' => 'pt_BR', 'ja' => 'ja', 'ar' => 'ar' );
	$tier = function ( $lang, $o, $t ) use ( $v, $si, $map ) {
		$l = isset( $map[ substr( strtolower( $lang ), 0, 2 ) ] ) ? $map[ substr( strtolower( $lang ), 0, 2 ) ] : $lang;
		$f = AITP_Audit_Logic::check_row( $v, $o, $t, $si, AITP_Languages::info( $l ) );
		return $f ? AITP_Audit_Logic::tier( $f['severity'], $f['codes'] ) : '';
	};
	$good_a = 0;
	$n      = 0;
	foreach ( json_decode( file_get_contents( $dir . 'okay-accepted-hhg-repair.json' ), true )['rows'] as $r ) {
		$n++;
		$good_a += $tier( $r['language'], $r['original'], $r['new'] ) === 'a' ? 1 : 0;
	}
	foreach ( json_decode( file_get_contents( $dir . 'okay-qa.json' ), true ) as $r ) {
		$n++;
		$good_a += $tier( $r['lang'], $r['source'], isset( $r['reviewed'] ) && is_string( $r['reviewed'] ) && $r['reviewed'] !== '' ? $r['reviewed'] : $r['model'] ) === 'a' ? 1 : 0;
	}
	ok( $good_a <= 2, "good translations called certainly wrong: $good_a of $n" );
	$bad_a = 0;
	$rows  = json_decode( file_get_contents( $dir . 'okay-candidates-remaining.json' ), true )['rows'];
	foreach ( $rows as $r ) {
		$bad_a += $tier( $r['language'], $r['original'], $r['old'] ) === 'a' ? 1 : 0;
	}
	ok( $bad_a >= 870, "known bad rows called certainly wrong: $bad_a of " . count( $rows ) );
} );

/* Found while measuring C10 (a batch 1 regression): Arabic text counted as Syriac. */

test( 'scripts: Arabic tatweel and Arabic-Indic digits are not a foreign script', function () {
	$v  = new AITP_Validator( okay_rules() );
	$si = AITP_Languages::info( 'en_US' );
	$ti = AITP_Languages::info( 'ar' );
	$r  = $v->check( 'Why global brands trust OKAY EYEWEAR', 'لماذا تثق العلامات التجارية العالمية بـ OKAY EYEWEAR', $si, $ti );
	ok( ! in_array( 'foreign_script', $r['hard'], true ), json_encode( $r['hard'] ) );
	$r = $v->check( 'With 15 years in eyewear', 'مع خبرة تمتد لـ ١٥ عاماً في صناعة النظارات', $si, $ti );
	ok( ! in_array( 'foreign_script', $r['hard'], true ), json_encode( $r['hard'] ) );
	$c = AITP_Text::script_counts( 'بـ ١٥' );
	ok( empty( $c['Syriac'] ) && empty( $c['Thaana'] ), json_encode( $c ) );
	$r = $v->check( 'Why global brands trust us', 'ܠܡܢܐ ܬܟܝܠܝܢ ܥܠܝܢ', $si, $ti );
	has( 'foreign_script', $r['hard'], 'real Syriac letters still count' );
} );

/* C09: runs of misplaced-looking rows. */

test( 'C09: misplacement runs need three rows, the right codes and a dense window', function () {
	eq( array(), AITP_Audit_Logic::neighbor_ranges( array( 10, 12 ) ), 'two rows are not a run' );
	eq( array( array( 8, 16 ) ), AITP_Audit_Logic::neighbor_ranges( array( 10, 12, 14 ) ) );
	eq( array(), AITP_Audit_Logic::neighbor_ranges( array( 10, 15, 20 ), 5, 3, 2, 0.4 ), '3 in 11 rows is not dense enough here' );
	eq( array( array( 8, 14 ) ), AITP_Audit_Logic::neighbor_ranges( array( 10, 11, 12 ), 5, 3, 2, 0.4 ) );
	ok( AITP_Audit_Logic::shift_like( array( 'sentences_missing' ) ) );
	ok( ! AITP_Audit_Logic::shift_like( array( 'number_mismatch' ) ), 'any bad translation may lose a number' );
	ok( ! AITP_Audit_Logic::shift_like( array( 'markup_mismatch', 'protected_pattern_missing', 'size_changed' ) ) );
} );

/* C17: plural interface texts are compared with the plural original. */

test( 'C17: a backup still belongs to a plural row whose original now reads as the plural', function () {
	$row = array( 'original' => '%d items', 'original_single' => '%d item', 'status' => 1, 'translated' => '%d articles' );
	ok( AITP_Audit_Logic::same_original( array( 'original' => '%d items' ), $row ) );
	ok( AITP_Audit_Logic::same_original( array( 'original' => '%d item' ), $row ), 'backup made before 0.7.0 holds the singular' );
	ok( ! AITP_Audit_Logic::same_original( array( 'original' => 'Other' ), $row ) );
	eq( '', AITP_Audit_Logic::restore_block( array( 'original' => '%d item' ), $row ) );
	eq( 'changed', AITP_Audit_Logic::restore_block( array( 'original' => 'Other' ), $row ) );
} );

test( 'C05: roll back only the rows that did not come out well', function () {
	$backups = array(
		10 => array( 'kind' => 'dictionary', 'lang' => 'fr_FR', 'row_id' => 1 ),
		11 => array( 'kind' => 'dictionary', 'lang' => 'fr_FR', 'row_id' => 2 ),
		12 => array( 'kind' => 'dictionary', 'lang' => 'fr_FR', 'row_id' => 3 ),
		13 => array( 'kind' => 'dictionary', 'lang' => 'fr_FR', 'row_id' => 4 ),
		14 => array( 'kind' => 'dictionary', 'lang' => 'fr_FR', 'row_id' => 5 ),
		15 => array( 'kind' => 'dictionary', 'lang' => 'fr_FR', 'row_id' => 6 ),
		16 => array( 'kind' => 'dictionary', 'lang' => 'fr_FR', 'row_id' => 7 ),
		17 => array( 'kind' => 'gettext', 'lang' => 'fr_FR', 'row_id' => 1 ),
		18 => array( 'kind' => 'dictionary', 'lang' => 'de_DE', 'row_id' => 9 ),
	);
	$cats = array(
		'dictionary|fr_FR|1' => 'ok',
		'dictionary|fr_FR|2' => 'left',
		'dictionary|fr_FR|3' => 'refused',
		'dictionary|fr_FR|4' => 'wrong',
		'dictionary|fr_FR|5' => 'english',
		'dictionary|fr_FR|6' => 'duplicate',
		'dictionary|fr_FR|7' => 'manual',
		'gettext|fr_FR|1'    => 'gone',
	);
	eq( array( 11, 12, 13, 14 ), array_keys( AITP_Audit_Logic::only_bad( $backups, $cats ) ), 'left, refused, wrong and English only; a row not checked is left alone' );
	eq( array(), AITP_Audit_Logic::only_bad( $backups, array() ), 'nothing checked: nothing restored' );
} );

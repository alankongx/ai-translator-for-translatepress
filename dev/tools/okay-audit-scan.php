<?php
/**
 * Audit rules on OKAY data (read-only, prints counts only):
 *   php dev/tools/okay-audit-scan.php [candidates.json]
 * - good set: tests/fixtures/private/okay-qa.json (reviewed translations) -> false findings
 * - candidates: list with language, original, old (e.g. the legacy candidate list) -> how many the audit flags
 */
require dirname( __DIR__, 2 ) . '/tests/bootstrap.php';
$v   = new AITP_Validator( okay_rules() );
$si  = AITP_Languages::info( 'en_US' );
$out = array();

$good = dirname( __DIR__, 2 ) . '/tests/fixtures/private/okay-qa.json';
if ( is_file( $good ) ) {
	$stat   = array( 'rows' => 0, 'error' => 0, 'review' => 0, 'by_lang' => array(), 'codes' => array() );
	$groups = array();
	foreach ( json_decode( file_get_contents( $good ), true ) as $i => $r ) {
		$tgt = isset( $r['reviewed'] ) && is_string( $r['reviewed'] ) && $r['reviewed'] !== '' ? $r['reviewed'] : $r['model'];
		if ( ! is_string( $tgt ) ) {
			continue;
		}
		$stat['rows']++;
		$f = AITP_Audit_Logic::check_row( $v, $r['source'], $tgt, $si, AITP_Languages::info( $r['lang'] ) );
		if ( $f ) {
			$stat[ $f['severity'] ] = ( isset( $stat[ $f['severity'] ] ) ? $stat[ $f['severity'] ] : 0 ) + 1;
			if ( $f['severity'] === 'error' ) {
				$stat['by_lang'][ $r['lang'] ] = ( isset( $stat['by_lang'][ $r['lang'] ] ) ? $stat['by_lang'][ $r['lang'] ] : 0 ) + 1;
			}
			foreach ( $f['codes'] as $c ) {
				$stat['codes'][ $c ] = ( isset( $stat['codes'][ $c ] ) ? $stat['codes'][ $c ] : 0 ) + 1;
			}
		}
		$groups[ $r['lang'] . '|' . $tgt ][ $i ] = $r['source'];
	}
	$shared = 0;
	foreach ( $groups as $k => $originals ) {
		$shared += count( AITP_Audit_Logic::shared_suspects( explode( '|', $k, 2 )[1], $originals ) );
	}
	$stat['shared_translation_suspects'] = $shared;
	$out['good_set'] = $stat;
}

if ( isset( $argv[1] ) && is_file( $argv[1] ) ) {
	$list = json_decode( file_get_contents( $argv[1] ), true );
	$stat = array( 'rows' => 0, 'error' => 0, 'review' => 0, 'none' => 0, 'structured' => 0, 'none_by_lang' => array(), 'codes' => array() );
	foreach ( $list as $r ) {
		$lang = isset( $r['language'] ) ? $r['language'] : $r['lang'];
		$map  = array( 'fr' => 'fr_FR', 'es' => 'es_MX', 'de' => 'de_DE', 'pt' => 'pt_BR', 'ja' => 'ja', 'ar' => 'ar' );
		$lang = isset( $map[ substr( strtolower( $lang ), 0, 2 ) ] ) ? $map[ substr( strtolower( $lang ), 0, 2 ) ] : $lang;
		$stat['rows']++;
		if ( AITP_Text::is_structured_data( $r['original'] ) ) {
			$stat['structured']++;
			$stat['structured_old_empty'] = ( isset( $stat['structured_old_empty'] ) ? $stat['structured_old_empty'] : 0 ) + ( trim( (string) $r['old'] ) === '' ? 1 : 0 );
		}
		$f = AITP_Audit_Logic::check_row( $v, $r['original'], $r['old'], $si, AITP_Languages::info( $lang ) );
		if ( ! $f ) {
			$stat['none']++;
			$stat['none_by_lang'][ $lang ] = ( isset( $stat['none_by_lang'][ $lang ] ) ? $stat['none_by_lang'][ $lang ] : 0 ) + 1;
			continue;
		}
		$stat[ $f['severity'] ] = ( isset( $stat[ $f['severity'] ] ) ? $stat[ $f['severity'] ] : 0 ) + 1;
		foreach ( $f['codes'] as $c ) {
			$stat['codes'][ $c ] = ( isset( $stat['codes'][ $c ] ) ? $stat['codes'][ $c ] : 0 ) + 1;
		}
	}
	arsort( $stat['codes'] );
	$out['candidates'] = $stat;
}
echo json_encode( $out, JSON_PRETTY_PRINT ) . "\n";

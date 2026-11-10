<?php
/**
 * wp eval-file /e2e/verify.php [lang,lang…]
 * Checks every machine-translated row (status 1) in the TranslatePress dictionaries against
 * the mock's deterministic translation of its own original. Any difference means a string was
 * saved with somebody else's translation (misalignment) or with damaged text.
 */
require_once __DIR__ . '/pseudo.php';

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
		if ( substr( ltrim( (string) $row['original'] ), 0, 1 ) === '{' ) {
			continue; // JSON-LD rows planted by audit-seed.php: not produced by the mock.
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

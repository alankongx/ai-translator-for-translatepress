<?php
/**
 * Runs the validator over CSVs of suspected bad translations (columns: language, original,
 * translated|old_translation). Reports how many would be rejected and shows those that pass.
 * Usage: php dev/tools/okay-bad-scan.php file.csv [show_passes]
 */
require dirname( __DIR__, 2 ) . '/tests/bootstrap.php';
$map  = array( 'es' => 'es_MX', 'pt' => 'pt_BR', 'fr' => 'fr_FR', 'de' => 'de_DE', 'ja' => 'ja', 'ar' => 'ar' );
$fh   = fopen( $argv[1], 'r' );
$head = fgetcsv( $fh, 0, ',', '"', '' );
$head[0] = preg_replace( '~^\xEF\xBB\xBF~', '', $head[0] );
$col  = array_flip( $head );
$tcol = isset( $col['translated'] ) ? 'translated' : 'old_translation';
$v    = new AITP_Validator( okay_rules() );
$n    = 0;
$rej  = 0;
$codes = array();
$pass = array();
while ( ( $row = fgetcsv( $fh, 0, ',', '"', '' ) ) !== false ) {
	if ( count( $row ) < count( $head ) ) {
		continue;
	}
	$l = $row[ $col['language'] ];
	$loc = isset( $map[ $l ] ) ? $map[ $l ] : $l;
	$res = $v->check( $row[ $col['original'] ], $row[ $col[ $tcol ] ], AITP_Languages::info( 'en_US' ), AITP_Languages::info( $loc ) );
	$n++;
	if ( $res['hard'] ) {
		$rej++;
		$codes[ $res['hard'][0] ] = ( isset( $codes[ $res['hard'][0] ] ) ? $codes[ $res['hard'][0] ] : 0 ) + 1;
	} else {
		$pass[] = "[$loc] soft=" . implode( ',', $res['soft'] ) . ' ratio=' . ( isset( $res['details']['length_ratio'] ) ? $res['details']['length_ratio'] : '-' ) . "\n  S: " . mb_substr( $row[ $col['original'] ], 0, 150 ) . "\n  T: " . mb_substr( $row[ $col[ $tcol ] ], 0, 150 );
	}
}
arsort( $codes );
printf( "%s: %d rows, %d rejected (%.1f%%)\n%s\n", basename( $argv[1] ), $n, $rej, 100 * $rej / max( 1, $n ), json_encode( $codes ) );
if ( ! empty( $argv[2] ) ) {
	echo implode( "\n", array_slice( $pass, 0, (int) $argv[2] ) ) . "\n";
}

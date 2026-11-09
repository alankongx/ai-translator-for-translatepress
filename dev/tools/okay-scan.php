<?php
/**
 * Runs the validator over the private OKAY QA records (source + reviewed/model translation)
 * and reports what it would reject. Usage: php dev/tools/okay-scan.php [lang] [code]
 */
require dirname( __DIR__, 2 ) . '/tests/bootstrap.php';
$file = dirname( __DIR__, 2 ) . '/tests/fixtures/private/okay-qa.json';
if ( ! is_file( $file ) ) {
	fwrite( STDERR, "No private fixture.\n" );
	exit( 0 );
}
$recs   = json_decode( file_get_contents( $file ), true );
$rules  = okay_rules();
$v      = new AITP_Validator( $rules );
$only_l = isset( $argv[1] ) && $argv[1] !== '-' ? $argv[1] : '';
$only_c = isset( $argv[2] ) ? $argv[2] : '';
$stats  = array();
$codes  = array();
$soft   = array();
$shown  = 0;
foreach ( $recs as $r ) {
	if ( $only_l && $r['lang'] !== $only_l ) {
		continue;
	}
	$tgt = isset( $r['reviewed'] ) && is_string( $r['reviewed'] ) && $r['reviewed'] !== '' ? $r['reviewed'] : $r['model'];
	if ( ! is_string( $tgt ) ) {
		continue;
	}
	$res = $v->check( $r['source'], $tgt, AITP_Languages::info( 'en_US' ), AITP_Languages::info( $r['lang'] ) );
	$l   = $r['lang'];
	if ( ! isset( $stats[ $l ] ) ) {
		$stats[ $l ] = array( 'n' => 0, 'hard' => 0, 'soft' => 0 );
	}
	$stats[ $l ]['n']++;
	if ( $res['hard'] ) {
		$stats[ $l ]['hard']++;
		foreach ( $res['hard'] as $c ) {
			$codes[ $c ] = ( isset( $codes[ $c ] ) ? $codes[ $c ] : 0 ) + 1;
		}
		if ( ( $only_c === '' || in_array( $only_c, $res['hard'], true ) ) && $shown < 40 ) {
			$shown++;
			echo "[$l] " . implode( ',', $res['hard'] ) . ' ' . json_encode( $res['details'], JSON_UNESCAPED_UNICODE ) . "\n  S: " . mb_substr( $r['source'], 0, 160 ) . "\n  T: " . mb_substr( $tgt, 0, 160 ) . "\n";
		}
	}
	if ( $res['soft'] ) {
		$stats[ $l ]['soft']++;
		foreach ( $res['soft'] as $c ) {
			$soft[ $c ] = ( isset( $soft[ $c ] ) ? $soft[ $c ] : 0 ) + 1;
		}
	}
}
echo "\n";
foreach ( $stats as $l => $s ) {
	printf( "%-6s n=%4d hard=%3d (%.1f%%) soft=%3d\n", $l, $s['n'], $s['hard'], 100 * $s['hard'] / max( 1, $s['n'] ), $s['soft'] );
}
arsort( $codes );
arsort( $soft );
echo 'hard: ' . json_encode( $codes ) . "\nsoft: " . json_encode( $soft ) . "\n";

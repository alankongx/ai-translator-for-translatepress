<?php
/**
 * Usage: php tests/run.php [filter]
 */
require __DIR__ . '/bootstrap.php';
foreach ( glob( __DIR__ . '/unit/test-*.php' ) as $f ) {
	require $f;
}
$r = run_tests( isset( $argv[1] ) ? $argv[1] : '' );
file_put_contents( __DIR__ . '/results.json', json_encode( array( 'passed' => $r['passed'], 'failed' => $r['failed'], 'failures' => $r['failures'], 'cases' => $r['names'] ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );
exit( $r['failed'] ? 1 : 0 );

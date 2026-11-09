<?php
/**
 * wp eval-file /e2e/warm-probe.php <mode>
 * Sends signed / forged / replayed internal visits and reports whether the page accepted them.
 */
$mode   = isset( $args[0] ) ? $args[0] : 'valid';
$url    = AITP_Queue::translated_url( home_url( '/okay-home/' ), 'fr_FR' ) . '?aitp_nc=probe' . wp_rand();
$path   = wp_parse_url( $url, PHP_URL_PATH ) . '?' . wp_parse_url( $url, PHP_URL_QUERY );
$secret = ( new ReflectionMethod( 'AITP_Queue', 'secret' ) );
$secret->setAccessible( true );
$secret = $secret->invoke( null );
$nonce  = bin2hex( random_bytes( 16 ) );
$ts     = time();
$header = AITP_Queue_Logic::sign( $secret, $path, $ts, $nonce );
if ( $mode === 'forged' ) {
	$header = AITP_Queue_Logic::sign( 'wrong-secret', $path, $ts, $nonce );
} elseif ( $mode === 'expired' ) {
	$header = AITP_Queue_Logic::sign( $secret, $path, $ts - 1000, $nonce );
} elseif ( $mode === 'other_path' ) {
	$header = AITP_Queue_Logic::sign( $secret, '/fr/okay-article/', $ts, $nonce );
}
$send = function () use ( $url, $header ) {
	$req = apply_filters( 'aitp_queue_request', array( 'url' => $url, 'args' => array( 'timeout' => 60, 'redirection' => 0, 'headers' => array( 'X-AITP-Warm' => $header ) ) ), 'fr_FR' );
	$r   = wp_remote_get( $req['url'], $req['args'] );
	usleep( 300000 );
	return array( 'http' => wp_remote_retrieve_response_code( $r ), 'report' => get_transient( 'aitp_warm_' . preg_replace( '~^\d+\.([a-f0-9]+)\..*$~', '$1', $header ) ) !== false );
};
$out = array( 'first' => $send() );
delete_transient( 'aitp_warm_' . $nonce );
if ( $mode === 'valid' ) {
	$out['replay'] = $send(); // Same header again.
}
echo wp_json_encode( $out ) . "\n";
